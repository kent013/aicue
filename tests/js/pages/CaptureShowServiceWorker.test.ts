import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * service worker の登録 scope の前提を pin する (家系裁定 AG-037 / 施策 8)。
 *
 * 組織 URL 配下 (`/organizations/{slug}/app/...`) が PWA の scope に収まる前提は
 * 「登録 script が root にあり、`scope` option を**渡していない**」ことに依存する。
 * **script の配置だけでは登録 scope は決まらない** (`scope` option が優先される) ので、
 * 「option を渡していない」ことまで固定する。
 *
 * ★保証しないもの: ここが見るのはソースの字面だけである。実ブラウザで解決される
 *   `registration.scope` は Browser lane の担当であり、本テストは実効値を主張しない。
 */
describe("撮影 PWA の service worker 登録", () => {
    const source = readFileSync(
        resolve(__dirname, "../../../resources/js/pages/Capture/Show.svelte"),
        "utf8",
    );

    it("script URL は /capture-sw.js である", () => {
        expect(source).toContain('navigator.serviceWorker.register("/capture-sw.js")');
    });

    it("scope option を渡していない (渡すようになったら赤にする)", () => {
        const withOption = /serviceWorker\.register\(\s*"[^"]*"\s*,/;
        expect(withOption.test(source)).toBe(false);
    });
});
