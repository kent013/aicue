Round 1 の指摘に対応しました。再レビューし全体判定を返してください。

## [Critical] 業務不変条件の回帰テスト追加
対応: ManualDuplicateTest に振る舞いテストを追加。元 manual が status != draft・scenario_version > 0 でも複製先が必ず Draft/0、かつ元 manual の status/scenario_version が不変であることを明示検証する。

## [Warning] 共有ロック規約を transaction 単位で明文化
対応: 実装方針に「duplicate() は複製 manual の INSERT と cuts 複製を単一 DB::transaction 内で完結させる (現行実装どおり)」を明記。inventory docblock にも「新規行生成 + 同一 tx 内反映」を明記。

## [Warning] scenario_version allowlist の監査性
対応: SCENARIO_VERSION_ALLOWED の VideoManualService.php コメントに「read (displayXxxJob) + write (duplicate の初期値 0 明示代入)」の複数理由を追記。

## [Suggestion] 不変条件をコード上で読みやすく
対応: duplicate() docblock に「複製 manual は必ず Draft / version 0 から開始」を明記。定数化は見送り (オーバーエンジニアリング回避)。

これで残件は解消と考えます。APPROVED 可否を判定してください。
