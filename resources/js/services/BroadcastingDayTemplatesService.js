import {BaseCrudService} from './BaseCrudService.js';
class ChannelService extends BaseCrudService {
    constructor() {
        super('broadcastingDayTemplates');
    }
}
export default new ChannelService();
