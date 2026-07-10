# 対応マトリクス: conceptual-review Round 2

## [Critical] stale 回復 cron と正常 finalize の競合（無課金 succeeded の可能性）
- 判断: 対応する
- 根拠: 指摘どおり「materialize → (cron failJob が release) → 非 Reserved でも続行 →
  succeeded 上書き」で無課金成功が成立し得た。
- 対応内容: terminal tx へ再設計。materialize・commit・succeeded を**単一トランザクション**に
  収め、冒頭で job 行を lockForUpdate + `status === running` guard（cron 先勝ちなら
  materialize もせず終了）。commit の Reserved guard 違反（LogicException）は terminal tx
  全体を rollback（materialize も巻き戻る）→ failJob。「report + 続行」は撤回・禁止。
  failJob 側も `status ∈ {queued, running}` のみ対象（terminal 状態は no-op）に明文化。

## [Warning] updated_at はハートビートではない
- 判断: 対応する
- 対応内容: 表現を「最終 step 更新時刻を stale 判定に利用」に改め、安全性の本体は
  terminal tx の job 行ロック + status guard（誤回収されても生存 pipeline は
  materialize/commit しない）であることを明記。専用カラムは追加しない（指摘どおり不要）。

## [Warning] SOP 差し替えと analyze の競合
- 判断: 対応する
- 対応内容: source-documents store も VideoManual 行を lockForUpdate() した同一 tx 内で
  状態確認 + SourceDocument 作成を行う（trigger と直列化）。trigger 側の最新 document 選択も
  同じ行ロック下で `latest('id')`（決定的順序）に固定。

## [Warning] 100,000 字は token 上限を保証しない
- 判断: 対応する
- 対応内容: token budget から導出する方式に変更: context 200,000 − 出力予約 16,000 −
  固定プロンプト 4,000 = 入力 budget 180,000 token、保守係数「1 字 = 最大 2 token」で
  上限 90,000 字 → 既定値 80,000 字。算術は config 不変条件テストで CI 固定
  （tokenizer 導入はせず保守的係数で担保、という指摘の選択肢を採用）。

## [Warning] ready→analyzing が doc §10.2 と不一致のまま
- 判断: 対応する
- 対応内容: 実装フェーズの施策に「doc/10_実装仕様.md §10.2 の更新（ready→analyzing 追加・
  失敗復帰規則）」を含め、許可遷移を状態遷移テストへ登録することを設計に明記。
