# 対応マトリクス: design-review Round 3

Round 3 は施策 0,1,2,3,4,6,7,8 が APPROVE。残る REQUEST_CHANGES は施策 5 の T4b のみ + 文言整合。

## [Critical] 施策5 T4b: 一括 update は CipherSweet/モデルイベントを迂回 + Service 直呼びで redirect/flash は起きない
- 判断: 対応する
- 根拠: 正しい。(1) `whereKey()->update(['email'=>...])` は暗号化を迂回し PII 不変条件違反・復号失敗テストに化ける。(2) Service 直呼びは Controller を通らないため redirect/flash は発生しない。(3) HTTP 経由だと認証ユーザーが DB 再解決され stale モデルを渡せない。
- 対応内容: T4b を「Service 直呼び Feature テスト」に確定。email 変更は別インスタンスの通常 save (CipherSweet 経由)。stale インスタンスが早期 predicate を通ること・最新保存値が不一致であることを明示 assert。acceptInvitation を直接呼び ValidationException(token) を確認。redirect/flash は期待しない。membership/role/project/accepted_at/current_org の DB 不変を確認。一括 update 案は削除。

## [Suggestion] 施策0: T5 は DB 検索の同値まで検証しない
- 判断: 対応する (主張を狭める)
- 対応内容: 施策 0 の記述を「T5 が固定するのはインメモリ経路どうしの同値。DB 検索側の case-sensitive fail-secure は既存 PendingInvitationScopeTest の範囲に留め、全数同値は主張しない」に修正。

## 文言整合 (施策1/2/5)
- 判断: 対応する
- 対応内容: 「fresh 再取得したユーザー」→「ロック読みしたユーザー ($lockedUser)」、PHPStan チェックの `$user->fresh()`→`$lockedUser`、「デッドライン」→「デッドロック」、施策2 の `canAccept => false`→`recipientEmailMatches => false`、T4b 末尾の「fresh なロック値」→「ロック読みの最新値」、既存テスト setup 説明の canAccept→recipientEmailMatches。

## [Suggestion] 施策1: helper が lockedUser を返せば重複クエリを避けられる
- 判断: 見送る (現状で正確性は問題なしと Codex も明記)
- 根拠: lockForMembershipWrite の戻り値変更は全 call-site 波及。重複 SELECT は同一ロック行の再取得 (軽微)。今必要な変更ではない。将来の最適化余地としてのみ記録。

## [Suggestion] 施策6: 使い捨てテストを残さない
- 判断: 対応済み
- 根拠: 事前検証・コード確定に使った使い捨て Pest はすべて削除済み。正式な回帰テストは施策 6 の MemberRemovalAccessTest に新規作成する。
