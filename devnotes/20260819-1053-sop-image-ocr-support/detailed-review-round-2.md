## 再レビュー結果

Round 1 の主要指摘には概ね適切に対応されています。特に、媒体 Prompt の自己初期化、YAML 自身の provider/model pin、ASCII 判読不能マーカー、機能フラグ導入は妥当です。

一方、共有比率ロジックの移動による具体的なコード破綻、失敗経路を取得できない観測設計、フラグ無効時の FormRequest/UI 不整合が残っています。

| # | 施策 | 判定 |
|---:|---|---|
| 1 | 画像 MIME の受理 | REQUEST_CHANGES |
| 2 | 抽出失敗理由の enum 化 | APPROVE |
| 3 | 媒体 DTO とバリデータ | REQUEST_CHANGES |
| 4 | `loadWithMedia()` | REQUEST_CHANGES |
| 5 | OCR prompt factory + YAML | APPROVE |
| 6 | パイプライン分岐 | REQUEST_CHANGES |
| 7 | OCR 成功条件 | REQUEST_CHANGES |
| 8 | 静的 gate 拡張 | REQUEST_CHANGES |
| 9 | token budget 検査 | REQUEST_CHANGES |
| 10 | UI 文言・案内 | REQUEST_CHANGES |
| 11 | 観測・rollout gate | REQUEST_CHANGES |

---

## 施策 1: 画像 MIME の受理

**判定: REQUEST_CHANGES**

[Warning] 機能フラグでゲートすると明記されているのは `SourceDocumentService::allowedMimeTypes()` だけです。一方、`source_document_mimes`、2つの FormRequest、フロントの `accept` と案内文言は画像対応状態になる設計です。

フラグ無効時に「UIではJPEG対応と表示され、FormRequestも通るがServiceで拒否される」という不整合が残ります。

修正案:

- 画像を含む許可拡張子・MIME集合を一つの設定/サービスから導出する。
- FormRequest、`SourceDocumentService`、UI Props の全てが同じ有効状態を見る。
- フラグ false 時は画像案内と `accept` からもJPEG/PNGを除外する。
- false/true それぞれについて、Request・Service・UI表示の集合が一致する Architecture/Feature テストを追加する。

[Warning] 「画像1手順書1枚」が「ドメイン不変条件であるなら」と未確定のままです。一方、施策10では確定した制約として利用者に表示します。

修正案:

- 不変条件として採用するかを確定する。
- 採用するなら `SourceDocumentService` の具体的な判定位置、親relation、例外キーを詳細設計に明記する。
- `storeForManual()` の行ロック内で、同時2要求の一方だけが成功するFeatureテストを追加する。

---

## 施策 2: 抽出失敗理由の enum 化

**判定: APPROVE**

Round 1 対応は妥当です。全caseとnamed constructorの完全一致検査も適切です。

[Suggestion] PHPStan欄の「defaultなしで全caseを尽くす」はコードと一致しません。`isOcrEligibleForPdf()` は `default => false` を持つため、説明を修正してください。

また、波及変更総括では `AnalysisFailureReason` をDTOに数えていますが、これはenumです。新規DTOは2つ、enumは1つと分けて記載するのが正確です。

---

## 施策 3: 媒体 DTO とバリデータ

**判定: REQUEST_CHANGES**

[Warning] persisted MIME と実バイトの形式一致を再確認していません。例えば `mime=image/jpeg` のレコードがPNGバイトを指している場合、`getimagesizefromstring()` は成功し、JPEG MIME付きのPNGバイトがDTOへ入ります。

修正案:

- `getimagesizefromstring()` の返すMIMEまたは別の内容sniff結果を取得する。
- 実バイトのMIMEが persisted MIME と一致することを検証する。
- JPEGレコード＋PNGバイト、PNGレコード＋JPEGバイトを負例にする。
- PDFも少なくともparser結果が1ページ以上であることを要求する。

[Warning] `$width * $height` は乗算後に上限比較しています。異常なヘッダー値に対して整数範囲を前提にしない方が安全です。

修正案:

- 先にwidth/heightが正の整数でdimension上限内であることを確認する。
- その後、`$height > intdiv($maxPixels, $width)` のように乗算を避けて画素数を判定する。
- 0次元・極端なdimensionのfixtureを追加する。

[Warning] PDFの0ページが許可されます。

修正案:

```php
if ($pageCount < 1) {
    throw AnalysisFailedException::mediaUnreadable();
}
```

ページ数0、上限ちょうど、上限超過を分けて固定してください。

---

## 施策 4: `PromptDefense::loadWithMedia()`

**判定: REQUEST_CHANGES**

自己初期化方式への変更により、Round 1 の致命的な状態欠落は解消されています。

[Warning] PHPStan level 10 に必要な配列型が不足しています。

該当箇所:

- `loadWithMedia(..., array $untrusted, ...)`
- 無名クラスの `__construct(string $templatePath, array $variables, ...)`
- `buildConversationMessages(): array`

修正案:

- `@param array<string,string> $untrusted`
- `@param array<string,UserInput|string> $variables`
- vendor実シグネチャに対応する `@return list<...>` または正確なarray shape

を付け、親メソッドとの共変性もPHPStanで固定してください。

[Warning] `Prompt::load()` の内部初期化を複製する以上、契約テストはreflection上の状態だけでは不十分です。帰属は最終的な `llm_call_logs` まで届いて初めて不変条件を満たします。

修正案:

- fake実行後に `llm_call_logs` の organization/subject/actor metadata を検証するFeatureテストを追加する。
- system prompt、media block、provider/model/optionsは最終request相当で検証する。
- `sanitizeUntrusted()` への共通化で、既存 `load()` と `loadUnattributed()` の無害化・canary・ログ動作が変わらない回帰テストを追加する。

[Suggestion] 「vendor媒体型の呼び出し箇所が1件」は再び曖昧です。許可ファイル1件、呼び出し2件と統一してください。

---

## 施策 5: OCR prompt factory + YAML

**判定: APPROVE**

untrusted変数と帰属inventoryの区別、ASCIIマーカー、schema一致検査の方針は妥当です。

[Suggestion] schemaの「正規化」が何を意味するかをテスト設計で固定してください。単なる空白除去では、説明文とJSON例の境界変更を見逃す可能性があります。既存schema部分を抽出する明示的なマーカーか、期待文字列の完全一致が安全です。

---

## 施策 6: パイプライン分岐

**判定: REQUEST_CHANGES**

型絞り込みの修正は妥当です。

[Critical] 観測ログは `withBoundedRetry()` が成功した後にしか出ません。次の失敗は記録されません。

- `validateImage()` / `validatePdfForOcr()` の失敗
- OCR応答のschema違反による最終失敗
- `OcrEmptyOrInvalid`
- provider timeout・busy・request too large

したがって、このログと `analysis_jobs` の終端状態を突合してもOCR失敗率を算出できません。失敗ジョブにはrouteログが存在しないためです。

修正案:

- `resolveExtractInput()` が媒体入力を返した時点で、route選択イベントを記録する。
- `run()` のcatchで `AnalysisFailedException` の `reason` とrouteを固定キーで記録する。
- routeを例外時にも参照できる局所状態または型付き実行コンテキストで持つ。
- 少なくとも以下を検証する。
  - media検証前失敗
  - OCR応答品質失敗
  - provider失敗
  - OCR成功
  - text成功
- 本文、ファイルパス、応答本文を含めないexact-keyテストにする。

[Warning] `$input instanceof ImageAnalysisMediaData ? $input->width * $input->height` もvalidatorと同じ乗算問題を再導入します。DTOに検証済み `pixelCount` を持たせるか、安全に計算した値を保持してください。

---

## 施策 7: OCR 経路の成功条件

**判定: REQUEST_CHANGES**

[Critical] `SopTextExtractor` から `countBy()` を削除すると、既存の `decodeRunAsSjis()` が壊れます。

現行コードは次を使用しています。

```php
$this->countBy(self::MULTIBYTE_JAPANESE_PATTERN, $decoded)
$this->countBy(self::MULTIBYTE_JAPANESE_PATTERN, $run)
```

詳細設計では `JAPANESE_PATTERN`、`NON_SPACE_PATTERN`、`japaneseRatio()`、`countBy()` を全て削除するとしているため、未定義メソッド呼び出しになります。

修正案:

- 文書比率用の `JAPANESE_PATTERN` / `NON_SPACE_PATTERN` / `japaneseRatio()` だけを `JapaneseTextRatio` へ移す。
- SJIS復元専用の `MULTIBYTE_JAPANESE_PATTERN` と `countBy()` は `SopTextExtractor` に残す。
- または共有カウンタを別クラスへ移し、SJIS復元側も明示的にそれを利用する。
- SJIS修復の全既存fixtureを回帰テストに残す。

[Warning] `[UNREADABLE]` をASCII化しても、「短い日本語1文＋大量の判読不能」が0.10を超えれば成功します。`OcrEmptyOrInvalid` という名前に対し、実装は比率しか見ていません。

修正案:

- marker除去後の実日本語文字数または読取可能なwork_process件数に最低条件を置く。
- 値を今決められない場合でも、少なくとも「全stepがmarker」「marker以外の本文がない」は構造的に拒否する。
- 手動評価で閾値を決めるなら、フラグ有効化前の評価項目へ明記する。

---

## 施策 8: 静的 gate の拡張

**判定: REQUEST_CHANGES**

[Warning] 「あらゆる構築手段」という検出力主張が、記載された構文より広すぎます。少なくとも次の形は説明されていません。

```php
$class = Image::class;
$class::fromRawContent(...);

(Image::class)::fromRawContent(...);
```

また、`Image` / `Document` が継承可能なら、subclass経由の生成も検討が必要です。

修正案:

- 動的class-string、括弧付きclass-string、subclassを検出する。
- またはdocblockとgate名の主張を「直接のnewと、静的に解決可能なImage/Document受信者のstatic call」に狭める。
- 保証外構文で実際に未検証媒体を構築できるなら、単にdocblockへ書くだけでは不十分です。未解決としてgateを失敗させる必要があります。
- vendorの `Image` / `Document` がfinalかどうかもpin済み版で確認し、負例に反映する。

[Warning] 「母集団が空でない」は4ルール全てで同じ意味にはなりません。許可呼び出しが本実装にまだ存在しないテストファースト段階と、scanner入力候補が空であることを区別してください。

修正案:

- scanner自己検査では合成入力の候補数非空を固定する。
- 本gateでは実装後の期待件数をexact-fitで固定する。
- 違反0件と候補0件を明確に分離する。

---

## 施策 9: token budget 検査

**判定: REQUEST_CHANGES**

[Warning] 「実際の上限はprovider側の拒否が担う」という表現は安全境界として不正確です。providerへ送信してから拒否される仕組みは、アプリ側の入力上限保証ではありません。

修正案:

- テスト名を「OCR容量見積りと設定値の整合」に変更する。
- `InvariantTest` 内に置く場合も、hard limitではないことをテストdocblockで明示する。
- provider公式のraw/request bytes、pages、dimension制限があるものは `AnalysisMediaValidator` で送信前に強制する。
- provider拒否は最後の砦としてのみ記述する。

[Warning] 上限値と計算定数が依然として仮値です。詳細設計の完了条件として、一次情報、参照日、対象model、導出式を確定する必要があります。「実装着手前に決める」状態のまま実装完了にはできません。

---

## 施策 10: UI 文言・案内

**判定: REQUEST_CHANGES**

[Warning] Round 1 と同様、対象コンポーネントがまだ未特定です。「実装時に詳細設計へ追記」は、現在の詳細設計レビューでは未解決です。

修正案:

- 実装着手前ではなく、設計承認前に対象page/feature/componentと既存テストを特定する。
- Atomic Designのimport方向と、利用するFormField/既存upload componentを明記する。
- フラグfalse時はJPEG/PNG案内、外部LLMへの画像送信文言、accept追加を表示しないこともテストする。

Inertia Propsを変更しない場合、フロントが機能フラグ状態をどう知るかも未定です。サーバー設定を共有Propsへ追加するなら、TypeScript型とInertia Propsの波及変更が発生します。ビルド時に常時表示するならフラグ設計と矛盾します。

---

## 施策 11: 観測・rollout gate

**判定: REQUEST_CHANGES**

[Critical] 「2か所を同時にゲートする」だけでは不十分です。FormRequestとUIが同じフラグを見ないため、公開面が一致しません。施策1・10と合わせて単一の能力判定へ統合してください。

[Warning] `.env` を変更しただけでは、config cacheを使うproductionで反映されない可能性があります。

修正案:

- rollout手順にconfig cache再生成・プロセス再起動・実効値確認を含める。
- 有効化後に画像の許可集合とPDF OCR routeを確認するread-only smoke checkを記載する。
- 無効化時に既にqueued/runningの画像ジョブをどう扱うかも定義する。

[Warning] 「失敗率はanalysis_jobsとの突合で得られる」という回答は、現設計では成立しません。`analysis_jobs` に構造化reason/routeがなく、routeログも成功後だけだからです。

修正案:

- 施策6のログ位置を修正する。
- 失敗reasonを固定キーで記録する。
- それでもログとDBを突合するなら、相関キー、期間、重複配送時の数え方を運用文書へ明記する。

---

## 全体判定

**CHANGES_REQUESTED**

Round 1 の中心問題だった媒体Promptの状態欠落は、今回の自己初期化方式と契約テスト計画によって解消されています。設計は大きく前進しています。

承認前に必須なのは次の4点です。

1. `SopTextExtractor::countBy()` 削除によるSJIS修復経路の破綻を修正する
2. OCR失敗時にもroute/reasonを取得できる観測設計へ変更する
3. 機能フラグをServiceだけでなくFormRequest・UIまで一貫して適用する
4. 静的gateの「あらゆる構築手段」という主張と実際の検出範囲を一致させる

これらを解消すれば、残る論点は上限値の一次情報確定とUIの具体的な配置特定が中心になります。