# 対応マトリクス: design-review Round 1 (判定 CHANGES_REQUESTED)

## [Critical] D/E: `PromptDefense::load()` の nullable context が不変条件を弱める
- 判断: 対応する
- 根拠: 指摘のとおり。AGENTS.md 禁止事項 5 は「実行経路を持つ prompt factory は
  `LlmCallContextData` を**必須引数**で受ける」であり、窓口に既定 null を置くと
  新しい本番 prompt が帰属なしで通る抜け道になる。
- 対応内容: 公開 API を 2 本に分ける。
  - `load(string $template, array $untrusted, LlmCallContextData $context): GuardedPrompt` (必須)
  - `loadUnattributed(string $template, array $untrusted): GuardedPrompt` (帰属の対象を持たない見本専用)
  さらに窓口 gate で **`loadUnattributed` の呼び出し site を `app/Prompts/ExampleSummaryPrompt.php`
  ただ 1 件へ名指し pin** する (新しい factory が exempt 側へ滑り込めない)。
  `PromptUntrustedInputContractTest` の帰属 exempt 登録 (空配列) は従来どおり維持。

## [Critical] G: YAML の Blade 変数抽出を正規表現に頼るのは脆い
- 判断: 対応する
- 根拠: 妥当。抽出の正しさが「YAML に書ける構文の狭さ」に依存しているのに、
  その狭さがどこにも固定されていなかった。
- 対応内容: **prompt YAML の Blade 構文契約**を `PromptYamlContractTest` (雛形の契約を持つ既存 gate) へ
  追加する。書けるのは 2 形だけ:
  (i) 単純変数展開 `{{ $name }}`、(ii) 防御指示の静的呼び出し
  `{{ \Kent013\PrismPrompt\Values\DefensiveInstructions::forUserInput(Ja)?() }}`。
  それ以外の `{{ … }}` の中身・`{!! !!}`・`@directive`・裸の `$` を禁止する。
  窓口 gate の 1 対 1 検査は**この契約の上に立つ**ことを明記する
  (構文契約 = 雛形 gate / 変数集合の一致 = 窓口 gate。役割は重ならない)。

## [Warning] B: 無効 UTF-8 の扱いが `Assert` 由来の汎用例外になる
- 判断: 対応する
- 対応内容: `preg_replace` が null なら `UntrustedInputRejectedException::invalidEncoding()` を投げる。
  例外に理由 enum (`UntrustedInputRejectionReason`: `TooLarge` / `InvalidEncoding`) を持たせ、
  `AnalysisPipeline::userMessageFor()` は**網羅 match** で文言を分ける (到達不能な else を作らない)。
  `AnalysisFailedException::unreadableEncoding()` を 1 本足す。

## [Warning] B: `removedCharacters` の意味が曖昧 (mb_strlen 差分)
- 判断: 対応する
- 対応内容: `preg_match_all(REMOVE_PATTERN)` で**除去対象だけ**を数える。改行正規化は件数に含めない
  (ログの意味を「不可視文字を n 文字除去した」に限定する)。

## [Warning] D: `GuardedPrompt` が保持する vendor 型が曖昧
- 判断: 対応する
- 根拠: `Prompt::load()` は宣言上 `self` を返し (docblock は `TextPrompt`)、
  `withMetadata()` は `static` を返す。基底型で保持するのが静的解析上いちばん素直。
- 対応内容: private プロパティの型を `Kent013\PrismPrompt\Prompt` (基底) にし、
  `executeSync(): mixed` を `Assert::string()` で string へ絞る根拠を docblock に書く。

## [Warning] D/G: 「`UserInput` 参照は窓口 1 ファイルだけ」がテストと衝突する
- 判断: 対応する (指摘の前提を明確化)
- 根拠: 走査根は `app/` であり `tests/` は母集団に入らない。設計書がそれを書いていなかった。
- 対応内容: 窓口 gate の走査根を `app/` **のみ**と明記し、
  「テストは vendor / 窓口の内部へ reflection で触ってよい (母集団外)」を設計に書く。

## [Warning] E: trusted 変数が必要になった瞬間に破綻する
- 判断: 対応する
- 対応内容: 窓口 gate #8 の失敗メッセージに
  「trusted 変数を足すときは入口・字句 gate・目録を同じ PR で足すこと」を明記する
  (`docs/template-divergence.md` の 4 条件と同文)。

## [Warning] F: 「同じ入力で再実行しても同じ結果」は断定しすぎ
- 判断: 対応する
- 根拠: 合言葉は毎回変わるので、再実行で漏洩が再現するとは限らない。
- 対応内容: コメントと利用者向け文言の根拠を「安全性違反の疑いがあるため、課金してまで
  再試行しない」に改める (文言自体は「確認して修正してから再実行」で維持)。

## [Warning] I: `PrismDirectDispatchScanner` を `PhpReferenceScanner` ベースへ寄せよ
- 判断: 一部反論 (前提が事実と異なる) / 一部対応
- 根拠 (反論): 現行 `PrismDirectDispatchScanner` は**既に `token_get_all` ベース**で、
  コメント・docblock・文字列リテラル中の出現を無視する。既存テストが
  「コメント / 文字列リテラル中の `Prism::text` を誤検出しない」ことを固定している。
  したがって `config/` を走査根に加えてもコメント中の `env(` / `Prism::text` で
  偽陽性は出ない。振る舞い保存の観点でも、動いている scanner の全面移植は
  今必要のない変更 (思考原則 2)。
- 対応内容 (対応): 走査根の拡張に伴う API 変更 (`appDir()` → `roots()`) と、
  `ExternalSeamInventory` の LLM 委譲 (`gateTestName` / `rationale` / `livenessProbe`) の
  同時更新を施策 I に明記する。

## [Warning] I: vendor の private プロパティ名への reflection 依存
- 判断: 対応する
- 対応内容: reflection を `tests/Support/Llm/GuardedPromptInspector.php` 1 箇所へ閉じ込め、
  `PromptUntrustedInputContractTest` と実行時テストの双方がそれを使う。
  vendor 側の property 名が変わったら 1 ファイルだけが壊れる。

## [Suggestion] A / C / H / J / K
- 判断: そのまま維持 (指摘は肯定的)。H の「`config()->integer()` 呼び出し側の pin」は
  施策 H の検査 2 (読み手クラスの双方向 pin) が既にその内容である旨を Round 2 で説明する。
