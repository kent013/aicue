# scripts/

本番運用・開発環境向けの恒久スクリプト台帳。
設計・調査・一時スクリプトは `devnotes/` に置く (AGENTS.md)。

> **規約**: `scripts/` へスクリプトを追加 (devnotes からの昇格を含む) したら、
> 必ず下表に 1 行追記する。用途と実行タイミングが書けないスクリプトは昇格しない。

## スクリプト一覧

| スクリプト | 用途 | 実行タイミング |
|---|---|---|
| `run-test.sh` | `composer test` の pgsql 経路。同一 worktree の test 二重起動を flock で直列化し、base テスト DB の冪等 CREATE (`ci/ensure-test-db.php`) → `artisan test --parallel` を実行 | `composer test` から自動呼び出し (直接呼ぶ必要なし) |
| `run-vitest.sh` | workspace 単位で vitest を flock 排他実行 (`.vite/` cache と coverage 出力の同時書き込み破損を防ぐ) | `pnpm test` から自動呼び出し |
| `phpstan.sh` | PHPStan の DX ラッパー。virtiofs 上の phar 並列 open レースを避けるため phar を実 fs に複製してから実行 | `composer phpstan` から自動呼び出し |
| `ci/ensure-test-db.php` | pgsql テストの base DB を不在時のみ冪等 CREATE (dev-DB 保護の二重防御付き) | `run-test.sh` / CI から自動呼び出し |
| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip) | worktree teardown / CI cleanup |
| `setup-worktree.sh` | TODO 用 worktree (`.claude/worktrees/tasks/<task-id>` + `todo/<task-id>` ブランチ) を作成し、実行時ファイルのコピー・worktree-local な `composer install --no-scripts` / `pnpm install` (GVS 共有)・health check・テスト DB ensure まで機械的に実行 (AGENTS.md §worktree 運用ルール) | 実装開始時 (`app-implement` W-3 等) |
| `teardown-worktree.sh` | worktree の dirty チェック → テスト DB の best-effort 回収 → `git worktree remove --force`。ブランチの削除/マージは呼び出し側の責務 | 実装完了後 (`app-implement` C-4 等) |
| `ci/make-shard-phpunit.php` | GitHub Actions の matrix sharding 用に、担当テストファイルのみの phpunit 設定を生成 | CI から自動呼び出し |
| `ci/pgsql_test_conn.php` | ensure / drop が共有する pgsql 接続 resolver | (上記 2 スクリプトの内部 include) |
| `audit-gate.sh` | supply-chain 依存脆弱性 gate のローカル実行ラッパ。composer / pnpm(pyproject.toml があれば pip-audit も)の audit JSON を取得して `audit-gate.ts` に渡す | `pnpm run audit:gate` から自動呼び出し / 直接実行 |
| `audit-gate.ts` | audit JSON の統合判定 (high+ fail / moderate warn / `docs/supply-chain/accepted-advisories.yaml` の expiry・cleanup・severity 別上限を機械強制) | `audit-gate.sh` / CI から自動呼び出し |
| `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 | (内部スクリプト) |
| `codex` | Codex CLI を VSCode 拡張のネイティブバイナリ経由で起動。`app-codex-review` / `app-codex-vscode` スキルの呼び出しラッパを兼ねる | スキルから自動呼び出し / 直接起動 |
