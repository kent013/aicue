全体判定: **APPROVED**

## 1. 使命との整合性

[Suggestion] SOP 起点の中核導線における誤表示と操作詰まりを、根本原因に限定して解消している。

## 2. 禁止事項違反

[Suggestion] 回帰テストが具体化され、テストファーストおよび禁止事項 #1 を満たせる設計になっている。その他の禁止事項にも抵触しない。

## 3. 実現可能性

[Suggestion] Svelte 5 の `$effect` で `hasDocument` の false→true を検知する方式は実現可能。ポーリング由来の state とも干渉しない。

## 4. 期待効果の妥当性

[Suggestion] 効果を「手順書なし 422 の残留解消」に限定しており、設計から合理的に期待できる。

## 5. リスク

[Suggestion] 402、session expired、`currentJob/status` を維持するため、副作用は十分抑制されている。

将来別用途の 422 が追加される可能性に備え、`422` だけで一律に `missing_document` と判定せず、レスポンスの安定した `code` を優先することが望ましい。現状、この endpoint の 422 が手順書不足専用という契約なら問題ない。

## 6. スコープの適切さ

[Suggestion] `AnalysisPanel.svelte` だけに限定した変更は適切。バックエンド変更や一般的な overlay 管理への拡張も不要。

## 7. 型安全性

[Suggestion] `StartErrorKind` union による判定は、文言比較より安全で保守性も高い。`startErrorKind` の初期型を `StartErrorKind | null` と明示すれば問題ない。