# 対応マトリクス: design-review Round 1

## 施策1

### [Critical] current_organization_id 確定が `=== null` のみだと異常状態を温存。register 責務として強制せよ
- 判断: 対応する
- 根拠: 妥当。register 経路では「招待成立 ⇒ 現在組織 = 招待先」を不変条件として強制する方が強い。
- 対応内容: 修正の配置を register 専用メソッド `acceptInvitationIfValid()` 内へ移し、`=== null` ガードを外して
  **無条件確定**にした。provision の `=== null` ガードは「ログイン中の追加組織作成でも呼ばれ既存 current を保護」
  という別前提由来であり、register 専用メソッドには持ち込まない旨を設計判断に明記。

### [Warning] acceptInvitationIfValid と forceFill が別操作。1 ユースケースに閉じよ（register 専用 service メソッド）
- 判断: 対応する（ただし新規メソッドは追加せず、既存 register 専用メソッドに畳み込む）
- 根拠: cohesion の指摘は妥当。ただし `acceptInvitationIfValid` が既に register 専用（呼び出し元 CreateNewUser のみ、
  POST 受諾は別メソッド acceptInvitation）のため、新メソッド `acceptInvitationForRegistration` を足すのは
  「今必要なものだけ作る」に反する over-engineering。既存メソッド内に「join + 現在組織確定」を畳み込めば十分。
- 対応内容: forceFill を `acceptInvitationIfValid()` の join 成功直後に配置し、「join + current 確定」を 1 メソッドに
  閉じた。CreateNewUser は変更不要（else 分岐も DI 追加も削除）。個人組織パスが provision() 内で current を据えるのと
  対称な配置になり、Action は薄いオーケストレーションに保てる。

### [Suggestion] else 節の意図・joinOrganization 非昇格は妥当
- 判断: 維持。joinOrganization（POST 共有）は不変のまま。

## 施策2

### [Critical] テストファーストの失敗点の粒度不足。各テストに現行の失敗点を明記せよ
- 判断: 対応する
- 根拠: 妥当。レビュー可能性のため赤/緑を明示すべき。
- 対応内容: 2-1（現行 current=null で赤）/ 2-2（現行 共有プロップ=null で赤）/ 2-4・2-5（現行 green の
  リグレッションガード）を各テストに「現行実装での失敗点」として追記。テスト計画に赤/緑マップを追加。

### [Warning] 2-2 が verification.notice の Inertia 実装に依存。観測点選定ルールと代替候補を定義せよ
- 判断: 対応する
- 根拠: 妥当。Fortify 差し替えで壊れ得る。
- 対応内容: 「観測点の選定ルール」（1. 未検証到達可 2. 自己修復非経由 3. Inertia 応答）を明記し、
  第一候補 verification.notice、代替候補（verified 後の非 dashboard Inertia ページ）を列挙。dashboard を避ける理由
  （自己修復で偽陰性）も明記。

### [Suggestion] 2-5 fallback にも current_organization_id assert を必須化せよ
- 判断: 対応する（必須化）
- 根拠: A/B 排他の証明として強固になる。
- 対応内容: 2-5 を「既存テストを維持」→「既存テストを強化（現在組織=個人組織 assert を必須追加）」へ変更。
