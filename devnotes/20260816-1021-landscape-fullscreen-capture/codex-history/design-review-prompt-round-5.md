# Round 5: Round 4 指摘への対応

Round 4 の [Warning] 1 件 (唯一のブロッカー) と [Suggestion] 2 件をすべて反映しました。

## 対応マトリクス

# 対応マトリクス: design-review Round 4

## [Warning] 施策 E: `getAllByTestId("upload-queue-bar")` は正常な 0 件で例外になる

- 判断: **対応する** (指摘のとおり。テストが書けない計画だった)
- 根拠: `UploadQueueBar` は `{#if pendingCount > 0 || quotaMessage !== null}` を内側に持つ。
  未送信 0 件の通常状態では要素そのものが無いので、`getAllByTestId` は
  **重複していない正常な状態で** 例外を投げる。
  さらに `queryAllByTestId(...).length <= 1` に替えるだけでは、
  未送信 0 件のまま検査すると**二重描画を作っても緑になる** (検出力ゼロ) 。
- 対応内容: `queryAllByTestId` を使い、かつ**未送信テイクがある状態を用意して**
  inline / fullscreen の**両方でちょうど 1 件**であることを固定する形に書き換えた。
  「0 件でも落ちない」と「二重描画を実際に検出できる」の両方を満たす。

## [Suggestion] 施策 C: 矩形検査を guide × secondary にも広げる

- 判断: **対応する**
- 根拠: 設計は primary / guide / secondary の **3 レーンが交差しない**と主張しているのに、
  機械保証が guide × primary の 1 組だけでは主張と保証がずれる
  (本リポジトリが繰り返し戒めている「保証範囲の誇張」に当たる)。
- 対応内容: `subtitle_primary` / `subtitle_secondary` / `shooting_point` の 3 つとも
  非空のカットを用意し、**`guide × primary` と `guide × secondary` の 2 組**を
  `getBoundingClientRect()` で検査する形にした。

## [Suggestion] 施策 F: `docs/supported-browsers.md` の保証列挙に非交差検査も加える

- 判断: **対応する**
- 根拠: 同ファイルは「Browser レーンが実際に何を固定しているか」を列挙する場所であり、
  施策 C で足す検査を書かないと文書と実際の検査範囲がずれる。
- 対応内容: 追記文の列挙に
  「撮影ガイドと字幕 (上下 2 帯) の矩形が互いに交差しないこと」を足した。

## 施策 A / B / C / D / F: APPROVE

- 判断: **対応不要**。


---

## 修正後の詳細設計書 (全文)

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
      全画面に入り、**3 レーンが互いに交差しない**ことを `getBoundingClientRect()` で
      assert する (`guide × primary` と **`guide × secondary`** の 2 組。
      設計が主張しているのは 3 レーンの非交差なので、機械保証も同じ範囲にする)。
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
  「撮影ガイドと字幕 (上下 2 帯) の矩形が互いに交差しないこと」までである。
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

