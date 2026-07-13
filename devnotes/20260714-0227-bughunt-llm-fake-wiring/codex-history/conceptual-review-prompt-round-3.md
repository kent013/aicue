Round 2 の残り 2 Warning に対応しました。再評価をお願いします。

## 対応サマリー

- **[Warning] queue worker + materialize 統合テスト欠落**: 対応。必須テストに「`RunManualAnalysis` を実際に dispatch・実行 → canned fake 下で 3 DTO 全通過 → ジョブ成功 → scenario materialize → 実 API 未到達 (StrayLlmCallGuard で stray call 0)」の AI 解析 end-to-end 統合テストを追加 (テスト方針 5)。

- **[Warning] signature 衝突の全 prompt 横断保証**: 対応。必須テストに「`resources/prompts/*.yaml` から全実 prompt を列挙し、各 prompt の render 済 system message に対し登録済み signature の一致数を数える。登録対象はちょうど 1 件・未登録対象は 0 件に一致することを検証する衝突防止テスト」を追加 (テスト方針 4)。signature は部分一致で誤爆しない一意句を選ぶ前提を明記。

## 更新後の「検証・テスト方針」セクション全文

必須成果物として以下の回帰・統合テストを設計に織り込む (テストなしの完了報告は禁止事項):

1. **DTO 通過統合テスト (主保証)**: 4 プロンプト (`sop-extract` / `work-decomposition` / `scenario-generation` / `example-summary`) それぞれについて「実 factory で prompt を build → registrar で canned fake install → `executeSync()` → 該当 DTO の `fromLlmText()` が成功 (example-summary は非空 string)」を 1 本で担保。canned JSON の DTO 追随と signature 1:1 対応を同時に固定する。

2. **provider 発火条件テスト**: `bughunt.local` 環境 ∧ flag=true で boot() すると `Prompt::isFaking()===true` かつ代表 prompt が canned を返す (stray call 0)。`testing` / `local` 環境 ∧ flag=true では boot() が `Prompt::$fake` に触れない (`Prompt::isFaking()===false`)。flag=false では触れない。既存 `FakeExternalsServiceProviderTest` の env 差し替えパターン (`$this->app['env']` を try/finally で復元 + `Prompt::stopFaking()`) を踏襲。

3. **未登録 prompt fail-fast テスト**: どの signature にも一致しない system message を canned 解決に渡すと `RuntimeException` (silent green 防止)。

4. **signature 衝突防止テスト (全 prompt 横断)**: `resources/prompts/*.yaml` から全実 prompt を列挙し、各 prompt を render した system message に対し登録済み signature の一致数を数える。登録対象 prompt はちょうど 1 件に一致、未登録対象 prompt は 0 件に一致することを検証。signature は部分一致で誤爆しない一意句を選ぶ。

5. **AI 解析 end-to-end 統合テスト (queue + materialize)**: `RunManualAnalysis` を実際に dispatch・実行し、canned fake 下で 3 DTO 全通過 → ジョブ成功 → scenario materialize まで到達すること、かつ実 API 未到達 (StrayLlmCallGuard で stray call 0) を確認する。bughunt の成功判定 (HTTP + queue worker 双方で完走) を代表する。

6. **既存経路非破壊**: 既存 Browser lane (tests/Pest.php) と StrayLlmCallGuard 系テストが緑のまま (改名追随のみで挙動不変)。
