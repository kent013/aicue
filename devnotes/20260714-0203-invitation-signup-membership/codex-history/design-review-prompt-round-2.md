# 詳細設計レビュー Round 2

Round 1 の指摘（施策1: Critical 1 / Warning 1、施策2: Critical 1 / Warning 1 / Suggestion 1）に対し、
詳細設計を改訂しました。対応マトリクスと改訂後の該当箇所を提示します。再レビューをお願いします
（各施策の判定 + 全体判定、残 Critical/Warning）。

## 対応マトリクス（要約）

### 施策1
- [Critical] `=== null` ガードのみで異常状態を温存 → **無条件確定**へ変更。配置を register 専用メソッド
  `OrganizationMembershipService::acceptInvitationIfValid()` 内（join 成功直後）へ移し、
  「招待成立 ⇒ 現在組織 = 招待先」を register 責務として強制。
- [Warning] join と current 確定が別操作で整合が崩れ得る → **同一 register 専用メソッドに畳み込み**、
  「join + 現在組織確定」を 1 ユースケースに閉じた。**新規メソッド追加はしない**（既存 register 専用メソッドで
  十分。新抽象は over-engineering）。CreateNewUser は変更不要（else 分岐も DI 追加も不要に）。個人組織パスが
  provision() 内で current を据えるのと対称配置。共通コア joinOrganization（POST 受諾と共有）は不変。

### 施策2
- [Critical] テストファーストの失敗点の粒度不足 → 各テストに「現行実装での失敗点」を明記。赤/緑マップを追加
  （2-1/2-2 = 現行赤→施策1 で緑、2-4/2-5 = 現行緑のリグレッションガード）。
- [Warning] 2-2 が verification.notice 依存 → 「観測点の選定ルール」（未検証到達可 / 自己修復非経由 / Inertia）と
  代替候補を明記。dashboard を避ける理由（自己修復で偽陰性）も記載。
- [Suggestion] 2-5 の current assert → **必須化**（A/B 排他の直接固定）。

## 改訂後コード（施策1 の核心）

`OrganizationMembershipService::acceptInvitationIfValid()` の join 成功直後・`return $organization;` 直前:
```php
$this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

// [register 経路限定] 参加した招待組織をこの新規ユーザーの「現在組織」として確定する。
// 本メソッドは register 専用 (呼び出し元 CreateNewUser のみ。POST 受諾は acceptInvitation)。
// 個人組織パスが provision() 内で current を据えるのと対称に「join + 現在組織確定」を 1 ユースケースに閉じる。
// user は登録直後で現在組織未確定のため招待先を無条件確定。current_organization_id は保護キーのため
// サーバ導出値を forceFill で明示代入 (tenant キー不信)。
$user->forceFill(['current_organization_id' => $organization->id])->save();

return $organization;
```
- `$organization` は直前の `Assert::isInstanceOf($organization, Organization::class)` で narrowing 済 → `->id` は int。
- メソッドシグネチャ `acceptInvitationIfValid(string, User): ?Organization` は不変（副作用追加のみ）。
- `CreateNewUser.php` は無変更（招待成立時 `$joined !== null` は現状のまま何もしない = 現在組織確定はサービス側）。

## 改訂後の観測点ルール（施策2 の 2-2）

1. 未検証ユーザーが到達できる / 2. `CurrentOrganizationResolver::resolve()` を経由しない（dashboard 以外。
自己修復を挟むと修正なしでも緑になる偽陰性）/ 3. Inertia 応答で共有プロップを載せる。
→ 第一候補 `verification.notice`（`Auth/VerifyEmail`）。代替 = verified 後の非 dashboard Inertia ページ。

## 赤/緑マップ（施策2）

- 2-1（招待成立で current=招待先）: 現行 **赤**（current=null）→ 施策1 で緑。
- 2-2（共有プロップ currentOrganization=招待先、自己修復非経由）: 現行 **赤**（prop=null）→ 施策1 で緑。
- 2-4（通常登録で current=個人組織）: 現行 **緑**（provision が確定）= 分岐 B 非波及のガード。
- 2-5（無効 token fallback で current=個人組織、必須追加）: 現行 **緑** = 分岐 A 修正の非波及ガード。

---

上記改訂で施策1・施策2 の Critical / Warning が解消されているか、残課題があれば指摘してください。
（詳細設計書の全文は Round 1 と同一ファイルを更新済み。差分の要点は上記のとおり）
