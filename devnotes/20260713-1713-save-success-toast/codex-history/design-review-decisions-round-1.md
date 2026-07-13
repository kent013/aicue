# 対応マトリクス: design-review Round 1

全体判定: **CHANGES_REQUESTED**（施策3 に Warning 2 件。他は APPROVE + Suggestion）。

## [Warning] 施策3: `trans($this->status)` の戻り型が array|string 推論で PHPStan Lv10 不安定
- 判断: **対応する**
- 根拠: `trans()`/`__()` は key に配列を渡すと array を返しうるため、level 10 で `array|string` 推論になり
  `JsonResponse` の message に入れると型エラー化するリスク。明示 string 化が確実。
- 対応内容: JSON 分岐を `new JsonResponse(['message' => (string) __($this->status)], 200)` に修正。
  詳細設計の施策3 変更後コードと PHPStan チェックを更新。

## [Warning] 施策3: redirect が `config('fortify.views', true)` を考慮せず API 専用構成で login 未定義リスク
- 判断: **対応する**
- 根拠: Fortify 既定式に完全準拠する方が将来の views 無効構成でも安全。差分を作らない。
- 対応内容: `redirect(Fortify::redirects('password-reset', config('fortify.views', true) ? route('login') : null))`
  に修正（既定式と同等）。詳細設計を更新。

## [Suggestion] 施策5: reset JSON を `assertJsonPath('message', __('passwords.reset'))` まで固定
- 判断: **対応する**
- 根拠: JSON 契約をより厳密に固定でき低コスト。
- 対応内容: テスト計画に `assertJsonPath('message', __('passwords.reset'))` を追記。

## [Suggestion] 施策5: 無効/期限切れ token の失敗系 非回帰テストを追加
- 判断: **対応する**
- 根拠: reset の token 検証が施策で触れる周辺。非回帰1ケースで安心。
- 対応内容: 「無効 token の reset は errors を返し success flash を出さない」ケースを施策5 に追加。

## [Suggestion] 施策1: expectsJson vs wantsJson の意図を1行補足
- 判断: 対応する（既存 note を明確化）
- 根拠: 将来保守性。
- 対応内容: 施策1 note に「AJAX 判定を厳密化する意図」を明記。

## [Suggestion] 施策6: 呼び出し総数 / error 引数精査も加える
- 判断: 対応する（テスト計画に追記）
- 根拠: 退行検知強化。低コスト。
- 対応内容: happy path で `addToastMock` が success で呼ばれた回数 0 を明示 assert する旨を追記。
