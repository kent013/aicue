# 対応マトリクス: design-review Round 2

## [Suggestion] 施策0: predicate は復号後インメモリ比較であり DB blind-index 規則の単一出典ではない
- 判断: 対応する (表現の正確化)
- 対応内容: 「復号後インメモリ宛先比較の単一出典」と明記。DB 検索 (scopeActivePendingForEmail) との同値は施策 5 T5 が固定すると追記。

## [Critical] 施策1: $user->fresh() は非ロック SELECT で MVCC 版がロック値と一致しない
- 判断: 対応する
- 根拠: 正しい。ロック読みした値で最終照合すべき。
- 対応内容: joinOrganization で `$lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail()` を使い、それで照合。先例 OrganizationMemberController::resetTwoFactor L95 / EnsureLoginMethodRemains L67。lockForMembershipWrite が既に users 行をロック済みのため同一行の再取得は no-op re-acquire (ロック順序不変)。

## [Warning] 施策1: joinOrganization 全呼び出し元が 3 経路である根拠を目録化
- 判断: 対応する (既存テストを根拠に明示)
- 対応内容: MembershipWriteLockInventoryTest が acceptInvitation/acceptInvitationIfValid/acceptPendingInvitation → joinOrganization( の委譲と bool 戻り値消費 drift-guard を既に機械強制していることを根拠として記載。

## (新規波及) 施策1: 新しい User 主キーロック取得は ModelDirectFetchInvariantTest 検出対象
- 判断: 対応する
- 対応内容: DirectFetchInventory.php に internalCaller 登録を追加する旨を波及に明記。先例 EnsureLoginMethodRemains#User.whereKey。

## [Critical] 施策5: T4b が早期照合で落ちて再照合を検証しない恐れ
- 判断: 対応する
- 対応内容: stale model 手順に確定。宛先 email でロード → 保存値だけ別 email に変更 → stale インスタンスを渡す → 早期照合は古い値で通過 → ロック読みの最新値で最終拒否、を 6 手順で明記。

## [Warning] 施策5: T1 prefill の所在 / T2 current_org 不変
- 判断: 対応する
- 対応内容: T1 に tests/Feature/Auth/RegistrationInvitationPrefillTest.php を名指し。T2 に「受諾前に別組織を current にし受諾後も維持」assertion を追加 (既存 L74 契約)。

## [Warning] 施策6: 403/404 が未確定 (テナント境界404 と認可403 の区別)
- 判断: 対応する (実測で確定)
- 対応内容: 使い捨てテストで実測し表で確定 — 自然除名 (current=null): projects/billing/manage=404 (層2)、stale: 403 (層3)、未割当: 全 403 (層3)、dashboard は current 未解決時 200 (no-org 設定)。層2=404/層3=403 の不変条件として固定。曖昧な assertStatusIn は使わない。

## [Warning] 施策6: T8 全 route 403 と リスク節の projects/billing 差異が矛盾
- 判断: 対応する
- 対応内容: 未割当は current=対象組織のため全 route 403 で統一。リスク節も上表に合わせ矛盾を解消。

## [Warning] 施策8: T10 の 422 は Inertia 契約とずれる
- 判断: 対応する
- 対応内容: T10 を「302 redirect back + assertSessionHasErrors('role') + pivot 不変」に修正。422 は期待しない。

## [Warning] 施策8: T9c の error source
- 判断: 対応する (確認して確定)
- 対応内容: Admin/Users.svelte L120-128 で roleMessage は page.props.errors から派生 (router.patch 直呼びのため useForm ではない) と確認。T9c は page.props.errors.role に注入して検証すると明記。

## [Warning] 施策8: 施策一覧「施策5と同ファイル」が本文の ConsoleRoleTransitionTest と不一致
- 判断: 対応する
- 対応内容: 施策一覧を tests/Feature/Organization/ConsoleRoleTransitionTest.php に訂正。
