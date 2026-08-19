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
});
