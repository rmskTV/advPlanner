import {BaseCrudService} from './BaseCrudService.js';
class ChannelService extends BaseCrudService {
    constructor() {
        super('broadcastingDayTemplateSlots');
    }
}
export default new ChannelService();
