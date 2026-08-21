# 対応マトリクス: conceptual-review Round 1

## [Critical] 手動評価未実施・緊急停止手段の消失をリスクとして明記すべき
- 判断: 対応する
- 根拠: 指摘は妥当。「意図された帰結でリスクではない」という書き方は、項目 2 の
  未実施という事実と、フラグ撤去後は緊急停止の手段が構造的に無くなるという事実の
  両方を弱めて見せる。設計文書としては両方を明示し、その上でオーナーがその帰結を
  承知して撤去を選んだという経緯を分けて書くべき。
- 対応内容: 「リスクと運用上の帰結」節を新設し、(1) 項目 2 未実施は変わらない、
  (2) 緊急停止手段は無くなり復旧はコード修正+再デプロイになる、(3) 項目 3 の再評価義務は
  存続する、(4) 既存の観測・評価計画はフラグ有無と無関係に継続する、(5) PromptDefense/
  GuardedPrompt 経路は変更しない、の 5 点を明記した。

## [Warning] LLM 呼び出し経路 (PromptDefense/GuardedPrompt) を迂回しないことの明示
- 判断: 対応する
- 根拠: 禁止事項 5 との整合を設計文書に明示すべきという指摘はそのまま採用できる。
- 対応内容: 「リスクと運用上の帰結」5. に明記。詳細設計でも変更対象外であることを
  再確認する。

## [Warning] 「無条件有効化はリスクではない」という断定の修正・観測値の明記
- 判断: 対応する
- 根拠: 指摘のとおり断定は不正確。既存の `docs/rollout-checklists.md` 「観測・課金の評価」
  節がまさにこの観測計画であり、フラグ撤去後もそのまま使える。
- 対応内容: 期待効果の文言を「リスクではない」から「リスクの記録は別節に分ける」へ修正し、
  「リスクと運用上の帰結」4. で既存の観測計画がフラグ撤去後も継続することを明記した。

## [Warning] 外部送信注意文言の対象確認 (画像だけでなく PDF OCR フォールバックも含むか)
- 判断: 対応する (確認の結果、既存文面で問題なし)
- 根拠: 実際のコンポーネント文面を確認したところ、「画像や、文字を読み取れないスキャン PDF
  では、紙面の見た目がそのまま送信されます」と既に PDF を含む記述になっている。
- 対応内容: 「波及変更・検証の受入条件」に確認結果を明記し、文面変更は不要と結論づけた。

## [Warning] 削除完了の保証 (grep 全数列挙は弱い) への受入条件追加
- 判断: 対応する
- 根拠: 指摘のとおり、設計時の grep は網羅の出発点であって完了の証明ではない。
- 対応内容: 「波及変更・検証の受入条件」に、3 識別子 (`ocr_analysis_enabled` /
  `MANUAL_OCR_ANALYSIS_ENABLED` / `imageSourceDocumentsEnabled`) の残存ゼロを実装完了時の
  確認条件として明記した。恒久的な Architecture テスト新設は思考原則 2 (今必要なものだけ
  作る) に反すると判断し、実装 PR の完了条件としての確認に留めた (反論の要素を含む対応)。

## [Warning] Inertia props 削除に伴う波及箇所の網羅
- 判断: 対応する
- 根拠: 指摘のとおり、props を配る側・受ける側・テスト fixture の全てを列挙しないと
  実装で漏れが生じる。
- 対応内容: 「波及変更・検証の受入条件」に、Controller (2 箇所)・Svelte 4 ファイル・
  JS テスト 3 ファイルを具体的なパスで列挙した。

---

## 更新後の概念設計 (全文)

# 概念設計: OCR 機能フラグ (`manual.ocr_analysis_enabled`) の完全撤去 (常時有効化)

## 背景・課題

`manual.ocr_analysis_enabled` (env: `MANUAL_OCR_ANALYSIS_ENABLED`, 既定 `false`) は、
画像・スキャン SOP の OCR 対応 (T234) をロールアウト用の機能フラグ付きで実装したときに
新設された。フラグの目的は「コードのデプロイ」と「法務確認・画像内 prompt injection の
手動評価という承認プロセスの完了」を切り離すことだった (承認プロセス完了前にコードだけ
デプロイしても、フラグが `false` の間は無害であることを保証する rollout gate)。

`docs/rollout-checklists.md` の記録によれば、この切り離しの役目は既に終えている:

- 項目 1 (法務確認): オーナー自身が「現行の利用規約・顧客契約と矛盾しない」ことを確認し、
  これをもって項目 1 を完了として扱うと決定した (2026-08-21)。
- 項目 2 (画像内 prompt injection の手動評価): 評価は実施していないが、オーナーが責任者
  権限で本項目を実施しないことを明示的に決定した (2026-08-21、例外決定であり合格ではない)。
- 項目 3 (再評価対象の棚卸し) は将来の義務として存続する。

この 2 つの決定を踏まえ、オーナーとの対話 (2026-08-21〜22) で「フラグを既定 `true` に変えて
緊急停止手段として残す」案 (B とは別の案) も提示されたが、オーナーは **B 案 (フラグの完全撤去
= 常時有効化)** を「いらないので。」という理由で選択した。

現状のフラグは以下の全箇所で読まれている (`grep ocr_analysis_enabled` による全数列挙):

| ファイル | 用途 |
|---|---|
| `config/manual.php:61` | フラグ定義本体 (`env('MANUAL_OCR_ANALYSIS_ENABLED', false)`) |
| `app/Support/Manual/AcceptedSourceDocumentTypes.php:57` | `imagesEnabled()` の判定源。受理形式の単一情報源 |
| `app/Services/Manual/AnalysisPipeline.php:213` | extract 段の route 決定 (`text`/`ocr`) の入力 |
| `app/Services/Manual/SopTextExtractor.php` (docblock のみ) | フラグの説明コメント (コード分岐なし) |
| `app/Rules/SourceDocumentSizeLimit.php` (docblock のみ) | 「容量分類はフラグに依存しない」という設計判断の説明コメント (コード分岐なし) |
| `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` | 両状態 (false/true) の分岐を固定するテスト |
| `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` | 両状態の分岐を固定する Feature テスト |
| `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` | 両状態の分岐を固定するパイプラインテスト |
| `docs/architecture.md` | rollout gate の設計記述 |
| `docs/rollout-checklists.md` | チェックリスト本体 (フラグの有効化条件) |

`AcceptedSourceDocumentTypes::imagesEnabled()` は Inertia props (`imageSourceDocumentsEnabled`)
経由でフロント (`SourceDocumentUpload.svelte` / `SourceDocumentUploadNotice.svelte` /
`Manuals/Create.svelte` / `Manuals/Show.svelte`) にも伝播しており、画像専用の外部送信注意文言の
出し分けに使われている。

## 改善アイデア

フラグを撤去し、OCR 機能 (画像受理 + PDF の OCR フォールバック) を無条件で有効にする。
思考原則 3 (後方互換の並走を残さない) に従い、フラグの痕跡そのもの
(config キー・env 変数・`config()->boolean()` 呼び出し・フラグ分岐を作るためだけに存在する
コード・両状態を検査するテスト分岐) を残さない。OCR 機能自体 (媒体検証・OCR プロンプト・
容量上限等) は撤去対象ではない — 撤去するのは「有効/無効を切り替える仕組み」だけである。

## 期待効果

- 使命への貢献: OCR 対応 (T234) は現場の SOP が画像・スキャン PDF で保管されている場合に
  対応するための機能であり、これが常時有効になることで「思考ゼロ・編集ゼロ」の対象範囲が
  フラグの状態に依存せず一貫する。
- コードの単純化: `AcceptedSourceDocumentTypes` / `AnalysisPipeline::resolveExtractInput()` の
  分岐が 1 本に減り、両状態を検査していたテストの半分 (無効状態側) が不要になる。
- 運用上の帰結の明示: 次のデプロイで OCR は無条件に有効になる。これは意図された帰結として
  明記するが、「リスクが無くなる」という意味ではない — 下記「リスクと運用上の帰結」に
  残存するリスクと、その上でオーナーが撤去を選んだという判断の位置づけを分けて記録する。

## リスクと運用上の帰結 (概念設計 Round 1 レビューで Critical 指摘を受けて追記)

フラグ撤去は「リスクが解消したので撤去する」という判断ではない。以下を明確に分けて記録する:

1. **項目 2 (画像内 prompt injection の手動評価) は「未実施」のままである。**
   フラグを撤去しても評価が完了したことにはならない。`docs/rollout-checklists.md` の
   既存記録が示すとおり、これはオーナーが責任者権限で行った**例外決定**であり、
   「合格した」への読み替えは禁止されている (既存記録の方針を維持する)。
2. **フラグ撤去後は緊急停止の手段が無くなる。** 撤去前は `MANUAL_OCR_ANALYSIS_ENABLED=false`
   への切替 (環境変数変更 + `config:cache` 再生成 + プロセス再起動) が唯一の緊急停止手段
   だったが、これは元々**運用操作であってこのリポジトリの変更ではない**
   (`docs/rollout-checklists.md` 「反映の運用手順」節が既に明記している)。撤去後は、
   問題が発覚した場合の対応は「OCR を無効化するコード変更 (例: 受理形式・OCR フォールバック
   分岐を再度ゲートする、または該当箇所を差し替える) を作って通常のデプロイ手順で反映する」
   という経路だけになる。この帰結はオーナーへの提示時に既に共有された内容であり、
   オーナーは「B です。いらないので。」という判断で緊急停止手段を残さない案を選んでいる。
   本設計はこの判断を実装可能な形にするものであり、判断自体を再考する場ではない。
3. **項目 3 (再評価対象の棚卸し) の義務は消えない。** provider/model pin・媒体 YAML・
   vendor 契約テストの変更時に項目 2 の評価セットを新規作成し再承認するという義務は、
   フラグの有無と無関係に存続する。フラグ撤去後もこの義務の記述はそのまま残す
   (削除しない)。
4. **既存の観測・評価計画 (`docs/rollout-checklists.md` 「観測・課金の評価」節) は
   フラグの有無に依存しない。** 評価期間・3 指標 (token 比率・OCR 経路比率・失敗率) は
   フラグ撤去後も同じ集計方法 (`AnalysisPipeline::logExtractStageTerminal()` の構造化ログ +
   `llm_call_logs`) で継続して見る対象である。フラグ撤去はこの観測計画を止める理由には
   ならないことを明記する。
5. **PromptDefense/GuardedPrompt 経路は変更しない。** OCR 経路の LLM 呼び出しは
   `LlmCallContextData` → `PromptDefense::loadWithMedia()` → `GuardedPrompt` の
   既存経路をそのまま使う。フラグ判定の削除は route (`text`/`ocr`) の決定条件を単純化する
   だけであり、窓口・実行単位・応答検査の構造には触れない (禁止事項 5 に抵触しない)。

## 実装方針 (概要)

1. `config/manual.php` から `ocr_analysis_enabled` キーを削除する。
2. `AcceptedSourceDocumentTypes` の `imagesEnabled()` を撤去し、`extensions()` / `mimes()` /
   `formatsLabel()` を画像込みの固定値へ一本化する。
3. `AnalysisPipeline` の route 決定 (`resolveExtractInput()` / `runExtractStage()`) から
   `$ocrEnabled` 変数と分岐を除去し、「画像なら OCR」「PDF 品質ゲート失敗なら OCR
   フォールバック」を無条件の経路にする。
4. Inertia props `imageSourceDocumentsEnabled` を撤去する (常に `true` になる値をわざわざ
   props として配らない)。`SourceDocumentUploadNotice.svelte` の OCR 固有警告は常時表示に
   フォールディングする。
5. `SopTextExtractor` / `SourceDocumentSizeLimit` のフラグに触れる docblock を、フラグが
   存在しない前提の記述へ書き換える (コード分岐は元々無いため変更なし)。
6. 両状態 (false/true) を検査していたテストを畳み込む: 無効状態を固定するテスト
   (「フラグ false のとき画像を含まない」等) は削除し、有効状態の挙動を固定するテスト
   だけを残す (常時有効という前提に立ったテスト名・コメントへ書き換える)。
7. `docs/rollout-checklists.md` の OCR 節へ、オーナー決定によるフラグ撤去の記録を追記する
   (既存の承認記録・将来の再評価義務の記録は消さない)。運用手順節はフラグ撤去に伴い
   「フラグを立てる」という前提の記述が成立しなくなるため、事実に合わせて書き換える。
8. `docs/architecture.md` の rollout gate に関する記述をフラグ撤去後の実態に合わせて更新する。

## 波及変更・検証の受入条件 (概念設計 Round 1 レビューで Warning 指摘を受けて追記)

- **削除完了の検査 (grep 全数列挙は検索の手段であり保証ではない)**: 詳細設計で、
  `ocr_analysis_enabled` / `MANUAL_OCR_ANALYSIS_ENABLED` / `imageSourceDocumentsEnabled`
  の 3 識別子が git 追跡下のコード (`app/` `config/` `resources/js/` `routes/` `database/`
  `bootstrap/`。devnotes 配下の設計履歴・`docs/` の経緯記録は対象外) に一切残っていないことを、
  実装完了時の確認手順として明記する (既存のアーキテクチャ gate 群と同じ「テストファーストで
  先に赤くする」の対象にはしない — フラグ削除は 1 回の PR で完結する作業であり、恒久的な
  gate を新設するのは思考原則 2 (今必要なものだけ作る) に反するため。確認は実装 PR の
  完了条件としての grep 確認で足りる)。
- **フロント側の波及箇所 (props 削除に伴う一括更新が必要な全ファイル)**:
  - Props を配る側: `app/Http/Controllers/Projects/VideoManualController.php`
    (`create()` / `show()` の両方から `imageSourceDocumentsEnabled` を削除)
  - Props を受ける側 (`interface Props` の型定義から削除):
    `resources/js/components/features/manual/SourceDocumentUpload.svelte` /
    `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` /
    `resources/js/pages/Manuals/Create.svelte` / `resources/js/pages/Manuals/Show.svelte`
  - コンポーネントテスト (props fixture の更新):
    `tests/js/components/features/manual/SourceDocumentUpload.test.ts` /
    `tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts` /
    `tests/js/pages/ManualsCreate.test.ts`
  - `sourceDocumentFormatsLabel` (フラグ true 固定値相当) は撤去せず、画像込みの固定文言
    (`'PDF・Excel・テキスト形式、または JPEG・PNG の画像'`) を返す形で残す (help 文言の
    情報源としての役割自体は変わらない)。
- **外部送信案内文言の対象確認**: `SourceDocumentUploadNotice.svelte` の OCR 固有警告
  (`source-document-image-notice`) の文面は「画像や、文字を読み取れないスキャン PDF では、
  紙面の見た目がそのまま送信されます」であり、**既に画像だけでなく OCR フォールバック対象の
  スキャン PDF も対象に含めている**ことを確認済み。常時表示にしても文面を書き換える必要は無い
  (対象の齟齬は無い)。

## 制約・前提

- OCR 機能自体 (媒体検証・プロンプト・容量上限・観測ログ) は変更しない。変更対象は
  「有効/無効を切り替える仕組み」だけである。
- `AnalysisFailedException` の文言・`AnalysisFailureReason` の列挙値・
  `AnalysisAcceptanceGate` 等の OCR 経路そのものは変更しない。
- `docs/template-fingerprints.json` に該当ファイル (`config/manual.php` /
  `AcceptedSourceDocumentTypes.php` / `AnalysisPipeline.php` / フロント該当ファイル) が
  含まれていないことを確認済み (テンプレート共有ファイルではない。乖離台帳の登録は不要)。
- 本設計は「設計」のみであり、実装・TODO 登録は行わない (別スキルの責務)。

## スコープ外

- OCR 機能自体の仕様変更 (対応形式の追加、容量上限の変更等)。
- `docs/rollout-checklists.md` の項目 3 (再評価義務) の運用自体の変更 — 記録として残す。
- 他機能のフラグ・rollout gate パターンへの一般化。

---

上記の対応マトリクスと更新後の概念設計全文を踏まえて再レビューしてください。
全体判定 (APPROVED / CHANGES_REQUESTED) と、残る指摘があれば [Critical]/[Warning]/[Suggestion] で示してください。
