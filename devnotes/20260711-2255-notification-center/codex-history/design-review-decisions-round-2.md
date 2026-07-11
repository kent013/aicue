# 対応マトリクス: design-review Round 2

## [Critical] 通知は exactly-once にならない（commit 後〜notify 間の停止で永久欠落）（施策4）
- 判断: 対応する（提示された二択のうち「欠落許容をプロダクト仕様として明記」を採用。outbox は不採用）
- 根拠: 正はジョブ status + 既存ポーリング UI であり通知は補助チャネル（概念設計 APPROVED 済みの合意）。欠落窓は terminal commit 直後〜insert の数 ms のみ（worker のジョブ実行中停止は recoverStale → failJob で失敗通知が発火する）。この窓のために outbox テーブル + 再配送 cron + 掃除を持ち込むのは「今必要なものだけ作る」原則に反する。
- 対応内容: 施策4に「配信保証仕様（プロダクト仕様として確定）」節を新設。at-most-once（重複なし・欠落あり得る）を明記し、「通知の exactly-once」は主張しない。「二重通知なし」の根拠（terminal 遷移 bool ゲート）と欠落許容の根拠、outbox 見送りの判断・将来の移行条件を docs へ記録する旨を明文化。

## [Critical] effectiveBalanceBeforeCommit の算術が balance() 定義と不整合（commit ではクロスが発生しない）（施策5）
- 判断: 対応する（指摘のとおり。修正案どおりクロス検知を reserve へ移動）
- 根拠: balance() = 有効台帳 − Reserved 拘束。実効残高が減る唯一の消費イベントは reserve であり、Reserved→Committed の commit は拘束と台帳が相殺して balance() 不変。commit 基準の判定は複数 pending 予約で誤通知になる（指摘の反例のとおり）。
- 対応内容: フックを `TicketLedgerService::reserve()`（org 行ロック下・不足チェックで算出済みの $balance を再利用）へ移動。`$balance >= threshold && $balance - $amount < threshold` のクロスで `DB::afterCommit` 通知。effectiveBalanceBeforeCommit は削除。通知が示す値 =「Reserved 拘束を含む実効残高」・release 回復→再クロスで再通知、をセマンティクスとして明記。commit()/release() は無変更。

## [Warning] 複数 pending 予約・commit 順入れ替え・release のテストが無い（施策8）
- 判断: 対応する
- 対応内容: TicketBalanceLowNotificationTest に追加 — 台帳10・閾値5・予約4×2（2件目の reserve でのみ通知、commit 順入れ替えで追加通知なし）/ release 回復→再クロスで再通知 / 並行 reserve で計 1 件 / rollback 非発火。

## [Warning] 未知 type の open() が招待文言に落ちる（施策6）
- 判断: 対応する
- 対応内容: `InvitationReceived` を明示分岐し、default（未知 type）は「この通知には開ける対象がありません。」の汎用文言へ。テスト計画にも未知 type 分岐を追加。
