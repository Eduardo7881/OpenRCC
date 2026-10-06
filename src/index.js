import { RCCService } from './RCCService.js';
export { RCCService } from './RCCService.js';
export { RCCException } from './RCCException.js';
export { RCCJobs } from './Jobs.js';
export { RCCGames } from './Games.js';
export { RCCThumbnails } from './Thumbnails.js';

export function createRCC(options = {}) { return new RCCService(options); }
