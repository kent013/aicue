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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- Browser lane は pest-plugin-browser (Chromium + WebKit の 2 レーン契約)
- vitest (tests/js/) が frontend の unit レーン

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（design token 経由か、hex 直書きを増やしていないか）
11. Atomic Design準拠（atoms/molecules/organisms/features/templates/pages の単方向 import、アイコンは Lucide）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
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
                <!-- 1 カラムのときだけ出す。2 カラムでは一覧が常に見えているので不要。 -->
                <TextLink
                    href="#"
                    onclick={backToCutList}
                    testId="back-to-cut-list"
                >
                    カット一覧へ戻る
                </TextLink>
            {/if}
        </div>
        ...
```

`TextLink` が `onclick` を受けない場合は `Button variant="tertiary"` 等の既存 atom を使う
（**新規 atom は作らない**。実装時に `TextLink.types.ts` を確認して選ぶ）。

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
    if (captureActive) return;
    // DOM 反映後に測る (Svelte 5 は tick() を await できる)
    void tick().then(() => {
        if (leftPaneEl === null || rightPaneEl === null || recordingHeadingEl === null) return;
        stacked = isStackedLayout(leftPaneEl.getBoundingClientRect(), rightPaneEl.getBoundingClientRect());
        if (!stacked) return;
        recordingHeadingEl.focus({ preventScroll: true });
        recordingHeadingEl.scrollIntoView({
            behavior: scrollBehaviorFor(prefersReducedMotion()),
            block: "start",
        });
    });
}

/** 「カット一覧へ戻る」: 視点とフォーカスの両方を一覧側へ返す (H2 を自分で作らない)。 */
function backToCutList(event: Event): void {
    event.preventDefault();
    if (cutListHeadingEl === null) return;
    cutListHeadingEl.focus({ preventScroll: true });
    cutListHeadingEl.scrollIntoView({
        behavior: scrollBehaviorFor(prefersReducedMotion()),
        block: "start",
    });
}
```

左 pane の `h2`（「シナリオ (タップして撮影)」）にも `bind:this={cutListHeadingEl}` と
`tabindex="-1"` を付ける（戻り先のフォーカス着地点）。

`stacked` は選択時だけでなく初期表示・resize でも更新する必要がある（戻るリンクの出し分けに使うため）。
`$effect` で `ResizeObserver` を張り、`leftPaneEl` / `rightPaneEl` の矩形変化で再判定する。
**ResizeObserver は破棄時に必ず `disconnect()` する。**

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] 新規 `tests/js/lib/capture/panel-navigation.test.ts`（vitest）
  - `isStackedLayout`: 縦積み（`right.top >= left.bottom`）で true、横並びで false、
    許容差 4px の境界（`left.bottom - 4` / `left.bottom - 5`）
  - `scrollBehaviorFor`: `true → "auto"` / `false → "smooth"`（**受入条件 5**）
  - `prefersReducedMotion`: `window` 無し / `matchMedia` 無しで `true` に倒れる
- [ ] 新規 `tests/Browser/CaptureCutNavigationTest.php`（Chromium + WebKit の 2 レーン）
  - **受入条件 1**: `->on()->mobile()` で `capture-recording-heading` の
    `getBoundingClientRect()` が矩形全体として viewport 内（`top >= 0 && bottom <= innerHeight`）
  - **受入条件 2**: 同操作後に `document.activeElement` が `capture-recording-heading`
  - **受入条件 3**: `->on()->desktop()` でカット選択しても `window.scrollY` が不変、
    かつ `document.activeElement` が変化しない
  - **受入条件 6**: `back-to-cut-list` クリックで `cut-navigator` が viewport 内に入り、
    `document.activeElement` が左 pane の見出しになる
  - **受入条件 4**（録画中の抑止）: 実カメラが無い CI で `captureActive=true` を作れないため、
    **Browser では直接検証しない**。代わりに `handleSelectCut` の `captureActive` 早期 return を
    vitest で固定する（下記）。**この分割は受入条件を落とすのではなく、検証手段を移す**。
- [ ] `handleSelectCut` の抑止ロジックは page component の中に埋めず、
      `panel-navigation.ts` に `shouldNavigateToPanel(captureActive: boolean, stacked: boolean): boolean`
      として括り出し、vitest で `captureActive=true → false` を固定する（**受入条件 4**）

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
- テストファイル: `tests/Browser/` に a11y 名の検査を追加（施策 A のテストと同居可）

### 現行コード / 変更後コード

```svelte
<!-- RenderPanel.svelte L369-376 -->
<!-- 現行 -->
<video controls preload="metadata" class="w-full rounded-md bg-neutral"
       src={...} data-testid="preview-video"></video>

<!-- 変更後: 完成動画/プレビューのどちらであるかを名前に含める -->
<video
    controls
    preload="metadata"
    class="w-full rounded-md bg-neutral"
    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackId}/playback`}
    aria-label={previewOnly ? "プレビュー動画" : "完成動画"}
    data-testid="preview-video"
></video>
```

> `previewOnly` に相当する既存 state 名は実装時に `RenderPanel.svelte` を読んで確定する
> （`playbackId` が preview 由来か render 由来かを既に区別している state があるはず。
> 無ければ**新しい state を足さず**、常に「完成動画のプレビュー」という 1 つの名前にする —
> 受入条件 7 は「完成動画 / プレビューであることが分かる語を含む」なので満たせる）。

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

`<!-- svelte-ignore a11y_media_has_caption -->` は**両方とも残す**（caption track の話であり、
アクセシブルネームとは別軸。字幕焼き込み済みという既存判断を覆さない）。

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] **受入条件 7**: Browser で `preview-video` の `aria-label` が「完成動画」または「プレビュー」を含む
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
            <!-- 青枠 (isHighlighted) が視覚で伝えている「選択中」を、支援技術にも同じだけ伝える。
                 role を偽らない (排他選択なので aria-pressed は不可、radiogroup 化は
                 キーボード操作モデルの作り替えになる)。文言にプラン名を含めるのは、
                 PricingPlanCard が semantic group ではなく、テキスト単位で移動したときに
                 対象が読み上げ順に依存しないようにするため。 -->
            <span class="sr-only" data-testid={`plan-selected-note-${plan.code}`}>
                {plan.name} プランが選択されています
            </span>
        {/if}
    {/snippet}
    {#snippet footerCta()} ... {/snippet}
</PricingPlanCard>
```

**表示文言（「選択」/「選択中」ボタンラベル）は `chosenPlanCode` 基準のまま動かさない。**
ユーザーが押していないものを「選択中」と表示すると別の誤認を作るため。

### レイアウト不変性の担保（受入条件 11）

`headerBadges` を渡すと `PricingPlanCard` 側で
`<div class="ml-auto flex max-w-full min-w-0 flex-wrap justify-end gap-2">` ラッパが出現する。
中身が `sr-only`（`position: absolute` 相当）のみなので、このラッパの実寸は 0 になるはずだが、
**`gap-2` と `flex` の相互作用で見出し行の高さが動かないことを実測で確認する**。

- (a) 選択カードと未選択カードで、`plan-card-{code}` 内の見出し・価格・選択ボタンの
  `getBoundingClientRect()` の `top` / `height` が一致する
- (b) `headerBadges` ラッパの `getBoundingClientRect()` が `width === 0 && height === 0`
- (c) `sr-only` 文言が可視でない（`plan-selected-note-*` の矩形が 1px 四方以下、
  かつ `clip` されている）

### PHPStan 適合チェック

- [x] PHP の変更なし（該当なし）

### テスト計画

- [ ] **受入条件 9**: `?plan=starter` で開き、`plan-selected-note-starter` が存在して
      テキストに「Starter」を含む。他プランの `plan-selected-note-*` は存在しない
- [ ] **受入条件 10**: 別プランの「選択」を押すと、旧 note が消え、新プラン名を含む note が現れる
- [ ] **受入条件 11**: 上記 (a)(b)(c) を `->script()` の `getBoundingClientRect()` で検査
- [ ] `Billing` / `Guest/Pricing` には**新規回帰テストを課さない**（コード無変更のため）

### リスク

- `sr-only` ユーティリティが Tailwind の標準クラスとして効いていること
  （既存先例: `atoms/Spinner.svelte` L43 / `CameraRecorder.svelte` L521 /
  `AppLayout.svelte` L231 / `Contact/Index.svelte` L168）。新規 CSS は足さない。
- プラン名に「プラン」が既に含まれる場合（例: 「Personalプラン組織」ではなくプラン名は
  `Personal` 想定）に「Personal プラン プランが選択されています」と重複しないか、
  実データ（`PlanSeeder`）を見て文言を確定する。

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
5. `pnpm build` → `composer test` / `composer test:browser` / `composer phpstan` /
   `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` を全緑にする

> **Browser レーンは実ブラウザが `public/build` を読む**ため、Svelte を変更したら
> `pnpm build` を先に走らせること（`tests/Browser/SmokeTest.php` 冒頭コメント / `docs/testing-browser.md`）。

## 受入条件と検証手段の対応表（概念設計 11 条件の全件マップ）

| # | 受入条件 | 検証 |
|---|---|---|
| 1 | 撮影パネル見出しが矩形全体として viewport 内 | Browser `CaptureCutNavigationTest` |
| 2 | フォーカスが撮影パネル見出しへ移る | Browser 同上 |
| 3 | 2 カラムではスクロールもフォーカスも動かない | Browser 同上（`->on()->desktop()`） |
| 4 | `captureActive` 中は動かない | **vitest** `panel-navigation.test.ts`（CI に実カメラが無く Browser で `captureActive` を作れないため、検証手段のみ移す） |
| 5 | reduced-motion で smooth を使わない | vitest `scrollBehaviorFor` |
| 6 | 戻るで視点とフォーカスが一覧側へ | Browser `CaptureCutNavigationTest` |
| 7 | `preview-video` の名前が「完成動画/プレビュー」を含む | Browser |
| 8 | `take-preview-video` の名前がカットラベルを含む | Browser |
| 9 | `?plan=` で当該プラン名を含む選択テキストが 1 つだけ | Browser `OnboardingPlanSelectionA11yTest` |
| 10 | 選び直しで note が移動する | Browser 同上 |
| 11 | Checkout のレイアウトが不変 (a)(b)(c) | Browser 同上（`getBoundingClientRect`） |


---

## 関連する現行コード

### resources/js/pages/Capture/Show.svelte (L34-60, L176-244)
```svelte
    let { project, manual }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let selectedCutId = $state<number | null>(null);
    const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
    // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)
    const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
    let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
    const showRecorder = $derived(canRecord && cameraUnavailableReason === null);
    // 撮影 active (recording|stopping) と recorder 参照 (preview の資源競合制御。T050 / S4)
    let captureActive = $state(false);
    let recorderRef = $state<CameraRecorderType | null>(null);
    // 実行時フォールバックの説明文 (reason で出し分け。静的 feature-detect 由来は
    // CaptureFileFallback 既存の説明文だけで足りるため notice なし)
    const fallbackNotice = $derived.by(() => {
        if (cameraUnavailableReason === null) return null;
        if (cameraUnavailableReason === "permission_denied") {
            return "カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザまたは端末・組織のカメラ設定を確認して再読み込みしてください。";
        }
        return "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。";
    });

    /* ---- アップロードキュー ---- */
    const store: PendingStore = createIdbPendingStore();
    const queue = new UploadQueue({ store });

...
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
            <h2 class="border-b border-border px-3 py-2 text-caption text-text-secondary">
                シナリオ (タップして撮影)
            </h2>
            <CutNavigator
                cuts={manual.cuts}
                {selectedCutId}
                onSelect={(cutId) => (selectedCutId = cutId)}
            />
        </section>

        <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">
                    左のシナリオからカットを選ぶと撮影パネルが開きます。
                </p>
            {:else}
                <div class="rounded-md border border-border bg-surface p-3">
                    <p class="text-caption text-text-secondary">ナレーション</p>
                    <p class="mt-1 text-body">{selectedCut.narration}</p>
                    {#if selectedCut.shooting_point}
                        <p class="mt-2 text-caption text-text-secondary">
                            撮影ポイント: {selectedCut.shooting_point}
                        </p>
                    {/if}
                </div>

                {#if showRecorder}
                    <CameraRecorder
                        bind:this={recorderRef}
                        onCaptured={(blob, mimeType, durationMs) =>
                            handleCaptured(blob, mimeType, durationMs)}
                        onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
                        subtitlePrimary={selectedCut.subtitle_primary}
                        subtitleSecondary={selectedCut.subtitle_secondary}
                        onCaptureActiveChange={(active) => (captureActive = active)}
                    />
                {:else}
                    {#if fallbackNotice !== null}
                        <p
                            class="text-caption text-text-secondary"
                            role="status"
                            data-testid="camera-fallback-notice"
                        >
                            {fallbackNotice}
                        </p>
                    {/if}
                    <CaptureFileFallback
                        onCaptured={(file) => handleCaptured(file, file.type, null)}
                    />
                {/if}

                <TakeStrip
                    projectId={project.id}
                    manualId={manual.id}
                    cut={selectedCut}
                    onChanged={reloadManual}
                    {captureActive}
                    onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
                    onCameraResume={() => void recorderRef?.resumeAfterPreview()}
                />
            {/if}
        </section>
        </div>
    </PageContainer>
</AppLayout>

```

### resources/js/components/features/capture/CutNavigator.svelte (L16-35)
```svelte
    let { cuts, selectedCutId, onSelect }: Props = $props();

    /** 手順番号ラベル (step は連番、point は親 step の番号 + 枝番) */
    const labels = $derived.by(() => {
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
    });
</script>

```

### resources/js/components/features/capture/CameraRecorder.svelte (active の定義 L38-44 / L115-125 / L208-215 / L470-486)
```svelte
     * 撮影 active の phase マシン (T050 / S4): idle / recording / paused / stopping。
     * 外部へ公開する排他状態 active は **starting || resuming || phase !== "idle"**。
     * getUserMedia grant 待ちの 2 窓 (録画開始 = starting / preview 復帰 = resuming) も active に
     * 含めることで、取得中でも親の captureActive が true になり preview が開けない
     * (preview と MediaRecorder の同居・stream 二重取得を根本から防ぐ。Codex R2/R3-S4)。
     * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件が一致する。
     *

...
    let flipping = false; // flip 再入ガード

    // 公開 active (starting || resuming || phase !== "idle") の変化時のみ 1 回通知する。
    // starting / resuming / phase を変えた箇所は必ず本関数を呼ぶ (通知の一元管理)。
    function syncActive(): void {
        const active = starting || resuming || phase !== "idle";
        if (active !== lastActive) {
            lastActive = active;
            onCaptureActiveChange?.(active);
        }
    }

...

    async function startRecording(): Promise<void> {
        // 再入防止 (アーリーリターン。規約: disabled 禁止)。preview 復帰の取得中 (resuming) も拒否
        // し getUserMedia 二重取得を防ぐ。
        if (starting || resuming || phase !== "idle") return;
        starting = true;
        syncActive(); // 開始押下時点で active=true (grant 窓でも preview を開けない)
        try {

...
    export function resumeAfterPreview(): Promise<void> {
        if (resuming) return resumePromise ?? Promise.resolve(); // in-flight 共有
        if (!wasActiveBeforePreview || starting || phase !== "idle") return Promise.resolve();
        resuming = true;
        syncActive(); // 復帰取得中も active=true (grant 窓で preview 再オープン・録画開始を抑止)
        // 取得成功後にのみ wasActiveBeforePreview を false 化 (失敗時は true のまま=再試行可能)
        resumePromise = acquirePreviewStream()
            .then((ok) => {
                if (ok) wasActiveBeforePreview = false;
            })
            .finally(() => {
                resuming = false;
                resumePromise = null;
                syncActive(); // 取得完了で active=false へ戻す (phase は idle のまま)
            });
        return resumePromise;
    }

```

### resources/js/components/features/manual/RenderPanel.svelte (L366-378)
```svelte
                </div>
            {/if}
            {#if playbackId !== null && !previewInFlight}
                <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
                <video
                    controls
                    preload="metadata"
                    class="w-full rounded-md bg-neutral"
                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackId}/playback`}
                    data-testid="preview-video"
                ></video>
            {/if}
        </div>

```

### resources/js/components/features/capture/TakePreviewDialog.svelte (L13-33, L74-90)
```svelte
    interface Props {
        open: boolean; // bindable
        take: CaptureTake | null; // 再生対象 (null で閉)
        cut: CaptureCut; // 字幕 (subtitle_primary/secondary) の供給元
        playbackUrl: string | null; // takeUrl(take, "/playback")。親が組み立て
        adopting: boolean; // 採用 XHR 中
        error: string | null; // 採用失敗メッセージ (親の run() error を流用)
        onAdopt: () => void; // 親の adopt() を呼ぶ
        onClose: () => void; // 親: dialog close + 録画復帰
    }

    let {
        open = $bindable(false),
        take,
        cut,
        playbackUrl,
        adopting,
        error,
        onAdopt,
        onClose,
    }: Props = $props();

...
        <div class="relative w-full overflow-hidden rounded-md bg-text/5">
            {#if open && take !== null}
                {#key take.id}
                    <!-- svelte-ignore a11y_media_has_caption -->
                    <video
                        bind:this={video}
                        controls
                        playsinline
                        src={playbackUrl ?? undefined}
                        class="w-full"
                        data-testid="take-preview-video"
                    ></video>
                {/key}
            {/if}

            {#if subtitlesOn}
                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3">

```

### resources/js/components/features/capture/TakeStrip.svelte (Props L16-36)
```svelte
    interface Props {
        projectId: number;
        manualId: number;
        cut: CaptureCut;
        onChanged: () => void;
        /** 撮影 active (recording|stopping) なら preview を開かずエラー表示 (資源競合防止) */
        captureActive?: boolean;
        /** preview を開く直前に撮影待機中の live stream を解放させる (親: CameraRecorder) */
        onRequestCameraRelease?: () => void;
        /** preview close で撮影待機を復帰させる (親: CameraRecorder) */
        onCameraResume?: () => void;
    }

    let {
        projectId,
        manualId,
        cut,
        onChanged,
        captureActive = false,
        onRequestCameraRelease,
        onCameraResume,

```

### resources/js/components/molecules/PricingPlanCard.svelte (props + header 描画部 L14-60)
```svelte

    interface Props {
        name: string;
        /** null = 基本料金なし = 無料表示 (0 も防御的に同一表示) */
        priceAmount: number | null;
        /** 価格サフィックス (既定 '／月') */
        priceSuffix?: string;
        /** 価格の直上に小さく載せる説明 (例: '基本料金')。表示価格が総額と誤解されるのを防ぐ。 */
        priceCaption?: string;
        /** 現在のプランなど強調枠 (border-primary) */
        isHighlighted?: boolean;
        features: PricingFeature[];
        testId?: string;
        /** header 右上専用 (現在のプラン等の Badge)。未指定時の出力は不変 */
        headerBadges?: Snippet;
        /** card footer 下部 CTA 専用 */
        footerCta: Snippet;
    }

    let {
        name,
        priceAmount,
        priceSuffix = "／月",
        priceCaption,
        isHighlighted = false,
        features,
        testId,
        headerBadges,
        footerCta,
    }: Props = $props();

    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
    const borderClass = $derived(isHighlighted ? "border-primary" : "border-border");
    const isFree = $derived(priceAmount === null || priceAmount === 0);
</script>

<div class="flex flex-col rounded-lg border bg-surface p-5 {borderClass}" data-testid={testId}>
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="shrink-0 text-h3 text-text">{name}</h3>
        {#if headerBadges}
            <div class="ml-auto flex max-w-full min-w-0 flex-wrap justify-end gap-2">
                {@render headerBadges()}
            </div>
        {/if}
    </div>
    {#if priceCaption !== undefined && !isFree}
        <!-- 表示価格が総額と誤解されるのを防ぐ (例: 基本料金)。 -->

```

### resources/js/pages/Onboarding/Checkout.svelte (L54-62, L108-116, L190-200)
```svelte
    };

    let chosenPlanCode = $state<string | null>(null);
    // 強調するカード = ユーザーが選んだもの。未選択なら props から導出した既定。
    // $state に初期値を焼くと props 変更 (Inertia partial reload) に追随せず、
    // $derived を再代入すると runes の再評価と競合するため、override を $state・表示値を $derived で持つ。
    const selectedPlanCode = $derived(chosenPlanCode ?? computeInitialPlan(pageData));
    let submitting = $state(false);
    let declarationChecked = $state(false);

...
    };

    const choosePlan = (plan: PlanShape): void => {
        chosenPlanCode = plan.code;
    };

    // Personal (無料) の有効化。declaration 未チェックでも送信し、サーバの文言を表示する
    // (押下時にエラー表示 = 禁止事項 #8)。
    const submitPersonalFree = (): void => {

...
            <div class="flex flex-col gap-6" data-testid="onboarding-checkout">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="plan-grid">
                    {#each pageData.plans as plan (plan.code)}
                        <PricingPlanCard
                            name={plan.name}
                            priceAmount={isPersonal(plan) ? 0 : plan.currentBaseAmount}
                            features={buildFeatures(plan)}
                            isHighlighted={selectedPlanCode === plan.code}
                            testId={`plan-card-${plan.code}`}
                        >
                            {#snippet footerCta()}

```

### tests/Browser/SmokeTest.php (Browser レーンの書き方の見本)
```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Browser スモークテスト (pest-plugin-browser / Playwright)
|--------------------------------------------------------------------------
|
| Browser lane の共通基盤 (in-process サーバ + 実ブラウザ + RefreshDatabase +
| actingAs) が機能することの最小検証。実行は `composer test:browser`
| (scripts/run-browser-test.sh 経由。前提・規約は docs/testing-browser.md)。
|
| 実ブラウザは public/build のビルド済アセットを読むため、UI 変更後は
| `pnpm build` を先に実行すること。
|
*/

test('ゲストがトップページを JS エラーなしで表示できる', function (): void {
    $page = visit('/');

    $page->assertPathIs('/')
        ->assertSee(config()->string('app.name'))
        ->assertNoJavaScriptErrors();
});

test('ゲストは /dashboard に到達できず /login へリダイレクトされる', function (): void {
    visit('/dashboard')->assertPathIs('/login');
});

test('actingAs が実ブラウザの session で効き dashboard を表示できる', function (): void {
    // 組織 provisioning 済みの owner を使う (dashboard は current org 前提の共有 props を読む)
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner);

    visit('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

```

