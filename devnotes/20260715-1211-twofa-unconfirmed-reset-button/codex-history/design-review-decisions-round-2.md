# 対応マトリクス: design-review Round 2

全体判定: APPROVED。Critical/Warning はすべて解消。残りは Suggestion のみ。

## [Suggestion] T1: `closest("li")` は DOM 構造変更に弱い。行 testid があれば優先
- 判断: 現案維持（+ 注記）
- 根拠: 現行 `<li>` に行 testid はない。testid 新設は production コードのテスト専用改変。
  reset ボタンの id-scoped testid が主検証で、バッジ検証は補助のため `closest("li")` で十分。
- 対応内容: 設計に「行構造を変えるなら行 testid を検討」の注記を残す（現案承認可）。

## [Suggestion] T2: 監査 event_type を文字列直書きでなく enum value にし、org/user で絞る
- 判断: 対応する（cheap robustness）
- 根拠: enum 変更・別 fixture 影響に強くなる。
- 対応内容: T2 の検索を `SecurityEventType::OrgMemberTwoFactorReset->value` + `user_id` 絞りに変更。

## [Suggestion] T2: `fresh()` を一度だけ取得して変数保持
- 判断: 対応する
- 根拠: 意図明確化。
- 対応内容: `$fresh = $member->fresh();` を一度取得して secret / confirmed_at を検証。

## [Suggestion] 運用周知の追記先ファイルを実装時に具体化
- 判断: 対応する（実装時）
- 根拠: コード変更と同一成果物に含めるべき。
- 対応内容: 実装フェーズで追記先（リリースノート/運用手順ドキュメント）を確定する旨を明記。
