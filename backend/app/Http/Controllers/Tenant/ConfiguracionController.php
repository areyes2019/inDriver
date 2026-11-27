<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\AutomaticoBitacora;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
use App\Services\ModoAutomaticoService;
use App\Support\SaldoViajesTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConfiguracionController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->estadoActual());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tarifa_banderazo' => ['required', 'numeric', 'min:0'],
            'km_incluidos_banderazo' => ['required', 'numeric', 'gt:0'],
            'tarifa_km_adicional' => ['required', 'numeric', 'min:0'],
            'modalidad_conductores' => ['required', Rule::in(['Prepago', 'Comision'])],
            'costo_viaje_prepago' => ['required_if:modalidad_conductores,Prepago', 'nullable', 'numeric', 'min:0'],
            'comision_porcentaje' => ['required_if:modalidad_conductores,Comision', 'nullable', 'numeric', 'min:0', 'max:100'],
            'usar_despachadores' => ['required', Rule::in(['Sí', 'No'])],
        ]);

        $usabaDespachadoresAntes = ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No');

        DB::transaction(function () use ($data, $usabaDespachadoresAntes) {
            ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, (string) $data['tarifa_banderazo']);
            ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, (string) $data['km_incluidos_banderazo']);
            ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, (string) $data['tarifa_km_adicional']);
            ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, $data['modalidad_conductores']);
            ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, isset($data['costo_viaje_prepago']) ? (string) $data['costo_viaje_prepago'] : null);
            ConfiguracionTenant::establecer(ConfiguracionTenant::COMISION_PORCENTAJE, isset($data['comision_porcentaje']) ? (string) $data['comision_porcentaje'] : null);
            ConfiguracionTenant::establecer(ConfiguracionTenant::USAR_DESPACHADORES, $data['usar_despachadores']);

            if ($usabaDespachadoresAntes === 'Sí' && $data['usar_despachadores'] === 'No') {
                Despachador::whereIn('estado', ['Activo', 'Suspendido'])->update(['estado' => 'Inactivo']);
            }
        });

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'configuraciones_tenant',
            'accion' => 'EDICION',
            'descripcion' => 'Edición de la configuración de tarifas y comisión del tenant',
        ]);

        return response()->json($this->estadoActual());
    }

    /**
     * Interruptor del modo automático (spec tenant/034, §7). `Pausado` no se puede mandar: lo pone
     * solo el sistema (RN-24). Mandar `Si` desde `Pausado` lo reactiva (RN-25).
     *
     * La ruta ya lo restringe a AdminCliente; el Despachador lo ve por `show()` (D2).
     */
    public function modoAutomatico(Request $request, ModoAutomaticoService $modo): JsonResponse
    {
        $data = $request->validate([
            'modo_automatico' => ['required', Rule::in([ModoAutomaticoService::SI, ModoAutomaticoService::NO])],
        ]);

        $modo->cambiar($data['modo_automatico']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'configuraciones_tenant',
            'accion' => 'EDICION',
            'descripcion' => $data['modo_automatico'] === ModoAutomaticoService::SI
                ? 'Se prendió el modo automático'
                : 'Se apagó el modo automático',
        ]);

        return response()->json(['modo_automatico' => $modo->estado()]);
    }

    /**
     * Las últimas 100 entradas de la bitácora del modo automático (spec tenant/034, RN-26).
     */
    public function bitacoraAutomatico(): JsonResponse
    {
        $entradas = AutomaticoBitacora::query()
            ->with(['pedido' => fn ($q) => $q->withoutGlobalScopes()->select('id_pedido', 'numero_pedido')])
            ->orderByDesc('created_at')
            ->orderByDesc('id_bitacora')
            ->limit(100)
            ->get()
            ->map(fn (AutomaticoBitacora $entrada) => [
                'id_bitacora' => $entrada->id_bitacora,
                'accion' => $entrada->accion,
                'detalle' => $entrada->detalle,
                'id_pedido' => $entrada->id_pedido,
                'numero_pedido' => $entrada->pedido?->numero_pedido,
                'created_at' => $entrada->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $entradas]);
    }

    /**
     * @return array<string, mixed>
     */
    private function estadoActual(): array
    {
        $tarifaBanderazo = ConfiguracionTenant::obtener(ConfiguracionTenant::BANDERAZO);
        $kmIncluidosBanderazo = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_INCLUIDOS);
        $tarifaKmAdicional = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_ADICIONAL);

        return [
            'tarifa_banderazo' => $tarifaBanderazo,
            'km_incluidos_banderazo' => $kmIncluidosBanderazo,
            'tarifa_km_adicional' => $tarifaKmAdicional,
            'tarifas_configuradas' => $tarifaBanderazo !== null && $kmIncluidosBanderazo !== null && $tarifaKmAdicional !== null,
            'modalidad_conductores' => ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago'),
            'costo_viaje_prepago' => ConfiguracionTenant::obtener(ConfiguracionTenant::COSTO_VIAJE_PREPAGO),
            'comision_porcentaje' => ConfiguracionTenant::obtener(ConfiguracionTenant::COMISION_PORCENTAJE),
            'usar_despachadores' => ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No'),
            'saldo_viajes_tenant' => SaldoViajesTenant::actualONull(),
            'modo_automatico' => ConfiguracionTenant::obtener(ConfiguracionTenant::MODO_AUTOMATICO, ModoAutomaticoService::NO),
            'app_clientes' => ConfiguracionTenant::obtener(ConfiguracionTenant::APP_CLIENTES, 'No'),
        ];
    }

    /**
     * Interruptor de la app de clientes (spec tenant/035, D17). Apagado, la app de esta flotilla no
     * deja registrarse, entrar ni pedir.
     */
    public function appClientes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_clientes' => ['required', Rule::in(['Si', 'No'])],
        ]);

        ConfiguracionTenant::establecer(ConfiguracionTenant::APP_CLIENTES, $data['app_clientes']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'configuraciones_tenant',
            'accion' => 'EDICION',
            'descripcion' => $data['app_clientes'] === 'Si'
                ? 'Se prendió la app de clientes'
                : 'Se apagó la app de clientes',
        ]);

        return response()->json(['app_clientes' => $data['app_clientes']]);
    }
}
