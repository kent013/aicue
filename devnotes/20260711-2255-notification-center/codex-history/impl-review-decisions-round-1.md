# 対応マトリクス: impl-review Round 1

Codex 判定: **Critical なし**(前段 3観点レビュー Warning の whereUuid 対応も「妥当」と確認済み)。

## [Warning] 1: notifications.organization_id のインデックス不足
- 判断: 見送る
- 根拠: AGENTS.md 思考原則 2「今必要なものだけ作る」。v1 の通知クエリは
  notifiable スコープ (`['notifiable_type','notifiable_id','read_at']` index でカバー) のみで、
  org 単位の通知集計・削除・調査クエリは存在しない。将来 org スコープのクエリを実装する
  TODO でその時に index を同 PR で追加する。
- 対応内容: なし (本記録に理由を残す)。

## [Warning] 2: findOwnOrFail 単体呼び出し時の 22P02 防護が route 依存
- 判断: 対応する
- 根拠: 3 行の防護で「存在オラクル封じ = 404」契約を service 層でも自己完結させられる。
  route の whereUuid を通らない将来経路 (CLI / 内部呼び出し) への防波堤として妥当なコスト。
- 対応内容: `NotificationCenterService::findOwnOrFail` に `Str::isUuid` ガードを追加し、
  非UUID は `ModelNotFoundException` を throw (web では 404 に変換される)。
  `NotificationCenterTest` に service 直呼びで ModelNotFoundException を確認するテストを追加。

## [Suggestion] 1: HandleInertiaRequests の未読数取得を Service 経由へ
- 判断: 見送る
- 根拠: middleware での `$user->unreadNotifications()->count()` は Laravel 標準 relation の
  1 行呼び出しで、Service 挟み込みは間接化のコストの方が大きい。挙動差異なし。

## [Suggestion] 2: NotificationListItemData::createdAt の空文字 fallback
- 判断: 見送る
- 根拠: `created_at` は DB の not-null timestamp で常に CarbonInterface。fallback は
  PHPStan 満足のための到達不能分岐であり、表示劣化の実害経路がない。

## [Suggestion] 3: payload キャストを type guard 関数へ
- 判断: 見送る
- 根拠: 現状 type + null ガードで安全と Codex 自身も認定。payload union が実際に
  拡張されるタイミングでリファクタする方が設計判断材料が揃う。

## [Suggestion] 4: 招待通知の重複ポリシー明文化
- 判断: 見送る (仕様変更なし)
- 根拠: 再招待ごとに通知が積まれるのは意図どおり (再送 = 再通知)。運用要望が
  出た時点で「期間内抑止」を検討する。detailed-design.md の追記は今回スコープ外。
