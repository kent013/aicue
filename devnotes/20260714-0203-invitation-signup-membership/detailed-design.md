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
| 1 | 登録経路の招待受諾で現在組織を確定（`acceptInvitationIfValid` 内で join と原子確定） | `app/Services/Organization/OrganizationMembershipService.php` | Critical |
| 2 | Feature テスト（分岐 A/B の現在組織・共有プロップ・grant無し固定） | `tests/Feature/Organization/InvitationTest.php` / `tests/Feature/Auth/RegistrationTest.php` | Critical |

> **配置の設計判断（Codex 詳細 Round1 C1/W1 反映）**: 概念設計では「`CreateNewUser::create()` の else 分岐で
> `current_organization_id` を確定」としたが、詳細レビューを受けて **register 専用メソッド
> `OrganizationMembershipService::acceptInvitationIfValid()` の内部**（join 成功直後）へ移す。理由:
> (1) 「招待参加（join）＋ 現在組織の確定」を **1 ユースケースとして閉じ**、`CreateNewUser` 側の別操作分割による
> 将来の整合崩れを排除する（Codex W1）。(2) 個人組織パスが `provision()`（ProvisioningService の内部）で
> 現在組織を確定するのと**対称**な配置になり、`CreateNewUser` は薄いオーケストレーションに保てる
> （AGENTS.md 実装規約「Controller/Action は薄く、Service 委譲」）。(3) `acceptInvitationIfValid()` は
> **register 経路専用**（呼び出し元は `CreateNewUser` のみ。POST 受諾は別メソッド `acceptInvitation`）のため、
> ここでの現在組織確定は POST 受諾経路に一切波及しない（共通コア `joinOrganization()` は不変）。
> **新規メソッド `acceptInvitationForRegistration` は追加しない**（Codex W1 の代替案）: 既存の register 専用
> メソッドに畳み込めば十分で、新抽象の追加は「今必要なものだけ作る」に反する。

---

## 施策 1: 登録経路の招待受諾で現在組織を確定する

### 変更箇所
- `app/Services/Organization/OrganizationMembershipService.php` `acceptInvitationIfValid()`（join 成功直後・
  `return $organization;` の直前）。`CreateNewUser.php` は**変更しない**（else 分岐追加も DI 追加も不要）。

### 波及変更
- TypeScript 型定義: なし（共有プロップ `currentOrganization` / `organizations` の**形は不変**。値の充足のみ）
- API Resource/DTO: なし（Inertia 応答。`response()->json()` 不使用）
- Inertia Props インターフェース: なし（`shared-props.ts` の `CurrentOrganization` 型は不変）
- メソッドシグネチャ: `acceptInvitationIfValid(string $plainToken, User $user): ?Organization` は**不変**
  （戻り値・引数変更なし。副作用として現在組織を確定するのみ）
- テストファイル: 施策 2（`InvitationTest` / `RegistrationTest`）

### 現行コード（`acceptInvitationIfValid` 末尾）
```php
$organization = $invitation->organization;
Assert::isInstanceOf($organization, Organization::class);

// 既メンバー (race 等) は個人組織へ fallback
if ($organization->users()->whereKey($user->getKey())->exists()) {
    return null;
}

$this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

return $organization;
```

### 変更後コード
```php
$organization = $invitation->organization;
Assert::isInstanceOf($organization, Organization::class);

// 既メンバー (race 等) は個人組織へ fallback
if ($organization->users()->whereKey($user->getKey())->exists()) {
    return null;
}

$this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

// [register 経路限定] 参加した招待組織をこの新規ユーザーの「現在組織」として確定する。
// - 本メソッドは register 経路専用 (呼び出し元は CreateNewUser のみ。POST 受諾は acceptInvitation)。
//   よって現在組織の確定は POST 受諾経路 (現在組織を切り替えない契約) に波及しない。
// - 個人組織パスが provision() 内で現在組織を据えるのと対称に、招待参加も本サービス内で
//   「join + 現在組織確定」を 1 ユースケースとして閉じる (呼び出し元の登録 tx 内で連続実行され、
//   「join 済だが現在組織未設定」の中間状態を残さない)。
// - この user は登録直後で現在組織が未確定のため、招待先組織を無条件に現在組織にする
//   (register 責務として「招待成立 ⇒ 現在組織 = 招待先」を強制)。current_organization_id は
//   mass-assignment 保護キーのためサーバ導出値を forceFill で明示代入する (tenant キー不信)。
$user->forceFill(['current_organization_id' => $organization->id])->save();

return $organization;
```

### 設計判断
- **register 責務の強制（Codex 詳細 Round1 C1）**: `=== null` ガードではなく**無条件確定**にする。本メソッドは
  register 専用で対象 user は登録直後（現在組織未設定）のため、「招待成立 ⇒ 現在組織 = 招待先」を不変条件として
  強制できる。`provision()` が `=== null` ガードを持つのは、provision が**ログイン中の追加組織作成**
  （`OrganizationController`）からも呼ばれ既存の現在組織を保護する必要があるためで、register 専用の本メソッドとは
  前提が異なる（同じガードを機械的に持ち込まない）。
- **共通コア不変**: `joinOrganization()`（POST 受諾と共有）は変更しない。現在組織確定は register 専用メソッド側のみ。
- **トランザクション境界**: `joinOrganization()` は内部で `DB::transaction`（呼び出し元 tx 内では savepoint）を張り、
  その戻り後・同じ登録 `DB::transaction` 内で forceFill する。join と現在組織確定が原子的に commit される。
- **型**: `$organization` は `Assert::isInstanceOf(...Organization::class)` で narrowing 済。`$organization->id` は int
  （`provision()` の `$organization->id` と同型）。`forceFill(array<string,mixed>)` へサーバ導出値のみ渡す。

### PHPStan 適合チェック
- [x] `$organization` は `Assert::isInstanceOf` で `Organization` に narrowing 済 → `$organization->id` は int
- [x] `$user->current_organization_id` は書き込みのみ（読み取り比較を追加しない）。`forceFill` はサーバ導出値のみ
- [x] メソッド戻り値 `?Organization` 不変（副作用追加のみ、型契約は不変）
- [x] DTO 返却なし / 新規 null 分岐・mixed 混入なし

### テスト計画（施策 2 で実装）
- [x] バグ修正の再現/固定テストを先に書く（現行実装では現在組織 null → 失敗を確認してから実装）
- [x] 分岐 A（招待成立）: `current_organization_id` = 招待先組織 / 共有プロップ `currentOrganization` = 招待先組織
- [x] 分岐 B（通常・fallback）: `current_organization_id` = 個人組織（既存 provision 挙動の固定）
- [x] 個別 `DatabaseTransactions` は使わない（`RefreshDatabase` グローバル）

### リスク
- 既存 register 経路テストへの影響: 現在組織を確定するのみで、membership/role/grant の挙動は不変。
  既存の「招待 email で register すると個人組織を作らず招待組織へ参加する」等は引き続き green（assertion 追加のみ）。
- POST 受諾経路（`acceptInvitation`）・共通コア（`joinOrganization`）は未変更のため、既存の
  「現在組織を切り替えない」挙動は維持。
- 将来 `acceptInvitationIfValid` をログイン中経路から呼ぶよう変更する場合は、無条件確定が現在組織を
  切り替える点に注意（現状は register 専用でその経路はない。docblock に register 専用を明記して防御）。

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

**現行実装での失敗点（テストファースト）**: 施策 1 未適用では `acceptInvitationIfValid` が
`current_organization_id` を確定しないため、`$user->current_organization_id` は **`null`** のまま
（`expect(...)->toBe($organization->id)` が `null !== id` で**失敗**）。他アサーション（membership/role/
個人組織なし/accepted）は現行でも green のため、追加した 1 行だけが赤くなることを確認してから実装する。

### 2-2. 分岐 A: 共有プロップ `currentOrganization` が dashboard 自己修復に依存せず招待先を指す（新規・`InvitationTest.php`）

**観測点の選定ルール**（脆い依存を避けるための優先順。Codex 詳細 Round1 W2 反映）:
1. **未検証ユーザーが到達できる**（招待経由登録直後は未検証のため）。
2. **`CurrentOrganizationResolver::resolve()` を経由しない**（= `DashboardController` 以外。自己修復を挟むと
   「修正がなくても heal で緑になる」偽陰性を招くため必ず除外）。
3. **Inertia 応答**で `HandleInertiaRequests` の共有プロップ `currentOrganization` を載せる。

上記 3 条件を満たす**第一候補 = `verification.notice`（`Auth/VerifyEmail` Inertia ページ）**。
代替候補（`verification.notice` が将来 Inertia でなくなった場合）: メール認証後に到達可能な非 dashboard の
Inertia ページ（例: `settings.profile` = `Auth/VerifyEmail` と異なり verified 必須のため、テスト内で
`$user->markEmailAsVerified()` 後に GET する）。いずれも「自己修復非経由」を満たす。第一候補で固定する。

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
            ->where('currentOrganization.role', OrganizationRole::Admin->value)
            // 共有プロップ間の整合 (Codex 詳細 Round2 Suggestion): organizations 一覧にも招待先が載る
            ->where('organizations.0.id', $organization->id));
});
```

**現行実装での失敗点（テストファースト）**: 施策 1 未適用では `current_organization_id` が `null` のため、
`verification.notice`（自己修復非経由）の共有プロップ `currentOrganization` は **`null`** になり
（`currentOrganizationProp` の `$organization === null` 早期 return）、`->where('currentOrganization.id', ...)` が
**失敗**する。施策 1 適用後に招待先組織を指すようになることを確認する。この観測点が dashboard を避けるのは、
dashboard だと自己修復で緑になり修正の有無を判別できない（偽陰性）ため。

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

**現行実装での失敗点（テストファースト）**: 分岐 B（通常登録）は現行でも `provision()` が現在組織を確定するため、
この追加アサーションは**現行でも green**（回帰の温床ではないことの固定 = 排他の証明）。したがって 2-4 は
「施策 1 で分岐 A を直しても分岐 B の現在組織確定が壊れない」ことのリグレッションガードであり、
2-1/2-2（現行で赤 → 施策 1 で緑）と役割が異なる点を明記する。

### 2-5. 分岐 B（fallback）: 無効 token は通常登録に fallback（既存テストを強化）

既存テスト「取り消し済みの招待 token で register すると通常登録 (個人組織生成) に fallback する」に、
**現在組織 = 個人組織**のアサーションを**必須で追加**する（Codex 詳細 Round1 施策2 Suggestion を必須化）。
これにより「token あり but 無効 → 分岐 B（個人組織 + grant + 現在組織=個人組織）」が、
分岐 A（招待先）と**排他**であることを直接固定する。

```php
// 既存テスト末尾に追加 ($user は当該テストで解決済みの登録ユーザー)
$personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
// [分岐 B(fallback) 固定] 無効 token の fallback でも現在組織は個人組織 (招待先ではない)
expect($user->current_organization_id)->toBe($personalOrg->id);
```

**現行実装での失敗点（テストファースト）**: 分岐 B は現行でも `provision()` が現在組織を確定するため
このアサーションも**現行で green**（分岐 A の修正が fallback の現在組織へ波及しないことのガード）。

### 2-6. register 専用前提の保護: POST 受諾は現在組織を切り替えない（既存テストを維持・強化）

施策 1 は「`acceptInvitationIfValid()` が register 専用」であることに依存する。この前提を守るため、
**ログイン済ユーザーの POST 受諾（`InvitationAcceptanceController::store` → `acceptInvitation`）では
`current_organization_id` が切り替わらない**ことを固定する（Codex 詳細 Round2 施策1 Suggestion）。
`InvitationTest.php` の既存「token 受諾でメンバーシップ + 招待ロールが付与される」テストに、
受諾前後で `current_organization_id` が不変（受諾前に別組織を current にしていればそのまま）である
アサーションを追加する。これにより、将来 `joinOrganization` へ現在組織確定を誤って昇格させる回帰を検知できる。

```php
// 受諾する user が別組織を current に持つ状態を作ってから受諾し、切り替わらないことを固定する例
$before = $user->current_organization_id;           // 受諾前の current (別組織 or null)
// ... POST /invitations/accept で受諾 ...
expect($user->refresh()->current_organization_id)->toBe($before); // POST 受諾は current を変えない
```

### PHPStan 適合チェック
- [x] テストは `expect()` / `AssertableInertia` の既存 idiom（`OrganizationNavSharedPropsTest` と同型）
- [x] `$user->current_organization_id` / `$organization->id` / `$personalOrg->id` は int（larastan schema 推論）
- [x] Factory / 既存ヘルパー（`createOrganizationWithOwner` / `inviteAndCaptureToken`）を使用（手組み禁止）

### テスト計画（実行）
- [x] `composer phpstan`（L10）/ `composer test`（`--parallel`）/ `vendor/bin/pint --test` を全 green
- [x] テストファーストの赤/緑マップ:
  - **2-1 / 2-2**: 現行（施策 1 未適用）で**赤**（`current_organization_id` = null / 共有プロップ = null）。
    施策 1 適用で緑になることを確認 = 回帰の直接固定。
  - **2-4 / 2-5**: 現行でも**緑**（分岐 B は `provision()` が現在組織を確定済）。施策 1 が分岐 B に波及しない
    ことのリグレッションガード（排他の証明）。
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
