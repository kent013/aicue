import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import SourceDocumentUploadNotice from "@/components/features/manual/SourceDocumentUploadNotice.svelte";
import { normalizedTextOf } from "../../../support/normalizeText";

/*
 * SOP アップロードの外部送信案内 (文言の唯一の出現箇所。作成画面と詳細画面が共有する):
 * - 一般案内はフラグの真偽に関わらず常時表示 (テキスト・Excel・通常 PDF にも等しく当てはまる事実)
 * - OCR 固有警告だけを imageSourceDocumentsEnabled で出し分ける
 * - 文言は **全文一致** で固定する (部分一致では文面の劣化を見逃す)
 */

const SEND_NOTICE =
    "アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。";

const IMAGE_NOTICE =
    "画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。" +
    " 不要な個人情報や機密情報が写っていないか特に確認してください。" +
    " 画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。";

afterEach(() => {
    cleanup();
});

describe("SourceDocumentUploadNotice", () => {
    it("imageSourceDocumentsEnabled=false では一般案内だけを全文どおり描画する", () => {
        render(SourceDocumentUploadNotice, { props: { imageSourceDocumentsEnabled: false } });

        expect(normalizedTextOf(screen.getByTestId("source-document-send-notice"))).toBe(
            SEND_NOTICE,
        );
        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
    });

    it("imageSourceDocumentsEnabled=true では OCR 固有警告も全文どおり描画する", () => {
        render(SourceDocumentUploadNotice, { props: { imageSourceDocumentsEnabled: true } });

        expect(normalizedTextOf(screen.getByTestId("source-document-send-notice"))).toBe(
            SEND_NOTICE,
        );
        expect(normalizedTextOf(screen.getByTestId("source-document-image-notice"))).toBe(
            IMAGE_NOTICE,
        );
    });
});
