import {BaseCrudService} from './BaseCrudService.js';
class SalesModelsService extends BaseCrudService {
    constructor() {
        super('advBlockTypes');
    }
}
export default new SalesModelsService();
