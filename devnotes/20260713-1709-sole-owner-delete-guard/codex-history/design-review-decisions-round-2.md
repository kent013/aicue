# 対応マトリクス: design-review Round 2

## 施策2 [Critical] deleteAccount のロック順 (User→列挙→Org の厳密化)
- 判断: 対応する
- 根拠: 妥当。列挙を user ロック前に行うと、列挙〜user ロック取得の間に新組織 B の Owner を
  削除対象 user へ移譲され、B を未検査で削除する race が残る。
- 対応内容: 2 段のロック呼び出しに分割。
  1. `lockForMembershipWrite([(int)$user->getKey()], [])` で **User 行を最初にロック**。
  2. user ロック下で所属組織を列挙 → `lockForMembershipWrite([], $organizationIds)` で Org 行をロック。
  3. `$freshUser = $user->fresh()` → 述語再評価。
  厳密順序 `User lock → 所属再取得 → Organization lock → 再評価` を満たす。メンバー追加/移譲経路も
  user 行をロックするため、user ロック下の列挙は安定する。

## 施策6 [Warning] SettingsPageProps extends SharedProps の errors 継承衝突
- 判断: 反論（対応: 根拠を明記）
- 根拠: `resources/js/lib/shared-props.ts` の `SharedProps` を確認。`errors` フィールドは
  **存在しない** (appName/auth/organizations/currentOrganization/flash/notifications/title のみ)。
  Inertia は errors を別途注入するため、`SettingsPageProps` に `errors?: Record<string, string|string[]>`
  を追加しても継承衝突しない。`Omit` は不要。
- 対応内容: 設計にこの確認結果 (SharedProps に errors 無し) をコメントとして明記。

## その他施策 (1,3,4,5,7,8) APPROVE
- 判断: 変更なし
