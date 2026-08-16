## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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


## あなたの役割

Laravel + Svelte 5 (Inertia) アプリの **実装レビュアー**。TODO T186「横持ち全画面撮影とカット間スワイプ」の実装差分をレビューする。

## レビュー観点

1. **設計との一致性** — 詳細設計書の施策 A〜F と不変条件 1〜6 が実装されているか。逸脱があるなら妥当か
2. **正確性** — 状態遷移・境界条件・null 安全・イベント配線の誤り
3. **PHPStan level 10 適合性** — 本差分に PHP の本体変更は無い (Browser テストのみ)。走査根は app/config/database/routes
4. **DTO / JsonResource パターン** — サーバ側変更が無いことの妥当性
5. **テスト網羅性** — 各施策にテストがあるか。**空振り (常に緑になる) テストが無いか**。fail-first で書けているか
6. **セキュリティ** — 認可・テナント境界・payload キー・XSS 等
7. **DESIGN.md 準拠** — color / radius / typography は token 経由。hex 直書き (`#RRGGBB`) を増やしていないか。任意値 utility を増やしていないか。z-index は ramp (z-0/10/20/30/40/50) 内か。静的 inline style が無いか
8. **Atomic Design 準拠** — `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。新規 component の配置階層は妥当か。アイコンは `@lucide/svelte` のみで SVG 直書きを増やしていないか

## 出力形式

- ファイルごとに判定と指摘を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
- 最後に **全体判定** を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明示する
- 指摘は「なぜ問題か」「どう直すか」をセットで書く。曖昧な感想は書かない


---


## 詳細設計書 (devnotes/20260816-1021-landscape-fullscreen-capture/detailed-design.md)

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

### 依存する Web 機能と最低バージョン前提 (本設計で 1 か所にまとめる)

`docs/supported-browsers.md` の「対象ブラウザ」節は面 (撮影 PWA = iOS Safari / Android Chrome)
までしか定めておらず、**最低バージョンは書かれていない**。本設計が新たに依存する機能の前提を
ここへ集約し、施策 F の `docs/supported-browsers.md` 追記からはこの表を参照する
(版の情報を 2 か所に散らさない)。

| 機能 | 使う場所 | iOS Safari | Android Chrome | 未対応時の縮退 |
|---|---|---|---|---|
| `MediaQueryList.addEventListener` | 施策 A `subscribeLandscapeCapture` | 14 | 45 | **並走させない**。`addListener` へのフォールバックは書かない (思考原則 3) |
| `(pointer: coarse)` / `(orientation: landscape)` media feature | 施策 A の判定式 | 対象版で対応済み | 対象版で対応済み | 判定が偽 = 既存レイアウトのまま (安全側) |
| Pointer Events (`pointerdown`/`up`/`cancel`) | 施策 B のスワイプ | 13 | 55 | スワイプが効かないだけ。前後ボタンと矢印キーが残る |
| `h-dvh` (`100dvh`) | 施策 D の全画面高さ | 15.4 | 108 | 高さが決まらず表示が崩れうる。**実機受入確認の項目 1** |
| `inert` 属性 | 施策 D の背後無効化 | 15.5 | 102 | 背後へ Tab で入り込める (情報は不透明な面で隠れている)。**実機受入確認の項目 8** |

> 表の「対象版」とは **iOS Safari 15.5 以降 / Android Chrome 108 以降** (この表の最大値) を指す。
> 本設計はこの 2 つを最低前提とする。

**この前提は既に成立している**: 撮影 PWA は Service Worker + `getUserMedia` +
`MediaRecorder` を要求し、iOS Safari で `MediaRecorder` が使えるのは **14.5 以降**である。
つまり本機能が要求する最低版 (15.5) は撮影機能そのものの最低版 (14.5) より 1 世代新しいだけで、
**新たに切り捨てる利用者は「録画はできるが全画面の一部が縮退する 15.4 以前」に限られる**。
その帯域でも既存の縦持ちレイアウトで撮影は完結できる (機能の詰みにならない)。

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
    縦優勢→`none` / 左端始まり→`none` / 右端始まり→`none` /
    **viewport 幅が除外幅の 2 倍以下 (極小・または `viewportWidth()` が 0 を返した場合) は
    常に `none`** (安全側へ倒れることを仕様として固定する)
  - `decideCutNavigation()`: `captureActive` で常に `alert` の告知 (**先頭で評価される**ことを
    「端かつ録画中」の入力で固定) / 通常移動 / 先頭で `-1` → `first` の `status` /
    末尾で `+1` → `last` の `status` / 未選択・不在 id・空配列 → `ignore`
  - `lockBackgroundScroll()`: class を付ける / 解除で外す / 既に付いていたら付けも外しもしない
- [x] 個別の `DatabaseTransactions` は使わない (JS テストのため無関係)

### リスク

- `MediaQueryList.addEventListener` は iOS Safari 14 以降 (上表)。13.x は `addListener` のみだが、
  撮影 PWA が要求する `MediaRecorder` の最低版 (14.5) より古いため対象外である。
  **二重の登録経路を持たない** (思考原則 3: 後方互換の並走を残さない)。
  テスト名にも「legacy MediaQueryList (`addListener`) は対象外」と残し、
  善意でフォールバックが足されたら意図の記録に当たるようにする。
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

    /**
     * 画面端の除外判定に使う viewport 幅。非ブラウザ実行では 0 を返す。
     * 0 のとき resolveSwipe は必ず "none" を返す = **移動しない側へ倒れる**
     * (panel-navigation.ts の prefersReducedMotion() が非対応環境で「動かさない」へ
     * 倒すのと同じ思想。安全側は常に「何もしない」)。
     */
    function viewportWidth(): number {
        return typeof window === "undefined" ? 0 : window.innerWidth;
    }

    /**
     * ボタンの上で始まった操作はスワイプとして扱わない。
     * 扱ってしまうと「ボタンを押しながら 48px 以上動かす」で
     * 親の pointerup による移動と button の click による移動が**二重発火**し、
     * 1 操作で 2 カット進んでしまう。
     */
    function startedOnButton(event: PointerEvent): boolean {
        const target = event.target;
        return target instanceof Element && target.closest("button") !== null;
    }

    function handlePointerDown(event: PointerEvent): void {
        if (startedOnButton(event)) {
            gesture = null;
            return;
        }
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
                viewportWidth: viewportWidth(),
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

  **このバー自体はフォーカス対象にしない** (tabindex を持たない)。
  キーイベントは内側の前後ボタンからバブルしてくるので、
  「前後ボタンにフォーカスがある状態で左右キー」は tabindex 無しでも成立する。
  バーを Tab 停止にすると、同じ目的の停止が 3 つ (バー + 前 + 次) に増えて操作が冗長になる。
  svelte-ignore: 非対話要素へのイベントだが、**操作の入口は内側の 2 つの button** であり、
  ここのハンドラはそれを補うだけ (キーはバブル、ポインタは帯全体を当たり判定にするため)。
-->
<!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
<div
    class="flex touch-pan-y items-center gap-2 rounded-md border border-border bg-surface/90 px-2 py-1"
    role="group"
    aria-label="カットの移動"
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
- [x] `pnpm lint` (eslint + svelte): バーは `role="group"` + `aria-label` を持ち
      **`tabindex` を持たない** (Tab 停止は内側の 2 ボタンだけ)。
      非対話要素へのイベントは `svelte-ignore` を理由コメント付きで 1 か所に置く
- [x] `event.target` は `instanceof Element` で絞ってから `closest()` を呼ぶ
      (`as` による無検査キャストを書かない)

### テスト計画

- [x] 新規 `tests/js/components/features/capture/CutSwipeBar.test.ts`
  - ラベル・scene・位置 (`2 / 12`) が描画される
  - 「前のカット」/「次のカット」ボタンが `onNavigate(-1)` / `onNavigate(1)` を呼ぶ
  - **端でもボタンが `disabled` にならない** (`toBeDisabled()` の否定。禁止事項 8 の機械固定)
  - 前後ボタンにフォーカスした状態の `ArrowLeft` / `ArrowRight` で `onNavigate` が呼ばれ、
    `preventDefault` される (キーがバーへバブルしていることの確認)
  - **Tab で到達するのは前後ボタンの 2 つだけ**でバー自体は停止しない
    (`cut-swipe-bar` に `tabindex` 属性が無いことを固定する)
  - pointerdown → pointerup の系列で左スワイプ = `onNavigate(1)`、右スワイプ = `onNavigate(-1)`
  - 距離不足 / 縦優勢 / 画面端始まりでは `onNavigate` が呼ばれない
    (判定そのものは施策 A のテストが網羅し、ここは**配線**を見る)
  - `pointercancel` の後の `pointerup` では移動しない (始点を捨てている)
  - **ボタンの上で `pointerdown` → 48px 以上動かした `pointerup` → `click` を
    明示的に順に発火しても `onNavigate` は合計 1 回しか呼ばれない**
    (スワイプと click の二重発火防止)。
    **`click` は自分で発火する** — jsdom / Testing Library の pointer event は
    実ブラウザのように `click` を合成しないため、`pointerup` だけのテストは
    「1 回しか起きない条件で緑になる」空振りになる
  - 同じ系列を **`event.target` がボタン内の Lucide アイコン要素**のケースでも行う
    (`closest("button")` が子孫からでも効くことの直接固定)
- [x] 個別の `DatabaseTransactions` を使っていない (JS テストのため無関係)

### リスク

- キーハンドラをバブル頼みにしたので、**前後ボタン以外にフォーカスがあるときは
  左右キーが効かない**。全画面内の他の操作 (録画開始/停止・グリッド・字幕トグル) から
  キーだけでカットを動かしたい場合は、いったん前後ボタンへ Tab する必要がある。
  受容する — 代替手段 (スワイプ・ボタン押下) が常にあり、
  「全画面のどこでも左右キーが効く」形にすると他の操作と競合しやすい。
- `viewportWidth()` が 0 を返す環境では**スワイプが常に無効**になる (前後ボタンは効く)。
  これは仕様として `landscape-capture.test.ts` が固定する。

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
     * **レーンは三分割の上ライン (`top-1/3`)**。SubtitleOverlay は
     * `absolute inset-0 p-3 flex flex-col justify-between` で **上端帯 = primary /
     * 下端帯 = secondary** を占めるため、上端に置くと primary と帯を奪い合い、
     * DOM 順で字幕が上になる以上**撮影ガイドが隠れて読めなくなる**。
     * 中間帯なら上下どちらの字幕帯とも交差しない。
     * 三分割線に沿う位置は構図指示として意味があり、GridOverlay の線とも一致する。
     * 非交差は Browser テストで矩形を実測して固定する (jsdom はレイアウトを持たない)。
     *
     * z 順は 映像 < グリッド < **撮影ガイド** < 字幕帯 (DOM 順で表現する)。
     * レーンが分かれているので通常は重ならないが、極端に長い字幕で万一重なった場合は
     * 字幕が上になる (v1 の中核価値が字幕であるため)。
     */
    interface Props {
        text: string;
    }

    let { text }: Props = $props();
</script>

<!--
  幅の制限は**任意値を使わず**コンテナの px-3 と max-w-full で行う
  (DESIGN.md の「token / 既存 utility の範囲で表現する」に寄せる。
  既存 SubtitleOverlay の max-w-[90%] には倣わない = 新設分で任意値を増やさない)。
-->
<div
    class="pointer-events-none absolute inset-x-0 top-1/3 flex justify-center px-3"
    data-testid="shooting-guide-overlay"
>
    <p
        class="line-clamp-2 flex max-w-full items-start gap-1 rounded-sm bg-text/70 px-3 py-1 text-caption text-surface"
    >
        <Lightbulb class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span class="min-w-0">{text}</span>
    </p>
</div>
```

> レーンの割り当て (横持ち 390px 高の stage での概算):
> 上端帯 (primary、`p-3` + `line-clamp-2`) は概ね 12〜68px、
> 撮影ガイド (`top-1/3` + `line-clamp-2`) は 130〜186px、
> 下端帯 (secondary、`line-clamp-3`) は概ね 294px 以降。**3 つとも交差しない**。

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
    /**
     * trim は**空判定にのみ**使い、描画には元文字列をそのまま渡す
     * (SubtitleOverlay と同じ作法。内容を書き換えない)。
     */
    const hasShootingGuide = $derived((shootingPoint ?? "").trim() !== "");
    const showShootingGuide = $derived(isFullscreen && hasShootingGuide);
```

#### C-3. `CameraRecorder.svelte` の markup (class 切替のみ)

```svelte
<!--
  全画面と inline の切替は **class の差し替えだけ**で行う。
  {#if} で描き分けると <video> が unmount され、録画中の MediaStream / MediaRecorder が
  破棄されて録ったデータが消えるため (不変条件 1)。

  **操作行は全画面でも映像に重ねない**。映像を flex-1 で伸ばし、操作行は不透明な面の上に
  そのまま置く。半透明の帯を敷いてアイコンのコントラストを別途担保する道を採らないのは、
  「仕組みが機能していない段階で値 (色) を弄るな」という原則と、
  contrast-invariant の検査対象を無駄に増やさないためである。
-->
<div class={isFullscreen ? "flex h-full min-h-0 flex-col gap-2" : "flex flex-col gap-3"}>
    <div
        class={isFullscreen
            ? "relative min-h-0 flex-1 overflow-hidden rounded-md"
            : "relative"}
    >
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
            <!-- 描画には元文字列を渡す (trim は showShootingGuide の空判定にだけ使う) -->
            <ShootingGuideOverlay text={shootingPoint ?? ""} />
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
            ? "flex shrink-0 items-center justify-center gap-3"
            : "flex items-center justify-center gap-3"}
    >
        …（録画開始 / 一時停止 / 再開 / 停止 / グリッド / 字幕トグル。**中身は無改変**）…
    </div>
    {#if error}
        <!-- 全画面でも重ねないので class は共通のまま (経験値の位置合わせが不要になった) -->
        <p class="shrink-0 text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
```

**変更の全量**: import 2 行 / props 2 つ / `$derived` 3 つ / 既存 3 要素の `class` 属性の
三項化 (+ `error` の `<p>` に `shrink-0` を 1 語) / `ShootingGuideOverlay` の 1 ブロック。
`Phase` union・`syncActive` / `setPhase`・`startRecording` / `safeStop` / `requestPause` /
`requestResume` / `recoverPhaseFromRecorderState` / `releaseForPreview` /
`resumeAfterPreview` / タイマー群 / flip 群は**1 行も触らない**。

### PHPStan適合チェック

- [x] PHP の変更なし (level 10 の解析結果は不変)
- [x] `pnpm typecheck`: `layout` は `LayoutMode` union で任意文字列を弾く。
      `shootingPoint` は `CaptureCut["shooting_point"]` を参照するので、
      上流の型が変わったら型エラーで気づける (文字列型のコピーを作らない)
- [x] `ShootingGuideOverlay` は非 null の `text: string` のみ受け、nullable を子へ持ち込まない
- [x] `ds-purity`: **任意値を 1 つも新設しない** (`max-w-full` / `rounded-sm` / `rounded-md` /
      `text-caption` / `bg-text/70` / `text-surface` はすべて token・ramp・既存 utility の範囲)。
      hex・raw palette・raw text-size・方向別 rounded・静的 inline style・
      arbitrary z-index のいずれも使わない

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
  - `layout="fullscreen"` でも**操作行が映像コンテナの子ではない**
    (`start-recording` が `camera-preview` の親要素の外にあること。
    「重ねない」判断が後から覆されたら赤くなる)
  - `shootingPoint` に前後空白を含む文字列を渡すと、**trim 前の元文字列がそのまま描画される**
    (空判定にだけ trim を使う契約の固定)
- [x] **レーンの非交差は Browser テストで固定する** (施策 E の新規 Browser ファイルに置く)。
      `subtitle_primary` / `subtitle_secondary` / `shooting_point` がすべて非空のカットで
      全画面に入り、**撮影ガイドが上下の字幕帯のいずれとも交差しない**ことを
      `getBoundingClientRect()` で assert する
      (`guide × primary` と `guide × secondary` の 2 組)。
      `primary × secondary` は本設計が触っていない既存 component 内部の配置なので
      検査対象に含めない — 主張と機械保証の範囲を一致させる。
      jsdom はレイアウトを持たないので vitest 側では固定できない
      (できない検査を component テストに書かない)
  - **既存の phase マシンのテストは 1 件も変更しない** (変更したら不変条件が緩んだ証拠)
- [x] 個別の `DatabaseTransactions` を使っていない (JS テストのため無関係)

### リスク

- 操作行を映像に重ねないぶん、**映像の高さは操作行の分だけ減る**。
  横持ち 390px 高の端末では操作行 (約 56px) と上部バー (約 52px) を引いた残りが映像になる。
  それでも既存の 1 カラム縦持ちレイアウトより広い (現行は映像が `aspect-video` で
  幅に従属し、下にナレーション欄とテイク一覧が積まれる)。
  実測値は実機受入確認の項目 4 で確認する。
- 字幕・撮影ガイドの overlay は `bg-text/70` の帯を持つが、
  明るい被写体の上での可読性は実映像でしか判断できない。**実機受入確認の項目 5**。

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
        matchesLandscapeCapture,
        subscribeLandscapeCapture,
        type NavigationDirection,
    } from "@/lib/capture/landscape-capture";
```

**宣言順を守ること** (`initialLandscape` は `selectedCutId` より前、`$props()` の直後):

```svelte
    let { project, manual }: Props = $props();

    /**
     * 横持ち全画面の初期判定。**テンプレートの初回描画より前**に確定させるため、
     * script のこの位置 (props 受領直後) で 1 度だけ評価する。
     * これより後ろで宣言すると selectedCutId の初期化が宣言前参照 (TDZ) になる。
     */
    const initialLandscape = matchesLandscapeCapture();

    /* ---- 既存の selectedCutId 宣言を初期値付きに変える (現行 L46) ----
     * 初期描画で全画面になる場合は、**同じ script 評価の中で**先頭カットも選んでおく。
     * 選ばずに全画面へ入ると、最初の 1 描画だけ「カットを選び直してください。」が出る。 */
    let selectedCutId = $state<number | null>(
        initialLandscape ? (manual.cuts[0]?.id ?? null) : null,
    );
```

その後の横持ち節では **`initialLandscape` を再宣言しない**:

```svelte
    /* ---- 横持ち全画面 (doc/05 §5.2) ----
     * 判定・ジェスチャ解釈・移動判断・スクロール抑止は lib/capture/landscape-capture.ts が持ち、
     * ここは配線だけを行う (panel-navigation.ts と同じ役割分担)。 */
    /**
     * 横持ち全画面の条件 (向き + 高さ + 粗いポインタ) を満たすか。
     *
     * **初期値は script 評価時に確定させる**。`$effect` はテンプレートの初回描画の**後**に
     * 走るため、`$state(false)` から effect で入れる形にすると
     * 「最初の 1 描画だけ inline レイアウト」というちらつきが必ず残る。
     * component の script はテンプレートより先に評価されるので、
     * ここで確定させれば**最初に描かれる DOM が既に全画面**になる。
     *
     * **この方式は「Inertia SSR が配線されていない」ことに依存する**。
     * 現状このリポジトリに SSR は無い — `config/inertia.php` / `resources/js/ssr.*` /
     * ssr build / `inertia:start-ssr` のいずれも存在せず、`app.ts` の
     * `data-server-rendered === "true"` 分岐が真になる経路が無い。
     * SSR を入れるとサーバは inline、クライアントの初期評価は fullscreen になり得るため
     * **hydration が食い違う**。「安全側に縮退する」とは書かない (下記 再確認条件)。
     */
    let landscapeMatches = $state(initialLandscape);
    /** 利用者が明示的に全画面を終了したか。**縦に戻すまで自動で入り直さない**ためのラッチ */
    let fullscreenDismissed = $state(false);
    /**
     * 実際に全画面を描くか。
     * **選択状態ではなく「撮るものがあるか」で決める** (`manual.cuts.length > 0`)。
     * `selectedCut !== null` を条件にすると、自動選択が反映される前の 1 フレームだけ
     * inline レイアウトが描かれてちらつく。また全画面中に reload で選択中カットが
     * 消えたときに「全画面なのに終了ボタンが無い」状態を作りかねない。
     */
    const fullscreenActive = $derived(
        landscapeMatches && !fullscreenDismissed && manual.cuts.length > 0,
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
    // 横持ち判定の購読。**初期値は script 評価時に確定済み**なので、この effect が担うのは
    // 「向きが変わったときの追従」だけである。
    // 追従に伴う後始末は同じ同期ブロックの中で済ませる
    // (2 本の effect に分けると、landscapeMatches が反映された描画と selectedCutId が
    //  入った描画の間に 1 フレーム挟まり、inline レイアウトが一瞬見えてしまう)。
    //  - 縦に戻ったらラッチを解除する (次に横へ倒せばまた自動で全画面に入る)
    //  - 横持ちでカット未選択なら先頭カットを自動選択する (何も撮れない全画面を作らない)
    // manual / selectedCutId は untrack で読む (選択やリロードで購読を張り直さない)。
    $effect(() =>
        subscribeLandscapeCapture((matches) => {
            landscapeMatches = matches;
            if (!matches) {
                fullscreenDismissed = false;
                return;
            }
            const first = untrack(() => manual.cuts)[0];
            if (first !== undefined && untrack(() => selectedCutId) === null) {
                selectedCutId = first.id;
            }
        }),
    );

    /** 全画面へ入った直後のフォーカス着地点 (背後に取り残さない)。tabindex="-1" */
    let fullscreenHeadingEl = $state<HTMLElement | null>(null);
    /** 直前に運んだ全画面状態。true への遷移でちょうど 1 回だけフォーカスを運ぶ */
    let lastFullscreenFocused = false;

    // 全画面へ入ったらフォーカスを全画面内へ運ぶ。
    // 背後 (ヘッダ / 左 pane) は inert にするが、AppLayout の chrome は覆わない (不変条件 6) ため、
    // 開始位置を明示的に全画面内へ置くことでキーボード利用者が背後から始まらないようにする。
    $effect(() => {
        if (fullscreenActive === lastFullscreenFocused) return;
        lastFullscreenFocused = fullscreenActive;
        if (!fullscreenActive) return;
        fullscreenHeadingEl?.focus({ preventScroll: true });
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
        navigationNotice = null; // ignore: 移動対象が無い (自動選択があるため通常は到達しない)
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
        navigationNotice = null;
        fullscreenDismissed = false;
    }
```

#### D-3b. 既存ハンドラへの最小の追記 (古い告知を残さない)

告知は**出す契機と消す契機がすべて関数呼び出しの中**にあるので、そこで消す
(依存を並べるだけの `$effect` は Svelte 5 では「読んだことにする」不自然な式が要り、
lint とも衝突するため採らない)。

```svelte
    function handleSelectCut(cutId: number): void {
        navigationNotice = null; // ← 追記: カットを選び直したら古い告知を捨てる
        selectedCutId = cutId;
        void tick().then(() => { /* 既存のまま */ });
    }
```

`CameraRecorder` の `onCaptureActiveChange` は式形式から block callback へ変える
(**D-4 の markup がこの形になっていること**が本項の内容である。両者を食い違わせない):

```svelte
    onCaptureActiveChange={(active) => {
        captureActive = active;
        // ← 追記: 録画の開始でも停止でも古い告知を捨てる。
        //   とくに停止後に「録画中は移動できません」が残らないようにする。
        navigationNotice = null;
    }}
```

告知が消える契機は合計 5 つ:
`handleSelectCut` / `handleCutNavigate` (移動成功時) / `enterFullscreen` /
`exitFullscreen` / `onCaptureActiveChange`。

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
                    {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
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
                {#if fullscreenActive}
                    <!-- 全画面へ入った直後のフォーカス着地点。読み上げ順の先頭に置く -->
                    <h2
                        bind:this={fullscreenHeadingEl}
                        tabindex="-1"
                        class="sr-only"
                        data-testid="capture-fullscreen-heading"
                    >
                        全画面撮影
                    </h2>
                    <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
                    <!--
                      **終了ボタンは selectedCut の有無に依らずここに置く**。
                      出口の有無を選択状態という別の軸に結び付けない
                      (結び付けると「全画面なのに出口が無い」状態を作りうる)。
                    -->
                    <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1">
                            {#if selectedCut !== null}
                                <CutSwipeBar
                                    label={cutLabels[selectedCut.id] ?? "選択中カット"}
                                    scene={selectedCut.scene}
                                    position={cutPosition}
                                    onNavigate={handleCutNavigate}
                                />
                            {:else}
                                <!-- 全画面のフォーカス着地点を兼ねる。通常は自動選択で到達しない -->
                                <p class="text-caption text-text-secondary">
                                    カットを選び直してください。
                                </p>
                            {/if}
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
                                onCaptureActiveChange={(active) => {
                                    captureActive = active;
                                    // 録画の開始でも停止でも古い告知を捨てる。とくに停止後に
                                    // 「録画中は移動できません」が残らないようにする (D-3b)。
                                    navigationNotice = null;
                                }}
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

### 再確認条件 (この設計の前提が崩れる変更)

- **Inertia SSR を導入する PR は、横持ちの初期判定方式を再設計しなければならない**。
  `$state` の初期値を `matchesLandscapeCapture()` で決める形は、
  サーバ側描画が存在しないことを前提にしている。SSR を入れると
  サーバ (inline) とクライアント初期評価 (fullscreen) で DOM が食い違う。
  この場合は「初期判定が確定するまで撮影 pane を未確定状態で描く」等の別方式が要る。

### 設計上の不変条件 (実装者が壊してはいけないもの)

1. **`CameraRecorder` は `fullscreenActive` の `{#if}` を跨がない**。
   跨ぐと向き変更で unmount され、録画中の `MediaStream` / `MediaRecorder` / 累積タイマーが
   破棄されて**録ったデータが消える**。テスト (`CaptureShow.test.ts`) が
   切替前後の `camera-preview` 要素の同一性で固定する。
   **主張の範囲**: 保証するのは「**向きの変化に伴う全画面/inline の切替では** remount しない」
   ことだけである。「いかなる場合も remount しない」ではない —
   選択カットが消えた場合 (`{#if selectedCut === null}`) や
   カメラの恒久失敗 (`{#if showRecorder}`) では従来どおり unmount される
   (どちらも本設計が持ち込んだ経路ではない。リスク節を参照)。
2. **`UploadQueueBar` は同時に 2 つ描かない** (`data-testid` の重複を作らない)。
3. **背景スクロール抑止の解除点は `lockBackgroundScroll()` の戻り値だけ**。
4. **告知文の出所は `landscape-capture.ts` の定数だけ** (page 内で文字列を組み立てない)。
5. **全画面から出る手段と入る手段が必ず対で存在する**
   (`exit-fullscreen-capture` / `enter-fullscreen-capture`)。
   さらに**終了ボタンは選択状態に依存しない位置**に置く (`fullscreenActive` の直下)。
   出口の有無を選択状態という別の軸に結び付けない、というだけの話であり、
   **録画データが守られることを意味しない** (不変条件 1 の主張範囲を参照)。
6. **`AppLayout` の chrome (モバイルヘッダのメニュー / サイドバー) は `inert` にしない**。
   `inert` を付けるのはこのページ自身のコンテンツ (ヘッダ wrapper / 左 pane) だけである。
   理由は 2 つ: (a) 全画面の描画が壊れたときに残る唯一の脱出路であり、覆うと
   「行き先のない詰み」を新設する。(b) 全画面 section を `inert` wrapper の**外**へ出すには
   grid の兄弟にするか portal を使う必要があり、前者は 2 カラムの既存レイアウトを壊し、
   後者は `CameraRecorder` を別ツリーへ再マウントして**不変条件 1 を直接壊す**。
   代わりに、全画面へ入った時点でフォーカスを `capture-fullscreen-heading` へ運び、
   キーボード利用者の開始位置を全画面内に置く。

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
  - **ちらつきが無いこと (2 段で固定する。最終状態だけ見ても検出できないため)**
    1. `render()` の**直後**、`await tick()` を挟まない同期 assertion で
       `data-fullscreen === "true"` になっている。
       `$effect` で状態を入れる実装ならこの時点では `"false"` なので**実装前に落ちる**
    2. `render()` の**前**に `document.body` へ MutationObserver
       (`childList` + `subtree`) を張り、inline レイアウト固有の
       `capture-recording-heading` が**一度も DOM に追加されない**ことを固定する
       (中間描画があれば必ず捕まる)。
       - callback は microtask 通知なので、**assertion の前に `observer.takeRecords()` で
         保留分を回収**し、microtask を 1 回進めてから `disconnect()` する
         (同期で切ると記録を取りこぼして**常に緑になる** = 最悪の空振り)
       - 探索は `addedNodes` 自身だけでなく**その子孫**も見る
         (`node instanceof Element &&
         node.querySelector('[data-testid="capture-recording-heading"]')`)
  - **カット未選択でも先頭カットが自動選択され**、`cut-swipe-label` に `手順 1` が出る
    (初期描画で全画面になる場合も、同じ script 評価の中で選ばれている)
  - **全画面切替の前後で `camera-preview` の DOM ノードが同一** (不変条件 1 の機械固定。
    `expect(before).toBe(after)` でノード同一性を見る)
  - 「次のカット」で `cut-swipe-label` が `手順 2` へ変わる
  - 末尾で「次のカット」を押すと `cut-navigation-notice` に「これが最後のカットです。」が出て
    ラベルが変わらない
  - `exit-fullscreen-capture` で `data-fullscreen` が `"false"` になり
    `enter-fullscreen-capture` が現れる。押すと再び `"true"` になる (ラッチと再入路)
  - 横持ち → 縦持ち → 横持ちでラッチが解除され、明示終了後でも再び全画面になる
  - **`upload-queue-bar` が同時に 2 つ存在しない** (不変条件 2)。
    **`getAllByTestId` は使わない** — `UploadQueueBar` は
    `{#if pendingCount > 0 || quotaMessage !== null}` を内側に持ち、
    未送信 0 件の通常状態では**要素そのものが存在しない**ため、
    `getAllByTestId` は正常な 0 件で例外を投げる。
    `queryAllByTestId` を使い、さらに**未送信テイクがある状態を用意して**
    inline / fullscreen の**両方でちょうど 1 件**であることを見る
    (0 件のまま `<= 1` を見るだけでは、二重描画を作っても
    「たまたま 0 件だから緑」になり検出力が無い)
  - 全画面中は `documentElement` に `overflow-hidden` が付き、終了で外れる (不変条件 3)
  - 全画面中は `take-strip-*` と `capture-recording-heading` が出ない
  - **カット 0 件では全画面にならず、`enter-fullscreen-capture` も出ない**
    (横持ち条件が真でも。「押しても何も起きないボタン」を作らない)
  - **全画面へ入った直後のフォーカスが `capture-fullscreen-heading` にある** (不変条件 6)
  - **全画面中は Tab で `cut-row-*` / `manual-detail-link` へ到達しない**
    (`inert` が page 自身のコンテンツを覆っていることの確認)。
    **`AppLayout` の chrome への到達は許容する** — 不変条件 6 でそう決めているので、
    期待値も「どこへも行けない」ではなく「page 自身のコンテンツへは行けない」と書く
  - **`selectedCut` が消えても `exit-fullscreen-capture` が残る** (不変条件 5。
    `manual` を props 更新して選択中カットを外した状態で固定する。
    これは**出口の配置**の検査であり、録画データ保護の検査ではない)
  - 告知が残らない: 端の告知を出した後に `handleSelectCut` 相当 (カット行の選択) を行うと
    `cut-navigation-notice` が消える
  - **録画中の抑止をページ配線として固定する** (下記):
    既存 `CameraRecorder.test.ts` の `FakeMediaRecorder` と `getUserMedia` stub を
    `tests/js/support/fake-media-recorder.ts` へ切り出して共有し、
    **`CameraRecorder` を本物のまま**録画状態へ駆動する。
    全画面 → `start-recording` → `cut-swipe-next` の順で操作し、
    `cut-navigation-error` に「録画中はカットを移動できません。…」が出て
    `cut-swipe-label` が変わらないことを固定する。さらに `stop-recording` の後に
    告知が消え、`cut-swipe-next` で移動できるようになることも見る
    (「行き先のない詰みを作らない」の実挙動確認)。
    component を stub へ差し替える方法は採らない — 実際の `onCaptureActiveChange` 経路を
    通らないと配線ミスを検出できないため
- [x] 新規 `tests/Browser/CaptureLandscapeFullscreenTest.php` (Chromium + WebKit の 2 レーン)
  - 横持ちスマホ viewport (`->on()->mobile()` = `hasTouch` かつ `isMobile` →
    `pointer: coarse`、その後 `->resize(844, 390)`) で
    `capture-right-pane[data-fullscreen="true"]` になる (ケース 0)
  - `cut-swipe-next` / `cut-swipe-previous` で `cut-swipe-label` が
    `手順 1` ↔ `手順 2` と往復する
  - `exit-fullscreen-capture` → `data-fullscreen="false"` かつ
    `enter-fullscreen-capture` が可視 → 押すと `"true"` に戻る
  - **前提の明示 (ケースごとに期待値が違う)**: 各ケースの冒頭で
    `window.matchMedia('(pointer: coarse)').matches` と、対象 media query
    (`LANDSCAPE_CAPTURE_MEDIA_QUERY` と同一文字列) の評価結果を assert する。
    これが無いと、ハーネスの context 設定が変わって前提が崩れたときに
    「全画面にならない」だけが観測され、**実装の回帰と区別できない**。
  - **ケース表** (正 1 本 + 負 3 本):
    | # | 種別 | context / viewport | `(pointer: coarse)` | 対象 query | `data-fullscreen` | 落ちたら検出できる条件 |
    |---|---|---|---|---|---|---|
    | 0 | 正 | `->on()->mobile()` + `resize(844, 390)` | `true` | `true` | `"true"` | 実装そのもの |
    | 1 | 負 | `->on()->desktop()` (1728×1117) | `false` | `false` | `"false"` | 全条件 (素の回帰) |
    | 2 | 負 | `->on()->mobile()` + `resize(1024, 900)` | `true` | `false` | `"false"` | `max-height` の欠落 |
    | 3 | 負 | `->on()->desktop()` + `resize(844, 390)` | `false` | `false` | `"false"` | `pointer: coarse` の欠落 |
  - fixture は既存 `captureNavigationFixture()` と同じ作り
    (`createOrganizationWithOwner` + `contractPaidPlan` + Factory。
    **`Model::create()` の手組みをしない**。撮影 PWA は
    `require-active-subscription` group 内なので有料契約が要る)
  - `declare(strict_types=1)` を先頭に置く (`StrictTypesDeclarationGateTest`)
  - **WebKit レーンを落とさない** (`docs/testing-browser.md` / AGENTS.md ドメイン規約 3)
- [x] `RefreshDatabase` はグローバル適用済み。個別 `DatabaseTransactions` を書かない

### リスク

- **`inert` の対応**: iOS Safari 15.5 以降 (施策 A の版の表)。未対応環境では
  page 自身の背後コンテンツへ Tab で入り込めるが、全画面は不透明な `bg-surface` で
  覆われているので**情報は見えない**。操作を誤る可能性が残るだけで、機能の詰みにはならない。
  **実機受入確認の項目 8**。
- **`AppLayout` の chrome は覆わない** (不変条件 6)。Tab でモバイルメニューへ到達し、
  開くと drawer (z-50) が全画面の上に出る。**これは意図した脱出路**であり、
  塞ぐと全画面の描画が壊れたときに行き先が無くなる。
- **`h-dvh` の対応**: iOS Safari 15.4 以降 / Chrome 108 以降。
  未対応環境での見え方は**実機受入確認の項目 1**。
- **`stacked` の測定**: 全画面中は `rightPaneEl` が `fixed` になるため
  `isStackedLayout()` の結果は意味を持たない。`stacked` を使う
  「カット一覧へ戻る」は `!fullscreenActive` の内側にしか無いので**影響しない**。
  ただし全画面終了直後は `tick()` の後に `updateStacked()` を呼び直す (上記 `exitFullscreen`)。
- **`fullscreenActive` が `manual.cuts.length` に依存する**ため、reload で cuts が
  0 件になると全画面から自動的に抜ける。これは望ましい挙動 (撮るものが無い全画面を残さない) だが、
  抜けた先が「左のシナリオからカットを選ぶと撮影パネルが開きます。」の面になる。
  既存挙動と同じ面なので新しい詰みは作らない。
- **録画中に `reloadManual()` が走って選択中カットが消えると `CameraRecorder` が
  unmount され、録画データが失われる**。これは **本設計が持ち込んだ経路ではなく、
  現行 `Show.svelte` に既にある挙動**である (現行も `handleOnline` →
  `runAutoDownload` → `changed` なら `reloadManual()` が録画中に走りうる)。
  本設計は**この点を改善も悪化もさせない** — 全画面の切替は `{#if}` を跨がないので
  新しい unmount 経路を増やしていない。
  塞ぐには「録画中は reload を保留する」か「録画中は選択カットを UI 側で保持する」が要り、
  どちらも撮影 phase マシンとアップロード/自動 DL の再入設計に触れる別テーマである。
  **本設計では扱わない (TODO も起票しない)**。保証範囲を誇張しないため、
  不変条件 1 の主張は「向きの変化に伴う切替では remount しない」に限定してある。

---

## 施策 E: テスト一式

施策 A〜D の「テスト計画」に列挙したものが本施策の内容である。ファイル単位では:

| ファイル | 種別 | 新規/追記 |
|---|---|---|
| `tests/js/support/fake-media-recorder.ts` | テスト用 stub の共有化 (既存 `CameraRecorder.test.ts` から移設) | 新規 |
| `tests/js/lib/capture/landscape-capture.test.ts` | vitest (純関数 + 副作用) | 新規 |
| `tests/js/components/features/capture/CutSwipeBar.test.ts` | vitest (component) | 新規 |
| `tests/js/components/features/capture/ShootingGuideOverlay.test.ts` | vitest (component) | 新規 |
| `tests/js/components/features/capture/CameraRecorder.test.ts` | vitest (component) | 追記 (+ stub の import 元差し替え。**テスト本体は書き換えない**) |
| `tests/js/pages/CaptureShow.test.ts` | vitest (ページ配線) | 追記 |
| `tests/Browser/CaptureLandscapeFullscreenTest.php` | Browser (Chromium + WebKit) | 新規 |

### テストファーストの順序 (思考原則 5)

1. `landscape-capture.test.ts` を書いて **fail を確認**してから施策 A を実装する
   (`LANDSCAPE_CAPTURE_MEDIA_QUERY` の 3 条件検査と `decideCutNavigation` の
   `captureActive` 優先評価が、実装前に落ちることを見る)。
2. component テスト → 施策 B / C。
3. `tests/js/support/fake-media-recorder.ts` へ既存 stub を移設し、
   **`CameraRecorder.test.ts` が緑のままであることを確認**してから (= 移設だけで挙動を変えていない)
   ページ配線テスト → 施策 D。とくに**「切替前後で `camera-preview` が同一ノード」は
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
  高さ超過・細いポインタの 3 通りでは切り替わらないこと」
  「撮影ガイドの矩形が上下の字幕帯のいずれとも交差しないこと」までである。
  **実カメラを伴う挙動 (録画中に向きが変わったときの録画継続、CSS 全画面での
  カメラプレビューの見え方、iOS Safari の動的ツールバーと `h-dvh` の相互作用、
  端末の戻るジェスチャとスワイプの競合、`inert` 非対応環境でのフォーカス漏れ) は
  どちらのレーンでも再現していない**。これらは実機受入確認の対象である。
  依存する Web 機能と最低バージョン前提は
  `devnotes/20260816-1021-landscape-fullscreen-capture/detailed-design.md` の
  **「依存する Web 機能と最低バージョン前提」を正本とする** (版番号を本書に写さない)。
```

> 版の一覧を `docs/supported-browsers.md` 側へ複製しないのは、AGENTS.md が繰り返し採っている
> 「正本を 1 つに決めて参照だけ置く」方式に合わせるためである。

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
| 4 | 全画面のカメラプレビューが `object-cover` で歪まず被写体が意図どおり入り、**映像・カット名バー・録画開始/停止ボタンが同時に viewport 内へ収まる** (概念設計の効果測定条件) | 同上。実映像とレイアウトが要る |
| 5 | 字幕・撮影ガイドの overlay (`bg-text/70` の帯) が、明るい被写体の上でも読める | 同上 (jsdom / Playwright はレイアウトと実映像を持たない) |
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


## design system 参照 (DESIGN.md)

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

## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
| `tertiary` | 真に重要な前向き CTA(1 画面 1 箇所) | bg-tertiary + text-neutral |
| `ghost` | 補助・キャンセル | 透明 + border-border-strong、hover で primary 化 |
| `neutral` | 取消可能・UI-only の補助操作(一時停止等) | bg-neutral + 常時 border(境界確保) |
| `success` | 肯定操作(追加・承認・付与) | bg-success + text-neutral |
| `danger` | dialog/form の主破壊 CTA | bg-danger + text-neutral |
| `danger-outline` | section 単位の破壊(card 内の削除) | border-danger、hover で塗り |
| `danger-ghost` | dense な row/list 内の破壊アクション | text-danger + 透明、hover で淡い tint |

- **全 variant が border(透明 or 色)を持ち外形高さを統一する**
- danger 系は irreversible / destructive 操作専用(削除・revoke・移譲・再開不可の中断)。
  危険度ではなく**配置文脈**で 3 重みを選ぶ
- **anchor 対応**: `href` 指定で `<a>`(`inertia` 指定で Inertia Link)。anchor モードでは
  `type`/`disabled` は型レベルで禁止。`target="_blank"` には `rel="noopener noreferrer"` を自動補完
- **iconOnly**: `ghost` / `neutral` / `danger-ghost` のみ許可。`ariaLabel` が型で必須
- **disclosure**: button モード限定で `ariaExpanded` / `ariaControls` / `element`(bindable な
  `HTMLButtonElement` 参照)を受ける。ハンバーガー等のトグルはこれを使い素の `<button>` を書かない
- size: `sm`(caption)/ `md`(既定)/ `lg`(form 入力面との高さ整合限定)

### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

- **`type` は入力補助であって検証手段ではない**。`email` / `tel` / `url` / `number` 等は
  モバイルキーボード・autofill・スクリーンリーダーの型アナウンスのために付ける。
  検証の正本はサーバ(日本語)と押下時の client エラーで、native constraint validation には
  依存しない(form 側で `novalidate`。§Do's and Don'ts)。`inputmode` は restProps で透過する
- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
  `bg-neutral` + `cursor-default`。ただし **disabled と同じ見た目にしない** — readonly の値は
  生きている(送信される・選択してコピーできる・フォーカスできる)ので、文字色は `text-text` の
  ままにし focus ring も維持する。disabled は `text-text-secondary` + `cursor-not-allowed` +
  フォーカス不可。`<select>` は HTML 仕様上 readonly を持たない(編集させないなら値を
  読み取り表示にする)
- 「編集させない値」の表現は 2 通り。**そのフォームの送信対象に含む / コピーさせたい**なら
  readonly input(例: 招待 email の prefill、権限が無い閲覧者への設定値提示)、
  **編集手段自体を出さない**なら読み取り表示(`<dl>` 等。例: 請求先情報カードの非管理者表示)。
  readonly input を選んだ場合、上記の見た目が付くことは atom が保証する

### Checkbox

実装: `components/atoms/Checkbox.svelte`。インラインラベル(右側)とエラー表示
(FormError 内包)を持つチェックボックス。ラベルは string のほか snippet でも受けられる
(利用規約リンク等を含める用)。複数行ラベルでもチェックボックスが 1 行目に揃う行揃えは
本 atom の責務。ページ側で素の `<input type="checkbox">` を書かない(§Do's and Don'ts)。

### FormError

実装: `components/atoms/FormError.svelte`。フィールド単位のエラー文言
(`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
責務。ページ常在の通知は Alert、一時通知は Toast を使う。
**フィールドに紐づかない失敗(ceremony 失敗・端末非対応等)を FormError に流さない**
(原因と提示先が食い違い、「パスキー失敗がパスワード欄の赤字として出る」species のバグになる)。
非フィールド起因は Alert(§Alert)。

### Avatar

実装: `components/atoms/Avatar.svelte`。`src` があれば画像、無ければ `name` の先頭 1 文字
(大文字化。サロゲートペアも 1 文字扱い)をイニシャル表示する。アバターは真に円形な UI
のため `rounded-full` を使う ramp 外例外(Toggle と並び ds-purity の file-scoped allowlist
出荷時 2 件の 1 つ)。size: `sm` / `md`(既定)/ `lg`。

### Badge

実装: `components/atoms/Badge.svelte`(仕様の真実は `Badge.types.ts`)。状態・属性の
**結果表示**ラベル(操作は Button。action button と status badge は意味色を独立に判断する
— §色の意味的割り当てルール)。tone: `primary` / `tertiary` / `success` / `warning` /
`danger` / `neutral`(中立ラベル)。既定は soft(tone 色の淡い背景 + tone 色文字)、
`bordered` は tone 色 border を atom 内で付与する(呼び出し側から border を足さない)。
左アイコン 1 つを snippet で受け、size/色の責務は Badge 内 wrapper に閉じる。
小コントロールなので `rounded-sm`。size: `sm`(既定)/ `md`。

### Card

実装: `components/atoms/Card.svelte`。浮いた要素の基本サーフェス
(`bg-surface border border-border rounded-lg`。影を使わず明度差 + 1px border で階層を
表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。

### Spinner

実装: `components/atoms/Spinner.svelte`。LoaderCircle(@lucide/svelte)+ `animate-spin`。
色は currentColor 継承(置かれた文脈の文字色に従う)。既定は装飾扱い(`aria-hidden`)で、
単独のローディング表示に使うときだけ `label` を渡す(`role="status"` + sr-only で
読み上げ)。size: `sm` / `md`(既定)/ `lg` / `xl`。

### TextLink

実装: `components/atoms/TextLink.svelte`(仕様の真実は `TextLink.types.ts`)。
リンク風 `<a>` / `<button>` の手書きは禁止(§Do's and Don'ts)、本 atom を使う。
3 モードの discriminated union: (a) `href` のみ = Inertia Link(SPA 遷移)、
(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"` +
末尾 ExternalLink アイコン(`icon` で差し替え可)、(c) `onclick` のみ = リンク風
`<button type="button">`。様式は `text-primary` + 下線(hover で下線が濃くなる)で 3 モード共通。

### Toggle

実装: `components/atoms/Toggle.svelte`(仕様の真実は `Toggle.types.ts`)。
オン/オフを**即時反映**する設定スイッチ(ネイティブ `<button>` + `role="switch"` +
`aria-checked`)。フォーム送信を伴う選択には使わない。`ariaLabel` は型レベルで必須。
トラックは On=`bg-primary` / Off=`bg-border-strong`、つまみは `bg-surface`(影なし、
明度差で表現)。`rounded-full` は真に円形な UI の例外として file-scoped allowlist で管理する。

### Modal

実装: `components/organisms/Modal.svelte`(仕様の真実は `Modal.types.ts`)。bits-ui Dialog のラップ。

- overlay は `bg-text/50`(墨色 50%。黒 hex を使わない)、本体は `bg-surface border border-border rounded-lg`
  (影が使えないためボーダーで背景と区別する)
- size: `sm`(max-w-md)/ `md`(max-w-lg 既定)/ `lg`(max-w-2xl)
- `processing` 中は ESC / overlay クリックでの close を抑止し、X ボタンを disabled にする(二重実行防止)
- title は `text-h3`。a11y 名は bits-ui `Dialog.Title` 経由で `aria-labelledby` に配線される

### ConfirmDialog

実装: `components/organisms/ConfirmDialog.svelte`(仕様の真実は `ConfirmDialog.types.ts`)。Modal の composition。

- `confirmVariant` は `primary` / `danger` の 2 値のみ。**irreversible / destructive な操作は danger**
  (§色の意味的割り当てルール)
- footer は Button atom(cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)
- confirm で自動 close しない(処理完了後に呼び出し側が `open=false` にする)。
  cancel / ESC / overlay / X は `onCancel` を発火して close
- `banner?: Snippet` は message 直上の任意スロット(サーバ validation エラーの Alert 等)。
  未指定なら描画されない(既存の出力は不変)

### Toast

実装: `components/organisms/ToastContainer.svelte` + `lib/stores/toast.ts`(addToast / dismissToast)。
Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。

- 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
  (mount するのは layout: AppLayout / AuthLayout / GuestLayout の 3 種。ページ側では mount しない)
- 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
- 消去境界: **layout(AppLayout / AuthLayout / GuestLayout)の初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
- 各 toast は `bg-surface` + type 別 border / アイコン色(success / primary(info)/ warning / danger)。
  アイコンは CircleCheck / Info / TriangleAlert / CircleX(@lucide/svelte)
- a11y: `role="status"`(error のみ `role="alert"`)

### Alert

実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)
- **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
  (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など)は、操作したその場に残る
  Alert で出す。FormError は**フィールド単位**のエラー専用であり、Toast は「一時通知」なので、
  押した直後に読ませたい失敗理由を画面外(上部中央)へ飛ばさない

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
本 molecule 経由で組む(AGENTS.md 実装規約)。

- **押下時に出した client エラーは、その後の入力に追随させる**(stale invalid を残さない)。
  ボタンを disabled にしない(§Do's and Don'ts / AGENTS.md 禁止事項 8)代わりに押下時にエラーを
  出すのだから、そのエラーは常に「今の入力」を説明していなければならない — 有効に戻ったら消え、
  無効の理由が変わったら文言も変わる。押下前には出さない。
  **canonical なのはこの不変条件であって実装形ではない**。実装は
  **「提示を開始したかの boolean」+ 文言は `$derived`** で組むのが既定(文言を `$state` で
  持つと同期漏れが起きる。`$effect` での状態同期はしない = Svelte 公式の指針)。
  先行実装(`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` に
  よる連動クリアで**同じ不変条件を満たしており、そのまま許容する**(動いている仕組みを
  churn させない)。**新規は `$derived` 形で書く**
- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない

### DangerZone

実装: `components/molecules/DangerZone.svelte`。破壊的・取り返しのつかない操作
(アカウント削除等)を集約する警告セクション(presentational・状態なし)。
`border-danger/30` + 淡い danger 背景の枠に title(danger 色 `text-h3`)+ 任意の
description、children には danger 系 Button(card 内なら `danger-outline`)を置く。
`<section>` + `aria-labelledby` で region 境界に accessible name を紐付ける。
複数同居時は `idBase` で id 衝突を回避する。

### Divider

実装: `components/molecules/Divider.svelte`。区切り線の正規化(「または」セパレータ等)。
`label` 指定時は中央ラベル付き区切り(線は `aria-hidden`、ラベルは `bg-surface` で線を
切り抜く)、省略時は素の `<hr>`。余白は呼び出し側が class で渡す(`my-6` 等)。

### Pagination

実装: `components/molecules/Pagination.svelte`。前へ / ページ番号 / 次へのページ送り UI。
callback ベース(ページング state は親が持ち、`currentPage` / `totalPages` を受けて
`onChange(page)` を返す)で遷移手段を持たないため、全て `<button type="button">` で構成する
(Inertia 遷移かローカル state 更新かは呼び出し側裁量)。総ページ ≤ 7 は全番号表示、
超過時は先頭・末尾 + 現在ページ ± 1 の窓を出し、飛びに省略記号を挿入する最小実装。
`<nav>` ランドマーク + 現在ページに `aria-current="page"`。

### Tabs

実装: `components/molecules/Tabs.svelte`。**同一ページ内 section 切替**の WAI-ARIA タブバー
(tablist のみ。URL 遷移で切り替えるページ間タブは ApiKeyTabNav のような専用 molecule を
使う)。パネル本体の描画は呼び出し側責務(god component 回避)で、
`id="{idBase}-panel-{tab.id}"` / `role="tabpanel"` / `aria-labelledby` を id 生成規則に
揃えて配線する。キーボードは ←/→(端でラップしない)+ Home/End、自動アクティベーション +
roving tabindex(active のみ tabindex=0)。`active` は bindable、`idBase` は必須
(複数同居時の id 衝突回避)。

### PasswordInput

実装: `components/molecules/PasswordInput.svelte`。Input atom + 右端の Eye/EyeOff トグルで
`password` ↔ `text` を即時切替する(button トグル + `aria-pressed`)。`id` は必須
(トグルの `aria-controls` に結線)。label/error 配線は FormField 側が担う。
Auth 系のパスワード入力は素の `Input type="password"` ではなく本 molecule を使う。

### CodeSnippet

実装: `components/molecules/CodeSnippet.svelte`。コピー付きコードブロック
(API キー・リカバリコード・CLI コマンド等)。コピー処理(navigator.clipboard)は
component 内に内包し、成功「コピー完了」/失敗「コピー失敗」を 2 秒表示する。
`<pre>` は `rounded-md bg-neutral` + `font-mono text-caption`。

### StatCard

実装: `components/molecules/StatCard.svelte`。Card atom に label(`text-caption`)+
value(`text-h2`。weight でなく ramp 昇格で強調)+ 任意の subtext / Lucide icon
(`bg-primary-soft` の rounded-md box)を載せる統計カード。

### EmptyState

実装: `components/molecules/EmptyState.svelte`。リストやテーブルが空のとき、次の行動を
案内する空状態表示。`description`(必須)+ 任意の `title` / Lucide `icon`(装飾なので
`aria-hidden`、`size-10`)。`cta` は discriminated union で遷移(`kind: "link"` = Button
の anchor+inertia)と操作(`kind: "action"` = onclick)を型安全に出し分ける。`bordered`
で破線枠サーフェス(`border-dashed`。drop 領域や明示的な空 region 向け)。

### Breadcrumb

実装: `components/molecules/Breadcrumb.svelte`。`BreadcrumbItem[]`(`@/types/components`)を
`ChevronRight` 区切りで並べるパンくず。**`href` 省略の項目は現在位置**としてリンクにしない。
atom 非依存(Lucide アイコンのみ)。単体で置かず、通常は PageHeaderSection 経由で出す。

### PageHeader / PageHeaderSection

実装: `components/molecules/PageHeaderSection.svelte`(full feature)と
`components/molecules/PageHeader.svelte`(shorthand)。

- **PageHeaderSection**: `title` / `breadcrumbs` / `description` / `icon`(Lucide 互換
  `Component`)/ actions(`children` Snippet)を持つ詳細画面用ヘッダ。全幅バーは
  PageContainer の padding を打ち消す**負マージン契約**で敷き、サイドバーのロゴブロックと
  同じ高さに揃える。**パンくずは 2 件以上のときだけ出す**(1 件は h1 と二重提示になるため)。
- **PageHeader**: breadcrumbs / actions を使わないルート画面用の薄いラッパー。
  内部で PageHeaderSection を呼ぶだけ。**actions や breadcrumbs が要るなら
  PageHeaderSection を直接使う**(PageHeader に prop を足さない)。
- actions は children Snippet で渡す(旧 slot API は使わない)。

### NotificationBell

実装: `components/molecules/NotificationBell.svelte`。`/notifications` への Inertia link に
未読数バッジを重ねた通知ベル。未読数は shared props(`notifications.unreadCount`)を親が渡す。
**100 以上は `99+` に丸める**。v1 はドロップダウンを持たない最小構成(フォーカス管理・
開閉状態を持たない)。**通知はこのベルが単一導線**で、サイドバー nav 項目に重複掲載しない。
`data-testid` は既定 `notification-bell`(mobile は呼び出し側が `notification-bell-mobile`)。

### PricingPlanCard

実装: `components/molecules/PricingPlanCard.svelte`(仕様の真実は `PricingPlanCard.types.ts`)。
料金プランカード。**DTO 非依存**(primitive props)で、feature 文言と CTA は呼び出し側が
props / Snippet で供給する。

- `priceAmount` が **null = 基本料金を持たない = 「無料」表示**(0 も防御的に同一表示)。
- `priceCaption`(例: 「基本料金」)は表示価格が総額と誤解されるのを防ぐための価格直上の説明。
- `isHighlighted` で `border-primary` の強調枠(現在のプラン等)。
- `headerBadges`(header 右上)/ `footerCta`(card 下部)は Snippet 専用スロット。

### ApiKeyTabNav

実装: `components/molecules/ApiKeyTabNav.svelte`。API キー管理ドメインのページ間
(API キー ⇔ 接続セッション ⇔ 導入ガイド)を **URL 遷移**(Inertia `Link`)で切替えるタブナビ。
同一ページ内 section 切替の `molecules/Tabs.svelte` とは責務が異なる。`tabs`(label + href +
active)はページ側が組み立てる(どのタブを出すか・URL は呼び出し側責務)。active タブに
`aria-current="page"` を付与する。

### RecentAuthModal

実装: `components/organisms/RecentAuthModal.svelte`(Modal の composition)。機微操作
(API キー発行/失効・アカウント削除・オーナー移譲)の前に出す**同一画面の再認証(step-up)
モーダル**。パスワード設定済みは再入力 → `POST /recent-auth/password`(成功は XHR 204)、
再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、パスキー登録済みは WebAuthn 検証。
認可の最終ゲートは各操作の recent-auth middleware で、本モーダルは UX 補助。

- **props 契約は `status: RecentAuthStatus | null` の 1 本**(`bind:open` / `onConfirmed` を除く)。
  `/recent-auth/status` の応答を field へ分解して手渡さない — field が増えるたびに配線漏れが
  生まれる(T106 で `passkeyAvailable` を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され
  passkey-only ユーザーが 5 画面で詰んだ)。`tsc --noEmit` は `.svelte` テンプレートを型検査
  しないため、強制点は `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`
  (deny-by-default。`status={recentAuthStatus}` の識別子・旧 prop 不在・`onStale` での代入まで検査)
- `status === null` は**状態不明**として扱い、空表示や事実に反する文言を出さず再読み込み導線を出す
- 再認証が成立しないユーザー(`canSatisfy=false` / この端末で実行不能)への回復導線は
  **`molecules/RecentAuthRecoveryNotice` に集約**する(下記)

### RecentAuthRecoveryNotice

実装: `components/molecules/RecentAuthRecoveryNotice.svelte`。再認証(step-up)が**この場では
成立しない**ユーザーに出す回復導線。全画面 confirm(`pages/Auth/ConfirmRecentAuth`)と
インラインモーダル(`organisms/RecentAuthModal`)の**両方が使う唯一の実装**(分けて持つと
片方だけ旧作法が残る)。

- `variant`: `no-satisfier`(アカウントに手段が無い)/ `not-executable-here`(手段はあるが
  この端末で実行できない = パスキー非対応ブラウザ)
- **`/forgot-password` へ直接リンクしない**。Fortify が `guest` middleware 付きで登録しており
  ログイン済みの本 UI 利用者はフォームに到達できない(踏破不能 CTA)。案内するのは
  「ログアウト → guest としてパスワード再設定」の経路だけ。アプリ内の初回設定
  (`POST /settings/password`)は recent-auth 必須なので、ここに来ているユーザーには使えない
- ログアウトは **Inertia visit(`router.post`)**(経路 C の保証条件。
  `tests/js/architecture/logout-call-site-inventory.test.ts` が inventory で固定)
- molecule 配置は構造的制約: 呼び出し元の RecentAuthModal は organism であり、
  atomic-import-graph 上 organism は features 層を import できない

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
- 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)
- **認証フロー画面(`AuthLayout`)には離脱導線を footer に必ず置く**。その手順を完了できない
  ユーザー(リンク期限切れ・コード紛失・再認証手段なし)が別の入口へ抜けられる `TextLink` を
  `{#snippet footer()}` に 1 つ以上持つ。行き先は**その画面のユーザーの認証状態で実際に
  踏破できる先**に限る(`tests/js/architecture/page-shell-structure.test.ts` が機械強制。
  例外は理由付き allowlist)

**Don't**

- グラデーション・ドロップシャドウ・scale 効果を使わない
- Danger と Tertiary を同一 action cluster・隣接 CTA 群で併置しない(赤系・強調系の意味が混ざる)
- **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
  押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
  disabled はユーザーに「なぜ押せないか」を伝えられない)
- **表示条件と踏破条件が食い違う導線を出さない**。押しても必ず失敗するボタン・リンク
  (認証・権限・ゲートで確実に弾かれる先を指すもの)は**出さずに、なぜ今は進めないかを
  文章で説明する**。disabled 化でも代替しない(上の Don't と同根。例: メール未認証画面から
  `verified` ゲート内の checkout へ進む CTA)
- ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる(`tests/js/architecture/form-novalidate.test.ts` が機械検証)

## 色の意味的割り当てルール

- **danger** = irreversible な喪失・破壊(削除・revoke・unassign・移譲・再開不可の中断)。
  確認 dialog があっても操作自体が不可逆ならボタン色は danger
- **warning** = 注意喚起 / 保留 / 可逆な要確認状態
- **tertiary** = 前向きな強調のみ(1 画面 1 箇所)
- **primary** = ブランド中核 / 主要 CTA / 選択中
- **neutral / text-secondary** = 中立・取消可能・UI-only の補助操作

action button(操作)と status badge(結果表示)は意味色を**独立に判断**する。


## 触れた atomic ディレクトリ構造

```
resources/js/components/
  atoms/           Button.svelte, TextLink.svelte, Badge.svelte, ...
  molecules/       SubtitleOverlay.svelte, PageHeaderSection.svelte, ...
  organisms/
  features/capture/  CameraRecorder.svelte, CutNavigator.svelte, GridOverlay.svelte,
                     TakeStrip.svelte, UploadQueueBar.svelte, CaptureFileFallback.svelte,
                     TakePreviewDialog.svelte, TakeCommentDialog.svelte,
                     **CutSwipeBar.svelte (新規)**, **ShootingGuideOverlay.svelte (新規)**
  templates/       AppLayout.svelte, PageContainer.svelte
resources/js/pages/Capture/Show.svelte
resources/js/lib/capture/  panel-navigation.ts, cut-labels.ts, camera.ts, ...,
                           **landscape-capture.ts (新規)**
```

## 実装差分 (git diff HEAD)

```diff
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index 1ca7f10..3c276e2 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -280,6 +280,24 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
   `window.crypto.subtle` が無い環境で Inertia は履歴を平文で保存する (`console.warn` のみ)。
   撮影 PWA は `getUserMedia` / Service Worker のためセキュアコンテキスト必須であり、
   degrade するのは中核機能が既に動かない環境に限られる。
+- **横持ち全画面の撮影 UI は、自動レーンでは DOM 契約と条件分岐だけを固定している**。
+  Browser レーン (Chromium + WebKit) が固定するのは「横持ちスマホ相当の context で
+  全画面へ切り替わること」「前後ボタンでカットが移動すること」「全画面を終了して
+  再入路から戻れること」「デスクトップ相当・高さ超過・細いポインタの 3 通りでは
+  切り替わらないこと」までである。
+  **「撮影ガイドの矩形が上下の字幕帯のいずれとも交差しないこと」は Chromium レーンだけが固定する** —
+  Playwright WebKit (Linux) には `MediaRecorder` が無く (実測: `typeof window.MediaRecorder`
+  が `"undefined"`)、撮影パネルがファイル選択フォールバックへ倒れて overlay が 1 つも
+  描画されないため、当該テストは前提を明示して skip する
+  (`tests/Browser/CaptureLandscapeFullscreenTest.php`)。
+  **これはレーンの能力差であって iOS Safari 実機の性質ではない**。
+  **実カメラを伴う挙動 (録画中に向きが変わったときの録画継続、CSS 全画面での
+  カメラプレビューの見え方、iOS Safari の動的ツールバーと `h-dvh` の相互作用、
+  端末の戻るジェスチャとスワイプの競合、`inert` 非対応環境でのフォーカス漏れ) は
+  どちらのレーンでも再現していない**。これらは実機受入確認の対象である。
+  依存する Web 機能と最低バージョン前提は
+  `devnotes/20260816-1021-landscape-fullscreen-capture/detailed-design.md` の
+  **「依存する Web 機能と最低バージョン前提」を正本とする** (版番号を本書に写さない)。
 
 ## Target — 到達目標 (未達)
 
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index 020b254..77a022b 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -13,6 +13,7 @@
     } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import GridOverlay from "@/components/features/capture/GridOverlay.svelte";
+    import ShootingGuideOverlay from "@/components/features/capture/ShootingGuideOverlay.svelte";
     import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
     import {
         classifyGetUserMediaError,
@@ -27,6 +28,7 @@
         CameraUnavailableReason,
         FacingMode,
     } from "@/lib/capture/camera";
+    import type { LayoutMode } from "@/lib/capture/landscape-capture";
     import type { CaptureCut } from "@/types/capture";
 
     /**
@@ -54,6 +56,17 @@
         subtitleSecondary?: CaptureCut["subtitle_secondary"];
         /** 撮影 active (starting || resuming || phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
         onCaptureActiveChange?: (active: boolean) => void;
+        /**
+         * 表示レイアウト (T186: 横持ち全画面)。**既定は従来どおり inline** で、
+         * 縦持ちの見た目は 1px も変わらない。
+         * 本 props は class の切替にしか使わず、**phase マシン・stream 管理には一切触れない**。
+         */
+        layout?: LayoutMode;
+        /**
+         * 撮影ガイド (撮影方法)。上流の CaptureCut["shooting_point"] の nullable 契約に合わせる。
+         * 非 null かつ非空へ絞る判定は本 component の内側 1 か所で行う。
+         */
+        shootingPoint?: CaptureCut["shooting_point"];
     }
 
     let {
@@ -62,8 +75,19 @@
         subtitlePrimary = null,
         subtitleSecondary = "",
         onCaptureActiveChange,
+        layout = "inline",
+        shootingPoint = null,
     }: Props = $props();
 
+    // --- 全画面レイアウト (表示のみ。phase マシンとは独立) ---
+    const isFullscreen = $derived(layout === "fullscreen");
+    /**
+     * trim は**空判定にのみ**使い、描画には元文字列をそのまま渡す
+     * (SubtitleOverlay と同じ作法。内容を書き換えない)。
+     */
+    const hasShootingGuide = $derived((shootingPoint ?? "").trim() !== "");
+    const showShootingGuide = $derived(isFullscreen && hasShootingGuide);
+
     // 単一ソース union (R2 反映: paused を追加)
     type Phase = "idle" | "recording" | "paused" | "stopping";
 
@@ -492,19 +516,35 @@
     });
 </script>
 
-<div class="flex flex-col gap-3">
-    <div class="relative">
+<!--
+  全画面と inline の切替は **class の差し替えだけ**で行う。
+  {#if} で描き分けると <video> が unmount され、録画中の MediaStream / MediaRecorder が
+  破棄されて録ったデータが消えるため。
+
+  **操作行は全画面でも映像に重ねない**。映像を flex-1 で伸ばし、操作行は不透明な面の上に
+  そのまま置く。半透明の帯を敷いてアイコンのコントラストを別途担保する道を採らないのは、
+  「仕組みが機能していない段階で値 (色) を弄るな」という原則と、
+  contrast-invariant の検査対象を無駄に増やさないためである。
+-->
+<div class={isFullscreen ? "flex h-full min-h-0 flex-col gap-2" : "flex flex-col gap-3"}>
+    <div class={isFullscreen ? "relative min-h-0 flex-1 overflow-hidden rounded-md" : "relative"}>
         <!-- svelte-ignore a11y_media_has_caption -->
         <video
             bind:this={video}
             autoplay
             playsinline
             muted
-            class="aspect-video w-full rounded-md bg-surface object-cover"
+            class={isFullscreen
+                ? "size-full bg-surface object-cover"
+                : "aspect-video w-full rounded-md bg-surface object-cover"}
             data-testid="camera-preview"
         ></video>
-        <!-- overlay の z 順 (DOM 順で映像 < grid < 字幕帯): グリッドは字幕より先 = 下層 -->
+        <!-- overlay の z 順 (DOM 順で 映像 < grid < 撮影ガイド < 字幕帯) -->
         <GridOverlay visible={showGrid} />
+        {#if showShootingGuide}
+            <!-- 描画には元文字列を渡す (trim は showShootingGuide の空判定にだけ使う) -->
+            <ShootingGuideOverlay text={shootingPoint ?? ""} />
+        {/if}
         <SubtitleOverlay
             primary={subtitlePrimary}
             secondary={subtitleSecondary}
@@ -522,7 +562,11 @@
             </div>
         {/if}
     </div>
-    <div class="flex items-center justify-center gap-3">
+    <div
+        class={isFullscreen
+            ? "flex shrink-0 items-center justify-center gap-3"
+            : "flex items-center justify-center gap-3"}
+    >
         {#if phase === "idle"}
             <Button variant="primary" onclick={startRecording} testId="start-recording">
                 <Circle class="size-4" aria-hidden="true" />
@@ -597,6 +641,7 @@
         </button>
     </div>
     {#if error}
-        <p class="text-center text-caption text-danger" role="alert">{error}</p>
+        <!-- 全画面でも重ねないので class は共通のまま (経験値の位置合わせが不要になった) -->
+        <p class="shrink-0 text-center text-caption text-danger" role="alert">{error}</p>
     {/if}
 </div>
diff --git a/resources/js/components/features/capture/CutSwipeBar.svelte b/resources/js/components/features/capture/CutSwipeBar.svelte
new file mode 100644
index 0000000..65daaec
--- /dev/null
+++ b/resources/js/components/features/capture/CutSwipeBar.svelte
@@ -0,0 +1,152 @@
+<script lang="ts">
+    import { ChevronLeft, ChevronRight } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import {
+        resolveSwipe,
+        swipeDirection,
+        type NavigationDirection,
+    } from "@/lib/capture/landscape-capture";
+
+    /**
+     * 横持ち全画面の上部カット名エリア (doc/05 §5.2)。
+     * **左右スワイプ / 前後ボタン / 左右矢印キー**の 3 手段でカットを前後に移動する。
+     * スワイプだけにしないのは、キーボード・スクリーンリーダー利用者に到達不能であり、
+     * 手袋を着けた現場作業者にも失敗しやすいためである。
+     *
+     * ラベル (手順 N / 急所 N-M) は **受け取るだけ**で自前では組み立てない
+     * (lib/capture/cut-labels.ts の buildCutLabels() が唯一の導出元。二重管理を作らない)。
+     * 端に着いたときの告知は親が持つ (判断の置き場所を 1 か所に保つ) ため、
+     * 本 component は端かどうかを知らない = ボタンを disabled にする理由も持たない。
+     */
+    interface Props {
+        /** 例: "手順 2" / "急所 2-1"。buildCutLabels() の結果をそのまま受ける */
+        label: string;
+        /** カット内容 (CutNavigator の行と同じ出所) */
+        scene: string;
+        /** 現在位置。index は 1 起点 (表示にそのまま使う) */
+        position: { index: number; total: number };
+        onNavigate: (direction: NavigationDirection) => void;
+    }
+
+    let { label, scene, position, onNavigate }: Props = $props();
+
+    /** 進行中のポインタ ID と始点。pointerdown で採り、pointerup / cancel で捨てる */
+    let gesture: { pointerId: number; startX: number; startY: number } | null = null;
+
+    /**
+     * 画面端の除外判定に使う viewport 幅。非ブラウザ実行では 0 を返す。
+     * 0 のとき resolveSwipe は必ず "none" を返す = **移動しない側へ倒れる**
+     * (panel-navigation.ts の prefersReducedMotion() が非対応環境で「動かさない」へ
+     * 倒すのと同じ思想。安全側は常に「何もしない」)。
+     */
+    function viewportWidth(): number {
+        return typeof window === "undefined" ? 0 : window.innerWidth;
+    }
+
+    /**
+     * ボタンの上で始まった操作はスワイプとして扱わない。
+     * 扱ってしまうと「ボタンを押しながら 48px 以上動かす」で
+     * 親の pointerup による移動と button の click による移動が**二重発火**し、
+     * 1 操作で 2 カット進んでしまう。
+     */
+    function startedOnButton(event: PointerEvent): boolean {
+        const target = event.target;
+
+        return target instanceof Element && target.closest("button") !== null;
+    }
+
+    function handlePointerDown(event: PointerEvent): void {
+        if (startedOnButton(event)) {
+            gesture = null;
+
+            return;
+        }
+        gesture = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY };
+    }
+
+    function handlePointerUp(event: PointerEvent): void {
+        const started = gesture;
+        gesture = null;
+        if (started === null || started.pointerId !== event.pointerId) return;
+        const direction = swipeDirection(
+            resolveSwipe({
+                startX: started.startX,
+                startY: started.startY,
+                endX: event.clientX,
+                endY: event.clientY,
+                viewportWidth: viewportWidth(),
+            }),
+        );
+        if (direction === null) return;
+        onNavigate(direction);
+    }
+
+    /** ジェスチャ中断 (別要素へ持って行かれた等) は始点ごと捨てる */
+    function handlePointerCancel(): void {
+        gesture = null;
+    }
+
+    function handleKeydown(event: KeyboardEvent): void {
+        if (event.key === "ArrowLeft") {
+            event.preventDefault();
+            onNavigate(-1);
+
+            return;
+        }
+        if (event.key === "ArrowRight") {
+            event.preventDefault();
+            onNavigate(1);
+        }
+    }
+</script>
+
+<!--
+  touch-pan-y: 横方向のブラウザ既定スクロールを止め、縦スクロールは残す
+  (静的 inline style を書かずに touch-action を指定する。ds-purity)。
+
+  **このバー自体はフォーカス対象にしない** (tabindex を持たない)。
+  キーイベントは内側の前後ボタンからバブルしてくるので、
+  「前後ボタンにフォーカスがある状態で左右キー」は tabindex 無しでも成立する。
+  バーを Tab 停止にすると、同じ目的の停止が 3 つ (バー + 前 + 次) に増えて操作が冗長になる。
+  svelte-ignore: 非対話要素へのイベントだが、**操作の入口は内側の 2 つの button** であり、
+  ここのハンドラはそれを補うだけ (キーはバブル、ポインタは帯全体を当たり判定にするため)。
+-->
+<!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
+<div
+    class="flex touch-pan-y items-center gap-2 rounded-md border border-border bg-surface/90 px-2 py-1"
+    role="group"
+    aria-label="カットの移動"
+    onpointerdown={handlePointerDown}
+    onpointerup={handlePointerUp}
+    onpointercancel={handlePointerCancel}
+    onkeydown={handleKeydown}
+    data-testid="cut-swipe-bar"
+>
+    <Button
+        variant="ghost"
+        size="sm"
+        iconOnly
+        ariaLabel="前のカット"
+        onclick={() => onNavigate(-1)}
+        testId="cut-swipe-previous"
+    >
+        <ChevronLeft class="size-5" aria-hidden="true" />
+    </Button>
+    <div class="min-w-0 flex-1 text-center">
+        <p class="text-caption text-text-secondary" data-testid="cut-swipe-label">
+            {label}
+            <span class="ml-1">{position.index} / {position.total}</span>
+        </p>
+        <p class="truncate text-body" data-testid="cut-swipe-scene">{scene}</p>
+    </div>
+    <Button
+        variant="ghost"
+        size="sm"
+        iconOnly
+        ariaLabel="次のカット"
+        onclick={() => onNavigate(1)}
+        testId="cut-swipe-next"
+    >
+        <ChevronRight class="size-5" aria-hidden="true" />
+    </Button>
+</div>
diff --git a/resources/js/components/features/capture/ShootingGuideOverlay.svelte b/resources/js/components/features/capture/ShootingGuideOverlay.svelte
new file mode 100644
index 0000000..345c0cf
--- /dev/null
+++ b/resources/js/components/features/capture/ShootingGuideOverlay.svelte
@@ -0,0 +1,48 @@
+<script lang="ts">
+    import { Lightbulb } from "@lucide/svelte";
+
+    /**
+     * 撮影ガイド (撮影方法 = cuts.shooting_point) の透過オーバーレイ (doc/05 §5.2:
+     * 「電球アイコンの横に、そのカットの撮影方法（構図指示）を表示」)。
+     * 焼込ではなく撮影ガイド overlay で、MediaRecorder が録る MediaStream には含まれない。
+     *
+     * **表示可否は親が決める** — 「非空の shooting_point があり、かつ全画面のとき」だけ親が描画する。
+     * GridOverlay の `visible` 形には揃えない: グリッドは内容を持たない装飾だが、
+     * こちらはカットごとに変わる文字列であり、「空文字列」と「非表示」の 2 状態を
+     * 子に持ち込む理由が無いため (型で不正状態を減らす)。
+     *
+     * **レーンは三分割の上ライン (`top-1/3`)**。SubtitleOverlay は
+     * `absolute inset-0 p-3 flex flex-col justify-between` で **上端帯 = primary /
+     * 下端帯 = secondary** を占めるため、上端に置くと primary と帯を奪い合い、
+     * DOM 順で字幕が上になる以上**撮影ガイドが隠れて読めなくなる**。
+     * 中間帯なら上下どちらの字幕帯とも交差しない。
+     * 三分割線に沿う位置は構図指示として意味があり、GridOverlay の線とも一致する。
+     * 非交差は Browser テストで矩形を実測して固定する (jsdom はレイアウトを持たない)。
+     *
+     * z 順は 映像 < グリッド < **撮影ガイド** < 字幕帯 (DOM 順で表現する)。
+     * レーンが分かれているので通常は重ならないが、極端に長い字幕で万一重なった場合は
+     * 字幕が上になる (v1 の中核価値が字幕であるため)。
+     */
+    interface Props {
+        text: string;
+    }
+
+    let { text }: Props = $props();
+</script>
+
+<!--
+  幅の制限は**任意値を使わず**コンテナの px-3 と max-w-full で行う
+  (DESIGN.md の「token / 既存 utility の範囲で表現する」に寄せる。
+  既存 SubtitleOverlay の max-w-[90%] には倣わない = 新設分で任意値を増やさない)。
+-->
+<div
+    class="pointer-events-none absolute inset-x-0 top-1/3 flex justify-center px-3"
+    data-testid="shooting-guide-overlay"
+>
+    <p
+        class="line-clamp-2 flex max-w-full items-start gap-1 rounded-sm bg-text/70 px-3 py-1 text-caption text-surface"
+    >
+        <Lightbulb class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
+        <span class="min-w-0">{text}</span>
+    </p>
+</div>
diff --git a/resources/js/lib/capture/landscape-capture.ts b/resources/js/lib/capture/landscape-capture.ts
new file mode 100644
index 0000000..811daca
--- /dev/null
+++ b/resources/js/lib/capture/landscape-capture.ts
@@ -0,0 +1,192 @@
+/**
+ * 横持ち全画面撮影の判定・ジェスチャ解釈・移動判断・背景スクロール抑止 (doc/05 §5.2)。
+ *
+ * panel-navigation.ts と同じ方針で **副作用ごとここに置く**。述語だけを切り出すと
+ * 「抑止条件が実際に副作用を止めているか」を page component の外から検証できず、
+ * 回帰を固定できない。
+ */
+
+/** 撮影パネルのレイアウト種別。CameraRecorder の Phase union と同じ書き方に揃える。 */
+export type LayoutMode = "inline" | "fullscreen";
+
+/** カット移動の向き。-1 = 前へ / +1 = 次へ。 */
+export type NavigationDirection = -1 | 1;
+
+/**
+ * 横持ち全画面へ入る条件。**ここが唯一の正本**で、Tailwind の breakpoint 値はコピーしない。
+ *
+ * - `orientation: landscape` … 横持ち。
+ * - `max-height: 540px`      … 横持ちスマホの短辺 (iPhone SE 320 / 15 Pro 393 /
+ *                              大型 Android 412) を含み、タブレット横持ち (iPad 768) と
+ *                              ノート PC を含まない高さ。
+ * - `pointer: coarse`        … 指で操作する端末に限る (スワイプ前提の UI のため)。
+ *
+ * 3 条件は**すべて必要**である。どれかが式から落ちるとデスクトップまで全画面になるため、
+ * 文字列そのものを landscape-capture.test.ts が固定し、Browser の負のコントロール 3 本が
+ * 条件ごとの欠落を実挙動で検出する。
+ */
+export const LANDSCAPE_CAPTURE_MEDIA_QUERY =
+    "(orientation: landscape) and (max-height: 540px) and (pointer: coarse)";
+
+/**
+ * 現在が横持ち全画面の条件を満たすか。
+ * SSR / matchMedia 非対応では **false** (= 全画面にしない) に倒す。
+ * 「既存レイアウトのまま」は常に安全側で、逆 (存在しない環境で全画面に入る) は
+ * 抜け出す手段が無くなるため採らない。
+ */
+export function matchesLandscapeCapture(): boolean {
+    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return false;
+
+    return window.matchMedia(LANDSCAPE_CAPTURE_MEDIA_QUERY).matches;
+}
+
+/**
+ * 横持ち判定の変化を購読する。**登録直後に現在値で 1 回呼ぶ**
+ * (change イベントを待つと初期表示が縦持ち扱いのままになるため)。
+ * 戻り値は解除関数。matchMedia 非対応環境では何もせず no-op を返す。
+ *
+ * legacy な `addListener` へのフォールバックは**書かない**。撮影 PWA が要求する
+ * MediaRecorder の最低版 (iOS Safari 14.5) は addEventListener の対応版 (14) より
+ * 新しく、二重の登録経路は後方互換の並走にしかならない (AGENTS.md 思考原則 3)。
+ */
+export function subscribeLandscapeCapture(onChange: (matches: boolean) => void): () => void {
+    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
+        return () => undefined;
+    }
+    const list = window.matchMedia(LANDSCAPE_CAPTURE_MEDIA_QUERY);
+    const handler = (event: MediaQueryListEvent): void => onChange(event.matches);
+    list.addEventListener("change", handler);
+    onChange(list.matches);
+
+    return () => list.removeEventListener("change", handler);
+}
+
+/* ---- スワイプ判定 ---- */
+
+/** 水平移動がこの px 以上でスワイプとみなす (タップ・微小な指ぶれを弾く)。 */
+export const SWIPE_MIN_DISTANCE_PX = 48;
+/** 縦方向のブレ許容比。|dy| がこの比率を超えたら縦スクロール意図とみなし移動しない。 */
+export const SWIPE_MAX_OFF_AXIS_RATIO = 0.6;
+/**
+ * 画面左右端のこの幅から始まったスワイプは扱わない。
+ * iOS Safari の戻る/進むジェスチャは JS から抑止できないため、
+ * **競合させずに譲る** (誤爆で意図せずカットが動くのを防ぐ)。
+ */
+export const SWIPE_EDGE_EXCLUSION_PX = 24;
+
+export type SwipeOutcome = "previous" | "next" | "none";
+
+export interface SwipeGestureInput {
+    startX: number;
+    startY: number;
+    endX: number;
+    endY: number;
+    /** ジェスチャ時点の viewport 幅 (右端の除外判定に使う) */
+    viewportWidth: number;
+}
+
+/**
+ * ポインタの始点・終点からカット移動の向きを決める。
+ * 左へスワイプ (dx < 0) = 次のカット、右へスワイプ (dx > 0) = 前のカット
+ * (カルーセルと同じ「内容が指について動く」向き)。
+ *
+ * viewport 幅が除外幅の 2 倍以下 (viewportWidth() が 0 を返す非ブラウザ実行を含む) では
+ * 左右の除外帯が画面全体を覆うため、必ず "none" = **移動しない側へ倒れる**。
+ */
+export function resolveSwipe(input: SwipeGestureInput): SwipeOutcome {
+    const { startX, startY, endX, endY, viewportWidth } = input;
+    if (startX <= SWIPE_EDGE_EXCLUSION_PX) return "none";
+    if (startX >= viewportWidth - SWIPE_EDGE_EXCLUSION_PX) return "none";
+    const dx = endX - startX;
+    const dy = endY - startY;
+    if (Math.abs(dx) < SWIPE_MIN_DISTANCE_PX) return "none";
+    if (Math.abs(dy) > Math.abs(dx) * SWIPE_MAX_OFF_AXIS_RATIO) return "none";
+
+    return dx < 0 ? "next" : "previous";
+}
+
+/** SwipeOutcome を移動の向きへ写像する (none は移動しない)。 */
+export function swipeDirection(outcome: SwipeOutcome): NavigationDirection | null {
+    if (outcome === "next") return 1;
+    if (outcome === "previous") return -1;
+
+    return null;
+}
+
+/* ---- 移動判断 (告知文の唯一の出所) ---- */
+
+/** 端に着いたときの告知。スワイプ・ボタン・キー操作の 3 手段が同じ文言を共有する。 */
+export const CUT_EDGE_MESSAGES = {
+    first: "これが最初のカットです。",
+    last: "これが最後のカットです。",
+} as const;
+
+/**
+ * 録画中の移動拒否。**押下時にエラーを出す** (禁止事項 8: disabled にしない)。
+ * 文中の「録画を停止」は全画面上に常時可視な停止ボタンを指す =
+ * 告知した次の操作が同じ画面に必ず存在する (行き先のない詰みを作らない)。
+ */
+export const RECORDING_BLOCKS_NAVIGATION_MESSAGE =
+    "録画中はカットを移動できません。録画を停止してから移動してください。";
+
+export type CutNavigationDecision =
+    | { kind: "move"; cutId: number }
+    | { kind: "notice"; tone: "status" | "alert"; message: string }
+    | { kind: "ignore" };
+
+export interface CutNavigationInput {
+    /**
+     * CameraRecorder の公開 active (`starting || resuming || phase !== "idle"`)。
+     * getUserMedia の grant 待ち 2 窓を含むため、権限ダイアログ中の移動も止まる
+     * (panel-navigation.ts の抑止条件と**同じ判断基準**)。
+     */
+    captureActive: boolean;
+    /** manual.cuts の並び順そのもの (CutNavigator の表示順)。別のソート規則を持ち込まない。 */
+    cuts: readonly { id: number }[];
+    currentCutId: number | null;
+    direction: NavigationDirection;
+}
+
+/**
+ * カット移動の可否と結果を 1 か所で決める。
+ *
+ * **自動停止はしない**。誤スワイプで録画が確定するのは現場で取り返しがつかず、
+ * 既存 `CameraRecorder.releaseForPreview()` が録画中は no-op (= 暗黙終了しない) という
+ * 確立済みの契約とも一致する。
+ */
+export function decideCutNavigation(input: CutNavigationInput): CutNavigationDecision {
+    const { captureActive, cuts, currentCutId, direction } = input;
+    if (captureActive) {
+        return { kind: "notice", tone: "alert", message: RECORDING_BLOCKS_NAVIGATION_MESSAGE };
+    }
+    if (currentCutId === null) return { kind: "ignore" };
+    const index = cuts.findIndex((cut) => cut.id === currentCutId);
+    if (index < 0) return { kind: "ignore" };
+    const target = cuts[index + direction];
+    if (target === undefined) {
+        const edge = direction < 0 ? "first" : "last";
+
+        return { kind: "notice", tone: "status", message: CUT_EDGE_MESSAGES[edge] };
+    }
+
+    return { kind: "move", cutId: target.id };
+}
+
+/* ---- 背景スクロール抑止 ---- */
+
+/** 抑止に使う Tailwind utility。静的 inline style を書かないため class で行う (ds-purity)。 */
+const SCROLL_LOCK_CLASS = "overflow-hidden";
+
+/**
+ * 全画面中に背後ページがスクロールするのを止める。**戻り値の解除関数が単一のクリーンアップ点**で、
+ * 解除漏れは「スクロールできない詰み」になるため他所で class を触らない。
+ * 既に他所が同じ class を付けていた場合は**外さない** (他所の抑止を横から解除しない)。
+ */
+export function lockBackgroundScroll(): () => void {
+    if (typeof document === "undefined") return () => undefined;
+    const element = document.documentElement;
+    if (element.classList.contains(SCROLL_LOCK_CLASS)) return () => undefined;
+    element.classList.add(SCROLL_LOCK_CLASS);
+
+    return () => element.classList.remove(SCROLL_LOCK_CLASS);
+}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 43c40ed..9d71644 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -1,7 +1,8 @@
 <script lang="ts">
-    import { onMount, tick } from "svelte";
+    import { onMount, tick, untrack } from "svelte";
     import { page, router } from "@inertiajs/svelte";
-    import { ArrowLeft, BookOpen, Video } from "@lucide/svelte";
+    import { ArrowLeft, BookOpen, Maximize, Minimize, Video } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
@@ -9,6 +10,7 @@
     import type CameraRecorderType from "@/components/features/capture/CameraRecorder.svelte";
     import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
     import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
+    import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
     import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
     import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
@@ -16,6 +18,13 @@
     import { supportsMediaRecorder } from "@/lib/capture/camera";
     import type { CameraUnavailableReason } from "@/lib/capture/camera";
     import { buildCutLabels } from "@/lib/capture/cut-labels";
+    import {
+        decideCutNavigation,
+        lockBackgroundScroll,
+        matchesLandscapeCapture,
+        subscribeLandscapeCapture,
+        type NavigationDirection,
+    } from "@/lib/capture/landscape-capture";
     import {
         isStackedLayout,
         navigateBackToList,
@@ -44,7 +53,21 @@
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
-    let selectedCutId = $state<number | null>(null);
+    /**
+     * 横持ち全画面の初期判定。**テンプレートの初回描画より前**に確定させるため、
+     * script のこの位置 (props 受領直後) で 1 度だけ評価する。
+     * これより後ろで宣言すると selectedCutId の初期化が宣言前参照 (TDZ) になる。
+     */
+    const initialLandscape = matchesLandscapeCapture();
+
+    /* 初期描画で全画面になる場合は、**同じ script 評価の中で**先頭カットも選んでおく。
+     * 選ばずに全画面へ入ると、最初の 1 描画だけ「カットを選び直してください。」が出る。
+     * mount 時点の値で確定させるのが意図どおりなので state_referenced_locally を明示的に無視する
+     * (以降の追従は横持ち購読の $effect が担う)。 */
+    // svelte-ignore state_referenced_locally
+    let selectedCutId = $state<number | null>(
+        initialLandscape ? (manual.cuts[0]?.id ?? null) : null,
+    );
     const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
     /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
     const cutLabels = $derived(buildCutLabels(manual.cuts));
@@ -138,7 +161,144 @@
         );
     }
 
+    /* ---- 横持ち全画面 (doc/05 §5.2 / T186) ----
+     * 判定・ジェスチャ解釈・移動判断・スクロール抑止は lib/capture/landscape-capture.ts が持ち、
+     * ここは配線だけを行う (panel-navigation.ts と同じ役割分担)。 */
+    /**
+     * 横持ち全画面の条件 (向き + 高さ + 粗いポインタ) を満たすか。
+     *
+     * **初期値は script 評価時に確定させる** (initialLandscape)。`$effect` はテンプレートの
+     * 初回描画の**後**に走るため、`$state(false)` から effect で入れる形にすると
+     * 「最初の 1 描画だけ inline レイアウト」というちらつきが必ず残る。
+     *
+     * **この方式は「Inertia SSR が配線されていない」ことに依存する**。
+     * SSR を入れるとサーバは inline、クライアントの初期評価は fullscreen になり得るため
+     * hydration が食い違う (詳細設計の「再確認条件」)。
+     */
+    let landscapeMatches = $state(initialLandscape);
+    /** 利用者が明示的に全画面を終了したか。**縦に戻すまで自動で入り直さない**ためのラッチ */
+    let fullscreenDismissed = $state(false);
+    /**
+     * 実際に全画面を描くか。
+     * **選択状態ではなく「撮るものがあるか」で決める** (`manual.cuts.length > 0`)。
+     * `selectedCut !== null` を条件にすると、自動選択が反映される前の 1 フレームだけ
+     * inline レイアウトが描かれてちらつく。また全画面中に reload で選択中カットが
+     * 消えたときに「全画面なのに終了ボタンが無い」状態を作りかねない。
+     */
+    const fullscreenActive = $derived(
+        landscapeMatches && !fullscreenDismissed && manual.cuts.length > 0,
+    );
+    /** 端の告知 (status) / 録画中の移動拒否 (alert)。文言の出所は landscape-capture.ts */
+    let navigationNotice = $state<{ tone: "status" | "alert"; message: string } | null>(null);
+    /** 全画面の現在位置表示 (1 起点)。cuts の並び順そのものを使う */
+    const cutPosition = $derived({
+        index: selectedCut === null ? 0 : manual.cuts.findIndex((c) => c.id === selectedCut.id) + 1,
+        total: manual.cuts.length,
+    });
+    /** 全画面へ入った直後のフォーカス着地点 (背後に取り残さない)。tabindex="-1" */
+    let fullscreenHeadingEl = $state<HTMLElement | null>(null);
+    /** 直前に運んだ全画面状態。true への遷移でちょうど 1 回だけフォーカスを運ぶ */
+    let lastFullscreenFocused = false;
+
+    // 横持ち判定の購読。**初期値は script 評価時に確定済み**なので、この effect が担うのは
+    // 「向きが変わったときの追従」だけである。追従に伴う後始末は同じ同期ブロックの中で済ませる
+    // (2 本の effect に分けると、landscapeMatches が反映された描画と selectedCutId が
+    //  入った描画の間に 1 フレーム挟まり、inline レイアウトが一瞬見えてしまう)。
+    //  - 縦に戻ったらラッチを解除する (次に横へ倒せばまた自動で全画面に入る)
+    //  - 横持ちでカット未選択なら先頭カットを自動選択する (何も撮れない全画面を作らない)
+    // manual / selectedCutId は untrack で読む (選択やリロードで購読を張り直さない)。
+    $effect(() =>
+        subscribeLandscapeCapture((matches) => {
+            landscapeMatches = matches;
+            if (!matches) {
+                fullscreenDismissed = false;
+
+                return;
+            }
+            const first = untrack(() => manual.cuts)[0];
+            if (first !== undefined && untrack(() => selectedCutId) === null) {
+                selectedCutId = first.id;
+            }
+        }),
+    );
+
+    // 全画面へ入ったらフォーカスを全画面内へ運ぶ。
+    // 背後 (ヘッダ / 左 pane) は inert にするが、AppLayout の chrome は覆わないため、
+    // 開始位置を明示的に全画面内へ置くことでキーボード利用者が背後から始まらないようにする。
+    $effect(() => {
+        if (fullscreenActive === lastFullscreenFocused) return;
+        lastFullscreenFocused = fullscreenActive;
+        if (!fullscreenActive) return;
+        fullscreenHeadingEl?.focus({ preventScroll: true });
+    });
+
+    // 全画面中だけ背後のスクロールを止める。**解除は戻り値の 1 か所に集約**する
+    // (終了ボタン / 縦復帰 / ページ離脱のどれでも必ず外れる = スクロール不能の詰みを作らない)。
+    $effect(() => {
+        if (!fullscreenActive) return;
+
+        return lockBackgroundScroll();
+    });
+
+    /**
+     * 全画面でのカット移動 (スワイプ / 前後ボタン / 左右矢印キーの共通の受け口)。
+     * 可否と文言の判断は decideCutNavigation が 1 か所で持つ (ここは配線だけ)。
+     * **録画中は移動せずその場でエラーを出す** — 自動停止しない (誤スワイプで録画を確定させない)。
+     */
+    function handleCutNavigate(direction: NavigationDirection): void {
+        const decision = decideCutNavigation({
+            captureActive,
+            cuts: manual.cuts,
+            currentCutId: selectedCutId,
+            direction,
+        });
+        if (decision.kind === "move") {
+            navigationNotice = null;
+            selectedCutId = decision.cutId;
+
+            return;
+        }
+        if (decision.kind === "notice") {
+            navigationNotice = { tone: decision.tone, message: decision.message };
+
+            return;
+        }
+        navigationNotice = null; // ignore: 移動対象が無い (自動選択があるため通常は到達しない)
+    }
+
+    /**
+     * 全画面を終了する。横持ちのまま既存レイアウトへ戻るので、
+     * **現在位置を見失わせない**よう視点とフォーカスを撮影パネルへ運ぶ (既存機構を再利用)。
+     */
+    function exitFullscreen(): void {
+        fullscreenDismissed = true;
+        navigationNotice = null;
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
+    /**
+     * 全画面へ戻る手動の再入路。ラッチ (fullscreenDismissed) を解除する。
+     * これが無いと「端末を一度縦に倒し直さないと全画面へ帰れない」行き止まりになる。
+     * 未選択なら先頭カットを選ぶ (押しても何も起きない、を作らない)。
+     */
+    function enterFullscreen(): void {
+        const first = manual.cuts[0];
+        if (selectedCutId === null && first !== undefined) selectedCutId = first.id;
+        navigationNotice = null;
+        fullscreenDismissed = false;
+    }
+
     function handleSelectCut(cutId: number): void {
+        navigationNotice = null; // カットを選び直したら古い告知を捨てる
         selectedCutId = cutId;
         // DOM 反映後に測る (撮影パネルは選択で初めて描画される)
         void tick().then(() => {
@@ -270,121 +430,240 @@
 
 <AppLayout {appName}>
     <PageContainer>
-        <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
-            <TextLink href={`/app/projects/${project.id}/manuals`}>
-                <ArrowLeft class="inline size-3" aria-hidden="true" />
-                一覧へ戻る
-            </TextLink>
-            <!-- PC 側詳細への復路 (T155)。**この画面へ到達できた利用者に対しては、追加の
-                 status / ability 条件で出し分けない**。根拠と保証範囲は
-                 docs/architecture.md §撮影 PWA の運用契約。 -->
-            <TextLink
-                href={`/projects/${project.id}/manuals/${manual.id}`}
-                testId="manual-detail-link"
-            >
-                <BookOpen class="inline size-3" aria-hidden="true" />
-                マニュアル詳細へ
-            </TextLink>
-        </PageHeaderSection>
-
-        <div class="mt-3">
-        <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
-    </div>
+        <!-- 全画面中は背後を inert にして、覆われた面へ Tab で入り込めないようにする -->
+        <div inert={fullscreenActive}>
+            <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
+                <TextLink href={`/app/projects/${project.id}/manuals`}>
+                    <ArrowLeft class="inline size-3" aria-hidden="true" />
+                    一覧へ戻る
+                </TextLink>
+                <!-- PC 側詳細への復路 (T155)。**この画面へ到達できた利用者に対しては、追加の
+                     status / ability 条件で出し分けない**。根拠と保証範囲は
+                     docs/architecture.md §撮影 PWA の運用契約。 -->
+                <TextLink
+                    href={`/projects/${project.id}/manuals/${manual.id}`}
+                    testId="manual-detail-link"
+                >
+                    <BookOpen class="inline size-3" aria-hidden="true" />
+                    マニュアル詳細へ
+                </TextLink>
+            </PageHeaderSection>
+        </div>
+
+        <!-- UploadQueueBar は全画面かどうかで **どちらか一方にだけ** 置く
+             (両方に置くと data-testid が重複してテストの指し先が曖昧になる)。
+             UploadQueueBar は props だけの表示 component なので、
+             切替時に作り直されても失われる状態が無い。 -->
+        {#if !fullscreenActive}
+            <div class="mt-3">
+                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
+            </div>
+        {/if}
 
     <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
         <section
             bind:this={leftPaneEl}
+            inert={fullscreenActive}
             class="min-w-0 rounded-md border border-border bg-surface"
             data-testid="capture-left-pane"
         >
-            <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
-                 フォーカス可能にする (Tab 順には入れない)。 -->
-            <h2
-                bind:this={cutListHeadingEl}
-                tabindex="-1"
-                class="border-b border-border px-3 py-2 text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
-                data-testid="capture-cut-list-heading"
-            >
-                シナリオ (タップして撮影)
-            </h2>
+            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
+                <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
+                     フォーカス可能にする (Tab 順には入れない)。 -->
+                <h2
+                    bind:this={cutListHeadingEl}
+                    tabindex="-1"
+                    class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+                    data-testid="capture-cut-list-heading"
+                >
+                    シナリオ (タップして撮影)
+                </h2>
+                <!-- 横持ちなのに全画面でないとき (= 明示終了した後) の再入路。
+                     文脈非該当時は非表示にする (disabled ではない)。 -->
+                {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
+                    <Button
+                        variant="neutral"
+                        size="sm"
+                        onclick={enterFullscreen}
+                        testId="enter-fullscreen-capture"
+                    >
+                        <Maximize class="size-4" aria-hidden="true" />
+                        全画面で撮影
+                    </Button>
+                {/if}
+            </div>
             <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
         </section>
 
+        <!--
+          全画面は **この section の class を差し替えるだけ**で作る。
+          CameraRecorder を別の {#if} ブランチへ移すと unmount され、録画中の
+          MediaStream / MediaRecorder が破棄されて録ったデータが消えるため。
+          fixed + h-dvh: iOS Safari の動的ツールバーで下端が隠れないようにする
+          (inset-0 だと bottom がツールバー下へ潜りうる)。
+          z-40: AppLayout のモバイルヘッダ (sticky z-30) を覆い、
+          Toast (z-50) は上に残す (アップロード失敗の告知を隠さない)。
+        -->
         <section
             bind:this={rightPaneEl}
-            class="flex min-w-0 flex-col gap-4"
+            class={fullscreenActive
+                ? "fixed inset-x-0 top-0 z-40 flex h-dvh min-w-0 flex-col gap-2 bg-surface p-2"
+                : "flex min-w-0 flex-col gap-4"}
             data-testid="capture-right-pane"
+            data-fullscreen={fullscreenActive ? "true" : "false"}
         >
-            {#if selectedCut === null}
-                <p class="text-caption text-text-secondary">
-                    左のシナリオからカットを選ぶと撮影パネルが開きます。
-                </p>
-            {:else}
-                <div class="flex items-center justify-between gap-2">
-                    <!-- カット選択時のフォーカス着地点。ラベルを含めて「どのカットの撮影か」を
-                         名前で伝える (視点だけ運んでフォーカスを残すと a11y 欠落を作るため)。 -->
-                    <h2
-                        bind:this={recordingHeadingEl}
-                        tabindex="-1"
-                        class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
-                        data-testid="capture-recording-heading"
+            {#if fullscreenActive}
+                <!-- 全画面へ入った直後のフォーカス着地点。読み上げ順の先頭に置く -->
+                <h2
+                    bind:this={fullscreenHeadingEl}
+                    tabindex="-1"
+                    class="sr-only"
+                    data-testid="capture-fullscreen-heading"
+                >
+                    全画面撮影
+                </h2>
+                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
+                <!--
+                  **終了ボタンは selectedCut の有無に依らずここに置く**。
+                  出口の有無を選択状態という別の軸に結び付けない
+                  (結び付けると「全画面なのに出口が無い」状態を作りうる)。
+                -->
+                <div class="flex items-center gap-2">
+                    <div class="min-w-0 flex-1">
+                        {#if selectedCut !== null}
+                            <CutSwipeBar
+                                label={cutLabels[selectedCut.id] ?? "選択中カット"}
+                                scene={selectedCut.scene}
+                                position={cutPosition}
+                                onNavigate={handleCutNavigate}
+                            />
+                        {:else}
+                            <p class="text-caption text-text-secondary">
+                                カットを選び直してください。
+                            </p>
+                        {/if}
+                    </div>
+                    <Button
+                        variant="neutral"
+                        size="sm"
+                        onclick={exitFullscreen}
+                        testId="exit-fullscreen-capture"
                     >
-                        {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
-                    </h2>
-                    {#if stacked}
-                        <!-- 1 カラムのときだけ出す (2 カラムでは一覧が常に見えているので不要)。
-                             TextLink のボタンモード (href なし + onclick) = <button type="button">。 -->
-                        <TextLink onclick={backToCutList} testId="back-to-cut-list">
-                            カット一覧へ戻る
-                        </TextLink>
-                    {/if}
+                        <Minimize class="size-4" aria-hidden="true" />
+                        全画面を終了
+                    </Button>
                 </div>
-
-                <div class="rounded-md border border-border bg-surface p-3">
-                    <p class="text-caption text-text-secondary">ナレーション</p>
-                    <p class="mt-1 text-body">{selectedCut.narration}</p>
-                    {#if selectedCut.shooting_point}
-                        <p class="mt-2 text-caption text-text-secondary">
-                            撮影ポイント: {selectedCut.shooting_point}
+                {#if navigationNotice !== null}
+                    {#if navigationNotice.tone === "alert"}
+                        <p
+                            class="text-caption text-danger"
+                            role="alert"
+                            data-testid="cut-navigation-error"
+                        >
+                            {navigationNotice.message}
                         </p>
-                    {/if}
-                </div>
-
-                {#if showRecorder}
-                    <CameraRecorder
-                        bind:this={recorderRef}
-                        onCaptured={(blob, mimeType, durationMs) =>
-                            handleCaptured(blob, mimeType, durationMs)}
-                        onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
-                        subtitlePrimary={selectedCut.subtitle_primary}
-                        subtitleSecondary={selectedCut.subtitle_secondary}
-                        onCaptureActiveChange={(active) => (captureActive = active)}
-                    />
-                {:else}
-                    {#if fallbackNotice !== null}
+                    {:else}
                         <p
                             class="text-caption text-text-secondary"
                             role="status"
-                            data-testid="camera-fallback-notice"
+                            data-testid="cut-navigation-notice"
                         >
-                            {fallbackNotice}
+                            {navigationNotice.message}
                         </p>
                     {/if}
-                    <CaptureFileFallback
-                        onCaptured={(file) => handleCaptured(file, file.type, null)}
-                    />
+                {/if}
+            {/if}
+
+            {#if selectedCut === null}
+                <p class="text-caption text-text-secondary">
+                    左のシナリオからカットを選ぶと撮影パネルが開きます。
+                </p>
+            {:else}
+                <!-- 全画面では見出し・ナレーション・テイク一覧を出さない
+                     (撮影ガイドと字幕は映像上の overlay が担う)。
+                     **CameraRecorder はこの {#if} を跨がない** = 位置が変わらない。 -->
+                {#if !fullscreenActive}
+                    <div class="flex items-center justify-between gap-2">
+                        <!-- カット選択時のフォーカス着地点。ラベルを含めて「どのカットの撮影か」を
+                             名前で伝える (視点だけ運んでフォーカスを残すと a11y 欠落を作るため)。 -->
+                        <h2
+                            bind:this={recordingHeadingEl}
+                            tabindex="-1"
+                            class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+                            data-testid="capture-recording-heading"
+                        >
+                            {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
+                        </h2>
+                        {#if stacked}
+                            <!-- 1 カラムのときだけ出す (2 カラムでは一覧が常に見えているので不要)。
+                                 TextLink のボタンモード (href なし + onclick) = <button type="button">。 -->
+                            <TextLink onclick={backToCutList} testId="back-to-cut-list">
+                                カット一覧へ戻る
+                            </TextLink>
+                        {/if}
+                    </div>
+
+                    <div class="rounded-md border border-border bg-surface p-3">
+                        <p class="text-caption text-text-secondary">ナレーション</p>
+                        <p class="mt-1 text-body">{selectedCut.narration}</p>
+                        {#if selectedCut.shooting_point}
+                            <p class="mt-2 text-caption text-text-secondary">
+                                撮影ポイント: {selectedCut.shooting_point}
+                            </p>
+                        {/if}
+                    </div>
                 {/if}
 
-                <TakeStrip
-                    projectId={project.id}
-                    manualId={manual.id}
-                    cut={selectedCut}
-                    cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
-                    onChanged={reloadManual}
-                    {captureActive}
-                    onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
-                    onCameraResume={() => void recorderRef?.resumeAfterPreview()}
-                />
+                <!-- 全画面では残り高さいっぱいに広げる。**要素そのものは同じ** (class だけ変わる)。
+                     inline 側を素の div にせず flex-col gap-4 にしてあるのは、この wrapper を
+                     挟んだことで fallback 経路 (notice + ファイル選択) の間隔が消えるのを防ぐため
+                     (従来は section 直下の兄弟として gap-4 が効いていた)。 -->
+                <div class={fullscreenActive ? "relative min-h-0 flex-1" : "flex flex-col gap-4"}>
+                    {#if showRecorder}
+                        <CameraRecorder
+                            bind:this={recorderRef}
+                            onCaptured={(blob, mimeType, durationMs) =>
+                                handleCaptured(blob, mimeType, durationMs)}
+                            onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
+                            subtitlePrimary={selectedCut.subtitle_primary}
+                            subtitleSecondary={selectedCut.subtitle_secondary}
+                            onCaptureActiveChange={(active) => {
+                                captureActive = active;
+                                // 録画の開始でも停止でも古い告知を捨てる。とくに停止後に
+                                // 「録画中は移動できません」が残らないようにする。
+                                navigationNotice = null;
+                            }}
+                            layout={fullscreenActive ? "fullscreen" : "inline"}
+                            shootingPoint={selectedCut.shooting_point}
+                        />
+                    {:else}
+                        {#if fallbackNotice !== null}
+                            <p
+                                class="text-caption text-text-secondary"
+                                role="status"
+                                data-testid="camera-fallback-notice"
+                            >
+                                {fallbackNotice}
+                            </p>
+                        {/if}
+                        <CaptureFileFallback
+                            onCaptured={(file) => handleCaptured(file, file.type, null)}
+                        />
+                    {/if}
+                </div>
+
+                {#if !fullscreenActive}
+                    <TakeStrip
+                        projectId={project.id}
+                        manualId={manual.id}
+                        cut={selectedCut}
+                        cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
+                        onChanged={reloadManual}
+                        {captureActive}
+                        onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
+                        onCameraResume={() => void recorderRef?.resumeAfterPreview()}
+                    />
+                {/if}
             {/if}
         </section>
         </div>
diff --git a/tests/Browser/CaptureLandscapeFullscreenTest.php b/tests/Browser/CaptureLandscapeFullscreenTest.php
new file mode 100644
index 0000000..7fa0a6f
--- /dev/null
+++ b/tests/Browser/CaptureLandscapeFullscreenTest.php
@@ -0,0 +1,277 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\VideoManual;
+
+/*
+|--------------------------------------------------------------------------
+| 撮影ナビ: 横持ち全画面撮影とカット間スワイプ (T186)
+|--------------------------------------------------------------------------
+|
+| 固定するのは **DOM 契約と条件分岐** だけである。
+| 実カメラを伴う挙動 (録画継続・プレビューの見え方・iOS の動的ツールバー・
+| 端末の戻るジェスチャ・inert 非対応環境) は Chromium でも WebKit でも再現していない。
+| 保証範囲は docs/supported-browsers.md の「未対応事項」が正本。
+|
+| 負のコントロールを 3 本置くのは、「全画面にならない」だけを観測すると
+| ハーネスの context 設定が変わって前提が崩れたのか実装が壊れたのかを区別できないためである。
+| そのため各ケースの冒頭で (pointer: coarse) と対象 query の評価結果を assert する。
+|
+*/
+
+/** LANDSCAPE_CAPTURE_MEDIA_QUERY と同一文字列 (正本は resources/js/lib/capture/landscape-capture.ts) */
+function landscapeCaptureMediaQuery(): string
+{
+    return '(orientation: landscape) and (max-height: 540px) and (pointer: coarse)';
+}
+
+/**
+ * 横持ち全画面の前提を一式作る。
+ *
+ * 撮影 PWA は require-active-subscription group 内 (AGENTS.md ドメイン規約 4) なので
+ * contractPaidPlan を通さないと /billing-required に着地する。
+ *
+ * @return array{0: Project, 1: VideoManual}
+ */
+function landscapeCaptureFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization);
+
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()
+        ->forProject($project)
+        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);
+
+    foreach (range(1, 3) as $index) {
+        Cut::factory()->forManual($manual)->create([
+            'sort_order' => $index,
+            'shooting_point' => "工程 {$index} は手元を寄りで撮る",
+            'subtitle_primary' => "工程 {$index}",
+            'subtitle_secondary' => "工程 {$index} の説明字幕",
+        ]);
+    }
+
+    test()->actingAs($owner);
+
+    return [$project, $manual];
+}
+
+/** capture.manuals.show の URL */
+function landscapeCaptureShowUrl(Project $project, VideoManual $manual): string
+{
+    return "/app/projects/{$project->id}/manuals/{$manual->id}";
+}
+
+/**
+ * 指定 testid の要素の属性が期待値になるまで上限付きで polling する。
+ *
+ * resize() 後は media query の再評価と Svelte の再描画が非同期なので、
+ * 直後に測ると移行途中を拾って flaky になる。固定 sleep にはしない
+ * (「目的の状態になったか」を直接見る)。上限を超えたら false を返し、
+ * 呼び出し側が「待機 timeout」として明示的に落とす。
+ */
+function waitForTestIdAttribute(
+    mixed $page,
+    string $testId,
+    string $attribute,
+    string $expected,
+    int $attempts = 40,
+): bool {
+    for ($i = 0; $i < $attempts; $i++) {
+        $actual = $page->script(<<<JS
+            (() => {
+                const el = document.querySelector('[data-testid="{$testId}"]');
+                return el === null ? null : el.getAttribute('{$attribute}');
+            })()
+        JS);
+
+        if ($actual === $expected) {
+            return true;
+        }
+
+        usleep(100_000);
+    }
+
+    return false;
+}
+
+/** 指定 testid のテキストが期待値を含むまで上限付きで polling する */
+function waitForTestIdText(mixed $page, string $testId, string $expected, int $attempts = 40): bool
+{
+    $needle = json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+
+    for ($i = 0; $i < $attempts; $i++) {
+        $found = $page->script(<<<JS
+            (() => {
+                const el = document.querySelector('[data-testid="{$testId}"]');
+                return el !== null && (el.textContent ?? '').includes({$needle});
+            })()
+        JS);
+
+        if ($found === true) {
+            return true;
+        }
+
+        usleep(100_000);
+    }
+
+    return false;
+}
+
+/** 対象 media query と (pointer: coarse) の評価結果を返す (ケースの前提の明示) */
+function landscapeMediaState(mixed $page): array
+{
+    $query = landscapeCaptureMediaQuery();
+
+    return [
+        'coarse' => $page->script("window.matchMedia('(pointer: coarse)').matches"),
+        'target' => $page->script("window.matchMedia('{$query}').matches"),
+    ];
+}
+
+test('横持ちスマホ相当の context では撮影パネルが全画面へ切り替わる (ケース 0)', function (): void {
+    [$project, $manual] = landscapeCaptureFixture();
+
+    $page = visit(landscapeCaptureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(landscapeCaptureShowUrl($project, $manual))
+        ->resize(844, 390);
+
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
+        ->toBeTrue('横持ちスマホ相当でも全画面へ切り替わらなかった (待機 timeout)');
+
+    // 前提の明示: 条件が満たされていることを実測で残す
+    // (これが無いと、ハーネスの context 設定が変わって前提が崩れたときに
+    //  「全画面にならない」だけが観測され、実装の回帰と区別できない)
+    expect(landscapeMediaState($page))->toBe(['coarse' => true, 'target' => true]);
+});
+
+test('全画面の前後ボタンでカットが往復する (ケース 0 の続き)', function (): void {
+    [$project, $manual] = landscapeCaptureFixture();
+
+    $page = visit(landscapeCaptureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(landscapeCaptureShowUrl($project, $manual))
+        ->resize(844, 390);
+
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
+        ->toBeTrue('全画面へ切り替わらなかった (待機 timeout)');
+
+    // 自動選択で先頭カットが選ばれている
+    expect(waitForTestIdText($page, 'cut-swipe-label', '手順 1'))
+        ->toBeTrue('先頭カットが自動選択されなかった (待機 timeout)');
+
+    $page->click('[data-testid="cut-swipe-next"]');
+    expect(waitForTestIdText($page, 'cut-swipe-label', '手順 2'))
+        ->toBeTrue('次のカットへ移動しなかった (待機 timeout)');
+
+    $page->click('[data-testid="cut-swipe-previous"]');
+    expect(waitForTestIdText($page, 'cut-swipe-label', '手順 1'))
+        ->toBeTrue('前のカットへ戻らなかった (待機 timeout)');
+});
+
+test('全画面は終了でき、再入路のボタンから戻れる (行き止まりを作らない)', function (): void {
+    [$project, $manual] = landscapeCaptureFixture();
+
+    $page = visit(landscapeCaptureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(landscapeCaptureShowUrl($project, $manual))
+        ->resize(844, 390);
+
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
+        ->toBeTrue('全画面へ切り替わらなかった (待機 timeout)');
+
+    $page->click('[data-testid="exit-fullscreen-capture"]');
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
+        ->toBeTrue('全画面を終了できなかった (待機 timeout)');
+    $page->assertVisible('[data-testid="enter-fullscreen-capture"]');
+
+    $page->click('[data-testid="enter-fullscreen-capture"]');
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
+        ->toBeTrue('全画面へ戻れなかった (待機 timeout)');
+});
+
+test('デスクトップ相当では全画面にならない (負のコントロール 1: 全条件)', function (): void {
+    [$project, $manual] = landscapeCaptureFixture();
+
+    $page = visit(landscapeCaptureShowUrl($project, $manual))->on()->desktop()
+        ->assertPathIs(landscapeCaptureShowUrl($project, $manual));
+
+    expect(landscapeMediaState($page))->toBe(['coarse' => false, 'target' => false]);
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
+        ->toBeTrue('デスクトップ相当で全画面になった');
+});
+
+test('粗いポインタでも高さが超えると全画面にならない (負のコントロール 2: max-height の欠落)', function (): void {
+    [$project, $manual] = landscapeCaptureFixture();
+
+    $page = visit(landscapeCaptureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(landscapeCaptureShowUrl($project, $manual))
+        ->resize(1024, 900);
+
+    expect(landscapeMediaState($page))->toBe(['coarse' => true, 'target' => false]);
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
+        ->toBeTrue('高さが 540px を超えているのに全画面になった');
+});
+
+test('横長でも細いポインタなら全画面にならない (負のコントロール 3: pointer: coarse の欠落)', function (): void {
+    [$project, $manual] = landscapeCaptureFixture();
+
+    $page = visit(landscapeCaptureShowUrl($project, $manual))->on()->desktop()
+        ->assertPathIs(landscapeCaptureShowUrl($project, $manual))
+        ->resize(844, 390);
+
+    expect(landscapeMediaState($page))->toBe(['coarse' => false, 'target' => false]);
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'false'))
+        ->toBeTrue('細いポインタなのに全画面になった');
+});
+
+test('撮影ガイドの矩形が上下の字幕帯のどちらとも交差しない', function (): void {
+    [$project, $manual] = landscapeCaptureFixture();
+
+    $page = visit(landscapeCaptureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(landscapeCaptureShowUrl($project, $manual))
+        ->resize(844, 390);
+
+    expect(waitForTestIdAttribute($page, 'capture-right-pane', 'data-fullscreen', 'true'))
+        ->toBeTrue('全画面へ切り替わらなかった (待機 timeout)');
+
+    // ★ レーン依存の前提: overlay は撮影パネル (CameraRecorder) の中にあり、
+    //   撮影パネルが出るのは MediaRecorder がある環境だけである。
+    //   **Playwright WebKit (Linux) には MediaRecorder が無く** (実測: typeof が "undefined")、
+    //   撮影パネルはファイル選択フォールバックへ倒れるため overlay が 1 つも描画されない。
+    //   これは実装の回帰ではなくレーンの能力差なので、条件を明示して skip する
+    //   (無条件に緑にする / 前提を assert せずに「交差しない」と主張する、はどちらもしない)。
+    //   保証範囲は docs/supported-browsers.md の「未対応事項」が正本。
+    if ($page->script('typeof window.MediaRecorder === "undefined"') === true) {
+        test()->markTestSkipped(
+            'このレーンには MediaRecorder が無く撮影パネルが描画されない (Playwright WebKit)'
+        );
+    }
+
+    // 前提: 撮影ガイドと上下の字幕帯が 3 つとも描画されている
+    // (どれかが欠けていると「交差しない」は自明に成立してしまう)
+    expect($page->script(<<<'JS'
+        (() => {
+            const ids = ['shooting-guide-overlay', 'subtitle-primary', 'subtitle-secondary'];
+            return ids.every((id) => document.querySelector(`[data-testid="${id}"]`) !== null);
+        })()
+    JS))->toBeTrue('撮影ガイドまたは字幕帯が描画されていない (前提が成立していない)');
+
+    // primary × secondary は本設計が触っていない既存 component 内部の配置なので検査しない
+    // (主張と機械保証の範囲を一致させる)
+    expect($page->script(<<<'JS'
+        (() => {
+            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
+            const intersects = (a, b) =>
+                a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
+            const guide = rect('shooting-guide-overlay');
+            return {
+                primary: intersects(guide, rect('subtitle-primary')),
+                secondary: intersects(guide, rect('subtitle-secondary')),
+            };
+        })()
+    JS))->toBe(['primary' => false, 'secondary' => false]);
+});
diff --git a/tests/js/architecture/page-shell-structure.test.ts b/tests/js/architecture/page-shell-structure.test.ts
index 838275a..70d4cc9 100644
--- a/tests/js/architecture/page-shell-structure.test.ts
+++ b/tests/js/architecture/page-shell-structure.test.ts
@@ -28,7 +28,9 @@ const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");
 const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
     {
         path: "Capture/Show.svelte",
-        reason: "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。",
+        reason:
+            "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。" +
+            "横持ち時は撮影パネルが fixed の全画面へ切り替わるため、中央寄せの外枠を前提にできない。",
     },
 ];
 const PAGECONTENT_ALLOWLIST_PATHS = new Set(PAGECONTENT_ALLOWLIST.map((e) => e.path));
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
index c3522be..7e2c8d8 100644
--- a/tests/js/components/features/capture/CameraRecorder.test.ts
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -1,6 +1,11 @@
 import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
 import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
 import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
+import {
+    FakeMediaRecorder,
+    createTrackingRecorderClass,
+    fakeStream,
+} from "../../../support/fake-media-recorder";
 
 /*
  * CameraRecorder: 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は
@@ -8,126 +13,20 @@ import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte"
  * 成功パスは onCaptured(blob, mimeType, durationMs) の契約を保つ。
  */
 
-/** 手動発火できる最小 MediaRecorder stub (start/stop → ondataavailable/onstop) */
-class FakeMediaRecorder {
-    static supportedTypes: string[] = ["video/webm"];
-    static isTypeSupported(type: string): boolean {
-        return FakeMediaRecorder.supportedTypes.includes(type);
-    }
-    static shouldThrowOnConstruct = false;
-    static shouldThrowOnStart = false;
-    static shouldThrowOnPause = false;
-    /** false のとき stop() は onstop を自動発火せず、テストが手動で駆動する (stopping 観測用) */
-    static autoStop = true;
-    /** false のとき pause()/resume() は onpause/onresume を自動発火せず、テストが手動で駆動する */
-    static autoPauseResume = true;
-
-    ondataavailable: ((event: { data: Blob }) => void) | null = null;
-    onstop: (() => void) | null = null;
-    onerror: (() => void) | null = null;
-    onpause: (() => void) | null = null;
-    onresume: (() => void) | null = null;
-    stopCalls = 0;
-    pauseCalls = 0;
-    resumeCalls = 0;
-    /** RecordingState 相当 (recoverPhaseFromRecorderState が参照する真実源) */
-    state: "inactive" | "recording" | "paused" = "inactive";
-
-    constructor(
-        public stream: unknown,
-        public options: { mimeType: string },
-    ) {
-        if (FakeMediaRecorder.shouldThrowOnConstruct) {
-            throw new DOMException("unsupported", "NotSupportedError");
-        }
-    }
-
-    start(): void {
-        if (FakeMediaRecorder.shouldThrowOnStart) {
-            throw new DOMException("invalid state", "InvalidStateError");
-        }
-        this.state = "recording";
-        // no-op (テストは stop() で明示的に onstop を駆動する)
-    }
-
-    stop(): void {
-        this.stopCalls += 1;
-        this.state = "inactive";
-        if (!FakeMediaRecorder.autoStop) return; // 手動駆動モード
-        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
-        this.onstop?.();
-    }
-
-    pause(): void {
-        if (FakeMediaRecorder.shouldThrowOnPause) {
-            throw new DOMException("invalid state", "InvalidStateError");
-        }
-        this.pauseCalls += 1;
-        this.state = "paused";
-        if (FakeMediaRecorder.autoPauseResume) this.onpause?.();
-    }
-
-    resume(): void {
-        this.resumeCalls += 1;
-        this.state = "recording";
-        if (FakeMediaRecorder.autoPauseResume) this.onresume?.();
-    }
-
-    /** 手動モードで onstop を駆動する (blob 生成 → onstop) */
-    fireStop(): void {
-        this.state = "inactive";
-        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
-        this.onstop?.();
-    }
-
-    /** 手動モードで onpause/onresume を駆動する */
-    firePause(): void {
-        this.onpause?.();
-    }
-    fireResume(): void {
-        this.onresume?.();
-    }
-}
+/*
+ * MediaRecorder / MediaStream の stub は tests/js/support/fake-media-recorder.ts へ移設し、
+ * 撮影ページ (CaptureShow.test.ts) と共有する。**移設だけで挙動は変えていない**ので、
+ * 以下の it ブロックが 1 行も変わらず緑であることがその証拠になる。
+ */
 
 /** 直近に構築された FakeMediaRecorder を捕捉する (onerror/onstop 手動駆動用) */
 let lastRecorder: FakeMediaRecorder | null = null;
-class TrackingFakeMediaRecorder extends FakeMediaRecorder {
-    constructor(stream: unknown, options: { mimeType: string }) {
-        super(stream, options);
-        lastRecorder = this;
-    }
-}
+const TrackingFakeMediaRecorder = createTrackingRecorderClass((recorder) => {
+    lastRecorder = recorder;
+});
 
 const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
 
-interface FakeTrack {
-    stop: ReturnType<typeof vi.fn>;
-    onended: (() => void) | null;
-    applyConstraints: ReturnType<typeof vi.fn>;
-    getSettings: ReturnType<typeof vi.fn>;
-}
-
-/** getTracks()/getVideoTracks() が stop spy 付き track を返す fake stream (解放・flip 検証用) */
-function fakeStream(facing: "environment" | "user" = "environment"): {
-    stream: MediaStream;
-    stop: ReturnType<typeof vi.fn>;
-    track: FakeTrack;
-} {
-    const stop = vi.fn();
-    const track: FakeTrack = {
-        stop,
-        onended: null,
-        // 既定は制約適用成功 + getSettings が要求 facingMode を返す (段階1 成功)
-        applyConstraints: vi.fn().mockResolvedValue(undefined),
-        getSettings: vi.fn(() => ({ facingMode: facing })),
-    };
-    const stream = {
-        getTracks: () => [track],
-        getVideoTracks: () => [track],
-    } as unknown as MediaStream;
-    return { stream, stop, track };
-}
-
 beforeEach(() => {
     FakeMediaRecorder.supportedTypes = ["video/webm"];
     FakeMediaRecorder.shouldThrowOnConstruct = false;
@@ -1020,3 +919,128 @@ describe("CameraRecorder", () => {
         );
     });
 });
+
+/*
+ * 横持ち全画面レイアウトと撮影ガイド overlay (T186 施策 C)。
+ *
+ * layout は **class の切替にしか使わない**ため、phase マシン・stream 管理の既存テストは
+ * 1 件も変更していない (変更したら不変条件が緩んだ証拠)。
+ */
+describe("CameraRecorder 全画面レイアウトと撮影ガイド", () => {
+    /** camera-preview を内包する overlay コンテナ (position: relative の親) */
+    function previewContainer(): HTMLElement {
+        const parent = screen.getByTestId("camera-preview").parentElement;
+        expect(parent).not.toBeNull();
+
+        return parent as HTMLElement;
+    }
+
+    it("既定 (layout 省略) は従来どおり: 撮影ガイドを出さず aspect-video を保つ", () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                shootingPoint: "手元を寄りで撮る",
+            },
+        });
+
+        expect(screen.queryByTestId("shooting-guide-overlay")).not.toBeInTheDocument();
+        expect(screen.getByTestId("camera-preview").className).toContain("aspect-video");
+    });
+
+    it("全画面 + 非空の shootingPoint で撮影ガイドが出る", () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                layout: "fullscreen",
+                shootingPoint: "手元を寄りで撮る",
+            },
+        });
+
+        expect(screen.getByTestId("shooting-guide-overlay")).toHaveTextContent("手元を寄りで撮る");
+    });
+
+    it.each([
+        ["null", null],
+        ["空文字列", ""],
+        ["空白のみ", "   "],
+    ])("全画面でも shootingPoint が %s なら撮影ガイドを出さない", (_label, shootingPoint) => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                layout: "fullscreen",
+                shootingPoint,
+            },
+        });
+
+        expect(screen.queryByTestId("shooting-guide-overlay")).not.toBeInTheDocument();
+    });
+
+    it("前後空白を含む shootingPoint は trim せずそのまま描画する (trim は空判定専用)", () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                layout: "fullscreen",
+                shootingPoint: "  手元を寄りで撮る  ",
+            },
+        });
+
+        expect(
+            screen.getByTestId("shooting-guide-overlay").querySelector("span")?.textContent,
+        ).toBe("  手元を寄りで撮る  ");
+    });
+
+    it("全画面では映像が object-cover で伸び aspect-video を持たない", () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                layout: "fullscreen",
+            },
+        });
+
+        const preview = screen.getByTestId("camera-preview");
+        expect(preview.className).toContain("object-cover");
+        expect(preview.className).not.toContain("aspect-video");
+    });
+
+    it("DOM 順が グリッド < 撮影ガイド < 字幕帯 (z 順の回帰検出)", async () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                layout: "fullscreen",
+                shootingPoint: "手元を寄りで撮る",
+                subtitlePrimary: "ネジ締め",
+                subtitleSecondary: "ドライバーで締めます",
+            },
+        });
+        await fireEvent.click(screen.getByTestId("toggle-grid")); // グリッドは既定 OFF
+
+        const grid = screen.getByTestId("grid-overlay");
+        const guide = screen.getByTestId("shooting-guide-overlay");
+        const subtitle = screen.getByTestId("subtitle-overlay");
+
+        expect(grid.compareDocumentPosition(guide) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
+            Node.DOCUMENT_POSITION_FOLLOWING,
+        );
+        expect(guide.compareDocumentPosition(subtitle) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
+            Node.DOCUMENT_POSITION_FOLLOWING,
+        );
+    });
+
+    it("全画面でも操作行は映像コンテナの外にある (映像へ重ねない判断の固定)", () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                layout: "fullscreen",
+            },
+        });
+
+        expect(previewContainer().contains(screen.getByTestId("start-recording"))).toBe(false);
+    });
+});
diff --git a/tests/js/components/features/capture/CutSwipeBar.test.ts b/tests/js/components/features/capture/CutSwipeBar.test.ts
new file mode 100644
index 0000000..8ea2300
--- /dev/null
+++ b/tests/js/components/features/capture/CutSwipeBar.test.ts
@@ -0,0 +1,253 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
+import {
+    SWIPE_EDGE_EXCLUSION_PX,
+    SWIPE_MIN_DISTANCE_PX,
+} from "@/lib/capture/landscape-capture";
+
+/*
+ * 横持ち全画面の上部カット名スワイプバー (T186 施策 B)。
+ *
+ * スワイプ判定そのものは landscape-capture.test.ts が網羅する。
+ * ここで固定するのは **配線** (どのイベント系列で onNavigate が何回呼ばれるか) と
+ * 禁止事項 8 (端でも disabled にしない) である。
+ */
+
+const VIEWPORT_WIDTH = 800;
+const CENTER_X = 400;
+const CENTER_Y = 60;
+
+function renderBar(onNavigate = vi.fn()): { onNavigate: ReturnType<typeof vi.fn> } {
+    render(CutSwipeBar, {
+        props: {
+            label: "手順 2",
+            scene: "ネジを締める",
+            position: { index: 2, total: 12 },
+            onNavigate,
+        },
+    });
+
+    return { onNavigate };
+}
+
+/** pointerdown → pointerup の系列を発火する (始点・終点を px で指定)。 */
+async function swipe(
+    target: Element,
+    from: { x: number; y: number },
+    to: { x: number; y: number },
+    pointerId = 1,
+): Promise<void> {
+    await fireEvent.pointerDown(target, { pointerId, clientX: from.x, clientY: from.y });
+    await fireEvent.pointerUp(target, { pointerId, clientX: to.x, clientY: to.y });
+}
+
+beforeEach(() => {
+    vi.stubGlobal("innerWidth", VIEWPORT_WIDTH);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+});
+
+describe("CutSwipeBar 表示", () => {
+    it("ラベル・現在位置・カット内容を描画する", () => {
+        renderBar();
+
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("2 / 12");
+        expect(screen.getByTestId("cut-swipe-scene")).toHaveTextContent("ネジを締める");
+    });
+
+    it("端かどうかを知らないので前後ボタンは disabled にならない (禁止事項 8)", () => {
+        renderBar();
+
+        expect(screen.getByTestId("cut-swipe-previous")).not.toBeDisabled();
+        expect(screen.getByTestId("cut-swipe-next")).not.toBeDisabled();
+    });
+
+    it("バー自体は Tab 停止にしない (停止するのは内側の 2 ボタンだけ)", () => {
+        renderBar();
+
+        const bar = screen.getByTestId("cut-swipe-bar");
+        expect(bar).not.toHaveAttribute("tabindex");
+        expect(bar.querySelectorAll("button")).toHaveLength(2);
+    });
+});
+
+describe("CutSwipeBar ボタン操作", () => {
+    it("「前のカット」は onNavigate(-1)", async () => {
+        const { onNavigate } = renderBar();
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-previous"));
+
+        expect(onNavigate).toHaveBeenCalledTimes(1);
+        expect(onNavigate).toHaveBeenCalledWith(-1);
+    });
+
+    it("「次のカット」は onNavigate(1)", async () => {
+        const { onNavigate } = renderBar();
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-next"));
+
+        expect(onNavigate).toHaveBeenCalledTimes(1);
+        expect(onNavigate).toHaveBeenCalledWith(1);
+    });
+});
+
+describe("CutSwipeBar キー操作 (前後ボタンからバーへバブルする)", () => {
+    it.each([
+        ["ArrowLeft", -1],
+        ["ArrowRight", 1],
+    ] as const)("%s で onNavigate(%s) を呼び preventDefault する", async (key, direction) => {
+        const { onNavigate } = renderBar();
+
+        const notCancelled = await fireEvent.keyDown(screen.getByTestId("cut-swipe-previous"), {
+            key,
+        });
+
+        expect(onNavigate).toHaveBeenCalledWith(direction);
+        expect(notCancelled).toBe(false); // preventDefault された
+    });
+
+    it("他のキーでは移動しない", async () => {
+        const { onNavigate } = renderBar();
+
+        await fireEvent.keyDown(screen.getByTestId("cut-swipe-next"), { key: "Enter" });
+
+        expect(onNavigate).not.toHaveBeenCalled();
+    });
+});
+
+describe("CutSwipeBar スワイプ配線", () => {
+    it("左へスワイプで onNavigate(1)", async () => {
+        const { onNavigate } = renderBar();
+
+        await swipe(
+            screen.getByTestId("cut-swipe-bar"),
+            { x: CENTER_X, y: CENTER_Y },
+            { x: CENTER_X - 120, y: CENTER_Y },
+        );
+
+        expect(onNavigate).toHaveBeenCalledTimes(1);
+        expect(onNavigate).toHaveBeenCalledWith(1);
+    });
+
+    it("右へスワイプで onNavigate(-1)", async () => {
+        const { onNavigate } = renderBar();
+
+        await swipe(
+            screen.getByTestId("cut-swipe-bar"),
+            { x: CENTER_X, y: CENTER_Y },
+            { x: CENTER_X + 120, y: CENTER_Y },
+        );
+
+        expect(onNavigate).toHaveBeenCalledTimes(1);
+        expect(onNavigate).toHaveBeenCalledWith(-1);
+    });
+
+    it.each([
+        [
+            "距離不足",
+            { x: CENTER_X, y: CENTER_Y },
+            { x: CENTER_X - (SWIPE_MIN_DISTANCE_PX - 1), y: CENTER_Y },
+        ],
+        ["縦優勢", { x: CENTER_X, y: CENTER_Y }, { x: CENTER_X - 100, y: CENTER_Y + 100 }],
+        [
+            "左端始まり",
+            { x: SWIPE_EDGE_EXCLUSION_PX - 1, y: CENTER_Y },
+            { x: SWIPE_EDGE_EXCLUSION_PX - 1 + 200, y: CENTER_Y },
+        ],
+        [
+            "右端始まり",
+            { x: VIEWPORT_WIDTH - SWIPE_EDGE_EXCLUSION_PX + 1, y: CENTER_Y },
+            { x: VIEWPORT_WIDTH - SWIPE_EDGE_EXCLUSION_PX + 1 - 200, y: CENTER_Y },
+        ],
+    ])("%s では移動しない", async (_label, from, to) => {
+        const { onNavigate } = renderBar();
+
+        await swipe(screen.getByTestId("cut-swipe-bar"), from, to);
+
+        expect(onNavigate).not.toHaveBeenCalled();
+    });
+
+    it("pointercancel の後の pointerup では移動しない (始点を捨てている)", async () => {
+        const { onNavigate } = renderBar();
+        const bar = screen.getByTestId("cut-swipe-bar");
+
+        await fireEvent.pointerDown(bar, { pointerId: 1, clientX: CENTER_X, clientY: CENTER_Y });
+        await fireEvent.pointerCancel(bar, { pointerId: 1 });
+        await fireEvent.pointerUp(bar, {
+            pointerId: 1,
+            clientX: CENTER_X - 200,
+            clientY: CENTER_Y,
+        });
+
+        expect(onNavigate).not.toHaveBeenCalled();
+    });
+
+    it("別 pointerId の pointerup は始点と対応しないので移動しない", async () => {
+        const { onNavigate } = renderBar();
+        const bar = screen.getByTestId("cut-swipe-bar");
+
+        await fireEvent.pointerDown(bar, { pointerId: 1, clientX: CENTER_X, clientY: CENTER_Y });
+        await fireEvent.pointerUp(bar, {
+            pointerId: 2,
+            clientX: CENTER_X - 200,
+            clientY: CENTER_Y,
+        });
+
+        expect(onNavigate).not.toHaveBeenCalled();
+    });
+});
+
+/*
+ * スワイプと click の二重発火防止。
+ *
+ * click は jsdom / Testing Library の pointer event からは合成されないため、
+ * **明示的に発火する**。pointerup だけのテストは「1 回しか起きない条件で緑になる」空振りになる。
+ */
+describe("CutSwipeBar ボタン上で始めた操作の二重発火防止", () => {
+    it("ボタン上で pointerdown → 大きく動かして pointerup → click で合計 1 回だけ", async () => {
+        const { onNavigate } = renderBar();
+        const button = screen.getByTestId("cut-swipe-next");
+
+        await fireEvent.pointerDown(button, {
+            pointerId: 1,
+            clientX: CENTER_X,
+            clientY: CENTER_Y,
+        });
+        await fireEvent.pointerUp(button, {
+            pointerId: 1,
+            clientX: CENTER_X - 200,
+            clientY: CENTER_Y,
+        });
+        await fireEvent.click(button);
+
+        expect(onNavigate).toHaveBeenCalledTimes(1);
+        expect(onNavigate).toHaveBeenCalledWith(1);
+    });
+
+    it("ボタン内のアイコン要素から始めても同じ (closest('button') が子孫から効く)", async () => {
+        const { onNavigate } = renderBar();
+        const button = screen.getByTestId("cut-swipe-next");
+        const icon = button.querySelector("svg");
+        expect(icon).not.toBeNull();
+
+        await fireEvent.pointerDown(icon as SVGElement, {
+            pointerId: 1,
+            clientX: CENTER_X,
+            clientY: CENTER_Y,
+        });
+        await fireEvent.pointerUp(icon as SVGElement, {
+            pointerId: 1,
+            clientX: CENTER_X - 200,
+            clientY: CENTER_Y,
+        });
+        await fireEvent.click(button);
+
+        expect(onNavigate).toHaveBeenCalledTimes(1);
+        expect(onNavigate).toHaveBeenCalledWith(1);
+    });
+});
diff --git a/tests/js/components/features/capture/ShootingGuideOverlay.test.ts b/tests/js/components/features/capture/ShootingGuideOverlay.test.ts
new file mode 100644
index 0000000..cacf3cf
--- /dev/null
+++ b/tests/js/components/features/capture/ShootingGuideOverlay.test.ts
@@ -0,0 +1,40 @@
+import { afterEach, describe, expect, it } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import ShootingGuideOverlay from "@/components/features/capture/ShootingGuideOverlay.svelte";
+
+/*
+ * 撮影ガイド (撮影方法 = cuts.shooting_point) の透過オーバーレイ (T186 施策 C)。
+ * 表示可否は親が決めるため、本 component は非 null の text だけを受ける。
+ *
+ * レーンの非交差 (上下の字幕帯と重ならない) は jsdom がレイアウトを持たないため
+ * ここでは固定できない。Browser レーンが矩形を実測して固定する
+ * (できない検査を component テストに書かない)。
+ */
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("ShootingGuideOverlay", () => {
+    it("受け取った text をそのまま描画する", () => {
+        render(ShootingGuideOverlay, { props: { text: "手元を寄りで撮る" } });
+
+        expect(screen.getByTestId("shooting-guide-overlay")).toHaveTextContent("手元を寄りで撮る");
+    });
+
+    it("前後の空白を含む文字列も書き換えずに描画する (trim は親の空判定専用)", () => {
+        render(ShootingGuideOverlay, { props: { text: "  手元を寄りで撮る  " } });
+
+        expect(
+            screen.getByTestId("shooting-guide-overlay").querySelector("span")?.textContent,
+        ).toBe("  手元を寄りで撮る  ");
+    });
+
+    it("pointer-events-none を持つ (映像上の操作を邪魔しない)", () => {
+        render(ShootingGuideOverlay, { props: { text: "手元を寄りで撮る" } });
+
+        expect(screen.getByTestId("shooting-guide-overlay").className).toContain(
+            "pointer-events-none",
+        );
+    });
+});
diff --git a/tests/js/lib/capture/landscape-capture.test.ts b/tests/js/lib/capture/landscape-capture.test.ts
new file mode 100644
index 0000000..a593a02
--- /dev/null
+++ b/tests/js/lib/capture/landscape-capture.test.ts
@@ -0,0 +1,341 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import {
+    CUT_EDGE_MESSAGES,
+    LANDSCAPE_CAPTURE_MEDIA_QUERY,
+    RECORDING_BLOCKS_NAVIGATION_MESSAGE,
+    SWIPE_EDGE_EXCLUSION_PX,
+    SWIPE_MIN_DISTANCE_PX,
+    decideCutNavigation,
+    lockBackgroundScroll,
+    matchesLandscapeCapture,
+    resolveSwipe,
+    subscribeLandscapeCapture,
+    swipeDirection,
+} from "@/lib/capture/landscape-capture";
+
+/*
+ * 横持ち全画面撮影の判定・ジェスチャ解釈・移動判断・背景スクロール抑止 (T186 施策 A)。
+ *
+ * panel-navigation.ts と同じく **副作用ごと lib に置く**方針なので、
+ * 述語だけでなく購読と class 操作もここで固定する。
+ *
+ * **legacy MediaQueryList (`addListener`) は対象外**である。撮影 PWA が要求する
+ * MediaRecorder の最低版 (iOS Safari 14.5) の方が addEventListener の対応版 (14) より
+ * 新しいため、二重の登録経路を持たない (AGENTS.md 思考原則 3)。
+ */
+
+/** matchMedia の最小 stub。change ハンドラを手動発火できるようにする。 */
+function stubMatchMedia(initial: boolean): {
+    setMatches: (next: boolean) => void;
+    addEventListener: ReturnType<typeof vi.fn>;
+    removeEventListener: ReturnType<typeof vi.fn>;
+    queries: string[];
+} {
+    const handlers = new Set<(event: MediaQueryListEvent) => void>();
+    const queries: string[] = [];
+    let matches = initial;
+    const addEventListener = vi.fn((type: string, handler: (event: MediaQueryListEvent) => void) => {
+        if (type === "change") handlers.add(handler);
+    });
+    const removeEventListener = vi.fn(
+        (type: string, handler: (event: MediaQueryListEvent) => void) => {
+            if (type === "change") handlers.delete(handler);
+        },
+    );
+
+    vi.stubGlobal(
+        "matchMedia",
+        vi.fn((query: string) => {
+            queries.push(query);
+
+            return {
+                get matches() {
+                    return matches;
+                },
+                media: query,
+                addEventListener,
+                removeEventListener,
+            };
+        }),
+    );
+
+    return {
+        setMatches: (next: boolean) => {
+            matches = next;
+            for (const handler of handlers) {
+                handler({ matches: next } as MediaQueryListEvent);
+            }
+        },
+        addEventListener,
+        removeEventListener,
+        queries,
+    };
+}
+
+afterEach(() => {
+    vi.unstubAllGlobals();
+    document.documentElement.classList.remove("overflow-hidden");
+});
+
+describe("LANDSCAPE_CAPTURE_MEDIA_QUERY", () => {
+    it.each([
+        ["向き", "(orientation: landscape)"],
+        ["高さの上限", "(max-height: 540px)"],
+        ["粗いポインタ", "(pointer: coarse)"],
+    ])("%s の条件を含む (欠けるとデスクトップまで全画面になる)", (_label, condition) => {
+        expect(LANDSCAPE_CAPTURE_MEDIA_QUERY).toContain(condition);
+    });
+
+    it("3 条件を and で結ぶ (or にすると単独条件で全画面になる)", () => {
+        expect(LANDSCAPE_CAPTURE_MEDIA_QUERY).toBe(
+            "(orientation: landscape) and (max-height: 540px) and (pointer: coarse)",
+        );
+    });
+});
+
+describe("matchesLandscapeCapture()", () => {
+    it("matchMedia 非対応環境では false (= 全画面にしない安全側)", () => {
+        vi.stubGlobal("matchMedia", undefined);
+
+        expect(matchesLandscapeCapture()).toBe(false);
+    });
+
+    it("対象 query が真なら true", () => {
+        const stub = stubMatchMedia(true);
+
+        expect(matchesLandscapeCapture()).toBe(true);
+        expect(stub.queries).toContain(LANDSCAPE_CAPTURE_MEDIA_QUERY);
+    });
+
+    it("対象 query が偽なら false", () => {
+        stubMatchMedia(false);
+
+        expect(matchesLandscapeCapture()).toBe(false);
+    });
+});
+
+describe("subscribeLandscapeCapture()", () => {
+    it("登録直後に現在値で 1 回呼ぶ (change を待つと初期表示が縦持ち扱いになる)", () => {
+        stubMatchMedia(true);
+        const onChange = vi.fn();
+
+        subscribeLandscapeCapture(onChange);
+
+        expect(onChange).toHaveBeenCalledTimes(1);
+        expect(onChange).toHaveBeenCalledWith(true);
+    });
+
+    it("change イベントで呼ばれる", () => {
+        const stub = stubMatchMedia(false);
+        const onChange = vi.fn();
+
+        subscribeLandscapeCapture(onChange);
+        stub.setMatches(true);
+
+        expect(onChange).toHaveBeenLastCalledWith(true);
+        expect(onChange).toHaveBeenCalledTimes(2);
+    });
+
+    it("解除関数で removeEventListener される (以降の change では呼ばれない)", () => {
+        const stub = stubMatchMedia(false);
+        const onChange = vi.fn();
+
+        const unsubscribe = subscribeLandscapeCapture(onChange);
+        unsubscribe();
+        stub.setMatches(true);
+
+        expect(stub.removeEventListener).toHaveBeenCalledTimes(1);
+        expect(onChange).toHaveBeenCalledTimes(1); // 初期通知のみ
+    });
+
+    it("matchMedia 非対応環境では何も呼ばず no-op の解除関数を返す", () => {
+        vi.stubGlobal("matchMedia", undefined);
+        const onChange = vi.fn();
+
+        const unsubscribe = subscribeLandscapeCapture(onChange);
+
+        expect(onChange).not.toHaveBeenCalled();
+        expect(() => unsubscribe()).not.toThrow();
+    });
+});
+
+describe("resolveSwipe()", () => {
+    const VIEWPORT = 800;
+    const START_X = 400;
+    const START_Y = 200;
+
+    it("左へスワイプ = 次のカット", () => {
+        expect(
+            resolveSwipe({
+                startX: START_X,
+                startY: START_Y,
+                endX: START_X - SWIPE_MIN_DISTANCE_PX,
+                endY: START_Y,
+                viewportWidth: VIEWPORT,
+            }),
+        ).toBe("next");
+    });
+
+    it("右へスワイプ = 前のカット", () => {
+        expect(
+            resolveSwipe({
+                startX: START_X,
+                startY: START_Y,
+                endX: START_X + SWIPE_MIN_DISTANCE_PX,
+                endY: START_Y,
+                viewportWidth: VIEWPORT,
+            }),
+        ).toBe("previous");
+    });
+
+    it("距離が閾値未満なら none (タップ・指ぶれを弾く)", () => {
+        expect(
+            resolveSwipe({
+                startX: START_X,
+                startY: START_Y,
+                endX: START_X - (SWIPE_MIN_DISTANCE_PX - 1),
+                endY: START_Y,
+                viewportWidth: VIEWPORT,
+            }),
+        ).toBe("none");
+    });
+
+    it("縦方向のブレが大きいと none (縦スクロール意図)", () => {
+        expect(
+            resolveSwipe({
+                startX: START_X,
+                startY: START_Y,
+                endX: START_X - 100,
+                endY: START_Y + 100,
+                viewportWidth: VIEWPORT,
+            }),
+        ).toBe("none");
+    });
+
+    it("画面左端から始まったスワイプは none (端末の戻るジェスチャへ譲る)", () => {
+        expect(
+            resolveSwipe({
+                startX: SWIPE_EDGE_EXCLUSION_PX - 1,
+                startY: START_Y,
+                endX: SWIPE_EDGE_EXCLUSION_PX - 1 + 200,
+                endY: START_Y,
+                viewportWidth: VIEWPORT,
+            }),
+        ).toBe("none");
+    });
+
+    it("画面右端から始まったスワイプは none (端末の進むジェスチャへ譲る)", () => {
+        expect(
+            resolveSwipe({
+                startX: VIEWPORT - SWIPE_EDGE_EXCLUSION_PX + 1,
+                startY: START_Y,
+                endX: VIEWPORT - SWIPE_EDGE_EXCLUSION_PX + 1 - 200,
+                endY: START_Y,
+                viewportWidth: VIEWPORT,
+            }),
+        ).toBe("none");
+    });
+
+    it("viewport 幅が除外幅の 2 倍以下 (0 を含む) では常に none = 安全側へ倒れる", () => {
+        for (const viewportWidth of [0, SWIPE_EDGE_EXCLUSION_PX, SWIPE_EDGE_EXCLUSION_PX * 2]) {
+            expect(
+                resolveSwipe({
+                    startX: SWIPE_EDGE_EXCLUSION_PX + 1,
+                    startY: START_Y,
+                    endX: SWIPE_EDGE_EXCLUSION_PX + 1 - 200,
+                    endY: START_Y,
+                    viewportWidth,
+                }),
+            ).toBe("none");
+        }
+    });
+});
+
+describe("swipeDirection()", () => {
+    it.each([
+        ["next", 1],
+        ["previous", -1],
+    ] as const)("%s は %s へ写像される", (outcome, expected) => {
+        expect(swipeDirection(outcome)).toBe(expected);
+    });
+
+    it("none は移動しない (null)", () => {
+        expect(swipeDirection("none")).toBeNull();
+    });
+});
+
+describe("decideCutNavigation()", () => {
+    const cuts = [{ id: 1 }, { id: 2 }, { id: 3 }];
+
+    it("録画中は常に alert の告知 (端かどうかより先に評価される)", () => {
+        // 「末尾で次へ」= 端の告知が出る入力でも、録画中なら録画中の文言が出ることで
+        // captureActive が先頭で評価されていることを固定する。
+        expect(
+            decideCutNavigation({ captureActive: true, cuts, currentCutId: 3, direction: 1 }),
+        ).toEqual({
+            kind: "notice",
+            tone: "alert",
+            message: RECORDING_BLOCKS_NAVIGATION_MESSAGE,
+        });
+    });
+
+    it("通常は次のカットへ移動する", () => {
+        expect(
+            decideCutNavigation({ captureActive: false, cuts, currentCutId: 2, direction: 1 }),
+        ).toEqual({ kind: "move", cutId: 3 });
+    });
+
+    it("通常は前のカットへ移動する", () => {
+        expect(
+            decideCutNavigation({ captureActive: false, cuts, currentCutId: 2, direction: -1 }),
+        ).toEqual({ kind: "move", cutId: 1 });
+    });
+
+    it("先頭で前へ = 最初のカットである告知 (status)", () => {
+        expect(
+            decideCutNavigation({ captureActive: false, cuts, currentCutId: 1, direction: -1 }),
+        ).toEqual({ kind: "notice", tone: "status", message: CUT_EDGE_MESSAGES.first });
+    });
+
+    it("末尾で次へ = 最後のカットである告知 (status)", () => {
+        expect(
+            decideCutNavigation({ captureActive: false, cuts, currentCutId: 3, direction: 1 }),
+        ).toEqual({ kind: "notice", tone: "status", message: CUT_EDGE_MESSAGES.last });
+    });
+
+    it.each([
+        ["未選択", null, cuts],
+        ["不在 id", 999, cuts],
+        ["空配列", 1, [] as { id: number }[]],
+    ])("%s は ignore (移動も告知もしない)", (_label, currentCutId, input) => {
+        expect(
+            decideCutNavigation({
+                captureActive: false,
+                cuts: input,
+                currentCutId,
+                direction: 1,
+            }),
+        ).toEqual({ kind: "ignore" });
+    });
+});
+
+describe("lockBackgroundScroll()", () => {
+    it("documentElement に overflow-hidden を付け、解除関数で外す", () => {
+        const release = lockBackgroundScroll();
+
+        expect(document.documentElement.classList.contains("overflow-hidden")).toBe(true);
+
+        release();
+
+        expect(document.documentElement.classList.contains("overflow-hidden")).toBe(false);
+    });
+
+    it("既に付いていたら付けも外しもしない (他所の抑止を横から解除しない)", () => {
+        document.documentElement.classList.add("overflow-hidden");
+
+        const release = lockBackgroundScroll();
+        release();
+
+        expect(document.documentElement.classList.contains("overflow-hidden")).toBe(true);
+    });
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 27f63c8..ad0b3df 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -1,8 +1,15 @@
 import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
 import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import { tick } from "svelte";
 import CaptureShow from "@/pages/Capture/Show.svelte";
+import { LANDSCAPE_CAPTURE_MEDIA_QUERY } from "@/lib/capture/landscape-capture";
 import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/capture";
 import { VIDEO_MANUAL_STATUS_LABELS, type VideoManualStatus } from "@/types/manual";
+import {
+    FakeMediaRecorder,
+    fakeStream,
+    resetFakeMediaRecorder,
+} from "../support/fake-media-recorder";
 
 /*
  * 撮影ページ Capture/Show: F-03 実行時カメラフォールバック。
@@ -12,14 +19,22 @@ import { VIDEO_MANUAL_STATUS_LABELS, type VideoManualStatus } from "@/types/manu
  * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
  */
 
-const { routerReloadMock, enqueueMock, resumeMock, autoDownloadRunMock, navigateToPanelMock } =
-    vi.hoisted(() => ({
-        routerReloadMock: vi.fn(),
-        enqueueMock: vi.fn(),
-        resumeMock: vi.fn(),
-        autoDownloadRunMock: vi.fn(),
-        navigateToPanelMock: vi.fn(),
-    }));
+const {
+    routerReloadMock,
+    enqueueMock,
+    resumeMock,
+    autoDownloadRunMock,
+    navigateToPanelMock,
+    pendingSeed,
+} = vi.hoisted(() => ({
+    routerReloadMock: vi.fn(),
+    enqueueMock: vi.fn(),
+    resumeMock: vi.fn(),
+    autoDownloadRunMock: vi.fn(),
+    navigateToPanelMock: vi.fn(),
+    /** in-memory PendingStore の初期内容。UploadQueueBar の表示条件を作るために使う */
+    pendingSeed: [] as { clientTakeId: string; blob: Blob }[],
+}));
 
 // 撮影パネルへのナビゲーション (F-1-03) は panel-navigation.ts が副作用ごと担い、
 // その抑止契約は panel-navigation.test.ts が固定する。ここで固定するのは
@@ -39,7 +54,9 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
 // jsdom に indexedDB が無いため in-memory PendingStore に差し替える
 vi.mock("@/lib/capture/idb", () => ({
     createIdbPendingStore: () => {
-        const items = new Map<string, unknown>();
+        const items = new Map<string, unknown>(
+            pendingSeed.map((item) => [item.clientTakeId, item]),
+        );
         return {
             put: async (item: { clientTakeId: string }) => {
                 items.set(item.clientTakeId, item);
@@ -161,6 +178,7 @@ beforeEach(() => {
     getUserMediaMock.mockReset();
     navigateToPanelMock.mockReset();
     navigateToPanelMock.mockReturnValue(false);
+    pendingSeed.length = 0;
 });
 
 afterEach(() => {
@@ -533,3 +551,378 @@ describe("Capture/Show サムネイル反映の配線 (T183)", () => {
         expect(routerReloadMock).not.toHaveBeenCalled();
     });
 });
+
+/*
+ * 横持ち全画面撮影の**ページ配線** (T186 施策 D)。
+ *
+ * 判定・スワイプ・移動判断そのものは landscape-capture.test.ts が、
+ * バーの操作系列は CutSwipeBar.test.ts が固定する。ここで固定するのは
+ * 「Show が全画面をどう組み立て、どの不変条件を守っているか」だけである。
+ *
+ * matchMedia の stub は本 describe 群の beforeEach でだけ入れて afterEach で戻す。
+ * 既定は現行挙動と同じ (prefers-reduced-motion: false / 横持ち: false) にしてあり、
+ * 上の既存テストは 1 件も書き換えていない。
+ */
+
+/** matchMedia stub の制御ハンドル。対象 query だけ真偽を切り替えられる。 */
+interface LandscapeMatchMedia {
+    set: (matches: boolean) => void;
+}
+
+function installLandscapeMatchMedia(initial: boolean): LandscapeMatchMedia {
+    const handlers = new Set<(event: MediaQueryListEvent) => void>();
+    let matches = initial;
+
+    vi.stubGlobal("matchMedia", (query: string) => ({
+        get matches() {
+            return query === LANDSCAPE_CAPTURE_MEDIA_QUERY ? matches : false;
+        },
+        media: query,
+        addEventListener: (type: string, handler: (event: MediaQueryListEvent) => void) => {
+            if (type === "change" && query === LANDSCAPE_CAPTURE_MEDIA_QUERY) {
+                handlers.add(handler);
+            }
+        },
+        removeEventListener: (type: string, handler: (event: MediaQueryListEvent) => void) => {
+            if (type === "change") handlers.delete(handler);
+        },
+    }));
+
+    return {
+        set: (next: boolean) => {
+            matches = next;
+            for (const handler of handlers) handler({ matches: next } as MediaQueryListEvent);
+        },
+    };
+}
+
+function makeLandscapeManual(count: number): CaptureManualDetail {
+    return {
+        id: 5,
+        title: "ネジ締め作業",
+        status: "ready",
+        cuts: Array.from({ length: count }, (_, index) =>
+            makeCut({ id: 101 + index, scene: `工程 ${index + 1}` }),
+        ),
+    };
+}
+
+function landscapeProps(count = 3): { project: { id: number; name: string }; manual: CaptureManualDetail } {
+    return { project: { id: 1, name: "現場A" }, manual: makeLandscapeManual(count) };
+}
+
+/** 実 CameraRecorder を録画状態まで駆動できる stub 一式 (component は本物のまま使う) */
+function stubCameraRecordable(): void {
+    resetFakeMediaRecorder();
+    vi.stubGlobal("MediaRecorder", FakeMediaRecorder);
+    vi.stubGlobal("navigator", {
+        ...navigator,
+        mediaDevices: { getUserMedia: getUserMediaMock },
+    });
+    getUserMediaMock.mockResolvedValue(fakeStream().stream);
+    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
+}
+
+function fullscreenState(): string | null {
+    return screen.getByTestId("capture-right-pane").getAttribute("data-fullscreen");
+}
+
+/**
+ * 祖先のどこかが inert で覆われているか。
+ * Svelte 5 は `inert` を **DOM プロパティ**として設定する (属性セレクタでは引けない) ため、
+ * `closest("[inert]")` ではなくプロパティを辿る。
+ */
+function hasInertAncestor(element: Element): boolean {
+    for (let node: Element | null = element; node !== null; node = node.parentElement) {
+        if (node instanceof HTMLElement && node.inert) return true;
+    }
+
+    return false;
+}
+
+describe("Capture/Show 横持ち全画面 (T186)", () => {
+    let landscape: LandscapeMatchMedia;
+
+    beforeEach(() => {
+        landscape = installLandscapeMatchMedia(true);
+    });
+
+    afterEach(() => {
+        vi.restoreAllMocks();
+        document.documentElement.classList.remove("overflow-hidden");
+    });
+
+    it("横持ち条件が真なら全画面になる", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        expect(fullscreenState()).toBe("true");
+    });
+
+    it("縦持ち条件では従来どおり全画面にならない", () => {
+        landscape.set(false);
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        expect(fullscreenState()).toBe("false");
+        expect(screen.queryByTestId("cut-swipe-bar")).not.toBeInTheDocument();
+    });
+
+    it("初回描画の時点で既に全画面 (tick を挟まない同期 assertion)", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        // $effect で状態を入れる実装ならこの時点では "false" になる = 実装前に落ちる
+        expect(fullscreenState()).toBe("true");
+    });
+
+    it("inline レイアウト固有の見出しが一度も DOM に現れない (ちらつきの直接検出)", async () => {
+        stubCameraSupported(false);
+        const seen: string[] = [];
+        let addedElements = 0;
+        const observer = new MutationObserver((records) => {
+            for (const record of records) {
+                for (const node of record.addedNodes) {
+                    if (!(node instanceof Element)) continue;
+                    addedElements += 1;
+                    if (
+                        node.matches('[data-testid="capture-recording-heading"]') ||
+                        node.querySelector('[data-testid="capture-recording-heading"]') !== null
+                    ) {
+                        seen.push("capture-recording-heading");
+                    }
+                }
+            }
+        });
+        observer.observe(document.body, { childList: true, subtree: true });
+
+        render(CaptureShow, { props: landscapeProps() });
+        await tick();
+
+        // callback は microtask 通知なので、保留分を回収してから切る
+        // (同期で切ると記録を取りこぼして常に緑になる = 最悪の空振り)
+        observer.takeRecords().forEach(() => undefined);
+        await Promise.resolve();
+        observer.disconnect();
+
+        // 空振り防止: 観測そのものが動いていること (0 件なら「何も見ていないから緑」になる)
+        expect(addedElements).toBeGreaterThan(0);
+        expect(seen).toEqual([]);
+    });
+
+    it("カット未選択でも先頭カットが自動選択される", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 1");
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("1 / 3");
+    });
+
+    it("「次のカット」でラベルが進み、末尾では告知が出てラベルが変わらない", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps(2) });
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-next"));
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-next"));
+        expect(screen.getByTestId("cut-navigation-notice")).toHaveTextContent(
+            "これが最後のカットです。",
+        );
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
+    });
+
+    it("先頭で「前のカット」は最初のカットである告知を出す", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-previous"));
+
+        expect(screen.getByTestId("cut-navigation-notice")).toHaveTextContent(
+            "これが最初のカットです。",
+        );
+    });
+
+    it("カットを選び直すと古い告知が消える", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-previous"));
+        expect(screen.getByTestId("cut-navigation-notice")).toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("cut-row-102"));
+
+        expect(screen.queryByTestId("cut-navigation-notice")).not.toBeInTheDocument();
+    });
+
+    it("全画面 ⇄ inline の切替で camera-preview が同一 DOM ノードのまま (不変条件 1)", async () => {
+        stubCameraSupported(true);
+        render(CaptureShow, { props: landscapeProps() });
+
+        const before = screen.getByTestId("camera-preview");
+        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
+        const after = screen.getByTestId("camera-preview");
+
+        expect(after).toBe(before);
+    });
+
+    it("終了ボタンで inline へ戻り、再入路のボタンで全画面へ戻れる (ラッチと再入路)", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
+        expect(fullscreenState()).toBe("false");
+
+        await fireEvent.click(screen.getByTestId("enter-fullscreen-capture"));
+        expect(fullscreenState()).toBe("true");
+    });
+
+    it("縦に戻すとラッチが解除され、再び横にすると自動で全画面へ入る", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
+        expect(fullscreenState()).toBe("false");
+
+        landscape.set(false);
+        await tick();
+        expect(fullscreenState()).toBe("false");
+
+        landscape.set(true);
+        await tick();
+        expect(fullscreenState()).toBe("true");
+    });
+
+    it("upload-queue-bar は inline / fullscreen のどちらでもちょうど 1 件 (不変条件 2)", async () => {
+        // 未送信テイクを用意しないと UploadQueueBar は 0 件のままで、
+        // 二重描画を作っても「たまたま 0 件だから緑」になり検出力が無い。
+        pendingSeed.push({
+            clientTakeId: "01J0PENDING",
+            blob: new Blob(["x".repeat(2048)], { type: "video/webm" }),
+        });
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        await vi.waitFor(() => {
+            expect(screen.queryAllByTestId("upload-queue-bar")).toHaveLength(1);
+        });
+
+        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
+        expect(screen.queryAllByTestId("upload-queue-bar")).toHaveLength(1);
+    });
+
+    it("全画面中は背景スクロールを止め、終了で必ず外す (不変条件 3)", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        await vi.waitFor(() => {
+            expect(document.documentElement.classList.contains("overflow-hidden")).toBe(true);
+        });
+
+        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
+
+        await vi.waitFor(() => {
+            expect(document.documentElement.classList.contains("overflow-hidden")).toBe(false);
+        });
+    });
+
+    it("全画面中は撮影パネル見出しとテイク一覧を出さない", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        expect(screen.queryByTestId("capture-recording-heading")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("take-strip-101")).not.toBeInTheDocument();
+    });
+
+    it("カット 0 件では全画面にならず、再入路のボタンも出さない", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps(0) });
+
+        expect(fullscreenState()).toBe("false");
+        expect(screen.queryByTestId("enter-fullscreen-capture")).not.toBeInTheDocument();
+    });
+
+    it("全画面へ入った直後のフォーカスは全画面内の見出しにある (不変条件 6)", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        await vi.waitFor(() => {
+            expect(document.activeElement).toBe(
+                screen.getByTestId("capture-fullscreen-heading"),
+            );
+        });
+    });
+
+    it("全画面中は page 自身の背後コンテンツが inert で覆われる (AppLayout の chrome は覆わない)", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: landscapeProps() });
+
+        expect(hasInertAncestor(screen.getByTestId("cut-row-101"))).toBe(true);
+        expect(hasInertAncestor(screen.getByTestId("manual-detail-link"))).toBe(true);
+        // 全画面そのものは inert の外にある (操作できないと詰む)
+        expect(hasInertAncestor(screen.getByTestId("exit-fullscreen-capture"))).toBe(false);
+    });
+
+    it("選択中カットが消えても全画面の出口は残る (不変条件 5)", async () => {
+        stubCameraSupported(false);
+        const { rerender } = render(CaptureShow, { props: landscapeProps() });
+
+        // 選択中カット (101) を含まない manual に差し替える
+        await rerender({
+            project: { id: 1, name: "現場A" },
+            manual: {
+                id: 5,
+                title: "ネジ締め作業",
+                status: "ready",
+                cuts: [makeCut({ id: 201, scene: "別の工程" })],
+            },
+        });
+
+        expect(fullscreenState()).toBe("true");
+        expect(screen.getByTestId("exit-fullscreen-capture")).toBeInTheDocument();
+    });
+});
+
+/*
+ * 録画中のカット移動抑止を**ページ配線として**固定する。
+ *
+ * CameraRecorder は stub へ差し替えない — 実際の onCaptureActiveChange 経路を通らないと
+ * 配線ミスを検出できないため。stub 一式は tests/js/support/fake-media-recorder.ts と共有する。
+ */
+describe("Capture/Show 全画面での録画中カット移動抑止 (T186)", () => {
+    beforeEach(() => {
+        installLandscapeMatchMedia(true);
+        stubCameraRecordable();
+    });
+
+    afterEach(() => {
+        vi.restoreAllMocks();
+        document.documentElement.classList.remove("overflow-hidden");
+    });
+
+    it("録画中は移動せずエラーを出し、停止後は移動できるようになる", async () => {
+        render(CaptureShow, { props: landscapeProps(2) });
+
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-next"));
+
+        expect(screen.getByTestId("cut-navigation-error")).toHaveTextContent(
+            "録画中はカットを移動できません。録画を停止してから移動してください。",
+        );
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 1");
+
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("start-recording")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("cut-navigation-error")).not.toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("cut-swipe-next"));
+
+        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
+    });
+});
diff --git a/tests/js/support/fake-media-recorder.ts b/tests/js/support/fake-media-recorder.ts
new file mode 100644
index 0000000..23c6c3a
--- /dev/null
+++ b/tests/js/support/fake-media-recorder.ts
@@ -0,0 +1,148 @@
+import { vi } from "vitest";
+
+/**
+ * CameraRecorder を **本物のまま**録画状態へ駆動するための最小 stub 一式。
+ *
+ * 元は CameraRecorder.test.ts の中だけにあったが、撮影ページ (CaptureShow.test.ts) でも
+ * 「録画中はカットを移動できない」というページ配線を実挙動で固定する必要が出たため、
+ * ここへ移設して共有する。component を stub へ差し替える形は採らない —
+ * 実際の onCaptureActiveChange 経路を通らないと配線ミスを検出できないため。
+ *
+ * **移設であって挙動の変更ではない**。CameraRecorder.test.ts の it ブロックは 1 行も
+ * 書き換えていないので、緑のままであることが「移設だけで挙動を変えていない」証拠になる。
+ */
+
+/** 手動発火できる最小 MediaRecorder stub (start/stop → ondataavailable/onstop) */
+export class FakeMediaRecorder {
+    static supportedTypes: string[] = ["video/webm"];
+    static isTypeSupported(type: string): boolean {
+        return FakeMediaRecorder.supportedTypes.includes(type);
+    }
+    static shouldThrowOnConstruct = false;
+    static shouldThrowOnStart = false;
+    static shouldThrowOnPause = false;
+    /** false のとき stop() は onstop を自動発火せず、テストが手動で駆動する (stopping 観測用) */
+    static autoStop = true;
+    /** false のとき pause()/resume() は onpause/onresume を自動発火せず、テストが手動で駆動する */
+    static autoPauseResume = true;
+
+    ondataavailable: ((event: { data: Blob }) => void) | null = null;
+    onstop: (() => void) | null = null;
+    onerror: (() => void) | null = null;
+    onpause: (() => void) | null = null;
+    onresume: (() => void) | null = null;
+    stopCalls = 0;
+    pauseCalls = 0;
+    resumeCalls = 0;
+    /** RecordingState 相当 (recoverPhaseFromRecorderState が参照する真実源) */
+    state: "inactive" | "recording" | "paused" = "inactive";
+
+    constructor(
+        public stream: unknown,
+        public options: { mimeType: string },
+    ) {
+        if (FakeMediaRecorder.shouldThrowOnConstruct) {
+            throw new DOMException("unsupported", "NotSupportedError");
+        }
+    }
+
+    start(): void {
+        if (FakeMediaRecorder.shouldThrowOnStart) {
+            throw new DOMException("invalid state", "InvalidStateError");
+        }
+        this.state = "recording";
+        // no-op (テストは stop() で明示的に onstop を駆動する)
+    }
+
+    stop(): void {
+        this.stopCalls += 1;
+        this.state = "inactive";
+        if (!FakeMediaRecorder.autoStop) return; // 手動駆動モード
+        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
+        this.onstop?.();
+    }
+
+    pause(): void {
+        if (FakeMediaRecorder.shouldThrowOnPause) {
+            throw new DOMException("invalid state", "InvalidStateError");
+        }
+        this.pauseCalls += 1;
+        this.state = "paused";
+        if (FakeMediaRecorder.autoPauseResume) this.onpause?.();
+    }
+
+    resume(): void {
+        this.resumeCalls += 1;
+        this.state = "recording";
+        if (FakeMediaRecorder.autoPauseResume) this.onresume?.();
+    }
+
+    /** 手動モードで onstop を駆動する (blob 生成 → onstop) */
+    fireStop(): void {
+        this.state = "inactive";
+        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
+        this.onstop?.();
+    }
+
+    /** 手動モードで onpause/onresume を駆動する */
+    firePause(): void {
+        this.onpause?.();
+    }
+    fireResume(): void {
+        this.onresume?.();
+    }
+}
+
+/** 静的フラグを既定へ戻す (beforeEach 用。テストごとの持ち越しを断つ) */
+export function resetFakeMediaRecorder(): void {
+    FakeMediaRecorder.supportedTypes = ["video/webm"];
+    FakeMediaRecorder.shouldThrowOnConstruct = false;
+    FakeMediaRecorder.shouldThrowOnStart = false;
+    FakeMediaRecorder.shouldThrowOnPause = false;
+    FakeMediaRecorder.autoStop = true;
+    FakeMediaRecorder.autoPauseResume = true;
+}
+
+/**
+ * 構築されたインスタンスを呼び出し側へ渡す派生クラスを作る。
+ * 捕捉先の変数はテストファイル側に置く (グローバルな可変状態をテスト間で共有しない)。
+ */
+export function createTrackingRecorderClass(
+    onConstruct: (recorder: FakeMediaRecorder) => void,
+): typeof FakeMediaRecorder {
+    return class TrackingFakeMediaRecorder extends FakeMediaRecorder {
+        constructor(stream: unknown, options: { mimeType: string }) {
+            super(stream, options);
+            onConstruct(this);
+        }
+    };
+}
+
+export interface FakeTrack {
+    stop: ReturnType<typeof vi.fn>;
+    onended: (() => void) | null;
+    applyConstraints: ReturnType<typeof vi.fn>;
+    getSettings: ReturnType<typeof vi.fn>;
+}
+
+/** getTracks()/getVideoTracks() が stop spy 付き track を返す fake stream (解放・flip 検証用) */
+export function fakeStream(facing: "environment" | "user" = "environment"): {
+    stream: MediaStream;
+    stop: ReturnType<typeof vi.fn>;
+    track: FakeTrack;
+} {
+    const stop = vi.fn();
+    const track: FakeTrack = {
+        stop,
+        onended: null,
+        // 既定は制約適用成功 + getSettings が要求 facingMode を返す (段階1 成功)
+        applyConstraints: vi.fn().mockResolvedValue(undefined),
+        getSettings: vi.fn(() => ({ facingMode: facing })),
+    };
+    const stream = {
+        getTracks: () => [track],
+        getVideoTracks: () => [track],
+    } as unknown as MediaStream;
+
+    return { stream, stop, track };
+}

```

## テスト結果

- `composer test` … 5416 tests / 5414 passed / 2 skipped (既存 skip)
- `composer phpstan` … No errors (level 10)
- `vendor/bin/pint --test` … passed
- `pnpm lint` … 0 problems
- `pnpm typecheck` … 0 errors
- `pnpm test` … 146 files / 1700 tests passed
- `pnpm build` … OK
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` … OK (106 tests)
- `composer test:browser -- --filter=CaptureLandscapeFullscreen` … chromium 7 passed / webkit 6 passed + 1 skipped

## 設計からの意図的な逸脱 (レビュー対象)

1. **撮影ガイドの矩形非交差テストは Chromium レーンでのみ成立する**。
   詳細設計は「Chromium + WebKit の 2 レーンで固定する」と書いていたが、
   実測で **Playwright WebKit (Linux) には `MediaRecorder` が存在せず**
   (`typeof window.MediaRecorder === "undefined"`)、撮影パネルがファイル選択
   フォールバックへ倒れて overlay が 1 つも描画されないことが分かった。
   そのため当該テストだけ前提を明示して `markTestSkipped` し、
   `docs/supported-browsers.md` の保証範囲の記述もそれに合わせて訂正した
   (誇張しないため)。他の 6 本は WebKit でも実行している。

2. **`CameraRecorder` を包む wrapper div の inline 時 class を `""` ではなく
   `"flex flex-col gap-4"` にした**。詳細設計は `""` だったが、この wrapper を
   挟むことでカメラ不可時のフォールバック経路 (notice + ファイル選択) が
   section 直下の兄弟でなくなり、既存の `gap-4` が効かなくなる (視覚的退行)。
   flex-col gap-4 にすると従来と同じ間隔が保たれ、recorder 経路 (子 1 つ) では
   何も変わらない。

3. **`inert` は Svelte 5 が DOM プロパティとして設定する**ため、テストの検査は
   `closest("[inert]")` (属性セレクタ) ではなくプロパティを辿る helper にした。

