import { defineStore } from 'pinia';
import axios from 'axios';
import router from '@/router';

const BASE_API_URL = 'http://localhost/api';
export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: localStorage.getItem('jwt_token') || null,
        isLoading: false,
        error: null,
        refreshTimeout: null
    }),
    actions: {
        // Инициализация при перезагрузке
        async initializeAuth() {
            if (!this.token) return;

            try {
                // 1. Временная установка заголовка из localStorage
                axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;

                // 2. Обновляем токен
                await this.refreshToken();
            } catch (error) {
                this.clearAuth();
            }
        },


        async login(credentials) {
            this.isLoading = true;
            this.error = null;

            try {
                const response = await axios.post(BASE_API_URL + '/auth/login', credentials);
                this.setAuthData(response.data);
                this.scheduleTokenRefresh(response.data.expires_in);
                router.push('/advBlocksGrid');
            } catch (error) {
                this.error = error.response?.data?.error || 'Login failed';
                throw error;
            } finally {
                this.isLoading = false;
            }
        },

        async refreshToken() {
            try {
                const response = await axios.post(BASE_API_URL + '/auth/refresh-token');
                this.setAuthData(response.data);
                this.scheduleTokenRefresh(response.data.expires_in);
                //return true;
            } catch (error) {
                this.clearAuth();
                throw error; // Пробрасываем ошибку для обработки в initializeAuth
            }
        },

        setAuthData(authData) {
            this.user = authData.user;
            this.token = authData.access_token;
            localStorage.setItem('jwt_token', this.token);
            localStorage.setItem('jwt_token_user', JSON.stringify(this.user));
            axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
        },

        // Новый метод: планирование обновления токена
        scheduleTokenRefresh(expiresIn) {
            // Очищаем предыдущий таймер
            if (this.refreshTimeout) clearTimeout(this.refreshTimeout);

            // Обновляем токен за 5 минут до истечения (в миллисекундах)
            const refreshTime = (expiresIn - 300) * 1000;
            //const refreshTime = 25 * 1000;

            this.refreshTimeout = setTimeout(async () => {
                await this.refreshToken();
            }, refreshTime);
        },

        async logout() {
            try {
                await axios.post(BASE_API_URL + '/auth/logout');
            } finally {
                this.clearAuth();
                router.push('/login');
            }
        },

        clearAuth() {
            this.user = null;
            this.token = null;
            localStorage.removeItem('jwt_token');
            localStorage.removeItem('jwt_token_user');
            delete axios.defaults.headers.common['Authorization'];
        },

        async checkAuth() {
            if (!this.token) return false;

            try {
                const response = await axios.get(BASE_API_URL + '/auth/me');
                this.user = response.data;
                return true;
            } catch (error) {
                this.clearAuth();
                return false;
            }
        }
    }
});
