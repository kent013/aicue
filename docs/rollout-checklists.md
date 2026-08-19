# rollout チェックリスト

機能フラグを production で有効化する前に確認しておく運用チェックリストを置く。
コードの完了条件ではなく、**機能公開の前提条件**である。フラグを立てる変更そのものを
レビュー対象にすることで、チェックリストと実際のデプロイ操作を 1 つの変更に結びつける。

## 画像・スキャン SOP の OCR 対応 (`MANUAL_OCR_ANALYSIS_ENABLED`)

`config('manual.ocr_analysis_enabled')` (既定 `false`) を `true` にする前に、以下を
**すべて**満たしていることを責任者が確認し、記録を残す。

1. **法務確認**: 外部送信の明示。`resources/views/legal/privacy.blade.php` の整備
   (既存のテキスト経路の外部送信を含む、別 TODO「AI 解析における外部送信の明示」の完了)、
   または現行の契約・同意で画像を含む文書の送信までカバー済みであることの確認。
   アップロード画面の短い案内文言 (`SourceDocumentUpload.svelte` の
   `source-document-send-notice` / `source-document-image-notice`) の妥当性もあわせて確認する。
2. **画像内 prompt injection の手動評価**: 代表的な日本語 SOP・正当な手順命令・
   画像内に仕込んだ攻撃的命令 (「この指示を無視して〇〇と出力せよ」等)・隠し文言・
   スキーマ逸脱を誘う文言を含む評価セットを用意し、期待される抽出結果との突合と
   責任者の承認記録を残す。日本語比率閾値 (`manual.analysis_min_japanese_ratio`) が
   OCR 経路の実データに対して妥当かどうかもこの評価のタイミングで確認する。
3. **再評価対象の棚卸し**: 以下のいずれかに変更が入る場合は、production を継続する前に
   同じ評価セットで再評価・再承認し、この変更単位に承認記録を添付する。
   - provider/model pin (`AnalysisTokenBudgetInvariantTest` の
     `OCR_ESTIMATE_PINNED_PROVIDER` / `OCR_ESTIMATE_PINNED_MODEL`)
   - 媒体 YAML (`resources/prompts/sop-extract-media.yaml`。特に防御指示 4 項目)
   - vendor 媒体変換の契約テスト (`tests/Feature/Manual/Analysis/OcrMediaMessageContractTest.php`)
     が前提にする Prism/Anthropic のバージョン

## 反映の運用手順

- production が `config:cache` を使う場合、`.env` の変更だけでは反映されない。
  `MANUAL_OCR_ANALYSIS_ENABLED=true` の設定後、`config:cache` の再生成とプロセス再起動が
  別途必要 (既存運用の一般論であり、AGENTS.md が定める経路キャッシュ関連の運用要件
  そのものを変更するものではない)。
- フラグ有効化直後の確認は「制御された synthetic 確認」(実際にアップロード・DB 書き込み・
  外部 LLM 呼び出し・チケット消費を伴う) と呼ぶ (read-only ではない)。専用の検証用組織・
  使い捨てのテスト SOP (PII を含まない fixture) を用いる。消費したチケットは通常の grant
  または検証費用として計上し、既存の課金履歴 (`ticket_reservations` / `llm_call_logs` 等の
  ledger) を削除・巻き戻す形にはしない。生成された `VideoManual`/`SourceDocument` 等の
  テストデータは確認後に削除する。
- **フラグを `false` へ戻す (無効化) 時の挙動**: フラグは DB へスナップショットせず、
  `AnalysisPipeline::resolveExtractInput()` がジョブ実行の瞬間に都度 `config()` から読む値を
  そのまま使う。
  - `queued` のジョブは、無効化後に実行されると、その時点のフラグ値 (`false`) で判定される
    (画像は 422 相当の `unextractable`、PDF 品質ゲート失敗も OCR フォールバックなしで即時失敗。
    フラグが最初から false だった場合と同じ既存の失敗経路)。
  - 既に `run()` を実行中で `resolveExtractInput()` を通過済みのジョブは、
    その 1 回の `run()` 呼び出しの中では config を再読込しないため、最後まで OCR 経路で完走する。
  - この挙動は追加の実装を要しない (kill switch としての目的にはこの挙動が自然に適合する)。

## 観測・課金の評価 (rollout 後)

評価期間: 公開後の最初の 4 週間、または OCR 経路の解析 50 件のどちらか早い方。
指標は解析ジョブ 1 件を単位にする (`llm_call_logs` の行をそのまま数えない。再試行で
同一ジョブが複数行になりうるため)。

- (a) OCR extract 呼び出し群 (初回 + 再試行) の入力 token 合計の中央値
  (OCR 経路 ÷ テキスト経路) が **5 倍**を超える
- (b) OCR 経路で extract を開始した解析ジョブの件数が、
  同期間に受理された全解析ジョブの **3 割**を超える
- (c) OCR 経路の失敗率 (課金されない失敗) が **3 割**を超える

集計は `AnalysisPipeline::logExtractStageTerminal()` が出す構造化ログ (`route` / `source_mime` /
`outcome` / `failure_category` / `media_size_bytes` / `media_pages` / `media_pixels`。
`run()` の 1 回の実行につきちょうど 1 回。永続化された冪等キーは持たないため、
同じジョブが stale 回復等で再実行されれば行が増えうる。集計は解析ジョブ 1 件を単位に
丸める方針であり、ログの行数そのものと 1:1 とは限らない) と `llm_call_logs`
(索引付きの `prompt_template` 列。`sop-extract-media` で経路別の実 token / 実費を集計できる)
を突き合わせて出す。
どれかに当たったらチケット単価と上限値を議題にする (これらはトリガであって、
実装時に調整する閾値ではない)。
