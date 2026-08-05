# Round 3: Round 2 指摘への対応

Round 2 の指摘 (Critical 2 / Warning 5) は **全件対応**しました。反論はありません。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 5)。
施策別: 1/2/3/4B/5/7/8/11 は APPROVE、4A/6/9/10 が REQUEST_CHANGES。
**全 7 件を対応した** (反論した指摘は無い)。

## [Critical] 施策 4A: `assertAuditSourceShape()` が Composer の `advisories: []` を受理する

- 判断: **対応する**
- 根拠: 完全に正しく、かつ致命的。設計コードは pnpm と composer を同じ条件
  (`typeof advisories === "object"`) で検査していたが、JS の `typeof [] === "object"` なので
  composer に `{"advisories":[]}` が来ると通過する。そして `normalizeComposerAudit` の
  `typeof obj.advisories !== "object"` も配列を弾かないため、`Object.entries([])` = `[]` で
  **advisory 0 件 = 緑**に落ちる。**塞いだつもりの穴を、塞いだコード自体が再び開けていた**。
  fail-closed 化の意味が失われるので最優先で修正。
- 対応内容: `switch (source)` で source ごとに期待コンテナ型を分けた。
  - `pnpm-audit`: object **または** array (normalizePnpmAudit が両対応しているため)
  - `composer-audit`: **array でない** object 固定 (`Array.isArray(c)` を明示的に弾く)
  - `pip-audit`: `dependencies` が array
  なぜ共通条件にしてはいけないかをコメントに残した (再発防止)。
  判定層テストに **`{"advisories":[]}` を composer として読むと throw する**ケースを追加し、
  同じ入力を pnpm として読むと throw しないことも併記した (source ごとの差が効いている証明)。

## [Critical] 施策 9: 再帰走査すると `scripts/README.md` 自身が未登録ファイルになる

- 判断: **対応する**
- 根拠: 正しい。Round 1 の Suggestion (再帰走査化) を採り入れた結果、
  **走査対象に台帳自身が入ってしまい、exemption を空にしたため初期状態から必ず赤になる**設計だった。
  Round 1 の対応が新しいバグを作った典型で、Codex が拾ってくれなければ実装初日に踏んでいた。
- 対応内容: Codex が「最も素直」とした案を採用し、`SCRIPTS_README_EXEMPT` に
  `'README.md' => '台帳ファイル自身 (表の正本であって、表に載る対象ではない)'` を登録した。
  拡張子で絞る案は採らない — 拡張子ホワイトリストは「新しい種類のスクリプトが黙って漏れる」
  という、まさに本 gate が防ぎたい失敗モードを持ち込むため。
  あわせてテスト計画に
  「実装直後に現状で緑になることを確認する」「exemption 定数が実在ファイルだけを指すことを検査する」
  (死んだ exemption の残置で除外が形骸化するのを防ぐ) を追加した。

## [Warning] 施策 4A: `STDERR_LOG` の生成と cleanup が設計コードに無い

- 判断: **対応する**
- 根拠: そのとおりで、`set -u` 配下なので最初の `acquire` で即死する。設計コードの記述漏れ。
- 対応内容: `STDERR_LOG="$(mktemp)"` と、既存 `trap ... EXIT` への `$STDERR_LOG` 追加を明記した。
  あわせて Codex の指摘どおり**取得ごとに truncate** する形 (`2>` であって `2>>` ではない) にし、
  「composer 失敗時に pnpm の古い stderr が混ざって原因が読めなくなる」ことを防いだ。
  取得成功時も stderr を診断用に流す 1 行を足した (警告を握り潰さない)。

## [Warning] 施策 4A: shape 検証の「関数単体」はテストされるが `loadAuditJson()` への配線が保証されない

- 判断: **対応する**
- 根拠: 鋭い。A3 (不正 JSON) はスタブ `pnpm exec tsx` が受け止めるため判定は実行されず、
  実装者が `assertAuditSourceShape` を **export しただけで呼び忘れても**全テストが緑になる。
  「関数はあるが配線されていない」= gate が存在するのに効いていない、という本バッチが
  一貫して潰そうとしている失敗モードそのもの。
- 対応内容: 判定層テストを**関数単体ではなく `loadAuditJson` 経由**に変更し、
  一時 JSON ファイルを書いて読ませる形にした。ケースは Codex の提案 4 種を含む 9 種:
  不正 JSON / `{"error":...}` / composer `{"advisories":[]}` / pnpm `{"advisories":[]}` /
  正常な空コンテナ (pnpm・composer) / top-level 配列 / pip 正常・異常 / source と normalizer の誤対応。

## [Warning] 施策 4A: pip 取得経路が contract test の対象外

- 判断: **対応する**
- 根拠: 「pip-audit も同じ `acquire` を通す」と新しい契約を宣言しながらテストしないのは
  契約として不完全。さらに Codex の指摘した「先行する `uv export` の空出力/失敗」は
  **より危険**で、requirements が空なら pip-audit は正常終了して「依存 0 件 = advisory 0 件」を返す
  = 有効な JSON なので shape 検証も通る。ここは shell 側で止めるしかない。
- 対応内容: `uv export` **も** `acquire` を通す形に変更後コードを書き直した。
  contract test に A7 (`uv export` 空出力) / A8 (`pip-audit` 空出力) / A9 (`pyproject.toml` なしで
  pip 経路を実行せず判定へ到達 = オプトイン条件を壊していないことの確認) を追加した。

## [Warning] 施策 6: 負のコントロールの文字列置換が成功した保証を追加すべき + 件数表記の不一致

- 判断: **対応する** (両方)
- 根拠: 「置換対象が将来変わると、改変されていないコピーを broken fixture として実行する」
  = 負のコントロールが黙って空振りする。負のコントロールの空振りは gate の空振りと同じ害がある
  (むしろ「守られている」という誤った安心を与える分だけ悪い)。件数不一致も設計文の欠陥。
- 対応内容: `mutate(source, from, to)` ヘルパを設計に追加し、
  (1) 置換対象がちょうど 1 箇所であること、(2) 置換後にソースが変化すること、
  (3) 置換後に期待トークンを含むこと、の 3 点を throw で強制する形にした。
  件数表記を「負のコントロール 7 本 (層 1 実走 3 + 層 2 静的 4) + 正のコントロール 1 本」に修正した。

## [Warning] 施策 10: W9 の保証範囲を明示する必要がある

- 判断: **対応する** (Codex が提示した 2 案のうち、**前者 (allowlist で経路自体を塞ぐ)** を採用)
- 根拠: Codex 自身が「前者の方が deny-by-default という設計方針と整合する」としており同意する。
  保証範囲を書くだけの案は「将来 composite action を挟むだけで W9 が空洞化する」ことを許す。
  静的検査の射程外を認めた上で**射程外の経路が生えること自体を止める**方が、
  本バッチが一貫して採っている deny-by-default に沿う。
- 対応内容: **W14 を追加**した。`browser-tests` job の `steps[*].uses` を
  setup action allowlist (`actions/checkout` / `shivammathur/setup-php` / `pnpm/action-setup` /
  `actions/setup-node`) に限定し、`composer test:browser` が `run` で直接実行されることを要求する。
  - allowlist の比較は `@version` を除いた action 名で行う (version 上げで偽赤にしない)
  - W14 は `browser-tests` job にのみ課す (骨抜きの標的は browser lane。全 job への過剰な制約は避ける)
  - W9 の保証範囲 (「`ci.yml` に直接記述された範囲」) と、W14 によって
    `browser-tests` ではその範囲が job 全体と一致することを設計に明記した
  - 負のコントロールに「allowlist 外の local composite action を混ぜる」
    「`composer test:browser` を composite action へ移す」の 2 種を追加 (計 7 本)


---

## 再レビュー依頼

上記の対応を踏まえ、修正後の詳細設計書を再レビューしてください。特に:

1. 施策 4A の `assertAuditSourceShape` が **source ごとに条件を分けた**形で、
   Round 2 [Critical] を本当に塞いでいるか。他に「valid JSON だが 0 件に落ちる」入力が残っていないか
2. `uv export` を `acquire` に通す変更が、pip 経路のオプトイン条件 (pyproject.toml の有無) を
   壊していないか。A7〜A9 のシナリオに抜けはないか
3. 施策 9 の `README.md` exemption + 「exemption が実在ファイルだけを指すことの検査」で
   初期赤が解消され、かつ除外の形骸化も防げているか
4. 施策 10 の W14 (uses allowlist + composer test:browser の直接 run) が W9 の空洞化を防いでいるか。
   allowlist の粒度 (4 action) は過小/過大でないか
5. 施策 6 の `mutate()` ヘルパで負のコントロールの空振りが防げているか
6. Round 2 で APPROVE した施策 (1/2/3/4B/5/7/8/11) が今回の修正で壊れていないか

残る Critical / Warning が無ければ **APPROVED** と明示してください。

---

## 修正後の詳細設計書 (全文)

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
| 4A | `audit-gate.sh` の fail-closed 化 (取得失敗を advisory 0 件扱いにしない) | `scripts/audit-gate.sh` / `scripts/audit-gate.ts` / `scripts/audit-gate.contract.test.ts` | 高 |
| 4B | 未受容 high advisory の解消 | `pnpm-workspace.yaml` / `package.json` / `packages/cli/package.json` / `composer.json` / lockfiles / `docs/supply-chain/accepted-advisories.yaml` | 高 |
| 5 | `supply-chain-audit` job 新設 + nightly + 運用責任の明文化 | `.github/workflows/ci.yml` / `docs/supply-chain/review-checklist.md` | 高 |
| 6 | `scripts/run-browser-test.contract.test.ts` 新設 | `scripts/run-browser-test.contract.test.ts` | 高 |
| 7 | `PhpunitBrowserConfigParityTest` 新設 | `tests/Architecture/PhpunitBrowserConfigParityTest.php` | 中 |
| 8 | vitest include の SoT 化 + inventory gate | `scripts/test-inventory-config.ts` / `vitest.config.ts` / `packages/cli/vitest.config.ts` / `scripts/vitest-inventory-gate.test.ts` / `tsconfig.json` | 高 |
| 9 | `make-shard-phpunit.php` 削除 + 台帳ドリフト解消 + 再発防止 gate | `scripts/ci/make-shard-phpunit.php` (削除) / `scripts/README.md` / `.gitignore` / `tests/Architecture/ScriptsReadmeInventoryTest.php` | 中 |
| 10 | CI workflow inventory gate | `tests/js/architecture/ci-workflow-inventory.test.ts` | 高 |
| 11 | ドキュメント追従 | `docs/testing-browser.md` / `AGENTS.md` | 低 |

**実装順序 (依存関係)**: 3 → 1 → 2 → 6 → 7 → 8 → 9 → **4A → 4B** → 5 → 10 → 11
（10 は全 job が揃ってから書く。**4A (gate を fail-closed にする) → 4B (advisory 解消) → 5 (job 配線)** の
順序は非交渉 — 取得失敗を緑にする gate を blocking へ昇格させると「blocking なのに取得失敗時は緑」に
なるため、fail-closed 化を advisory 解消より先に行う。1/2 は 10 の前提）

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

## 施策 4A: `audit-gate.sh` の fail-closed 化

> design-review Round 1 [Critical] 反映。
> **gate を blocking へ昇格させる以上、取得失敗を「advisory 0 件」として緑にする経路は放置できない。**

### 変更箇所

- ファイル: `scripts/audit-gate.sh` (取得部の全面)
- ファイル: `scripts/audit-gate.ts` (shape 検証関数の追加)
- ファイル: `scripts/audit-gate.contract.test.ts` (新規)

### 波及変更

- TypeScript 型定義: `assertAuditSourceShape` の追加 (`scripts/audit-gate.ts` の export)
- API Resource/DTO: なし
- テストファイル: `scripts/audit-gate.test.ts` に shape 検証の unit テストを追加。
  `scripts/audit-gate.contract.test.ts` を新規追加 (shell の取得契約)
- ドキュメント: `scripts/README.md` に `audit-gate.contract.test.ts` の行を追加 (施策 9 の台帳規約)

### 現行コードと穴

```bash
# scripts/audit-gate.sh (現行)
# audit 自体の exit code は無視 (脆弱性検出で非ゼロを返すため)。judging は audit-gate.ts。
# 取得失敗 (network 不通等) で空 JSON になった場合も gate は走る (advisory 0 件扱い)。
pnpm audit --json --audit-level=moderate > "$PNPM_JSON" 2>/dev/null || true
composer audit --format=json > "$COMPOSER_JSON" 2>/dev/null || true

# 空ファイル (audit が何も出力しなかった) は最小 JSON で補完し parse 失敗を防ぐ。
[[ -s "$PNPM_JSON" ]] || echo '{"advisories":{}}' > "$PNPM_JSON"
[[ -s "$COMPOSER_JSON" ]] || echo '{"advisories":{}}' > "$COMPOSER_JSON"
```

穴は 2 段ある:

1. **shell 層**: 取得が失敗して空出力になったとき、`{"advisories":{}}` を**捏造して**判定へ渡す。
   `2>/dev/null` でエラーメッセージも消える。→ 「blocking job だが network 不通なら緑」
2. **判定層**: `normalizePnpmAudit` / `normalizeComposerAudit` は
   `if (!obj.advisories) return [];` と書かれており、**valid だが期待 schema でない JSON**
   (例: `{"error":{"code":"ENETUNREACH"}}`) を silent に 0 件へ落とす。

`loadAuditJson` は既に**不正 JSON では throw** し、`main()` が `exit 1` する。
つまり穴は「空出力の捏造」と「shape 不一致の黙殺」の 2 つに限定できる。

### 変更後コード

#### (a) `scripts/audit-gate.sh` — 取得と取得失敗の分離

```bash
# 取得失敗の原因を残すための stderr ログ。set -u で未定義参照にならないよう
# **acquire を呼ぶ前に**生成し、既存の trap に cleanup を足す (design-review R2 [Warning])。
STDERR_LOG="$(mktemp)"
trap 'rm -f "$PNPM_JSON" "$COMPOSER_JSON" "$STDERR_LOG" ${PIP_JSON:+"$PIP_JSON"} ${REQ_TXT:+"$REQ_TXT"}' EXIT

# audit ツールの非ゼロ終了には 2 つの意味がある:
#   (i)  脆弱性を検出した      → **正常**。有効な JSON が出ているので judging へ進む
#   (ii) 取得自体に失敗した    → **異常**。ここで fail-closed に止める
# 両者は exit code では区別できないため、**出力が有効な JSON であるか**で区別する。
# 空出力を最小 JSON で捏造して先へ進める旧実装は「blocking gate なのに network 不通なら緑」
# という偽グリーンだったため廃止した (後方互換の並走を残さない)。
acquire() {
    local label="$1" out="$2"; shift 2
    echo ">>> ${label}"
    # stderr は捨てない (取得失敗の原因をログに残す)。exit code は保存だけして判定に使わない。
    # ログは **取得ごとに truncate** する (> であって >> ではない)。追記にすると
    # composer 失敗時に pnpm の古い stderr が混ざって原因が読めなくなる (design-review R2)。
    local code=0
    "$@" > "${out}" 2>"${STDERR_LOG}" || code=$?
    if [[ ! -s "${out}" ]]; then
        echo "::error::audit-gate: ${label} produced no output (exit ${code}). refusing to treat this as 'no advisories'." >&2
        sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
        exit 1
    fi
    # 取得は成功した。stderr は診断用に流しておく (警告等)。
    [[ -s "${STDERR_LOG}" ]] && sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
}

acquire "pnpm audit --json"            "$PNPM_JSON"     pnpm audit --json --audit-level=moderate
acquire "composer audit --format=json" "$COMPOSER_JSON" composer audit --format=json

# pip-audit 経路 (pyproject.toml があるリポジトリのみ)。**uv export も acquire を通す** —
# requirements が空のまま pip-audit を回すと「依存 0 件 = advisory 0 件」で緑になるため
# (design-review R2 [Warning])。
if [[ -f pyproject.toml ]]; then
    PIP_JSON="$(mktemp)"; REQ_TXT="$(mktemp)"
    acquire "uv export (requirements)" "$REQ_TXT"  uv export --format=requirements-txt --no-hashes --no-dev
    acquire "pip-audit --format=json"  "$PIP_JSON" uv tool run --from "pip-audit==2.7.3" pip-audit --format=json --requirement "$REQ_TXT"
fi
```

- 空出力の捏造 (`[[ -s ... ]] || echo '{...}'`) は**削除**する。
- JSON としての妥当性と schema 検証は判定層 (`audit-gate.ts`) に一本化する
  (bash で JSON を検証しない = 判定ロジックの二重管理を作らない)。
- 責務境界: **shell = 「有効な出力が得られたか」だけを見る / TypeScript = JSON 妥当性と schema**。
- pip-audit と **その前段の `uv export`** も同じ `acquire` を通す
  (`pyproject.toml` がある場合のみ実行という条件は現状維持)。

#### (b) `scripts/audit-gate.ts` — shape 検証の追加

```ts
/** audit 入力 1 件分の由来。エラーメッセージと shape 期待値を決める。 */
export type AuditSource = "pnpm-audit" | "composer-audit" | "pip-audit";

/**
 * audit JSON が **その ecosystem の期待 schema を持つ**ことを検証する (純関数)。
 *
 * 目的は「valid JSON だが中身が違う」を 0 件へ黙って落とさないこと。
 * 例: ネットワークエラーで `{"error":{...}}` が返ると、normalizer は
 * `if (!obj.advisories) return []` により **advisory 0 件 = 緑** に落ちる。
 * blocking gate ではこれが偽グリーンになるため、ここで fail-closed に止める。
 *
 * 検証するのは **top-level コンテナの存在と型だけ**。中身の各 advisory の形は
 * normalizer が緩く扱う (未知フィールドで落とさない) 方針を維持する。
 *
 * @throws Error 期待 schema を満たさない場合
 */
export function assertAuditSourceShape(source: AuditSource, json: unknown): void {
    if (!json || typeof json !== "object" || Array.isArray(json)) {
        throw new Error(`${source}: expected a JSON object at top level`);
    }
    const obj = json as Record<string, unknown>;
    const keys = Object.keys(obj).join(", ");

    // **source ごとに期待コンテナの型を変える** (design-review R2 [Critical])。
    // 共通条件 (`typeof === "object"`) にすると composer で `{"advisories": []}` が通り、
    // normalizeComposerAudit の `typeof obj.advisories !== "object"` も配列を弾かないため
    // Object.entries([]) = [] で **advisory 0 件 = 緑** に落ちる (偽グリーン)。
    switch (source) {
        case "pnpm-audit": {
            // pnpm/npm audit は形式によって object (キー = advisory id) と array の両方を返す。
            // normalizePnpmAudit が両対応しているので、ここも両方を受理する。
            const c = obj.advisories;
            if (c === undefined || c === null || typeof c !== "object") {
                throw new Error(`pnpm-audit: missing 'advisories' object or array (got keys: ${keys})`);
            }
            return;
        }
        case "composer-audit": {
            // composer audit は `{"advisories": {"<package>": [...]}}` の **object 固定**。
            // 配列で来たら schema 不一致 = 取得異常として fail-closed に倒す。
            const c = obj.advisories;
            if (c === undefined || c === null || typeof c !== "object" || Array.isArray(c)) {
                throw new Error(`composer-audit: missing 'advisories' object (got keys: ${keys})`);
            }
            return;
        }
        case "pip-audit": {
            if (!Array.isArray(obj.dependencies)) {
                throw new Error(`pip-audit: missing 'dependencies' array (got keys: ${keys})`);
            }
            return;
        }
    }
}
```

`loadAuditJson` のシグネチャに `source` を追加し、`JSON.parse` の直後に
`assertAuditSourceShape(source, json)` を呼ぶ。`main()` は既に load 失敗で `exit 1` するので、
呼び出し側の変更は引数追加のみ。

> **「advisory 0 件」と「取得失敗」の区別**: `{"advisories":{}}` を**ツールが実際に出力した**
> ケースは正当な 0 件であり、shape 検証を通過して緑になる。捏造をやめたことで、
> 「本当に 0 件」と「取れなかった」が初めて区別できるようになる。

### `scripts/audit-gate.contract.test.ts` (新規)

施策 6 と同じ sandbox 方式で、**shell の取得契約**を実プロセスで検証する。

sandbox 構成:

```
$SANDBOX/
  scripts/audit-gate.sh   ← 実ファイルを verbatim コピー
  bin/pnpm                ← スタブ。引数で分岐する:
                             `pnpm audit ...`     → シナリオごとの出力 + exit code
                             `pnpm exec tsx ...`  → 呼ばれた事実を $SANDBOX/judged に記録して exit 0
  bin/composer            ← スタブ (同上の audit 部分のみ)
```

`PATH="$SANDBOX/bin:$PATH"` で実ツールを差し替える。判定 (`audit-gate.ts`) は
スタブが受け止めるので、本テストが検証するのは **「判定に到達したか / 手前で止まったか」** に限定される
(判定ロジック自体は `scripts/audit-gate.test.ts` の unit テストの責務。責務を混ぜない)。

| シナリオ | 期待 |
|---|---|
| A1: 空出力 + exit 0 | script が**非ゼロ終了**し、`judged` が**作られない** |
| A2: 空出力 + exit 1 (ネットワーク失敗の模擬) | 同上 |
| A3: 不正 JSON (`not json`) + exit 0 | 出力は非空なので shell は通過し、**判定へ到達する** (`judged` が作られる)。JSON parse 失敗は判定層の責務 |
| A4: 有効 JSON + **非ゼロ** exit (= 脆弱性検出の正常系) | **判定へ到達する** (`judged` が作られる)。ここを止めると通常運用が壊れる |
| A5: 有効な 0 件 JSON (`{"advisories":{}}`) + exit 0 | 判定へ到達する (本当に 0 件なので緑になってよい) |
| A6: composer 側だけ空出力 | 非ゼロ終了 + `judged` 無し (片側の失敗でも止まる) |
| A7: `pyproject.toml` あり + `uv export` が空出力 | 非ゼロ終了 + `judged` 無し (requirements が空のまま pip-audit を回すと「依存 0 件 = advisory 0 件」で緑になるため) |
| A8: `pyproject.toml` あり + `pip-audit` が空出力 | 非ゼロ終了 + `judged` 無し |
| A9: `pyproject.toml` **なし** | pip 経路を実行せず判定へ到達 (既存のオプトイン条件を壊していないことの確認) |

#### 判定層の unit テスト (`scripts/audit-gate.test.ts` へ追加)

**shape 検証関数を単体テストするだけでは配線を保証できない** (design-review R2 [Warning]) —
実装者が `assertAuditSourceShape` を export しただけで `loadAuditJson` から呼び忘れても
contract test (A3) はスタブ判定で素通りする。そこで **`loadAuditJson` 経由**でテストする:

```ts
// 一時ファイルへ JSON を書いて loadAuditJson を呼ぶ (配線まで含めて検証する)
```

| 入力 (source) | 期待 |
|---|---|
| 不正 JSON (`not json`) | throw (`JSON parse failure in ...`) |
| `{"error":{"code":"ENETUNREACH"}}` (pnpm) | **throw** — shape 黙殺の穴が塞がった証明 |
| `{"advisories":[]}` (**composer**) | **throw** — Round 2 [Critical] の穴が塞がった証明 |
| `{"advisories":[]}` (pnpm) | throw しない (pnpm は array 形も正当) |
| `{"advisories":{}}` (pnpm / composer) | throw しない (**真の 0 件は緑**) |
| `[]` (top-level 配列, 任意 source) | throw |
| `{"dependencies":[]}` (pip) | throw しない |
| `{}` (pip) | throw |
| source と normalizer の誤対応 (composer の JSON を `"pnpm-audit"` として読む等) | shape が両立しない組み合わせで throw することを確認 |

### PHPStan 適合チェック

- [x] PHP コード変更なし（該当なし）

### テスト計画

- [x] 新規テスト: `scripts/audit-gate.contract.test.ts` — A1〜A9 (shell の取得契約)
- [x] 既存テストの更新: `scripts/audit-gate.test.ts` に **`loadAuditJson` 経由**の
      shape 検証テスト 9 本を追加 (関数単体ではなく配線まで検証する)。既存テストの削除・上書きはしない
- [x] 負のコントロール: A4/A5/A9 が「止まらないこと」を確認する = fail-closed 化が
      **正常系まで巻き込んでいない**ことの証明 (過剰な fail-closed は運用不能になる)
- [x] 実装後 `pnpm run audit:gate` が (advisory がある状態でも) 判定まで到達し、
      施策 4B 完了後に exit 0 になること
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 対応 |
|---|---|
| fail-closed 化で「一時的なレジストリ障害」でも CI が赤になる | **意図した挙動**。「取れなかった」を「安全だった」と読み替えないのが gate の役割。再実行 (re-run job) で回復する一過性の赤は受容する |
| `2>/dev/null` をやめたことで audit の警告がログを汚す | stderr をログファイルへ落とし、**失敗時のみ**本文へ吐く設計にした (正常時は静か) |
| pnpm/composer の出力 schema が将来変わる | `assertAuditSourceShape` が即座に fail する = 黙って 0 件になるより望ましい。検証は top-level コンテナのみに絞り、過剰結合を避ける |
| 判定層の責務が shell に漏れる | shell は「非空かどうか」だけを見る。JSON 妥当性と schema は `audit-gate.ts` に一本化した |

---

## 施策 4B: 未受容 high advisory の解消

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

0. **施策 4A を先に完了させる**。fail-closed 化前の gate で「exit 0」を確認しても、
   それが「本当に 0 件」なのか「取得できなかった」なのか区別がつかない。
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

- [x] 施策 4A (fail-closed 化) → 4B (advisory 解消) の順に完了させ、ローカルで
      `pnpm run audit:gate` が exit 0 であることを確認してから本 job を配線する
      (main を赤で着地させない。かつ「取得できていないのに緑」でないことを 4A が保証している)
- [x] 施策 10 が固定する: `supply-chain-audit` job が存在する / `pnpm run audit:gate` を呼ぶ /
      `continue-on-error` を持たない / `on.schedule` が存在する
- [x] 既存テストの更新: なし
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 対応 |
|---|---|
| 上流の新 advisory で全 PR が赤くなる | **意図した挙動**。nightly で先出しし、逃げ道は期限付き accept-risk 1 本に統一。§6 の SLA で初動を決めた |
| nightly が誰にも見られない | GitHub Actions の失敗通知が owner に届く。通知経路を増やすのは今回のスコープ外 |
| `pnpm audit` / `composer audit` のネットワーク失敗で advisory 0 件扱いになる | **施策 4A で解消済み** (design-review R1 [Critical])。取得失敗は fail-closed で止まり、shape 不一致も `assertAuditSourceShape` が捕まえる |

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

**検出器と同じ層で確認する** (design-review R1 [Warning] 反映)。
静的検査項目に C2 の検出器は無いので、C2 の負のコントロールは**実走側**に置く。

層 1 (実走) の負のコントロール — sandbox 内のスクリプトを**改変したコピー**で走らせる:

| 改変 | 期待 |
|---|---|
| レーンループ内で失敗時に `break` するよう改変 | `pest-calls.jsonl` が **1 行しか出ない** ことをテストが検出し fail 扱いにする (= C2 検査が空振りしていない証明) |
| 失敗レーンがあっても最後に `exit 0` するよう改変 | スクリプトの exit code が 0 になることを検出 (= C3 検査が空振りしていない証明) |
| 既定を `PROCESSES="${BROWSER_TEST_PROCESSES:-2}"` に改変 | `--parallel` が現れることを検出 (= C4 検査が空振りしていない証明) |

> 改変コピーは `mkdtemp` 内の文字列置換で作る。**実ファイル (`scripts/run-browser-test.sh`) は
> 一切書き換えない**。層 1 の正の検査は verbatim コピーに対して行う。

**置換が成立したことを必ず assert する** (design-review R2 [Warning])。
置換対象の文言が将来変わると、**改変されていないコピーを「broken fixture」として実行し、
負のコントロールが黙って空振りする**。共通ヘルパで 3 点を機械的に確認する:

```ts
/** 元ソースの `from` を `to` に 1 箇所だけ置換する。置換が成立しなければ throw (空振り防止)。 */
function mutate(source: string, from: string, to: string): string {
    const occurrences = source.split(from).length - 1;
    if (occurrences !== 1) {
        throw new Error(`mutation target must appear exactly once (found ${occurrences}): ${from}`);
    }
    const mutated = source.replace(from, to);
    if (mutated === source) throw new Error(`mutation did not change the source: ${from}`);
    if (!mutated.includes(to)) throw new Error(`mutated source lacks the expected token: ${to}`);
    return mutated;
}
```

層 2 (静的) の負のコントロール — fixture 文字列を検査関数へ渡す:

| fixture | 期待 |
|---|---|
| `@playwright/` 除外を削った source | C6 が違反を返す |
| `global_test_lock_on_exit` を自前 `trap ... EXIT` に戻した source | C7 が違反を返す |
| `pgrep` の掃除ブロックごと削った source | C5 が違反を返す |
| 既定を `BROWSER_TEST_LANES:-chromium` に狭めた source | 既定値検査が違反を返す |
| 現行の実ソース | 違反 0 件 (正のコントロール) |

### テスト計画

- [x] 新規テスト: `scripts/run-browser-test.contract.test.ts` — 上記 C1〜C8 +
      **負のコントロール 7 本** (層 1 実走 3 本 + 層 2 静的 4 本) + 正のコントロール 1 本
      (現行実ソースで静的違反 0 件)
- [x] `mutate()` ヘルパが「置換対象が 1 箇所だけ存在し、置換後に変化し、期待トークンを含む」を
      検査すること (負のコントロールが空振りしないことの保証)
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
 *   1. spawn は **module top-level と `describe` callback の中では絶対に行わない**。
 *      許されるのは「通常実行時にだけ走る callback」= `it` / `beforeAll` / `beforeEach` の
 *      内側だけである。理由: 本ファイル自身が root project の include に入るため、
 *      `vitest list` は本ファイルを **import して `describe` を評価する** (収集フェーズ)。
 *      収集フェーズで評価される場所に spawn を置くと無限再帰する。
 *      逆に `it`/hook の callback は収集フェーズでは **登録されるだけで実行されない**ため、
 *      `beforeAll` に置いても再帰しない。
 *      helper 関数も「呼ばれたときに spawn する」形にし、module 初期化時に spawn しない。
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
  (`beforeAll` の callback は収集フェーズでは実行されないため制約 1 を満たす)。
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
| `audit-gate.contract.test.ts` | `audit-gate.sh` の取得契約テスト (空出力 / 取得失敗を advisory 0 件にせず fail-closed で止める。有効 JSON + 非ゼロ exit = 脆弱性検出の正常系は判定へ通す) | `pnpm test` |
```

あわせて既存 `audit-gate.sh` 行の説明を fail-closed 化に追従させる
(「取得失敗時は空 JSON で続行」という旧挙動の記述が残らないようにする)。

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

- 走査対象: **`scripts/` 配下を再帰的に走査**する (design-review R1 [Suggestion] 反映)。
  `scripts/*` + `scripts/ci/*` の 2 階層だけを見る実装は、将来 `scripts/foo/bar.sh` が
  増えたときに黙って漏れる = deny-by-default を名乗れない。
- 除外は**明示定数**として持ち、各項目に理由コメントを付ける。
  **`README.md` 自身は必ず除外する** — 台帳そのものが台帳に自分を登録するのは同語反復であり、
  除外しないと本テストは**初期状態から赤**になる (design-review R2 [Critical]):
  ```php
  /**
   * 台帳登録の対象外 (`scripts/` からの相対パス)。理由を書けないものをここに足さないこと。
   */
  const SCRIPTS_README_EXEMPT = [
      // 台帳そのもの。自分を自分の表へ登録するのは同語反復なので対象外にする。
      'README.md' => '台帳ファイル自身 (表の正本であって、表に載る対象ではない)',
  ];
  ```
- README のパス表記は `` `run-test.sh` `` / `` `ci/ensure-test-db.php` `` の形式なので、
  `scripts/` からの相対パスとして正規化して突き合わせる (再帰走査側も同じ正規化を通す)。
- **明示 exemption は初期値ゼロ** (deny-by-default)。除外したいファイルが出たら、
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
- [x] **実装直後に「現状の `scripts/` と `scripts/README.md` で緑になる」ことを確認する**。
      再帰走査 + exemption の設計は `README.md` 除外を忘れると初期状態から赤になる (R2 [Critical])。
      exemption 定数が「実在するファイルだけ」を指していることも検査する
      (死んだ exemption の残置を許すと除外が形骸化するため)
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
| W9 | **`BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` が workflow 全体のどこにも現れない — キー名としても、あらゆる scalar 値の中身としても** (T082 の 2 レーン契約と既定直列の workflow 側固定) |
| W10 | `frontend` が `pnpm test` / `pnpm test:packages` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm build` / `pnpm lint` / `pnpm typecheck` をすべて実行する |
| W11 | `supply-chain-audit` が `pnpm run audit:gate` を実行する |
| W12 | `on.schedule` が存在する (nightly) |
| W13 | **workflow 全体 (job / step のどのレベル) にも `continue-on-error` が現れない** — 判断 B の「soft-fail を採らない」の機械強制 |

W9 / W13 は「値が正しいこと」ではなく「**現れないこと**」を検査する。
文字列 grep ではコメント内の言及で偽赤になるため、**YAML を parse した後の構造を歩く**
(コメントは parse 時に落ちるので、`BROWSER_TEST_LANES` を**コメントで説明する**ことは許される。
施策 2 の workflow にその説明コメントを置いている)。

**ただし W9 と W13 で走査対象が異なる** (design-review R1 [Critical] 反映):

| 検査 | 走査対象 | 理由 |
|---|---|---|
| W13 (`continue-on-error`) | **キー名のみ** | GitHub Actions の予約キーであり、キーとしてしか意味を持たない |
| W9 (`BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES`) | **キー名 + 全 scalar 値の中身** | env 変数は `run` 文字列の中でも設定できるため、キー走査だけでは以下が素通りする |

```yaml
# キー走査だけでは検出できない骨抜きの例 (どちらも W9 で検出する)
run: BROWSER_TEST_LANES=chromium composer test:browser
run: |
  export BROWSER_TEST_PROCESSES=4
  composer test:browser
```

そのため `findKeyPaths` に加えて `findScalarValuePathsContaining(node, needle)` を実装し、
W9 は**両方の結果の和**が空であることを要求する。

#### W9 の保証範囲と、それを実効化する W14 (design-review R2 [Warning])

静的な scalar 検査が保証できるのは「**`ci.yml` に直接記述された**キー・値」までである。
次の経路は静的検査の射程外:

- local composite action が `$GITHUB_ENV` へ書く
- `run` から呼んだスクリプトが環境変数を設定する
- reusable workflow (`uses: ./.github/workflows/x.yml`) 内で設定する
- marketplace action が内部で設定する

現在の workflow は `composer test:browser` を直接 `run` するのでこれらの経路は存在しないが、
「保証範囲を書いて終わり」にすると **将来 composite action を挟むだけで W9 が空洞化する**。
deny-by-default の設計方針に揃えるため、**射程外の経路そのものを塞ぐ W14 を追加する**:

| # | 検査 |
|---|---|
| W14 | `browser-tests` job の `steps[*].uses` が **既知の setup action allowlist** (`actions/checkout` / `shivammathur/setup-php` / `pnpm/action-setup` / `actions/setup-node`) に限定され、かつ `composer test:browser` が **`run` で直接**実行されている (composite action / reusable workflow / ローカル action 経由でない) |

allowlist は version 指定 (`@v4` 等) を除いた action 名で比較する
(version 上げで偽赤にしない)。allowlist に無い action を足したい人は、
**そのとき W9 の保証が届かなくなることを意識して**このリストへ登録することになる。

これにより W9 の保証は「`ci.yml` に直接記述された範囲」のままだが、
**`browser-tests` job についてはその範囲が job の全体と一致する**ことを W14 が固定する。
他 job (`php` / `frontend` / `supply-chain-audit`) には W14 を課さない
(レーン骨抜きの標的は browser lane であり、過剰な制約を全 job に広げない)。

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

/** 任意の深さのオブジェクト木に指定 **キー名** が現れる位置を返す純関数 (W9 / W13 用)。 */
export function findKeyPaths(node: unknown, key: string, path = "$"): string[] { /* ... */ }

/**
 * 任意の深さの木を歩き、**scalar 文字列の中身**に needle を含む位置を返す純関数 (W9 用)。
 * `run: BROWSER_TEST_LANES=chromium composer test:browser` のような
 * 「キーではなく値として仕込む」骨抜きを検出するために必要
 * (キー走査だけでは素通りする。design-review R1 [Critical])。
 */
export function findScalarValuePathsContaining(node: unknown, needle: string, path = "$"): string[] { /* ... */ }
```

- `process.cwd()` = repo root 前提 (`feedback-probe.test.ts` と同じ)。
- W4/W6/W7/W8/W10/W11 は `runScript(job).includes("...")` で判定する。
  step 名や step 分割の仕方に依存させない (リファクタで偽赤にしない)。

#### 負のコントロール (必須)

fixture の workflow オブジェクトに対して:

| fixture | 期待 |
|---|---|
| `continue-on-error: true` を持つ step を混ぜる | `findKeyPaths(..., "continue-on-error")` が 1 件返す |
| `env: { BROWSER_TEST_LANES: "chromium" }` を持つ job | `findKeyPaths` が 1 件返す |
| `run: "BROWSER_TEST_LANES=chromium composer test:browser"` | **`findScalarValuePathsContaining` が 1 件返す** (キー走査は 0 件のまま = 値走査が必要であることの証明) |
| `run: "export BROWSER_TEST_PROCESSES=4\ncomposer test:browser"` (複数行 scalar) | 同上 |
| 正常な fixture | 両関数とも 0 件 |
| `browser-tests` に `uses: ./.github/actions/setup-browser` を混ぜる | W14 が違反を返す (allowlist 外の action = W9 の射程外経路の混入) |
| `composer test:browser` を `run` から消し composite action に移す | W14 が違反を返す |

### テスト計画

- [x] 新規テスト: `ci-workflow-inventory.test.ts` — W1〜W14 + 負のコントロール 7 本
- [x] 施策 1/2/3/5 の実装完了後に緑になること
- [x] 既存テストの更新: なし
- [x] 個別 `DatabaseTransactions` を使っていないことを確認: 該当なし

### リスク

| リスク | 対応 |
|---|---|
| workflow の正当な改善 (step の並べ替え / 名前変更) で偽赤 | 判定を「job 単位の run 文字列に含まれるか」に寄せ、step 名や順序に依存させない |
| W1 の完全一致が job 追加のたびに落ちる | それが意図 (deny-by-default)。job を足す人に「レーン契約を意識させる」ためのコスト |
| W9 の保証が composite action / reusable workflow 経由の設定に届かない | W14 が `browser-tests` job の `uses` を setup action allowlist に固定し、`composer test:browser` を直接 `run` することを要求することで、**射程外経路の混入自体**を止める (R2 [Warning]) |
| W14 の allowlist が action の version 上げで偽赤になる | 比較は `@version` を除いた action 名で行う |
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
| 判断根拠 | 12 施策すべてが `.github/workflows/ci.yml` か「テストレーンの契約」という単一テーマに収束し、施策間に明確な依存 (3→1→2→6→7→8→9→4A→4B→5→10→11) がある。特に **施策 10 (workflow inventory gate) は施策 1/2/3/5 が揃って初めて緑になる**ため、分割すると中間状態で必ず赤になる。同一 worktree で順に積む方が中間状態を緑に保てる |
| 競合リスク | `.github/workflows/ci.yml` を施策 1/2/3/5 が触るため、これらを別々の worktree で並行実装すると確実に競合する。**同一 worktree で直列に実装すること** |
| 分割の余地 | 施策 4A (gate の fail-closed 化) + 4B (advisory 解消) + 5 (job 配線) の 3 本だけは、advisory 集合が時間で drift するため**先行 PR として切り出す判断があり得る** (conceptual-review R1 [Suggestion])。その場合も **4A → 4B → 5 の順序は非交渉**。切り出したら施策 10 の W1/W11/W12 は後続バッチで有効化する |
| 非交渉の順序 | **4A (fail-closed 化) は 4B (advisory 解消) より先**。fail-closed 化前の `audit:gate` の「exit 0」は「本当に 0 件」と「取得できなかった」を区別できないため、4B の完了判定が信用できない (design-review R1 [Critical]) |
| main を赤にしないための着地順 | 実装中は各施策の完了時点でローカル全レーン (`composer test` / `pnpm test` / `pnpm test:packages` / `pnpm build` / `pnpm build:packages` / `pnpm typecheck` / `composer phpstan` / `vendor/bin/pint --test`) を緑に保つ。CI job の追加 (施策 2/5) は、対応するローカル実走が緑になったことを確認してから行う |

### 実装完了の判定条件

1. ローカル: `composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages` /
   `pnpm lint` / `pnpm typecheck` / `pnpm typecheck:packages` / `pnpm build` / `pnpm build:packages` /
   `composer phpstan` / `vendor/bin/pint --test` / `pnpm run audit:gate` が**すべて exit 0**
2. CI: `php` / `frontend` / `browser-tests` / `supply-chain-audit` の **4 job すべてが緑**
3. 新規テスト 6 本 (施策 4A/6/7/8/9/10) がすべて負のコントロールを持ち、
   壊れた fixture / 改変スクリプトを実際に検出することを確認済み
4. `pnpm run audit:gate` の緑が **fail-closed 化後のもの** であること
   (= 取得成功を前提とした緑であり、取得失敗を握り潰した緑ではない)

