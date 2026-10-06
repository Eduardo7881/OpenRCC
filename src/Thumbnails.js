export class RCCThumbnails {
  constructor(rcc) { this.rcc = rcc; }
  place(placeId, baseUrl, options = {}) { return this.rcc.renderPlaceThumbnail(placeId, baseUrl, options); }
  script(script, options = {}) { return this.rcc.renderWithScript(script, options); }
  texture(asset, options = {}) { return this.rcc.renderTextureThumbnail(asset, options); }
}
