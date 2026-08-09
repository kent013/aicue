**全体判定: CHANGES_REQUESTED**

大きな方向性は妥当です。特に「ラベル導出を先に共有化する」「1カラム時だけ視点とフォーカスを移す」「PHP/API/DTOを触らない」は既存制約に合っています。  
ただし施策 A/C の受入条件と実装案に、テストが不安定化または実装不能になり得る点があります。

**施策 0: APPROVE**

[Suggestion] `buildCutLabels(cuts: CaptureCut[])` は副作用がないので、引数を `readonly CaptureCut[]` にしてもよいです。必須ではありません。

[Suggestion] `Record<number, string>` は JS 実体では string key になりますが、既存用途が `cutLabels[selectedCut.id]` なら問題ありません。テストで欠落時の fallback までは不要です。

**施策 A: REQUEST_CHANGES**

[Warning] Desktop の受入条件 3「`document.activeElement` が変化しない」は、クリック操作で検証すると成立しません。`CutNavigator` の項目をクリックした時点で、ブラウザはクリック対象へフォーカスを移す可能性があります。これは新実装の副作用ではなく通常挙動です。  
修正案: 受入条件を「撮影パネル見出しへプログラムフォーカスしない」に変える。具体的には、クリック後の `activeElement` が `capture-recording-heading` ではないこと、かつ `window.scrollY` が不変であることを検証してください。

[Warning] `captureActive=true` の抑止を `shouldNavigateToPanel(captureActive, stacked)` の vitest だけで固定する計画は弱いです。実際の `handleSelectCut` は `selectedCutId` 更新、`tick()`、DOM bind、`scrollIntoView`、`focus` の組み合わせなので、純関数だけでは回帰を防げません。  
修正案: `panel-navigation.ts` に「副作用の実行関数」を切り出してください。例: `navigateToPanelIfNeeded({ captureActive, leftEl, rightEl, headingEl, prefersReducedMotion })`。vitest では `focus` と `scrollIntoView` を spy し、`captureActive=true` でどちらも呼ばれないことを固定するのがよいです。

[Warning] `stacked` を ResizeObserver だけで更新する場合、初期表示時にリンク表示が遅れる/出ない可能性があります。ResizeObserver の初回 callback は実装差・タイミング差があり、Svelte の bind 完了直後に必ず期待通り更新される前提にしない方がよいです。  
修正案: `$effect` 内で observer 登録後に即時 `updateStacked()` を呼び、その後 observer callback でも更新してください。

[Warning] `TextLink href="#" onclick={backToCutList}` は、`TextLink` が `onclick` を forwarding しない場合に動きません。設計書では「確認して選ぶ」とありますが、受入条件に関わるため曖昧さを潰した方がよいです。  
修正案: 実装方針を「既存 `TextLink` が click handler を公開している場合のみ使用。そうでなければ既存 `Button` atom の tertiary/link 相当を使う」に明記し、`href="#"` を使う場合は必ず `preventDefault()` を Browser テストで間接的に確認してください。

[Suggestion] 見出しに `tabindex="-1"` を付けるなら、左 pane の戻り先見出しにも `focus-visible` の見た目を揃えるとよいです。

**施策 B: REQUEST_CHANGES**

[Warning] `RenderPanel` の `aria-label={previewOnly ? "プレビュー動画" : "完成動画"}` は、`previewOnly` 相当の既存 state がある前提になっています。無ければ「完成動画のプレビュー」に倒す案は妥当ですが、テストが「完成動画またはプレビューを含む」だけだと状態取り違えを検出できません。  
修正案: 実装時に状態区別できるなら、preview/render それぞれの分岐をテストしてください。区別できない設計にするなら、設計書上も固定文言「完成動画のプレビュー」に決めて、テストもその語を含むことに寄せてください。

[Warning] `TakePreviewDialog` に `cutLabel: string` を必須追加すると、既存の全 call site と component test/story が一斉に壊れる可能性があります。設計では `TakeStrip` の中継しか触れていません。  
修正案: `rg "<TakePreviewDialog"` 相当で全 call site を確認する前提を明記し、テスト/fixture 側の props 更新も波及対象に含めてください。必須 prop のままでよいですが、波及一覧が不足しています。

[Suggestion] `aria-label={`${cutLabel} のテイク再生`}` は良いです。`cutLabel` が空になり得ないことは `buildCutLabels` 供給で担保されますが、念のため `cutLabels[selectedCut.id] ?? "選択中カット"` のような fallback を Show 側で持つと画面名欠落に強くなります。

**施策 C: REQUEST_CHANGES**

[Warning] `headerBadges` ラッパの `getBoundingClientRect()` が `width === 0 && height === 0` になる、という前提は危険です。ラッパ自体は flex item で、`ml-auto flex ... gap-2` を持つため、中身が `sr-only` でもレイアウト上の高さや幅が 0 とは限りません。`sr-only` は子要素を視覚的に隠すだけで、親ラッパの寸法を必ず 0 にする契約ではありません。  
修正案: 受入条件 11(b) を削除または変更してください。代わりに「カードの主要要素の top/height が選択有無で許容差内」「`sr-only` 自体が可視領域を持たない」を検証するのが適切です。

[Warning] `selectedPlanCode` は `chosenPlanCode ?? computeInitialPlan(pageData)` なので、ユーザーが別プランを押した後は sr-only note が移動します。一方、設計書は「表示文言は `chosenPlanCode` 基準のまま」としており、視覚上の選択状態と支援技術向けの選択状態が一時的に食い違う可能性があります。  
修正案: `isHighlighted`、sr-only note、CTA 文言の責務を明文化してください。事前選択を「現在選択中」と扱うなら CTA も同じ基準に寄せるか、逆に CTA 基準を維持するなら sr-only 文言を「初期選択候補」など誤認しない語に変えるべきです。

[Suggestion] `role` を偽らず sr-only に留める判断は妥当です。radiogroup 化は操作モデル変更になるため、この施策範囲では避ける判断でよいです。

**横断指摘**

[Warning] Browser テスト追加は妥当ですが、既存のテストデータ作成手順が設計に足りません。Capture/Onboarding のページ到達には org/current project/manual/subscription/onboarding 状態が絡むはずです。  
修正案: 各 Browser テストで使う factory/helper を明記してください。特に「課金ゲート内 route」「Default Project」「manual.cuts」「take playback URL」「checkout plan seed」の前提を固定しないと、テストがアプリ状態に引きずられます。

[Warning] `pnpm build` 後に Browser レーンを走らせる順序は正しいですが、最終検証一覧から `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が落ちています。AGENTS.md の検証コマンド一覧と不一致です。  
修正案: 最終検証に package 系 3 本も含めるか、本変更が packages 非依存であるため省略する明確な根拠を設計書に書いてください。