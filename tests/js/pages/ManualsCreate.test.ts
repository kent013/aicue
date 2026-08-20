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
    // 受理形式・画像対応の出し分けはサーバの AcceptedSourceDocumentTypes 由来の props に従う
    // (フロント側で accept 文字列を解析して画像対応可否を判定しない)
    sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt",
    imageSourceDocumentsEnabled: false,
    sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式",
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

    it("手順書 (SOP) のファイル入力は accept をサーバ props からそのまま受ける (フラグ false 相当)", () => {
        render(Create, { props: baseProps });

        const input = screen.getByTestId("manual-document-input");
        expect(input).toBeInTheDocument();
        expect(input.getAttribute("type")).toBe("file");
        expect(input.getAttribute("accept")).toBe(".pdf,.xlsx,.xls,.txt");

        // 一般的な外部送信案内はフラグの真偽に関わらず常時表示
        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent(
            "外部の LLM provider",
        );
        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
    });

    it("フラグ true 相当の props では accept に画像拡張子を含み OCR 固有警告が出る", () => {
        render(Create, {
            props: {
                ...baseProps,
                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
                imageSourceDocumentsEnabled: true,
                sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式、または JPEG・PNG の画像",
            },
        });

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
            "PDF・Excel・テキスト形式。アップロードすると AI 解析でシナリオを生成できます。",
        );
    });

    /*
     * 「ファイルを選ぶ前に外部送信の事実が見えている」配置と、作成 form の flex 列 (gap) が
     * 案内へ直接効く親子構造を固定する (詳細画面側と同じ判定方法)。
     */
    it("案内は file input より前にあり、作成 form の直下に置かれる", () => {
        const { container } = render(Create, {
            props: { ...baseProps, imageSourceDocumentsEnabled: true },
        });

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
