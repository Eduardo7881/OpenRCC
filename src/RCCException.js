export class RCCException extends Error {
  constructor(message, options = {}) {
    super(message, options);
    this.name = 'RCCException';
    this.code = options.code;
    this.status = options.status;
    this.operation = options.operation;
    this.response = options.response;
  }
}
