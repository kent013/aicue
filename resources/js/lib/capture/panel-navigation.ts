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
