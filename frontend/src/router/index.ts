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
      // spec tenant/001 (enmienda "el slug va inmediatamente después del dominio", RN-S2): la raíz
      // del tenant es su única entrada. Sin sesión pinta el login aquí mismo; con sesión, el
      // `beforeEach` la manda al Panel. Conserva el nombre `tenant-login` para que todo lo que ya
      // "manda al login" por nombre siga funcionando sin tocarlo.
      path: '/:slug',
      name: 'tenant-login',
      component: () => import('../views/tenant/LoginView.vue'),
    },
    {
      // Compatibilidad con los correos ya enviados (RN-S3/RN-S4): `/t/{slug}/login` cae aquí
      // después de la redirección de abajo, y `/{slug}/login` a secas también.
      path: '/:slug/login',
      redirect: (to) => ({ name: 'tenant-login', params: { slug: to.params.slug } }),
    },
    {
      // Las URLs viejas `/t/{slug}[/...]` siguen funcionando: se les quita el `/t/` y se conserva
      // el resto de la ruta (RN-S4). Se queda indefinidamente; los marcadores y correos no caducan.
      path: '/t/:slug/:resto(.*)*',
      redirect: (to) => {
        const resto = to.params.resto
        const cola = Array.isArray(resto) ? resto.join('/') : (resto ?? '')
        return `/${to.params.slug as string}${cola ? `/${cola}` : ''}`
      },
    },
    {
      path: '/:slug/forgot-password',
      name: 'tenant-forgot-password',
      component: () => import('../views/tenant/ForgotPasswordView.vue'),
    },
    {
      path: '/:slug/reset-password/:token',
      name: 'tenant-reset-password',
      component: () => import('../views/tenant/ResetPasswordView.vue'),
    },
    {
      path: '/:slug/panel',
      name: 'tenant-panel',
      component: () => import('../views/tenant/panel/PanelView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/usuarios',
      name: 'tenant-usuarios-lista',
      component: () => import('../views/tenant/usuarios/ListaUsuariosView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/usuarios/crear',
      name: 'tenant-usuarios-crear',
      component: () => import('../views/tenant/usuarios/CrearUsuarioView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/usuarios/:id/editar',
      name: 'tenant-usuarios-editar',
      component: () => import('../views/tenant/usuarios/EditarUsuarioView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/despachadores',
      name: 'tenant-despachadores-lista',
      component: () => import('../views/tenant/despachadores/ListaDespachadoresView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/conductores',
      name: 'tenant-conductores-lista',
      component: () => import('../views/tenant/conductores/ListaConductoresView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/conductores/crear',
      name: 'tenant-conductores-crear',
      component: () => import('../views/tenant/conductores/CrearConductorView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/conductores/:id/editar',
      name: 'tenant-conductores-editar',
      component: () => import('../views/tenant/conductores/EditarConductorView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/clientes',
      name: 'tenant-clientes-lista',
      component: () => import('../views/tenant/clientes/ListaClientesView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/clientes/crear',
      name: 'tenant-clientes-crear',
      component: () => import('../views/tenant/clientes/CrearClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/clientes/:id/editar',
      name: 'tenant-clientes-editar',
      component: () => import('../views/tenant/clientes/EditarClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/clientes/:id/direcciones',
      name: 'tenant-direcciones-lista',
      component: () =>
        import('../views/tenant/clientes/direcciones/ListaDireccionesClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/clientes/:id/direcciones/crear',
      name: 'tenant-direcciones-crear',
      component: () => import('../views/tenant/clientes/direcciones/CrearDireccionClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/clientes/:id/direcciones/:direccionId/editar',
      name: 'tenant-direcciones-editar',
      component: () =>
        import('../views/tenant/clientes/direcciones/EditarDireccionClienteView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/configuracion',
      name: 'tenant-configuracion',
      component: () => import('../views/tenant/configuracion/ConfiguracionView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/reportes/pagos-conductores',
      name: 'tenant-reporte-pagos-conductores',
      component: () => import('../views/tenant/reportes/PagosConductoresView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/comisiones',
      name: 'tenant-comisiones',
      component: () => import('../views/tenant/comisiones/ComisionesView.vue'),
      meta: { requiresTenantAuth: true },
    },
    {
      path: '/:slug/panel/cambiar-password',
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

  // spec tenant/001 (enmienda "el slug va inmediatamente después del dominio", RN-S2): la raíz del
  // tenant decide sola. Se resuelve la sesión antes de dejar pintar nada, así quien ya la tiene
  // nunca ve el formulario "un instante" (RN-S8); el guard de `tenant-panel` decide después si es
  // operativo o va a Clientes (spec 007, RN-A1). Antes esto era una redirección `/t/:slug` → Panel.
  if (to.name === 'tenant-login') {
    const slug = to.params.slug as string
    const auth = useTenantAuthStore()
    if (!auth.checked || auth.slug !== slug) {
      await auth.fetchMe(slug)
    }

    if (auth.isAuthenticated) {
      return { name: 'tenant-panel', params: { slug } }
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

    // El "operativo" (quien ve el Panel) depende de la configuración del tenant, no solo del rol:
    // Despachador cuando usa despachadores, AdminCliente cuando no (spec tenant/011). La regla vive
    // en el store, compartida con el login y el layout (spec tenant/007, RN-A1). Un rol que no es
    // ninguno de los dos tampoco es operativo, así que la comprobación de rol de antes sobra.
    const usaDespachadores = auth.usuario?.usar_despachadores === 'Sí'

    if (to.name === 'tenant-panel' && !auth.esOperativo) {
      return { name: 'tenant-clientes-lista', params: { slug } }
    }

    if (to.name === 'tenant-despachadores-lista' && !usaDespachadores) {
      return { name: 'tenant-clientes-lista', params: { slug } }
    }

    if (
      (to.name === 'tenant-configuracion' ||
        to.name === 'tenant-reporte-pagos-conductores' ||
        to.name === 'tenant-comisiones') &&
      auth.usuario?.rol !== 'AdminCliente'
    ) {
      return { name: 'tenant-clientes-lista', params: { slug } }
    }
  }

  return true
})

export default router
