Round 2 の指摘への対応です。全体判定を再度お願いします。

## 対応マトリクス (Round 2)

- [Critical] pivot 行ロックでは phantom を防げない / 組織行を共通ロック基点に統一 → **対応**。`organizations` 親行を共通ロック境界にする。メンバーシップ書き込みは `OrganizationMembershipService` の唯一の窓口に集約済みなので、共有 private helper `lockOrganizations(Organization ...$orgs)`（`organizations` 行を **id 昇順**で `lockForUpdate`、デッドロック回避）を 1 ファイル内に導入し、owner 数/メンバー数を変える全メソッド（`joinOrganization`・`changeRole`(+`applyConsoleRole`/`normalizeOrganizationRole`)・`removeMember`・`transferOwnership`・新規 `deleteAccount`）が txn 冒頭で呼ぶ。`transferOwnership` の既存 pivot ロックも同基点へ寄せる（挙動不変）。deleteAccount は対象組織を昇順ロック後にロック内で述語再評価。
- [Warning] 不変条件の Architecture テスト無し → **対応**。`OrganizationMembershipService` の public メソッドを reflection 列挙し、mutating メソッドがロック対象 inventory に未登録なら fail する drift-guard Architecture テストを追加。
- [Warning] pivot ロックのままでは効果を保証できない → **対応**（上記で解消）。
- [Warning] AccountDeleted の txn 内発行 → **反論**。`SecurityEventRecorder::record` は `security_audit_events` への純 DB insert のみで Laravel event/外部副作用 dispatch を持たない（best-effort・例外は report で握り潰す）。txn 内記録ならロールバック時に監査行も巻き戻り「削除していないのに deleted 記録」を防げて正しい。かつ user_id は nullOnDelete で削除時に null 化され既存テスト期待（user_id null で残存）とも整合。したがって削除と同一 txn 内・削除直前で問題ない。
- [Warning] logout/session invalidate/regenerate が消えている → **対応**（明記漏れ）。`deleteAccount` 成功後に Controller で `Auth::logout()`→`session()->invalidate()`→`session()->regenerateToken()`→`redirect()->route('home')` の順（現行順序保持）。ブロック時は例外伝播で未到達。
- [Warning] errors.account 表現が曖昧 → **対応**。PHP `ValidationException::withMessages(['account' => '先に…移譲…'])`、Svelte `$page.props.errors.account`。props は `list<array{name:string,slug:string}>`。

## 判定述語（再掲）
ブロック = ユーザーが Owner かつ 他 Owner 無し かつ 他に1人以上メンバーが残る組織。個人組織のように唯一メンバーなら許可（is_personal を特別扱いせずメンバー数で一様判定）。

## 補足: スコープ判断
`removeMember`（Owner は削除不可＝owner 数を減らさない）と `transferOwnership`（owner 数を1に維持）は単体でも ≥1 Owner を保つが、共通ロック基点の一貫性（deleteAccount の allow 判定と直列化）のため同 helper に統一する。個人組織の全員招待や既存破損組織の修復はスコープ外のまま。
