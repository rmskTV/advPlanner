import { createApp } from "vue";
import App from './App.vue';
import router from './router';
import { createPinia } from 'pinia';
import axios from 'axios';

// PrimeVue imports...
import Aura from '@primevue/themes/aura';
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import ConfirmDialog from 'primevue/confirmdialog';

import '../sass/styles.scss';
import '../sass/app.scss';
import {useAuthStore} from "./stores/auth.js";

// 1. Сначала создаём приложение и pinia
const app = createApp(App);
const pinia = createPinia();
app.use(pinia);

// 2. Только после использования pinia можно получить доступ к хранилищу
const authStore = useAuthStore();

// 3. Настраиваем axios
axios.defaults.baseURL = '/api';
if (authStore.token) {
    axios.defaults.headers.common['Authorization'] = `Bearer ${authStore.token}`;
}

// 4. Инициализируем аутентификацию ДО монтирования приложения
authStore.initializeAuth().finally(() => {
    // 5. Настраиваем перехватчик после инициализации auth
    axios.interceptors.response.use(
        response => response,
        async error => {
            if (error.response?.status === 401) {
                authStore.clearAuth();
                await router.push('/login');
            }
            return Promise.reject(error);
        }
    );

    // 6. Подключаем остальные плагины
    app.use(router);
    app.use(PrimeVue, {
        theme: {
            preset: Aura,
            options: {
                darkModeSelector: '.app-dark'
            }
        },
        locale: {
            firstDayOfWeek: 1,
            dayNames: ["Воскресенье", "Понедельник", "Вторник", "Среда", "Четверг", "Пятница", "Суббота"],
            dayNamesShort: ["Вс", "Пн", "Вт", "Ср", "Чт", "Пт", "Сб"],
            dayNamesMin: ["Вс", "Пн", "Вт", "Ср", "Чт", "Пт", "Сб"],
            monthNames: ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"],
            monthNamesShort: ["Янв", "Фев", "Мар", "Апр", "Май", "Июн", "Июл", "Авг", "Сен", "Окт", "Ноя", "Дек"],
            today: 'Сегодня',
            clear: 'Очистить'
        }
    });
    app.use(ToastService);
    app.use(ConfirmationService);
    app.component('ConfirmDialog', ConfirmDialog);
    // 7. Монтируем приложение только после полной инициализации
    app.mount('#app');
});
