import axios from 'axios';
const API_URL = 'http://localhost/api/organisations'
export const OrganisationService = {
    async List(page = 1, perPage = 10) {

        try {
            const response = await axios.get(API_URL, {
                params: {
                    page: page,
                    per_page: perPage,
                },
            });
            return response.data; // Предполагается, что API возвращает объект с полями data, current_page, per_page, last_page, total
        } catch (error) {
            console.error('Error fetching items:', error);
            throw error;
        }

    },

    async Delete(id) {

        try {
            const response = await axios.delete(API_URL+`/${id}`);
            return response.data;
        } catch (error) {
            console.error("Error deleting item:", error);
            throw error;
        }
    },

    async Update(item) {
        try {
            const response = await axios.patch(API_URL + '/' + item.id, item);
            return response.data;
        } catch (error) {
            console.error("Error updating item:", error);
            throw error;
        }
    },

    async Create(item) {
        try {
            const response = await axios.put(API_URL, item);
            return response.data;
        } catch (error) {
            console.error("Error creating item:", error);
            throw error;
        }
    }
};
