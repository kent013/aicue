# アプリの使命・禁止事項・思考原則（レビュー基準・絶対遵守）

## 使命 (North Star)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、
そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化された
マニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。

## 禁止事項

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）のエージェント判断実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`（ログイン直後フロー専用）
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件

1. tenant キー不信（保護キーは payload から受け取らない。forceFill / relation で明示代入）
2. 子は親に属する（nested route 不整合は認可より前に 404）
3. cross-org 不可（relation / org-scoped 解決のみ）
5. 権限判定は `laratrust_team_id` 明示（strict_check=true）
6. PII(email/name)は CipherSweet、検索は `whereBlind()`
7. 課金の冪等性（idempotency_key + 部分 UNIQUE index）

## 思考原則

まず仮説を立てろ。フレームワークのレンジ内でやる。今必要なものだけ作る。後方互換の並走を残さない。
タコツボ実装を避ける（結合観点を確認）。テストファースト。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中すること（ファイル読み込みは許可）。

---

# レビュー役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の
**詳細設計**をレビューしてください。

## 前提環境
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest
- DTO + JsonResource パターン / Laratrust RBAC（Organization → Team → Project 階層）

## レビュー観点
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策に Pest テスト、RefreshDatabase グローバル適用）
5. DTO/JsonResource パターン遵守 / 6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク / 8. 波及変更の網羅性（TS 型・Props・テスト）
9. セキュリティ（認可・入力バリデーション・AGENTS.md セキュリティ不変条件）
10. DESIGN.md 準拠（UI 変更を含む場合）/ 11. Atomic Design 準拠（UI 変更を含む場合）

## 出力形式
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# 関連する現行コード

## `app/Actions/Fortify/CreateNewUser.php`（該当部・現行）
```php
$invitationToken = $this->resolveInvitationToken();
$validated = Validator::make($input, [
    'name' => ['required', 'string', 'max:255'],
    'email' => ['required','string','email','max:255',
        new UniqueEncryptedEmail(message: 'このメールアドレスではアカウントを作成できません。'),
        new MatchesInvitationEmail($invitationToken)],
    'password' => ['required', 'string', Password::default()],
    'terms_accepted' => ['accepted'],
], [...])->validate();
// ... Assert::string 群 ...
$user = DB::transaction(function () use ($name, $email, $password, $invitationToken): User {
    $user = (new User([...]))->forceFill([
        'terms_accepted_at' => now(),
        'consent_version' => config()->string('legal.consent_version'),
    ]);
    $user->save();

    $joined = $invitationToken !== null
        ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
        : null;

    if ($joined === null) {
        $organization = $this->provisioning->provisionPersonalOrganization($user);
        $this->tickets->grantSignupGrant($organization);
    }
    return $user;
});
// ... UniqueConstraintViolationException 捕捉 / session forget invitation_token ...
return $user;
```
- コンストラクタ DI: `OrganizationProvisioningService $provisioning`, `OrganizationMembershipService $membership`, `TicketLedgerService $tickets`。

## `OrganizationProvisioningService::provision()`（個人組織パスが現在組織を確定する既存作法）
```php
$organization->users()->attach($creator);
$creator->addRole(OrganizationRole::Owner->value, $organization->laratrust_team_id);
if ($creator->current_organization_id === null) {
    $creator->forceFill(['current_organization_id' => $organization->id])->save();
}
return $organization;
```

## `OrganizationMembershipService::acceptInvitationIfValid()`（register 経路専用。?Organization を返す）
- token active + 招待 email 一致 + 未メンバー → `joinOrganization()` で attach + addRole + accepted_at、参加組織を返す。
- 失敗（不在/失効/受諾済/取消/email 不一致/既メンバー）→ null。
- `joinOrganization()` は POST 受諾経路（`acceptInvitation`）と共有。**current_organization_id は触らない**。

## `MatchesInvitationEmail` rule（validation 段）
- token なし/不正型/空 → pass。token が DB 不在/失効/受諾済/取消 → pass（後段処理へ委譲）。
- token 有効 + email 不一致 → **fail（422 で登録拒否）**。

## `HandleInertiaRequests`（全ページ共有プロップ。自己修復を通さない）
- `currentOrganization` = `$user->currentOrganization`（current_organization_id 生読み + isMemberOf 再確認）。
- `CurrentOrganizationResolver::resolve()`（null/dangling を所属先頭へ heal）は **DashboardController のみ**が呼ぶ。

## `Fortify::verifyEmailView`
- `Inertia::render('Auth/VerifyEmail')`。未検証ユーザーが到達でき、共有プロップを載せる（自己修復非経由）。

---

# 詳細設計書（レビュー対象）

# 詳細設計: invitation-signup-membership

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、
そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化された
マニュアル動画を作れるようにする（「思考ゼロ・編集ゼロ」）。本改善は、招待で組織へ参加したメンバーが
**登録直後から所属組織で作業を開始できる**ようにし、組織横断運用の入口（招待→参加→利用開始）を機能させる
オンボーディング整合修正である（本質機能の改善ではなく入口整備）。

### 禁止事項（AGENTS.md 正本より）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）のエージェント判断実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

**セキュリティ不変条件**: `current_organization_id` は mass-assignment 保護キー（`MassAssignmentProtectedKeys`）。
payload 由来値を使わず、サーバ導出値（参加した招待組織の id）を `forceFill` で明示代入する（tenant キー不信）。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）/ **Pest**（`composer test`）
- **RefreshDatabase** + `--parallel`（`tests/Pest.php` グローバル適用、個別 `DatabaseTransactions` 禁止）
- テストデータは **Factory** 生成 / **DTO + JsonResource** / アーリーリターン
- `composer fix`（Pint）/ `pnpm lint:fix` / PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TS
- テスト DB は **pgsql**

## 概念設計リファレンス

- `devnotes/20260714-0203-invitation-signup-membership/conceptual-design.md`（APPROVED / conceptual Round 2）
- レビュー履歴: `conceptual-review-round-1..2.md`, `codex-history/conceptual-review-decisions-round-1.md`

### 修正対象（bug-hunt 回帰run F-01 / Critical / data_integrity）

招待 token 経由の登録（`CreateNewUser::create()` の招待成立分岐 `$joined !== null`）が
`users.current_organization_id` を確定しないため、登録直後は全ページ共有プロップ
`currentOrganization`（`HandleInertiaRequests`）が null を生読みし、ヘッダーが「組織を作成/選択」表示に
なる中間不整合が生じる。dashboard だけが `CurrentOrganizationResolver` の自己修復で招待先組織へ復帰し、
共有残高（招待先 owner の signup grant 10 枚）を描画するため「残高 10・ヘッダー未所属」という別ページ観測に
なる。**招待成立時に登録トランザクション内で `current_organization_id` を招待先組織へ確定**して解消する。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 招待成立時に現在組織を確定（登録 tx 内 forceFill） | `app/Actions/Fortify/CreateNewUser.php` | Critical |
| 2 | Feature テスト（分岐 A/B の現在組織・共有プロップ・grant無し固定） | `tests/Feature/Organization/InvitationTest.php` / `tests/Feature/Auth/RegistrationTest.php` | Critical |

---

## 施策 1: 招待成立時に現在組織を確定する

### 変更箇所
- `app/Actions/Fortify/CreateNewUser.php` `create()` 内・登録 `DB::transaction` の招待分岐（L90-107 付近）

### 波及変更
- TypeScript 型定義: なし（共有プロップ `currentOrganization` / `organizations` の**形は不変**。値の充足のみ）
- API Resource/DTO: なし（Inertia 応答。`response()->json()` 不使用）
- Inertia Props インターフェース: なし（`shared-props.ts` の `CurrentOrganization` 型は不変）
- テストファイル: 施策 2（`InvitationTest` / `RegistrationTest`）

### 現行コード
```php
// 招待 token 経由なら招待組織へ参加し、個人組織生成をスキップする。
// 受諾不能 (失効/取消/不一致/既メンバー) なら null が返るので個人組織へ fallback。
$joined = $invitationToken !== null
    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
    : null;

if ($joined === null) {
    // 個人用組織を同一 transaction 内で原子的に生成する
    // (user だけ存在し組織なしの中間状態を作らない)
    $organization = $this->provisioning->provisionPersonalOrganization($user);

    // 初回 signup grant (無償 10 枚 / 30 日)。...
    $this->tickets->grantSignupGrant($organization);
}

return $user;
```

### 変更後コード
```php
// 招待 token 経由なら招待組織へ参加し、個人組織生成をスキップする。
// 受諾不能 (失効/取消/不一致/既メンバー) なら null が返るので個人組織へ fallback。
$joined = $invitationToken !== null
    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
    : null;

if ($joined === null) {
    // 個人用組織を同一 transaction 内で原子的に生成する
    // (user だけ存在し組織なしの中間状態を作らない)
    $organization = $this->provisioning->provisionPersonalOrganization($user);

    // 初回 signup grant (無償 10 枚 / 30 日)。LP が約束する「新規登録で 10 枚」を実現する。
    // grantSignupGrant は純粋な ledger insert (通知・イベント・外部 I/O なし) のため登録 tx 内で完結し、
    // 冪等性は idempotency_key + 部分 UNIQUE index が DB レベルで保証する。
    // 招待経由 (join) は個人組織を作らず所属組織の残高を共有するため、ここでは付与しない
    // (招待 N 人 = N×10 の増幅を避ける)。
    $this->tickets->grantSignupGrant($organization);
} else {
    // 招待成立: 参加した招待組織を「現在組織」として登録 tx 内で確定する。
    // 個人組織パスの provisionPersonalOrganization → provision() が
    // current_organization_id === null のとき生成組織を現在組織に据えるのと同位置づけの
    // 「初回のみ確定」に揃える (register 経路限定。POST 受諾経路の joinOrganization は
    // 現在組織を切り替えない契約のため共通コアには昇格しない)。
    // これにより登録直後の全ページで HandleInertiaRequests の共有プロップ currentOrganization が
    // 招待先組織を指し、dashboard の自己修復に依存しない (中間不整合の窓を消す)。
    if ($user->current_organization_id === null) {
        $user->forceFill(['current_organization_id' => $joined->id])->save();
    }
}

return $user;
```

### 設計判断
- **配置**: 修正は `CreateNewUser::create()`（register 経路）に限定する。`OrganizationMembershipService::joinOrganization()`
  は POST 受諾経路（`InvitationAcceptanceController::store` → `acceptInvitation`）と共有され、そこでは
  **ログイン済で現在組織を確定済**のユーザーが 2 つ目以降の組織へ参加する。共通コアで現在組織を強制切替すると
  「操作の副作用で現在組織が勝手に変わる」回帰になるため、昇格しない（Codex 概念 Round1 W1）。
- **null ガード**: 登録直後の user は `current_organization_id === null` だが、`provision()` と同じく
  冪等ガードを付ける（意味の一貫。将来の経路変更で二重設定しない）。
- **トランザクション境界**: forceFill は既存の登録 `DB::transaction` 内・`$joined` 取得後に行う。
  join（organization_user attach + role）と現在組織確定が原子的に commit され、
  「join 済だが現在組織未設定」の中間状態を残さない。
- **enum/型**: `$joined` は `?Organization`。`if ($joined === null)` の else 側で `Organization` に narrowing 済みのため
  `$joined->id`（int）へ安全にアクセスできる（`provision()` の `$organization->id` と同一パターン）。

### PHPStan 適合チェック
- [x] `$joined->id` は else 分岐で `Organization` に narrowing 済（`provision()` の `$organization->id` と同型・int）
- [x] `$user->current_organization_id` は nullable int（larastan schema 推論。既存 `provision()` が同一比較を L10 通過）
- [x] `forceFill(array<string,mixed>)` へサーバ導出値のみ渡す（保護キーを payload から受けない）
- [x] DTO 返却なし（User を返す既存シグネチャ不変）
- [x] 新規 null 分岐・mixed 混入なし

### テスト計画（施策 2 で実装）
- [x] バグ修正の再現/固定テストを先に書く（現行実装では現在組織 null → 失敗を確認してから実装）
- [x] 分岐 A（招待成立）: `current_organization_id` = 招待先組織 / 共有プロップ `currentOrganization` = 招待先組織
- [x] 分岐 B（通常・fallback）: `current_organization_id` = 個人組織（既存 provision 挙動の固定）
- [x] 個別 `DatabaseTransactions` は使わない（`RefreshDatabase` グローバル）

### リスク
- 既存 register 経路テストへの影響: 現在組織を確定するのみで、membership/role/grant の挙動は不変。
  既存の「招待 email で register すると個人組織を作らず招待組織へ参加する」等は引き続き green（assertion 追加のみ）。
- POST 受諾経路（`acceptInvitation`）は未変更のため、既存の「現在組織を切り替えない」挙動は維持。

---

## 施策 2: Feature テスト（分岐 A/B の排他・網羅を固定）

### 変更箇所
- `tests/Feature/Organization/InvitationTest.php`（招待成立 = 分岐 A の現在組織・共有プロップ検証を追加）
- `tests/Feature/Auth/RegistrationTest.php`（通常登録 = 分岐 B の現在組織検証を追加）

### 波及変更
- TypeScript 型定義 / DTO: なし
- テストファイル: 本施策が対象

### 2-1. 分岐 A: 招待成立で現在組織が招待先に確定する（`InvitationTest.php` 追記）

既存テスト「招待 email で register すると個人組織を作らず招待組織へ参加する」に、
**現在組織の DB 値**の検証を追加する（回帰の一次原因を直接固定）。

```php
test('招待 email で register すると個人組織を作らず招待組織へ参加する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('招待組織');
    $token = inviteAndCaptureToken($organization, $owner, 'newbie@example.com', AdminConsoleRole::Admin);

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => '新人 花子',
        'email' => 'newbie@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'newbie@example.com')->firstOrFail();
    expect($organization->users()->whereKey($user->id)->exists())->toBeTrue();
    expect($user->organizationRole($organization))->toBe(OrganizationRole::Admin);
    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();
    $response->assertSessionMissing('invitation_token');

    // [回帰固定] 招待成立で現在組織が招待先組織に確定する (登録直後・自己修復非依存)
    expect($user->current_organization_id)->toBe($organization->id);
});
```

### 2-2. 分岐 A: 共有プロップ `currentOrganization` が dashboard 自己修復に依存せず招待先を指す（新規・`InvitationTest.php`）

**dashboard を経由しない**（= `CurrentOrganizationResolver::resolve()` を通さない）認証済みページで
共有プロップを観測する。未検証ユーザーがアクセスできる `verification.notice`（`Auth/VerifyEmail` Inertia ページ）を
観測点にし、ヘッダーが登録直後から招待先組織を指すことを固定する（Codex 概念 Round2 の実装助言に対応）。

```php
use Inertia\Testing\AssertableInertia;

test('招待経由登録の直後、dashboard 自己修復を経ずに共有プロップ currentOrganization が招待先を指す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('招待組織');
    $token = inviteAndCaptureToken($organization, $owner, 'header@example.com', AdminConsoleRole::Admin);

    $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => 'ヘッダー 確認',
        'email' => 'header@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    // verification.notice は未検証ユーザーが到達でき、CurrentOrganizationResolver の自己修復を
    // 通さない (dashboard 専用)。ここで共有プロップが招待先組織を指せば、ヘッダーが登録直後の
    // 全ページで一貫することを保証できる。
    $this->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentOrganization.id', $organization->id)
            ->where('currentOrganization.slug', $organization->slug)
            ->where('currentOrganization.role', OrganizationRole::Admin->value));
});
```

### 2-3. 分岐 A: grant 非増幅（既存テストを維持・明示コメント補強）

既存テスト「招待経由登録では個人組織を作らず signup grant を付与しない (増幅防止)」を維持する
（招待先組織残高 0 / `signup_grant:%` 行 0）。現在組織確定を加えても grant 経路は不変であることを、
本テストが引き続き green であることで担保する（変更不要。念のため本設計で回帰対象として明記）。

### 2-4. 分岐 B: 通常登録で現在組織が個人組織に確定する（`RegistrationTest.php` 追記）

既存テスト「登録できる (同意の証跡が記録される)」に、**現在組織 = 個人組織**の検証を追加し、
分岐 B の現在組織確定（既存 `provision()` 挙動）を固定する。これにより分岐 A/B の現在組織確定が
**排他かつ網羅**（A=招待先 / B=個人組織）で固定される。

```php
test('登録できる (同意の証跡が記録される)', function (): void {
    $response = $this->post('/register', [
        'name' => '山田 太郎',
        'email' => 'taro@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertRedirect(route('verification.notice'));
    $this->assertAuthenticated();

    $user = User::whereBlind('email', 'email_index', 'taro@example.com')->firstOrFail();
    expect($user->terms_accepted_at)->not->toBeNull();
    expect($user->consent_version)->toBe(config()->string('legal.consent_version'));

    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
    expect(app(TicketLedgerService::class)->balance($personalOrg))
        ->toBe(config()->integer('billing.signup_grant_tickets'));

    // [分岐 B 固定] 通常登録では現在組織が個人組織に確定する (招待成立分岐と排他)
    expect($user->current_organization_id)->toBe($personalOrg->id);
});
```

### 2-5. 分岐 B（fallback）: 無効 token は通常登録に fallback（既存テストを維持）

既存テスト「取り消し済みの招待 token で register すると通常登録 (個人組織生成) に fallback する」を維持する
（個人組織が生成される = 分岐 B）。fallback 時も現在組織が個人組織に確定することは 2-4 と同一挙動
（provision の null ガード確定）で担保される。必要に応じて当該テストにも
`expect($user->current_organization_id)->toBe($personalOrg->id)` を追加して分岐 B の網羅を強化する。

### PHPStan 適合チェック
- [x] テストは `expect()` / `AssertableInertia` の既存 idiom（`OrganizationNavSharedPropsTest` と同型）
- [x] `$user->current_organization_id` / `$organization->id` / `$personalOrg->id` は int（larastan schema 推論）
- [x] Factory / 既存ヘルパー（`createOrganizationWithOwner` / `inviteAndCaptureToken`）を使用（手組み禁止）

### テスト計画（実行）
- [x] `composer phpstan`（L10）/ `composer test`（`--parallel`）/ `vendor/bin/pint --test` を全 green
- [x] 現行実装（施策 1 未適用）で 2-1/2-2/2-4 の現在組織アサーションが**失敗**することを先に確認（テストファースト）
- [x] 個別 `DatabaseTransactions` を使わない（`RefreshDatabase` グローバル）

### リスク
- `verification.notice` が将来 Inertia ページでなくなる/共有プロップを載せない構成に変わると 2-2 が壊れる。
  現状 Fortify `verifyEmailView` が `Inertia::render('Auth/VerifyEmail')` で確定しており、
  `HandleInertiaRequests` が全 Inertia 応答に共有プロップを載せるため妥当。壊れた場合は別の
  「未検証アクセス可 + 自己修復非経由」の Inertia ルートへ観測点を移す。

---

## 使命・禁止事項チェック（最終確認）

- 使命寄与: 招待メンバーが登録直後から所属組織で作業に入れる（組織横断運用の入口整備）。
- 禁止事項: PHPStan 無視なし / 全施策にテスト（分岐 A/B を Feature で固定）/ `response()->json()` 直書きなし /
  DTO・Props・TS 型変更なし / 保護キー `current_organization_id` は forceFill でサーバ導出値のみ代入 /
  個別 `DatabaseTransactions` なし。
- セキュリティ不変条件: tenant キー不信（payload 由来の org id を使わず、参加した招待組織 relation の id を使う）。
  cross-org 不可（`acceptInvitationIfValid` が招待 relation 経由で org を解決。current は所属先のみ）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は `CreateNewUser` の 1 分岐追加 + Feature テストのみの局所・自己完結修正。新規ドメイン追加ではなく、他タスクと共有する広域インターフェース変更もない。registration-ticket-grant(T021) の成果物（TicketLedgerService / migration / LP）には触れず、独立ブランチで完結できる。 |
| 競合リスク | 低。`CreateNewUser.php` を触る他タスクがなければ衝突なし。テストは既存 `InvitationTest` / `RegistrationTest` への追記のため、同ファイルを触る並行タスクがある場合のみ軽微な merge 調整。 |

