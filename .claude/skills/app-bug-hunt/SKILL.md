---
name: app-bug-hunt
description: このアプリの LLM 探索的バグハント。専用 bughunt 環境 (直列 :8010 / 並列 shard :8011..8014) に対し隔離ブラウザ (Bash 駆動の @playwright/cli) でユーザーストーリーを実走し、UX破綻・詰み・認可漏れ (IDOR) を発見してレポートする (修正はしない)。テンプレート同梱のオプトイン基盤 (未使用時は完全 no-op)。
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
> プレースホルダ化してある。`screens.md` / `operations.md` は**生成物**で、注釈 (`inventory/annotations.toml`)
> と散文 (`inventory/notes-*.md`) から作る (下記 Phase 1)。`stories/` はスケルトンのままである。
> オプトインで、使わなければアプリ実行には一切影響しない
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
| --parallel[=N] | No | 並列シャード実行 (N=2/4、cap=4、既定 4)。既定に含まれる。親はインベントリ確認 → `provision-all` → `bughunt-shard` subagent を Workflow で N 体 fan-out → `verify-run` → 統合レポート |
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
5. **`pipeline-smoke` を実行しない。** `scripts/bug-hunt-shard.sh pipeline-smoke` は
   **LLM を 3 段とも実呼び出しする = 実行するたびに課金が発生する**。実行するのは親
   (orchestrator) のみで、子 wrapper にも露出していない (`BUGHUNT_ORCHESTRATOR` 無しでは
   副作用の前に die する)。探索中にパイプラインの通し確認が要ると判断したら、
   自分で走らせずレポートに「親へ依頼」と書く。
6. **誤検知をバグとして断定しない。** 期待仕様が設計文書 (devnotes/docs) から確認できないものは
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
   `run-id={ts}` を控える。shard 1..N の DB (`bug_hunt_{i}`) / serve (:8011..8014) / wrapper を用意。
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
7. **目録修正の反映**: 統合 report に記録した採用分のみを `inventory/annotations.toml` (割当・区分・理由) /
   `inventory/notes-*.md` (散文) / stories に反映し、`python3 scripts/bug-hunt-inventory.py generate` を走らせる。
8. **adjudication 追記の規律 (人手判断時のみ)**: finding を誤検知 / 意図的仕様 / won't-fix と確定したら、
   cross-session の再 triage を避けるため `ledger/adjudications.jsonl` に 1 行 append (既存行は編集しない)。
   詳細スキーマは `ledger/README.md`。

ストーリー割り当ては固定マップ (`scripts/bug-hunt-shard.sh` の `stories_for_shard`。S3→S7 の状態依存を shard-1 に
閉じ込める。cap=4、`--parallel` は 2/4)。統合レポートが route×症状で dedupe する。

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

## Phase 1: 目録の鮮度確認 (生成物なので手で書かない)

screens.md (画面) と operations.md (操作) は**生成物**である。実装の機械事実
(`php artisan bughunt:inventory-scan`) と、人が書く注釈・散文を合成して作る。
まずドリフトが無いことを確認する:

```bash
scripts/bug-hunt-inventory-check.sh   # exit 0=一致 / 2=致命 / 3=ドリフト
```

- **exit 3 (ドリフト)** の出力は 3 種類に分かれる。
  - `[注釈] 未注釈の route: …` — 実装に route が増えた。
    `.claude/skills/app-bug-hunt/inventory/annotations.toml` に 1 行足す
    (画面なら `kind` = 画面 / JSON、割当なら `story` = S1..S7 と `kubun` = 通常 / 逸、
    探索の分母に載せないなら `kubun` = 外 と 30 文字以上の `reason`)。
  - `[注釈] 実装に無い route の注釈が残っている: …` — route が消えた。注釈も消す。
  - `[生成物] 生成物が再生成の結果と一致しない: …` — 再生成し忘れか手編集。下記を走らせる。
- 注釈を直したら再生成する (**表の行は手で書かない**):

```bash
python3 scripts/bug-hunt-inventory.py generate
```

- **exit 2 (致命)** は抽出そのものが成立していない (抽出条件を満たさない環境 / 母集合 0 件 /
  壊れた注釈)。目録には触れずに原因を直す。
- 散文 (画面の既知の仕様・認可契約など) は `inventory/notes-screens.md` /
  `inventory/notes-operations.md` に書く。**ノートに表を書かない**
  (連結先を読む `coverage/correlate.py` が操作行として拾ってしまうため、段 2 が拒否する)。
- 見るのは `web` group を宣言した面だけである。`web` を宣言していない面 (機械向け API /
  管理画面 / MCP / 現在の webhook の大半) には沈黙する。`web` を宣言していれば webhook でも
  目録に入る (`webhooks.ses` は操作表に区分 `外` で載っている)。
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
2b. **一過性フィードバック (toast 等) は事後 snapshot では観測できない。** 書き込み操作は
   **直前に記録器を仕込み直後に読む** (§一過性フィードバックの観測)。「事後 snapshot に無い」を
   根拠にフィードバック欠落を主張してはならない。
3. カードの「期待」と照合 + 下の**横断ヒューリスティクス**を毎ステップ通す。
4. `playwright-cli console`(error) と `playwright-cli requests`(4xx/5xx、外部ドメイン) を確認。
5. 異常を見たら: `playwright-cli screenshot` で証跡保存 → finding 記録 → **finding は停止信号ではない**。
   当該ストーリーの screen / operation / ヒューリスティクスを**完走するまで検証は終わらない**。
   - 詰み (blocker) でも reseed / 再ログイン / 別導線 / 直 URL で "詰みの先" を取りに行く。本当に到達不能な分だけ skip (理由必須)。
6. **finding を 1 件で満足しない — 特に Critical/High を見つけたエリアは深掘りする** (variant 入力・隣接値・他ロール・二重送信・戻る/リロード)。
7. skip した場合は必ず skip として記録する。無言の skip は禁止。
8. **走行中に突然の 401/ログイン失敗が出たら、アプリバグと断定する前に DB 生存確認**
   (`tmp/bug-hunt/shard-0-cmd.sh db-check`)。空なら `reseed` してやり直し、環境ハザードとして記録。

### 一過性フィードバックの観測 (feedback probe)

**成功 toast は 4 秒で自動消滅する** (`resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS`。
error だけは消えない)。「コピー完了」表示は 2 秒 (`resources/js/components/molecules/CodeSnippet.svelte`)。
driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、
並列 shard ではさらに遅延) 後ろにずれる。したがって **事後 snapshot に無いことは「出なかった」の
証拠にならない** (run 20260803-203721 の F-1-02 が誤検知になった機序。spec-ledger.md 参照)。

そこで **操作の直前に記録器を仕込み、直後に読む**。記録器は ARIA live region
(`role="status"` / `role="alert"`) の出現・変化を記録するので、**消えた後でも読める**。

```bash
# 設置 (arm) と読み出しは同じ 1 コマンド (冪等)。--raw で JSON だけを受け取る
playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
```

**呼ぶタイミング**:
- `open` / `goto` / `reload` / `go-back` / `go-forward` の**直後**に 1 回 (= arm)。
- **各書き込み操作の直後**に 1 回 (= 読み出し)。この呼び出しが次の操作の arm を兼ねるので、
  操作を続ける限り **1 操作 = probe 1 コール**で済む。
- arm を忘れた場合や document が置換された場合は、次の読み出しが `installed_now:true` を返す。
  **arm 漏れは黙って「フィードバック無し」にはならず、必ず「未検証」に倒れる** (下表 3 行目)。
  逆に言えば **`installed_now:false` を得られていない操作について H7 陰性を主張してはならない**。

**判定 (これを守ること)**:

| 記録器の戻り値 | 解釈 | 行動 |
|---|---|---|
| `installed_now:false` かつ (`seen` の `visible:true` entry または `present_new`) に**操作結果を伝える文言**がある | 観測窓が連続し、ユーザーに見える変化を捕捉した | フィードバックあり → finding にしない |
| `installed_now:false` かつ どちらにも無い (`pending:0` かつ `errors:0`) | 操作の全区間で記録器が生きていた = **本当に出なかった** | H7 finding 候補 |
| `installed_now:true` | 途中で document が置換され記録器が失われた (基線も無い) | **肯定証拠のみ採用**: `present_new` または直後の `snapshot` に**操作結果を伝える文言**があれば「フィードバックあり」と結論してよい。無い場合は **未検証** (finding にしない)。基線が無いので常駐 live region も `present_new` に混ざる = 陰性判断には使えない |
| `pending > 0` | 可視性判定が未解決 | probe をもう 1 回だけ叩き、**1 回目と 2 回目の応答を統合**して判定する (統合規則は下記)。2 回目も `pending > 0` なら**未検証** |
| `errors > 0` | 可視判定そのものが例外で解決できなかった entry がある (`seen[].error`) | **陰性判断に使えない**。肯定証拠 (`visible:true` + 結果文言) があれば「フィードバックあり」でよいが、無ければ **未検証** (H7 finding にしない)。`visible:false` は「不可視だった」ではなく「判定不能」である |

- **複数応答の統合規則** (再 probe したときは必ずこれで畳む):
  `seen` / `present_new` は**和集合** (1 回目の `present_new` は基線更新で 2 回目には
  `present_preexisting` に落ちるので、2 回目だけを見ると証拠を失う)。
  一方 **`installed_now` / `errors` は「いずれかの応答で真 / 非 0 なら操作全体でそう」と扱う**
  (`errors` は drain 単位の件数なので、2 回目が `errors:0` でも 1 回目の判定不能は消えない)。
  **陰性 (H7 起票) を主張してよいのは、統合後に `installed_now` が全て false、
  `errors` の合計が 0、最終応答の `pending` が 0 のときだけ。**
- **`visible:false` / `visible:"gone"` は証拠に数えない** (返るのは診断のため)。
- **件数ではなく本文で判定する**: `role="status"` は進捗表示にも使われうる
  (`resources/js/components/atoms/Spinner.svelte` は `label` 指定時に `role="status"`)。
  ローディング/進捗は「操作結果のフィードバック」ではない。
  判定の目安 (最小辞書。網羅列挙ではない):
  - **結果文言 (単独で採用してよい)**: 「〜しました」「完了」「成功」/
    失敗系「〜できません」「失敗」「エラー」
  - **文脈依存語 (単独では採用しない)**: 「削除」「変更」「保存」「作成」「更新」「送信」「招待」。
    これらはボタン名・見出しにも出るので、**`role="status"` / `role="alert"` の中**か
    **フィードバック用 testid (`toast-*` 等) の中**にある場合だけ採用する。
    probe の `seen` / `present_new` は定義上 live region の中なのでこの制限に自動で適合する。
    制限が効くのは `installed_now:true` 時に `snapshot` を肯定証拠に使う経路である。
  - **数えない**: 「読み込み中」「処理中」「Loading」など進捗・状態表示、
    および操作前から出ていた常駐 Alert (基線で `present_preexisting` に落ちる)
- **H7 の「結果フィードバックが無い」は `installed_now:false` かつ `pending:0` かつ `errors:0` の
  操作にのみ適用する。** `installed_now:true` / `pending>0` 継続 / `errors>0` で肯定証拠も得られなかった操作は
  **`H7 未検証` として shard-report に件数と操作名を必ず出す** (無言の skip は禁止 = 走行プロトコル 7)。
  この件数が run ごとに増えていくなら、probe 方式ではなく**導線側の観測設計**を見直す信号とする。
  再実行は 1 回まで。**非冪等な破壊操作 (削除等) は再実行せず未検証のまま記録する。**
- probe が空でも「**視覚的**フィードバックが無い」とまでは言えない (live region を持たない
  一過性 UI は記録されない)。H14 (a11y) に格上げしてよいのは、snapshot / DOM 調査で
  **視覚的な一過性フィードバックの存在を別途確認でき、かつ live region が無い**と示せた場合だけ。
- **probe の結果を `findings.jsonl` の `symptom_tokens` に入れてはならない。**
  `ledger/validate_findings.py` の `has_new_signal()` は symptom_tokens の新語で
  adjudication を `ambiguous` に倒すため、probe 由来の語を混ぜると**既存 adjudication の
  downrank が無効化される**。probe 出力は report.md の証跡欄に書く。

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

> **H7 の前提条件**: 「結果フィードバックが無い」の判定には **feedback probe の陰性所見**が必須
> (§一過性フィードバックの観測)。事後 snapshot に無いことを根拠に H7 を起票しない。

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
- 証跡: screenshots/F-xx.png, console: ..., network: ...,
  feedback-probe: `installed_now=false seen=0(visible:true) present_new=0 pending=0 errors=0`
  (フィードバック欠落を主張する finding では**必須**)
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
- H7 未検証 (観測窓が途切れ肯定証拠も得られなかった操作): n 件 (操作名を列挙)
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

- **操作到達カバレッジ (operation-reach、毎回)** — 2 コマンド。走行中にアプリ側の記録器
  (`BughuntExecutedRouteMiddleware`) が書いた JSONL を `coverage/build_executed.py` が束ね、
  `coverage/correlate.py` が機構分母と突合して「未実行機構 / ★cross / hotspot」を出す。pcov 不要。

  ```bash
  # provision した shard をすべて --shard に渡す (manifest の shard 番号が正本。直列走行は 0)
  python3 .claude/skills/app-bug-hunt/coverage/build_executed.py \
    --run-id {ts} --shard 1 --shard 2 --shard 3 --shard 4 \
    --out devnotes/{ts}-bug-hunt/executed.json
  python3 .claude/skills/app-bug-hunt/coverage/correlate.py \
    --operations .claude/skills/app-bug-hunt/operations.md \
    --findings 'devnotes/{ts}-bug-hunt/shard-*/findings.jsonl' \
    --executed devnotes/{ts}-bug-hunt/executed.json \
    --graph-db /workspace/.code-review-graph/graph.db \
    --run-id {ts} > devnotes/{ts}-bug-hunt/coverage-operation-reach.md
  ```

  **どちらかが終了コード 3 で落ちたら、レポートに「カバレッジ突合できず」と明記する**
  (理由コードを添える)。**未実行一覧は載せない** — 記録が揃っていない走行の一覧は
  「全部やっていない」という嘘になるためである。
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

- 新画面・新フローを実装したら `inventory/annotations.toml` に注釈を 1 行足して再生成し
  (`python3 scripts/bug-hunt-inventory.py generate`)、該当ストーリーを更新する。
  新しい書き込みルートは必ずいずれかのストーリーに割り当てる (未注釈は inventory-check.sh が exit 3)。
  **screens.md / operations.md を直接編集しない** (生成物であり、byte 比較で赤くなる)。
- ストーリーカードの「期待」は設計の正 (devnotes/docs) への参照を持つこと。カード自体が仕様の正本になってはならない。
- 同じ finding が 2 回連続で「要確認」のまま放置されたら、仕様を確定させる TODO を提案する。
