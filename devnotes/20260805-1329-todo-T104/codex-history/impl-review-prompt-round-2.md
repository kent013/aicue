Round 1 の指摘への対応が完了しました。対応マトリクスと修正差分を提示します。

# 対応マトリクス

## [Critical] audit-gate.ts: error-bearing JSON が空コンテナだと 0 件で通る
→ **対応した**。`assertAuditSourceShape` の冒頭で top-level `error` / `errors` を検査し、
**非空なら throw** する。空 (null / `{}` / `[]`) は「エラー無しの明示」として通し偽赤を避けた。
負のコントロール 2 本追加 (error-bearing 3 パターン → throw / 空 error 3 パターン → 通る)。
既存の「pnpm ネットワークエラー形」テストは error 検査が先に発火するため期待メッセージを追従させ、
**shape 検査自体の空振り防止として「error シグナル無しで advisories 欠落」ケースを別テストで残した**。

## [Warning] on.schedule は workflow 全体を起動する
→ **対応した**。指摘どおり docs の記述と実体が食い違っていた。
`php` / `frontend` / `browser-tests` に `if: github.event_name != 'schedule'` を付与。
あわせて inventory gate に **W15** を追加し、(a) 3 job が schedule から除外されていること、
(b) **supply-chain-audit には `if` が無いこと** (= gate を nightly から外す退行を止める) を
deny-by-default で固定した。docs にも構成を明記。

## [Warning] sandbox が呼び出し元環境のレーン変数を継承する
→ **対応した**。子 env から `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を明示 delete し、
注入は `options.env` 経由の明示指定のみに限定 (C8 のみ使用)。

## [Warning] global-test-lock.sh の潜在 race の別タスク化
→ **本バッチでは見送り**、最終報告で申し送る。
`global-test-lock.sh` は T099 の契約ファイルで本バッチ (CI レーン統合) のスコープ外。
レビュアーも「T104 の範囲では受け入れ可能」としているため、スコープを広げるより
事実を報告して TODO 化の判断を委ねる方が適切と判断した。

## [Suggestion] C5 の PPID 検査が反転条件でも通る
→ **対応した**。正規表現を `!=` 除外へ修正し、反転を明示的に違反として検出。
負のコントロール (PPID 判定を反転した改変ソース) を 1 本追加。

## [Suggestion] load() が一時ディレクトリを残す
→ **対応した**。`rmSync(dir, { recursive: true, force: true })` へ変更。

# 修正後のテスト結果

- pnpm test: 114 files / **1082 tests passed** (Round 1 時点は 1077。負のコントロール 5 本増)
- pnpm run audit:gate: **exit 0** のまま (error 検査追加で偽赤になっていないことの確認)
- pnpm typecheck / lint: exit 0
- composer test / phpstan / pint は Round 1 から PHP 側の変更が無いため再実行不要と判断
  (変更は ci.yml / scripts/*.ts / tests/js/ / docs のみ)

# 修正差分

diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index fb66f1f..8b6ba65 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -4,15 +4,55 @@ on:
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
+    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
+    # 本 job は schedule では走らせない (impl-review R1 [Warning]:
+    # on.schedule は workflow 全体を起動するため、明示除外しないと
+    # docs の「nightly は supply-chain gate の先行検知」という記述と実体が食い違う)。
+    if: github.event_name != 'schedule'
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
@@ -44,11 +84,95 @@ jobs:
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
+    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
+    # 本 job は schedule では走らせない (impl-review R1 [Warning]:
+    # on.schedule は workflow 全体を起動するため、明示除外しないと
+    # docs の「nightly は supply-chain gate の先行検知」という記述と実体が食い違う)。
+    if: github.event_name != 'schedule'
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
+    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
+    # 本 job は schedule では走らせない (impl-review R1 [Warning]:
+    # on.schedule は workflow 全体を起動するため、明示除外しないと
+    # docs の「nightly は supply-chain gate の先行検知」という記述と実体が食い違う)。
+    if: github.event_name != 'schedule'
     steps:
       - uses: actions/checkout@v4
       - uses: pnpm/action-setup@v4
@@ -68,7 +192,47 @@ jobs:
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
diff --git a/docs/supply-chain/review-checklist.md b/docs/supply-chain/review-checklist.md
index 62ae91c..6ab795e 100644
--- a/docs/supply-chain/review-checklist.md
+++ b/docs/supply-chain/review-checklist.md
@@ -50,6 +50,39 @@ ## 5. 0day 緊急時
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
+  `on.schedule` は workflow 全体を起動するため、`php` / `frontend` / `browser-tests` には
+  `if: github.event_name != 'schedule'` を付けて **nightly では supply-chain-audit だけが走る**
+  ようにしている (`tests/js/architecture/ci-workflow-inventory.test.ts` W15 が固定)。
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
diff --git a/scripts/audit-gate.test.ts b/scripts/audit-gate.test.ts
index d551118..bab4018 100644
--- a/scripts/audit-gate.test.ts
+++ b/scripts/audit-gate.test.ts
@@ -4,11 +4,12 @@
  * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
  */
 import { describe, expect, it } from "vitest";
-import { writeFileSync, unlinkSync, mkdtempSync } from "node:fs";
+import { writeFileSync, unlinkSync, mkdtempSync, rmSync } from "node:fs";
 import { tmpdir } from "node:os";
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
@@ -360,3 +362,119 @@ describe("audit-gate utility helpers", () => {
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
+            rmSync(dir, { recursive: true, force: true });
+        }
+    }
+
+    it("不正 JSON は throw する", () => {
+        expect(() => load("pnpm-audit", "not json")).toThrow(/JSON parse failure/);
+    });
+
+    it("pnpm: ネットワークエラー形 {error:{...}} は throw する (shape 黙殺の穴が塞がった証明)", () => {
+        // error シグナル検査が先に発火する (advisories 欠落でもあるので、どちらで止めても fail-closed)。
+        expect(() => load("pnpm-audit", JSON.stringify({ error: { code: "ENETUNREACH" } })))
+            .toThrow(/non-empty 'error' field/);
+    });
+
+    it("pnpm: error シグナル無しで advisories が欠落していても throw する", () => {
+        expect(() => load("pnpm-audit", JSON.stringify({ metadata: { totalDependencies: 0 } })))
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
+    it("error-bearing output は空コンテナでも throw する (impl-review R1 [Critical])", () => {
+        // 「有効 JSON だが取得失敗を示す形」が正当な 0 件として通ると偽グリーンになる。
+        expect(() => load("pnpm-audit", JSON.stringify({ advisories: {}, error: { code: "ENETUNREACH" } })))
+            .toThrow(/non-empty 'error' field/);
+        expect(() => load("composer-audit", JSON.stringify({ advisories: [], error: "registry unreachable" })))
+            .toThrow(/non-empty 'error' field/);
+        expect(() => load("pip-audit", JSON.stringify({ dependencies: [], errors: ["boom"] })))
+            .toThrow(/non-empty 'errors' field/);
+    });
+
+    it("空の error フィールドは通す (偽赤にしない)", () => {
+        expect(load("pnpm-audit", JSON.stringify({ advisories: {}, error: null }))).toEqual([]);
+        expect(load("composer-audit", JSON.stringify({ advisories: [], error: {} }))).toEqual([]);
+        expect(load("pip-audit", JSON.stringify({ dependencies: [], errors: [] }))).toEqual([]);
+    });
+
+    it("NORMALIZERS が全 AuditSource を網羅している (誤配線を型と assertion で固定)", () => {
+        const sources: AuditSource[] = ["pnpm-audit", "composer-audit", "pip-audit"];
+        expect(Object.keys(NORMALIZERS).sort()).toEqual([...sources].sort());
+    });
+});
diff --git a/scripts/audit-gate.ts b/scripts/audit-gate.ts
index efb77d0..851a85b 100644
--- a/scripts/audit-gate.ts
+++ b/scripts/audit-gate.ts
@@ -165,10 +165,144 @@ export function daysBetween(fromIso: string, toIso: string): number {
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
+    // **既知の失敗シグナルはコンテナが空でも拒否する** (impl-review R1 [Critical])。
+    // コンテナの型だけを見ると `{"advisories":{},"error":{"code":"ENETUNREACH"}}` /
+    // `{"dependencies":[],"error":...}` のような「有効 JSON だが取得失敗を示す形」が
+    // **正当な 0 件**として通ってしまう。空コンテナの許容 (真の 0 件を緑にする) と
+    // error-bearing output の拒否は必ずセットにする。
+    // 空の error (null / {} / []) は「エラー無し」を明示しただけなので通す (偽赤にしない)。
+    for (const field of ["error", "errors"]) {
+        const signal = obj[field];
+        if (signal === undefined || signal === null) continue;
+        const isEmpty =
+            (Array.isArray(signal) && signal.length === 0) ||
+            (typeof signal === "object" && !Array.isArray(signal) && Object.keys(signal).length === 0);
+        if (!isEmpty) {
+            throw new Error(
+                `${source}: output carries a non-empty '${field}' field — treating this as an acquisition ` +
+                    `failure, not as 'no advisories' (got keys: ${keys})`,
+            );
+        }
+    }
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
@@ -176,7 +310,8 @@ export function loadAuditJson(
     } catch (e) {
         throw new Error(`JSON parse failure in ${path}: ${(e as Error).message}`);
     }
-    return normalizer(json);
+    assertAuditSourceShape(source, json); // ← 配線点。ここを消すと unit テストが落ちる
+    return NORMALIZERS[source](json);
 }
 
 export function loadAcceptedAdvisories(path: string): AcceptedAdvisory[] {
@@ -490,9 +625,9 @@ async function main(): Promise<void> {
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
diff --git a/scripts/run-browser-test.contract.test.ts b/scripts/run-browser-test.contract.test.ts
new file mode 100644
index 0000000..9ea9f34
--- /dev/null
+++ b/scripts/run-browser-test.contract.test.ts
@@ -0,0 +1,407 @@
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
+    // `!=` を除外する: 反転条件 (`[ "${ppid}" != "1" ]`) は「orphan **以外**を kill する」
+    // という真逆の実装であり、素朴な `= "1"` 検査だと素通りしてしまう (impl-review R1 [Suggestion])。
+    if (!/"\$\{ppid\}"\s*(?<![!])=\s*"1"/.test(code) || /"\$\{ppid\}"\s*!=\s*"1"/.test(code)) {
+        violations.push("C5: PPID==1 の判定が消えている (または反転している)");
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
+        const childEnv: NodeJS.ProcessEnv = {
+            ...process.env,
+            PATH: `${join(sandbox, "bin")}:${process.env.PATH ?? ""}`,
+            // ライブラリが self-test 用として明示サポートする override。
+            // 本テストが pnpm test のロック下で走っても自己デッドロックしないために必要。
+            GLOBAL_TEST_LOCK_DIR: join(sandbox, "lockdir"),
+        };
+        // **既定値の契約テストなので、呼び出し元環境のレーン変数を必ず落とす**。
+        // 開発者が BROWSER_TEST_LANES を export していると「既定は 2 レーン・直列」の
+        // 検査が環境依存で偽赤になる。注入は options.env 経由の明示指定のみに限る。
+        delete childEnv.BROWSER_TEST_LANES;
+        delete childEnv.BROWSER_TEST_PROCESSES;
+        Object.assign(childEnv, options.env ?? {});
+
+        const result = spawnSync("bash", [join(sandbox, "scripts/run-browser-test.sh")], {
+            encoding: "utf-8",
+            env: childEnv,
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
+    it("C5: PPID 判定を反転すると違反を返す (orphan 以外を kill する真逆の実装)", () => {
+        const broken = mutate(realSource(), 'if [ "${ppid}" = "1" ]; then', 'if [ "${ppid}" != "1" ]; then');
+        expect(staticContractViolations(broken)).toContain(
+            "C5: PPID==1 の判定が消えている (または反転している)",
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
diff --git a/tests/js/architecture/ci-workflow-inventory.test.ts b/tests/js/architecture/ci-workflow-inventory.test.ts
new file mode 100644
index 0000000..3b148b4
--- /dev/null
+++ b/tests/js/architecture/ci-workflow-inventory.test.ts
@@ -0,0 +1,344 @@
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
+    if?: string;
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
+    it("W15: nightly (schedule) では supply-chain-audit だけが走ること", () => {
+        // on.schedule は workflow 全体を起動する。docs (review-checklist §6) が
+        // 「nightly は supply-chain gate の先行検知」と書いている以上、
+        // 他 job は schedule から明示除外され、**gate 自身は除外されない**ことを固定する。
+        for (const name of ["php", "frontend", "browser-tests"]) {
+            expect(job(workflow, name).if, `${name} が schedule から除外されていない`).toBe(
+                "github.event_name != 'schedule'",
+            );
+        }
+        // gate を nightly から外す (= 先行検知を殺す) 退行を止める
+        expect(job(workflow, "supply-chain-audit").if).toBeUndefined();
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
