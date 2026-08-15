**指摘**

`tests/Feature/Llm/PromptDefenseTest.php`
[Warning] ログ漏洩テストが空振りし得ます。  
`不可視文字の除去はログに件数だけを残す` の `! in_array('機密の手順', $context, true)` は、context に `input => '機密の手順です'` のような値が入っても検出できません。設計 J の「ログに untrusted 文字列そのものが出ない」を固定するなら、context 全体を文字列化して substring 検査するなど、部分一致で判定する必要があります。実装本体は現状 `prompt` / `variable` / `removed_characters` のみなので漏洩していませんが、回帰検出力が弱いです。

`docs/architecture.md`
[Warning] 「経路 (これ以外の道は構造的に存在しない)」は少し強すぎます。  
同じ節の「保証しないもの」で、動的クラス名や文字列キーの container 解決は gate が沈黙すると明記しているため、表現が内部で衝突しています。「通常の静的参照で許される経路」などに弱めるのが安全です。

**ファイルごとの判定**

`app/Support/Llm/*`, `app/Exceptions/Llm/*`, `app/Enums/Llm/*`: APPROVED  
設計 B〜D と整合。無害化、カナリア、窓口、実行単位はいずれも fail-closed で、untrusted 本文や合言葉を例外 message に載せない作りです。

`app/Prompts/*.php`, `resources/prompts/*.yaml`: APPROVED  
旧 `Prompt::load()` / `UserInput::from()` 直呼びは撤去され、4 factory が窓口経由に統一されています。合言葉 slot も system 側のみです。

`app/Services/Manual/AnalysisPipeline.php`, `app/Exceptions/Manual/AnalysisFailedException.php`: APPROVED  
拒否理由の写像は網羅 match で、`isTransient()` を触らない deny-by-default 方針とも一致しています。

`config/llm-defense.php`: APPROVED  
構造的しきい値のみで env / on-off switch はありません。

`tests/Architecture/*`, `tests/Support/Llm/PromptWindow*`, `tests/Support/Prompts/PrismDirectDispatchScanner.php`: APPROVED  
設計 G〜I の母集団分離、5 根走査、合成負例、YAML 変数突き合わせは概ね実装されています。

`tests/Feature/Llm/PromptDefenseTest.php`: CHANGES_REQUESTED  
上記 Warning のログ漏洩検査だけ修正してください。ここは設計 J の明示要件なので、テストが実際に漏洩を捕まえる形にする必要があります。

`tests/Unit/Support/Llm/*`, `tests/Support/Llm/PromptInjectionCorpus.php`, `tests/Support/Llm/GuardedPromptInspector.php`, `tests/Support/Llm/CanaryEchoPromptFake.php`: APPROVED  
分類表、拒否、カナリア限界、reflection 閉じ込めは設計通りです。

`AGENTS.md`, `docs/app-integration-guide.md`, `docs/template-divergence.md`, `docs/testing-browser.md`: APPROVED  
規約更新は施策 K と整合しています。

**全体判定: CHANGES_REQUESTED**