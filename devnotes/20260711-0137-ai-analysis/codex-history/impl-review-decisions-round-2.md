# 対応マトリクス: impl-review Round 2 (最終レビュー)

Round 1 は Codex 側のサンドボックス制約でファイル未読のため判定保留 (レビュー不成立)。
Round 2 で diff 全文 (main..todo/T003) をプロンプトに貼付して再実施した。以下は Round 2 指摘への判断。

## [Critical] C1: AnalysisPipeline::runExtractStep が SourceDocument をロックせず extracted_json を上書き (複数 manual が同一 SOP を共有し得る前提での後勝ち破壊)

- 判断: 反論する (修正しない)
- 根拠: 指摘の前提が事実と異なる。
  1. `SourceDocument` は `video_manual_id` FK で **単一の VideoManual に専属**する
     (`app/Models/SourceDocument.php`。「同一 SOP を複数 manual が共有し得る」は誤り。
     アップロードも `POST /projects/{project}/manuals/{manual}/source-documents` で
     manual 配下に relation 経由 create される追記型)。
  2. 同一 manual 内の並走も `AnalysisJobService::trigger()` の in-flight guard
     (queued/running は 1 つ。VideoManual 行ロック下で判定) で構造的に 1 本に制限される。
  3. 旧 worker 生存中の stale 回復→再 trigger の窓も、`RunManualAnalysis::$timeout = 1380s`
     < `retry_after 1560s` < stale 閾値 `1800s` (30 分) の連鎖 (Job クラスの docblock に明記) で
     旧 worker が先に kill されるため成立しない。
  4. 仮に病理的ケースで書き込みが重なっても、対象は同一 manual の同一 document の
     write-only 監査スナップショット列であり、シナリオ整合・課金整合は terminal tx
     (job 行ロック + Running guard + manual 行ロック) が別途保護している。
- 対応内容: なし (S1 の「別 manual で同一 source_document を同時解析」テスト追加も
  前提不成立のため見送り)

## [Warning] W1: recoverStale が failJob の terminal no-op でも recovered をカウント

- 判断: 対応する
- 根拠: pluck 後〜failJob 前に terminal へ先着された場合、実際に回復していない件数を
  「recovered N」と報告し得る (実害は cron ログの精度のみだが指摘どおり)
- 対応内容: `failJob(): bool` (failed へ実遷移したら true、terminal no-op は false) に変更し、
  `recoverStale()` は true のときのみカウント。既存呼び出し元 (pipeline catch / Job::failed)
  は戻り値を使わないため影響なし。既存の `recovered N` 出力アサーションは全 green

## [Warning] W2: ポーリングの !res.ok 完全黙殺で 401/419 時に復帰導線がない

- 判断: 対応する
- 根拠: 解析は 20 分超に及び得るため、セッション失効時に無言で永久再試行するのは
  ユーザーに復帰手段を示せない
- 対応内容: `AnalysisPanel.svelte` の poll で 401/419 を検出したらポーリングを停止し、
  解析中表示内に「セッションの有効期限が切れました。ページを再読み込みしてください
  (解析はサーバで継続しています)」の Alert (warning) を表示
  (`data-testid="analysis-session-expired"`)。401 で停止 + 以降 interval が発火しても
  再ポーリングしないことをフェイクタイマー vitest で固定。その他の !res.ok は
  従来どおり次周期に任せる (一時失敗の静かな再試行)

## [Warning] W3: SopTextExtractor の file_put_contents 戻り値未検証

- 判断: 対応する
- 根拠: 書き込み失敗 (ディスクフル等) の検出を IOFactory の後段例外に依存するのは暗黙的
- 対応内容: `Assert::integer(file_put_contents(...), '一時ファイルへ書き込めません')` で明示検証
  (失敗は既存の catch(Throwable) → unextractable 正規化に乗る)

## [Suggestion] S1: 同一 source_document_id を別 manual で同時解析するテスト追加

- 判断: 見送る
- 根拠: C1 反論のとおり SourceDocument は manual 専属で「別 manual から同一 document」は
  スキーマ上構成できない (FK が単一 manual を指す)

## [Suggestion] S2: withBoundedRetry にジッター付きバックオフ

- 判断: 見送る
- 根拠: リトライは LLM 出力の JSON 検証失敗のみ・最大 2 回 (計 3 試行) の有界で、
  provider 過負荷例外はリトライ対象外。現時点でバースト源にならない
  (YAGNI。provider レート制御が必要になった時点で導入)

## 総評への回答

C1 は前提誤認のため「マージ見送り」判断には従わない。W1/W2/W3 はすべて修正済みで、
全検証コマンド (composer test 1090 / phpstan 482 files / pint / eslint / tsc /
vitest 318 / build) green を再確認した。
