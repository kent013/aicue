# 対応マトリクス: conceptual-review Round 3

## [Critical] `EnsureLoginMethodRemains` が「削除前の状態」で数えている

- 判断: **対応する (設計の欠陥。指摘が正しい)**
- 根拠: 完全に正しい。middleware は DELETE の**前**に走るため、
  素朴に inventory を取ると**削除対象の passkey 自身**が残存手段として数えられる。
  passkey 1 件だけ・password も social も無いユーザーが、
  その唯一の passkey を削除できてしまう = **guard が意図と逆に働く**。
  機能の名前 (`EnsureLoginMethodRemains` = 「手段が**残る**ことを保証する」) に立ち返れば、
  評価すべきは現在状態ではなく「**操作が成功した後の投影状態**」である。
- 対応内容:
  1. `LoginMethodInventory` の API を「**除去対象を受け取って投影後の集合を返す**」形にする。
     `LoginMethodRemoval` (除去対象を表す型付き DTO) を導入し、
     `LoginMethodInventory::remainingAfter(User $user, LoginMethodRemoval $removal): LoginMethodSet`
     を主 API とする。passkey 件数は `whereKeyNot($target->getKey())->exists()` で評価する。
  2. `EnsureLoginMethodRemains` は必ず `remainingAfter()` を使い、
     結果が空なら操作をブロックする。
  3. 「現在の手段」を知りたい用途 (UI 表示等) は
     `remainingAfter($user, LoginMethodRemoval::none())` で表現し、API を 1 本に保つ。

## [Warning] / [Warning] `PasskeyLoginPolicy` に credential 存在確認まで持たせると責務が曖昧

- 判断: **対応する (Critical への対応と同じ根で正しい)**
- 根拠: credential 集合の評価を policy に持たせると、
  投影 (削除対象の除外) が policy 側にも必要になり責務が滲む。
  Round 2 で集約した目的は「**TOTP と feature の判定を二重定義しないこと**」であり、
  credential 件数はそこに含める必要がない。
- 対応内容: policy を**純粋な可否判定**に縮める。

  ```php
  final class PasskeyLoginPolicy
  {
      /** その User は (credential の有無と無関係に) passkey ログインを許されるか */
      public function allowsPasskeyLogin(User $user): bool;   // feature 有効 && TOTP 未 confirmed
  }
  ```

  - `LoginMethodInventory`: 「**投影後の残存 credential あり** AND policy が許可」
  - `authorizeLoginUsing`: 「検証済み credential あり (vendor 保証) AND policy が許可」

  Architecture テスト (呼び出し元固定) は**構造しか保証しない**という指摘も正しいので、
  「同一ユーザー状態で inventory の passkey 判定と login authorization が一致する」ことを
  **Feature テスト**で意味レベルでも固定する。

## [Warning] 「TOTP を有効化できる経路が password/SSO 前提」だけでは手段の現存を保証しない

- 判断: **対応する (指摘のほうが良い定式化)**
- 根拠: 有効化時点の前提を固定しても、その後手段が消えていないことは言えない。
  除去経路が限定されている本設計では、不変条件として直接固定するほうが明確で、
  将来 password 削除 / SSO 解除が追加されたときも同じテストが回帰を捕まえる。
- 対応内容: Feature テストの不変条件を次に置き換える。

  > **TOTP confirmed ユーザーは、passkey を除外しても最低 1 つのログイン可能手段を持つ。**

## [Suggestion] Response での session 汚染除去 / S3 一括化 / legacy 記述 / c2c 差し戻し

- 判断: **見送る (肯定的評価であり変更不要)**
- 根拠: いずれも「成立している」との評価。
  `toResponse()` が controller 実行後・session 保存前に評価されるという Laravel の
  lifecycle についても確認が取れた。異常系は詳細設計の担当範囲とする。
