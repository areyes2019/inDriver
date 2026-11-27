import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useMarcaStore } from './stores/marca'
import { useSesionStore } from './stores/sesion'

declare module 'vue-router' {
  interface RouteMeta {
    /** Pantallas sin sesión: registro, entrar, código. */
    publica?: boolean
    /** Muestra la barra inferior Inicio / Mis pedidos / Mi cuenta. */
    barra?: boolean
  }
}

const rutas: RouteRecordRaw[] = [
  { path: '/', redirect: '/motopanda' },
  {
    path: '/:slug',
    children: [
      { path: '', redirect: (to) => `/${String(to.params.slug)}/inicio` },
      {
        path: 'registro',
        name: 'registro',
        component: () => import('./views/RegistroView.vue'),
        meta: { publica: true },
      },
      {
        path: 'entrar',
        name: 'entrar',
        component: () => import('./views/EntrarView.vue'),
        meta: { publica: true },
      },
      {
        path: 'codigo',
        name: 'codigo',
        component: () => import('./views/CodigoView.vue'),
        meta: { publica: true },
      },
      {
        path: 'no-disponible',
        name: 'no-disponible',
        component: () => import('./views/NoDisponibleView.vue'),
        meta: { publica: true },
      },
      {
        path: 'inicio',
        name: 'inicio',
        component: () => import('./views/InicioView.vue'),
        meta: { barra: true },
      },
      {
        path: 'pedir/:tipo(enviar|recibir)',
        name: 'pedir',
        component: () => import('./views/AsistenteView.vue'),
      },
      {
        path: 'pedido/:id(\\d+)',
        name: 'pedido',
        component: () => import('./views/SeguimientoView.vue'),
      },
      {
        path: 'pedidos',
        name: 'pedidos',
        component: () => import('./views/MisPedidosView.vue'),
        meta: { barra: true },
      },
      {
        path: 'pedidos/:id(\\d+)',
        name: 'detalle',
        component: () => import('./views/DetallePedidoView.vue'),
      },
      {
        path: 'cuenta',
        name: 'cuenta',
        component: () => import('./views/CuentaView.vue'),
        meta: { barra: true },
      },
      {
        path: 'cuenta/direcciones',
        name: 'direcciones',
        component: () => import('./views/DireccionesView.vue'),
      },
      {
        path: 'cuenta/direcciones/:id(nueva|\\d+)',
        name: 'direccion',
        component: () => import('./views/FormDireccionView.vue'),
      },
      {
        path: 'avisos',
        name: 'avisos',
        component: () => import('./views/NotificacionesView.vue'),
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory('/pide/'),
  routes: rutas,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const slug = to.params.slug
  if (typeof slug !== 'string') return true

  const marca = useMarcaStore()
  const sesion = useSesionStore()
  const datos = await marca.cargar(slug)
  await sesion.restaurar()

  // RN-01: con la app apagada solo queda el aviso.
  if (!datos.disponible && to.name !== 'no-disponible') {
    return { name: 'no-disponible', params: { slug } }
  }
  if (datos.disponible && to.name === 'no-disponible') {
    return { name: 'inicio', params: { slug } }
  }

  // RN-02 / D1: sin sesión solo existen registro, entrar y código.
  if (!to.meta.publica && !sesion.activa) {
    return { name: 'registro', params: { slug } }
  }
  if (to.meta.publica && sesion.activa && to.name !== 'no-disponible') {
    return { name: 'inicio', params: { slug } }
  }
  return true
})

export default router
