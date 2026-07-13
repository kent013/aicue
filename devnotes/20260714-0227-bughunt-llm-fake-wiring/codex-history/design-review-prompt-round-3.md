Round 2 の施策5 の残 Warning に対応しました。再評価をお願いします。

## 対応サマリー

- **[Warning] Reflection で protected buildMessages() 依存**: 対応。reflection を廃止し、vendor の**公開契約**のみ使用する capture 方式へ変更。共通ヘルパ `captureMessages(callable $runOnce)` は `CannedPromptFakeRegistrar::install()` 下で対象 prompt を **1 回だけ** `executeSync()` し、`Prompt::getFake()->recorded()`（PromptFake の public API）の唯一 entry から `messages` を取得する。1 ケース 1 実行のため record 順序・混入は生じない。protected `buildMessages()` / reflection には一切依存しない。

- **[Warning] 5-4 リーク assertion が例外時に到達しない**: 対応。リーク検知 assertion を**テスト本体の finally 後ではなく `afterEach` に移動**。`afterEach` で `Prompt::stopFaking()` を実行し、各テスト境界で `Prompt::isFaking()===false`（static リークなし）を保証する。テスト本体が例外で落ちても必ず実行される。

- **[Suggestion] 5-6 stray 再確認**: 対応（安全側へ）。新規に実 stray を発生させるケースは追加せず、既存 `StrayLlmCallGuard` 単体テスト群が green のままであることの維持確認に留める。

## 更新後の該当テストセクション（全文）

**共通テストヘルパ（vendor 公開契約 `recorded()` による capture 方式）**: `captureMessages(callable $runOnce): array<int, Message>` — `CannedPromptFakeRegistrar::install()` した状態で、渡された「対象 prompt を 1 回だけ `executeSync()` する」クロージャを実行し、`Prompt::getFake()->recorded()`（vendor の公開 API）の唯一 entry から `messages` を取得する。1 ケース 1 実行のため record 順序・他メッセージ混入は発生しない。reflection / protected `buildMessages()` には依存しない。取得した `messages` を `CannedPromptResponses::forMessages()` / signature 一致検証に渡す。

### 5-1. canned DTO 通過テスト（主保証）
各実 factory について「build → install → `executeSync()` → 該当 DTO の `fromLlmText()` が成功」。テスト名は「{prompt} の canned が {DTO}::fromLlmText を通過する」形式。afterEach で `Prompt::stopFaking()`、stray call 0、防御的 `Http::fake`。

### 5-2. signature 衝突防止テスト（登録 prompt allowlist に対する 1:1）
- 登録対象 4 factory を dataset とし、各ケースで `captureMessages()` 経由で 1 回だけ `executeSync()` → capture した system message に対し signature 一致数が ちょうど 1・一致 signature が期待どおりであることを検証。
- signature のペアワイズ非部分包含を assert。
- 「全 YAML 総数 = signature 件数」の等値検証はしない（未登録判定は 5-3 の fail-fast で担保）。
- afterEach で `Prompt::stopFaking()`。

### 5-3. 未登録 / 曖昧 fail-fast テスト
- signature を含まない `SystemMessage('未知の役割')` のみ → `RuntimeException`（0 件一致）。
- 2 signature を同時に含む messages → `RuntimeException`（曖昧）。
- 例外に system text 先頭 200 字 + 一致 signature が含まれることを assert。

### 5-4. provider 発火条件テスト
- env（`$this->app['env']`）と config（`testing.fake_externals`）を try/finally で原値復元。
- `bughunt.local`∧true → `isFaking()===true` + canned 応答（stray 0）
- `testing`∧true → `isFaking()===false`（Prompt::$fake に触れない）
- `local`∧true → `isFaking()===false`
- `false` → `isFaking()===false`
- **リーク検知は `afterEach`** に置き（`Prompt::stopFaking()` + 各テスト境界で `isFaking()===false`）、本体例外時も必ず実行。

### 5-5. AI 解析 end-to-end 統合テスト（queue + materialize）
`pipelineContext()` パターンで context 構築 → install 下で `AnalysisPipeline::run($job->id)` → ジョブ succeeded / cuts materialize（step1+point1）/ status 遷移 / stray 0 / Http stray なし。afterEach `Prompt::stopFaking()`。

### 5-6. 既存経路非破壊 + stray guard 健全性
- 既存 Browser lane / `StrayLlmCallGuard` 系 / `ExampleSummaryPromptTest` / `AnalysisPipelineTest` が green のまま（改名追随のみ・挙動不変）。
- 新規に実 stray を発生させるケースは追加せず、既存 `StrayLlmCallGuard` 単体テスト維持確認に留める。
- `composer phpstan` / `vendor/bin/pint --test` green。
