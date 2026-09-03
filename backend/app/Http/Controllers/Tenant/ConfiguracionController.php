<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\CompraPaquete;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\VentaViajeConductor;
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
     * @return array<string, mixed>
     */
    private function estadoActual(): array
    {
        $saldoTenant = (int) CompraPaquete::sum('cantidad_viajes') - (int) VentaViajeConductor::sum('cantidad_viajes');

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
            'saldo_viajes_tenant' => $saldoTenant,
        ];
    }
}
