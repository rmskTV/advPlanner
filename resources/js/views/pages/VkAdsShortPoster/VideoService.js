// src/services/VideoService.js
import axios from 'axios';

export default {
    async uploadVideo(file, onProgress) {
        const formData = new FormData();
        formData.append('video', file);

        const response = await axios.post('/video', formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            },
            onUploadProgress: (progressEvent) => {
                const percent = Math.round(
                    (progressEvent.loaded * 100) / progressEvent.total
                );
                onProgress(percent);
            }
        });

        return response.data;
    },

    async getVideoInfo(id) {
        const response = await axios.get(`/video/${id}`);
        return response.data;
    }
};
