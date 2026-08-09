# 対応マトリクス: design-review Round 1

Critical はゼロ。Warning 9 件はすべて対応した (うち 2 件はコードの事実確認で前提が消滅)。

## 施策 0 (APPROVE) — [Suggestion] のみ
- `readonly CaptureCut[]` / `Record<number,string>` の key: **見送る**。
  既存の呼び出し形 (`labels[cut.id]`) と揃えるのが優先で、型を厳しくする副次効果が薄い。

## [Warning/A] Desktop の受入条件 3「activeElement が変化しない」はクリックで成立しない
- 判断: **対応する**
- 根拠: 指摘のとおり。`CutNavigator` の行は `<button>` なので、クリックすればブラウザが
  そこへフォーカスを移す。これは本実装の副作用ではなく通常挙動であり、
  この条件のままだとテストが実装と無関係な理由で赤くなる。
- 対応内容: 受入条件 3 を
  「(a) `window.scrollY` 不変、(b) `document.activeElement` が **`capture-recording-heading` ではない**」
  に変更した。「撮影パネル見出しへ**プログラムフォーカスしない**」ことが検証対象であると
  テスト計画に注意書きとして明記した。受入条件マップも更新した。

## [Warning/A] `captureActive` 抑止を純関数 vitest だけで固定するのは弱い
- 判断: **対応する**
- 根拠: 指摘のとおり。`shouldNavigateToPanel` のような述語だけを切り出しても、
  実際に `focus` / `scrollIntoView` を止めているかは page component の中でしか分からず、
  回帰を固定できない。
- 対応内容: `panel-navigation.ts` に**副作用ごと**切り出した
  `navigateToPanelIfNeeded({ captureActive, leftEl, rightEl, headingEl, reducedMotion })` と
  `navigateBackToList(headingEl, reducedMotion)` を定義した。
  vitest では `focus` / `scrollIntoView` を `vi.fn()` で spy し、
  - `captureActive=true` → **どちらも呼ばれない**（受入条件 4）
  - 横並び矩形 → **どちらも呼ばれない**（受入条件 3 の半分）
  - 縦積み → `focus({preventScroll:true})` の**後に** `scrollIntoView`（呼び出し順も固定 = 二重移動防止の回帰）
  - `reducedMotion=true` → `behavior: "auto"`
  を固定する計画に改めた。page component 側は薄い委譲だけになる。

## [Warning/A] `stacked` を ResizeObserver だけで更新すると初期表示でリンクが出ない可能性
- 判断: **対応する**
- 根拠: ResizeObserver の初回 callback のタイミングは実装差があり、bind 完了直後に
  必ず走る前提にはできない。戻るリンクの出し分けに使う値なので、出ない/遅れるのは実害。
- 対応内容: `$effect` の実装例を書き下し、**observer 登録の前に `updateStacked()` を即時 1 回呼ぶ**
  形にした。`ResizeObserver` 非対応環境では初期値のまま続行し、cleanup で必ず `disconnect()` する。
  また `handleSelectCut` からも `updateStacked()` を呼び、抑止条件とは独立に更新されるようにした。

## [Warning/A] `TextLink href="#" onclick` は forwarding されない可能性がある
- 判断: **対応する (事実確認により曖昧さを解消)**
- 根拠: `resources/js/components/atoms/TextLink.types.ts` を確認したところ、`ModeProps` は
  **(c) ボタンモード** = `{ href?: never; external?: never; icon?: never; onclick: (event: MouseEvent) => void }`
  という分岐を discriminated union で持ち、`TextLink.svelte` はこれを `<button type="button">` として
  描画していた。つまり `href="#"` も `preventDefault()` も不要で、`Button` へのフォールバックも要らない。
- 対応内容: 設計を `<TextLink onclick={backToCutList} testId="back-to-cut-list">` に確定し、
  根拠 (型定義の該当分岐) を設計書に引用した。`backToCutList` から `event.preventDefault()` を削除した。
  受入条件 6 に「URL に `#` が付かないこと」の確認も足した。

## [Warning/B] `previewOnly` 相当の state がある前提。無い場合のテストが取り違えを検出できない
- 判断: **対応する (コードの事実により分岐そのものが不要と確定)**
- 根拠: `playbackId` の供給源を追った結果、**常に preview 由来**だった:
  - 初期値 `playbackJobId` は `app/Http/Controllers/Projects/VideoManualController.php` L142-143 が
    「playbackJobId は **succeeded preview のみ**を見る」と明記して抽出している
  - 実行中の更新は `RenderPanel.svelte` L126-130 の **preview 分岐**でのみ `playbackId = body.id`
    （render 分岐は `router.reload()` するだけで `playbackId` を触らない）
  よって `data-testid="preview-video"` の `<video>` が完成動画を指すことはなく、
  **状態取り違えの余地が構造的に存在しない**。
- 対応内容: 設計を**固定文言「プレビュー動画」**に確定し、上記 2 つの根拠を設計書へ引用した。
  受入条件 7 も「『プレビュー』を含む」に狭めた（曖昧な OR 条件を排除）。
  なお bug-hunt の finding F-1-02 は「完成動画/プレビュー」と併記していたが、
  **report の記述より実装の事実を採る**旨も明記した。

## [Warning/B] `cutLabel` 必須追加で既存 call site / fixture が壊れる可能性。波及一覧が不足
- 判断: **対応する (全数確認を実施し、結果を設計に記載)**
- 根拠: `resources/js` と `tests/js` 全体を検索した結果、`<TakePreviewDialog` は
  **`TakeStrip.svelte` L316 の 1 箇所のみ**、`<TakeStrip` は `Capture/Show.svelte` の 1 箇所のみ。
  壊れる component test / story / fixture は存在しない。
- 対応内容: 波及変更節に**全数確認の事実**を書き、実装時にも再度 `rg` で確認してから
  必須 prop にする手順を明記した。あわせて Codex の [Suggestion] を採り、
  `Show.svelte` 側の供給を `cutLabels[selectedCut.id] ?? "選択中カット"` として
  `"undefined のテイク再生"` を防ぐフォールバックを入れた。

## [Warning/C] `headerBadges` ラッパの矩形が 0×0 という前提は危険
- 判断: **対応する**
- 根拠: 指摘のとおり。`sr-only` は**子要素**を視覚的に隠すユーティリティで、
  `ml-auto` / `gap-2` を持つ**親の flex item** の実寸が 0 になることは保証しない。
  満たせない条件を受入条件にすると、実装が正しくてもテストが赤くなる。
- 対応内容: 受入条件 11(b)「ラッパが 0×0」を**削除**し、
  「**変わってはいけないもの**の位置で測る」形に置き換えた ——
  (a) 選択/未選択カードでカード内の見出し・価格・選択ボタンの `height` 一致と
  カード上端からの相対 `top` が許容差 1px 以内、
  (b) `plan-selected-note-*` 自身が可視領域を持たない（`sr-only` が効いている）、
  (c) カード全体の `height` が選択有無で一致（見出し行の折返しが起きていない）。

## [Warning/C] `isHighlighted` / note / CTA の基準が食い違い、視覚と支援技術がズレる
- 判断: **対応する (最も重要な指摘)**
- 根拠: 指摘のとおり。note を `selectedPlanCode` 基準にしつつ CTA を `chosenPlanCode` 基準のまま
  残すと、`?plan=starter` 初期表示で「CTA は『選択』なのに note は『選択されています』」となり、
  スクリーンリーダー利用者だけが矛盾した情報を受け取る。
- 対応内容: **3 つの表現の責務を表で明文化**し、note を 2 状態に分けた:
  - note の**存在**は `selectedPlanCode` 基準（= 青枠と完全一致 → どのカードかがズレない）
  - note の**文言**は `chosenPlanCode` 基準（= CTA と一致 →「押していないのに選択中」と言わない）
    - 未押下: 「{plan.name} プランが**初期選択されています**」
    - 押下後: 「{plan.name} プランを**選択中です**」
  - 青枠と CTA ラベルは**一切変更しない**（視覚の挙動は現状のまま）
  受入条件 9/10 も「初期選択」「選択中」への切り替わりを固定する形に強化した。
  将来 CTA の基準を変えるなら note も同時に変える契約であることをリスク欄に明記した。

## [Warning/横断] Browser テストのテストデータ前提が設計に無い
- 判断: **対応する**
- 根拠: 撮影 PWA は `require-active-subscription` group 内（AGENTS.md ドメイン規約 4）で、
  前提を固定しないとアプリ状態に引きずられて無関係な理由で落ちる。
- 対応内容: 施策 A / C それぞれに「テストデータ（Browser レーンの前提固定）」節を追加した。
  - 施策 A: `createOrganizationWithOwner()` (tests/Pest.php L173) + `contractPaidPlan()` (L208) +
    `Project` / `VideoManual` / `Cut` の各 Factory。**cuts は 14 件以上**
    （件数不足だと受入条件 1 が最初から成立し、**テストが fail を経由しない** = テストファーストが空回りする）。
    CI に実カメラが無く `CaptureFileFallback` 分岐になることも前提として明記
  - 施策 C: `onboarding.checkout` は**課金ゲートの構造的 allowlist 内**なので
    `contractPaidPlan()` を**呼ばない**（契約済みだと `/billing` へリダイレクトされる）。
    `PlanSeeder` の投入と、プラン名の実値による文言重複回避も明記

## [Warning/横断] 最終検証一覧から package 系 3 本が落ちている
- 判断: **対応する**
- 根拠: AGENTS.md の検証コマンド一覧は「全 green でコミット」と定めており、
  `verification-commands-doc-sync.test.ts` が一覧と package.json の同期を機械強制している。
- 対応内容: 実装順序 5 に `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` と
  `pnpm build` を追加し、**「無関係だから省く」という判断を個々の TODO 側に持ち込まない**
  （省略の可否は規約側の問題である）と明記した。
