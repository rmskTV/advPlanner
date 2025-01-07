import {createRouter, createWebHistory} from "vue-router";

import home from '../pages/HomePage.vue';
import about from '../pages/AboutPage.vue';
import notfound from '../pages/NotFoundPage.vue';

const routes = [
    {
        path: '/',
        component: home,
    },
    {
        path: '/about',
        component: about,
    },
    {
        path: '/:pathMatch(.*)*',
        component: notfound,
    },
]

const router = createRouter({
    history: createWebHistory(),
    routes
});

export default router;
