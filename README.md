# Roblox RCC Node.js

Universal backend-side Node.js client for the 2016 Roblox RCCService SOAP interface.

This is a **library**, not a website/control panel. It is intended to be imported into an existing Roblox revival backend.

## Features

- Raw SOAP 1.1 and optional SOAP 1.2
- `HelloWorld`, `GetVersion`, `GetStatus`
- `OpenJob` / `OpenJobEx`
- `Execute` / `ExecuteEx`
- `BatchJob` / `BatchJobEx`
- `CloseJob`
- leases: `RenewLease`, `GetExpiration`
- job enumeration: `GetAllJobs`, `GetAllJobsEx`
- `CloseExpiredJobs`, `CloseAllJobs`
- `Diag` / `DiagEx`
- RCC health check
- persistent jobs
- temporary execution jobs
- 2016 place thumbnails through `ThumbnailGenerator:Click()`
- texture thumbnails through `ClickTexture()`
- game-server start/stop using the supplied 2016 `gameserver.txt`
- Lua argument encoding/decoding
- higher-level `RCCJobs`, `RCCGames`, and `RCCThumbnails` helpers

## Install

```bash
npm install
```

Or from a local copy:

```bash
npm install /path/to/RobloxRCC-Node
```

## Basic usage

```js
import { createRCC } from 'roblox-rcc-revival';

const rcc = createRCC({
  host: '127.0.0.1',
  port: 64989
});

console.log(await rcc.getVersion());
```

## Run Lua

```js
const result = await rcc.run('return "hello", 123, true');

console.log(result);
// [ 'hello', 123, true ]
```

Persistent job:

```js
await rcc.openJobEx('revival-job-1', 'return "booted"');

const result = await rcc.executeEx(
  'revival-job-1',
  'return game:GetService("RunService") ~= nil'
);

await rcc.closeJob('revival-job-1');
```

## Thumbnails

```js
const base64 = await rcc.renderPlaceThumbnail(
  12345,
  'http://127.0.0.1',
  { width: 420, height: 420, format: 'PNG' }
);

const image = Buffer.from(base64, 'base64');
```

The helper follows the 2016 signature:

```lua
ThumbnailGenerator:Click(format, width, height, hideSky, crop)
```

## Game server

```js
import fs from 'node:fs';
import { createRCC, RCCGames } from 'roblox-rcc-revival';

const rcc = createRCC();
const games = new RCCGames(rcc, fs.readFileSync('./gameserver.txt', 'utf8'));

await games.start({
  jobId: 'game-123',
  placeId: 12345,
  port: 53640,
  baseUrl: 'http://127.0.0.1'
});
```

Stop:

```js
await games.stop('game-123');
```

## Higher-level modules

```js
import {
  createRCC,
  RCCJobs,
  RCCGames,
  RCCThumbnails
} from 'roblox-rcc-revival';

const rcc = createRCC();
const jobs = new RCCJobs(rcc);
const thumbs = new RCCThumbnails(rcc);

console.log(await jobs.list());
console.log(await rcc.health());
```

## "Join game server"

RCCService itself does not expose a SOAP `JoinGame` operation. The revival backend should create the server with RCC, track its job/port/session, and then provide the client with the appropriate game-server connection information.

This package deliberately does not invent a fake RCC API for that step.

## Universal low-level calls

If a specific RCC build exposes an operation not wrapped by this package:

```js
const response = await rcc.call('SomeOperation', {
  value: 'test'
});
```

The SOAP layer remains available so a revival can extend the wrapper without replacing it.
