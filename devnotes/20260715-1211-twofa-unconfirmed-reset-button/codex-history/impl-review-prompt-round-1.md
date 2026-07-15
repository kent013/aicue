# 実装レビュー依頼 (T063 / twofa-unconfirmed-reset-button / F-2-03)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

本改善は本質機能ではなく、運用者がメンバーのセキュリティ状態を誤認しない管理 UX の整合性回復。

## 禁止事項（レビュー観点）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示。DESIGN.md）

## 思考原則 — 全議論に適用

まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵（Laravel/Svelte）を探せ。機能の名前に立ち返れ。オーバーエンジニアリング禁止。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

Laravel 12 + Svelte 5 + Inertia + TypeScript のコードレビュアーとして、以下の実装差分をレビューせよ。観点:

- **設計との一致性**: detailed-design.md の施策 1/2/T1/T2 の意図どおりか
- **正確性**: enum 比較・narrowing・境界条件にバグがないか
- **PHPStan 適合性**: level 10、型 widen なし
- **DTO/JsonResource パターン**: 禁止事項 4/7 非該当か
- **テスト網羅性**: pending 非表示（フロント）・pending reset 拒否（バックエンド）が回帰込みで検証されているか
- **セキュリティ**: 新規 PII 露出・cross-org read/write を増やしていないか。defense-in-depth（UI 隠蔽 + サーバ拒否）が両輪で成立しているか
- **DESIGN.md 準拠 / Atomic Design 準拠**: 今回 DS token / component 階層の変更なし（既存条件式の変更のみ）。逸脱がないか確認

出力形式: ファイルごとに判定、Critical / Warning / Suggestion に分類。最後に全体判定 **APPROVED** または **CHANGES_REQUESTED**。

---

## user: 詳細設計書（要約）

- **施策1** `resources/js/pages/Admin/Users.svelte` `canResetTwoFactor`: 判定を `twoFactorStatus === "disabled"` で false から `twoFactorStatus !== "enabled"` で false に変更。pending も解除ボタン非表示にし、2FA バッジ（`=== "enabled"`）と表示意味論を揃える。
- **施策2** `app/Http/Controllers/Organizations/OrganizationMemberController::resetTwoFactor()`: guard を `=== TwoFactorStatus::Disabled` 拒否から `!== TwoFactorStatus::Enabled` 拒否へ一貫化（defense-in-depth）。エラー key/文言は据え置き。pending も拒否され、secret クリア・通知・監査は発火しない。
- **施策T1** vitest: 共有 fixture 不変、ローカル members 配列で pending 非表示 + enabled 対照を id-scoped testid と行スコープバッジで検証。
- **施策T2** Feature: pending メンバーの reset が `two_factor` エラーで拒否され、secret 残存・通知なし・監査 0 件を検証。

TwoFactorStatus enum は disabled/pending/enabled の 3 値。`twoFactorStatus()` は非 null enum を返す。TS union も 3 値。backend の DTO/Resource/型は変更不要（既に 3 値露出済み）。

## user: 実装差分（git diff）

```diff
diff --git a/app/Http/Controllers/Organizations/OrganizationMemberController.php b/app/Http/Controllers/Organizations/OrganizationMemberController.php
index 875d0c9..6178cc5 100644
--- a/app/Http/Controllers/Organizations/OrganizationMemberController.php
+++ b/app/Http/Controllers/Organizations/OrganizationMemberController.php
@@ -101,8 +101,11 @@ public function resetTwoFactor(
                 abort(403);
             }
 
-            // disabled は明示拒否 (冪等成功にすると監査ノイズ・誤認が増える)
-            if ($lockedUser->twoFactorStatus() === TwoFactorStatus::Disabled) {
+            // 2FA が確定 (Enabled) しているメンバーのみ解除対象。未設定 (Disabled) と
+            // 設定途中 (Pending) はともに「2FA 無効」として明示拒否する。UI (解除ボタンは
+            // enabled のみ表示) と API の意味論を揃え、未確認 secret のクリアに対して
+            // 誤解を招く監査記録・本人へのセキュリティ通知が発生するのを防ぐ (F-2-03)。
+            if ($lockedUser->twoFactorStatus() !== TwoFactorStatus::Enabled) {
                 throw ValidationException::withMessages([
                     'two_factor' => ['このメンバーは 2 段階認証を設定していません。'],
                 ]);
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index d4d77f6..40deb58 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -192,9 +192,13 @@
         });
     }
 
-    /** 2FA リセットを提示できる対象か (自分以外 + 設定済み + Admin は org Member 系のみ対象) */
+    /**
+     * 2FA リセットを提示できる対象か (自分以外 + 2FA 確定済み + Admin は org Member 系のみ対象)。
+     * pending (secret 生成済・TOTP 未確認) は 2FA 無効として扱い、解除ボタンを出さない
+     * (本人の設定画面・2FA バッジと表示意味論を揃える。F-2-03)。
+     */
     function canResetTwoFactor(member: MemberRow): boolean {
-        if (member.isSelf || member.twoFactorStatus === "disabled") {
+        if (member.isSelf || member.twoFactorStatus !== "enabled") {
             return false;
         }
         // Owner は誰でも。Admin は org Member (editor/shooter/unassigned) のみ (同格以上は不可)
diff --git a/tests/Feature/Organizations/TwoFactorEnforcementTest.php b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
index ccbe5d8..194d089 100644
--- a/tests/Feature/Organizations/TwoFactorEnforcementTest.php
+++ b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
@@ -3,6 +3,7 @@
 declare(strict_types=1);
 
 use App\Enums\OrganizationRole;
+use App\Enums\SecurityEventType;
 use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
 use App\Models\Organization;
 use App\Models\SecurityAuditEvent;
@@ -461,6 +462,32 @@ function tfeResetUrl(Organization $organization, User $member): string
         ->assertSessionHasErrors(['two_factor']);
 });
 
+test('2FA 未確認 (pending) のメンバーへのリセットも明示拒否 (validation error / 通知・監査なし)', function (): void {
+    Notification::fake();
+    [$organization, $owner] = tfeCreateOrganization();
+    $member = tfeAddMember($organization, 'pending');
+
+    $this->actingAs($owner)
+        ->withSession(['recent_auth_at' => time()])
+        ->delete(tfeResetUrl($organization, $member), ['reason' => '未確認 secret への誤操作'])
+        ->assertSessionHasErrors(['two_factor']);
+
+    // 未確認 secret は解除されず残る (冪等成功にしない)。fresh は一度だけ取得。
+    $fresh = $member->fresh();
+    expect($fresh->two_factor_secret)->not->toBeNull();
+    expect($fresh->two_factor_confirmed_at)->toBeNull();
+
+    // 拒否時は本人通知・監査イベントを発火しない (誤解を招く通知/監査の抑止を仕様固定)。
+    // event_type は enum value を使い、対象ユーザーでも絞る (enum 変更・別 fixture に強い)。
+    Notification::assertNothingSentTo($member);
+    expect(
+        SecurityAuditEvent::query()
+            ->where('event_type', SecurityEventType::OrgMemberTwoFactorReset->value)
+            ->where('user_id', $member->id)
+            ->count(),
+    )->toBe(0);
+});
+
 test('接続: 必須組織メンバーの 2FA を管理者が解除すると次のリクエストからゲートされる', function (): void {
     Notification::fake();
     [$organization, $owner] = tfeCreateOrganization(twoFactorRequired: true);
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index e5d0f77..dbe864e 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -170,6 +170,58 @@ describe("Admin/Users", () => {
         expect(screen.queryByTestId("reset-two-factor-1")).toBeNull();
     });
 
+    it("2FA 未確認 (pending) メンバーには解除ボタン・2FA バッジを出さない (owner 閲覧)", () => {
+        // viewer=owner (id=1, isSelf) を明示。対象は role=editor に固定し role 条件を満たさせる。
+        render(Users, {
+            props: {
+                ...baseProps,
+                members: [
+                    {
+                        id: 1,
+                        name: "オーナー 太郎",
+                        email: "owner@example.com",
+                        roleState: "owner",
+                        roleLabel: "管理者（オーナー）",
+                        twoFactorStatus: "enabled",
+                        isSelf: true,
+                    },
+                    {
+                        id: 2,
+                        name: "確定 花子",
+                        email: "enabled@example.com",
+                        roleState: "editor",
+                        roleLabel: "編集者",
+                        twoFactorStatus: "enabled",
+                        isSelf: false,
+                    },
+                    {
+                        id: 5,
+                        name: "設定中 五郎",
+                        email: "pending@example.com",
+                        roleState: "editor",
+                        roleLabel: "編集者",
+                        twoFactorStatus: "pending",
+                        isSelf: false,
+                    },
+                ] satisfies MemberRow[],
+            },
+        });
+
+        // enabled (id=2): 従来どおり解除ボタン表示（回帰しないことの対照）
+        expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
+        // pending (id=5): 解除ボタン非表示（本バグの修正点）
+        expect(screen.queryByTestId("reset-two-factor-5")).toBeNull();
+
+        // pending 行には 2FA バッジも出ない（バッジと解除ボタンの意味論一致）。
+        // 行スコープ: 対象メンバー固有の email から closest('li') を辿る（件数アサーションを避ける）
+        const pendingRow = screen.getByText("pending@example.com").closest("li");
+        expect(pendingRow).not.toBeNull();
+        expect(within(pendingRow as HTMLElement).queryByText("2FA")).toBeNull();
+        // enabled 行には 2FA バッジが出る（対照）
+        const enabledRow = screen.getByText("enabled@example.com").closest("li");
+        expect(within(enabledRow as HTMLElement).getByText("2FA")).toBeInTheDocument();
+    });
+
     it("admin 閲覧者は同格 (admin) の 2FA 解除ボタンを出さない", () => {
         render(Users, {
             props: {

```

## user: テスト結果

- vitest `tests/js/pages/AdminUsers.test.ts`: 19 passed
- Pest `TwoFactorEnforcement` filter: 54 passed, 199 assertions
- composer phpstan: No errors (644 files)
- vendor/bin/pint --test: passed
- pnpm typecheck / pnpm lint / pnpm build: all pass
