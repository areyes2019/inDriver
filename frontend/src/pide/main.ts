import './pide.css'
import '@/lib/icons'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import PideApp from './PideApp.vue'
import router from './router'

createApp(PideApp).use(createPinia()).use(router).mount('#pide')
