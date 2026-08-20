import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import SourceDocumentUpload from "@/components/features/manual/SourceDocumentUpload.svelte";

/*
 * SOP アップロード (画像・スキャン SOP の OCR 対応。施策 1/10):
 * - accept 属性はサーバ Props (sourceDocumentAccept) をそのまま使う (フロントで解析しない)
 * - 送信案内 (外部 LLM provider への送信) は imageSourceDocumentsEnabled の真偽に関わらず常時表示
 * - OCR 固有の警告・1 枚制約の明示は imageSourceDocumentsEnabled=true のときだけ表示
 */

vi.mock("@inertiajs/svelte", () => ({
    useForm: () => ({ document: null, errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
}));

afterEach(() => {
    cleanup();
});

const baseProps = {
    projectId: 1,
    manualId: 5,
    hasDocument: false,
};

describe("SourceDocumentUpload", () => {
    it("imageSourceDocumentsEnabled=false では accept が画像を含まず OCR 固有文言が出ない", () => {
        render(SourceDocumentUpload, {
            props: {
                ...baseProps,
                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt",
                imageSourceDocumentsEnabled: false,
            },
        });

        const input = screen.getByTestId("source-document-input") as HTMLInputElement;
        expect(input.accept).toBe(".pdf,.xlsx,.xls,.txt");
        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
        // 一般的な外部送信案内は false のときも表示され続ける
        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent("外部の LLM provider");
    });

    it("imageSourceDocumentsEnabled=true では accept に画像拡張子を含み OCR 固有文言が出る", () => {
        render(SourceDocumentUpload, {
            props: {
                ...baseProps,
                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
                imageSourceDocumentsEnabled: true,
            },
        });

        const input = screen.getByTestId("source-document-input") as HTMLInputElement;
        expect(input.accept).toBe(".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png");
        expect(screen.getByTestId("source-document-image-notice")).toHaveTextContent("1 手順書につき 1 枚");
        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent("外部の LLM provider");
    });

    /*
     * 案内を共有 component (SourceDocumentUploadNotice) へ切り出した後も、
     * 「ファイルを選ぶ前に外部送信の事実が見えている」配置と、`form` の
     * `flex flex-col gap-3` が案内 2 つに直接効く親子構造が保たれること。
     * 順序だけでは wrapper の追加を検出できず gap の適用単位が変わる後退を見逃すため、
     * 親要素も併せて固定する。
     */
    it("案内は file input より前にあり、form 直下に置かれる (wrapper を挟まない)", () => {
        render(SourceDocumentUpload, {
            props: {
                ...baseProps,
                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
                imageSourceDocumentsEnabled: true,
            },
        });

        const form = screen.getByTestId("source-document-upload");
        const sendNotice = screen.getByTestId("source-document-send-notice");
        const imageNotice = screen.getByTestId("source-document-image-notice");
        const input = screen.getByTestId("source-document-input");

        // DOM 順: 一般案内 → OCR 固有警告 → file input
        const ordered = [...form.querySelectorAll("[data-testid]")].filter((el) =>
            ["source-document-send-notice", "source-document-image-notice", "source-document-input"].includes(
                el.getAttribute("data-testid") ?? "",
            ),
        );
        expect(ordered.map((el) => el.getAttribute("data-testid"))).toEqual([
            "source-document-send-notice",
            "source-document-image-notice",
            "source-document-input",
        ]);
        expect(
            sendNotice.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();

        // 親子構造: 案内 2 つは form の直下 (余計な wrapper が挟まっていない)
        expect(sendNotice.parentElement).toBe(form);
        expect(imageNotice.parentElement).toBe(form);
    });
});
