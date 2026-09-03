import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import { useAdminAuthStore } from '@/stores/adminAuth'
import { useTenantAuthStore } from '@/stores/tenantAuth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    {
      path: '/about',
      name: 'about',
      // route level code-splitting
      // this generates a separate chunk (About.[hash].js) for this route
      // which is lazy-loaded when the route is visited.
      component: () => import('../views/AboutView.vue'),
    },
    {
      path: '/admin/login',
      name: 'admin-login',
      component: () => import('../views/admin/LoginView.vue'),
    },
    {
      path: '/admin/forgot-password',
      name: 'admin-forgot-password',
      component: () => import('../views/admin/ForgotPasswordView.vue'),
    },
    {
      path: '/admin/reset-password/:token',
      name: 'admin-reset-password',
      component: () => import('../views/admin/ResetPasswordView.vue'),
    },
    {
      path: '/admin',
      name: 'admin-dashboard',
      component: () => import('../views/admin/DashboardView.vue'),
      meta: { requiresAdminAuth: true },
    },
    {
      path: '/admin/tenants',
      name: 'admin-tenants-lista',
      component: () => import('../views/admin/tenants/ListaTenantsView.vue'),
      meta: { requiresAdminAuth: true },
    },
    {
      path: '/admin/tenants/crear',
      name: 'admin-tenants-crear',
      component: () => import('../views/admin/tenants/CrearTenantView.vue'),
      meta: { requiresAdminAuth: true },
    },
    {
      path: '/admin/tenants/:id',
      name: 'admin-tenants-detalle',
      component: () => import('../views/admin/tenants/DetalleTenantView.vue'),
      meta: { requiresAdminAuth: true },
    },
    {
      path: '/admin/tenants/:id/editar',
      name: 'admin-tenants-editar',
      component: () => import('../views/admin/tenants/EditarTenantView.vue'),
      meta: { requiresAdminAuth: true },
    },
    {
      path: '/admin/paquetes',
      name: 'admin-paquetes-lista',
      component: () => import('../views/admin/paquetes/ListaPaquetesView.vue'),
      meta: { requiresAdminAuth: true },
    },
    {
      path: '/admin/paquetes/crear',
      name: 'admin-paquetes-crear',
      component: () => import('../views/admin/paquetes/CrearPaqueteView.vue'),
      meta: { requiresAdminAuth: true },
    },
    {
      path: '/admin/paquetes/:id/editar',
      name: 'admin-paquetes-editar',
      component: () => import('../views/admin/paquetes/EditarPaqueteView.vue'),
      meta: { requiresAdminAuth: true },
    },
    ...(import.meta.env.DEV
      ? [
          {
            path: '/admin/style-guide',
            name: 'admin-style-guide',
            component: () => import('../views/admin/StyleGuideView.vue'),
          },
        ]
      : []),
    {
      path: '/t/:slug/login',
      name: 'tenant-login',
      component: () => import('../views/tenant/LoginView.vue'),
    },
    {
      path: '/t/:slug/forgot-password',
      name: 'tenant-forgot-password',
      component: () => import('../views/tenant/ForgotPasswordView.vue'),
    },
    {
      path: '/t/:slug/reset-password/:token',
      name: 'tenant-reset-password',
      component: () => import('../views/tenant/ResetPasswordView.vue'),
    },
    {
      path: '/t/:slug/panel',
      name: 'tenant-panel',
      component: () => import('../views/tenant/panel/PanelView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/usuarios',
      name: 'tenant-usuarios-lista',
      component: () => import('../views/tenant/usuarios/ListaUsuariosView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/usuarios/crear',
      name: 'tenant-usuarios-crear',
      component: () => import('../views/tenant/usuarios/CrearUsuarioView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/usuarios/:id/editar',
      name: 'tenant-usuarios-editar',
      component: () => import('../views/tenant/usuarios/EditarUsuarioView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/despachadores',
      name: 'tenant-despachadores-lista',
      component: () => import('../views/tenant/despachadores/ListaDespachadoresView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/conductores',
      name: 'tenant-conductores-lista',
      component: () => import('../views/tenant/conductores/ListaConductoresView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/conductores/crear',
      name: 'tenant-conductores-crear',
      component: () => import('../views/tenant/conductores/CrearConductorView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/conductores/:id/editar',
      name: 'tenant-conductores-editar',
      component: () => import('../views/tenant/conductores/EditarConductorView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/vehiculos',
      name: 'tenant-vehiculos-lista',
      component: () => import('../views/tenant/vehiculos/ListaVehiculosView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/vehiculos/crear',
      name: 'tenant-vehiculos-crear',
      component: () => import('../views/tenant/vehiculos/CrearVehiculoView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/vehiculos/:id/editar',
      name: 'tenant-vehiculos-editar',
      component: () => import('../views/tenant/vehiculos/EditarVehiculoView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/asignaciones',
      name: 'tenant-asignaciones-lista',
      component: () => import('../views/tenant/asignaciones/ListaAsignacionesView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/asignaciones/asignar',
      name: 'tenant-asignaciones-asignar',
      component: () => import('../views/tenant/asignaciones/AsignarVehiculoView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/clientes',
      name: 'tenant-clientes-lista',
      component: () => import('../views/tenant/clientes/ListaClientesView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/clientes/crear',
      name: 'tenant-clientes-crear',
      component: () => import('../views/tenant/clientes/CrearClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/clientes/:id/editar',
      name: 'tenant-clientes-editar',
      component: () => import('../views/tenant/clientes/EditarClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/clientes/:id/direcciones',
      name: 'tenant-direcciones-lista',
      component: () =>
        import('../views/tenant/clientes/direcciones/ListaDireccionesClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/clientes/:id/direcciones/crear',
      name: 'tenant-direcciones-crear',
      component: () => import('../views/tenant/clientes/direcciones/CrearDireccionClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/clientes/:id/direcciones/:direccionId/editar',
      name: 'tenant-direcciones-editar',
      component: () =>
        import('../views/tenant/clientes/direcciones/EditarDireccionClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/configuracion',
      name: 'tenant-configuracion',
      component: () => import('../views/tenant/configuracion/ConfiguracionView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/reportes/pagos-conductores',
      name: 'tenant-reporte-pagos-conductores',
      component: () => import('../views/tenant/reportes/PagosConductoresView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/t/:slug/panel/cambiar-password',
      name: 'tenant-cambiar-password',
      component: () => import('../views/tenant/cuenta/CambiarPasswordView.vue'),
      meta: { requiresTenantAuth: true },
    },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.requiresAdminAuth) {
    const auth = useAdminAuthStore()
    if (!auth.checked) {
      await auth.fetchMe()
    }

    if (!auth.isAuthenticated) {
      return { name: 'admin-login' }
    }
  }

  if (to.meta.requiresTenantAuth) {
    const slug = to.params.slug as string
    const auth = useTenantAuthStore()
    if (!auth.checked || auth.slug !== slug) {
      await auth.fetchMe(slug)
    }

    if (!auth.isAuthenticated) {
      return { name: 'tenant-login', params: { slug } }
    }

    if (
      to.name === 'tenant-panel' &&
      !['Despachador', 'AdminCliente'].includes(auth.usuario?.rol ?? '')
    ) {
      return { name: 'tenant-clientes-lista', params: { slug } }
    }

    // El "operativo" (quien ve el Panel) depende de la configuración del tenant, no solo del rol:
    // Despachador cuando usa despachadores, AdminCliente cuando no (spec tenant/011).
    const usaDespachadores = auth.usuario?.usar_despachadores === 'Sí'
    const esOperativo =
      (auth.usuario?.rol === 'Despachador' && usaDespachadores) ||
      (auth.usuario?.rol === 'AdminCliente' && !usaDespachadores)

    if (to.name === 'tenant-panel' && !esOperativo) {
      return { name: 'tenant-clientes-lista', params: { slug } }
    }

    if (to.name === 'tenant-despachadores-lista' && !usaDespachadores) {
      return { name: 'tenant-clientes-lista', params: { slug } }
    }

    if (
      (to.name === 'tenant-configuracion' || to.name === 'tenant-reporte-pagos-conductores') &&
      auth.usuario?.rol !== 'AdminCliente'
    ) {
      return { name: 'tenant-clientes-lista', params: { slug } }
    }
  }

  return true
})

export default router
