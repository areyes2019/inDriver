export interface PedidoAsignadoFicticio {
  numero_pedido: string
  estado: 'TOMADO' | 'ARRIBADO' | 'EN_CAMINO' | 'ARRIBADO_A_ENTREGA'
  direccion_recogida: string
  latitud_recogida: number
  longitud_recogida: number
  direccion_entrega: string
  latitud_entrega: number
  longitud_entrega: number
}

export interface ConductorActivoFicticio {
  id: number
  nombre: string
  estado: 'ONLINE' | 'OFFLINE' | 'DISPONIBLE' | 'OCUPADO' | 'DESCANSO' | 'FUERA_DE_SERVICIO'
  vehiculo_placa: string
  latitud: number
  longitud: number
  pedidoAsignado?: PedidoAsignadoFicticio
}

export const conductoresActivosFixture: ConductorActivoFicticio[] = [
  {
    id: 1,
    nombre: 'Jorge Ramírez',
    estado: 'OCUPADO',
    vehiculo_placa: 'MTY-4521',
    latitud: 19.428,
    longitud: -99.1276,
    pedidoAsignado: {
      numero_pedido: 'PED-000103',
      estado: 'TOMADO',
      direccion_recogida: 'Blvd. Miguel de Cervantes Saavedra 301',
      latitud_recogida: 19.4435,
      longitud_recogida: -99.2011,
      direccion_entrega: 'Av. Insurgentes Sur 1457, Col. Del Valle',
      latitud_entrega: 19.3833,
      longitud_entrega: -99.1719,
    },
  },
  {
    id: 2,
    nombre: 'Ana Torres',
    estado: 'OCUPADO',
    vehiculo_placa: 'GDL-7789',
    latitud: 19.3925,
    longitud: -99.161,
    pedidoAsignado: {
      numero_pedido: 'PED-000106',
      estado: 'EN_CAMINO',
      direccion_recogida: 'Calle Morelos 88, Centro',
      latitud_recogida: 19.4342,
      longitud_recogida: -99.1386,
      direccion_entrega: 'Calle 5 de Mayo 12, Coyoacán',
      latitud_entrega: 19.3467,
      longitud_entrega: -99.1618,
    },
  },
  {
    id: 3,
    nombre: 'Luis Fernández',
    estado: 'DISPONIBLE',
    vehiculo_placa: 'CDMX-1102',
    latitud: 19.4147,
    longitud: -99.1729,
  },
  {
    id: 4,
    nombre: 'Marcela Ortiz',
    estado: 'ONLINE',
    vehiculo_placa: 'QRO-3390',
    latitud: 19.4506,
    longitud: -99.1327,
  },
  {
    id: 5,
    nombre: 'Diego Salgado',
    estado: 'DESCANSO',
    vehiculo_placa: 'PUE-6644',
    latitud: 19.3661,
    longitud: -99.1544,
  },
  {
    id: 6,
    nombre: 'Karla Núñez',
    estado: 'OFFLINE',
    vehiculo_placa: 'EDM-9981',
    latitud: 19.4978,
    longitud: -99.1269,
  },
  {
    id: 7,
    nombre: 'Roberto Chávez',
    estado: 'FUERA_DE_SERVICIO',
    vehiculo_placa: 'TOL-2245',
    latitud: 19.3792,
    longitud: -99.2005,
  },
]
