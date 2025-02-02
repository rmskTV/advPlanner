import axios from 'axios';

const BASE_API_URL = 'http://localhost/api/';
/**
 * @description Родительский класс для создания CRUD-сервисов
 * @author Ruslan Moskvitin
 * @date 23.01.2025
 */
class BaseCrudService {
    constructor(endpoint) {
        this.API_URL = BASE_API_URL + endpoint;
    }

    async List(paramsArray = {}) {
        try {
            const response = await axios.get(this.API_URL, {
                params: paramsArray,
            });
            return response.data;
        } catch (error) {
            console.error(`Error fetching items:`, error);
            throw error;
        }
    }

    async Delete(id) {
        try {
            const response = await axios.delete(this.API_URL+`/${id}`);
            return response.data;
        } catch (error) {
            console.error("Error deleting item:", error);
            throw error;
        }
    }

    async Update(item) {
        try {
            const response = await axios.patch(this.API_URL + '/' + item.id, item);
            return response.data;
        } catch (error) {
            console.error("Error updating item:", error);
            throw error;
        }
    }

    async Create(item) {
        try {
            const response = await axios.put(this.API_URL, item);
            return response.data;
        } catch (error) {
            console.error("Error creating item:", error);
            throw error;
        }
    }
}

export {BaseCrudService};
