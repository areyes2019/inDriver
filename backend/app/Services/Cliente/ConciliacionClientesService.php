<?php

declare(strict_types=1);

namespace App\Services\Cliente;

use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\DireccionCliente;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\TelefonoEnRevision;
use App\Models\Tenant\WhatsappConversacion;
use App\Support\TelefonoMexicano;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teléfonos repetidos entre clientes de una flotilla (spec tenant/035, D20 y RN-15 a RN-19).
 *
 * `telefono_e164` es único: solo lo tiene un cliente cuyo teléfono no comparte nadie más. Si dos
 * clientes tienen el mismo número, ninguno lo lleva y el número queda "por revisar" hasta que el
 * AdminCliente los unifique. El sistema nunca une clientes por su cuenta.
 *
 * Los clientes `Inactivo` no cuentan: así un cliente ya unificado (que queda inactivo con su
 * teléfono original) no vuelve a aparecer como duplicado.
 */
class ConciliacionClientesService
{
    private const ESTADO_INACTIVO = 'Inactivo';

    /**
     * RN-15 y RN-19: después de dar de alta o editar un cliente. Le da su `telefono_e164` si nadie más
     * lo tiene; si lo tiene otro, lo deja sin él y manda el número a revisión. No cambia nada más del
     * cliente, así que el Panel y WhatsApp responden igual que antes.
     */
    public function asignarTelefono(Cliente $cliente): void
    {
        $e164 = $cliente->estado === self::ESTADO_INACTIVO ? null : TelefonoMexicano::e164($cliente->telefono);

        if ($cliente->telefono_e164 === $e164 && ($e164 === null || ! TelefonoEnRevision::whereKey($e164)->exists())) {
            return;
        }

        DB::transaction(function () use ($cliente, $e164) {
            $cliente->telefono_e164 = null;
            $cliente->save();

            if ($e164 === null) {
                return;
            }

            $revision = TelefonoEnRevision::find($e164);
            $dueno = Cliente::where('telefono_e164', $e164)
                ->whereKeyNot($cliente->id_cliente)
                ->first();

            if ($revision === null && $dueno === null) {
                try {
                    $cliente->telefono_e164 = $e164;
                    $cliente->save();

                    return;
                } catch (UniqueConstraintViolationException) {
                    // Otro cliente lo tomó en ese instante: se trata como repetido.
                    $cliente->telefono_e164 = null;
                    $dueno = Cliente::where('telefono_e164', $e164)->first();
                }
            }

            if ($dueno !== null) {
                $dueno->telefono_e164 = null;
                $dueno->save();
            }

            $this->anotarEnRevision($e164, array_filter([$dueno?->id_cliente, $cliente->id_cliente]));
        });
    }

    /**
     * RN-16: `clientes:normalizar-telefonos`. Agrupa los clientes (no inactivos) por su teléfono
     * normalizado. Sin `$aplicar` solo informa.
     *
     * @return array{unicos: int, repetidos: array<string, list<int>>, invalidos: int}
     */
    public function normalizarTodos(bool $aplicar): array
    {
        $clientes = Cliente::where('estado', '!=', self::ESTADO_INACTIVO)
            ->whereNotNull('telefono')
            ->get(['id_cliente', 'telefono', 'telefono_e164']);

        $invalidos = 0;
        /** @var array<string, list<int>> $grupos */
        $grupos = [];

        foreach ($clientes as $cliente) {
            $e164 = TelefonoMexicano::e164($cliente->telefono);
            if ($e164 === null) {
                $invalidos++;

                continue;
            }
            $grupos[$e164][] = $cliente->id_cliente;
        }

        $repetidos = array_filter($grupos, fn (array $ids) => count($ids) > 1);
        $unicos = array_filter($grupos, fn (array $ids) => count($ids) === 1);

        if ($aplicar) {
            DB::transaction(function () use ($repetidos, $unicos) {
                foreach ($repetidos as $e164 => $ids) {
                    Cliente::whereIn('id_cliente', $ids)->update(['telefono_e164' => null]);
                    $this->anotarEnRevision($e164, $ids);
                }

                foreach ($unicos as $e164 => $ids) {
                    Cliente::where('telefono_e164', $e164)->whereKeyNot($ids[0])->update(['telefono_e164' => null]);
                    Cliente::whereKey($ids[0])->update(['telefono_e164' => $e164]);
                    TelefonoEnRevision::whereKey($e164)->delete();
                }
            });
        }

        return ['unicos' => count($unicos), 'repetidos' => $repetidos, 'invalidos' => $invalidos];
    }

    /**
     * Pestaña "Por revisar" del Panel (6.8). Antes de listar, resuelve sola cualquier revisión que ya
     * no tenga repetidos (porque alguien cambió un teléfono o desactivó un cliente).
     *
     * @return Collection<int, array{telefono_e164: string, detectado_en: string, clientes: Collection<int, Cliente>}>
     */
    public function pendientes(): Collection
    {
        return TelefonoEnRevision::orderBy('detectado_en')->get()
            ->map(function (TelefonoEnRevision $revision) {
                $clientes = $this->clientesQueComparten($revision->telefono_e164);

                if ($clientes->count() < 2) {
                    $this->cerrarRevision($revision, $clientes->first());

                    return null;
                }

                return [
                    'telefono_e164' => $revision->telefono_e164,
                    'detectado_en' => $revision->detectado_en->toIso8601String(),
                    'clientes' => $clientes,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * RN-18: unifica a los clientes de un teléfono en revisión. Todo en una transacción.
     *
     * @param  int|null  $idCorreo  De qué cliente se conserva el correo de acceso, si dos o más tienen uno.
     */
    public function unificar(string $e164, int $idPrincipal, ?int $idCorreo, int $idUsuario): Cliente
    {
        return DB::transaction(function () use ($e164, $idPrincipal, $idCorreo, $idUsuario) {
            $revision = TelefonoEnRevision::lockForUpdate()->find($e164);
            if ($revision === null) {
                throw ValidationException::withMessages(['telefono_e164' => 'Ese teléfono ya no está por revisar.']);
            }

            $clientes = $this->clientesQueComparten($e164);
            $principal = $clientes->firstWhere('id_cliente', $idPrincipal);
            if ($principal === null) {
                throw ValidationException::withMessages(['id_principal' => 'El cliente principal no comparte ese teléfono.']);
            }

            $otros = $clientes->where('id_cliente', '!=', $idPrincipal)->values();
            $idsOtros = $otros->pluck('id_cliente')->all();
            $correo = $this->correoQueSeConserva($clientes, $idCorreo);

            Pedido::withoutGlobalScopes()->whereIn('id_cliente', $idsOtros)->update(['id_cliente' => $idPrincipal]);
            DireccionCliente::whereIn('id_cliente', $idsOtros)->update(['id_cliente' => $idPrincipal]);
            WhatsappConversacion::whereIn('id_cliente', $idsOtros)->update(['id_cliente' => $idPrincipal]);

            // El correo y el teléfono son únicos: primero se liberan en los demás.
            foreach ($otros as $otro) {
                $otro->tokens()->delete();
                $otro->forceFill([
                    'estado' => self::ESTADO_INACTIVO,
                    'telefono_e164' => null,
                    'email_acceso' => null,
                ])->save();
            }

            $principal->forceFill([
                'telefono_e164' => $e164,
                'email_acceso' => $correo['email'],
                'email_verificado_en' => $correo['verificado_en'],
            ])->save();

            $revision->delete();

            Auditoria::create([
                'id_usuario' => $idUsuario,
                'tabla_afectada' => 'clientes',
                'accion' => 'UNIFICACION',
                'descripcion' => mb_substr(sprintf(
                    'Unificación del teléfono %s: principal %d, unificados %s',
                    $e164,
                    $idPrincipal,
                    implode(',', $idsOtros),
                ), 0, 255),
            ]);

            return $principal->refresh();
        });
    }

    /**
     * @param  list<int>  $ids
     */
    private function anotarEnRevision(string $e164, array $ids): void
    {
        $revision = TelefonoEnRevision::find($e164);
        $todos = array_values(array_unique([...($revision?->clientes ?? []), ...$ids]));

        TelefonoEnRevision::updateOrCreate(
            ['telefono_e164' => $e164],
            ['clientes' => $todos, 'detectado_en' => $revision?->detectado_en ?? now()],
        );
    }

    /**
     * Quienes hoy comparten el teléfono, leído de sus datos y no de la lista guardada: un cliente
     * pudo cambiar de número o desactivarse después de entrar a revisión.
     *
     * @return Collection<int, Cliente>
     */
    private function clientesQueComparten(string $e164): Collection
    {
        $nacional = substr($e164, 3);

        return Cliente::where('estado', '!=', self::ESTADO_INACTIVO)
            ->where('telefono', 'like', '%'.substr($nacional, -4))
            ->withCount(['pedidos' => fn ($q) => $q->withoutGlobalScopes()])
            ->withMax(['pedidos' => fn ($q) => $q->withoutGlobalScopes()], 'created_at')
            ->orderBy('id_cliente')
            ->get()
            ->filter(fn (Cliente $c) => TelefonoMexicano::e164($c->telefono) === $e164)
            ->values();
    }

    private function cerrarRevision(TelefonoEnRevision $revision, ?Cliente $unico): void
    {
        DB::transaction(function () use ($revision, $unico) {
            $revision->delete();
            if ($unico !== null && $unico->telefono_e164 === null) {
                Cliente::where('telefono_e164', $revision->telefono_e164)->update(['telefono_e164' => null]);
                $unico->forceFill(['telefono_e164' => $revision->telefono_e164])->save();
            }
        });
    }

    /**
     * RN-18, paso 5.
     *
     * @param  Collection<int, Cliente>  $clientes
     * @return array{email: string|null, verificado_en: mixed}
     */
    private function correoQueSeConserva(Collection $clientes, ?int $idCorreo): array
    {
        $conCorreo = $clientes->whereNotNull('email_acceso')->values();

        $elegido = match (true) {
            $conCorreo->isEmpty() => null,
            $conCorreo->count() === 1 => $conCorreo->first(),
            default => $conCorreo->firstWhere('id_cliente', $idCorreo)
                ?? throw ValidationException::withMessages([
                    'id_correo' => 'Varios clientes entran a la app con su correo: elige cuál se conserva.',
                ]),
        };

        return ['email' => $elegido?->email_acceso, 'verificado_en' => $elegido?->email_verificado_en];
    }
}
