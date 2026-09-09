<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Tenant\PedidoCreado;
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
 * Los envíos salen indistinguibles de los capturados a mano: direcciones reales de Celaya con sus
 * coordenadas, nombre y teléfono de solicitante creíbles, y el importe calculado con las tarifas
 * del tenant. Es la diferencia con la versión anterior, que sorteaba puntos al azar alrededor de
 * un centro y los rotulaba "Recogida de prueba 3": servían para probar el mapa, pero no para ver
 * el Panel como lo ve un despachador.
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
    {--centro= : "lat,lng"; con esto los puntos se sortean al azar alrededor y se deja de usar el callejero de Celaya}
    {--radio=4 : Radio en km del sorteo. Solo tiene efecto junto con --centro}
    {--sin-publicar : Dejarlos en PENDIENTE en vez de publicarlos}
    {--force : Permitir la siembra en producción}')]
#[Description('Crea N envíos publicados de golpe, con direcciones reales de Celaya')]
class SembrarPedidos extends Command
{
    /**
     * Callejero de Celaya, Gto. Cada punto es una dirección que existe, con las coordenadas que
     * devuelve Google para ella —no un sorteo alrededor de un centro—, para que el mapa del Panel
     * muestre calles reconocibles y las rutas simuladas del modo TEST recorran avenidas de verdad.
     *
     * @var array<int, array{direccion: string, lat: float, lng: float}>
     */
    private const DIRECCIONES_CELAYA = [
        ['direccion' => 'Jardín Principal, Álvaro Obregón Sur s/n, Centro, 38000 Celaya, Gto.', 'lat' => 20.5214843, 'lng' => -100.8144210],
        ['direccion' => 'Templo de San Francisco, Perfecto I. Aranda s/n, Centro, 38000 Celaya, Gto.', 'lat' => 20.5225603, 'lng' => -100.8120624],
        ['direccion' => 'Bola de Agua, Independencia s/n, Centro, 38000 Celaya, Gto.', 'lat' => 20.5219539, 'lng' => -100.8124112],
        ['direccion' => 'Blvd. Adolfo López Mateos s/n, Centro, 38000 Celaya, Gto.', 'lat' => 20.5196132, 'lng' => -100.8141387],
        ['direccion' => 'Mercado Morelos, Morelos s/n, Centro, 38000 Celaya, Gto.', 'lat' => 20.5212838, 'lng' => -100.8117359],
        ['direccion' => 'Hospital General de Celaya, Juan B. Castelazo s/n, Valle del Real, 38020 Celaya, Gto.', 'lat' => 20.5255541, 'lng' => -100.8466975],
        ['direccion' => 'Central de Autobuses, Antonio Plaza s/n, El Vergel, 38078 Celaya, Gto.', 'lat' => 20.5138698, 'lng' => -100.8071187],
        ['direccion' => 'Universidad de Celaya, Carretera Panamericana km 269, Rancho Pinto, 38080 Celaya, Gto.', 'lat' => 20.5189910, 'lng' => -100.7859661],
        ['direccion' => 'Instituto Tecnológico de Celaya, Antonio García Cubas 600, Fovissste, 38010 Celaya, Gto.', 'lat' => 20.5360708, 'lng' => -100.8188007],
        ['direccion' => 'Estadio Miguel Alemán, Av. Irrigación s/n, Deportiva, 38010 Celaya, Gto.', 'lat' => 20.5358458, 'lng' => -100.8177560],
        ['direccion' => 'Walmart Celaya, Carr. Villagrán - Salamanca 758, 38064 Celaya, Gto.', 'lat' => 20.5197364, 'lng' => -100.8390428],
        ['direccion' => 'Soriana Los Sauces, 12 de Octubre 200, Los Sauces, 38027 Celaya, Gto.', 'lat' => 20.5323317, 'lng' => -100.8370360],
        ['direccion' => 'Cruz Roja Celaya, Av. Constituyentes s/n, Rosa Linda, 38060 Celaya, Gto.', 'lat' => 20.5180616, 'lng' => -100.8386777],
        ['direccion' => 'Parque Bicentenario, Av. Bicentenario s/n, San Isidro de Trojes, 38080 Celaya, Gto.', 'lat' => 20.5113987, 'lng' => -100.7781602],
        ['direccion' => 'Alameda de Celaya, Celaya, Gto.', 'lat' => 20.5292071, 'lng' => -100.8080506],
        ['direccion' => 'Parque Xochipilli, 38010 Celaya, Gto.', 'lat' => 20.5383258, 'lng' => -100.8283000],
    ];

    /** Quien pide el envío. Nombres corrientes, para que la lista del Panel no se lea como un test. */
    private const SOLICITANTES = [
        'María Fernanda Ramírez', 'José Luis Hernández', 'Guadalupe Martínez', 'Ricardo Olvera',
        'Ana Karen Zavala', 'Miguel Ángel Cruz', 'Verónica Aguilar', 'Jorge Alberto Pérez',
        'Claudia Ibarra', 'Fernando Rangel', 'Laura Elena Vázquez', 'Sergio Mendoza',
        'Alejandra Trejo', 'Óscar Gutiérrez', 'Patricia Solís', 'Iván Cervantes',
    ];

    /** Las tres del formulario del Panel (`Tenant\PedidoController::validarDatos`). */
    private const MODALIDADES_PAGO = [
        'REMITENTE_PAGA_ENVIO',
        'RECEPTOR_PAGA_ENVIO',
        'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
    ];

    /** Pares recogida-entrega ya usados en esta corrida, para no repetir el mismo trayecto. */
    private array $paresUsados = [];

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
            $centro = $this->centroDelSorteo();
            $origen = $centro === null
                ? 'callejero de Celaya'
                : "sorteo alrededor de {$centro['lat']},{$centro['lng']} (radio {$radioKm} km)";

            $this->line("Tenant <info>{$tenant->slug}</info> · ambiente <info>{$ambiente}</info> · {$origen}");

            $filas = [];

            for ($i = 1; $i <= $cantidad; $i++) {
                $pedido = $this->crear($ambiente, $centro, $radioKm, $tarifas, $i);

                if (! $this->option('sin-publicar')) {
                    $this->publicar($pedido);
                }

                // El mismo aviso que da el alta desde el Panel (spec tenant/027, RN-05): sin él
                // los envíos sembrados no aparecen en "Viajes en turno" hasta que alguien recargue.
                PedidoCreado::dispatch($pedido->id_pedido, $tenant->slug);

                $filas[] = [
                    $pedido->numero_pedido,
                    $pedido->estado,
                    number_format((float) $pedido->importe_envio, 2),
                    $pedido->nombre_solicitante,
                    $this->resumir($pedido->direccion_recogida),
                    $this->resumir($pedido->direccion_entrega),
                ];
            }

            $this->table(['Envío', 'Estado', 'Importe', 'Solicitante', 'Recogida', 'Entrega'], $filas);
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
     * `null` —el caso normal— significa "usa el callejero de Celaya". Solo con `--centro` explícito
     * se vuelve al sorteo al azar de la versión anterior, que es la salida para un tenant de otra
     * ciudad: ahí ninguna dirección del catálogo tendría sentido.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function centroDelSorteo(): ?array
    {
        $opcion = $this->option('centro');

        if (! is_string($opcion) || ! str_contains($opcion, ',')) {
            return null;
        }

        [$lat, $lng] = array_map('trim', explode(',', $opcion, 2));

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /** La dirección completa no cabe en la tabla de la consola; el Panel sí la guarda entera. */
    private function resumir(string $direccion): string
    {
        return str_contains($direccion, ',') ? strstr($direccion, ',', true) : $direccion;
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
     * @param  array{lat: float, lng: float}|null  $centro
     * @param  array{banderazo: float, km_incluidos: float, km_adicional: float}  $tarifas
     */
    private function crear(string $ambiente, ?array $centro, float $radioKm, array $tarifas, int $indice): Pedido
    {
        [$recogida, $entrega] = $centro === null
            ? $this->parDeDirecciones()
            : $this->parSorteado($centro, $radioKm, $indice);

        $km = $this->distanciaKm($recogida, $entrega);
        $importe = $tarifas['banderazo'] + (max(0.0, $km - $tarifas['km_incluidos']) * $tarifas['km_adicional']);

        // Igual que el formulario del Panel: `importe_cobro` solo tiene sentido cuando el receptor
        // paga los productos; en las otras dos modalidades queda en cero
        // (`Tenant\PedidoController::validarDatos`).
        $modalidad = self::MODALIDADES_PAGO[array_rand(self::MODALIDADES_PAGO)];
        $cobro = $modalidad === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS' ? (float) mt_rand(120, 950) : 0.0;

        $solicitante = self::SOLICITANTES[array_rand(self::SOLICITANTES)];

        return DB::transaction(function () use ($ambiente, $recogida, $entrega, $importe, $modalidad, $cobro, $solicitante) {
            // Mismo criterio que `Tenant\PedidoController@store`: el correlativo es del tenant
            // entero y no del ambiente, y `ambiente` se sella fuera del `create()` porque no está
            // en el `#[Fillable]` del modelo.
            $siguienteId = (int) (Pedido::withoutGlobalScopes()->max('id_pedido') ?? 0) + 1;

            $pedido = Pedido::create([
                'numero_pedido' => 'PED-'.str_pad((string) $siguienteId, 6, '0', STR_PAD_LEFT),
                'nombre_solicitante' => $solicitante,
                'telefono_solicitante' => $this->telefono(),
                'direccion_recogida' => $recogida['direccion'],
                'latitud_recogida' => $recogida['lat'],
                'longitud_recogida' => $recogida['lng'],
                'direccion_entrega' => $entrega['direccion'],
                'latitud_entrega' => $entrega['lat'],
                'longitud_entrega' => $entrega['lng'],
                'fecha_servicio' => now()->toDateString(),
                'lo_antes_posible' => true,
                'modalidad_pago' => $modalidad,
                'importe_envio' => round($importe, 2),
                'importe_cobro' => $cobro,
                'estado' => 'PENDIENTE',
            ]);

            $pedido->ambiente = $ambiente;
            $pedido->save();

            return $pedido;
        });
    }

    /** Un envío de 200 metros no lo captura nadie: dos puntos más cerca que esto no hacen pareja. */
    private const DISTANCIA_MINIMA_KM = 1.0;

    /**
     * Dos direcciones distintas del callejero, sin repetir un trayecto ya sembrado en esta corrida.
     * Con más envíos que combinaciones el catálogo se agota y se acepta la repetición: es preferible
     * a quedarse dando vueltas.
     *
     * @return array{0: array{direccion: string, lat: float, lng: float}, 1: array{direccion: string, lat: float, lng: float}}
     */
    private function parDeDirecciones(): array
    {
        $total = count(self::DIRECCIONES_CELAYA);

        for ($intento = 0; $intento < 60; $intento++) {
            $i = random_int(0, $total - 1);
            $j = random_int(0, $total - 1);

            if ($i === $j || in_array("{$i}-{$j}", $this->paresUsados, true)) {
                continue;
            }

            if ($this->distanciaKm(self::DIRECCIONES_CELAYA[$i], self::DIRECCIONES_CELAYA[$j]) < self::DISTANCIA_MINIMA_KM) {
                continue;
            }

            $this->paresUsados[] = "{$i}-{$j}";

            return [self::DIRECCIONES_CELAYA[$i], self::DIRECCIONES_CELAYA[$j]];
        }

        $i = random_int(0, $total - 1);
        $j = ($i + random_int(1, $total - 1)) % $total;

        return [self::DIRECCIONES_CELAYA[$i], self::DIRECCIONES_CELAYA[$j]];
    }

    /**
     * El modo `--centro`: puntos al azar y direcciones rotuladas, para un tenant fuera de Celaya.
     *
     * @param  array{lat: float, lng: float}  $centro
     * @return array{0: array{direccion: string, lat: float, lng: float}, 1: array{direccion: string, lat: float, lng: float}}
     */
    private function parSorteado(array $centro, float $radioKm, int $indice): array
    {
        $recogida = $this->puntoCercano($centro, $radioKm);
        $entrega = $this->puntoCercano($centro, $radioKm);

        return [
            ['direccion' => "Recogida de prueba {$indice}", ...$recogida],
            ['direccion' => "Entrega de prueba {$indice}", ...$entrega],
        ];
    }

    /** Teléfono de Celaya: lada 461 y siete dígitos, como los que teclea un despachador. */
    private function telefono(): string
    {
        return '461'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
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
