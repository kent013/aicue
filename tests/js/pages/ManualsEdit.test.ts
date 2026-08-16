import { afterEach, describe, expect, it } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import Edit from "@/pages/Manuals/Edit.svelte";
import type { ScenarioDocument } from "@/types/manual";

const scenario: ScenarioDocument = {
    scenario_version: 0,
    steps: [],
};

afterEach(() => {
    cleanup();
});

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    manual: { id: 5, title: "ネジ締め作業", category: 2, status: "draft" as const },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
    scenario,
    takeSummaries: [],
};

describe("Manuals/Edit", () => {
    it("現在のタイトルとカテゴリを初期値に描画する", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByRole("heading", { name: "動画マニュアルの編集" })).toBeInTheDocument();
        expect(screen.getByLabelText(/タイトル/)).toHaveValue("ネジ締め作業");
        expect(screen.getByTestId("manual-category-select")).toHaveValue("2");
    });

    it("未分類 (category=null) の manual は未分類が選択される", () => {
        render(Edit, {
            props: { ...baseProps, manual: { ...baseProps.manual, category: null } },
        });

        expect(screen.getByTestId("manual-category-select")).toHaveValue("");
    });

    it("2 つの保存系統が分離して描画される (基本情報を保存 / シナリオを更新)", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByRole("heading", { name: "基本情報" })).toBeInTheDocument();
        expect(screen.getByRole("heading", { name: "シナリオ" })).toBeInTheDocument();
        expect(screen.getByTestId("manual-submit")).toHaveTextContent("基本情報を保存");
        expect(screen.getByTestId("scenario-submit")).toHaveTextContent("シナリオを更新");
    });

    it("空シナリオでは EmptyState (最初の手順を追加) が表示される", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByTestId("scenario-empty-state")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "最初の手順を追加" })).toBeInTheDocument();
    });

    it("保存ボタンは disabled にしない", () => {
        render(Edit, { props: baseProps });

        expect(screen.getByTestId("manual-submit")).not.toBeDisabled();
        expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
    });

    it("published 状態では撮影ナビへの導線を表示し href が撮影ナビを厳密に指す", () => {
        render(Edit, {
            props: { ...baseProps, manual: { ...baseProps.manual, status: "published" as const } },
        });

        // Inertia Link は jsdom で origin 付き絶対 URL に解決される。
        // path 全体を start/end 固定で照合し prefix / suffix / クエリ変化を検知する。
        expect(screen.getByTestId("capture-manual-link").getAttribute("href")).toMatch(
            /^https?:\/\/[^/]+\/app\/projects\/1\/manuals\/5$/,
        );
    });

    it("draft 状態では撮影導線を表示しない", () => {
        render(Edit, { props: baseProps });

        expect(screen.queryByTestId("capture-manual-link")).toBeNull();
    });
});

/*
 * 動画列 (doc/04)。保存済みカットだけがテイク選択画面へのリンクを持ち、
 * 未保存行 (id=null) はリンクを出さずに保存を促す (押せるのに詰むボタンを作らない)。
 */
describe("Manuals/Edit — 動画列", () => {
    const savedScenario: ScenarioDocument = {
        scenario_version: 3,
        steps: [
            {
                id: 41,
                scene: "工具を準備する",
                shot_type: "hiki",
                shooting_point: null,
                narration: "工具を準備します。",
                subtitle_primary: null,
                subtitle_secondary: "工具を準備する",
                material_type: null,
                static_display_seconds: null,
                points: [],
            },
        ],
    };

    it("保存済み行にテイク選択画面へのリンクと件数が出る", () => {
        render(Edit, {
            props: {
                ...baseProps,
                scenario: savedScenario,
                takeSummaries: [{ cut_id: 41, takes_count: 2, adopted: null }],
            },
        });

        expect(screen.getByTestId("video-cell-count")).toHaveTextContent("テイク 2 件");
        const link = screen.getByTestId("video-cell-link");
        expect(link).toHaveTextContent("テイクを選択");
        expect(link.getAttribute("href")).toMatch(
            /^https?:\/\/[^/]+\/projects\/1\/manuals\/5\/cuts\/41\/takes$/,
        );
    });

    it("テイク 0 件のカットは「ファイルの選択」を出す (導線は消さない)", () => {
        render(Edit, {
            props: {
                ...baseProps,
                scenario: savedScenario,
                takeSummaries: [{ cut_id: 41, takes_count: 0, adopted: null }],
            },
        });

        expect(screen.getByTestId("video-cell-link")).toHaveTextContent("ファイルの選択");
    });

    it("採用済みカットには「採用済み」バッジが出る", () => {
        render(Edit, {
            props: {
                ...baseProps,
                scenario: savedScenario,
                takeSummaries: [
                    { cut_id: 41, takes_count: 2, adopted: { id: 9, status: "ready" as const } },
                ],
            },
        });

        expect(screen.getByTestId("video-cell-material")).toHaveTextContent("動画登録済");
    });

    it("未保存行 (手順を追加した直後) にはリンクが出ず、保存を促す文言が出る", async () => {
        render(Edit, { props: { ...baseProps, scenario: { scenario_version: 0, steps: [] } } });

        await fireEvent.click(screen.getByRole("button", { name: "最初の手順を追加" }));

        expect(await screen.findByTestId("video-cell-unsaved")).toHaveTextContent(
            "「シナリオを更新」で保存すると",
        );
        expect(screen.queryByTestId("video-cell-link")).toBeNull();
    });
});
