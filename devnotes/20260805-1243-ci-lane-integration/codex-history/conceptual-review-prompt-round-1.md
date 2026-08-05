## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上記に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js、GitHub Actions、pnpm workspace、Pest 4 + pest-plugin-browser、vitest 4）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本設計固有の重点確認事項】
- 判断 B (audit:gate を「先に緑にしてから blocking で入れる」) が本当に持続可能か。
  blocking にした結果「無関係な PR が上流 advisory で赤くなる」トレードオフの扱いは妥当か。
  より良い第 4 の選択肢はあるか (ただし baseline 化・soft-fail は禁止事項相当として却下済み)
- 判断 D (make-shard-phpunit.php の削除) は「後方互換の並走を残さない」に照らして妥当か
- 判断 E/F (vitest inventory gate の SoT 表現と実装制約) に論理的な穴はないか。
  特に「gate 自身が vitest list に列挙される」ことによる再帰の扱い
- 判断 G (browser lane の CI 化) で、T082 の 2 レーン契約を実質的に骨抜きにする経路が残っていないか
- CI バイパスを作らないという T099 の契約を、本設計が間接的に破っていないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: ci-lane-integration

c2c 台帳 4 件 (php-test-pgsql-lane / browser-test-lane / ci-multi-lane-workflow /
vitest-lane-inventory-gate) を 1 バッチに統合する。

---

## 0. 現状再実査 (2026-08-05 実測。台帳記載との差分を含む)

台帳の前提が古い箇所があるため、すべて自分で実行して確認した。

| # | 実査項目 | 実測結果 | 台帳との差分 |
|---|---|---|---|
| 1 | `.github/workflows/ci.yml` の job | `php` / `frontend` の 2 job。postgres service なし | 一致 |
| 2 | CI の php lane が pgsql で走るか | **走らない。かつ silent skip ではなく hard fail**。`composer test` → `run-test.sh` → `php scripts/ci/ensure-test-db.php` が `127.0.0.1:5432` へ接続失敗し `exit 1` (`ensure-test-db: failed to connect to maintenance DB`) | 台帳は「実質走っていない」。実際は **fail-closed で CI が赤**。偽グリーンではない |
| 3 | frontend lane の packages 検証 | `typecheck:packages` **と** `test:packages` の**両方が既に配線済み** (commit `4f9e7e8` T100-B0) | 台帳の施策 3「`pnpm test:packages` を追加」は**既に完了済み**。未配線なのは `build:packages` |
| 4 | `packages/cli` のテスト | `pnpm test:packages` = 10 files / 106 tests / **exit 0** / 764ms | 台帳「CI で走っていない」は誤り。走っており緑 |
| 5 | `scripts/ci/make-shard-phpunit.php` | 実体はあるが参照は `.gitignore` と `scripts/README.md` の 2 箇所のみ。**どこからも呼ばれていない** | 一致 (README:20 のドリフト確認) |
| 6 | グローバルテストロック (T099) | `scripts/global-test-lock.sh` 冒頭に「CI は無競合なので無音」と明記。`GlobalTestLockInventoryTest` が 5 ファイルへ `CI` 環境変数**参照禁止**を機械強制 | 一致。**CI バイパスは不要**でもある (後述 §2.1) |
| 7 | `pnpm run audit:gate` | **26 advisory (critical 0 / high 15 / moderate 11)**、failure 15 件で `exit 1`。`accepted-advisories.yaml` は `[]` | 台帳「既存 18 advisory」から**増加**している (advisory 集合は時間で drift する = 設計上の本質) |
| 8 | `pnpm build:packages` | **7 件で fail** (TS6192 ×1 / TS6133 ×6)。すべて未使用 import・未使用定数 | 一致 |
| 9 | 8 の根本原因 | `packages/cli/tsconfig.json` は `noUnusedLocals/noUnusedParameters: true` だが、`typecheck:packages` が使う `tsconfig.test.json` は**両方を明示的に `false`** にしている | 新規発見。`typecheck:packages` は構造的にこの種のエラーを検出**できない** |
| 10 | PostgreSQL | host `db` = **PostgreSQL 18.4** (PDO 接続実証)。`composer test:browser` の `ensure-test-db` も通る | 一致 |
| 11 | `composer test:browser` のローカル実走 | **不可**。`~/.cache/ms-playwright` が存在せず `PlaywrightOutdatedException` (13 failed + 1 error) ×2 レーン、overall `exit 2` | 台帳の「ローカルで実走検証できる」前提は browser lane については**成立していない** (ブラウザ未 DL)。ただし副産物として §5 の契約 3 点を実測できた |
| 12 | 11 の副産物 | chromium レーンが全 fail した後も **webkit レーンが実行され**、overall が非ゼロで終了。各レーン後に `global-test-lock: grace exceeded; SIGKILL process group` = orphan が残って刈られた | 施策 5 が固定すべき現行契約が実挙動として確認できた |
| 13 | root `tsconfig.json` の `include` | `resources/js/**` と `tests/js/**` のみ。**`scripts/**/*.ts` は `pnpm typecheck` の対象外** | 新規発見。`scripts/audit-gate.ts` すら型検査されていない |
| 14 | 13 の解消可否 | `include` に `scripts/**/*.ts` を足して `tsc --noEmit` した結果 **エラー 0** (`vitest.config.ts` を足すと vitest 側の型で 2 件出るので足さない) | 新規発見。低コストで塞げる |
| 15 | JS テストファイル全数 | `*.test.ts` は 120 件 = root project 110 (tests/js 109 + scripts 1) + packages/cli 10。`*.spec.ts` / `*.test.js` は 0 件。**現時点で include 漏れは 0** | inventory gate は「今は 0 件」を固定する gate になる |
| 16 | `vitest list` の使用可否 | vitest 4.1.8 で `vitest list --json=<path>` が動作。root 110 files / cli 10 files を列挙。**`--json` を stdout に流すと vite plugin の警告が混入する**ため必ずファイル出力を使う | 新規発見 (施策 7 の実装可否を決める) |

---

## 1. 背景・課題

AI-CUE の品質は「SOP → シナリオ → ナビ撮影 → レンダ」という長い鎖の**どこも壊れていないこと**に依存する。
その担保は既に十分な数のテストレーンとして存在するが、**CI で実際に走っているレーンはその一部でしかない**。

現状の穴を、守っている不変条件との対応で並べる:

| 穴 | 落ちる保証 | 使命への影響 |
|---|---|---|
| postgres service が無く php lane が ensure-test-db で落ちる | Feature/Unit/Architecture **2704 テスト全部**。`ScenarioWritePathInventoryTest` / `NestedRouteIdorDefenseTest` / `MassAssignmentSafetyTest` 等のセキュリティ不変条件を含む | 「思考ゼロ・編集ゼロ」の中核 (シナリオ整合の共有ロック規約) が CI で一度も検証されない |
| browser lane が CI に無い | `docs/supported-browsers.md` の 3 枚セット (A: no-store / B: bfcache guard / C: Inertia history 暗号化)。特に **WebKit レーン = 撮影 PWA の主戦場 iOS Safari の唯一の自動回帰** | 撮影 PWA でログアウト後に PII が復元される回帰が CI で検知されない |
| `build:packages` が CI に無く、`typecheck:packages` では構造的に検出できない | `packages/cli` の **emit 経路**。`dist/` が壊れたまま `bin/run.js` が公開されうる | CLI が「動かない状態で配れる」 |
| `audit:gate` が CI に無い | AGENTS.md §依存脆弱性の運用そのもの。high/critical の未受容検出 | supply-chain の invariant が人手依存 |
| vitest の include が 2 ファイルに分散し突合が無い | 新規テストが**どの glob にも入らず静かに 0 件で緑**になる経路 | テストを書いたのに走っていない = 最悪の偽グリーン |
| `scripts/run-browser-test.sh` / `phpunit.browser.xml` に契約テストが無い | T082 の 2 レーン契約 / 既定直列 / orphan 掃除 / browser 設定の env 一致 | 「実行時間が長い」を理由にレーンを落とす退行を機械的に止められない |
| `make-shard-phpunit.php` が未配線のまま台帳に「CI から自動呼び出し」と記載 | — | 台帳が嘘をつくと台帳規約 (AGENTS.md) 全体の信頼が落ちる |

**仮説**: CI で実際に走るレーンを「開発者がローカルで走らせるレーンと同一集合」まで引き上げれば、
worktree 並走で main へ入る変更に対する回帰検知が人手レビュー依存から機械保証へ移る。
成功判定は「main で全 job が緑」かつ「各レーンが空振りでないことを負のコントロールで実証できている」こと。

---

## 2. 改善アイデア

### 2.1 貫く原則 — CI と開発者が走らせるものを同一に保つ

T099 は「CI バイパス分岐を作らない」を**機械強制**した (`GlobalTestLockInventoryTest` が
`scripts/{global-test-lock,with-global-test-lock,run-test,run-browser-test,run-vitest}.sh` の
`CI` 環境変数**参照**を deny-by-default で禁止)。本設計はこの契約を一切触らない。

**触る必要がそもそも無い**ことを確認した: GitHub Actions の job は 1 job = 1 runner (専用コンテナ) なので、
`php` / `frontend` / `browser-tests` が並走してもロックは**別マシン上の別ロック**であり競合しない。
ロックは無競合で即時取得され、heartbeat も出ない (ライブラリの設計コメントどおり)。
よって CI 側は `composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages` を
**そのまま呼ぶだけ**でよい。lock を TMPDIR へ移す等の推測追従もしない。

同じ原則から、本設計では以下を**採らない**:

- `continue-on-error: true` (赤いのに緑に見える = 偽グリーン。`@phpstan-ignore` / baseline と同種)
- CI 専用の phpunit 設定・CI 専用のレーン起動経路 (= ローカルで一度も実行されないコードパス)
- 「CI では skip」条件

### 2.2 施策 (9 本)

| # | 施策 | 種別 | 依存 |
|---|---|---|---|
| 1 | `php` job に postgres service を足し `composer test` を実 pgsql で走らせる | CI | — |
| 2 | `browser-tests` job を新設 (chromium + webkit の 2 レーン) | CI | — |
| 3 | `frontend` job に `pnpm build:packages` を追加。前提として `packages/cli` の未使用 import 7 件を削除 | CI + 実装 | — |
| 4 | `packages/cli` の直接依存を含む未受容 high advisory 15 件を解消する (upgrade → overrides → 期限付き accept-risk の順) | 実装 | 施策 5 の前提 |
| 5 | `supply-chain-audit` job を **blocking** で新設 + nightly `schedule` を追加 | CI | 施策 4 |
| 6 | `scripts/run-browser-test.contract.test.ts` 新設 (2 レーン / 失敗継続 / overall 非ゼロ / 既定直列 / orphan 掃除) | テスト | — |
| 7 | `tests/Architecture/PhpunitBrowserConfigParityTest.php` 新設 (`<server>` 完全一致 / 差分は `memory_limit` のみ) | テスト | — |
| 8 | `scripts/test-inventory-config.ts` を include の単一 SoT として新設 + `scripts/vitest-inventory-gate.test.ts` 新設 + root `tsconfig.json` に `scripts/**/*.ts` を追加 | テスト + 設定 | — |
| 9 | `scripts/ci/make-shard-phpunit.php` を**削除**し `scripts/README.md` のドリフトを解消。再発防止に `ScriptsReadmeInventoryTest` を新設 | 削除 + テスト | — |

---

## 3. 主要な設計判断

### 判断 A: postgres は `postgres:18-alpine` (compose と揃える)

`postgres:16` ではなく **docker-compose と同一の `postgres:18-alpine`** を使う。

- 「フレームワークのレンジ内でやる」の変形として、**開発環境と CI の PostgreSQL を同一 image に揃える**方を優先する。
  ローカルで 2704 passed の実測がそのまま CI の期待値になる。
- major が違うと collation / `ORDER BY` の挙動差や新旧 SQL 差が「CI だけ赤 / CI だけ緑」を生む。
  これは §2.1 の原則 (CI とローカルを同一に保つ) の DB 版。
- compose 側は直前に PGDATA マウント境界を pg18 仕様へ移した (作業ツリーの `docker-compose.yml` 差分)。
  CI service は volume を持たないためこの論点の影響を受けない。

派生する必須変更 (実測に基づく):

- `shivammathur/setup-php` に `extensions: pdo_pgsql, pgsql` を**明示**する
  (現状 `extensions:` キーが無く、pdo_pgsql が入る保証がない)。
- job level env に `DB_HOST=127.0.0.1` / `DB_PORT=5432` / `DB_USERNAME=postgres` / `DB_PASSWORD=postgres` を置く。
  `DB_DATABASE` は**置かない** (`tests/bootstrap.php` が worktree hash 名を後勝ちで注入する。
  ここに何か書くと単一点ガードの意図が曖昧になる)。
- service の `POSTGRES_DB` は `postgres` を明示する (`pgsql_test_conn.php` が maintenance DB として固定で使う)。
- 既存の ffmpeg / fonts-noto-cjk / fontconfig provision と `fc-match` fail-fast は **aicue 固有なので完全に残す**。

### 判断 B: `audit:gate` — soft-fail もベースラインも採らない。「緑にしてから blocking で入れる」

台帳の要求は「CI を常時赤にしないための扱いを決める。ただし baseline 化はしない」。
選択肢と評価:

| 案 | 評価 |
|---|---|
| `continue-on-error: true` | **却下**。UI 上は緑になる。「gate はあるが誰も見ない」= AGENTS.md 禁止事項 2 (PHPStan の baseline 化) と同型の逃げ |
| 別 workflow で nightly のみ、PR では動かさない | **却下 (単独では)**。PR が持ち込んだ新規依存の advisory を merge 後まで検出できない |
| `audit-gate.ts` に「main の現状を許容する」除外リストを持たせる | **却下**。これがまさに baseline 化 |
| **現存 15 high を先に解消し、緑になってから blocking job として配線する** | **採用** |

採用案の骨子:

1. **施策 4 (先行)**: `pnpm run audit:gate` が `exit 0` になるまで解消する。手段の優先順位は AGENTS.md の
   「upgrade で解消が原則。accept-risk は最終手段」に従う:
   1. 直接依存の upgrade (`postcss` / `phpoffice/phpspreadsheet` / `packages/cli` の `undici` 等)
   2. transitive は `pnpm-workspace.yaml#overrides` (規約コメントどおり GHSA ID・脆弱レンジ・patch 選定理由を併記) /
      `composer update` による間接 bump (`guzzlehttp/guzzle`)
   3. それでも残る分だけ `docs/supply-chain/accepted-advisories.yaml` に**期限付き**で登録
      (high は 30 日上限 + `approved_by` / `compensating_controls` / `tracking_issue` 必須)
2. **施策 5**: 緑を確認してから `supply-chain-audit` job を **`continue-on-error` なし**で追加する。

**「accept-risk はベースラインではない」ことの根拠**: `audit-gate.ts` は accepted entry の `expiry` を
JST 基準で常時検査し、期限切れ・解消済み entry の残置・severity 迂回受容を**機械的に fail** させる。
つまり登録は「忘れる」ことが構造的に不可能な、期限つきの明示的な意思決定であり、
「エラーを黙らせて永続化する」ベースラインとは性質が異なる。

**残る正直なトレードオフ (受容する)**: blocking にすると、**上流で新 advisory が公開された日から、
無関係な PR も含めて全 PR が赤になる**。実測でも台帳記載の 18 件が 26 件へ増えており、これは例外でなく常態である。
これを緩和するために 2 つを併用する:

- `schedule` (nightly cron) を同 workflow に追加し、**PR のクリティカルパス外で先に検知**する。
  「朝、誰の PR でもないところで赤くなる」ので、無関係な PR 作者が最初の被害者になる確率を下げる。
- 逃げ道は既存の期限付き accept-risk 1 本に統一する (新しい逃げ道を作らない)。

nightly は **PR job の代替ではなく追加**である (PR job を降格させない)。

### 判断 C: `build:packages` — 7 件を消して CI に入れる。`noUnusedLocals` は緩めない

- 7 件はすべて**実際の dead import / dead const** であり、消せば済む。
  `noUnusedLocals: false` にするのは「型を緩めて黙らせる」= 禁止事項 2 と同型。
- `typecheck:packages` (= `tsconfig.test.json`) は `noUnusedLocals/noUnusedParameters: false` を
  **明示している**ため、この種のエラーを構造的に検出できない。よって
  「typecheck があるから build は不要」は成立しない。**emit 経路 (`tsconfig.json`) を CI で走らせる必要がある**。
- `tsconfig.test.json` 側の `false` は**そのまま残す**。テストのモック関数は未使用引数を持つのが正当で、
  ここを厳格化するのは本バッチの目的 (レーン統合) と無関係な広域変更になる。
  「build が emit 経路を守り、test config はテストの都合を持つ」という役割分担を明示的な設計判断として残す。
- 配置は `frontend` job (`typecheck:packages` の直後、`test:packages` より前)。
  型 → build → テスト の順で、壊れ方の原因が近い順に落ちる。

### 判断 D: `make-shard-phpunit.php` は**削除**する

配線ではなく削除を選ぶ理由:

1. **今必要ではない** (思考原則 2)。`composer test` は `--parallel --processes=4` で 2704 件を回せており、
   sharding を入れる動機となる CI 時間の逼迫がまだ観測されていない。
2. **T099 の契約と衝突する**。shard 化は `run-test.sh` (グローバルロック + `ensure-test-db` + `artisan test --parallel`) を
   迂回して `vendor/bin/pest -c phpunit.ci-shard.xml` を直接叩く形になる。
   `GlobalTestLockInventoryTest` の deny-by-default は composer/package.json の script しか見ないので
   機械的には素通りするが、「全レーンが公式 entrypoint 経由」という契約の精神を CI だけ破ることになる。
   将来 sharding が本当に必要になったら、**`run-test.sh` 側に shard 引数を通す形**で再設計するべきで、
   その時この未配線ファイルは設計の出発点として役に立たない。
3. **後方互換の並走を残さない** (思考原則 3)。未配線コードを「いつか使うかも」で残すのが台帳ドリフトの発生源だった。

削除物: `scripts/ci/make-shard-phpunit.php` / `scripts/README.md` の該当行 / `.gitignore` の `/phpunit.ci-shard.xml`。

再発防止として `ScriptsReadmeInventoryTest` (Architecture) を新設する。
「あったら便利」ではなく、**今回まさにドリフトが観測された不変条件**を、
禁止事項 1 (不変条件は Architecture テストへの登録まで含めて実装済み) に従って登録するもの。
`BugHuntInventoryCheckInvariantTest` / `PhpstanWrapperInvariantTest` と同じ deny-by-default 型。

### 判断 E: inventory gate の SoT 表現 — 「2 project の配列」を単一ファイルに置く

vitest は root project と `packages/cli` project の 2 つに分かれている。これは統合しない
(思考原則 4: 別物の概念を似ているからで統合しない)。`packages/cli` は独立に公開されうる oclif パッケージで、
node 環境・独自 setupFile・独自 timeout を持ち、root は jsdom + svelte plugin である。

したがって SoT は「1 本の include 配列」ではなく **「project の配列」** として表現する:

```ts
// scripts/test-inventory-config.ts (SoT)
export const TEST_PROJECTS = [
  { name: "root",         root: ".",            include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"] },
  { name: "packages/cli", root: "packages/cli", include: ["tests/**/*.test.ts"] },
] as const;
```

- `vitest.config.ts` / `packages/cli/vitest.config.ts` は**このファイルから include を引く**だけにする
  (他の設定は各 project が持ったまま)。
- gate は「FS 走査で見つけた `*.test.ts` の全数」と「vitest が実際に列挙したファイル」を
  **独立に**求めて突合する。SoT の glob を gate 側でも使ってしまうと同語反復になり、
  glob そのものの誤りを検出できない。

**`packages/cli` から repo root の `scripts/` を import することのトレードオフ**:
CLI パッケージが monorepo root に依存する。ただし対象は `vitest.config.ts` (devtool 設定) のみで、
`package.json#files` は `dist` / `bin` / `README.md` に限定されているため**公開成果物には一切入らない**。
「2 project の include が別々に drift する」ことのリスクの方が大きいと判断して受容する。

### 判断 F: gate の実装制約 (実測で判明した load-bearing な事項)

- `vitest list --json` は **stdout に vite plugin の警告が混入する**ため使えない。
  **必ず `--json=<tmpfile>` でファイルへ出力**して読む (実測で確認)。
- gate 自身が `scripts/**/*.test.ts` に含まれるため、**子プロセス起動を必ず `it()` の内側に置く**
  (top-level に置くと `vitest list` が本ファイルを import した瞬間に無限再帰する)。これは非交渉。
- `scripts/run-vitest.sh` が repo root で vitest を起動するので `process.cwd()` は repo root。
  この前提は `tests/js/bughunt/feedback-probe.test.ts:45` が既に依存しているので**変えない**
  (= root project の `root` オプションを新設しない)。
- `scripts/audit-gate.test.ts` は `./audit-gate` を相対 import している。
  include の書き換えでも alias 追加でもこの解決経路は変わらないが、施策 8 の受入条件に含める。
- 「0 件なら fail」は各 project ごとに判定する
  (片方が 0 件でも合計は非 0 になり得るため、合計での判定は空振りを見逃す)。

### 判断 G: browser lane を CI に載せるための前提 (実測で判明)

- ブラウザ実体は Playwright が別途 DL する。CI で
  **`pnpm exec playwright install --with-deps chromium webkit`** が必須
  (`npx playwright` ではなく `pnpm exec` = root devDependency の `playwright@1.61.1` と同一実体を使う。
  pest-plugin-browser が起動する `run-server` とバージョン実体を一致させるため)。
  ローカルで `~/.cache/ms-playwright` 不在のまま走らせたら
  `PlaywrightOutdatedException` で 2 レーンとも全 fail した = この step を落とすと全滅する。
- 実ブラウザが `public/build` を読むため **`pnpm build` を先に実行**する。
- postgres service と `passport:keys --force` は php job と同じものが必要
  (browser lane は同じ `tests/bootstrap.php` / 同じ pgsql テスト DB を使う)。
- **`BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を CI で上書きしない**。
  T082 の 2 レーン契約と既定直列をそのまま CI にも適用する (§2.1)。
- ffmpeg は browser lane では**入れない** (`tests/Browser/` の 4 ファイルはレンダーを踏まない)。
  レンダー smoke は php job 側の責務のまま。job 時間を無駄に伸ばさない。
- job に `timeout-minutes` を置く (ブラウザのハングで 6 時間燃やさないため)。

---

## 4. 期待効果

### 使命への貢献

- **撮影 PWA (iOS Safari) の履歴復元 PII 漏れ**が CI の恒久回帰になる。
  AGENTS.md ドメイン規約 3 の「3 枚セット」のうち (B)(C) は Browser lane でしか自動検証されない。
- **シナリオ整合の共有ロック規約** (ドメイン規約 1) を守る `ScenarioWritePathInventoryTest` を含む
  Architecture テスト群が、main へ入る全変更に対して実際に走るようになる。
- CLI (`packages/cli`) が emit 経路まで検証され、「動かない CLI を配る」経路が閉じる。

### 具体的な改善見込み

| 指標 | Before (実測) | After (期待) |
|---|---|---|
| CI で実行される PHP テスト | 0 件 (ensure-test-db で exit 1) | 2704 件 |
| CI で実行される Browser テスト | 0 件 | 14 件 × 2 レーン |
| CI で検証される JS test file | 110 (root) + 10 (cli) — ただし include 漏れの検知手段なし | 同数 + **include 漏れ 0 件を機械保証** |
| `packages/cli` の emit 検証 | なし | `tsc -p tsconfig.json` が CI で通ること |
| supply-chain gate | 人手 (`pnpm run audit:gate` を思い出したときだけ) | PR blocking + nightly |
| `scripts/README.md` のドリフト | 1 件 (make-shard) | 0 件 + 再発を Architecture テストが検出 |

---

## 5. 実装方針 (概要)

```
.github/workflows/ci.yml
  php:                + services.postgres (postgres:18-alpine) / setup-php extensions: pdo_pgsql,pgsql / DB_* env
                      ※ ffmpeg + fonts-noto-cjk + fontconfig + fc-match fail-fast は現状維持
  frontend:           + "Build (workspace packages)" step (pnpm build:packages)
  browser-tests:      新設 (postgres service / pnpm build / playwright install --with-deps chromium webkit /
                            composer test:browser / timeout-minutes)
  supply-chain-audit: 新設 (pnpm run audit:gate。continue-on-error なし)
  on:                 + schedule (nightly cron)

packages/cli/src/**                     未使用 import / 定数 7 箇所を削除
pnpm-workspace.yaml / composer.json     advisory 解消のための overrides / 版上げ
docs/supply-chain/accepted-advisories.yaml  upgrade 不能分のみ期限付き登録

scripts/test-inventory-config.ts        新設 (include の SoT。2 project)
vitest.config.ts                        include を SoT から引く
packages/cli/vitest.config.ts           include を SoT から引く
scripts/vitest-inventory-gate.test.ts   新設 (FS 走査 × vitest list の突合 + 0 件 fail)
tsconfig.json                           include に "scripts/**/*.ts" を追加 (実測でエラー 0)

scripts/run-browser-test.contract.test.ts        新設 (sandbox 実走 + 静的契約)
tests/Architecture/PhpunitBrowserConfigParityTest.php  新設
tests/Architecture/ScriptsReadmeInventoryTest.php      新設

scripts/ci/make-shard-phpunit.php       削除
scripts/README.md                       該当行削除 + audit-gate 行の実態追従
.gitignore                              /phpunit.ci-shard.xml 削除
```

### テスト方針 (禁止事項 2: テストなしの実装完了をしない)

CI 設定は「変更したら壊れたことが分かる」形でしか守れないため、**各契約に負のコントロールを必ず付ける**:

- 施策 6/7/9 の新規テストは、正の assertion に加えて **壊れた fixture を検出すること**を同ファイル内で確認する
  (`GlobalTestLockInventoryTest` の「負のコントロール」節と同じ形式)。
- 施策 8 の gate は「列挙 0 件なら fail」を持つことで、gate 自身が空振りしていないことを保証する。

---

## 6. 制約・前提

1. **T099 の契約を壊さない**: ロック機構の 5 ファイルに `CI` 参照を入れない。`run-test.sh` を推測で書き換えない。
   本設計では lock 周りのファイルを**一切変更しない**。
2. **T082 の 2 レーン契約を巻き戻さない**: CI で `BROWSER_TEST_LANES` を絞らない。
3. **T100/T101/T102 の成果を踏襲**: `typecheck:packages` / `test:packages` は既に配線済みなので重複追加しない。
   新規 Architecture テストは `tests/Architecture/` の既存規約 (純関数 + 負のコントロール) に従う。
4. **RefreshDatabase はグローバル適用**、個別 `DatabaseTransactions` を使わない。新規 Architecture テストは DB を触らない。
5. **PHPStan level 10**: 新規 PHP テストは `Webmozart\Assert\Assert` で narrow する (`file_get_contents` の `false` 等)。
6. **advisory 集合は時間で drift する**: 施策 4 の「15 件」は実装着手時点で再実測すること (実測済みの 26/15 も既に台帳の 18 から動いている)。
7. **ローカル検証の限界**: browser lane はブラウザ実体が未 DL のためこの作業環境では実走できない。
   実装時は `pnpm exec playwright install --with-deps chromium webkit` を先に打つこと。

---

## 7. スコープ外

- テスト実行時間の最適化 (sharding / キャッシュ / `composer test` の並列度チューニング)。
  判断 D のとおり、必要性が観測されてから設計する。
- `tsconfig.test.json` の `noUnusedLocals` 厳格化 (判断 C)。
- vitest の 2 project を `test.projects` へ統合するリファクタ (判断 E)。
- bug-hunt 基盤の CI 化 (意図的に隔離されたオプトイン基盤であり、ロック規約にも参加しない)。
- `secret-scan.yml` の変更。
- deploy / release パイプライン。

