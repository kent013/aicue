**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、帰属 metadata の必須性と gate の実装可能性に重要な穴があります。このまま実装すると「窓口方式にしたが、窓口自体が抜け道になる」リスクがあります。

### A. 防御設定の集約: APPROVE

[Suggestion] `max_untrusted_bytes` は「正常運用では当たらない最後の砦」という説明で妥当です。`manual.analysis_max_text_bytes` との大小固定も良いです。

### B. 入力の無害化: REQUEST_CHANGES

[Warning] 無効 UTF-8 時に `Assert::string()` が `InvalidArgumentException` を投げますが、`userMessageFor()` で `UntrustedInputRejectedException` として扱われません。結果として汎用文言になり、拒否理由の扱いが分散します。

修正案: `preg_replace()` が `null` の場合は `UntrustedInputRejectedException::invalidEncoding()` を投げる設計にしてください。例外 message に入力本文を載せない方針も維持できます。

[Warning] `removedCharacters` は `mb_strlen` 差分なので、CRLF 正規化や不正 UTF-8 の影響を受ける可能性があります。現状は「除去件数」であり「正規化件数」ではないため、ログの意味が曖昧です。

修正案: `removedCharacters` は `preg_match_all()` で除去対象だけを数えるか、名前を `structuralChanges` などに変えて意味を広げてください。

### C. 応答カナリア: APPROVE

[Suggestion] 限界を明示してテストで pin する方針は良いです。これは検出器ではなく「system prompt 漏洩時の fail-closed」という位置づけで正しく書けています。

### D. 窓口と実行単位: REQUEST_CHANGES

[Critical] `PromptDefense::load(..., ?LlmCallContextData $context = null)` は、AGENTS.md の「実行経路を持つ prompt factory は `LlmCallContextData` 必須」という不変条件を弱めます。PHPStan で必須引数にするという説明とも矛盾しています。任意引数にすると、将来の本番 prompt が metadata なしで通る抜け道になります。

修正案: 本番用 `load()` は `LlmCallContextData $context` を必須にしてください。見本 prompt 用は `loadUnattributedExample()` のような別メソッドに分け、呼び出し可能な factory を inventory で明示 exempt にしてください。

[Warning] `new GuardedPrompt($prompt, ...)` の `Prompt` 型が曖昧です。現行 factory は `TextPrompt` を返しており、`withMetadata()` 後の戻り値型も vendor 実装に依存します。`GuardedPrompt` の private property 型が実際の `TextPrompt` と合わない可能性があります。

修正案: vendor の実型に合わせて `TextPrompt` を保持するか、共通 interface があるならそれを使ってください。少なくとも PHPStan level 10 で `Prompt::load()` と `withMetadata()` の戻り値型を確認する前提を設計に明記してください。

[Warning] `UserInput` / `UntrustedTextSanitizer` / `PromptCanary` への参照を窓口 1 ファイルだけに制限する設計だと、Unit test や既存 contract test が直接参照できなくなります。

修正案: gate の走査対象を `app/` に限定する、または test 側の参照は明示的に除外してください。

### E. factory 4 本と YAML 4 本の窓口化: REQUEST_CHANGES

[Critical] `ExampleSummaryPrompt` だけ `context: null` とする設計は、D の nullable context 問題と同じです。見本 prompt の exempt は「窓口の通常 API を緩める」理由にはなりません。

修正案: 見本専用の明示 API を作り、`PromptUntrustedInputContractTest` で「この factory だけ attribution exempt」と pin してください。

[Warning] YAML 変数集合の 1 対 1 検査で `{{ $llm_canary }}` 以外を全 untrusted とみなす設計は、将来 YAML に固定値・enum・locale・schema version のような trusted 変数が必要になった瞬間に破綻します。現時点で trusted 入口を作らない判断自体は妥当ですが、エラーメッセージと docs に「trusted 変数を足すなら設計拡張が必要」と明確に出すべきです。

修正案: gate 失敗時の文言に `trusted variable requires a new explicit entrypoint + inventory` を含めてください。

### F. パイプラインの失敗写像: REQUEST_CHANGES

[Warning] `UntrustedInputRejectedException` を常に `tooLarge()` に写像する設計になっていますが、B で無効 UTF-8 も同系統の拒否にするなら文言が不正確になります。

修正案: `UntrustedInputRejectedException` に reason enum または named constructor ごとの user message を持たせ、`tooLarge` と `invalidEncoding` を分けてください。

[Warning] `PromptResponseRejectedException` は非 transient で正しいですが、「同じ入力で再実行しても同じ結果になる」と断定しすぎです。カナリアは毎回変わるため、同じ入力でも漏洩するとは限りません。ただし安全上、再試行しない判断は妥当です。

修正案: コメントを「安全性違反の疑いがあるため課金リトライしない」に変更してください。

### G. 窓口通過の構造検査 gate: REQUEST_CHANGES

[Critical] #8 の「YAML Blade 変数抽出を正規表現で集める」は脆いです。Blade 式は `{{ $foo }}` 以外にも空白、関数、配列アクセス、コメント、エスケープ形式があり、正規表現だけでは「保証内容で揃える」という裁定の強さに届きません。

修正案: 最低限、対象 YAML の許容構文を gate で固定してください。つまり prompt YAML 内の変数参照は `{{ $name }}` のみ許可し、それ以外の `$` 参照や `{!! !!}` を禁止するテストを追加してください。その上で正規表現抽出なら実用上成立します。

[Warning] #4 の「`UserInput` 参照が窓口 1 ファイルだけ」は、`PromptUntrustedInputContractTest` が `UserInput` を reflection で検査する設計と衝突します。

修正案: app code のみを対象にするか、`tests/Architecture/PromptUntrustedInputContractTest.php` は許可してください。

### H. 集約設定の gate: APPROVE

[Suggestion] `env(` の検査はコメント除外を明記しており妥当です。`config()->integer()` 呼び出し側の pin まで入れるなら十分です。

### I. 既存 gate 3 本の射程更新: REQUEST_CHANGES

[Warning] `PromptGuardrailTest` の走査根を `config/` まで広げる一方で、`config/llm-defense.php` にはコメントとして `env(` が出ます。別 gate では token 正規化すると書いていますが、こちらの scanner もコメント・文字列を除外しないと偽陽性が出ます。

修正案: 既存の `PrismDirectDispatchScanner` も `PhpReferenceScanner` ベースへ寄せ、コメント・文字列リテラルを母集団から外してください。

[Warning] `PromptUntrustedInputContractTest` が `GuardedPrompt::$prompt` を reflection で辿る設計は、`GuardedPrompt` の private 封じ込め方針と緊張します。テストで private を読むこと自体はあり得ますが、vendor 内部 property 名にも依存します。

修正案: test-only の helper を `tests/Support` 側に閉じる、または既存と同じ vendor property 依存であることを docs/test コメントに明記してください。

### J. 実行時テストと攻撃コーパス: APPROVE

[Suggestion] カバレッジは十分です。特に「LLM を 1 回も呼ばない」「応答を返さない」「ログに本文を出さない」は入れるべきテストです。

### K. 規約文書更新: APPROVE

[Suggestion] `AGENTS.md` の更新は必要です。特に「UserInput 経由」から「PromptDefense 経由」への変更は、実装と同じ PR に含めるべきです。

**必須修正の要点**

1. `PromptDefense::load()` から nullable context を消し、見本 prompt は専用 exempt 経路に分離する。
2. 無効 UTF-8 を `UntrustedInputRejectedException` 系で扱い、user message を理由別に分ける。
3. YAML 変数抽出 gate は、許容 Blade 構文を固定してから正規表現抽出する。
4. gate の走査対象と test 側参照の除外条件を整理し、自己矛盾をなくす。