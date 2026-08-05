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

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest 4 (+ pest-plugin-browser) / vitest 4 / pnpm 11 workspace / GitHub Actions
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【本設計の性質 — レビュー時に踏まえること】
本バッチは **CI レーン統合** であり、モデル・Controller・Inertia Props・DTO を一切追加しない。
変更対象は GitHub Actions workflow / テストレーンのスクリプトと設定 / テストファイル / 台帳ドキュメント。
したがって「DTO を返しているか」「Factory を追加したか」「DESIGN.md 準拠か」は多くの施策で該当なしになる。
**該当なしの観点を無理に指摘するのではなく、本設計固有のリスクに集中せよ。**

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にテスト、負のコントロールが空振りしないか）
5. DTO/JsonResource パターンの遵守（該当する場合のみ）
6. Inertia Props vs API Response の使い分け（該当する場合のみ）
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（AGENTS.md のセキュリティ不変条件。特に **dev DB 保護** と **CI に secret を持ち込まないこと**）
10. DESIGN.md 準拠 / 11. Atomic Design 準拠（UI 変更が無ければ「該当なし」と述べてよい）

【本設計固有の重点確認事項 — ここを最も厳しく見よ】
A. **施策 1/2 の postgres 化が dev DB 保護機構を壊していないか**。
   `tests/bootstrap.php` の単一点ガード + `TestDatabaseEnv` の allowlist / denylist と、
   workflow で `DB_*` を job env に置くことの相互作用。`DB_DATABASE` を置かない判断は正しいか。
B. **T099 の契約 (CI バイパス禁止) を間接的に破っていないか**。特に施策 6 が
   `GLOBAL_TEST_LOCK_DIR` override を使うことは `GlobalTestLockInventoryTest` の
   禁止事項に触れないか (禁止対象は lane スクリプト自身の設定であってテストの env 渡しではない、という
   設計側の主張が妥当か)。
C. **施策 6 の sandbox 実走方式に穴がないか**。`php` を PATH でスタブ化する、
   `vendor/bin/pest` をスタブ化する、実スクリプトを verbatim コピーする、という構成で
   C1〜C4/C8 が本当に検証できるか。bug-hunt ポート guard の扱いは妥当か。
   orphan 掃除 (C5/C6/C7) を静的検査に落とした判断は妥当か、それとも逃げか。
D. **施策 8 の inventory gate に自己矛盾や偽グリーン経路がないか**。
   「子プロセス起動を it() 内に閉じる」だけで再帰が防げるという主張は正しいか。
   `vitest list --json=<file>` への依存は妥当か。FS 走査の除外リスト (devnotes 等) が
   検出漏れを作らないか。
E. **施策 4/5 の advisory 運用**。`audit-gate.sh` がネットワーク失敗時に空 JSON で
   gate を通す既知の穴を「本バッチでは触らない」とした判断は妥当か (偽グリーン方向のリスク)。
F. **施策 9 の削除判断**と `ScriptsReadmeInventoryTest` の新設が過剰でないか。
G. 施策 10 の W9/W13 (キーの不在を検査する) が YAML parse 後の走査で十分か。
H. 実装順序 (3→1→2→6→7→8→9→4→5→10→11) と incremental モードの妥当性。
   中間状態で main が赤になる経路が残っていないか。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: ci-lane-integration

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

> 本バッチは **モデル / Controller / Inertia Props / DTO を一切追加しない**。
> 変更対象は CI workflow・テストレーンのスクリプトと設定・テストファイル・台帳ドキュメント。
> したがって DTO/JsonResource・Factory・DESIGN.md/Atomic Design の観点は「該当なし」となる施策が多い。
> 各施策の「波及変更」欄でこれを明示する。

## 概念設計リファレンス

`devnotes/20260805-1243-ci-lane-integration/conceptual-design.md`
（conceptual-review Round 1 で **APPROVED**。Warning 3 件は全件反映済み）

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `php` job の pgsql 化 | `.github/workflows/ci.yml` | 高 |
| 2 | `browser-tests` job 新設 | `.github/workflows/ci.yml` | 高 |
| 3 | `packages/cli` の dead import 除去 + `build:packages` の CI 配線 | `packages/cli/src/*` (7 ファイル) / `.github/workflows/ci.yml` | 高 |
| 4 | 未受容 high advisory の解消 | `pnpm-workspace.yaml` / `package.json` / `packages/cli/package.json` / `composer.json` / lockfiles / `docs/supply-chain/accepted-advisories.yaml` | 高 |
| 5 | `supply-chain-audit` job 新設 + nightly + 運用責任の明文化 | `.github/workflows/ci.yml` / `docs/supply-chain/review-checklist.md` | 高 |
| 6 | `scripts/run-browser-test.contract.test.ts` 新設 | `scripts/run-browser-test.contract.test.ts` | 高 |
| 7 | `PhpunitBrowserConfigParityTest` 新設 | `tests/Architecture/PhpunitBrowserConfigParityTest.php` | 中 |
| 8 | vitest include の SoT 化 + inventory gate | `scripts/test-inventory-config.ts` / `vitest.config.ts` / `packages/cli/vitest.config.ts` / `scripts/vitest-inventory-gate.test.ts` / `tsconfig.json` | 高 |
| 9 | `make-shard-phpunit.php` 削除 + 台帳ドリフト解消 + 再発防止 gate | `scripts/ci/make-shard-phpunit.php` (削除) / `scripts/README.md` / `.gitignore` / `tests/Architecture/ScriptsReadmeInventoryTest.php` | 中 |
| 10 | CI workflow inventory gate | `tests/js/architecture/ci-workflow-inventory.test.ts` | 高 |
| 11 | ドキュメント追従 | `docs/testing-browser.md` / `AGENTS.md` | 低 |

**実装順序 (依存関係)**: 3 → 1 → 2 → 6 → 7 → 8 → 9 → 4 → 5 → 10 → 11
（10 は全 job が揃ってから書く。4 は 5 の前提。1/2 は 10 の前提）

---

## 施策 1: `php` job の pgsql 化

### 変更箇所

- ファイル: `.github/workflows/ci.yml` (`jobs.php`)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 10 (`ci-workflow-inventory.test.ts`) が本 job の構成を固定する
- ドキュメント: なし (CI 手順書は存在しない)

### 現行コード

```yaml
jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      - name: Provision ffmpeg for render smoke
        run: |
          sudo apt-get update
          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
          ffmpeg -version
          ffprobe -version
          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
      - name: Pint (code style)
        run: vendor/bin/pint --test
      - name: PHPStan
        run: composer phpstan
      - name: Verify global test lock
        run: bash scripts/verify-global-test-lock.sh
      - name: Pest
        run: composer test
```

### 変更後コード

```yaml
jobs:
  php:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    # pgsql 一本化 (phpunit.xml が DB_CONNECTION=pgsql を <server force> する) の実体。
    # image は docker-compose と同一 major に揃える: ローカルの実測 (2704 passed) を
    # CI の期待値としてそのまま使えるようにするため (major 差は collation / SQL 差で
    # 「CI だけ赤 / CI だけ緑」を生む)。
    services:
      postgres:
        image: postgres:18-alpine
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
          # scripts/ci/pgsql_test_conn.php が maintenance DB として固定で使うため明示する
          POSTGRES_DB: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    # DB_DATABASE は置かない: tests/bootstrap.php が `<slug>_test_<worktree-hash>` を
    # 後勝ちで注入し assertPgsqlTestDatabaseSafe() が fail-closed 検証する単一点ガードを
    # 曖昧にしないため。接続先だけを渡す (pgsql_test_conn.php は shell env を最優先で読む)。
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_USERNAME: postgres
      DB_PASSWORD: postgres
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          # pdo_pgsql は既定で入る保証がない。未導入だと ensure-test-db.php が
          # 「could not find driver」で落ちる (pgsql lane が丸ごと動かない)
          extensions: pdo_pgsql, pgsql
          coverage: none
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Prepare environment
        # passport:keys: OAuth/MCP テストは Passport 鍵 (storage/oauth-*.key) を要する。
        # CI には鍵が無いため生成する (未生成だと "Invalid key supplied" で fail)
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      # レンダー smoke テスト (施策 4) の前提。Dockerfile (dev/bughunt) と別に CI runner にも
      # ffmpeg/ffprobe と字幕フォントを導入し、存在・フォント解決を fail-fast 検証する (層 1)。
      - name: Provision ffmpeg for render smoke
        run: |
          sudo apt-get update
          # fontconfig を明示 (fc-match の依存。ランナー差異で未導入の可能性をゼロにする。design-review R1)
          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
          ffmpeg -version
          ffprobe -version
          # fc-match の終了コードだけでなく、解決 family が Noto CJK であることを機械的に判定
          # (代替フォントへのフォールバックを検出する。-f '%{family}' で family のみ抽出しノイズ耐性を上げる)
          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
      - name: Pint (code style)
        run: vendor/bin/pint --test
      - name: PHPStan
        run: composer phpstan
      # グローバルテストロックの並行挙動ゲート (層 1)。
      # 実ロックには触れず、mktemp -d の scratch 上で待機・シグナル収束・fd 非継承などを検証する。
      - name: Verify global test lock
        run: bash scripts/verify-global-test-lock.sh
      # composer test = scripts/run-test.sh (グローバルロック → ensure-test-db → artisan test --parallel)。
      # CI 専用の起動経路は作らない (T099: CI が検証するものと開発者が走らせるものを同一に保つ)。
      # 1 job = 1 runner なので他 job と競合せず、ロックは無競合で即時取得される。
      - name: Pest
        run: composer test
```

**変更点は 5 つだけ** (既存 step の中身は 1 文字も変えない):
`timeout-minutes` / `services.postgres` / `env` ブロック / `extensions:` / 各所コメント。

### PHPStan 適合チェック

- [x] PHP コード変更なし（該当なし）

### テスト計画

- [x] 施策 10 の `ci-workflow-inventory.test.ts` が以下を固定する:
      `php` job が postgres service を持つ / `extensions` に `pdo_pgsql` を含む /
      `composer test` を呼ぶ / `verify-global-test-lock.sh` を呼ぶ /
      ffmpeg provision と `fc-match` fail-fast が残っている / `continue-on-error` が無い
- [x] 既存テストの更新: 不要 (2704 件がそのまま CI で走るようになるだけ)
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 変更なし

**検証手順 (実装時)**: ローカルで `composer test` が緑であることを再確認した上で push し、
GitHub Actions 上で `Pest` step が `ensure-test-db: created base DB: app_test_<hash>` を出して
2704 件走ることをログで確認する。

### リスク

| リスク | 評価 |
|---|---|
| ローカル (postgres:18-alpine on aarch64) と CI (postgres:18-alpine on x86_64) の挙動差 | 同 image・同 major なので collation / SQL 差はない。アーキ差で挙動が変わる SQL は使っていない |
| `--parallel --processes=4` が runner の 4 vCPU を食い切る | 既存 `run-test.sh` の固定値。ローカルでも同じ値で回っており、変えると T099 の「推測追従しない」に反する。**変えない** |
| 4 worker DB + base DB で接続数が増える | postgres 既定 `max_connections=100` に対し 5 DB / 高々十数接続。問題ない |
| `.env` が sqlite のまま (`.env.example` 由来) | 意図どおり。phpunit.xml が `DB_CONNECTION=pgsql` を `<server force>` し、`.env.testing` が接続先を供給する。`.env` は artisan 系 step 用 |

---

## 施策 2: `browser-tests` job 新設

### 変更箇所

- ファイル: `.github/workflows/ci.yml` (`jobs.browser-tests` を新規追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 6 (スクリプト契約) と施策 10 (workflow 契約) が本 job を守る
- ドキュメント: `docs/testing-browser.md` に「CI でも 2 レーンが走る」旨を追記 (施策 11)

### 変更後コード

```yaml
  # Browser lane (pest-plugin-browser)。Chromium + WebKit の 2 レーンが契約であり
  # (AGENTS.md ドメイン規約 3 / docs/supported-browsers.md / T082)、CI でもレーンを絞らない。
  # WebKit は撮影 PWA の主戦場 iOS Safari に最も近い engine で、ログアウト後の
  # Inertia 履歴からの PII 復元を止める唯一の自動回帰である。
  browser-tests:
    runs-on: ubuntu-latest
    # 実ブラウザがハングしたときに既定 6 時間を燃やさないための上限。
    # 現状 14 テスト × 2 レーン (直列) なので十分な余裕がある。
    timeout-minutes: 45
    services:
      postgres:
        image: postgres:18-alpine
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_USERNAME: postgres
      DB_PASSWORD: postgres
      # BROWSER_TEST_LANES / BROWSER_TEST_PROCESSES は **意図的に設定しない**。
      # 既定 (chromium webkit / 直列 1) が契約であり、CI で上書きするとレーンを
      # 骨抜きにできてしまう (tests/js/architecture/ci-workflow-inventory.test.ts が
      # この不在を deny-by-default で固定する)。
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          extensions: pdo_pgsql, pgsql
          coverage: none
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Install pnpm dependencies
        run: pnpm install --frozen-lockfile
      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      # 実ブラウザは public/build のビルド済アセットを読む (withoutVite() は Browser lane に効かない)。
      - name: Build frontend assets
        run: pnpm build
      # ブラウザ実体は Playwright が別途 DL する。**pnpm exec** を使うこと:
      # pest-plugin-browser が起動する run-server は root devDependency の playwright と
      # 同一実体である必要があり、npx だと別バージョンを引きうる。
      # 未導入だと PlaywrightOutdatedException で 2 レーンとも全 fail する (ローカル実測)。
      # --with-deps は WebKit が Linux で要求する共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を入れる。
      - name: Install Playwright browsers (chromium + webkit)
        run: pnpm exec playwright install --with-deps chromium webkit
      # composer test:browser = scripts/run-browser-test.sh
      # (グローバルロック → config:clear → ensure-test-db → chromium レーン → webkit レーン)。
      # レーン引数は渡さない (§既定が契約)。
      - name: Pest (browser lanes)
        run: composer test:browser
```

**ffmpeg を入れない理由**: `tests/Browser/` の 4 ファイル
(`SmokeTest` / `FlashToastTest` / `AuthenticatedPageBfcacheTest` / `InertiaHistoryRestoreAfterLogoutTest`)
はいずれもレンダーパイプラインを踏まない。レンダー smoke は `php` job の責務のまま置く。

### PHPStan 適合チェック

- [x] PHP コード変更なし（該当なし）

### テスト計画

- [x] 施策 6: `scripts/run-browser-test.contract.test.ts` がスクリプト側の契約
      (2 レーン / 失敗継続 / overall 非ゼロ / 既定直列 / orphan 掃除) を固定
- [x] 施策 10: workflow 側の契約 (`BROWSER_TEST_LANES`/`BROWSER_TEST_PROCESSES` 不在 /
      `playwright install --with-deps chromium webkit` の存在 / `continue-on-error` 不在) を固定
- [x] 既存テストの更新: なし
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 変更なし

### リスク

| リスク | 対応 |
|---|---|
| WebKit の共有ライブラリ不足で全 fail | `--with-deps` で解消。`docs/testing-browser.md` に記載済みの既知事項 |
| `AuthenticatedPageBfcacheTest` の skip 判定が CI で不安定 | 現行実装は「毎回ハーネスの再現能力を実測して skip」する。CI でも同じ判定が走るだけで、判定ロジックは変更しない |
| ジョブ時間の増加 (playwright install + build + 2 レーン) | 独立 job なので他 job のクリティカルパスを伸ばさない。`timeout-minutes: 45` で上限を切る |
| `run-browser-test.sh` の bughunt ポート pre-flight guard が CI で誤発火 | CI runner に bughunt は存在しない。`/dev/tcp` guard は listen 無しで通過する |
| Playwright ブラウザのキャッシュが無く毎回 DL (~数分) | 初期は受容する。速度が問題になったら `actions/cache` を検討 (今必要なものだけ作る) |

---

## 施策 3: `packages/cli` の dead import 除去 + `build:packages` の CI 配線

### 変更箇所

- ファイル: `packages/cli/src/api/client.ts` (L1, L170, L625)
- ファイル: `packages/cli/src/credential/encryption.ts` (L19, L49, L76)
- ファイル: `packages/cli/src/http/schemas.ts` (L1, L18)
- ファイル: `packages/cli/src/oauth/client-id.ts` (L1, L7)
- ファイル: `packages/cli/src/oauth/login.ts` (L1, L25)
- ファイル: `packages/cli/src/oclif/commands/profile/add.ts` (L1, L15)
- ファイル: `packages/cli/src/oclif/commands/whoami.ts` (L1, L8)
- ファイル: `.github/workflows/ci.yml` (`jobs.frontend`)

### 波及変更

- TypeScript 型定義: なし (import 削除のみ。エクスポート面は不変)
- API Resource/DTO: なし
- テストファイル: **なし** — 削除するのは実行時に一切参照されない import なので、
  既存 106 テストの挙動は変わらない (検証: 変更後に `pnpm test:packages` が 106 passed のままであること)
- ドキュメント: なし

### 現行コードと根本原因

実測 (`pnpm build:packages`) の 7 エラー:

```
src/api/client.ts(1,1): error TS6192: All imports in import declaration are unused.
src/credential/encryption.ts(19,1): error TS6133: 'ENV' is declared but its value is never read.
src/http/schemas.ts(1,1): error TS6133: 'BIN_NAME' is declared but its value is never read.
src/oauth/client-id.ts(1,15): error TS6133: 'BIN_NAME' is declared but its value is never read.
src/oauth/login.ts(1,1): error TS6133: 'ENV' is declared but its value is never read.
src/oclif/commands/profile/add.ts(1,1): error TS6133: 'BIN_NAME' is declared but its value is never read.
src/oclif/commands/whoami.ts(1,1): error TS6133: 'BIN_NAME' is declared but its value is never read.
```

**7 件すべての正体は同じ**: `BIN_NAME` / `ENV` が **ブロックコメント内の `${...}` 記法**でしか
参照されていない。例:

```ts
// packages/cli/src/oclif/commands/whoami.ts
import { BIN_NAME } from "../../branding.js";   // ← 実コードでは一度も使わない

/**
 * `${BIN_NAME} whoami` — confirm the active API key resolves to an
 * organization on the configured API server.
 */
```

ブロックコメント内の `${BIN_NAME}` は**テンプレートリテラルではないので展開されない**。
つまり import は「コメントが解決済みに見える」以外の効果を一切持っていない。
削除は挙動的に完全に安全である。

**なぜ `typecheck:packages` が検出しないか** (新規発見・load-bearing):

```jsonc
// packages/cli/tsconfig.json      ← build が使う
{ "noUnusedLocals": true,  "noUnusedParameters": true,  "include": ["src/**/*.ts"] }

// packages/cli/tsconfig.test.json ← typecheck:packages が使う
{ "noUnusedLocals": false, "noUnusedParameters": false, "include": ["src/**/*.ts", "tests/**/*.ts"] }
```

`tsconfig.test.json` が**明示的に無効化している**ため、`typecheck:packages` は
この種のエラーを構造的に検出できない。「typecheck があるから build は要らない」は成立しない。

### 変更後コード

各ファイルで **(a) import を削る** + **(b) コメントの `${...}` 記法をやめる** の 2 点セットで直す。
(b) を伴わせるのは、`${}` を残すと「展開されるつもりの記述」が残り、
将来また import を足す誘因になるため。アプリ名の直書きはしない (branding.ts が SoT という規約を保つ)。

```ts
// packages/cli/src/api/client.ts
- import { ENV, BIN_NAME } from "../branding.js";
  import { readFileSync } from "node:fs";
  ...
- * `--api-key` / `${ENV.API_KEY}` / stored-key users keep their exact
+ * `--api-key` / `ENV.API_KEY` (branding.ts) / stored-key users keep their exact
  ...
- * Used by `${BIN_NAME} logout` against `DELETE /api/v1/me/session`, which returns
+ * Used by the CLI `logout` command against `DELETE /api/v1/me/session`, which returns
```

```ts
// packages/cli/src/credential/encryption.ts
- import { ENV } from "../branding.js";
  import { createCipheriv, createDecipheriv, randomBytes } from "node:crypto";
  ...
- * directly-provided master key (e.g. `${ENV.CREDENTIAL_KEY}`) may pass any
+ * directly-provided master key (e.g. the `ENV.CREDENTIAL_KEY` env var) may pass any
  ...
-     // direct `${ENV.CREDENTIAL_KEY}` that would otherwise ignore the salt),
+     // direct `ENV.CREDENTIAL_KEY` that would otherwise ignore the salt),
```

```ts
// packages/cli/src/http/schemas.ts
- import { BIN_NAME } from "../branding.js";
  import { z } from "zod";
  ...
-                 // T715 Phase 3: public PKCE client id for `${BIN_NAME} login`.
+                 // T715 Phase 3: public PKCE client id for the CLI `login` command.
```

```ts
// packages/cli/src/oauth/client-id.ts
// ENV は L34 / L52 の実コードで使用中。BIN_NAME だけを外す。
- import { ENV, BIN_NAME } from "../branding.js";
+ import { ENV } from "../branding.js";
  ...
- * Resolve the public PKCE client id for `${BIN_NAME} login` (T715 Phase 3).
+ * Resolve the public PKCE client id for the CLI `login` command (T715 Phase 3).
  ...
- *   2. `${ENV.OAUTH_CLIENT_ID}` env
+ *   2. the `ENV.OAUTH_CLIENT_ID` env var
```

```ts
// packages/cli/src/oauth/login.ts
- import { ENV } from "../branding.js";
  import type { Dispatcher } from "undici";
  ...
- * `${ENV.OAUTH_CLIENT_ID}`) — see `resolveCliOAuthClientId`.
+ * the `ENV.OAUTH_CLIENT_ID` env var) — see `resolveCliOAuthClientId`.
```

```ts
// packages/cli/src/oclif/commands/profile/add.ts
- import { BIN_NAME } from "../../../branding.js";
  import { Args, Flags } from "@oclif/core";
  ...
- * `${BIN_NAME} profile:add <name>` — register a new profile and verify
+ * `profile:add <name>` — register a new profile and verify
```

```ts
// packages/cli/src/oclif/commands/whoami.ts
- import { BIN_NAME } from "../../branding.js";
  import { apiGet } from "../../api/client.js";
  ...
- * `${BIN_NAME} whoami` — confirm the active API key resolves to an
+ * `whoami` — confirm the active API key resolves to an
```

CI 側 (`jobs.frontend`):

```yaml
      - name: TypeScript (workspace packages)
        run: pnpm typecheck:packages
+     # emit 経路 (packages/cli/tsconfig.json) の検証。
+     # typecheck:packages が使う tsconfig.test.json は noUnusedLocals/noUnusedParameters を
+     # 明示的に false にしているため、**build を通さないと検出できないエラーが存在する**。
+     # 「typecheck があるから build は不要」は成立しない (実測: main で TS6133/TS6192 7 件)。
+     - name: Build (workspace packages)
+       run: pnpm build:packages
      - name: Vitest (workspace packages)
        run: pnpm test:packages
```

### 設計判断: `noUnusedLocals` を緩めない / `tsconfig.test.json` も変えない

- `noUnusedLocals: false` にして黙らせるのは、禁止事項 2 (PHPStan の型 widen / baseline 化) と同型の逃げ。
  7 件は本当に dead なので**消す**。
- `tsconfig.test.json` 側の `false` は**そのまま残す**。テストのモック関数は未使用引数を持つのが正当で、
  そこを厳格化するのはレーン統合という本バッチの目的と無関係な広域変更になる。
  「**build (`tsconfig.json`) が emit 経路の厳格性を守り、test config はテストの都合を持つ**」
  という役割分担を意図的な設計として残す。

### PHPStan 適合チェック

- [x] PHP コード変更なし（該当なし）

### テスト計画

- [x] 実装後 `pnpm build:packages` が **exit 0** であること (現在 7 エラー → 0)
- [x] 実装後 `pnpm test:packages` が **106 passed のまま**であること (挙動不変の証明)
- [x] 実装後 `pnpm typecheck:packages` が exit 0 のままであること
- [x] 施策 10 が `frontend` job に `pnpm build:packages` step が存在することを固定する
      (= この step を将来こっそり外す退行を止める)
- [x] 既存テストの更新: 不要
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 評価 |
|---|---|
| import 削除で実行時に壊れる | ありえない。7 件とも実コードから参照が 0 件であることを `grep -n 'BIN_NAME\|\bENV\b'` で全ファイル確認済み。`client-id.ts` のみ `ENV` が実使用中なので `BIN_NAME` だけ外す |
| コメント書き換えで branding SoT 規約を破る | アプリ名を直書きせず「the CLI `login` command」等の中立表現にする。`branding.ts` が SoT である旨は同ファイルの doc に残る |
| 将来 dead import が再発する | `build:packages` が CI にある限り即座に赤くなる (それが本施策の目的) |

---

## 施策 4: 未受容 high advisory の解消

### 変更箇所

- ファイル: `pnpm-workspace.yaml` (`overrides` セクション)
- ファイル: `package.json` / `packages/cli/package.json` (直接依存の版上げ)
- ファイル: `composer.json` / `composer.lock` / `pnpm-lock.yaml`
- ファイル: `docs/supply-chain/accepted-advisories.yaml` (upgrade 不能分のみ)

### 波及変更

- TypeScript 型定義: 依存 major が上がる場合は型互換の確認が必要 (下記「実行手順」の 5)
- API Resource/DTO: なし
- テストファイル: 既存全レーン (`composer test` / `pnpm test` / `pnpm test:packages` / `composer test:browser`)
  が回帰検出器。**新規テストは書かない** (依存の版は SoT が lockfile であり、
  「この版であること」をテストで固定すると upgrade のたびにテストを書き換える二重管理になる)

### 現状 (2026-08-05 実測)

```
Total advisories: 26 (low=0, moderate=11, high=15, critical=0)
### ❌ Failures (15)
- npm|brace-expansion  GHSA-3jxr-9vmj-r5cp / GHSA-mh99-v99m-4gvg / GHSA-rgw5-rvv9-x895  (各 ×3 経路)
- npm|js-yaml          GHSA-52cp-r559-cp3m
- npm|postcss          GHSA-r28c-9q8g-f849
- composer|guzzlehttp/guzzle          PKSA-gcrk-3vtt-1r14
- composer|phpoffice/phpspreadsheet   PKSA-r22k-87hv-mfk4 / PKSA-m9cr-9614-rsf7 / PKSA-dqzt-yst9-1w9y
```

依存経路の実測 (`pnpm why`):

| package | 実測版 | 経路 | 性質 |
|---|---|---|---|
| `brace-expansion` | 1.1.15 / 2.1.1 / 5.0.6 (3 major 併存) | `minimatch` ← eslint / @typescript-eslint / @oclif/core (ejs→jake→filelist) | すべて **devDependency 経由 + oclif の実行時**。3 major 併存 |
| `js-yaml` | 4.2.0 | transitive | dev tooling |
| `postcss` | 8.5.15 | root **直接 devDependency** (`^8.5.15`) | build 時 |
| `guzzlehttp/guzzle` | — | `aws/aws-sdk-php` 等の transitive | 実行時 (SSRF 検査経路と隣接) |
| `phpoffice/phpspreadsheet` | ^5.8 | **直接 require** | 実行時 |

### 実行手順 (優先順位は AGENTS.md §依存脆弱性の運用に従う)

1. **着手時に再実測する** (`pnpm run audit:gate`)。advisory 集合は時間で drift する
   — 台帳の「18 件」は実測時点で既に 26 件へ増えていた。**下記の 15 件は着手時点の実測で上書きすること**。
2. **直接依存を upgrade** (最優先):
   - `postcss`: `pnpm up postcss@latest` (root devDependency)
   - `phpoffice/phpspreadsheet`: `composer update phpoffice/phpspreadsheet`
     (major 跨ぎになる場合は `app/` の利用箇所の breaking change を確認する)
3. **transitive を bump**:
   - composer: `composer update guzzlehttp/guzzle --with-all-dependencies`
   - npm: 上流が patched 版を出していれば `pnpm up -r --latest <parent>` で解ける。
     解けない場合のみ `pnpm-workspace.yaml#overrides` に**規約どおりのコメント付き**で追加する:
     ```yaml
     overrides:
         # GHSA-rgw5-rvv9-x895 / GHSA-mh99-v99m-4gvg / GHSA-3jxr-9vmj-r5cp:
         # brace-expansion の DoS 3 件。上流 (eslint / @typescript-eslint / @oclif/core) が
         # minimatch を上げるまでの間、脆弱 major のみ scoped に patched 版へ固定する。
         # 3 major が併存するため major ごとに指定する (規約: 脆弱な major のみ scoped に上げる)。
         'brace-expansion@1': ^1.1.<patched>
         'brace-expansion@2': ^2.0.<patched>
         'brace-expansion@5': ^5.0.<patched>
     ```
     > `overrides` は「pnpm audit の解消のみに使う。機能目的の版固定はここに書かない」という
     > `pnpm-workspace.yaml` 冒頭の既存規約に従う。
4. **それでも残る分のみ accept-risk**: `docs/supply-chain/accepted-advisories.yaml` へ登録。
   high は `expiry` 30 日以内 + `approved_by` / `compensating_controls` / `tracking_issue` 必須
   (`audit-gate.ts` が機械強制)。例:
   ```yaml
   - id: GHSA-xxxx-xxxx-xxxx
     package: brace-expansion
     ecosystem: npm
     severity: high
     owner: ishitoya
     approved_at: 2026-08-05
     expiry: 2026-09-04          # high 上限 30 日
     rationale: |
       dev tooling (eslint / @typescript-eslint) の glob 展開でのみ到達する DoS。
       攻撃者制御の glob パターンを受け取る経路が存在せず、上流の minimatch 更新待ち。
     approved_by: ishitoya
     compensating_controls: 本番実行時バンドルに含まれない (devDependency のみ)
     tracking_issue: docs/TODO.md T<id>
   ```
5. **回帰確認**: `composer test` / `pnpm test` / `pnpm test:packages` / `pnpm build` /
   `pnpm build:packages` / `pnpm typecheck` / `composer phpstan` / `vendor/bin/pint --test` を全て緑にする。
   特に `phpoffice/phpspreadsheet` は実行時依存なので Feature テストで回帰を見る。
6. **完了条件**: `pnpm run audit:gate` が **exit 0**。

### PHPStan 適合チェック

- [x] `phpspreadsheet` の major 更新で型が変わる場合は `composer phpstan` (level 10) で検出される。
      **型を緩めて黙らせない** (禁止事項 2)。API 変更には利用側を追従させる

### テスト計画

- [x] `pnpm run audit:gate` が exit 0 であること (本施策の完了判定そのもの)
- [x] 既存レーン全緑 (上記手順 5)
- [x] 新規テストは書かない — 依存の版の SoT は lockfile であり、テストで版を固定すると二重管理になる。
      「gate が緑であること」は施策 5 の CI job が恒久的に守る
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 対応 |
|---|---|
| `phpspreadsheet` major 更新の breaking change | `composer phpstan` + Feature テストで検出。解消不能なら本 advisory のみ期限付き accept-risk に倒す |
| `overrides` による強制版が上流と非互換 | `pnpm build` / `pnpm test` / `pnpm test:packages` で検出。非互換なら override をやめ accept-risk へ |
| 着手時に advisory が更に増えている | 手順 1 で必ず再実測する。**設計に書いた 15 件は着手時点の実測で置き換える** |
| accept-risk が「実質ベースライン」に堕ちる | `expiry` (high 30 日) が切れれば gate が再び落ちる = 忘却が構造的に不可能。§判断 B に記載 |

---

## 施策 5: `supply-chain-audit` job 新設 + nightly + 運用責任の明文化

### 変更箇所

- ファイル: `.github/workflows/ci.yml` (`on.schedule` 追加 / `jobs.supply-chain-audit` 追加)
- ファイル: `docs/supply-chain/review-checklist.md` (新セクション追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 10 が job の存在と `continue-on-error` 不在を固定する
- ドキュメント: `AGENTS.md` §依存脆弱性 に「CI で blocking + nightly」を 1 行追記 (施策 11)

### 変更後コード

```yaml
on:
  push:
    branches: [main]
  pull_request:
  # 上流で新しい advisory が公開された事実を、無関係な PR のクリティカルパス外で先に検知する。
  # nightly は PR job の **代替ではなく追加** (PR job を降格させない)。
  schedule:
    - cron: "0 20 * * *"   # 05:00 JST
```

```yaml
  # supply-chain 依存脆弱性 gate (AGENTS.md §依存脆弱性の運用)。
  #
  # **continue-on-error を付けない**。soft-fail は「赤いのに緑に見える」= 偽グリーンであり、
  # PHPStan の baseline 化 (禁止事項 2) と同型の逃げになる。
  # 未受容 high/critical で fail、moderate は warn (audit-gate.ts の判定)。
  # 逃げ道は docs/supply-chain/accepted-advisories.yaml の **期限付き** accept-risk のみ
  # (expiry・cleanup・severity 別上限を audit-gate.ts が機械強制するため、
  #  「黙らせて永続化する」ベースラインとは性質が異なる)。
  supply-chain-audit:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - uses: actions/checkout@v4
      # composer audit / pnpm audit の両方を回すため PHP と Node の両方が要る
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Install pnpm dependencies
        run: pnpm install --frozen-lockfile
      # scripts/audit-gate.sh → scripts/audit-gate.ts (tsx 経由)。
      # ローカルの `pnpm run audit:gate` と同一経路 (CI 専用の判定を作らない)。
      - name: Supply-chain audit gate
        run: pnpm run audit:gate
```

`docs/supply-chain/review-checklist.md` へ追加するセクション (§5 の後、付録の前):

```markdown
## 6. CI での実行と運用責任

`pnpm run audit:gate` は GitHub Actions の `supply-chain-audit` job で実行される。

- **PR / push (main)**: blocking。`continue-on-error` は付けない
  (soft-fail は「赤いのに緑に見える」= baseline 化と同型のため採らない)。
- **nightly (05:00 JST)**: 同じ job を `schedule` でも回す。上流で新しい advisory が
  公開された事実を、**無関係な PR のクリティカルパス外**で先に検知するため。
  nightly は PR blocking の代替ではない。

### 一次対応

| 項目 | 決め |
|---|---|
| 一次対応 owner | リポジトリオーナー (`ishitoya`)。nightly / PR いずれの赤化でも同一 |
| 初動 SLA | critical: 当日中に判断 / high: 2 営業日以内に判断 / moderate: warn のみ (SLA なし) |
| 「判断」の中身 | upgrade で解消する、または §3 の上限内で accept-risk を登録する、のいずれか |
| accept-risk の承認者 | 単独開発体制のため `approved_by` = owner。代替統制として `expiry` 上限 (high 30 日) と `tracking_issue` 必須で外部から追跡可能にする (`audit-gate.ts` が両方を機械強制) |
| 自動 upgrade PR (Dependabot / Renovate) | **現時点では導入しない**。gate 単体で運用し「upgrade 追従が人手で回らない」ことが観測されてから検討する |

### 上流由来で全 PR が赤くなったとき

新しい advisory の公開は無関係な PR も止める。これは gate の副作用ではなく**意図した挙動**
(未受容の high を抱えたまま main が進むことを許さない)。逃げ道は §3 の期限付き accept-risk のみで、
`continue-on-error` の追加や gate の除外リスト化はしない。
```

### PHPStan 適合チェック

- [x] PHP コード変更なし（該当なし）

### テスト計画

- [x] 施策 4 完了後、ローカルで `pnpm run audit:gate` が exit 0 であることを確認してから本 job を配線する
      (main を赤で着地させない)
- [x] 施策 10 が固定する: `supply-chain-audit` job が存在する / `pnpm run audit:gate` を呼ぶ /
      `continue-on-error` を持たない / `on.schedule` が存在する
- [x] 既存テストの更新: なし
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 対応 |
|---|---|
| 上流の新 advisory で全 PR が赤くなる | **意図した挙動**。nightly で先出しし、逃げ道は期限付き accept-risk 1 本に統一。§6 の SLA で初動を決めた |
| nightly が誰にも見られない | GitHub Actions の失敗通知が owner に届く。通知経路を増やすのは今回のスコープ外 |
| `pnpm audit` / `composer audit` のネットワーク失敗で advisory 0 件扱いになる | `audit-gate.sh` の既存挙動 (取得失敗時は空 JSON で gate 続行)。**偽グリーン方向のリスクがある既知の穴**だが、本バッチでは触らない (audit-gate.sh の判定変更は別施策。ここで直すと施策 4/5 の完了判定が動く) |

---

## 施策 6: `scripts/run-browser-test.contract.test.ts` 新設

### 変更箇所

- ファイル: `scripts/run-browser-test.contract.test.ts` (新規)

> 配置が `scripts/` なのは、vitest root project の include に `scripts/**/*.test.ts` が
> 既に入っており、`scripts/audit-gate.test.ts` という先例があるため。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイルが新規。施策 8 の inventory gate が本ファイルを列挙対象として捕捉する
- 対象スクリプト (`scripts/run-browser-test.sh`) は**一切変更しない** (現行契約を正本として固定する施策)

### 固定する契約 (現行実装を正本とする)

| # | 契約 | 根拠 |
|---|---|---|
| C1 | 既定レーンは `chromium webkit` の 2 本で、順に実行される | T082 / AGENTS.md ドメイン規約 3 / `docs/supported-browsers.md` |
| C2 | **先頭レーンが失敗しても後続レーンを実行する** | スクリプト内コメント「先頭レーンの失敗で後続レーンを飛ばすと WebKit の回帰を見落とす」 |
| C3 | いずれかのレーンが失敗したら **overall が非ゼロ** | 同上 |
| C4 | 既定は直列 (`BROWSER_TEST_PROCESSES=1`) で、`--parallel` を渡さない | 「`--parallel --processes=1` は全テスト成功でも終了コード 1 になる実測」= 緑を赤と誤報告しない |
| C5 | orphan (`PPID==1`) の `playwright/cli.js run-server` を掃除する | プラグイン側の後始末漏れ対策 |
| C6 | cmdline に `@playwright/` を含むプロセス (bug-hunt の @playwright/cli) は**掃除しない** | bug-hunt との相互不干渉 (方向 1 を構造的に塞ぐ) |
| C7 | 後始末は自前 `trap ... EXIT` ではなく `global_test_lock_on_exit` へ登録する | T099: EXIT trap の所有者はライブラリ 1 箇所 |
| C8 | レーン名は `chromium`→`chrome` / `webkit`→`safari` に写像し、未知レーンは exit 2 | 現行実装 |

### 実装方式

**2 層構成**にする。振る舞いで検証できるものは実プロセスで検証し、
実プロセス化するとかえって脆くなるものだけ静的に検証する。

#### 層 1: sandbox 実走 (C1〜C4, C8)

repo を汚さずに実スクリプトを走らせるため、`mkdtemp` に**最小の repo 骨格を組む**:

```
$SANDBOX/
  scripts/run-browser-test.sh      ← 実ファイルをコピー (verbatim。書き換えない)
  scripts/global-test-lock.sh      ← 実ファイルをコピー
  scripts/ci/ensure-test-db.php    ← 空スタブ (中身不要。`php` 自体をスタブ化するため)
  vendor/bin/pest                  ← 記録用スタブ (argv を JSONL で $SANDBOX/pest-calls.jsonl へ追記)
  artisan                          ← 空スタブ
  bin/php                          ← `exit 0` するだけのスタブ (PATH 先頭に差す)
```

- `run-browser-test.sh` は `cd "$(dirname "$0")/.."` するので、sandbox 内の相対パスで完結する。
- 環境変数:
  - `GLOBAL_TEST_LOCK_DIR=$SANDBOX/lockdir` — ライブラリが「self-test only」として
    明示サポートしている override。**これを使うことで、本テスト自身が `pnpm test` の
    グローバルロックを保持したまま走っても自己デッドロックしない**。
    (`GlobalTestLockInventoryTest` が禁じているのは *lane スクリプトが自分で設定すること*
    であって、テストハーネスが env で渡すことではない。用途はライブラリの記載どおり)
  - `PATH="$SANDBOX/bin:$PATH"` — `php` をスタブへ差し替える
- 検証:
  - **C1**: `pest-calls.jsonl` が 2 行で、1 行目に `--browser chrome`、2 行目に `--browser safari` が
    この順で現れる。かつ `-c phpunit.browser.xml` を含む
  - **C4**: どちらの行にも `--parallel` / `--processes` が**現れない**
  - **C2/C3**: スタブ pest を「1 回目の呼び出しだけ exit 1」に切り替えて再実行し、
    **2 行記録される** (= 後続レーンが走った) かつ **スクリプトの exit code が非ゼロ**
  - **C8**: `BROWSER_TEST_LANES=chrome` (誤った名前) で走らせると exit 2

**pre-flight guard の扱い**: `run-browser-test.sh` は起動時に `127.0.0.1:8010..8018` を覗いて
bug-hunt 走行中なら exit 1 する。sandbox でも verbatim コピーなのでこの guard は動く。
テスト側で**先に同じポートを自前で覗き、listen していたら明示メッセージで fail** させる
(silent skip にしない = 「担保されていない」を隠さない)。
`pnpm test` と bug-hunt の同時実行は `docs/testing-browser.md` が既に非推奨としている。

#### 層 2: 静的契約 (C5, C6, C7)

orphan 掃除の振る舞いを実プロセスで検証するには「PPID が 1 に reparent する」ことに依存する。
これは subreaper (systemd --user 等) の有無で環境依存になり、**偽赤を生む**。
一方この検査で守りたいのは「掃除ロジックが消される / bug-hunt 除外が消される / EXIT trap の
所有権が奪われる」という**編集による退行**であり、静的検査で十分に捕まえられる。
`GlobalTestLockInventoryTest` が既に採っている「コメント行を除去したコードだけを検査する」方式に揃える。

```ts
/** 行頭 (空白除く) が `#` の行を落とす。方針説明コメントで偽赤にしないため
 *  (tests/Architecture/GlobalTestLockInventoryTest.php の globalTestLockCodeLines と同方針)。 */
function codeLines(source: string): string { /* ... */ }
```

検査項目:
- `pgrep -f "playwright/cli.js run-server"` の呼び出しが存在する (C5)
- `ppid` が `"1"` と比較されている (C5)
- `*"@playwright/"*) continue ;;` 相当の除外が存在する (C6)
- `global_test_lock_on_exit cleanup_orphan_playwright` が存在する (C7)
- `trap` + `EXIT` を自前で張っていない (C7)
- 既定値リテラル `BROWSER_TEST_PROCESSES:-1` と `BROWSER_TEST_LANES:-chromium webkit` が存在する
  (層 1 の振る舞い検査と二重化する。層 1 が何らかの理由で空振りしたときの保険)

#### 負のコントロール (必須)

`GlobalTestLockInventoryTest` と同じく、**壊れた fixture を検出できること**を同ファイルで確認する:

- レーンループから `break` するよう改変した source → C2 の静的検査が違反を返す
- `@playwright/` 除外を削った source → C6 が違反を返す
- `global_test_lock_on_exit` を `trap ... EXIT` に戻した source → C7 が違反を返す
- 既定を `BROWSER_TEST_LANES:-chromium` に狭めた source → 既定値検査が違反を返す

### テスト計画

- [x] 新規テスト: `scripts/run-browser-test.contract.test.ts` — 上記 C1〜C8 + 負のコントロール 4 本
- [x] バグ修正ではないので再現テストは不要 (現行契約の固定が目的)
- [x] 既存テストの更新: なし。`scripts/run-browser-test.sh` は**変更しない**
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし (JS テスト)
- [x] タイムアウト: sandbox 実走は数百 ms で終わる (pest はスタブ) が、ロック取得のオーバーヘッドを見て
      `{ timeout: 30_000 }` を明示する

### リスク

| リスク | 対応 |
|---|---|
| sandbox コピーが実ファイルとドリフトする | コピー元は実ファイル 1 本で、書き換えを一切しない。ドリフトのしようがない |
| `GLOBAL_TEST_LOCK_DIR` override の使用が T099 契約違反に見える | ライブラリが「self-test only」として明示サポートする用途。`GlobalTestLockInventoryTest` の禁止対象は **lane スクリプト内の設定** であり、テストが env で渡すことは対象外。本テストのコメントに根拠を明記する |
| bug-hunt 併走時に層 1 が fail する | 明示メッセージで fail させる (silent skip にしない)。`docs/testing-browser.md` が併走を既に非推奨としている |
| 静的検査が実装の言い回し変更で偽赤になる | 検査対象を「消えると契約が壊れるトークン」に限定し、負のコントロールで空振りしないことを保証する |

---

## 施策 7: `tests/Architecture/PhpunitBrowserConfigParityTest.php` 新設

### 変更箇所

- ファイル: `tests/Architecture/PhpunitBrowserConfigParityTest.php` (新規)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルが新規。`phpunit.xml` / `phpunit.browser.xml` は**変更しない**

### 固定する契約

`phpunit.browser.xml` のコメントが既に宣言している契約を機械化する:

> `<php>` の値は phpunit.xml と揃える (乖離させない)。差分は memory_limit のみ

| # | 契約 |
|---|---|
| P1 | 両ファイルの `<php>/<server>` 集合が **name / value / force まで完全一致** |
| P2 | `phpunit.browser.xml` にのみ `<ini name="memory_limit">` があり、`phpunit.xml` には無い |
| P3 | `<ini>` の差分は `memory_limit` **のみ** (他の ini を勝手に増やさない) |
| P4 | 両ファイルの `bootstrap` 属性が `tests/bootstrap.php` で一致 (dev-DB 保護の単一点ガードを共有する根拠) |
| P5 | testsuite は分かれている: `phpunit.xml` は `tests/Browser` を **含まない**、`phpunit.browser.xml` は `tests/Browser` **のみ** |

P5 を入れる理由: `composer test` から Browser テストが誤起動しないことは、
「Browser lane が独立している」という前提そのもの。`phpunit.browser.xml` のコメントが
明示している契約なので一緒に固定する。

### 実装方針

```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * phpunit.xml と phpunit.browser.xml の <php> 設定同値性を固定する。
 *
 * 背景: Browser lane は Feature lane と同じ worktree 固有 pgsql テスト DB を使い、
 * 同じ LLM 実通信遮断 (StrayLlmCallGuard の最終防壁となるダミー API キー) を要求する。
 * 片方にだけ新 provider のダミーキーを足す / 片方だけ SESSION_DRIVER を変える、という
 * 乖離は「Browser lane だけ dev DB を向く」「Browser lane だけ実 LLM を叩く」に直結する。
 * 唯一許される差分は memory_limit (実ブラウザ + in-process サーバ分の余裕)。
 */

/** 設定ファイルの <php> 配下 <server> を name => [value, force] へ正規化する (純関数)。 */
function phpunitServerEntries(string $xml): array { /* DOMDocument + DOMXPath */ }

/** <php> 配下 <ini> を name => value へ正規化する (純関数)。 */
function phpunitIniEntries(string $xml): array { /* ... */ }

/** <testsuites> 配下の <directory> を list<string> で返す (純関数)。 */
function phpunitSuiteDirectories(string $xml): array { /* ... */ }
```

- `file_get_contents()` の `false` は `Assert::string()` で narrow する (PHPStan level 10)。
- `DOMXPath::query()` の戻り (`DOMNodeList|false`) と `item()` の `DOMNode|null` も
  `instanceof DOMElement` で narrow する。
- **DB を触らない** (ファイル読み取りのみ)。`RefreshDatabase` のグローバル適用下で動くが
  DB 操作は無し。個別 `DatabaseTransactions` は使わない。

#### 負のコントロール (必須)

実ファイルは書き換えず、fixture 文字列で以下を検出できることを確認する:
- browser 側にだけ `<server name="SESSION_DRIVER" value="database">` がある fixture → P1 違反
- browser 側の `OPENAI_API_KEY` の `force` が `false` の fixture → P1 違反 (force まで見ていることの証明)
- browser 側に `<ini name="error_reporting">` を追加した fixture → P3 違反
- `phpunit.xml` の testsuite に `tests/Browser` を足した fixture → P5 違反

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`@return array<string, array{value: string, force: bool}>` 等)
- [x] null 安全 (`Assert::string()` / `instanceof DOMElement`)
- [x] DTO を返している: 該当なし (Architecture テストの純関数。配列返却は既存
      `GlobalTestLockInventoryTest` と同じ形式で、DTO 規約は HTTP 応答に対する規約)
- [x] Generics の型パラメータが正しい (`array<string, string>` / `list<string>`)

### テスト計画

- [x] 新規テスト: `PhpunitBrowserConfigParityTest` — P1〜P5 + 負のコントロール 4 本
- [x] 既存テストの更新: なし
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 使わない

### リスク

| リスク | 対応 |
|---|---|
| 将来 Browser lane にだけ正当な `<server>` 差分が必要になる | そのときは差分を**明示的な例外リストとして本テストに登録**させる (deny-by-default)。黙って乖離させない |
| DOM 解析が phpunit.xml のフォーマット変更で壊れる | XPath で `//php/server` を引くので整形の影響を受けない |

---

## 施策 8: vitest include の SoT 化 + inventory gate

### 変更箇所

- ファイル: `scripts/test-inventory-config.ts` (新規)
- ファイル: `vitest.config.ts` (L28 の `include`)
- ファイル: `packages/cli/vitest.config.ts` (L5 の `include`)
- ファイル: `scripts/vitest-inventory-gate.test.ts` (新規)
- ファイル: `tsconfig.json` (`include` に `scripts/**/*.ts` を追加)

### 波及変更

- TypeScript 型定義: `scripts/test-inventory-config.ts` の `TestProject` 型が新規。
  外部に公開する型ではない (repo 内 devtool 設定)
- Inertia Props: なし / API Resource/DTO: なし
- テストファイル: `scripts/vitest-inventory-gate.test.ts` が新規。
  **既存の 2 ファイルの前提を壊さないことが受入条件**:
  - `tests/js/bughunt/feedback-probe.test.ts:45` の `process.cwd()` = repo root 前提
    → root project に `root` オプションを新設しないので不変
  - `scripts/audit-gate.test.ts` の `import { ... } from "./audit-gate"`
    → include glob も alias も変えないので不変
- ドキュメント: `scripts/README.md` に新規 2 ファイルの行を追加 (施策 9 の台帳規約)

### 現行コード

```ts
// vitest.config.ts
        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
```

```ts
// packages/cli/vitest.config.ts
        include: ["tests/**/*.test.ts"],
```

```jsonc
// tsconfig.json
    "include": [
        "resources/js/**/*.ts",
        "resources/js/**/*.svelte",
        "tests/js/**/*.ts"
    ],
```

### 変更後コード

```ts
// scripts/test-inventory-config.ts (新規) — vitest の include の単一 SoT。
//
// root と packages/cli は **統合しない**: root は jsdom + svelte plugin、
// packages/cli は node 環境 + 独自 setupFile + 独自 timeout の別 project であり、
// 「似ているから」で 1 つにしない (AGENTS.md 思考原則 4)。
// 統合する代わりに **include だけ** を本ファイルへ集約し、
// scripts/vitest-inventory-gate.test.ts が「FS 上の *.test.ts が全部どこかの
// project に入っているか」を独立に突き合わせる。

/** vitest project 1 つ分の収集定義。 */
export interface TestProject {
    /** 人間可読な識別子 (gate の失敗メッセージに出す)。 */
    readonly name: string;
    /** repo root からの相対 project root。vitest を起動する cwd でもある。 */
    readonly root: string;
    /** project root からの相対 include glob。 */
    readonly include: readonly string[];
}

/**
 * 全 vitest project の inventory。
 * **新しい project / 新しい include を足したらここに書く**。書き忘れると
 * scripts/vitest-inventory-gate.test.ts が「どの project にも入らない test file」を検出して落ちる。
 */
export const TEST_PROJECTS: readonly TestProject[] = [
    {
        name: "root",
        root: ".",
        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
    },
    {
        name: "packages/cli",
        root: "packages/cli",
        include: ["tests/**/*.test.ts"],
    },
] as const;

/** name から project を引く (config 側で使う。未知名は fail-fast)。 */
export function testProject(name: string): TestProject {
    const found = TEST_PROJECTS.find((p) => p.name === name);
    if (!found) throw new Error(`unknown vitest project: ${name}`);
    return found;
}
```

```ts
// vitest.config.ts
+ import { testProject } from "./scripts/test-inventory-config";
  ...
-         include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
+         // include の正本は scripts/test-inventory-config.ts (2 project 分を 1 箇所で持つ)。
+         // scripts/vitest-inventory-gate.test.ts が FS 走査と突き合わせて漏れを検出する。
+         include: [...testProject("root").include],
```

```ts
// packages/cli/vitest.config.ts
+ import { testProject } from "../../scripts/test-inventory-config";
  ...
-         include: ["tests/**/*.test.ts"],
+         // include の正本は repo root の scripts/test-inventory-config.ts。
+         // 本パッケージが monorepo root を参照するのはこの devtool 設定のみで、
+         // package.json#files は dist/bin/README.md に限定されているため公開成果物には入らない。
+         include: [...testProject("packages/cli").include],
```

```jsonc
// tsconfig.json
    "include": [
        "resources/js/**/*.ts",
        "resources/js/**/*.svelte",
-       "tests/js/**/*.ts"
+       "tests/js/**/*.ts",
+       // scripts/ 配下の TS (audit-gate.ts / test-inventory-config.ts 等) は
+       // これまで pnpm typecheck の対象外だった。include の SoT を scripts/ に置く以上、
+       // SoT 自身が型検査されないのは弱いので対象に入れる (実測: エラー 0 件)。
+       // vitest.config.ts は入れない (vitest 側の型で 2 件出るため。設定ファイルは対象外に留める)。
+       "scripts/**/*.ts"
    ],
```

> **実測済み**: `include` に `scripts/**/*.ts` を足して `tsc --noEmit` した結果 **エラー 0 件**。
> `vitest.config.ts` を足すと `hot` / `minWorkers` で 2 件出るため足さない。

### `scripts/vitest-inventory-gate.test.ts` (新規)

```ts
/**
 * vitest inventory gate — 「書いたのに走っていないテスト」を deny-by-default で検出する。
 *
 * 検査は 2 系統を **独立に** 求めて突き合わせる:
 *   A. FS 走査  — repo を自前で歩いて `*.test.ts` を全部拾う (SoT の glob を使わない)
 *   B. vitest 列挙 — 各 project で `vitest list --json=<tmpfile>` を実行して実際の収集結果を取る
 *
 * SoT の glob を A にも使うと同語反復になり、**glob そのものの誤りを検出できない**。
 * だから A は glob を使わず「拡張子が .test.ts のファイル」という素朴な定義で歩く。
 *
 * **非交渉の実装制約**:
 *   1. 子プロセス起動は必ず `it()` の内側に置く。本ファイル自身が root project の
 *      include に入るため、top-level で spawn すると `vitest list` が本ファイルを
 *      import した瞬間に無限再帰する。
 *   2. `vitest list --json` は **stdout に vite plugin の警告が混ざる** (実測)。
 *      必ず `--json=<tmpfile>` でファイル出力し、ファイルを読む。
 *   3. 再帰防止用の env フラグは導入しない。`vitest list` は収集のみで実行しないため
 *      制約 1 だけで再帰は起きない。フラグを足すと「そのフラグが立つと gate が空振りする」
 *      新しい偽グリーン経路を作ることになる。
 */
```

検査項目:

| # | 検査 | fail 条件 |
|---|---|---|
| G1 | 各 project の列挙結果が **0 件でない** | どれか 1 project でも 0 件 (空振り gate の防止。合計では判定しない) |
| G2 | FS 走査で見つけた全 `*.test.ts` が、いずれかの project の列挙に含まれる | 1 件でも orphan があれば、ファイル名を列挙して fail |
| G3 | 列挙結果にあって FS に無いファイルが無い | 逆方向の整合 (理論上ゼロ) |
| G4 | **gate 自身** (`scripts/vitest-inventory-gate.test.ts`) が root project の列挙に含まれる | gate が SoT の外にいる自己矛盾の検出 |
| G5 | 2 project の列挙結果が**互いに素** (同じファイルを 2 回走らせていない) | 重複実行の検出 |

実装の要点:

- **FS 走査の除外**: `node_modules` / `vendor` / `.git` / `dist` / `devnotes` / `.claude` /
  `storage` / `public/build` / `coverage`。
  `devnotes/` を除外するのは、過去の設計レビュー記録に `*.test.ts` 断片が含まれうるため
  (`codex-model-consistency.test.ts` が同じ理由で devnotes を除外している先例に倣う)。
  **除外リスト自体を定数として明示**し、コメントで各項目の理由を書く。
- **列挙の実行**: `execFileSync("pnpm", ["exec", "vitest", "list", `--json=${tmp}`], { cwd })`。
  `cwd` は `resolve(repoRoot, project.root)`。stdio は `"ignore"` (警告混入を無視)。
- **結果の正規化**: `vitest list` は**絶対パス**を返す (実測)。FS 走査側も絶対パスに揃え、
  `realpath` を通してから比較する (シンボリックリンク経由の worktree で不一致にしないため)。
- **タイムアウト**: root project の列挙は全 110 ファイルを import するため数秒〜十数秒かかる。
  `it(..., { timeout: 180_000 })` を明示する。列挙は 1 回だけ行い `beforeAll` で共有する
  (ただし `beforeAll` も `it` と同様にモジュール top-level ではないので制約 1 を満たす)。
- **repo root の解決**: `process.cwd()` を使う (`scripts/run-vitest.sh` が repo root で起動するため。
  `feedback-probe.test.ts:45` と同じ前提を共有する)。

#### 負のコントロール (必須)

実 FS は書き換えず、**純関数に切り出した突合ロジック**に fixture を渡して検出を確認する:

```ts
/** FS 集合と列挙集合を突き合わせる純関数 (テストしやすさのため副作用と分離する)。 */
export function inventoryViolations(
    fsFiles: readonly string[],
    enumeratedByProject: ReadonlyMap<string, readonly string[]>,
): string[];
```

- どの project にも入らないファイルを 1 件混ぜた fixture → G2 違反を返す
- ある project の列挙が空の fixture → G1 違反を返す
- 同じファイルが 2 project に現れる fixture → G5 違反を返す
- 正常な fixture → 空配列

### テスト計画

- [x] 新規テスト: `scripts/vitest-inventory-gate.test.ts` — G1〜G5 + 負のコントロール 4 本
- [x] 現状で緑になること (実測: root 110 files / packages/cli 10 files / orphan 0 件)
- [x] 既存テストの更新: **なし**。ただし以下を回帰確認する:
      `tests/js/bughunt/feedback-probe.test.ts` が緑のまま (cwd 前提) /
      `scripts/audit-gate.test.ts` が緑のまま (相対 import の解決経路)
- [x] `pnpm typecheck` が `scripts/**/*.ts` を含めた状態で緑であること (実測でエラー 0 を確認済み)
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 対応 |
|---|---|
| `vitest list` の子プロセスが遅く CI/ローカルを詰まらせる | 各 project 1 回のみ。root で数秒〜十数秒。`timeout: 180_000` で余裕を取る |
| gate 自身の import で無限再帰 | 制約 1 (spawn を `it`/`beforeAll` 内に閉じる) で構造的に回避。設計上の非交渉事項として明記 |
| `--json` の stdout 混入で JSON parse に失敗 | 実測済み。`--json=<tmpfile>` でファイル出力する (これも非交渉) |
| `packages/cli` が repo root を参照する結合 | 対象は `vitest.config.ts` のみ。`package.json#files` が `dist`/`bin`/`README.md` に限定されており公開成果物に入らない。トレードオフとして受容 (概念設計 判断 E) |
| `vitest list` の出力形式が将来変わる | G1 (0 件 fail) が壊れたことを即座に教える。silent に空集合を返す方向の変化は G1 で捕まる |
| `tsconfig.json` に `scripts/**` を足して既存が壊れる | 実測でエラー 0 件。`vitest.config.ts` は意図的に除外 |

---

## 施策 9: `make-shard-phpunit.php` 削除 + 台帳ドリフト解消 + 再発防止 gate

### 変更箇所

- ファイル: `scripts/ci/make-shard-phpunit.php` (**削除**)
- ファイル: `scripts/README.md` (該当行の削除 + 実態追従)
- ファイル: `.gitignore` (L20-21 の 2 行削除)
- ファイル: `tests/Architecture/ScriptsReadmeInventoryTest.php` (新規)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `ScriptsReadmeInventoryTest` が新規
- 参照確認済み: `make-shard-phpunit.php` の参照は `.gitignore` / `scripts/README.md` /
  自ファイルの 3 箇所のみ (`grep -rn "make-shard"` で全数確認)。**呼び出し元は存在しない**

### 現行コード

```markdown
<!-- scripts/README.md -->
| `ci/make-shard-phpunit.php` | GitHub Actions の matrix sharding 用に、担当テストファイルのみの phpunit 設定を生成 | CI から自動呼び出し |
```

```gitignore
# CI matrix sharding の生成物 (scripts/ci/make-shard-phpunit.php)
/phpunit.ci-shard.xml
```

### 変更後コード

1. `scripts/ci/make-shard-phpunit.php` を `git rm`
2. `scripts/README.md` の該当行を削除。あわせて施策 8 の新規 2 ファイルの行を追加:

```markdown
| `test-inventory-config.ts` | vitest の include (root / packages/cli の 2 project) の単一 SoT。`vitest.config.ts` と `packages/cli/vitest.config.ts` が本ファイルから include を引く | 両 vitest config から import (直接実行しない) |
| `vitest-inventory-gate.test.ts` | FS 走査と `vitest list` の突合による inventory gate。どの project にも入らない `*.test.ts` (= 書いたのに走っていないテスト) と、列挙 0 件の空振りを検出 | `pnpm test` |
| `run-browser-test.contract.test.ts` | `run-browser-test.sh` の契約テスト (2 レーン実行 / 失敗レーンがあっても全レーン実行して overall 非ゼロ / 既定直列 / orphan playwright 掃除 / bug-hunt 除外) | `pnpm test` |
```

3. `.gitignore` の 2 行を削除
4. `tests/Architecture/ScriptsReadmeInventoryTest.php` を新設

### `ScriptsReadmeInventoryTest` の設計

```php
/*
 * 台帳規約 (AGENTS.md): 「scripts/ へスクリプトを追加したら scripts/README.md の表に 1 行追記する」
 * を deny-by-default で機械強制する。
 *
 * 本テストを足す理由: この規約は実際にドリフトした (make-shard-phpunit.php が
 * 「CI から自動呼び出し」と書かれたまま、どこからも呼ばれていなかった)。
 * 禁止事項 1 に従い、不変条件を Architecture テストへ登録するところまでを「実装済み」とする。
 */
```

| # | 検査 | fail 条件 |
|---|---|---|
| S1 | `scripts/` 直下 + `scripts/ci/` の全ファイルが README の表に行を持つ | 台帳に無いファイルがある (追加時の書き忘れ) |
| S2 | README の表の全行に対応する実ファイルがある | 実体の無い行がある (削除時の書き忘れ = 今回のドリフト) |
| S3 | 各行の「用途」「実行タイミング」列が空でない | 「用途と実行タイミングが書けないスクリプトは昇格しない」という規約の機械化 |

- 走査対象: `scripts/*` と `scripts/ci/*` のファイル。ディレクトリ (`scripts/ci`) 自体は除く。
- README のパス表記は `` `run-test.sh` `` / `` `ci/ensure-test-db.php` `` の形式なので、
  `scripts/` からの相対パスとして正規化して突き合わせる。
- **明示 exemption を持たない** (deny-by-default)。除外したいファイルが出たら、
  そのときに理由付きで定数へ登録させる。
- `file_get_contents` の `false` は `Assert::string()` で narrow (PHPStan level 10)。

#### 負のコントロール (必須)

fixture 文字列で以下を検出できることを確認する (実ファイルは読まない純関数に切り出す):
- 表に無いファイル名を FS 側に足した fixture → S1 違反
- 実体の無い行を README 側に足した fixture → S2 違反 (= 今回のドリフトそのものを再現)
- 「実行タイミング」列が空の行 → S3 違反

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`@return list<string>` / `@return array<string, array{purpose: string, timing: string}>`)
- [x] null 安全 (`Assert::string()`)
- [x] DTO を返している: 該当なし (Architecture テストの純関数)
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] 新規テスト: `ScriptsReadmeInventoryTest` — S1〜S3 + 負のコントロール 3 本
- [x] 既存テストの更新: なし
- [x] 削除の安全確認: `grep -rn "make-shard" .` の結果が (devnotes を除き) 0 件になること
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 使わない

### リスク

| リスク | 対応 |
|---|---|
| 将来 sharding が必要になったとき、この実装が失われる | git 履歴に残る。ただし概念設計 判断 D のとおり、**再導入時は `run-test.sh` 側に shard 引数を通す設計**にすべきで、この未配線実装 (公式 entrypoint を迂回する形) は出発点として適切でない |
| `ScriptsReadmeInventoryTest` が過剰 | 今回まさにドリフトが観測された不変条件の登録であり「あったら便利」ではない。禁止事項 1 の要求そのもの |
| README の表フォーマット変更で偽赤 | パースを「行頭 `|` + 1 列目がバッククォート囲み」に限定し、負のコントロールで空振りしないことを保証する |

---

## 施策 10: CI workflow inventory gate

### 変更箇所

- ファイル: `tests/js/architecture/ci-workflow-inventory.test.ts` (新規)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルが新規。`yaml` は既存 devDependency (`^2.9.0`。`scripts/audit-gate.ts` が使用中)
- 対象 (`.github/workflows/ci.yml`) は施策 1/2/3/5 で変更済みの状態を固定する

### なぜ必要か (conceptual-review R1 [Warning])

施策 6 は**スクリプトの契約**を守るが、workflow 側で

- `browser-tests` の env に `BROWSER_TEST_LANES: chromium` を足す
- どこかの step に `continue-on-error: true` を足す
- `pnpm test:packages` / `pnpm build:packages` の step を消す

といった退行は**スクリプトを一切壊さずに**実行できる。
「レーンが CI で実際に走っている」を守るには workflow 自体を inventory 化する必要がある。

### 固定する契約

| # | 検査 |
|---|---|
| W1 | job 集合が `{php, frontend, browser-tests, supply-chain-audit}` と**完全一致** (deny-by-default。job を増やしたらここに登録させる) |
| W2 | `php` / `browser-tests` が `services.postgres` を持ち、image が `postgres:18-alpine` |
| W3 | `php` / `browser-tests` の `setup-php` が `extensions` に `pdo_pgsql` を含む |
| W4 | `php` が `composer test` と `bash scripts/verify-global-test-lock.sh` を実行する step を持つ |
| W5 | `php` の ffmpeg provision step が `ffmpeg` / `fonts-noto-cjk` / `fontconfig` を install し、`fc-match` の fail-fast を含む |
| W6 | `browser-tests` が `composer test:browser` を実行する |
| W7 | `browser-tests` が `pnpm exec playwright install --with-deps chromium webkit` を実行する |
| W8 | `browser-tests` が `pnpm build` を実行する (実ブラウザが public/build を読む前提) |
| W9 | **`BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` が workflow 全体のどこにも現れない** (T082 の 2 レーン契約と既定直列の workflow 側固定) |
| W10 | `frontend` が `pnpm test` / `pnpm test:packages` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm build` / `pnpm lint` / `pnpm typecheck` をすべて実行する |
| W11 | `supply-chain-audit` が `pnpm run audit:gate` を実行する |
| W12 | `on.schedule` が存在する (nightly) |
| W13 | **workflow 全体 (job / step のどのレベル) にも `continue-on-error` が現れない** — 判断 B の「soft-fail を採らない」の機械強制 |

W9 / W13 は「値が正しいこと」ではなく「**キーが存在しないこと**」を検査する。
YAML を parse した上で再帰的に走査し、キー名の出現自体を禁じる
(文字列 grep だとコメント内の言及で偽赤になるため、parse 後の構造を歩く)。
ただしコメントは parse 時に落ちるので、`BROWSER_TEST_LANES` を**コメントで説明する**ことは許される
(施策 2 の workflow にその説明コメントを置いている)。

### 実装方針

```ts
import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { parse as parseYaml } from "yaml";

/** ci.yml の最小構造 (検査に必要な範囲のみ)。 */
interface WorkflowStep { name?: string; uses?: string; with?: Record<string, unknown>; run?: string; env?: Record<string, unknown>; }
interface WorkflowJob { "runs-on"?: string; services?: Record<string, { image?: string }>; env?: Record<string, unknown>; steps?: WorkflowStep[]; }
interface Workflow { on?: Record<string, unknown>; jobs?: Record<string, WorkflowJob>; }

/** 全 run 文字列を job 単位で連結する (step の分割に依存せず「実行しているか」を見るため)。 */
function runScript(job: WorkflowJob): string { /* ... */ }

/** 任意の深さのオブジェクト木に指定キーが現れるかを探す純関数 (W9 / W13 用)。 */
export function findKeyPaths(node: unknown, key: string, path = "$"): string[] { /* ... */ }
```

- `process.cwd()` = repo root 前提 (`feedback-probe.test.ts` と同じ)。
- W4/W6/W7/W8/W10/W11 は `runScript(job).includes("...")` で判定する。
  step 名や step 分割の仕方に依存させない (リファクタで偽赤にしない)。

#### 負のコントロール (必須)

fixture の workflow オブジェクトに対して:
- `continue-on-error: true` を持つ step を混ぜた fixture → `findKeyPaths` が 1 件返す
- `env: { BROWSER_TEST_LANES: "chromium" }` を持つ job → 1 件返す
- 正常な fixture → 0 件

### テスト計画

- [x] 新規テスト: `ci-workflow-inventory.test.ts` — W1〜W13 + 負のコントロール 3 本
- [x] 施策 1/2/3/5 の実装完了後に緑になること
- [x] 既存テストの更新: なし
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 対応 |
|---|---|
| workflow の正当な改善 (step の並べ替え / 名前変更) で偽赤 | 判定を「job 単位の run 文字列に含まれるか」に寄せ、step 名や順序に依存させない |
| W1 の完全一致が job 追加のたびに落ちる | それが意図 (deny-by-default)。job を足す人に「レーン契約を意識させる」ためのコスト |
| 本テストが `frontend` job で走るため、`frontend` job 自体が壊れると gate も走らない | `php` job が独立に緑/赤を出す。単一 job の全滅で全 gate が消える構造にはなっていない |

---

## 施策 11: ドキュメント追従

### 変更箇所

- ファイル: `docs/testing-browser.md` (「実行」節)
- ファイル: `AGENTS.md` (§依存脆弱性 (supply-chain) の運用)

### 波及変更

- コード変更なし。台帳・規約の実態追従のみ

### 変更内容

`docs/testing-browser.md` の「実行」節に追記:

```markdown
### CI での実行

`.github/workflows/ci.yml` の `browser-tests` job が、**Chromium / WebKit の 2 レーンをそのまま**
実行する (レーン限定も並列度上書きもしない)。job は postgres service +
`pnpm build` + `pnpm exec playwright install --with-deps chromium webkit` を前提として
`composer test:browser` を呼ぶ。CI 専用の起動経路は作らない (T099: CI が検証するものと
開発者が走らせるものを同一に保つ)。

workflow 側で `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を設定する退行は
`tests/js/architecture/ci-workflow-inventory.test.ts` が、スクリプト側の契約は
`scripts/run-browser-test.contract.test.ts` が deny-by-default で止める。
```

`AGENTS.md` §依存脆弱性の運用 に 1 行追記:

```markdown
- gate は CI (`supply-chain-audit` job) で **blocking** 実行され、加えて nightly (05:00 JST) でも回る。
  `continue-on-error` は付けない (soft-fail = 偽グリーン)。運用責任 (owner / 初動 SLA) は
  `docs/supply-chain/review-checklist.md` §6
```

### テスト計画

- [x] `docs/testing-browser.md` の記述はテスト対象外 (実体は施策 6/10 のテストが守る)
- [x] `AGENTS.md` の記述も同様
- [x] 既存テストの更新: なし。ただし `BugHuntSkillInvariantTest` 等が AGENTS.md を読んでいないことを
      実装時に確認する (`grep -rn "AGENTS.md" tests/`)

### リスク

| リスク | 対応 |
|---|---|
| ドキュメントだけ更新して実体が伴わない | 実体側は施策 6/10 のテストが守る。ドキュメントは説明であって契約の正本ではない |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 11 施策すべてが `.github/workflows/ci.yml` か「テストレーンの契約」という単一テーマに収束し、施策間に明確な依存 (3→1→2→6→7→8→9→4→5→10→11) がある。特に **施策 10 (workflow inventory gate) は施策 1/2/3/5 が揃って初めて緑になる**ため、分割すると中間状態で必ず赤になる。同一 worktree で順に積む方が中間状態を緑に保てる |
| 競合リスク | `.github/workflows/ci.yml` を施策 1/2/3/5 が触るため、これらを別々の worktree で並行実装すると確実に競合する。**同一 worktree で直列に実装すること** |
| 分割の余地 | 施策 4 (advisory 解消) + 施策 5 (job 配線) だけは、advisory 集合が時間で drift するため**先行 PR として切り出す判断があり得る** (conceptual-review R1 [Suggestion])。その場合も「4 が緑になってから 5 を配線する」順序は不変。切り出したら施策 10 の W1/W11/W12 は後続バッチで有効化する |
| main を赤にしないための着地順 | 実装中は各施策の完了時点でローカル全レーン (`composer test` / `pnpm test` / `pnpm test:packages` / `pnpm build` / `pnpm build:packages` / `pnpm typecheck` / `composer phpstan` / `vendor/bin/pint --test`) を緑に保つ。CI job の追加 (施策 2/5) は、対応するローカル実走が緑になったことを確認してから行う |

### 実装完了の判定条件

1. ローカル: `composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages` /
   `pnpm lint` / `pnpm typecheck` / `pnpm typecheck:packages` / `pnpm build` / `pnpm build:packages` /
   `composer phpstan` / `vendor/bin/pint --test` / `pnpm run audit:gate` が**すべて exit 0**
2. CI: `php` / `frontend` / `browser-tests` / `supply-chain-audit` の **4 job すべてが緑**
3. 新規テスト 5 本 (施策 6/7/8/9/10) がすべて負のコントロールを持ち、
   壊れた fixture を実際に検出することを確認済み


---

## 関連する現行コード

### `.github/workflows/ci.yml`

```
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Prepare environment
        # passport:keys: OAuth/MCP テストは Passport 鍵 (storage/oauth-*.key) を要する。
        # CI には鍵が無いため生成する (未生成だと "Invalid key supplied" で fail)
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      # レンダー smoke テスト (施策 4) の前提。Dockerfile (dev/bughunt) と別に CI runner にも
      # ffmpeg/ffprobe と字幕フォントを導入し、存在・フォント解決を fail-fast 検証する (層 1)。
      - name: Provision ffmpeg for render smoke
        run: |
          sudo apt-get update
          # fontconfig を明示 (fc-match の依存。ランナー差異で未導入の可能性をゼロにする。design-review R1)
          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
          ffmpeg -version
          ffprobe -version
          # fc-match の終了コードだけでなく、解決 family が Noto CJK であることを機械的に判定
          # (代替フォントへのフォールバックを検出する。-f '%{family}' で family のみ抽出しノイズ耐性を上げる)
          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
      - name: Pint (code style)
        run: vendor/bin/pint --test
      - name: PHPStan
        run: composer phpstan
      # グローバルテストロックの並行挙動ゲート (層 1)。
      # 実ロックには触れず、mktemp -d の scratch 上で待機・シグナル収束・fd 非継承などを検証する。
      - name: Verify global test lock
        run: bash scripts/verify-global-test-lock.sh
      - name: Pest
        run: composer test

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
      - name: Install dependencies
        run: pnpm install --frozen-lockfile
      - name: ESLint
        run: pnpm lint
      - name: TypeScript
        run: pnpm typecheck
      - name: Vitest
        run: pnpm test
      - name: TypeScript (workspace packages)
        run: pnpm typecheck:packages
      - name: Vitest (workspace packages)
        run: pnpm test:packages
      - name: Build
        run: pnpm build

```
### `scripts/run-browser-test.sh`

```
#!/usr/bin/env bash
#
# scripts/run-browser-test.sh — Browser テスト (pest-plugin-browser) を排他 + 並列上限付きで実行する。
#
# 背景:
#   - pest-plugin-browser は in-process サーバ + Playwright を起動する。
#     `--parallel` のプロセス数を未指定 (= nproc) にするとブラウザの同時起動で
#     devcontainer がハングし得るため、既定 1 = 直列に固定する
#     (上書きは BROWSER_TEST_PROCESSES=N。2 以上でのみ parallel runner を使う。理由は下記)。
#   - **Chromium / WebKit の 2 レーンを実行する契約**。bfcache 復元シナリオ
#     (tests/Browser/AuthenticatedPageBfcacheTest.php) は Chromium では再現できず
#     (Playwright 既定の --disable-back-forward-cache で bfcache 自体が無効。
#      仮に有効化しても no-store ページは cookie 変更で evict される)、
#     WebKit レーンが正本になる (ただし WebKit も現状は復元せず skip。原因未特定)。
#     実行時間を理由に WebKit を落とすことはしない (落とすと恒久自動回帰が消える)。
#     レーンの意味と保証範囲は docs/supported-browsers.md。
#     レーン限定実行は BROWSER_TEST_LANES="chromium" のように上書きする。
#   - Browser lane は Feature lane と同じ worktree 固有 base テスト DB
#     (<slug>_test_<worktree-hash>) と per-worker DB (_test_<token>) を使い、さらに
#     実ブラウザ 2 engine と **マシン全体スコープの playwright 掃除** を伴う。
#     排他は scripts/global-test-lock.sh のグローバルロック (同一 UID・同一マシン) に
#     一本化した。旧 worktree-local ロックは cross-worktree の相互破壊を防げないため廃止した。
#   - pest 終了後に playwright run-server (node) が orphan (PPID 1) として残る
#     プラグイン側の後始末漏れがある。orphan は親プロセスのパイプ fd を握って
#     呼び出し元を詰まらせる + プロセスを leak するため、実行前後に掃除する。
#
# 前提: pnpm build 済み (実ブラウザが public/build を読む) +
#       `pnpm exec playwright install chromium webkit` 済み。詳細は docs/testing-browser.md。
#
# 使い方:
#   composer test:browser                          # 全 Browser テスト (Chromium → WebKit)
#   composer test:browser -- --filter=...          # pest 引数の追加 (両レーンへ渡る)
#   BROWSER_TEST_LANES=webkit composer test:browser # レーン限定

set -euo pipefail
cd "$(dirname "$0")/.."

PROCESSES="${BROWSER_TEST_PROCESSES:-1}"
LANES="${BROWSER_TEST_LANES:-chromium webkit}"

# --- bug-hunt 併走の pre-flight guard (best-effort。保証ではない) ---
#
# **ロック取得より前**に実行する。取得後に落とすと、先行レーンの終了を数分待ってから
# 「bug-hunt が走っているので実行できません」と言うことになり、待ち時間が無駄になる。
#
# bug-hunt は本ロック規約に参加しない (意図的に隔離された並列実行基盤で、
# global lock を被せると 8 並列が 1 直列に潰れる)。そのため bug-hunt の
# `playwright-cli kill-all` (@playwright/cli) が Browser lane の run-server を
# 巻き込む可能性を **こちらからは証明できない**。
#
# ここで行うのは「起動時点で bug-hunt が既に走っている」という頻度の高いケースだけを
# 捕まえる best-effort guard であり、**TOCTOU がある** (Browser lane 開始後に
# bug-hunt が起動する経路、bug-hunt が listen していない起動フェーズは捕まえられない)。
# 非干渉は保証しない — 失敗モードが偽赤であって偽グリーンではないため受容する。
#
# 検知は bash の /dev/tcp のみを使う (ss/lsof/netstat の可用性と出力形式に依存しない)。
# bug-hunt は 127.0.0.1:801N に明示 bind するので IPv4 loopback だけ見れば足りる。
# /dev/tcp が使えないシェルでは検査を skip して続行する (guard であって保証ではない)。
bughunt_port_in_use() {
    local port
    for port in {8010..8018}; do
        if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
            exec 3<&- 3>&- 2>/dev/null || true
            printf '%s\n' "${port}"
            return 0
        fi
    done
    return 1
}

if busy_port="$(bughunt_port_in_use)"; then
    echo "ERROR: bug-hunt 環境が走行中です (127.0.0.1:${busy_port} が listen 中)。" >&2
    echo "       bug-hunt の playwright-cli kill-all が Browser lane の run-server を" >&2
    echo "       巻き込む可能性があるため、bug-hunt の終了を待ってから実行してください" >&2
    echo "       (scripts/bug-hunt-shard.sh teardown / docs/testing-browser.md)。" >&2
    exit 1
fi

# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---
# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test:browser"

# orphan 化した playwright run-server (pest-plugin-browser 同梱 Playwright) を掃除する。
#
# **@playwright/cli は対象外にする**: bug-hunt が使うのは @playwright/cli であり、
# 別プロセス名前空間である。pgrep のパターンは既存のまま維持し (正のマッチを弱めない)、
# cmdline に "@playwright/" を含むプロセスを明示除外することで、こちらの掃除が
# bug-hunt のブラウザを巻き込む経路 (方向 1) を構造的に塞ぐ。
cleanup_orphan_playwright() {
    local pid ppid args
    for pid in $(pgrep -f "playwright/cli.js run-server" 2>/dev/null || true); do
        args="$(ps -o args= -p "${pid}" 2>/dev/null || true)"
        case "${args}" in
            *"@playwright/"*) continue ;;   # bug-hunt の @playwright/cli は触らない
        esac
        ppid="$(ps -o ppid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)"
        if [ "${ppid}" = "1" ]; then
            kill "${pid}" 2>/dev/null || true
        fi
    done
}

# 起動時の掃除は従来どおり。EXIT trap は **自前で張らず** ライブラリへ登録する。
#
# `trap cleanup_orphan_playwright EXIT` を自前で張ると、acquire 前なら acquire 側の
# `trap '_gtl_cleanup' EXIT` に上書きされ、acquire 後ならこちらが `_gtl_cleanup` を消して
# **ロックが永久に解放されなくなる**。EXIT trap の所有者はライブラリ 1 箇所に固定する。
# 登録したフックは **ロックを保持したまま** 実行される (次のレーンが入る前に掃除を終える)。
cleanup_orphan_playwright
global_test_lock_on_exit cleanup_orphan_playwright

global_test_lock_run php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証は tests/bootstrap.php の単一点ガードが担う (run-test.sh と同じ)。
global_test_lock_run php scripts/ci/ensure-test-db.php

# 既定 (PROCESSES=1) では pest の parallel runner を使わない。
# 1 プロセスは直列と等価である一方、`--parallel --processes=1` で Browser lane を
# 走らせると **全テスト成功でも終了コードが 1 になる** ケースを実測した
# (pest-plugin-browser のページ操作を含むテストで再現。--processes=2 や parallel なしでは
# 発生しない)。緑を赤と誤報告する = レーンの信頼性が失われるため、既定は直列にする。
# 並列数を明示指定 (BROWSER_TEST_PROCESSES>=2) したときのみ parallel runner を使う。
PEST_PARALLEL_ARGS=()
if [ "${PROCESSES}" -gt 1 ]; then
    PEST_PARALLEL_ARGS=(--parallel --processes="${PROCESSES}")
fi

# レーンは順に実行し、**どれかが失敗したら最後に非ゼロで終わる**
# (先頭レーンの失敗で後続レーンを飛ばすと WebKit の回帰を見落とすため)。
# ロックは acquire で 1 回取ったまま 2 レーンを通して保持される (run は取得しない)。
overall=0
for lane in ${LANES}; do
    case "${lane}" in
        chromium) browser="chrome" ;;
        webkit)   browser="safari" ;;   # pest-plugin-browser の safari = Playwright webkit
        *)
            echo "ERROR: unknown browser lane '${lane}' (chromium / webkit)" >&2
            exit 2
            ;;
    esac

    echo ""
    echo "=== Browser lane: ${lane} (playwright: ${browser}) ==="

    code=0
    global_test_lock_run vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" || code=$?
    if [ "${code}" -ne 0 ]; then
        overall="${code}"
    fi

    cleanup_orphan_playwright
done

exit "${overall}"

```
### `vitest.config.ts`

```
import { defineConfig } from "vitest/config";
import { svelte } from "@sveltejs/vite-plugin-svelte";
import { svelteTesting } from "@testing-library/svelte/vite";
import path from "path";

export default defineConfig({
    plugins: [
        svelte({
            hot: !process.env.VITEST,
            compilerOptions: {},
        }),
        svelteTesting(),
    ],
    test: {
        globals: true,
        environment: "jsdom",
        // CPU を食い尽くさないよう並列ワーカーをコア数の半分に抑える
        // (環境非依存: 10コア→5, 8コア→4 のように自動追従)
        maxWorkers: "50%",
        minWorkers: 1,
        setupFiles: ["./tests/js/setup.ts"],
        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
        coverage: {
            provider: "v8",
            reporter: ["text", "json", "html"],
            exclude: [
                "node_modules/",
                "tests/",
                "**/*.d.ts",
                "**/*.config.*",
                "**/mockData",
            ],
        },
    },
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "./resources/js"),
        },
    },
});

```
### `packages/cli/vitest.config.ts`

```
import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        include: ["tests/**/*.test.ts"],
        environment: "node",
        // 資格情報バックエンドをホスト非依存に固定する (setup の解説参照)。
        setupFiles: ["tests/setup/credential-backend.ts"],
        testTimeout: 15000,
    },
});

```
### `tsconfig.json`

```
{
    "extends": "@tsconfig/svelte/tsconfig.json",
    "compilerOptions": {
        "target": "ESNext",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "resolveJsonModule": true,
        "allowJs": true,
        "checkJs": false,
        "strict": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "forceConsistentCasingInFileNames": true,
        "isolatedModules": true,
        "paths": {
            "@/*": ["./resources/js/*"]
        },
        "types": ["node"]
    },
    "include": [
        "resources/js/**/*.ts",
        "resources/js/**/*.svelte",
        "tests/js/**/*.ts"
    ],
    "exclude": ["node_modules", "tmp"]
}

```
### `scripts/audit-gate.sh`

```
#!/usr/bin/env bash
# scripts/audit-gate.sh — supply-chain 依存脆弱性 gate のローカル実行ラッパ。
#
# composer / pnpm の audit を JSON で取得し、pyproject.toml があるリポジトリでは
# pip-audit も加えて scripts/audit-gate.ts に渡す。judging (severity 判定・
# accept-risk の expiry/cleanup・運用上限の機械強制) は audit-gate.ts に集約する。
#
# 終了コード: high+ 未受容 / expiry 切れ / cleanup 漏れ / 上限超過 のいずれかで非ゼロ。
# 使い方: `pnpm run audit:gate` または直接 `bash scripts/audit-gate.sh`。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

PNPM_JSON="$(mktemp)"
COMPOSER_JSON="$(mktemp)"
PIP_JSON=""
REQ_TXT=""
trap 'rm -f "$PNPM_JSON" "$COMPOSER_JSON" ${PIP_JSON:+"$PIP_JSON"} ${REQ_TXT:+"$REQ_TXT"}' EXIT

# audit 自体の exit code は無視 (脆弱性検出で非ゼロを返すため)。judging は audit-gate.ts。
# 取得失敗 (network 不通等) で空 JSON になった場合も gate は走る (advisory 0 件扱い)。
echo ">>> pnpm audit --json"
pnpm audit --json --audit-level=moderate > "$PNPM_JSON" 2>/dev/null || true
echo ">>> composer audit --format=json"
composer audit --format=json > "$COMPOSER_JSON" 2>/dev/null || true

# 空ファイル (audit が何も出力しなかった) は最小 JSON で補完し parse 失敗を防ぐ。
[[ -s "$PNPM_JSON" ]] || echo '{"advisories":{}}' > "$PNPM_JSON"
[[ -s "$COMPOSER_JSON" ]] || echo '{"advisories":{}}' > "$COMPOSER_JSON"

# PyPI 判定は pyproject.toml があるリポジトリでのみ有効化する (テンプレート初期状態では skip)。
if [[ -f pyproject.toml ]]; then
    echo ">>> pip-audit --format=json (pyproject.toml detected)"
    PIP_JSON="$(mktemp)"
    REQ_TXT="$(mktemp)"
    uv export --format=requirements-txt --no-hashes --no-dev > "$REQ_TXT" 2>/dev/null || true
    uv tool run --from "pip-audit==2.7.3" pip-audit --format=json --requirement "$REQ_TXT" > "$PIP_JSON" 2>/dev/null || true
    [[ -s "$PIP_JSON" ]] || echo '{"dependencies":[]}' > "$PIP_JSON"
fi

echo ">>> audit-gate judging"
pnpm exec tsx scripts/audit-gate.ts "$PNPM_JSON" "$COMPOSER_JSON" ${PIP_JSON:+"$PIP_JSON"}

```
### `phpunit.xml` (抜粋: <php> セクション)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Architecture">
            <directory>tests/Architecture</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <!--
          テストは本番同等の PostgreSQL で回す (sqlite/pgsql 二重運用なし)。

          <env> は $_ENV にしか書き込まないため、shell / docker-compose から
          DB_DATABASE 等が export されていると phpdotenv (immutable) が shell env を
          優先し、テストが dev DB へ接続して RefreshDatabase が dev データを wipe
          し得る。<server force="true"> は $_SERVER + putenv() まで上書きするため
          確実に TEST 値を強制できる。

          dev-DB 保護 (2 段):
            1. ここで APP_ENV / DB_CONNECTION 等の static 値を <server force> で強制
            2. tests/bootstrap.php が DB_DATABASE を `<slug>_test_<worktree-hash>` へ
               後勝ち上書きし、直後に assertPgsqlTestDatabaseSafe() で最終 DB 名を
               Laravel boot 前に fail-closed 検証する (単一点ガード)。
               DB_DATABASE はここでは force しない (静的記述で worktree hash を持てない)

          DB 接続先 (DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD) は
          .env.testing / shell env を継承する (ローカルは 127.0.0.1、devcontainer は
          docker-compose が export する db)。paratest 実行時は Laravel の
          ParallelTesting が base 名に `_test_{token}` を付与して更に分離する。
          base DB の作成は scripts/ci/ensure-test-db.php が test 前に担う。
        -->
        <server name="APP_ENV" value="testing" force="true"/>
        <server name="APP_MAINTENANCE_DRIVER" value="file" force="true"/>
        <server name="BCRYPT_ROUNDS" value="4" force="true"/>
        <server name="BROADCAST_CONNECTION" value="null" force="true"/>
        <server name="CACHE_STORE" value="array" force="true"/>
        <!-- DB_DATABASE は tests/bootstrap.php が worktree 固有の pgsql DB 名を注入する -->
        <server name="DB_CONNECTION" value="pgsql" force="true"/>
        <server name="DB_URL" value="" force="true"/>
        <server name="MAIL_MAILER" value="array" force="true"/>
        <server name="QUEUE_CONNECTION" value="sync" force="true"/>
        <server name="SESSION_DRIVER" value="array" force="true"/>
        <!--
          LLM provider API key はテスト中に実 API へ絶対に届かないようダミー値で上書きする。
          主防御は tests/Support/StrayLlmCallGuard (tests/Pest.php の beforeEach/afterEach で install)。
          本上書きは guard が万一無効化された場合の最終防壁で、config への到達は
          tests/Feature/Config/PrismApiKeyDummyTest が検証する。
          新 LLM provider 導入時はここにダミー値を追加すること (PrismApiKeyDummyTest にも assert を追加)。
          `force="true"` は $_SERVER + putenv() を上書きし .env 値より優先するため、
          「key 未設定 (null) 時の挙動」の検証は専用テストで Config::set(...) を使う。
        -->
        <server name="OPENAI_API_KEY" value="test-dummy-openai-key" force="true"/>
        <server name="ANTHROPIC_API_KEY" value="test-dummy-anthropic-key" force="true"/>
        <server name="GEMINI_API_KEY" value="test-dummy-gemini-key" force="true"/>
        <!-- 課金: テンプレート既定通貨 (jpy)。Stripe API はテストから呼ばない -->
        <server name="CASHIER_CURRENCY" value="jpy" force="true"/>
        <server name="STRIPE_KEY" value="" force="true"/>
        <server name="STRIPE_SECRET" value="" force="true"/>
        <server name="STRIPE_WEBHOOK_SECRET" value="" force="true"/>
        <server name="PULSE_ENABLED" value="false" force="true"/>
        <server name="TELESCOPE_ENABLED" value="false" force="true"/>
        <server name="NIGHTWATCH_ENABLED" value="false" force="true"/>
    </php>
</phpunit>

```
### `phpunit.browser.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
  Browser (pest-plugin-browser) 専用 PHPUnit 設定。

  Browser lane は本 config 経由でのみ動く (scripts/run-browser-test.sh が
  `vendor/bin/pest -c phpunit.browser.xml` で起動する)。既定 phpunit.xml の
  testsuite には tests/Browser を含めないため、composer test (Feature/Unit/
  Architecture lane) から Browser テストが誤起動することはない。

  DB は Feature lane と同じ worktree 固有 pgsql テスト DB を使う:
  bootstrap="tests/bootstrap.php" が DB_DATABASE を `<slug>_test_<worktree-hash>`
  に注入し、Laravel boot 前に fail-closed 検証する (dev-DB 保護は phpunit.xml と
  同一機構)。同じ base DB を共有するため、composer test との同時実行は
  scripts/run-browser-test.sh の flock (test.lock 共有) で排他する。

  <php> の値は phpunit.xml と揃える (乖離させない)。差分は memory_limit のみ
  (実ブラウザ + in-process サーバの分だけ余裕を持たせる)。
-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Browser">
            <directory>tests/Browser</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <ini name="memory_limit" value="1G"/>
        <server name="APP_ENV" value="testing" force="true"/>
        <server name="APP_MAINTENANCE_DRIVER" value="file" force="true"/>
        <server name="BCRYPT_ROUNDS" value="4" force="true"/>
        <server name="BROADCAST_CONNECTION" value="null" force="true"/>
        <server name="CACHE_STORE" value="array" force="true"/>
        <!-- DB_DATABASE は tests/bootstrap.php が worktree 固有の pgsql DB 名を注入する -->
        <server name="DB_CONNECTION" value="pgsql" force="true"/>
        <server name="DB_URL" value="" force="true"/>
        <server name="MAIL_MAILER" value="array" force="true"/>
        <!-- 同期実行で決定論性を確保 (ブラウザ操作の直後に job の副作用を assert できる) -->
        <server name="QUEUE_CONNECTION" value="sync" force="true"/>
        <server name="SESSION_DRIVER" value="array" force="true"/>
        <!--
          LLM 実通信遮断 (二層防御)。主防御は StrayLlmCallGuard + CannedPromptFake
          (tests/Pest.php の Browser lane)。新 LLM provider 導入時は phpunit.xml と
          あわせてここにもダミー値を追加すること。
        -->
        <server name="OPENAI_API_KEY" value="test-dummy-openai-key" force="true"/>
        <server name="ANTHROPIC_API_KEY" value="test-dummy-anthropic-key" force="true"/>
        <server name="GEMINI_API_KEY" value="test-dummy-gemini-key" force="true"/>
        <!-- 課金: テンプレート既定通貨 (jpy)。Stripe API はテストから呼ばない -->
        <server name="CASHIER_CURRENCY" value="jpy" force="true"/>
        <server name="STRIPE_KEY" value="" force="true"/>
        <server name="STRIPE_SECRET" value="" force="true"/>
        <server name="STRIPE_WEBHOOK_SECRET" value="" force="true"/>
        <server name="PULSE_ENABLED" value="false" force="true"/>
        <server name="TELESCOPE_ENABLED" value="false" force="true"/>
        <server name="NIGHTWATCH_ENABLED" value="false" force="true"/>
    </php>
</phpunit>

```
### `tests/Architecture/GlobalTestLockInventoryTest.php` (T099 の層 2 gate。**この契約を壊さないことが必須**)

```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: 全テストレーンがグローバルテストロックを経由すること。
 *
 * 背景 (SoT = devnotes/20260804-2319-global-test-lock/conceptual-design.md):
 * 複数 worktree の並行実装でテストレーンが同時に走ると、PostgreSQL サーバ・実ブラウザ・
 * CPU/メモリを奪い合い、Browser lane の machine-wide な playwright 掃除が他レーンの
 * run-server を巻き込む。旧実装は worktree-local な flock (cross-worktree 排他ゼロ) かつ
 * flock -n (待たずに即エラー) だったため、これを scripts/global-test-lock.sh へ一本化した。
 *
 * worktree-local flock を「残さず削除する」判断が安全なのは、公式 entrypoint を
 * **全て確実に包めている場合に限る**。よって本テストは deny-by-default の inventory とする:
 * composer.json / package.json の test 系スクリプトは、明示 exemption に無い限り
 * ロック経由でなければ fail する (新レーン追加時に落ちて気づける)。
 *
 * 並行挙動そのものは scripts/verify-global-test-lock.sh (層 1) が検証する。
 * **本テストから層 1 を実行してはならない**: 本テストは composer test の内側
 * = グローバルロック保持中に走るため、自分自身と競合する。
 */

/** watch / 対話用途のため意図的にラップしない script と、その理由。 */
const GLOBAL_TEST_LOCK_EXEMPT = [
    'test:ui' => 'vitest --ui (常駐 UI サーバ)。無期限にロックを保持するため対象外',
    'test:watch' => 'vitest --watch (常駐 watch)。同上',
];

/** ロック経由と認められる呼び出し先 (これ自身がライブラリを source していることも検査する)。 */
const GLOBAL_TEST_LOCK_LANE_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * 構造検査の対象スクリプト = lane スクリプト 3 本 + 汎用ラッパ。
 * ラッパを対象外にすると、将来 `exec "$@"` へ戻されても層 2 は
 * 「存在し実行可能」だけで通過してしまう (ロックが即解放される致命的回帰を見逃す)。
 * ライブラリ本体 (scripts/global-test-lock.sh) は対象外 —
 * trap / exec fd リダイレクトを**正当に持つ唯一のファイル**だから。
 */
const GLOBAL_TEST_LOCK_GUARDED_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
    'scripts/with-global-test-lock.sh',
];

/**
 * JSON の scripts セクションを「script 名 => コマンド文字列」へ正規化する (純関数)。
 * composer.json は配列形式を採るため、改行連結して 1 文字列にする。
 *
 * @return array<string, string>
 */
function globalTestLockScriptsFromJson(string $json): array
{
    /** @var mixed $decoded */
    $decoded = json_decode($json, true);
    if (! is_array($decoded)) {
        return [];
    }

    /** @var mixed $scripts */
    $scripts = $decoded['scripts'] ?? null;
    if (! is_array($scripts)) {
        return [];
    }

    $normalized = [];
    /** @var mixed $command */
    foreach ($scripts as $name => $command) {
        $lines = is_array($command) ? $command : [$command];
        /** @var array<array-key, mixed> $lines */
        $normalized[(string) $name] = implode("\n", array_map(
            static fn (mixed $line): string => is_scalar($line) ? (string) $line : '',
            $lines,
        ));
    }

    return $normalized;
}

/**
 * composer.json / package.json の test 系 script が全てロック経由かを検査する (純関数)。
 *
 * @param  array<string, string>  $scripts  script 名 => コマンド文字列 (配列形式は改行連結済み)
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneViolations(array $scripts): array
{
    $violations = [];

    foreach ($scripts as $name => $command) {
        if ($name !== 'test' && ! str_starts_with($name, 'test:')) {
            continue;
        }
        if (array_key_exists($name, GLOBAL_TEST_LOCK_EXEMPT)) {
            continue;
        }
        // 部分一致で通すと `with-global-test-lock.sh true && unlocked-test` のような
        // 「ラッパ名は含むが実体は無ロック」が素通りする。
        // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
        // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $command) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if (preg_match('/(&&|\|\||;|(?<!\|)\|(?!\|))/', $last) === 1) {
            $violations[] = "script '{$name}' がロック配下のコマンドをシェル演算子で連結している: {$last}";

            continue;
        }

        $entrypoints = array_merge(['scripts/with-global-test-lock.sh'], GLOBAL_TEST_LOCK_LANE_SCRIPTS);
        $viaEntrypoint = false;
        foreach ($entrypoints as $entrypoint) {
            if (preg_match('#^bash\s+'.preg_quote($entrypoint, '#').'(?:\s|$)#', $last) === 1) {
                $viaEntrypoint = true;
                break;
            }
        }
        if (! $viaEntrypoint) {
            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$last}";
        }
    }

    return $violations;
}

/**
 * shell ソースから **実行行だけ** を取り出す (純関数)。
 *
 * 全ての静的検査はこの結果を単一の解析入力として使う。変更後スクリプトは
 * 「旧 worktree-local な test.lock を廃止した」「flock -n をやめた」といった説明を
 * **コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる。
 *
 * 行頭 (空白を除く) が `#` の行だけを落とす。行末コメントの除去はしない —
 * `'#'` のような引用符内の `#` を壊してコードを誤って削るリスクの方が大きい。
 */
function globalTestLockCodeLines(string $source): string
{
    $lines = preg_split('/\R/', $source) ?: [];
    $code = array_filter(
        $lines,
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    );

    return implode("\n", $code);
}

/**
 * `CI` 環境変数の参照禁止を検査する対象 = ロック機構の全ファイル (ライブラリ本体を含む)。
 *
 * 「CI では素通り」の分岐は、**正しさが最も要求される場所に、ローカルでは一度も
 * 実行されないコードパス**を増やす。CI が検証しているものと開発者が走らせるものを
 * 同一に保つため、ロック機構は CI を特別扱いしない (概念設計 §CI の扱い)。
 */
const GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS = [
    'scripts/global-test-lock.sh',
    'scripts/with-global-test-lock.sh',
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * ロック機構が `CI` 環境変数を **参照していない** ことを検査する (純関数)。
 *
 * 契約は「分岐していないこと」ではなく「**参照していないこと**」= deny-by-default。
 * 分岐だけを狙うと `flag=$CI` → `if [ "$flag" ]` のような 2 段構えを取りこぼすし、
 * そもそもロック機構が CI を読む正当な用途が 1 つも無いため、参照自体を禁じる方が
 * 契約として単純である (安全側の偽陽性は許容する)。
 *
 * **保証範囲**: 検出するのは shell の **通常の直接参照** (変数展開 / `-v` / `printenv` /
 * `env | grep`)。`declare -p CI` や変数名を組み立てる間接参照まで意味論的に完全検出は
 * しない (それは静的検査の射程外)。回帰防止としてはこれで十分 —
 * CI バイパスを足す人が意図的に難読化して書く前提は取らない。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockCiReferenceViolations(string $path, string $source): array
{
    $code = globalTestLockCodeLines($source);

    // 参照の書き方は複数あるので、bash で実際に CI を読める形を網羅する。
    $patterns = [
        '/\$\{?CI\b/',                     // $CI / ${CI} / ${CI:-} / ${CI+x}
        '/(?:\[\[|\btest\b|\[)[^\n]*\s-v\s+["\']?CI["\']?/', // [[ -v CI ]] / test -v CI
        '/\bprintenv\b[^\n]*\bCI\b/',      // printenv CI
        '/\benv\b[^\n|]*\|[^\n]*\bCI\b/',  // env | grep CI
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return ["{$path} が CI 環境変数を参照している (CI を特別扱いしない = バイパス分岐を作らない)"];
        }
    }

    return [];
}

/**
 * lane スクリプト / ラッパ本体が契約を守っているかを検査する (純関数)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneScriptViolations(string $path, string $source): array
{
    $violations = [];
    $code = globalTestLockCodeLines($source);

    if (! str_contains($code, 'global-test-lock.sh')) {
        $violations[] = "{$path} が scripts/global-test-lock.sh を source していない";
    }
    // 旧 worktree-local ロックの残存 (後方互換の並走) を禁止する。
    if (str_contains($code, 'storage/framework/testing/test.lock')) {
        $violations[] = "{$path} に旧 worktree-local な test.lock が残っている";
    }
    if (preg_match('/app-vitest-/', $code) === 1) {
        $violations[] = "{$path} に旧 workspace-hash ロック (app-vitest-*) が残っている";
    }
    if (preg_match('/\bflock\s+-n\b/', $code) === 1) {
        $violations[] = "{$path} に flock -n (非ブロッキング取得) が残っている";
    }
    // 自己バイパスの禁止。
    if (preg_match('/GLOBAL_TEST_LOCK_DIR=/', $code) === 1) {
        $violations[] = "{$path} が GLOBAL_TEST_LOCK_DIR を設定している (自己バイパス禁止)";
    }
    // exec はロック fd を閉じてロックを即解放するため、ロック配下では使わない。
    // ただし `exec 3<>...` のような **fd リダイレクト形は正当** なので除外する
    // (run-browser-test.sh の /dev/tcp guard が使う)。
    if (preg_match('/^\s*exec\s+(?!\d*[<>])/m', $code) === 1) {
        $violations[] = "{$path} が exec を使っている (fd 7 が閉じてロックが即解放される)";
    }
    // EXIT trap の所有者はライブラリ 1 箇所。lane が自前で張ると _gtl_cleanup を
    // 上書きしてロックが解放されなくなる (逆順なら lane 側が消される)。
    // 後始末は global_test_lock_on_exit へ登録する。
    if (preg_match('/^\s*trap\b[^\n]*\bEXIT\b/m', $code) === 1) {
        $violations[] = "{$path} が自前で trap ... EXIT を張っている (global_test_lock_on_exit を使うこと)";
    }
    // ラッパ / lane は必ず acquire → run の順で公開 API を **実際に呼ぶ** こと。
    // str_contains ではコメント/文字列だけでも通ってしまうため、呼び出し形を正規表現で見る。
    $acquireAt = preg_match('/^\s*global_test_lock_acquire\b/m', $code, $mA, PREG_OFFSET_CAPTURE) === 1
        ? $mA[0][1]
        : null;
    $runAt = preg_match('/^\s*global_test_lock_run\b/m', $code, $mR, PREG_OFFSET_CAPTURE) === 1
        ? $mR[0][1]
        : null;

    if ($acquireAt === null) {
        $violations[] = "{$path} が global_test_lock_acquire を呼んでいない";
    }
    if ($runAt === null) {
        $violations[] = "{$path} が global_test_lock_run を呼んでいない";
    }
    if ($acquireAt !== null && $runAt !== null && $acquireAt > $runAt) {
        $violations[] = "{$path} が global_test_lock_run を acquire より前に呼んでいる";
    }

    return $violations;
}

test('scripts/global-test-lock.sh と with-global-test-lock.sh が存在し実行可能であること', function (): void {
    foreach (['scripts/global-test-lock.sh', 'scripts/with-global-test-lock.sh'] as $rel) {
        $path = base_path($rel);
        expect(file_exists($path))->toBeTrue("{$rel} が見つからない");
        expect(is_executable($path))->toBeTrue("{$rel} に実行権が無い");
    }
});

test('scripts/verify-global-test-lock.sh が存在し実行可能であること', function (): void {
    // 層 1 (並行挙動スイート) の存在だけを固定する。**実行はしない** —
    // 本テストはグローバルロック保持中に走るため、起動すると自己競合する。
    $path = base_path('scripts/verify-global-test-lock.sh');
    expect(file_exists($path))->toBeTrue('scripts/verify-global-test-lock.sh が見つからない');
    expect(is_executable($path))->toBeTrue('scripts/verify-global-test-lock.sh に実行権が無い');
});

test('composer.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
    $json = file_get_contents(base_path('composer.json'));
    expect($json)->toBeString();
    /** @var string $json */
    $scripts = globalTestLockScriptsFromJson($json);
    expect($scripts)->not->toBe([]);
    expect(array_key_exists('test', $scripts))->toBeTrue('composer.json に test script が無い');
    expect(globalTestLockLaneViolations($scripts))->toBe([]);
});

test('package.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
    $json = file_get_contents(base_path('package.json'));
    expect($json)->toBeString();
    /** @var string $json */
    $scripts = globalTestLockScriptsFromJson($json);
    expect($scripts)->not->toBe([]);
    expect(array_key_exists('test', $scripts))->toBeTrue('package.json に test script が無い');
    expect(globalTestLockLaneViolations($scripts))->toBe([]);
});

test('lane スクリプトとラッパが契約 (source / 旧ロック不在 / flock -n 不在 / exec 不在 / 自前 EXIT trap 不在 / acquire+run 使用) を守ること', function (): void {
    foreach (GLOBAL_TEST_LOCK_GUARDED_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockLaneScriptViolations($rel, $source))->toBe([]);
    }
});

test('ロック機構が CI 環境変数を参照しないこと (CI バイパス禁止)', function (): void {
    foreach (GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockCiReferenceViolations($rel, $source))->toBe([]);
    }
});

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 */
test('負のコントロール: 未ラップの新レーンを検出する', function (): void {
    $violations = globalTestLockLaneViolations(['test:e2e' => 'pnpm exec playwright test']);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test:e2e');
});

test('負のコントロール: ラッパ名を含むだけの偽装 (演算子連結) を検出する', function (): void {
    $violations = globalTestLockLaneViolations([
        'test:e2e' => 'bash scripts/with-global-test-lock.sh true && pnpm exec playwright test',
    ]);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('連結');
});

test('負のコントロール: 旧 worktree-local ロックへ戻した lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    LOCK_FILE="storage/framework/testing/test.lock"
    exec 9>"$LOCK_FILE"
    flock -n 9 || exit 1
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test.lock');
    expect(implode("\n", $violations))->toContain('flock -n');
});

test('負のコントロール: exec を復活させたラッパを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "$*"
    exec "$@"
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('exec');
});

test('負のコントロール: 自前 EXIT trap を張った lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "lane"
    trap cleanup_orphan_playwright EXIT
    global_test_lock_run vendor/bin/pest
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('trap');
});

test('負のコントロール: exec の fd リダイレクト形は違反にしない', function (): void {
    $ok = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    (exec 3<>"/dev/tcp/127.0.0.1/8010") 2>/dev/null || true
    global_test_lock_acquire "lane"
    global_test_lock_run vendor/bin/pest
    SH;
    expect(globalTestLockLaneScriptViolations('fixture.sh', $ok))->toBe([]);
});

test('負のコントロール: CI 環境変数の参照を書き方によらず検出する', function (): void {
    // 「${CI} だけ見る」実装だと素通りする形を含めて固定する (Codex impl-review Round 2 の指摘)。
    $broken = [
        'expansion' => '        if [ "${CI:-}" = "true" ]; then exec "$@"; fi',
        'bracket-v' => '        if [[ -v CI ]]; then return 0; fi',
        'test-v' => '        if test -v CI; then return 0; fi',
        'printenv' => '        if [ "$(printenv CI)" = "true" ]; then return 0; fi',
        'env-grep' => '        if env | grep -q "^CI="; then return 0; fi',
        'indirect' => '        flag=$CI',
    ];
    foreach ($broken as $label => $line) {
        $violations = globalTestLockCiReferenceViolations('fixture.sh', "#!/usr/bin/env bash\n{$line}\n");
        expect($violations)->not->toBe([], "CI 参照 ({$label}) を検出できていない");
        expect(implode("\n", $violations))->toContain('CI 環境変数を参照している');
    }

    // コメント内の説明は違反にしない (実装が方針を説明できないと困るため)。
    $ok = <<<'SH'
    #!/usr/bin/env bash
    # CI バイパス分岐は作らない (${CI} で素通りさせない / printenv CI も見ない)
    global_test_lock_acquire "lane"
    SH;
    expect(globalTestLockCiReferenceViolations('fixture.sh', $ok))->toBe([]);
});

test('負のコントロール: 自己バイパス (GLOBAL_TEST_LOCK_DIR 設定) と acquire/run の順序違反を検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    GLOBAL_TEST_LOCK_DIR=/tmp/bypass
    global_test_lock_run vendor/bin/pest
    global_test_lock_acquire "lane"
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect(implode("\n", $violations))->toContain('自己バイパス');
    expect(implode("\n", $violations))->toContain('acquire より前');
});

```
### `scripts/README.md` (台帳)

```markdown
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
| `ci/ensure-test-db.php` | pgsql テストの base DB を不在時のみ冪等 CREATE (dev-DB 保護の二重防御付き) | `run-test.sh` / CI から自動呼び出し |
| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip) | worktree teardown / CI cleanup |
| `setup-worktree.sh` | TODO 用 worktree (`.claude/worktrees/tasks/<task-id>` + `todo/<task-id>` ブランチ) を作成し、実行時ファイルのコピー・worktree-local な `composer install --no-scripts` / `pnpm install` (GVS 共有)・health check・テスト DB ensure まで機械的に実行 (AGENTS.md §worktree 運用ルール) | 実装開始時 (`app-implement` W-3 等) |
| `teardown-worktree.sh` | worktree の dirty チェック → テスト DB の best-effort 回収 → `git worktree remove --force`。ブランチの削除/マージは呼び出し側の責務 | 実装完了後 (`app-implement` C-4 等) |
| `ci/make-shard-phpunit.php` | GitHub Actions の matrix sharding 用に、担当テストファイルのみの phpunit 設定を生成 | CI から自動呼び出し |
| `ci/pgsql_test_conn.php` | ensure / drop が共有する pgsql 接続 resolver | (上記 2 スクリプトの内部 include) |
| `audit-gate.sh` | supply-chain 依存脆弱性 gate のローカル実行ラッパ。composer / pnpm(pyproject.toml があれば pip-audit も)の audit JSON を取得して `audit-gate.ts` に渡す | `pnpm run audit:gate` から自動呼び出し / 直接実行 |
| `audit-gate.ts` | audit JSON の統合判定 (high+ fail / moderate warn / `docs/supply-chain/accepted-advisories.yaml` の expiry・cleanup・severity 別上限を機械強制) | `audit-gate.sh` / CI から自動呼び出し |
| `audit-gate.test.ts` | `audit-gate.ts` の unit テスト (正規化・expiry 判定・accept-risk 照合) | `pnpm test` (vitest の include に `scripts/**/*.test.ts` が入っている) |
| `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
| `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
| `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
| `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する | `.claude/settings.json` の hook として配線 (`.claude/settings.bughunt-hook.example.json` をマージ) |
| `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 | (内部スクリプト) |
| `codex` | Codex CLI を VSCode 拡張のネイティブバイナリ経由で起動。`app-codex-review` / `app-codex-vscode` スキルの呼び出しラッパを兼ねる | スキルから自動呼び出し / 直接起動 |

```
### `pnpm-workspace.yaml`

```yaml
# pnpm workspace 設定 (Global Virtual Store + isolated linker)。

packages:
    - '.'
    - 'packages/*'

# ネイティブ build script の実行許可 (pnpm 11 の build-script gating)。
# Vite 8 が引き込む esbuild は postinstall で platform binary を配置するため、
# 明示許可しないと ERR_PNPM_IGNORED_BUILDS で install/build が落ちる。
allowBuilds:
    esbuild: true

# git worktree 運用での重複実体 (= TypeScript 型 identity 衝突) を防ぐため
# global virtual store を有効化する。main と全 worktree が同一 content-addressable
# store (<store-path>/links/) を共有することで realpath を収束させ、かつ worktree 内
# pnpm install/add の影響を自 worktree に局所化する (workspace を直接汚染しない)。
enableGlobalVirtualStore: true

# linker を default 任せにせず明示固定する (GVS は isolated 前提)。
# 将来の default 変更や `.npmrc` 復活時に hoisted 等へ無断切替されると
# enableGlobalVirtualStore の前提が崩れるため、明示固定で設定ドリフトを防ぐ。
nodeLinker: isolated

# workspace 内 package を registry より優先解決し silent misresolve を防ぐ safety net。
# packages/* と同名の package が npmjs.org にあった場合の誤解決を排除する。
linkWorkspacePackages: true

# === overrides: transitive 依存の既知脆弱性を patched 版へ強制するためのセクション ===
# 運用規約:
#   - pnpm audit (high/moderate) の解消のみに使う。機能目的の版固定はここに書かない
#   - 追加時は GHSA ID・脆弱レンジ・patch 選定理由 (major 据え置き等) をコメントで残す
#   - 複数 major が混在する package は脆弱な major のみ scoped に上げる (例: 'ws@8': ^8.21.0)
#   - 直接依存の bump で advisory が解消されたらエントリを削除する
overrides: {}

# === packageExtensions: 暗黙の peer dependency を明示宣言する補正 ===
# 背景: enableGlobalVirtualStore 有効時、ESM では NODE_PATH が効かないため
#       「peer として宣言していない依存を import するパッケージ」が global store から
#       host project の依存を解決できなくなる (pnpm Issue #11221、公式 doc caveat 明記)。
# 対応: 該当 package の peerDependencies を packageExtensions で補完し、pnpm が
#       global store 内に peer の symlink を配置するように仕向ける。
# 参考: https://pnpm.io/settings#enableglobalvirtualstore
#       https://github.com/pnpm/pnpm/issues/11221
packageExtensions:
    # @testing-library/jest-dom の vitest.js が peer 宣言なしで `import 'vitest'` する。
    # 宣言しないと global store 内 jest-dom から vitest を解決できず、tests/js/setup.ts の
    # matcher 拡張 (toBeInTheDocument / toHaveClass 等) が無効化される。
    '@testing-library/jest-dom':
        peerDependencies:
            vitest: '>=4.0.0'
    # vite-plugin-full-reload (laravel-vite-plugin の依存) は `import 'vite'` するが
    # peerDependencies を宣言していない。hoisted linker では vite がホイストされ偶然解決
    # できたが、isolated + GVS では暗黙依存が central store 上で解決できず
    # `vite build` が ERR_MODULE_NOT_FOUND で落ちる。peer を明示補完する。
    'vite-plugin-full-reload':
        peerDependencies:
            vite: '*'

```

