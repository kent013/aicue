import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Show from "@/pages/Manuals/Show.svelte";
import type { VideoManualStatus } from "@/types/manual";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    manual: {
        id: 5,
        title: "ネジ締め作業",
        status: "draft" as VideoManualStatus,
        category: { id: 2, name: "仕上げ" },
        created_at: "2026-07-10 12:00",
    },
    analysis: { job: null, hasDocument: false },
    render: { job: null, previewJob: null, playbackJobId: null },
    canManage: true,
};

describe("Manuals/Show", () => {
    it("タイトル・状態バッジ・カテゴリ・作成日時を描画する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("manual-title")).toHaveTextContent("ネジ締め作業");
        expect(screen.getByTestId("manual-status")).toHaveTextContent("下書き");
        expect(screen.getByTestId("manual-category")).toHaveTextContent("仕上げ");
        expect(screen.getByText("2026-07-10 12:00")).toBeInTheDocument();
    });

    it("未分類 (category=null) は「未分類」を表示する", () => {
        render(Show, {
            props: { ...baseProps, manual: { ...baseProps.manual, category: null } },
        });

        expect(screen.getByTestId("manual-category")).toHaveTextContent("未分類");
    });

    it("canManage=true なら編集・削除導線を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("edit-manual-button").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/5\/edit$/,
        );
        expect(screen.getByTestId("delete-manual-button")).toBeInTheDocument();
    });

    it("canManage=false なら編集・削除導線を表示しない", () => {
        render(Show, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByTestId("edit-manual-button")).toBeNull();
        expect(screen.queryByTestId("delete-manual-button")).toBeNull();
    });

    it("canManage=true (draft) は AI 解析ボタンと手順書アップロード導線を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
        expect(screen.getByTestId("source-document-upload")).toBeInTheDocument();
    });

    it("canManage=false は解析ボタン・アップロード導線を表示しない", () => {
        render(Show, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByTestId("analyze-button")).toBeNull();
        expect(screen.queryByTestId("source-document-upload")).toBeNull();
    });

    it("analyzing 中は進捗を表示し、アップロード導線は出さない (draft/ready のみ)", () => {
        render(Show, {
            props: {
                ...baseProps,
                manual: { ...baseProps.manual, status: "analyzing" as VideoManualStatus },
                analysis: {
                    job: {
                        id: 9,
                        status: "running" as const,
                        step: "extract" as const,
                        progress: 10,
                        error: null,
                        manual_status: "analyzing" as VideoManualStatus,
                    },
                    hasDocument: true,
                },
            },
        });

        expect(screen.queryByTestId("source-document-upload")).toBeNull();
        expect(screen.getByTestId("analysis-progress")).toBeInTheDocument();
    });
});
