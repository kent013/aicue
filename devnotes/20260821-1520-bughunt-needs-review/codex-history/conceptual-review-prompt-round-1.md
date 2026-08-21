## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口 PromptDefense 経由の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用

思考原則: フレームワークのレンジ内でやる / 今必要なものだけ作る(オーバーエンジニアリング禁止) / 後方互換の並走を残さない / 別物の概念を統合しない / テストファースト / タコツボ実装を避ける。

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【この設計の特殊事情】
bug-hunt の「要確認」4 件のうち 3 件 (F-3-02 / S6-1 / S6-2) は調査の結果「実装済み + Feature テスト済み」と判明したため、コード変更なし・既存テスト引用の報告に留める方針。1 件 (Q-2-01) のみ North Star 判断に基づく最小コード変更を行う。この「3 件は追加不要」という判断が思考原則 2 (今必要なものだけ) / 禁止事項 6 に照らして妥当か、既存カバレッジの引用が「テストなしの実装完了」禁止に抵触しないか（そもそも実装していないので抵触しないという整理でよいか）も評価すること。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260821-1520-bughunt-needs-review/conceptual-design.md の全文。リポジトリを読める場合は同ファイルおよび言及されている実装・テストファイルを参照してよい）

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

#### North Star に照らした判断 → **非管理メンバーの初回着地を `/dashboard` に寄せる**

- 選択肢 (A) 非管理メンバーの着地を `/dashboard` に寄せる / (B) 現状維持 + 明文化 のうち **(A)** を採る。
- 根拠: 使命は「現場作業者を摩擦ゼロで仕事へ」。操作不能な billing 画面への着地は soft dead-end で、
  禁止事項 8 (操作できないものを見せない) の精神にも反する。`/dashboard` は課金ゲート外で
  非管理メンバーでも 200 で描画され、詰みを作らない。
- **manageBilling 保持者は billing.index 着地を維持する** (自分で契約/請求管理する権限があり、
  既契約組織でも請求確認は正当な着地。変更は非管理メンバーに限定 = 最小スコープ)。

#### 実装方針 (概要)

`OnboardingController::show` の `hasActiveAccess()` 分岐 1 箇所を、role で 2 分岐にする:

- `hasActiveAccess()` 真 かつ `manageBilling` 保持 → `billing.index` (現状維持)
- `hasActiveAccess()` 真 かつ `manageBilling` 非保持 → `dashboard` (**新規**)

これは onboarding.checkout の離脱ガードも兼ねるため、直接 `/onboarding/checkout` に来た
非管理メンバー (既契約) も同様に dashboard へ逃がす (一貫)。既契約でない経路 (未契約/支払い未解決) は
一切変更しない。

## 期待効果

- 使命への貢献: 現場作業者 (非管理メンバー) が初回ログインで業務入口 (dashboard) へ直接着地し、
  操作不能な billing 画面での戸惑い・手戻りが消える。
- 機能破綻ゼロ: 既契約判定・manageBilling 判定は既存の述語をそのまま使い、新しい述語を発明しない。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `app/Http/Controllers/Onboarding/OnboardingController.php` | `hasActiveAccess()` 真の分岐を manageBilling で 2 分岐 (非保持 → dashboard) |
| `tests/Feature/Onboarding/OnboardingCheckoutTest.php` | 既存「非管理メンバー → billing.index」の期待を dashboard に更新 + ActiveFreePlan 非管理メンバー着地テスト追加 |
| ドキュメント (screens.md 課金ゲート着地節) | 非管理メンバー分岐の追記 (app-update-docs 領域として記録) |

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
- manageBilling 保持者の着地 (billing.index) は変えない。
- ドキュメント本文の実編集は app-update-docs の責務 (本設計では touch-point の記録に留める)。

