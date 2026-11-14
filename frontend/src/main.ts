import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import router from '@/router'
import i18n from '@/i18n'
import App from './App.vue'
import './assets/index.css'

const app = createApp(App)
app.use(createPinia())
app.use(PiniaColada)
app.use(i18n)
app.use(router)
app.mount('#app')
