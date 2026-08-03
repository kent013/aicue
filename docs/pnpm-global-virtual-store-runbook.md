# pnpm Global Virtual Store (GVS) runbook

`AGENTS.md` §worktree 運用ルールは「node_modules は `pnpm-workspace.yaml#enableGlobalVirtualStore` で
実体を共有 store に置き、worktree 内 `pnpm install` / `pnpm add` の影響を自 worktree に局所化する」に
依存している。**その依存が何を守っているのか / 壊れたときにどう直すのか**を書く。

> 分離戦略の全体像 (vendor 側を含む) は [`docs/worktree-isolation-strategy.md`](worktree-isolation-strategy.md)。
> 本書は node_modules (pnpm) の深堀りと障害対応に絞る。

## なぜ GVS なのか (捨てた選択肢)

worktree ごとに node_modules を持たせる方式は 3 つあり、AI-CUE は 3 番目を採っている。

| 方式 | 採否 | 理由 |
|---|---|---|
| main の `node_modules` を symlink 共有 | **不採用** | worktree 内で `pnpm install` / `pnpm add` を打つと **main の実体を直接書き換える**。LLM が反射的に install を打つ運用では事故が確定する |
| worktree ごとに完全独立な `node_modules` | **不採用** | 同一 version の package が main と worktree で別 path に実体化し、`tsc` が**別型として扱う** (`packages/*` と root を跨ぐ型で `TS2345`)。install 時間・ディスクも線形に増える |
| **worktree-local install + GVS で実体共有** | **採用** | install/add の影響は自 worktree の `node_modules/` (symlink 群) に閉じ、実体は共有 store の `links/` に収束するため **realpath が一致 = 型 identity も一致**する |

## AI-CUE の現行設定 (正本は `pnpm-workspace.yaml`)

pnpm は **11.9.0** (`package.json#packageManager` で固定。GVS は v10.12.1+ の機能)。

| 設定 | 値 | 役割 |
|---|---|---|
| `packages` | `'.'` / `'packages/*'` | root 自身も workspace member (glob 派。明示列挙はしない) |
| `enableGlobalVirtualStore` | `true` | 実体を `<store-path>/links/` に置き main / 全 worktree で共有する |
| `nodeLinker` | `isolated` | GVS の前提。default 変更や `.npmrc` 復活で hoisted に倒れるのを防ぐ明示固定 |
| `linkWorkspacePackages` | `true` | `packages/*` と同名 package が registry にあった場合の silent misresolve を防ぐ safety net |
| `packageExtensions` | jest-dom → `vitest` / vite-plugin-full-reload → `vite` | **暗黙 peer** の補完 (下記「落とし穴 1・2」) |
| `allowBuilds` | `esbuild` | pnpm 11 の build-script gating。未許可だと `ERR_PNPM_IGNORED_BUILDS` |
| `overrides` | `{}` | 脆弱性対応の patched 版強制枠 (運用規約は同ファイルのコメント。`pnpm run audit:gate` と対で使う) |

**`package.json` に `workspaces` フィールドを置かない** (npm syntax。pnpm は読まず WARN を出し続ける)。
workspace の正本は `pnpm-workspace.yaml` 一本。

## install の作法 (CLI で config を強制する)

`scripts/setup-worktree.sh` の `[5/7]` は必ずこの形で実行する。手動 install も同じ形にする
(AGENTS.md §worktree の記述と同一)。

```bash
pnpm install --frozen-lockfile \
    --config.ci=false \
    --config.enableGlobalVirtualStore=true \
    --config.nodeLinker=isolated \
    --config.confirmModulesPurge=false
```

| flag | なぜ必要か |
|---|---|
| `--config.ci=false` | **`CI=true` 等が立っていると pnpm は GVS を自動で無効化する**。yaml で `true` にしていても効かない |
| `--config.enableGlobalVirtualStore=true` | yaml と冗長だが、env / `.npmrc` で殺された場合の保険 |
| `--config.nodeLinker=isolated` | 同上 (hoisted に倒れると GVS の前提が崩れる) |
| `--config.confirmModulesPurge=false` | TTY 無しでも modules-dir purge の確認を通す (旧来 `CI=true` でやっていたことの正規置換) |

pnpm の設定 precedence は **CLI > env > `PNPM_CONFIG_*` > yaml**。CLI 明示が最も頑健。

## GVS が効いているかの確認

`setup-worktree.sh` の post-setup health check #4 が、代表 direct dependency (**`svelte`**) の realpath が
共有 store の `links/` 配下に解決されることを assert する (`.modules.yaml` の存在だけでは GVS 無効な
layout も成立するため、実効を直接見る)。手で確認するなら:

```bash
pnpm store path --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated
readlink -f node_modules/svelte            # worktree 内
readlink -f /workspace/node_modules/svelte # main
```

両者が同一の `<store-path>/links/...` を指せば OK (2026-08-02 実測: main / worktree ともに
`/workspace/.pnpm-store/v11/links/@/svelte/<ver>/<hash>/node_modules/svelte` に収束)。
`node_modules/.pnpm/...` を指していたら **GVS が無効化されている**。

代表 dep を変えるときは (1) root の direct external dependency であること (安定して
`node_modules/` 直下に symlink される) (2) ESM の暗黙 peer 問題を持たない package を選ぶこと、
の 2 点を満たしてから `scripts/setup-worktree.sh` の health check を更新し、本書も直す。

## 落とし穴と対処

### 1. jest-dom の暗黙 peer — `Invalid Chai property: toBeInTheDocument`

`@testing-library/jest-dom` の `vitest.js` は `vitest` を peerDependencies に宣言せずに `import 'vitest'` する。
GVS 有効時、jest-dom の実体は共有 store 内にあり、**ESM では `NODE_PATH` が効かない**ため
host 側の vitest を解決できず、`tests/js/setup.ts` の matcher 拡張が丸ごと無効になる。

**対処**: `pnpm-workspace.yaml#packageExtensions` で peer を補完する (設定済み)。
同種の「peer を宣言しない ESM 依存」を持つ package が増えたら同じ形で追加する。

### 2. `vite build` が `ERR_MODULE_NOT_FOUND` (vite-plugin-full-reload)

`vite-plugin-full-reload` (laravel-vite-plugin の依存) も `vite` を peer 宣言せずに import する。
hoisted linker では偶然解決できていたが、isolated + GVS では解決できない。
**対処**: 同じく `packageExtensions` で `vite` peer を補完する (設定済み)。

### 3. `ERR_PNPM_IGNORED_BUILDS` (esbuild)

pnpm 11 は postinstall script をデフォルトで実行しない。Vite が引き込む esbuild は
postinstall で platform binary を配置するため、`allowBuilds: { esbuild: true }` が無いと
install / build が落ちる (設定済み)。新たに native binary を配る依存が入ったら同節に追加する。

### 4. install が `ENOMEM` / "Cannot allocate memory" で落ちる

OrbStack / virtiofs の共有 FS で大量小ファイルを展開すると一過性で落ちることがある。
`setup-worktree.sh` は composer / pnpm とも**最大 3 回リトライ**する (`--config.*` 強制と
`--frozen-lockfile` はリトライ各回で維持)。手動時は間に `sync` を挟んで再実行する。

### 5. `mise: Config not trusted`

Node / pnpm を mise で管理している環境では、新規 worktree の `mise.toml` が untrusted のため
pnpm shim が起動できない。`setup-worktree.sh` が worktree 作成直後に `mise trust <worktree>` を
実行する (mise 非導入環境では skip)。手動なら同コマンドを打つ。

### 6. `health-check FAIL: GVS 無効の疑い`

setup が上記メッセージで落ちたときの手順:

1. `env | grep -i '^CI='` — CI 変数が立っていないか (立っていても CLI 強制で防げるはずだが、
   `pnpm install` を素で打っていた場合は無効化される)
2. `pnpm config get enableGlobalVirtualStore` / `pnpm config get nodeLinker` を確認
3. `rm -rf node_modules` して上記「install の作法」の形で再 install
4. `readlink -f node_modules/svelte` が `links/` 配下に戻ったか確認

### 7. lockfile を壊さない

`pnpm add/remove/update` は task branch 上でのみ実行し、**変更した `package.json` /
`pnpm-lock.yaml` を必ずコミットする** (未コミットのまま teardown すると失われる)。
GVS 導入に伴って lockfile に期待される差分は importers 表記のみで、
**transitive dep の major 上げ・新規追加・消滅が出たら中止して原因を調べる**。

## 参考

- 内部: `AGENTS.md` §worktree 運用ルール / `scripts/setup-worktree.sh` (`[5/7]` と health check) /
  [`docs/worktree-isolation-strategy.md`](worktree-isolation-strategy.md)
- pnpm 公式: [Global Virtual Store](https://pnpm.io/global-virtual-store) (ESM の `NODE_PATH` caveat も同ページ) /
  [Git Worktrees for Multi-Agent Development](https://pnpm.io/next/git-worktrees) /
  [Settings](https://pnpm.io/settings)
- pnpm Issue [#11221](https://github.com/pnpm/pnpm/issues/11221): global virtual store が peer を解決できない
