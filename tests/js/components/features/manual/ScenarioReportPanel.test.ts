import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import ScenarioReportPanel from "@/components/features/manual/ScenarioReportPanel.svelte";
import type { ScenarioReportProps } from "@/types/manual";

/*
 * 生成結果の確認パネル (T200):
 * - 所見 (LLM・解析時点) と 検査 (現在の cuts) を分けて出す
 * - 鮮度が落ちた所見には注記を添える (隠さない)
 * - 指摘 0 件でも「指摘なし」を明示する / 位置は「手順 N」「急所 N-M」
 * - 判定でボタンを disabled にしない (編集導線は canManage のみ)
 */

const baseReport: ScenarioReportProps = {
    verdict: null,
    counts: { steps: 2, points: 3, total: 5 },
    findings: [],
};

const baseProps = {
    projectId: 1,
    manualId: 5,
    report: baseReport,
    canManage: true,
};

afterEach(() => {
    cleanup();
});

describe("ScenarioReportPanel", () => {
    it("カット構成と「指摘なし」を描画する", () => {
        render(ScenarioReportPanel, { props: baseProps });

        expect(screen.getByTestId("scenario-counts")).toHaveTextContent("手順 2");
        expect(screen.getByTestId("scenario-counts")).toHaveTextContent("急所 3");
        expect(screen.getByTestId("scenario-counts")).toHaveTextContent("合計 5");
        expect(screen.getByTestId("scenario-findings-empty")).toHaveTextContent(
            "シナリオの書式に関する指摘はありません。",
        );
        expect(screen.queryByTestId("scenario-verdict")).toBeNull();
    });

    it.each([
        ["valid" as const, "マニュアルとして有効", "text-success"],
        ["needs_review" as const, "確認が必要な箇所があります", "text-warning"],
        ["invalid" as const, "このままでは元資料として不十分", "text-danger"],
    ])("verdict=%s のラベルと tone を出す", (verdict, label, toneClass) => {
        render(ScenarioReportPanel, {
            props: {
                ...baseProps,
                report: {
                    ...baseReport,
                    verdict: {
                        verdict,
                        reason: "判定の理由です。",
                        works: ["バルブ閉止作業"],
                        work_count: 1,
                        split_recommended: false,
                        is_current_document: true,
                    },
                },
            },
        });

        expect(screen.getByTestId("scenario-verdict")).toHaveTextContent(label);
        // tone は Badge atom の TONE_CLASSES 経由で class に現れる (表示語彙 helper の回帰固定)
        expect(screen.getByTestId("scenario-verdict")).toHaveClass(toneClass);
        expect(screen.getByTestId("scenario-verdict-reason")).toHaveTextContent("判定の理由です。");
        expect(screen.getByTestId("scenario-work-count")).toHaveTextContent("1");
        expect(screen.getByTestId("scenario-works")).toHaveTextContent("バルブ閉止作業");
        expect(screen.queryByTestId("scenario-verdict-stale")).toBeNull();
        expect(screen.queryByTestId("scenario-split-recommended")).toBeNull();
    });

    it("is_current_document=false では所見を隠さず注記を添える", () => {
        render(ScenarioReportPanel, {
            props: {
                ...baseProps,
                report: {
                    ...baseReport,
                    verdict: {
                        verdict: "needs_review",
                        reason: "確認すべき箇所があります。",
                        works: ["バルブ閉止作業"],
                        work_count: 1,
                        split_recommended: false,
                        is_current_document: false,
                    },
                },
            },
        });

        expect(screen.getByTestId("scenario-verdict")).toBeInTheDocument();
        expect(screen.getByTestId("scenario-verdict-stale")).toHaveTextContent(
            "解析時の手順書に対するもの",
        );
    });

    it("split_recommended=true で分割の案内を出す", () => {
        render(ScenarioReportPanel, {
            props: {
                ...baseProps,
                report: {
                    ...baseReport,
                    verdict: {
                        verdict: "valid",
                        reason: "2 つの作業が含まれています。",
                        works: ["バルブ閉止作業", "点検作業"],
                        work_count: 2,
                        split_recommended: true,
                        is_current_document: true,
                    },
                },
            },
        });

        expect(screen.getByTestId("scenario-split-recommended")).toHaveTextContent("複製");
    });

    it("指摘の件数と位置 (手順 N / 急所 N-M / ほか) を描画する", () => {
        render(ScenarioReportPanel, {
            props: {
                ...baseProps,
                report: {
                    ...baseReport,
                    findings: [
                        {
                            code: "narration_missing",
                            count: 2,
                            positions: [
                                { step: 2, point: null },
                                { step: 2, point: 3 },
                            ],
                        },
                        {
                            code: "subtitle_secondary_missing",
                            count: 7,
                            positions: [
                                { step: 1, point: null },
                                { step: 1, point: 1 },
                            ],
                        },
                    ],
                },
            },
        });

        const findings = screen.getByTestId("scenario-findings");
        expect(findings).toHaveTextContent("ナレーションが空のカット: 2 件");
        expect(findings).toHaveTextContent("手順 2 / 急所 2-3");
        // count が positions より多いときだけ「ほか」を添える
        expect(findings).toHaveTextContent("手順 1 / 急所 1-1 ほか");
        expect(screen.queryByTestId("scenario-findings-empty")).toBeNull();
    });

    it("canManage=false では編集導線を出さない (表示は止めない)", () => {
        render(ScenarioReportPanel, { props: { ...baseProps, canManage: false } });

        expect(screen.getByTestId("scenario-report")).toBeInTheDocument();
        expect(screen.queryByTestId("scenario-report-edit-link")).toBeNull();
    });

    it("canManage=true では編集導線を出す", () => {
        render(ScenarioReportPanel, { props: baseProps });

        // Inertia の Link は絶対 URL へ解決されるため末尾一致で見る
        expect(screen.getByTestId("scenario-report-edit-link").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/5\/edit$/,
        );
    });
});
