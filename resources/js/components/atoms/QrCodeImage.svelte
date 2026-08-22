<script lang="ts">
    /**
     * QrCodeImage atom。**サーバが生成した SVG 文字列を data URI の <img> として描く**。
     *
     * 存在理由: raw HTML 挿入構文 (生 HTML を DOM へ差し込む構文) を使わずに
     * サーバ生成の QR を表示するための唯一の手段を配る。
     * 禁止構文は文字列を DOM 木として解釈させるが、本部品は画像リソースとして読ませる。
     * lint 規則 svelte/no-at-html-tags と対で 1 組である
     * (禁止だけを配ると現場は使い続けるため、代わりの手段を同時に配る)。
     *
     * ※ 本ファイルは resources/js 配下の .svelte なので、禁止構文の**字面**は書けない
     *   (tests/js/architecture/svelte-raw-html-gate.test.ts の検査 C が字面で数えるため)。
     *   正本の説明は eslint.config.js のコメントと DESIGN.md にある。
     *
     * **保証範囲 (誇張しない)**: 本部品が保証するのは
     * 「SVG 文字列を DOM へ HTML として挿さないこと」までである。
     * browser が画像文脈の SVG をどう扱うかの細部は本部品の保証範囲ではない。
     *
     * data URI は **percent encoding** で作る (base64 を採らない):
     *   - btoa() は非 ASCII を含む SVG で例外を投げる
     *   - TextEncoder 経由の base64 化は安全性が同じで手数だけ増える
     *   - 素朴な文字列連結は `#` (fragment 開始) で切れ、`%` が不正な escape になり、
     *     非 ASCII で壊れる
     *
     * CSP の img-src が `data:` を含むことに依存する
     * (tests/Feature/Security/SecurityHeadersTest.php が 2 構成で pin している)。
     */

    interface Props {
        /** サーバが生成した SVG 文字列。**null 許容にしない** (呼び出し側が分岐を持つ) */
        svg: string;
        /** 画像の代替テキスト。必須 (アクセシブルネームの正本) */
        alt: string;
        testId?: string;
    }

    let { svg, alt, testId }: Props = $props();

    const src = $derived(`data:image/svg+xml,${encodeURIComponent(svg)}`);
</script>

<img {src} {alt} data-testid={testId} />
