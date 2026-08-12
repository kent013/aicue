**全体判定: APPROVE**

Critical / Warning はありません。提示差分は詳細設計の意図に沿っており、「古い」と断定せず、観測できる 2 値だけで「生成時点の黒背景数」と「現在は未採用ゼロ」を分けて説明できています。

**docs/architecture.md: APPROVE**

[Suggestion] 「現在 coverage は『上書き』ではなく『表示の文脈』」という追記は妥当です。保証しないものも明記されており、文言も誇張していません。

**resources/js/components/features/manual/RenderPanel.svelte: APPROVE**

[Suggestion] 1 つの `<p>` に条件文を継ぎ足す実装は、この内容なら読み上げ・可読性ともに問題ない判断です。文としては連続した注記で、条件部分も追加説明なので、別段落に分ける必然性は低いです。

[Suggestion] 文言も「生成時点で」「現在のシナリオでは未採用のカットはありません」「再生成してください」に留まっており、「古い」「最新でない」といった未観測の断定をしていません。設計どおりです。

**tests/js/components/features/manual/RenderPanel.test.ts: APPROVE**

[Suggestion] テストヘルパー `stalePreviewProps` の戻り値型注釈を外した判断は妥当です。`typeof baseProps` は `null` や `never[]` に狭まりすぎるため、この用途では不適切です。将来的に型を固定したいなら `RenderPanel` の props 型へ `satisfies` させる選択肢はありますが、今回の変更必須事項ではありません。

[Suggestion] mutation 実測は十分です。特に `finishedJob` 依存を入れた mutation が狙った契約で落ちており、詳細設計の「完成動画の有無で判定しない」を退行検出できます。