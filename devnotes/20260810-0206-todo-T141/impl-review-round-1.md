全体判定: **CHANGES_REQUESTED**

主な実装方針は詳細設計に概ね沿っていますが、受入条件 11 の固定が弱く、Checkout の文言にも設計上の確認事項が未反映です。少なくとも下記は直してから approve したいです。

**resources/js/pages/Onboarding/Checkout.svelte: REQUEST_CHANGES**

[Warning] `"{plan.name} プラン..."` が、設計で明記された「PlanSeeder の `name` に『プラン』が含まれる場合は重複を避ける」を満たしているか差分上では確認できません。  
修正案: 実データ名に依存しない文言へ寄せてください。例えば常に `{plan.name} が初期候補として表示されています` / `{plan.name} を選択中です` にすれば「プラン プラン」重複を避けられます。

**tests/Browser/OnboardingPlanSelectionA11yTest.php: REQUEST_CHANGES**

[Warning] 受入条件 11 の「許容差 1px 以内」が実装されておらず、`toEqual()` の完全一致になっています。Browser の `getBoundingClientRect()` はフォント描画・WebKit/Chromium 差・小数丸めで揺れうるため、設計どおり 1px 許容にしないと flaky になります。  
修正案: `abs($after - $before) <= 1` を見る helper を作り、Starter の `heading.top/height`, `cta.top/height`、Standard の `heading.top/height`, `cta.top` を個別に比較してください。

[Warning] 受入条件 11 で設計されていた「価格」の相対位置・高さを測っていません。現在は `h3` と CTA だけなので、headerBadges 追加で価格行が押し下げられる退行を検出できません。  
修正案: `PricingPlanCard` 内の価格要素に安定した `data-testid` があるならそれを測定対象へ追加し、無いならテスト可能な selector/testid を既存コンポーネントの責務に沿って追加してください。

[Warning] `sr-only` の不可視確認が `width <= 1 && height <= 1` だけです。詳細設計は「1px 四方以下、または clip / clip-path」としており、Tailwind の sr-only 契約をより直接見る意図でした。  
修正案: computed style の `position:absolute`, `overflow:hidden`, `clip` または `clipPath` も許容条件に含めてください。

**tests/Browser/CaptureCutNavigationTest.php: REQUEST_CHANGES**

[Warning] `waitUntilHeadingInViewport()` は失敗時に何も返さず、呼び出し側の assert で落とす構造ですが、失敗理由が待機 timeout なのか最終座標不一致なのか分かりにくいです。特に Browser lane の調査コストが上がります。  
修正案: boolean を返して `expect(waitUntil...)->toBeTrue()` にするか、最後に座標を含めた assertion を置いてください。

[Suggestion] `usleep(500_000)` で「動くなら動き切る」前提を見る desktop test は少し弱いです。実装上 smooth scroll は発火しないはずなので致命的ではありませんが、可能なら短い polling で `scrollY` が変わらないことを観測する形のほうが意図が明確です。

**resources/js/lib/capture/panel-navigation.ts: APPROVE**

設計どおり、breakpoint 値を JS に複製せず実測判定にしている点、副作用を helper に閉じ込めてテスト可能にしている点は妥当です。DESIGN/Atomic への違反も見当たりません。

**resources/js/lib/capture/cut-labels.ts: APPROVE**

CutNavigator 内の導出ロジックを純粋抽出しており、現行挙動固定のテストもあります。問題ありません。

**resources/js/pages/Capture/Show.svelte: APPROVE**

施策 A/B の配線は詳細設計に一致しています。`focus-visible:ring-primary/35` など token 経由で、hex 直書きもありません。Atomic Design 上も page から feature/atom/lib を参照しており、方向は妥当です。

**resources/js/components/features/capture/CutNavigator.svelte: APPROVE**

表示規則の共有化のみで、責務の移動として妥当です。

**resources/js/components/features/capture/TakeStrip.svelte: APPROVE**

`cutLabel` の中継は設計どおりです。

**resources/js/components/features/capture/TakePreviewDialog.svelte: APPROVE**

video の accessible name 追加は適切です。

**resources/js/components/features/manual/RenderPanel.svelte: APPROVE**

`aria-label="プレビュー動画"` は設計の根拠と一致しています。

**tests/js/lib/capture/*.test.ts / tests/js/pages/CaptureShow.test.ts: APPROVE**

helper 契約とページ配線の 2 段固定は設計意図に合っています。`captureActive=true` を module mock で検証している点も妥当です。