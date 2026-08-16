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
 *
 * legacy な `addListener` へのフォールバックは**書かない**。撮影 PWA が要求する
 * MediaRecorder の最低版 (iOS Safari 14.5) は addEventListener の対応版 (14) より
 * 新しく、二重の登録経路は後方互換の並走にしかならない (AGENTS.md 思考原則 3)。
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
 *
 * viewport 幅が除外幅の 2 倍以下 (viewportWidth() が 0 を返す非ブラウザ実行を含む) では
 * 左右の除外帯が画面全体を覆うため、必ず "none" = **移動しない側へ倒れる**。
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
