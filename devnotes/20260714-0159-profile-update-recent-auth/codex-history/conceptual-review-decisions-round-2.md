# 対応マトリクス: conceptual-review Round 2

## [Warning/必須] ケース5を stale に変更（no-gate 分岐を証明可能にする）
- 判断: 対応する
- 根拠: fresh だと middleware へ委譲されても通過するため「gate されなかった」ことを証明できない。
  stale にすれば「gate をスキップ → 422」vs「誤って gate → 409/302」で分岐を固定できる。正当。
- 対応内容: ケース5を「stale + email 欠落/非 string → recent-auth 応答ではなく Validator 422」に変更。

## [Warning] stale の期待値を request 種別ごとに分離
- 判断: 対応する
- 根拠: 「409/302」一括りだと誤分岐でもテストが通り得る。
- 対応内容: テストマトリクスの遮断ケースを分離:
  - Inertia mutation (X-Inertia + 非GET): 409 + `RecentAuthRequiredResource`
  - 通常リクエスト (非 Inertia): 302 → `recent-auth.confirm` + `url.intended` 保持

## [Warning] ケース3/6 の重複 → ケース6 を「再認証後の再開」client テストへ置換
- 判断: 対応する
- 根拠: 3 と 6 が実質重複。一方で「再認証完了後に編集済み name/email を失わず再送できる」
  ことが未固定という指摘は妥当（dropped_mutation の UX 保証）。
- 対応内容: ケース6 を client テストへ置換: stale 検出 → RecentAuthModal 再認証 → 更新再開で
  編集済み name/email が保持され再送されることを検証。旧アドレス通知・email_verified_at null 化の
  回帰はケース3が担う。

## 反映先
- 概念設計「テストマトリクス（必須）」節を上記に更新。詳細設計では各ケースを Pest テスト名に落とす。
