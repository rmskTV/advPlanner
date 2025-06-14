<template>
    <div class="video-uploader">
        <div v-if="!videoUrl">
            <!-- Этап 1: Загрузка файла -->
            <div v-if="!videoId" class="upload-section">
                <h2>Загрузите видеофайл</h2>
                <input
                    type="file"
                    @change="handleFileSelect"
                    class="file-input"
                    :disabled="isUploading"
                />
                <ProgressBar
                    v-if="isUploading"
                    :value="uploadProgress"
                    :showValue="false"
                    class="progress-bar"
                />
                <small v-if="isUploading">{{ uploadProgress }}%</small>
                <p v-if="errorMessage" class="error-message">{{ errorMessage }}</p>
            </div>

            <!-- Этап 2: Ожидание обработки -->
            <div v-else class="processing-section">
                <h2>Обработка видео</h2>
                <ProgressSpinner style="width: 50px; height: 50px" />
                <p>Пожалуйста, подождите...</p>
                <p>Статус: {{ processingStatus }}</p>
            </div>
        </div>

        <!-- Этап 3: Показ результата -->
        <div v-else class="result-section">
            <h2>Ваше видео готово!</h2>
            <video  v-if="videoUrl" controls autoplay class="video-player">
                <source :src="videoUrl" type="video/mp4">
                Ваш браузер не поддерживает видео тег.
            </video>
            <Button
                label="Загрузить новое видео"
                @click="resetUploader"
                class="p-button-secondary mt-3"
            />
        </div>
    </div>
</template>

<script setup>
import { ref, onUnmounted } from 'vue';
import ProgressBar from 'primevue/progressbar';
import ProgressSpinner from 'primevue/progressspinner';
import Button from 'primevue/button';
import VideoService from './VideoService.js';

const videoId = ref(null);
const videoUrl = ref(null);
const processingStatus = ref('');
const uploadProgress = ref(0);
const isUploading = ref(false);
const errorMessage = ref(null);
const pollingInterval = ref(null);

const handleFileSelect = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    errorMessage.value = null;
    isUploading.value = true;

    try {
        const response = await VideoService.uploadVideo(file, (progress) => {
            uploadProgress.value = progress;
        });

        videoId.value = response.id;
        startPolling(response.id);
    } catch (error) {
        errorMessage.value = 'Ошибка при загрузке файла';
        console.error('Upload error:', error);
    } finally {
        isUploading.value = false;
    }
};

const startPolling = (id) => {
    processingStatus.value = 'Анализ видео...';

    pollingInterval.value = setInterval(async () => {
        try {
            const statusResponse = await VideoService.getVideoInfo(id);
            processingStatus.value = statusResponse.status;

            if (statusResponse.status === 'done' && statusResponse.preview_url) {
                clearInterval(pollingInterval.value);
                videoUrl.value = statusResponse.preview_url;
            } else if (statusResponse.status.startsWith('fail_')) {
                clearInterval(pollingInterval.value);
                errorMessage.value = 'Ошибка обработки видео';
            }
        } catch (error) {
            console.error('Status check error:', error);
            errorMessage.value = 'Ошибка при проверке статуса';
            clearInterval(pollingInterval.value);
        }
    }, 2000);
};
const resetUploader = () => {
    videoId.value = null;
    videoUrl.value = null;
    processingStatus.value = '';
    uploadProgress.value = 0;
    clearInterval(pollingInterval.value);
};

onUnmounted(() => {
    clearInterval(pollingInterval.value);
});
</script>

<style scoped>
.video-uploader {
    max-width: 600px;
    margin: 0 auto;
    padding: 20px;
    text-align: center;
}

.upload-section, .processing-section, .result-section {
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    margin-top: 20px;
}

.file-input {
    display: block;
    margin: 20px auto;
}

.progress-bar {
    height: 10px;
    margin-top: 10px;
}

.video-player {
    width: 100%;
    max-height: 400px;
    background: #000;
    margin-top: 15px;
    border-radius: 4px;
}

.error-message {
    color: #f44336;
    margin-top: 10px;
}
</style>
