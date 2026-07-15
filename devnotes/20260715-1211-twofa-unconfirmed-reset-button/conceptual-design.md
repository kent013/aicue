# 概念設計: twofa-unconfirmed-reset-button

bug-hunt run 20260715-084108 F-2-03 (Medium, H10)

## 背景・課題

組織メンバー管理画面 (`manage.users` / `resources/js/pages/Admin/Users.svelte`) で、
TOTP の QR/secret を生成しただけで **TOTP 確認を完了していない** (`two_factor_confirmed_at`
が null = `pending`) メンバーに対して「2FA 解除」ボタンが表示される。

これによりオーナー/管理者は、そのメンバーが 2FA を **有効化済み** と誤認する。
一方でメンバー本人の設定画面は「無効」と正しく表示しており、**管理画面と本人画面で
2FA 状態の見え方が食い違う**。

### 原因の所在 (調査済み)

- **バックエンドは確定状態を既に露出している。**
  `App\Models\User::twoFactorStatus()` は Fortify の 2 カラム
  (`two_factor_secret` / `two_factor_confirmed_at`) から 3 値
  (`disabled` / `pending` / `enabled`) を導出する状態機械
  (`App\Enums\TwoFactorStatus`)。`App\DataTransferObjects\Admin\MemberRowData`
  はこの 3 値を `twoFactorStatus` として Inertia props に載せており、TS 側
  `resources/js/types/admin.ts` の `MemberRow.twoFactorStatus` も
  `"disabled" | "pending" | "enabled"` を受けている。
  → **props に confirmed 状態は既に含まれている。Resource/DTO/Controller の
    追加露出は不要** (brief の「最初にやること」の懸念は既に満たされている)。

- **バグはフロントの判定条件にある。**
  `Users.svelte` の `canResetTwoFactor()` は
  `member.twoFactorStatus === "disabled"` のときだけ false を返す。
  `pending` はこの early-return を通り抜け、以降の role 条件を満たせば
  ボタンが表示される。すなわち **`pending` を `enabled` と同一に扱っている** のが直接原因。
  なお 2FA バッジ (`Users.svelte` L276) は `=== "enabled"` でのみ表示され正しい。
  → バッジと解除ボタンで pending の扱いが不一致。

- **サーバ側 guard も pending を通す (副次)。**
  `App\Http\Controllers\Organizations\OrganizationMemberController` の 2FA リセット
  経路は `twoFactorStatus() === TwoFactorStatus::Disabled` のときだけ拒否し、
  `pending` は素通しで `$disableTwoFactorAuthentication()` を実行する。
  結果、未確認 secret のクリアに対して「2 段階認証を解除しました」という
  **誤解を招く監査記録 + 本人へのセキュリティ通知** が発生し得る
  (ボタン非表示後は直 API 経由でのみ到達)。

## 改善アイデア

**「2FA が確定 (`enabled`) しているメンバーにのみ『2FA 解除』ボタンを表示する。
未確認 (`pending`) は 2FA 無効として扱い、解除ボタンを出さない。」**

1. **(主) フロント表示条件の修正**: `canResetTwoFactor()` を
   「`enabled` のときだけ true」に変える。`pending` / `disabled` はともに false。
   これでボタン表示・2FA バッジ・本人設定画面の 3 者が「pending = 2FA 無効扱い」で一致する。

2. **(副・防御) サーバ guard の一貫化**: リセット経路の拒否条件を
   「`=== Disabled` のとき拒否」から「`!== Enabled` のとき拒否」へ広げ、
   `pending` も明示拒否する。UI と API の意味論を揃え、未確認メンバーに対する
   誤解を招く監査記録/セキュリティ通知を防ぐ (defense-in-depth)。

**バックエンドの props 露出 (Resource/DTO/Controller) は変更しない** (既に十分)。

## 期待効果

- 使命への貢献 (間接): **本質機能 (動画作成フロー) の拡張ではなく**、現場運用者
  (オーナー/管理者) が **メンバーのセキュリティ状態を誤認しない** という管理 UX の
  整合性回復。「思考ゼロ」で任せられる管理画面の信頼性を支える周辺品質。
- 確実な効果 (施策 1 のみでも達成): 管理画面 (バッジ/解除ボタン) と本人設定画面、
  および API 意味論が「pending = 2FA 無効扱い」で一致する。
- 追加効果 (施策 1 + 2 の両方を入れた場合のみ達成): 未確認メンバーに対する誤った
  「2FA 解除」操作と、それに伴う誤解を招く監査ログ/セキュリティ通知が発生しなくなる。
  ※ フロント修正 (施策 1) だけでは直 API 経由の誤操作は残るため、この効果には
  サーバ guard (施策 2) が前提。

## 実装方針（概要）

- `resources/js/pages/Admin/Users.svelte`: `canResetTwoFactor()` の判定を
  `member.twoFactorStatus !== "enabled"` で早期 false にする (isSelf / role 境界は現状維持)。
- `app/Http/Controllers/Organizations/OrganizationMemberController.php`:
  拒否 guard を `!== TwoFactorStatus::Enabled` に広げる (エラーメッセージ・
  `ValidationException` の key は現状の `two_factor` を踏襲)。
- テスト:
  - **(必須) vitest** (`tests/js/pages/AdminUsers.test.ts`): `pending` メンバーに解除
    ボタンが出ない / `enabled` メンバーには出る、を追加。回帰防止の本命。
  - **(必須) Feature** (2FA リセット経路): `pending` メンバーへのリセットが拒否される
    ことを追加 (施策 2)。回帰防止の本命。
  - (条件付き) Feature (`tests/Feature/Admin/UserManagementPageTest.php`): 未確認メンバーの
    props `twoFactorStatus` が `'pending'` で返ることの確認は、既存カバレッジが薄い場合に
    のみ追加 (既存仕様の再確認に留まるため優先度は低)。
  - 必要なら `database/factories/UserFactory.php` に pending 状態
    (secret あり・confirmed_at なし) の factory state を追加。

## 制約・前提

- v1 スコープ (doc/10) を尊重。単一 Default Project、PWA、セッション認証。
- PII/tenant 境界: 本画面は `manageMembers` 権限者のみ到達 (403 境界)。
  今回は既存 props の解釈変更のみで、新たな PII 露出・cross-org read/write は増やさない。
- DTO + JsonResource / Inertia パターン維持。`response()->json()` 直書きなし。
  操作系 POST/DELETE の拒否応答は `ValidationException` / `back()->with(...)` で完結
  (禁止事項 7)。
- PHPStan level 10 / Pest / DS token / Atomic Design の既存規約を維持。
- 後方互換の並走を残さない (旧判定を残さず置換)。
- **ドメインの 3 値 (`TwoFactorStatus`) は維持する。** 変えるのは UI 表示と API guard の
  「解除ボタン/解除許可の対象を enabled のみに絞る」という解釈だけで、pending を disabled へ
  畳み込む状態機械の改変はしない。表示上は disabled と同一扱いだが、ドメイン上は
  未設定 (disabled) と設定途中 (pending) の区別を保つ。

## スコープ外

- 2FA 状態の props 表現そのもの (3 値 enum) の変更。既に正しいので触らない。
- 本人の 2FA 設定フロー (Fortify) の変更。
- pending 状態の可視化 (例: 「設定中」バッジの追加) — 今回は「無効として扱う」に留め、
  新 UI 要素は増やさない (オーバーエンジニアリング回避)。必要なら別施策。
- backend の Resource/DTO/Controller への confirmed フラグ追加 (不要と判明)。
