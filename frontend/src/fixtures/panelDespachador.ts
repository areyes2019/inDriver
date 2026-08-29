export interface ViajeEnTurno {
  numero_pedido: string
  cliente_nombre: string | null
  direccion_entrega: string
  estado: 'PENDIENTE' | 'PUBLICADO' | 'TOMADO' | 'ARRIBADO' | 'EN_CAMINO' | 'ARRIBADO_A_ENTREGA'
  lo_antes_posible: boolean
  hora_desde: string | null
}

export const viajesEnTurnoFixture: ViajeEnTurno[] = [
  {
    numero_pedido: 'PED-000101',
    cliente_nombre: 'Laura Medina',
    direccion_entrega: 'Av. Insurgentes Sur 1457, Col. Del Valle',
    estado: 'PUBLICADO',
    lo_antes_posible: true,
    hora_desde: null,
  },
  {
    numero_pedido: 'PED-000102',
    cliente_nombre: null,
    direccion_entrega: 'Calle Morelos 88, Centro',
    estado: 'PENDIENTE',
    lo_antes_posible: false,
    hora_desde: '09:30',
  },
  {
    numero_pedido: 'PED-000103',
    cliente_nombre: 'Grupo Ferretero del Norte',
    direccion_entrega: 'Blvd. Miguel de Cervantes Saavedra 301',
    estado: 'TOMADO',
    lo_antes_posible: false,
    hora_desde: '10:00',
  },
  {
    numero_pedido: 'PED-000104',
    cliente_nombre: 'Farmacias Vida',
    direccion_entrega: 'Av. Universidad 1330, Col. Xoco',
    estado: 'ARRIBADO',
    lo_antes_posible: false,
    hora_desde: '10:15',
  },
  {
    numero_pedido: 'PED-000105',
    cliente_nombre: null,
    direccion_entrega: 'Periférico Sur 4690, Jardines del Pedregal',
    estado: 'EN_CAMINO',
    lo_antes_posible: true,
    hora_desde: null,
  },
  {
    numero_pedido: 'PED-000106',
    cliente_nombre: 'Panadería La Espiga',
    direccion_entrega: 'Calle 5 de Mayo 12, Coyoacán',
    estado: 'ARRIBADO_A_ENTREGA',
    lo_antes_posible: false,
    hora_desde: '08:45',
  },
  {
    numero_pedido: 'PED-000107',
    cliente_nombre: 'Refaccionaria El Tornillo',
    direccion_entrega: 'Eje Central Lázaro Cárdenas 611',
    estado: 'PUBLICADO',
    lo_antes_posible: false,
    hora_desde: '11:20',
  },
]
