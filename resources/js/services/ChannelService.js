import {BaseCrudService} from './BaseCrudService.js';
class ChannelService extends BaseCrudService {
    constructor() {
        super('channels');
    }
}
export default new ChannelService();
