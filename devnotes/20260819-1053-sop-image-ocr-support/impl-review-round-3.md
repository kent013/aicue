CHANGES_REQUESTED

Critical が1件残っています。

- `PromptWindowScanner` が配列 callable を検出できない状態を「既知の限界」として受容していますが、これは媒体 DTO の検証済み性を実際に迂回できます。

```php
$factory = [ImageAnalysisMediaData::class, 'fromValidated'];
$media = $factory('image/jpeg', $unvalidatedBytes, 1, 1, 1);

return SopExtractFromMediaPrompt::make($media, $context);
```

この経路は `AnalysisMediaValidator` を通らず、未検証バイトを正規の `loadWithMedia()` 経路へ渡せます。したがって「媒体 DTO は `AnalysisMediaValidator` だけが生成できる」という不変条件が成立しません。

[PromptDefenseWindowGateTest.php](/workspace/.claude/worktrees/tasks/T234/tests/Architecture/PromptDefenseWindowGateTest.php) の「検出できないことを示す」テストは、検出力の限界を記録しているだけで、Critical の解消にはなりません。AGENTS.md の fail-closed 規約上も、保護対象の操作を実行可能な構文を docblock だけで保証範囲外にはできません。

必要な対応は次のとおりです。

- `[ImageAnalysisMediaData::class, 'fromValidated']` と `PdfAnalysisMediaData` の同形を、呼び出し時のデータフロー解析ではなく、callable の生成時点で禁止・検出する。
- `Image` / `Document` の vendor factory callable も同様に検出する。
- 現在の「検出されないことを期待する」テストを、違反として検出される負例へ変更する。
- `$class::{$method}()`、完全修飾名・部分修飾名による動的メソッド呼び出しも fail-closed にする。現在の `dynamicMethodNameCalls()` はこれらを明示的に取りこぼしています。
- 継続中の `composer test` が最終的に全件 green であることも完了条件として確認する。

OCR 本文量下限の追加、スキーマ抽出 docblock、手順0件の分類に関する反論は妥当です。しかし上記は未検証媒体を LLM へ送れる具体的な迂回経路なので、最終ラウンドでも APPROVED にはできません。