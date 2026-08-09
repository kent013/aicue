Round 5 の Warning 1 件 + Suggestion 1 件を対応した。

- fixture 例で `$page = visit(...)->assertPathIs(...)` と戻り値を保持してから
  `expect($page->script('window.location.search'))->toBe('')` する形に直した
- 実装規模を「frontend 6 ファイル改修」に修正し、内訳 6 枚を明記した

最終判定を返してほしい。

# 対応マトリクス

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


---

# 該当箇所 (施策 C の fixture と実装モード)

## 施策 C の fixture

```php
// ★ 既定の grandfatherFreePlan=true では Checkout に到達できない (下記根拠)。必ず false にする。
[$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
$this->actingAs($owner);

// 前提の事前 assert: 未契約オーナーで Checkout が「表示できる」ことをまず固定する
visit('/onboarding/checkout')->assertPathIs('/onboarding/checkout');

// ?plan= は canonical URL へ 303 されるため、着地は query 無しの /onboarding/checkout
$page = visit('/onboarding/checkout?plan=starter')
    ->assertPathIs('/onboarding/checkout');

// assertPathIs は path しか見ないので、query が消えたことも明示的に assert する
expect($page->script('window.location.search'))->toBe('');
```

## 実装モード (規模)

規模は **frontend 6 ファイル改修** (`CutNavigator` / `Show` / `RenderPanel` / `TakePreviewDialog` /
`TakeStrip` / `Checkout`) **+ 新規 lib 2 本 + テスト 5 本** (vitest 3 = cut-labels / panel-navigation /
Capture/Show、Browser 2 = CaptureCutNavigationTest / OnboardingPlanSelectionA11yTest) と小さく、
1 worktree で完結する。
