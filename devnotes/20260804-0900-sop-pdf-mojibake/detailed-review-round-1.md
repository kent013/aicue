**レビュー要約**
- 仮説: 根本原因は `pdfparser` の CP932→CP1252 誤解釈で、現行ガードが「UTF-8妥当性」と「byte長」しか見ないため素通りする点。
- 成功条件: ①誤解釈を安全に復元、②復元不能時は LLM 入力前で遮断、③既存正常系を壊さない、④Pest/PHPStan運用と整合。

**施策別判定**

- **施策1: SJIS 誤解釈テキストの区間単位復元 — APPROVE**
  - [Suggestion] `decodeRunAsSjis()` 内で毎回 `config()->float(...)` を読むより、`extract()` 冒頭で閾値をローカル変数化して渡すと意図が明確になり、将来の設定変更時の追跡もしやすい。

- **施策2: 日本語本文ゲート + 閾値 config — APPROVE**
  - [Suggestion] `analysis_min_japanese_ratio` は運用で最も議論になりやすい値なので、`docs/architecture.md` 側にも「変更は TODO 起票 + 実測再提出必須」を1行明記すると運用ぶれを防げる。

- **施策3: 例外文言体系是正 — REQUEST_CHANGES**
  - [Warning] `bytes === 0` を一律 `unextractable()` にすると、`text/plain` の空ファイルや空Spreadsheetまで「画像・スキャン未対応」文言になり、原因案内が不正確。
  - 修正案: `kind` で分岐し、少なくとも `plain/spreadsheet` の 0 byte は `tooShort()` へ寄せる、または `emptyExtractedText()` を新設して媒体非依存の文言にする。

- **施策4: 観測ログ — APPROVE**
  - [Suggestion] ログキーに `manual_stage`（例: `extractor_repair` / `extractor_gate`）を追加すると、後段の分析ジョブログと横断集計しやすくなる。

- **施策5: テスト — REQUEST_CHANGES**
  - [Warning] 施策3の分岐修正を担保するテストが不足。0 byte の `plain` / `spreadsheet` の期待例外が固定されていない。
  - 修正案: `SopTextExtractorTest` に  
    1) 空 `text/plain` の期待例外、  
    2) 空Spreadsheet（タイトル/セルなし）の期待例外  
    を追加し、文言体系の回帰を防ぐ。

- **施策6: ドキュメント追記 — APPROVE**
  - [Suggestion] `analysis_min_japanese_ratio` の評価対象（空白除外・正規化後）を明記すると実装との1:1対応がより明確。

**観点10/11**
- 非該当（UI/frontend 変更なし）という整理で妥当。

**全体判定**
- **CHANGES_REQUESTED**（主因: 施策3の0 byte文言分類と、それを固定する施策5のテスト不足）  

必要なら、この2点を反映した「最小差分の修正方針（コード断片レベル）」まで具体化します。