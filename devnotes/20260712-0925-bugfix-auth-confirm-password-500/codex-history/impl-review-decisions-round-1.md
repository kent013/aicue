# 対応マトリクス: impl-review Round 1

Codex 最終実装レビュー (gpt-5.3-codex / reasoning=high / one-shot) の結果:
Critical なし / Warning なし / Suggestion 2 件 / 総合判定「マージ可」。

## [Critical] なし

## [Warning] なし

## [Suggestion] GET /user/confirm-password が内部固定リダイレクトであることを明示するテスト追加

- 判断: 対応する
- 根拠: open redirect 懸念を回帰テストで機械的に否定でき、コスト極小(テスト1本)。
- 対応内容: `tests/Feature/Auth/RecentAuthTest.php` に
  「救済 redirect はクエリや url.intended に依らず固定先へ向かう」テストを追加
  (session `url.intended` と悪性クエリパラメータを与えても `recent-auth.confirm` へ 302)。

## [Suggestion] config/fortify.php の TODO(template) への相互参照追記

- 判断: 対応する
- 根拠: FortifyServiceProvider 側コメントが `config/fortify.php` の TODO を参照しているのに
  逆方向の参照が無く、将来改修時に「middleware 互換が提供済み」と誤解するリスクがあった。
- 対応内容: `config/fortify.php` の `confirmPassword => false` 直前に
  「confirmPasswordView は GET 救済 redirect のみで password.confirm middleware 互換は未提供」
  の注意書きを追記。
