# 対応マトリクス: impl-review Round 1

Critical はゼロ。Warning 4 件 + Suggestion 1 件、すべて対応した（反論なし）。

## [Warning] Checkout の文言が「プラン名に『プラン』が含まれる場合」の重複を避けていない
- 判断: **対応する**
- 根拠: 指摘のとおり。実データ (`PlanSeeder`) の現行値は `Personal` / `Starter` / `Standard` で
  今は重複しないが、**文言が実データ名に依存している**状態は将来壊れる。
  設計で「実値を確認して調整する」と書いた条件を、そもそも成立しない形にするほうが強い。
- 対応内容: 文言から「プラン」の語を落とし、
  `{plan.name} が初期候補として表示されています` / `{plan.name} を選択中です` にした。
  なぜ「プラン」を付けないかの理由もコメントに残した。
  Browser テストの assert (「Starter」「初期候補」「選択中」の包含) はそのまま通る。

## [Warning] 受入条件 11 の「許容差 1px 以内」が実装されず完全一致になっていた
- 判断: **対応する**
- 根拠: 指摘のとおり。`toEqual()` の完全一致は、フォント描画・Chromium/WebKit 差・
  小数丸めで揺れて flaky になる。設計は 1px 許容と書いていたのに実装が守れていなかった
  （設計と実装の乖離。指摘されなければそのまま入っていた）。
- 対応内容: `expectRectUnchanged(?array $before, ?array $after, array $keys, string $label)`
  ヘルパを追加し、`abs($after[$key] - $before[$key]) <= 1.0` で比較する形にした。
  失敗時にどの要素のどのキーが動いたかが分かるよう `$label` 付きメッセージも入れた。

## [Warning] 受入条件 11 で「価格」の相対位置・高さを測っていない
- 判断: **対応する**
- 根拠: 指摘のとおり。`h3` と CTA だけでは、`headerBadges` 追加で**見出し行が伸びて
  価格行が押し下げられる**退行を検出できない。価格はまさに見出しの直下にあり、
  この施策で最も動きやすい要素だった。
- 対応内容: `PricingPlanCard.svelte` の価格 `<p>` に `data-testid="plan-price"` を追加し、
  測定対象に `price` を加えた（Starter / Standard とも `top` + `height` を検査）。
  - **設計からの逸脱**: 施策 C は「`Onboarding/Checkout.svelte` のみ変更」としていたが、
    molecule に計測用 testid を 1 つ足した。表示・スタイルには一切影響しない計測点であり、
    既存の `data-testid="price-caption"` と同じ性質。逸脱として本マトリクスに記録する。

## [Warning] `sr-only` の不可視確認が矩形サイズだけ
- 判断: **対応する**
- 根拠: 指摘のとおり。`width <= 1 && height <= 1` は「たまたま小さい」でも通る。
  設計は「1px 四方以下、**または** clip / clip-path」と書いており、
  Tailwind の `sr-only` 契約そのものを見る意図だった。
- 対応内容: `getComputedStyle` を併用し、
  `tiny` / `absolute` (`position: absolute`) / `hidden` (`overflow: hidden`) /
  `clipped` (`clip` または `clip-path` が設定されている) の 4 点を `toMatchArray` で固定した。

## [Warning] `waitUntilHeadingInViewport()` の失敗理由が分かりにくい
- 判断: **対応する**
- 根拠: 指摘のとおり。void を返して呼び出し側の assert で落とす構造だと、
  「待機 timeout」と「最終座標が条件を満たさない」が同じ失敗に見える。
  Browser lane は調査コストが高いので、失敗の切り分けが付くべき。
- 対応内容: `waitUntilInViewport(mixed $page, string $testId, int $attempts = 40): bool` に
  作り替え（testid を引数化して一覧見出し側の重複ループも統合）、
  呼び出し側は `expect(waitUntilInViewport(...))->toBeTrue('… (待機 timeout)')` と
  **明示メッセージ付きで落とす**形にした。重複していた polling ループ 1 つも削除できた。

## [Suggestion] desktop test の `usleep(500_000)` 単発は意図が弱い
- 判断: **対応する**
- 根拠: 単発 sleep だと「動いていない」のか「まだ動いていないだけ」なのか区別できない。
  区間で観測するほうが「動かない」という主張に一致する。
- 対応内容: 50ms × 10 回の polling に変え、**各回で `scrollY` が初期値のまま**であることを
  assert する形にした（区間を通して一度も動かないことの観測）。

## 検証（対応後）
- `composer test:browser` chromium: 22 tests / 19 passed / 3 skipped / **141 assertions**
- `composer test:browser` webkit: 22 tests / 19 passed / 3 skipped / **141 assertions**
  （対応前は 94 assertions。許容差比較・価格・sr-only 契約の追加で増えている）
- `vendor/bin/pint --test`: passed
- `PricingPlanCard.test.ts` / `OnboardingCheckout.test.ts`: 27 passed（testid 追加の影響なし）
