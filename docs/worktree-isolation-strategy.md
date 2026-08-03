# worktree 分離戦略 (依存・DB・実行時ファイル)

`AGENTS.md` §worktree 運用ルールが「何をどう分離しているのか」「なぜその形なのか」の背景。
**運用ルールそのものの正本は AGENTS.md、実装の正本は `scripts/setup-worktree.sh` /
`scripts/teardown-worktree.sh`** で、本書はその設計意図と落とし穴を記録する。

## North Star

> **worktree は「依存・DB・実行時ファイル」を workspace (main) と共有しない。**
> LLM が worktree 内で `pnpm install` / `composer install` / `composer test` を反射的に打っても、
> main や他の worktree が壊れない状態を**構造的に**保証する。

分離の意義は disk 節約ではなく **事故耐性**である。「注意して運用する」ではなく
「共有していないから壊せない」に倒す。

## 分離の 4 軸

| 軸 | 戦略 | 実装 |
|---|---|---|
| **vendor (composer)** | worktree-local に独立 install | `setup-worktree.sh [4/7]` の `composer install --no-progress --no-interaction --no-scripts` (最大 3 回リトライ) |
| **node_modules (pnpm)** | worktree-local install + GVS で実体共有 | `setup-worktree.sh [5/7]` の `pnpm install --frozen-lockfile --config.*` 強制 (同 3 回リトライ)。詳細は [`docs/pnpm-global-virtual-store-runbook.md`](pnpm-global-virtual-store-runbook.md) |
| **テスト DB (pgsql)** | worktree ごとに別 DB (`<slug>_test_<worktree-hash>`) | `tests/Support/Ci/TestDatabaseEnv::workrootHash()` = worktree root realpath の sha1 先頭 8 桁。`scripts/ci/ensure-test-db.php` が冪等 CREATE |
| **実行時ファイル** | 親から実コピー (共有しない) | `setup-worktree.sh [2/7]` が `.env` (無ければ `.env.example`) / `storage/oauth-*.key` / `public/build` をコピー |

### なぜ vendor を hardlink 共有しないのか

`cp -al` による hardlink 共有は、Docker named volume (btrfs) と worktree (virtiofs) が
**別デバイスになる構成で cross-device link エラーになる**。環境依存の最適化のために
セットアップが壊れる方が高くつくため、素直に worktree-local install に統一している。

### なぜテスト DB を worktree ごとに分けるのか

複数 worktree で `composer test` が同時に走ると、`RefreshDatabase` の `migrate:fresh` と
paratest の per-worker DB が衝突して**不可解な failure**になる。DB 名を worktree path の hash で
分けることで、別 worktree のテストは互いに止めない (同一 worktree 内の二重起動だけは
`scripts/run-test.sh` の flock で直列化する)。

**dev DB 防御は分離とは別レイヤで多重化されている** (AGENTS.md 禁止事項 #3):
`TestDatabaseEnv` の allowlist (`<slug>_test_` + 8 桁 hash + paratest token のみ) と dev DB denylist を
`tests/bootstrap.php` の単一点ガード (Laravel boot 前 fail-closed) と `ensure-test-db.php` /
`drop-test-db.php` 側の再検査という二重防御で使う。**shell や docker-compose から `DB_DATABASE=<dev DB>` が
leak しても dev DB には到達しない**。

## setup の 7 step (`scripts/setup-worktree.sh <task-id>`)

```
[0/7] 事前条件チェック + lock 排他 (flock、無ければ mkdir lock)
[1/7] git worktree add .claude/worktrees/tasks/<task-id> -b todo/<task-id> main
      (+ mise trust。mise 環境で新規 worktree が untrusted だと pnpm が起動できない)
[2/7] 実行時ファイルの provision (.env 必須 / storage/oauth-*.key・public/build は存在すれば)
[3/7] git submodule update --init --recursive (.gitmodules がある場合のみ)
[4/7] vendor:       composer install --no-scripts (最大 3 回リトライ)
[5/7] node_modules: pnpm install --frozen-lockfile --config.* 強制 (最大 3 回リトライ)
[6/7] post-setup health check
[7/7] pgsql test base DB の冪等 ensure (失敗は warning。test 実行時に再 ensure される)
```

- **ブランチ名は `todo/<task-id>` 固定** (custom branch 非対応)。teardown 側の前提と一致させるため。
- **worktree の置き場は `.claude/worktrees/tasks/<task-id>`** (`tasks/` 階層を含む)。
- 失敗時は EXIT trap (`cleanup_on_exit`) が**作成途中の worktree とブランチを自動削除**するため、
  中途半端な worktree が残らない。
- 工程ごとに `[timing] step=... elapsed=...s` を stderr に出す (遅い工程の切り分け用)。

### post-setup health check ( `[6/7]` )

| # | 検査 | 何を守るか |
|---|---|---|
| 1 | `.env` と provision したパスの存在 | コピー漏れで後段が謎エラーになるのを防ぐ |
| 2 | `vendor/autoload.php` 経由で `App\Models\User` が解決できる | composer install の完整性 |
| 3 | `node_modules` が実ディレクトリ (symlink でない) + `.modules.yaml` あり | pnpm install の完了 |
| 4 | `readlink -f node_modules/svelte` が `<store-path>/links/` 配下 | **GVS の実効** (無効化されると型 identity 衝突が再発する) |
| 5 | `php artisan --version` / `vendor/bin/pest --version` / `vendor/bin/phpstan --version` | cold 状態でツールが動くかの fail-fast |

`--no-scripts` を付けているため composer の post-autoload-dump (artisan `package:discover`) は走らない。
dev DB の cache テーブル不在等で install 自体が落ちるのを避けるためで、Laravel 12 は
`bootstrap/cache/packages.php` 不在時に runtime auto-discovery するので機能影響はない。

## teardown (`scripts/teardown-worktree.sh <task-id>`)

```
1) 入力バリデーション + lock 排他 (setup と同一 lock)
2) dirty チェック — 未コミット / untracked があれば fail (依存 lockfile の取りこぼし防止)
3) main マージ済みかチェック (警告のみ。処理は止めない)
4) pgsql テスト DB の best-effort 回収 (scripts/ci/drop-test-db.php)
5) git worktree remove --force → git worktree prune
```

**ブランチ `todo/<task-id>` は teardown の責務外** — 削除もマージもしない。
worktree だけを消し、ブランチの扱い (main へのマージ / `git branch -d`) は呼び出し側が決める。
「掃除のつもりが未マージの成果を消す」事故を構造的に避けるための線引きである。

vendor / node_modules は worktree-local なので、**worktree を消せば依存も一緒に消えるだけ**。
main 側を汚す経路が存在しないため、teardown 時の汚染検証 (fingerprint 比較等) は要らない。

orphan 化した worktree (teardown を経ずに削除) は `git worktree prune` で整理する。
検証なしの強制削除は `git worktree remove --force <path> && git worktree prune`。

## worktree 内のコマンド規則 (2 層)

| 層 | コマンド | 条件 |
|---|---|---|
| **許可** | `composer install` / `pnpm install` | lockfile に従う再構成。worktree-local に閉じる |
| **依存変更タスク時のみ** | `composer require/remove` / `pnpm add/remove/update` | task branch 上で実行し、変更した `composer.json` / `composer.lock` / `package.json` / `pnpm-lock.yaml` を**必ずコミット** (未コミットのまま teardown すると失われる) |

手動 `pnpm install` では `--config.ci=false --config.enableGlobalVirtualStore=true
--config.nodeLinker=isolated` を明示する (`CI` 等の env で GVS が自動無効化されるのを CLI で防ぐ)。

## bug-hunt との関係

`.claude/skills/app-bug-hunt/` は **worktree から走ることを既定**とし、
`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが main 直叩きを早期に止める。
bughunt 環境の DB (`bug_hunt(_1..8)`) は本書のテスト DB 分離とは**別系統の隔離**で、
`scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` + DB 名 regex + role guard) が担う
(AGENTS.md §bug-hunt)。

## 既知のギャップ

- **worktree 規約の自動検証テストが無い**。setup / teardown / AGENTS.md の記述がずれても
  `composer test` では落ちない (参照実装の aigenba は `WorktreeRuleInvariantTest` で
  regex 固定している)。導入するなら「ブランチ名固定」「teardown がブランチを触らない」
  「install 系 2 層規則」あたりが pin 対象になる。
- `.env` は親から**実コピー**するため、親の `.env` を後から変えても worktree には反映されない
  (worktree ごとに直す)。

## 参考

- `AGENTS.md` §worktree 運用ルール (運用ルールの正本) / §bug-hunt
- `scripts/setup-worktree.sh` / `scripts/teardown-worktree.sh` / `scripts/run-test.sh`
- `tests/Support/Ci/TestDatabaseEnv.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/drop-test-db.php`
- [`docs/pnpm-global-virtual-store-runbook.md`](pnpm-global-virtual-store-runbook.md) (node_modules 側の深堀り)
- pnpm 公式: [Git Worktrees for Multi-Agent Development](https://pnpm.io/next/git-worktrees)
