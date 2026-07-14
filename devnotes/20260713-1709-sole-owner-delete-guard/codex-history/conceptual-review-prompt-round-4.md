Round 3 の [Critical] への対応です。全体判定を再度お願いします。

## 対応 (Round 3 Critical: 未列挙組織への並行 Owner 移譲 race)

**対応する**。canonical ロック順序を **`users` 行 (id 昇順) → `organizations` 行 (id 昇順)** の 2 段に拡張しました。

- 共有 helper を `lockForMembershipWrite(array $userIds, array $organizationIds)` とし、両者を id 昇順で `lockForUpdate`（デッドロック回避）。
- `deleteAccount(U)`: **対象 User 行 U を先にロック** → U の所属組織を列挙 → 該当組織行を昇順ロック → ロック内で述語（唯一 Owner かつ他メンバー有り）を再評価 → 非空なら `ValidationException(['account' => ...])`、空なら記録+削除。
- `transferOwnership(from, to)`: from/to の 2 User 行 + 組織行をロック。よって「B の Owner を U へ移譲」する txn は U の User 行で `deleteAccount(U)` と直列化する。
  - transfer 先行 → deleteAccount の列挙が B を含む → B を blocker と判定して削除拒否。
  - deleteAccount 先行 → U 削除後、transfer は to=U が存在せず失敗。
- Owner をユーザーへ付与する唯一の経路は `transferOwnership`（Owner 昇格は transferOwnership のみが正規経路）なので、User 行の直列化で「未列挙組織への Owner 付与」race は構造的に消える。
- 他の mutating メソッド（`joinOrganization`・`changeRole`・`removeMember`）も同 helper で自身が触れる user/org をロックし規約を統一。

## テスト方針（Round 3 Warning: 並行テスト追加）

**構造担保 + drift-guard で対応**（真の race テストは反論）。テストは本番同等 PostgreSQL で走るが、`RefreshDatabase` がテストを単一トランザクションで包むため、複数コネクションの race を決定的に再現するのは現行ハーネスの範囲外（既存 `transferOwnership` のロックも race テストでなく構造で担保）。よって:
- 並行正当性 = canonical 順序の `lockForUpdate`（構造）で担保。
- drift-guard Architecture テストが `OrganizationMembershipService` の mutating public メソッドのロック規約適用漏れを検出。
- Feature テストは論理述語（唯一Owner+他メンバー→拒否／自分のみ→許可／複数Owner→許可／非Owner非対象／Inertia 表示）を検証。

これで残存 [Critical] は解消と考えます。判定をお願いします。
