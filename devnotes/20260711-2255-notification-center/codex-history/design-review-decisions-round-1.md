# 対応マトリクス: design-review Round 1

## [Critical] notifications.type に enum 値を書くのはクラス名前提との互換性差分（施策1/2）
- 判断: 対応する（提示された選択肢 B を採用）
- 根拠: `databaseType()` は Laravel の公式 API であり、クラス名を DB に置かない方が refactor 耐性・情報漏洩の面で優る。別カラム追加（選択肢 A）は type の二重管理になる。
- 対応内容: 「このアプリの database 通知は type=enum 値」を運用規約として明文化し、`tests/Architecture/InAppNotificationTypeInvariantTest.php` を新設（app/Notifications/InApp/* 全クラスが AppNotification 派生・type() ∈ NotificationType・databaseType()=enum 値を deny-by-default で固定 + DB 実発火 round-trip で DatabaseChannel 差し替えの回帰も担保）。migration コメントにも規約を明記。

## [Critical] NotificationListItemData の型矛盾（NotificationType $type と未知 type 許容）（施策6）
- 判断: 対応する
- 対応内容: `?NotificationType $type` + `string $rawType`（常に DB 値を保持）に変更。`toArray()` の type は常に rawType を返し（TS discriminant）、未知 type は payload=null → フロント fallback 描画。`isManualJob()` は type enum + payload instanceof で判定。

## [Critical] open() の manualExists 事前確認が遷移先の認可/404 責務と二重化・TOCTOU（施策6）
- 判断: 部分対応（責務分界を明確化。存在解決は維持）
- 根拠: 概念レビュー（Round 2）で「遷移先が 402/404 になり UX が途切れる」への対処として一覧へのフォールバックが要求され APPROVED 済み。単純委譲に戻すと dead-end 404 が復活し矛盾する。本リポジトリには「middleware + controller inline guard の二重防御」の確立済みパターンがあり、relation 連鎖による存在解決は認可判断の複製ではない。
- 対応内容: 設計に責務分界を明記 — open は Gate 認可を一切複製せず、(a) 自通知 organization_id と current org の突合（自分のデータ同士のルーティング判断）と (b) current org → projects() → manuals() の relation 連鎖 exists() のみを行う（既存 inline guard 層の再利用）。redirect 後の TOCTOU 残余は遷移先の標準 404 が受けることを設計に明記（唯一の認可判断点は projects.manuals.show 側）。

## [Warning] organization_id nullable がデータ品質の揺らぎ源（施策1/2）
- 判断: 対応する
- 対応内容: `AppNotification::organizationId(): int`（non-nullable）へ変更。DB 列は将来の org 非依存通知に備え nullable のままとし、「null を書く通知種別は現状存在しない」を NotificationSchemaTest で固定。

## [Warning] manual/project 削除競合時の分岐が曖昧（施策3）
- 判断: 対応する
- 対応内容: 「削除競合時は通知スキップ（例外にしない）」を仕様として明文化（コード骨子のコメント + resolveRecipientsForManualJob のテスト計画に追加）。

## [Warning] 閾値クロス判定が balance() の Reserved 控除仕様に脆い（施策5）
- 判断: 対応する
- 対応内容: `effectiveBalanceBeforeCommit(int $balanceAfter, TicketReservation $locked): int` を導入し意図を doc コメントで固定。テスト名に「Reserved 拘束を含む実効残高」を含めることを明記。

## [Warning] readAll 連打の UX ノイズ（施策6/7）
- 判断: 対応する（提案どおりサーバは現状維持・フロントで送信ガード）
- 対応内容: ハンドラ内 in-flight guard（disabled 属性ではない）を Index.svelte の設計に追記。

## [Suggestion] unreadCount の partial reload 運用を README/doc に追記
- 判断: 対応する — docs/architecture.md への運用注記を設計に追加（only:['notifications'] ポーリング拡張）。

## [Suggestion] TS 同期テストは既存ヘルパの再利用
- 判断: 対応する — ManualEnumTsSyncInvariantTest の抽出ヘルパを共有 helper に抽出して両テストで使う方針を明記。

## [Suggestion] jobRecipients の責務指向な命名
- 判断: 対応する — `resolveRecipientsForManualJob` に改名。
