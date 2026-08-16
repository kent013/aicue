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
    /**
     * 取消。**利用者由来の取消だけ**を通知する (Esc / pointercancel / 位置が変わらない drop)。
     * `destroy()` (コンポーネント破棄) では**呼ばれない** — 破棄は利用者の意思ではないので、
     * ここに告知や通信を足したときに unmount で誤発火しないようにするためである。
     */
    readonly onCancel?: () => void;
}

export interface PointerDragController {
    /**
     * ハンドルの pointerdown から呼ぶ。
     * **開始を受理したら true**、既に別のポインタが進行中などで無視したら false を返す。
     * 呼び出し側は「受理されたときだけ」ドラッグに紐づく状態 (対象スコープ等) を確定すること。
     * 戻り値を無視して先に状態を書き換えると、2 本目の指が 1 本目のドラッグの対象を
     * すり替えてしまう (design-review R2 Critical)。
     */
    readonly start: (index: number, event: PointerEvent) => boolean;
    /** コンポーネント破棄時に必ず呼ぶ (受け入れ条件 A2) */
    readonly destroy: () => void;
}

/**
 * **`isDragging()` のような「今ドラッグ中か」を外へ出す API は置かない**。
 * 閾値を超えるまで false を返すため、閾値未満の待機中に別のドラッグを受理してしまう
 * (排他の判定に使うと穴になる)。排他は `start()` の戻り値 = **受理した瞬間**を基準にする
 * (design-review R3)。呼び出し側が複数の controller を持つ場合も同じ基準で 1 つに絞る。
 */
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
    /** 直近のポインタ Y (viewport 座標)。自動スクロール中の再計算に使う */
    let lastClientY = 0;

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

    /** 挿入位置が実際に変わったときだけ通知する (毎フレームの無駄な再描画を避ける) */
    function setInsertion(next: number): void {
        if (insertion === next) return;
        insertion = next;
        callbacks.onState({ activeIndex: fromIndex, insertionIndex: next });
    }

    /**
     * 自動スクロールの 1 フレーム。
     * **スクロールしたら挿入位置を必ず採り直す**。指を止めたまま端でスクロールさせると
     * `pointermove` は来ないのに行だけが動くため、採り直さないと古い挿入位置のまま
     * drop できてしまう (iOS Safari で最も起きやすい。design-review R1 の指摘)。
     */
    function tickAutoScroll(): void {
        scrollFrame = null;
        if (pointerId === null || scrollDelta === 0) return;
        window.scrollBy(0, scrollDelta);
        setInsertion(insertionIndexFromRects(bounds(), lastClientY));
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
     *
     * @param commit true なら位置が変わっていれば onCommit する
     * @param notify false なら onCancel を呼ばない (destroy 専用。
     *        破棄は利用者の取消ではないため、告知や通信を伴う onCancel を発火させない)
     */
    function finish(commit: boolean, notify: boolean): void {
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
            if (notify) callbacks.onCancel?.();
            return;
        }
        const to = toFinalIndex(target, from);
        if (to === from) {
            if (notify) callbacks.onCancel?.();
            return;
        }
        callbacks.onCommit(from, to);
    }

    function onPointerMove(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        lastClientY = event.clientY;
        if (!activated) {
            if (Math.abs(event.clientY - startY) < DRAG_ACTIVATION_DISTANCE) return;
            activated = true;
        }
        // ハンドルの touch-action:none と併せて、スクロール/テキスト選択との競合を断つ
        event.preventDefault();
        if (insertion === null) {
            // 掴んだ直後の 1 回目は必ず通知する (activeIndex を UI へ伝えるため)
            insertion = insertionIndexFromRects(bounds(), event.clientY);
            callbacks.onState({ activeIndex: fromIndex, insertionIndex: insertion });
        } else {
            setInsertion(insertionIndexFromRects(bounds(), event.clientY));
        }
        updateAutoScroll(event.clientY);
    }

    function onPointerUp(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(true, true);
    }

    function onPointerCancel(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(false, true);
    }

    function onKeyDown(event: KeyboardEvent): void {
        if (pointerId !== null && event.key === "Escape") finish(false, true);
    }

    // listener は生成時に 1 度だけ張り destroy で外す (start/finish のたびに張り替えない)。
    // capture が使えない環境でも window で拾えるよう、ハンドルではなく window に張る。
    window.addEventListener("pointermove", onPointerMove, { passive: false });
    window.addEventListener("pointerup", onPointerUp);
    window.addEventListener("pointercancel", onPointerCancel);
    window.addEventListener("keydown", onKeyDown);

    return {
        start(index: number, event: PointerEvent): boolean {
            if (pointerId !== null) return false; // 2 本目の指は無視 (多点ドラッグは提供しない)
            if (event.pointerType === "mouse" && event.button !== 0) return false; // 左ボタンのみ
            const target = event.currentTarget;
            handle = target instanceof HTMLElement ? target : null;
            pointerId = event.pointerId;
            fromIndex = index;
            startY = event.clientY;
            lastClientY = event.clientY;
            activated = false;
            insertion = null;
            // pointer capture が無い環境 (jsdom / 一部の古い WebKit) でも
            // window の listener で同じ callback 契約のまま完走する (受け入れ条件 A2)
            if (handle !== null && typeof handle.setPointerCapture === "function") {
                handle.setPointerCapture(event.pointerId);
            }
            return true;
        },
        destroy(): void {
            // 進行中のドラッグを畳むが onCancel は呼ばない (破棄は利用者の取消ではない)
            finish(false, false);
            window.removeEventListener("pointermove", onPointerMove);
            window.removeEventListener("pointerup", onPointerUp);
            window.removeEventListener("pointercancel", onPointerCancel);
            window.removeEventListener("keydown", onKeyDown);
        },
    };
}
