import {BaseCrudService} from './BaseCrudService.js';
class ExchangeConnectorService extends BaseCrudService {
    constructor() {
        super('exchangeConnectors');
    }
}
export default new ExchangeConnectorService();
