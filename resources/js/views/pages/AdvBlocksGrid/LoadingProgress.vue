<template>
    <div class="loading-container" v-if="progress.isLoading">
        <div class="loading-progress">
            <div
                class="progress-bar"
                :style="{
          width: `${calculateProgress()}%`,
          backgroundColor: progressColor
        }"
            ></div>
        </div>
        <div class="loading-info">
            Страница {{ progress.currentPage }} из {{ progress.totalPages }} |
            Загружено {{ progress.loaded }} из {{ progress.total }} записей
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    progress: Object
});

const calculateProgress = () => {
    if (props.progress.totalPages === 0) return 0;
    return Math.round((props.progress.currentPage / props.progress.totalPages) * 100);
};

const progressColor = computed(() => {
    const progress = calculateProgress();
    return progress > 70 ? '#4CAF50' : progress > 30 ? '#FFC107' : '#F44336';
});
</script>

<style scoped>
.loading-container {
    padding: 1rem;
    background: white;
    border-bottom: 1px solid #dee2e6;
    position: sticky;
    top: 0;
    z-index: 10;
}

.loading-progress {
    height: 24px;
    background: #f0f0f0;
    border-radius: 4px;
    margin-bottom: 8px;
    overflow: hidden;
    position: relative;
}

.progress-bar {
    height: 100%;
    transition: width 0.3s ease, background-color 0.3s ease;
}

.loading-info {
    font-size: 0.875rem;
    color: #6c757d;
    text-align: center;
}

/* Debug стили */
.loading-debug {
    background: #fff3cd;
    padding: 8px;
    margin-bottom: 10px;
    border-radius: 4px;
    font-size: 14px;
}
</style>
