import { XMLParser } from 'fast-xml-parser';
import { RCCException } from './RCCException.js';

const SOAP11 = 'http://schemas.xmlsoap.org/soap/envelope/';
const SOAP12 = 'http://www.w3.org/2003/05/soap-envelope';
const RCC_NS = 'http://roblox.com/';

export class RCCService {
  constructor({ host = '127.0.0.1', port = 64989, timeout = 60000, soap12 = false, path = '/' } = {}) {
    const normalized = String(host).replace(/^https?:\/\//, '').replace(/\/$/, '');
    const scheme = String(host).startsWith('https://') ? 'https' : 'http';
    this.endpoint = `${scheme}://${normalized}:${port}${path.startsWith('/') ? path : `/${path}`}`;
    this.timeout = timeout;
    this.soap12 = soap12;
    this.parser = new XMLParser({
      ignoreAttributes: false,
      attributeNamePrefix: '@_',
      removeNSPrefix: true,
      parseTagValue: false,
      trimValues: true,
      isArray: (name) => ['LuaValue', 'Job', 'luaValue'].includes(name)
    });
  }

  async call(operation, params = {}) {
    const xml = this.buildEnvelope(operation, params);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeout);
    try {
      const headers = {
        'Content-Length': Buffer.byteLength(xml).toString(),
        'Connection': 'close'
      };
      if (this.soap12) {
        headers['Content-Type'] = `application/soap+xml; charset=utf-8; action="${RCC_NS}${operation}"`;
      } else {
        headers['Content-Type'] = 'text/xml; charset=utf-8';
        headers['SOAPAction'] = `"${RCC_NS}${operation}"`;
      }

      const res = await fetch(this.endpoint, {
        method: 'POST',
        headers,
        body: xml,
        signal: controller.signal
      });
      const body = await res.text();
      if (!res.ok) throw new RCCException(`RCC HTTP ${res.status}: ${body}`, { status: res.status, operation, response: body });
      return this.parseResponse(body, operation);
    } catch (err) {
      if (err instanceof RCCException) throw err;
      throw new RCCException(`RCC request failed for ${operation}: ${err.message}`, { operation, cause: err });
    } finally {
      clearTimeout(timer);
    }
  }

  async helloWorld() { return this.call('HelloWorld').then(r => r.HelloWorldResult ?? null); }
  async getVersion() { return String((await this.call('GetVersion')).GetVersionResult ?? ''); }
  async getStatus() { return this.call('GetStatus').then(r => r.GetStatusResult ?? {}); }
  async getAllJobs() { return this.normalizeList((await this.call('GetAllJobs')).GetAllJobsResult); }
  async getAllJobsEx() { return this.normalizeList((await this.call('GetAllJobsEx')).GetAllJobsExResult); }
  async getExpiration(jobId) { return Number((await this.call('GetExpiration', { jobID: jobId })).GetExpirationResult ?? 0); }
  async renewLease(jobId, expirationInSeconds) { return Number((await this.call('RenewLease', { jobID: jobId, expirationInSeconds })).RenewLeaseResult ?? 0); }
  async closeJob(jobId) { await this.call('CloseJob', { jobID: jobId }); }
  async closeExpiredJobs() { return Number((await this.call('CloseExpiredJobs')).CloseExpiredJobsResult ?? 0); }
  async closeAllJobs() { return Number((await this.call('CloseAllJobs')).CloseAllJobsResult ?? 0); }

  async openJob(jobId, script, { expirationInSeconds = 300, category = 0, cores = 1, name = 'RCC' } = {}) {
    return this.call('OpenJob', { job: { id: jobId, expirationInSeconds, category, cores }, script: { name, script, arguments: [] } });
  }

  async openJobEx(jobId, script, { arguments: args = [], expirationInSeconds = 300, category = 0, cores = 1, name = 'RCC' } = {}) {
    return this.decodeResult(await this.call('OpenJobEx', {
      job: { id: jobId, expirationInSeconds, category, cores },
      script: { name, script, arguments: args }
    }), 'OpenJobExResult');
  }

  async execute(jobId, script, { arguments: args = [], name = 'RCC' } = {}) {
    return this.decodeResult(await this.call('Execute', { jobID: jobId, script: { name, script, arguments: args } }), 'ExecuteResult');
  }

  async executeEx(jobId, script, { arguments: args = [], name = 'RCC' } = {}) {
    return this.decodeResult(await this.call('ExecuteEx', { jobID: jobId, script: { name, script, arguments: args } }), 'ExecuteExResult');
  }

  async batchJob(jobId, script, { arguments: args = [], expirationInSeconds = 300, category = 0, cores = 1, name = 'RCC' } = {}) {
    return this.decodeResult(await this.call('BatchJob', {
      job: { id: jobId, expirationInSeconds, category, cores }, script: { name, script, arguments: args }
    }), 'BatchJobResult');
  }

  async batchJobEx(jobId, script, { arguments: args = [], expirationInSeconds = 300, category = 0, cores = 1, name = 'RCC' } = {}) {
    return this.decodeResult(await this.call('BatchJobEx', {
      job: { id: jobId, expirationInSeconds, category, cores }, script: { name, script, arguments: args }
    }), 'BatchJobExResult');
  }

  async diag(type, jobId = undefined) {
    const p = { type }; if (jobId !== undefined) p.jobID = jobId;
    return this.decodeResult(await this.call('Diag', p), 'DiagResult');
  }
  async diagEx(type, jobId = undefined) {
    const p = { type }; if (jobId !== undefined) p.jobID = jobId;
    return this.decodeResult(await this.call('DiagEx', p), 'DiagExResult');
  }

  async isAlive() {
    try { await this.helloWorld(); return true; } catch { return false; }
  }
  async health() {
    const started = Date.now();
    try { const version = await this.getVersion(); return { ok: true, version, latencyMs: Date.now() - started, endpoint: this.endpoint }; }
    catch (error) { return { ok: false, latencyMs: Date.now() - started, endpoint: this.endpoint, error: error.message }; }
  }
  async jobExists(jobId) {
    return (await this.getAllJobsEx()).some(j => String(j?.id ?? j?.jobID ?? '') === String(jobId));
  }
  async closeJobIfExists(jobId) { if (await this.jobExists(jobId)) await this.closeJob(jobId); }
  async ensureJob(jobId, script, options = {}) {
    if (await this.jobExists(jobId)) return { created: false, result: null };
    return { created: true, result: await this.openJobEx(jobId, script, options) };
  }
  async run(script, options = {}) {
    const jobId = options.jobId ?? `batch-${cryptoRandomId()}`;
    return this.batchJobEx(jobId, script, options);
  }

  async renderWithScript(script, options = {}) {
    const jobId = options.jobId ?? `thumbnail-${cryptoRandomId()}`;
    try {
      const values = await this.openJobEx(jobId, script, { ...options, name: options.name ?? 'Thumbnail' });
      return firstString(values);
    } finally { await this.closeJobIfExists(jobId).catch(() => {}); }
  }

  async renderPlaceThumbnail(placeId, baseUrl, { width = 420, height = 420, hideSky = false, crop = true, format = 'PNG', expirationInSeconds = 300 } = {}) {
    const url = baseUrl.replace(/\/$/, '');
    const script = `game:GetService("ContentProvider"):SetBaseUrl(${luaString(url)})\n` +
      `game:GetService("ScriptContext").ScriptsDisabled = true\n` +
      `game:Load(${luaString(`${url}/asset/?id=${placeId}`)})\n` +
      `return game:GetService("ThumbnailGenerator"):Click(${luaString(format.toUpperCase())}, ${width}, ${height}, ${hideSky}, ${crop})`;
    return this.renderWithScript(script, { expirationInSeconds, name: 'PlaceThumbnail' });
  }

  async renderTextureThumbnail(scriptOrAsset, { width = 420, height = 420, format = 'PNG', hideSky = false, crop = true } = {}) {
    const script = typeof scriptOrAsset === 'string' && scriptOrAsset.includes('ThumbnailGenerator')
      ? scriptOrAsset
      : `return game:GetService("ThumbnailGenerator"):ClickTexture(${luaString(format.toUpperCase())}, ${width}, ${height}, ${hideSky}, ${crop}, ${luaString(String(scriptOrAsset))})`;
    return this.renderWithScript(script, { name: 'TextureThumbnail' });
  }

  async startGameServer(jobId, placeId, port, baseUrl, gameserverScript, options = {}) {
    const script = `${gameserverScript.trim()}\n\nstart(${placeId}, ${port}, ${luaString(baseUrl.replace(/\/$/, ''))})\n`;
    return this.openJobEx(jobId, script, { expirationInSeconds: 86400, name: 'GameServer', ...options });
  }
  async stopGameServer(jobId) { return this.closeJob(jobId); }

  static thumbnailClickScript({ format = 'PNG', width = 420, height = 420, hideSky = true, crop = true } = {}) {
    return `return game:GetService("ThumbnailGenerator"):Click(${luaString(format.toUpperCase())}, ${width}, ${height}, ${hideSky}, ${crop})`;
  }
  static thumbnailClickTextureScript({ format = 'PNG', width = 420, height = 420, hideSky = true, crop = true, asset = '' } = {}) {
    return `return game:GetService("ThumbnailGenerator"):ClickTexture(${luaString(format.toUpperCase())}, ${width}, ${height}, ${hideSky}, ${crop}, ${luaString(asset)})`;
  }

  buildEnvelope(operation, params) {
    const env = this.soap12 ? SOAP12 : SOAP11;
    const body = Object.entries(params).map(([k, v]) => encodeElement(k, v)).join('');
    return `<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="${env}" xmlns:ns1="${RCC_NS}"><soap:Body><ns1:${xmlName(operation)}>${body}</ns1:${xmlName(operation)}></soap:Body></soap:Envelope>`;
  }

  parseResponse(xml, operation) {
    let doc;
    try { doc = this.parser.parse(xml); } catch (e) { throw new RCCException(`Invalid RCC XML response: ${e.message}`, { operation }); }
    const fault = doc?.Envelope?.Body?.Fault;
    if (fault) throw new RCCException(`RCC SOAP fault: ${JSON.stringify(fault)}`, { operation, response: xml });
    return doc?.Envelope?.Body?.[`${operation}Response`] ?? doc?.Envelope?.Body?.[operation] ?? {};
  }

  decodeResult(response, key) { return decodeLuaContainer(response?.[key]); }
  normalizeList(value) {
    if (value == null || value === '') return [];
    if (Array.isArray(value)) return value;
    if (value.Job) return Array.isArray(value.Job) ? value.Job : [value.Job];
    return [value];
  }
}

function encodeElement(name, value) {
  name = xmlName(name);
  if (name === 'arguments') {
    return `<ns1:${name}>${toArray(value).map(encodeLuaValue).join('')}</ns1:${name}>`;
  }
  if (value === null || value === undefined) return `<ns1:${name} xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>`;
  if (Array.isArray(value)) return `<ns1:${name}>${value.map(v => encodeElement('item', v)).join('')}</ns1:${name}>`;
  if (typeof value === 'object') return `<ns1:${name}>${Object.entries(value).map(([k,v]) => encodeElement(k,v)).join('')}</ns1:${name}>`;
  return `<ns1:${name}>${escapeXml(String(value))}</ns1:${name}>`;
}

function encodeLuaValue(value) {
  let type = 'LUA_TNIL', scalar = null, table = null;
  if (typeof value === 'boolean') { type = 'LUA_TBOOLEAN'; scalar = value ? 'true' : 'false'; }
  else if (typeof value === 'number') { type = 'LUA_TNUMBER'; scalar = String(value); }
  else if (typeof value === 'string') { type = 'LUA_TSTRING'; scalar = value; }
  else if (Array.isArray(value)) { type = 'LUA_TTABLE'; table = value; }
  let out = `<ns1:LuaValue><ns1:type>${type}</ns1:type>`;
  if (scalar !== null) out += `<ns1:value>${escapeXml(scalar)}</ns1:value>`;
  if (table) out += `<ns1:table>${table.map(encodeLuaValue).join('')}</ns1:table>`;
  return out + '</ns1:LuaValue>';
}

function decodeLuaContainer(value) {
  if (value == null || value === '') return [];
  const list = Array.isArray(value) ? value : (value.LuaValue ? toArray(value.LuaValue) : [value]);
  return list.map(decodeLuaValue);
}
function decodeLuaValue(v) {
  if (v == null || typeof v !== 'object') return v;
  switch (v.type) {
    case 'LUA_TNIL': return null;
    case 'LUA_TBOOLEAN': return String(v.value).toLowerCase() === 'true';
    case 'LUA_TNUMBER': { const n = String(v.value ?? '0'); return Number(n); }
    case 'LUA_TSTRING': return String(v.value ?? '');
    case 'LUA_TTABLE': return toArray(v.table?.LuaValue ?? v.table).map(decodeLuaValue);
    default: return v;
  }
}
function firstString(values) { const v = values.find(x => typeof x === 'string'); if (v === undefined) throw new RCCException('RCC script did not return a string.'); return v; }
function toArray(v) { return Array.isArray(v) ? v : (v == null ? [] : [v]); }
function escapeXml(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;'); }
function xmlName(s) { return String(s).replace(/[^A-Za-z0-9_.-]/g, ''); }
function luaString(s) { return JSON.stringify(String(s)); }
function cryptoRandomId() { return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`; }
