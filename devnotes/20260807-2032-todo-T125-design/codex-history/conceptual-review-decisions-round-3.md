# 対応マトリクス: conceptual-review Round 3

Codex の全体判定は **APPROVED**。[Critical] / [Warning] は 0 件。
[Suggestion] のうち文言・型実装に関する 3 点を、詳細設計へ持ち込む前に概念設計へ反映した。

## [Suggestion] 「認証面のアクセスログ量」は throttle で 48/min に制限されない
- 判断: **対応する**
- 根拠: Web サーバのアクセスログには 429 も記録されるため、
  「アクセスログ量」を throttle の天井で語るのは不正確。制限されるのは
  **throttle を通過した後**に生成される処理量・ログである。
- 対応内容: コスト軸表の行名を
  「throttle 通過後に生成されるアプリケーション処理量・ログ」に変更。

## [Suggestion] 「状態変更の上限が 48/min」→「状態変更を試行できる受理リクエストの上限」
- 判断: **対応する**
- 根拠: 48/min は受理リクエスト数の上限であり、成功する状態遷移の数ではない
  （その差を作るのは事前条件・一回性であって throttle ではない、と本文で既に区別している）。
  副作用の説明文だけが古い表現のままだった。
- 対応内容: 該当箇所を「状態変更を**試行できる受理リクエスト**の上限が 48/min に上がることに
  付随するもの」に修正。

## [Suggestion] 旧レーン名 `password-credential` の残存
- 判断: **対応する**
- 根拠: Round 2 で `password-verify` / `password-set` へ分割した際の追随漏れ。
- 対応内容: gate 説明中の「描画のたびに飛ぶ GET を `password-credential` に足す」を
  `password-verify` に修正。

## [Suggestion] `is_scalar()` は `bool` / `float` も通す
- 判断: **対応する**
- 根拠: `getAuthIdentifier()` の契約は `int|string|null` であり、
  `is_scalar()` では `true` が `:user:1` へ潰れる。契約と実装を一致させるべき。
- 対応内容: §制約・前提の型の閉じ方を
  「`is_int()` / `is_string()` で明示的に絞り込む（既存 `passkeys` は `is_scalar()` を使うが
  helper 側では採らない）」に変更。詳細設計 S1 でこの方針を実装し、
  負のコントロール（`bool` / `float` で user 分岐へ落ちない）を Unit テストに置いた。

## [Suggestion] lane enum 化への反論は受け入れ / 6 レーンの粒度・スコープは妥当
- 判断: **対応不要**
- 対応内容: なし（現行方針を維持）。
