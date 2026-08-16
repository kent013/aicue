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
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: drag-and-drop-reordering (シナリオ行とテイクのドラッグ&ドロップ並べ替え)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

> 本設計は **8 に直接関係する**。新設するドラッグハンドルも既存の ▲▼ も disabled にしない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロント固有: Svelte 5 runes + DS token のみ (`DESIGN.md` canonical / `ds-purity` が検出)。
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の
  単方向 import (`atomic-import-graph.test.ts`)。アイコンは `@lucide/svelte` のみ
  (`lucide-scoped-import.test.ts` / `svg-inline-allowlist.test.ts`)。

> **本設計は PHP を 1 行も変更しない**。よって各施策の「PHPStan 適合チェック」は
> **TypeScript strict 適合チェック**（`pnpm typecheck` = `tsc --noEmit` / `any` 不使用 /
> 素の型アサーション不使用）に読み替える。PHP 側は「変更 0 件であること」自体を
> 受け入れ条件とし、`composer phpstan` / `composer test` が**無変更のまま緑**であることを確認する。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) （Codex `conceptual-review` **Round 1 で APPROVED**）
- 受け入れ条件 A1〜A5（概念設計 §受け入れ条件）は本設計の各施策に割り付ける:

| 受け入れ条件 | 割り付け先 |
|---|---|
| A1 disabled を増やさない / 端は告知する | 施策 3・4・5 |
| A2 ポインタ状態を必ず解放する | 施策 2（単一出口 `finish()`）・施策 4・5（`$effect` の cleanup） |
| A3 iOS Safari 実機確認を devnotes に記録 | 施策 7（受け入れ手順） |
| A4 共通化の境界を越えない | 施策 1・2（lib は lifecycle / 挿入位置計算 / moveItem のみ） |
| A5 挿入 index と最終 index を分ける | 施策 1（関数分離）・施策 7（off-by-one テスト） |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 並べ替え計算の純関数モジュール | `resources/js/lib/dnd/list-reorder.ts` (新規) | 高 |
| 2 | Pointer Events のドラッグ制御 (Svelte 非依存の素 TS) | `resources/js/lib/dnd/pointer-drag.ts` (新規) | 高 |
| 3 | ドラッグハンドル atom | `resources/js/components/atoms/DragHandle.svelte` / `DragHandle.types.ts` (新規) | 高 |
| 4 | シナリオ編集への配線 (手順行・急所行) | `resources/js/components/features/manual/ScenarioEditor.svelte` | 高 |
| 5 | 撮影 PWA テイク列への配線 | `resources/js/components/features/capture/TakeStrip.svelte` | 高 |
| 6 | jsdom に無い pointer capture のスタブ | `tests/js/setup.ts` | 中 |
| 7 | テスト (純関数 2 本 + コンポーネント 2 本) | `tests/js/lib/dnd/*.test.ts` / 既存 2 テストへ追記 | 高 |

---

## 施策 1: 並べ替え計算の純関数モジュール

### 変更箇所

- ファイル: `resources/js/lib/dnd/list-reorder.ts` (**新規**)

### 波及変更

- TypeScript 型定義: 本ファイルが `RowBounds` を新規 export する。他型への影響なし
- API Resource/DTO: **なし**（サーバ変更なし）
- テストファイル: `tests/js/lib/dnd/list-reorder.test.ts` (新規)
- 走査系テストへの影響: `ds-purity` は `resources/js` 配下の `.ts` も走査するが、
  本ファイルは class 文字列を持たないため無関係。`atomic-import-graph` 上は `util` 層

### 現行コード

存在しない（新規）。同等の計算は現在 `ScenarioEditor.svelte` の隣接 swap に埋め込まれている:

```svelte
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
    runSettled(() =>
        commitStructural(() => {
            [steps[index], steps[next]] = [steps[next], steps[index]];
        }),
    );
}
```

### 変更後コード

```ts
/**
 * リスト並べ替えの純関数 (DOM に触れない)。
 *
 * D&D は DOM イベントの連鎖でテストしづらい。そこで「どこに落ちたら何番目になるか」の
 * 意味論だけをここに閉じ込め、Vitest で網羅する (概念設計 D3)。
 *
 * **index の語彙は 2 つある**。混同すると off-by-one になるため関数を分ける (受け入れ条件 A5):
 * - **挿入 index (insertion index)**: 行と行の隙間を数えた `0..n` の値。n 行のリストには
 *   隙間が n+1 個ある。「どの隙間に落としたか」を表す。
 * - **最終 index (final index)**: 移動が終わった後の配列での `0..n-1` の値。
 *   撮影 PWA がサーバへ渡す `position` はこちらである
 *   (`CaptureTakeService::reorderWithinCut` は対象を除いた配列へ splice するため、
 *   結果として「移動後の全体配列での 0 始まり index」と一致する)。
 */

/** 行の上下位置 (getBoundingClientRect の実測値。viewport 座標) */
export interface RowBounds {
    readonly top: number;
    readonly height: number;
}

/**
 * 要素を from から to (最終 index) へ動かした**新しい配列**を返す。入力は変更しない。
 * 範囲外・非整数は「動かさない」に倒す (fail-safe。呼び出し側で throw させない)。
 */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
    const next = [...list];
    if (!Number.isInteger(from) || !Number.isInteger(to)) return next;
    if (from < 0 || from >= next.length) return next;
    const clamped = Math.min(Math.max(to, 0), next.length - 1);
    if (clamped === from) return next;
    const [moved] = next.splice(from, 1);
    next.splice(clamped, 0, moved);
    return next;
}

/**
 * ポインタの Y 座標 (viewport 座標) から**挿入 index** (0..rows.length) を決める。
 * 各行の中点より上なら「その行の前」、下ならさらに次の行を見る。
 *
 * rows は**表示順**で、掴んでいる行自身も含めて渡す (DOM から抜かないため)。
 * スクロールしても `getBoundingClientRect()` を採り直せば viewport 座標系で
 * ポインタ座標と一致する (受け入れ条件 A2)。
 */
export function insertionIndexFromRects(rows: readonly RowBounds[], pointerY: number): number {
    for (let i = 0; i < rows.length; i += 1) {
        if (pointerY < rows[i].top + rows[i].height / 2) return i;
    }
    return rows.length;
}

/**
 * 挿入 index → 最終 index。
 * 掴んだ行自身がいったんリストから抜けるぶん、掴んだ位置より後ろの隙間は 1 つ手前へ詰まる。
 * 掴んだ行の前後 2 つの隙間 (from と from+1) はどちらも「動かさない」= from になる。
 */
export function toFinalIndex(insertion: number, from: number): number {
    return insertion > from ? insertion - 1 : insertion;
}
```

### TypeScript strict 適合チェック（PHPStan 適合チェックの読み替え）

- [x] 戻り値の型が明示されている（`T[]` / `number`）
- [x] `any` / 素の型アサーション（`as`）を使っていない
- [x] 入力を破壊しない（`readonly T[]` を受け、`[...list]` を返す）
- [x] 範囲外入力で throw せず定義済みの値へ倒す（fail-safe）
- [x] DOM API に触れない（jsdom 非依存で単体テストできる）

### テスト計画

- [x] 新規 `tests/js/lib/dnd/list-reorder.test.ts`
  - `moveItem`: 前方移動 / 後方移動 / 端 → 端 / `from === to` の no-op /
    範囲外 from / 非整数 / 空配列 / **入力配列が変更されていないこと** (immutability)
  - `insertionIndexFromRects`: 空配列 → 0 / 1 行目の中点の上 → 0 / 中点の下 → 1 /
    最終行の中点より下 → `rows.length` / 行の高さが不揃いでも中点で切り替わること
  - `toFinalIndex`: `insertion <= from` は素通し / `insertion > from` は 1 減 /
    **`from` と `from+1` がどちらも `from` になる**こと
  - **合成テスト (A5 の本丸)**: 4 要素の配列に対し、全 (from, insertion) の組み合わせで
    `moveItem(list, from, toFinalIndex(insertion, from))` の結果が
    「手で並べた期待値」と一致することを表駆動で検証する（off-by-one の恒久回帰）
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

- 挿入 index / 最終 index の語彙が実装者に伝わらないと再び混同が起きる。
  → 関数名・doc コメント・表駆動テストの 3 点で固定する。

---

## 施策 2: Pointer Events のドラッグ制御

### 変更箇所

- ファイル: `resources/js/lib/dnd/pointer-drag.ts` (**新規**)

### 波及変更

- TypeScript 型定義: `PointerDragCallbacks` / `PointerDragController` / `PointerDragState` を新規 export
- API Resource/DTO: **なし**
- テストファイル: `tests/js/lib/dnd/pointer-drag.test.ts` (新規)
- `tests/js/setup.ts`: pointer capture のスタブ（施策 6）

### 現行コード

存在しない（新規）。現状 `resources/js` 配下に `pointerdown` / `draggable` / `touch-action` を
使う実装は 1 件も無い（`rg` で確認済み）。

### 変更後コード

```ts
/**
 * Pointer Events による 1 軸 (縦) の並べ替えドラッグ制御。**Svelte に依存しない素の TS**。
 *
 * HTML5 Drag and Drop API は iOS Safari のタッチで発火しないため採らない (概念設計 D1)。
 * 撮影 PWA の主戦場は iOS Safari (docs/supported-browsers.md) なので、
 * マウス・タッチ・ペンを 1 系統で扱える Pointer Events に一本化する。
 *
 * **共通化の境界** (受け入れ条件 A4): ここに置くのは
 * (i) ポインタの生死管理 (ii) 挿入位置の算出 (iii) 端での自動スクロール だけである。
 * 保存経路・文言・aria-live メッセージ・見た目・サーバへ渡す position 変換は
 * 呼び出し側 (feature component) に残す。
 */
import { insertionIndexFromRects, toFinalIndex, type RowBounds } from "./list-reorder";

/** ドラッグ開始とみなす最小移動量 (px)。タップ/クリックをドラッグにしない */
export const DRAG_ACTIVATION_DISTANCE = 6;
/** 画面端からこの距離に入ったら自動スクロールする (px) */
export const AUTO_SCROLL_EDGE = 64;
/** 自動スクロールの 1 フレームあたりの移動量 (px) */
export const AUTO_SCROLL_STEP = 12;

/** 表示用の状態。UI (影ではなく border と不透明度) の描画にのみ使う */
export interface PointerDragState {
    /** 掴んでいる行の index。ドラッグしていなければ null */
    readonly activeIndex: number | null;
    /** 落とし先の隙間 (挿入 index)。ドラッグしていなければ null */
    readonly insertionIndex: number | null;
}

export interface PointerDragCallbacks {
    /** 表示順の行要素を返す (呼び出し側が DOM から採る)。毎回の pointermove で採り直す */
    readonly rows: () => ReadonlyArray<HTMLElement>;
    /** 表示状態の変化通知 */
    readonly onState: (state: PointerDragState) => void;
    /** 確定。`to` は**最終 index**。`from === to` のときは呼ばれない */
    readonly onCommit: (from: number, to: number) => void;
    /** 取消 (Esc / pointercancel / 位置が変わらなかった / 破棄) */
    readonly onCancel?: () => void;
}

export interface PointerDragController {
    /** ハンドルの pointerdown から呼ぶ */
    readonly start: (index: number, event: PointerEvent) => void;
    /** 現在ドラッグ中か (呼び出し側が click 抑止などに使う) */
    readonly isDragging: () => boolean;
    /** コンポーネント破棄時に必ず呼ぶ (受け入れ条件 A2) */
    readonly destroy: () => void;
}

export function createPointerDrag(callbacks: PointerDragCallbacks): PointerDragController {
    let pointerId: number | null = null;
    let handle: HTMLElement | null = null;
    let fromIndex = 0;
    let startY = 0;
    /** 閾値を超えて実際にドラッグが始まったか */
    let activated = false;
    let insertion: number | null = null;
    let scrollFrame: number | null = null;
    let scrollDelta = 0;

    function bounds(): RowBounds[] {
        return callbacks.rows().map((el): RowBounds => {
            const rect = el.getBoundingClientRect();
            return { top: rect.top, height: rect.height };
        });
    }

    function stopAutoScroll(): void {
        if (scrollFrame !== null && typeof cancelAnimationFrame === "function") {
            cancelAnimationFrame(scrollFrame);
        }
        scrollFrame = null;
        scrollDelta = 0;
    }

    function tickAutoScroll(): void {
        scrollFrame = null;
        if (pointerId === null || scrollDelta === 0) return;
        window.scrollBy(0, scrollDelta);
        scrollFrame = requestAnimationFrame(tickAutoScroll);
    }

    /**
     * 画面端に近ければスクロールを回す。
     * requestAnimationFrame が無い環境 (jsdom 等) では自動スクロールだけ働かない。
     * 並べ替えそのものは動くので、機能検出で静かに劣化させる (誇張しない)。
     */
    function updateAutoScroll(clientY: number): void {
        if (typeof requestAnimationFrame !== "function") return;
        const height = window.innerHeight;
        const next =
            clientY < AUTO_SCROLL_EDGE
                ? -AUTO_SCROLL_STEP
                : clientY > height - AUTO_SCROLL_EDGE
                  ? AUTO_SCROLL_STEP
                  : 0;
        scrollDelta = next;
        if (next === 0) {
            stopAutoScroll();
            return;
        }
        if (scrollFrame === null) scrollFrame = requestAnimationFrame(tickAutoScroll);
    }

    /**
     * **すべての終了経路が合流する唯一の出口** (受け入れ条件 A2)。
     * pointerup / pointercancel / Escape / destroy はここへ入る。
     * 資源 (pointer capture / rAF) を先に解放してから callback を呼ぶので、
     * callback 内で再入しても状態は壊れない。
     */
    function finish(commit: boolean): void {
        if (pointerId === null) return;
        const wasActivated = activated;
        const target = insertion;
        const from = fromIndex;
        if (
            handle !== null &&
            typeof handle.releasePointerCapture === "function" &&
            typeof handle.hasPointerCapture === "function" &&
            handle.hasPointerCapture(pointerId)
        ) {
            handle.releasePointerCapture(pointerId);
        }
        pointerId = null;
        handle = null;
        activated = false;
        insertion = null;
        stopAutoScroll();
        callbacks.onState({ activeIndex: null, insertionIndex: null });
        if (!commit || !wasActivated || target === null) {
            callbacks.onCancel?.();
            return;
        }
        const to = toFinalIndex(target, from);
        if (to === from) {
            callbacks.onCancel?.();
            return;
        }
        callbacks.onCommit(from, to);
    }

    function onPointerMove(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        if (!activated) {
            if (Math.abs(event.clientY - startY) < DRAG_ACTIVATION_DISTANCE) return;
            activated = true;
        }
        // ハンドルの touch-action:none と併せて、スクロール/テキスト選択との競合を断つ
        event.preventDefault();
        insertion = insertionIndexFromRects(bounds(), event.clientY);
        updateAutoScroll(event.clientY);
        callbacks.onState({ activeIndex: fromIndex, insertionIndex: insertion });
    }

    function onPointerUp(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(true);
    }

    function onPointerCancel(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(false);
    }

    function onKeyDown(event: KeyboardEvent): void {
        if (pointerId !== null && event.key === "Escape") finish(false);
    }

    // listener は生成時に 1 度だけ張り destroy で外す (start/finish のたびに張り替えない)。
    // capture が使えない環境でも window で拾えるよう、ハンドルではなく window に張る。
    window.addEventListener("pointermove", onPointerMove, { passive: false });
    window.addEventListener("pointerup", onPointerUp);
    window.addEventListener("pointercancel", onPointerCancel);
    window.addEventListener("keydown", onKeyDown);

    return {
        start(index: number, event: PointerEvent): void {
            if (pointerId !== null) return; // 2 本目の指は無視 (多点ドラッグは提供しない)
            if (event.pointerType === "mouse" && event.button !== 0) return; // 左ボタンのみ
            const target = event.currentTarget;
            handle = target instanceof HTMLElement ? target : null;
            pointerId = event.pointerId;
            fromIndex = index;
            startY = event.clientY;
            activated = false;
            insertion = null;
            // pointer capture が無い環境 (jsdom / 一部の古い WebKit) でも
            // window の listener で同じ callback 契約のまま完走する (受け入れ条件 A2)
            if (handle !== null && typeof handle.setPointerCapture === "function") {
                handle.setPointerCapture(event.pointerId);
            }
        },
        isDragging(): boolean {
            return pointerId !== null && activated;
        },
        destroy(): void {
            finish(false); // 進行中のドラッグを取消として畳む
            window.removeEventListener("pointermove", onPointerMove);
            window.removeEventListener("pointerup", onPointerUp);
            window.removeEventListener("pointercancel", onPointerCancel);
            window.removeEventListener("keydown", onKeyDown);
        },
    };
}
```

### TypeScript strict 適合チェック

- [x] 戻り値の型が明示されている（全関数）
- [x] `any` を使わない。`event.currentTarget` は `instanceof HTMLElement` で絞る（素の `as` なし）
- [x] `null` 安全（`pointerId === null` の早期 return を全ハンドラの先頭に置く）
- [x] 省略可能な callback は `?.()` で呼ぶ
- [x] ブラウザ API は機能検出する（`setPointerCapture` / `hasPointerCapture` /
      `releasePointerCapture` / `requestAnimationFrame` / `cancelAnimationFrame`）
- [x] `window` を module 読み込み時に触らない（`createPointerDrag` を呼んだ時だけ触るので
      SSR/Node 読み込みで落ちない）

### テスト計画

- [x] 新規 `tests/js/lib/dnd/pointer-drag.test.ts`（jsdom 上で `PointerEvent` を直接 dispatch）
  - 閾値未満の移動では `onCommit` も `onState(active)` も起きない（タップがドラッグにならない）
  - 閾値超え → `onState` が `{activeIndex, insertionIndex}` を通知する
  - `pointerup` で `onCommit(from, to)` が**最終 index** で 1 回だけ呼ばれる
  - 位置が変わらない drop は `onCommit` ではなく `onCancel`
  - `pointercancel` / `Escape` は `onCommit` を呼ばず `onCancel` を呼ぶ
  - **異なる `pointerId` の move/up を無視する**（2 本目の指で確定しない）
  - `destroy()` 後に `pointermove` を投げても callback が呼ばれない（listener 解放の証明）
  - `destroy()` を drag 中に呼ぶと `onCancel` が呼ばれ、`onCommit` は呼ばれない
  - `setPointerCapture` が**無い**環境でも一連の流れが完走する
    （jsdom 既定。施策 6 のスタブは `beforeEach` で局所的に外して 1 ケース検証する）
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| `window` に張った listener が漏れる | `destroy()` で必ず外す。テストで「destroy 後は callback が来ない」ことを固定する |
| rAF の自動スクロールが止まらない | `finish()` が必ず `stopAutoScroll()` を通る。`pointerId === null` なら tick 自身も自走を止める |
| pointer capture が効かず、ハンドル外へ出るとイベントが切れる | listener を `window` に張っているため capture の有無に依存しない（capture は補助） |
| `preventDefault()` がスクロールを殺しすぎる | `preventDefault` はドラッグが**確定してから**(閾値超え後)しか呼ばない。ハンドル以外は `touch-action` を変えないので通常スクロールは無傷 |
| iOS Safari の実挙動が jsdom と異なる | A3: 実機確認を devnotes に記録する。自動テストの緑を「iOS 対応の実証」と書かない |

---

## 施策 3: ドラッグハンドル atom

### 変更箇所

- ファイル: `resources/js/components/atoms/DragHandle.svelte` (**新規**)
- ファイル: `resources/js/components/atoms/DragHandle.types.ts` (**新規**)

### 波及変更

- TypeScript 型定義: `DragHandleProps` を新規 export（既存 atom の `Badge.types.ts` /
  `Button.types.ts` と同じ companion 型ファイル方式に合わせる）
- API Resource/DTO: **なし**
- テストファイル: 単体テストは置かず、施策 4・5 のコンポーネントテストで挙動を検証する
  （atom 単体では「押しても何も起きない」ため、意味のある assert は配線側にしか無い）
- 走査系テスト: `atomic-import-graph`（atom は token/util/external のみ import 可 →
  `@lucide/svelte` は external、自身の `.types.ts` は相対 import で同層 = 適合）、
  `lucide-scoped-import` / `svg-inline-allowlist`（Lucide 経由のみ）、`ds-purity`（token のみ）

### 現行コード

存在しない（新規）。既存 `Button` atom は `onpointerdown` / `onkeydown` を prop に持たないため、
**共有 atom である `Button` に prop を足すのではなく**、専用 atom を新設する
（`Button` への prop 追加は全画面が影響範囲になるのに対し、ここで必要なのは
「掴むための小さな取っ手」という別の役割である。思考原則 4）。

### 変更後コード

`resources/js/components/atoms/DragHandle.types.ts`:

```ts
/**
 * 並べ替えの取っ手 (DragHandle) の props。
 * 「掴む」ことが役割であり、押しても何も起きない点で Button とは別物なので atom を分ける。
 */
export interface DragHandleProps {
    /**
     * 何を掴んでいるかの読み上げ。
     * 例: 「手順 2 の並び順を変更 (ドラッグ、または上下キー)」
     */
    ariaLabel: string;
    /** ドラッグ開始 (PointerDragController.start へ中継する) */
    onpointerdown: (event: PointerEvent) => void;
    /** キーボードでの 1 段移動 (ArrowUp / ArrowDown)。呼び出し側が既存の移動関数へ写す */
    onkeydown?: (event: KeyboardEvent) => void;
    testId?: string;
    class?: string;
}
```

`resources/js/components/atoms/DragHandle.svelte`:

```svelte
<script lang="ts">
    import { GripVertical } from "@lucide/svelte";
    import type { DragHandleProps } from "./DragHandle.types";

    let {
        ariaLabel,
        onpointerdown,
        onkeydown,
        testId,
        class: extraClass = "",
    }: DragHandleProps = $props();

    // 小コントロール → rounded-sm (DESIGN.md §Shapes)。影・scale は使わない (§Elevation)。
    // touch-none: ハンドル上のタッチをブラウザのスクロールに奪われないようにする
    //   (これを付けないと iOS Safari で縦ドラッグがページスクロールになる)。
    // select-none: ドラッグ中のテキスト選択を抑止する。
    // **disabled にはしない** (禁止事項 8 / 受け入れ条件 A1)。
    const computedClass = $derived(
        [
            "inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center",
            "rounded-sm border border-transparent text-text-secondary select-none",
            "transition-colors duration-150",
            "hover:border-border-strong hover:text-text",
            "focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none",
            "active:cursor-grabbing",
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );
</script>

<button
    type="button"
    class={computedClass}
    aria-label={ariaLabel}
    data-testid={testId}
    {onpointerdown}
    {onkeydown}
>
    <GripVertical class="size-4" aria-hidden="true" />
</button>
```

### TypeScript strict 適合チェック

- [x] props は `DragHandleProps` で型付け（`$props()` の暗黙 any なし）
- [x] `any` / 素の型アサーションなし
- [x] `class` を `class: extraClass` で受ける（既存 atom と同じ命名）
- [x] `ds-purity` 抵触なし（raw palette / hex / 静的 inline style / shadow / gradient /
      scale / 素の rounded / raw text-size をいずれも含まない）

### テスト計画

- [x] 施策 4・5 のコンポーネントテストで、`data-testid` によるハンドルの実在、
      `aria-label` の文言、`ArrowUp` / `ArrowDown` の動作を検証する
- [x] `disabled` 属性を一切持たないこと（禁止事項 8 の回帰防止）を
      ScenarioEditor / TakeStrip 双方のテストで assert する

### リスク

| リスク | 対応 |
|---|---|
| focus できるのに何も起きないコントロールになる | `ArrowUp`/`ArrowDown` で既存の 1 段移動を必ず呼ぶ（受け入れ条件 A1/D6） |
| `touch-none` が広がりすぎてスクロールを殺す | ハンドル要素**だけ**に付ける。行やリストには付けない |
| icon-only ボタンの操作対象が小さすぎる（タッチ） | `size-8` (32px) を最低ラインとし、実機確認 (A3) で押しにくさを確認する |

---

## 施策 4: シナリオ編集への配線

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - L224-244（`moveStep` / `movePoint`）
  - L841-951（手順 `<ol>` と急所 `<ol>` の markup）
  - script 冒頭の import 群（L1-32）

### 波及変更

- TypeScript 型定義: **変更なし**（`DraftStep` / `DraftPoint` / `ScenarioDocument` は無変更）
- Inertia Props: **変更なし**（`projectId` / `manualId` / `scenario` の 3 props は無変更）
- API Resource / DTO: **変更なし**（PUT payload は `payloadSteps()` のまま。
  `sort_order` / `parent_cut_id` / `type` はサーバ導出のままで、**順序は配列順が唯一の表現**）
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts` に追記
- 履歴 util: `resources/js/lib/manual/scenario-history.ts` は**変更なし**
  （並べ替えは既存 `commitStructural` を通るので履歴形式が変わらない）

### 現行コード

```svelte
/** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
    runSettled(() =>
        commitStructural(() => {
            [steps[index], steps[next]] = [steps[next], steps[index]];
        }),
    );
}

function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
    const points = steps[stepIndex].points;
    const next = index + delta;
    if (next < 0 || next >= points.length) return;
    runSettled(() =>
        commitStructural(() => {
            [points[index], points[next]] = [points[next], points[index]];
        }),
    );
}
```

markup（抜粋）:

```svelte
<ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
    {#each steps as step, stepIndex (step.clientKey)}
        <li>
            <Card padding="md">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                    <div class="flex items-center gap-1">
                        <Button ... onclick={() => moveStep(stepIndex, -1)} testId="step-{stepIndex}-move-up"> ...
```

### 変更後コード

**(a) import 追加**

```svelte
import DragHandle from "@/components/atoms/DragHandle.svelte";
import { moveItem } from "@/lib/dnd/list-reorder";
import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
```

**(b) 移動関数を「任意位置移動」に一本化し、▲▼ はその特殊形にする**

```svelte
/**
 * 並べ替えは「任意位置への移動」1 本に集約する。
 * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
 * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
 * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
 * ここで順序表現を作る必要はない。
 */
function moveStepTo(from: number, to: number): void {
    if (from === to || to < 0 || to >= steps.length) return;
    runSettled(() =>
        commitStructural(() => {
            steps = moveItem(steps, from, to);
        }),
    );
    announce(`手順 ${from + 1} を ${to + 1} 番目に移動しました`);
}

function movePointTo(stepIndex: number, from: number, to: number): void {
    const points = steps[stepIndex]?.points;
    if (points === undefined) return;
    if (from === to || to < 0 || to >= points.length) return;
    runSettled(() =>
        commitStructural(() => {
            steps[stepIndex].points = moveItem(points, from, to);
        }),
    );
    announce(`急所 ${stepIndex + 1}-${from + 1} を ${to + 1} 番目に移動しました`);
}

/** ▲▼ (既存 UI。挙動は現行と同じ = 1 段移動 + 端は無変更) */
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) {
        // disabled にはしない (禁止事項 8)。押されたら「なぜ動かないか」を告知する
        announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
        return;
    }
    moveStepTo(index, next);
}

function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
    const points = steps[stepIndex]?.points;
    if (points === undefined) return;
    const next = index + delta;
    if (next < 0 || next >= points.length) {
        announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
        return;
    }
    movePointTo(stepIndex, index, next);
}
```

**(c) 読み上げ領域 (aria-live)**

```svelte
/** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
let reorderStatus = $state("");
function announce(message: string): void {
    reorderStatus = message;
}
```

```svelte
<p class="sr-only" aria-live="polite" data-testid="scenario-reorder-status">{reorderStatus}</p>
```

**(d) ドラッグ制御の生成と破棄**

```svelte
/** ドラッグ表示状態 (手順リスト / 急所リストで別々に持つ) */
let stepDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
let pointDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
/** 急所ドラッグ中の親手順 index (急所は手順をまたがないので 1 つで足りる) */
let pointDragStep = $state<number | null>(null);

/** 手順 <ol> / ドラッグ中の急所 <ol> の実体 (行の実測に使う) */
let stepListEl = $state<HTMLOListElement | null>(null);
let pointListEl: HTMLOListElement | null = null; // ドラッグ中のみ有効 (非 reactive で足りる)

function directRows(list: HTMLElement | null): HTMLElement[] {
    if (list === null) return [];
    return [...list.querySelectorAll<HTMLElement>(":scope > li")];
}

// controller は client 生成時に 1 度だけ作り、破棄時に必ず destroy する (受け入れ条件 A2)。
// この $effect は $state を同期的に読まないので再実行されない (callback は呼ばれた時に読む)。
let stepDragCtl: ReturnType<typeof createPointerDrag> | null = null;
let pointDragCtl: ReturnType<typeof createPointerDrag> | null = null;
$effect(() => {
    stepDragCtl = createPointerDrag({
        rows: () => directRows(stepListEl),
        onState: (state) => (stepDrag = state),
        onCommit: (from, to) => moveStepTo(from, to),
    });
    pointDragCtl = createPointerDrag({
        rows: () => directRows(pointListEl),
        onState: (state) => (pointDrag = state),
        onCommit: (from, to) => {
            if (pointDragStep !== null) movePointTo(pointDragStep, from, to);
        },
        onCancel: () => {
            pointListEl = null;
            pointDragStep = null;
        },
    });
    return () => {
        stepDragCtl?.destroy();
        pointDragCtl?.destroy();
        stepDragCtl = null;
        pointDragCtl = null;
    };
});

function onStepHandleDown(index: number, event: PointerEvent): void {
    stepDragCtl?.start(index, event);
}

/** 急所は「掴んだ行が属する <ol>」を先に確定してから開始する (手順をまたがないため) */
function onPointHandleDown(stepIndex: number, pointIndex: number, event: PointerEvent): void {
    const target = event.currentTarget;
    pointListEl =
        target instanceof HTMLElement ? target.closest<HTMLOListElement>("ol[data-point-list]") : null;
    pointDragStep = stepIndex;
    pointDragCtl?.start(pointIndex, event);
}

/** ハンドル上のキーボード操作 (▲▼ と同じ 1 段移動へ写す) */
function onHandleKeydown(event: KeyboardEvent, move: (delta: -1 | 1) => void): void {
    if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
    event.preventDefault();
    move(event.key === "ArrowUp" ? -1 : 1);
}
```

> `pointDragCtl.onCommit` の後始末（`pointListEl` / `pointDragStep` の解除）は
> `onCancel` だけでなく確定時も必要なので、実装では `onCommit` の末尾でも同じ 2 行を実行する。

**(e) markup（手順リスト。急所リストも同型）**

```svelte
<ol
    class="mt-4 flex flex-col gap-4 {stepDrag.activeIndex !== null ? 'select-none' : ''}"
    data-testid="scenario-steps"
    bind:this={stepListEl}
>
    {#each steps as step, stepIndex (step.clientKey)}
        <li class="relative" data-reorder-index={stepIndex}>
            {#if stepDrag.insertionIndex === stepIndex}
                <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
                <div class="absolute inset-x-0 -top-2 h-0.5 bg-primary" aria-hidden="true"></div>
            {/if}
            <div class={stepDrag.activeIndex === stepIndex ? "opacity-50" : ""}>
                <Card padding="md">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <DragHandle
                                ariaLabel={`手順 ${stepIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                                onpointerdown={(event) => onStepHandleDown(stepIndex, event)}
                                onkeydown={(event) =>
                                    onHandleKeydown(event, (delta) => moveStep(stepIndex, delta))}
                                testId="step-{stepIndex}-drag-handle"
                            />
                            <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                        </div>
                        <div class="flex items-center gap-1">
                            <!-- 既存の ▲▼ と削除ボタンは無変更 (キーボード経路を消さない) -->
                        </div>
                    </div>
                    ...
                </Card>
            </div>
        </li>
    {/each}
    {#if stepDrag.insertionIndex === steps.length}
        <li class="h-0.5 bg-primary" aria-hidden="true"></li>
    {/if}
</ol>
```

急所側は `<ol ... data-point-list bind:this=...>` の代わりに **属性 `data-point-list` だけ**を
付ける（`bind:this` は使わず `closest()` で引く。手順ごとに ref を持たずに済む）。

### TypeScript strict 適合チェック

- [x] `querySelectorAll<HTMLElement>` / `closest<HTMLOListElement>` の型引数を明示
      （素の `as` を使わない）
- [x] `event.currentTarget` は `instanceof HTMLElement` で絞る
- [x] `steps[stepIndex]?.points` の undefined を早期 return で処理
- [x] `PointerDragState` を型 import して `$state` の型を明示
- [x] `ReturnType<typeof createPointerDrag>` で controller の型を明示（`any` なし）
- [x] `pnpm typecheck` / `pnpm lint` / `svelte-no-undef-gate` が緑

### テスト計画

`tests/js/components/features/manual/ScenarioEditor.test.ts` に describe を追加する。

- [x] 既存テスト（`step-1-move-up` / `step-0-move-down` を使う 3 箇所）は**無変更で緑**
      であること（▲▼ の外形と挙動を変えていないことの証明）
- [x] 新規: ハンドルの pointerdown → pointermove（閾値超え、目的行の中点より下）→ pointerup で
      **`payloadSteps` の順序が入れ替わる**こと（保存ボタン押下時の PUT body で検証する。
      `fetch` mock の body を JSON.parse して `steps[].id` の並びを assert）
- [x] 新規: D&D の直後に `scenario-dirty-indicator` が出ること（dirty 判定との整合）
- [x] 新規: D&D の直後に「元に戻す」で**元の順序へ戻る**こと（undo 履歴との整合）
- [x] 新規: 急所行の D&D が**同じ手順の中だけ**で完結すること
      （別手順の急所 `<ol>` の行は `rows()` に入らない = `closest()` による絞り込みの検証）
- [x] 新規: ドラッグ中に `Escape` を押すと順序が変わらないこと
- [x] 新規: ハンドルに focus して `ArrowUp` / `ArrowDown` で 1 段移動すること
- [x] 新規: ハンドルが `disabled` 属性を持たないこと（禁止事項 8）
- [x] 新規: 先頭行で `ArrowUp` を押すと順序が変わらず、
      `scenario-reorder-status` に「これ以上、上へは移動できません」が入ること（A1）
- [x] テスト用の DOM 実測: `HTMLElement.prototype.getBoundingClientRect` を
      `vi.spyOn` で行ごとに固定値（`top = index * 100`, `height = 100`）へ差し替える
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| `steps = moveItem(...)` の再代入で keyed each が全再描画になる | key は `clientKey` で変わらないため DOM は移動のみ。既存 undo/redo も `steps` 再代入で動いている（`restoreFrom`）ので前例がある |
| `$effect` が state を読んで再実行され、controller が多重生成される | effect 本体で `$state` を同期的に読まない設計にする。テストで「destroy 後に callback が来ない」ことを固定し、多重生成は `onCommit` の呼び出し回数 1 回で検出する |
| IME 変換中の D&D が履歴を壊す | `runSettled` を通すので既存の pending キューに乗る（構造操作と同じ扱い） |
| ドラッグ中に別タブ/別操作でリストが縮む | `moveStepTo` が `to` の範囲を毎回検査する。範囲外は no-op |
| `-top-2` の目印がカード間の `gap-4` の中央に来ない | 目印は視覚的補助であり、判定は `insertionIndexFromRects` が持つ。位置は実装時に微調整（値ではなく仕組みで判定している。思考原則） |

---

## 施策 5: 撮影 PWA テイク列への配線

### 変更箇所

- ファイル: `resources/js/components/features/capture/TakeStrip.svelte`
  - L106-109（`move` の定義）
  - L188-220（リスト container と ▲▼ の markup）
  - script 冒頭の import 群

### 波及変更

- TypeScript 型定義: **変更なし**（`CaptureTake` / `CaptureCut` は無変更）
- API Resource / DTO: **変更なし**。既存の `PATCH /app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}`
  に `{ position }` を送る既存経路をそのまま使う。
  `UpdateCaptureTakeRequest`（`position` は `nullable|integer|min:0`）・
  `CaptureTakeService::update()` / `reorderWithinCut()` は**無変更**
- Inertia Props: **変更なし**（`onChanged` によるサーバ再取得も現行どおり）
- テストファイル: `tests/js/components/features/capture/TakeStrip.test.ts` に追記

### 現行コード

```svelte
const move = (take: CaptureTake, position: number) =>
    run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));
```

```svelte
<div class="flex shrink-0 flex-col gap-1">
    <Button variant="ghost" size="sm" iconOnly ariaLabel="上へ"
        onclick={() => move(take, index - 1)} testId={`take-up-${take.id}`}>
        <ChevronUp class="size-4" aria-hidden="true" />
    </Button>
    <Button variant="ghost" size="sm" iconOnly ariaLabel="下へ"
        onclick={() => move(take, index + 1)} testId={`take-down-${take.id}`}>
        <ChevronDown class="size-4" aria-hidden="true" />
    </Button>
</div>
```

サーバ側（無変更。`position` の意味の根拠）:

```php
/** cut 内の並べ替え (対象を position に挿入し 0..n-1 でサーバ再採番)。行ロック下で呼ぶ */
private function reorderWithinCut(Cut $lockedCut, Take $target, int $position): void
{
    $ordered = $lockedCut->takes()->whereKeyNot($target->id)->orderBy('sort_order')->orderBy('id')->get()->all();
    $position = min($position, count($ordered));
    array_splice($ordered, $position, 0, [$target]);
    ...
}
```

> **`position` の基準（受け入れ条件 A5 の確定）**: サーバは「対象を除いた配列」へ `position` で
> splice する。対象を除いた配列に index `p` で挿入した結果、その要素は**全体配列でも index `p`**
> に来る。したがって **`position` = 移動後の全体配列での 0 始まり index = 最終 index** であり、
> `toFinalIndex()` の出力をそのまま渡してよい。既存 ▲▼ の `index + 1`（1 段下げ）もこの定義と
> 一致する（`[A,B,C]` で A を position 1 → 除外後 `[B,C]` に 1 で挿入 → `[B,A,C]`）。

### 変更後コード

**(a) import 追加**

```svelte
import DragHandle from "@/components/atoms/DragHandle.svelte";
import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
```

**(b) 並べ替えの単一入口**

```svelte
/**
 * テイクの並べ替え。▲▼・ハンドルのキーボード・D&D のすべてがここへ合流する。
 * position は**最終 index**（移動後の全体配列での 0 始まり index）。
 * サーバの reorderWithinCut が対象を除いた配列へ splice するため両者は一致する。
 *
 * クライアント側で楽観的に並べ替えない（サーバ権威）。理由は 2 つ:
 *   1. 採用テイク (adopted_take_id) と親の再取得 (onChanged) が同じ経路で更新されるため、
 *      ローカル順序を別に持つと 2 つの真実ができる
 *   2. 既存の ▲▼ と同じ挙動になり、並べ替え手段ごとに体感が割れない
 */
function reorderTo(from: number, to: number): void {
    const take = cut.takes[from];
    if (take === undefined || from === to || to < 0 || to >= cut.takes.length) return;
    announce(`テイク ${from + 1} を ${to + 1} 番目に移動しました`);
    void move(take, to);
}

/** ▲▼ とハンドルのキーボードの共通経路 (1 段移動)。端は disabled にせず告知する */
function moveBy(index: number, delta: -1 | 1): void {
    const to = index + delta;
    if (to < 0 || to >= cut.takes.length) {
        announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
        return;
    }
    reorderTo(index, to);
}

let reorderStatus = $state("");
function announce(message: string): void {
    reorderStatus = message;
}
```

既存 `move` は残す（`reorderTo` が呼ぶ）。▲▼ の `onclick` は
`() => move(take, index - 1)` から `() => moveBy(index, -1)` へ差し替える。

> **既存挙動の変化（意図的）**: 現行は先頭で「上へ」を押すと `position: 0` の PATCH が飛び、
> サーバ側 no-op のうえで親の再取得が走っていた。変更後は**通信せず**告知だけ行う。
> ボタンは活性のままで（禁止事項 8 に適合）、無駄な往復と再取得が消える。

**(c) ドラッグ制御**

```svelte
let takeDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
let listEl = $state<HTMLDivElement | null>(null);
let dragCtl: ReturnType<typeof createPointerDrag> | null = null;

$effect(() => {
    dragCtl = createPointerDrag({
        rows: () =>
            listEl === null ? [] : [...listEl.querySelectorAll<HTMLElement>(":scope > [data-reorder-index]")],
        onState: (state) => (takeDrag = state),
        onCommit: (from, to) => reorderTo(from, to),
    });
    return () => {
        dragCtl?.destroy();
        dragCtl = null;
    };
});

function onHandleKeydown(event: KeyboardEvent, index: number): void {
    if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
    event.preventDefault();
    moveBy(index, event.key === "ArrowUp" ? -1 : 1);
}
```

**(d) markup**

```svelte
<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`} bind:this={listEl}>
    ...
    {#each cut.takes as take, index (take.id)}
        <div
            class="relative flex flex-wrap items-center ... {takeDrag.activeIndex === index ? 'opacity-50' : ''}"
            data-testid={`take-item-${take.id}`}
            data-reorder-index={index}
        >
            {#if takeDrag.insertionIndex === index}
                <div class="absolute inset-x-0 -top-1 h-0.5 bg-primary" aria-hidden="true"></div>
            {/if}
            <DragHandle
                ariaLabel={`テイク ${index + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                onpointerdown={(event) => dragCtl?.start(index, event)}
                onkeydown={(event) => onHandleKeydown(event, index)}
                testId={`take-drag-${take.id}`}
            />
            <div class="flex shrink-0 flex-col gap-1">
                <!-- 既存の 上へ / 下へ ボタン (onclick だけ moveBy へ差し替え) -->
            </div>
            ...
        </div>
    {/each}
    <p class="sr-only" aria-live="polite" data-testid="take-reorder-status">{reorderStatus}</p>
    {#if error}
        <p class="text-caption text-danger" role="alert" data-testid="take-strip-error">{error}</p>
    {/if}
</div>
```

> `listEl` を `data-testid={take-strip-...}` の div にそのまま bind するため、
> `:scope > [data-reorder-index]` の絞り込みで**行だけ**を採る
> （末尾の告知段落・エラー段落は `data-reorder-index` を持たないので混ざらない）。

### TypeScript strict 適合チェック

- [x] `querySelectorAll<HTMLElement>` の型引数を明示
- [x] `cut.takes[from]` の undefined を早期 return で処理
- [x] `void move(take, to)` で floating promise を明示的に破棄（既存 `run` と同じ扱い）
- [x] `PointerDragState` / `ReturnType<typeof createPointerDrag>` で型明示（`any` なし）
- [x] `pnpm typecheck` / `pnpm lint` が緑

### テスト計画

`tests/js/components/features/capture/TakeStrip.test.ts` に describe を追加する。

- [x] 新規: ハンドルの pointerdown → pointermove（3 行目の中点より下）→ pointerup で
      **`PATCH` の body が `{ position: <最終 index> }`** になること（A5 の本丸。
      3 要素で「1 番目を 3 番目へ」= `position: 2`、「3 番目を 1 番目へ」= `position: 0`）
- [x] 新規: 位置が変わらない drop では **`fetch` が呼ばれない**こと
- [x] 新規: ドラッグ中の `Escape` / `pointercancel` で `fetch` が呼ばれないこと
- [x] 新規: ハンドル focus 中の `ArrowUp` / `ArrowDown` が既存 ▲▼ と同じ PATCH を出すこと
- [x] 新規: 先頭で `ArrowUp` / 末尾で `ArrowDown` は **`fetch` を出さず**
      `take-reorder-status` に告知が入ること（A1。既存挙動からの意図的な変更）
- [x] 新規: PATCH が 422 を返したら既存の `take-strip-error`（`role="alert"`）に
      サーバ文言が出ること（失敗経路が D&D でも同じであることの証明）
- [x] 新規: ハンドルが `disabled` 属性を持たないこと（禁止事項 8）
- [x] 既存テスト（採用 / 削除 / DL ACK / プレビュー）は**無変更で緑**であること
- [x] `getBoundingClientRect` は施策 4 と同じ方法でテストごとに固定する
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| drop 後、サーバ再取得までの間に行が元位置へ戻って見える | 既存 ▲▼ と同じ挙動。`run()` が `busyTakeId` を立てるので操作中であることは伝わる。楽観更新は「2 つの真実」を作るため採らない（設計判断として明記） |
| 端での PATCH をやめたことで、意図せず何かが壊れる | 既存テストに端 PATCH を assert するものは無い（確認済み）。no-op PATCH と再取得が消えるだけ |
| ドラッグ中に親の再取得（ポーリング等）でリストが差し替わる | `rows()` は毎 move で DOM を採り直し、`reorderTo` が範囲を再検査する。範囲外は no-op |
| ハンドルが増えて 1 行が横に広がりスマホで折り返す | 行は既に `flex-wrap`。ハンドルは `shrink-0` で最左に固定。実機確認 (A3) の確認項目に入れる |

---

## 施策 6: jsdom に無い pointer capture のスタブ

### 変更箇所

- ファイル: `tests/js/setup.ts`（末尾の既存スタブ群の隣に追加）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイル自体がテスト基盤

### 現行コード

`tests/js/setup.ts` は `Element.prototype.animate` / `ResizeObserver` /
`Element.prototype.scrollTo` / `scrollIntoView` を「未実装なら最小スタブ」の形で補っている。

### 変更後コード

```ts
// jsdom は Pointer capture (setPointerCapture / releasePointerCapture / hasPointerCapture) を
// 実装しない。並べ替えの制御 (resources/js/lib/dnd/pointer-drag.ts) は機能検出して
// 無い環境でも完走するが、「capture がある環境」の分岐もテストで通せるよう
// 実際の捕捉状態を覚える最小スタブを入れる。
if (typeof Element !== "undefined" && typeof Element.prototype.setPointerCapture !== "function") {
    const captured = new WeakMap<Element, Set<number>>();
    Element.prototype.setPointerCapture = function (pointerId: number): void {
        const ids = captured.get(this) ?? new Set<number>();
        ids.add(pointerId);
        captured.set(this, ids);
    };
    Element.prototype.releasePointerCapture = function (pointerId: number): void {
        captured.get(this)?.delete(pointerId);
    };
    Element.prototype.hasPointerCapture = function (pointerId: number): boolean {
        return captured.get(this)?.has(pointerId) ?? false;
    };
}
```

### TypeScript strict 適合チェック

- [x] `WeakMap<Element, Set<number>>` で型を明示（`any` なし）
- [x] 既存スタブと同じ「未実装なら入れる」条件付きにする（実装がある環境を上書きしない）

### テスト計画

- [x] `pointer-drag.test.ts` の 1 ケースで、スタブを一時的に外した状態
      （`vi.spyOn` ではなく `delete` ではなく、**capture 非対応を模す専用ケース**として
      `Element.prototype.setPointerCapture` を `undefined` にする `beforeEach`/`afterEach` 対）
      でも一連の流れが完走することを検証する
- [x] スタブ導入で既存テストが 1 件も壊れないこと（`pnpm test` 全緑）

### リスク

| リスク | 対応 |
|---|---|
| スタブが本物と挙動が違い、テストが実ブラウザを保証しない | 保証範囲を誇張しない。**capture の有無で結果が変わらない設計**（listener は window）にしてあり、スタブは分岐網羅のためだけに置く。iOS Safari の実挙動は A3 の実機確認が担う |

---

## 施策 7: テストと受け入れ手順

### 変更箇所

- 新規: `tests/js/lib/dnd/list-reorder.test.ts` / `tests/js/lib/dnd/pointer-drag.test.ts`
- 追記: `tests/js/components/features/manual/ScenarioEditor.test.ts`
- 追記: `tests/js/components/features/capture/TakeStrip.test.ts`

### 波及変更

- **テスト目録**: `scripts/test-inventory-config.ts` が Vitest の `include` の正本であり、
  `scripts/vitest-inventory-gate.test.ts` が FS 走査と突き合わせる。
  **確認済み**: root project の include は `["tests/js/**/*.test.ts", "scripts/**/*.test.ts"]` で、
  新規の `tests/js/lib/dnd/*.test.ts` は既存 glob に入る = **目録の変更は不要**。
  （テスト置き場を `tests/js/` の外へ動かす場合のみ目録を同じ変更で直すこと）
- **CI**: 追加の workflow は作らない（`ci-workflow-inventory.test.ts` の W17 等に触れない）

### テスト方針（D&D をどうテストするか）

D&D の実挙動は DOM とブラウザ実装に強く依存する。そこで**検証対象を 3 層に分ける**:

| 層 | 何を固定するか | 手段 |
|---|---|---|
| 1. 意味論 | どこに落ちたら何番目になるか（off-by-one） | `list-reorder.test.ts`（純関数・表駆動・DOM なし） |
| 2. 生死 | ポインタの開始/確定/取消/解放が漏れないか | `pointer-drag.test.ts`（jsdom で `PointerEvent` を dispatch。`getBoundingClientRect` は spy で固定） |
| 3. 配線 | 落としたら既存の保存経路が期待どおり動くか | 2 つのコンポーネントテスト（PUT body の順序 / PATCH の position） |

**実機でしか分からないこと**（タッチの取りこぼし、慣性スクロールとの競合、標準外の
ジェスチャ割り込み）は自動テストの対象外であり、A3 の実機確認で扱う。
**Vitest の緑を「iOS Safari で動く証明」と書かない。**

### 受け入れ手順（実装完了の条件）

1. `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build` が緑
2. `composer test` / `composer phpstan` / `vendor/bin/pint --test` が**無変更のまま**緑
   （PHP 差分が 0 件であることを併せて確認する）
3. **iOS Safari 実機**（PWA standalone を含む）で以下を確認し、日時・端末・OS バージョン・
   結果を `devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md` に記録する（A3）:
   - テイク列のハンドルを縦にドラッグして順序が変わる
   - ハンドル以外を触ったときは従来どおりページがスクロールする
   - リスト末尾が画面外にあるとき、端に寄せると自動スクロールして落とせる
   - ドラッグ中に着信・アプリ切替が入っても（`pointercancel`）操作が中断されるだけで
     順序が壊れない
4. シナリオ編集を **Chrome / Safari デスクトップ**でマウス操作し、
   undo（元に戻す）で元の順序に戻ることを確認する

### リスク

| リスク | 対応 |
|---|---|
| 新規テストディレクトリが `include` に載らず走らない | 目録 (`scripts/test-inventory-config.ts`) との突き合わせを受け入れ条件に入れる |
| 実機確認が省略されて「対応済み」と書かれる | 記録ファイルの存在を受け入れ条件 3 に明記。無い場合は完了にしない |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規ファイル 5 本 + 既存 3 ファイル改修で 1 つの機能が完結し、途中状態に意味が無い（純関数だけ入れても UI は変わらず、UI だけ入れても計算が無い）。(2) 触るのはフロントの 2 画面と共通 lib のみで、他ドメインの同時作業とファイルが重ならない。(3) サーバ・DB・API 契約に触れないためマイグレーション順序の制約が無い |
| 競合リスク | `ScenarioEditor.svelte` / `TakeStrip.svelte` を触る他タスクがあれば衝突する（どちらも大きなファイル）。`tests/js/setup.ts` は追記のみで衝突しにくい。`scripts/test-inventory-config.ts` を触る場合は他タスクと確認する |
| 実装順序 | 施策 1 → 2 → 6（テスト基盤）→ 3 → 4 → 5 → 7。1・2 はテストファースト（fail を見てから実装）で進められる |

## 使命・禁止事項の最終チェック

- **使命への寄与**: 「編集ゼロ」の中核である「AI 生成シナリオの構成順の修正」と
  「採用候補テイクの入れ替え」を、それぞれ 1 ジェスチャに縮める。撮影 PWA（現場）と
  PC 編集（設計）の両方に効く。
- **禁止事項 1（テストなしの完了報告）**: 全施策にテストを割り付け済み。
  D&D の弱点（テストしづらさ）に対しては 3 層分割で対処した。
- **禁止事項 8（disabled）**: ハンドルも ▲▼ も disabled にしない。端での要求は
  aria-live で告知する。
- **禁止事項 2/4/5/6/7（PHP 側）**: PHP を変更しないため抵触しない。
- **禁止事項 9（Artifact）**: 成果物は本 devnotes 配下のファイルのみ。
- **依存追加なし**: 新しい npm パッケージを入れないため、
  `docs/supply-chain/review-checklist.md` の審査対象は増えない。
  将来ライブラリ化する場合は同 §（新規依存の審査観点）に沿って別途審議する。


---

## 関連する現行コード

### `resources/js/components/features/manual/ScenarioEditor.svelte` (L200-300)

```svelte
        };
    }

    function addStep(): void {
        runSettled(() =>
            commitStructural(() => steps.push({ ...emptyRow("hiki"), id: null, points: [] })),
        );
    }

    function addPoint(stepIndex: number): void {
        runSettled(() =>
            commitStructural(() => steps[stepIndex].points.push({ ...emptyRow("yori"), id: null })),
        );
    }

    function removeStep(index: number): void {
        runSettled(() => commitStructural(() => steps.splice(index, 1)));
        confirmingStepIndex = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
    }

    function removePoint(stepIndex: number, pointIndex: number): void {
        runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
    }

    /** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
    function moveStep(index: number, delta: -1 | 1): void {
        const next = index + delta;
        if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
        runSettled(() =>
            commitStructural(() => {
                [steps[index], steps[next]] = [steps[next], steps[index]];
            }),
        );
    }

    function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
        const points = steps[stepIndex].points;
        const next = index + delta;
        if (next < 0 || next >= points.length) return;
        runSettled(() =>
            commitStructural(() => {
                [points[index], points[next]] = [points[next], points[index]];
            }),
        );
    }

    // --- 履歴コア (保存前ローカル編集のみ対象。undo/redo は steps を再代入し安定 clientKey で差分描画) ---

    /** 保存/リロード時に履歴を断つ (保存前ローカル編集のみ対象。R1 決定) */
    function resetHistory(): void {
        undoStack = [];
        redoStack = [];
        editBaseline = null;
        flushDeferred = false;
        pendingActions = [];
    }

    /** editBaseline を(変化があれば)確定して 1 エントリに積む。IME-aware・冪等 */
    function flushPendingEdit(): void {
        if (composing) {
            flushDeferred = true; // 変換確定後に compositionend で flush
            return;
        }
        if (editBaseline === null) return;
        const before = editBaseline;
        editBaseline = null; // 冪等化 (直後の focusout で再 push しない)
        if (pushHistory(undoStack, before, serializeSteps(steps))) {
            redoStack = []; // 新規編集で redo クリア
        }
    }

    /** 構造操作/undo/redo の IME ゲート。composing 中は compositionend まで保留 */
    function runSettled(action: () => void): void {
        if (composing) {
            pendingActions.push(action); // FIFO: 発行順に compositionend で実行 (R4 policy)
            return;
        }
        action();
    }

    /** 構造操作の共通コミット: pending 編集確定 → 変更前を控え → 変異 → 変化があれば push */
    function commitStructural(mutate: () => void): void {
        flushPendingEdit();
        const before = serializeSteps(steps);
        mutate();
        if (pushHistory(undoStack, before, serializeSteps(steps))) {
            redoStack = [];
        }
    }

    /**
     * 履歴文字列を検証(util)→ rowOf 正規化で新規 DraftStep[] を作り steps に反映。
     * 壊れていれば false(steps を変えない fail-safe)。素の型アサーションを残さない。
     */
    function restoreFrom(serialized: string): boolean {
        const parsed = parseHistorySnapshot(serialized); // util: unknown→type predicate→検証済み
        if (parsed === null) return false;
        steps = parsed.map((step) => ({
            ...rowOf(step),
            id: step.id,
            clientKey: step.clientKey, // 安定 key を round-trip
```

### `resources/js/components/features/manual/ScenarioEditor.svelte` (L822-960)

```svelte
<section
    aria-label="シナリオ編集"
    onfocusin={onEditorFocusIn}
    onfocusout={onEditorFocusOut}
    oncompositionstart={onCompositionStart}
    oncompositionend={onCompositionEnd}
>
    {#if steps.length === 0}
        <div class="mt-4">
            <EmptyState
                title="シナリオがまだありません"
                description="手順を追加して、マニュアル動画の台本を組み立てましょう。"
                icon={ListPlus}
                bordered
                cta={{ kind: "action", label: "最初の手順を追加", onclick: addStep }}
                testId="scenario-empty-state"
            />
        </div>
    {:else}
        <ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
            {#each steps as step, stepIndex (step.clientKey)}
                <li>
                    <Card padding="md">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を上へ移動`}
                                    onclick={() => moveStep(stepIndex, -1)}
                                    testId="step-{stepIndex}-move-up"
                                >
                                    <ChevronUp class="size-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を下へ移動`}
                                    onclick={() => moveStep(stepIndex, 1)}
                                    testId="step-{stepIndex}-move-down"
                                >
                                    <ChevronDown class="size-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="danger-ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を削除`}
                                    onclick={() => (confirmingStepIndex = stepIndex)}
                                    testId="step-{stepIndex}-remove"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </div>
                        <div class="mt-3">
                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                        </div>

                        {#if step.points.length > 0}
                            <ol class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4">
                                {#each step.points as point, pointIndex (point.clientKey)}
                                    <li>
                                        <div class="flex items-start justify-between gap-2">
                                            <h4 class="text-caption font-medium text-text-secondary">
                                                急所 {stepIndex + 1}-{pointIndex + 1}
                                            </h4>
                                            <div class="flex items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を上へ移動`}
                                                    onclick={() => movePoint(stepIndex, pointIndex, -1)}
                                                    testId="point-{stepIndex}-{pointIndex}-move-up"
                                                >
                                                    <ChevronUp class="size-4" aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を下へ移動`}
                                                    onclick={() => movePoint(stepIndex, pointIndex, 1)}
                                                    testId="point-{stepIndex}-{pointIndex}-move-down"
                                                >
                                                    <ChevronDown class="size-4" aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    variant="danger-ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
                                                    onclick={() => removePoint(stepIndex, pointIndex)}
                                                    testId="point-{stepIndex}-{pointIndex}-remove"
                                                >
                                                    <Trash2 class="size-4" aria-hidden="true" />
                                                </Button>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            {@render rowFields(
                                                point,
                                                `steps.${stepIndex}.points.${pointIndex}`,
                                                `point-${stepIndex}-${pointIndex}`,
                                            )}
                                        </div>
                                    </li>
                                {/each}
                            </ol>
                        {/if}

                        <div class="mt-4">
                            <Button
                                variant="ghost"
                                size="sm"
                                onclick={() => addPoint(stepIndex)}
                                testId="step-{stepIndex}-add-point"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                急所を追加
                            </Button>
                        </div>
                    </Card>
                </li>
            {/each}
        </ol>

        <div class="mt-4">
            <Button variant="neutral" onclick={addStep} testId="scenario-add-step">
                <Plus class="size-4" aria-hidden="true" />
                手順を追加
            </Button>
        </div>
    {/if}

```

### `resources/js/components/features/capture/TakeStrip.svelte` (L85-130)

```svelte
    function takeUrl(take: CaptureTake, suffix = ""): string {
        return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
    }

    async function run(take: CaptureTake, action: () => Promise<Response>): Promise<void> {
        error = null;
        busyTakeId = take.id;
        try {
            const response = await action();
            if (!response.ok) {
                error = await extractErrorMessage(response);
                return;
            }
            onChanged();
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
        } finally {
            busyTakeId = null;
        }
    }

    const adopt = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take, "/adopt"), "POST"));
    const remove = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take), "DELETE"));
    const move = (take: CaptureTake, position: number) =>
        run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));

    // 再生ボタン押下: 撮影中はエラー表示して開かない (押下時エラー。disabled 禁止)。
    function openPreview(take: CaptureTake): void {
        error = null;
        if (captureActive) {
            // captureActive は recording|stopping を含む (撮影データ保護のため preview と同居させない)
            error = "撮影中はプレビューを再生できません。撮影を停止してからお試しください。";
            return;
        }
        previewTarget = take;
        onRequestCameraRelease?.(); // 撮影待機中の live stream を解放
        previewOpen = true;
    }

    // dialog が閉じた時 (背景クリック / Esc / × / 閉じるボタン / 採用成功) の単一クリーンアップ点。
    // TakePreviewDialog が open の true→false 遷移でちょうど 1 回だけ呼ぶ (二重復帰防止)。
    function handlePreviewClose(): void {
        previewTarget = null;
        onCameraResume?.(); // 録画待機を復帰
    }

```

### `resources/js/components/features/capture/TakeStrip.svelte` (L186-260)

```svelte
</script>

<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`}>
    {#if cut.takes.length === 0}
        <p class="text-caption text-text-secondary">テイクはまだありません。撮影してください。</p>
    {/if}
    {#each cut.takes as take, index (take.id)}
        <div
            class="flex flex-wrap items-center gap-x-2 gap-y-2 rounded-md border border-border bg-surface px-3 py-2 sm:flex-nowrap {take.downloaded
                ? 'border-border-strong'
                : ''}"
            data-testid={`take-item-${take.id}`}
        >
            <div class="flex shrink-0 flex-col gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="上へ"
                    onclick={() => move(take, index - 1)}
                    testId={`take-up-${take.id}`}
                >
                    <ChevronUp class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="下へ"
                    onclick={() => move(take, index + 1)}
                    testId={`take-down-${take.id}`}
                >
                    <ChevronDown class="size-4" aria-hidden="true" />
                </Button>
            </div>
            <div class="min-w-0 flex-1">
                <p
                    class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-body"
                    data-testid={`take-label-${take.id}`}
                >
                    テイク {index + 1}
                    {#if cut.adopted_take_id === take.id}
                        <Badge tone="success" testId={`take-adopted-${take.id}`}>採用中</Badge>
                    {/if}
                    {#if take.downloaded}
                        <Badge tone="neutral">DL 済み</Badge>
                    {/if}
                </p>
                <p class="text-caption text-text-secondary">
                    {sizeLabel(take.size_bytes)}
                    {#if take.duration_ms !== null}
                        ・{Math.round(take.duration_ms / 1000)} 秒
                    {/if}
                    {#if take.comment}
                        ・{take.comment}
                    {/if}
                </p>
                {#if take.status !== "ready"}
                    <p class="text-caption text-text-secondary" data-testid={`take-not-ready-${take.id}`}>
                        {#if take.status === "failed"}
                            アップロードに失敗しました。
                        {:else}
                            アップロード処理中は再生できません。
                        {/if}
                    </p>
                {/if}
            </div>
            <div
                class="flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start"
                data-testid={`take-actions-${take.id}`}
            >
                {#if take.status === "ready"}
                    <Button
                        variant="ghost"
                        size="sm"
```

### `app/Services/Capture/CaptureTakeService.php` (L62-90)

```php
    /**
     * コメント・並べ替え (position = cut 内 0 始まり)。sort_order はサーバ再採番。
     */
    public function update(Project $project, VideoManual $manual, Cut $cut, Take $take, CaptureTakeUpdateInput $input): Take
    {
        return DB::transaction(function () use ($project, $manual, $cut, $take, $input): Take {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
            /** @var Take $lockedTake */
            $lockedTake = $lockedCut->takes()->whereKey($take->id)->firstOrFail();

            if ($input->hasComment) {
                $lockedTake->fill(['comment' => $input->comment])->save();
            }
            if ($input->position !== null) {
                $this->reorderWithinCut($lockedCut, $lockedTake, $input->position);
                $lockedTake->refresh();
            }

            return $lockedTake;
        });
    }

    /**
     * 削除。DL 済み (downloaded_at 非 null) は 422。採用中なら null 化 + S3 削除 Job (業務 tx 内)。
     */
    public function delete(Project $project, VideoManual $manual, Cut $cut, Take $take): void
```

### `app/Services/Capture/CaptureTakeService.php` (L154-184)

```php
    /** cut 内の並べ替え (対象を position に挿入し 0..n-1 でサーバ再採番)。行ロック下で呼ぶ */
    private function reorderWithinCut(Cut $lockedCut, Take $target, int $position): void
    {
        /** @var list<Take> $ordered */
        $ordered = $lockedCut->takes()
            ->whereKeyNot($target->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
        $position = min($position, count($ordered));
        array_splice($ordered, $position, 0, [$target]);
        foreach ($ordered as $index => $take) {
            if ($take->sort_order !== $index) {
                $take->forceFill(['sort_order' => $index])->save();
            }
        }
    }

    /** 削除後の詰め直し (0..n-1)。行ロック下で呼ぶ */
    private function renumber(Cut $lockedCut): void
    {
        /** @var list<Take> $ordered */
        $ordered = $lockedCut->takes()->orderBy('sort_order')->orderBy('id')->get()->all();
        foreach ($ordered as $index => $take) {
            if ($take->sort_order !== $index) {
                $take->forceFill(['sort_order' => $index])->save();
            }
        }
    }
}
```

### `resources/js/lib/manual/scenario-history.ts` (L1-50)

```ts
import type { DraftPoint, DraftStep } from "@/types/manual";

/**
 * シナリオ編集の undo/redo 履歴ユーティリティ(純関数)。
 * 1 エントリ = ScenarioEditor.serializeSteps() の正規化 JSON 文字列
 * (clientKey + id + 本文 8 フィールド + points)。
 * サイズ上限は件数と総文字数の二本立て (メモリ非有界化の防止)。
 */

/** 履歴の最大エントリ数 */
export const MAX_HISTORY_ENTRIES = 100;
/** 履歴の総文字数ソフト上限 (≈ 数 MB。単一エントリが超えても保持する) */
export const MAX_HISTORY_CHARS = 2_000_000;

/** serializeSteps が出力する行 shape (DraftPoint と構造一致。id は number|null) */
export type SerializedRow = DraftPoint;
/** serializeSteps が出力する step shape (DraftStep と構造一致) */
export type SerializedStep = DraftStep;

/**
 * スタック(古→新)を上限内に収める(破壊的 in-place。同一参照を返す)。
 * 先頭(最古)から捨てるが、length>1 を保持し単一エントリでは空にしない
 * (= MAX_HISTORY_CHARS はソフト上限)。
 */
export function boundHistory(
    stack: string[],
    maxEntries: number = MAX_HISTORY_ENTRIES,
    maxChars: number = MAX_HISTORY_CHARS,
): string[] {
    let chars = stack.reduce((total, entry) => total + entry.length, 0);
    while (stack.length > 1 && (stack.length > maxEntries || chars > maxChars)) {
        const removed = stack.shift();
        if (removed === undefined) break;
        chars -= removed.length;
    }
    return stack;
}

/**
 * before が current と異なるときのみ before を stack に積み、上限を適用する。
 * 積んだら true(呼び出し側は true のとき redo スタックをクリアする)。
 */
export function pushHistory(stack: string[], before: string, current: string): boolean {
    if (before === current) return false;
    stack.push(before);
    boundHistory(stack);
    return true;
}

/** 履歴 1 行 (clientKey + id + 本文 8 フィールド) の type predicate */
```

### `resources/js/components/atoms/Badge.svelte` (L1-27)

```svelte
<script lang="ts">
    import type { BadgeProps } from "./Badge.types";
    import { BORDERED_CLASSES, SIZE_CLASSES, TONE_CLASSES } from "./Badge.types";

    let {
        tone = "primary",
        size = "sm",
        bordered = false,
        icon,
        class: extraClass = "",
        testId,
        children,
    }: BadgeProps = $props();

    // バッジは小コントロール → rounded-sm (DESIGN.md §Shapes)
    const computedClass = $derived(
        [
            "inline-flex items-center gap-1 rounded-sm",
            TONE_CLASSES[tone],
            bordered ? BORDERED_CLASSES[tone] : "",
            SIZE_CLASSES[size],
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );
</script>
```

### `scripts/test-inventory-config.ts` (L27-38)

```ts
export const TEST_PROJECTS: readonly TestProject[] = [
    {
        name: "root",
        root: ".",
        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
    },
    {
        name: "packages/cli",
        root: "packages/cli",
        include: ["tests/**/*.test.ts"],
    },
] as const;
```
