// Vitest setup file
import "@testing-library/jest-dom/vitest";
import { afterEach } from "vitest";
import { cleanup } from "@testing-library/svelte";

// jsdom は Web Animations API (Element.prototype.animate) を実装しない。
// Svelte の transition (fly 等) は要素描画時に element.animate を呼ぶため、
// 未実装だと transition を持つコンポーネント (ToastContainer 等) が描画時に throw する。
// 即時 finish する最小スタブを入れる（テストはアニメーション完了を待たない）。
if (typeof Element !== "undefined" && typeof Element.prototype.animate !== "function") {
    Element.prototype.animate = function animate(): Animation {
        const stub = {
            cancel() {},
            finish() {},
            play() {},
            pause() {},
            reverse() {},
            onfinish: null as ((ev: AnimationPlaybackEvent) => void) | null,
            oncancel: null as ((ev: AnimationPlaybackEvent) => void) | null,
            finished: Promise.resolve(),
        };
        // finished Promise を即時 resolve 済みで返し、onfinish があれば次 tick で呼ぶ。
        queueMicrotask(() => stub.onfinish?.(new Event("finish") as AnimationPlaybackEvent));
        return stub as unknown as Animation;
    };
}

// jsdom は ResizeObserver を実装しない。bits-ui の floating layer
// (Tooltip / Popover の Content) はサイズ計測に ResizeObserver を使い、
// 初回 observe のコールバックで content の id 確定・配置を進める。
// 本物同様「observe 直後に一度コールバックを発火する」最小スタブを入れる
// （何も発火しないと floating content が pre-mount のまま id が未確定になる）。
if (typeof globalThis.ResizeObserver === "undefined") {
    class ResizeObserverStub {
        #callback: ResizeObserverCallback;
        constructor(callback: ResizeObserverCallback) {
            this.#callback = callback;
        }
        observe(target: Element): void {
            const rect = target.getBoundingClientRect();
            const box: ReadonlyArray<ResizeObserverSize> = [
                { inlineSize: rect.width, blockSize: rect.height },
            ];
            const entry = {
                target,
                contentRect: rect,
                borderBoxSize: box,
                contentBoxSize: box,
                devicePixelContentBoxSize: box,
            } as ResizeObserverEntry;
            this.#callback([entry], this as unknown as ResizeObserver);
        }
        unobserve(): void {}
        disconnect(): void {}
    }
    globalThis.ResizeObserver =
        ResizeObserverStub as unknown as typeof ResizeObserver;
}

// jsdom は Element.scrollTo / scrollIntoView を実装しない。スクロール制御を行う
// コンポーネントを render すると TypeError になるため noop で polyfill する。
if (typeof Element !== "undefined") {
    if (typeof Element.prototype.scrollTo !== "function") {
        Element.prototype.scrollTo = function () {
            // noop
        } as Element["scrollTo"];
    }
    if (typeof Element.prototype.scrollIntoView !== "function") {
        Element.prototype.scrollIntoView = function () {
            // noop
        } as Element["scrollIntoView"];
    }
}

// テスト間の DOM 汚染を防ぐ明示 cleanup。
// さらに bits-ui の body-scroll-lock は Dialog/Popover/Select の unmount 時に
// `<body>` スタイルを戻す setTimeout(~24ms) を予約する (huntabyte/bits-ui#1639)。
// この timer がテスト環境の破棄後に発火すると `document is not defined` の
// unhandled error になる (テストファイル跨ぎで間欠 fail)。lock 中は `<body>` に
// overflow:hidden が付くため、それを marker に「Dialog 等を開いたテストだけ」
// 環境が生存している間に timer を発火させて排出する (大半のテストは即時 return)。
afterEach(async () => {
    cleanup();
    if (
        typeof document !== "undefined" &&
        document.body.style.overflow === "hidden"
    ) {
        await new Promise((resolve) => setTimeout(resolve, 40));
    }
});
