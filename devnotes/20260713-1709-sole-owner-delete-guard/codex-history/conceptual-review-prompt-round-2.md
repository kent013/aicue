Round 1 の指摘への対応です。対応マトリクスと修正後の概念設計を提示します。全体判定を再度お願いします。

## 対応マトリクス (Round 1)

- [Critical] TOCTOU/直列化 → **対応**。判定と削除を service `deleteAccount(User)` の同一 `DB::transaction` に閉じ、対象組織の `organization_user` 行を `lockForUpdate` してロック内で述語を再評価してから `$user->delete()`（`transferOwnership` と同方式）。完全直列化のため `changeRole`/`removeMember` の最終 Owner 再チェックも共有 private helper で同じ行ロック下に置く。
- [Warning] UI 失敗表示 → **対応**。サーバーは固定キー `errors.account` で返し、DangerZone が常時描画（禁止事項8 の「押下後に理由が見える」を満たす）。
- [Warning] 効果の言い切り → **対応**。「自己削除フロー起因の新規 Owner 不在組織の発生を防止」に狭めた。
- [Warning] props スナップショット → **対応**。UI 文言で「削除時にサーバーが再判定・最終権威」を明示。
- [Warning] テスト観点未明示 → **対応**。5 観点をスコープに明記（拒否/自分のみ許可/複数Owner許可/非Owner非対象/Inertia表示）。
- [Suggestion] service に判定+例外+削除を集約 / Collection を配列 shape に変換 → **対応**。

## 修正後の概念設計（差分の要点）

### 改善アイデア
1. サーバー側ブロック（権威）: 唯一 Owner かつ他メンバー有りの組織が 1 つでもあれば `ValidationException`(`errors.account`) で拒否。判定と `$user->delete()` を同一トランザクション + `lockForUpdate` で直列化（`transferOwnership` と同方式）。UI 迂回でも守られる = 最終権威。
2. 事前警告・移譲導線（UX）: `/settings` に該当組織一覧(name+slug)を props で渡し DangerZone に警告 + 各組織 settings への移譲リンク。一覧はスナップショットであり UI 文言で「削除時にサーバーが再判定する」と明示。ブロック時の `errors.account` を DangerZone に常時描画。

### 判定述語
ユーザーが Owner かつ 他 Owner 無し かつ 他に1人以上メンバーが残る組織。個人組織のように唯一メンバーなら許可（is_personal を特別扱いせずメンバー数で一様判定）。

### 実装方針
- Service(判定): `organizationsBlockingDeletion(User): Collection<Organization>`（UI props 用・読み取り専用）。private `hasAnotherOwner` 再利用。
- Service(削除): `deleteAccount(User): void`。同一 `DB::transaction` 内で対象組織の `organization_user` 行を `lockForUpdate`→述語再評価→非空なら `ValidationException(errors.account)`／空なら `$user->delete()`。`changeRole`/`removeMember` の最終 Owner 再チェックも共有 private helper で同じ行ロック下へ。
- Controller: `AccountController::destroy` は `deleteAccount` を呼ぶだけ（ブロック時は例外伝播で `Auth::logout()` に到達しない）。監査記録 `AccountDeleted` はトランザクション内・削除直前。
- Route/Props: `GET /settings` closure にサービス注入し `soleOwnedOrganizations`(`list<array{name:string,slug:string}>`) を渡す。
- UI: DangerZone に警告 + 移譲導線。削除ボタンは disabled にしない。

### 期待効果
自己削除フロー起因の新規 Owner 不在組織の発生を防止（既存破損・別経路は対象外）。既存 Owner 保護不変条件と挙動を対称化。削除前に移譲を提示し詰みを回避。

### テスト観点（スコープ内）
1. 唯一 Owner + 他メンバー有り → 拒否(`errors.account`)・ユーザー残存 2. 唯一 Owner + 自分のみ(個人組織含む) → 許可 3. 複数 Owner のみ → 許可 4. 非 Owner 所属組織は blocker 非対象 5. Inertia: 非空で警告+移譲リンク表示・削除ボタン非 disabled・拒否時 `errors.account` 表示。
