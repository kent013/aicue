#!/usr/bin/env node
// oclif エントリポイント。全コマンドは `src/oclif/commands/` 配下に
// first-class な oclif Command として存在する。
//
// `@oclif/core` は遅延 import する。`@oclif/core/lib/screen.js` は
// `isTTY=true` を検出すると module-init 時に `stream.getWindowSize()` を
// 呼ぶが、テストの TTY shim は `isTTY` だけを偽装し `getWindowSize` は
// 偽装しない。実行直前まで import を遅らせることでその crash 経路を避ける。

const { execute } = await import("@oclif/core");
await execute({ dir: import.meta.url });
