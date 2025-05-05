import { defineStore } from 'pinia';
import axios from 'axios';
import router from '@/router';

const BASE_API_URL = 'http://localhost/api';
export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: localStorage.getItem('jwt_token') || null,
        isLoading: false,
        error: null
    }),
    actions: {
        async login(credentials) {
            this.isLoading = true;
            this.error = null;

            try {
                const response = await axios.post(BASE_API_URL + '/auth/login', credentials);

                this.user = response.data.user;
                this.token = response.data.access_token;

                // Сохраняем токен в localStorage
                localStorage.setItem('jwt_token', this.token);
                localStorage.setItem('jwt_token_user', JSON.stringify(this.user));

                // Добавляем токен в заголовки axios
                axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;

                // Перенаправляем после успешного входа
                router.push('/advBlocksGrid');
            } catch (error) {
                this.error = error.response?.data?.error || 'Login failed';
                throw error;
            } finally {
                this.isLoading = false;
            }
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
