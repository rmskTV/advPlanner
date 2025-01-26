import {BaseCrudService} from './BaseCrudService.js';
class SalesModelsService extends BaseCrudService {
    constructor() {
        super('salesModels');
    }
}
export default new SalesModelsService();
