import { describe, expect, it, vi } from "vitest";
import type { ResolvedComponent } from "@inertiajs/svelte";
import { EAGER_PAGES, LAZY_PAGES, resolvePageFrom } from "@/inertia";

/*
 * Inertia の page resolver の配備契約。
 *
 * Error ページは「サーバが 4xx/5xx を返している瞬間」に必要になるため、追加 chunk 取得に
 * 出ると取得失敗 → resolvePage が throw → SPA が無反応 (今日のモーダル表示より悪化) になる。
 * よって Error だけ eager glob で初期 bundle に同梱し、他は従来どおり遅延解決する。
 *
 * ★**保証範囲の限界 (誇張しない)**: 本 gate は「resolver が Error のとき遅延 loader を
 *   呼ばない」ところまでしか保証しない。`pnpm build` 生成物の chunk 分割は検査しない
 *   (vitest を build 生成物に従属させると build 未実行の環境で恒常的に赤くなるため)。
 */

const fakeComponent = {} as ResolvedComponent;

describe("inertia resolver: Error ページの eager 同梱", () => {
    it("eager 解決の対象は Error ページちょうど 1 件", () => {
        // glob が広がって全ページ eager 化する退行も、Error が外れる退行も両方検出する。
        expect(Object.keys(EAGER_PAGES)).toEqual(["./pages/Error.svelte"]);
    });

    it("遅延 map に Error ページが含まれない", () => {
        expect(Object.keys(LAZY_PAGES)).not.toContain("./pages/Error.svelte");
    });

    it("遅延 map には Error 以外の実在ページが載っている", () => {
        // 除外パターンが広すぎて全部消える退行の検出。
        expect(Object.keys(LAZY_PAGES)).toContain("./pages/Dashboard.svelte");
    });

    it("Error は遅延 loader を 1 度も呼ばずに解決される", async () => {
        const loader = vi.fn(async () => fakeComponent);
        const lazy = { "./pages/Error.svelte": loader };

        const resolved = await resolvePageFrom("Error", EAGER_PAGES, lazy);

        expect(loader).not.toHaveBeenCalled();
        expect(resolved).toBe(EAGER_PAGES["./pages/Error.svelte"]);
    });

    it("Error 以外は遅延 loader 経由で解決される", async () => {
        const loader = vi.fn(async () => fakeComponent);
        const lazy = { "./pages/Dashboard.svelte": loader };

        const resolved = await resolvePageFrom("Dashboard", EAGER_PAGES, lazy);

        expect(loader).toHaveBeenCalledTimes(1);
        expect(resolved).toBe(fakeComponent);
    });

    it("未解決ページは throw する (既存契約の維持)", async () => {
        await expect(resolvePageFrom("Totally/Missing", {}, {})).rejects.toThrow(
            "Inertia page not found: Totally/Missing",
        );
    });
});
