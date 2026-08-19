## 再レビュー結果

Round 2 の主要指摘はかなり解消されています。特に媒体検証、SJIS復元ロジック、Inertia Propsへの波及認識は妥当です。

ただし、観測ログが「検証成功」と「抽出処理の終端」を混同して二重記録する問題と、画像容量上限の判定元、UI節の自己矛盾が残っています。

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
| 9 | token budget 検査 | REQUEST_CHANGES |
| 10 | UI 文言・案内 | REQUEST_CHANGES |
| 11 | rollout gate・運用 | REQUEST_CHANGES |

---

## 施策 1: 画像 MIME の受理

**判定: REQUEST_CHANGES**

[Critical] 画像専用容量上限をどの情報で選択するかが未定義です。

FormRequestがクライアント拡張子やクライアント申告MIMEを見て上限を選ぶと、JPEGを `.pdf` に改名することで20MB上限側へ流せます。解析時validatorでは拒否されますが、設計が要求するアップロード時422にはなりません。

修正案:

- 画像用上限の選択は、Laravelがサーバー側でsniffしたMIMEを正本にする。
- 拡張子や `getClientMimeType()` では判定しない。
- 2つのFormRequestで重複実装せず、共通Ruleまたは共通メソッドに閉じる。
- 次をFeatureテストへ追加する。
  - JPEGバイト＋`.pdf` ファイル名
  - PDFバイト＋`.jpg` ファイル名
  - 偽装JPEGが画像上限を迂回できないこと
  - 画像上限ちょうど／1 byte超過

[Warning] `acceptAttribute()` の説明は「拡張子＋画像MIME」ですが、実装は拡張子だけです。

```php
return implode(',', ['.pdf', '.xlsx', ...]);
```

修正案は、説明を「拡張子一覧」に訂正するか、実際にMIMEも含めてください。拡張子だけでもHTMLの `accept` としては有効です。

[Warning] 画像1枚制約が依然として「ドメイン不変条件であるなら」と未確定です。UIでは確定事項として表示するため矛盾します。

修正案:

- 本施策で採否を確定する。
- 採用するならService側拒否、親relation、ValidationExceptionのフィールド名、並行2要求テストまで実装対象として確定する。

---

## 施策 2: 抽出失敗理由の enum 化

**判定: APPROVE**

Round 2 の訂正を含めて妥当です。

[Suggestion] `OcrEmptyOrInvalid` のコメントにある「手順0件」は、実際には `ExtractedSopData::fromLlmText()` が先に `LlmOutputInvalidException` を投げるため、このreasonには到達しません。「日本語比率不足・判読可能本文なし」へ説明を狭めると正確です。

---

## 施策 3: 媒体 DTO とバリデータ

**判定: APPROVE**

MIME再確認、0次元、除算による画素数判定、0ページ拒否は妥当です。

[Suggestion] テスト計画の「乗算オーバーフローを起こしうる大きなwidth/height」は、dimension上限で先に拒否されるため、画素数境界のテストとは分けてください。

- dimension超過は辺長拒否テスト
- dimension内で `maxPixels` ちょうど／1px超過は画素数テスト

とする方が、どの分岐を裏取りしているか明確です。

---

## 施策 4: `PromptDefense::loadWithMedia()`

**判定: REQUEST_CHANGES**

[Warning] `@return list<Message>` の `Message` がimportされていません。記載されたuse一覧のままでは、PHPDoc上は `App\Support\Llm\Message` と解決される可能性があります。

修正案:

```php
use Prism\Prism\Contracts\Message;
```

またはPHPDocに完全修飾名を書いてください。実際のpin済みvendorにおける正確なinterface名も確定してください。

[Warning] `llm_call_logs` のテスト方針がまだ条件分岐のままです。

> 記録されない場合は smoke に委ねる

では、実装完了時にどのテストが帰属不変条件を証明するのか確定していません。

修正案:

- fakeでもイベント記録されるならFeatureテストを正本にする。
- fakeで必ず短絡するなら、そのFeatureテスト案は削除し、既存Architectureテスト＋`dev:pipeline-smoke` の具体的検証項目を正本にする。
- 実装前にどちらかへ確定する。
- smokeでは organization/subject/actor/template の全キーを検査する。

---

## 施策 5: OCR prompt factory + YAML

**判定: APPROVE**

schema部分の抽出境界が具体化され、fail-closedになっています。帰属inventoryも正しいです。

[Suggestion] `出力スキーマ:` が複数回現れた場合も失敗させてください。先頭または末尾を暗黙選択すると、重複見出しによる抽出範囲のずれを見逃します。

---

## 施策 6: パイプライン分岐・観測

**判定: REQUEST_CHANGES**

[Critical] 現在の観測設計は、一つの抽出試行について「成功」と「失敗」を両方記録します。

例として画像OCRでは:

1. `validateImage()` 成功時に `outcome=ok`
2. LLMが失敗すると `runExtractStep()` で `outcome=failed`

となります。ここで最初の `ok` は「媒体検証成功」であって「抽出成功」ではありません。テスト計画の「ログが1回だけ出る」とも矛盾します。

text経路も同様に、テキスト抽出成功後・LLM失敗時に `ok` と `failed` が残ります。この状態ではジョブ成功率を単純集計できません。

修正案:

- イベントを二種類に明確に分けるか、抽出routeの終端イベントを一回だけ出す。

推奨は次の形です。

```text
media_validation outcome=ok|failed       // 必要なら別イベント
extract_route_terminal outcome=ok|failed // 1 jobのextract段につき1回
```

- media検証失敗時はterminal eventをその場で1回記録する。
- media/text入力の解決成功時はまだterminal成功を記録しない。
- `runExtractStep()` の成功後またはcatchでterminal eventを1回記録する。
- `analysis_job_id + stage + terminal` で重複しない集計規則を決める。
- retryごとのログと段全体のterminalログを別event名にする。

[Warning] `failure_reason` が `AnalysisFailedException` 以外ではnullになり、代わりにPHPクラス名が入ります。運用集計が実装クラス名に依存します。

修正案:

- LLM schema違反、timeout、provider busy、request too large、unsafe responseを固定enum/categoryへ正規化する。
- `exception_class` は内部診断用に残してもよいですが、集計キーには使わない。
- `LlmOutputInvalidException::reason` も固定キーとして記録する。

[Warning] Featureテストの「1回だけ出る」は成功・媒体検証失敗・LLM失敗・retry成功の各ケースについて明示してください。

---

## 施策 7: OCR 経路の成功条件

**判定: APPROVE**

`countBy()` と `MULTIBYTE_JAPANESE_PATTERN` を残す訂正は正しく、SJIS復元の後退を避けられています。marker-onlyの構造的拒否も妥当です。

[Suggestion] 「日本語らしい捏造」のfixtureは、このgateでは検出できないと明記されています。テストの期待値も「通過する既知の限界」と明示してください。拒否を期待するようにも読める現在の表現は曖昧です。

---

## 施策 8: 静的 gate の拡張

**判定: REQUEST_CHANGES**

[Warning] subclass経路を「実装着手時に確認」としており、設計が未確定です。gateの検出対象が変わる論点なので、AGENTS.mdの「同じPRで揃える4点」に直接影響します。

修正案:

- 実装前ではなく設計承認前に、pin済み `Image` / `Document` がfinalか確定する。
- finalでなければ、以下を設計に含める。
  - vendor媒体型を継承するclass宣言の検出
  - alias/group use/FQNの解決
  - 記名classと無名classの負例
  - subclass経由のstatic constructor呼び出し

[Warning] 動的static callを全て違反候補にすると、`$class::method()` の正当な既存利用まで拾う可能性があります。どの走査根・どの候補集合に対してfail-closedにするかが未定です。

修正案:

- 少なくとも `Image::class` / `Document::class` が代入・返却・配列格納されたclass-string由来の動的callを対象にする。
- 全動的static callを対象にするなら、既存母集団とexemption inventoryを先に棚卸しし、exact-fitで固定する。
- 合成負例だけでなく、既存コード上の正例・許容例を用意する。

---

## 施策 9: token budget 検査

**判定: REQUEST_CHANGES**

[Warning] 数値、一次情報、参照日、導出式がまだ未確定です。「未確定なら実装しない」という停止条件は正しいですが、詳細設計自体はまだ完成していません。

修正案:

- 実装開始前の別調査タスクとして切り出す。
- 調査結果を詳細設計へ反映してから承認対象に戻す。
- hard limitとestimateを別定数・別テスト名・別docblockにする。

このレビューでは提供テキストのみを対象としているため、Anthropicの現行仕様値そのものは検証していません。

---

## 施策 10: UI 文言・案内

**判定: REQUEST_CHANGES**

[Critical] 対応マトリクスと施策1では対象を特定済みですが、施策10本文は依然として「未特定」「実装時に特定する」と書かれています。

また、波及変更も次のように矛盾しています。

- 施策1・総括: `sourceDocumentAccept: string` の型追加あり
- 施策10: TypeScript型変更なし

修正案:

- 変更対象を `resources/js/components/features/manual/SourceDocumentUpload.svelte` と確定する。
- 親pageからcomponentまでのprop伝播経路を明記する。
- `sourceDocumentAccept: string` の型変更を施策10にも反映する。
- 使用する既存 `FormField` とcomponentテストの具体的なファイルを記載する。
- 「実装時に特定する」節を削除する。

[Warning] UIが画像対応状態を判断する方法が未確定です。`sourceDocumentAccept` の文字列を解析してJPEG有無を判定するのは脆弱です。

修正案:

- `sourceDocumentAccept: string` に加えて `imageSourceDocumentsEnabled: bool` などの明示Propsを渡す。
- 案内文言はbooleanを見る。
- `accept` 属性だけstringを見る。
- 両Propsは同じ `AcceptedSourceDocumentTypes` から導出する。

---

## 施策 11: rollout gate・運用

**判定: REQUEST_CHANGES**

[Critical] queuedジョブの扱いが矛盾しています。

設計では:

- 「既にqueued/runningのOCRジョブは最後まで走らせる」
- 「既に `resolveExtractInput()` を通過して実行中のジョブは最後まで走らせる」

とありますが、queuedジョブはまだ `resolveExtractInput()` を通過していません。フラグをfalseにしてworkerが後から実行すると、OCRは無効になります。

修正案として、次のどちらかへ明確化してください。

- queuedは無効化後にOCRを行わず失敗、既に媒体入力を解決済みのrunningだけ継続する
- ジョブ起票時にOCR可否をジョブへスナップショットし、queuedも起票時の判断で継続する

後者はDB変更を伴うため、kill switchとしての目的なら前者が自然です。

[Warning] 「画像アップロードとOCRフォールバックを確認するread-only smoke check」はread-onlyではありません。アップロード、DB更新、外部LLM呼び出し、チケット処理を伴います。

修正案:

- 「制御されたsynthetic smoke test」と表現する。
- 専用tenant、テストSOP、費用、生成物の後始末、PIIを含まないfixtureを明記する。
- 外部呼び出しなしのread-only確認と、実外部呼び出しを伴うsynthetic確認を分ける。

[Warning] config cache再生成に加えて、このリポジトリの運用要件どおりデプロイ時の `route:cache` 再生成を壊さないこともrollout手順から参照してください。新たなデプロイ基盤を作る必要はありません。

---

## 全体判定

**CHANGES_REQUESTED**

残る主要な修正は以下です。

1. 観測ログを「検証成功」と「抽出段の終端成功」に分離し、terminal outcomeを一度だけ記録する
2. FormRequestの画像容量判定をサーバーsniff MIME基準で確定する
3. UI節を実際に特定済みのcomponent・Props変更へ更新する
4. queuedジョブのフラグ無効化時挙動を一意に決める
5. 静的gateのsubclass・動的class-stringの保証範囲を確定する
6. provider上限・見積り値の一次情報調査を完了させる

これら以外のRound 2対応は概ね妥当です。