# 対応マトリクス: conceptual-review Round 1

## [Critical] 根本原因の説明が一段足りず、same-request の shared prop 不整合を説明できていない
- 判断: 対応する（説明を精緻化）
- 根拠: 指摘は妥当。ただし観測は「同一リクエスト内の矛盾」ではなく、**ページ（リクエスト）ごとに現在組織の
  解決経路が違う**ことで生じる。dashboard は `CurrentOrganizationResolver` の自己修復を通し（heal UPDATE →
  refresh）招待先組織（共有残高 10）を描画する一方、dashboard 以外・自己修復前のヘッダーは
  `current_organization_id` の生読み（null）で「未所属」表示になる。two 症状は別ページ観測。
- 対応内容: 「根本原因（コード確定）」を書き換え、**一次原因（登録経路で current_organization_id を確定しない）
  + 二次条件（共有プロップが dashboard-only 自己修復に依存）**の 2 段構成に明記。一次原因を入口で塞げば
  自己修復非依存で全ページ一貫、と補正。テスト観点にも「登録直後の認証済みリクエストの共有プロップ
  currentOrganization に招待先組織が反映されること」を追加（DB 値＋共有プロップの両観測点）。

## [Warning] CreateNewUser 直書きだと不変条件が分散。register 限定の理由を明記せよ
- 判断: 対応する（理由を明文化）
- 根拠: 妥当。POST 受諾経路（acceptInvitation）は現在組織を切り替えない契約を持つため、joinOrganization 共通契約へ
  昇格させると副作用切替になる。
- 対応内容: 「実装方針」に、register 経路限定の理由（POST 受諾での意図しない現在組織切替を招かない／
  provision の null ガード初回確定と同位置づけ）を明記。

## [Warning] テスト計画の排他・網羅の定義が曖昧（token なし / token あり無効 / 不一致の扱い）
- 判断: 対応する（分岐タクソノミーを明記）
- 根拠: 妥当。MatchesInvitationEmail rule の挙動を精査した結果、分岐は 2 つ（成立=join / 通常=personal+grant）で
  閉じ、email 不一致は 422 拒否（分岐外）と確定できる。
- 対応内容: 「登録経路の分岐タクソノミー（排他・網羅）」表を追加。A: 招待成立、B: 通常/フォールバック
  （token なし＋token 無効/不在/失効/受諾済/取消/既メンバー race）、拒否ケース（token 有効 + email 不一致 → 422）
  を明記。テストは A 代表 + B 代表 2 系（token なし・token 無効）を固定。

## [Warning] North Star への貢献表現がやや大きい
- 判断: 対応する（表現を抑制）
- 根拠: 妥当。本質改善（教材設計・撮影ナビ）ではなく、オンボーディング整合・導線の詰み解消。
- 対応内容: 「期待効果」を「招待メンバーの初期オンボーディング整合性回復・導線の詰み除去（入口整備の範囲）」へ修正。

## [Suggestion] 禁止事項・セキュリティ不変条件との整合は良好（forceFill/保護キー）
- 判断: 維持（変更不要）。current_organization_id を forceFill で明示代入する方針を継続。

## [Suggestion] HandleInertiaRequests を変えない判断は妥当
- 判断: 維持。入口（登録完了）で確定する方針を継続。スコープ外に「共有プロップ毎リクエスト自己修復」を明記済み。

## [Suggestion] 型安全性は低リスク。$joined の nullable 分岐を明確に保て
- 判断: 維持。詳細設計で $joined（?Organization）の分岐と Assert/forceFill の型を明示する。
