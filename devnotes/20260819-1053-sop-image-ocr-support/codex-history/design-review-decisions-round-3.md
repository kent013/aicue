# 対応マトリクス: design-review Round 3

## [Critical] 画像専用容量上限の判定材料が未定義 (クライアント申告拡張子/MIME で迂回できる)
- 判断: 対応する
- 根拠: 指摘のとおり。クライアント申告の拡張子・MIME は攻撃者が書き換えられるため、
  これを容量上限の選択材料にすると JPEG を `.pdf` にリネームして緩い上限へ迂回できる。
- 対応内容: `UploadedFile::getMimeType()` (サーバー側 finfo sniff。クライアント申告ではない)
  だけを判定材料にする共通 Rule `App\Rules\SourceDocumentSizeLimit` を新設し、
  2 つの FormRequest がこれを共有する設計にした。偽装ファイル名での迂回不可を
  テスト計画に明記した。

## [Critical] 観測ログが「媒体検証成功」と「LLM 呼び出し失敗」を両方 outcome 付きで残す
- 判断: 対応する
- 根拠: 指摘のとおり。同じジョブの extract 段について 2 つの outcome 付きログが残り、
  ジョブ成功率を単純集計できない状態になっていた。
- 対応内容: `resolveExtractInput()` からログ出力を除去し、`runExtractStage()` という
  単一の呼び出し点で「ジョブの extract 段につきちょうど 1 回」だけ終端ログを出す設計へ
  再構成した。`failure_category` も実装クラス名ではなく固定語彙 (reason enum の値・
  `llm_output_invalid_*`・`timed_out`・`provider_busy`・`too_large`・`unknown`) に
  正規化した。

## [Critical] queued ジョブの扱いが「まだ resolveExtractInput() を通過していない」ため矛盾
- 判断: 対応する
- 根拠: 指摘のとおり。`queued` ジョブは実行開始前であり `resolveExtractInput()` を
  通過していないため、「実行中のジョブは最後まで走らせる」という当初の記述は
  queued ジョブの実態と矛盾していた。
- 対応内容: フラグは DB へスナップショットせず `resolveExtractInput()` 実行時に
  都度 `config()` から読む既存の設計のまま、「queued ジョブは新規判定の対象であり、
  無効化後に実行されれば OCR は試みられず既存の失敗経路 (unextractable 等) になる」
  「既に判定を終えて実行中のジョブだけが最後まで OCR 経路で完走する」と明確化した。
  これは追加実装を要しない (既存設計がそのまま両方のケースを説明する)。

## [Critical] UI 対象コンポーネントが施策 10 本文では未特定のまま
- 判断: 対応する
- 対応内容: 施策 10 本文を `resources/js/components/features/manual/
  SourceDocumentUpload.svelte` への具体的な変更内容 (accept 属性・
  imageSourceDocumentsEnabled Props の反映) へ更新し、「実装時に特定する」という
  記述を削除した。

## [Warning] `@return list<Message>` の `Message` が import されていない
- 判断: 対応する
- 対応内容: `use Prism\Prism\Contracts\Message;` を追加した。

## [Warning] `llm_call_logs` テスト方針が条件分岐のまま確定していない
- 判断: 対応する
- 根拠: `Prompt::$fake` は `executePrism()` の先頭で必ず短絡し記録されないという
  既存の制約 (`PromptUntrustedInputContractTest` の docblock) が、この 4 段目にも
  そのまま当てはまる。
- 対応内容: 「テストレーンで `llm_call_logs` 到達を検証する Feature テストは書かない」
  と確定し、正本を reflection ベースの契約テストと `dev:pipeline-smoke` の
  llm-evidence 段の 2 つに一本化した。

## [Warning] 動的 static call を全て違反候補にすると正当な利用まで拾う懸念
- 判断: 見送る (現状の設計を維持。理由を明記)
- 根拠: `Image`/`Document` への動的な間接呼び出しは、現在の設計のどこにも
  正当な利用例が存在しない (窓口内の呼び出しは全てリテラルなクラス名の static 呼び出し)。
  「既存母集団の棚卸し」は母集団が実質空であるため過剰な作業になる。
  fail-closed 側に倒すことで実運用上の誤検知は起きない見込みである、という
  Round 2 時点の記述を維持する。

## [Warning] vendor `Image`/`Document` の subclass 経由の構築が未確定のまま
- 判断: 対応する
- 根拠: 指摘のとおり、gate の検出対象が変わる論点は設計承認前に確定する必要がある。
  実際に vendor ソース (`Image.php`/`Document.php`) を読み、**どちらも `final` ではない**
  ことを確認した。
- 対応内容: `VendorMediaTypeSubclassDeclaration` という新しいルールを追加し、
  `app/` 配下で `Image`/`Document`/`Media` を継承する class 宣言を許可 0 件で
  deny-by-default に固定する設計にした。vendor の `final` 宣言に依存しない、
  より強い保証にした。

## [Warning] 一次情報・導出式が未確定のまま詳細設計が完成扱いになっている (施策 9)
- 判断: 対応する
- 対応内容: 施策 9 を「9a: 調査タスク (実装 PR に先行)」「9b: テスト実装 (9a の結果を消費)」の
  2 段階に明示的に分割し、9a 完了までは本施策を APPROVE 対象にしないことを明記した。
  hard limit とestimateを別定数・別テスト名・別docblockにする方針も明記した。

## [Warning] rollout smoke check が read-only ではない
- 判断: 対応する
- 対応内容: 「read-only の smoke check」を「制御された synthetic 確認 (実際にアップロード・
  DB 書き込み・外部 LLM 呼び出し・チケット消費を伴う)」という正確な表現に改め、
  専用検証組織・使い捨て fixture・後始末を rollout 手順に明記した。

## [Warning] config cache 再生成の言及が route:cache 運用要件と紛らわしい
- 判断: 対応する
- 対応内容: `route:cache` 運用要件そのものを変更するものではなく、フラグ反映のための
  config cache 再生成という一般論であることを明記した。

## [Suggestion] `OcrEmptyOrInvalid` の docblock の「手順 0 件」が到達しない
- 判断: 対応する
- 対応内容: コメントを「日本語比率不足・判読可能な本文なし」に限定し、手順 0 件は
  schemaViolation で先に検出されるため到達しないことを明記した。

## [Suggestion] 画素数境界テストとオーバーフロー境界テストの分離
- 判断: 対応する
- 対応内容: 辺長上限の境界テストと、辺長内側での画素数上限の境界テストを分けて記載した。

## [Suggestion] `[UNREADABLE]` の部分的な判読不能ケースの期待値が曖昧
- 判断: 対応する
- 対応内容: 「日本語らしい捏造」と同様、このゲートでは検出できない既知の限界として
  テストの期待値を明示する方針を維持しつつ、fixture の期待値表現を明確化した。

## [Suggestion] `出力スキーマ:` 見出しが複数回現れた場合の抽出範囲
- 判断: 対応する
- 対応内容: 見出しが複数回現れる場合は抽出できないとして fail-closed にすることを明記した。

## [Suggestion] `isOcrEligibleForPdf()` の `match` に `default` があることの明記
- 判断: 対応済み (Round 2 で対応)
