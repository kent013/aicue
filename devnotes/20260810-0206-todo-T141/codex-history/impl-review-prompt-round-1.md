# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはコードレビュアーです。Laravel + Svelte アプリの改善実装をレビューしてください。

【前提環境】
PHP 8.4 / Laravel 12 / Svelte 5 (runes) / Inertia.js / TypeScript / PHPStan level 10 / Pest /
Browser lane は pest-plugin-browser (Chromium + WebKit 2 レーン) / vitest (tests/js)

【レビュー観点】
1. 設計との一致性 (詳細設計どおりに実装されているか。乖離があれば指摘)
2. コードの正確性 (ロジックエラー、エッジケース、null 安全性、リーク)
3. PHPStan level 10 適合性
4. DTO/JsonResource パターン
5. テスト網羅性 (受入条件 11 件が実際に固定されているか。すり抜ける余地がないか)
6. セキュリティ (AGENTS.md のセキュリティ不変条件)
7. **DESIGN.md 準拠**: design token 経由か、hex 直書き (#RRGGBB) を増やしていないか
8. **Atomic Design 準拠**: atoms/molecules/organisms/features/templates/pages の単方向 import、
   atom は単機能・無状態、アイコンは Lucide (SVG 直書きを増やさない)

【出力形式】
- ファイルごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
- テストファイル: 新規 `tests/js/lib/capture/panel-navigation.test.ts`（vitest / helper 契約）、
  新規 **`tests/js/pages/Capture/Show.test.ts`（vitest component test / ページ配線。受入条件 4 の必須要素）**、
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
export interface PanelNavigationInput {
    captureActive: boolean;
    leftEl: HTMLElement | null;
    rightEl: HTMLElement | null;
    headingEl: HTMLElement | null;
    reducedMotion: boolean;
}

export function navigateToPanelIfNeeded(input: PanelNavigationInput): boolean {
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
    `getBoundingClientRect()` が矩形全体として viewport 内（`top >= 0 && bottom <= innerHeight`）。
    > **smooth scroll の完了を待ってから評価する**。`behavior: "smooth"` は非同期なので、
    > クリック直後に測ると移動途中の座標を拾って flaky になる。
    > 待ち方は **「対象が viewport 内になるまで上限時間付きで polling」** を採る
    > （`window.scrollY` の 2 フレーム静止判定より安定する。慣性やアニメーション実装差で
    > 一瞬止まって見えるケースを踏まない）。この waiter をテストヘルパとして 1 つ用意し、
    > 受入条件 1・2・6 で共有する。上限時間内に成立しなければ**失敗**とする（無限待ちにしない）。
  - **受入条件 2**: 同操作後（同じ waiter 通過後）に `document.activeElement` が `capture-recording-heading`
  - **受入条件 3**: `->on()->desktop()` でカット行をクリックしても
    (a) `window.scrollY` が不変、(b) `document.activeElement` が
    **`capture-recording-heading` ではない**こと。
    > **注意**: 「`activeElement` が変化しない」と書いてはならない。ブラウザは
    > クリックした `<button>`（= カット行）自体にフォーカスを移すのが通常挙動であり、
    > それは本実装の副作用ではない。検証すべきは「**撮影パネル見出しへプログラムフォーカスしない**」ことである。
  - **受入条件 6**: `->on()->mobile()` で `back-to-cut-list` をクリックすると
    `cut-navigator` が viewport 内に入り、`document.activeElement` が左 pane の見出しになる
    （TextLink ボタンモードなので URL に `#` が付かないことも併せて確認する）
  - **受入条件 4 は Browser では検証しない**（CI に実カメラが無く `captureActive=true` を作れない）。
    代わりに下記の 2 段を**両方とも必須**とする。
- [ ] **受入条件 4 の 2 段構え（両方必須。どちらも省略不可）**
  - (i) **helper の抑止契約**: 上記 vitest。`captureActive=true` で `focus` / `scrollIntoView` が呼ばれない
  - (ii) **ページ配線**: `tests/js/pages/Capture/Show.test.ts`（vitest component test）。
    **副作用そのものではなく「helper に何が渡ったか」を検証する** ——
    `vi.mock("@/lib/capture/panel-navigation")` で module mock し、
    `Show.svelte` をマウント → `CameraRecorder` の `onCaptureActiveChange(true)` を発火 →
    カット行をクリック → **`navigateToPanelIfNeeded` の呼び出し引数の `captureActive` が `true`** であることを assert する。
    > この形なら jsdom の矩形・`focus`・`scrollIntoView` の実装差に依存せず、
    > **配線だけ**を直接固定できる（副作用の抑止は (i) が担う）。
  - **module mock が難しい場合は「未固定として完了」にしてはならない。**
    その場合は「渡す値を組み立てる部分」をさらに純関数へ抽出し
    （例: `buildPanelNavigationInput(...)` を `panel-navigation.ts` に置き、
    `Show.svelte` はその戻り値を渡すだけにする）、**テスト可能な境界まで設計を動かす**。
    受入条件 4 は録画中の UX 不変条件であり、**保証範囲を下げて完了にする選択肢は取らない**。

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
- **cuts 件数は「viewport 外にするための手段」であって条件ではない**。行高・折返し・
  mobile 実寸が変われば 14 件でも収まりうるので、**件数を条件にしない**。
  代わりに Browser テストが**クリック前に前提そのものを assert する**:
  ```js
  // 前提: この時点で撮影パネルは viewport の外にある (でなければテストは何も証明しない)
  document.querySelector('[data-testid=capture-right-pane]').getBoundingClientRect().top
      >= window.innerHeight
  ```
  この assert が落ちたら**テストデータ側を増やす**（件数は 14 件から始めるが、
  必要なら増やす。**条件は「viewport 外であること」**）。
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
>
> **(1) 初期値 `playbackJobId`** — `app/Http/Controllers/Projects/VideoManualController.php` L142-148。
> コメントではなく**実 query 条件**が preview に限定している:
> ```php
> 'playbackJobId' => $manual->renderJobs()
>     ->where('kind', RenderKind::Preview->value)      // ← kind を Preview に固定
>     ->where('status', JobStatus::Succeeded->value)   // ← succeeded のみ
>     ->whereNotNull('output_path')                    // ← 出力実体があるものだけ
>     ->latest('id')                                   // ← 最新 1 件
>     ->value('id'),
> ```
> `kind` の enum 比較で render job は構造的に混ざらない。
>
> **(2) 実行中の更新** — `RenderPanel.svelte` L118-131。`kind === "render"` の分岐は
> `renderJob` / `status` の更新と `router.reload()` だけで `playbackId` を**触らない**。
> `playbackId = body.id` は `else`（= preview）分岐かつ `body.status === "succeeded"` のときだけ実行される。
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
                    {plan.name} プランが初期候補として表示されています
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
| **sr-only note (新規)** | `selectedPlanCode`（**存在**） + `chosenPlanCode`（**文言**） | starter に「Starter プランが**初期候補として表示されています**」 | Standard に「Standard プランを**選択中です**」 |

- **note が出る/消える条件は青枠と完全に一致する** → 視覚と支援技術で「どのカードか」がズレない
- **note の文言は CTA と同じ基準で切り替わる** → 「まだ押していない」のに「選択中」と読み上げる
  誤認が起きない。**未押下時は「選択済み」を意味する語を使わない** ——
  「初期選択されています」も *選択済み* を含意するため採らず、**「初期候補として表示されています」**とする。
  CTA が「選択」（= まだ操作が必要）と言っているのと意味が一致する
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

代わりに**同一カードの状態変更「前後」で測る**（異なるカード同士の比較は成立しない ——
プランごとに名前・価格・機能数・CTA 内容が違うので、選択状態と無関係に高さや相対位置が異なる。
また grid の stretch でカード全体の高さだけ揃い、内部の折返しが隠れることもある）。

**測定は 2 枚のカードで非対称に行う。** Standard を押すと note の増加と**同時に
CTA ラベルが「選択」→「選択中」へ変わる**ため、Standard の CTA `height` の変化は
「`headerBadges` による退行」なのか「既存の CTA 文言差」なのか**判別できない**（交絡する）。
交絡しない対象だけを不変条件にする。

| カード | note の変化 | CTA ラベル | 前後比較する対象 |
|---|---|---|---|
| **Starter** | 有 → 無 | **不変**（「選択」のまま） | `h3` / 価格 / CTA の相対 `top` **と** `height`（交絡なし = 最も強い検査） |
| **Standard** | 無 → 有 | 「選択」→「選択中」に**変化** | `h3` / 価格 / CTA の相対 `top` **のみ**。**CTA の `height` は不変条件から外す** |

測定手順:

1. 初期状態（`?plan=starter`）で Starter と Standard それぞれについて、
   カード内の `h3` / 価格 / CTA の矩形を保存する（カード上端からの相対 `top` と `height`）
2. Standard の「選択」を押す
3. **同一カードの変更前後**を上表の対象について比較し、**許容差 1px 以内**であること
4. 両カードで `plan-selected-note-*` の `sr-only` 状態を別途検査する
   （矩形が 1px 四方以下、または `clip` / `clip-path` により視覚的に隠れている）

> **なぜ Starter が主役か**: Starter は CTA ラベルが動かないまま note だけが消えるので、
> 「`headerBadges` の有無がレイアウトを動かすか」を**単独要因**で測れる。
> Standard は補助（top のみ）に留める。
>
> Standard の CTA `height` を検査したい場合は、**CTA に固定寸法の契約が既にあるときだけ**にする
> （`Button` atom の `SIZE_CLASSES` が高さを固定しているなら検査してよい）。
> 実装時に `Button.types.ts` を見て判断し、契約が無ければ検査しない。

カード全体 (`plan-card-{code}`) の `height` の前後一致は**補助検査**として残すが、
主要な不変条件にはしない（grid stretch に吸収されて内部の折返しを見逃すため）。

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] **受入条件 9**: `?plan=starter` で開き、`plan-selected-note-starter` が存在して
      テキストに「Starter」と「初期候補」を含む（**「選択中」は含まない**）。
      他プランの `plan-selected-note-*` は存在しない
- [ ] **受入条件 10**: 別プラン（Standard）の「選択」を押すと、
      starter の note が消え、Standard の note が現れ、その文言が「選択中」に切り替わる
      （note の**存在**が青枠と一致し、**文言**が CTA と一致することの同時固定）
- [ ] **受入条件 11**: 上記の測定手順 1〜4 を `->script()` の `getBoundingClientRect()` で検査
      （**同一カードの前後比較**。異なるカード同士は比較しない）
- [ ] `Billing` / `Guest/Pricing` には**新規回帰テストを課さない**（コード無変更のため）

### テストデータ（Browser レーンの前提固定）

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

**リダイレクト条件をコードで確認した結果**（Codex R2 指摘。allowlist の記述だけを根拠にしない）:

- `app/Http/Controllers/Onboarding/OnboardingController.php` L61-68 が
  `hasActiveAccess($organization)` なら **`billing.index` へリダイレクト**、
  未契約 + `manageBilling` なしなら `onboarding.billing-required` へリダイレクトする
- `app/Services/Billing/BillingAccess.php` L74-76 は
  **`free_plan_code === PersonalPlanService::FREE_PLAN_CODE` を `ActiveFreePlan`** と判定し、
  `app/Enums/Billing/OnboardingBillingState.php` L25-28 の `grantsAccess()` は
  **`Subscribed` と `ActiveFreePlan` の両方で true** を返す
- `createOrganizationWithOwner()` の既定 `grandfatherFreePlan = true` は
  まさにその `free_plan_code` を立てる。**既定のままだと `hasActiveAccess` が true になり
  `/billing` へリダイレクトされ、Checkout に到達できない**
- → **`grandfatherFreePlan: false` を明示する**。オーナーは `manageBilling` を持つので
  `billing-required` にも飛ばない

**`?plan=` の 303 canonical redirect**: 同 Controller L72-76 が `?plan=` を org-scoped に
session へ積んでから **query 無しの canonical URL へ 303** する。よって
`assertPathIs('/onboarding/checkout')`（query 無し）で着地を固定し、
事前選択は session 経由で効く。**`?plan=starter` のまま URL に残る前提でテストを書かない。**

**プラン seed**: `tests/TestCase.php` L14 が **`protected bool $seed = true;`** を持つため、
Feature/Browser とも `DatabaseSeeder` が自動で走り `PlanSeeder` が投入される。
テスト側での明示 `$this->seed(PlanSeeder::class)` は**不要**（`contractPaidPlan()` が
seeded の `'standard'` を前提にしているのも同じ理由）。

**プラン名の実値**: 文言重複（「Personal プラン プランが〜」）を避けるため、
実装前に `PlanSeeder` の `name` 実値を確認する。名前に「プラン」が含まれるなら
文言を `{plan.name} が初期候補として表示されています` に調整する。

### リスク

- `sr-only` ユーティリティが Tailwind の標準クラスとして効いていること
  （既存先例: `atoms/Spinner.svelte` L43 / `CameraRecorder.svelte` L521 /
  `AppLayout.svelte` L231 / `Contact/Index.svelte` L168）。新規 CSS は足さない。
- プラン名に「プラン」が既に含まれる場合に「Personal プラン プランが〜」と重複しないか、
  実データ（`PlanSeeder` の `name`）を見て文言を確定する。
- **note の 2 状態分岐が CTA と同期し続けること**。将来 CTA の基準を変えるなら note も同時に変える
  （上の責務表がその契約であり、変更時はこの表を更新する）。
- **fixture が `grandfatherFreePlan: false` であること**。既定のままだと Checkout に到達できず、
  テストが「note が無い」ではなく「画面が違う」で落ちる（原因が分かりにくい失敗になる）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 0 → A / B が依存関係で連なる（`buildCutLabels` が A の見出しと B の aria-label 双方の前提）。C は独立だが同じ frontend レーンで `pnpm build` / Browser テストを共有するため、分割すると build とテストを二重に回すことになる。規模は **frontend 6 ファイル改修**（`CutNavigator` / `Show` / `RenderPanel` / `TakePreviewDialog` / `TakeStrip` / `Checkout`）**+ 新規 lib 2 本 + テスト 5 本**（vitest 3 = `cut-labels` / `panel-navigation` / `Capture/Show`、Browser 2 = `CaptureCutNavigationTest` / `OnboardingPlanSelectionA11yTest`）と小さく、1 worktree で完結する |
| 競合リスク | 低。PHP を 1 行も変更せず、触る Svelte は Capture 2 枚 + manual 1 枚 + Onboarding 1 枚で、他 TODO と重なる見込みが薄い。`CutNavigator` の書き換えは純粋な抽出で表示不変 |

## 実装順序（テストファースト）

1. **施策 0**: `cut-labels.test.ts` を先に書いて **fail を確認** → `cut-labels.ts` 作成 →
   `CutNavigator` を置換 → vitest 緑（表示不変の証明）
2. **施策 A**: `panel-navigation.test.ts`（helper 契約。**fail を確認**）→ `panel-navigation.ts` →
   **`tests/js/pages/Capture/Show.test.ts`（ページ配線。module mock で `captureActive` を検証。fail を確認）** →
   `CaptureCutNavigationTest.php`（**fail を確認**。クリック前の viewport 外 assert を含む）→
   `Show.svelte` 改修 → vitest 2 本 + Browser 2 レーン緑
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
| 4 | `captureActive` 中は動かない | **vitest 2 段を両方必須**: (i) `panel-navigation.test.ts`（helper の抑止契約）、(ii) `tests/js/pages/Capture/Show.test.ts`（**ページ配線** = module mock で `navigateToPanelIfNeeded` に渡る `captureActive` を検証）。(ii) の module mock が成立しない場合は、入力組み立てを純関数へ抽出し**ページ配線がテスト可能になるまで設計を変更する**。**未固定での完了は不可** |
| 5 | reduced-motion で smooth を使わない | vitest `scrollBehaviorFor` |
| 6 | 戻るで視点とフォーカスが一覧側へ | Browser `CaptureCutNavigationTest` |
| 7 | `preview-video` の名前が「プレビュー」を含む（固定文言。playbackId は常に preview 由来） | Browser |
| 8 | `take-preview-video` の名前がカットラベルを含む | Browser |
| 9 | `?plan=` で当該プラン名 +「初期候補」を含む note が 1 つだけ（「選択中」を含まない） | Browser `OnboardingPlanSelectionA11yTest` |
| 10 | 選び直しで note が移動し、文言が「選択中」へ切り替わる | Browser 同上 |
| 11 | Checkout の**同一カード**の前後比較。Starter = 相対 top + height（CTA 文言不変で交絡なし）、Standard = 相対 top のみ（CTA 文言が変わるため height は除外）。+ 両カードで note が不可視 | Browser 同上（`getBoundingClientRect`） |


---

## 実装差分 (git diff)

```diff
diff --git a/resources/js/components/features/capture/CutNavigator.svelte b/resources/js/components/features/capture/CutNavigator.svelte
index 0821120..1eff868 100644
--- a/resources/js/components/features/capture/CutNavigator.svelte
+++ b/resources/js/components/features/capture/CutNavigator.svelte
@@ -1,6 +1,7 @@
 <script lang="ts">
     import { Check, MapPin, Video } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
+    import { buildCutLabels } from "@/lib/capture/cut-labels";
     import type { CaptureCut } from "@/types/capture";
 
     /**
@@ -15,23 +16,12 @@
 
     let { cuts, selectedCutId, onSelect }: Props = $props();
 
-    /** 手順番号ラベル (step は連番、point は親 step の番号 + 枝番) */
-    const labels = $derived.by(() => {
-        const result: Record<number, string> = {};
-        let stepIndex = 0;
-        let pointIndex = 0;
-        for (const cut of cuts) {
-            if (cut.type === "step") {
-                stepIndex += 1;
-                pointIndex = 0;
-                result[cut.id] = `手順 ${stepIndex}`;
-            } else {
-                pointIndex += 1;
-                result[cut.id] = `急所 ${stepIndex}-${pointIndex}`;
-            }
-        }
-        return result;
-    });
+    /**
+     * 手順番号ラベル (step は連番、point は親 step の番号 + 枝番)。
+     * 導出規則は lib/capture/cut-labels.ts が唯一の正本 (撮影パネル見出し・
+     * テイクプレビューの aria-label と共有するため)。
+     */
+    const labels = $derived(buildCutLabels(cuts));
 </script>
 
 <ul class="divide-y divide-border" data-testid="cut-navigator">
diff --git a/resources/js/components/features/capture/TakePreviewDialog.svelte b/resources/js/components/features/capture/TakePreviewDialog.svelte
index a972497..5abf6ac 100644
--- a/resources/js/components/features/capture/TakePreviewDialog.svelte
+++ b/resources/js/components/features/capture/TakePreviewDialog.svelte
@@ -14,6 +14,8 @@
         open: boolean; // bindable
         take: CaptureTake | null; // 再生対象 (null で閉)
         cut: CaptureCut; // 字幕 (subtitle_primary/secondary) の供給元
+        /** 手順 N / 急所 N-M。video のアクセシブルネームに使う (どのカットのテイクか) */
+        cutLabel: string;
         playbackUrl: string | null; // takeUrl(take, "/playback")。親が組み立て
         adopting: boolean; // 採用 XHR 中
         error: string | null; // 採用失敗メッセージ (親の run() error を流用)
@@ -25,6 +27,7 @@
         open = $bindable(false),
         take,
         cut,
+        cutLabel,
         playbackUrl,
         adopting,
         error,
@@ -81,6 +84,7 @@
                         playsinline
                         src={playbackUrl ?? undefined}
                         class="w-full"
+                        aria-label={`${cutLabel} のテイク再生`}
                         data-testid="take-preview-video"
                     ></video>
                 {/key}
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
index 560a1a1..108f7d7 100644
--- a/resources/js/components/features/capture/TakeStrip.svelte
+++ b/resources/js/components/features/capture/TakeStrip.svelte
@@ -17,6 +17,8 @@
         projectId: number;
         manualId: number;
         cut: CaptureCut;
+        /** 手順 N / 急所 N-M。TakePreviewDialog の video アクセシブルネームへ中継する */
+        cutLabel: string;
         onChanged: () => void;
         /** 撮影 active (recording|stopping) なら preview を開かずエラー表示 (資源競合防止) */
         captureActive?: boolean;
@@ -30,6 +32,7 @@
         projectId,
         manualId,
         cut,
+        cutLabel,
         onChanged,
         captureActive = false,
         onRequestCameraRelease,
@@ -317,6 +320,7 @@
     bind:open={previewOpen}
     take={previewTarget}
     {cut}
+    {cutLabel}
     playbackUrl={previewUrl}
     adopting={previewTarget !== null && busyTakeId === previewTarget.id}
     {error}
diff --git a/resources/js/components/features/manual/RenderPanel.svelte b/resources/js/components/features/manual/RenderPanel.svelte
index b355936..fa70baf 100644
--- a/resources/js/components/features/manual/RenderPanel.svelte
+++ b/resources/js/components/features/manual/RenderPanel.svelte
@@ -367,11 +367,15 @@
             {/if}
             {#if playbackId !== null && !previewInFlight}
                 <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
+                <!-- aria-label は固定文言でよい: playbackId の供給源は初期値 (Controller が
+                     kind=Preview ∧ status=Succeeded で抽出) と poll の preview 分岐だけで、
+                     render job が入る経路が無い (完成動画と取り違わない)。 -->
                 <video
                     controls
                     preload="metadata"
                     class="w-full rounded-md bg-neutral"
                     src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackId}/playback`}
+                    aria-label="プレビュー動画"
                     data-testid="preview-video"
                 ></video>
             {/if}
diff --git a/resources/js/lib/capture/cut-labels.ts b/resources/js/lib/capture/cut-labels.ts
new file mode 100644
index 0000000..5563fb0
--- /dev/null
+++ b/resources/js/lib/capture/cut-labels.ts
@@ -0,0 +1,26 @@
+import type { CaptureCut } from "@/types/capture";
+
+/**
+ * カットの表示ラベル (手順 N / 急所 N-M) を cuts の並び順から導出する。
+ * step は連番、point は直前 step の番号 + 枝番 (doc/10 §10.1)。
+ *
+ * CutNavigator の行ラベル・撮影パネルの見出し (F-1-03) ・テイクプレビューの
+ * アクセシブルネーム (F-1-02) が同じ規則を共有するため、ここを唯一の導出元とする
+ * (規則が 3 箇所に散るのを避ける)。
+ */
+export function buildCutLabels(cuts: CaptureCut[]): Record<number, string> {
+    const result: Record<number, string> = {};
+    let stepIndex = 0;
+    let pointIndex = 0;
+    for (const cut of cuts) {
+        if (cut.type === "step") {
+            stepIndex += 1;
+            pointIndex = 0;
+            result[cut.id] = `手順 ${stepIndex}`;
+        } else {
+            pointIndex += 1;
+            result[cut.id] = `急所 ${stepIndex}-${pointIndex}`;
+        }
+    }
+    return result;
+}
diff --git a/resources/js/lib/capture/panel-navigation.ts b/resources/js/lib/capture/panel-navigation.ts
new file mode 100644
index 0000000..157212d
--- /dev/null
+++ b/resources/js/lib/capture/panel-navigation.ts
@@ -0,0 +1,84 @@
+/**
+ * 撮影ナビの「視点とフォーカスを運ぶ」責務 (詳細設計 施策 A / bug-hunt F-1-03)。
+ *
+ * 1 カラム表示ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットをタップしても
+ * 撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
+ * 視覚的なスクロールだけを直すとキーボード / スクリーンリーダーの現在位置は一覧側に残るので、
+ * **視点とフォーカスを同時に運ぶ**。
+ *
+ * 副作用 (focus / scrollIntoView) ごとここに置く。述語だけを切り出すと抑止条件が実際に
+ * 副作用を止めているかを page component の外から検証できず、回帰を固定できないため。
+ */
+
+/** 縦積み判定の許容差 (px)。sub-pixel と border 由来のズレを吸収する。 */
+const STACK_TOLERANCE_PX = 4;
+
+export interface PanelNavigationInput {
+    /** 録画中 / getUserMedia grant 待ち (CameraRecorder の公開 active)。true なら何もしない */
+    captureActive: boolean;
+    leftEl: HTMLElement | null;
+    rightEl: HTMLElement | null;
+    headingEl: HTMLElement | null;
+    reducedMotion: boolean;
+}
+
+/**
+ * 右 pane が左 pane の「下」に積まれているか (= 1 カラム表示か) を実測で判定する。
+ * lg breakpoint の値を JS 側へコピーしない (Tailwind 設定との二重管理を避ける) ため、座標で判定する。
+ */
+export function isStackedLayout(leftRect: DOMRect, rightRect: DOMRect): boolean {
+    return rightRect.top >= leftRect.bottom - STACK_TOLERANCE_PX;
+}
+
+/** scrollIntoView の behavior。prefers-reduced-motion: reduce なら smooth を使わない。 */
+export function scrollBehaviorFor(reducedMotion: boolean): ScrollBehavior {
+    return reducedMotion ? "auto" : "smooth";
+}
+
+/**
+ * ブラウザ側でのみ評価する。
+ * SSR / matchMedia 非対応では true (= アニメーションしない) に倒す:
+ * 「動かさない」は常に安全側で、逆は存在しない環境で不要な副作用を仮定することになる。
+ */
+export function prefersReducedMotion(): boolean {
+    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return true;
+    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
+}
+
+/**
+ * カット選択時に視点とフォーカスを撮影パネルへ運ぶ。
+ *
+ * - captureActive の間は動かさない。captureActive は CameraRecorder の公開 active
+ *   (`starting || resuming || phase !== "idle"`) で、getUserMedia の grant 待ち 2 窓を含む =
+ *   権限ダイアログ中・カメラ初期化中も抑止される。
+ * - 2 カラム (横並び) では左右が同時に見えているので動かさない。
+ * - focus() 自体が暗黙スクロールを起こすため、preventScroll してから scrollIntoView する
+ *   (二重移動の防止)。
+ *
+ * @returns 実際にナビゲートしたか
+ */
+export function navigateToPanelIfNeeded(input: PanelNavigationInput): boolean {
+    const { captureActive, leftEl, rightEl, headingEl, reducedMotion } = input;
+    if (captureActive) return false;
+    if (leftEl === null || rightEl === null || headingEl === null) return false;
+    if (!isStackedLayout(leftEl.getBoundingClientRect(), rightEl.getBoundingClientRect())) {
+        return false;
+    }
+    headingEl.focus({ preventScroll: true });
+    headingEl.scrollIntoView({ behavior: scrollBehaviorFor(reducedMotion), block: "start" });
+    return true;
+}
+
+/**
+ * 「カット一覧へ戻る」。視点とフォーカスの両方を一覧側へ返す
+ * (スクロールで運んだ以上、帰り道が無ければ別の詰みを作るため)。
+ */
+export function navigateBackToList(
+    headingEl: HTMLElement | null,
+    reducedMotion: boolean,
+): boolean {
+    if (headingEl === null) return false;
+    headingEl.focus({ preventScroll: true });
+    headingEl.scrollIntoView({ behavior: scrollBehaviorFor(reducedMotion), block: "start" });
+    return true;
+}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 8ada496..96bcc51 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -1,5 +1,5 @@
 <script lang="ts">
-    import { onMount } from "svelte";
+    import { onMount, tick } from "svelte";
     import { page, router } from "@inertiajs/svelte";
     import { ArrowLeft, Video } from "@lucide/svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
@@ -15,6 +15,13 @@
     import { AdoptedTakeAutoDownloader } from "@/lib/capture/auto-download";
     import { supportsMediaRecorder } from "@/lib/capture/camera";
     import type { CameraUnavailableReason } from "@/lib/capture/camera";
+    import { buildCutLabels } from "@/lib/capture/cut-labels";
+    import {
+        isStackedLayout,
+        navigateBackToList,
+        navigateToPanelIfNeeded,
+        prefersReducedMotion,
+    } from "@/lib/capture/panel-navigation";
     import { createIdbPendingStore } from "@/lib/capture/idb";
     import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
     import type { PendingStore } from "@/lib/capture/upload-queue";
@@ -38,6 +45,8 @@
 
     let selectedCutId = $state<number | null>(null);
     const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
+    /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
+    const cutLabels = $derived(buildCutLabels(manual.cuts));
     // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)
     const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
     let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
@@ -81,6 +90,56 @@
         router.reload({ only: ["manual"] });
     }
 
+    /* ---- 撮影パネルへの視点/フォーカス移送 (F-1-03) ----
+     * 1 カラム表示ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットをタップしても
+     * 撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
+     * 判定と副作用は lib/capture/panel-navigation.ts が持つ (page は配線だけ)。 */
+    let leftPaneEl = $state<HTMLElement | null>(null);
+    let rightPaneEl = $state<HTMLElement | null>(null);
+    let recordingHeadingEl = $state<HTMLElement | null>(null);
+    let cutListHeadingEl = $state<HTMLElement | null>(null);
+    /** 縦積みか (= 1 カラム)。「カット一覧へ戻る」の出し分けに使う */
+    let stacked = $state(false);
+
+    function updateStacked(): void {
+        if (leftPaneEl === null || rightPaneEl === null) return;
+        stacked = isStackedLayout(
+            leftPaneEl.getBoundingClientRect(),
+            rightPaneEl.getBoundingClientRect(),
+        );
+    }
+
+    function handleSelectCut(cutId: number): void {
+        selectedCutId = cutId;
+        // DOM 反映後に測る (撮影パネルは選択で初めて描画される)
+        void tick().then(() => {
+            updateStacked();
+            navigateToPanelIfNeeded({
+                captureActive,
+                leftEl: leftPaneEl,
+                rightEl: rightPaneEl,
+                headingEl: recordingHeadingEl,
+                reducedMotion: prefersReducedMotion(),
+            });
+        });
+    }
+
+    /** 視点で運んだ以上、帰り道も用意する (行き先のない詰みを作らない) */
+    function backToCutList(): void {
+        navigateBackToList(cutListHeadingEl, prefersReducedMotion());
+    }
+
+    $effect(() => {
+        if (leftPaneEl === null || rightPaneEl === null) return;
+        // observer の初回 callback はタイミング差があるため当てにせず、登録前に必ず 1 回測る
+        updateStacked();
+        if (typeof ResizeObserver === "undefined") return;
+        const observer = new ResizeObserver(() => updateStacked());
+        observer.observe(leftPaneEl);
+        observer.observe(rightPaneEl);
+        return () => observer.disconnect();
+    });
+
     async function handleCaptured(blob: Blob, mimeType: string, durationMs: number | null): Promise<void> {
         if (selectedCutId === null) return;
         uploading = true;
@@ -176,23 +235,54 @@
     </div>
 
     <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
-        <section class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
-            <h2 class="border-b border-border px-3 py-2 text-caption text-text-secondary">
+        <section
+            bind:this={leftPaneEl}
+            class="min-w-0 rounded-md border border-border bg-surface"
+            data-testid="capture-left-pane"
+        >
+            <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
+                 フォーカス可能にする (Tab 順には入れない)。 -->
+            <h2
+                bind:this={cutListHeadingEl}
+                tabindex="-1"
+                class="border-b border-border px-3 py-2 text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+                data-testid="capture-cut-list-heading"
+            >
                 シナリオ (タップして撮影)
             </h2>
-            <CutNavigator
-                cuts={manual.cuts}
-                {selectedCutId}
-                onSelect={(cutId) => (selectedCutId = cutId)}
-            />
+            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
         </section>
 
-        <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
+        <section
+            bind:this={rightPaneEl}
+            class="flex min-w-0 flex-col gap-4"
+            data-testid="capture-right-pane"
+        >
             {#if selectedCut === null}
                 <p class="text-caption text-text-secondary">
                     左のシナリオからカットを選ぶと撮影パネルが開きます。
                 </p>
             {:else}
+                <div class="flex items-center justify-between gap-2">
+                    <!-- カット選択時のフォーカス着地点。ラベルを含めて「どのカットの撮影か」を
+                         名前で伝える (視点だけ運んでフォーカスを残すと a11y 欠落を作るため)。 -->
+                    <h2
+                        bind:this={recordingHeadingEl}
+                        tabindex="-1"
+                        class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+                        data-testid="capture-recording-heading"
+                    >
+                        {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
+                    </h2>
+                    {#if stacked}
+                        <!-- 1 カラムのときだけ出す (2 カラムでは一覧が常に見えているので不要)。
+                             TextLink のボタンモード (href なし + onclick) = <button type="button">。 -->
+                        <TextLink onclick={backToCutList} testId="back-to-cut-list">
+                            カット一覧へ戻る
+                        </TextLink>
+                    {/if}
+                </div>
+
                 <div class="rounded-md border border-border bg-surface p-3">
                     <p class="text-caption text-text-secondary">ナレーション</p>
                     <p class="mt-1 text-body">{selectedCut.narration}</p>
@@ -232,6 +322,7 @@
                     projectId={project.id}
                     manualId={manual.id}
                     cut={selectedCut}
+                    cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
                     onChanged={reloadManual}
                     {captureActive}
                     onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
diff --git a/resources/js/pages/Onboarding/Checkout.svelte b/resources/js/pages/Onboarding/Checkout.svelte
index 19878af..f3a4127 100644
--- a/resources/js/pages/Onboarding/Checkout.svelte
+++ b/resources/js/pages/Onboarding/Checkout.svelte
@@ -197,6 +197,27 @@
                             isHighlighted={selectedPlanCode === plan.code}
                             testId={`plan-card-${plan.code}`}
                         >
+                            {#snippet headerBadges()}
+                                {#if selectedPlanCode === plan.code}
+                                    <!-- 青枠 (isHighlighted) が視覚で伝えている状態を、支援技術にも
+                                         同じだけ伝える (F-2-01)。role は偽らない: 排他選択なので
+                                         aria-pressed は誤りで、radiogroup 化はキーボード操作モデルの
+                                         作り替えになる。文言にプラン名を含めるのは、カードが semantic
+                                         group ではなくテキスト単位の移動で対象が読み上げ順に依存する
+                                         のを避けるため。文言は CTA と同じ基準 (chosenPlanCode) で
+                                         切り替え、未押下を「選択済み」と誤認させない。 -->
+                                    <span
+                                        class="sr-only"
+                                        data-testid={`plan-selected-note-${plan.code}`}
+                                    >
+                                        {#if chosenPlanCode === plan.code}
+                                            {plan.name} プランを選択中です
+                                        {:else}
+                                            {plan.name} プランが初期候補として表示されています
+                                        {/if}
+                                    </span>
+                                {/if}
+                            {/snippet}
                             {#snippet footerCta()}
                                 <div class="flex flex-col gap-2">
                                     {#if showRecommendedBadge(plan.code)}
diff --git a/tests/Browser/CaptureCutNavigationTest.php b/tests/Browser/CaptureCutNavigationTest.php
new file mode 100644
index 0000000..4409dab
--- /dev/null
+++ b/tests/Browser/CaptureCutNavigationTest.php
@@ -0,0 +1,260 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\Take;
+use App\Models\VideoManual;
+
+/*
+|--------------------------------------------------------------------------
+| 撮影ナビ: カット選択時の視点/フォーカス移送 (bug-hunt F-1-03)
+|--------------------------------------------------------------------------
+|
+| 1 カラム (モバイル) ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットを
+| タップしても撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
+| 「視点」だけ運んでフォーカスを一覧側に残すと a11y 欠落を作るので、両方運ぶことを固定する。
+|
+| 受入条件 4 (captureActive 中は動かない) は CI に実カメラが無いため
+| tests/js/lib/capture/panel-navigation.test.ts (抑止契約) と
+| tests/js/pages/CaptureShow.test.ts (ページ配線) の 2 段で固定している。
+|
+*/
+
+/**
+ * 撮影ナビの前提を一式作る。
+ *
+ * 撮影 PWA は require-active-subscription group 内 (AGENTS.md ドメイン規約 4) なので
+ * contractPaidPlan を通さないと /billing-required に着地する。
+ *
+ * cuts の件数は「viewport 外にするための手段」であって条件ではない。
+ * 実際に viewport 外であることは各テストがクリック前に assert する。
+ *
+ * @return array{0: Project, 1: VideoManual}
+ */
+function captureNavigationFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization);
+
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()
+        ->forProject($project)
+        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);
+
+    foreach (range(1, 20) as $index) {
+        Cut::factory()->forManual($manual)->create(['sort_order' => $index]);
+    }
+
+    test()->actingAs($owner);
+
+    return [$project, $manual];
+}
+
+/** capture.manuals.show の URL */
+function captureShowUrl(Project $project, VideoManual $manual): string
+{
+    return "/app/projects/{$project->id}/manuals/{$manual->id}";
+}
+
+/**
+ * smooth scroll は非同期なので、クリック直後に測ると移動途中の座標を拾って flaky になる。
+ * 「対象が viewport 内に入るまで」上限付きで polling する (scrollY の静止判定より安定)。
+ * 上限を超えたら待たずに抜け、呼び出し側の assert が失敗する (無限待ちにしない)。
+ */
+function waitUntilHeadingInViewport(mixed $page, int $attempts = 40): void
+{
+    for ($i = 0; $i < $attempts; $i++) {
+        $inside = $page->script(<<<'JS'
+            (() => {
+                const el = document.querySelector('[data-testid="capture-recording-heading"]');
+                if (el === null) return false;
+                const r = el.getBoundingClientRect();
+                return r.top >= 0 && r.bottom <= window.innerHeight;
+            })()
+        JS);
+
+        if ($inside === true) {
+            return;
+        }
+
+        usleep(100_000);
+    }
+}
+
+test('モバイル幅ではカット選択で撮影パネルが viewport に入りフォーカスも移る', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');
+
+    // ★ on()->mobile() が返す On は __call のたびに新しいページを作るため、
+    //   ここで 1 度だけ materialize して以降は同じ Webpage を使い回す。
+    $page = visit(captureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(captureShowUrl($project, $manual));
+
+    // 前提: この時点で撮影パネルは viewport の外にある。
+    // これが成り立たないとテストは何も証明しない (修正前でも緑になってしまう)。
+    expect($page->script(<<<'JS'
+        (() => {
+            const el = document.querySelector('[data-testid="capture-right-pane"]');
+            return el.getBoundingClientRect().top >= window.innerHeight;
+        })()
+    JS))->toBeTrue();
+
+    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
+    waitUntilHeadingInViewport($page);
+
+    // 受入条件 1: 見出しが「矩形全体として」viewport 内 (1px 交差では不可)
+    expect($page->script(<<<'JS'
+        (() => {
+            const r = document.querySelector('[data-testid="capture-recording-heading"]')
+                .getBoundingClientRect();
+            return r.top >= 0 && r.bottom <= window.innerHeight;
+        })()
+    JS))->toBeTrue();
+
+    // 受入条件 2: フォーカスも撮影パネル先頭へ移る
+    expect($page->script(
+        'document.activeElement?.dataset?.testid ?? null'
+    ))->toBe('capture-recording-heading');
+});
+
+test('デスクトップ幅ではカット選択でスクロールも撮影パネルへのフォーカスも起きない', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');
+
+    $page = visit(captureShowUrl($project, $manual))->on()->desktop()
+        ->assertPathIs(captureShowUrl($project, $manual));
+
+    $before = $page->script('window.scrollY');
+
+    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
+    usleep(500_000); // 動くなら動き切るだけの猶予を与えたうえで「動いていない」ことを見る
+
+    expect($page->script('window.scrollY'))->toBe($before);
+
+    // 「activeElement が変化しない」ではない: クリックした <button> にブラウザが
+    // フォーカスを移すのは通常挙動であり本実装の副作用ではない。
+    // 検証すべきは「撮影パネル見出しへプログラムフォーカスしない」こと。
+    expect($page->script(
+        'document.activeElement?.dataset?.testid ?? null'
+    ))->not->toBe('capture-recording-heading');
+});
+
+test('モバイル幅では撮影パネルからカット一覧へ視点とフォーカスの両方が戻る', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');
+
+    $page = visit(captureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(captureShowUrl($project, $manual));
+    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
+    waitUntilHeadingInViewport($page);
+
+    $page->click('[data-testid="back-to-cut-list"]');
+
+    // 一覧見出しが viewport に入るまで待つ (同じ理由で polling)
+    for ($i = 0; $i < 40; $i++) {
+        $inside = $page->script(<<<'JS'
+            (() => {
+                const el = document.querySelector('[data-testid="capture-cut-list-heading"]');
+                const r = el.getBoundingClientRect();
+                return r.top >= 0 && r.bottom <= window.innerHeight;
+            })()
+        JS);
+
+        if ($inside === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    expect($page->script(<<<'JS'
+        (() => {
+            const r = document.querySelector('[data-testid="capture-cut-list-heading"]')
+                .getBoundingClientRect();
+            return r.top >= 0 && r.bottom <= window.innerHeight;
+        })()
+    JS))->toBeTrue();
+
+    expect($page->script(
+        'document.activeElement?.dataset?.testid ?? null'
+    ))->toBe('capture-cut-list-heading');
+
+    // TextLink のボタンモード (href なし) なので URL に # が付かない
+    expect($page->script('window.location.hash'))->toBe('');
+});
+
+test('テイク再生の video のアクセシブルネームに手順ラベルが入る (F-1-02)', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCut = $manual->cuts()->orderBy('sort_order')->first();
+    $take = Take::factory()->forCut($firstCut)->create();
+
+    $page = visit(captureShowUrl($project, $manual))->on()->desktop()
+        ->assertPathIs(captureShowUrl($project, $manual));
+    $page->click("[data-testid=\"cut-row-{$firstCut->id}\"]");
+    $page->click("[data-testid=\"take-preview-{$take->id}\"]");
+
+    // ダイアログ内の video が描画されるまで待つ
+    for ($i = 0; $i < 40; $i++) {
+        $exists = $page->script(
+            'document.querySelector(\'[data-testid="take-preview-video"]\') !== null'
+        );
+
+        if ($exists === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    // 受入条件 8: 非空ではなく「どのカットのテイクか」が分かる意味内容を固定する
+    // (完全一致は i18n 変更に脆いので必要語の包含で見る)
+    expect($page->attribute('[data-testid="take-preview-video"]', 'aria-label'))
+        ->toContain('手順 1');
+});
+
+test('プレビュー動画の video にアクセシブルネームがある (F-1-02)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization);
+
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()
+        ->forProject($project)
+        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);
+    Cut::factory()->forManual($manual)->create(['sort_order' => 1]);
+
+    // playbackJobId は「kind=Preview ∧ status=Succeeded ∧ output_path 非 null」でのみ引かれる
+    RenderJob::factory()
+        ->forManual($manual)
+        ->preview()
+        ->create([
+            'status' => JobStatus::Succeeded->value,
+            'output_path' => 'renders/preview-fixture.mp4',
+        ]);
+
+    $this->actingAs($owner);
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")->on()->desktop()
+        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}");
+
+    for ($i = 0; $i < 40; $i++) {
+        $exists = $page->script(
+            'document.querySelector(\'[data-testid="preview-video"]\') !== null'
+        );
+
+        if ($exists === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    // 受入条件 7: 固定文言「プレビュー動画」(playbackId は常に preview 由来なので
+    // 完成動画と取り違える経路が無く、状態分岐を持たない)
+    expect($page->attribute('[data-testid="preview-video"]', 'aria-label'))
+        ->toContain('プレビュー');
+});
diff --git a/tests/Browser/OnboardingPlanSelectionA11yTest.php b/tests/Browser/OnboardingPlanSelectionA11yTest.php
new file mode 100644
index 0000000..7b0cfff
--- /dev/null
+++ b/tests/Browser/OnboardingPlanSelectionA11yTest.php
@@ -0,0 +1,171 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+|--------------------------------------------------------------------------
+| オンボーディング: プラン選択状態のアクセシビリティ (bug-hunt F-2-01)
+|--------------------------------------------------------------------------
+|
+| /onboarding/checkout?plan=starter で該当カードが青枠 (border-primary) で強調されるが、
+| その状態がアクセシビリティツリーに一切現れていなかった。契約という不可逆操作の前段で
+| 「どのプランが選ばれているか」が支援技術利用者に伝わらないのは実害がある。
+|
+| role は偽らない: 排他選択なので aria-pressed (トグル) は誤りで、radiogroup 化は
+| 契約画面のキーボード操作モデルを作り替える規模になる。青枠が伝えている一事を
+| sr-only テキストで同じだけ伝える (Billing が「現在のプラン」Badge = テキストで
+| 同種の状態を伝えているのと同じ手口)。
+|
+| 注意: 既定の createOrganizationWithOwner() は free_plan_code を立てるため
+| BillingAccess が ActiveFreePlan と判定し、Checkout は /billing へリダイレクトされる。
+| grandfatherFreePlan: false を明示しないとこの画面に到達できない。
+|
+*/
+
+/** 未契約オーナーでログインし、Checkout に到達できる状態を作る */
+function checkoutFixture(): void
+{
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    test()->actingAs($owner);
+}
+
+test('?plan= の事前選択が sr-only テキストでアクセシビリティツリーに現れる', function (): void {
+    checkoutFixture();
+
+    // ?plan= は org-scoped に session へ積まれ canonical URL へ 303 されるため、
+    // 着地は query 無しの /onboarding/checkout になる
+    $page = visit('/onboarding/checkout?plan=starter')
+        ->assertPathIs('/onboarding/checkout');
+
+    expect($page->script('window.location.search'))->toBe('');
+
+    // 受入条件 9: starter だけが「プラン名 + 初期候補」の note を持つ
+    $note = $page->text('[data-testid="plan-selected-note-starter"]');
+    expect($note)->toContain('Starter');
+    expect($note)->toContain('初期候補');
+    // まだ押していないので「選択中」とは言わない (CTA が「選択」のままなのと意味を揃える)
+    expect($note)->not->toContain('選択中');
+
+    expect($page->script(
+        'document.querySelectorAll(\'[data-testid^="plan-selected-note-"]\').length'
+    ))->toBe(1);
+});
+
+test('別プランを選び直すと note が移動し文言が選択中へ切り替わる', function (): void {
+    checkoutFixture();
+
+    $page = visit('/onboarding/checkout?plan=starter')
+        ->assertPathIs('/onboarding/checkout');
+
+    $page->click('[data-testid="select-plan-standard"]');
+
+    // 受入条件 10: 旧 note が消え、新プラン名を含む note が現れる
+    for ($i = 0; $i < 40; $i++) {
+        $moved = $page->script(
+            'document.querySelector(\'[data-testid="plan-selected-note-standard"]\') !== null'
+        );
+
+        if ($moved === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    expect($page->script(
+        'document.querySelector(\'[data-testid="plan-selected-note-starter"]\') === null'
+    ))->toBeTrue();
+
+    $note = $page->text('[data-testid="plan-selected-note-standard"]');
+    expect($note)->toContain('Standard');
+    // 押下後は CTA が「選択中」になるので note も同じ基準で切り替わる
+    expect($note)->toContain('選択中');
+});
+
+test('sr-only note の追加でカードのレイアウトが動かない', function (): void {
+    checkoutFixture();
+
+    $page = visit('/onboarding/checkout?plan=starter')
+        ->assertPathIs('/onboarding/checkout');
+
+    // カード上端からの相対 top と height を測る (異なるカード同士は比較しない)。
+    // 欠落を黙って握り潰さないよう、カードが無ければ null を明示的に返す。
+    $measure = <<<'JS'
+        (() => {
+            const out = {};
+            for (const code of ['starter', 'standard']) {
+                const card = document.querySelector('[data-testid="plan-card-' + code + '"]');
+                if (card === null) { out[code] = null; continue; }
+                const base = card.getBoundingClientRect().top;
+                const pick = (sel) => {
+                    const el = card.querySelector(sel);
+                    if (el === null) return null;
+                    const r = el.getBoundingClientRect();
+                    return {
+                        top: Math.round((r.top - base) * 100) / 100,
+                        height: Math.round(r.height * 100) / 100,
+                    };
+                };
+                out[code] = {
+                    heading: pick('h3'),
+                    cta: pick('[data-testid="select-plan-' + code + '"]'),
+                };
+            }
+            return out;
+        })()
+    JS;
+
+    // script() は locator と違って自動待機しないため、hydration 完了までは
+    // カードが DOM に無い。計測前に明示的に待つ。
+    for ($i = 0; $i < 40; $i++) {
+        $ready = $page->script(
+            'document.querySelector(\'[data-testid="plan-card-starter"]\') !== null'
+        );
+
+        if ($ready === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    $before = $page->script($measure);
+    // 計測対象が取れていることを先に固定する (取れないまま比較して緑になるのを防ぐ)
+    expect($before['starter'])->not->toBeNull();
+    expect($before['standard'])->not->toBeNull();
+
+    $page->click('[data-testid="select-plan-standard"]');
+
+    for ($i = 0; $i < 40; $i++) {
+        $moved = $page->script(
+            'document.querySelector(\'[data-testid="plan-selected-note-standard"]\') !== null'
+        );
+
+        if ($moved === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    $after = $page->script($measure);
+
+    // Starter: note 有 → 無、CTA 文言は不変 = 交絡なしの最も強い検査
+    expect($after['starter']['heading'])->toEqual($before['starter']['heading']);
+    expect($after['starter']['cta'])->toEqual($before['starter']['cta']);
+
+    // Standard: note 無 → 有 だが CTA 文言が「選択」→「選択中」に変わるため、
+    // CTA の height は不変条件にしない (headerBadges 由来か文言差か判別できないため)。
+    expect($after['standard']['heading'])->toEqual($before['standard']['heading']);
+    expect($after['standard']['cta']['top'])->toEqual($before['standard']['cta']['top']);
+
+    // note 自身は可視領域を持たない (sr-only が効いている)
+    expect($page->script(<<<'JS'
+        (() => {
+            const el = document.querySelector('[data-testid="plan-selected-note-standard"]');
+            const r = el.getBoundingClientRect();
+            return r.width <= 1 && r.height <= 1;
+        })()
+    JS))->toBeTrue();
+});
diff --git a/tests/js/lib/capture/cut-labels.test.ts b/tests/js/lib/capture/cut-labels.test.ts
new file mode 100644
index 0000000..ea9c87d
--- /dev/null
+++ b/tests/js/lib/capture/cut-labels.test.ts
@@ -0,0 +1,84 @@
+/**
+ * Tests for resources/js/lib/capture/cut-labels.ts
+ *
+ * 公開契約 (詳細設計 施策 0):
+ *   step は連番 (手順 1, 手順 2, ...)、point は直前 step の番号 + 枝番 (急所 N-M)。
+ *
+ * これは CutNavigator.svelte 内にあった $derived.by の**純粋な抽出**であり、
+ * 撮影パネルの見出し (F-1-03) とテイクプレビューの aria-label (F-1-02) が
+ * 同じ規則を共有するための唯一の導出元にする。
+ *
+ * したがって本テストの役割は「新しい仕様を決めること」ではなく
+ * **現行挙動を固定してリファクタであることを証明すること**である。
+ * 先頭が point のような端ケースも、良し悪しを問わず現行どおりに固定する。
+ */
+import { describe, expect, it } from "vitest";
+
+import { buildCutLabels } from "@/lib/capture/cut-labels";
+import type { CaptureCut } from "@/types/capture";
+
+/** ラベル導出に効くのは id / type だけなので、それ以外は既定値で埋める。 */
+function cut(id: number, type: "step" | "point"): CaptureCut {
+    return {
+        id,
+        type,
+        parent_cut_id: null,
+        scene: `scene-${id}`,
+        shot_type: "hiki",
+        shooting_point: null,
+        narration: "",
+        subtitle_primary: null,
+        subtitle_secondary: "",
+        adopted_take_id: null,
+        takes: [],
+    };
+}
+
+describe("buildCutLabels", () => {
+    it("step のみなら連番の手順ラベルになる", () => {
+        expect(buildCutLabels([cut(10, "step"), cut(11, "step"), cut(12, "step")])).toEqual({
+            10: "手順 1",
+            11: "手順 2",
+            12: "手順 3",
+        });
+    });
+
+    it("point は直前 step の番号 + 枝番になる", () => {
+        const labels = buildCutLabels([
+            cut(1, "step"),
+            cut(2, "point"),
+            cut(3, "point"),
+            cut(4, "step"),
+            cut(5, "point"),
+        ]);
+
+        expect(labels).toEqual({
+            1: "手順 1",
+            2: "急所 1-1",
+            3: "急所 1-2",
+            4: "手順 2",
+            5: "急所 2-1",
+        });
+    });
+
+    it("step をまたぐと枝番がリセットされる", () => {
+        const labels = buildCutLabels([
+            cut(1, "step"),
+            cut(2, "point"),
+            cut(3, "step"),
+            cut(4, "point"),
+        ]);
+
+        expect(labels[2]).toBe("急所 1-1");
+        expect(labels[4]).toBe("急所 2-1");
+    });
+
+    it("先頭が point (親 step 無し) でも現行どおり 急所 0-1 になる", () => {
+        // 仕様として良いかは問わない。抽出前の CutNavigator と同一挙動であることの固定。
+        expect(buildCutLabels([cut(9, "point")])).toEqual({ 9: "急所 0-1" });
+    });
+
+    it("空配列なら空オブジェクトを返す", () => {
+        expect(buildCutLabels([])).toEqual({});
+    });
+});
diff --git a/tests/js/lib/capture/panel-navigation.test.ts b/tests/js/lib/capture/panel-navigation.test.ts
new file mode 100644
index 0000000..eb80b97
--- /dev/null
+++ b/tests/js/lib/capture/panel-navigation.test.ts
@@ -0,0 +1,223 @@
+/**
+ * Tests for resources/js/lib/capture/panel-navigation.ts
+ *
+ * 公開契約 (詳細設計 施策 A / bug-hunt F-1-03):
+ *   1 カラム (縦積み) のときだけ、カット選択で撮影パネルへ「視点」と「フォーカス」を運ぶ。
+ *   2 カラムでは動かさない (デスクトップで勝手に画面が動くのは退行)。
+ *   captureActive (録画中 / getUserMedia grant 待ちを含む) の間も動かさない。
+ *
+ * ここでは **副作用ごと** 固定する。述語だけを切り出すと「抑止条件が実際に focus /
+ * scrollIntoView を止めているか」が page component の中でしか検証できず、回帰を防げない
+ * (design-review R1 の指摘)。
+ *
+ * 負のコントロール: captureActive=true / 横並び / 要素 null では focus も scrollIntoView も
+ * **1 度も呼ばれない**こと。
+ */
+import { beforeEach, describe, expect, it, vi } from "vitest";
+
+import {
+    isStackedLayout,
+    navigateBackToList,
+    navigateToPanelIfNeeded,
+    prefersReducedMotion,
+    scrollBehaviorFor,
+} from "@/lib/capture/panel-navigation";
+
+/** getBoundingClientRect だけを差し替えた最小の HTMLElement スタブ。 */
+function elementWithRect(rect: { top: number; bottom: number }): HTMLElement {
+    const el = document.createElement("div");
+    el.getBoundingClientRect = (): DOMRect =>
+        ({ top: rect.top, bottom: rect.bottom }) as DOMRect;
+    return el;
+}
+
+/** focus / scrollIntoView を spy した見出し要素と、呼び出し順の記録。 */
+function headingWithSpies(): {
+    el: HTMLElement;
+    focus: ReturnType<typeof vi.fn>;
+    scrollIntoView: ReturnType<typeof vi.fn>;
+    calls: string[];
+} {
+    const el = document.createElement("h2");
+    const calls: string[] = [];
+    const focus = vi.fn(() => calls.push("focus"));
+    const scrollIntoView = vi.fn(() => calls.push("scrollIntoView"));
+    el.focus = focus as unknown as HTMLElement["focus"];
+    el.scrollIntoView = scrollIntoView as unknown as HTMLElement["scrollIntoView"];
+    return { el, focus, scrollIntoView, calls };
+}
+
+const STACKED = { left: { top: 0, bottom: 400 }, right: { top: 400, bottom: 900 } };
+const SIDE_BY_SIDE = { left: { top: 0, bottom: 400 }, right: { top: 0, bottom: 400 } };
+
+describe("isStackedLayout", () => {
+    it("右 pane が左 pane の下にあれば縦積みと判定する", () => {
+        expect(
+            isStackedLayout(
+                { top: 0, bottom: 400 } as DOMRect,
+                { top: 400, bottom: 900 } as DOMRect,
+            ),
+        ).toBe(true);
+    });
+
+    it("左右が並んでいれば縦積みではない", () => {
+        expect(
+            isStackedLayout({ top: 0, bottom: 400 } as DOMRect, { top: 0, bottom: 400 } as DOMRect),
+        ).toBe(false);
+    });
+
+    it("許容差 4px の内側は縦積みとみなす (sub-pixel / border のズレ吸収)", () => {
+        // right.top = left.bottom - 4 → 許容差ちょうどなので縦積み
+        expect(
+            isStackedLayout(
+                { top: 0, bottom: 400 } as DOMRect,
+                { top: 396, bottom: 900 } as DOMRect,
+            ),
+        ).toBe(true);
+    });
+
+    it("許容差 4px を超えて重なっていれば縦積みではない", () => {
+        expect(
+            isStackedLayout(
+                { top: 0, bottom: 400 } as DOMRect,
+                { top: 395, bottom: 900 } as DOMRect,
+            ),
+        ).toBe(false);
+    });
+});
+
+describe("scrollBehaviorFor", () => {
+    it("reduced-motion を望むなら smooth を使わない", () => {
+        expect(scrollBehaviorFor(true)).toBe("auto");
+    });
+
+    it("そうでなければ smooth", () => {
+        expect(scrollBehaviorFor(false)).toBe("smooth");
+    });
+});
+
+describe("prefersReducedMotion", () => {
+    const original = window.matchMedia;
+
+    beforeEach(() => {
+        window.matchMedia = original;
+    });
+
+    it("matchMedia が無い環境では安全側 (true = アニメーションしない) に倒れる", () => {
+        // @ts-expect-error 非対応環境の再現
+        window.matchMedia = undefined;
+        expect(prefersReducedMotion()).toBe(true);
+    });
+
+    it("matchMedia の結果をそのまま返す", () => {
+        window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as unknown as typeof matchMedia;
+        expect(prefersReducedMotion()).toBe(true);
+
+        window.matchMedia = vi
+            .fn()
+            .mockReturnValue({ matches: false }) as unknown as typeof matchMedia;
+        expect(prefersReducedMotion()).toBe(false);
+    });
+});
+
+describe("navigateToPanelIfNeeded", () => {
+    it("縦積み かつ 非 captureActive なら focus → scrollIntoView の順で運ぶ", () => {
+        const heading = headingWithSpies();
+
+        const moved = navigateToPanelIfNeeded({
+            captureActive: false,
+            leftEl: elementWithRect(STACKED.left),
+            rightEl: elementWithRect(STACKED.right),
+            headingEl: heading.el,
+            reducedMotion: false,
+        });
+
+        expect(moved).toBe(true);
+        // focus() 自体が暗黙スクロールを起こすため preventScroll してから scrollIntoView する
+        expect(heading.focus).toHaveBeenCalledWith({ preventScroll: true });
+        expect(heading.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "start" });
+        // 順序も契約 (逆にすると二重移動になる)
+        expect(heading.calls).toEqual(["focus", "scrollIntoView"]);
+    });
+
+    it("reducedMotion なら behavior が auto になる", () => {
+        const heading = headingWithSpies();
+
+        navigateToPanelIfNeeded({
+            captureActive: false,
+            leftEl: elementWithRect(STACKED.left),
+            rightEl: elementWithRect(STACKED.right),
+            headingEl: heading.el,
+            reducedMotion: true,
+        });
+
+        expect(heading.scrollIntoView).toHaveBeenCalledWith({ behavior: "auto", block: "start" });
+    });
+
+    it("captureActive の間は視点もフォーカスも奪わない (録画中 / grant 待ち)", () => {
+        const heading = headingWithSpies();
+
+        const moved = navigateToPanelIfNeeded({
+            captureActive: true,
+            leftEl: elementWithRect(STACKED.left),
+            rightEl: elementWithRect(STACKED.right),
+            headingEl: heading.el,
+            reducedMotion: false,
+        });
+
+        expect(moved).toBe(false);
+        expect(heading.focus).not.toHaveBeenCalled();
+        expect(heading.scrollIntoView).not.toHaveBeenCalled();
+    });
+
+    it("2 カラム (横並び) では動かさない", () => {
+        const heading = headingWithSpies();
+
+        const moved = navigateToPanelIfNeeded({
+            captureActive: false,
+            leftEl: elementWithRect(SIDE_BY_SIDE.left),
+            rightEl: elementWithRect(SIDE_BY_SIDE.right),
+            headingEl: heading.el,
+            reducedMotion: false,
+        });
+
+        expect(moved).toBe(false);
+        expect(heading.focus).not.toHaveBeenCalled();
+        expect(heading.scrollIntoView).not.toHaveBeenCalled();
+    });
+
+    it.each([
+        ["leftEl", { leftEl: null }],
+        ["rightEl", { rightEl: null }],
+        ["headingEl", { headingEl: null }],
+    ])("%s が null なら何もしない", (_label, override) => {
+        const heading = headingWithSpies();
+
+        const moved = navigateToPanelIfNeeded({
+            captureActive: false,
+            leftEl: elementWithRect(STACKED.left),
+            rightEl: elementWithRect(STACKED.right),
+            headingEl: heading.el,
+            reducedMotion: false,
+            ...override,
+        });
+
+        expect(moved).toBe(false);
+        expect(heading.focus).not.toHaveBeenCalled();
+        expect(heading.scrollIntoView).not.toHaveBeenCalled();
+    });
+});
+
+describe("navigateBackToList", () => {
+    it("focus → scrollIntoView の順で一覧側へ戻す", () => {
+        const heading = headingWithSpies();
+
+        expect(navigateBackToList(heading.el, false)).toBe(true);
+        expect(heading.focus).toHaveBeenCalledWith({ preventScroll: true });
+        expect(heading.calls).toEqual(["focus", "scrollIntoView"]);
+    });
+
+    it("要素が無ければ何もしない", () => {
+        expect(navigateBackToList(null, false)).toBe(false);
+    });
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index cc3a2f1..adf1a2f 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -11,10 +11,23 @@ import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/captu
  * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
  */
 
-const { routerReloadMock, enqueueMock, autoDownloadRunMock } = vi.hoisted(() => ({
-    routerReloadMock: vi.fn(),
-    enqueueMock: vi.fn(),
-    autoDownloadRunMock: vi.fn(),
+const { routerReloadMock, enqueueMock, autoDownloadRunMock, navigateToPanelMock } = vi.hoisted(
+    () => ({
+        routerReloadMock: vi.fn(),
+        enqueueMock: vi.fn(),
+        autoDownloadRunMock: vi.fn(),
+        navigateToPanelMock: vi.fn(),
+    }),
+);
+
+// 撮影パネルへのナビゲーション (F-1-03) は panel-navigation.ts が副作用ごと担い、
+// その抑止契約は panel-navigation.test.ts が固定する。ここで固定するのは
+// **ページ配線** = Show が navigateToPanelIfNeeded に何を渡しているか、だけ。
+// jsdom の矩形 / focus / scrollIntoView 実装差に依存させないため spy に差し替える
+// (実装の他の export は本物を残す)。
+vi.mock("@/lib/capture/panel-navigation", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/capture/panel-navigation")>()),
+    navigateToPanelIfNeeded: navigateToPanelMock,
 }));
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
@@ -139,6 +152,8 @@ beforeEach(() => {
     // 既定: 対象なし (changed=false)。個別ケースで override する
     autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });
     getUserMediaMock.mockReset();
+    navigateToPanelMock.mockReset();
+    navigateToPanelMock.mockReturnValue(false);
 });
 
 afterEach(() => {
@@ -336,6 +351,48 @@ describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
     });
 });
 
+/*
+ * 受入条件 4 (bug-hunt F-1-03) のうち **ページ配線** を固定する。
+ *
+ * 抑止そのもの (captureActive=true で focus / scrollIntoView が呼ばれない) は
+ * panel-navigation.test.ts が担う。ここは「Show が captureActive を正しく渡しているか」
+ * だけを見る。両方揃って初めて「録画中は視点とフォーカスを奪わない」が守られる
+ * (helper だけでは、将来 Show が誤って false を渡しても緑のままになる)。
+ */
+describe("Capture/Show 撮影パネルへのナビゲーション配線 (F-1-03 受入条件 4)", () => {
+    it("通常時は captureActive=false を渡す", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: baseProps });
+
+        await selectCut();
+
+        await vi.waitFor(() => {
+            expect(navigateToPanelMock).toHaveBeenCalled();
+        });
+        expect(navigateToPanelMock.mock.calls.at(-1)?.[0]).toMatchObject({ captureActive: false });
+    });
+
+    it("録画開始 (getUserMedia grant 待ち) の間は captureActive=true を渡す", async () => {
+        stubCameraSupported(true);
+        // grant 待ちを再現する: 解決しない Promise を返すと starting=true のまま留まる。
+        // CameraRecorder の公開 active は `starting || resuming || phase !== "idle"` であり、
+        // grant 窓も active に含める設計なので、ここで captureActive=true になるはず。
+        getUserMediaMock.mockReturnValue(new Promise<MediaStream>(() => {}));
+
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        navigateToPanelMock.mockClear();
+        await selectCut(); // 録画中に同じカットを選び直す
+
+        await vi.waitFor(() => {
+            expect(navigateToPanelMock).toHaveBeenCalled();
+        });
+        expect(navigateToPanelMock.mock.calls.at(-1)?.[0]).toMatchObject({ captureActive: true });
+    });
+});
+
 describe("Capture/Show レイアウト overflow ガード (H13/F-1-3)", () => {
     it("グリッドは mobile 単一列 (grid-cols-1)、左右 pane が min-w-0 を持つ", () => {
         stubCameraSupported(false);

```

---

## テスト結果

- `composer test` (Pest Feature/Unit/Architecture): 4102 tests, 4100 passed, 2 skipped, 17611 assertions
- `composer test:browser` chromium: 22 tests, 19 passed, 3 skipped, 94 assertions
- `composer test:browser` webkit: 22 tests, 19 passed, 3 skipped, 94 assertions
- `pnpm test` (vitest): 126 files, 1213 tests passed
- `composer phpstan`: No errors (838 files) / `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed (106 tests)

**fail-first の確認**: 実装 (resources/js) を `git stash` した状態で新規 Browser テストを走らせ、
8 件中 7 件が落ちることを確認済み (残り 1 件は「デスクトップでは動かない」= 不在を主張する
negative test なので実装前でも緑になるのが正しい)。vitest 側 (cut-labels / panel-navigation /
CaptureShow 配線) も実装前に fail を確認してから実装した。

**設計からの逸脱 (自己申告)**:
1. component test のファイル名を `tests/js/pages/Capture/Show.test.ts` ではなく
   既存の命名規約に合わせて `tests/js/pages/CaptureShow.test.ts` (既存ファイルへの追記) にした。
2. 受入条件 7/8 (video の aria-label) は独立した Browser テストファイルではなく
   `CaptureCutNavigationTest.php` に同居させた (設計でも「施策 A のテストと同居可」としていた)。
3. Browser テストで `visit(...)->on()->mobile()` の戻り値 (On) は `__call` のたびに
   **新しいページを生成する**ため、`->assertPathIs(...)` で 1 度だけ materialize してから
   同じ Webpage を使い回す形にした (設計時には気づいていなかった API 挙動)。
4. `script()` は locator と違って自動待機しないため、hydration 前に DOM を測ると null になる。
   計測前に明示的な polling 待機を入れた。

---

## design system 参照 (DESIGN.md 冒頭抜粋)

---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#B91C1C"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### Typography ramp utility

各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
そのまま class として適用する。**raw の `text-sm` / `font-bold` 等は禁止**(ds-purity が検出)。

- **text-display**: 48px / 500 / lh 1.2 / ls 0.02em — tailwind: `text-display`
- **text-h1**: 32px / 500 / lh 1.3 / ls 0.02em — tailwind: `text-h1`
- **text-h2**: 24px / 500 / lh 1.4 — tailwind: `text-h2`
- **text-h3**: 18px / 500 / lh 1.5 — tailwind: `text-h3`
- **text-body**: 16px / 400 / lh 1.7 — tailwind: `text-body`
- **text-caption**: 12px / 400 / lh 1.5 — tailwind: `text-caption`

役割マッピング: 本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`、
page タイトル → `text-h1`/`text-h2`、section/card 見出し → `text-h3`。
強調は `font-medium`(500)を上限とし、足りなければ weight を上げず ramp 昇格+余白+
色階層(text vs text-secondary)でコントラストを作る。

## Layout

8px ベースのスケール。要素間は `md (16px)` を基本に、セクション間は `xl (40px)`。
コンテナは最大幅 1080px を目安に、画面の左右に 32px の余白を確保する。

## Elevation & Depth

**`box-shadow` は使わない。** Neutral(背景)と Surface(カード)の明度差、および 1px の
ボーダーで階層を表現する。ホバー時も影を出さず、ボーダー色や文字色の変化で反応を示す。
グラデーション・scale 効果も使わない。

## Shapes

角丸 ramp は **`rounded-sm`(4px)/ `rounded-md`(6px)/ `rounded-lg`(8px)の 3 段のみ**。
DOM 役割で選ぶ(上から優先): カード・モーダル=`lg` / 中間 box(パネル・`<pre>`)=`md` /
ボタン・入力・バッジ等の小コントロール=`sm`。
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。
完全円(`rounded-full`)はアバター/status dot/トグル等の**真に円形な UI に限る** ramp 外の例外で、
file-scoped allowlist で個別管理する。

## Compone
