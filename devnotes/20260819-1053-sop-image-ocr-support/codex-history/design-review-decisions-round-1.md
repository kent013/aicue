# 対応マトリクス: design-review Round 1

## [Critical] `loadWithMedia()` の無名 `TextPrompt` が実行状態・帰属を引き継がない
- 判断: 対応する
- 根拠: 指摘のとおり実装できないコードだった。vendor
  (`Kent013\PrismPrompt\Prompt` / `Traits\ResolvesProviderConfig`) を実読した結果、
  `templatePath` / `templateVariables` / `loadMetadata()` が `protected` であり、
  無名クラスの**コンストラクタの内側**で `Prompt::load()` と同じ初期化を行えば、
  provider/model/system_prompt/canary/metadata の解決ロジックがそのまま正しく働くことを確認した。
- 対応内容: `loadWithMedia()` を「外側から既存 TextPrompt を包む」形から
  「無名クラス自身が `templatePath` 代入 → `loadMetadata()` を自分のコンストラクタで行う」形へ
  書き直した。状態引き継ぎの契約テスト (provider/model/system prompt/canary/metadata/
  client options/max_tokens/メッセージ順序/応答検査) を施策 4 のテスト計画に追加した。

## [Critical] `"(判読不能)"` マーカーが日本語比率ゲートを通過してしまう
- 判断: 対応する
- 根拠: `"(判読不能)"` 自体が日本語文字であるため、最も拒否したい「ほぼ何も読み取れなかった」
  結果が成功扱いになる。指摘は正しい。
- 対応内容: マーカーを ASCII の `"[UNREADABLE]"` へ変更した (YAML の prompt 指示を修正)。
  日本語比率計算に対して自然に不利に働くため、特別なコードを足さずに解決できる。
  テスト fixture も「全て空文字」(スキーマ検証で先に落ちるため構築不能) から
  「`[UNREADABLE]` の羅列」へ訂正した。

## [Critical] provider/model の実行時チェックが config を見ており媒体 YAML と不整合
- 判断: 対応する (根本原因を修正)
- 根拠: vendor (`ResolvesProviderConfig::resolveProvider()`)を実読し、
  provider/model は「クラスプロパティ > YAML > config」の優先順位で解決され、
  YAML に明記した値が config の既定値より常に優先されることを確認した。
  既存 3 YAML も新設 YAML も `provider: anthropic` を直接書くため、
  「config の既定が変わると誤動作する」というシナリオ自体が実際には起こらない。
- 対応内容: `AnalysisMediaValidator::assertProviderSupported()` (ランタイム config チェック) を
  削除し、代わりに `AnalysisTokenBudgetInvariantTest` (施策 9) で
  **`sop-extract-media.yaml` 自身の `provider`/`model` フィールド**を pin する
  ビルド時テストへ置き換えた。`AnalysisFailureReason::MediaProviderUnsupported` も削除した
  (思考原則 2: 起こらないシナリオ用のコードを持たない)。

## [Critical] gate が `Image::fromRawContent`/`Document::fromRawContent` の 2 呼び出ししか見ていない
- 判断: 対応する
- 根拠: vendor (`Media.php`) を実読し、`Image`/`Document` の構築手段が
  `__construct` (public) + 6 つの named constructor (`fromFileId`/`fromPath`/
  `fromLocalPath`/`fromStoragePath`/`fromUrl`/`fromRawContent`/`fromBase64`) の
  計 7 通りあることを確認した。2 つだけを見る gate は迂回路が残る。
- 対応内容: `VendorMediaTypeConstruction` ルールを「`Image`/`Document` を受信者にする
  あらゆる static 呼び出し + `new Image`/`new Document`」に拡張した (メソッド名の列挙をやめ、
  `Media` クラスに構築以外の static メソッドが無いという事実で母集団を過不足なく表す)。
  合成負例に `fromStoragePath` 等の見逃していた形を追加した。

## [Critical] rollout チェックリストが production 有効化を実際には止められない
- 判断: 対応する
- 根拠: 指摘のとおり、ドキュメントのチェックリストだけでは施策 1 (MIME 受理) を含む
  コードがデプロイされた時点で機能が露出してしまう。
- 対応内容: `config('manual.ocr_analysis_enabled')` (既定 false) を新設し、
  画像 MIME 受理 (施策 1) と OCR フォールバック分岐 (施策 6) の両方をこの 1 つの
  フラグでゲートする設計にした。フラグが false の間は施策 1〜9 のコードを
  いつデプロイしても中間状態が生まれない。フラグを true にする変更を、
  法務確認・prompt injection 手動評価の承認後に行う独立の運用操作として切り出した
  (実装モードの PR 分割もこれに合わせて更新)。

## [Warning] 画像専用容量上限が解析時に再検証されない
- 判断: 対応する
- 対応内容: `AnalysisMediaValidator::validateImage()`/`validatePdfForOcr()` に
  `Storage::get()` 直後・vendor 変換より前の容量検査を追加した。境界値テスト
  (ちょうど・1 byte 超過) を計画に追加した。

## [Warning] 画像 1 枚制約が UI にしかない
- 判断: 対応する (既に施策 1 のリスク欄で対応済みだったものを明確化)
- 対応内容: Service 層 (`SourceDocumentService`) で拒否する設計とし、UI は
  サーバーのエラーをそのまま表示するだけにすることを施策 10 にも明記した。

## [Warning] `validateImage()`/`validatePdfForOcr()` が mime を検証しない
- 判断: 対応する
- 対応内容: 各メソッドの冒頭に `Assert::inArray()`/`Assert::same()` で契約違反を
  防御的に落とす分岐を追加した。

## [Warning] 破損 PDF/画像を `MediaTooLarge` にするのは理由と実態が不一致
- 判断: 対応する
- 対応内容: `AnalysisFailureReason::MediaUnreadable` を新設し、破損・未対応形式は
  こちらに、容量/画素数/ページ数の上限超過は `MediaTooLarge` のままにして区別した。

## [Warning] PDF が二重読み込みされる (SopTextExtractor + AnalysisMediaValidator)
- 判断: 見送る (許容するトレードオフとして明記)
- 根拠: 20MB 上限があるため最悪でも 2 回の読み込みで収まり、`SopTextExtractor` の
  入力型を変えてまで統合する価値は現時点では無いと判断する (思考原則 2)。
- 対応内容: 「単一読み込み」の保証範囲を `AnalysisMediaValidator` の各メソッド単体の
  atomicity に限定する (誇張しない) ことを明記し、パイプライン全体での 2 回読みは
  リスク欄に既知のトレードオフとして明記した。

## [Warning] `MediaPromptExtendsDeclaration` の名前解決が不十分
- 判断: 対応する
- 対応内容: 既存の `PromptWindowScanner` が `VendorPromptLoad` 等で既に持つ
  完全修飾名解決ロジックを再利用する設計にし、別名 import 等の負例を追加した。

## [Warning] `PromptUntrustedInputContractTest` の untrusted キーと帰属 exempt の混同
- 判断: 対応する
- 根拠: 実際のテストファイルを読み、「untrusted 変数 list を空にする」ことと
  「帰属キー list を空にする (exempt)」ことは別物であると確認した。
  `SopExtractFromMediaPrompt` は帰属必須であり exempt 対象ではない。
- 対応内容: 施策 5 の記述を訂正し、untrusted list は `[]`、帰属キー list は
  `['organization_id', 'subject_type', 'subject_id']` を通常登録する形にした。

## [Warning] 防御指示・schema の一致検査が文字列存在確認だけ
- 判断: 対応する
- 対応内容: スキーマ部分を正規化して完全一致比較するテストへ変更する方針を明記した。

## [Warning] `match (true)` の `default` 節で PHPStan の型絞り込みが保証されない (施策 6)
- 判断: 対応する
- 対応内容: `match (true)` をやめ、`if ($input instanceof ExtractedText) { return ...; }` の
  早期 return + 後段処理という素直な分岐に書き直した。

## [Warning] OCR route の選択・失敗理由が構造化して観測されない
- 判断: 対応する
- 対応内容: `runExtractStep()` に構造化ログ (`route`/`source_mime`/`media_size_bytes`/
  `media_pages`/`media_pixels`) を追加した。本文・応答は含めない。

## [Warning] token budget 見積りが hard invariant ではない / provider/model pin が config 参照
- 判断: 対応する
- 対応内容: 「設定値どうしの整合の検査であり実 token 上限の保証ではない」ことを明記し、
  provider/model pin を config ではなく `sop-extract-media.yaml` 自身の値へ変更した
  (Critical 項目と同一の修正)。

## [Warning] 評価指標 (失敗率) の集計方法が `llm_call_logs` だけでは出せない
- 判断: 見送る (概念設計 Round 4/5 で既に対応済み)
- 根拠: 概念設計は既に「集計元は指標ごとに異なる。(c) は `analysis_jobs` の終端状態との
  突合が必要」と明記している。design-review はこの既存の記述を見落としたか、
  detailed design 側に転記されていなかった可能性があるため、施策 11 に明記し直した。

## [Warning] UI 変更対象コンポーネントが未特定
- 判断: 対応する (実装着手時の前提条件として明記)
- 対応内容: 実装着手時に具体的な配置とテスト名をこの節へ追記してから着手することを明記した。

## [Warning] 画像 2 枚目拒否を UI 状態だけで行わない
- 判断: 対応する (画像 1 枚制約の対応と同一)

## PR 分割の修正案 (施策 1 を含む先行デプロイで未完成機能が露出する)
- 判断: 対応する
- 対応内容: 機能フラグ (`ocr_analysis_enabled`) の導入により、Critical の rollout gate 対応と
  合わせて解決した。実装モードの PR 分割を「フラグ新設 → 内部型 → 実行経路+gate →
  MIME受理+UI (フラグ配下) → フラグ有効化 (運用変更)」の 5 段に更新した。
