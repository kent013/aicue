import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import ManualMetaSummary from "@/components/features/capture/ManualMetaSummary.svelte";

/*
 * 撮影 PWA シナリオ詳細のメタ情報 (doc/05 §5.2: TIME 合計 / カテゴリ・日付・作成者)。
 *
 * 合計時間は「いま尺が確定している分」の合計であって完成動画の見込み尺ではない。
 * 全件未確定のときは「確定分・」を前置しない (確定分が実在するかのように読めるため)。
 */

describe("ManualMetaSummary", () => {
    it("全件確定なら合計時間を出し但し書きは出ない", () => {
        render(ManualMetaSummary, {
            props: {
                categoryName: "組立作業",
                creatorName: "山田太郎",
                updatedAt: "2026-07-01T00:00:00Z",
                totalDurationMs: 200_000, // 3:20
                undeterminedCutCount: 0,
            },
        });

        expect(screen.getByTestId("capture-manual-duration").textContent).toContain("合計時間 3:20");
        expect(screen.getByTestId("capture-manual-duration").textContent).not.toContain("確定分");
        expect(screen.getByTestId("capture-manual-duration").textContent).not.toContain("未確定");
    });

    it("一部未確定なら「確定分・未確定 N カット」を併記する", () => {
        render(ManualMetaSummary, {
            props: {
                categoryName: "組立作業",
                creatorName: "山田太郎",
                updatedAt: "2026-07-01T00:00:00Z",
                totalDurationMs: 200_000,
                undeterminedCutCount: 2,
            },
        });

        expect(screen.getByTestId("capture-manual-duration").textContent).toContain("合計時間 3:20");
        expect(screen.getByTestId("capture-manual-duration").textContent).toContain("確定分・未確定 2 カット");
    });

    it("全件未確定なら「—（未確定 N カット）」で「確定分・」は前置しない", () => {
        render(ManualMetaSummary, {
            props: {
                categoryName: "組立作業",
                creatorName: "山田太郎",
                updatedAt: "2026-07-01T00:00:00Z",
                totalDurationMs: null,
                undeterminedCutCount: 5,
            },
        });

        const text = screen.getByTestId("capture-manual-duration").textContent ?? "";
        expect(text).toContain("合計時間 —");
        expect(text).toContain("未確定 5 カット");
        expect(text).not.toContain("確定分");
    });

    it("カット 0 件 (null/0) なら「合計時間 —」で但し書きは出ない", () => {
        render(ManualMetaSummary, {
            props: {
                categoryName: "組立作業",
                creatorName: "山田太郎",
                updatedAt: "2026-07-01T00:00:00Z",
                totalDurationMs: null,
                undeterminedCutCount: 0,
            },
        });

        const text = screen.getByTestId("capture-manual-duration").textContent ?? "";
        expect(text).toContain("合計時間 —");
        expect(text).not.toContain("未確定");
        expect(text).not.toContain("確定分");
    });

    it("categoryName が null なら「未分類」、creatorName が null なら「不明」", () => {
        render(ManualMetaSummary, {
            props: {
                categoryName: null,
                creatorName: null,
                updatedAt: "2026-07-01T00:00:00Z",
                totalDurationMs: null,
                undeterminedCutCount: 0,
            },
        });

        const text = screen.getByTestId("capture-manual-meta-line").textContent ?? "";
        expect(text).toContain("未分類");
        expect(text).toContain("不明");
    });

    it("updatedAt が null なら formatDate の fallback (-) を出す", () => {
        render(ManualMetaSummary, {
            props: {
                categoryName: "組立作業",
                creatorName: "山田太郎",
                updatedAt: null,
                totalDurationMs: null,
                undeterminedCutCount: 0,
            },
        });

        const text = screen.getByTestId("capture-manual-meta-line").textContent ?? "";
        expect(text).toContain("更新 -");
    });
});
