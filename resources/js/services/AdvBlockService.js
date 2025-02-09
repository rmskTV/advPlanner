import {BaseCrudService} from './BaseCrudService.js';
class SalesModelsService extends BaseCrudService {
    constructor() {
        super('advBlocks');
    }
}
export default new SalesModelsService();
