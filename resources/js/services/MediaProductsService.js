import {BaseCrudService} from './BaseCrudService.js';
class Service extends BaseCrudService {
    constructor() {
        super('mediaProducts');
    }
}
export default new Service();
