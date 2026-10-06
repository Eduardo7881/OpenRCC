export class RCCJobs {
  constructor(rcc) { this.rcc = rcc; }
  list() { return this.rcc.getAllJobsEx(); }
  listBasic() { return this.rcc.getAllJobs(); }
  get(jobId) { return this.list().then(jobs => jobs.find(j => String(j?.id ?? j?.jobID ?? '') === String(jobId)) ?? null); }
  exists(jobId) { return this.rcc.jobExists(jobId); }
  expiration(jobId) { return this.rcc.getExpiration(jobId); }
  renew(jobId, seconds) { return this.rcc.renewLease(jobId, seconds); }
  close(jobId) { return this.rcc.closeJob(jobId); }
  closeIfExists(jobId) { return this.rcc.closeJobIfExists(jobId); }
  closeExpired() { return this.rcc.closeExpiredJobs(); }
  closeAll() { return this.rcc.closeAllJobs(); }
  ensure(jobId, script, options) { return this.rcc.ensureJob(jobId, script, options); }
}
