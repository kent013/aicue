施策 D の 6 Warning に対応し詳細設計を修正しました。A/B/C は APPROVE 済みとして扱います。施策 D の再評価をお願いします。

## 対応サマリー

- [Warning] 未契約+非管理者の回帰不足 → テスト #6「未契約 + manageBilling 非保持 → onboarding.billing-required」を新規追加。8 行の着地境界表 (状態 × manageBilling → 期待) を明示し、#4 (ActiveFreePlan+非保持→dashboard) と #6 の境界を固定。

- [Warning] continuation の状態曖昧 → continuation テストを ActiveFreePlan(free_plan_code=personal) に固定。paid Subscribed 非管理者は直接アクセス #2 で担保。continuation は verification→onboarding.checkout→dashboard の入口接続確認に限定 (全状態×全入口の直積は作らない)。

- [Warning] 既存 owner continuation テストの過大主張 → 主張を「continuation 第一段 redirect + session 消去の回帰防止」に狭め、owner の最終分岐着地は直接アクセス #1/#3 で担保すると明記。

- [Warning] dashboard 描画テストの不具体 → 実 component 名 'Dashboard' (DashboardController の `Inertia::render('Dashboard')` / 既存 `DashboardTest.php` line 56 と一致) を明記。302 確認 → 別 GET → `assertOk()` + `assertInertia(component 'Dashboard')` の 3 段に分離。業務導線の存在自体は不変条件にせず「課金ゲートに阻まれず 200 で開く = soft dead-end でない」のみ固定。

- [Warning] 完了条件の検証コマンド不足 → AGENTS.md の全検証コマンド (composer test / composer phpstan / vendor/bin/pint --test / pnpm lint / pnpm typecheck / pnpm test / pnpm build / pnpm typecheck:packages / pnpm build:packages / pnpm test:packages) を完了条件に追加。テストファースト赤の記録、A/B/C の本ブランチ実行緑の記録も追加。

- [Warning] screens.md の不整合 → `.claude/skills/app-bug-hunt/screens.md` を施策一覧の変更ファイルに追加し、挙動変更と同一 PR で「契約済みは billing.index へ」を「manageBilling 保持者→billing.index / 非保持者→dashboard」に更新すると明記。

## 修正後の詳細設計 (施策 D 以降を中心に全文)

# 詳細設計: bughunt-needs-review

> **Codex 合議の状態**: 概念設計は Codex (`gpt-5.6-terra`) 合議で **APPROVED** (Round 3)。
> 詳細設計も Codex (`gpt-5.6-sol`) 合議を実施する。Codex が使えない場合の代替方針は末尾に記載。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び (窓口 PromptDefense 経由の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`) / **RefreshDatabase** + `--parallel` グローバル適用 (個別 `DatabaseTransactions` 禁止)
- テストデータは必ず **Factory** で生成
- **DTO + JsonResource** パターン / **アーリーリターン** 推奨
- `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260821-1520-bughunt-needs-review/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | F-3-02: 同 token・別プラン 422 の既存カバレッジ確認・報告 (変更なし) | — | Medium |
| B | S6-1: メール変更→旧アドレス通知の既存カバレッジ確認・報告 (変更なし) | — | Medium |
| C | S6-2: パスワード未設定ユーザー初回設定 正常系の既存カバレッジ確認・報告 (変更なし) | — | Medium |
| D | Q-2-01: `manageBilling` 非保持メンバーの onboarding 入口着地を dashboard に寄せる | `OnboardingController.php` + `OnboardingCheckoutTest.php` + `RegisterVerifyFlowTest.php` + `.claude/skills/app-bug-hunt/screens.md` | Medium |

---

## 施策 A: F-3-02 既存カバレッジ確認 (コード変更なし)

### 結論

同 token・別プラン → 422 のガードは実装済み、Feature テストも存在する。**追加不要**。

### 根拠 (証跡ファイル)

- 実装: `app/Services/Billing/SubscriptionService.php` `startCheckout()` → `startCheckoutLocked()` が
  `SubscriptionAttemptPlanMismatchException` を throw (L365 の docblock: 「同 token・別 plan の再送 (Controller が 422)」)。
- Controller: `app/Http/Controllers/Billing/BillingController.php` L285 が同例外を catch し
  `plan_code` バリデーションエラー (422) へ写像。
- 既存テスト: `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php`
  `test('同一 token + 別 plan_code は 422 で、行も Stripe 呼び出しも増えない')`。
  `/billing/checkout` 経由で `assertInvalid(['plan_code'])`、`BillingCheckoutSession` 行 1 本のまま、
  `FakeStripeGateway->created` 0 増加を固定。

### テスト計画

新規テストなし。上記既存テストが同 token・別 plan → 422 の HTTP 経路の不変条件を固定している
(課金 checkout 全体の完全性を主張するものではない)。**禁止事項 1 非該当** (新規実装がない)。

### リスク

なし (変更しない)。

---

## 施策 B: S6-1 既存カバレッジ確認 (コード変更なし)

### 結論

メール変更時に旧アドレスへ `EmailChangedSecurityNotification` を送るのが確定仕様 (Q11 決定)。
実装済み、action 層 + HTTP 層の Feature テストも存在する。**追加不要**。

### 根拠 (証跡ファイル)

- 仕様: `app/Actions/Fortify/UpdateUserProfileInformation.php` docblock「旧アドレスへセキュリティ通知を送る」+
  実装 `Notification::route('mail', $oldEmail)->notify(new EmailChangedSecurityNotification)`。
- action 層テスト: `tests/Feature/Auth/EmailChangeTest.php`
  `test('メール変更時に旧アドレスへセキュリティ通知が送られ再検証が要求される')`
  (`Notification::assertSentTo` で宛先 = 旧アドレスを固定)。
- HTTP 層テスト: `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php`
  `test('3: fresh + email 変更は成功し旧アドレス通知 + 再検証要求')`。
  実 route `PUT /user/profile-information` (bug-hunt が指した `user-profile-information.update`) 経由。

### テスト計画

新規テストなし。bug-hunt は `mail-urls` が署名 URL 抽出のみで本文/宛先を確認できなかっただけで、
通知経路とそのテストは健在。

### リスク

なし (変更しない)。

---

## 施策 C: S6-2 既存カバレッジ確認 (コード変更なし)

### 結論

パスワード未設定 (SSO/passkey のみ) ユーザーの初回パスワード設定 正常系は、`ssoOnly()` Factory を
使う Feature テストで固定済み。**追加不要**。

### 根拠 (証跡ファイル)

- 実装: `app/Http/Controllers/Settings/PasswordSetupController.php` (`POST /settings/password`、
  recent-auth 必須、設定済みは fail-closed 422)。
- Factory: `database/factories/UserFactory.php` `ssoOnly()` (パスワード未設定ユーザー)。
- 既存テスト: `tests/Feature/Settings/PasswordSetupTest.php`
  `test('password 未設定 + recent-auth fresh なら設定できる')` が正常系を固定
  (設定成功 → `hasPassword()` true、監査イベント `password_set` 1 件、他デバイス失効、再訪一貫性まで網羅)。

### テスト計画

新規テストなし。bughunt 環境に seed ユーザーが無く browser 未検証だったが、Factory と正常系
Feature テストは存在する。

### リスク

なし (変更しない)。

---

## 施策 D: Q-2-01 `manageBilling` 非保持メンバーの onboarding 入口着地を dashboard に寄せる

### 変更箇所

- ファイル: `app/Http/Controllers/Onboarding/OnboardingController.php` `show()` (L52-77 付近)

### 波及変更

- TypeScript 型定義: **なし** (Inertia Props / API 応答の形は変えない。redirect 先を変えるだけ)。
- API Resource / DTO: **なし** (新規 DTO なし。既存 `OnboardingCheckoutDto` 等は不変)。
- テストファイル:
  - `tests/Feature/Onboarding/OnboardingCheckoutTest.php`
    (既存「Subscribed の non-manager member → billing.index」テストの期待更新 + ActiveFreePlan
    非管理メンバーの新規ケース)。
  - `tests/Feature/Auth/RegisterVerifyFlowTest.php` (continuation 経由の非管理メンバー着地 = dashboard)。
- ドキュメント: `.claude/skills/app-bug-hunt/screens.md` の「課金ゲート着地」節 (L143-146) の
  「契約済みは billing.index へ」を「契約済みは manageBilling 保持者→billing.index / 非保持者→dashboard」に
  更新する。**挙動変更と同じ変更 (同一 PR) で目録を更新する** (Codex obs: 追跡だけでは弱い)。
  この screens.md は施策一覧の変更ファイルにも含める (下記施策一覧参照)。

### 現行コード

`OnboardingController::show()` の該当分岐 (現状):

```php
// 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
// billing-required に飛ばないよう、先に契約状態を判定する。
if ($this->access->hasActiveAccess($organization)) {
    return new RedirectResponse(route('billing.index'));
}

// 未契約 + manageBilling 権限なし → billing-required へ
if (! Gate::allows('manageBilling', $organization)) {
    return new RedirectResponse(route('onboarding.billing-required'));
}
```

- `$organization` は直前で `resolveMemberCurrentOrganization($request)` により解決済み
  (非メンバー / 不在は認可より前に 404)。`Gate::authorize('view', $organization)` 済み。
- 既契約でない経路で既に `Gate::allows('manageBilling', $organization)` を organization-scoped で呼んでいる。

### 変更後コード

```php
// 契約済み (Subscribed / ActiveFreePlan) の入口着地。
// - manageBilling 能力保持者: 請求管理が正当な着地なので billing.index (現状維持)。
// - 非保持メンバー (現場作業者): 自分で操作できない請求画面ではなく、業務入口 dashboard へ
//   (Q-2-01。North Star: 現場作業者を最小摩擦で仕事へ着地させる)。
if ($this->access->hasActiveAccess($organization)) {
    return new RedirectResponse(
        Gate::allows('manageBilling', $organization)
            ? route('billing.index')
            : route('dashboard'),
    );
}

// 未契約 + manageBilling 権限なし → billing-required へ
if (! Gate::allows('manageBilling', $organization)) {
    return new RedirectResponse(route('onboarding.billing-required'));
}
```

- `dashboard` は `RequireActiveSubscription` を通らない課金ゲート外の route (`routes/web.php` L194)。
  `DashboardController` は「未契約でも状況把握と復帰導線を提供」する。非管理メンバーでも 200 で描画され、
  プロジェクト/撮影への業務導線を持つ = 作業者の業務入口として機能する (soft dead-end にしない)。
- 判定順序 (hasActiveAccess → manageBilling) は不変。既契約でない経路 (billing-required / checkout render /
  支払い未解決 → billing.index) は 1 行も変えない。
- docblock の「契約済みは billing.index へ」記述を「契約済みは manageBilling 保持者→billing.index /
  非保持者→dashboard」に更新する。

### PHPStan 適合チェック

- [x] 戻り値の型は既存どおり `Response|RedirectResponse` (変更なし)。
- [x] null 安全: `$organization` は `resolveMemberCurrentOrganization` で非 null 確定 (既存 Assert)。
      新たな nullable 値を導入しない。
- [x] DTO を返す経路ではない (redirect 分岐)。`response()->json()` は使わない (禁止事項 4 非該当)。
- [x] `Gate::allows('manageBilling', $organization)` は既存呼び出しと同一形式 (新 role 文字列なし)。
- [x] 新規 generics なし。

### テスト計画 (Pest、テストファースト — 赤を確認してから実装)

**直接アクセス側で状態分岐を網羅し、continuation 側は代表的な非管理者経路を一本通す** (全状態 × 全入口の
直積は作らない。Codex obs 対応)。着地先境界の完全表:

| # | 状態 | manageBilling | `/onboarding/checkout` 直接アクセスの期待 | 種別 |
|---|---|:--:|---|---|
| 1 | Subscribed (paid) | あり | `billing.index` | 既存・維持確認 |
| 2 | Subscribed (paid) | なし | `dashboard` | **既存テストの期待更新** |
| 3 | ActiveFreePlan (`free_plan_code=personal`) | あり | `billing.index` | 既存・維持確認 |
| 4 | ActiveFreePlan (`free_plan_code=personal`) | なし | `dashboard` | **新規** |
| 5 | 未契約 (`plan_code IS NULL`) | あり | checkout 200 描画 | 既存・維持確認 |
| 6 | 未契約 | なし | `onboarding.billing-required` | **新規 (境界回帰)** |
| 7 | 支払い未解決 | あり | `billing.index` | 既存・維持確認 |
| 8 | 支払い未解決 | なし | `onboarding.billing-required` | 既存・維持確認 |

`tests/Feature/Onboarding/OnboardingCheckoutTest.php`:

- **#2 [期待更新]** 既存 `test('Subscribed の non-manager member は billing-required ではなく billing.index へ ...')`
  の期待を `assertRedirect(route('dashboard'))` に更新し、名前を
  「Subscribed の non-manager member は billing.index ではなく dashboard へ (Q-2-01)」に改名。
  **削除ではなく期待の更新** (意図的な仕様変更の反映。禁止事項 3 非該当)。
- **#4 [新規]** `test('ActiveFreePlan + manageBilling 非保持 member は dashboard へ (Q-2-01 の既契約=Personal free ケース)')`
  — bug-hunt が観測した実シナリオ。`free_plan_code='personal'` の org に `attachOrganizationMember`、
  `current_organization_id` を設定、`/onboarding/checkout` GET → `assertRedirect(route('dashboard'))`。
- **#6 [新規・境界回帰]** `test('未契約 + manageBilling 非保持 member は billing-required へ (dashboard には行かない)')`
  — 未契約 (grandfatherFreePlan: false, `plan_code`/`free_plan_code` とも null) の org に非管理メンバー、
  `/onboarding/checkout` GET → `assertRedirect(route('onboarding.billing-required'))`。
  **#4 と最も取り違えやすい境界** (hasActiveAccess の有無で dashboard か billing-required かが分かれる) を固定。
- **#1/#3/#5/#7/#8 [維持確認]** 既存テストが不変で緑を維持すること (manageBilling 保持者・未契約 owner・
  支払い未解決の各着地は変えない = 変更範囲が「active access を持つ非管理者だけ」であることの証明)。
  #8 (支払い未解決 + 非 manager → billing-required) が既存に無ければ**新規追加**する。

- **[着地の実効性]** `test('dashboard 着地は 302 の先で実際に Dashboard 画面が 200 描画される')`
  — 段を分けて確認 (障害箇所の切り分け):
  1. #4 と同条件で `/onboarding/checkout` が `route('dashboard')` へ 302。
  2. 同一認証ユーザーで `->get(route('dashboard'))` を GET。
  3. `->assertOk()` かつ `->assertInertia(fn ($page) => $page->component('Dashboard'))`
     (component 名は `DashboardController` の `Inertia::render('Dashboard', ...)` / 既存 `DashboardTest.php` と一致)。
  - 「業務導線が存在する」ことまでを不変条件にはしない (Codex obs: component 確認だけでは業務導線の存在は
    保証しない)。ここでは「非管理メンバーでも Dashboard 画面が課金ゲートに阻まれず 200 で開く」= soft
    dead-end でないことのみを固定する。導線露出の設計はスコープ外。

`tests/Feature/Auth/RegisterVerifyFlowTest.php` (continuation 入口 — 接続確認 1 本):

- **[新規]** `test('continuation: ActiveFreePlan の非管理メンバーは verify 完了後 onboarding.checkout 経由で dashboard に着地する (Q-2-01)')`
  — 状態は **ActiveFreePlan (`free_plan_code=personal`) に固定** (bug-hunt 再現優先。paid Subscribed の
  非管理者は上の直接アクセス #2 で担保するため continuation では作らない)。既契約 org の非管理メンバー
  (unverified) を用意し `current_organization_id` を設定、session に `verify_continue_organization_id`=当該 org id。
  署名付き `verification.verify` を踏むと `VerifyEmailResponse` → `onboarding.checkout` へ redirect し、
  それを follow すると最終的に `route('dashboard')` に着地することを固定 (verification → onboarding.checkout →
  dashboard の**入口接続の確認**に限定)。
- **[既存テストの主張の修正]** 既存 `test('verify 完了で onboarding.checkout へ redirect し continuation が消える')`
  (owner=personal org) は **verify 完了の第一段 redirect (onboarding.checkout) と continuation session の消去**を
  固定するもので、**最終着地 (billing.index) までは保証しない**。本設計ではこのテストの主張を
  「continuation の第一段 + session 消去の回帰防止」と正しく位置づけ、owner の最終分岐着地は
  直接アクセス側 (#1/#3) で担保する (Codex obs 対応: 過大な保証主張をしない)。

- テストは Factory (`createOrganizationWithOwner` / `attachOrganizationMember` / `contractPaidPlan` /
  `User::factory()->unverified()`) で生成。`Model::create()` 手組みはしない。
- 個別 `DatabaseTransactions` は使わない (`tests/Pest.php` の `RefreshDatabase` グローバル適用のまま)。
- **テストファースト**: まず #2・#4・continuation を先に書き、**現状 (billing.index 着地) で赤になること**を
  実行して記録してから実装する。#6 も実装前に赤を確認する。

### リスク

- **既存挙動の置換**: `OnboardingController::show` は「onboarding.checkout の離脱ガード」も兼ねる。
  非管理メンバーが直接 onboarding.checkout に来た場合も dashboard へ変わるが、これは意図どおり
  (非管理メンバーに billing/checkout を見せる意味がない)。回帰は上記テスト 2/4/5/6 で押さえる。
- **dashboard → billing の導線**: 今回 billing 閲覧設計は変えない。dashboard 上の請求導線露出で混乱が
  残るなら別 finding として切り出す (スコープ外。概念設計に記載済み)。
- **manageBilling gate のコンテキスト誤り**: 既存呼び出しと同一形式・同一 organization を使うため
  権限境界は変わらない。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は controller 1 分岐 + テスト追加/更新のみ。新モデル・migration・広域リファクタなし。他施策 A/B/C はコード変更なし。競合面が小さく incremental で十分。 |
| 競合リスク | 低。`OnboardingController` / `OnboardingCheckoutTest` / `RegisterVerifyFlowTest` に閉じる。他グループ (F-2-02/F-2-03 招待・メンバー削除、F-1-02 撮影 PWA) とファイル重複なし。 |

## 乖離台帳 (template-divergence) の確認

- 変更対象 (`OnboardingController.php` / `OnboardingCheckoutTest.php` / `RegisterVerifyFlowTest.php`) は
  いずれも `docs/template-fingerprints.json` のキーに**存在しない** (アプリ固有の Onboarding ドメイン)。
  → 乖離台帳への登録追加・`LedgerPins.php` 件数更新・adoption-debt.tsv の対応は**不要**。
- `.claude/skills/app-bug-hunt/screens.md` はテンプレート同梱の bug-hunt スキル目録。ここへの
  非管理メンバー分岐の追記は app-update-docs で扱う (実装 TODO のクローズ条件に追跡を明記)。

## 完了条件

- 施策 D: 上記テスト計画の全ケース (直接アクセス #1-#8 + dashboard 実効性 + continuation) が緑。
  実装前に #2/#4/#6/continuation が現状の billing.index / (未契約) 着地で**赤**になったことを記録する
  (テストファーストの証跡)。
- 施策 A/B/C: 対象既存テストを本ブランチで**実行して緑**であることを完了報告に記録する
  (「テストファイルが存在する」ではなく「本ブランチで成功した」を記す。Codex obs 対応)。
- `.claude/skills/app-bug-hunt/screens.md` 課金ゲート着地節を**同一 PR で更新**する
  (非管理メンバー分岐の追記)。
- **リポジトリ必須の全検証コマンドが緑** (AGENTS.md「検証コマンド」。formatter 実行と formatter 検査は別):
  - `composer test`
  - `composer phpstan`
  - `vendor/bin/pint --test`
  - `pnpm lint`
  - `pnpm typecheck`
  - `pnpm test`
  - `pnpm build`
  - `pnpm typecheck:packages`
  - `pnpm build:packages`
  - `pnpm test:packages`

