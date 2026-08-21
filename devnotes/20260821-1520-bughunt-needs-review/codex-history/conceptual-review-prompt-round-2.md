Round 1 の指摘への対応を反映しました。以下の対応マトリクスに基づき概念設計を修正済みです。全体判定の再評価をお願いします。

## 対応サマリー

- [Critical] obs5 (テスト入口分離): テスト計画を 4 分岐に明示化しました。
  1. 既契約 + manageBilling 保持者が /onboarding/checkout → billing.index のまま (既存挙動維持を明示)
  2. 既契約 + manageBilling 非保持メンバーが /onboarding/checkout へ直接 → dashboard
  3. 招待登録→メール認証 continuation を通った非保持メンバー → 最終着地 dashboard (VerifyEmailResponse→onboarding.checkout→dashboard の 2 段 redirect を追う)
  4. 未契約 / 支払い未解決組織の既存着地は不変 (回帰防止)
  着地先 dashboard は 302 redirect 先確認に加え、dashboard component の 200 描画も固定します。

- [Warning] obs1 (/dashboard の業務入口性): DashboardController が RequireActiveSubscription を通らない課金ゲート外 route で「未契約でも状況把握と復帰導線を提供」する docblock を根拠に、業務入口として機能することを明記。詳細設計で表示内容・必要権限を確認し、テストで component 描画を固定する旨を追記。

- [Warning] obs2 (禁止事項 8 の拡張解釈): 主根拠を North Star + UX 判断に置き、禁止事項 8 は「補助的思想 (主根拠ではない)」に格下げしました。

- [Warning] obs3/obs7 (manageBilling の主体/対象・型): 既存の `Gate::allows('manageBilling', $organization)` 形式 (同 controller が既契約でない経路で既に使用) と既存の organization 解決経路 (resolveMemberCurrentOrganization で解決・非 null Assert 済み) をそのまま再利用すると明記。新 role 判定・独自 role 文字列・新規 nullable を導入しないため PHPStan level 10 の型付き経路を維持。文言も「role」→「manageBilling 能力」に統一。

- [Warning] obs4 (機能破綻ゼロの過剰主張): 効果を「既契約組織の manageBilling 非保持メンバーの onboarding 入口着地が billing→dashboard になる」に限定。無限定主張を削除し、4 分岐テストで保証範囲を具体化。

- [Warning] obs6 (ドキュメント完了条件): 実装 TODO のクローズ条件に「screens.md 課金ゲート着地節への非管理メンバー分岐追記を app-update-docs で追跡」を明記。

- [Warning] obs5b (dashboard→billing 導線): 閲覧設計は今回変えない方針を維持し、混乱が残れば別 finding として切り出す旨をスコープ外に追記。

## 修正後の概念設計 (全文)

# 概念設計: bughunt-needs-review

bug-hunt run 20260821-095643 の「要確認 (needs-review)」グループ 4 件に対する設計。
証跡: `devnotes/20260821-095643-bug-hunt/report.md` および `shard-2/3/4/shard-report.md`。

## 背景・課題

このグループは「バグと断定できないが仕様確定・カバレッジ確認が必要」な 4 件。
コード調査の結果、**3 件は既に実装済み + Feature テストで固定済み**であり、
**1 件 (Q-2-01) だけが North Star に照らした挙動判断と最小コード変更を要する**ことが判明した。

| finding | 実態 | 必要な作業 |
|---|---|---|
| F-3-02 | 同 token・別 plan → 422 ガード実装済み + Feature テスト済み | 確認して報告 (変更なし) |
| S6 要確認-1 | 旧アドレス通知は Q11 決定として実装済み + Feature テスト済み (action + HTTP 両層) | 確認して報告 (変更なし) |
| S6 要確認-2 | パスワード未設定ユーザーの初回設定 正常系は `ssoOnly()` Factory で Feature テスト済み | 確認して報告 (変更なし) |
| Q-2-01 | 既契約組織へ招待参加した非管理メンバーの初回着地が `/billing`。仕様判断が必要 | North Star 判断 + 最小コード変更 + テスト |

## 各 finding の調査結果と方針

### F-3-02 (要確認): 課金 checkout の同 token・別プラン冪等 → 422

- **実装**: `SubscriptionService::startCheckout()` → `startCheckoutLocked()` が
  `SubscriptionAttemptPlanMismatchException` を投げ、`BillingController` (L285) が捕捉して
  `plan_code` の 422 バリデーションエラーへ写像する。実装は既に存在する (bug-hunt も読了確認済み)。
- **既存テスト**: `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php` の
  `test('同一 token + 別 plan_code は 422 で、行も Stripe 呼び出しも増えない')` が
  `/billing/checkout` HTTP 経路で「同 token・別 plan → `assertInvalid(['plan_code'])`、
  行 1 本のまま、Stripe 呼び出しも増えない」を固定している。**この経路は完全にカバー済み**。
- **方針**: bug-hunt が browser-only raw fetch で 422 を再現できなかったのは
  FakeStripeGateway の中立帰還設計と Inertia クライアント前提によるもので、
  サーバ側ガードとその Feature テストは健在。**実装・テストとも追加不要**。既存テストを引用して報告する。

### S6 要確認-1: メール変更時の旧アドレスへの通知

- **仕様**: `app/Actions/Fortify/UpdateUserProfileInformation.php` の docblock に
  「Q11 決定: 旧アドレスへセキュリティ通知を送る (新アドレスは旧保持者に非開示。乗っ取り検知導線)」と
  明記され、`Notification::route('mail', $oldEmail)->notify(new EmailChangedSecurityNotification)` が
  実装済み。**「通知する」が確定仕様**。
- **既存テスト**:
  - action 層: `tests/Feature/Auth/EmailChangeTest.php`
    `test('メール変更時に旧アドレスへセキュリティ通知が送られ再検証が要求される')` が
    `Notification::assertSentTo(AnonymousNotifiable, EmailChangedSecurityNotification, 宛先=旧アドレス)` を固定。
  - HTTP 層: `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php`
    `test('3: fresh + email 変更は成功し旧アドレス通知 + 再検証要求')` が
    実 route `PUT /user/profile-information` (= bug-hunt が指した `user-profile-information.update`) 経由で同じ通知を固定。
- **方針**: 仕様も実装もテストも揃っている。**追加不要**。bug-hunt が `mail-urls` で本文/宛先を
  確認できなかっただけで、通知は実際に飛んでいる。既存テストを引用して報告する。

### S6 要確認-2: パスワード未設定ユーザーの初回パスワード設定 正常系

- **実装**: `POST /settings/password` (`PasswordSetupController::store`) は SSO/passkey のみの
  ユーザーがパスワードを持てる唯一の経路。`recent-auth` 必須、設定済みは fail-closed で 422。
- **Factory**: `database/factories/UserFactory.php` に `ssoOnly()` state が既にある
  (パスワード未設定ユーザーを生成する)。
- **既存テスト**: `tests/Feature/Settings/PasswordSetupTest.php`
  `test('password 未設定 + recent-auth fresh なら設定できる')` が
  `ssoOnly()` ユーザーの**正常系** (未設定ユーザーが設定に成功する) を固定済み。
  監査イベント記録・他デバイス失効・設定後 `hasPassword=true` の再訪一貫性まで網羅。
- **方針**: bug-hunt は「seed に該当ユーザーが無く新規作成手段も塞がれ」browser で未検証だったが、
  Factory (`ssoOnly()`) と正常系 Feature テストは既に存在する。**追加不要**。既存テストを引用して報告する。

### Q-2-01 (要確認): 招待参加した非管理メンバーの初回着地が `/billing`

- **現象**: 既契約組織 (Personal free 有効等) へ招待経由で参加した**非管理メンバー**が、
  register → email 認証完走後に `/billing` (プランとお支払い) に着地する。`/dashboard` ではない。
- **経路**: register 時に `EmailVerificationContinuation` が org id を保持 → 認証完了で
  `VerifyEmailResponse` が `onboarding.checkout` へ復帰 → `OnboardingController::show` が
  `hasActiveAccess()` 真 (既契約) のため `route('billing.index')` へ redirect。
  この分岐は role を見ないため、非管理メンバーも `/billing` へ着地する。
- **問題の本質**: 非管理メンバー (編集者/撮影者 = 現場作業者) は billing を**自分では操作できない**。
  初回着地が「自分では何もできない請求画面」なのは、現場作業者を最短で仕事へ着地させる使命
  (North Star: 専門知識ゼロの現場作業者が最小摩擦でマニュアル動画を作れる) に対する摩擦である。
  一方 `/dashboard` は課金ゲート外の状況把握 + 業務入口であり、作業者の着地点として正しい。

#### North Star に照らした判断 → **billing を管理できないメンバーの初回着地を `/dashboard` に寄せる**

- 選択肢 (A) `manageBilling` 能力を持たないメンバーの着地を `/dashboard` に寄せる / (B) 現状維持 + 明文化
  のうち **(A)** を採る。
- **主根拠 (North Star + UX 判断)**: 使命は「現場作業者を最小摩擦で仕事へ着地させる」。
  billing を操作できない利用者を、初回に操作不能な請求画面へ落とすのは業務開始導線として不適切で、
  仕事の入口 (`/dashboard`) へ送るべきである。
- **補助的思想 (主根拠ではない)**: 禁止事項 8 は「必須条件未充足を理由にボタンを disabled にしない」
  という UI 規約であり「操作不能画面を見せない」一般原則そのものではない。方向性の傍証としてのみ参照する。
- **`/dashboard` が実際に業務入口として機能することの確認 (Codex obs 1 対応)**: `/dashboard` は
  `RequireActiveSubscription` を通らない課金ゲート外の route で、`DashboardController` の docblock どおり
  「未契約でも状況把握と復帰導線を提供」する。非管理メンバーでも 200 で描画され、プロジェクト/撮影への
  業務導線を持つ。単なる 200 応答ではなく「作業者が次の行動に進める入口」であることを詳細設計で
  表示内容・必要権限まで確認する (Feature テストで dashboard component の描画も固定)。
- **`manageBilling` 能力保持者は billing.index 着地を維持する** (自分で契約/請求管理でき、
  既契約組織でも請求確認は正当な着地。変更は能力非保持者に限定 = 最小スコープ)。

#### 実装方針 (概要)

`OnboardingController::show` の `hasActiveAccess()` 分岐 1 箇所を、**既存の `manageBilling` 能力判定**で
2 分岐にする。**新しい role 判定・独自 role 文字列は導入しない** (Codex obs 3/7 対応):

- `hasActiveAccess()` 真 かつ `Gate::allows('manageBilling', $organization)` 真 → `billing.index` (現状維持)
- `hasActiveAccess()` 真 かつ `manageBilling` 非保持 → `dashboard` (**新規**)

`manageBilling` の Gate は **同 controller が既契約でない経路で既に呼んでいる形式** (`$organization` を
渡す organization-scoped 判定。organization は既存の `resolveMemberCurrentOrganization` で解決済み・
非 null が Assert 済み) をそのまま再利用する。新たな nullable 値や推測的な型を持ち込まないため
PHPStan level 10 の型付き経路を維持する。

これは onboarding.checkout の離脱ガードも兼ねるため、直接 `/onboarding/checkout` に来た
非管理メンバー (既契約) も同様に dashboard へ逃がす (一貫)。既契約でない経路 (未契約/支払い未解決) は
一切変更しない。

## 期待効果

- 使命への貢献: billing を管理できないメンバー (現場作業者) が初回ログインで業務入口 (dashboard) へ
  直接着地し、**不要な請求画面への初回遷移が除去される** (Codex obs 1b: 「摩擦ゼロ」ではなく
  検証可能な限定表現)。
- 保証範囲の限定 (Codex obs 4 対応): 効果は「**既契約組織の `manageBilling` 非保持メンバーについて、
  onboarding 入口で billing ではなく dashboard へ遷移する**」に限定する。この保証範囲は下記テスト計画の
  4 分岐で具体的に固定する。「機能破綻ゼロ」のような無限定な主張はしない。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `app/Http/Controllers/Onboarding/OnboardingController.php` | `hasActiveAccess()` 真の分岐を既存 `manageBilling` 能力判定で 2 分岐 (非保持 → dashboard) + docblock 更新 |
| `tests/Feature/Onboarding/OnboardingCheckoutTest.php` | 下記テスト計画の 4 分岐を明示 (既存 non-manager テストの期待更新 + 追加) |
| `tests/Feature/Auth/RegisterVerifyFlowTest.php` (または新規) | 招待登録→メール認証 continuation 経由の非管理メンバー着地 = dashboard を固定 |
| ドキュメント (screens.md 課金ゲート着地節) | 非管理メンバー分岐の追記 (app-update-docs 領域として記録) |

#### テスト計画の 4 分岐 (Codex obs 5 [Critical] 対応 — 入口別に分離)

1. 既契約 (Subscribed / ActiveFreePlan) + `manageBilling` 保持者が `/onboarding/checkout` へ来ると
   `billing.index` のまま (**既存挙動の維持を明示**)。
2. 既契約 + `manageBilling` 非保持メンバーが `/onboarding/checkout` へ**直接**来ると `dashboard`
   (直アクセス入口)。
3. 招待登録 → メール認証完了の **continuation** を通った非保持メンバーが最終的に `dashboard` に着地する
   (continuation 入口。`VerifyEmailResponse` → `onboarding.checkout` → dashboard の 2 段 redirect を追う)。
4. 未契約 / 支払い未解決組織の既存着地 (checkout 200 描画 / billing.index) は**変わらない** (回帰防止)。

これにより「メール認証後 continuation」と「直接アクセス」という別入口を分離して固定し、
意図しない既存挙動の置換を検知できるようにする。着地先 dashboard は 302 の redirect 先確認に加え、
到達後の dashboard component が 200 で描画されること (作業者の業務入口として機能すること) も確認する。

## 制約・前提

- F-3-02 / S6-1 / S6-2 は実装済み + テスト済みのため**コード変更なし** (思考原則 2・禁止事項 6: 不要な追加をしない)。
- Q-2-01 の変更は既存の判定述語 (`BillingAccess::hasActiveAccess` / `Gate manageBilling`) の範囲内で行い、
  新しい状態・新しい経路を作らない。
- 課金ゲート反転 (P4) の runbook (`docs/billing-gate-inversion-runbook.md`) が固定する
  「未契約組織の遮断着地」ロジック (RequireActiveSubscription middleware) には**一切触れない**
  (今回変えるのは「既契約組織の onboarding 入口の逃がし先」だけ)。

## スコープ外

- F-2-02 / F-2-03 (Critical 認可バグ)、F-1-02 (High)、その他 Medium/Low findings は別グループ。
- 非管理メンバーの billing 画面の閲覧可否そのもの (read-only 表示の設計) は変えない。
  dashboard 上の請求導線の露出は今回変えない — もし混乱が残るなら別 finding として切り出す (Codex obs 5b)。
- manageBilling 能力保持者の着地 (billing.index) は変えない。
- ドキュメント本文の実編集は app-update-docs の責務。ただし完了条件を曖昧にしないため (Codex obs 6)、
  実装 TODO のクローズ条件に「screens.md 課金ゲート着地節への非管理メンバー分岐の追記を
  app-update-docs で追跡する」ことを明記し、追跡先を残す。

