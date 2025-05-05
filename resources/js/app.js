 //import './bootstrap';

import {createApp} from "vue";
import App from './App.vue';
import router from './router';
import { createPinia } from 'pinia'
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';

import Aura from '@primevue/themes/aura';
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';

import '../sass/styles.scss';
import '../sass/app.scss';

const app = createApp(App);
const pinia = createPinia();

 // Глобальная конфигурация axios
 axios.defaults.baseURL = '/api';
 const token = localStorage.getItem('jwt_token');
 if (token) {
     axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
 }

 // Перехватчик для обработки 401 ошибки
 axios.interceptors.response.use(
     response => response,
     async error => {
         if (error.response?.status === 401) {
             const authStore = useAuthStore();
             authStore.clearAuth();
             await router.push('/login');
         }
         return Promise.reject(error);
     }
 );

app.use(router);
app.use(pinia);
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.app-dark'
        }
    },
    locale: {
        firstDayOfWeek: 1, // 1 = Понедельник, 0 = Воскресенье
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

app.mount('#app');
