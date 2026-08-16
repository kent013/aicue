# アプリの使命・禁止事項・思考原則 (全 Codex 呼び出しに適用)

## アプリの使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- PHPStan level 10 (走査根は app / config / database / routes。tests は対象外)
- Pest テストフレームワーク (Feature/Unit は RefreshDatabase グローバル適用 + --parallel、Browser は Chromium + WebKit の 2 レーン)
- vitest + @testing-library/svelte (フロントのユニット/コンポーネントテスト)
- DTO + JsonResource パターン
- Laratrust RBAC (Organization → Team → Project 階層)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策にテスト、RefreshDatabase グローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠: design token 経由で color / radius / typography を参照しているか、hex 直書きを増やしていないか
11. Atomic Design 準拠: atoms → molecules → organisms → features/{domain} → templates → pages の単方向 import。アイコンは @lucide/svelte のみで SVG 直書きを新設していないか

【このリポジトリの機械検査 (設計が抵触していないか確認せよ)】
- ds-purity (resources/js 全体を静的走査): raw palette 色 (bg-blue-500 等) / hex 直書き / arbitrary z-index (z-[...]) / 静的 inline style (style="…") / shadow-* / gradient / hover:scale- / 素の rounded / rounded-xs,xl,2xl,3xl,4xl,full / 方向別 rounded (rounded-t- 等) / 任意値 rounded / raw text-size (text-sm 等。ramp は text-display,h1,h2,h3,body,caption) / 任意値 text-[...] / font-* (normal,medium,mono 以外) を禁止。**任意値の w-/max-w-/top-/bottom- は禁止対象に含まれない**。
- atomic-import-graph: 単方向 import の強制。
- page-shell-structure: AppLayout を import するページは PageContainer + PageHeader|PageHeaderSection + PageContent を使う (PageContent だけ allowlist 除外可、reason 非空必須)。
- StrictTypesDeclarationGateTest: git 追跡下の PHP 全数に declare(strict_types=1)。
- 検証コマンド: composer test / composer phpstan / vendor/bin/pint --test / pnpm lint / pnpm typecheck / pnpm test / pnpm build / pnpm typecheck:packages / pnpm build:packages / pnpm test:packages / composer test:browser

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: landscape-fullscreen-capture (横持ち全画面撮影とカット間スワイプ)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より転記）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計に直接効くのは **2 / 8**。とくに 8 は、端 (最初/最後) のカット移動ボタンと
> 録画中のカット移動の両方に効く (どちらも `disabled` にしない)。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`。走査根は `app` / `config` / `database` / `routes`）
- **Pest**テストフレームワーク（`composer test` / `composer test:browser`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **フロント固有**: Svelte 5 runes + DS token のみ (`ds-purity`)。
  `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import
  (`atomic-import-graph`)。アイコンは `@lucide/svelte` のみ (`lucide-scoped-import`)。
  arbitrary z-index 禁止 (`z-0/10/20/30/40/50` の ramp のみ)、静的 inline style 禁止、
  raw text-size 禁止 (`text-{display,h1,h2,h3,body,caption}` の ramp)、
  方向別 / 任意値 `rounded` 禁止 (`rounded-sm/md/lg` の 3 段)。
- **git 追跡下の PHP 全数に `declare(strict_types=1)`**（`StrictTypesDeclarationGateTest`。
  新規の Browser テストファイルも対象）

## 概念設計リファレンス

- `devnotes/20260816-1021-landscape-fullscreen-capture/conceptual-design.md` (Codex Round 3 で APPROVED)
- 合議履歴: 同ディレクトリの `conceptual-review-round-{1,2,3}.md` と
  `codex-history/conceptual-review-{prompt,decisions}-round-{1,2,3}.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 横持ち判定・スワイプ判定・移動判断・スクロール抑止の純関数化 | `resources/js/lib/capture/landscape-capture.ts` (新規) | 高 |
| B | 上部カット名スワイプバー | `resources/js/components/features/capture/CutSwipeBar.svelte` (新規) | 高 |
| C | 撮影ガイドの透過オーバーレイと `CameraRecorder` の全画面レイアウト | `resources/js/components/features/capture/ShootingGuideOverlay.svelte` (新規) / `CameraRecorder.svelte` | 高 |
| D | 撮影ページの全画面配線 (切替・ラッチ・再入路・告知・スクロール抑止) | `resources/js/pages/Capture/Show.svelte` | 高 |
| E | テスト一式 (vitest 純関数 / component / ページ配線 + Browser 2 レーン) | `tests/js/lib/capture/landscape-capture.test.ts` (新規) / `tests/js/components/features/capture/{CutSwipeBar,ShootingGuideOverlay}.test.ts` (新規) / `tests/js/components/features/capture/CameraRecorder.test.ts` / `tests/js/pages/CaptureShow.test.ts` / `tests/Browser/CaptureLandscapeFullscreenTest.php` (新規) | 高 |
| F | 既存契約テキストの同期と保証範囲の明示 | `tests/js/architecture/page-shell-structure.test.ts` / `docs/supported-browsers.md` | 中 |

---

## 施策 A: 横持ち判定・スワイプ判定・移動判断・スクロール抑止の純関数化

### 変更箇所

- ファイル: `resources/js/lib/capture/landscape-capture.ts` (**新規**)

`panel-navigation.ts` と同じ設計思想を踏襲する — **述語だけを切り出さず、副作用ごとここに置く**。
述語だけを切り出すと「抑止条件が実際に副作用を止めているか」を page component の外から
検証できず、回帰を固定できないためである (`panel-navigation.ts` の冒頭コメントの原則)。

### 波及変更

- TypeScript型定義: 本ファイルが新しい型 (`LayoutMode` / `SwipeOutcome` /
  `CutNavigationDecision` / `NavigationDirection`) の**定義元**になる。既存 `types/capture.ts` は不変。
- API Resource/DTO: **なし** (サーバ応答の形は変わらない)。
- テストファイル: `tests/js/lib/capture/landscape-capture.test.ts` を新規作成 (施策 E)。

### 現行コード

該当ファイルは存在しない。参考となる既存の先例は次の 2 つ。

```ts
// resources/js/lib/capture/panel-navigation.ts (抜粋) — 副作用ごと lib に置く先例
export function prefersReducedMotion(): boolean {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return true;
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

export function navigateToPanelIfNeeded(input: PanelNavigationInput): boolean {
    const { captureActive, leftEl, rightEl, headingEl, reducedMotion } = input;
    if (captureActive) return false;
    // …
}
```

```ts
// resources/js/lib/capture/cut-labels.ts (抜粋) — ラベル導出の唯一の正本
export function buildCutLabels(cuts: CaptureCut[]): Record<number, string> { /* … */ }
```

### 変更後コード

```ts
/**
 * 横持ち全画面撮影の判定・ジェスチャ解釈・移動判断・背景スクロール抑止 (doc/05 §5.2)。
 *
 * panel-navigation.ts と同じ方針で **副作用ごとここに置く**。述語だけを切り出すと
 * 「抑止条件が実際に副作用を止めているか」を page component の外から検証できず、
 * 回帰を固定できない。
 */

/** 撮影パネルのレイアウト種別。CameraRecorder の Phase union と同じ書き方に揃える。 */
export type LayoutMode = "inline" | "fullscreen";

/** カット移動の向き。-1 = 前へ / +1 = 次へ。 */
export type NavigationDirection = -1 | 1;

/**
 * 横持ち全画面へ入る条件。**ここが唯一の正本**で、Tailwind の breakpoint 値はコピーしない。
 *
 * - `orientation: landscape` … 横持ち。
 * - `max-height: 540px`      … 横持ちスマホの短辺 (iPhone SE 320 / 15 Pro 393 /
 *                              大型 Android 412) を含み、タブレット横持ち (iPad 768) と
 *                              ノート PC を含まない高さ。
 * - `pointer: coarse`        … 指で操作する端末に限る (スワイプ前提の UI のため)。
 *
 * 3 条件は**すべて必要**である。どれかが式から落ちるとデスクトップまで全画面になるため、
 * 文字列そのものを landscape-capture.test.ts が固定し、Browser の負のコントロール 3 本が
 * 条件ごとの欠落を実挙動で検出する。
 */
export const LANDSCAPE_CAPTURE_MEDIA_QUERY =
    "(orientation: landscape) and (max-height: 540px) and (pointer: coarse)";

/**
 * 現在が横持ち全画面の条件を満たすか。
 * SSR / matchMedia 非対応では **false** (= 全画面にしない) に倒す。
 * 「既存レイアウトのまま」は常に安全側で、逆 (存在しない環境で全画面に入る) は
 * 抜け出す手段が無くなるため採らない。
 */
export function matchesLandscapeCapture(): boolean {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return false;
    return window.matchMedia(LANDSCAPE_CAPTURE_MEDIA_QUERY).matches;
}

/**
 * 横持ち判定の変化を購読する。**登録直後に現在値で 1 回呼ぶ**
 * (change イベントを待つと初期表示が縦持ち扱いのままになるため)。
 * 戻り値は解除関数。matchMedia 非対応環境では何もせず no-op を返す。
 */
export function subscribeLandscapeCapture(onChange: (matches: boolean) => void): () => void {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
        return () => undefined;
    }
    const list = window.matchMedia(LANDSCAPE_CAPTURE_MEDIA_QUERY);
    const handler = (event: MediaQueryListEvent): void => onChange(event.matches);
    list.addEventListener("change", handler);
    onChange(list.matches);
    return () => list.removeEventListener("change", handler);
}

/* ---- スワイプ判定 ---- */

/** 水平移動がこの px 以上でスワイプとみなす (タップ・微小な指ぶれを弾く)。 */
export const SWIPE_MIN_DISTANCE_PX = 48;
/** 縦方向のブレ許容比。|dy| がこの比率を超えたら縦スクロール意図とみなし移動しない。 */
export const SWIPE_MAX_OFF_AXIS_RATIO = 0.6;
/**
 * 画面左右端のこの幅から始まったスワイプは扱わない。
 * iOS Safari の戻る/進むジェスチャは JS から抑止できないため、
 * **競合させずに譲る** (誤爆で意図せずカットが動くのを防ぐ)。
 */
export const SWIPE_EDGE_EXCLUSION_PX = 24;

export type SwipeOutcome = "previous" | "next" | "none";

export interface SwipeGestureInput {
    startX: number;
    startY: number;
    endX: number;
    endY: number;
    /** ジェスチャ時点の viewport 幅 (右端の除外判定に使う) */
    viewportWidth: number;
}

/**
 * ポインタの始点・終点からカット移動の向きを決める。
 * 左へスワイプ (dx < 0) = 次のカット、右へスワイプ (dx > 0) = 前のカット
 * (カルーセルと同じ「内容が指について動く」向き)。
 */
export function resolveSwipe(input: SwipeGestureInput): SwipeOutcome {
    const { startX, startY, endX, endY, viewportWidth } = input;
    if (startX <= SWIPE_EDGE_EXCLUSION_PX) return "none";
    if (startX >= viewportWidth - SWIPE_EDGE_EXCLUSION_PX) return "none";
    const dx = endX - startX;
    const dy = endY - startY;
    if (Math.abs(dx) < SWIPE_MIN_DISTANCE_PX) return "none";
    if (Math.abs(dy) > Math.abs(dx) * SWIPE_MAX_OFF_AXIS_RATIO) return "none";
    return dx < 0 ? "next" : "previous";
}

/** SwipeOutcome を移動の向きへ写像する (none は移動しない)。 */
export function swipeDirection(outcome: SwipeOutcome): NavigationDirection | null {
    if (outcome === "next") return 1;
    if (outcome === "previous") return -1;
    return null;
}

/* ---- 移動判断 (告知文の唯一の出所) ---- */

/** 端に着いたときの告知。スワイプ・ボタン・キー操作の 3 手段が同じ文言を共有する。 */
export const CUT_EDGE_MESSAGES = {
    first: "これが最初のカットです。",
    last: "これが最後のカットです。",
} as const;

/**
 * 録画中の移動拒否。**押下時にエラーを出す** (禁止事項 8: disabled にしない)。
 * 文中の「録画を停止」は全画面上に常時可視な停止ボタンを指す =
 * 告知した次の操作が同じ画面に必ず存在する (行き先のない詰みを作らない)。
 */
export const RECORDING_BLOCKS_NAVIGATION_MESSAGE =
    "録画中はカットを移動できません。録画を停止してから移動してください。";

export type CutNavigationDecision =
    | { kind: "move"; cutId: number }
    | { kind: "notice"; tone: "status" | "alert"; message: string }
    | { kind: "ignore" };

export interface CutNavigationInput {
    /**
     * CameraRecorder の公開 active (`starting || resuming || phase !== "idle"`)。
     * getUserMedia の grant 待ち 2 窓を含むため、権限ダイアログ中の移動も止まる
     * (panel-navigation.ts の抑止条件と**同じ判断基準**)。
     */
    captureActive: boolean;
    /** manual.cuts の並び順そのもの (CutNavigator の表示順)。別のソート規則を持ち込まない。 */
    cuts: readonly { id: number }[];
    currentCutId: number | null;
    direction: NavigationDirection;
}

/**
 * カット移動の可否と結果を 1 か所で決める。
 *
 * **自動停止はしない**。誤スワイプで録画が確定するのは現場で取り返しがつかず、
 * 既存 `CameraRecorder.releaseForPreview()` が録画中は no-op (= 暗黙終了しない) という
 * 確立済みの契約とも一致する。
 */
export function decideCutNavigation(input: CutNavigationInput): CutNavigationDecision {
    const { captureActive, cuts, currentCutId, direction } = input;
    if (captureActive) {
        return { kind: "notice", tone: "alert", message: RECORDING_BLOCKS_NAVIGATION_MESSAGE };
    }
    if (currentCutId === null) return { kind: "ignore" };
    const index = cuts.findIndex((cut) => cut.id === currentCutId);
    if (index < 0) return { kind: "ignore" };
    const target = cuts[index + direction];
    if (target === undefined) {
        const edge = direction < 0 ? "first" : "last";
        return { kind: "notice", tone: "status", message: CUT_EDGE_MESSAGES[edge] };
    }
    return { kind: "move", cutId: target.id };
}

/* ---- 背景スクロール抑止 ---- */

/** 抑止に使う Tailwind utility。静的 inline style を書かないため class で行う (ds-purity)。 */
const SCROLL_LOCK_CLASS = "overflow-hidden";

/**
 * 全画面中に背後ページがスクロールするのを止める。**戻り値の解除関数が単一のクリーンアップ点**で、
 * 解除漏れは「スクロールできない詰み」になるため他所で class を触らない。
 * 既に他所が同じ class を付けていた場合は**外さない** (他所の抑止を横から解除しない)。
 */
export function lockBackgroundScroll(): () => void {
    if (typeof document === "undefined") return () => undefined;
    const element = document.documentElement;
    if (element.classList.contains(SCROLL_LOCK_CLASS)) return () => undefined;
    element.classList.add(SCROLL_LOCK_CLASS);
    return () => element.classList.remove(SCROLL_LOCK_CLASS);
}
```

### PHPStan適合チェック

- [x] **PHP の変更が 1 行も無い** (本施策は TypeScript のみ)。走査根 `app` / `config` /
      `database` / `routes` のいずれにも変更が入らないため、level 10 の解析結果は不変。
- [x] TypeScript 側は `pnpm typecheck` (`tsc --noEmit`) が対応する。
      戻り値型はすべて明示、`any` を使わない、`readonly` を受ける入力は `readonly` で宣言。
- [x] 判別可能 union (`CutNavigationDecision`) で不正状態を型で排除
      (「移動もしないし告知もしない」以外の中間状態を作らない)。

### テスト計画

- [x] 新規 `tests/js/lib/capture/landscape-capture.test.ts`
  - `LANDSCAPE_CAPTURE_MEDIA_QUERY` が **3 条件をすべて含む** — 条件が式から落ちる回帰の直接検出
  - `matchesLandscapeCapture()`: `window.matchMedia` 不在で `false`
  - `subscribeLandscapeCapture()`: 登録直後に現在値で 1 回呼ぶ / `change` で呼ぶ /
    解除関数で `removeEventListener` される / 非対応環境で no-op
  - `resolveSwipe()`: 左→`next` / 右→`previous` / 距離不足→`none` /
    縦優勢→`none` / 左端始まり→`none` / 右端始まり→`none`
  - `decideCutNavigation()`: `captureActive` で常に `alert` の告知 (**先頭で評価される**ことを
    「端かつ録画中」の入力で固定) / 通常移動 / 先頭で `-1` → `first` の `status` /
    末尾で `+1` → `last` の `status` / 未選択・不在 id・空配列 → `ignore`
  - `lockBackgroundScroll()`: class を付ける / 解除で外す / 既に付いていたら付けも外しもしない
- [x] 個別の `DatabaseTransactions` は使わない (JS テストのため無関係)

### リスク

- `MediaQueryList.addEventListener` は iOS Safari 14 以降で利用できる。
  13.x は `addListener` のみだが、`docs/supported-browsers.md` の対象範囲より古く、
  そもそも撮影 PWA が要求する機能 (Service Worker + `getUserMedia` + MediaRecorder) を満たさない。
  **二重の登録経路を持たない** (思考原則 3: 後方互換の並走を残さない)。
- `max-height: 540px` は境界値であり、将来の端末で外れる可能性がある。
  値を 1 か所に閉じ込めてあるので変更時の影響範囲は本ファイルに限られる。
  **仕組みが機能していないうちは値を弄らない** (思考原則) 前提で、初期値のまま出す。

---

## 施策 B: 上部カット名スワイプバー (`CutSwipeBar.svelte`)

### 変更箇所

- ファイル: `resources/js/components/features/capture/CutSwipeBar.svelte` (**新規**)
- 配置理由: 撮影ドメイン固有の component であり、`features/capture` に置く
  (`atomic-import-graph`: `features/{domain}` から `atoms` / `organisms` は import 可、逆流不可)。

### 波及変更

- TypeScript型定義: props は本ファイル内で完結 (`label` / `scene` / `position` / `onNavigate`)。
  `types/capture.ts` は不変。
- API Resource/DTO: **なし**。
- テストファイル: `tests/js/components/features/capture/CutSwipeBar.test.ts` (新規、施策 E)。

### 概念設計からの精緻化 (props の確定)

概念設計では `hasPrevious` / `hasNext` を持たせる案だったが、
**`position: { index, total }` に置き換える**。理由:

- `hasPrevious` / `hasNext` は「押せなさ」の表現に転びやすく、
  実装者が素直に `disabled` / 薄いグレー表示を書くと禁止事項 8 の趣旨を崩す。
- 端であることは「2 / 12」という**現在位置**を出せば自然に伝わり、
  かつ全カット中どこにいるかという**より多くの情報**を同じ面積で提供できる。
- 端に着いたときの告知は施策 A の `CUT_EDGE_MESSAGES` が担うので、
  バー側が端を知る必要はない (判断の置き場所を 1 か所に保つ)。

### 現行コード

該当ファイルは存在しない。ラベル導出とアイコンの先例は `CutNavigator.svelte`:

```svelte
<script lang="ts">
    import { Check, MapPin, Video } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import { buildCutLabels } from "@/lib/capture/cut-labels";
    import type { CaptureCut } from "@/types/capture";

    /** 導出規則は lib/capture/cut-labels.ts が唯一の正本 */
    const labels = $derived(buildCutLabels(cuts));
</script>
```

### 変更後コード

```svelte
<script lang="ts">
    import { ChevronLeft, ChevronRight } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import {
        resolveSwipe,
        swipeDirection,
        type NavigationDirection,
    } from "@/lib/capture/landscape-capture";

    /**
     * 横持ち全画面の上部カット名エリア (doc/05 §5.2)。
     * **左右スワイプ / 前後ボタン / 左右矢印キー**の 3 手段でカットを前後に移動する。
     * スワイプだけにしないのは、キーボード・スクリーンリーダー利用者に到達不能であり、
     * 手袋を着けた現場作業者にも失敗しやすいためである。
     *
     * ラベル (手順 N / 急所 N-M) は **受け取るだけ**で自前では組み立てない
     * (lib/capture/cut-labels.ts の buildCutLabels() が唯一の導出元。二重管理を作らない)。
     * 端に着いたときの告知は親が持つ (判断の置き場所を 1 か所に保つ) ため、
     * 本 component は端かどうかを知らない = ボタンを disabled にする理由も持たない。
     */
    interface Props {
        /** 例: "手順 2" / "急所 2-1"。buildCutLabels() の結果をそのまま受ける */
        label: string;
        /** カット内容 (CutNavigator の行と同じ出所) */
        scene: string;
        /** 現在位置。index は 1 起点 (表示にそのまま使う) */
        position: { index: number; total: number };
        onNavigate: (direction: NavigationDirection) => void;
    }

    let { label, scene, position, onNavigate }: Props = $props();

    /** 進行中のポインタ ID と始点。pointerdown で採り、pointerup / cancel で捨てる */
    let gesture: { pointerId: number; startX: number; startY: number } | null = null;

    function handlePointerDown(event: PointerEvent): void {
        gesture = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY };
    }

    function handlePointerUp(event: PointerEvent): void {
        const started = gesture;
        gesture = null;
        if (started === null || started.pointerId !== event.pointerId) return;
        const direction = swipeDirection(
            resolveSwipe({
                startX: started.startX,
                startY: started.startY,
                endX: event.clientX,
                endY: event.clientY,
                viewportWidth: window.innerWidth,
            }),
        );
        if (direction === null) return;
        onNavigate(direction);
    }

    /** ジェスチャ中断 (別要素へ持って行かれた等) は始点ごと捨てる */
    function handlePointerCancel(): void {
        gesture = null;
    }

    function handleKeydown(event: KeyboardEvent): void {
        if (event.key === "ArrowLeft") {
            event.preventDefault();
            onNavigate(-1);
            return;
        }
        if (event.key === "ArrowRight") {
            event.preventDefault();
            onNavigate(1);
        }
    }
</script>

<!--
  touch-pan-y: 横方向のブラウザ既定スクロールを止め、縦スクロールは残す
  (静的 inline style を書かずに touch-action を指定する。ds-purity)。
-->
<div
    class="flex touch-pan-y items-center gap-2 rounded-md border border-border bg-surface/90 px-2 py-1"
    role="group"
    aria-label="カットの移動"
    tabindex="0"
    onpointerdown={handlePointerDown}
    onpointerup={handlePointerUp}
    onpointercancel={handlePointerCancel}
    onkeydown={handleKeydown}
    data-testid="cut-swipe-bar"
>
    <Button
        variant="ghost"
        size="sm"
        iconOnly
        ariaLabel="前のカット"
        onclick={() => onNavigate(-1)}
        testId="cut-swipe-previous"
    >
        <ChevronLeft class="size-5" aria-hidden="true" />
    </Button>
    <div class="min-w-0 flex-1 text-center">
        <p class="text-caption text-text-secondary" data-testid="cut-swipe-label">
            {label}
            <span class="ml-1">{position.index} / {position.total}</span>
        </p>
        <p class="truncate text-body" data-testid="cut-swipe-scene">{scene}</p>
    </div>
    <Button
        variant="ghost"
        size="sm"
        iconOnly
        ariaLabel="次のカット"
        onclick={() => onNavigate(1)}
        testId="cut-swipe-next"
    >
        <ChevronRight class="size-5" aria-hidden="true" />
    </Button>
</div>
```

### PHPStan適合チェック

- [x] PHP の変更なし (level 10 の解析結果は不変)
- [x] `pnpm typecheck`: props は `interface Props` で明示、`onNavigate` の引数は
      `NavigationDirection` union (`-1 | 1`) で、任意の number を渡せない
- [x] `pnpm lint` (eslint + svelte): `role="group"` + `tabindex` + `aria-label` を揃え、
      a11y ルール (`a11y_no_noninteractive_element_interactions` 等) を満たす形にする

### テスト計画

- [x] 新規 `tests/js/components/features/capture/CutSwipeBar.test.ts`
  - ラベル・scene・位置 (`2 / 12`) が描画される
  - 「前のカット」/「次のカット」ボタンが `onNavigate(-1)` / `onNavigate(1)` を呼ぶ
  - **端でもボタンが `disabled` にならない** (`toBeDisabled()` の否定。禁止事項 8 の機械固定)
  - `ArrowLeft` / `ArrowRight` で `onNavigate` が呼ばれ、`preventDefault` される
  - pointerdown → pointerup の系列で左スワイプ = `onNavigate(1)`、右スワイプ = `onNavigate(-1)`
  - 距離不足 / 縦優勢 / 画面端始まりでは `onNavigate` が呼ばれない
    (判定そのものは施策 A のテストが網羅し、ここは**配線**を見る)
  - `pointercancel` の後の `pointerup` では移動しない (始点を捨てている)
- [x] 個別の `DatabaseTransactions` を使っていない (JS テストのため無関係)

### リスク

- `role="group"` に `tabindex="0"` を付ける形は、スクリーンリーダーで「グループ」に
  フォーカスが止まる挙動になる。矢印キーの受け口として必要で、
  前後ボタンという明示的な操作手段が同じ要素内にあるため**キーボード利用者が詰まらない**。
- `window.innerWidth` を component から直接読む。jsdom では既定 1024 で安定しており、
  テストでは上書きできる。SSR では `pointerup` が発生しないため到達しない。

---

## 施策 C: 撮影ガイドの透過オーバーレイと `CameraRecorder` の全画面レイアウト

### 変更箇所

- ファイル: `resources/js/components/features/capture/ShootingGuideOverlay.svelte` (**新規**)
- ファイル: `resources/js/components/features/capture/CameraRecorder.svelte`
  (props 追加 = L48-65 付近、markup の class 切替 = L495-602 付近)

### 波及変更

- TypeScript型定義: `CameraRecorder` の `Props` に 2 つ追加。
  `shootingPoint` は **既存 `CaptureCut["shooting_point"]` (= `string | null`) をそのまま参照**し、
  上流の nullable 契約と一致させる (既存 `subtitlePrimary?: CaptureCut["subtitle_primary"]` と同じ書き方)。
  非 null へ絞る判定は `CameraRecorder` の内側 1 か所で行い、
  `ShootingGuideOverlay` は非 null の `text: string` だけを受ける。
- API Resource/DTO: **なし** (`shooting_point` は既に `CaptureCut` に存在し、`Show.svelte` が
  「撮影ポイント: …」として描画済み。サーバ側の DTO / JsonResource は無変更)。
- テストファイル: `tests/js/components/features/capture/ShootingGuideOverlay.test.ts` (新規) と
  `CameraRecorder.test.ts` への追記 (施策 E)。

### 現行コード

```svelte
<!-- resources/js/components/features/capture/CameraRecorder.svelte (抜粋: props) -->
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
        /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
        subtitlePrimary?: CaptureCut["subtitle_primary"];
        subtitleSecondary?: CaptureCut["subtitle_secondary"];
        /** 撮影 active (starting || resuming || phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
        onCaptureActiveChange?: (active: boolean) => void;
    }

    let {
        onCaptured,
        onCameraUnavailable,
        subtitlePrimary = null,
        subtitleSecondary = "",
        onCaptureActiveChange,
    }: Props = $props();
```

```svelte
<!-- resources/js/components/features/capture/CameraRecorder.svelte (抜粋: markup) -->
<div class="flex flex-col gap-3">
    <div class="relative">
        <!-- svelte-ignore a11y_media_has_caption -->
        <video
            bind:this={video}
            autoplay
            playsinline
            muted
            class="aspect-video w-full rounded-md bg-surface object-cover"
            data-testid="camera-preview"
        ></video>
        <!-- overlay の z 順 (DOM 順で映像 < grid < 字幕帯): グリッドは字幕より先 = 下層 -->
        <GridOverlay visible={showGrid} />
        <SubtitleOverlay
            primary={subtitlePrimary}
            secondary={subtitleSecondary}
            visible={showSubtitles}
        />
        {#if showTimer}
            <!-- 録画タイマー (overlay 右上)。recording/paused 時のみ -->
            <div class="pointer-events-none absolute top-2 right-2 …" data-testid="record-timer">…</div>
        {/if}
    </div>
    <div class="flex items-center justify-center gap-3">
        …（録画開始 / 一時停止 / 再開 / 停止 / グリッド / 字幕トグル）…
    </div>
    {#if error}
        <p class="text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
```

参考: 既存 `GridOverlay.svelte` (装飾のみの overlay。`visible` だけを受ける形)

```svelte
{#if visible}
    <div class="pointer-events-none absolute inset-0" aria-hidden="true" data-testid="grid-overlay">
        <div class="absolute inset-y-0 left-1/3 w-px bg-surface/40"></div>
        …
    </div>
{/if}
```

### 変更後コード

#### C-1. `ShootingGuideOverlay.svelte` (新規)

```svelte
<script lang="ts">
    import { Lightbulb } from "@lucide/svelte";

    /**
     * 撮影ガイド (撮影方法 = cuts.shooting_point) の透過オーバーレイ (doc/05 §5.2:
     * 「電球アイコンの横に、そのカットの撮影方法（構図指示）を表示」)。
     * 焼込ではなく撮影ガイド overlay で、MediaRecorder が録る MediaStream には含まれない。
     *
     * **表示可否は親が決める** — 「非空の shooting_point があり、かつ全画面のとき」だけ親が描画する。
     * GridOverlay の `visible` 形には揃えない: グリッドは内容を持たない装飾だが、
     * こちらはカットごとに変わる文字列であり、「空文字列」と「非表示」の 2 状態を
     * 子に持ち込む理由が無いため (型で不正状態を減らす)。
     *
     * z 順は 映像 < グリッド < **撮影ガイド** < 字幕帯 (DOM 順で表現する)。
     * 字幕を最優先で可読にするのは、v1 の中核価値が字幕であるため。
     */
    interface Props {
        text: string;
    }

    let { text }: Props = $props();
</script>

<div
    class="pointer-events-none absolute inset-x-0 top-2 flex justify-center px-3"
    data-testid="shooting-guide-overlay"
>
    <p
        class="line-clamp-2 flex max-w-[90%] items-start gap-1 rounded-sm bg-text/70 px-3 py-1 text-caption text-surface"
    >
        <Lightbulb class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span class="min-w-0">{text}</span>
    </p>
</div>
```

> `top-2` は `SubtitleOverlay` の上部帯 (`p-3` の内側) より上に来る。
> 字幕の上部帯と視覚的に重なる場合でも、DOM 順で字幕が後 = 上層なので字幕が勝つ。

#### C-2. `CameraRecorder.svelte` の props 追加

```svelte
    import ShootingGuideOverlay from "@/components/features/capture/ShootingGuideOverlay.svelte";
    import type { LayoutMode } from "@/lib/capture/landscape-capture";

    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
        /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
        subtitlePrimary?: CaptureCut["subtitle_primary"];
        subtitleSecondary?: CaptureCut["subtitle_secondary"];
        /** 撮影 active (starting || resuming || phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
        onCaptureActiveChange?: (active: boolean) => void;
        /**
         * 表示レイアウト (T18x: 横持ち全画面)。**既定は従来どおり inline** で、
         * 縦持ちの見た目は 1px も変わらない。
         * 本 props は class の切替にしか使わず、**phase マシン・stream 管理には一切触れない**。
         */
        layout?: LayoutMode;
        /**
         * 撮影ガイド (撮影方法)。上流の CaptureCut["shooting_point"] の nullable 契約に合わせる。
         * 非 null かつ非空へ絞る判定は本 component の内側 1 か所で行う。
         */
        shootingPoint?: CaptureCut["shooting_point"];
    }

    let {
        onCaptured,
        onCameraUnavailable,
        subtitlePrimary = null,
        subtitleSecondary = "",
        onCaptureActiveChange,
        layout = "inline",
        shootingPoint = null,
    }: Props = $props();

    // --- 全画面レイアウト (表示のみ。phase マシンとは独立) ---
    const isFullscreen = $derived(layout === "fullscreen");
    /** trim は空判定にのみ使い、描画には元文字列を渡す (SubtitleOverlay と同じ作法) */
    const shootingGuideText = $derived((shootingPoint ?? "").trim());
    const showShootingGuide = $derived(isFullscreen && shootingGuideText !== "");
```

#### C-3. `CameraRecorder.svelte` の markup (class 切替のみ)

```svelte
<!--
  全画面と inline の切替は **class の差し替えだけ**で行う。
  {#if} で描き分けると <video> が unmount され、録画中の MediaStream / MediaRecorder が
  破棄されて録ったデータが消えるため (詳細設計 D3 の不変条件)。
  fullscreen: 外枠を relative にして、映像コンテナと操作行を絶対配置で重ねる。
-->
<div class={isFullscreen ? "relative size-full" : "flex flex-col gap-3"}>
    <div class={isFullscreen ? "absolute inset-0 overflow-hidden rounded-md" : "relative"}>
        <!-- svelte-ignore a11y_media_has_caption -->
        <video
            bind:this={video}
            autoplay
            playsinline
            muted
            class={isFullscreen
                ? "size-full bg-surface object-cover"
                : "aspect-video w-full rounded-md bg-surface object-cover"}
            data-testid="camera-preview"
        ></video>
        <!-- overlay の z 順 (DOM 順で 映像 < grid < 撮影ガイド < 字幕帯) -->
        <GridOverlay visible={showGrid} />
        {#if showShootingGuide}
            <ShootingGuideOverlay text={shootingGuideText} />
        {/if}
        <SubtitleOverlay
            primary={subtitlePrimary}
            secondary={subtitleSecondary}
            visible={showSubtitles}
        />
        {#if showTimer}
            <div class="pointer-events-none absolute top-2 right-2 …" data-testid="record-timer">…</div>
        {/if}
    </div>
    <div
        class={isFullscreen
            ? "absolute inset-x-0 bottom-0 z-10 flex items-center justify-center gap-3 p-3"
            : "flex items-center justify-center gap-3"}
    >
        …（録画開始 / 一時停止 / 再開 / 停止 / グリッド / 字幕トグル。**中身は無改変**）…
    </div>
    {#if error}
        <p
            class={isFullscreen
                ? "absolute inset-x-0 bottom-14 z-10 text-center text-caption text-danger"
                : "text-center text-caption text-danger"}
            role="alert"
        >
            {error}
        </p>
    {/if}
</div>
```

**変更の全量**: import 2 行 / props 2 つ / `$derived` 3 つ / 既存 4 要素の `class` 属性の
三項化 / `ShootingGuideOverlay` の 1 ブロック。
`Phase` union・`syncActive` / `setPhase`・`startRecording` / `safeStop` / `requestPause` /
`requestResume` / `recoverPhaseFromRecorderState` / `releaseForPreview` /
`resumeAfterPreview` / タイマー群 / flip 群は**1 行も触らない**。

### PHPStan適合チェック

- [x] PHP の変更なし (level 10 の解析結果は不変)
- [x] `pnpm typecheck`: `layout` は `LayoutMode` union で任意文字列を弾く。
      `shootingPoint` は `CaptureCut["shooting_point"]` を参照するので、
      上流の型が変わったら型エラーで気づける (文字列型のコピーを作らない)
- [x] `ShootingGuideOverlay` は非 null の `text: string` のみ受け、nullable を子へ持ち込まない
- [x] `ds-purity`: `max-w-[90%]` は既存 `SubtitleOverlay` と同一の書き方
      (任意値の `w-` 系は禁止パターンに含まれない)。`z-10` は ramp 内。
      hex・raw palette・raw text-size・方向別 rounded・静的 inline style を使わない

### テスト計画

- [x] 新規 `tests/js/components/features/capture/ShootingGuideOverlay.test.ts`
  - `text` がそのまま描画される (trim 前の元文字列を書き換えない)
  - `pointer-events-none` を持つ (映像上の操作を邪魔しない)
- [x] 既存 `tests/js/components/features/capture/CameraRecorder.test.ts` へ追記
  - **既定 (`layout` 省略) で従来どおり**: `shooting-guide-overlay` が出ない /
    `camera-preview` が `aspect-video` を持つ (縦持ちの見た目の回帰)
  - `layout="fullscreen"` + `shootingPoint` 非空 → `shooting-guide-overlay` が出る
  - `layout="fullscreen"` + `shootingPoint` が `null` / 空白のみ → 出ない
  - `layout="fullscreen"` で `camera-preview` が `object-cover` かつ `aspect-video` を持たない
  - DOM 順で `grid-overlay` < `shooting-guide-overlay` < `subtitle-overlay`
    (`compareDocumentPosition` で固定。z 順の回帰検出)
  - **既存の phase マシンのテストは 1 件も変更しない** (変更したら不変条件が緩んだ証拠)
- [x] 個別の `DatabaseTransactions` を使っていない (JS テストのため無関係)

### リスク

- 全画面で操作行を `absolute bottom-0` にすると、映像の下端に文字が重なる。
  背景の `bg-text/70` を持つのは overlay 側だけなので、操作行のアイコンが
  明るい被写体の上で見えづらくなりうる。**実機受入確認の項目**に含める
  (色を足す前に実機で見る = 「仕組みが機能していない段階で値を弄るな」)。
- `error` の `bottom-14` は操作行の高さに依存する経験値。操作行の高さを変える改修が入ると
  重なる。`CameraRecorder.test.ts` では位置を固定できない (jsdom はレイアウトを持たない) ため、
  これも実機受入確認の項目に置く。

---

## 施策 D: 撮影ページの全画面配線 (`Capture/Show.svelte`)

### 変更箇所

- ファイル: `resources/js/pages/Capture/Show.svelte`
  - import 追加 (L1-29)
  - 状態と派生 (L46-56 付近)
  - `$effect` 追加 (L132-141 付近)
  - ハンドラ追加 (L112-130 付近)
  - markup (L243-343)

### 波及変更

- TypeScript型定義: なし (施策 A/B/C の型を使うだけ)
- Inertia Props インターフェース: **不変**。`Props` (`project` / `manual`) は変えない。
  サーバから新しい prop を受け取らない
- API Resource/DTO: **なし**
- テストファイル: `tests/js/pages/CaptureShow.test.ts` (追記) /
  `tests/Browser/CaptureLandscapeFullscreenTest.php` (新規) / 施策 F の
  `tests/js/architecture/page-shell-structure.test.ts` (allowlist の理由文)

### 現行コード

```svelte
    let selectedCutId = $state<number | null>(null);
    const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
    /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
    const cutLabels = $derived(buildCutLabels(manual.cuts));
    …
    let captureActive = $state(false);
    …
    /** 縦積みか (= 1 カラム)。「カット一覧へ戻る」の出し分けに使う */
    let stacked = $state(false);

    function handleSelectCut(cutId: number): void {
        selectedCutId = cutId;
        // DOM 反映後に測る (撮影パネルは選択で初めて描画される)
        void tick().then(() => {
            updateStacked();
            navigateToPanelIfNeeded({ … });
        });
    }
```

```svelte
        <div class="mt-3">
        <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section bind:this={leftPaneEl} class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
            <h2 bind:this={cutListHeadingEl} tabindex="-1" class="border-b border-border px-3 py-2 …" data-testid="capture-cut-list-heading">
                シナリオ (タップして撮影)
            </h2>
            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
        </section>

        <section bind:this={rightPaneEl} class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">左のシナリオからカットを選ぶと撮影パネルが開きます。</p>
            {:else}
                <div class="flex items-center justify-between gap-2">
                    <h2 bind:this={recordingHeadingEl} tabindex="-1" … data-testid="capture-recording-heading">
                        {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
                    </h2>
                    {#if stacked}
                        <TextLink onclick={backToCutList} testId="back-to-cut-list">カット一覧へ戻る</TextLink>
                    {/if}
                </div>

                <div class="rounded-md border border-border bg-surface p-3">
                    <p class="text-caption text-text-secondary">ナレーション</p>
                    <p class="mt-1 text-body">{selectedCut.narration}</p>
                    {#if selectedCut.shooting_point}
                        <p class="mt-2 text-caption text-text-secondary">撮影ポイント: {selectedCut.shooting_point}</p>
                    {/if}
                </div>

                {#if showRecorder}
                    <CameraRecorder bind:this={recorderRef} … />
                {:else}
                    …CaptureFileFallback…
                {/if}

                <TakeStrip … />
            {/if}
        </section>
        </div>
```

### 変更後コード

#### D-1. import と状態

```svelte
    import { onMount, tick, untrack } from "svelte";
    import { ArrowLeft, BookOpen, Maximize, Minimize, Video } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
    import {
        decideCutNavigation,
        lockBackgroundScroll,
        subscribeLandscapeCapture,
        type NavigationDirection,
    } from "@/lib/capture/landscape-capture";
```

```svelte
    /* ---- 横持ち全画面 (doc/05 §5.2) ----
     * 判定・ジェスチャ解釈・移動判断・スクロール抑止は lib/capture/landscape-capture.ts が持ち、
     * ここは配線だけを行う (panel-navigation.ts と同じ役割分担)。 */
    /** 横持ち全画面の条件 (向き + 高さ + 粗いポインタ) を満たすか */
    let landscapeMatches = $state(false);
    /** 利用者が明示的に全画面を終了したか。**縦に戻すまで自動で入り直さない**ためのラッチ */
    let fullscreenDismissed = $state(false);
    /** 実際に全画面を描くか。カット未選択の全画面 (= 空の全画面) は作らない */
    const fullscreenActive = $derived(
        landscapeMatches && !fullscreenDismissed && selectedCut !== null,
    );
    /** 端の告知 (status) / 録画中の移動拒否 (alert)。文言の出所は landscape-capture.ts */
    let navigationNotice = $state<{ tone: "status" | "alert"; message: string } | null>(null);
    /** 全画面の現在位置表示 (1 起点)。cuts の並び順そのものを使う */
    const cutPosition = $derived({
        index: selectedCut === null ? 0 : manual.cuts.findIndex((c) => c.id === selectedCut.id) + 1,
        total: manual.cuts.length,
    });
```

#### D-2. `$effect` (購読 / 自動選択 + ラッチ解除 / スクロール抑止)

```svelte
    // 横持ち判定の購読。**この effect は landscapeMatches を書くだけ**にして、
    // selectedCutId を読まない (読むと選択のたびに購読を張り直すことになる)。
    $effect(() => subscribeLandscapeCapture((matches) => (landscapeMatches = matches)));

    // 横持ちに入った / 縦に戻った時の後始末。
    // - 縦に戻ったらラッチを解除する (次に横へ倒せばまた自動で全画面に入る)
    // - 横持ちでカット未選択なら先頭カットを自動選択する (空の全画面 = 詰みを作らない)
    // selectedCutId は untrack で読む (選択の変化でこの effect を再実行させない)。
    $effect(() => {
        if (!landscapeMatches) {
            fullscreenDismissed = false;
            return;
        }
        const first = manual.cuts[0];
        if (first !== undefined && untrack(() => selectedCutId) === null) {
            selectedCutId = first.id;
        }
    });

    // 全画面中だけ背後のスクロールを止める。**解除は戻り値の 1 か所に集約**する
    // (終了ボタン / 縦復帰 / ページ離脱のどれでも必ず外れる = スクロール不能の詰みを作らない)。
    $effect(() => {
        if (!fullscreenActive) return;
        return lockBackgroundScroll();
    });
```

#### D-3. ハンドラ

```svelte
    /**
     * 全画面でのカット移動 (スワイプ / 前後ボタン / 左右矢印キーの共通の受け口)。
     * 可否と文言の判断は decideCutNavigation が 1 か所で持つ (ここは配線だけ)。
     * **録画中は移動せずその場でエラーを出す** — 自動停止しない (誤スワイプで録画を確定させない)。
     */
    function handleCutNavigate(direction: NavigationDirection): void {
        const decision = decideCutNavigation({
            captureActive,
            cuts: manual.cuts,
            currentCutId: selectedCutId,
            direction,
        });
        if (decision.kind === "move") {
            navigationNotice = null;
            selectedCutId = decision.cutId;
            return;
        }
        if (decision.kind === "notice") {
            navigationNotice = { tone: decision.tone, message: decision.message };
            return;
        }
        navigationNotice = null; // ignore: 移動対象が無い (全画面は selectedCut 非 null でのみ描くため通常到達しない)
    }

    /**
     * 全画面を終了する。横持ちのまま既存レイアウトへ戻るので、
     * **現在位置を見失わせない**よう視点とフォーカスを撮影パネルへ運ぶ (既存機構を再利用)。
     */
    function exitFullscreen(): void {
        fullscreenDismissed = true;
        navigationNotice = null;
        void tick().then(() => {
            updateStacked();
            navigateToPanelIfNeeded({
                captureActive,
                leftEl: leftPaneEl,
                rightEl: rightPaneEl,
                headingEl: recordingHeadingEl,
                reducedMotion: prefersReducedMotion(),
            });
        });
    }

    /**
     * 全画面へ戻る手動の再入路。ラッチ (fullscreenDismissed) を解除する。
     * これが無いと「端末を一度縦に倒し直さないと全画面へ帰れない」行き止まりになる。
     * 未選択なら先頭カットを選ぶ (押しても何も起きない、を作らない)。
     */
    function enterFullscreen(): void {
        const first = manual.cuts[0];
        if (selectedCutId === null && first !== undefined) selectedCutId = first.id;
        fullscreenDismissed = false;
    }
```

#### D-4. markup

```svelte
<AppLayout {appName}>
    <PageContainer>
        <!-- 全画面中は背後を inert にして、覆われた面へ Tab で入り込めないようにする -->
        <div inert={fullscreenActive}>
            <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
                …（既存の「一覧へ戻る」「マニュアル詳細へ」。無改変）…
            </PageHeaderSection>
        </div>

        <!-- UploadQueueBar は全画面かどうかで **どちらか一方にだけ** 置く
             (両方に置くと data-testid が重複してテストの指し先が曖昧になる)。
             UploadQueueBar は props だけの表示 component なので、
             切替時に作り直されても失われる状態が無い。 -->
        {#if !fullscreenActive}
            <div class="mt-3">
                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
            </div>
        {/if}

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
            <section
                bind:this={leftPaneEl}
                inert={fullscreenActive}
                class="min-w-0 rounded-md border border-border bg-surface"
                data-testid="capture-left-pane"
            >
                <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                    <h2
                        bind:this={cutListHeadingEl}
                        tabindex="-1"
                        class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                        data-testid="capture-cut-list-heading"
                    >
                        シナリオ (タップして撮影)
                    </h2>
                    <!-- 横持ちなのに全画面でないとき (= 明示終了した後) の再入路。
                         文脈非該当時は非表示にする (disabled ではない)。 -->
                    {#if landscapeMatches && !fullscreenActive}
                        <Button variant="neutral" size="sm" onclick={enterFullscreen} testId="enter-fullscreen-capture">
                            <Maximize class="size-4" aria-hidden="true" />
                            全画面で撮影
                        </Button>
                    {/if}
                </div>
                <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
            </section>

            <!--
              全画面は **この section の class を差し替えるだけ**で作る。
              CameraRecorder を別の {#if} ブランチへ移すと unmount され、録画中の
              MediaStream / MediaRecorder が破棄されて録ったデータが消えるため。
              fixed + h-dvh: iOS Safari の動的ツールバーで下端が隠れないようにする
              (inset-0 だと bottom がツールバー下へ潜りうる)。
              z-40: AppLayout のモバイルヘッダ (sticky z-30) を覆い、
              Toast (z-50) は上に残す (アップロード失敗の告知を隠さない)。
            -->
            <section
                bind:this={rightPaneEl}
                class={fullscreenActive
                    ? "fixed inset-x-0 top-0 z-40 flex h-dvh min-w-0 flex-col gap-2 bg-surface p-2"
                    : "flex min-w-0 flex-col gap-4"}
                data-testid="capture-right-pane"
                data-fullscreen={fullscreenActive ? "true" : "false"}
            >
                {#if fullscreenActive && selectedCut !== null}
                    <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
                    <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1">
                            <CutSwipeBar
                                label={cutLabels[selectedCut.id] ?? "選択中カット"}
                                scene={selectedCut.scene}
                                position={cutPosition}
                                onNavigate={handleCutNavigate}
                            />
                        </div>
                        <Button variant="neutral" size="sm" onclick={exitFullscreen} testId="exit-fullscreen-capture">
                            <Minimize class="size-4" aria-hidden="true" />
                            全画面を終了
                        </Button>
                    </div>
                    {#if navigationNotice !== null}
                        {#if navigationNotice.tone === "alert"}
                            <p class="text-caption text-danger" role="alert" data-testid="cut-navigation-error">
                                {navigationNotice.message}
                            </p>
                        {:else}
                            <p class="text-caption text-text-secondary" role="status" data-testid="cut-navigation-notice">
                                {navigationNotice.message}
                            </p>
                        {/if}
                    {/if}
                {/if}

                {#if selectedCut === null}
                    <p class="text-caption text-text-secondary">
                        左のシナリオからカットを選ぶと撮影パネルが開きます。
                    </p>
                {:else}
                    <!-- 全画面では見出し・ナレーション・テイク一覧を出さない
                         (撮影ガイドと字幕は映像上の overlay が担う)。
                         **CameraRecorder はこの {#if} を跨がない** = 位置が変わらない。 -->
                    {#if !fullscreenActive}
                        <div class="flex items-center justify-between gap-2">
                            <h2 bind:this={recordingHeadingEl} tabindex="-1" … data-testid="capture-recording-heading">
                                {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
                            </h2>
                            {#if stacked}
                                <TextLink onclick={backToCutList} testId="back-to-cut-list">カット一覧へ戻る</TextLink>
                            {/if}
                        </div>

                        <div class="rounded-md border border-border bg-surface p-3">
                            …（ナレーション / 撮影ポイント。無改変）…
                        </div>
                    {/if}

                    <!-- 全画面では残り高さいっぱいに広げる。**要素そのものは同じ** (class だけ変わる) -->
                    <div class={fullscreenActive ? "relative min-h-0 flex-1" : ""}>
                        {#if showRecorder}
                            <CameraRecorder
                                bind:this={recorderRef}
                                onCaptured={(blob, mimeType, durationMs) => handleCaptured(blob, mimeType, durationMs)}
                                onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
                                subtitlePrimary={selectedCut.subtitle_primary}
                                subtitleSecondary={selectedCut.subtitle_secondary}
                                onCaptureActiveChange={(active) => (captureActive = active)}
                                layout={fullscreenActive ? "fullscreen" : "inline"}
                                shootingPoint={selectedCut.shooting_point}
                            />
                        {:else}
                            …（fallbackNotice + CaptureFileFallback。無改変）…
                        {/if}
                    </div>

                    {#if !fullscreenActive}
                        <TakeStrip … 無改変 … />
                    {/if}
                {/if}
            </section>
        </div>
    </PageContainer>
</AppLayout>
```

### 設計上の不変条件 (実装者が壊してはいけないもの)

1. **`CameraRecorder` は `fullscreenActive` の `{#if}` を跨がない**。
   跨ぐと向き変更で unmount され、録画中の `MediaStream` / `MediaRecorder` / 累積タイマーが
   破棄されて**録ったデータが消える**。テスト (`CaptureShow.test.ts`) が
   切替前後の `camera-preview` 要素の同一性で固定する。
2. **`UploadQueueBar` は同時に 2 つ描かない** (`data-testid` の重複を作らない)。
3. **背景スクロール抑止の解除点は `lockBackgroundScroll()` の戻り値だけ**。
4. **告知文の出所は `landscape-capture.ts` の定数だけ** (page 内で文字列を組み立てない)。
5. **全画面から出る手段と入る手段が必ず対で存在する**
   (`exit-fullscreen-capture` / `enter-fullscreen-capture`)。

### PHPStan適合チェック

- [x] PHP の変更なし (走査根 `app` / `config` / `database` / `routes` は無変更 = level 10 の結果は不変)
- [x] Inertia Props (`project` / `manual`) を変えないので、サーバ側 DTO / JsonResource の
      型と UI の型がずれる余地が無い
- [x] `pnpm typecheck`: `navigationNotice` は判別可能な object union、`cutPosition` は
      `{ index: number; total: number }`、`handleCutNavigate` の引数は `NavigationDirection`
- [x] `manual.cuts[0]` / `cuts[index + direction]` は `undefined` チェックを経由する
      (`noUncheckedIndexedAccess` 相当の安全側。`!` を書かない)

### テスト計画

- [x] 既存 `tests/js/pages/CaptureShow.test.ts` に追記
      (`window.matchMedia` の stub を用意し、`matches` を切り替えられるようにする)
  - 横持ち条件が真になると `capture-right-pane` の `data-fullscreen` が `"true"` になる
  - **カット未選択でも先頭カットが自動選択され**、`cut-swipe-label` に `手順 1` が出る
  - **全画面切替の前後で `camera-preview` の DOM ノードが同一** (不変条件 1 の機械固定。
    `expect(before).toBe(after)` でノード同一性を見る)
  - 「次のカット」で `cut-swipe-label` が `手順 2` へ変わる
  - 末尾で「次のカット」を押すと `cut-navigation-notice` に「これが最後のカットです。」が出て
    ラベルが変わらない
  - `exit-fullscreen-capture` で `data-fullscreen` が `"false"` になり
    `enter-fullscreen-capture` が現れる。押すと再び `"true"` になる (ラッチと再入路)
  - 横持ち → 縦持ち → 横持ちでラッチが解除され、明示終了後でも再び全画面になる
  - **`upload-queue-bar` が同時に 2 つ存在しない** (不変条件 2。`getAllByTestId` の長さ ≤ 1)
  - 全画面中は `documentElement` に `overflow-hidden` が付き、終了で外れる (不変条件 3)
  - 全画面中は `take-strip-*` と `capture-recording-heading` が出ない
  - **録画中の抑止は本テストでは固定しない** — CI に実カメラが無く `captureActive` を
    真にできないため。抑止契約は `landscape-capture.test.ts` の `decideCutNavigation` が、
    ページ配線は「`captureActive` を `decideCutNavigation` へ渡していること」で担う
    (既存 `CaptureCutNavigationTest` が採っている **2 段構成**と同じ)
- [x] 新規 `tests/Browser/CaptureLandscapeFullscreenTest.php` (Chromium + WebKit の 2 レーン)
  - 横持ちスマホ viewport (`->on()->mobile()` = `hasTouch` かつ `isMobile` →
    `pointer: coarse`、その後 `->resize(844, 390)`) で
    `capture-right-pane[data-fullscreen="true"]` になる
  - `cut-swipe-next` / `cut-swipe-previous` で `cut-swipe-label` が
    `手順 1` ↔ `手順 2` と往復する
  - `exit-fullscreen-capture` → `data-fullscreen="false"` かつ
    `enter-fullscreen-capture` が可視 → 押すと `"true"` に戻る
  - **負のコントロール 3 本** (どれも `data-fullscreen="false"` を期待。
    条件が式から落ちる回帰を条件ごとに検出する):
    | # | context | viewport | 落ちたら検出できる条件 |
    |---|---|---|---|
    | 1 | `->on()->desktop()` | 1728×1117 | 全条件 (素の回帰) |
    | 2 | `->on()->mobile()` + `resize(1024, 900)` | 高さ 900 / coarse | `max-height` |
    | 3 | `->on()->desktop()` + `resize(844, 390)` | 高さ 390 / fine | `pointer: coarse` |
  - fixture は既存 `captureNavigationFixture()` と同じ作り
    (`createOrganizationWithOwner` + `contractPaidPlan` + Factory。
    **`Model::create()` の手組みをしない**。撮影 PWA は
    `require-active-subscription` group 内なので有料契約が要る)
  - `declare(strict_types=1)` を先頭に置く (`StrictTypesDeclarationGateTest`)
  - **WebKit レーンを落とさない** (`docs/testing-browser.md` / AGENTS.md ドメイン規約 3)
- [x] `RefreshDatabase` はグローバル適用済み。個別 `DatabaseTransactions` を書かない

### リスク

- **`inert` の対応**: iOS Safari 15.5 以降。未対応環境では覆われた面へ Tab で入り込めるが、
  全画面は不透明な `bg-surface` で覆われているので**情報は見えない**。
  操作を誤る可能性が残るだけで、機能の詰みにはならない。**実機受入確認の項目**に含める。
- **`h-dvh` の対応**: iOS Safari 15.4 以降 / Chrome 108 以降。
  未対応環境では高さが 0 になりうるため、`fixed inset-x-0 top-0` と併せて
  `h-dvh` が効かない場合の見え方を実機で確認する項目に含める。
- **`stacked` の測定**: 全画面中は `rightPaneEl` が `fixed` になるため
  `isStackedLayout()` の結果は意味を持たない。`stacked` を使う
  「カット一覧へ戻る」は `!fullscreenActive` の内側にしか無いので**影響しない**。
  ただし全画面終了直後は `tick()` の後に `updateStacked()` を呼び直す (上記 `exitFullscreen`)。
- **`enterFullscreen` の可視条件**: `landscapeMatches && !fullscreenActive` は
  「横持ちだが全画面でない」= 明示終了した後、またはカットが 0 件のとき。
  カット 0 件で押しても選択できるカットが無く何も起きないため、
  `manual.cuts.length === 0` のときは**そもそも撮影パネル自体が空の面**であり、
  既存の「左のシナリオからカットを選ぶと撮影パネルが開きます。」が出続ける
  (新しい詰みを作らない。この分岐は既存挙動のまま)。

---

## 施策 E: テスト一式

施策 A〜D の「テスト計画」に列挙したものが本施策の内容である。ファイル単位では:

| ファイル | 種別 | 新規/追記 |
|---|---|---|
| `tests/js/lib/capture/landscape-capture.test.ts` | vitest (純関数 + 副作用) | 新規 |
| `tests/js/components/features/capture/CutSwipeBar.test.ts` | vitest (component) | 新規 |
| `tests/js/components/features/capture/ShootingGuideOverlay.test.ts` | vitest (component) | 新規 |
| `tests/js/components/features/capture/CameraRecorder.test.ts` | vitest (component) | 追記 |
| `tests/js/pages/CaptureShow.test.ts` | vitest (ページ配線) | 追記 |
| `tests/Browser/CaptureLandscapeFullscreenTest.php` | Browser (Chromium + WebKit) | 新規 |

### テストファーストの順序 (思考原則 5)

1. `landscape-capture.test.ts` を書いて **fail を確認**してから施策 A を実装する
   (`LANDSCAPE_CAPTURE_MEDIA_QUERY` の 3 条件検査と `decideCutNavigation` の
   `captureActive` 優先評価が、実装前に落ちることを見る)。
2. component テスト → 施策 B / C。
3. ページ配線テスト → 施策 D。とくに**「切替前後で `camera-preview` が同一ノード」は
   実装前に落ちる形で先に書く** (`{#if}` で描き分ける素朴な実装なら必ず落ちる = 
   不変条件 1 の fail-first になる)。
4. Browser テスト → 最後 (実挙動の確認と負のコントロール)。

### 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
に加えて **`composer test:browser`** (Chromium + WebKit の 2 レーン)。

> テストレーンはホスト全体のグローバルロックで直列化される。**待ちが出るのは正常**で
> 30 秒ごとに heartbeat が出る。**kill しない / ロックファイルを消さない** (AGENTS.md)。

### リスク

- `CaptureShow.test.ts` で `window.matchMedia` を stub すると、
  同ファイル内の既存テスト (`prefersReducedMotion` が読む) に影響する。
  **stub は `beforeEach` で入れて `afterEach` で戻し**、既定は
  「`(prefers-reduced-motion: reduce)` は false / 横持ちは false」= 現行挙動と同じにする
  (既存テストを 1 件も書き換えないで済む形にする)。
- Browser テストの `resize()` 後は media query の再評価と Svelte の再描画を待つ必要がある。
  既存 `CaptureCutNavigationTest` の `waitUntilInViewport()` と同じく、
  **「目的の状態になったか」を上限付きで polling** する helper を書く
  (固定 sleep にしない = flaky を作らない)。

---

## 施策 F: 既存契約テキストの同期と保証範囲の明示

### 変更箇所

- `tests/js/architecture/page-shell-structure.test.ts` の `PAGECONTENT_ALLOWLIST`
  (`Capture/Show.svelte` の `reason`)
- `docs/supported-browsers.md` の「未対応事項 (誤読を防ぐため明示列挙する)」節

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: `page-shell-structure.test.ts` 自身 (reason 文字列の更新のみで、
  検査ロジックは変えない)

### 現行コード

```ts
/** PageContent 必須契約の除外 allowlist (PageContainer/PageHeader は必須)。追加は理由必須(reason 非空)。 */
const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Capture/Show.svelte",
        reason: "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。",
    },
];
```

### 変更後コード

```ts
const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Capture/Show.svelte",
        reason:
            "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。" +
            "横持ち時は撮影パネルが fixed の全画面へ切り替わるため、中央寄せの外枠を前提にできない。",
    },
];
```

`docs/supported-browsers.md` の「未対応事項」へ 1 項目を追加する
(**保証範囲を誇張しない**ための記載であり、確認の実施記録ではない):

```markdown
- **横持ち全画面の撮影 UI は、自動レーンでは DOM 契約と条件分岐だけを固定している**。
  Browser レーン (Chromium + WebKit) が固定するのは「横持ちスマホ相当の context で
  全画面へ切り替わること」「前後ボタンでカットが移動すること」「デスクトップ相当・
  高さ超過・細いポインタの 3 通りでは切り替わらないこと」までである。
  **実カメラを伴う挙動 (録画中に向きが変わったときの録画継続、CSS 全画面での
  カメラプレビューの見え方、iOS Safari の動的ツールバーと `h-dvh` の相互作用、
  端末の戻るジェスチャとスワイプの競合、`inert` 非対応環境でのフォーカス漏れ) は
  どちらのレーンでも再現していない**。これらは実機受入確認の対象である。
```

### PHPStan適合チェック

- [x] PHP の変更なし
- [x] `pnpm typecheck`: allowlist の型は `{ path: string; reason: string }` のまま

### テスト計画

- [x] `pnpm test` で `page-shell-structure.test.ts` が緑 (reason 非空の検査を満たす)
- [x] ドキュメント変更はテスト対象外だが、`verification-commands-doc-sync.test.ts` の
      対象マーカーには触れないことを確認する

### リスク

- `docs/supported-browsers.md` の「実機受入確認の再確認条件」節には**追記しない**。
  あの節は bfcache guard / パスキーの挙動変更を検知するトリガ一覧であり、
  撮影 UI のレイアウト変更を混ぜると**トリガの意味が薄まって不要な再確認を誘発する**
  (同節が「トリガは挙動変更に限る」と明記している)。

---

## 実機でしか確認できない項目 (この設計では TODO を起票しない)

`docs/supported-browsers.md` の「実機受入確認」の作法に倣い、**何を実機で確認する必要があるか**を
ここに残す。記録先は `devnotes/<日付>-<topic>/` に日時・端末・OS バージョン・結果を書く運用で、
**本書にも `docs/supported-browsers.md` にも「いつ・何を確認したか」は書かない**
(記録の二重管理を作らない)。

| # | 確認項目 | なぜ自動レーンで確認できないか |
|---|---|---|
| 1 | iPhone Safari (ブラウザタブ) で横に倒すと全画面になり、アドレスバーの伸縮で `h-dvh` の高さがガタつかない | Playwright WebKit ≠ 実機 iOS Safari。動的ツールバーの挙動を持たない |
| 2 | PWA standalone (ホーム画面から起動) で 1 と同じ結果になる | standalone モードを自動レーンで再現できない (`docs/supported-browsers.md` の既知の非対称) |
| 3 | **録画中に端末を横↔縦へ倒しても録画が継続し、停止で 1 本のテイクとして保存される** | CI に実カメラが無い。`getUserMedia` / `MediaRecorder` が動かない |
| 4 | 全画面のカメラプレビューが `object-cover` で歪まず、被写体が意図どおり入る | 同上 |
| 5 | 操作行 (`absolute bottom-0`) と字幕・撮影ガイドの overlay が、明るい被写体の上でも読める | 同上 (jsdom / Playwright はレイアウトと実映像を持たない) |
| 6 | 画面端から始めたスワイプが**端末の戻るジェスチャに譲り**、カットが動かない | iOS の system gesture は Playwright で再現できない |
| 7 | 手袋着用・片手操作でスワイプのしきい値 (48px / 縦ブレ 0.6) が実用的か | 実利用者の操作特性は自動レーンで測れない |
| 8 | `inert` 非対応環境 (iOS Safari 15.4 以前) で背後へフォーカスが漏れても操作を誤らないか | 対象 OS バージョンの実機が要る |
| 9 | タッチ対応 Windows ノート PC で**既存 2 カラムのまま**であること | Browser の負のコントロール 3 で条件は固定するが、実機の `pointer` 報告値は環境依存 |
| 10 | Android Chrome 横持ちでの 1〜7 (`docs/supported-browsers.md` の Target に既出の未着手項目) | 同上 |

---

## 使命・禁止事項の最終チェック

| 観点 | 確認 |
|---|---|
| 使命への寄与 | 横持ちで「向ける・録る・次へ」を同一画面で完結させ、撮影以外の操作負荷を減らす。ナビ撮影という中核価値の実効性を上げる |
| 禁止事項 1 (テストなし完了) | 施策 A〜D すべてにテストを対応させ、不変条件 1〜5 を機械固定する |
| 禁止事項 2 (PHPStan widen/baseline) | **PHP の変更が 1 行も無い**。`@phpstan-ignore` も baseline も足さない |
| 禁止事項 4 (`response()->json()`) | サーバ側の変更なし。新しい endpoint を作らない |
| 禁止事項 5/6 (LLM 経路 / prompt 直書き) | LLM に触れない |
| 禁止事項 8 (disabled UI) | 端の前後ボタンも録画中の移動も `disabled` にせず、押下時に告知する。`CutSwipeBar.test.ts` が `toBeDisabled()` の否定で機械固定する |
| 禁止事項 3 (dev DB 破壊操作) | DB に触れない。Browser テストは `RefreshDatabase` のグローバル適用に乗る |
| セキュリティ不変条件 | 認可・テナント境界・payload キー・cache・throttle・課金のいずれにも触れない。表示層のみ |
| ドメイン規約 3 (3 枚セット) | 新しいログアウト導線も非 Inertia 経路も作らない。`bfcache-guard` / `session.status` / Inertia 履歴暗号化に触れない = 再確認条件のトリガに当たらない |
| ドメイン規約 3 (Browser 2 レーン) | Chromium + WebKit の両方で走らせる。WebKit を落とさない |
| DESIGN.md 準拠 | color / radius / typography はすべて token 経由。hex 直書きなし。`z-40` / `z-10` は ramp 内。静的 inline style なし。アイコンは `@lucide/svelte` のみ |
| Atomic Design 準拠 | 新規 2 component は `features/capture` に置き、`atoms` (`Button`) / 既存 `features/capture` のみを import する (逆流・domain 間横参照なし) |
| 思考原則 2 (今必要なものだけ) | 全画面 API 経路を作らない / テイクサムネイルの即再生をスコープ外にする / 向きロックを追わない |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 施策 A → B/C → D → E → F の順で、各段が前段のテスト green を前提に積み上がる。とくに施策 D の不変条件 1 (`CameraRecorder` を remount しない) は、施策 A/C が先に入っていないと fail-first の形で書けない。また変更対象は `resources/js` の 5 ファイル + テスト 6 ファイルに閉じており、途中でマージしても他機能を壊さない (サーバ側 0 変更)。standalone にすると 1 コミットが巨大になり、`camera-preview` 同一性のような繊細な不変条件のレビュー粒度が落ちる |
| 競合リスク | **`resources/js/pages/Capture/Show.svelte` と `CameraRecorder.svelte` を同時に触る他タスクとは衝突する**。とくに撮影 UI の他改修 (テイク操作・カメラ機能) が並行していると markup の同じ範囲を書き換える。マージ順を決めて逐次に入れる。`tests/js/pages/CaptureShow.test.ts` も同様。逆に `lib/capture/landscape-capture.ts` と新規 2 component は新規ファイルのみで競合しない |


---

## 関連する現行コード

### `resources/js/pages/Capture/Show.svelte`

```
<script lang="ts">
    import { onMount, tick } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, BookOpen, Video } from "@lucide/svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
    import type CameraRecorderType from "@/components/features/capture/CameraRecorder.svelte";
    import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
    import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
    import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
    import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { AdoptedTakeAutoDownloader } from "@/lib/capture/auto-download";
    import { supportsMediaRecorder } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";
    import { buildCutLabels } from "@/lib/capture/cut-labels";
    import {
        isStackedLayout,
        navigateBackToList,
        navigateToPanelIfNeeded,
        prefersReducedMotion,
    } from "@/lib/capture/panel-navigation";
    import { createIdbPendingStore } from "@/lib/capture/idb";
    import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
    import type { PendingStore } from "@/lib/capture/upload-queue";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CaptureManualDetail } from "@/types/capture";

    /**
     * 撮影ナビ (doc/05 / 概念設計 D9)。cut を選び、録画 (または ファイル選択) →
     * 即時アップロード (upload-url → S3 PUT → POST takes)。失敗/オフラインは IndexedDB に
     * 一時保持し、フォアグラウンド復帰 / online / SW message で再送する。
     */
    interface Props {
        project: { id: number; name: string };
        manual: CaptureManualDetail;
    }

    let { project, manual }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let selectedCutId = $state<number | null>(null);
    const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
    /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
    const cutLabels = $derived(buildCutLabels(manual.cuts));
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

    /* ---- 採用済みテイクの自動 DL (T051) ----
     * project.id / manual.id はインスタンス生存中は安定 (別 manual へ遷移すると Inertia が
     * ページを remount する。reload({only:["manual"]}) は id を変えない)。mount 時点の値で
     * 確定させるのが意図どおりなので state_referenced_locally を明示的に無視する。 */
    // svelte-ignore state_referenced_locally
    const autoDownloader = new AdoptedTakeAutoDownloader(project.id, manual.id);
    let pendingCount = $state(0);
    let pendingBytes = $state(0);
    let uploading = $state(false);
    let quotaMessage = $state<string | null>(null);

    async function refreshPending(): Promise<void> {
        const items = await store.list();
        pendingCount = items.length;
        pendingBytes = items.reduce((sum, item) => sum + item.blob.size, 0);
        quotaMessage = queue.quotaMessage;
    }

    function reloadManual(): void {
        router.reload({ only: ["manual"] });
    }

    /* ---- 撮影パネルへの視点/フォーカス移送 (F-1-03) ----
     * 1 カラム表示ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットをタップしても
     * 撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
     * 判定と副作用は lib/capture/panel-navigation.ts が持つ (page は配線だけ)。 */
    let leftPaneEl = $state<HTMLElement | null>(null);
    let rightPaneEl = $state<HTMLElement | null>(null);
    let recordingHeadingEl = $state<HTMLElement | null>(null);
    let cutListHeadingEl = $state<HTMLElement | null>(null);
    /** 縦積みか (= 1 カラム)。「カット一覧へ戻る」の出し分けに使う */
    let stacked = $state(false);

    function updateStacked(): void {
        if (leftPaneEl === null || rightPaneEl === null) return;
        stacked = isStackedLayout(
            leftPaneEl.getBoundingClientRect(),
            rightPaneEl.getBoundingClientRect(),
        );
    }

    function handleSelectCut(cutId: number): void {
        selectedCutId = cutId;
        // DOM 反映後に測る (撮影パネルは選択で初めて描画される)
        void tick().then(() => {
            updateStacked();
            navigateToPanelIfNeeded({
                captureActive,
                leftEl: leftPaneEl,
                rightEl: rightPaneEl,
                headingEl: recordingHeadingEl,
                reducedMotion: prefersReducedMotion(),
            });
        });
    }

    /** 視点で運んだ以上、帰り道も用意する (行き先のない詰みを作らない) */
    function backToCutList(): void {
        navigateBackToList(cutListHeadingEl, prefersReducedMotion());
    }

    $effect(() => {
        if (leftPaneEl === null || rightPaneEl === null) return;
        // observer の初回 callback はタイミング差があるため当てにせず、登録前に必ず 1 回測る
        updateStacked();
        if (typeof ResizeObserver === "undefined") return;
        const observer = new ResizeObserver(() => updateStacked());
        observer.observe(leftPaneEl);
        observer.observe(rightPaneEl);
        return () => observer.disconnect();
    });

    async function handleCaptured(blob: Blob, mimeType: string, durationMs: number | null): Promise<void> {
        if (selectedCutId === null) return;
        uploading = true;
        try {
            const outcome = await queue.enqueue({
                clientTakeId: generateClientTakeId(),
                projectId: project.id,
                manualId: manual.id,
                cutId: selectedCutId,
                blob,
                contentType: mimeType.split(";")[0],
                durationMs,
                capturedAt: new Date().toISOString(),
            });
            if (outcome.status === "uploaded") {
                reloadManual();
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    // 入室時 / online 復帰時に採用済み未 DL テイクを自動取得する。changed のときのみ
    // reload を 1 回行う (複数採用テイクでも reload は 1 回)。多重発火は内部 running ガードが抑止。
    // reload 後は downloaded=true で対象が空になるため再 DL は起きない (冪等)。
    async function runAutoDownload(): Promise<void> {
        const { changed } = await autoDownloader.run(manual);
        if (changed) reloadManual();
    }

    async function resumeUploads(): Promise<void> {
        uploading = true;
        try {
            const outcomes = await queue.resume();
            if (outcomes.some((outcome) => outcome.status === "uploaded")) {
                reloadManual();
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    onMount(() => {
        void refreshPending();
        void runAutoDownload();

        // SW 登録 (Capture ページ mount 時に限定。素の JS・/build/* のみキャッシュ)
        if ("serviceWorker" in navigator) {
            void navigator.serviceWorker.register("/capture-sw.js");
            navigator.serviceWorker.addEventListener("message", handleSwMessage);
        }
        // フォアグラウンド復帰 / online でキュー再開 (Background Sync 非依存。概念設計 D9)
        document.addEventListener("visibilitychange", handleVisibility);
        window.addEventListener("online", handleOnline);

        return () => {
            document.removeEventListener("visibilitychange", handleVisibility);
            window.removeEventListener("online", handleOnline);
            if ("serviceWorker" in navigator) {
                navigator.serviceWorker.removeEventListener("message", handleSwMessage);
            }
        };
    });

    function handleVisibility(): void {
        if (document.visibilityState === "visible") void resumeUploads();
    }

    function handleOnline(): void {
        // resumeUploads と runAutoDownload は独立・順序非依存 (将来回帰防止のため明記)
        void resumeUploads();
        void runAutoDownload();
    }

    function handleSwMessage(event: MessageEvent): void {
        if (event.data === "resume-uploads") void resumeUploads();
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
            <TextLink href={`/app/projects/${project.id}/manuals`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                一覧へ戻る
            </TextLink>
            <!-- PC 側詳細への復路 (T155)。**この画面へ到達できた利用者に対しては、追加の
                 status / ability 条件で出し分けない**。根拠と保証範囲は
                 docs/architecture.md §撮影 PWA の運用契約。 -->
            <TextLink
                href={`/projects/${project.id}/manuals/${manual.id}`}
                testId="manual-detail-link"
            >
                <BookOpen class="inline size-3" aria-hidden="true" />
                マニュアル詳細へ
            </TextLink>
        </PageHeaderSection>

        <div class="mt-3">
        <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section
            bind:this={leftPaneEl}
            class="min-w-0 rounded-md border border-border bg-surface"
            data-testid="capture-left-pane"
        >
            <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
                 フォーカス可能にする (Tab 順には入れない)。 -->
            <h2
                bind:this={cutListHeadingEl}
                tabindex="-1"
                class="border-b border-border px-3 py-2 text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                data-testid="capture-cut-list-heading"
            >
                シナリオ (タップして撮影)
            </h2>
            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
        </section>

        <section
            bind:this={rightPaneEl}
            class="flex min-w-0 flex-col gap-4"
            data-testid="capture-right-pane"
        >
            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">
                    左のシナリオからカットを選ぶと撮影パネルが開きます。
                </p>
            {:else}
                <div class="flex items-center justify-between gap-2">
                    <!-- カット選択時のフォーカス着地点。ラベルを含めて「どのカットの撮影か」を
                         名前で伝える (視点だけ運んでフォーカスを残すと a11y 欠落を作るため)。 -->
                    <h2
                        bind:this={recordingHeadingEl}
                        tabindex="-1"
                        class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                        data-testid="capture-recording-heading"
                    >
                        {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
                    </h2>
                    {#if stacked}
                        <!-- 1 カラムのときだけ出す (2 カラムでは一覧が常に見えているので不要)。
                             TextLink のボタンモード (href なし + onclick) = <button type="button">。 -->
                        <TextLink onclick={backToCutList} testId="back-to-cut-list">
                            カット一覧へ戻る
                        </TextLink>
                    {/if}
                </div>

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
                    cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
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
### `resources/js/components/features/capture/CameraRecorder.svelte`

```
<script lang="ts">
    import { onDestroy } from "svelte";
    import {
        Captions,
        CaptionsOff,
        Circle,
        Grid3x3,
        Pause,
        Play,
        Square,
        SwitchCamera,
        Timer,
    } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import GridOverlay from "@/components/features/capture/GridOverlay.svelte";
    import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
    import {
        classifyGetUserMediaError,
        formatElapsed,
        nextFacingMode,
        preferredRecordingMimeType,
        supportsPauseResume,
        videoConstraints,
    } from "@/lib/capture/camera";
    import type {
        CameraErrorClassification,
        CameraUnavailableReason,
        FacingMode,
    } from "@/lib/capture/camera";
    import type { CaptureCut } from "@/types/capture";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
     * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
     * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
     *
     * 撮影 active の phase マシン (T050 / S4): idle / recording / paused / stopping。
     * 外部へ公開する排他状態 active は **starting || resuming || phase !== "idle"**。
     * getUserMedia grant 待ちの 2 窓 (録画開始 = starting / preview 復帰 = resuming) も active に
     * 含めることで、取得中でも親の captureActive が true になり preview が開けない
     * (preview と MediaRecorder の同居・stream 二重取得を根本から防ぐ。Codex R2/R3-S4)。
     * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件が一致する。
     *
     * T056 (capture-ux-enrichment): 録画タイマー / 一時停止・再開 / グリッド / カメラ反転を追加。
     * paused も非 idle のため active=true を維持し preview 排他を保つ。
     */
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
        /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
        subtitlePrimary?: CaptureCut["subtitle_primary"];
        subtitleSecondary?: CaptureCut["subtitle_secondary"];
        /** 撮影 active (starting || resuming || phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
        onCaptureActiveChange?: (active: boolean) => void;
    }

    let {
        onCaptured,
        onCameraUnavailable,
        subtitlePrimary = null,
        subtitleSecondary = "",
        onCaptureActiveChange,
    }: Props = $props();

    // 単一ソース union (R2 反映: paused を追加)
    type Phase = "idle" | "recording" | "paused" | "stopping";

    // 字幕オーバーレイの表示トグル (doc/05 §5.2)。v1 中核価値が字幕のため既定 ON。
    let showSubtitles = $state(true);
    const subtitleToggleLabel = $derived(showSubtitles ? "字幕を非表示" : "字幕を表示");

    // グリッド overlay の表示トグル。字幕と違い構図補助は任意のため既定 OFF (doc/05 §5.2)。
    let showGrid = $state(false);
    const gridToggleLabel = $derived(showGrid ? "グリッドを非表示" : "グリッドを表示");

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let phase = $state<Phase>("idle");
    let error = $state<string | null>(null);
    /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
    let starting = false;
    /** 直近に外部通知した active 値 (starting || resuming || phase !== "idle" の変化検出用) */
    let lastActive = false;
    /** preview 解放前に live だったか (復帰要否) */
    let wasActiveBeforePreview = false;
    /** resumeAfterPreview の再入ガード (多重 close/open で getUserMedia を二重発火させない) */
    let resuming = false;
    let resumePromise: Promise<void> | null = null;

    // --- 一時停止/再開 (S4)。イベント基準・in-flight ガード・タイムアウト復旧 ---
    // pause/resume 要求の in-flight ガード。**boolean ではなく操作種別を保持** する (R3-2 反映):
    // stale な onpause が進行中の resume の pending を誤って解除する事故を防ぐため、
    // 一致する操作のイベント/タイムアウトのみが pending を解除する。
    type PauseResumeOperation = "pause" | "resume";
    let pendingOperation: PauseResumeOperation | null = null;
    // pause/resume イベント未到達検出のタイムアウト handle (R3-S)
    let pauseResumeTimeout: ReturnType<typeof setTimeout> | null = null;
    // 能力検査は module 初期化時に一度評価 (ボタンの出し分けに使う)
    const canPauseResume = supportsPauseResume();

    // --- 録画タイマー (S3)。performance.now() 累積・pause 対応 ---
    let elapsedMs = $state(0);
    let accumulatedMs = 0; // pause で確定した累積 (performance.now ベース)
    let segmentStart = 0; // 現 recording 区間の開始 (performance.now())
    let timerHandle: ReturnType<typeof setInterval> | null = null;
    const elapsedLabel = $derived(formatElapsed(elapsedMs));
    const showTimer = $derived(phase === "recording" || phase === "paused");

    // --- カメラ反転 (S6)。idle 時のみ・段階的縮退 ---
    let facingMode = $state<FacingMode>("environment");
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

    // phase 遷移は単一 setter を通す。active 通知は syncActive に一元化する。
    function setPhase(next: Phase): void {
        phase = next;
        syncActive();
    }

    // --- 録画タイマー関数群 (S3) ---
    // recording 区間の計測開始 (start / resume で呼ぶ)
    function startTimer(): void {
        if (timerHandle !== null) return; // 二重起動防止
        segmentStart = performance.now();
        timerHandle = setInterval(() => {
            elapsedMs = accumulatedMs + (performance.now() - segmentStart);
        }, 200);
    }
    // 計測停止 + 累積確定 (pause / stop / idle / destroy で呼ぶ)
    function stopTimer(): void {
        if (timerHandle !== null) {
            accumulatedMs += performance.now() - segmentStart;
            clearInterval(timerHandle);
            timerHandle = null;
        }
        elapsedMs = accumulatedMs;
    }
    function resetTimer(): void {
        if (timerHandle !== null) {
            clearInterval(timerHandle);
            timerHandle = null;
        }
        accumulatedMs = 0;
        segmentStart = 0;
        elapsedMs = 0;
    }
    // 実録画尺 (durationMs 用)。累積 + 現区間の経過 (recording 中に stop されたケース)。
    // R1-S: Math.max(0, …) で明示クランプ (防御的。performance.now 単調増加のため通常は非負)。
    function recordedDurationMs(): number {
        const raw =
            timerHandle !== null ? accumulatedMs + (performance.now() - segmentStart) : accumulatedMs;
        return Math.max(0, raw);
    }

    // 副作用なしの取得 (classify 結果を返すだけ。onCameraUnavailable/error を呼ばない)。
    // 呼び出し前に stream=null であること (reacquire 前は releaseCamera 済み)。stream ??= のため
    // 既存 stream があれば再取得しない = flip の reacquire では releaseCamera() 後に呼ぶ。
    async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                // 呼出時点の facingMode を渡す (reacquireWithFacing が代入した直後の値を読む)。
                // キャッシュ禁止 — キャッシュすると flip 後も旧カメラで取得してしまう。
                video: videoConstraints(facingMode),
                audio: true,
            });
        } catch (cause) {
            return classifyGetUserMediaError(cause);
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        return { kind: "ok" };
    }

    // classify 失敗に既存の副作用ポリシーを適用 (transient→error / unavailable→F-03 委譲)。
    function applyAcquireFailure(result: CameraErrorClassification): void {
        if (result.kind === "transient") {
            error =
                "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
            return;
        }
        onCameraUnavailable(result.reason);
    }

    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
    // 既存契約を維持するラッパ (startRecording / resumeAfterPreview は無改変で呼べる)。
    async function acquirePreviewStream(): Promise<boolean> {
        const result = await acquireStream();
        if (result.kind === "ok") return true;
        applyAcquireFailure(result);
        return false;
    }

    async function startRecording(): Promise<void> {
        // 再入防止 (アーリーリターン。規約: disabled 禁止)。preview 復帰の取得中 (resuming) も拒否
        // し getUserMedia 二重取得を防ぐ。
        if (starting || resuming || phase !== "idle") return;
        starting = true;
        syncActive(); // 開始押下時点で active=true (grant 窓でも preview を開けない)
        try {
            error = null;
            const mimeType = preferredRecordingMimeType();
            if (mimeType === null) {
                // 恒久系: ローカル表示はせず親へ委譲 (責務の二重化回避)
                onCameraUnavailable("mime_unsupported");
                return;
            }
            const acquired = await acquirePreviewStream();
            if (!acquired) return;
            if (stream === null) return; // 型絞り込み (acquired=true なら実質非 null)
            chunks = [];
            try {
                recorder = new MediaRecorder(stream, { mimeType });
            } catch {
                // NotSupportedError 等: 取得済み stream を解放してからフォールバックへ
                releaseCamera();
                onCameraUnavailable("recorder_unsupported");
                return;
            }
            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) chunks.push(event.data);
            };
            // 一時停止/再開はイベント基準で phase を確定する (S4)。要求ハンドラは phase を
            // 同期で動かさず、MediaRecorder の onpause/onresume 到達で初めて遷移する。
            // R2-Critical: タイマー操作は phase 条件の内側で行う。stale な onpause/onresume が
            // stopping/idle で到着しても timer を触らない (durationMs 汚染・interval リークを防ぐ)。
            recorder.onpause = () => {
                if (pendingOperation === "pause") clearPauseResumePending(); // 一致操作のみ解除 (R3-2)
                if (phase !== "recording") return; // stale なら timer/phase を触らない
                stopTimer(); // 経過計測を止める (累積は保持)
                setPhase("paused");
            };
            recorder.onresume = () => {
                if (pendingOperation === "resume") clearPauseResumePending(); // 一致操作のみ解除 (R3-2)
                if (phase !== "paused") return; // stale なら timer/phase を触らない
                startTimer(); // 経過計測を再開 (累積へ加算)
                setPhase("recording");
            };
            // 唯一の正常終了点 (idle への遷移)。onCaptured の reject/throw でも終了通知を保証する。
            recorder.onstop = async () => {
                // 実録画尺 (pause 区間を除外)。resetTimer 前に確定する。
                const durationMs = recordedDurationMs();
                try {
                    const blob = new Blob(chunks, { type: mimeType });
                    if (blob.size > 0) {
                        await onCaptured(blob, mimeType, durationMs);
                    }
                } catch {
                    // 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない)
                    error = "撮影データの処理に失敗しました。もう一度お試しください。";
                } finally {
                    resetTimer(); // idle 到達時の interval リーク防止
                    clearPauseResumePending();
                    setPhase("idle");
                }
            };
            recorder.onerror = () => safeStop();
            stream.getTracks().forEach((track) => {
                track.onended = () => safeStop();
            });
            resetTimer();
            startTimer(); // 従来の startedAt = Date.now() を置換 (R1: 実録画尺へ是正)
            try {
                recorder.start();
            } catch {
                // start() の InvalidStateError 等 (UA 差異・状態競合)。構築成功後でも
                // 詰ませないため stream を解放してフォールバックへ倒す (§10.8-3)
                recorder = null;
                resetTimer();
                releaseCamera();
                onCameraUnavailable("recorder_unsupported");
                return;
            }
            setPhase("recording");
        } finally {
            starting = false;
            // 開始成功時: phase=recording のため active は true 維持 (重複通知しない)。
            // 開始失敗/恒久失敗時: phase=idle のため active=false へ戻す。
            syncActive();
        }
    }

    // 一時停止要求 (recording のみ)。pending 中 (種別を問わず) は多重押下ガードで拒否。
    function requestPause(): void {
        if (phase !== "recording" || pendingOperation !== null || recorder === null) return;
        if (!canPauseResume) return; // 未対応端末はボタン非表示のため通常到達しない (ボタン出し分けと同一判定)
        pendingOperation = "pause";
        armPauseResumeTimeout("pause");
        try {
            recorder.pause();
        } catch {
            // InvalidStateError 等: pending を解除し recorder.state から phase を復旧
            clearPauseResumePending();
            recoverPhaseFromRecorderState();
        }
    }

    // 録画再開要求 (paused のみ)
    function requestResume(): void {
        if (phase !== "paused" || pendingOperation !== null || recorder === null) return;
        pendingOperation = "resume";
        armPauseResumeTimeout("resume");
        try {
            recorder.resume();
        } catch {
            clearPauseResumePending();
            recoverPhaseFromRecorderState();
        }
    }

    // イベント未到達の保険 (R3-S: 解除条件 = onpause/onresume/onerror/onstop/タイムアウト)。
    // 操作種別を渡し、**古いタイムアウトが後続操作の pending/handle を奪わない** よう二重防御する:
    //  (1) handle 自己同定: 遅延実行された古い callback は `pauseResumeTimeout !== handle` で
    //      早期 return し、新しい timeout の handle を null 化しない (R4-2 の handle 喪失防止)。
    //  (2) 操作種別一致: 自分の操作がまだ pending のときだけ pending を解除して復旧する。
    function armPauseResumeTimeout(op: PauseResumeOperation): void {
        clearPauseResumeTimeout();
        const handle: ReturnType<typeof setTimeout> = setTimeout(() => {
            if (pauseResumeTimeout !== handle) return; // 古い callback は新 handle を触らない (R4-2)
            pauseResumeTimeout = null;
            if (pendingOperation !== op) return; // 自分の操作が解決/交代済みなら何もしない
            pendingOperation = null;
            recoverPhaseFromRecorderState(); // 遅延イベントが来ても phase は state 同期のみ
        }, 2000);
        pauseResumeTimeout = handle;
    }
    function clearPauseResumeTimeout(): void {
        if (pauseResumeTimeout !== null) {
            clearTimeout(pauseResumeTimeout);
            pauseResumeTimeout = null;
        }
    }
    function clearPauseResumePending(): void {
        pendingOperation = null;
        clearPauseResumeTimeout();
    }

    // recorder.state を真実源に UI phase を同期 (stopping 中は onstop に委ねるため触らない)
    function recoverPhaseFromRecorderState(): void {
        if (recorder === null || phase === "stopping") return;
        const state = recorder.state; // "inactive" | "recording" | "paused"
        if (state === "inactive") {
            // R1-W フェイルセーフ: recording/paused 中に recorder が inactive (onstop 永久未達 UA
            // バグ等の異常系) を検出したら、復帰不能を防ぐため fatalStopCleanup で idle 復帰 + 資源解放。
            fatalStopCleanup();
            return;
        }
        const nextPhase: Phase = state === "paused" ? "paused" : "recording";
        if (state === "paused") stopTimer();
        else startTimer();
        if (phase !== nextPhase) setPhase(nextPhase);
    }

    // 安全停止 (多重呼び出しガード)。recording/paused 以外では no-op (stopping/idle で重複 stop しない)。
    // paused からも停止できる必要がある (recorder.onerror は paused 中にも発火し得るため。R1-Critical)。
    function safeStop(): void {
        if (phase !== "recording" && phase !== "paused") return;
        clearPauseResumePending();
        setPhase("stopping"); // active は true のまま維持 (idle 遷移で初めて false)
        if (recorder === null) {
            fatalStopCleanup(); // 不整合: stopping 固定を防ぐ
            return;
        }
        try {
            recorder.stop(); // paused 状態でも stop() は onstop を発火し blob 確定
        } catch {
            fatalStopCleanup(); // 停止不能時: UI 復旧不能を防ぐ
        }
    }

    // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
    function fatalStopCleanup(): void {
        resetTimer();
        clearPauseResumePending();
        setPhase("idle");
        releaseCamera();
        onCameraUnavailable("recorder_unsupported");
    }

    function releaseCamera(): void {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    // --- カメラ反転 (S6)。idle 時のみ機能。段階的縮退 (R2/R3) ---
    async function flipCamera(): Promise<void> {
        // idle 以外・取得中・flip 中は no-op (録画中の stream 再取得を避ける)
        if (starting || resuming || flipping || phase !== "idle") return;
        const target = nextFacingMode(facingMode);

        // live stream 未保持 (録画前): state 更新のみ、次回 getUserMedia に反映
        if (stream === null) {
            facingMode = target;
            return;
        }
        flipping = true;
        try {
            error = null;
            const track = stream.getVideoTracks()[0] ?? null;
            // 段階1: applyConstraints({exact}) + getSettings 検証 (同一 stream 維持)
            if (track !== null && (await tryApplyFacing(track, target))) {
                facingMode = target;
                return;
            }
            // 段階2〜4: 再取得 (旧停止 → 新取得 → 失敗時旧復旧 → 完全喪失で classify)
            await reacquireWithFacing(target);
        } finally {
            flipping = false;
        }
    }

    // 段階1: exact 制約を適用し getSettings で実切替を検証 (R3: resolve≠実切替)
    async function tryApplyFacing(track: MediaStreamTrack, target: FacingMode): Promise<boolean> {
        try {
            await track.applyConstraints({ facingMode: { exact: target } });
        } catch {
            return false;
        }
        // R1-W: getSettings().facingMode が undefined の端末は「未検証扱い」で false を返し
        // 再取得経路 (段階2〜) へ倒す (安全側。誤って同一 stream 維持で切替失敗を隠さない)。
        const applied = track.getSettings().facingMode;
        return applied === target;
    }

    // 段階2〜4: 旧 stream 停止 → 新取得 → 失敗時旧復旧 → 完全喪失で初めて副作用 (R3 + R1-critical)
    // 副作用なしの acquireStream() を使い、onCameraUnavailable(F-03)/error の発火を段階4 まで遅延する。
    async function reacquireWithFacing(target: FacingMode): Promise<void> {
        const previous = facingMode;
        releaseCamera(); // 旧 stream 停止 (二重取得不可端末に対応。stream=null になる)
        facingMode = target;
        const forward = await acquireStream(); // 段階2: 副作用なし取得
        if (forward.kind === "ok") return;
        // 段階3: 旧 facingMode で再取得して復旧 (flip 断念・元カメラ継続。onCameraUnavailable は呼ばない)
        facingMode = previous;
        const back = await acquireStream();
        if (back.kind === "ok") {
            error = "カメラを切り替えられませんでした。";
            return;
        }
        // 段階4: 両カメラ喪失。段階3 の classify(back) に対してのみ副作用を適用
        // (transient→error 表示 / unavailable→onCameraUnavailable(F-03) 委譲)。
        applyAcquireFailure(back);
    }

    // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)。
    // 取得中 (starting: 録画開始 / resuming: preview 復帰) も拒否し、取得中の stream を横から
    // 解放しない (Codex R1/R3-S4)。
    export function releaseForPreview(): void {
        if (starting || resuming || phase !== "idle") return; // recording/stopping/取得中で解放拒否
        wasActiveBeforePreview = stream !== null; // 復帰要否を記録
        releaseCamera();
    }

    // preview close 後に呼ばれる。解放前に live だった時のみ再取得。多重 close/open を再入防止。
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

    onDestroy(() => {
        resetTimer();
        clearPauseResumeTimeout();
        releaseCamera();
    });
</script>

<div class="flex flex-col gap-3">
    <div class="relative">
        <!-- svelte-ignore a11y_media_has_caption -->
        <video
            bind:this={video}
            autoplay
            playsinline
            muted
            class="aspect-video w-full rounded-md bg-surface object-cover"
            data-testid="camera-preview"
        ></video>
        <!-- overlay の z 順 (DOM 順で映像 < grid < 字幕帯): グリッドは字幕より先 = 下層 -->
        <GridOverlay visible={showGrid} />
        <SubtitleOverlay
            primary={subtitlePrimary}
            secondary={subtitleSecondary}
            visible={showSubtitles}
        />
        {#if showTimer}
            <!-- 録画タイマー (overlay 右上)。recording/paused 時のみ -->
            <div
                class="pointer-events-none absolute top-2 right-2 flex items-center gap-1 rounded-sm bg-text/70 px-2 py-1 text-caption text-surface"
                data-testid="record-timer"
            >
                <Timer class="size-3.5" aria-hidden="true" />
                <span aria-live="off">{elapsedLabel}</span>
                {#if phase === "paused"}<span class="sr-only">一時停止中</span>{/if}
            </div>
        {/if}
    </div>
    <div class="flex items-center justify-center gap-3">
        {#if phase === "idle"}
            <Button variant="primary" onclick={startRecording} testId="start-recording">
                <Circle class="size-4" aria-hidden="true" />
                録画開始
            </Button>
            <!-- カメラ反転 (idle のみ表示 = 文脈非該当時は非表示。disabled ではない) -->
            <button
                type="button"
                class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                aria-label="カメラを切り替え"
                onclick={flipCamera}
                data-testid="flip-camera"
            >
                <SwitchCamera class="size-5" aria-hidden="true" />
            </button>
        {:else}
            <!-- recording / paused / stopping 共通: 停止ボタンは常時可視 (stopping では no-op) -->
            {#if phase === "recording" && canPauseResume}
                <!-- 一時停止 (supportsPauseResume() 時のみ表示。未対応は非表示で start/stop のみ) -->
                <button
                    type="button"
                    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                    aria-label="一時停止"
                    onclick={requestPause}
                    data-testid="pause-recording"
                >
                    <Pause class="size-5" aria-hidden="true" />
                </button>
            {:else if phase === "paused"}
                <!-- 録画再開 -->
                <button
                    type="button"
                    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                    aria-label="録画を再開"
                    onclick={requestResume}
                    data-testid="resume-recording"
                >
                    <Play class="size-5" aria-hidden="true" />
                </button>
            {/if}
            <Button variant="danger" onclick={safeStop} testId="stop-recording">
                <Square class="size-4" aria-hidden="true" />
                録画停止
            </Button>
        {/if}
        <!-- グリッドトグル (常時表示・字幕トグルと並置。字幕が空でも disabled にしない = 禁止事項 8) -->
        <button
            type="button"
            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
            aria-label={gridToggleLabel}
            aria-pressed={showGrid}
            onclick={() => (showGrid = !showGrid)}
            data-testid="toggle-grid"
        >
            <Grid3x3 class="size-5" aria-hidden="true" />
        </button>
        <!-- 字幕トグル (録画ボタン右)。二値の pressed 状態は raw button + aria-pressed で表現
             (先例: molecules/PasswordInput.svelte)。字幕が空でも disabled にしない (禁止事項 8) -->
        <button
            type="button"
            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
            aria-label={subtitleToggleLabel}
            aria-pressed={showSubtitles}
            onclick={() => (showSubtitles = !showSubtitles)}
            data-testid="toggle-subtitles"
        >
            {#if showSubtitles}
                <Captions class="size-5" aria-hidden="true" />
            {:else}
                <CaptionsOff class="size-5" aria-hidden="true" />
            {/if}
        </button>
    </div>
    {#if error}
        <p class="text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>

```
### `resources/js/components/features/capture/SubtitleOverlay.svelte`

```
<script lang="ts">
    import type { CaptureCut } from "@/types/capture";

    /**
     * 撮影中カメラプレビューへ重畳する字幕ガイド (doc/05 §5.2 の字幕重畳要件)。
     * 焼込ではなく撮影ガイド overlay: MediaRecorder が録る MediaStream には含まれない。
     * primary=上部帯 (名称・数値) / secondary=下部メイン。位置は AssSubtitleWriter (ASS) と一致。
     * 位置・占有領域の確認用であり全文確認用ではない (長文は line-clamp で省略)。
     */
    interface Props {
        primary: CaptureCut["subtitle_primary"];
        secondary: CaptureCut["subtitle_secondary"];
        visible: boolean;
    }

    let { primary, secondary, visible }: Props = $props();

    // trim は「空判定」のみに使う。描画には元文字列をそのまま使う (内容を書き換えない)。
    // secondary は型上 string だが将来の props 契約変更に備え防御的に nullish 合体する。
    const hasPrimary = $derived((primary ?? "").trim() !== "");
    const hasSecondary = $derived((secondary ?? "").trim() !== "");
    const shown = $derived(visible && (hasPrimary || hasSecondary));
</script>

{#if shown}
    <div
        class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3"
        data-testid="subtitle-overlay"
    >
        <div class="flex justify-center">
            {#if hasPrimary}
                <p
                    class="line-clamp-2 max-w-[90%] rounded-sm bg-text/70 px-3 py-1 text-center text-body whitespace-pre-line text-surface"
                    data-testid="subtitle-primary"
                >
                    {primary}
                </p>
            {/if}
        </div>
        <div class="flex justify-center">
            {#if hasSecondary}
                <p
                    class="line-clamp-3 max-w-[90%] rounded-sm bg-text/70 px-3 py-1 text-center text-body whitespace-pre-line text-surface"
                    data-testid="subtitle-secondary"
                >
                    {secondary}
                </p>
            {/if}
        </div>
    </div>
{/if}

```
### `resources/js/components/features/capture/GridOverlay.svelte`

```
<script lang="ts">
    /**
     * 撮影プレビューへ重畳する三分割グリッド (doc/05 §5.2 グリッド表示)。
     * 構図補助の overlay で MediaRecorder が録る MediaStream には含まれない (焼込ではない)。
     * z 順は映像 < grid < 字幕帯 (SubtitleOverlay)。字幕帯と重なっても字幕優先で可読。
     */
    interface Props {
        visible: boolean;
    }
    let { visible }: Props = $props();
</script>

{#if visible}
    <div class="pointer-events-none absolute inset-0" aria-hidden="true" data-testid="grid-overlay">
        <!-- 三分割: 縦 2 本・横 2 本の細線。DS token surface の半透明で映像上に薄く表示 -->
        <div class="absolute inset-y-0 left-1/3 w-px bg-surface/40"></div>
        <div class="absolute inset-y-0 left-2/3 w-px bg-surface/40"></div>
        <div class="absolute inset-x-0 top-1/3 h-px bg-surface/40"></div>
        <div class="absolute inset-x-0 top-2/3 h-px bg-surface/40"></div>
    </div>
{/if}

```
### `resources/js/components/features/capture/CutNavigator.svelte`

```
<script lang="ts">
    import { Check, MapPin, Video } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import { buildCutLabels } from "@/lib/capture/cut-labels";
    import type { CaptureCut } from "@/types/capture";

    /**
     * シナリオカットのナビゲーション (doc/10 §10.1)。
     * 手順 N / 急所ラベルは cuts の走査で派生する (行タップで撮影パネルを開く)。
     */
    interface Props {
        cuts: CaptureCut[];
        selectedCutId: number | null;
        onSelect: (cutId: number) => void;
    }

    let { cuts, selectedCutId, onSelect }: Props = $props();

    /**
     * 手順番号ラベル (step は連番、point は親 step の番号 + 枝番)。
     * 導出規則は lib/capture/cut-labels.ts が唯一の正本 (撮影パネル見出し・
     * テイクプレビューの aria-label と共有するため)。
     */
    const labels = $derived(buildCutLabels(cuts));
</script>

<ul class="divide-y divide-border" data-testid="cut-navigator">
    {#each cuts as cut (cut.id)}
        <li>
            <button
                type="button"
                class={[
                    "flex w-full items-center gap-3 px-3 py-3 text-left transition-colors hover:bg-neutral",
                    selectedCutId === cut.id && "bg-neutral",
                ]}
                onclick={() => onSelect(cut.id)}
                data-testid={`cut-row-${cut.id}`}
            >
                <div class="min-w-0 flex-1">
                    <p class="text-caption text-text-secondary">
                        {labels[cut.id]}
                        <span class="ml-1">{cut.shot_type === "hiki" ? "引き" : "寄り"}</span>
                    </p>
                    <p class="truncate text-body">{cut.scene}</p>
                    {#if cut.shooting_point}
                        <p class="flex min-w-0 items-center gap-1 text-caption text-text-secondary">
                            <MapPin class="size-3 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{cut.shooting_point}</span>
                        </p>
                    {/if}
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    {#if cut.takes.length > 0}
                        <Badge tone="neutral">
                            <Video class="size-3" aria-hidden="true" />
                            {cut.takes.length}
                        </Badge>
                    {/if}
                    {#if cut.adopted_take_id !== null}
                        <Badge tone="success" testId={`cut-adopted-${cut.id}`}>
                            <Check class="size-3" aria-hidden="true" />
                            採用済
                        </Badge>
                    {/if}
                </div>
            </button>
        </li>
    {/each}
</ul>

```
### `resources/js/lib/capture/panel-navigation.ts`

```
/**
 * 撮影ナビの「視点とフォーカスを運ぶ」責務 (詳細設計 施策 A / bug-hunt F-1-03)。
 *
 * 1 カラム表示ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットをタップしても
 * 撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
 * 視覚的なスクロールだけを直すとキーボード / スクリーンリーダーの現在位置は一覧側に残るので、
 * **視点とフォーカスを同時に運ぶ**。
 *
 * 副作用 (focus / scrollIntoView) ごとここに置く。述語だけを切り出すと抑止条件が実際に
 * 副作用を止めているかを page component の外から検証できず、回帰を固定できないため。
 */

/** 縦積み判定の許容差 (px)。sub-pixel と border 由来のズレを吸収する。 */
const STACK_TOLERANCE_PX = 4;

export interface PanelNavigationInput {
    /** 録画中 / getUserMedia grant 待ち (CameraRecorder の公開 active)。true なら何もしない */
    captureActive: boolean;
    leftEl: HTMLElement | null;
    rightEl: HTMLElement | null;
    headingEl: HTMLElement | null;
    reducedMotion: boolean;
}

/**
 * 右 pane が左 pane の「下」に積まれているか (= 1 カラム表示か) を実測で判定する。
 * lg breakpoint の値を JS 側へコピーしない (Tailwind 設定との二重管理を避ける) ため、座標で判定する。
 */
export function isStackedLayout(leftRect: DOMRect, rightRect: DOMRect): boolean {
    return rightRect.top >= leftRect.bottom - STACK_TOLERANCE_PX;
}

/** scrollIntoView の behavior。prefers-reduced-motion: reduce なら smooth を使わない。 */
export function scrollBehaviorFor(reducedMotion: boolean): ScrollBehavior {
    return reducedMotion ? "auto" : "smooth";
}

/**
 * ブラウザ側でのみ評価する。
 * SSR / matchMedia 非対応では true (= アニメーションしない) に倒す:
 * 「動かさない」は常に安全側で、逆は存在しない環境で不要な副作用を仮定することになる。
 */
export function prefersReducedMotion(): boolean {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return true;
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/**
 * カット選択時に視点とフォーカスを撮影パネルへ運ぶ。
 *
 * - captureActive の間は動かさない。captureActive は CameraRecorder の公開 active
 *   (`starting || resuming || phase !== "idle"`) で、getUserMedia の grant 待ち 2 窓を含む =
 *   権限ダイアログ中・カメラ初期化中も抑止される。
 * - 2 カラム (横並び) では左右が同時に見えているので動かさない。
 * - focus() 自体が暗黙スクロールを起こすため、preventScroll してから scrollIntoView する
 *   (二重移動の防止)。
 *
 * @returns 実際にナビゲートしたか
 */
export function navigateToPanelIfNeeded(input: PanelNavigationInput): boolean {
    const { captureActive, leftEl, rightEl, headingEl, reducedMotion } = input;
    if (captureActive) return false;
    if (leftEl === null || rightEl === null || headingEl === null) return false;
    if (!isStackedLayout(leftEl.getBoundingClientRect(), rightEl.getBoundingClientRect())) {
        return false;
    }
    headingEl.focus({ preventScroll: true });
    headingEl.scrollIntoView({ behavior: scrollBehaviorFor(reducedMotion), block: "start" });
    return true;
}

/**
 * 「カット一覧へ戻る」。視点とフォーカスの両方を一覧側へ返す
 * (スクロールで運んだ以上、帰り道が無ければ別の詰みを作るため)。
 */
export function navigateBackToList(
    headingEl: HTMLElement | null,
    reducedMotion: boolean,
): boolean {
    if (headingEl === null) return false;
    headingEl.focus({ preventScroll: true });
    headingEl.scrollIntoView({ behavior: scrollBehaviorFor(reducedMotion), block: "start" });
    return true;
}

```
### `resources/js/lib/capture/cut-labels.ts`

```
import type { CaptureCut } from "@/types/capture";

/**
 * カットの表示ラベル (手順 N / 急所 N-M) を cuts の並び順から導出する。
 * step は連番、point は直前 step の番号 + 枝番 (doc/10 §10.1)。
 *
 * CutNavigator の行ラベル・撮影パネルの見出し (F-1-03) ・テイクプレビューの
 * アクセシブルネーム (F-1-02) が同じ規則を共有するため、ここを唯一の導出元とする
 * (規則が 3 箇所に散るのを避ける)。
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
### `resources/js/types/capture.ts`

```
/**
 * 撮影 PWA の型定義。PHP 側 App\DataTransferObjects\Capture\* と対で保守する
 * (キー集合の契約は tests/Feature/Capture/CaptureManualBrowsingTest が固定する)。
 */

export type TakeStatus = "uploading" | "processing" | "ready" | "failed";

export interface CaptureTake {
    id: number;
    client_take_id: string;
    status: TakeStatus;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
    /** 採用テイクのみ非 null (doc/10 §10.3) */
    playback_url: string | null;
    /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
    download_ack_token: string | null;
}

export interface CaptureCut {
    id: number;
    type: "step" | "point";
    parent_cut_id: number | null;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    adopted_take_id: number | null;
    takes: CaptureTake[];
}

export interface CaptureManualDetail {
    id: number;
    title: string;
    status: string;
    cuts: CaptureCut[];
}

export interface CaptureManualSummary {
    id: number;
    title: string;
    status: string;
    category_id: number | null;
    category_name: string | null;
    cuts_total: number;
    cuts_adopted: number;
    cuts_with_takes: number;
    updated_at: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
}

/** POST .../takes/upload-url の応答 (TakeUploadTicketResource と対) */
export interface UploadTicket {
    upload_url: string;
    headers: Record<string, string>;
    ticket: string;
    client_take_id: string;
    expires_at: string;
}

/** 422 quota 超過ボディ (QuotaExceededResource と対) */
export interface QuotaExceededBody {
    code: "quota_exceeded";
    message: string;
}

/** 409 登録競合ボディ (CaptureConflictResource と対) */
export interface CaptureConflictBody {
    code: "capture_conflict";
    conflict_type: "registration_in_flight" | "reservation_inconsistent";
    message: string;
}

```
### `resources/js/components/features/capture/UploadQueueBar.svelte`

```
<script lang="ts">
    import { RefreshCw, Upload } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * アップロードキューの状態表示 (概念設計 D9: 端末保持中のテイク数・概算サイズを明示)。
     * quota 超過はキュー停止の理由を表示し、再開ボタン (resume) を常時押下可能にする。
     */
    interface Props {
        pendingCount: number;
        pendingBytes: number;
        uploading: boolean;
        quotaMessage: string | null;
        onResume: () => void;
    }

    let { pendingCount, pendingBytes, uploading, quotaMessage, onResume }: Props = $props();

    const sizeLabel = $derived(
        pendingBytes >= 1024 * 1024
            ? `${(pendingBytes / (1024 * 1024)).toFixed(1)} MB`
            : `${Math.max(1, Math.round(pendingBytes / 1024))} KB`,
    );
</script>

{#if pendingCount > 0 || quotaMessage !== null}
    <div
        class="flex items-center gap-3 rounded-md border border-border bg-surface px-3 py-2"
        data-testid="upload-queue-bar"
    >
        <Upload class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
        <div class="min-w-0 flex-1">
            {#if pendingCount > 0}
                <p class="text-caption">
                    未送信テイク {pendingCount} 件 ({sizeLabel}) を端末に保持中
                </p>
            {/if}
            {#if quotaMessage !== null}
                <p class="text-caption text-danger" role="alert" data-testid="quota-message">
                    {quotaMessage}
                </p>
            {/if}
        </div>
        <Button variant="neutral" size="sm" loading={uploading} onclick={onResume} testId="resume-uploads">
            <RefreshCw class="size-4" aria-hidden="true" />
            再送
        </Button>
    </div>
{/if}

```

