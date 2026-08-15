# Round 3: Round 2 の指摘への対応

Round 2 の [Warning] 1 件を**対応した**。反論・見送りは無い。

## docs/architecture.md §LLM 呼び出しの帰属 の保証を実装へ揃えた

指摘のとおり、同一ファイル内で保証の強さが食い違っていた。
「帰属を迂回する経路が構造的に存在しない」を
「**静的に書かれた通常の呼び出し経路では帰属の迂回ができない**」へ弱め、
反射・動的クラス名・文字列キーだけの container 解決には沈黙することと、
その正本 (§LLM プロンプト防御の窓口方式 の「保証しないもの」) への参照を足した。
併せて、この段落が引いていた禁止事項 5 の射程を
「factory 経由のみ」→「factory → 窓口 → 実行単位の 1 本道のみ」へ更新した
(AGENTS.md 側の更新と表現を揃えるため)。

```diff
 ### LLM 呼び出しの帰属 (記録側の配線)
 
 **実行経路を持つ** `app/Prompts/` の factory は `LlmCallContextData` を**必須引数**で受け、
-`->withMetadata($context->toMetadata())` で `organization_id` / `user_id` /
+窓口 (`PromptDefense::load()`) へ渡す。窓口が `withMetadata()` で `organization_id` / `user_id` /
 `subject_type` / `subject_id` を載せる。AI 解析では subject = **`VideoManual`**
-(費用を知りたい単位は成果物であって job ではない)。禁止事項 5 (LLM 呼び出しは factory 経由のみ) が
-既に強制しているため、**帰属を迂回する経路が構造的に存在しない**。記録層の列は 1 本も増やしていない。
+(費用を知りたい単位は成果物であって job ではない)。禁止事項 5 (LLM 呼び出しは factory → 窓口 →
+実行単位の 1 本道のみ) を gate が強制しているため、**静的に書かれた通常の呼び出し経路では
+帰属の迂回ができない**。記録層の列は 1 本も増やしていない。
+なお gate が見るのは静的な出現だけで、反射・動的に組み立てたクラス名・文字列キーだけの
+container 解決には沈黙する (§LLM プロンプト防御の窓口方式 の「保証しないもの」)。
 
 **適用範囲を誇張しない**: 帰属の対象を持たない見本 (`ExampleSummaryPrompt`。呼び出し元が無い) は
 `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**してある。
@@ -2065,3 +2068,107 @@ ### 保証しないもの (誇張しない)
 - **撮影 PWA からの戻り導線は `Capture/Show` ヘッダーの常設リンクとして実装済み** (T155。
   §撮影 PWA の運用契約)。ただし**完成動画へ直接着地するわけではない** — 行き先はマニュアル
   詳細画面で、そこに完成動画が出るかは本節の認可条件がそのまま決める (撮影者には出ない)
+
+## LLM プロンプト防御の窓口方式 (T169 / 家系の裁定 AG-028)
+
+外部由来の文字列 (SOP 本文と、そこから生まれた前段 LLM 出力の JSON) が prompt へ入る経路を
+**1 本道**に畳み、その道の上で無害化・境界化・応答検査を行う。
+
+### 経路 (静的に書ける道はこれだけ)
+
+```
+app/Prompts/{Sop,WorkDecomposition,ScenarioGeneration,ExampleSummary}Prompt
+        │  make(生 string, LlmCallContextData)
```

## 再検証結果

- `composer test`: 4907 tests / 4905 passed / 0 failed / 2 skipped
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed

他に指摘があれば挙げてほしい。無ければ全体判定を示してほしい。
