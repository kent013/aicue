Round 1 の Warning 9 件をすべて捌いた (Critical は無し)。うち 2 件は**コードの事実確認により前提が消滅**した。

特に確認してほしい点:
- **施策 C の責務表**: note の「存在」を `selectedPlanCode` (= 青枠と一致)、「文言」を
  `chosenPlanCode` (= CTA と一致) の 2 基準に分けた。これで視覚と支援技術の食い違いが消えているか。
  「初期選択されています」/「選択中です」という語の選択が誤認を生まないか。
- **受入条件 11 の置き換え**: 「ラッパが 0×0」を捨て、「変わってはいけないものの位置」で測る形にした。
  これで実装可能かつ意味のある不変性検査になっているか。
- **施策 B の固定文言化**: `playbackId` が常に preview 由来であることをコードで確認したので
  分岐そのものを消した。この事実認定が正しいか (提示した現行コードで判断してほしい)。
- **副作用関数 `navigateToPanelIfNeeded` の粒度**: page component 側を薄い委譲にして
  vitest で spy 固定する形にした。これで受入条件 3・4 の回帰が実際に守れるか。
- **テストデータ前提**: 施策 A で「cuts 14 件以上でないとテストが fail を経由しない」と書いた点、
  施策 C で「contractPaidPlan を呼ばない」と書いた点が正しいか。

# 対応マトリクス

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


---

# 改訂後の詳細設計書

# 詳細設計: bughunt-ux-a11y

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より。本設計に関係する核）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本設計は PHP を変更しないが、レーンは緑を維持する
- **Pest** テストフレームワーク（`composer test` / `composer test:browser`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- **DESIGN.md 準拠**: color / radius / typography は token 経由。hex 直書きを増やさない
- **Atomic Design**: `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import

## 概念設計リファレンス

`devnotes/20260810-0134-bughunt-ux-a11y/conceptual-design.md`（conceptual-review Round 4 で **APPROVED**）

出自は bug-hunt run `20260809-152048` の finding F-1-03 / F-1-02 / F-2-01
（`devnotes/20260809-152048-bug-hunt/report.md` §3）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 0 | カットラベル導出の共有化（施策 A/B の前提整備） | `resources/js/lib/capture/cut-labels.ts` (新規), `components/features/capture/CutNavigator.svelte` | 高 |
| A | 撮影パネルへ視点とフォーカスを運ぶ + 戻る導線 | `resources/js/lib/capture/panel-navigation.ts` (新規), `resources/js/pages/Capture/Show.svelte` | 高 |
| B | 動画要素のアクセシブルネーム | `components/features/manual/RenderPanel.svelte`, `components/features/capture/TakePreviewDialog.svelte`, `components/features/capture/TakeStrip.svelte` (props 中継) | 中 |
| C | プラン事前選択を sr-only テキストで伝える | `resources/js/pages/Onboarding/Checkout.svelte` | 中 |

**PHP 側の変更は 0 件**（Controller / DTO / route / migration / Inertia Props すべて不変）。

---

## 施策 0: カットラベル導出の共有化

### 背景（なぜ先に要るか）

「手順 N / 急所 N-M」のラベルは現在 `CutNavigator.svelte` L19-33 の `$derived.by` の中にだけ存在する。
施策 A は撮影パネルの見出しに、施策 B はテイクプレビューの `aria-label` に**同じラベル**を要する。
ここで各所にコピーすると、ラベル規則が 3 箇所に散る（二重管理）。先に純関数へ括り出す。

### 変更箇所

- 新規: `resources/js/lib/capture/cut-labels.ts`
- 変更: `resources/js/components/features/capture/CutNavigator.svelte` (L19-33 を置換)

### 波及変更

- TypeScript 型定義: `CaptureCut` を読むだけ。型追加なし
- API Resource/DTO: なし
- テストファイル: 新規 `tests/js/lib/capture/cut-labels.test.ts`

### 変更後コード

```ts
// resources/js/lib/capture/cut-labels.ts
import type { CaptureCut } from "@/types/capture";

/**
 * カットの表示ラベル (手順 N / 急所 N-M) を cuts の並び順から導出する。
 * step は連番、point は直前 step の番号 + 枝番 (doc/10 §10.1)。
 *
 * CutNavigator の行ラベル・撮影パネルの見出し (F-1-03) ・テイクプレビューの
 * アクセシブルネーム (F-1-02) が同じ規則を共有するため、ここを唯一の導出元とする。
 */
export function buildCutLabels(cuts: CaptureCut[]): Record<number, string> {
    const result: Record<number, string> = {};
    let stepIndex = 0;
    let pointIndex = 0;
    for (const cut of cuts) {
        if (cut.type === "step") {
            stepIndex += 1;
            pointIndex = 0;
            result[cut.id] = `手順 ${stepIndex}`;
        } else {
            pointIndex += 1;
            result[cut.id] = `急所 ${stepIndex}-${pointIndex}`;
        }
    }
    return result;
}
```

`CutNavigator.svelte` は `const labels = $derived(buildCutLabels(cuts));` に置換する（**表示は完全に不変**）。

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] 新規 `tests/js/lib/capture/cut-labels.test.ts`（vitest）
  - step のみ → `手順 1, 手順 2, ...`
  - step + point 混在 → `急所` が直前 step の番号 + 枝番になる
  - 先頭が point（親 step 無し）→ `急所 0-1`（現行実装と同一の挙動であることを固定する。
    **仕様として良いかは問わない = 現行踏襲のリファクタであることの証明**）
  - 空配列 → `{}`
- [ ] 既存 `tests/js/components/features/` に CutNavigator のテストがあれば緑のまま

### リスク

- ラベル規則を変えてしまうと CutNavigator の表示が変わる。**純粋な抽出**に留め、
  先頭 point のような端ケースも現行挙動をテストで固定してから移す。

---

## 施策 A: 撮影パネルへ視点とフォーカスを運ぶ + 戻る導線

### 変更箇所

- 新規: `resources/js/lib/capture/panel-navigation.ts`
- 変更: `resources/js/pages/Capture/Show.svelte`（L178-238 のレイアウト部 + script 部）

### 波及変更

- TypeScript 型定義: なし（page 内の局所 state のみ）
- API Resource/DTO: なし
- テストファイル: 新規 `tests/js/lib/capture/panel-navigation.test.ts`（vitest）、
  新規 `tests/Browser/CaptureCutNavigationTest.php`（Browser 2 レーン）

### 現行コード（要点）

```svelte
<!-- resources/js/pages/Capture/Show.svelte L178-189 -->
<div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
    <section class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
        <h2 class="border-b border-border px-3 py-2 text-caption text-text-secondary">
            シナリオ (タップして撮影)
        </h2>
        <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={(cutId) => (selectedCutId = cutId)} />
    </section>
    <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
        {#if selectedCut === null}
```

カット選択は `selectedCutId` を書き換えるだけで、スクロールもフォーカスも動かない（= F-1-03）。

### 変更後の設計

#### A-1. 1 カラム判定は「レイアウトの実測」で行う（純関数に切り出す）

`lg` の breakpoint 値（1024px）を JS 側にコピーすると Tailwind 設定との二重管理になる。
**2 つの pane の実座標**から縦積みかどうかを判定する。

```ts
// resources/js/lib/capture/panel-navigation.ts

/** 縦積み判定の許容差 (px)。sub-pixel と border 由来のズレを吸収する。 */
const STACK_TOLERANCE_PX = 4;

/**
 * 右 pane が左 pane の「下」に積まれているか (= 1 カラム表示か) を実測で判定する。
 * lg breakpoint の値を JS 側へコピーしない (Tailwind 設定との二重管理を避ける) ため、
 * 座標で判定する。
 */
export function isStackedLayout(leftRect: DOMRect, rightRect: DOMRect): boolean {
    return rightRect.top >= leftRect.bottom - STACK_TOLERANCE_PX;
}

/**
 * scrollIntoView の behavior を決める。prefers-reduced-motion: reduce なら smooth を使わない。
 * matchMedia は SSR に無いので、呼び出し側が渡した prefersReducedMotion を受け取る純関数にする。
 */
export function scrollBehaviorFor(prefersReducedMotion: boolean): ScrollBehavior {
    return prefersReducedMotion ? "auto" : "smooth";
}

/** ブラウザ側でのみ評価する (SSR では常に false = smooth 前提にしない安全側)。 */
export function prefersReducedMotion(): boolean {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return true;
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/**
 * 「視点とフォーカスを運ぶ」副作用そのものをここに置く (page component 側に残さない)。
 *
 * 純関数だけを切り出すと、抑止条件 (captureActive / 2 カラム) が実際に focus と
 * scrollIntoView を止めているかは page component の中でしか検証できず、vitest で回帰を
 * 固定できない。副作用の実行単位ごと切り出し、vitest では focus / scrollIntoView を spy して
 * 「呼ばれない」ことを固定する (受入条件 3・4)。
 *
 * @returns 実際にナビゲートしたか (テストと呼び出し側の分岐用)
 */
export function navigateToPanelIfNeeded(input: {
    captureActive: boolean;
    leftEl: HTMLElement | null;
    rightEl: HTMLElement | null;
    headingEl: HTMLElement | null;
    reducedMotion: boolean;
}): boolean {
    const { captureActive, leftEl, rightEl, headingEl, reducedMotion } = input;
    // 録画中 / getUserMedia grant 待ちは視点もフォーカスも奪わない
    if (captureActive) return false;
    if (leftEl === null || rightEl === null || headingEl === null) return false;
    // 2 カラム (横並び) では左右が同時に見えているので動かさない
    if (!isStackedLayout(leftEl.getBoundingClientRect(), rightEl.getBoundingClientRect())) return false;
    // focus() 自体が暗黙スクロールを起こすため preventScroll してから scrollIntoView する
    headingEl.focus({ preventScroll: true });
    headingEl.scrollIntoView({ behavior: scrollBehaviorFor(reducedMotion), block: "start" });
    return true;
}

/** 「カット一覧へ戻る」の副作用。視点とフォーカスの両方を一覧側へ返す。 */
export function navigateBackToList(headingEl: HTMLElement | null, reducedMotion: boolean): boolean {
    if (headingEl === null) return false;
    headingEl.focus({ preventScroll: true });
    headingEl.scrollIntoView({ behavior: scrollBehaviorFor(reducedMotion), block: "start" });
    return true;
}
```

> SSR / matchMedia 非対応時は `true`（= `auto`）に倒す。アニメーションしないことは常に安全側で、
> 逆（存在しない環境で smooth を仮定する）は不要な副作用を生む。

#### A-2. 撮影パネル先頭に「見出し + 戻る導線」を置き、フォーカスの着地点にする

`capture-right-pane` の先頭に、選択中カットのラベルを含む `h2` を新設する。

```svelte
<section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane" bind:this={rightPaneEl}>
    {#if selectedCut === null}
        <p class="text-caption text-text-secondary">
            左のシナリオからカットを選ぶと撮影パネルが開きます。
        </p>
    {:else}
        <div class="flex items-center justify-between gap-2">
            <!-- フォーカスの着地点。tabindex="-1" で「プログラムからのみ」フォーカス可能にする
                 (Tab 順には入れない)。見出しにカットラベルを含め、どのカットの撮影かを名前で伝える。 -->
            <h2
                bind:this={recordingHeadingEl}
                tabindex="-1"
                class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                data-testid="capture-recording-heading"
            >
                {cutLabels[selectedCut.id]} の撮影
            </h2>
            {#if stacked}
                <!-- 1 カラムのときだけ出す。2 カラムでは一覧が常に見えているので不要。
                     TextLink の **(c) ボタンモード** (href なし + onclick のみ) を使う。
                     TextLink.types.ts の discriminated union で href を持たない分岐が定義済みで、
                     実体は <button type="button"> になる。href="#" は使わない
                     (preventDefault が要らず、URL も汚さない)。 -->
                <TextLink onclick={backToCutList} testId="back-to-cut-list">
                    カット一覧へ戻る
                </TextLink>
            {/if}
        </div>
        ...
```

> **確認済み**: `resources/js/components/atoms/TextLink.types.ts` の `ModeProps` は
> `{ href?: never; external?: never; icon?: never; onclick: (event: MouseEvent) => void }` という
> **ボタンモード**の分岐を持ち、`TextLink.svelte` はこれを `<button type="button">` として描画する。
> よって新規 atom も `href="#"` + `preventDefault` も不要。**`Button` へのフォールバックは要らない**。

#### A-3. カット選択時の副作用

```ts
let rightPaneEl = $state<HTMLElement | null>(null);
let leftPaneEl = $state<HTMLElement | null>(null);
let recordingHeadingEl = $state<HTMLElement | null>(null);
let cutListHeadingEl = $state<HTMLElement | null>(null);
let stacked = $state(false);

const cutLabels = $derived(buildCutLabels(manual.cuts));

/**
 * カット選択時に「視点」と「フォーカス」を撮影パネルへ運ぶ (F-1-03)。
 *
 * - 1 カラム (縦積み) のときだけ動かす。2 カラムでは左右が同時に見えており、
 *   勝手に画面が動くのは退行になる。
 * - captureActive の間は動かさない。captureActive は CameraRecorder L38-43 の定義で
 *   `starting || resuming || phase !== "idle"` であり、getUserMedia の grant 待ち 2 窓
 *   (startRecording L213 / resumeAfterPreview L473 — いずれも await より前に立てて
 *   syncActive() 済み) を含む。よって権限ダイアログ中・カメラ初期化中も抑止される。
 * - focus() 自体が暗黙スクロールを起こすため、先に focus({ preventScroll: true }) してから
 *   scrollIntoView する (二重移動の防止)。
 */
function handleSelectCut(cutId: number): void {
    selectedCutId = cutId;
    // DOM 反映後に測る (Svelte 5 は tick() を await できる)
    void tick().then(() => {
        updateStacked(); // 戻るリンクの出し分けは抑止条件と独立に更新する
        navigateToPanelIfNeeded({
            captureActive,
            leftEl: leftPaneEl,
            rightEl: rightPaneEl,
            headingEl: recordingHeadingEl,
            reducedMotion: prefersReducedMotion(),
        });
    });
}

/** 「カット一覧へ戻る」: 視点とフォーカスの両方を一覧側へ返す (H2 を自分で作らない)。
    TextLink のボタンモード (= <button type="button">) なので既定動作の抑止は不要。 */
function backToCutList(): void {
    navigateBackToList(cutListHeadingEl, prefersReducedMotion());
}

/** 縦積みかどうかを実測して stacked を更新する (戻るリンクの出し分け専用)。 */
function updateStacked(): void {
    if (leftPaneEl === null || rightPaneEl === null) return;
    stacked = isStackedLayout(leftPaneEl.getBoundingClientRect(), rightPaneEl.getBoundingClientRect());
}
```

左 pane の `h2`（「シナリオ (タップして撮影)」）にも `bind:this={cutListHeadingEl}` と
`tabindex="-1"` を付ける（戻り先のフォーカス着地点）。

`stacked` は選択時だけでなく初期表示・resize でも更新する必要がある（戻るリンクの出し分けに使うため）。

```ts
$effect(() => {
    if (leftPaneEl === null || rightPaneEl === null) return;
    // ★ observer の初回 callback はタイミング・実装差があるため当てにしない。
    //   登録直後に必ず 1 回自分で測る (初期表示でリンクが出ない/遅れるのを防ぐ)。
    updateStacked();
    if (typeof ResizeObserver === "undefined") return; // 非対応環境では初期値のまま
    const observer = new ResizeObserver(() => updateStacked());
    observer.observe(leftPaneEl);
    observer.observe(rightPaneEl);
    return () => observer.disconnect(); // ★ 破棄時に必ず disconnect
});
```

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] 新規 `tests/js/lib/capture/panel-navigation.test.ts`（vitest）
  - `isStackedLayout`: 縦積み（`right.top >= left.bottom`）で true、横並びで false、
    許容差 4px の境界（`left.bottom - 4` / `left.bottom - 5`）
  - `scrollBehaviorFor`: `true → "auto"` / `false → "smooth"`（**受入条件 5**）
  - `prefersReducedMotion`: `window` 無し / `matchMedia` 無しで `true` に倒れる
  - **`navigateToPanelIfNeeded`（副作用ごと固定する。`focus` / `scrollIntoView` を `vi.fn()` で spy）**
    - **受入条件 4**: `captureActive=true` → `focus` も `scrollIntoView` も**呼ばれない**、戻り値 false
    - **受入条件 3 の半分**: 横並び矩形（`isStackedLayout === false`）→ 両方**呼ばれない**、戻り値 false
    - 縦積み + `captureActive=false` → `focus({ preventScroll: true })` が呼ばれ、
      その**後**に `scrollIntoView` が呼ばれる（呼び出し順も固定 = 二重移動防止の回帰）
    - `reducedMotion=true` → `scrollIntoView` の `behavior` が `"auto"`
    - いずれかの要素が `null` → 何も呼ばれない
  - `navigateBackToList`: `headingEl=null` で何も呼ばれない / 非 null で focus → scrollIntoView の順
- [ ] 新規 `tests/Browser/CaptureCutNavigationTest.php`（Chromium + WebKit の 2 レーン）
  - **受入条件 1**: `->on()->mobile()` でカット行をクリック後、`capture-recording-heading` の
    `getBoundingClientRect()` が矩形全体として viewport 内（`top >= 0 && bottom <= innerHeight`）
  - **受入条件 2**: 同操作後に `document.activeElement` が `capture-recording-heading`
  - **受入条件 3**: `->on()->desktop()` でカット行をクリックしても
    (a) `window.scrollY` が不変、(b) `document.activeElement` が
    **`capture-recording-heading` ではない**こと。
    > **注意**: 「`activeElement` が変化しない」と書いてはならない。ブラウザは
    > クリックした `<button>`（= カット行）自体にフォーカスを移すのが通常挙動であり、
    > それは本実装の副作用ではない。検証すべきは「**撮影パネル見出しへプログラムフォーカスしない**」ことである。
  - **受入条件 6**: `->on()->mobile()` で `back-to-cut-list` をクリックすると
    `cut-navigator` が viewport 内に入り、`document.activeElement` が左 pane の見出しになる
    （TextLink ボタンモードなので URL に `#` が付かないことも併せて確認する）
  - **受入条件 4 は Browser では検証しない**。CI に実カメラが無く `captureActive=true` を
    作れないため、上記 vitest の副作用テストへ**検証手段を移す**（受入条件自体は落とさない）

### テストデータ（Browser レーンの前提固定）

`tests/Pest.php` のヘルパと Factory で組み立て、アプリ状態に引きずられないようにする。

```php
[$organization, $owner] = createOrganizationWithOwner();   // tests/Pest.php L173
contractPaidPlan($organization);                            // 課金ゲート内 route のため必須 (L208)
$project = Project::factory()->for($organization)->create();
$manual  = VideoManual::factory()->for($project)->create(['status' => ...]);
Cut::factory()->count(14)->for($manual)->create();          // 縦積みで一覧が長くなる件数
$this->actingAs($owner);
visit("/app/projects/{$project->id}/manuals/{$manual->id}")->on()->mobile();
```

- **課金ゲート**: 撮影 PWA は `require-active-subscription` group 内（AGENTS.md ドメイン規約 4）。
  `contractPaidPlan()` を通さないと `/billing-required` に着地してテストが無関係な理由で落ちる。
- **cuts 件数**: 1 カラムで撮影パネルが初期 viewport の外に出る必要があるため、
  bug-hunt 実測と同じ **14 件以上**を投入する（件数不足だと受入条件 1 が最初から成立してしまい、
  **テストが fail を経由しない** = テストファーストが空回りする）。
- **カメラ**: CI に実カメラは無く `showRecorder` は false（`CaptureFileFallback` 表示）になる。
  施策 A は `capture-right-pane` 先頭の見出しが対象なので、この分岐でも受入条件は成立する。

### リスク

- **フォーカス移動が「勝手に動く」体験になる**: `tabindex="-1"` の見出しへ移すのは
  SPA での標準的な着地点パターンだが、2 カラムでは一切動かさない・録画中は動かさないという
  2 つの抑止で影響範囲を限定している。
- **`scrollIntoView` と `focus()` の二重移動**: `preventScroll: true` を必ず付ける。
- **ResizeObserver のリーク**: `$effect` の cleanup で `disconnect()` する。
- **WebKit レーンでの `preventScroll` 対応**: Safari は歴史的に `preventScroll` 未対応時期があった。
  受入条件 1（見出しが viewport 内）は満たされるので実害は無いが、
  WebKit レーンで falsy な二重スクロールが視覚的に出ないことを実装時に確認する。

---

## 施策 B: 動画要素のアクセシブルネーム

### 変更箇所

- `resources/js/components/features/manual/RenderPanel.svelte` (L369-376)
- `resources/js/components/features/capture/TakePreviewDialog.svelte` (L77-86)
- `resources/js/components/features/capture/TakeStrip.svelte`（`cutLabel` prop の中継）
- `resources/js/pages/Capture/Show.svelte`（`cutLabel` の供給。施策 0 の `cutLabels` を使う）

### 波及変更

- TypeScript 型定義: `TakeStrip` / `TakePreviewDialog` の `Props` に `cutLabel: string` を追加
- API Resource/DTO: なし
- **call site の全数確認（実施済み）**: `<TakePreviewDialog` は
  **`TakeStrip.svelte` L316 の 1 箇所のみ**（`resources/js` / `tests/js` 全体を検索して確認）。
  `<TakeStrip` は `Capture/Show.svelte` の 1 箇所のみ。よって必須 prop の追加で壊れる
  component test / story / fixture は**存在しない**。実装時に再度 `rg "<TakePreviewDialog"` /
  `rg "<TakeStrip"` で確認してから必須 prop にする
- テストファイル: `tests/Browser/` に a11y 名の検査を追加（施策 A のテストと同居可）

### 現行コード / 変更後コード

```svelte
<!-- RenderPanel.svelte L369-376 -->
<!-- 現行 -->
<video controls preload="metadata" class="w-full rounded-md bg-neutral"
       src={...} data-testid="preview-video"></video>

<!-- 変更後: 固定文言。この <video> は常に「プレビュー」である (下記の根拠を参照) -->
<video
    controls
    preload="metadata"
    class="w-full rounded-md bg-neutral"
    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackId}/playback`}
    aria-label="プレビュー動画"
    data-testid="preview-video"
></video>
```

> **`previewOnly` のような分岐は要らない（コードで確認済み）。** `playbackId` の供給源は 2 つだけで、
> どちらも **preview 由来**である:
> - 初期値 `playbackJobId` は `VideoManualController` L142-143 が
>   「**playbackJobId は succeeded preview のみを見る**」と明記して抽出している
> - 実行中の更新は `RenderPanel.svelte` L126-130 の **preview 分岐**でのみ
>   `playbackId = body.id` としている（render 分岐は `router.reload()` するだけ）
>
> よって `data-testid="preview-video"` の `<video>` が完成動画を指すことはなく、
> **状態取り違えの余地そのものが無い**。固定文言「プレビュー動画」で正確である。
> （bug-hunt の finding F-1-02 は「完成動画/プレビュー」と併記していたが、実装上は前者にならない。
> report の記述より実装の事実を採る。）
> 併せて既存コメント `<!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->`
> とも整合する。

```svelte
<!-- TakePreviewDialog.svelte L77-86 -->
<video
    bind:this={video}
    controls
    playsinline
    src={playbackUrl ?? undefined}
    class="w-full"
    aria-label={`${cutLabel} のテイク再生`}
    data-testid="take-preview-video"
></video>
```

`Show.svelte` 側の供給は `cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}` とする。
`buildCutLabels` は `manual.cuts` 全件からラベルを作るので欠落はしない想定だが、
**万一欠落しても `aria-label` が `"undefined のテイク再生"` にならない**ようにフォールバックを置く。

`<!-- svelte-ignore a11y_media_has_caption -->` は**両方とも残す**（caption track の話であり、
アクセシブルネームとは別軸。字幕焼き込み済みという既存判断を覆さない）。

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] **受入条件 7**: Browser で `preview-video` の `aria-label` が **「プレビュー」を含む**
      （固定文言に決めたので、状態取り違えを検出できない曖昧さは残らない）
- [ ] **受入条件 8**: Browser で `take-preview-video` の `aria-label` が当該カットのラベル
      （`手順 1` 等）を含む
- [ ] 文言の**完全一致では検査しない**（i18n 変更に脆いため、必要語の包含で固定する）

### リスク

- `cutLabel` の props 中継が 2 hop（Show → TakeStrip → TakePreviewDialog）増える。
  代替は TakeStrip 側で再導出することだが、それは施策 0 で潰した二重管理に戻る。
  **props で明示的に渡す方を採る。**

---

## 施策 C: プラン事前選択を sr-only テキストで伝える

### 変更箇所

- `resources/js/pages/Onboarding/Checkout.svelte` (L192-199 のカード呼び出し)

**`PricingPlanCard` (molecule) / `Button` (atom) / `Billing` / `Guest/Pricing` はいずれも変更しない。**

### 波及変更

- TypeScript 型定義: なし（既存の optional snippet `headerBadges` を使うだけ）
- API Resource/DTO: なし
- テストファイル: 新規 `tests/Browser/OnboardingPlanSelectionA11yTest.php`

### 現行コード

```svelte
<PricingPlanCard
    name={plan.name}
    priceAmount={isPersonal(plan) ? 0 : plan.currentBaseAmount}
    features={buildFeatures(plan)}
    isHighlighted={selectedPlanCode === plan.code}
    testId={`plan-card-${plan.code}`}
>
    {#snippet footerCta()} ... {/snippet}
</PricingPlanCard>
```

### 変更後コード

```svelte
<PricingPlanCard
    name={plan.name}
    priceAmount={isPersonal(plan) ? 0 : plan.currentBaseAmount}
    features={buildFeatures(plan)}
    isHighlighted={selectedPlanCode === plan.code}
    testId={`plan-card-${plan.code}`}
>
    {#snippet headerBadges()}
        {#if selectedPlanCode === plan.code}
            <!-- 青枠 (isHighlighted) が視覚で伝えている状態を、支援技術にも同じだけ伝える。
                 role を偽らない (排他選択なので aria-pressed は不可、radiogroup 化は
                 キーボード操作モデルの作り替えになる)。文言にプラン名を含めるのは、
                 PricingPlanCard が semantic group ではなく、テキスト単位で移動したときに
                 対象が読み上げ順に依存しないようにするため。
                 ★ 文言は CTA と同じ基準で 2 状態に分ける (下の責務表を参照)。 -->
            <span class="sr-only" data-testid={`plan-selected-note-${plan.code}`}>
                {#if chosenPlanCode === plan.code}
                    {plan.name} プランを選択中です
                {:else}
                    {plan.name} プランが初期選択されています
                {/if}
            </span>
        {/if}
    {/snippet}
    {#snippet footerCta()} ... {/snippet}
</PricingPlanCard>
```

### 3 つの表現の責務（明文化）

Codex R1 の指摘どおり、`isHighlighted` / sr-only note / CTA ラベルが**別々の基準**を持つと
視覚と支援技術で状態が食い違う。基準を表で固定する。

| 表現 | 基準 | `?plan=starter` 初期表示 | Standard を押した後 |
|---|---|---|---|
| 青枠 (`isHighlighted`) | `selectedPlanCode` | starter に枠 | Standard に枠 |
| CTA ラベル | `chosenPlanCode` | 全カード「選択」 | Standard だけ「選択中」 |
| **sr-only note (新規)** | `selectedPlanCode`（**存在**） + `chosenPlanCode`（**文言**） | starter に「Starter プランが**初期選択されています**」 | Standard に「Standard プランを**選択中です**」 |

- **note が出る/消える条件は青枠と完全に一致する** → 視覚と支援技術で「どのカードか」がズレない
- **note の文言は CTA と同じ基準で切り替わる** → 「まだ押していない」のに「選択中」と読み上げる
  誤認が起きない。CTA が「選択」のままの状態では note も「初期選択」と言う
- **CTA ラベルも青枠も一切変更しない**（視覚の挙動は現状のまま）

これにより「ユーザーが押していないものを選択中と呼ばない」という当初の意図を保ったまま、
視覚と支援技術の食い違いも消える。

### レイアウト不変性の担保（受入条件 11）

`headerBadges` を渡すと `PricingPlanCard` 側で
`<div class="ml-auto flex max-w-full min-w-0 flex-wrap justify-end gap-2">` ラッパが出現する。

> **当初案の (b)「ラッパの矩形が 0×0」は誤りなので採らない**（Codex R1 指摘）。
> `sr-only` は**子要素**を視覚的に隠すユーティリティであって、
> **親の flex item がレイアウト上の寸法を持たないことを保証する契約ではない**。
> `ml-auto` / `gap-2` を持つ flex item の実寸が 0 になる保証はないため、
> これを受入条件にすると実装が満たせない条件で赤くなる。

代わりに**カード内主要要素の位置で不変性を測る**（測定対象を、変わってはいけないものに合わせる）:

- (a) 同一 viewport で「選択されたカード」と「選択されていないカード」を比較し、
  カード内の**見出し (`h3`) / 価格 / 選択ボタン**の `getBoundingClientRect()` の
  `height` が一致し、`top` のカード上端からの相対オフセットが**許容差 1px 以内**で一致する
- (b) `plan-selected-note-*` 自身が**可視領域を持たない**
  （`getBoundingClientRect()` が 1px 四方以下、または `clip` / `clip-path` により
  視覚的に隠されている = `sr-only` が効いている）
- (c) カード全体 (`plan-card-{code}`) の `height` が選択有無で一致する
  （見出し行の折返しが起きていないことの実測）

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] **受入条件 9**: `?plan=starter` で開き、`plan-selected-note-starter` が存在して
      テキストに「Starter」と「初期選択」を含む。他プランの `plan-selected-note-*` は存在しない
- [ ] **受入条件 10**: 別プラン（Standard）の「選択」を押すと、
      starter の note が消え、Standard の note が現れ、その文言が「選択中」に切り替わる
      （note の**存在**が青枠と一致し、**文言**が CTA と一致することの同時固定）
- [ ] **受入条件 11**: 上記 (a)(b)(c) を `->script()` の `getBoundingClientRect()` で検査
- [ ] `Billing` / `Guest/Pricing` には**新規回帰テストを課さない**（コード無変更のため）

### テストデータ（Browser レーンの前提固定）

```php
[$organization, $owner] = createOrganizationWithOwner();  // 未契約のまま (onboarding.checkout は課金ゲート外)
$this->actingAs($owner);
visit('/onboarding/checkout?plan=starter');
```

- **プラン seed**: `/onboarding/checkout` は `PlanSeeder` が投入するプラン一覧を読む。
  Browser レーンの `RefreshDatabase` で seeder が走る前提を確認し、
  走らないなら当該テストで明示的に `$this->seed(PlanSeeder::class)` する
- **`onboarding.checkout` は課金ゲートの構造的 allowlist 内**（AGENTS.md ドメイン規約 4）なので、
  `contractPaidPlan()` は**呼ばない**（契約済みだと `/billing` へリダイレクトされる）
- **プラン名の実値**: 文言重複（「Personal プラン プランが〜」）を避けるため、
  実装前に `PlanSeeder` の `name` 実値を確認する。名前に「プラン」が含まれるなら
  文言を `{plan.name} が初期選択されています` に調整する

### リスク

- `sr-only` ユーティリティが Tailwind の標準クラスとして効いていること
  （既存先例: `atoms/Spinner.svelte` L43 / `CameraRecorder.svelte` L521 /
  `AppLayout.svelte` L231 / `Contact/Index.svelte` L168）。新規 CSS は足さない。
- プラン名に「プラン」が既に含まれる場合に「Personal プラン プランが〜」と重複しないか、
  実データ（`PlanSeeder` の `name`）を見て文言を確定する。
- **note の 2 状態分岐が CTA と同期し続けること**。将来 CTA の基準を変えるなら note も同時に変える
  （上の責務表がその契約であり、変更時はこの表を更新する）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 0 → A / B が依存関係で連なる（`buildCutLabels` が A の見出しと B の aria-label 双方の前提）。C は独立だが同じ frontend レーンで `pnpm build` / Browser テストを共有するため、分割すると build とテストを二重に回すことになる。全体で frontend 4 ファイル + 新規 lib 2 本 + テスト 4 本と小さく、1 worktree で完結する |
| 競合リスク | 低。PHP を 1 行も変更せず、触る Svelte は Capture 2 枚 + manual 1 枚 + Onboarding 1 枚で、他 TODO と重なる見込みが薄い。`CutNavigator` の書き換えは純粋な抽出で表示不変 |

## 実装順序（テストファースト）

1. **施策 0**: `cut-labels.test.ts` を先に書いて **fail を確認** → `cut-labels.ts` 作成 →
   `CutNavigator` を置換 → vitest 緑（表示不変の証明）
2. **施策 A**: `panel-navigation.test.ts`（純関数）→ `panel-navigation.ts` →
   `CaptureCutNavigationTest.php`（**fail を確認**）→ `Show.svelte` 改修 → Browser 2 レーン緑
3. **施策 B**: Browser の a11y 名検査を追加（**fail を確認**）→ 3 ファイル改修 → 緑
4. **施策 C**: `OnboardingPlanSelectionA11yTest.php`（**fail を確認**）→ `Checkout.svelte` 改修 → 緑
5. `pnpm build` → **AGENTS.md の検証コマンド一覧を全数**回して全緑にする:
   `composer test` / `composer test:browser` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   **`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`**

   > package 系 3 本は本変更が `packages/` に一切触れないため理屈上は無関係だが、
   > **AGENTS.md の検証コマンド一覧は「全 green でコミット」と定めており、
   > `verification-commands-doc-sync.test.ts` が一覧と package.json の同期を機械強制している**。
   > 「無関係だから省く」という判断を設計側で持ち込まない（省略の可否は規約側の問題であり、
   > 個々の TODO が独自に決めてよいことではない）。

> **Browser レーンは実ブラウザが `public/build` を読む**ため、Svelte を変更したら
> `pnpm build` を先に走らせること（`tests/Browser/SmokeTest.php` 冒頭コメント / `docs/testing-browser.md`）。

## 受入条件と検証手段の対応表（概念設計 11 条件の全件マップ）

| # | 受入条件 | 検証 |
|---|---|---|
| 1 | 撮影パネル見出しが矩形全体として viewport 内 | Browser `CaptureCutNavigationTest` |
| 2 | フォーカスが撮影パネル見出しへ移る | Browser 同上 |
| 3 | 2 カラムでは `scrollY` 不変 + **撮影パネル見出しへプログラムフォーカスしない**（クリック対象へのネイティブなフォーカス移動は許容） | Browser 同上（`->on()->desktop()`） + vitest（`navigateToPanelIfNeeded` が横並び矩形で何も呼ばない） |
| 4 | `captureActive` 中は動かない | **vitest** `panel-navigation.test.ts` の副作用テスト（`focus` / `scrollIntoView` を spy）。CI に実カメラが無く Browser で `captureActive` を作れないため検証手段のみ移す |
| 5 | reduced-motion で smooth を使わない | vitest `scrollBehaviorFor` |
| 6 | 戻るで視点とフォーカスが一覧側へ | Browser `CaptureCutNavigationTest` |
| 7 | `preview-video` の名前が「プレビュー」を含む（固定文言。playbackId は常に preview 由来） | Browser |
| 8 | `take-preview-video` の名前がカットラベルを含む | Browser |
| 9 | `?plan=` で当該プラン名 +「初期選択」を含む note が 1 つだけ | Browser `OnboardingPlanSelectionA11yTest` |
| 10 | 選び直しで note が移動し、文言が「選択中」へ切り替わる | Browser 同上 |
| 11 | Checkout のカード内主要要素の位置・高さが選択有無で不変 (a)(b)(c) | Browser 同上（`getBoundingClientRect`） |


---

## 追加で提示する現行コード (Round 1 の指摘を潰すために確認した実物)

### resources/js/components/atoms/TextLink.types.ts (ボタンモードの存在)
```ts
type ModeProps =
    | { href: string; external?: false; icon?: never; onclick?: (event: MouseEvent) => void }
    | { href: string; external: true; icon?: LucideIcon; onclick?: (event: MouseEvent) => void }
    | { href?: never; external?: never; icon?: never; onclick: (event: MouseEvent) => void };
```

### app/Http/Controllers/Projects/VideoManualController.php L141-144 (playbackJobId は preview のみ)
```php
                // playbackJobId は succeeded preview のみを見るため staleness 抑制の対象外 (不変)
                'playbackJobId' => $manual->renderJobs()
```

### resources/js/components/features/manual/RenderPanel.svelte L118-131 (render 分岐は playbackId を触らない)
```svelte
            if (target.kind === "render") {
                renderJob = body;
                status = body.manual_status;
                if (body.status === "succeeded") {
                    // reload は render 側終端でのみ発火 (preview 終端は local state 更新のみ)
                    stop();
                    router.reload();
                }
            } else {
                preview = body;
                if (body.status === "succeeded") {
                    playbackId = body.id;
                }
            }
```

### TakePreviewDialog / TakeStrip の call site 全数 (検索結果)
```
resources/js/components/features/capture/TakeStrip.svelte:316:<TakePreviewDialog
(resources/js 全体・tests/js 全体で他に無し)
```

### tests/Pest.php のヘルパ (Browser テストの前提固定に使う)
```php
function createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array
function contractPaidPlan(Organization $organization, string $status = 'active'): Subscription
function attachProjectMember(...)
function enableFakeStorage(): void
```

