## 再レビュー結果

Round 3 の修正方針は概ね妥当です。特に、sniff MIMEによる容量判定、終端ログの一元化、queuedジョブの定義、vendor媒体型のsubclass禁止は前進しています。

ただし、PDFのOCRフォールバックが媒体検証中に失敗した場合のroute誤記録、OCR専用smokeが実行されない帰属検証、施策10本文の未更新が残っています。

| # | 施策 | 判定 |
|---:|---|---|
| 1 | 画像 MIME の受理 | REQUEST_CHANGES |
| 2 | 抽出失敗理由の enum 化 | APPROVE |
| 3 | 媒体 DTO とバリデータ | APPROVE |
| 4 | `loadWithMedia()` | REQUEST_CHANGES |
| 5 | OCR prompt factory + YAML | APPROVE |
| 6 | パイプライン分岐・観測 | REQUEST_CHANGES |
| 7 | OCR 成功条件 | APPROVE |
| 8 | 静的 gate 拡張 | REQUEST_CHANGES |
| 9 | token budget | REQUEST_CHANGES |
| 10 | UI 文言・案内 | REQUEST_CHANGES |
| 11 | rollout・運用 | APPROVE |

---

## 施策 1: 画像 MIME の受理

**判定: REQUEST_CHANGES**

[Warning] `UploadedFile::getSize()` は取得失敗を表現し得ます。提案コードのまま比較すると、取得不能を上限内として扱う可能性があり、PHPStan level 10でも型上の問題になり得ます。

修正案:

```php
$size = $value->getSize();
if (! is_int($size)) {
    $fail('ファイルサイズを確認できません。');

    return;
}

if ($size > $limit) {
    $fail('ファイルが大きすぎます。');
}
```

`getMimeType()` も一度だけ取得し、失敗時はfail-closedにしてください。

[Warning] 容量分類を `AcceptedSourceDocumentTypes::mimes()` に依存させる必要はありません。これは機能フラグで変化する許可集合だからです。ファイルの性質と現在の許可状態は別概念です。

修正案:

```php
$mime = $value->getMimeType();
$isImage = in_array($mime, ['image/jpeg', 'image/png'], true);
```

許可判定はFormRequestの `mimes`、容量分類は実バイトMIMEという責務分離にします。

[Warning] 波及変更に `imageSourceDocumentsEnabled: bool` がありません。施策本文では追加済みのため、TypeScript型、Inertia Props、Featureテストの変更対象へ追記してください。

画像1枚制約をService側不変条件として確定した点は妥当です。

---

## 施策 2: 抽出失敗理由の enum 化

**判定: APPROVE**

reasonの責務、PDFフォールバック対象、到達しない「手順0件」の説明はいずれも整合しています。

---

## 施策 3: 媒体 DTO とバリデータ

**判定: APPROVE**

MIME一致、容量、辺長、画素数、ページ数をvendor変換前に検証する構造は妥当です。

施策9aの調査結果でprovider hard limitが確定した後、設定値とコメントを更新することが実装開始条件です。

---

## 施策 4: `loadWithMedia()`

**判定: REQUEST_CHANGES**

[Critical] `dev:pipeline-smoke` に変更不要という説明では、新しい `sop-extract-media` の帰属到達を検証できません。

既存smokeがテキスト抽出経路だけを通るなら、新しいmedia promptは実行されず、`prompt_template=sop-extract-media` の `llm_call_logs` 行も生成されません。既存3段と同じ仕組みであることと、新しい4本目が実際に通ることは別の検証です。

修正案:

- `dev:pipeline-smoke` にOCR画像またはスキャンPDFのシナリオを追加する。
- 既に任意fixture・経路を指定できるなら、OCR用fixtureと具体的な実行手順を設計へ記載する。
- 次を完全一致で検証する。
  - `prompt_template = sop-extract-media`
  - `organization_id`
  - `subject_type`
  - `subject_id`
  - 必要ならactorキー
- OCR用smokeを実行しない既存シナリオを根拠にしない。

reflection契約テストと実課金smokeの役割分担自体は妥当です。

---

## 施策 5: OCR prompt factory + YAML

**判定: APPROVE**

窓口経路、帰属inventory、防御指示、schema完全一致検査は承認可能です。

---

## 施策 6: パイプライン分岐・観測

**判定: REQUEST_CHANGES**

[Critical] PDFフォールバックの媒体検証で失敗すると、終端ログのrouteが誤って `text` になります。

現在の初期値は次のとおりです。

```php
$route = ($isImage && $ocrEnabled) ? 'ocr' : 'text';
```

PDFは初期値 `text` です。その後、テキスト抽出失敗から `validatePdfForOcr()` へ進み、そこで `MediaUnreadable` や `MediaTooLarge` が投げられると、`resolveExtractInput()` は戻らないため `$route` が `ocr` に更新されません。catchは `route=text` を記録します。

修正案:

- route選択結果と入力解決を型でまとめる。
- またはPDF OCRへ遷移した時点を呼び出し側へ伝えられる構造にする。

例えば、経路選択と検証を分離します。

```php
$route = $this->resolveExtractRoute(...); // text|ocr
$input = $this->resolveInputForRoute($route, ...);
```

これならOCR媒体検証が失敗してもrouteは既に確定しています。参照引数や例外reasonからの事後推測は避ける方が安全です。

必須テスト:

- 画像媒体検証失敗 → `route=ocr`
- PDF OCRフォールバック後の媒体検証失敗 → `route=ocr`
- PDF OCR対象外失敗 → `route=text`
- フラグfalseのPDF失敗 → `route=text`

[Warning] `observabilityCategoryFor()` は `userMessageFor()` と分類材料を共有すると説明していますが、generic `PrismException` のHTTP statusを見ていません。

現状ではHTTP 408/500/502/503/504が `unknown` になり、利用者向け分類は `timed_out` / `provider_busy` になるためdriftします。

修正案:

- `extractHttpStatus()` を呼び、既存定数で分類する。
- `UntrustedInputRejectedException` も `too_large` / `unreadable_encoding` へ写像する。
- `JobOwnershipLostException` は通常の失敗ジョブではないため、`outcome=failed` と数えるかを明示する。推奨は `outcome=aborted` または集計対象外です。

---

## 施策 7: OCR 成功条件

**判定: APPROVE**

SJIS復元ロジックの保持、marker-only拒否、既知の捏造限界の期待値明示はいずれも整合しています。

---

## 施策 8: 静的 gate 拡張

**判定: REQUEST_CHANGES**

[Warning] ルールが5件に増えていますが、テスト計画は「4ルールそれぞれで母集団が空でない」のままです。

修正案:

- 5ルールへ訂正する。
- `VendorMediaTypeSubclassDeclaration` は本番コードの期待件数が0なので、「母集団非空」と「違反0件」を混同しない。
- scanner自己検査ではsubclassの合成候補が非空であることを確認する。
- 本gateでは検出違反がexactly 0件であることを確認する。

[Warning] 新しいsubclassルールの負例がテスト計画に明示されていません。

修正案:

- `extends Image`
- `extends Document`
- `extends Media`
- alias import
- group use
- 無名class
- 同じ短名の別namespaceクラスを誤検出しない正例

を追加してください。

動的static callをfail-closedにする判断は、このアプリで正当利用がないという前提のもとでは許容できます。ただし、既存 `app/` 母集団が本当に0件または全件分類済みであることを、実装PRのexact-fit検査で確認してください。

---

## 施策 9: token budget

**判定: REQUEST_CHANGES**

設計自身が明記しているとおり、9aの一次情報調査が未完了なので承認対象ではありません。

調査完了後に必要な反映先は少なくとも以下です。

- `source_document_image_max_bytes`
- PDF raw/request上限
- `analysis_ocr_max_pages`
- `analysis_ocr_max_pixels`
- `analysis_ocr_max_dimension`
- token estimate定数
- 対象model・参照日・導出式
- `sop-extract-media.yaml` のmax tokensとの整合

9aを独立タスクにした判断は妥当です。

---

## 施策 10: UI 文言・案内

**判定: REQUEST_CHANGES**

[Critical] 対応マトリクスでは修正済みとされていますが、詳細設計本文はRound 2以前の内容のままです。

現在も以下が残っています。

- 対象コンポーネントを「実装時に特定する」
- TypeScript型変更なし
- `sourceDocumentAccept` / `imageSourceDocumentsEnabled` のprop伝播記載なし
- 具体的なcomponent test未特定

修正案:

- 対象を `resources/js/components/features/manual/SourceDocumentUpload.svelte` と確定する。
- 親pageのProps型に以下を追加する。

```ts
sourceDocumentAccept: string;
imageSourceDocumentsEnabled: boolean;
```

- pageからcomponentへのprop伝播を記載する。
- componentは `accept={sourceDocumentAccept}` を使用する。
- 画像案内・1枚制約・法務文言は `imageSourceDocumentsEnabled` がtrueのときだけ表示する。
- false時に画像文言が存在しないこともテストする。
- 既存FormField経由で `document` エラーを表示することを固定する。
- 施策10の「実装時に特定する」節を削除する。

[Warning] 法務文言の「画像・PDFは写真や紙面がそのまま送られる」は、テキスト層を正常抽出できるPDFには当てはまりません。

修正案:

> 画像およびスキャンPDFなどOCR対象のファイルは、AI解析のためファイル内容が外部LLM providerへ送信されます。

など、実際の分岐と一致する文言を法務確認対象にしてください。

---

## 施策 11: rollout・運用

**判定: APPROVE**

queued/runningの扱いは一意になりました。synthetic確認、config cache、kill switchの意味も明確です。

[Suggestion] synthetic確認後の「チケットの後始末」は、既存ledgerを削除・巻き戻す意味に読めないようにしてください。専用組織への通常のgrant、または検証費用として計上する形が安全です。既存の課金履歴を破壊的に修正しないことを運用手順に明記してください。

また、通常のデプロイ時に要求されるroute cache再生成は既存runbookへの参照として残し、この施策専用の新機構は作らない判断で問題ありません。

---

## 全体判定

**CHANGES_REQUESTED**

最終承認に必要な残件は、次の4点です。

1. PDF OCRフォールバックの媒体検証失敗を `route=ocr` と記録できる構造へ直す
2. OCR専用の `dev:pipeline-smoke` シナリオを明示する
3. 施策10本文を、既に特定済みのcomponentと2つのInertia Propsへ実際に更新する
4. 施策9aの一次情報調査を完了し、上限値・導出式を確定する

加えて、`SourceDocumentSizeLimit` のsize/MIME取得失敗をfail-closedにし、subclass gateの負例を補えば、実装へ移行できる設計になります。