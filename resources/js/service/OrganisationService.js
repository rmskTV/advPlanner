import {BaseCrudService} from './BaseCrudService.js';
class OrganisationService extends BaseCrudService {
    constructor() {
        super('organisations');
    }
}
export default new OrganisationService();
