# 対応マトリクス: design-review Round 5

Critical はゼロ。Warning 1 件 + Suggestion 1 件、両方対応した（反論なし）。

## [Warning/C] fixture 例の `$page` が未定義（転記すると実行時エラーになる）
- 判断: **対応する**
- 根拠: 指摘のとおり。`visit(...)->assertPathIs(...)` の戻り値を受けずに `$page->script(...)` を
  書いていた。設計書のコード例はそのまま実装へ転記されるので、これは実害のある誤りである。
- 対応内容: `$page = visit('/onboarding/checkout?plan=starter')->assertPathIs('/onboarding/checkout');`
  と戻り値を変数に保持してから `expect($page->script('window.location.search'))->toBe('');` する形に修正した。

## [Suggestion] 実装規模の「frontend 5 ファイル改修」は 6 ファイル
- 判断: **対応する**
- 根拠: 数え間違い。変更対象一覧そのものは網羅されていた（影響調査漏れではなく集計表記の誤り）。
- 対応内容: 「frontend **6** ファイル改修（`CutNavigator` / `Show` / `RenderPanel` /
  `TakePreviewDialog` / `TakeStrip` / `Checkout`）+ 新規 lib 2 本 + テスト 5 本」に修正し、
  内訳を明記して再発しないようにした。

## 施策 0 / A / B: APPROVE を受領
- 追加対応なし。
