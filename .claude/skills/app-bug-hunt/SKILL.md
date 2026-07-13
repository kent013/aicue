---
name: app-bug-hunt
description: このアプリの LLM 探索的バグハント。専用 bughunt 環境 (直列 :8010 / 並列 shard :8011..8018) に対し隔離ブラウザ (Bash 駆動の @playwright/cli) でユーザーストーリーを実走し、UX破綻・詰み・認可漏れ (IDOR) を発見してレポートする (修正はしない)。テンプレート同梱のオプトイン基盤 (未使用時は完全 no-op)。
user-invocable: true
argument-hint: "省略時は --all --coverage --parallel --deviate --real-llm 相当 (既定=全ストーリー並列+コードカバレッジ+逸脱+実LLM接続)。絞るなら [S1..S7 ...] [--no-deviate] [--keep-db] [--fake-llm] 例: /app-bug-hunt, /app-bug-hunt S3"
---

# 探索的バグハント (bug-hunt)

回帰テスト (pest-plugin-browser) は「事前にスクリプト化された期待」を検証する。構造上、
**自由探索でしか見つからない種類の問題** (説明なしリダイレクト・操作詰み・無反応 UI・空状態の放置・
認可漏れ/IDOR・直前操作との矛盾) は見つけられない。本スキルは LLM が実ブラウザでユーザーストーリーを
演じて探索し、それらを発見する。**画面を見るだけでなく、全機能を実際に操作する**。カバレッジの正本は
screens.md (画面 = GET×inertia) と operations.md (全書き込み操作 = 非GET×web) の 2 つで、ストーリーは
両方を消化するように設計されている。**発見と報告まで**が守備範囲。修正は app-design / app-implement の管轄。

> **テンプレート注記**: 本スキルは spirux/aigenba の bug-hunt 基盤を汎用化したもの。アプリ名・ポート・DB 名は
> プレースホルダ化してある。`screens.md` / `operations.md` / `stories/` は**スケルトン**で、初回に
> `php artisan route:list` から生成する (下記 Phase 1)。オプトインで、使わなければアプリ実行には一切影響しない
> (config/bughunt.php + BughuntCoverageMiddleware は env + function_exists の二重 guard で完全 no-op)。

## 使命

> **本スキルはこのアプリの UX 品質を dogfooding で検証する仕組みである。**
> エンドユーザーが「今どこにいて、なぜここに来て、次に何をすべきか」を見失う瞬間を全て発見する。

技術的に正しく動いていても、ユーザーを混乱させたら finding である。使命の正本は AGENTS.md の North Star。

## 引数

> **既定 (引数なし) = `--all --coverage --parallel --deviate --real-llm`**
> (= 全ストーリーを並列 shard + コード到達カバレッジ計装 + 逸脱込み + 実 LLM 接続で走行)。狭めたいときだけ下表で絞る。

| 引数 | 必須 | 説明 |
|------|------|------|
| (引数なし) | — | 既定で `--all --coverage --parallel --deviate --real-llm` 相当を実行 (worktree 走行) |
| S1..S7 | No | 実行するストーリーカード (stories/ 配下、複数指定可)。明示するとその指定分だけに絞る (直列走行) |
| --all | No | 全ストーリーを実行 (S7 は S3 の状態を前提にするため S3 の後)。既定に含まれる |
| --coverage | No | serve を pcov 付き php で起動しコード到達カバレッジ (C3) を収集する。既定に含まれる。pcov 未導入環境では middleware が no-op で安全に続行 |
| --no-coverage | No | カバレッジ計装を省く (既定の --coverage を打ち消す) |
| --parallel[=N] | No | 並列シャード実行 (N=2/4/6/8、cap=8、既定 4)。既定に含まれる。親はインベントリ確認 → `provision-all` → `bughunt-shard` subagent を Workflow で N 体 fan-out → `verify-run` → 統合レポート |
| --deviate | No | 各ストーリー末尾の「逸脱アイデア」も実行する。既定に含まれる |
| --no-deviate | No | 逸脱探索を省く |
| --real-llm | No | LLM を実 Anthropic API に接続して走行する (既定)。親リポジトリ `.env` の `ANTHROPIC_API_KEY` が必須で、未設定なら provision が fail-fast する。生成内容・所要時間は run ごとに非決定的 |
| --fake-llm | No | LLM を canned 応答 (T035) に切り替える (実 API 未接続)。再現・切り分け用。`--real-llm` とは同時指定不可 |
| --real-storage | No | `TESTING_FAKE_STORAGE=false` を注入する。※実 S3 接続の実配線は未実装 = **inert トグル** (現状は挙動不変) |
| --keep-db | No | Phase 0 の provision (migrate:fresh) をスキップし現状の `bug_hunt` を使う (連続実行の 2 回目以降用)。mode は provision 時に確定したものを保持 (mode を変えるには --keep-db を外して再 provision) |
| --shard {i} | No | (内部用) シャード i として走行。--parallel の子として起動される |
| --run-id {ts} | No | (内部用) 親が採番した run-id。--shard とセット |

**ストーリーの粒度はアプリが定義する** (stories/ 配下)。テンプレート初期状態では S1..S7 のスケルトンが
置かれる。並列 fan-out は S1..S7 の browser story を対象にする。CLI/REST 面・管理画面など特殊 guard を要する
面は subagent fan-out に含めず親 (shard 0) が直列追走する (アプリ側でストーリーカードに記述する)。

## 禁止事項 — 絶対遵守

1. **bug-hunt の専用環境以外への接続・操作禁止。** 対象は bughunt 環境
   (直列 = `http://127.0.0.1:8010`、shard 走行時は**自シャードの URL のみ**) に限る。
   **dev (:8000 系) への接続禁止**。他シャードのポート (自分以外) も禁止。staging / 本番 URL も禁止。
2. **バグを見つけても修正しない。** コードの Edit/Write 禁止。更新可能なのはレポート dir
   (`devnotes/{run-id}-bug-hunt/`) のみ。スキル正本 (screens.md / operations.md / stories) への
   書き込みは Phase 1 の鮮度確認で必要な場合に限る。
3. **DB の直接書き換え禁止。** 状態操作は migrate/seeder 経由のみ。**dev DB には一切触れない**。
   環境操作は専用 wrapper `tmp/bug-hunt/shard-{i}-cmd.sh` (直列は i=0。db-check / db-exists /
   mail-urls / reseed の 4 種だけ) 経由に限る。本体 `scripts/bug-hunt-shard.sh` の provision/teardown は
   Phase 0/4 の指定箇所のみ。**生 artisan・tinker・psql・createdb・dropdb は使わない (例外なし)**。
   - **なぜ wrapper 経由が必須か (非交渉)**: shell には `DB_DATABASE=<dev>` 等が export されており、
     dotenv (`.env.bughunt.local`) より優先される。wrapper の `artisan_for_shard` は `env -i` で shell の
     `DB_*`/`PG*` を遮断してから bughunt 値を注入することでこれを無力化する。生 tinker/artisan はこの遮断を
     受けられず **dev DB に書き込む**。
   - **あらゆる DB 書き込みの前に接続先 DB を検証する** (`db-check` または getDatabaseName)。検証なしの書き込み禁止。
4. **許可する実外部接続は LLM プロバイダ (Anthropic) API ドメインのみ (real-llm 既定)。** 決済 / Captcha /
   SSO / mail / S3 等その他の外部は fake / 外部通信なし。**LLM プロバイダ API ドメイン以外の外部ドメインへの
   実リクエストは従来どおり全面禁止**で、検知したら即中断して報告する (egress ガードの許可先に LLM API ドメインを
   加えるだけで、他は不変。SSRF/egress ガードの他ドメイン全面禁止は変わらない)。`--fake-llm` 時は LLM も canned
   (実接続なし)。real-llm は実キー必須で、未設定なら provision が fail-fast する (`--fake-llm` を案内)。
5. **誤検知をバグとして断定しない。** 期待仕様が設計文書 (devnotes/docs) から確認できないものは
   「要確認」に分類し、severity を付けない。

## 並列モード (--parallel[=N]) — 親セッションの手順

> **正典 (既定) は Workflow/subagent fan-out。** 各 shard を `bughunt-shard` subagent として本セッション
> 内で並走させ、各自が自分専用の隔離ブラウザセッション (`-s=bughunt{i}`) を Bash で駆動する。
> **claude -p も MCP サーバも立てない。**

### セッション準備 (前提)

```bash
command -v playwright-cli >/dev/null || npm install -g @playwright/cli@latest
npx --yes playwright install chromium
```

### 手順 (親 = このセッション。worktree 内から実行)

1. **インベントリ鮮度確認** (Phase 1 と同一) を親で 1 回。子は Phase 1 をスキップ。
2. `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh provision-all --parallel={N} [--coverage] --hold-lock` を
   **run_in_background で常駐**させる (lock を fan-out 全期間保持 = 2 run 並走防止)。STDOUT の
   `run-id={ts}` を控える。shard 1..N の DB (`bug_hunt_{i}`) / serve (:8011..8018) / wrapper を用意。
   > **`BUGHUNT_ORCHESTRATOR=1` は必須** (B-HARNESS-01): provision / provision-all / teardown は
   > このトークンが無いと拒否される (default-deny)。**親だけが export** し、fan-out する
   > `bughunt-shard` subagent には**渡さない** (worker の自走復旧による共有 worktree 破壊を機械的に防ぐ)。
3. **`bughunt-shard` subagent を shard ごとに 1 体 fan-out** する。各体に渡す入力:
   - **shard i** / **stories** / **URL** `http://127.0.0.1:801{i}`
   - **使うブラウザ = `playwright-cli -s=bughunt{i}` (自分の i だけ)**
   - **egress**: `PLAYWRIGHT_MCP_ALLOWED_ORIGINS="http://127.0.0.1:801{i};http://localhost:801{i}"` を export
   - **wrapper** `tmp/bug-hunt/shard-{i}-cmd.sh` / **report dir** `devnotes/{run-id}-bug-hunt/shard-{i}/`
   各 subagent は自分の隔離セッションで割り当てストーリーを実走し、`shard-{i}/shard-report.md` に逐次書き出す。
4. 全 subagent 完了後、`scripts/bug-hunt-shard.sh verify-run --run-id {ts}` で欠落判定
   (exit 0=全完遂 / 3=一部欠落。**shard-report.md が実質空/骨子のみも欠落扱い**)。
5. **統合レポート作成** (正本には触れない): manifest と各 `shard-*/shard-report.md` を読み、
   `devnotes/{run-id}-bug-hunt/report.md` に統合する。
   - finding 番号は `F-{shard}-{連番}` に揃える / 同一 route×症状は dedupe
   - カバレッジは screens.md / operations.md に対する**和集合**で再計算 / 欠落シャードは「未走行」と明記
   - **adjudication registry の consult (親のみ)**: dedupe 直後に統合 findings (`findings.jsonl` 連結) を
     `python3 ledger/validate_findings.py <findings.jsonl> --adjudications ledger/adjudications.jsonl --annotate --run-id {ts} --repo-root .`
     に通す。出力 annotation で report を **(1) 未知/actionable / (2) known-accepted / (3) ambiguous (要人手)** に分ける。
     **shard agent は consult しない** (子は素の `proposed` finding のみ)。
6. **teardown**: `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh teardown --run-id {ts} [--drop-db]`。
   その後、手順2 の `--hold-lock` 常駐プロセスを終了して lock 解放。
7. **インベントリ修正の反映**: 統合 report に記録した採用分のみを screens.md / operations.md / stories に反映する。
8. **adjudication 追記の規律 (人手判断時のみ)**: finding を誤検知 / 意図的仕様 / won't-fix と確定したら、
   cross-session の再 triage を避けるため `ledger/adjudications.jsonl` に 1 行 append (既存行は編集しない)。
   詳細スキーマは `ledger/README.md`。

ストーリー割り当ては固定マップ (`scripts/bug-hunt-shard.sh` の `stories_for_shard`。S3→S7 の状態依存を shard-1 に
閉じ込める。cap=8、`--parallel` は 2/4/6/8)。N=8 は S1/S4 の独立 2nd pass で埋め、統合レポートが route×症状で dedupe する。

### 隔離と権限

- **cookie 隔離 (S7 の要)**: 各 `playwright-cli -s=bughunt{i}` は別の隔離ブラウザ (別 cookie/storage)。
  これにより IDOR (組織 B のユーザーが組織 A のデータを見れるか) が正しく検査できる。subagent は
  **自分の i のセッション名だけ**を使う。
- **dev DB 保護**: dev DB を壊さない本体は **provision/wrapper の `env -i` 隔離** (shell の `DB_DATABASE` を
  遮断してから bughunt 値を注入)。subagent は DB を**必ず wrapper 経由**で触る。
- **egress**: 許可外オリジンへの遷移は `net::ERR_BLOCKED_BY_CLIENT` でブロックされる。network に他シャードポート /
  外部ドメインの形跡があれば即中断 (走行プロトコル 4)。

## シャードワーカーの走行 (bughunt-shard subagent)

詳細は `.claude/agents/bughunt-shard.md` を正とする。要点:
- ブラウザは **`@playwright/cli` を Bash で**駆動する。操作ループは「`snapshot` で ref を読む → `click`/`fill`/`type` で
  操作 → `snapshot` で結果確認」。証跡混在を避けるため**自分の report dir に cd してから**叩く。
- 対象 URL / DB / レポート dir は shard 番号から導出 (URL=`:{8010+i}`、DB=`bug_hunt_{i}`)。
- 環境操作は全て**自分専用の wrapper** `tmp/bug-hunt/shard-{i}-cmd.sh` で行う (db-check / mail-urls / reseed)。
- **shard-report.md は走行開始時に骨子を作成し finding を見つけ次第 逐次追記する** (最後にまとめて書かない。
  turn/context budget 超過で結果を全損するため)。
- クロージングは shard-report.md の最終化と `playwright-cli close` まで。serve 停止・teardown は親が行う。

## Phase 0: worktree 作成 (既定) + 環境準備

> **bug-hunt は既定で worktree から走行する** (AGENTS.md §worktree 運用ルール)。

### Phase 0a: worktree 作成

```bash
scripts/setup-worktree.sh bughunt-$(TZ=Asia/Tokyo date +%Y%m%d)
cd .claude/worktrees/tasks/bughunt-<date>
```

setup-worktree.sh が `.env.bughunt.local` (`.gitignore` 対象) と Passport 鍵 (`storage/oauth-*.key`) を親から
コピーする (無いと OAuth が 500 になる)。以降の Phase 0b/1/2/4 のコマンドはこの worktree 内で実行する。

> **例外 (main 走行)**: `--keep-db` での連続再走など単発確認時のみ、main から走行してよい。走行前に必ず
> `keepdb-check` ゲートを通す (下記 Phase 0b)。迷ったら worktree を切る。

### Phase 0b: 環境準備 (provision)

1. `.env.bughunt.local` が無ければ中断し案内する: `cp .env.bughunt.local.example .env.bughunt.local` →
   隔離前提の値 (専用 role `bughunt` / admin role / APP_KEY / CIPHERSWEET_KEY) を埋める →
   `APP_ENV=bughunt.local php artisan key:generate --env=bughunt.local`。
2. run-id を採番し (`TZ=Asia/Tokyo date +%Y%m%d-%H%M%S`)、provision を実行する:

```bash
BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh provision --shard 0 --run-id {ts}
```

   createdb(admin) / migrate:fresh / seed (ManualTestSeeder / AdminUserSeeder / BughuntOAuthSeeder) /
   serve 起動 (:8010) / 実効 env 検証 / wrapper 生成まで全て行われる。
   `--keep-db` 時は稼働確認を `keepdb-check` に一本化する:
   `scripts/bug-hunt-shard.sh keepdb-check --shard 0` (stale で中止されたら `--keep-db` を外して通常 provision)。
3. 以降の環境操作は `tmp/bug-hunt/shard-0-cmd.sh` (db-check / db-exists / mail-urls / reseed) を使う。
   レポート dir は `devnotes/{run-id}-bug-hunt/shard-0/`。

### 環境の前提知識

| 項目 | 値 |
|---|---|
| 対象 URL | 直列 = `http://127.0.0.1:8010` (APP_ENV=bughunt.local, DB=bug_hunt) |
| 外部サービス | **LLM=real (実 Anthropic API 接続。既定 real-llm)**、その他 (決済/Captcha/SSO/mail/S3) は fake / 外部通信なし。許可先は LLM API ドメインのみで、それ以外の外部ドメインへの実 request 検知で即中断 |
| LLM モード | 既定 real-llm。親 `.env` の `ANTHROPIC_API_KEY` が必須 (未設定なら provision が fail-fast → `--fake-llm` を案内)。`--fake-llm` で canned 応答 (再現/切り分け用)。生成内容・所要時間は run ごとに非決定的。**real-llm × --parallel は shard 数ぶん実 API を並行呼びするためレート/コストに注意** |
| ストレージ | 既定 fake (`filesystems.default=local`)。`--real-storage` は inert トグル (実 S3 配線は未実装) |
| メール | MAIL_MAILER=log。署名 URL は `tmp/bug-hunt/shard-0-cmd.sh mail-urls [--count K]` で取得 |
| テストアカウント | ManualTestSeeder が投入 (`{role}-{plan}@example.com` / `multi-org@example.com` / `unverified@example.com`、全員 `password123`)。管理画面 admin は `admin@example.com` / `password12345` (AdminUserSeeder) |
| 管理画面 MFA | `.env.bughunt.local` の `ADMIN_MFA_REQUIRED=false` で無効化 (email+password でログイン可) |

## Phase 1: インベントリ鮮度確認 (初回はスケルトンから生成)

screens.md (画面) と operations.md (操作) が現実と乖離していないかを確認する。**テンプレート初期状態では
両ファイルは空スケルトン**なので、初回は下記で `route:list` から生成して埋める:

```bash
# 画面 (GET × inertia)
php artisan route:list --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    if 'GET' not in r['method']: continue
    uri=r['uri']; mw=str(r.get('middleware',[]))
    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
    if 'web' not in mw: continue
    print(uri, r.get('name') or '-')" | sort

# 操作 (非GET × web セッション面)
php artisan route:list --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    m=r['method'].split('|')[0]
    if m in ('GET','HEAD','OPTIONS'): continue
    mw=str(r.get('middleware',[])); name=r.get('name') or '-'
    if 'web' not in mw: continue
    if name.startswith(('cashier','passport','livewire')) or 'webhook' in name: continue
    print(m, r['uri'], name)" | sort -k2
```

- インベントリに無い新ルートは追記し、どのストーリーに割り当てるか決める。消えたルートは落とす。
- ドリフト検知は `scripts/bug-hunt-inventory-check.sh` でも実行できる (exit 0=差分なし / 3=差分あり)。
- このフェーズは数分以内に留める。

## Phase 2: ストーリー実走 (本体)

対象ストーリーカード (`stories/S*.md`) を 1 枚ずつ読み、`@playwright/cli` (Bash で `playwright-cli <cmd>`) で実行する。

### 走行プロトコル (各ステップ共通)

0. **見るだけで終わらせない。** 各画面で operations.md にその画面の操作が割り当てられていれば必ず実行する。
   操作は「実行 → 成功フィードバック確認 → 一覧/表示への反映確認 (H10)」の 3 点セットで 1 カウント。
   主要フォームは正常系の前に**空入力 or 不正値を 1 回**送ってバリデーション表示も確認する。
   対応するボタン/フォームが UI に見つからない operation は、それ自体を finding 候補として記録する。
1. `playwright-cli snapshot` で現在地と要素 ref を確認 → カードの「操作」を実行。
2. 遷移を伴う操作の後は再 snapshot して testid / テキスト出現を確認する (Inertia+Svelte は描画が非同期)。
3. カードの「期待」と照合 + 下の**横断ヒューリスティクス**を毎ステップ通す。
4. `playwright-cli console`(error) と `playwright-cli requests`(4xx/5xx、外部ドメイン) を確認。
5. 異常を見たら: `playwright-cli screenshot` で証跡保存 → finding 記録 → **finding は停止信号ではない**。
   当該ストーリーの screen / operation / ヒューリスティクスを**完走するまで検証は終わらない**。
   - 詰み (blocker) でも reseed / 再ログイン / 別導線 / 直 URL で "詰みの先" を取りに行く。本当に到達不能な分だけ skip (理由必須)。
6. **finding を 1 件で満足しない — 特に Critical/High を見つけたエリアは深掘りする** (variant 入力・隣接値・他ロール・二重送信・戻る/リロード)。
7. skip した場合は必ず skip として記録する。無言の skip は禁止。
8. **走行中に突然の 401/ログイン失敗が出たら、アプリバグと断定する前に DB 生存確認**
   (`tmp/bug-hunt/shard-0-cmd.sh db-check`)。空なら `reseed` してやり直し、環境ハザードとして記録。

### 横断ヒューリスティクス (毎ステップ適用)

| # | 兆候 | 既定 severity |
|---|---|---|
| H1 | 説明なしリダイレクト (操作の結果どこかに飛ばされ、画面に理由がない) | High |
| H2 | 詰み (進む導線も戻る導線もない / 同じエラーをループする) | Critical |
| H3 | 無反応 (クリックして何も起きない、ローディングが終わらない >10s) | High |
| H4 | 生エラー (500 / スタックトレース / 英語例外文 / `[unknown]` / 未翻訳キー / 白画面) | High |
| H5 | console error / 4xx・5xx network (画面上は正常に見えても) | Medium |
| H6 | 二重送信が可能 (連打・リロード・戻る、で副作用が 2 回) | 課金系 Critical / 他 Medium |
| H7 | destructive 操作に確認がない、または結果フィードバック (flash) がない | Medium |
| H8 | 空状態 (0 件) で説明・次アクションがない | Low |
| H9 | 権限外データの表示・操作 (IDOR 含む。他組織/他プロジェクトのリソース) | Critical |
| H10 | 文言・件数・状態が直前の操作結果と矛盾 (例: 作成したのに一覧に出ない) | High |

**UI/UX ヒューリスティクス (H11-H14、視覚/操作品質。snapshot + screenshot で観察)**

| # | 兆候 | 既定 severity |
|---|---|---|
| H11 | **視覚破綻**: レイアウト崩れ・要素の重なり・overflow / 横スクロール・テキスト切れ/はみ出し・視覚階層の破綻 | 操作阻害あり=High / 見た目のみ=Medium〜Low |
| H12 | **アフォーダンス/状態表現**: 押せる/押せないが見分けられない・disabled/loading/selected の状態が判別不能・primary と副操作の階層が不明 | Medium |
| H13 | **レスポンシブ/モバイル**: 狭幅 viewport (mobile 375 / tablet 768) で横スクロール・要素はみ出し/重なり・操作要素が画面外/タップ不能・nav 到達不能 | 操作不能=High / 見た目崩れ=Medium |
| H14 | **アクセシビリティ基礎**: コントラスト不足・focus リング不可視/キーボード到達不能・interactive 要素に aria/label/name 欠落・画像/アイコンボタンに alt/aria-label 欠落・見出し階層の崩れ | Medium〜Low |

**適用方法**:
- **H11 / H12 / H14 は毎ステップ**、snapshot (role/name/state が取れるか) と必要に応じ screenshot で観察。
- **H13 (レスポンシブ) は各ストーリーで最低 1 回、代表的な主要画面 2〜3 枚**で `playwright-cli resize` を
  **mobile 375×667 と tablet 768×1024** に変えて確認する。確認後 **desktop に戻す**。
- UI/UX finding も通常フォーマットで記録し、**証跡 screenshot を必ず残す**。viewport を変えた場合は寸法を明記。
- 純粋な好み (色味・余白の美的判断) は finding にしない。**「観察可能な破綻・判別不能・到達不能」**に限定する。

### finding 記録フォーマット

```markdown
## F-{連番}: {一行サマリ}
- severity: Critical / High / Medium / Low / 要確認
- story/step: S3-7
- 再現手順: (URL とアカウントから書く。誰でも再現できる粒度)
- 期待: / 実際:
- 阻害されたユーザージョブ: (このバグでユーザーが達成できなくなった目的。使命接続の必須欄)
- 改善アクション候補: (どう直せばユーザーが目的を達成できるか)
- 証跡: screenshots/F-xx.png, console: ..., network: ...
- 推定原因: (code-review-graph で当たりを付ける。5 分で見つからなければ「未調査」)
- 関連既知情報: (devnotes/TODO.md に同種の記録があれば参照。regression かどうかが重要)
```

`findings.jsonl` の分類スキーマは `ledger/findings.schema.json` を参照 (report.md は人間向け本文の正本、
findings.jsonl は分類の正本。同じ説明文を両方に書かない)。

### 状態管理

- ストーリーが DB 状態を汚す場合は、次のストーリー開始前に `tmp/bug-hunt/shard-0-cmd.sh reseed` で初期状態へ戻す。
- 例外: S7 (認可境界) は S3 後の状態を意図的に使う。

## Phase 3: 逸脱探索 (--deviate 時のみ)

各カード末尾の「逸脱アイデア」を実行する。加えて任意の画面で汎用逸脱を 1〜2 個試す:
ブラウザバック直後の再操作 / リロード連打 / URL パラメータの隣接 ID 書き換え (IDOR=H9 探索) / 2 タブ同時操作。
**逸脱中も禁止事項 4 (実外部接続は LLM API ドメインのみ許可、他ドメインは全面禁止) は維持。**

## Phase 4: レポート + クロージング

> **shard-report.md は逐次書き出しする (絶対遵守)**。走行の最初に骨子 (ヘッダ + 空の findings 節 + 空の
> カバレッジ節) を作成し、finding を 1 件見つけるたびに即追記、ストーリー/画面を消化するたびにカバレッジ行を
> 更新する。**最後にまとめて書く方式は禁止** (budget 超過で結果を全損するため)。

`devnotes/{run-id}-bug-hunt/shard-0/shard-report.md` に集約する (逐次更新):

```markdown
# bug-hunt report {日時}
- 実行ストーリー: / skip したステップ:
- 画面カバレッジ: 走行 n / screens.md 総画面 m (未走行を列挙)
- 操作カバレッジ: 実行 n / operations.md 対象 m (未実行を列挙、skip は理由必須)
- UI/UX 検証: 視覚破綻(H11) / アフォーダンス・状態(H12) / レスポンシブ(H13: resize した画面と viewport) / a11y 基礎(H14) の所見
- findings: Critical x / High y / Medium z / Low w / 要確認 v (UI/UX = H11-H14 由来は H 番号を併記)
(以下 finding 詳細を severity 降順で)
```

- **カバレッジ完了条件 (finding と独立)**: あるストーリーを「走行済み」と数えるのは、その screen + operation
  リストを**完走したとき**だけ。finding 件数で分母を縮めない。未走行を report に列挙する。
- **Critical/High は TODO 候補として要約を最後に出力する** (app-design → app-todo-add に渡せる粒度:
  一行サマリ + 再現手順参照 + 阻害されたユーザージョブ + 改善アクション候補 + 関連ファイル)。
- 「要確認」は仕様確認の質問リストとしてまとめ、バグと混ぜない。既知の問題は「既知」と明記し重複登録を防ぐ。
- 最後に `playwright-cli close` でブラウザを閉じ、直列走行なら
  `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh teardown --run-id {ts}` で serve を停止する。
- **走行の最後に、生成したレポートのファイルパスを必ず出力する**。レポート未生成で終わるのは禁止。

### Phase 4 後: カバレッジ突合 (operation-reach は毎回 / code-reach は --coverage 時)

レポート確定後、run の網羅を **未カバー worklist** として機械突合する (`coverage/README.md` が正本)。

- **操作到達カバレッジ (operation-reach、毎回)** — `coverage/correlate.py`。run_id で executed / findings /
  operations.md / graph.db を突合し「未実行機構 / ★cross / hotspot」を出す。pcov 不要。
- **コード到達カバレッジ (code-reach、`--coverage` 時のみ)** — `coverage/merge_pcov.py`。C3 middleware が吐く
  shard JSONL を union し uncovered を主出力する。**pcov 未導入なら OFF** (middleware が no-op)。

### Phase 4b: worktree のクローズ (既定の worktree 走行時)

レポート・採用したインベントリ修正を commit し main へマージしてから worktree を teardown する
(devnotes は必ず commit、AGENTS.md)。

```bash
cd <worktree絶対パス> && git add devnotes/{run-id}-bug-hunt .claude/skills/app-bug-hunt && git commit -m "docs(bug-hunt): ..."
cd <repo-root> && git merge --no-ff todo/bughunt-<date>
scripts/teardown-worktree.sh bughunt-<date>
```

## メンテナンス規約

- 新画面・新フローを実装したら screens.md / operations.md と該当ストーリーを更新する。
  新しい書き込みルートは必ずいずれかのストーリーに割り当てる (ドリフト検知は inventory-check.sh)。
- ストーリーカードの「期待」は設計の正 (devnotes/docs) への参照を持つこと。カード自体が仕様の正本になってはならない。
- 同じ finding が 2 回連続で「要確認」のまま放置されたら、仕様を確定させる TODO を提案する。
