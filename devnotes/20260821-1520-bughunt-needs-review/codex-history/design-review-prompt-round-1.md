## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。v1: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作 4. `response()->json()` 直書き(DTO/JsonResource/Inertia) 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き 7. 操作系 POST 応答での `redirect()->intended()` 8. 必須条件未充足でボタン disabled にする UI 9. Artifact の使用

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC (Organization → Team → Project)。

【レビュー観点】1. コードの正確性 2. 既存コードとの整合性 3. PHPStan level 10 適合性 4. テスト計画の網羅性 (RefreshDatabase グローバル適用) 5. DTO/JsonResource 遵守 6. Inertia Props vs API Response 7. 副作用・後退リスク 8. 波及変更の網羅性 9. セキュリティ (認可・入力検証・OWASP・セキュリティ不変条件) 10. DESIGN.md 準拠 (UI 変更時) 11. Atomic Design 準拠 (UI 変更時)。

【この設計の特徴】bug-hunt 要確認 4 件のうち A/B/C (F-3-02/S6-1/S6-2) は既存実装 + 既存 Feature テストの確認報告でコード変更なし、D (Q-2-01) のみ OnboardingController の 1 分岐変更 + テスト。概念設計は既に Codex 合議 APPROVED 済み。既存テストの引用で「テストなしの実装完了」を満たすか、施策 D の分岐変更が既存の課金ゲート挙動 (RequireActiveSubscription / 離脱ガード) を後退させないか、テスト計画が入口 (直アクセス / continuation) と状態 (Subscribed / ActiveFreePlan / 未契約 / 支払い未解決) を十分に分離しているかを重点評価してください。

【出力形式】各施策ごと APPROVE / REQUEST_CHANGES、指摘は [Critical][Warning][Suggestion]、Critical/Warning に修正案、全体判定 APPROVED / CHANGES_REQUESTED、日本語。

---

## 詳細設計書

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
| D | Q-2-01: `manageBilling` 非保持メンバーの onboarding 入口着地を dashboard に寄せる | `OnboardingController.php` + テスト 2 本 | Medium |

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
- ドキュメント: `.claude/skills/app-bug-hunt/screens.md` の「課金ゲート着地」節 (L143-146) に
  非管理メンバー分岐を追記 (app-update-docs の責務。TODO クローズ条件に追跡を明記)。

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

概念設計の 4 分岐を入口別に分離。**状態ごとに個別ケース**にする (複数状態を 1 テストにまとめない)。

`tests/Feature/Onboarding/OnboardingCheckoutTest.php`:

1. **[更新]** 既存 `test('Subscribed の non-manager member は billing-required ではなく billing.index へ ...')`
   → 期待を `assertRedirect(route('dashboard'))` に更新し、テスト名も
   「Subscribed の non-manager member は billing.index ではなく dashboard へ (Q-2-01)」に改名。
   これは**既存テストの削除ではなく期待の更新** (意図的な仕様変更の反映。禁止事項 3 非該当)。
2. **[維持確認]** `test('Subscribed は manageBilling でも billing.index へ redirect')` は**不変**で通ること
   (manageBilling 保持者の着地は変えない = 回帰防止)。既存テストがそのまま緑を維持する。
3. **[新規]** `test('ActiveFreePlan + non-manager member は dashboard へ (Q-2-01 の既契約=Personal free ケース)')`
   — bug-hunt が観測した「既契約組織 (Personal free 有効)」の実シナリオ。`free_plan_code='personal'` の
   org に `attachOrganizationMember`、`current_organization_id` を設定、`/onboarding/checkout` GET →
   `assertRedirect(route('dashboard'))`。
4. **[維持確認/新規]** `test('ActiveFreePlan (free_plan_code=personal) は billing.index へ redirect')`
   (既存、owner=manageBilling) は不変で通ること。
5. **[回帰]** 未契約 org は checkout 200 描画のまま (既存 `未契約 org ... checkout を 200 で render` が緑)。
6. **[回帰]** 支払い未解決 + manageBilling は billing.index (既存 `支払い未解決 + manageBilling は billing.index へ`
   が緑) / 支払い未解決 + 非 manager は billing-required (既存が緑)。
7. **[着地の実効性]** dashboard 着地後、`->get(route('dashboard'))` が 200 で
   `assertInertia(component 'Dashboard/...')` を返すこと (redirect 先が単なる 302 でなく業務入口として
   描画されることの確認。Codex obs 1 対応)。

`tests/Feature/Auth/RegisterVerifyFlowTest.php` (continuation 入口):

8. **[新規]** `test('continuation: 既契約組織の non-manager member は verify 完了後に dashboard へ着地する (Q-2-01)')`
   — 既契約 (active/free) org の非管理メンバー (unverified) を用意し `current_organization_id` を設定、
   session に `verify_continue_organization_id`=当該 org id を積む。署名付き `verification.verify` を踏むと
   `VerifyEmailResponse` → `onboarding.checkout` へ redirect → follow すると最終的に `dashboard` に着地することを固定。
   既存 `test('verify 完了で onboarding.checkout へ redirect し continuation が消える')`
   (owner=personal org=manageBilling) は**不変**で通ること (owner 経路の回帰防止)。

- テストは Factory (`createOrganizationWithOwner` / `attachOrganizationMember` / `contractPaidPlan`) で生成。
  `Model::create()` 手組みはしない。
- 個別 `DatabaseTransactions` は使わない (`tests/Pest.php` の `RefreshDatabase` グローバル適用のまま)。
- **テストファースト**: まず 1・3・8 を先に書いて赤 (現状 billing.index 着地) を確認してから実装する。

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

- 施策 D: テスト 1/3/7/8 が緑、2/4/5/6 が回帰緑、`composer phpstan` (level 10) 通過、`composer fix` 適用。
- 施策 A/B/C: 既存テストが引き続き緑であることの確認 (新規実装なし)。
- screens.md 課金ゲート着地節の更新を app-update-docs で追跡。


---

## 関連する現行コード

### `app/Http/Controllers/Onboarding/OnboardingController.php` show() の該当部 (現状)

```php
public function show(Request $request): Response|RedirectResponse
{
    $organization = $this->resolveMemberCurrentOrganization($request);
    Gate::authorize('view', $organization);

    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    // 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
    // billing-required に飛ばないよう、先に契約状態を判定する。
    if ($this->access->hasActiveAccess($organization)) {
        return new RedirectResponse(route('billing.index'));
    }

    // 未契約 + manageBilling 権限なし → billing-required へ
    if (! Gate::allows('manageBilling', $organization)) {
        return new RedirectResponse(route('onboarding.billing-required'));
    }

    // 支払い未解決のまま契約が残っている組織は billing.index へ
    $subscription = $organization->subscription('default');
    if ($subscription instanceof Subscription
        && SubscriptionState::fromSubscription($subscription)->hasUnsettledPayment()) {
        return new RedirectResponse(route('billing.index'));
    }
    // ... 以下 ?plan= 処理と Inertia::render('Onboarding/Checkout', ...)
}
```

### 既存テスト `tests/Feature/Onboarding/OnboardingCheckoutTest.php` (該当 4 ケース、現状)

```php
test('Subscribed は manageBilling でも billing.index へ redirect', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'active');
    $this->actingAs($owner)->get('/onboarding/checkout')->assertRedirect(route('billing.index'));
});

test('Subscribed の non-manager member は billing-required ではなく billing.index へ (判定順序の固定)', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'active');
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $this->actingAs($member)->get('/onboarding/checkout')->assertRedirect(route('billing.index'));
});

test('ActiveFreePlan (free_plan_code=personal) は billing.index へ redirect', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    // ... free_plan_code=personal を設定 (owner=manageBilling)
    $this->actingAs($owner)->get('/onboarding/checkout')->assertRedirect(route('billing.index'));
});

test('未契約 org (plan_code IS NULL) は checkout を 200 で render する (P4 ゲート反転後)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $this->actingAs($owner)->get('/onboarding/checkout')->assertOk();
});
```

### 既存 continuation テスト `tests/Feature/Auth/RegisterVerifyFlowTest.php` (該当)

```php
test('verify 完了で onboarding.checkout へ redirect し continuation が消える', function (): void {
    // owner (personal org = manageBilling) が signed verify を踏む
    $response->assertRedirect(route('onboarding.checkout'));
    $response->assertSessionMissing('verify_continue_organization_id');
});
```

### 参考: `routes/web.php`

```php
Route::get('/dashboard', DashboardController::class)->name('dashboard'); // 課金ゲート外 (require-active-subscription 非適用)
```
