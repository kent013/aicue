# 対応マトリクス: conceptual-review Round 1

Codex 判定: CHANGES_REQUESTED (Critical 0 / Warning 7 / Suggestion 4)

## [Warning] 「費用増はゼロ」は言い過ぎ
- 判断: **対応する**
- 根拠: 指摘が正しい。LLM 呼び出し回数とチケットは不変だが、入出力 token は増えるので実費は微増する。
  保証範囲を誇張しないのは本リポジトリの作法でもある。
- 対応内容: 「追加 LLM 呼び出し 0 回 / チケット消費 据え置き / token 実費は微増 (見積り上界を明記)」の
  3 段に書き分けた。上界は「出力 +700 token (出力予約 16,000 の 5% 未満) / 入力側は
  プロンプト本文 +250 token 程度」と数値で書き、"ゼロ" という語を削除。

## [Warning] `validation` 追加で `WorkDecompositionData::fromLlmText()` の契約が壊れうる / 二重パース
- 判断: **対応する** (2 件まとめて)
- 根拠: 現行 `fromLlmText()` は未知キーを無視する実装なので即座には壊れないが、
  「1 応答 = 1 パース」に一本化した方が例外経路・エラーメッセージ・ログが一貫する。
  同じテキストを 2 回 decode する案は自分でも弱いと考えていた。
- 対応内容: 2 段目の応答 DTO `WorkDecompositionResponseData` を新設し、
  `fromLlmText(string)` で **decode を 1 回だけ**行い、そこから
  `WorkDecompositionData::fromPayload(array)` と `SopValidationData::fromPayload(array)` を組む。
  `WorkDecompositionData::fromLlmText()` は**削除**する (後方互換の並走を残さない)。
  保存値からの復元は `SopValidationData::fromStorage(?array): ?self` として別メソッドに分ける。

## [Warning] validation 不正で解析本体を失敗させるのは強すぎないか (必須度を決めよ)
- 判断: **厳格必須のまま維持する (反論)。ただし根拠を設計に明記する**
- 根拠:
  1. ブリーフの指示 (「不正なら有界リトライ。既存 AnalysisPipeline の作法に従う」) と一致する。
  2. 「一部だけ不正な応答」という概念を作らないほうが経路が 1 本で腐りにくい。
  3. **失敗してもチケットは課金されない** — `AnalysisJobService::failLockedJob()` が
     予約を release するため、利用者が失う可能性があるのは時間だけで金銭ではない。
     しかも失敗は画面に出る (無音のデータ欠落ではない)。
  4. スキーマは 4 フィールド・enum 3 値・タイトル 10 件上限と小さく、同じモデルは
     3 段目でこれよりはるかに大きいスキーマを満たしている。
- 対応内容: 「必須度の決定」節を設計に追加し、上記 4 点を根拠として明記。
  併せてリスク欄に「report のスキーマ違反が解析失敗の原因になりうる (上限: リトライ 3 回)」を残す。

## [Suggestion] `narration_not_polite` の仕様 (「ました」「ません」の扱い) を明文化せよ
- 判断: **対応する**
- 根拠: 「ます」終端だけを可とすると「〜してはいけません」「〜が必要です」を偽陽性にする。
  偽陽性は検査そのものを読み飛ばさせるので、規則の目的 (丁寧体への統一) に合わせて集合を定義する。
- 対応内容: 許容終端集合を **{ます, ません, ました, ましょう, です, でした}** と定義し
  (末尾の空白と句点 。/./!/！ を除去してから判定)、ラベルも
  「ナレーションが「です・ます」調で終わっていない」に改めた。
  `narration_directive` (「ください」を含む) とは独立に数える (同一カットが両方に載りうる) ことも明記。
  字幕①の検査も同様に「「。」を含む、または「ます」「です」を含む」と規則を明文化した。

## [Warning] `fromStorage()` の null フォールバックが無音だと保存契約の破損が見えない
- 判断: **対応する**
- 根拠: 指摘が正しい。画面を落とさない判断と、壊れたことを知る手段は両立できる。
- 対応内容: 復元失敗時は固定 event 名の `Log::warning`
  (`解析ジョブの妥当性所見の復元に失敗しました` + job id) を出し、
  テストで「壊れた保存値で Show が 200 を返す」と「警告が記録される」の両方を固定する。

## [Warning] 最新 succeeded job の取得と source_document 比較の境界・表示仕様
- 判断: **対応する**
- 根拠: cross-org 読み出しはセキュリティ不変条件 3 に触れる。クラス起点の主キー取得は
  `ModelDirectFetchInvariantTest` の目録対象にもなる。
- 対応内容: 取得は **`$manual->analysisJobs()` relation 起点**に固定 (クラス起点 fetch を作らない
  = 目録登録も不要)。表示仕様も明記:
  `is_current_document = (job.source_document_id !== null && job.source_document_id === 最新 SOP の id)`。
  SOP が削除済み (nullOnDelete で NULL) / 差し替え済み / 1 件も無い、のいずれでも false になり、
  false のときだけ「解析時の手順書に対する所見です」の注記と再解析導線を添える。

## [Warning] 「追加クエリ 2 本」の主張は位置表記のために nested cuts を読むと崩れる
- 判断: **対応する**
- 根拠: 指摘が正しい。手順 N / 急所 N-M の位置は親子関係が要るので、素朴に書くと N+1 になる。
- 対応内容: 規約検査は **cuts を `orderBy('sort_order')` の 1 クエリで全件取得し、
  `parent_cut_id` で 1 パス groupBy して組む** (`ScenarioDocumentData::fromManual` と同じ手口) と明記。
  cut 件数に依存せずクエリが 1 本であることを **DB::listen でクエリ数を数えるテスト**で固定する。

## [Warning] props / storage / TS の shape と同期責務が抽象的 (PHPStan level 10)
- 判断: **対応する**
- 根拠: level 10 では array shape を書き切る必要があり、詳細設計の前に境界を決めておくべき。
- 対応内容: DTO 階層を確定して設計に書いた:
  `ScenarioReportData { verdict: ScenarioVerdictData|null, counts: ScenarioCountsData, findings: list<ScenarioRuleFindingData> }`。
  Controller は生 array を組まず `ScenarioReportData::toArray()` だけを props に渡す。
  enum 2 つ (`ScenarioVerdict` / `ScenarioRuleCode`) は `ManualEnumTsSyncInvariantTest` に登録する。
