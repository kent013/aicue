**全体判定**
- **CHANGES_REQUESTED**

**施策1: 改名 + signature 解決**
- 判定: **REQUEST_CHANGES**
- [Critical] `resources/prompts/*.yaml` 件数と signature 件数の一致を強制する設計は、将来 prompt 追加時に「LLM fake 対象外 prompt」まで誤って失敗させる可能性があります。  
  修正案: 「対象 prompt の allowlist（4件）」を明示し、その集合に対して 1:1 対応を検証してください（全 YAML 総数との一致は外す）。
- [Warning] `forMessages()` の曖昧判定は良いですが、例外文面に「実際に抽出した system message の要約」がないと調査コストが高いです。  
  修正案: 例外に先頭 N 文字の system text を含め、どの signature と競合したかを出力。
- [Suggestion] signature は役割文の自然文依存で drift しやすいです。  
  改善案: YAML 側に machine-friendly な固定トークン（例: `[PROMPT_SIGNATURE:sop-extract]`）を system に埋める方式へ将来移行を検討。

**施策2: S3 canned 追加**
- 判定: **APPROVE**
- [Suggestion] `JSON_THROW_ON_ERROR` 使用は適切。DTO 制約変更に備えて、各 canned に対し「どの DTO 制約を満たすか」のテスト名を明示すると保守性が上がります。

**施策3: Provider boot で bughunt のみ fake**
- 判定: **APPROVE**
- [Warning] `Prompt::$fake` が static のため、同一プロセス内での後続処理に残留しうる点はテストで固定すべきです。  
  修正案: Provider テストに「boot 後に `Prompt::stopFaking()` が呼ばれる運用（finally）」を必須化し、リーク検知テストを追加。
- [Suggestion] `register()` と `boot()` で allowlist 意図が分かれるため、定数名を `PAYMENT_FAKE_ENVIRONMENTS` / `LLM_FAKE_ENVIRONMENTS` のように対比させると誤読を防げます。

**施策4: tests/Pest.php 改名追随**
- 判定: **APPROVE**
- [Suggestion] `CannedPromptFakeRegistrar` の install/uninstall を Browser lane 専用であることをコメント化（短文）すると将来の誤利用抑止に有効。

**施策5: テスト計画**
- 判定: **REQUEST_CHANGES**
- [Critical] 5-2 の「factory を executeSync して recorded から system を取る」方式は、fake 実行の副作用（record 順序・他メッセージ混入）に依存しすぎて不安定です。  
  修正案: 可能なら prompt レンダリング直後の message 配列を直接検証するテストヘルパを用意し、recorded 依存を最小化してください。
- [Warning] 「既存 Feature/Browser の Prism fake 経路を壊さない」観点で、回帰テストに `ExampleSummaryPrompt` の既存経路固定が明示されているのは良いが、`StrayLlmCallGuard` 連携の失敗時メッセージ確認まで入れるとさらに堅牢です。  
  修正案: stray 発生時に fail-fast することを 1 ケース追加。
- [Warning] Provider 発火条件テストは `env` 差し替えだけでなく `config('testing.fake_externals')` の明示復元が必要です。  
  修正案: `Config::set` を使う場合は try/finally で原値復元を必須化。

**観点別サマリ**
- 正確性/エッジケース: 概ね良好、ただし signature 解決テストの設計が過剰拘束。
- 既存整合性: 改名範囲を限定しており良好。
- PHPStan Lv10: 型の絞り込み方針は妥当。
- テスト網羅: 方向性は良いが、5-2 の検証手段を安定化すべき。
- DTO/JsonResource: 本件は内部 fake で逸脱なし。
- Inertia/API 使い分け: UI/API 変更なしで問題なし。
- 後退リスク: Browser lane 非破壊の意図は適切。
- 波及変更: TS/API Resource なし判断は妥当。
- セキュリティ: bughunt 限定 fake は妥当、漏洩防止意図も良い。
- DESIGN/Atomic: UI 変更なしで準拠。

**最終結論**
- 設計の主方向は適切で、特に `bughunt.local` 限定の fake 配線方針は妥当です。  
- ただしテスト設計の2点（「全 YAML 件数一致」拘束、`recorded` 依存の不安定検証）は将来保守で破綻リスクがあるため、**修正後に APPROVED** が妥当です。