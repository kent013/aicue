import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import QrCodeImage from "@/components/atoms/QrCodeImage.svelte";

/*
 * QrCodeImage atom — サーバ生成の SVG 文字列を data URI の <img> として描く部品。
 *
 * 検査は **性質** で書く。実装と同じ式 (encodeURIComponent) による完全一致テストは
 * トートロジーなので書かない。固定したいのは符号化方式ではなく
 * 「data URI として往復すること」と「HTML として解釈されないこと」である。
 */

const DATA_URI_PREFIX = "data:image/svg+xml,";

/** data URI の payload 部を取り出して復号する (往復検査の共通手順)。 */
function decodedPayload(src: string): string {
    expect(src.startsWith(DATA_URI_PREFIX), `data URI の接頭辞が違う: ${src}`).toBe(true);

    return decodeURIComponent(src.slice(DATA_URI_PREFIX.length));
}

/** 対象 <img> を testId で引く (存在しなければ失敗する)。 */
function renderQr(svg: string): HTMLImageElement {
    render(QrCodeImage, { props: { svg, alt: "QR コード", testId: "qr" } });
    const element = screen.getByTestId("qr");
    expect(element.tagName).toBe("IMG");

    return element as HTMLImageElement;
}

describe("QrCodeImage", () => {
    it("src が data:image/svg+xml, で始まる <img> を描く", () => {
        const image = renderQr("<svg><rect /></svg>");

        expect(image.getAttribute("src")).toMatch(/^data:image\/svg\+xml,/);
    });

    it("src の payload は入力 SVG へ往復して戻る", () => {
        const svg = "<svg><rect width='10' height='10' /></svg>";
        const image = renderQr(svg);

        expect(decodedPayload(image.getAttribute("src") ?? "")).toBe(svg);
    });

    it("URI を壊しやすい文字 (# / % / 非 ASCII / &) を含んでも往復する", () => {
        // # は fragment 開始、% は escape 開始、非 ASCII は btoa() が壊す文字種。
        const svg = '<svg><text fill="#f00">100% 完了 &amp; 保存</text></svg>';
        const image = renderQr(svg);

        expect(decodedPayload(image.getAttribute("src") ?? "")).toBe(svg);
    });

    it("SVG 文字列は HTML として解釈されない (本部品の存在理由)", () => {
        // サーバ応答が汚れていた場合を模す。HTML として差し込まれていれば
        // <svg> / <script> が DOM に生える = 本部品の前提が崩れている。
        const { container } = render(QrCodeImage, {
            props: {
                svg: '<svg><script>window.pwned = true;</script></svg>',
                alt: "QR コード",
                testId: "qr",
            },
        });

        expect(container.querySelector("svg")).toBeNull();
        expect(container.querySelector("script")).toBeNull();
        expect(container.querySelectorAll("img")).toHaveLength(1);
    });

    it("alt と testId が渡る (アクセシブルネームの正本は alt)", () => {
        render(QrCodeImage, {
            props: { svg: "<svg></svg>", alt: "2 要素認証の設定用 QR コード", testId: "qr" },
        });

        const image = screen.getByAltText("2 要素認証の設定用 QR コード");
        expect(image).toBe(screen.getByTestId("qr"));
        expect(screen.getByRole("img", { name: "2 要素認証の設定用 QR コード" })).toBe(image);
    });
});
