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
分けることで、**DB 名の取り合いは構造的に起きない**。

ただし分離できるのは名前空間だけで、**PostgreSQL サーバ・実ブラウザ・CPU/メモリは
マシン全体で 1 つ**である。そこで 2 層構造にしている:

| 層 | 何を分けるか | 機構 |
|---|---|---|
| **リソース名前空間** | テスト DB 名 (`<slug>_test_<worktree-hash>`) | `TestDatabaseEnv` の hash 導出 (worktree ごと) |
| **実行そのもの** | テストレーンの同時実行 | `scripts/global-test-lock.sh` のグローバルロック (`/tmp/global-test-lane-<uid>.d/lock` = **同一 UID・同一マシン**単位。worktree をまたいで直列化する) |

グローバルロックは**ブロッキング取得**なので、後発レーンはエラーにならず待つ。
対象は `composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages`
(+ `pnpm test:coverage`)。旧 worktree-local な flock はスコープが厳密に包含されるため廃止した
(後方互換の並走を残さない)。並行挙動は `scripts/verify-global-test-lock.sh`、
構造的不変条件は `tests/Architecture/GlobalTestLockInventoryTest.php` が固定する。

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

## teardown が常時失敗していた経路 (NFC/NFD) と恒久対策

**症状**: `doc/reference/` に **NFC 形と NFD 形の index entry が両方**載っていた
(index 197 に対し作業ツリーの実体 139)。正規化非依存 lookup の FS
(macOS APFS / OrbStack の virtiofs 等) では 1 ファイルに潰れるため、
新しい worktree を checkout すると「**削除済み扱いの entry**」と
「**untracked 扱いのファイル**」が現れ、teardown の **(2) dirty チェックが必ず fail** した。

**連鎖**: dirty チェックで止まる → **(4) の DB 回収に到達しない** →
開発者は `git worktree remove --force` で迂回する → `drop-test-db.php` を通らない →
**孤児テスト DB が単調増加**する (監査時点で 17 個 / 221.9 MB)。
順序を入れ替えて DB 回収を先にするのは **誤り** — 「teardown が失敗したのに、
まだ使っている worktree のテスト DB が消える」という別の事故になる。直すべきは原因の側。

**恒久対策**: NFD 側 index entry 58 件を `git rm --cached` で除去し
(落とす内容は残す NFC entry に**同一 blob**で保存されていることを実測で確認済み。
作業ツリーのファイルは 1 つも消えていない)、再発を
**`tests/Architecture/GitIndexNormalizationTest.php`** が deny-by-default で止める
(index 全体を NFC 正規化して衝突が 0 件であること)。

> `core.precomposeunicode = true` は **`.git/config` のローカル設定**であり、
> clone した各人が設定しない限り効かない = **リポジトリの恒久対策にはならない**。
> 各自 `true` にしておくと再発を緩和できる**補助情報**として扱い、
> 受入条件にもロールバック手順にも含めない。恒久対策はあくまで上記ゲートである。

**迂回されたときの回収経路**: それでも `--force` で強制撤去された場合に備え、
`scripts/ci/drop-test-db.php --orphans` が「生存 worktree に紐づかない孤児 DB」を
**dry-run で列挙**する (§孤児テスト DB の回収)。

### ⚠️ 是正コミットを既存チェックアウトへ取り込むときの注意 (1 回だけ必要な操作)

**正規化非依存 FS では、この是正コミットを `pull` / `merge` した直後に
`doc/reference/` の実体ファイルが 58 件消える。** git は index から落ちた NFD path を
「削除されたファイル」として `unlink` するが、FS 上ではそれが NFC path と**同一の inode**
だからである (実測: 実体 139 → 81 / `git status` に 58 件の ` D `)。

**中身は失われていない** (blob は index にも object DB にも残っている)。取り込んだ直後に:

```bash
git checkout -- doc/reference     # index から復元する (139 件に戻り status が空になる)
```

を 1 度実行すればよい。以後は index に NFC entry しか無いので二度と起きない。
**新規 clone / 新規 worktree では最初から発生しない** (実測: 是正後のコミットから
checkout した worktree は dirty 0 行 / on-disk NFD path 0 件)。

## 孤児テスト DB の回収 (`drop-test-db.php --orphans`)

DB 名の hash は worktree の realpath から算出されるため、**worktree が既に消えていると
hash を再現できない** = 従来の無引数 `drop-test-db.php` では孤児を回収できなかった。
そこで出自を機械記録し、hash 単位で分類する:

- `ensure-test-db.php` が base DB へ **`COMMENT ON DATABASE <base> IS '<worktree の realpath>'`**
  を記録する (作成時・既存時の**両方** = 冪等。非破壊 DDL)。付与失敗は best-effort で無視する
  (comment は分類材料であって必須ではない。ここで落とすとテスト前処理が権限差で止まり偽赤が増える)
- `--orphans` は `pg_database` + `shobj_description` を **SELECT だけ**で列挙し、
  **`Protected → Live → Foreign → Orphan → Unlabeled`** の順に評価して分類する。
  `Live` (生存 worktree hash 突合) が `Foreign` / `Orphan` より**先**なので、
  **comment を細工しても生存 DB は落とせない**
- **削除可否を分類だけで自動決定しない**。`Orphan` も `Unlabeled` も
  `--include-hash=<hash>` で人間が 1 つずつ名指ししない限り 1 件も落ちない
  (一括フラグは**意図的に用意していない**)
- `--apply` は `--confirm=<token>` 必須。token は
  `classifier_version` / `drop_targets` / `live_hashes` / `protected` / `include_hashes` の
  canonical JSON の SHA-256 で、apply は `.claude/worktrees/.setup.lock` 取得後に
  判定入力を再取得して token を再計算し、一致したときだけ DROP する
  (= 指紋ではなく **lock 下のスナップショット照合**)
- **DROP DDL を実行するのは `drop-test-db.php` の 1 本のまま**。`--orphans` は
  「どれを落とすかを決める入口」を足すだけで、`isDevDatabase()` /
  `isAllowedTestDatabase()` / `pgsqlDropDatabaseSql()` は既存実装を共有する

> **排他の適用範囲を誇張しない**: `.setup.lock` が閉じるのは
> **同一クローンの協調スクリプト (setup / teardown / sweep) 間の TOCTOU だけ**である。
> lock はファイルシステム上の 1 クローンに閉じており別クローンとは共有されない。
> **cross-clone の防御は `Foreign` 分類 + `--protect-hash` + 人間承認**の 3 段で行う。

> ⚠️ **`--apply` は LLM / エージェントが実行してはならない**。
> ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
> (AGENTS.md 禁止事項 3)。

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
