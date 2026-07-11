# 対応マトリクス: conceptual-review Round 2

## [Critical] GET /notifications/{notification}/open が状態変更（既読化）を行う
- 判断: 対応する
- 根拠: prefetch / クローラー / リンクプレビューによる意図しない既読化はそのとおり。
- 対応内容: `POST /notifications/{notification}/open` に変更（所有スコープ解決 → 既読化 → 303 redirect）。一覧行は Inertia の POST 操作として実装することを設計に明記。

## [Warning] notifiable + read_at の index が migration 定義から保証されない
- 判断: 対応する
- 対応内容: `(notifiable_type, notifiable_id, read_at)` の複合 index を migration に明示する旨を設計へ追記（標準 morph index の置き換え）。

## [Warning] org 名スナップショット「削除後も表示可能」と cascadeOnDelete の矛盾
- 判断: 対応する（cascadeOnDelete に統一）
- 根拠: org 削除は通知の文脈自体が消えるため通知も消すのが自然。スナップショットの目的は join 回避・org 改名・退会後表示。
- 対応内容: 主張を「改名・退会後も当時の名前で表示。org 削除時は cascade で通知ごと消える」に修正。

## [Warning] open 失敗時の back() が Referer ループ・外部 Referer に戻り得る
- 判断: 対応する
- 対応内容: 失敗時は `redirect()->route('notifications.index')->with('info', ...)` の明示 redirect に変更。

## [Warning] 「送信時に組織所属を再確認」が invitation_received と矛盾
- 判断: 対応する
- 対応内容: 受信資格を種別ごとに定義。所属再確認はジョブ通知・残高通知に限定し、招待通知は「whereBlind 一致の既存ユーザー + 有効な招待レコード作成」を受信資格として明記。

## [Warning] 「失敗の見落としがなくなる」が at-most-once と矛盾
- 判断: 対応する
- 対応内容: 期待効果を「見落としを減らし、復帰導線を短縮する（排除までは主張しない）」に修正。

## [Suggestion] 禁止事項違反なし / スコープ妥当 / 型安全性解消
- 判断: 現状維持（同意）
