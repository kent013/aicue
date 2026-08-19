## 最終ラウンド再レビュー結果

Round 4 の主要修正は妥当です。PDF OCR失敗時のroute、OCR専用smoke、UI対象の具体化、静的gateの負例は解消されています。

残件は、施策9aの未完了に加え、失敗ログで媒体メタデータが失われる点と、UIの法務文言・波及変更総括の不整合です。

| # | 施策 | 判定 |
|---:|---|---|
| 1 | 画像 MIME の受理 | APPROVE |
| 2 | 抽出失敗理由の enum 化 | APPROVE |
| 3 | 媒体 DTO とバリデータ | APPROVE |
| 4 | `loadWithMedia()` | APPROVE |
| 5 | OCR prompt factory + YAML | APPROVE |
| 6 | パイプライン分岐・観測 | REQUEST_CHANGES |
| 7 | OCR 成功条件 | APPROVE |
| 8 | 静的 gate 拡張 | APPROVE |
| 9 | token budget | REQUEST_CHANGES |
| 10 | UI 文言・案内 | REQUEST_CHANGES |
| 11 | rollout・運用 | APPROVE |

---

## 施策 1: 画像 MIME の受理

**判定: APPROVE**

sniff MIMEによる容量分類、取得失敗時のfail-closed、許可判定との責務分離、Service側の画像1枚制約は妥当です。

[Suggestion] `SourceDocumentSizeLimit` の上限超過文言は「実装時に確定」ではなく、2つのFormRequestで同じ文言になることだけでもテストで固定してください。

---

## 施策 2: 抽出失敗理由の enum 化

**判定: APPROVE**

reason、named constructor、PDFフォールバック適格性の設計に問題ありません。

---

## 施策 3: 媒体 DTO とバリデータ

**判定: APPROVE**

媒体検証の境界は妥当です。ただし実際の設定値確定は施策9a完了後であり、placeholderのまま実装完了にはできません。

---

## 施策 4: `loadWithMedia()`

**判定: APPROVE**

自己初期化、metadata、防御指示、canary、vendorメッセージ契約、OCR専用smokeの役割分担は妥当です。

[Suggestion] `PipelineSmokeCommand` では、OCRフラグをfixtureのアップロードより前に有効化してください。アップロード後ではFormRequestまたはServiceで画像が拒否されます。

また、`--source-kind` の不正値拒否と、各値が期待するextract promptを選ぶことをコマンドテストで固定してください。

---

## 施策 5: OCR prompt factory + YAML

**判定: APPROVE**

窓口、帰属、防御指示、schema一致検査の設計は承認できます。

---

## 施策 6: パイプライン分岐・観測

**判定: REQUEST_CHANGES**

[Warning] LLM呼び出し段階で失敗した場合、検証済み媒体のメタデータがログから失われます。

現在のcatchは常に `$input=null` を渡しています。

```php
} catch (Throwable $exception) {
    $this->logExtractStageTerminal($job, $document, $route, null, $exception);
}
```

そのため、媒体検証後にschema違反、timeout、provider busy、`OcrEmptyOrInvalid` が発生すると、`media_size_bytes`、`media_pages`、`media_pixels` がすべてnullになります。評価期間で失敗率と入力規模の相関を見られません。

修正案:

```php
$input = null;

try {
    $input = $this->resolveExtractInput(...);
    $extracted = $this->runExtractStep(...);

    $this->logExtractStageTerminal($job, $document, $route, $input, null);

    return $extracted;
} catch (Throwable $exception) {
    $this->logExtractStageTerminal($job, $document, $route, $input, $exception);

    throw $exception;
}
```

必須テスト:

- OCR媒体検証前の失敗では媒体メタデータがnull
- 画像LLM失敗ではsize/pixelsが入る
- PDF OCR LLM失敗ではsize/pagesが入る
- text失敗では媒体メタデータがnull

[Suggestion] `JobOwnershipLostException` では意図的に終端ログが0件になるため、「extract段につきちょうど1回」という保証には例外があることをdocblockと集計手順へ明記してください。

---

## 施策 7: OCR 成功条件

**判定: APPROVE**

marker-only拒否、日本語比率、SJIS復元ロジックの保持、捏造検出を保証しない境界が明確です。

[Suggestion] docblockの「ここでは日本語比率だけ」は、marker-only構造検査も実施するため「判読可能本文の存在と日本語比率」に訂正すると正確です。

---

## 施策 8: 静的 gate 拡張

**判定: APPROVE**

5ルール、subclass禁止、動的static callのfail-closed、完全修飾名解決、正負例が揃っています。

実装PRでは、既存 `app/` にある動的static callの母集団を実測し、想定外の候補があれば黙って除外せずexact-fit inventoryで扱ってください。

---

## 施策 9: token budget

**判定: REQUEST_CHANGES**

設計自身が明記しているとおり、施策9aが未完了です。したがって本施策は承認できません。

最終決定記録として、次を明確に残す必要があります。

- 施策1〜8・10〜11の構造設計と、施策9aの調査を別承認単位にする
- 9a完了までは施策3の設定値、施策5の対象model、施策9bの定数を確定扱いしない
- 9a結果を反映した差分は、実装前に人手レビューを必須とする
- placeholder値を含むコードをproductionへ有効化しない

今回のレビューは提供テキストだけを対象としているため、Anthropicの現行仕様値は検証していません。

---

## 施策 10: UI 文言・案内

**判定: REQUEST_CHANGES**

[Warning] 一般的な外部LLM送信案内まで `imageSourceDocumentsEnabled=true` のときだけ表示する設計になっています。

文言の前半:

> アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。

はテキスト、Excel、通常PDFにも該当します。OCRフラグfalse時にこれまで非表示になるのは、文言の意味と条件が一致しません。

修正案:

- 全形式に共通する外部送信案内は常時表示する。
- 次のOCR固有警告だけを `imageSourceDocumentsEnabled` で出し分ける。

> 画像や、文字を読み取れないスキャンPDFでは、紙面の見た目がそのまま送信されます。

既存画面ですでに一般送信案内があるなら、それを正本として重複させず、OCR固有文言だけ追加してください。

[Warning] 波及変更総括が本文と一致していません。末尾では依然として以下しか記載されていません。

- TypeScript: `sourceDocumentAccept` のみ
- Inertia Props: `sourceDocumentAccept` のみ

修正案:

- `imageSourceDocumentsEnabled: boolean` を両方へ追加する。
- 施策一覧のUI変更ファイルも具体的なcomponent/pageへ更新する。
- false時のテストは「OCR固有文言がない」を確認し、一般的な外部送信案内まで消えることを期待しない。

---

## 施策 11: rollout・運用

**判定: APPROVE**

kill switch、queued/runningの扱い、config cache、synthetic確認、課金ledgerを巻き戻さない方針は妥当です。

[Suggestion] syntheticデータの削除は、直接DB操作ではなく既存の通常削除・管理経路を使うことをrunbookに明記してください。監査ログと課金ledgerは削除対象にしない方針で問題ありません。

---

## 全体判定

**CHANGES_REQUESTED**

最大5ラウンドの最終記録として、未承認理由は次の3点に限定されます。

1. 施策9aの一次情報調査が未完了で、provider上限・token見積り値が確定していない
2. OCRのLLM失敗ログで、検証済み媒体メタデータが失われる
3. UIの一般送信案内とOCR固有警告の表示条件、および波及変更総括が不一致

それ以外の施策については、上記Suggestionを実装時に確認する前提で承認可能です。