<script lang="ts">
    /**
     * SOP アップロードの外部送信案内。**文言の唯一の出現箇所** (法務確認を経た文面。
     * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
     * component 1 つへ集約している)。
     *
     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 OCR 有効化フラグ (config/manual.php) は
     * オーナー決定により撤去済み) なので、OCR 固有警告も常時表示する。props は持たない。
     *
     * **wrapper 要素を作らない**: 呼び出し側の flex 列 (gap) が案内の各段落へ直接効く
     * 前提で描画順・間隔が決まっているため、fragment として 2 要素を返す。
     */
</script>

<p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
    アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
</p>
<p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
    画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
    不要な個人情報や機密情報が写っていないか特に確認してください。
    画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
</p>
