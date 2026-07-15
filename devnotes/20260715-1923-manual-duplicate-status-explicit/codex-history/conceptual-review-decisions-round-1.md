# 対応マトリクス: conceptual-review Round 1 (item2)

## [Critical] 業務不変条件の回帰テスト不足 (静的 inventory だけでは値を守れない)
- 判断: 対応
- 対応内容: `duplicate()` の振る舞いテストを ManualDuplicateTest に追加。元 manual が
  status != draft・scenario_version > 0 でも複製先が必ず Draft/0 になること、かつ
  元 manual の status/scenario_version が不変であることを明示検証する。

## [Warning] 共有ロック規約との整合を transaction 単位で明文化
- 判断: 対応
- 対応内容: 実装方針に「duplicate() は複製 manual の INSERT と cuts 複製を同一 DB::transaction で
  完結させる (現行実装どおり)」を明記。inventory docblock にも「新規行生成 + 同一 tx 内反映」を明記。

## [Warning] scenario_version allowlist 既存流用の監査性低下
- 判断: 対応
- 対応内容: `SCENARIO_VERSION_ALLOWED` の VideoManualService.php コメントに
  「read (displayXxxJob) + write (duplicate の初期値 0 明示代入)」の複数理由を追記する。

## [Suggestion] 不変条件をコード上で読みやすく
- 判断: 対応 (docblock)
- 対応内容: duplicate() docblock に「複製 manual は必ず Draft / version 0 から開始」を明記。
  定数化までは広げない (オーバーエンジニアリング回避)。
