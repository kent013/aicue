# 対応マトリクス: impl-review Round 1

Codex 最終 impl-review (gpt-5.3-codex / reasoning=high / one-shot) の判定は **APPROVED**。
Critical / Warning はゼロ。Suggestion 2 件の判断を以下に記録する。

## [Suggestion] wantsJson 時の 202 契約テストを FortifyResponseTest に追加
- 判断: 対応する
- 根拠: 低コスト (テスト 1 本) で `VerificationNotificationSentResponse` の JSON 分岐
  (Fortify 既定互換: wantsJson / 202) の設計意図をテストで固定でき、誤変更
  (expectsJson 化・ステータス変更) を検出できる。禁止事項 1 (不変条件はテスト登録まで
  含めて実装済み) の趣旨にも沿う。
- 対応内容: `tests/Feature/Auth/FortifyResponseTest.php` に
  「認証メール再送は JSON リクエストに 202 を返す (Fortify 既定互換)」を追加。
  別ユーザーで 1 リクエストのみ発行し throttle:6,1 に構造的に触れない。

## [Suggestion] logout() の onError 時トースト表示を将来タスク化
- 判断: 見送る (将来タスク候補として記録のみ)
- 根拠: Codex 自身が「今回 diff の範囲では必須ではない」と明記。T013 のスコープは
  F-03/F-06/F-08/F-14 の修正であり、ログアウト失敗時のフィードバック強化は
  新規の UX 施策 (エラーフィードバックの一貫性) に該当する。思考原則 2
  (今必要なものだけ作る) に従い本 TODO には含めない。必要になれば bug-hunt /
  app-design フローで別 TODO として起票する。
