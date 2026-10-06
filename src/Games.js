export class RCCGames {
  constructor(rcc, gameserverScript = '') { this.rcc = rcc; this.gameserverScript = gameserverScript; }
  start({ jobId, placeId, port, baseUrl, script = this.gameserverScript, ...options }) {
    if (!script) throw new Error('gameserver.txt was not supplied to RCCGames.');
    return this.rcc.startGameServer(jobId, placeId, port, baseUrl, script, options);
  }
  stop(jobId) { return this.rcc.stopGameServer(jobId); }
  restart(args) { return this.stop(args.jobId).catch(() => {}).then(() => this.start(args)); }
}
