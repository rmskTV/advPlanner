import {BaseCrudService} from './BaseCrudService.js';
class AdvBlocksService extends BaseCrudService {
    constructor() {
        super('advBlocks');
    }
}
export default new AdvBlocksService();
