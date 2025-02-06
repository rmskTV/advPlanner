import {BaseCrudService} from './BaseCrudService.js';
class AccountingUnitsService extends BaseCrudService {
    constructor() {
        super('accountingUnits');
    }
}
export default new AccountingUnitsService();
