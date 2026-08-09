# 対応マトリクス: conceptual-review Round 1

Critical はゼロ。Warning 5 件はすべて対応した。

## [Warning] 概念設計時点で回帰固定方針が未確定 (禁止事項 1)
- 判断: **対応する**
- 根拠: 「テストは詳細設計で確定」は、設計が通ってから受入条件を後付けする形になり、
  受入条件が実装の都合に寄る危険がある。finding ごとの受入条件は概念設計の一部である。
- 対応内容: 「受入条件 (finding ごとに 1 行 + 固定するレーン)」節を新設。7 行の受入条件と
  固定レーン (Browser: Chromium + WebKit / vitest) を明記した。

## [Warning] scrollIntoView だけではキーボード/SR の現在位置が一覧側に残る (H13 修正が H14 欠落を生む)
- 判断: **対応する**
- 根拠: 指摘のとおり。bug-hunt は H13 と H14 を同じ表で扱っており、片方の修正で
  もう片方を作るのは本末転倒。「視点」と「フォーカス」は同時に運ぶべき。
- 対応内容: 施策 A を「視点**と**フォーカスを運ぶ」に改め、
  (a) 撮影パネル先頭に見出し + `tabindex="-1"` を置きカット選択時に `focus()`、
  (b) 見出しに選択中カット名を含める、(c) 受入条件に「フォーカスが撮影パネル先頭へ移る」
  「lg 以上ではフォーカスも動かない」を追加した。

## [Warning] 「次回以降の bug-hunt で再燃しない」は先走り
- 判断: **対応する**
- 根拠: テストが固定するのは受入条件そのものであって、周辺の類似欠落までは守らない。
  保証範囲を誇張しないのは AGENTS.md の基調でもある。
- 対応内容: 期待効果を「同一条件をテストで固定する」に書き換え、
  括弧書きで「周辺の類似欠落まで守るわけではない」と明示した。

## [Warning] `aria-current="true"` の意味論が危うい / prop 名も再検討すべき
- 判断: **対応する (方針を変更)**
- 根拠: 指摘を受けて呼び出し元 3 箇所を実地確認したところ、初版の前提が誤っていた。
  - `Onboarding/Checkout.svelte` は**選択肢 UI** (カードごとに「選択」Button があり、
    `selectedPlanCode = chosenPlanCode ?? ?plan= 由来の初期値`)。現在位置ではないので
    `aria-current` は誤り。状態は**操作要素 (Button)** に載せるのが正しい。
  - `Billing/_helpers/PlanCard.svelte` は `headerBadges` に Badge「現在のプラン」を
    既に持っており、**テキストとして a11y ツリーに出ている = 欠落なし**。
  - `Guest/Pricing.svelte` は選択状態ではない。
  つまり欠落は Checkout の 1 箇所だけで、molecule に意味論 prop を足す必要そのものが無かった。
- 対応内容: 施策 C を全面改稿。`PricingPlanCard` は**変更しない**。
  代わりに `Button` atom へ `ariaPressed?: boolean` を追加 (既存 `ariaExpanded` /
  `ariaControls` と同じ button モード専用 aria prop の枠)、
  `Onboarding/Checkout.svelte` の「選択」Button に `aria-pressed={selectedPlanCode === plan.code}` を付与。
  表示文言 (`chosenPlanCode` 基準の「選択中」) は動かさない
  (押していないものを「選択中」と表示すると別の誤認を作るため)。
  スコープ外に「Billing への ARIA 追加」「molecule への意味論 prop (初版案の撤回)」を明記。

## [Warning] 録画中・権限ダイアログ中のカット切替/スクロール挙動を確認すべき
- 判断: **対応する**
- 根拠: `Capture/Show.svelte` を確認したところ、`captureActive` state は既にあるが
  カット切替は録画中でも抑止されていない (既存仕様)。ここで切替可否そのものを変えるのは
  スコープ拡大なので、**既存仕様は変えず、新しい挙動 (自動スクロール/フォーカス移動) だけを
  録画中は抑止する**のが最小かつ安全。
- 対応内容: 施策 A に「録画中 (`captureActive === true`) は自動スクロール・フォーカス移動を
  行わない」を追加し、受入条件にも 1 行足した。スコープ外に「録画中カット切替の可否そのものの
  見直し」を明記した。

## [Warning] Svelte 側 prop 追加は明示型 + 未指定時の差分なしをテストで固定
- 判断: **対応する**
- 根拠: 妥当。`ariaPressed` を optional にしただけでは「未指定で属性が出ない」ことは保証されない。
- 対応内容: `ariaPressed?: boolean` (optional・既定値なし・未指定なら属性を出力しない) と明記し、
  受入条件に「`ariaPressed` 未指定の既存 Button は `aria-pressed` 属性を出力しない (vitest)」を追加。
  呼び出し元 3 箇所の意味差分については、そもそも `PricingPlanCard` を変更しない方針に変えたため
  差分は発生しない (Checkout の 1 箇所のみ Browser テストで固定)。

## [Suggestion] 採用したもの
- `matchMedia('(prefers-reduced-motion: reduce)')` の SSR/ブラウザ境界に注意 → 施策 A に明記した。
- 施策 A の breakpoint 判定は `lg` の値を二重管理しないよう**レイアウトの実測**で行う点も追記した
  (Codex の指摘ではないが、同じ「二重管理を避ける」観点で自主的に足した)。
