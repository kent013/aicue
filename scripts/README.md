# scripts/

本番運用・開発環境向けの恒久スクリプト台帳。
設計・調査・一時スクリプトは `devnotes/` に置く (AGENTS.md)。

> **規約**: `scripts/` へスクリプトを追加 (devnotes からの昇格を含む) したら、
> 必ず下表に 1 行追記する。用途と実行タイミングが書けないスクリプトは昇格しない。

## スクリプト一覧

| スクリプト | 用途 | 実行タイミング |
|---|---|---|
| `global-test-lock.sh` | 全テストレーン共通のグローバルロック (source して使うライブラリ)。`/tmp/global-test-lane-<uid>.d/lock` を**ブロッキング取得**し、待機中のみ保持者の身元つき heartbeat を出す。レーンは専用プロセスグループで起動し、**グループが空になるまで**ロックを保持する。公開 API は `global_test_lock_acquire` / `global_test_lock_run` / `global_test_lock_on_exit` | 各 lane スクリプトから source (直接実行しない) |
| `with-global-test-lock.sh` | 任意コマンドをグローバルテストロック配下で実行する汎用ラッパ (lane スクリプトを持たない `pnpm test:packages` / `test:coverage` 用) | `package.json` の script から自動呼び出し |
| `verify-global-test-lock.sh` | グローバルテストロックの**並行挙動**検証スイート (層 1・C01〜C24)。実ロックには触れず `mktemp -d` の scratch 上で待機・heartbeat・fd 非継承・プロセスグループ刈り取り・シグナル収束・再入・終了コードを実プロセスで検証する | CI (`php` job) から自動実行 / ロック機構を変更したら手動実行 |
| `run-test.sh` | `composer test` の pgsql 経路。**グローバルテストロック配下**で base テスト DB の冪等 CREATE (`ci/ensure-test-db.php`) → `artisan test --parallel` を実行 | `composer test` から自動呼び出し (直接呼ぶ必要なし) |
| `run-vitest.sh` | vitest を**グローバルテストロック配下**で実行 (`exec` は使わない = fd 7 を保持したまま子を待つ) | `pnpm test` から自動呼び出し |
| `phpstan.sh` | PHPStan の DX ラッパー。virtiofs 上の phar 並列 open レースを避けるため phar を実 fs に複製してから実行 | `composer phpstan` から自動呼び出し |
| `ci/ensure-test-db.php` | pgsql テストの base DB を不在時のみ冪等 CREATE (dev-DB 保護の二重防御付き)。併せて出自 (worktree の realpath) を `COMMENT ON DATABASE` で冪等に記録する (孤児 sweep の分類材料。付与失敗は best-effort で無視) | `run-test.sh` / CI から自動呼び出し |
| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip)。`--orphans` で「生存 worktree に紐づかない孤児 DB」の検出も行う (既定 dry-run。`--apply` は `--confirm=<token>` 必須で **LLM は実行しない** = ユーザー実行またはユーザーの明示承認のみ) | worktree teardown / CI cleanup / 孤児回収 (手動) |
| `setup-worktree.sh` | TODO 用 worktree (`.claude/worktrees/tasks/<task-id>` + `todo/<task-id>` ブランチ) を作成し、実行時ファイルの供給 (秘密ファイルは 0600 で作成。`.env` は必須)・worktree-local な `composer install --no-scripts` / `pnpm install` (GVS 共有)・health check・テスト DB ensure まで機械的に実行 (AGENTS.md §worktree 運用ルール) | 実装開始時 (`app-implement` W-3 等) |
| `teardown-worktree.sh` | worktree の dirty チェック → テスト DB の best-effort 回収 → `git worktree remove --force`。ブランチの削除/マージは呼び出し側の責務 | 実装完了後 (`app-implement` C-4 等) |
| `ci/pgsql_test_conn.php` | ensure / drop が共有する pgsql 接続 resolver | (上記 2 スクリプトの内部 include) |
| `audit-gate.sh` | supply-chain 依存脆弱性 gate の実行ラッパ。composer / pnpm(pyproject.toml があれば pip-audit も)の audit JSON を取得して `audit-gate.ts` に渡す。**取得は fail-closed**: 空出力・前処理 (`uv export`) の失敗はそこで停止し、advisory 0 件として判定へ流さない | `pnpm run audit:gate` から自動呼び出し / CI (`supply-chain-audit` job) / 直接実行 |
| `audit-gate.ts` | audit JSON の統合判定 (high+ fail / moderate warn / `docs/supply-chain/accepted-advisories.yaml` の expiry・cleanup・severity 別上限を機械強制) | `audit-gate.sh` / CI から自動呼び出し |
| `audit-gate.test.ts` | `audit-gate.ts` の unit テスト (正規化・expiry 判定・accept-risk 照合) | `pnpm test` (vitest の include に `scripts/**/*.test.ts` が入っている) |
| `audit-gate.contract.test.ts` | `audit-gate.sh` の取得契約テスト (空出力 / 取得失敗を advisory 0 件にせず fail-closed で止める。有効 JSON + 非ゼロ exit = 脆弱性検出の正常系は判定へ通す) | `pnpm test` |
| `test-inventory-config.ts` | vitest の include (root / packages/cli の 2 project) の単一 SoT。`vitest.config.ts` と `packages/cli/vitest.config.ts` が本ファイルから include を引く | 両 vitest config から import (直接実行しない) |
| `vitest-inventory-gate.test.ts` | FS 走査と `vitest list` の突合による inventory gate。どの project にも入らない `*.test.ts` (= 書いたのに走っていないテスト) と、列挙 0 件の空振りを検出 | `pnpm test` |
| `run-browser-test.contract.test.ts` | `run-browser-test.sh` の契約テスト (2 レーン実行 / 失敗レーンがあっても全レーン実行して overall 非ゼロ / 既定直列 / orphan playwright 掃除 / bug-hunt 除外 / 導入の事前確認がロック取得より前 / レーン別の証跡退避) | `pnpm test` |
| `setup-browser-testing.sh` | Browser テスト用のブラウザ実体 (chromium + webkit) と OS 共有ライブラリの導入。**導入の単一情報源**。要求 (`install-deps --dry-run`) と権限 (root / `sudo -n`) を別々に判定し、要求があるのに権限が無ければ特権経路を起こす前に落ちる。判定不能も拒否側に倒す。導入専用ロックで並行実行を直列化する。`--self-test` は判定関数を fixture で駆動する (実資源に触れない) | `scripts/run-browser-test.sh` の事前確認から自動 / CI (`browser-tests` job) / 手動 |
| `setup-browser-testing.contract.test.ts` | `setup-browser-testing.sh` の契約テスト (決定表の sandbox 実走 / 静的契約 / pin された実 Playwright の出力との突合) | `pnpm test` |
| `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける (cap=4 より広く取るのは残留検出のため) | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
| `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
| `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
| `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する | `.claude/settings.json` の hook として配線 (`.claude/settings.bughunt-hook.example.json` をマージ) |
| `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 | (内部スクリプト) |
| `claude-account` | Claude Code のログインアカウントのプロファイル保存・切替・一覧 (Python 3 標準ライブラリのみ)。`(claudeAiOauth, oauthAccount)` のペアを `~/.claude/account-profiles/` に 0600 でスナップショットし、`switch` で書き戻す (切替直前に現アカウントを再スナップショットするのでトークン回転で失効しない)。`add` は使い捨ての `CLAUDE_CONFIG_DIR` で claude を起動し、現ログイン・起動中セッション無影響で別アカウントを登録する。**本リポジトリは `claude-statusline` を持たないため `autosave` の自動呼び出しは効かない** — 登録は `save` / `add` で手動に行う | 人間が実行 (`scripts/claude-account switch` 等) / `switch-account` スキルから |
| `codex` | Codex CLI を VSCode 拡張のネイティブバイナリ経由で起動。`app-codex-review` / `app-codex-vscode` スキルの呼び出しラッパを兼ねる | スキルから自動呼び出し / 直接起動 |
