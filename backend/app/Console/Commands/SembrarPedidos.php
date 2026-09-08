<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Services\PedidoEstadoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Siembra envíos ya publicados, para no llenar el formulario del Panel una y otra vez cuando lo que
 * se quiere probar son varios conductores a la vez.
 *
 * Pasa por `PedidoEstadoService::transicionar()` en vez de escribir el estado a mano: publicar es
 * lo que crea el pool de ofertas (spec tenant/020) y dispara los avisos. Un `INSERT` directo en
 * `pedidos` deja el envío invisible para las apps de conductor.
 *
 * Herramienta de desarrollo: en producción exige `--force`.
 */
#[Signature('pedidos:sembrar
    {cantidad=5 : Cuántos envíos crear}
    {--tenant= : Slug del tenant; obligatorio si hay más de uno activo}
    {--ambiente= : live|test; por omisión, el interruptor del tenant}
    {--centro= : "lat,lng" del centro de la zona; por omisión se deduce de los datos existentes}
    {--radio=4 : Radio en km dentro del cual caen recogida y entrega}
    {--sin-publicar : Dejarlos en PENDIENTE en vez de publicarlos}
    {--force : Permitir la siembra en producción}')]
#[Description('Crea N envíos publicados de golpe, sin pasar por el formulario')]
class SembrarPedidos extends Command
{
    /** Último recurso si el tenant todavía no tiene ni un envío ni un conductor con posición. */
    private const CENTRO_POR_DEFECTO = ['lat' => 19.4326, 'lng' => -99.1332];

    public function __construct(private readonly PedidoEstadoService $estados)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (App::environment('production') && ! $this->option('force')) {
            $this->error('Esto crea envíos reales en producción. Repite con --force si es lo que quieres.');

            return self::FAILURE;
        }

        $tenant = $this->resolverTenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        $cantidad = max(1, (int) $this->argument('cantidad'));
        $radioKm = max(0.1, (float) $this->option('radio'));

        tenancy()->initialize($tenant);

        try {
            $tarifas = $this->tarifas();

            if ($tarifas === null) {
                $this->error('El tenant no tiene configuradas las tarifas (banderazo, km incluidos, km adicional).');

                return self::FAILURE;
            }

            $ambiente = $this->resolverAmbiente();
            $centro = $this->resolverCentro();

            $this->line("Tenant <info>{$tenant->slug}</info> · ambiente <info>{$ambiente}</info> · centro {$centro['lat']},{$centro['lng']}");

            $filas = [];

            for ($i = 1; $i <= $cantidad; $i++) {
                $pedido = $this->crear($ambiente, $centro, $radioKm, $tarifas, $i);

                if (! $this->option('sin-publicar')) {
                    $this->publicar($pedido);
                }

                $filas[] = [
                    $pedido->numero_pedido,
                    $pedido->estado,
                    number_format((float) $pedido->importe_envio, 2),
                    "{$pedido->latitud_recogida},{$pedido->longitud_recogida}",
                    "{$pedido->latitud_entrega},{$pedido->longitud_entrega}",
                ];
            }

            $this->table(['Envío', 'Estado', 'Importe', 'Recogida', 'Entrega'], $filas);
        } finally {
            tenancy()->end();
        }

        return self::SUCCESS;
    }

    private function resolverTenant(): ?TenantCentral
    {
        $slug = $this->option('tenant');

        if ($slug !== null) {
            $tenant = TenantCentral::where('slug', $slug)->first();

            if ($tenant === null) {
                $this->error("No existe el tenant '{$slug}'.");
            }

            return $tenant;
        }

        $activos = TenantCentral::where('estado', 'Activo')->get();

        if ($activos->count() === 1) {
            return $activos->first();
        }

        $this->error('Indica el tenant con --tenant='.($activos->pluck('slug')->implode('|') ?: '<slug>'));

        return null;
    }

    private function resolverAmbiente(): string
    {
        $ambiente = $this->option('ambiente')
            ?? ConfiguracionTenant::obtener(ConfiguracionTenant::AMBIENTE, Pedido::AMBIENTE_LIVE);

        return $ambiente === Pedido::AMBIENTE_TEST ? Pedido::AMBIENTE_TEST : Pedido::AMBIENTE_LIVE;
    }

    /**
     * El centro sale de los datos del propio tenant, para que los envíos caigan donde ya se está
     * probando: primero la última recogida capturada a mano, luego el último conductor que reportó
     * posición.
     *
     * @return array{lat: float, lng: float}
     */
    private function resolverCentro(): array
    {
        $opcion = $this->option('centro');

        if (is_string($opcion) && str_contains($opcion, ',')) {
            [$lat, $lng] = array_map('trim', explode(',', $opcion, 2));

            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }

        $ultimo = Pedido::withoutGlobalScopes()
            ->whereNotNull('latitud_recogida')
            ->orderByDesc('id_pedido')
            ->first(['latitud_recogida', 'longitud_recogida']);

        if ($ultimo !== null) {
            return ['lat' => (float) $ultimo->latitud_recogida, 'lng' => (float) $ultimo->longitud_recogida];
        }

        $conductor = DB::table('conductor_estado')
            ->whereNotNull('ultima_latitud')
            ->orderByDesc('updated_at')
            ->first();

        if ($conductor !== null) {
            return ['lat' => (float) $conductor->ultima_latitud, 'lng' => (float) $conductor->ultima_longitud];
        }

        return self::CENTRO_POR_DEFECTO;
    }

    /**
     * @return array{banderazo: float, km_incluidos: float, km_adicional: float}|null
     */
    private function tarifas(): ?array
    {
        $banderazo = ConfiguracionTenant::obtener(ConfiguracionTenant::BANDERAZO);
        $incluidos = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_INCLUIDOS);
        $adicional = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_ADICIONAL);

        if ($banderazo === null || $incluidos === null || $adicional === null) {
            return null;
        }

        return [
            'banderazo' => (float) $banderazo,
            'km_incluidos' => (float) $incluidos,
            'km_adicional' => (float) $adicional,
        ];
    }

    /**
     * @param  array{lat: float, lng: float}  $centro
     * @param  array{banderazo: float, km_incluidos: float, km_adicional: float}  $tarifas
     */
    private function crear(string $ambiente, array $centro, float $radioKm, array $tarifas, int $indice): Pedido
    {
        $recogida = $this->puntoCercano($centro, $radioKm);
        $entrega = $this->puntoCercano($centro, $radioKm);
        $km = $this->distanciaKm($recogida, $entrega);
        $importe = $tarifas['banderazo'] + (max(0.0, $km - $tarifas['km_incluidos']) * $tarifas['km_adicional']);

        return DB::transaction(function () use ($ambiente, $recogida, $entrega, $importe, $indice) {
            // Mismo criterio que `Tenant\PedidoController@store`: el correlativo es del tenant
            // entero y no del ambiente, y `ambiente` se sella fuera del `create()` porque no está
            // en el `#[Fillable]` del modelo.
            $siguienteId = (int) (Pedido::withoutGlobalScopes()->max('id_pedido') ?? 0) + 1;

            $pedido = Pedido::create([
                'numero_pedido' => 'PED-'.str_pad((string) $siguienteId, 6, '0', STR_PAD_LEFT),
                'nombre_solicitante' => "Siembra {$indice}",
                'telefono_solicitante' => '5500'.str_pad((string) $indice, 6, '0', STR_PAD_LEFT),
                'direccion_recogida' => "Recogida de prueba {$indice}",
                'latitud_recogida' => $recogida['lat'],
                'longitud_recogida' => $recogida['lng'],
                'direccion_entrega' => "Entrega de prueba {$indice}",
                'latitud_entrega' => $entrega['lat'],
                'longitud_entrega' => $entrega['lng'],
                'fecha_servicio' => now()->toDateString(),
                'lo_antes_posible' => true,
                'modalidad_pago' => 'REMITENTE_PAGA_ENVIO',
                'importe_envio' => round($importe, 2),
                'importe_cobro' => 0,
                'estado' => 'PENDIENTE',
            ]);

            $pedido->ambiente = $ambiente;
            $pedido->save();

            return $pedido;
        });
    }

    /**
     * Mismo trato que `Tenant\PedidoController@publicar`: si el aviso falla, el estado se persiste
     * igual — dejar el envío en PENDIENTE sería peor que perder la notificación.
     */
    private function publicar(Pedido $pedido): void
    {
        try {
            $this->estados->transicionar($pedido, 'PUBLICADO');
        } catch (\Throwable $e) {
            $this->warn("No se pudo avisar de la publicación de {$pedido->numero_pedido}: {$e->getMessage()}");
        } finally {
            if ($pedido->estado === 'PUBLICADO') {
                $pedido->save();
            }
        }
    }

    /**
     * @param  array{lat: float, lng: float}  $centro
     * @return array{lat: float, lng: float}
     */
    private function puntoCercano(array $centro, float $radioKm): array
    {
        $gradosLat = $radioKm / 111.0;
        $gradosLng = $radioKm / max(0.01, 111.0 * cos(deg2rad($centro['lat'])));

        return [
            'lat' => round($centro['lat'] + ((mt_rand(-1000, 1000) / 1000) * $gradosLat), 7),
            'lng' => round($centro['lng'] + ((mt_rand(-1000, 1000) / 1000) * $gradosLng), 7),
        ];
    }

    /**
     * @param  array{lat: float, lng: float}  $desde
     * @param  array{lat: float, lng: float}  $hasta
     */
    private function distanciaKm(array $desde, array $hasta): float
    {
        $dLat = deg2rad($hasta['lat'] - $desde['lat']);
        $dLng = deg2rad($hasta['lng'] - $desde['lng']);

        $a = sin($dLat / 2) ** 2
            + (cos(deg2rad($desde['lat'])) * cos(deg2rad($hasta['lat'])) * sin($dLng / 2) ** 2);

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
