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

---

あなたは Laravel + Svelte アプリのコードレビュアーです。以下の実装差分をレビューしてください。

## レビュー観点
- 詳細設計との一致性 (設計から逸脱している箇所とその妥当性)
- 正確性 (ロジックの誤り、偽グリーン/偽赤を生む経路)
- PHPStan level 10 適合性
- テスト網羅性 (特に **負のコントロールが空振りしていないか**)
- セキュリティ (supply-chain gate の fail-closed 性)
- 禁止事項違反: PHPStan の widen/baseline 化、テストなしの実装完了、既存テストの削除・上書き、
  CI バイパス分岐 (CI 環境変数の参照)、continue-on-error による soft-fail

DTO/JsonResource・Inertia Props・DESIGN.md・Atomic Design は本バッチでは該当なし
(モデル/Controller/Props を一切追加していないため)。

## 出力形式
ファイルごとに判定し、Critical / Warning / Suggestion に分類してください。
最後に全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。

## タスク: T104 CI レーン統合

設計は devnotes/20260805-1243-ci-lane-integration/detailed-design.md (APPROVED Round 4)。
施策 12 本を実装順序 3 → 1 → 2 → 6 → 7 → 8 → 9 → 4A → 4B → 5 → 10 → 11 で実装しました。

施策一覧:
1. php job の pgsql 化 (postgres:18-alpine service + pdo_pgsql extension)
2. browser-tests job 新設 (chromium + webkit の 2 レーン)
3. packages/cli の dead import 除去 (TS6192x1 / TS6133x6) + build:packages の CI 配線
4A. audit-gate.sh の fail-closed 化 (取得失敗を advisory 0 件扱いにしない)
4B. 未受容 high advisory の解消 (26 件 → 4 件、high 15 → 0)
5. supply-chain-audit job 新設 + nightly + 運用責任の明文化
6. scripts/run-browser-test.contract.test.ts 新設
7. tests/Architecture/PhpunitBrowserConfigParityTest.php 新設
8. vitest include の SoT 化 + inventory gate
9. make-shard-phpunit.php 削除 + 台帳ドリフト解消 + 再発防止 gate
10. CI workflow inventory gate
11. ドキュメント追従

## 設計から意図的に逸脱した点 (重点的にレビューしてください)

**逸脱 1: composer-audit の空配列を許容した**

設計 (施策 4A) は「composer audit は object 固定。配列で来たら schema 不一致 = 取得異常として
fail-closed に倒す」と書き、テスト表にも `{"advisories":[]}` (composer) は **throw** と明記していました。

しかし実装中の実測で、`composer audit --format=json` は **advisory 0 件のとき
`{"advisories":[],"abandoned":[],"filter":[]}` を出す** ことが判明しました
(PHP の空配列が json_encode で `[]` になるため)。設計どおりに実装すると、
**advisory を全部解消した正常状態が恒久的に赤くなる** (偽赤) ため、
「**空配列だけ許容し、非空配列は拒否**」へ変更しました。

設計意図 (非 0 件の中身が黙って 0 件へ落ちる偽グリーン経路を塞ぐ) は保持しているつもりですが、
この判断が妥当か、穴が残っていないかを厳しく見てください。

**逸脱 2: 契約テストの sandbox スタブに sleep 0.1 を入れた**

scripts/run-browser-test.contract.test.ts の sandbox で php/pest スタブが即座に exit すると、
global_test_lock_run 内の `pgid="$(ps -o pgid= -p "$pid" | tr -d ' ')"` が
「そんな pid は無い」で非ゼロを返し、`set -euo pipefail` によりレーンごと落ちます。
実運用のコマンドはミリ秒では終わらないため顕在化しませんが、これは
**global-test-lock.sh に残る潜在的な race** です。T099 の契約ファイルなので本バッチでは
触らず、スタブ側に sleep を入れて回避しました。この判断の是非も見てください。

## 実装差分 (git diff。lockfile は量が多いため除外)
diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index fb66f1f..f3cb7b5 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -4,15 +4,50 @@ on:
   push:
     branches: [main]
   pull_request:
+  # 上流で新しい advisory が公開された事実を、無関係な PR のクリティカルパス外で先に検知する。
+  # nightly は PR job の **代替ではなく追加** (PR job を降格させない)。
+  schedule:
+    - cron: "0 20 * * *"   # 05:00 JST
 
 jobs:
   php:
     runs-on: ubuntu-latest
+    timeout-minutes: 30
+    # pgsql 一本化 (phpunit.xml が DB_CONNECTION=pgsql を <server force> する) の実体。
+    # image は docker-compose と同一 major に揃える: ローカルの実測 (2704 passed) を
+    # CI の期待値としてそのまま使えるようにするため (major 差は collation / SQL 差で
+    # 「CI だけ赤 / CI だけ緑」を生む)。
+    services:
+      postgres:
+        image: postgres:18-alpine
+        env:
+          POSTGRES_USER: postgres
+          POSTGRES_PASSWORD: postgres
+          # scripts/ci/pgsql_test_conn.php が maintenance DB として固定で使うため明示する
+          POSTGRES_DB: postgres
+        ports:
+          - 5432:5432
+        options: >-
+          --health-cmd pg_isready
+          --health-interval 5s
+          --health-timeout 5s
+          --health-retries 10
+    # DB_DATABASE は置かない: tests/bootstrap.php が `<slug>_test_<worktree-hash>` を
+    # 後勝ちで注入し assertPgsqlTestDatabaseSafe() が fail-closed 検証する単一点ガードを
+    # 曖昧にしないため。接続先だけを渡す (pgsql_test_conn.php は shell env を最優先で読む)。
+    env:
+      DB_HOST: 127.0.0.1
+      DB_PORT: 5432
+      DB_USERNAME: postgres
+      DB_PASSWORD: postgres
     steps:
       - uses: actions/checkout@v4
       - uses: shivammathur/setup-php@v2
         with:
           php-version: "8.4"
+          # pdo_pgsql は既定で入る保証がない。未導入だと ensure-test-db.php が
+          # 「could not find driver」で落ちる (pgsql lane が丸ごと動かない)
+          extensions: pdo_pgsql, pgsql
           coverage: none
       - name: Install composer dependencies
         run: composer install --prefer-dist --no-progress --no-interaction
@@ -44,9 +79,83 @@ jobs:
       # 実ロックには触れず、mktemp -d の scratch 上で待機・シグナル収束・fd 非継承などを検証する。
       - name: Verify global test lock
         run: bash scripts/verify-global-test-lock.sh
+      # composer test = scripts/run-test.sh (グローバルロック → ensure-test-db → artisan test --parallel)。
+      # CI 専用の起動経路は作らない (T099: CI が検証するものと開発者が走らせるものを同一に保つ)。
+      # 1 job = 1 runner なので他 job と競合せず、ロックは無競合で即時取得される。
       - name: Pest
         run: composer test
 
+  # Browser lane (pest-plugin-browser)。Chromium + WebKit の 2 レーンが契約であり
+  # (AGENTS.md ドメイン規約 3 / docs/supported-browsers.md / T082)、CI でもレーンを絞らない。
+  # WebKit は撮影 PWA の主戦場 iOS Safari に最も近い engine で、ログアウト後の
+  # Inertia 履歴からの PII 復元を止める唯一の自動回帰である。
+  browser-tests:
+    runs-on: ubuntu-latest
+    # 実ブラウザがハングしたときに既定 6 時間を燃やさないための上限。
+    # 現状 14 テスト × 2 レーン (直列) なので十分な余裕がある。
+    timeout-minutes: 45
+    services:
+      postgres:
+        image: postgres:18-alpine
+        env:
+          POSTGRES_USER: postgres
+          POSTGRES_PASSWORD: postgres
+          POSTGRES_DB: postgres
+        ports:
+          - 5432:5432
+        options: >-
+          --health-cmd pg_isready
+          --health-interval 5s
+          --health-timeout 5s
+          --health-retries 10
+    env:
+      DB_HOST: 127.0.0.1
+      DB_PORT: 5432
+      DB_USERNAME: postgres
+      DB_PASSWORD: postgres
+      # BROWSER_TEST_LANES / BROWSER_TEST_PROCESSES は **意図的に設定しない**。
+      # 既定 (chromium webkit / 直列 1) が契約であり、CI で上書きするとレーンを
+      # 骨抜きにできてしまう (tests/js/architecture/ci-workflow-inventory.test.ts が
+      # この不在を deny-by-default で固定する)。
+    steps:
+      - uses: actions/checkout@v4
+      - uses: shivammathur/setup-php@v2
+        with:
+          php-version: "8.4"
+          extensions: pdo_pgsql, pgsql
+          coverage: none
+      - uses: pnpm/action-setup@v4
+        with:
+          version: 11.3.0
+      - uses: actions/setup-node@v4
+        with:
+          node-version: 22
+          cache: pnpm
+      - name: Install composer dependencies
+        run: composer install --prefer-dist --no-progress --no-interaction
+      - name: Install pnpm dependencies
+        run: pnpm install --frozen-lockfile
+      - name: Prepare environment
+        run: |
+          cp .env.example .env
+          php artisan key:generate
+          php artisan passport:keys --force
+      # 実ブラウザは public/build のビルド済アセットを読む (withoutVite() は Browser lane に効かない)。
+      - name: Build frontend assets
+        run: pnpm build
+      # ブラウザ実体は Playwright が別途 DL する。**pnpm exec** を使うこと:
+      # pest-plugin-browser が起動する run-server は root devDependency の playwright と
+      # 同一実体である必要があり、npx だと別バージョンを引きうる。
+      # 未導入だと PlaywrightOutdatedException で 2 レーンとも全 fail する (ローカル実測)。
+      # --with-deps は WebKit が Linux で要求する共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を入れる。
+      - name: Install Playwright browsers (chromium + webkit)
+        run: pnpm exec playwright install --with-deps chromium webkit
+      # composer test:browser = scripts/run-browser-test.sh
+      # (グローバルロック → config:clear → ensure-test-db → chromium レーン → webkit レーン)。
+      # レーン引数は渡さない (§既定が契約)。
+      - name: Pest (browser lanes)
+        run: composer test:browser
+
   frontend:
     runs-on: ubuntu-latest
     steps:
@@ -68,7 +177,47 @@ jobs:
         run: pnpm test
       - name: TypeScript (workspace packages)
         run: pnpm typecheck:packages
+      # emit 経路 (packages/cli/tsconfig.json) の検証。
+      # typecheck:packages が使う tsconfig.test.json は noUnusedLocals/noUnusedParameters を
+      # 明示的に false にしているため、**build を通さないと検出できないエラーが存在する**。
+      # 「typecheck があるから build は不要」は成立しない (実測: main で TS6133/TS6192 7 件)。
+      - name: Build (workspace packages)
+        run: pnpm build:packages
       - name: Vitest (workspace packages)
         run: pnpm test:packages
       - name: Build
         run: pnpm build
+
+  # supply-chain 依存脆弱性 gate (AGENTS.md §依存脆弱性の運用)。
+  #
+  # **continue-on-error を付けない**。soft-fail は「赤いのに緑に見える」= 偽グリーンであり、
+  # PHPStan の baseline 化 (禁止事項 2) と同型の逃げになる。
+  # 未受容 high/critical で fail、moderate は warn (audit-gate.ts の判定)。
+  # 逃げ道は docs/supply-chain/accepted-advisories.yaml の **期限付き** accept-risk のみ
+  # (expiry・cleanup・severity 別上限を audit-gate.ts が機械強制するため、
+  #  「黙らせて永続化する」ベースラインとは性質が異なる)。
+  supply-chain-audit:
+    runs-on: ubuntu-latest
+    timeout-minutes: 15
+    steps:
+      - uses: actions/checkout@v4
+      # composer audit / pnpm audit の両方を回すため PHP と Node の両方が要る
+      - uses: shivammathur/setup-php@v2
+        with:
+          php-version: "8.4"
+          coverage: none
+      - uses: pnpm/action-setup@v4
+        with:
+          version: 11.3.0
+      - uses: actions/setup-node@v4
+        with:
+          node-version: 22
+          cache: pnpm
+      - name: Install composer dependencies
+        run: composer install --prefer-dist --no-progress --no-interaction
+      - name: Install pnpm dependencies
+        run: pnpm install --frozen-lockfile
+      # scripts/audit-gate.sh → scripts/audit-gate.ts (tsx 経由)。
+      # ローカルの `pnpm run audit:gate` と同一経路 (CI 専用の判定を作らない)。
+      - name: Supply-chain audit gate
+        run: pnpm run audit:gate
diff --git a/.gitignore b/.gitignore
index b13648a..8782492 100644
--- a/.gitignore
+++ b/.gitignore
@@ -17,8 +17,6 @@
 /.idea
 /.nova
 /.phpunit.cache
-# CI matrix sharding の生成物 (scripts/ci/make-shard-phpunit.php)
-/phpunit.ci-shard.xml
 # pest-plugin-browser が失敗時 screenshot を書く (runtime artifact)
 /tests/Browser/Screenshots/
 /.vscode/*
diff --git a/AGENTS.md b/AGENTS.md
index bacf20d..ca861c8 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -116,6 +116,9 @@ ## 依存脆弱性 (supply-chain) の運用
   rationale 付きで登録する(high/critical は approved_by / compensating_controls /
   tracking_issue も必須)。severity 別の expiry 上限(low/moderate 90 日・high 30 日・
   critical 14 日)、期限切れ・解消済み entry の残置は gate が機械的に fail させる
+- gate は CI (`supply-chain-audit` job) で **blocking** 実行され、加えて nightly (05:00 JST) でも回る。
+  `continue-on-error` は付けない (soft-fail = 偽グリーン)。取得失敗は advisory 0 件扱いにせず
+  fail-closed で止まる。運用責任 (owner / 初動 SLA) は `docs/supply-chain/review-checklist.md` §6
 - 判断基準・0day 緊急時フロー・新規 npm 依存の審査観点は
   `docs/supply-chain/review-checklist.md` を参照
 
diff --git a/docs/supply-chain/review-checklist.md b/docs/supply-chain/review-checklist.md
index 62ae91c..4cbecc5 100644
--- a/docs/supply-chain/review-checklist.md
+++ b/docs/supply-chain/review-checklist.md
@@ -50,6 +50,36 @@ ## 5. 0day 緊急時
 `compensating_controls` に具体的な緩和 (WAF rule / 機能無効化 / network egress 制限 等) を明記する。
 7 日以内に恒久対応 (upgrade / patch / 機能撤去) を完了させる。
 
+## 6. CI での実行と運用責任
+
+`pnpm run audit:gate` は GitHub Actions の `supply-chain-audit` job で実行される。
+
+- **PR / push (main)**: blocking。`continue-on-error` は付けない
+  (soft-fail は「赤いのに緑に見える」= baseline 化と同型のため採らない)。
+- **nightly (05:00 JST)**: 同じ job を `schedule` でも回す。上流で新しい advisory が
+  公開された事実を、**無関係な PR のクリティカルパス外**で先に検知するため。
+  nightly は PR blocking の代替ではない。
+
+取得失敗 (network 不通・レジストリ障害) は **advisory 0 件として扱わない**。
+`scripts/audit-gate.sh` が空出力・前処理失敗をそこで止め、`assertAuditSourceShape` が
+「valid JSON だが期待 schema でない」出力を弾く (fail-closed)。一過性の赤は re-run で回復する。
+
+### 一次対応
+
+| 項目 | 決め |
+|---|---|
+| 一次対応 owner | リポジトリオーナー (`ishitoya`)。nightly / PR いずれの赤化でも同一 |
+| 初動 SLA | critical: 当日中に判断 / high: 2 営業日以内に判断 / moderate: warn のみ (SLA なし) |
+| 「判断」の中身 | upgrade で解消する、または §3 の上限内で accept-risk を登録する、のいずれか |
+| accept-risk の承認者 | 単独開発体制のため `approved_by` = owner。代替統制として `expiry` 上限 (high 30 日) と `tracking_issue` 必須で外部から追跡可能にする (`audit-gate.ts` が両方を機械強制) |
+| 自動 upgrade PR (Dependabot / Renovate) | **現時点では導入しない**。gate 単体で運用し「upgrade 追従が人手で回らない」ことが観測されてから検討する |
+
+### 上流由来で全 PR が赤くなったとき
+
+新しい advisory の公開は無関係な PR も止める。これは gate の副作用ではなく**意図した挙動**
+(未受容の high を抱えたまま main が進むことを許さない)。逃げ道は §3 の期限付き accept-risk のみで、
+`continue-on-error` の追加や gate の除外リスト化はしない。
+
 ## 付録: 新規 npm 依存の審査観点
 
 新規 npm 依存を追加する、もしくは既存依存を major version 更新する際の人手レビュー観点。
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index fb5e213..587e8f1 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -70,6 +70,18 @@ ### ブラウザレーン (Chromium + WebKit)
 
 pest 終了後に orphan 化した `playwright run-server` (node) はスクリプトが実行前後に掃除する。
 
+### CI での実行
+
+`.github/workflows/ci.yml` の `browser-tests` job が、**Chromium / WebKit の 2 レーンをそのまま**
+実行する (レーン限定も並列度上書きもしない)。job は postgres service +
+`pnpm build` + `pnpm exec playwright install --with-deps chromium webkit` を前提として
+`composer test:browser` を呼ぶ。CI 専用の起動経路は作らない (T099: CI が検証するものと
+開発者が走らせるものを同一に保つ)。
+
+workflow 側で `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を設定する退行は
+`tests/js/architecture/ci-workflow-inventory.test.ts` が、スクリプト側の契約は
+`scripts/run-browser-test.contract.test.ts` が deny-by-default で止める。
+
 ### 前提
 
 - **DB は Feature lane と同じ worktree 固有 pgsql テスト DB** (`<slug>_test_<worktree-hash>`)。
diff --git a/package.json b/package.json
index b21f55c..aa0f22d 100644
--- a/package.json
+++ b/package.json
@@ -31,20 +31,20 @@
         "@testing-library/svelte": "^5.3.1",
         "@tsconfig/svelte": "^5.0.8",
         "@types/node": "^25.9.2",
-        "@typescript-eslint/parser": "^8.60.1",
+        "@typescript-eslint/parser": "^8.66.0",
         "@vitest/coverage-v8": "^4.1.8",
         "@vitest/ui": "^4.1.8",
         "autoprefixer": "^10.5.0",
-        "eslint": "^9.39.4",
+        "eslint": "^10.8.0",
         "eslint-plugin-better-tailwindcss": "4.4.1",
-        "eslint-plugin-svelte": "^3.19.0",
+        "eslint-plugin-svelte": "^3.22.0",
         "globals": "^17.9.0",
         "happy-dom": "^20.10.2",
         "jsdom": "^27.4.0",
         "laravel-vite-plugin": "^3.1.0",
         "mprocs": "^0.7.3",
         "playwright": "^1.60.0",
-        "postcss": "^8.5.15",
+        "postcss": "^8.5.25",
         "svelte-eslint-parser": "^1.8.0",
         "tailwindcss": "^4.3.0",
         "tsx": "^4.22.4",
diff --git a/packages/cli/package.json b/packages/cli/package.json
index 71e7fd6..761a981 100644
--- a/packages/cli/package.json
+++ b/packages/cli/package.json
@@ -39,8 +39,8 @@
     },
     "dependencies": {
         "@napi-rs/keyring": "^1.2.0",
-        "@oclif/core": "^4.10.5",
-        "@oclif/plugin-help": "^6.2.44",
+        "@oclif/core": "^4.13.3",
+        "@oclif/plugin-help": "^6.2.56",
         "envinfo": "^7.21.0",
         "undici": "^6.27.0",
         "yaml": "^2.9.0",
diff --git a/packages/cli/src/api/client.ts b/packages/cli/src/api/client.ts
index d3baf7c..5610c83 100644
--- a/packages/cli/src/api/client.ts
+++ b/packages/cli/src/api/client.ts
@@ -1,4 +1,3 @@
-import { ENV, BIN_NAME } from "../branding.js";
 import { readFileSync } from "node:fs";
 import {
     Agent,
@@ -167,7 +166,7 @@ export function buildUrl(
  * Resolve the `Authorization` header for a request.
  *
  * Precedence (T715 Phase 3): a configured API key always wins, so existing
- * `--api-key` / `${ENV.API_KEY}` / stored-key users keep their exact
+ * `--api-key` / `ENV.API_KEY` (branding.ts) / stored-key users keep their exact
  * behaviour. Only when no API key is present does the OAuth user-token
  * provider run (reading the stored token and refreshing under a file lock
  * if it is near expiry). When neither is available, the request goes out
@@ -622,7 +621,7 @@ export async function apiDelete<S extends ZodTypeAny>(
 
 /**
  * DELETE an API resource that responds with a JSON body (200, not 204).
- * Used by `${BIN_NAME} logout` against `DELETE /api/v1/me/session`, which returns
+ * Used by the CLI `logout` command against `DELETE /api/v1/me/session`, which returns
  * the revoked-session descriptor. Unlike {@link apiDelete} the body is
  * parsed against `schema`.
  */
diff --git a/packages/cli/src/credential/encryption.ts b/packages/cli/src/credential/encryption.ts
index 0271aff..225cfb8 100644
--- a/packages/cli/src/credential/encryption.ts
+++ b/packages/cli/src/credential/encryption.ts
@@ -16,7 +16,6 @@
 //   - Per-profile `.salt` file removed; each file carries its own salt so
 //     keys are derived freshly from the user's master password + file salt.
 
-import { ENV } from "../branding.js";
 import {
     createCipheriv,
     createDecipheriv,
@@ -46,7 +45,7 @@ export const UXI2_MIN_LEN = UXI2_PREAMBLE_LEN; // empty plaintext is valid
  * The salt is not used by encryption itself (AES-GCM does not need a salt)
  * but is embedded in the header so that the same password can re-derive the
  * key on read without consulting any sidecar file. Callers that use a
- * directly-provided master key (e.g. `${ENV.CREDENTIAL_KEY}`) may pass any
+ * directly-provided master key (e.g. the `ENV.CREDENTIAL_KEY` env var) may pass any
  * 16B value; it will simply be stored verbatim.
  */
 export function encryptString(
@@ -73,7 +72,7 @@ export function encryptString(
     // AAD authenticates the bytes that AES-GCM does not otherwise cover:
     // magic (4) + version (1) + cipher_id (1) + salt (16). If any of these
     // are tampered with on disk (including when the caller is using a
-    // direct `${ENV.CREDENTIAL_KEY}` that would otherwise ignore the salt),
+    // direct `ENV.CREDENTIAL_KEY` that would otherwise ignore the salt),
     // GCM's auth tag check will fail on read and we exit 33. IV and
     // authTag themselves are implicitly covered by GCM and need no AAD.
     const aad = buildAad(salt);
diff --git a/packages/cli/src/http/schemas.ts b/packages/cli/src/http/schemas.ts
index b04ad66..56b629d 100644
--- a/packages/cli/src/http/schemas.ts
+++ b/packages/cli/src/http/schemas.ts
@@ -1,4 +1,3 @@
-import { BIN_NAME } from "../branding.js";
 import { z } from "zod";
 
 import { EnvironmentTagSourceSchema } from "../schemas/environment-tag-source.js";
@@ -15,7 +14,7 @@ export const VersionResponseSchema = z
                 environment_tag: z.string().nullable(),
                 environment_tag_source: EnvironmentTagSourceSchema,
                 instance_id: z.string().nullable(),
-                // T715 Phase 3: public PKCE client id for `${BIN_NAME} login`.
+                // T715 Phase 3: public PKCE client id for the CLI `login` command.
                 // Optional so older servers (and the CLI mock harness) that
                 // don't advertise it stay schema-valid; the login command
                 // falls back to --client-id / APP_OAUTH_CLIENT_ID.
diff --git a/packages/cli/src/oauth/client-id.ts b/packages/cli/src/oauth/client-id.ts
index 231827b..7d04688 100644
--- a/packages/cli/src/oauth/client-id.ts
+++ b/packages/cli/src/oauth/client-id.ts
@@ -1,14 +1,14 @@
-import { ENV, BIN_NAME } from "../branding.js";
+import { ENV } from "../branding.js";
 import { fetchJsonValidated } from "../http/fetch-json.js";
 import { VersionResponseSchema } from "../http/schemas.js";
 import type { ResolvedConnectionOptions } from "../profile/context.js";
 
 /**
- * Resolve the public PKCE client id for `${BIN_NAME} login` (T715 Phase 3).
+ * Resolve the public PKCE client id for the CLI `login` command (T715 Phase 3).
  *
  * Resolution order (first hit wins):
  *   1. explicit `--client-id`
- *   2. `${ENV.OAUTH_CLIENT_ID}` env
+ *   2. the `ENV.OAUTH_CLIENT_ID` env var
  *   3. the server's `GET /version` → `cli_oauth_client_id`
  *
  * The version discovery is best-effort: a server that doesn't advertise the
diff --git a/packages/cli/src/oauth/login.ts b/packages/cli/src/oauth/login.ts
index b095d8a..9a9f679 100644
--- a/packages/cli/src/oauth/login.ts
+++ b/packages/cli/src/oauth/login.ts
@@ -1,4 +1,3 @@
-import { ENV } from "../branding.js";
 import type { Dispatcher } from "undici";
 import type { CredentialStore } from "../credential/store.js";
 import { startLoopbackServer } from "./loopback-server.js";
@@ -22,7 +21,7 @@ import { writeOAuthToken, type OAuthTokenBundle } from "./token-store.js";
  * 5. `POST /oauth/token` (with code_verifier) and persist the token bundle
  *
  * The caller resolves `clientId` (from `/version`, `--client-id`, or
- * `${ENV.OAUTH_CLIENT_ID}`) — see `resolveCliOAuthClientId`.
+ * the `ENV.OAUTH_CLIENT_ID` env var) — see `resolveCliOAuthClientId`.
  */
 
 export type LoginResult = {
diff --git a/packages/cli/src/oclif/commands/profile/add.ts b/packages/cli/src/oclif/commands/profile/add.ts
index f77e5d8..2a0d474 100644
--- a/packages/cli/src/oclif/commands/profile/add.ts
+++ b/packages/cli/src/oclif/commands/profile/add.ts
@@ -1,4 +1,3 @@
-import { BIN_NAME } from "../../../branding.js";
 import { Args, Flags } from "@oclif/core";
 import { getCliVersion } from "../../../index.js";
 import { confirmPrompt } from "../../../credential/prompt.js";
@@ -12,7 +11,7 @@ import { profileFlags } from "../../base/flags.js";
 import { ProfileCommand } from "../../base/ProfileCommand.js";
 
 /**
- * `${BIN_NAME} profile:add <name>` — register a new profile and verify
+ * `profile:add <name>` — register a new profile and verify
  * its connection end-to-end (`/version` + optional `/me`).
  *
  * Uses `resolveMode: "if-needed"` because add creates the profile; it
diff --git a/packages/cli/src/oclif/commands/whoami.ts b/packages/cli/src/oclif/commands/whoami.ts
index d6a57f5..776d4b2 100644
--- a/packages/cli/src/oclif/commands/whoami.ts
+++ b/packages/cli/src/oclif/commands/whoami.ts
@@ -1,11 +1,10 @@
-import { BIN_NAME } from "../../branding.js";
 import { apiGet } from "../../api/client.js";
 import { MeResponseSchema } from "../../api/schemas.js";
 import { formatJson } from "../../output/json.js";
 import { ReadCommand } from "../base/ReadCommand.js";
 
 /**
- * `${BIN_NAME} whoami` — confirm the active API key resolves to an
+ * `whoami` — confirm the active API key resolves to an
  * organization on the configured API server.
  *
  * Output format is fixed at 4 lines of `key: value` pairs so downstream
diff --git a/packages/cli/vitest.config.ts b/packages/cli/vitest.config.ts
index 74ac981..c2ff565 100644
--- a/packages/cli/vitest.config.ts
+++ b/packages/cli/vitest.config.ts
@@ -1,8 +1,12 @@
 import { defineConfig } from "vitest/config";
+import { testProject } from "../../scripts/test-inventory-config";
 
 export default defineConfig({
     test: {
-        include: ["tests/**/*.test.ts"],
+        // include の正本は repo root の scripts/test-inventory-config.ts。
+        // 本パッケージが monorepo root を参照するのはこの devtool 設定のみで、
+        // package.json#files は dist/bin/README.md に限定されているため公開成果物には入らない。
+        include: [...testProject("packages/cli").include],
         environment: "node",
         // 資格情報バックエンドをホスト非依存に固定する (setup の解説参照)。
         setupFiles: ["tests/setup/credential-backend.ts"],
diff --git a/scripts/README.md b/scripts/README.md
index bf550cc..d37bcaa 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -20,11 +20,14 @@ ## スクリプト一覧
 | `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip) | worktree teardown / CI cleanup |
 | `setup-worktree.sh` | TODO 用 worktree (`.claude/worktrees/tasks/<task-id>` + `todo/<task-id>` ブランチ) を作成し、実行時ファイルのコピー・worktree-local な `composer install --no-scripts` / `pnpm install` (GVS 共有)・health check・テスト DB ensure まで機械的に実行 (AGENTS.md §worktree 運用ルール) | 実装開始時 (`app-implement` W-3 等) |
 | `teardown-worktree.sh` | worktree の dirty チェック → テスト DB の best-effort 回収 → `git worktree remove --force`。ブランチの削除/マージは呼び出し側の責務 | 実装完了後 (`app-implement` C-4 等) |
-| `ci/make-shard-phpunit.php` | GitHub Actions の matrix sharding 用に、担当テストファイルのみの phpunit 設定を生成 | CI から自動呼び出し |
 | `ci/pgsql_test_conn.php` | ensure / drop が共有する pgsql 接続 resolver | (上記 2 スクリプトの内部 include) |
-| `audit-gate.sh` | supply-chain 依存脆弱性 gate のローカル実行ラッパ。composer / pnpm(pyproject.toml があれば pip-audit も)の audit JSON を取得して `audit-gate.ts` に渡す | `pnpm run audit:gate` から自動呼び出し / 直接実行 |
+| `audit-gate.sh` | supply-chain 依存脆弱性 gate の実行ラッパ。composer / pnpm(pyproject.toml があれば pip-audit も)の audit JSON を取得して `audit-gate.ts` に渡す。**取得は fail-closed**: 空出力・前処理 (`uv export`) の失敗はそこで停止し、advisory 0 件として判定へ流さない | `pnpm run audit:gate` から自動呼び出し / CI (`supply-chain-audit` job) / 直接実行 |
 | `audit-gate.ts` | audit JSON の統合判定 (high+ fail / moderate warn / `docs/supply-chain/accepted-advisories.yaml` の expiry・cleanup・severity 別上限を機械強制) | `audit-gate.sh` / CI から自動呼び出し |
 | `audit-gate.test.ts` | `audit-gate.ts` の unit テスト (正規化・expiry 判定・accept-risk 照合) | `pnpm test` (vitest の include に `scripts/**/*.test.ts` が入っている) |
+| `audit-gate.contract.test.ts` | `audit-gate.sh` の取得契約テスト (空出力 / 取得失敗を advisory 0 件にせず fail-closed で止める。有効 JSON + 非ゼロ exit = 脆弱性検出の正常系は判定へ通す) | `pnpm test` |
+| `test-inventory-config.ts` | vitest の include (root / packages/cli の 2 project) の単一 SoT。`vitest.config.ts` と `packages/cli/vitest.config.ts` が本ファイルから include を引く | 両 vitest config から import (直接実行しない) |
+| `vitest-inventory-gate.test.ts` | FS 走査と `vitest list` の突合による inventory gate。どの project にも入らない `*.test.ts` (= 書いたのに走っていないテスト) と、列挙 0 件の空振りを検出 | `pnpm test` |
+| `run-browser-test.contract.test.ts` | `run-browser-test.sh` の契約テスト (2 レーン実行 / 失敗レーンがあっても全レーン実行して overall 非ゼロ / 既定直列 / orphan playwright 掃除 / bug-hunt 除外) | `pnpm test` |
 | `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
 | `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
diff --git a/scripts/audit-gate.contract.test.ts b/scripts/audit-gate.contract.test.ts
new file mode 100644
index 0000000..32a0aa4
--- /dev/null
+++ b/scripts/audit-gate.contract.test.ts
@@ -0,0 +1,249 @@
+/**
+ * scripts/audit-gate.sh の **取得契約** テスト。
+ *
+ * supply-chain gate を blocking へ昇格させる以上、「取得できなかった」を
+ * 「advisory 0 件 = 安全」と読み替える経路を残せない。旧実装は空出力を
+ * `{"advisories":{}}` で捏造して判定へ渡していたため、network 不通なら緑になった。
+ *
+ * 本テストが検証するのは **「判定に到達したか / 手前で止まったか」** に限定される。
+ * 判定ロジック自体 (JSON 妥当性・schema・severity) は scripts/audit-gate.test.ts の
+ * unit テストの責務であり、責務を混ぜない。
+ *
+ * 方式: mkdtemp の sandbox に audit-gate.sh を verbatim コピーし、
+ * `PATH=$SANDBOX/bin:$PATH` で pnpm / composer / uv を引数分岐スタブへ差し替える。
+ * `pnpm exec tsx ...` (判定) に到達したら $SANDBOX/judged が作られる。
+ *
+ * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
+ */
+import { describe, expect, it } from "vitest";
+import { spawnSync } from "node:child_process";
+import { chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
+import { tmpdir } from "node:os";
+import { join, resolve } from "node:path";
+
+const REPO_ROOT = process.cwd();
+const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/audit-gate.sh");
+
+/** 1 ツール分のスタブ挙動 (出力内容と exit code)。 */
+interface StubBehaviour {
+    /** stdout へ出す内容。空文字 = 何も出力しない (取得失敗の模擬)。 */
+    stdout: string;
+    /** exit code。 */
+    exit: number;
+}
+
+interface Scenario {
+    pnpmAudit?: StubBehaviour;
+    composerAudit?: StubBehaviour;
+    /** `uv export` の挙動 (pyproject.toml がある場合のみ使われる)。 */
+    uvExport?: StubBehaviour;
+    /** `uv tool run --from pip-audit ...` の挙動。 */
+    pipAudit?: StubBehaviour;
+    /** true なら sandbox に pyproject.toml を置く (pip 経路のオプトイン条件を実際に踏む)。 */
+    pyproject?: boolean;
+}
+
+interface ContractRun {
+    /** audit-gate.sh の終了コード。 */
+    status: number;
+    /** 判定 (`pnpm exec tsx scripts/audit-gate.ts`) に到達したか。 */
+    judged: boolean;
+    /** bin/uv が一度でも呼ばれたか (A10 用)。 */
+    uvInvoked: boolean;
+    stderr: string;
+}
+
+const OK_PNPM = '{"advisories":{}}';
+const OK_COMPOSER = '{"advisories":{}}';
+const OK_REQUIREMENTS = "requests==2.32.3\n";
+const OK_PIP = '{"dependencies":[]}';
+
+function writeExecutable(path: string, content: string): void {
+    writeFileSync(path, content, "utf-8");
+    chmodSync(path, 0o755);
+}
+
+/** stdout / exit code を返すだけの bash 分岐を組み立てる。 */
+function emit(behaviour: StubBehaviour): string {
+    const body =
+        behaviour.stdout === ""
+            ? "  : # 出力なし (取得失敗の模擬)"
+            : `  cat <<'STUB_EOF'\n${behaviour.stdout}\nSTUB_EOF`;
+    return `${body}\n  exit ${behaviour.exit}`;
+}
+
+function runScenario(scenario: Scenario): ContractRun {
+    const sandbox = mkdtempSync(join(tmpdir(), "audit-gate-contract-"));
+    try {
+        mkdirSync(join(sandbox, "scripts"), { recursive: true });
+        mkdirSync(join(sandbox, "bin"), { recursive: true });
+
+        copyFileSync(SCRIPT_PATH, join(sandbox, "scripts/audit-gate.sh"));
+        chmodSync(join(sandbox, "scripts/audit-gate.sh"), 0o755);
+
+        if (scenario.pyproject) {
+            writeFileSync(join(sandbox, "pyproject.toml"), "[project]\nname = \"x\"\n", "utf-8");
+        }
+
+        const judgedMarker = join(sandbox, "judged");
+        const uvMarker = join(sandbox, "uv-invoked");
+
+        // pnpm スタブ: `pnpm audit ...` と `pnpm exec tsx ...` を引数で分岐する
+        const pnpmAudit = scenario.pnpmAudit ?? { stdout: OK_PNPM, exit: 0 };
+        writeExecutable(
+            join(sandbox, "bin/pnpm"),
+            [
+                "#!/usr/bin/env bash",
+                'if [ "$1" = "audit" ]; then',
+                emit(pnpmAudit),
+                'elif [ "$1" = "exec" ]; then',
+                `  touch "${judgedMarker}"`,
+                "  exit 0",
+                "fi",
+                'echo "unexpected pnpm invocation: $*" >&2',
+                "exit 99",
+            ].join("\n"),
+        );
+
+        const composerAudit = scenario.composerAudit ?? { stdout: OK_COMPOSER, exit: 0 };
+        writeExecutable(
+            join(sandbox, "bin/composer"),
+            [
+                "#!/usr/bin/env bash",
+                'if [ "$1" = "audit" ]; then',
+                emit(composerAudit),
+                "fi",
+                'echo "unexpected composer invocation: $*" >&2',
+                "exit 99",
+            ].join("\n"),
+        );
+
+        // uv スタブ: `uv export ...` と `uv tool run --from pip-audit ...` を分岐する
+        const uvExport = scenario.uvExport ?? { stdout: OK_REQUIREMENTS, exit: 0 };
+        const pipAudit = scenario.pipAudit ?? { stdout: OK_PIP, exit: 0 };
+        writeExecutable(
+            join(sandbox, "bin/uv"),
+            [
+                "#!/usr/bin/env bash",
+                `touch "${uvMarker}"`,
+                'if [ "$1" = "export" ]; then',
+                emit(uvExport),
+                'elif [ "$1" = "tool" ]; then',
+                emit(pipAudit),
+                "fi",
+                'echo "unexpected uv invocation: $*" >&2',
+                "exit 99",
+            ].join("\n"),
+        );
+
+        const result = spawnSync("bash", [join(sandbox, "scripts/audit-gate.sh")], {
+            encoding: "utf-8",
+            env: { ...process.env, PATH: `${join(sandbox, "bin")}:${process.env.PATH ?? ""}` },
+        });
+
+        return {
+            status: result.status ?? -1,
+            judged: existsSync(judgedMarker),
+            uvInvoked: existsSync(uvMarker),
+            stderr: result.stderr ?? "",
+        };
+    } finally {
+        rmSync(sandbox, { recursive: true, force: true });
+    }
+}
+
+describe("audit-gate.sh の取得契約: 取得失敗は fail-closed で止める", () => {
+    it("A1: pnpm audit が空出力 + exit 0 なら判定へ進まず非ゼロ終了", () => {
+        const run = runScenario({ pnpmAudit: { stdout: "", exit: 0 } });
+
+        expect(run.status).not.toBe(0);
+        expect(run.judged).toBe(false);
+        expect(run.stderr).toContain("produced no output");
+    });
+
+    it("A2: pnpm audit が空出力 + exit 1 (ネットワーク失敗) なら判定へ進まず非ゼロ終了", () => {
+        const run = runScenario({ pnpmAudit: { stdout: "", exit: 1 } });
+
+        expect(run.status).not.toBe(0);
+        expect(run.judged).toBe(false);
+    });
+
+    it("A6: composer 側だけ空出力でも止まる (片側の失敗でも fail-closed)", () => {
+        const run = runScenario({ composerAudit: { stdout: "", exit: 0 } });
+
+        expect(run.status).not.toBe(0);
+        expect(run.judged).toBe(false);
+    });
+});
+
+describe("audit-gate.sh の取得契約: 正常系を巻き込まない (負のコントロール)", () => {
+    it("A3: 不正 JSON でも非空なら判定へ到達する (JSON 妥当性は判定層の責務)", () => {
+        const run = runScenario({ pnpmAudit: { stdout: "not json", exit: 0 } });
+
+        expect(run.judged).toBe(true);
+    });
+
+    it("A4: 有効 JSON + 非ゼロ exit (= 脆弱性検出の正常系) は判定へ到達する", () => {
+        const run = runScenario({
+            pnpmAudit: { stdout: '{"advisories":{"1":{"id":"GHSA-x","severity":"high"}}}', exit: 1 },
+            composerAudit: { stdout: OK_COMPOSER, exit: 1 },
+        });
+
+        expect(run.judged).toBe(true);
+    });
+
+    it("A5: 本当に 0 件 ({\"advisories\":{}}) + exit 0 は判定へ到達する", () => {
+        const run = runScenario({});
+
+        expect(run.status).toBe(0);
+        expect(run.judged).toBe(true);
+    });
+
+    it("A10: pyproject.toml が無ければ pip 経路を実行せず判定へ到達する", () => {
+        const run = runScenario({ pyproject: false });
+
+        expect(run.judged).toBe(true);
+        expect(run.uvInvoked).toBe(false);
+    });
+});
+
+describe("audit-gate.sh の取得契約: pip 経路 (pyproject.toml あり)", () => {
+    it("A7a: uv export が空出力なら判定へ進まず非ゼロ終了", () => {
+        const run = runScenario({ pyproject: true, uvExport: { stdout: "", exit: 0 } });
+
+        expect(run.status).not.toBe(0);
+        expect(run.judged).toBe(false);
+    });
+
+    it("A7b: uv export が非空出力 + exit 1 でも止まる (acquire_required の存在意義)", () => {
+        // 部分的 / コメントだけの requirements を残して失敗する経路は A7a では捕まらない。
+        // ここを通してしまうと、痩せた requirements に対する「advisory 0 件」で緑になる。
+        const run = runScenario({
+            pyproject: true,
+            uvExport: { stdout: "# partial export\n", exit: 1 },
+        });
+
+        expect(run.status).not.toBe(0);
+        expect(run.judged).toBe(false);
+        expect(run.stderr).toContain("never 'findings'");
+    });
+
+    it("A8: pip-audit が空出力なら判定へ進まず非ゼロ終了", () => {
+        const run = runScenario({ pyproject: true, pipAudit: { stdout: "", exit: 0 } });
+
+        expect(run.status).not.toBe(0);
+        expect(run.judged).toBe(false);
+    });
+
+    it("A9: pip-audit が有効 JSON + 非ゼロ exit なら判定へ到達する (検出の正常系を止めない)", () => {
+        const run = runScenario({
+            pyproject: true,
+            pipAudit: {
+                stdout: '{"dependencies":[{"name":"x","vulns":[{"id":"PYSEC-1"}]}]}',
+                exit: 1,
+            },
+        });
+
+        expect(run.judged).toBe(true);
+    });
+});
diff --git a/scripts/audit-gate.sh b/scripts/audit-gate.sh
index 7cd8121..7727f13 100755
--- a/scripts/audit-gate.sh
+++ b/scripts/audit-gate.sh
@@ -1,11 +1,14 @@
 #!/usr/bin/env bash
-# scripts/audit-gate.sh — supply-chain 依存脆弱性 gate のローカル実行ラッパ。
+# scripts/audit-gate.sh — supply-chain 依存脆弱性 gate の実行ラッパ。
 #
 # composer / pnpm の audit を JSON で取得し、pyproject.toml があるリポジトリでは
 # pip-audit も加えて scripts/audit-gate.ts に渡す。judging (severity 判定・
 # accept-risk の expiry/cleanup・運用上限の機械強制) は audit-gate.ts に集約する。
 #
-# 終了コード: high+ 未受容 / expiry 切れ / cleanup 漏れ / 上限超過 のいずれかで非ゼロ。
+# 責務境界: **shell = 「有効な出力が得られたか」だけを見る / TypeScript = JSON 妥当性と schema**。
+# bash 側で JSON を検証しない (判定ロジックの二重管理を作らない)。
+#
+# 終了コード: 取得失敗 / high+ 未受容 / expiry 切れ / cleanup 漏れ / 上限超過 のいずれかで非ゼロ。
 # 使い方: `pnpm run audit:gate` または直接 `bash scripts/audit-gate.sh`。
 set -euo pipefail
 
@@ -16,27 +19,60 @@ PNPM_JSON="$(mktemp)"
 COMPOSER_JSON="$(mktemp)"
 PIP_JSON=""
 REQ_TXT=""
-trap 'rm -f "$PNPM_JSON" "$COMPOSER_JSON" ${PIP_JSON:+"$PIP_JSON"} ${REQ_TXT:+"$REQ_TXT"}' EXIT
+# 取得失敗の原因を残すための stderr ログ。set -u で未定義参照にならないよう
+# **acquire を呼ぶ前に**生成し、trap の cleanup にも含める。
+STDERR_LOG="$(mktemp)"
+trap 'rm -f "$PNPM_JSON" "$COMPOSER_JSON" "$STDERR_LOG" ${PIP_JSON:+"$PIP_JSON"} ${REQ_TXT:+"$REQ_TXT"}' EXIT
+
+# audit ツールの非ゼロ終了には 2 つの意味がある:
+#   (i)  脆弱性を検出した      → **正常**。有効な JSON が出ているので judging へ進む
+#   (ii) 取得自体に失敗した    → **異常**。ここで fail-closed に止める
+# 両者は exit code では区別できないため、**出力が有効な JSON であるか**で区別する。
+# 空出力を最小 JSON で捏造して先へ進める旧実装は「blocking gate なのに network 不通なら緑」
+# という偽グリーンだったため廃止した (後方互換の並走を残さない)。
+#
+# 共通の取得本体。exit code の扱いだけを引数 require_zero で切り替える。
+_run_acquire() {
+    local label="$1" out="$2" require_zero="$3"; shift 3
+    echo ">>> ${label}"
+    # stderr は捨てない (取得失敗の原因をログに残す)。
+    # ログは **取得ごとに truncate** する (> であって >> ではない)。追記にすると
+    # composer 失敗時に pnpm の古い stderr が混ざって原因が読めなくなる。
+    local code=0
+    "$@" > "${out}" 2>"${STDERR_LOG}" || code=$?
+    if [[ ! -s "${out}" ]]; then
+        echo "::error::audit-gate: ${label} produced no output (exit ${code}). refusing to treat this as 'no advisories'." >&2
+        sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
+        exit 1
+    fi
+    if [[ "${require_zero}" == "yes" && "${code}" -ne 0 ]]; then
+        echo "::error::audit-gate: ${label} failed (exit ${code}). its non-zero exit always means failure, never 'findings'." >&2
+        sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
+        exit 1
+    fi
+    # 取得は成功した。stderr は診断用に流しておく (警告等)。
+    [[ -s "${STDERR_LOG}" ]] && sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
+}
+
+# audit ツール用: 非空出力を要求し、**非ゼロ exit は許容**する
+# (非ゼロ = 脆弱性検出という正常系がありうるため。exit code では取得失敗と区別できない)。
+acquire_audit()    { _run_acquire "$1" "$2" no  "${@:3}"; }
 
-# audit 自体の exit code は無視 (脆弱性検出で非ゼロを返すため)。judging は audit-gate.ts。
-# 取得失敗 (network 不通等) で空 JSON になった場合も gate は走る (advisory 0 件扱い)。
-echo ">>> pnpm audit --json"
-pnpm audit --json --audit-level=moderate > "$PNPM_JSON" 2>/dev/null || true
-echo ">>> composer audit --format=json"
-composer audit --format=json > "$COMPOSER_JSON" 2>/dev/null || true
+# 非 audit の前処理用: 非空出力 **かつ exit 0** を要求する。
+# `uv export` の非ゼロには「検出した」という意味が無く、**常に失敗**である。
+# 共通ハンドラで済ませると「部分的な / コメントだけの非空出力を残して失敗」したときに
+# そのまま pip-audit へ進み、痩せた requirements に対する「advisory 0 件」で緑になる。
+acquire_required() { _run_acquire "$1" "$2" yes "${@:3}"; }
 
-# 空ファイル (audit が何も出力しなかった) は最小 JSON で補完し parse 失敗を防ぐ。
-[[ -s "$PNPM_JSON" ]] || echo '{"advisories":{}}' > "$PNPM_JSON"
-[[ -s "$COMPOSER_JSON" ]] || echo '{"advisories":{}}' > "$COMPOSER_JSON"
+acquire_audit "pnpm audit --json"            "$PNPM_JSON"     pnpm audit --json --audit-level=moderate
+acquire_audit "composer audit --format=json" "$COMPOSER_JSON" composer audit --format=json
 
 # PyPI 判定は pyproject.toml があるリポジトリでのみ有効化する (テンプレート初期状態では skip)。
 if [[ -f pyproject.toml ]]; then
-    echo ">>> pip-audit --format=json (pyproject.toml detected)"
     PIP_JSON="$(mktemp)"
     REQ_TXT="$(mktemp)"
-    uv export --format=requirements-txt --no-hashes --no-dev > "$REQ_TXT" 2>/dev/null || true
-    uv tool run --from "pip-audit==2.7.3" pip-audit --format=json --requirement "$REQ_TXT" > "$PIP_JSON" 2>/dev/null || true
-    [[ -s "$PIP_JSON" ]] || echo '{"dependencies":[]}' > "$PIP_JSON"
+    acquire_required "uv export (requirements)" "$REQ_TXT"  uv export --format=requirements-txt --no-hashes --no-dev
+    acquire_audit    "pip-audit --format=json"  "$PIP_JSON" uv tool run --from "pip-audit==2.7.3" pip-audit --format=json --requirement "$REQ_TXT"
 fi
 
 echo ">>> audit-gate judging"
diff --git a/scripts/audit-gate.test.ts b/scripts/audit-gate.test.ts
index d551118..29f3096 100644
--- a/scripts/audit-gate.test.ts
+++ b/scripts/audit-gate.test.ts
@@ -9,6 +9,7 @@ import { tmpdir } from "node:os";
 import { join } from "node:path";
 import {
     AcceptedAdvisorySchema,
+    NORMALIZERS,
     daysBetween,
     evaluate,
     loadAuditJson,
@@ -17,6 +18,7 @@ import {
     normalizePipAudit,
     normalizePnpmAudit,
     todayIsoJst,
+    type AuditSource,
     type NormalizedAdvisory,
 } from "./audit-gate";
 
@@ -338,7 +340,7 @@ describe("audit-gate JSON parse failure", () => {
         const tmp = join(dir, "invalid-json.json");
         writeFileSync(tmp, "{ not valid json");
         try {
-            expect(() => loadAuditJson(tmp, normalizePnpmAudit)).toThrow(/JSON parse failure/);
+            expect(() => loadAuditJson(tmp, "pnpm-audit")).toThrow(/JSON parse failure/);
         } finally {
             unlinkSync(tmp);
         }
@@ -360,3 +362,97 @@ describe("audit-gate utility helpers", () => {
         expect(todayIsoJst(new Date("2026-04-30T15:00:00Z"))).toBe("2026-05-01");
     });
 });
+
+// ============================================================================
+// 施策 4A: shape 検証 (取得異常を「advisory 0 件 = 緑」へ黙って落とさないこと)
+//
+// **loadAuditJson 経由**でテストする。assertAuditSourceShape を単体で呼ぶだけだと、
+// 実装者が export しただけで loadAuditJson から呼び忘れても検出できない (配線の空振り)。
+// ============================================================================
+
+describe("loadAuditJson の shape 検証 (fail-closed)", () => {
+    /** 一時ファイルへ内容を書いて loadAuditJson を呼ぶ (配線まで含めて検証する)。 */
+    function load(source: AuditSource, contents: string): NormalizedAdvisory[] {
+        const dir = mkdtempSync(join(tmpdir(), "audit-gate-shape-"));
+        const tmp = join(dir, "audit.json");
+        writeFileSync(tmp, contents);
+        try {
+            return loadAuditJson(tmp, source);
+        } finally {
+            unlinkSync(tmp);
+        }
+    }
+
+    it("不正 JSON は throw する", () => {
+        expect(() => load("pnpm-audit", "not json")).toThrow(/JSON parse failure/);
+    });
+
+    it("pnpm: ネットワークエラー形 {error:{...}} は throw する (shape 黙殺の穴が塞がった証明)", () => {
+        expect(() => load("pnpm-audit", JSON.stringify({ error: { code: "ENETUNREACH" } })))
+            .toThrow(/missing 'advisories'/);
+    });
+
+    it("composer: 空配列 {advisories: []} は throw しない (composer の正当な 0 件表現)", () => {
+        // 実測: composer audit --format=json は advisory 0 件のとき
+        // `{"advisories":[],"abandoned":[],"filter":[]}` を出す (PHP の空配列由来)。
+        // ここを弾くと「全部解消した正常状態」が恒久的に赤くなる (偽赤)。
+        expect(load("composer-audit", JSON.stringify({ advisories: [] }))).toEqual([]);
+    });
+
+    it("composer: 非空配列は throw する (黙って 0 件へ落ちる偽グリーン経路)", () => {
+        // composer は非 0 件を必ず package キーの object で出す。非空配列は schema 不一致であり、
+        // normalizeComposerAudit の Object.entries が index キーで走査して黙って 0 件になる。
+        expect(() => load("composer-audit", JSON.stringify({ advisories: [{ advisoryId: "X" }] })))
+            .toThrow(/must be an object when non-empty/);
+    });
+
+    it("pnpm: {advisories: []} は throw しない (pnpm は array 形も正当)", () => {
+        expect(load("pnpm-audit", JSON.stringify({ advisories: [] }))).toEqual([]);
+    });
+
+    it("pnpm / composer: {advisories: {}} は throw しない (真の 0 件は緑)", () => {
+        expect(load("pnpm-audit", JSON.stringify({ advisories: {} }))).toEqual([]);
+        expect(load("composer-audit", JSON.stringify({ advisories: {} }))).toEqual([]);
+    });
+
+    it("top-level 配列は throw する", () => {
+        for (const source of ["pnpm-audit", "composer-audit", "pip-audit"] as const) {
+            expect(() => load(source, "[]")).toThrow(/expected a JSON object at top level/);
+        }
+    });
+
+    it("composer: advisories の値が array でないと throw する (内部 schema 不整合)", () => {
+        expect(() =>
+            load("composer-audit", JSON.stringify({ advisories: { "vendor/pkg": { error: "unavailable" } } })),
+        ).toThrow(/advisories\["vendor\/pkg"\] must be an array/);
+    });
+
+    it("pnpm: primitive / null の entry は throw する", () => {
+        expect(() => load("pnpm-audit", JSON.stringify({ advisories: [null] })))
+            .toThrow(/advisories\[0\] must be an object/);
+        expect(() => load("pnpm-audit", JSON.stringify({ advisories: ["x"] })))
+            .toThrow(/advisories\[0\] must be an object/);
+    });
+
+    it("pip: {dependencies: []} は throw しない", () => {
+        expect(load("pip-audit", JSON.stringify({ dependencies: [] }))).toEqual([]);
+    });
+
+    it("pip: name 欠落の dependency は throw する", () => {
+        expect(() => load("pip-audit", JSON.stringify({ dependencies: [{}] })))
+            .toThrow(/dependencies\[0\]\.name must be a string/);
+    });
+
+    it("pip: 空 vulns は正当な 0 件として通す", () => {
+        expect(load("pip-audit", JSON.stringify({ dependencies: [{ name: "x", vulns: [] }] }))).toEqual([]);
+    });
+
+    it("pip: dependencies 欠落は throw する", () => {
+        expect(() => load("pip-audit", "{}")).toThrow(/missing 'dependencies' array/);
+    });
+
+    it("NORMALIZERS が全 AuditSource を網羅している (誤配線を型と assertion で固定)", () => {
+        const sources: AuditSource[] = ["pnpm-audit", "composer-audit", "pip-audit"];
+        expect(Object.keys(NORMALIZERS).sort()).toEqual([...sources].sort());
+    });
+});
diff --git a/scripts/audit-gate.ts b/scripts/audit-gate.ts
index efb77d0..cc2085d 100644
--- a/scripts/audit-gate.ts
+++ b/scripts/audit-gate.ts
@@ -165,10 +165,124 @@ export function daysBetween(fromIso: string, toIso: string): number {
 // Loaders
 // ============================================================================
 
-export function loadAuditJson(
-    path: string,
-    normalizer: (json: unknown) => NormalizedAdvisory[],
-): NormalizedAdvisory[] {
+/** audit 入力 1 件分の由来。エラーメッセージと shape 期待値を決める。 */
+export type AuditSource = "pnpm-audit" | "composer-audit" | "pip-audit";
+
+/**
+ * audit JSON が **その ecosystem の期待 schema を持つ**ことを検証する (純関数)。
+ *
+ * 目的は「valid JSON だが中身が違う」を 0 件へ黙って落とさないこと。
+ * 例: ネットワークエラーで `{"error":{...}}` が返ると、normalizer は
+ * `if (!obj.advisories) return []` により **advisory 0 件 = 緑** に落ちる。
+ * blocking gate ではこれが偽グリーンになるため、ここで fail-closed に止める。
+ *
+ * 検証するのは **normalizer が走査に使う最小構造** まで。未知フィールドは許容し、
+ * 空コンテナ (`{}` / `[]` / 空 `vulns`) は **正当な 0 件** として通す。
+ *
+ * top-level だけを見る設計にしないのは、内部が壊れた JSON も 0 件へ落ちるため。例:
+ *   `{"advisories":{"vendor/pkg":{"error":"unavailable"}}}`
+ *   → normalizeComposerAudit は `Array.isArray(advisoriesUnknown)` が false のとき
+ *     `[]` を使うので **黙って 0 件**になる。取得異常を「安全」と読み替えてしまう。
+ *
+ * @throws Error 期待 schema を満たさない場合
+ */
+export function assertAuditSourceShape(source: AuditSource, json: unknown): void {
+    if (!json || typeof json !== "object" || Array.isArray(json)) {
+        throw new Error(`${source}: expected a JSON object at top level`);
+    }
+    const obj = json as Record<string, unknown>;
+    const keys = Object.keys(obj).join(", ");
+
+    // **source ごとに期待コンテナの型を変える**。
+    // 共通条件 (`typeof === "object"`) にすると composer で `{"advisories": []}` が通り、
+    // normalizeComposerAudit の `typeof obj.advisories !== "object"` も配列を弾かないため
+    // Object.entries([]) = [] で **advisory 0 件 = 緑** に落ちる (偽グリーン)。
+    switch (source) {
+        case "pnpm-audit": {
+            // pnpm/npm audit は形式によって object (キー = advisory id) と array の両方を返す。
+            // normalizePnpmAudit が両対応しているので、ここも両方を受理する。
+            const c = obj.advisories;
+            if (c === undefined || c === null || typeof c !== "object") {
+                throw new Error(`pnpm-audit: missing 'advisories' object or array (got keys: ${keys})`);
+            }
+            // normalizePnpmAudit は各 entry を `Record<string, unknown>` として読む。
+            // primitive / null の entry は黙って id="" package="" の advisory になるため弾く。
+            const entries = Array.isArray(c) ? c : Object.values(c as Record<string, unknown>);
+            for (const [i, e] of entries.entries()) {
+                if (!e || typeof e !== "object" || Array.isArray(e)) {
+                    throw new Error(`pnpm-audit: advisories[${i}] must be an object (got ${typeof e})`);
+                }
+            }
+            return;
+        }
+        case "composer-audit": {
+            // composer audit は findings があるとき `{"advisories": {"<package>": [...]}}` の object。
+            //
+            // ただし **0 件のときだけ `[]` を出す** — PHP の空配列が json_encode で `[]` に
+            // なるためで、これは composer の正当な「advisory なし」表現である (実測)。
+            // 設計は当初「配列なら一律 fail」としていたが、それでは advisory を全て解消した
+            // 正常状態が恒久的に赤くなる (偽赤)。設計意図は「非 0 件の中身が黙って 0 件へ
+            // 落ちる経路を塞ぐ」ことなので、**空配列だけを許容し、非空配列は拒否**する。
+            // 非空配列は composer が出さない形であり、normalizeComposerAudit の
+            // Object.entries([...]) が index キーで走査して黙って 0 件になる偽グリーン経路。
+            const c = obj.advisories;
+            if (c === undefined || c === null || typeof c !== "object") {
+                throw new Error(`composer-audit: missing 'advisories' object (got keys: ${keys})`);
+            }
+            if (Array.isArray(c)) {
+                if (c.length > 0) {
+                    throw new Error(
+                        `composer-audit: 'advisories' must be an object when non-empty (got a ${c.length}-element array)`,
+                    );
+                }
+                return; // 空配列 = composer の正当な 0 件表現
+            }
+            // normalizeComposerAudit は package ごとの値が array でなければ **黙って空扱い** にする。
+            // 空の object {} (= 0 件) は正当だが、値が array でないのは schema 不一致なので弾く。
+            for (const [pkg, v] of Object.entries(c as Record<string, unknown>)) {
+                if (!Array.isArray(v)) {
+                    throw new Error(`composer-audit: advisories["${pkg}"] must be an array (got ${typeof v})`);
+                }
+            }
+            return;
+        }
+        case "pip-audit": {
+            if (!Array.isArray(obj.dependencies)) {
+                throw new Error(`pip-audit: missing 'dependencies' array (got keys: ${keys})`);
+            }
+            // normalizePipAudit は name / vulns を読む。空 vulns は正当な 0 件。
+            for (const [i, d] of obj.dependencies.entries()) {
+                if (!d || typeof d !== "object" || Array.isArray(d)) {
+                    throw new Error(`pip-audit: dependencies[${i}] must be an object`);
+                }
+                const dep = d as Record<string, unknown>;
+                if (typeof dep.name !== "string") {
+                    throw new Error(`pip-audit: dependencies[${i}].name must be a string`);
+                }
+                if (!Array.isArray(dep.vulns)) {
+                    throw new Error(`pip-audit: dependencies[${i}].vulns must be an array`);
+                }
+            }
+            return;
+        }
+    }
+}
+
+/**
+ * source => normalizer の対応表。
+ *
+ * pnpm と composer はどちらも object 形式の `advisories` を持ちうるため、
+ * shape 検査だけでは normalizer の取り違えを常に検出できない。
+ * `source` と `normalizer` を別々の引数で渡す限り、誤った組み合わせが型として書けてしまう。
+ * そこで source から normalizer を **内部で選択** し、誤配線そのものを表現不能にする。
+ */
+export const NORMALIZERS: Record<AuditSource, (json: unknown) => NormalizedAdvisory[]> = {
+    "pnpm-audit": normalizePnpmAudit,
+    "composer-audit": normalizeComposerAudit,
+    "pip-audit": normalizePipAudit,
+};
+
+export function loadAuditJson(path: string, source: AuditSource): NormalizedAdvisory[] {
     const raw = readFileSync(path, "utf-8");
     let json: unknown;
     try {
@@ -176,7 +290,8 @@ export function loadAuditJson(
     } catch (e) {
         throw new Error(`JSON parse failure in ${path}: ${(e as Error).message}`);
     }
-    return normalizer(json);
+    assertAuditSourceShape(source, json); // ← 配線点。ここを消すと unit テストが落ちる
+    return NORMALIZERS[source](json);
 }
 
 export function loadAcceptedAdvisories(path: string): AcceptedAdvisory[] {
@@ -490,9 +605,9 @@ async function main(): Promise<void> {
     let accepted: AcceptedAdvisory[];
     try {
         advisories = [
-            ...loadAuditJson(pnpmPath, normalizePnpmAudit),
-            ...loadAuditJson(composerPath, normalizeComposerAudit),
-            ...(pipPath ? loadAuditJson(pipPath, normalizePipAudit) : []),
+            ...loadAuditJson(pnpmPath, "pnpm-audit"),
+            ...loadAuditJson(composerPath, "composer-audit"),
+            ...(pipPath ? loadAuditJson(pipPath, "pip-audit") : []),
         ];
         accepted = loadAcceptedAdvisories(acceptedPath);
     } catch (e) {
diff --git a/scripts/ci/make-shard-phpunit.php b/scripts/ci/make-shard-phpunit.php
deleted file mode 100644
index a8580be..0000000
--- a/scripts/ci/make-shard-phpunit.php
+++ /dev/null
@@ -1,127 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-/*
- * scripts/ci/make-shard-phpunit.php — GitHub Actions の matrix sharding 用に、
- * phpunit.xml をベースに「このシャードが担当するテストファイルだけ」を
- * <testsuites> に持つ phpunit 設定を生成する。
- *
- * 使い方:
- *   php scripts/ci/make-shard-phpunit.php <shardIndex> <shardTotal> <outPath>
- *     shardIndex : 1..shardTotal
- *     shardTotal : 総シャード数
- *     outPath    : 出力先 (プロジェクトルートに置くこと。bootstrap="tests/bootstrap.php"
- *                  が設定ファイルからの相対で解決されるため。既定の生成物
- *                  phpunit.ci-shard.xml は .gitignore 済み)
- *
- * 方針:
- *   - phpunit.xml をそのまま DOMDocument で読み、<php> / <source> / bootstrap 属性等は
- *     一切いじらず継承する (= <server force> による DB force / bootstrap の
- *     `<slug>_test_<hash>` 後勝ち上書き + fail-closed ガードを drift させない)。
- *   - 既存 <testsuites> の <directory> 配下の *Test.php を収集し、ファイル名 sort 後に
- *     interleave (index % total) でシャードへ割り当てる。遅いテストが偏らないよう
- *     round-robin で散らす。
- *   - <testsuites> の中身を、選ばれたファイルだけを <file> で列挙する単一 testsuite に置換。
- *   - 自 shard の割当ファイル数が 0 件なら exit 2 (テスト 0 件で緑になる偽グリーン防止)。
- */
-
-if ($argc < 4) {
-    fwrite(STDERR, "usage: php make-shard-phpunit.php <shardIndex> <shardTotal> <outPath>\n");
-    exit(2);
-}
-
-$shardIndex = (int) $argv[1];
-$shardTotal = (int) $argv[2];
-$outPath = $argv[3];
-
-if ($shardIndex < 1 || $shardTotal < 1 || $shardIndex > $shardTotal) {
-    fwrite(STDERR, "invalid shard: {$shardIndex}/{$shardTotal}\n");
-    exit(2);
-}
-
-$root = dirname(__DIR__, 2);
-$src = $root.'/phpunit.xml';
-
-$dom = new DOMDocument;
-$dom->preserveWhiteSpace = false;
-$dom->formatOutput = true;
-if (! $dom->load($src)) {
-    fwrite(STDERR, "failed to load {$src}\n");
-    exit(1);
-}
-
-$xpath = new DOMXPath($dom);
-
-/** @var DOMElement|null $testsuites */
-$testsuites = $xpath->query('//testsuites')->item(0);
-if (! $testsuites instanceof DOMElement) {
-    fwrite(STDERR, "no <testsuites> in phpunit.xml\n");
-    exit(1);
-}
-
-// <testsuites>/<testsuite>/<directory> を収集 (Unit / Feature / Architecture)。
-$dirs = [];
-foreach ($xpath->query('//testsuites/testsuite') as $suiteNode) {
-    if (! $suiteNode instanceof DOMElement) {
-        continue;
-    }
-    foreach ($suiteNode->getElementsByTagName('directory') as $d) {
-        $dirs[] = trim($d->textContent);
-    }
-}
-
-// 各ディレクトリ配下の *Test.php を収集。
-$files = [];
-foreach ($dirs as $dir) {
-    $abs = $root.'/'.$dir;
-    if (! is_dir($abs)) {
-        continue;
-    }
-    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
-    foreach ($it as $f) {
-        /** @var SplFileInfo $f */
-        if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
-            // phpunit 設定からの相対パス (= ルート相対) で記録。
-            $files[] = ltrim(str_replace($root, '', $f->getPathname()), '/');
-        }
-    }
-}
-
-sort($files); // 決定的順序
-
-// interleave 割り当て: index % total == shardIndex-1
-$mine = [];
-foreach ($files as $i => $file) {
-    if ($i % $shardTotal === $shardIndex - 1) {
-        $mine[] = $file;
-    }
-}
-
-// 偽グリーン防止: 0 件は gate を無効化する。
-if (count($mine) === 0) {
-    fwrite(STDERR, sprintf(
-        "shard %d/%d collected 0 files. refusing to emit empty gate.\n",
-        $shardIndex, $shardTotal
-    ));
-    exit(2);
-}
-
-// <testsuites> の中身を置換。
-while ($testsuites->firstChild) {
-    $testsuites->removeChild($testsuites->firstChild);
-}
-$suite = $dom->createElement('testsuite');
-$suite->setAttribute('name', "CIShard-{$shardIndex}-of-{$shardTotal}");
-foreach ($mine as $file) {
-    $fileEl = $dom->createElement('file', $file);
-    $suite->appendChild($fileEl);
-}
-$testsuites->appendChild($suite);
-
-if ($dom->save($outPath) === false) {
-    fwrite(STDERR, "failed to write {$outPath}\n");
-    exit(1);
-}
-
-fwrite(STDERR, sprintf("shard %d/%d: %d test files -> %s\n", $shardIndex, $shardTotal, count($mine), $outPath));
diff --git a/scripts/run-browser-test.contract.test.ts b/scripts/run-browser-test.contract.test.ts
new file mode 100644
index 0000000..96a9b31
--- /dev/null
+++ b/scripts/run-browser-test.contract.test.ts
@@ -0,0 +1,391 @@
+/**
+ * scripts/run-browser-test.sh の契約テスト。
+ *
+ * Browser lane は「Chromium / WebKit の 2 レーンを、失敗レーンがあっても両方走らせ、
+ * 最後に非ゼロで終わる」ことが契約である (T082 / AGENTS.md ドメイン規約 /
+ * docs/supported-browsers.md)。この契約はスクリプトを 1 行編集するだけで壊せるため、
+ * 実プロセスで振る舞いを固定する。
+ *
+ * 2 層構成:
+ *   層 1 (sandbox 実走): C1〜C4, C8 — mkdtemp に最小の repo 骨格を組み、pest / php を
+ *         スタブへ差し替えて実スクリプトを走らせる。
+ *   層 2 (静的契約):    C5〜C7 — orphan 掃除の振る舞いは「PPID が 1 に reparent する」
+ *         という subreaper 依存の前提を要するため、実プロセス化すると偽赤を生む。
+ *         守りたいのは「掃除ロジックが消される / bug-hunt 除外が消される / EXIT trap の
+ *         所有権が奪われる」という編集による退行なので静的検査で足りる
+ *         (tests/Architecture/GlobalTestLockInventoryTest.php と同方針)。
+ *
+ * GLOBAL_TEST_LOCK_DIR の使用について: これは scripts/global-test-lock.sh が
+ * 「self-test only」として明示サポートする override であり、本テスト自身が
+ * `pnpm test` のグローバルロックを保持したまま走っても自己デッドロックしないために使う。
+ * GlobalTestLockInventoryTest が禁じているのは **lane スクリプトが自分で設定すること**
+ * であって、テストハーネスが env で渡すことは対象外である。
+ *
+ * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
+ */
+import { describe, expect, it } from "vitest";
+import { spawnSync } from "node:child_process";
+import {
+    chmodSync,
+    copyFileSync,
+    existsSync,
+    mkdirSync,
+    mkdtempSync,
+    readFileSync,
+    rmSync,
+    writeFileSync,
+} from "node:fs";
+import { tmpdir } from "node:os";
+import { dirname, join, resolve } from "node:path";
+
+const REPO_ROOT = process.cwd();
+const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/run-browser-test.sh");
+const LOCK_LIB_PATH = resolve(REPO_ROOT, "scripts/global-test-lock.sh");
+
+/** 実ソース (verbatim)。層 2 の正のコントロールと層 1 の sandbox 元になる。 */
+function realSource(): string {
+    return readFileSync(SCRIPT_PATH, "utf-8");
+}
+
+// --------------------------------------------------------------------------
+// 層 2: 静的契約 (C5, C6, C7 + 既定値リテラル)
+// --------------------------------------------------------------------------
+
+/**
+ * 行頭 (空白除く) が `#` の行を落とす。方針説明コメントで偽赤にしないため
+ * (tests/Architecture/GlobalTestLockInventoryTest.php の globalTestLockCodeLines と同方針)。
+ */
+export function codeLines(source: string): string {
+    return source
+        .split("\n")
+        .filter((line) => !/^\s*#/.test(line))
+        .join("\n");
+}
+
+/**
+ * 静的契約の違反を列挙する純関数。
+ * fixture 文字列を渡せるようにして、負のコントロール (検出器が空振りしていないこと) を
+ * 同じ層で確認できるようにする。
+ */
+export function staticContractViolations(source: string): string[] {
+    const code = codeLines(source);
+    const violations: string[] = [];
+
+    // C5: orphan (PPID==1) の playwright run-server を掃除する
+    if (!code.includes('pgrep -f "playwright/cli.js run-server"')) {
+        violations.push('C5: pgrep -f "playwright/cli.js run-server" が消えている');
+    }
+    if (!/\bppid\b[^\n]*=\s*"1"|"\$\{ppid\}"\s*=\s*"1"/.test(code)) {
+        violations.push("C5: PPID==1 の判定が消えている");
+    }
+
+    // C6: bug-hunt (@playwright/cli) は掃除しない
+    if (!code.includes('*"@playwright/"*) continue ;;')) {
+        violations.push("C6: @playwright/ の除外が消えている (bug-hunt を巻き込む)");
+    }
+
+    // C7: EXIT trap の所有者はライブラリ 1 箇所
+    if (!code.includes("global_test_lock_on_exit cleanup_orphan_playwright")) {
+        violations.push("C7: global_test_lock_on_exit への登録が消えている");
+    }
+    if (/\btrap\b[^\n]*\bEXIT\b/.test(code)) {
+        violations.push("C7: 自前の trap ... EXIT が張られている (ロックが解放されなくなる)");
+    }
+
+    // 既定値リテラル (層 1 の振る舞い検査と二重化する保険)
+    if (!code.includes("${BROWSER_TEST_PROCESSES:-1}")) {
+        violations.push("既定並列度が 1 でない (BROWSER_TEST_PROCESSES:-1 が消えている)");
+    }
+    if (!code.includes("${BROWSER_TEST_LANES:-chromium webkit}")) {
+        violations.push("既定レーンが chromium webkit でない");
+    }
+
+    return violations;
+}
+
+// --------------------------------------------------------------------------
+// 層 1: sandbox 実走
+// --------------------------------------------------------------------------
+
+/** 元ソースの `from` を `to` に 1 箇所だけ置換する。置換が成立しなければ throw (空振り防止)。 */
+export function mutate(source: string, from: string, to: string): string {
+    const occurrences = source.split(from).length - 1;
+    if (occurrences !== 1) {
+        throw new Error(`mutation target must appear exactly once (found ${occurrences}): ${from}`);
+    }
+    const mutated = source.replace(from, to);
+    if (mutated === source) throw new Error(`mutation did not change the source: ${from}`);
+    if (!mutated.includes(to)) throw new Error(`mutated source lacks the expected token: ${to}`);
+    return mutated;
+}
+
+interface SandboxRun {
+    /** スクリプトの終了コード。 */
+    status: number;
+    /** スタブ pest が記録した呼び出し (1 行 = 1 レーン、argv の JSON 配列)。 */
+    pestCalls: string[][];
+    stderr: string;
+}
+
+function writeExecutable(path: string, content: string): void {
+    mkdirSync(dirname(path), { recursive: true });
+    writeFileSync(path, content, "utf-8");
+    chmodSync(path, 0o755);
+}
+
+/**
+ * bug-hunt 併走の pre-flight guard (127.0.0.1:8010..8018) と同じ検査をテスト側でも行う。
+ * listen していたら **明示メッセージで fail** させる (silent skip にしない =
+ * 「担保されていない」を隠さない)。docs/testing-browser.md が併走を既に非推奨としている。
+ */
+function assertNoBughuntPorts(): void {
+    for (let port = 8010; port <= 8018; port += 1) {
+        const probe = spawnSync(
+            "bash",
+            ["-c", `(exec 3<>/dev/tcp/127.0.0.1/${port}) 2>/dev/null`],
+            { encoding: "utf-8" },
+        );
+        if (probe.status === 0) {
+            throw new Error(
+                `bug-hunt 環境が 127.0.0.1:${port} で listen 中のため、run-browser-test.sh の ` +
+                    "pre-flight guard が発火して契約テストを実行できません " +
+                    "(scripts/bug-hunt-shard.sh teardown で停止してから再実行してください)。",
+            );
+        }
+    }
+}
+
+/**
+ * sandbox に最小の repo 骨格を組み、渡された script source を実行する。
+ *
+ * @param scriptSource   実行する run-browser-test.sh の内容 (verbatim または改変コピー)
+ * @param failingLanes   何レーン目 (1 始まり) を exit 1 にするか
+ * @param env            追加の環境変数
+ */
+function runInSandbox(
+    scriptSource: string,
+    options: { failingLanes?: number[]; env?: Record<string, string> } = {},
+): SandboxRun {
+    assertNoBughuntPorts();
+
+    const sandbox = mkdtempSync(join(tmpdir(), "run-browser-test-contract-"));
+    try {
+        mkdirSync(join(sandbox, "scripts/ci"), { recursive: true });
+        mkdirSync(join(sandbox, "vendor/bin"), { recursive: true });
+        mkdirSync(join(sandbox, "bin"), { recursive: true });
+
+        writeFileSync(join(sandbox, "scripts/run-browser-test.sh"), scriptSource, "utf-8");
+        chmodSync(join(sandbox, "scripts/run-browser-test.sh"), 0o755);
+        // ライブラリは実ファイルをコピー (ロック取得の実挙動をそのまま使う)
+        copyFileSync(LOCK_LIB_PATH, join(sandbox, "scripts/global-test-lock.sh"));
+
+        // php 自体をスタブ化するので中身は不要
+        writeFileSync(join(sandbox, "scripts/ci/ensure-test-db.php"), "<?php\n", "utf-8");
+        writeFileSync(join(sandbox, "artisan"), "<?php\n", "utf-8");
+        writeFileSync(join(sandbox, "phpunit.browser.xml"), "<phpunit/>\n", "utf-8");
+
+        // php スタブ: 何もせず成功する。
+        //
+        // **短い sleep を入れるのは必須**: global_test_lock_run は起動直後の子を
+        // `ps -o pgid= -p "$pid"` で probe して専用プロセスグループを確認する。
+        // 子が sub-millisecond で終わると ps が「そんな pid は無い」で非ゼロを返し、
+        // `set -euo pipefail` 下の代入が失敗してレーンごと落ちる。実運用の
+        // `php artisan config:clear` / `vendor/bin/pest` はミリ秒では終わらないため
+        // 顕在化しないが、スタブは現実の所要時間を最低限模す必要がある。
+        writeExecutable(join(sandbox, "bin/php"), "#!/usr/bin/env bash\nsleep 0.1\nexit 0\n");
+
+        const callsPath = join(sandbox, "pest-calls.jsonl");
+        const failing = options.failingLanes ?? [];
+        // pest スタブ: argv を JSONL で追記し、指定回目の呼び出しだけ exit 1 する
+        writeExecutable(
+            join(sandbox, "vendor/bin/pest"),
+            [
+                "#!/usr/bin/env bash",
+                "set -u",
+                // bin/php と同じ理由 (global_test_lock_run の ps probe との race 回避)
+                "sleep 0.1",
+                `CALLS="${callsPath}"`,
+                // argv を JSON 配列へ (jq に依存しない素朴なエスケープ: 実引数に " や \\ は現れない)
+                'out="["',
+                'first=1',
+                'for a in "$@"; do',
+                '  if [ "$first" = "1" ]; then first=0; else out="${out},"; fi',
+                '  esc="${a//\\\\/\\\\\\\\}"',
+                '  esc="${esc//\\"/\\\\\\"}"',
+                '  out="${out}\\"${esc}\\""',
+                "done",
+                'out="${out}]"',
+                'printf "%s\\n" "$out" >> "$CALLS"',
+                'n=$(wc -l < "$CALLS" | tr -d " ")',
+                `for f in ${failing.join(" ") || "''"}; do`,
+                '  [ -n "$f" ] && [ "$n" = "$f" ] && exit 1',
+                "done",
+                "exit 0",
+            ].join("\n"),
+        );
+
+        const result = spawnSync("bash", [join(sandbox, "scripts/run-browser-test.sh")], {
+            encoding: "utf-8",
+            env: {
+                ...process.env,
+                PATH: `${join(sandbox, "bin")}:${process.env.PATH ?? ""}`,
+                // ライブラリが self-test 用として明示サポートする override。
+                // 本テストが pnpm test のロック下で走っても自己デッドロックしないために必要。
+                GLOBAL_TEST_LOCK_DIR: join(sandbox, "lockdir"),
+                ...(options.env ?? {}),
+            },
+        });
+
+        const pestCalls = existsSync(callsPath)
+            ? readFileSync(callsPath, "utf-8")
+                  .split("\n")
+                  .filter((l) => l.trim() !== "")
+                  .map((l) => JSON.parse(l) as string[])
+            : [];
+
+        return { status: result.status ?? -1, pestCalls, stderr: result.stderr ?? "" };
+    } finally {
+        rmSync(sandbox, { recursive: true, force: true });
+    }
+}
+
+// --------------------------------------------------------------------------
+
+describe("run-browser-test.sh 層 2: 静的契約 (C5, C6, C7)", () => {
+    it("現行の実ソースは違反 0 件 (正のコントロール)", () => {
+        expect(staticContractViolations(realSource())).toEqual([]);
+    });
+
+    it("C6: @playwright/ 除外を削ると違反を返す", () => {
+        const broken = mutate(
+            realSource(),
+            '            *"@playwright/"*) continue ;;   # bug-hunt の @playwright/cli は触らない\n',
+            "",
+        );
+        expect(staticContractViolations(broken)).toContain(
+            "C6: @playwright/ の除外が消えている (bug-hunt を巻き込む)",
+        );
+    });
+
+    it("C7: 自前 trap ... EXIT に戻すと違反を返す", () => {
+        const broken = mutate(
+            realSource(),
+            "global_test_lock_on_exit cleanup_orphan_playwright",
+            "trap cleanup_orphan_playwright EXIT",
+        );
+        const violations = staticContractViolations(broken);
+        expect(violations).toContain("C7: global_test_lock_on_exit への登録が消えている");
+        expect(violations).toContain(
+            "C7: 自前の trap ... EXIT が張られている (ロックが解放されなくなる)",
+        );
+    });
+
+    it("C5: pgrep の掃除を削ると違反を返す", () => {
+        const broken = mutate(
+            realSource(),
+            'pgrep -f "playwright/cli.js run-server"',
+            'pgrep -f "nothing-to-clean"',
+        );
+        expect(staticContractViolations(broken)).toContain(
+            'C5: pgrep -f "playwright/cli.js run-server" が消えている',
+        );
+    });
+
+    it("既定レーンを chromium だけに狭めると違反を返す", () => {
+        const broken = mutate(
+            realSource(),
+            '${BROWSER_TEST_LANES:-chromium webkit}',
+            '${BROWSER_TEST_LANES:-chromium}',
+        );
+        expect(staticContractViolations(broken)).toContain(
+            "既定レーンが chromium webkit でない",
+        );
+    });
+});
+
+describe("run-browser-test.sh 層 1: sandbox 実走 (C1〜C4, C8)", () => {
+    it("C1/C4: 既定で chrome → safari の 2 レーンを直列に走らせる", { timeout: 30_000 }, () => {
+        const run = runInSandbox(realSource());
+
+        expect(run.status).toBe(0);
+        expect(run.pestCalls).toHaveLength(2);
+        // C1: レーン名の写像と順序
+        expect(run.pestCalls[0]).toContain("--browser");
+        expect(run.pestCalls[0][run.pestCalls[0].indexOf("--browser") + 1]).toBe("chrome");
+        expect(run.pestCalls[1][run.pestCalls[1].indexOf("--browser") + 1]).toBe("safari");
+        // C1: browser 用の phpunit 設定を使う
+        for (const call of run.pestCalls) {
+            expect(call).toContain("-c");
+            expect(call).toContain("phpunit.browser.xml");
+            // C4: 既定は直列 = parallel runner を使わない
+            expect(call).not.toContain("--parallel");
+            expect(call.some((a) => a.startsWith("--processes"))).toBe(false);
+        }
+    });
+
+    it(
+        "C2/C3: 先頭レーンが失敗しても後続レーンを実行し、overall は非ゼロ",
+        { timeout: 30_000 },
+        () => {
+            const run = runInSandbox(realSource(), { failingLanes: [1] });
+
+            expect(run.pestCalls).toHaveLength(2); // C2: webkit が飛ばされていない
+            expect(run.status).not.toBe(0); // C3
+        },
+    );
+
+    it("C8: 未知のレーン名は exit 2", { timeout: 30_000 }, () => {
+        // "chrome" は playwright 側の名前であってレーン名ではない
+        const run = runInSandbox(realSource(), { env: { BROWSER_TEST_LANES: "chrome" } });
+
+        expect(run.status).toBe(2);
+        expect(run.pestCalls).toHaveLength(0);
+    });
+});
+
+describe("run-browser-test.sh 層 1 の負のコントロール (検査が空振りしていないこと)", () => {
+    it("C2 検査: 失敗時に break する改変では 1 レーンしか走らない", { timeout: 30_000 }, () => {
+        const broken = mutate(
+            realSource(),
+            '    if [ "${code}" -ne 0 ]; then\n        overall="${code}"\n    fi',
+            '    if [ "${code}" -ne 0 ]; then\n        overall="${code}"\n        break\n    fi',
+        );
+        const run = runInSandbox(broken, { failingLanes: [1] });
+
+        expect(run.pestCalls).toHaveLength(1);
+    });
+
+    it("C3 検査: 最後に exit 0 する改変では失敗が握り潰される", { timeout: 30_000 }, () => {
+        const broken = mutate(realSource(), 'exit "${overall}"', "exit 0");
+        const run = runInSandbox(broken, { failingLanes: [1, 2] });
+
+        expect(run.pestCalls).toHaveLength(2);
+        expect(run.status).toBe(0);
+    });
+
+    it("C4 検査: 既定並列度を 2 にすると --parallel が現れる", { timeout: 30_000 }, () => {
+        const broken = mutate(
+            realSource(),
+            'PROCESSES="${BROWSER_TEST_PROCESSES:-1}"',
+            'PROCESSES="${BROWSER_TEST_PROCESSES:-2}"',
+        );
+        const run = runInSandbox(broken);
+
+        expect(run.pestCalls).toHaveLength(2);
+        expect(run.pestCalls[0]).toContain("--parallel");
+    });
+});
+
+describe("mutate() ヘルパ自身の保証 (負のコントロールが空振りしないこと)", () => {
+    it("置換対象が存在しなければ throw", () => {
+        expect(() => mutate("abc", "zzz", "yyy")).toThrow(/must appear exactly once \(found 0\)/);
+    });
+
+    it("置換対象が複数あれば throw", () => {
+        expect(() => mutate("aa", "a", "b")).toThrow(/must appear exactly once \(found 2\)/);
+    });
+
+    it("1 箇所だけなら置換して返す", () => {
+        expect(mutate("abc", "b", "X")).toBe("aXc");
+    });
+});
diff --git a/scripts/test-inventory-config.ts b/scripts/test-inventory-config.ts
new file mode 100644
index 0000000..5efb0fe
--- /dev/null
+++ b/scripts/test-inventory-config.ts
@@ -0,0 +1,45 @@
+/**
+ * vitest の include の単一 SoT。
+ *
+ * root と packages/cli は **統合しない**: root は jsdom + svelte plugin、
+ * packages/cli は node 環境 + 独自 setupFile + 独自 timeout の別 project であり、
+ * 「似ているから」で 1 つにしない (AGENTS.md 思考原則 4)。
+ * 統合する代わりに **include だけ** を本ファイルへ集約し、
+ * scripts/vitest-inventory-gate.test.ts が「FS 上の *.test.ts が全部どこかの
+ * project に入っているか」を独立に突き合わせる。
+ */
+
+/** vitest project 1 つ分の収集定義。 */
+export interface TestProject {
+    /** 人間可読な識別子 (gate の失敗メッセージに出す)。 */
+    readonly name: string;
+    /** repo root からの相対 project root。vitest を起動する cwd でもある。 */
+    readonly root: string;
+    /** project root からの相対 include glob。 */
+    readonly include: readonly string[];
+}
+
+/**
+ * 全 vitest project の inventory。
+ * **新しい project / 新しい include を足したらここに書く**。書き忘れると
+ * scripts/vitest-inventory-gate.test.ts が「どの project にも入らない test file」を検出して落ちる。
+ */
+export const TEST_PROJECTS: readonly TestProject[] = [
+    {
+        name: "root",
+        root: ".",
+        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
+    },
+    {
+        name: "packages/cli",
+        root: "packages/cli",
+        include: ["tests/**/*.test.ts"],
+    },
+] as const;
+
+/** name から project を引く (config 側で使う。未知名は fail-fast)。 */
+export function testProject(name: string): TestProject {
+    const found = TEST_PROJECTS.find((p) => p.name === name);
+    if (!found) throw new Error(`unknown vitest project: ${name}`);
+    return found;
+}
diff --git a/scripts/vitest-inventory-gate.test.ts b/scripts/vitest-inventory-gate.test.ts
new file mode 100644
index 0000000..6c72217
--- /dev/null
+++ b/scripts/vitest-inventory-gate.test.ts
@@ -0,0 +1,236 @@
+/**
+ * vitest inventory gate — 「書いたのに走っていないテスト」を deny-by-default で検出する。
+ *
+ * 検査は 2 系統を **独立に** 求めて突き合わせる:
+ *   A. FS 走査  — repo を自前で歩いて `*.test.ts` を全部拾う (SoT の glob を使わない)
+ *   B. vitest 列挙 — 各 project で `vitest list --json=<tmpfile>` を実行して実際の収集結果を取る
+ *
+ * SoT の glob を A にも使うと同語反復になり、**glob そのものの誤りを検出できない**。
+ * だから A は glob を使わず「拡張子が .test.ts のファイル」という素朴な定義で歩く。
+ *
+ * **非交渉の実装制約**:
+ *   1. spawn は **module top-level と `describe` callback の中では絶対に行わない**。
+ *      許されるのは「通常実行時にだけ走る callback」= `it` / `beforeAll` / `beforeEach` の
+ *      内側だけである。理由: 本ファイル自身が root project の include に入るため、
+ *      `vitest list` は本ファイルを **import して `describe` を評価する** (収集フェーズ)。
+ *      収集フェーズで評価される場所に spawn を置くと無限再帰する。
+ *      逆に `it`/hook の callback は収集フェーズでは **登録されるだけで実行されない**ため、
+ *      `beforeAll` に置いても再帰しない。
+ *      helper 関数も「呼ばれたときに spawn する」形にし、module 初期化時に spawn しない。
+ *   2. `vitest list --json` は **stdout に vite plugin の警告が混ざる** (実測)。
+ *      必ず `--json=<tmpfile>` でファイル出力し、ファイルを読む。
+ *   3. 再帰防止用の env フラグは導入しない。`vitest list` は収集のみで実行しないため
+ *      制約 1 だけで再帰は起きない。フラグを足すと「そのフラグが立つと gate が空振りする」
+ *      新しい偽グリーン経路を作ることになる。
+ */
+import { beforeAll, describe, expect, it } from "vitest";
+import { execFileSync } from "node:child_process";
+import { mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync } from "node:fs";
+import { tmpdir } from "node:os";
+import { join, resolve } from "node:path";
+import { TEST_PROJECTS } from "./test-inventory-config";
+
+/** repo root。scripts/run-vitest.sh が repo root で起動する前提 (feedback-probe.test.ts と同じ)。 */
+const REPO_ROOT = process.cwd();
+
+/**
+ * FS 走査から除外するディレクトリ名と、その理由。
+ * **除外を増やすときは「そこに走らせるべきテストが無い」ことを確認すること。**
+ */
+const FS_SCAN_EXCLUDED_DIRS: Record<string, string> = {
+    node_modules: "依存パッケージ。自リポジトリのテストではない",
+    vendor: "composer 依存。同上",
+    ".git": "VCS メタデータ",
+    dist: "ビルド成果物 (packages/cli の emit 先)",
+    devnotes:
+        "設計レビュー記録。過去の設計文書に *.test.ts 断片が含まれうる " +
+        "(codex-model-consistency.test.ts が同じ理由で除外している先例に倣う)",
+    ".claude": "worktree / skill の作業領域。他タスクの worktree が入れ子で見える",
+    storage: "Laravel の実行時生成物",
+    coverage: "カバレッジレポート出力先",
+    build: "public/build のビルド成果物",
+};
+
+/**
+ * repo を歩いて `*.test.ts` を全部拾う (glob を使わない素朴な定義)。
+ *
+ * @returns 絶対パス (realpath 済み) のリスト
+ */
+function scanTestFiles(dir: string, found: string[] = []): string[] {
+    for (const entry of readdirSync(dir, { withFileTypes: true })) {
+        if (entry.isDirectory()) {
+            if (entry.name in FS_SCAN_EXCLUDED_DIRS) continue;
+            scanTestFiles(join(dir, entry.name), found);
+            continue;
+        }
+        if (entry.isFile() && entry.name.endsWith(".test.ts")) {
+            found.push(realpathSync(join(dir, entry.name)));
+        }
+    }
+    return found;
+}
+
+/**
+ * 1 project 分の `vitest list` を実行して収集済みファイルの絶対パスを返す。
+ *
+ * **制約 1**: 本関数は `beforeAll` からのみ呼ぶこと (module top-level / describe から呼ばない)。
+ */
+function enumerateProject(projectRoot: string): string[] {
+    const dir = mkdtempSync(join(tmpdir(), "vitest-inventory-"));
+    const jsonPath = join(dir, "list.json");
+    try {
+        execFileSync("pnpm", ["exec", "vitest", "list", `--json=${jsonPath}`], {
+            cwd: resolve(REPO_ROOT, projectRoot),
+            // 制約 2: stdout には vite plugin の警告が混ざるので読まない (ファイル出力を読む)
+            stdio: "ignore",
+        });
+        const parsed: unknown = JSON.parse(readFileSync(jsonPath, "utf-8"));
+        if (!Array.isArray(parsed)) {
+            throw new Error(`vitest list の出力が配列でない (project=${projectRoot})`);
+        }
+        const files = new Set<string>();
+        for (const entry of parsed) {
+            if (entry && typeof entry === "object" && "file" in entry) {
+                const file = (entry as { file: unknown }).file;
+                if (typeof file === "string") files.add(realpathSync(file));
+            }
+        }
+        return [...files];
+    } finally {
+        rmSync(dir, { recursive: true, force: true });
+    }
+}
+
+/**
+ * FS 集合と列挙集合を突き合わせる純関数 (テストしやすさのため副作用と分離する)。
+ *
+ * @param fsFiles              FS 走査で見つけた `*.test.ts` (絶対パス)
+ * @param enumeratedByProject  project 名 => `vitest list` の収集結果 (絶対パス)
+ * @returns 違反一覧 (空 = 合格)
+ */
+export function inventoryViolations(
+    fsFiles: readonly string[],
+    enumeratedByProject: ReadonlyMap<string, readonly string[]>,
+): string[] {
+    const violations: string[] = [];
+
+    // G1: 各 project の列挙結果が 0 件でない (空振り gate の防止。合計では判定しない)
+    for (const [name, files] of enumeratedByProject) {
+        if (files.length === 0) {
+            violations.push(`G1: project "${name}" の収集結果が 0 件 (gate が空振りしている)`);
+        }
+    }
+
+    const enumeratedAll = new Set<string>();
+    // G5: 2 project の列挙結果が互いに素 (同じファイルを 2 回走らせていない)
+    const seenIn = new Map<string, string>();
+    for (const [name, files] of enumeratedByProject) {
+        for (const file of files) {
+            const previous = seenIn.get(file);
+            if (previous !== undefined) {
+                violations.push(`G5: ${file} が project "${previous}" と "${name}" の両方で収集されている`);
+            } else {
+                seenIn.set(file, name);
+            }
+            enumeratedAll.add(file);
+        }
+    }
+
+    // G2: FS 走査で見つけた全 *.test.ts が、いずれかの project の列挙に含まれる
+    for (const file of fsFiles) {
+        if (!enumeratedAll.has(file)) {
+            violations.push(`G2: ${file} がどの vitest project にも収集されていない (書いたのに走っていない)`);
+        }
+    }
+
+    // G3: 列挙結果にあって FS に無いファイルが無い (逆方向の整合)
+    const fsSet = new Set(fsFiles);
+    for (const file of enumeratedAll) {
+        if (!fsSet.has(file)) {
+            violations.push(`G3: ${file} が収集されているが FS 走査では見つからない`);
+        }
+    }
+
+    return violations;
+}
+
+describe("vitest inventory gate", () => {
+    let fsFiles: string[] = [];
+    const enumerated = new Map<string, string[]>();
+
+    // 制約 1: spawn は beforeAll の中でのみ行う (収集フェーズでは実行されない)
+    beforeAll(() => {
+        fsFiles = scanTestFiles(REPO_ROOT);
+        for (const project of TEST_PROJECTS) {
+            enumerated.set(project.name, enumerateProject(project.root));
+        }
+    }, 180_000);
+
+    it("G1〜G3/G5: FS 上の *.test.ts と vitest の収集結果が一致すること", { timeout: 180_000 }, () => {
+        const violations = inventoryViolations(fsFiles, enumerated);
+        expect(violations).toEqual([]);
+    });
+
+    it("G4: gate 自身が root project の収集結果に含まれること", { timeout: 180_000 }, () => {
+        const self = realpathSync(resolve(REPO_ROOT, "scripts/vitest-inventory-gate.test.ts"));
+        expect(enumerated.get("root")).toContain(self);
+    });
+});
+
+describe("inventoryViolations() の負のコントロール", () => {
+    const a = "/repo/tests/js/a.test.ts";
+    const b = "/repo/packages/cli/tests/b.test.ts";
+
+    it("正常な fixture では違反 0 件", () => {
+        const violations = inventoryViolations(
+            [a, b],
+            new Map([
+                ["root", [a]],
+                ["packages/cli", [b]],
+            ]),
+        );
+        expect(violations).toEqual([]);
+    });
+
+    it("G2: どの project にも入らないファイルを検出する", () => {
+        const orphan = "/repo/scripts/orphan.test.ts";
+        const violations = inventoryViolations(
+            [a, b, orphan],
+            new Map([
+                ["root", [a]],
+                ["packages/cli", [b]],
+            ]),
+        );
+        expect(violations).toContain(
+            `G2: ${orphan} がどの vitest project にも収集されていない (書いたのに走っていない)`,
+        );
+    });
+
+    it("G1: 列挙が空の project を検出する", () => {
+        const violations = inventoryViolations(
+            [a],
+            new Map([
+                ["root", [a]],
+                ["packages/cli", []],
+            ]),
+        );
+        expect(violations).toContain('G1: project "packages/cli" の収集結果が 0 件 (gate が空振りしている)');
+    });
+
+    it("G5: 同じファイルが 2 project に現れることを検出する", () => {
+        const violations = inventoryViolations(
+            [a],
+            new Map([
+                ["root", [a]],
+                ["packages/cli", [a]],
+            ]),
+        );
+        expect(violations).toContain(`G5: ${a} が project "root" と "packages/cli" の両方で収集されている`);
+    });
+
+    it("G3: 収集されているが FS に無いファイルを検出する", () => {
+        const ghost = "/repo/tests/js/ghost.test.ts";
+        const violations = inventoryViolations([a], new Map([["root", [a, ghost]]]));
+        expect(violations).toContain(`G3: ${ghost} が収集されているが FS 走査では見つからない`);
+    });
+});
diff --git a/tests/Architecture/PhpunitBrowserConfigParityTest.php b/tests/Architecture/PhpunitBrowserConfigParityTest.php
new file mode 100644
index 0000000..1adac4c
--- /dev/null
+++ b/tests/Architecture/PhpunitBrowserConfigParityTest.php
@@ -0,0 +1,306 @@
+<?php
+
+declare(strict_types=1);
+
+use Webmozart\Assert\Assert;
+
+/*
+ * Architecture invariant: phpunit.xml と phpunit.browser.xml の <php> 設定同値性。
+ *
+ * 背景: Browser lane は Feature lane と同じ worktree 固有 pgsql テスト DB を使い、
+ * 同じ LLM 実通信遮断 (StrayLlmCallGuard の最終防壁となるダミー API キー) を要求する。
+ * 片方にだけ新 provider のダミーキーを足す / 片方だけ SESSION_DRIVER を変える、という
+ * 乖離は「Browser lane だけ dev DB を向く」「Browser lane だけ実 LLM を叩く」に直結する。
+ * 唯一許される差分は memory_limit (実ブラウザ + in-process サーバ分の余裕)。
+ *
+ * phpunit.browser.xml のコメントが既に宣言している契約
+ * (「<php> の値は phpunit.xml と揃える (乖離させない)。差分は memory_limit のみ」) を
+ * 機械強制するのが本テストの役割。禁止事項 1 に従い、不変条件を Architecture テストへ
+ * 登録するところまでを「実装済み」とする。
+ *
+ * 本テストは DB を触らない (ファイル読み取りのみ)。
+ */
+
+/** Browser lane にのみ存在してよい <ini> の name。ここを増やすときは理由を書くこと。 */
+const PHPUNIT_BROWSER_ONLY_INI = [
+    // 実ブラウザ + in-process サーバの分だけ余裕を持たせる (phpunit.browser.xml のコメント)。
+    'memory_limit',
+];
+
+/**
+ * 設定ファイルの <php> 配下 <server> を name => [value, force] へ正規化する (純関数)。
+ *
+ * @return array<string, array{value: string, force: bool}>
+ */
+function phpunitServerEntries(string $xml): array
+{
+    $entries = [];
+    foreach (phpunitPhpChildren($xml, 'server') as $element) {
+        $force = strtolower($element->getAttribute('force'));
+        $entries[$element->getAttribute('name')] = [
+            'value' => $element->getAttribute('value'),
+            'force' => $force === 'true' || $force === '1',
+        ];
+    }
+
+    ksort($entries);
+
+    return $entries;
+}
+
+/**
+ * <php> 配下 <ini> を name => value へ正規化する (純関数)。
+ *
+ * @return array<string, string>
+ */
+function phpunitIniEntries(string $xml): array
+{
+    $entries = [];
+    foreach (phpunitPhpChildren($xml, 'ini') as $element) {
+        $entries[$element->getAttribute('name')] = $element->getAttribute('value');
+    }
+
+    ksort($entries);
+
+    return $entries;
+}
+
+/**
+ * <testsuites> 配下の <directory> を返す (純関数)。
+ *
+ * @return list<string>
+ */
+function phpunitSuiteDirectories(string $xml): array
+{
+    $directories = [];
+    foreach (phpunitQuery($xml, '//testsuites//directory') as $element) {
+        $directories[] = trim($element->textContent);
+    }
+
+    return $directories;
+}
+
+/** phpunit 設定の bootstrap 属性を返す (純関数)。 */
+function phpunitBootstrap(string $xml): string
+{
+    $elements = phpunitQuery($xml, '/phpunit');
+    Assert::notEmpty($elements, 'ルート要素 <phpunit> が見つからない');
+
+    return $elements[0]->getAttribute('bootstrap');
+}
+
+/**
+ * `<php>` 直下の指定タグを DOMElement のリストで返す (純関数)。
+ *
+ * @return list<DOMElement>
+ */
+function phpunitPhpChildren(string $xml, string $tag): array
+{
+    return phpunitQuery($xml, '//php/'.$tag);
+}
+
+/**
+ * XPath で DOMElement のみを取り出す (純関数)。
+ *
+ * DOMXPath::query() の戻り (DOMNodeList|false) と item() の (DOMNode|null) を
+ * instanceof で narrow する (PHPStan level 10)。
+ *
+ * @return list<DOMElement>
+ */
+function phpunitQuery(string $xml, string $expression): array
+{
+    $document = new DOMDocument;
+    // 整形用の空白テキストノードを落として走査を安定させる。
+    $document->preserveWhiteSpace = false;
+    Assert::true($document->loadXML($xml), "XML の parse に失敗した: {$expression}");
+
+    $nodes = (new DOMXPath($document))->query($expression);
+    if ($nodes === false) {
+        return [];
+    }
+
+    $elements = [];
+    foreach ($nodes as $node) {
+        if ($node instanceof DOMElement) {
+            $elements[] = $node;
+        }
+    }
+
+    return $elements;
+}
+
+/**
+ * 2 つの設定 XML の <php>/<server>・<ini>・testsuite・bootstrap の乖離を列挙する (純関数)。
+ *
+ * 実ファイルを読まない純関数に切り出すのは、負のコントロール (検出器が空振りしていないこと)
+ * を fixture 文字列で確認できるようにするため。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function phpunitBrowserParityViolations(string $baseXml, string $browserXml): array
+{
+    $violations = [];
+
+    // P1: <server> 集合が name / value / force まで完全一致
+    $baseServers = phpunitServerEntries($baseXml);
+    $browserServers = phpunitServerEntries($browserXml);
+
+    foreach ($baseServers as $name => $entry) {
+        if (! array_key_exists($name, $browserServers)) {
+            $violations[] = "P1: <server name=\"{$name}\"> が phpunit.browser.xml に無い";
+
+            continue;
+        }
+        if ($browserServers[$name]['value'] !== $entry['value']) {
+            $violations[] = sprintf(
+                'P1: <server name="%s"> の value が乖離している (phpunit.xml="%s" / browser="%s")',
+                $name,
+                $entry['value'],
+                $browserServers[$name]['value'],
+            );
+        }
+        if ($browserServers[$name]['force'] !== $entry['force']) {
+            $violations[] = sprintf(
+                'P1: <server name="%s"> の force が乖離している (phpunit.xml=%s / browser=%s)',
+                $name,
+                $entry['force'] ? 'true' : 'false',
+                $browserServers[$name]['force'] ? 'true' : 'false',
+            );
+        }
+    }
+    foreach ($browserServers as $name => $_entry) {
+        if (! array_key_exists($name, $baseServers)) {
+            $violations[] = "P1: <server name=\"{$name}\"> が phpunit.browser.xml にのみ存在する";
+        }
+    }
+
+    // P2 / P3: <ini> の差分は memory_limit のみ
+    $baseIni = phpunitIniEntries($baseXml);
+    $browserIni = phpunitIniEntries($browserXml);
+
+    foreach (PHPUNIT_BROWSER_ONLY_INI as $allowed) {
+        if (! array_key_exists($allowed, $browserIni)) {
+            $violations[] = "P2: <ini name=\"{$allowed}\"> が phpunit.browser.xml に無い";
+        }
+        if (array_key_exists($allowed, $baseIni)) {
+            $violations[] = "P2: <ini name=\"{$allowed}\"> が phpunit.xml にもある (差分でなくなっている)";
+        }
+    }
+    foreach ($browserIni as $name => $_value) {
+        if (! in_array($name, PHPUNIT_BROWSER_ONLY_INI, true) && ! array_key_exists($name, $baseIni)) {
+            $violations[] = "P3: <ini name=\"{$name}\"> が phpunit.browser.xml にのみ存在する (許可外の ini 差分)";
+        }
+    }
+    foreach ($baseIni as $name => $value) {
+        if (! array_key_exists($name, $browserIni)) {
+            $violations[] = "P3: <ini name=\"{$name}\"> が phpunit.xml にのみ存在する";
+
+            continue;
+        }
+        if ($browserIni[$name] !== $value) {
+            $violations[] = "P3: <ini name=\"{$name}\"> の value が乖離している";
+        }
+    }
+
+    // P4: bootstrap の一致 (dev-DB 保護の単一点ガードを共有する根拠)
+    $baseBootstrap = phpunitBootstrap($baseXml);
+    $browserBootstrap = phpunitBootstrap($browserXml);
+    if ($baseBootstrap !== $browserBootstrap) {
+        $violations[] = "P4: bootstrap が乖離している ({$baseBootstrap} / {$browserBootstrap})";
+    }
+    if ($baseBootstrap !== 'tests/bootstrap.php') {
+        $violations[] = "P4: bootstrap が tests/bootstrap.php でない ({$baseBootstrap})";
+    }
+
+    // P5: testsuite の分離 (composer test から Browser テストが誤起動しないこと)
+    $baseSuites = phpunitSuiteDirectories($baseXml);
+    $browserSuites = phpunitSuiteDirectories($browserXml);
+
+    foreach ($baseSuites as $directory) {
+        if (str_starts_with($directory, 'tests/Browser')) {
+            $violations[] = "P5: phpunit.xml の testsuite に {$directory} が含まれている (composer test から Browser が誤起動する)";
+        }
+    }
+    if ($browserSuites !== ['tests/Browser']) {
+        $violations[] = 'P5: phpunit.browser.xml の testsuite が tests/Browser のみでない ('.implode(', ', $browserSuites).')';
+    }
+
+    return $violations;
+}
+
+/** リポジトリ直下の設定ファイルを読む。 */
+function phpunitConfigSource(string $relativePath): string
+{
+    $contents = file_get_contents(base_path($relativePath));
+    Assert::string($contents, "{$relativePath} を読めない");
+
+    return $contents;
+}
+
+test('phpunit.xml と phpunit.browser.xml の <php> / testsuite / bootstrap が契約どおりであること', function (): void {
+    $violations = phpunitBrowserParityViolations(
+        phpunitConfigSource('phpunit.xml'),
+        phpunitConfigSource('phpunit.browser.xml'),
+    );
+
+    expect($violations)->toBe([], "phpunit 設定の乖離:\n".implode("\n", $violations));
+});
+
+test('P1 負のコントロール: browser 側にだけ <server> がある fixture を検出すること', function (): void {
+    $base = phpunitConfigSource('phpunit.xml');
+    $browser = str_replace(
+        '<server name="SESSION_DRIVER" value="array" force="true"/>',
+        '<server name="SESSION_DRIVER" value="array" force="true"/>'
+            ."\n".'<server name="EXTRA_BROWSER_ONLY" value="x" force="true"/>',
+        phpunitConfigSource('phpunit.browser.xml'),
+    );
+
+    expect(phpunitBrowserParityViolations($base, $browser))
+        ->toContain('P1: <server name="EXTRA_BROWSER_ONLY"> が phpunit.browser.xml にのみ存在する');
+});
+
+test('P1 負のコントロール: force の差分まで検出すること', function (): void {
+    $base = phpunitConfigSource('phpunit.xml');
+    $browser = str_replace(
+        '<server name="OPENAI_API_KEY" value="test-dummy-openai-key" force="true"/>',
+        '<server name="OPENAI_API_KEY" value="test-dummy-openai-key" force="false"/>',
+        phpunitConfigSource('phpunit.browser.xml'),
+    );
+
+    expect(phpunitBrowserParityViolations($base, $browser))
+        ->toContain('P1: <server name="OPENAI_API_KEY"> の force が乖離している (phpunit.xml=true / browser=false)');
+});
+
+test('P3 負のコントロール: 許可外の <ini> 追加を検出すること', function (): void {
+    $base = phpunitConfigSource('phpunit.xml');
+    $browser = str_replace(
+        '<ini name="memory_limit" value="1G"/>',
+        '<ini name="memory_limit" value="1G"/>'."\n".'<ini name="error_reporting" value="0"/>',
+        phpunitConfigSource('phpunit.browser.xml'),
+    );
+
+    expect(phpunitBrowserParityViolations($base, $browser))
+        ->toContain('P3: <ini name="error_reporting"> が phpunit.browser.xml にのみ存在する (許可外の ini 差分)');
+});
+
+test('P5 負のコントロール: phpunit.xml の testsuite に tests/Browser を足すと検出すること', function (): void {
+    $base = str_replace(
+        '<directory>tests/Architecture</directory>',
+        '<directory>tests/Architecture</directory>'."\n".'<directory>tests/Browser</directory>',
+        phpunitConfigSource('phpunit.xml'),
+    );
+
+    expect(phpunitBrowserParityViolations($base, phpunitConfigSource('phpunit.browser.xml')))
+        ->toContain('P5: phpunit.xml の testsuite に tests/Browser が含まれている (composer test から Browser が誤起動する)');
+});
+
+test('P4 負のコントロール: bootstrap の乖離を検出すること', function (): void {
+    $browser = str_replace(
+        'bootstrap="tests/bootstrap.php"',
+        'bootstrap="vendor/autoload.php"',
+        phpunitConfigSource('phpunit.browser.xml'),
+    );
+
+    expect(phpunitBrowserParityViolations(phpunitConfigSource('phpunit.xml'), $browser))
+        ->toContain('P4: bootstrap が乖離している (tests/bootstrap.php / vendor/autoload.php)');
+});
diff --git a/tests/Architecture/ScriptsReadmeInventoryTest.php b/tests/Architecture/ScriptsReadmeInventoryTest.php
new file mode 100644
index 0000000..3dd9107
--- /dev/null
+++ b/tests/Architecture/ScriptsReadmeInventoryTest.php
@@ -0,0 +1,205 @@
+<?php
+
+declare(strict_types=1);
+
+use Webmozart\Assert\Assert;
+
+/*
+ * 台帳規約 (AGENTS.md): 「scripts/ へスクリプトを追加したら scripts/README.md の表に 1 行追記する」
+ * を deny-by-default で機械強制する。
+ *
+ * 本テストを足す理由: この規約は実際にドリフトした (make-shard-phpunit.php が
+ * 「CI から自動呼び出し」と書かれたまま、どこからも呼ばれていなかった)。
+ * 禁止事項 1 に従い、不変条件を Architecture テストへ登録するところまでを「実装済み」とする。
+ *
+ * 本テストは DB を触らない (ファイル読み取りのみ)。
+ */
+
+/**
+ * 台帳登録の対象外 (`scripts/` からの相対パス)。理由を書けないものをここに足さないこと。
+ *
+ * @var array<string, string>
+ */
+const SCRIPTS_README_EXEMPT = [
+    // 台帳そのもの。自分を自分の表へ登録するのは同語反復なので対象外にする。
+    'README.md' => '台帳ファイル自身 (表の正本であって、表に載る対象ではない)',
+];
+
+/**
+ * `scripts/` 配下を **再帰的に** 走査してファイルの相対パスを返す (純関数)。
+ *
+ * `scripts/*` + `scripts/ci/*` の 2 階層だけを見る実装は、将来 `scripts/foo/bar.sh` が
+ * 増えたときに黙って漏れる = deny-by-default を名乗れない。
+ *
+ * @return list<string> `scripts/` からの相対パス (昇順)
+ */
+function scriptsDirectoryFiles(string $scriptsDir): array
+{
+    $found = [];
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($scriptsDir, FilesystemIterator::SKIP_DOTS),
+    );
+
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
+            continue;
+        }
+        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($scriptsDir) + 1));
+        $found[] = $relative;
+    }
+
+    sort($found);
+
+    return $found;
+}
+
+/**
+ * README の表を「相対パス => [用途, 実行タイミング]」へ正規化する (純関数)。
+ *
+ * パースは「行頭 `|` + 1 列目がバッククォート囲み」に限定する
+ * (見出し行 / 区切り行 / 説明文を巻き込まないため)。
+ *
+ * @return array<string, array{purpose: string, timing: string}>
+ */
+function scriptsReadmeRows(string $markdown): array
+{
+    $rows = [];
+
+    // 改行分割に `\R` を使わないこと: `/u` 無しの PCRE では `\R` が NEL (0x85) にも一致し、
+    // 日本語 UTF-8 テキスト (0x85 を含む多バイト文字が頻出) を**文字の途中で分断**する。
+    // 実測でこの表の行が壊れて S1 の偽陽性が出た。改行だけを明示列挙する。
+    foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
+        $line = trim($line);
+        if (! str_starts_with($line, '|')) {
+            continue;
+        }
+
+        $cells = array_map(trim(...), explode('|', trim($line, '|')));
+        if (count($cells) < 3) {
+            continue;
+        }
+        if (preg_match('/^`([^`]+)`$/', $cells[0], $matches) !== 1) {
+            continue;
+        }
+
+        $rows[$matches[1]] = ['purpose' => $cells[1], 'timing' => $cells[2]];
+    }
+
+    return $rows;
+}
+
+/**
+ * 台帳と実ファイルの乖離を列挙する (純関数)。
+ *
+ * 実ファイルを読まない純関数に切り出すのは、負のコントロール (検出器が空振りしていないこと)
+ * を fixture で確認できるようにするため。
+ *
+ * @param  list<string>  $files  `scripts/` からの相対パス
+ * @param  array<string, array{purpose: string, timing: string}>  $rows
+ * @param  array<string, string>  $exempt  相対パス => 除外理由
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function scriptsReadmeInventoryViolations(array $files, array $rows, array $exempt): array
+{
+    $violations = [];
+
+    // S1: scripts/ 配下の全ファイル (明示 exemption を除く) が README の表に行を持つ
+    foreach ($files as $relative) {
+        if (array_key_exists($relative, $exempt)) {
+            continue;
+        }
+        if (! array_key_exists($relative, $rows)) {
+            $violations[] = "S1: scripts/{$relative} が scripts/README.md の表に無い (追加時は 1 行追記すること)";
+        }
+    }
+
+    // S2: README の表の全行に対応する実ファイルがある
+    foreach ($rows as $relative => $_row) {
+        if (! in_array($relative, $files, true)) {
+            $violations[] = "S2: scripts/README.md の行 `{$relative}` に対応する実ファイルが無い (削除時は行も消すこと)";
+        }
+    }
+
+    // S3: 各行の「用途」「実行タイミング」列が空でない
+    foreach ($rows as $relative => $row) {
+        if ($row['purpose'] === '') {
+            $violations[] = "S3: scripts/README.md の行 `{$relative}` の用途が空";
+        }
+        if ($row['timing'] === '') {
+            $violations[] = "S3: scripts/README.md の行 `{$relative}` の実行タイミングが空";
+        }
+    }
+
+    // S4: exemption が実在ファイルを指し、理由が非空であること
+    foreach ($exempt as $relative => $reason) {
+        if (! in_array($relative, $files, true)) {
+            $violations[] = "S4: exemption `{$relative}` が実在しない (死んだ除外の残置)";
+        }
+        if (trim($reason) === '') {
+            $violations[] = "S4: exemption `{$relative}` の理由が空 (理由なし除外は認めない)";
+        }
+    }
+
+    return $violations;
+}
+
+test('scripts/ 配下の全ファイルが scripts/README.md の台帳と一致すること', function (): void {
+    $markdown = file_get_contents(base_path('scripts/README.md'));
+    Assert::string($markdown, 'scripts/README.md を読めない');
+
+    $violations = scriptsReadmeInventoryViolations(
+        scriptsDirectoryFiles(base_path('scripts')),
+        scriptsReadmeRows($markdown),
+        SCRIPTS_README_EXEMPT,
+    );
+
+    expect($violations)->toBe([], "scripts/README.md 台帳の乖離:\n".implode("\n", $violations));
+});
+
+test('S1 負のコントロール: 台帳に無いファイルを検出すること', function (): void {
+    $violations = scriptsReadmeInventoryViolations(
+        ['README.md', 'a.sh', 'ci/new-thing.php'],
+        ['a.sh' => ['purpose' => 'x', 'timing' => 'y']],
+        SCRIPTS_README_EXEMPT,
+    );
+
+    expect($violations)->toContain(
+        'S1: scripts/ci/new-thing.php が scripts/README.md の表に無い (追加時は 1 行追記すること)',
+    );
+});
+
+test('S2 負のコントロール: 実体の無い行を検出すること (今回のドリフトそのもの)', function (): void {
+    $violations = scriptsReadmeInventoryViolations(
+        ['README.md', 'a.sh'],
+        [
+            'a.sh' => ['purpose' => 'x', 'timing' => 'y'],
+            'ci/make-shard-phpunit.php' => ['purpose' => 'sharding', 'timing' => 'CI から自動呼び出し'],
+        ],
+        SCRIPTS_README_EXEMPT,
+    );
+
+    expect($violations)->toContain(
+        'S2: scripts/README.md の行 `ci/make-shard-phpunit.php` に対応する実ファイルが無い (削除時は行も消すこと)',
+    );
+});
+
+test('S3 負のコントロール: 実行タイミングが空の行を検出すること', function (): void {
+    $violations = scriptsReadmeInventoryViolations(
+        ['README.md', 'a.sh'],
+        ['a.sh' => ['purpose' => 'x', 'timing' => '']],
+        SCRIPTS_README_EXEMPT,
+    );
+
+    expect($violations)->toContain('S3: scripts/README.md の行 `a.sh` の実行タイミングが空');
+});
+
+test('S4 負のコントロール: 死んだ exemption と理由なし exemption を検出すること', function (): void {
+    $violations = scriptsReadmeInventoryViolations(
+        ['README.md', 'a.sh'],
+        ['a.sh' => ['purpose' => 'x', 'timing' => 'y']],
+        ['gone.sh' => '既に消したスクリプト', 'a.sh' => '   '],
+    );
+
+    expect($violations)->toContain('S4: exemption `gone.sh` が実在しない (死んだ除外の残置)');
+    expect($violations)->toContain('S4: exemption `a.sh` の理由が空 (理由なし除外は認めない)');
+});
diff --git a/tests/js/architecture/ci-workflow-inventory.test.ts b/tests/js/architecture/ci-workflow-inventory.test.ts
new file mode 100644
index 0000000..020e2e0
--- /dev/null
+++ b/tests/js/architecture/ci-workflow-inventory.test.ts
@@ -0,0 +1,330 @@
+/**
+ * CI workflow inventory gate — `.github/workflows/ci.yml` の構成を deny-by-default で固定する。
+ *
+ * なぜ必要か: scripts/run-browser-test.contract.test.ts は**スクリプトの契約**を守るが、
+ * workflow 側で
+ *   - `browser-tests` の env に `BROWSER_TEST_LANES: chromium` を足す
+ *   - どこかの step に `continue-on-error: true` を足す
+ *   - `pnpm test:packages` / `pnpm build:packages` の step を消す
+ * といった退行は**スクリプトを一切壊さずに**実行できる。
+ * 「レーンが CI で実際に走っている」を守るには workflow 自体を inventory 化する必要がある。
+ *
+ * W9 / W13 は「値が正しいこと」ではなく「**現れないこと**」を検査する。
+ * 文字列 grep ではコメント内の言及で偽赤になるため、**YAML を parse した後の構造を歩く**
+ * (コメントは parse 時に落ちるので、`BROWSER_TEST_LANES` を**コメントで説明する**ことは許される)。
+ */
+import { describe, expect, it } from "vitest";
+import { readFileSync } from "node:fs";
+import { resolve } from "node:path";
+import { parse as parseYaml } from "yaml";
+
+/** ci.yml の最小構造 (検査に必要な範囲のみ)。 */
+interface WorkflowStep {
+    name?: string;
+    uses?: string;
+    with?: Record<string, unknown>;
+    run?: string;
+    env?: Record<string, unknown>;
+}
+interface WorkflowJob {
+    "runs-on"?: string;
+    services?: Record<string, { image?: string }>;
+    env?: Record<string, unknown>;
+    steps?: WorkflowStep[];
+}
+interface Workflow {
+    on?: Record<string, unknown>;
+    jobs?: Record<string, WorkflowJob>;
+}
+
+const WORKFLOW_PATH = resolve(process.cwd(), ".github/workflows/ci.yml");
+
+function loadWorkflow(): Workflow {
+    return parseYaml(readFileSync(WORKFLOW_PATH, "utf-8")) as Workflow;
+}
+
+function job(workflow: Workflow, name: string): WorkflowJob {
+    const found = workflow.jobs?.[name];
+    if (!found) throw new Error(`job "${name}" が ci.yml に無い`);
+    return found;
+}
+
+/** 全 run 文字列を job 単位で連結する (step の分割に依存せず「実行しているか」を見るため)。 */
+function runScript(target: WorkflowJob): string {
+    return (target.steps ?? []).map((s) => s.run ?? "").join("\n");
+}
+
+/** `run` 文字列を「空行とコメント行を除いた実行行」へ分解する。 */
+function runLines(target: WorkflowJob): string[] {
+    return (target.steps ?? [])
+        .flatMap((s) => (s.run ?? "").split("\n"))
+        .map((l) => l.trim())
+        .filter((l) => l !== "" && !l.startsWith("#"));
+}
+
+/** 任意の深さのオブジェクト木に指定 **キー名** が現れる位置を返す純関数 (W9 / W13 用)。 */
+export function findKeyPaths(node: unknown, key: string, path = "$"): string[] {
+    const hits: string[] = [];
+    if (Array.isArray(node)) {
+        node.forEach((child, i) => hits.push(...findKeyPaths(child, key, `${path}[${i}]`)));
+        return hits;
+    }
+    if (node && typeof node === "object") {
+        for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
+            if (k === key) hits.push(`${path}.${k}`);
+            hits.push(...findKeyPaths(v, key, `${path}.${k}`));
+        }
+    }
+    return hits;
+}
+
+/**
+ * 任意の深さの木を歩き、**scalar 文字列の中身**に needle を含む位置を返す純関数 (W9 用)。
+ * `run: BROWSER_TEST_LANES=chromium composer test:browser` のような
+ * 「キーではなく値として仕込む」骨抜きを検出するために必要 (キー走査だけでは素通りする)。
+ */
+export function findScalarValuePathsContaining(node: unknown, needle: string, path = "$"): string[] {
+    const hits: string[] = [];
+    if (typeof node === "string") {
+        if (node.includes(needle)) hits.push(path);
+        return hits;
+    }
+    if (Array.isArray(node)) {
+        node.forEach((child, i) => hits.push(...findScalarValuePathsContaining(child, needle, `${path}[${i}]`)));
+        return hits;
+    }
+    if (node && typeof node === "object") {
+        for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
+            hits.push(...findScalarValuePathsContaining(v, needle, `${path}.${k}`));
+        }
+    }
+    return hits;
+}
+
+/** action 名から `@version` を落とす (version 上げで偽赤にしない)。 */
+function actionName(uses: string): string {
+    return uses.split("@")[0];
+}
+
+/**
+ * browser-tests job で使ってよい setup action (allowlist)。
+ * ここに足すことは「その action が BROWSER_TEST_* を $GITHUB_ENV へ書かない」ことの表明である。
+ */
+const BROWSER_JOB_ALLOWED_USES = [
+    "actions/checkout",
+    "shivammathur/setup-php",
+    "pnpm/action-setup",
+    "actions/setup-node",
+] as const;
+
+/** browser-tests job で実行してよいコマンド行 (完全一致)。
+ *  追加するときは「その行が BROWSER_TEST_* を設定しうるか」を必ず確認すること。 */
+const BROWSER_JOB_ALLOWED_RUN_LINES = [
+    "composer install --prefer-dist --no-progress --no-interaction",
+    "pnpm install --frozen-lockfile",
+    "cp .env.example .env",
+    "php artisan key:generate",
+    "php artisan passport:keys --force",
+    "pnpm build",
+    "pnpm exec playwright install --with-deps chromium webkit",
+    "composer test:browser",
+] as const;
+
+const LANE_ENV_VARS = ["BROWSER_TEST_LANES", "BROWSER_TEST_PROCESSES"] as const;
+
+describe("ci.yml inventory gate", () => {
+    const workflow = loadWorkflow();
+
+    it("W1: job 集合が完全一致すること (job を増やしたらここに登録させる)", () => {
+        expect(Object.keys(workflow.jobs ?? {}).sort()).toEqual(
+            ["browser-tests", "frontend", "php", "supply-chain-audit"].sort(),
+        );
+    });
+
+    it("W2: php / browser-tests が postgres:18-alpine service を持つこと", () => {
+        for (const name of ["php", "browser-tests"]) {
+            expect(job(workflow, name).services?.postgres?.image).toBe("postgres:18-alpine");
+        }
+    });
+
+    it("W3: php / browser-tests の setup-php が pdo_pgsql を含むこと", () => {
+        for (const name of ["php", "browser-tests"]) {
+            const setup = (job(workflow, name).steps ?? []).find(
+                (s) => s.uses !== undefined && actionName(s.uses) === "shivammathur/setup-php",
+            );
+            expect(setup, `${name} に setup-php step が無い`).toBeDefined();
+            expect(String(setup?.with?.extensions ?? "")).toContain("pdo_pgsql");
+        }
+    });
+
+    it("W4: php が composer test と verify-global-test-lock.sh を実行すること", () => {
+        const script = runScript(job(workflow, "php"));
+        expect(script).toContain("composer test");
+        expect(script).toContain("bash scripts/verify-global-test-lock.sh");
+    });
+
+    it("W5: php の ffmpeg provision と fc-match fail-fast が残っていること", () => {
+        const script = runScript(job(workflow, "php"));
+        for (const token of ["ffmpeg", "fonts-noto-cjk", "fontconfig"]) {
+            expect(script).toContain(token);
+        }
+        expect(script).toContain("fc-match");
+        // 解決 family が Noto CJK であることの機械判定 (代替フォントへのフォールバック検出)
+        expect(script).toContain("Noto Sans CJK");
+    });
+
+    it("W6/W14c: browser-tests に composer test:browser 完全一致の run step がちょうど 1 つあること", () => {
+        // `includes` 判定にしないのは `run: echo "composer test:browser"` が素通りするため。
+        const exact = (job(workflow, "browser-tests").steps ?? []).filter(
+            (s) => (s.run ?? "").trim() === "composer test:browser",
+        );
+        expect(exact).toHaveLength(1);
+    });
+
+    it("W7: browser-tests が playwright install --with-deps chromium webkit を実行すること", () => {
+        expect(runScript(job(workflow, "browser-tests"))).toContain(
+            "pnpm exec playwright install --with-deps chromium webkit",
+        );
+    });
+
+    it("W8: browser-tests が pnpm build を実行すること (実ブラウザが public/build を読む)", () => {
+        expect(runLines(job(workflow, "browser-tests"))).toContain("pnpm build");
+    });
+
+    it("W9: BROWSER_TEST_LANES / BROWSER_TEST_PROCESSES が workflow のどこにも現れないこと", () => {
+        for (const name of LANE_ENV_VARS) {
+            // キー名としても、あらゆる scalar 値の中身としても現れてはならない
+            expect(findKeyPaths(workflow, name)).toEqual([]);
+            expect(findScalarValuePathsContaining(workflow, name)).toEqual([]);
+        }
+    });
+
+    it("W10: frontend が全レーンを実行すること", () => {
+        const lines = runLines(job(workflow, "frontend"));
+        for (const command of [
+            "pnpm test",
+            "pnpm test:packages",
+            "pnpm typecheck:packages",
+            "pnpm build:packages",
+            "pnpm build",
+            "pnpm lint",
+            "pnpm typecheck",
+        ]) {
+            expect(lines, `frontend に "${command}" が無い`).toContain(command);
+        }
+    });
+
+    it("W11: supply-chain-audit が pnpm run audit:gate を実行すること", () => {
+        expect(runScript(job(workflow, "supply-chain-audit"))).toContain("pnpm run audit:gate");
+    });
+
+    it("W12: on.schedule (nightly) が存在すること", () => {
+        expect(workflow.on?.schedule).toBeDefined();
+    });
+
+    it("W13: continue-on-error が workflow のどこにも現れないこと (soft-fail 禁止)", () => {
+        expect(findKeyPaths(workflow, "continue-on-error")).toEqual([]);
+    });
+
+    it("W14a: browser-tests の uses が信頼済み setup action の allowlist に限定されること", () => {
+        const used = (job(workflow, "browser-tests").steps ?? [])
+            .filter((s) => s.uses !== undefined)
+            .map((s) => actionName(s.uses as string));
+        for (const name of used) {
+            expect(BROWSER_JOB_ALLOWED_USES, `allowlist 外の action: ${name}`).toContain(name);
+        }
+    });
+
+    it("W14b: browser-tests の run 実行行が allowlist に完全一致すること", () => {
+        for (const line of runLines(job(workflow, "browser-tests"))) {
+            expect(BROWSER_JOB_ALLOWED_RUN_LINES, `allowlist 外の実行行: ${line}`).toContain(line);
+        }
+    });
+});
+
+describe("走査関数の負のコントロール (検出器が空振りしていないこと)", () => {
+    it("continue-on-error を持つ step を検出する", () => {
+        const fixture = { jobs: { php: { steps: [{ run: "x", "continue-on-error": true }] } } };
+        expect(findKeyPaths(fixture, "continue-on-error")).toHaveLength(1);
+    });
+
+    it("env キーとしての BROWSER_TEST_LANES を検出する", () => {
+        const fixture = { jobs: { "browser-tests": { env: { BROWSER_TEST_LANES: "chromium" } } } };
+        expect(findKeyPaths(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
+    });
+
+    it("run 値に埋めた BROWSER_TEST_LANES を検出する (キー走査は 0 件 = 値走査が必要な証明)", () => {
+        const fixture = {
+            jobs: { "browser-tests": { steps: [{ run: "BROWSER_TEST_LANES=chromium composer test:browser" }] } },
+        };
+        expect(findKeyPaths(fixture, "BROWSER_TEST_LANES")).toEqual([]);
+        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
+    });
+
+    it("複数行 scalar に埋めた BROWSER_TEST_PROCESSES を検出する", () => {
+        const fixture = {
+            jobs: {
+                "browser-tests": { steps: [{ run: "export BROWSER_TEST_PROCESSES=4\ncomposer test:browser" }] },
+            },
+        };
+        expect(findKeyPaths(fixture, "BROWSER_TEST_PROCESSES")).toEqual([]);
+        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_PROCESSES")).toHaveLength(1);
+    });
+
+    it("正常な fixture では両関数とも 0 件", () => {
+        const fixture = { jobs: { "browser-tests": { steps: [{ run: "composer test:browser" }] } } };
+        for (const name of LANE_ENV_VARS) {
+            expect(findKeyPaths(fixture, name)).toEqual([]);
+            expect(findScalarValuePathsContaining(fixture, name)).toEqual([]);
+        }
+        expect(findKeyPaths(fixture, "continue-on-error")).toEqual([]);
+    });
+
+    it("W14a: allowlist 外の composite action を検出する", () => {
+        const steps: WorkflowStep[] = [
+            { uses: "actions/checkout@v4" },
+            { uses: "./.github/actions/setup-browser" },
+        ];
+        const outside = steps
+            .map((s) => actionName(s.uses as string))
+            .filter((n) => !(BROWSER_JOB_ALLOWED_USES as readonly string[]).includes(n));
+        expect(outside).toEqual(["./.github/actions/setup-browser"]);
+    });
+
+    it("W14b: allowlist 外のローカルスクリプト実行行を検出する", () => {
+        const fixture: WorkflowJob = {
+            steps: [{ run: "bash scripts/prepare-browser-ci.sh" }, { run: "composer test:browser" }],
+        };
+        const outside = runLines(fixture).filter(
+            (l) => !(BROWSER_JOB_ALLOWED_RUN_LINES as readonly string[]).includes(l),
+        );
+        expect(outside).toEqual(["bash scripts/prepare-browser-ci.sh"]);
+    });
+
+    it("W14c: echo で偽装した composer test:browser を検出する", () => {
+        const fixture: WorkflowJob = { steps: [{ run: 'echo "composer test:browser"' }] };
+        const exact = (fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser");
+        // includes 判定なら素通りするが、完全一致では 0 件になる
+        expect(runScript(fixture)).toContain("composer test:browser");
+        expect(exact).toHaveLength(0);
+    });
+
+    it("W9 + W14b/W14c: 環境変数付与つき起動は 3 検査すべてが違反を返す", () => {
+        const fixture: WorkflowJob = { steps: [{ run: "BROWSER_TEST_LANES=chromium composer test:browser" }] };
+        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
+        expect(
+            runLines(fixture).filter((l) => !(BROWSER_JOB_ALLOWED_RUN_LINES as readonly string[]).includes(l)),
+        ).toHaveLength(1);
+        expect((fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser")).toHaveLength(0);
+    });
+
+    it("composite action へ移送すると W14a と W14c の両方が違反を返す", () => {
+        const fixture: WorkflowJob = { steps: [{ uses: "./.github/actions/run-browser-lanes@v1" }] };
+        const outside = (fixture.steps ?? [])
+            .filter((s) => s.uses !== undefined)
+            .map((s) => actionName(s.uses as string))
+            .filter((n) => !(BROWSER_JOB_ALLOWED_USES as readonly string[]).includes(n));
+        expect(outside).toHaveLength(1);
+        expect((fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser")).toHaveLength(0);
+    });
+});
diff --git a/tsconfig.json b/tsconfig.json
index 2af6553..b80df54 100644
--- a/tsconfig.json
+++ b/tsconfig.json
@@ -20,7 +20,8 @@
     "include": [
         "resources/js/**/*.ts",
         "resources/js/**/*.svelte",
-        "tests/js/**/*.ts"
+        "tests/js/**/*.ts",
+        "scripts/**/*.ts"
     ],
     "exclude": ["node_modules", "tmp"]
 }
diff --git a/vitest.config.ts b/vitest.config.ts
index e2254ce..b0e353b 100644
--- a/vitest.config.ts
+++ b/vitest.config.ts
@@ -2,6 +2,7 @@ import { defineConfig } from "vitest/config";
 import { svelte } from "@sveltejs/vite-plugin-svelte";
 import { svelteTesting } from "@testing-library/svelte/vite";
 import path from "path";
+import { testProject } from "./scripts/test-inventory-config";
 
 export default defineConfig({
     plugins: [
@@ -19,7 +20,9 @@ export default defineConfig({
         maxWorkers: "50%",
         minWorkers: 1,
         setupFiles: ["./tests/js/setup.ts"],
-        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
+        // include の正本は scripts/test-inventory-config.ts (2 project 分を 1 箇所で持つ)。
+        // scripts/vitest-inventory-gate.test.ts が FS 走査と突き合わせて漏れを検出する。
+        include: [...testProject("root").include],
         coverage: {
             provider: "v8",
             reporter: ["text", "json", "html"],

## テスト結果

- composer test: 2717 tests / 2715 passed / 0 failed / 2 skipped (main baseline は 2704 passed。新規 PHP テスト 11 本を追加)
- composer phpstan: No errors (level 10, 747 files)
- vendor/bin/pint --test: passed
- pnpm test: 114 files / 1077 tests passed
- pnpm test:packages: 10 files / 106 tests passed (施策 3 の import 削除後も不変 = 挙動不変の証明)
- pnpm lint / typecheck / typecheck:packages / build / build:packages: すべて exit 0
- pnpm run audit:gate: **exit 0** (26 advisories → 4、high 15 → 0、accept-risk 登録 0 件)
- composer test:browser: **この環境では実行不可**。~/.cache/ms-playwright 不在のため
  PlaywrightOutdatedException で 2 レーンとも全 fail する (実装前から同じ。CI で初めて実走する)。
  副産物として「失敗レーンがあっても両レーン実行・overall 非ゼロ」= 契約 C2/C3 は実測できた。

## 新規テストの内訳 (すべて負のコントロール付き)

- scripts/run-browser-test.contract.test.ts: 14 tests (層 1 実走 3 + 層 2 静的 5 + 負のコントロール 3 + mutate ヘルパ 3)
- scripts/audit-gate.contract.test.ts: 11 tests (A1〜A10)
- scripts/audit-gate.test.ts: +13 tests (loadAuditJson 経由の shape 検証。既存 26 tests は削除も上書きもしていない)
- scripts/vitest-inventory-gate.test.ts: 7 tests (G1〜G5 + 負のコントロール 5)
- tests/js/architecture/ci-workflow-inventory.test.ts: 25 tests (W1〜W14 + 負のコントロール 10)
- tests/Architecture/PhpunitBrowserConfigParityTest.php: 6 tests (P1〜P5 + 負のコントロール 5)
- tests/Architecture/ScriptsReadmeInventoryTest.php: 5 tests (S1〜S4 + 負のコントロール 4)

## 実装中に発見した既存バグ (本バッチで修正したもの)

ScriptsReadmeInventoryTest の実装中、`preg_split('/\R/', $markdown)` が `/u` 修飾子なしでは
**NEL (0x85) にも一致して日本語 UTF-8 テキストを文字の途中で分断する**ことを実測しました
(scripts/README.md の表行が壊れて S1 の偽陽性が出た)。新規コードでは
`preg_split('/\r\n|\r|\n/', ...)` に変更しています。なお既存の
tests/Architecture/GlobalTestLockInventoryTest.php も同じ `/\R/` を使っていますが、
入力が ASCII の JSON script 文字列なので顕在化しません (本バッチでは触っていません)。
