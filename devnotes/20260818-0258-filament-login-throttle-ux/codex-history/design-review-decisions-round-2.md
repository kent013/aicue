# 対応マトリクス: design-review Round 2

## [Warning] MFA 専用鍵は `getKey()` ではなく `getAuthIdentifier()`

- 判断: 対応する
- 根拠: vendor の正本が `$user->getAuthIdentifier()` であり、認証識別子は主キーと同一とは限らない。
  ずれるとテストが実使用の鍵を積まず、MFA 専用経路に到達しないまま緑になる (偽緑)。
- 対応内容: テストコードを `"filament-multi-factor-challenge:{$admin->getAuthIdentifier()}"` に変更し、
  確認点の表も「認証識別子 (主キーとは限らない)」と書き直した。

## [Warning] `assertHasErrors()` では required を踏んだことを固定できない

- 判断: **一部対応・一部反論**
- 根拠: 「どのエラーでも緑になる」という指摘は妥当なのでキーまで固定する。
  ただし規則名まで書く形は、Livewire の規則名アサーションが `TestsValidation::failedRules()` =
  テスト用 store に登録された validator に依存しており、Filament の schema 検証経路が
  それを満たすかは実測しないと分からない。推測で契約を書かない。
- 対応内容: `assertHasErrors(['data.multiFactor.app.code'])` としてキーを固定し、
  「赤の実測で failed rule が取れることを確認できたら `=> 'required'` へ強化する」を
  リスク欄の手順として明記した。状態パスの根拠 (`statePath('data.multiFactor')` + 提供元 id の Group) も表に追加した。

## 施策 2 / 施策 3 (APPROVE)

- 変更なし。
