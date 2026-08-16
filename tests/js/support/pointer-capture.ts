/**
 * pointer capture が実装されていない環境 (古い WebKit 等) を模して run を実行する。
 *
 * `Element.prototype.setPointerCapture = undefined` は
 * `(pointerId: number) => void` 型への undefined 代入になり型エラーになるため、
 * `Object.defineProperty` で差し替えて finally で必ず戻す
 * (delete も生の代入も使わない。design-review R1 の指摘)。
 */
export async function withoutPointerCapture(run: () => void | Promise<void>): Promise<void> {
    const original = Object.getOwnPropertyDescriptor(Element.prototype, "setPointerCapture");
    Object.defineProperty(Element.prototype, "setPointerCapture", {
        value: undefined,
        configurable: true,
        writable: true,
    });
    try {
        await run();
    } finally {
        if (original === undefined) {
            Reflect.deleteProperty(Element.prototype, "setPointerCapture");
        } else {
            Object.defineProperty(Element.prototype, "setPointerCapture", original);
        }
    }
}
