# 対応マトリクス: design-review Round 2

## [Critical] syncWithoutDetaching は並行トランザクションに対する原子的 upsert ではない
- 判断: 対応する（提示 3 案のうち「招待行ロック」+「原子的 INSERT」の併用を採用）
- 根拠: 招待行ロックだけでは「別招待経由の同一 user × 同一 org の並行 join」を直列化できず、insertOrIgnore だけでは同一招待の accepted_at TOCTOU が残る。両方で全 race を閉じる。
- 対応内容: `joinOrganization` を改訂。
  1. tx 冒頭で招待行を `lockForUpdate()` 取得し、受諾可能状態（未受諾・未失効）をロック下で再検証（敗者は冪等 no-op）
  2. org 参加は `DB::table('organization_user')->insertOrIgnore(...)`（(organization_id, user_id) UNIQUE を利用した原子的 INSERT。affected rows = 0 なら join 済みとして role/pivot をスキップ）。値はすべてサーバ側 relation 解決済みモデル由来で保護キー規約に整合。スキーマ（timestamps のみの pivot）は migration で確認済み
  3. テスト計画を「ロック下再検証 no-op」「既 attach で unique 違反にならず role/pivot 不変」の逐次契約検証へ書き換え（真の並行実行は並列 DB テストで flaky のため、原子性は DB 保証に委ねる旨を明記）

## [Warning] derive() は orgRole === null を project pivot 判定より先に評価せよ
- 判断: 対応する
- 根拠: 指摘どおり。org ロールなし + stale pivot が Editor/Shooter と誤表示され修復契約と矛盾する。
- 対応内容: match の先頭に `$orgRole === null => self::Unassigned` を追加（Codex 提示のコードをそのまま採用）。phpdoc に評価順の理由を明記。テスト計画の「null×pivot 有無 → Unassigned」ケースが本順序を固定する。
