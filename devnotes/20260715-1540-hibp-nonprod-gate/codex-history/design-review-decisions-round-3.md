# 対応マトリクス: design-review Round 3 (全体判定 APPROVED)

## 施策1 / 施策2 / 施策3: APPROVE
- fail-secure・責務分離・テスト隔離すべて承認。

## [Suggestion] 施策2: 設計書末尾に旧記述 `withAppEnv()` / 「false へ復帰」が残存
- 判断: 対応する
- 対応内容: 施策2 の PHPStan/テスト計画/リスク節の `withAppEnv()` を `withPasswordPolicyAppEnv()` に、「false へ復帰」を「元の値へ復元」に修正。実装方針への影響なし。

## [Suggestion] 施策3: リセット POST も含むなら Feature 追加 or 保証範囲を明記
- 判断: 対応する(保証範囲を明記)
- 根拠: リセット/変更/管理者作成はすべて `PasswordPolicy::rule()` の同一 SSOT を通り、述語の全 env matrix は施策2 の Unit テストが横断固定する。Feature を各経路に増やすのは冗長(禁止事項6 / 今必要なものだけ作る)。
- 対応内容: 施策3 に「保証範囲」節を追加。Feature は代表経路(登録 POST)1 本、述語横断保証は施策2 Unit に委譲する旨を明記。リセット/変更固有の HTTP 副作用が生じた場合のみ将来 Feature 追加(スコープ外)。
