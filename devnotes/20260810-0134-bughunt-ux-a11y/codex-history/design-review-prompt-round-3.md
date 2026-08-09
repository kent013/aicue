Round 2 の Warning 6 件をすべて対応した (反論なし・全件受け入れ)。

特に、fixture の指摘は**設計が実際に間違っていた**ことが確認できた:
`createOrganizationWithOwner()` の既定 `grandfatherFreePlan=true` → `free_plan_code` が立つ →
`BillingAccess` が `ActiveFreePlan` と判定 → `grantsAccess()` が true → `OnboardingController` が
`/billing` へリダイレクト、で **Checkout に到達すらできなかった**。加えて `?plan=` は
canonical URL へ 303 されるため、URL に query が残る前提も誤りだった。両方直した。

判定を求める点:
- 受入条件 4 の 2 段構え (helper 契約 + ページ配線 component test)、および
  「(ii) が成立しないなら保証範囲を下方修正して明記する」という書き方で足りるか。
- 受入条件 1 の前提 assert (`rightPane.top >= innerHeight`) が、テストが必ず fail を経由することの
  保証になっているか。
- 受入条件 11 の同一カード前後比較が、レイアウト不変性の検査として成立しているか。
- 「初期候補として表示されています」が誤認の少ない表現になっているか。

# 対応マトリクス

# 対応マトリクス: design-review Round 2

Critical はゼロ。Warning 6 件はすべて対応した（反論なし・全件受け入れ）。

## [Warning/A] `navigateToPanelIfNeeded` の単体テストだけでは受入条件 4 のページ配線を保証できない
- 判断: **対応する**
- 根拠: 指摘のとおり。将来 `Show.svelte` が誤って `captureActive: false` を渡しても helper の
  テストは緑のままで、回帰を検出できない。helper を切り出したこと自体は正しいが、
  「配線が正しい」ことは別に固定しないと受入条件 4 を満たしたとは言えない。
- 対応内容: 受入条件 4 を **2 段構え**にした ——
  (i) helper の抑止契約（`panel-navigation.test.ts`）、
  (ii) **ページ配線**（`tests/js/pages/Capture/Show.test.ts` の component test で
  `CameraRecorder` の `onCaptureActiveChange(true)` を発火させてからカット行をクリックし、
  `focus` / `scrollIntoView` が呼ばれないことを固定）。
  さらに **(ii) が技術的に成立しない場合は「helper の抑止契約のみ検証（ページ配線は未固定）」と
  保証範囲を下方修正して実装レポートに明記する**という逃げ道の書き方まで指定した
  （「完全に検証した」と書かないため）。受入条件マップにも反映。

## [Warning/A] 「cuts 14 件以上」はテストが失敗経路を通る保証にならない
- 判断: **対応する**
- 根拠: 指摘のとおり。行高・折返し・mobile 実寸が変われば 14 件でも撮影パネルが
  初期 viewport に収まりうる。そうなるとテストは実装前から緑で、何も証明しない。
- 対応内容: **件数を条件から外した**。Browser テストが**クリック前に前提そのものを assert** する形に変更:
  `capture-right-pane` の `getBoundingClientRect().top >= window.innerHeight`。
  件数は 14 件から始めるが「viewport 外にするための手段」と位置づけ、
  この前提 assert が落ちたらテストデータを増やす、と明記した。

## [Warning/B] コメントと代入キー名だけでは `playbackJobId` の preview 限定を認定できない
- 判断: **対応する**
- 根拠: 妥当。コメントは不変条件の根拠にならない。実 query 条件を示すべき。
- 対応内容: `VideoManualController.php` L142-148 の**実 query を全文引用**した:
  `->where('kind', RenderKind::Preview->value)` / `->where('status', JobStatus::Succeeded->value)` /
  `->whereNotNull('output_path')` / `->latest('id')` / `->value('id')`。
  `kind` の enum 比較で render job が構造的に混ざらないことを示した。
  実行中の更新側（`RenderPanel.svelte` L118-131 で render 分岐が `playbackId` を触らないこと）も
  行番号付きで明示した。これで固定文言「プレビュー動画」の根拠が実コードのみになった。

## [Warning/C] 「初期選択されています」は依然として「選択済み」を意味し CTA と食い違う
- 判断: **対応する**
- 根拠: 指摘のとおり。「初期選択されて**います**」は完了相であり、
  CTA の「選択」（= これから操作が必要）と意味が一致しない。
  基準を分離しても、語が選択済みを含意していたら誤認は消えない。
- 対応内容: 未押下時の文言を **「{plan.name} プランが初期候補として表示されています」** に変更した。
  責務表・受入条件 9 も更新し、受入条件 9 に **「『選択中』を含まないこと」** の否定条件も足した。
  押下後の「選択中です」はそのまま（Codex も妥当と評価）。

## [Warning/C] 異なるカード同士の比較はレイアウト不変性の検査として成立しない
- 判断: **対応する**
- 根拠: 指摘のとおり。プランごとに名前・価格・機能数・CTA が違うので、
  選択状態と無関係に高さや相対位置が異なる。grid stretch でカード全体の高さだけ揃い、
  内部の折返しが隠れる可能性もある。
- 対応内容: 受入条件 11 を **同一カードの状態変更「前後」比較**に置き換えた ——
  (1) 初期状態で Starter / Standard の `h3` / 価格 / CTA の矩形を保存、
  (2) Standard を選択（Starter は note 有→無、Standard は無→有）、
  (3) **同一カードの前後**を比較、(4) 相対 `top` と `height` が許容差 1px 以内、
  (5) note 自身が可視領域を持たないことを別途検査。
  カード全体の `height` 一致は**補助検査**に格下げした（grid stretch に吸収されるため）。

## [Warning/C] fixture が「未契約」を保証していない / Seeder の実行も未確定
- 判断: **対応する（最も実害の大きい指摘。設計のままだとテストが到達すらしない）**
- 根拠: コードを追った結果、**指摘のとおり設計が間違っていた**:
  - `createOrganizationWithOwner()` の既定 `grandfatherFreePlan = true` は
    `free_plan_code = PersonalPlanService::FREE_PLAN_CODE` を立てる
  - `BillingAccess::state()` L74-76 はそれを **`ActiveFreePlan`** と判定する
  - `OnboardingBillingState::grantsAccess()` L25-28 は **`ActiveFreePlan` でも true** を返す
  - `OnboardingController::show()` L61-63 は `hasActiveAccess` なら **`billing.index` へリダイレクト**
  → **既定の fixture では Checkout に到達できない**。
  さらに同 Controller L72-76 は `?plan=` を session へ積んでから
  **query 無しの canonical URL へ 303** するため、`?plan=starter` のまま URL に残る前提も誤りだった。
- 対応内容: fixture を **`createOrganizationWithOwner(grandfatherFreePlan: false)`** に確定し、
  上記 4 ファイルの行番号付き根拠を設計書に書いた。
  `?plan=` の 303 canonical redirect を明記し、`assertPathIs('/onboarding/checkout')`（query 無し）で
  着地を固定する形にした。前提の事前 assert（未契約オーナーで Checkout が表示できること）も追加した。
  Seeder は `tests/TestCase.php` L14 の **`protected bool $seed = true;`** を確認し、
  `DatabaseSeeder` が自動で走るため**明示 seed は不要**と確定した（「走るなら」を消した）。
  リスク欄にも「fixture が `grandfatherFreePlan: false` であること」を明記した
  （既定のままだと「note が無い」ではなく「画面が違う」で落ち、原因が分かりにくいため）。

## [Suggestion] `navigateToPanelIfNeeded` の引数に名前付き型
- 判断: **対応する**
- 対応内容: `export interface PanelNavigationInput` を定義し、それを引数型にした。


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
    作れないため、下記 2 段で守る（保証範囲は正直に書く）:
- [ ] **受入条件 4 の 2 段構え**（helper だけでは配線を守れないため）
  - (i) **helper の抑止契約**: 上記 vitest（`captureActive=true` で `focus` / `scrollIntoView` が呼ばれない）
  - (ii) **ページ配線**: `tests/js/pages/Capture/Show.test.ts`（vitest component test）で
    `Show.svelte` をマウントし、`CameraRecorder` の `onCaptureActiveChange(true)` を発火させてから
    カット行をクリックし、見出しの `focus` / `scrollIntoView` が**呼ばれない**ことを 1 本固定する。
    実カメラは不要で、子 component の callback を叩ければ足りる。
  - **(ii) が技術的に成立しない場合**（`CameraRecorder` の mock が現実的でない等）は、
    受入条件 4 を「**helper の抑止契約を検証**（ページ配線は未固定）」と保証範囲を**下方修正して明記**する。
    「完全に検証した」と書かない。この判断は実装時に行い、実装レポートへ結果を書く

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

測定手順:

1. 初期状態（`?plan=starter`）で **Starter と Standard それぞれ**について、
   カード内の `h3` / 価格 / CTA の矩形を保存する（カード上端からの相対 `top` と `height`）
2. Standard の「選択」を押す（= Starter は note 有→無、Standard は note 無→有 に変わる）
3. **同一カードの変更前後**を比較する
4. `h3` / 価格 / CTA のカード上端からの相対 `top` と `height` が**許容差 1px 以内**
5. `plan-selected-note-*` 自身が**可視領域を持たない**ことを別途検査
   （矩形が 1px 四方以下、または `clip` / `clip-path` により視覚的に隠れている = `sr-only` が効いている）

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
- [ ] **受入条件 11**: 上記 1〜5 を `->script()` の `getBoundingClientRect()` で検査
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
visit('/onboarding/checkout?plan=starter')->assertPathIs('/onboarding/checkout');
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
| 4 | `captureActive` 中は動かない | **vitest 2 段**: (i) `panel-navigation.test.ts` の副作用テスト（helper の抑止契約）、(ii) `tests/js/pages/Capture/Show.test.ts` の component test（**ページ配線**）。(ii) が成立しない場合は「helper の抑止契約のみ検証」と保証範囲を下方修正して実装レポートに明記する |
| 5 | reduced-motion で smooth を使わない | vitest `scrollBehaviorFor` |
| 6 | 戻るで視点とフォーカスが一覧側へ | Browser `CaptureCutNavigationTest` |
| 7 | `preview-video` の名前が「プレビュー」を含む（固定文言。playbackId は常に preview 由来） | Browser |
| 8 | `take-preview-video` の名前がカットラベルを含む | Browser |
| 9 | `?plan=` で当該プラン名 +「初期候補」を含む note が 1 つだけ（「選択中」を含まない） | Browser `OnboardingPlanSelectionA11yTest` |
| 10 | 選び直しで note が移動し、文言が「選択中」へ切り替わる | Browser 同上 |
| 11 | Checkout の**同一カード**で、選択状態の変更前後にカード内主要要素の相対 top・height が 1px 以内で不変（+ note が不可視） | Browser 同上（`getBoundingClientRect`） |


---

## Round 2 の指摘を潰すために確認した実コード

### app/Http/Controllers/Projects/VideoManualController.php L142-148 (playbackJobId の実 query)
```php
                // playbackJobId は succeeded preview のみを見るため staleness 抑制の対象外 (不変)
                'playbackJobId' => $manual->renderJobs()
                    ->where('kind', RenderKind::Preview->value)
                    ->where('status', JobStatus::Succeeded->value)
                    ->whereNotNull('output_path')
                    ->latest('id')
                    ->value('id'),

```

### app/Http/Controllers/Onboarding/OnboardingController.php L58-77 (Checkout の到達条件と ?plan= の 303)
```php

        // 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
        // billing-required に飛ばないよう、先に契約状態を判定する。
        if ($this->access->hasActiveAccess($organization)) {
            return new RedirectResponse(route('billing.index'));
        }

        // 未契約 + manageBilling 権限なし → billing-required へ
        if (! Gate::allows('manageBilling', $organization)) {
            return new RedirectResponse(route('onboarding.billing-required'));
        }

        // ?plan= が来ていたら org-scoped に積み (Resolver 規約: 有効→put / 無効→forget)、
        // canonical URL へ 303 する (再読込・共有時に query が残らない)。
        // 不在なら session を破壊しない (= リロード耐性のため後段で peek する)。
        if ($request->has('plan')) {
            $this->intendedPlanResolver->rememberForOrganizationFromQuery($request, $organization);

            return new RedirectResponse(route('onboarding.checkout'), 303);
        }

```

### app/Services/Billing/BillingAccess.php L70-77 (free_plan_code → ActiveFreePlan)
```php
        // 現在 entitled な Stripe subscription が「ない」(行の不在ではなく entitlement で判定。
        // canceled 等の過去行が残っていてもよい = paid→free 経路) とき free entitlement を見る。
        // 判定は定数比較 (未知値は fail-closed で通さない)。entitled subscription があれば上で
        // Subscribed 優先 (free と併存しない invariant)。
        if ($organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
            return OnboardingBillingState::ActiveFreePlan;
        }


```

### app/Enums/Billing/OnboardingBillingState.php L25-28 (ActiveFreePlan でも grantsAccess=true)
```php
    public function grantsAccess(): bool
    {
        return $this === self::Subscribed || $this === self::ActiveFreePlan;
    }

```

### tests/Pest.php L173-186 (createOrganizationWithOwner の既定が free plan を立てる)
```php
function createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($grandfatherFreePlan) {
        $organization->forceFill([
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
        ])->save();
    }

    return [$organization, $owner];
}

```

### tests/TestCase.php L10-18 (seed = true)
```php
{
    /**
     * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
     */
    protected bool $seed = true;
}

```

