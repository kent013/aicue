# 対応マトリクス: conceptual-review Round 1

全体判定: APPROVED (Round 1)。Warning 3 件は全て対応し、概念設計へ反映した。

## [Warning] 「password.confirm middleware 互換まで得られる」含みの過剰な期待効果 (観点1)
- 判断: 対応する
- 根拠: 指摘どおり本修正は GET view の救済 redirect であり、`auth.password_confirmed_at` を
  要求する `password.confirm` middleware の互換は提供しない。誤認は将来のセキュリティ設計を
  歪めるリスクがある。
- 対応内容: 期待効果を「直アクセス/既存リンク由来の 500 解消 + 正規画面への誘導」に限定し、
  「効果に含めないこと」として middleware 非互換を明記。middleware 互換が必要になった場合は
  別タスク (config/fortify.php の既存 TODO(template) と同じ棚) とした。

## [Warning] 「SSO-only ユーザーも詰まない」の根拠不足 (観点4)
- 判断: 対応する
- 根拠: 誘導先画面が再SSO satisfier を提示する事実は既存実装・既存テストで保証されているため、
  設計書に根拠を参照として明記すべき。
- 対応内容: 期待効果に `ConfirmRecentAuthController::show()` の props
  (passwordSet/availableProviders/canSatisfy) と既存テスト
  (RecentAuthTest「confirm 画面は passwordSet / availableProviders / canSatisfy を返す」) への
  参照を追記。再現テストにも「302 先が recent-auth.confirm で、追従後 200 で
  Auth/ConfirmRecentAuth が表示される」ことを含める (詳細設計のテスト計画に反映)。

## [Warning] 将来の実装者が shim を recent-auth の別名と誤認するリスク (観点5)
- 判断: 対応する
- 根拠: コメントで意図を固定しないと、`password.confirm` middleware を app 側ルートに
  付ける実装が「もう保護されている」と誤認され得る。
- 対応内容: 実装方針 2 に「GET view の救済 redirect であり、`password.confirm` middleware 互換
  (`auth.password_confirmed_at` の充足) は提供しない」旨をコード内コメントとして明記する
  ことを追加。詳細設計の変更後コードのコメントにもこの文言を入れる。

## [Suggestion] 各種 (North Star 接続の絞り込み / 前提の明文化 等)
- 判断: 対応する (上記 Warning 対応に包含)
- 根拠: いずれも軽微な文言整理であり、Warning 対応の編集で同時に満たされる。
- 対応内容: 期待効果の North Star 接続を「再認証導線で詰み画面を出さない」に絞った。
