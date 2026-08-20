<script lang="ts">
    /**
     * SOP アップロードの外部送信案内。**文言の唯一の出現箇所** (法務確認を経た文面。
     * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
     * component 1 つへ集約している)。
     *
     * 一般案内はフラグの真偽に関わらず常時表示する (テキスト・Excel・通常 PDF にも
     * 等しく当てはまる事実のため)。OCR 固有警告だけを imageSourceDocumentsEnabled で
     * 出し分ける (画像・スキャン SOP の OCR 対応の方針)。
     *
     * **wrapper 要素を作らない**: 呼び出し側の flex 列 (gap) が案内の各段落へ直接効く
     * 前提で描画順・間隔が決まっているため、fragment として 2 要素を返す。
     */
    interface Props {
        imageSourceDocumentsEnabled: boolean;
    }

    let { imageSourceDocumentsEnabled }: Props = $props();
</script>

<p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
    アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
</p>
{#if imageSourceDocumentsEnabled}
    <p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
        画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
        不要な個人情報や機密情報が写っていないか特に確認してください。
        画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
    </p>
{/if}
