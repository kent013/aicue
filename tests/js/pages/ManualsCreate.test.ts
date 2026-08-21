import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { reactiveUseForm } from "../support/reactiveUseForm.svelte";
import { normalizedTextOf } from "../support/normalizeText";

const { formState } = vi.hoisted(() => ({ formState: { current: null as unknown } }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    useForm: () => formState.current,
    page: { props: {}, url: "/" },
}));

import Create from "@/pages/Manuals/Create.svelte";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
    // 受理形式はサーバの AcceptedSourceDocumentTypes 由来の props に従う
    // (フロント側で accept 文字列を解析して画像対応可否を判定しない)
    sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
    sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式、または JPEG・PNG の画像",
};

/** FormField が描画する help 段落を入力要素から引く (FormField の id 規約 `{id}-help`)。 */
function helpTextOf(input: HTMLElement): Element | null {
    return document.getElementById(`${input.id}-help`);
}

/** 反応的フェイクフォームを毎テスト用意する (errors は $state で clearErrors 再描画を観測可能) */
function setupForm(errors: Record<string, string> = {}): void {
    formState.current = reactiveUseForm(
        { title: "", category: "", document: null as File | null },
        errors,
    );
}

describe("Manuals/Create", () => {
    beforeEach(() => setupForm());

    it("タイトル入力とカテゴリ選択 (未分類既定) を描画する", () => {
        render(Create, { props: baseProps });

        expect(screen.getByRole("heading", { name: "動画マニュアルの作成" })).toBeInTheDocument();
        expect(screen.getByLabelText(/タイトル/)).toBeInTheDocument();

        const select = screen.getByTestId("manual-category-select");
        expect(select).toBeInTheDocument();
        expect(select).toHaveValue("");
        expect(screen.getByRole("option", { name: "未分類" })).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "準備作業" })).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "仕上げ" })).toBeInTheDocument();
    });

    it("送信ボタンは必須未充足でも disabled にしない (押下時にエラー表示)", () => {
        render(Create, { props: baseProps });

        const submit = screen.getByTestId("manual-submit");
        expect(submit).toBeInTheDocument();
        expect(submit).not.toBeDisabled();
    });

    it("カテゴリ 0 件でも未分類のみで描画できる", () => {
        render(Create, { props: { ...baseProps, categories: [] } });

        expect(screen.getByTestId("manual-category-select")).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "未分類" })).toBeInTheDocument();
        expect(screen.queryByRole("option", { name: "準備作業" })).toBeNull();
    });

    it("手順書 (SOP) のファイル入力は accept をサーバ props からそのまま受ける (画像拡張子を含む)", () => {
        render(Create, { props: baseProps });

        expect(screen.getByTestId("manual-document-input").getAttribute("accept")).toBe(
            ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
        );
        expect(screen.getByTestId("source-document-image-notice")).toHaveTextContent(
            "1 手順書につき 1 枚",
        );
        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent(
            "外部の LLM provider",
        );
        // help はラベル props を前半に据える (後半の文は現行のまま)
        expect(normalizedTextOf(helpTextOf(screen.getByTestId("manual-document-input")))).toContain(
            "JPEG・PNG の画像",
        );
    });

    /*
     * help の全文一致 pin。ラベルの部分一致だけでは後半の文
     * 「アップロードすると AI 解析でシナリオを生成できます。」と句点の維持を固定できない。
     */
    it("help は受理形式ラベル props + 現行の後半文で構成される (全文一致)", () => {
        render(Create, { props: baseProps });

        expect(normalizedTextOf(helpTextOf(screen.getByTestId("manual-document-input")))).toBe(
            "PDF・Excel・テキスト形式、または JPEG・PNG の画像。アップロードすると AI 解析でシナリオを生成できます。",
        );
    });

    /*
     * 「ファイルを選ぶ前に外部送信の事実が見えている」配置と、作成 form の flex 列 (gap) が
     * 案内へ直接効く親子構造を固定する (詳細画面側と同じ判定方法)。
     */
    it("案内は file input より前にあり、作成 form の直下に置かれる", () => {
        const { container } = render(Create, { props: baseProps });

        const form = container.querySelector("form");
        const sendNotice = screen.getByTestId("source-document-send-notice");
        const imageNotice = screen.getByTestId("source-document-image-notice");
        const input = screen.getByTestId("manual-document-input");

        expect(form).not.toBeNull();
        expect(sendNotice.parentElement).toBe(form);
        expect(imageNotice.parentElement).toBe(form);
        expect(
            sendNotice.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(
            imageNotice.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it("タイトル入力 (oninput) でタイトルエラーがその場でクリアされる", async () => {
        setupForm({ title: "タイトルを入力してください" });
        render(Create, { props: baseProps });

        // 初期はエラー文言が表示されている
        expect(screen.getByText("タイトルを入力してください")).toBeInTheDocument();

        const title = screen.getByLabelText(/タイトル/);
        await fireEvent.input(title, { target: { value: "ネ" } });

        // clearErrors("title") が呼ばれ、$state 反応で文言が消える
        expect(
            (formState.current as { clearErrors: ReturnType<typeof vi.fn> }).clearErrors,
        ).toHaveBeenCalledWith("title");
        expect(screen.queryByText("タイトルを入力してください")).toBeNull();
    });

    it("ファイル選択後に選択したファイル名が表示される", async () => {
        render(Create, { props: baseProps });

        expect(screen.queryByTestId("manual-document-selected-name")).toBeNull();

        const input = screen.getByTestId("manual-document-input");
        const file = new File(["x"], "手順書.pdf", { type: "application/pdf" });
        await fireEvent.change(input, { target: { files: [file] } });

        const name = screen.getByTestId("manual-document-selected-name");
        expect(name).toHaveTextContent("選択したファイル: 手順書.pdf");
    });

    it("未選択時はファイル名表示が出ない", () => {
        render(Create, { props: baseProps });

        expect(screen.queryByTestId("manual-document-selected-name")).toBeNull();
    });

    it("別ファイルを再選択すると表示名が置き換わる", async () => {
        render(Create, { props: baseProps });

        const input = screen.getByTestId("manual-document-input");
        await fireEvent.change(input, {
            target: { files: [new File(["a"], "first.pdf", { type: "application/pdf" })] },
        });
        expect(screen.getByTestId("manual-document-selected-name")).toHaveTextContent(
            "選択したファイル: first.pdf",
        );

        await fireEvent.change(input, {
            target: { files: [new File(["b"], "second.pdf", { type: "application/pdf" })] },
        });
        expect(screen.getByTestId("manual-document-selected-name")).toHaveTextContent(
            "選択したファイル: second.pdf",
        );
    });

    it("選択を解除 (files 空) すると表示が消える", async () => {
        render(Create, { props: baseProps });

        const input = screen.getByTestId("manual-document-input");
        await fireEvent.change(input, {
            target: { files: [new File(["a"], "first.pdf", { type: "application/pdf" })] },
        });
        expect(screen.getByTestId("manual-document-selected-name")).toBeInTheDocument();

        await fireEvent.change(input, { target: { files: [] } });
        expect(screen.queryByTestId("manual-document-selected-name")).toBeNull();
    });

    it("タイトルエラーが無いとき oninput は clearErrors を呼ばない", async () => {
        setupForm();
        render(Create, { props: baseProps });

        const title = screen.getByLabelText(/タイトル/);
        await fireEvent.input(title, { target: { value: "ネジ" } });

        expect(
            (formState.current as { clearErrors: ReturnType<typeof vi.fn> }).clearErrors,
        ).not.toHaveBeenCalled();
    });
});
