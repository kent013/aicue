## アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、スマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。v1 スコープ: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項
1. テストなしの実装完了報告 / 2. PHPStan エラーの widen・baseline 化 / 3. dev DB 破壊操作 / 4. `response()->json()` 直書き(Inertia/DTO/JsonResource を使う) / 5. LLM の Prism 直呼び / 6. prompt 文字列のコード直書き / 7. 操作系 POST での `redirect()->intended()` / 8. 必須条件未充足でボタンを disabled にする UI

## セキュリティ不変条件（関係項）
#1 tenant キー不信 / #6 PII(email/name)は CipherSweet・検索は whereBlind()。本設計は token_hash 照合のみ追加、平文 email 検索は新設しない。

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Fortify + Inertia.js + Svelte 5 + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC / PII は CipherSweet。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策に Pest テスト、RefreshDatabase グローバル）
5. DTO/JsonResource パターンの遵守 / Inertia Props vs API Response の使い分け
6. 副作用・後退リスク
7. 波及変更の網羅性（TypeScript型定義、テストが変更対象に含まれるか）
8. セキュリティ（認可、入力バリデーション、OWASP、セキュリティ不変条件。特に本設計は PII(email) を Inertia props でクライアントへ返す = bearer token モデルでのリスク受容が妥当か、session stale token の fail-secure 破棄が正しいか）
9. DESIGN.md / Atomic Design 準拠（Input atom の readonly 透過、DS token 使用、hex 直書き回避）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: invite-email-prefill（招待経由登録フォームでの招待メールアドレス自動入力）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項
1. テストなしの実装完了報告 / 2. PHPStan エラーの widen・baseline 化 / 3. dev DB への破壊操作
4. `response()->json()` の直書き (DTO/JsonResource/Inertia を使う) / 5. LLM の Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST での `redirect()->intended()` / 8. 必須条件未充足でボタンを disabled にする UI

### セキュリティ不変条件（本設計に関係する項）
- #1 tenant キー不信 (payload から ownership/actor/tenant キーを受けない)
- #6 PII(email/name)は CipherSweet。検索は `whereBlind()` (平文 where は hit しない)
- 本設計は **token_hash 照合のみ** を追加し、平文 email 検索 (whereBlind/平文 where) を新設しない。

### コーディングルール
- **PHPStan level 10** (`composer phpstan`) / **Pest** (`composer test`) / **RefreshDatabase + --parallel** (グローバル適用、個別 `DatabaseTransactions` 禁止)
- テストデータは Factory 生成 (`OrganizationInvitationFactory` に必要な state は既存)
- Controller/Provider は薄く Service 委譲。保護キーは forceFill / relation
- フロントは Svelte 5 runes + DS token。フォームは FormField / Input atom 経由。アイコンは `@lucide/svelte`
- 検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`

## 概念設計リファレンス
- [conceptual-design.md](./conceptual-design.md)（概念設計 APPROVED / conceptual-review-round-3.md）

## セキュリティ判定結論（概念設計より要約）
- **列挙面は広げない**: active token の token_hash 照合成功時のみ email を返す。任意 email 存在照会の口を新設しない。
- **PII 開示は bearer token モデルで受容**: 開示権限は「active な bearer token の所持」。開示相手が招待相手本人であることは保証せず、リンク転送・誤送信時の第三者開示を**残余リスクとして受容**。token は受諾後無効化されるが受諾前は複数回閲覧可。開示は招待先 email 1 件のみ。
- **編集不可 (readonly)**: サーバ契約 (`MatchesInvitationEmail`) を UI に正直に反映。DESIGN.md #8 (ボタン disabled) には非抵触。
- 実装するのが妥当（scope 内）。**「実装済み」でも「スコープ外で実装不要」でもない**（register view は現在 `socialProviders` のみ渡しており prefill 未実装）。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | active 招待の単一解決口を model に集約 | `app/Models/OrganizationInvitation.php` / `app/Rules/MatchesInvitationEmail.php` / `app/Services/Organization/OrganizationMembershipService.php` | High (基盤) |
| S2 | register prefill resolver (session stale token 破棄付き) | `app/Services/Organization/OrganizationMembershipService.php` | High |
| S3 | Fortify registerView props に `invitationEmail` 追加 | `app/Providers/FortifyServiceProvider.php` | High |
| S4 | Register.svelte に prefill + readonly 描画 | `resources/js/pages/Auth/Register.svelte` | High |
| S5 | テスト (Feature + JS) | `tests/Feature/Auth/RegistrationInvitationPrefillTest.php`(新規) / `tests/js/pages/Register.test.ts` | High |

---

## S1: active 招待の単一解決口を model に集約

### 変更箇所
- `app/Models/OrganizationInvitation.php`: `findActiveByPlainToken()` static メソッド追加
- `app/Rules/MatchesInvitationEmail.php` (L40-50): 重複クエリを新メソッドへ寄せる
- `app/Services/Organization/OrganizationMembershipService.php::acceptInvitationIfValid()` (L149-156): 重複クエリを寄せる

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 既存 `tests/Feature/Organization/InvitationTest.php` の非退行を確認 (挙動不変のリファクタ)

### 現行コード（重複している active 解決ロジック）
```php
// MatchesInvitationEmail::validate()
$invitation = OrganizationInvitation::query()
    ->where('token_hash', OrganizationInvitation::hashToken($this->invitationToken))
    ->first();
if ($invitation === null) { return; }
if ($invitation->isAccepted() || $invitation->isRevoked() || $invitation->isExpired()) { return; }

// OrganizationMembershipService::acceptInvitationIfValid()
$invitation = OrganizationInvitation::query()
    ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
    ->first();
if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
    return null;
}
```

### 変更後コード
```php
// app/Models/OrganizationInvitation.php に追加
/**
 * 平文 token から「受諾可能 (active: 未受諾・未失効・期限内)」な招待を解決する。
 * token_hash 照合 + scopeActive のみ (平文 email 検索は行わない)。
 * active でない (不在/失効/取消/受諾済) 場合は null。
 *
 * MatchesInvitationEmail / acceptInvitationIfValid / register prefill resolver が共有し、
 * active 判定条件のドリフトを防ぐ単一解決口。
 * (POST 受諾 acceptInvitation() は revoked/accepted/expired を個別メッセージに出し分けるため本メソッドを使わない)
 */
public static function findActiveByPlainToken(string $plainToken): ?self
{
    return self::query()
        ->active()
        ->where('token_hash', self::hashToken($plainToken))
        ->first();
}
```
```php
// MatchesInvitationEmail::validate() — 置換後
if (! is_string($this->invitationToken) || $this->invitationToken === '') {
    return;
}
if (! is_string($value)) {
    return;
}
$invitation = OrganizationInvitation::findActiveByPlainToken($this->invitationToken);
if ($invitation === null) {
    return; // 不在/失効/取消/受諾済 → 後段の受諾処理が中立に扱う
}
if ($invitation->email !== $value) {
    $fail('招待されたメールアドレスと一致しません。招待メール記載のアドレスをご確認ください。');
}
```
```php
// OrganizationMembershipService::acceptInvitationIfValid() — 置換後
$invitation = OrganizationInvitation::findActiveByPlainToken($plainToken);
if ($invitation === null) {
    return null; // active でなければ join しない
}
// email 一致・既メンバー判定は現行のまま
```

### PHPStan適合チェック
- [x] 戻り値の型 `?self` を明示
- [x] `scopeActive` は `Builder<OrganizationInvitation>` を受ける既存 scope。`->active()` は magic scope 呼び出しで既存パターン (`scopeActive` 定義済)
- [x] null 安全: `->first()` は `?OrganizationInvitation`。呼び出し側は null 分岐済
- [x] 配列返却なし (model or null)

### テスト計画
- [x] 既存 `InvitationTest.php` が全 green (挙動不変リファクタの回帰保証)
- [x] `MatchesInvitationEmail` / `acceptInvitationIfValid` の既存 expired/revoked/accepted ケースが引き続き pass/null

### リスク
- `scopeActive` は `expires_at > now()` を使う。`isExpired()` は `expires_at->isPast()`。境界 (ちょうど now) の判定差は実務上無視できるが、**厳密同値を避けるため挙動同値** (どちらも「now 以下は expired」)。念のため既存テストで非退行確認。

---

## S2: register prefill resolver（session stale token 破棄付き）

### 変更箇所
- `app/Services/Organization/OrganizationMembershipService.php`: `resolveRegisterPrefillEmail()` 追加

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: S5 の Feature テストで GET 経路を通して検証

### 変更後コード
```php
use Illuminate\Contracts\Session\Session;

/**
 * register 画面のメール prefill 用に、session の invitation_token から
 * 「active な招待の招待先 email」を解決する。fail-secure:
 *  - session 値が非文字列/空 → forget して null
 *  - findActiveByPlainToken が null (不在/失効/取消/受諾済) → session から forget して null
 *    (GET 時点で stale/invalid な token を破棄し「UI は通常登録・サーバは招待フロー」の不整合を除去)
 *  - active → 招待先 email (CipherSweet 自動復号後は string) を返す
 *
 * 平文 email 検索は行わない (token_hash 照合のみ)。列挙面を広げない。
 */
public function resolveRegisterPrefillEmail(Session $session): ?string
{
    $raw = $session->get('invitation_token');

    if (! is_string($raw) || $raw === '') {
        if ($raw !== null) {
            $session->forget('invitation_token'); // 汚染値を除去
        }

        return null;
    }

    $invitation = OrganizationInvitation::findActiveByPlainToken($raw);
    if ($invitation === null) {
        $session->forget('invitation_token'); // stale/invalid を GET 時点で破棄

        return null;
    }

    $email = $invitation->email;
    Assert::string($email); // CipherSweet 復号後は string

    return $email;
}
```

### PHPStan適合チェック
- [x] 引数型 `Illuminate\Contracts\Session\Session` (具象 Store でなく contract)
- [x] 戻り値 `?string` 明示
- [x] `$session->get()` は `mixed` → `is_string` で narrow (fail-secure)
- [x] `$invitation->email` は CipherSweet 属性で復号後 `string`。`Assert::string()` で L10 に確定
- [x] 配列返却なし

### テスト計画
- [x] S5 Feature: active→email / expired・revoked・accepted→null かつ session forget / 非文字列 session→null かつ forget / token 無し→null

### リスク
- GET (この resolver) で forget した token は POST 到達前に消えるため、正常系 (active) では forget しない (POST の `CreateNewUser` が受諾に使う)。正常系で誤って forget しないことを Feature で保証。
- resolver は read + session mutation を行うが transaction 不要 (session forget は副作用のみ、DB 書き込みなし)。

---

## S3: Fortify registerView props に `invitationEmail` 追加

### 変更箇所
- `app/Providers/FortifyServiceProvider.php::configureViews()` (L180-182)

### 波及変更
- TypeScript 型定義: S4 の `Register.svelte` Props interface に `invitationEmail`
- API Resource/DTO: なし (Inertia props。DTO 化は過剰 = 既存 register view も plain array)
- テストファイル: S5 Feature

### 現行コード
```php
Fortify::registerView(static fn (): InertiaResponse => Inertia::render('Auth/Register', [
    'socialProviders' => array_keys(config()->array('template.social_providers')),
]));
```

### 変更後コード
```php
Fortify::registerView(static fn (Request $request): InertiaResponse => Inertia::render('Auth/Register', [
    'socialProviders' => array_keys(config()->array('template.social_providers')),
    // 招待リンク経由 (session に active token) の場合のみ招待先 email を prefill 用に渡す。
    // resolver 内で stale/invalid token は session から破棄される (fail-secure)。
    'invitationEmail' => app(OrganizationMembershipService::class)
        ->resolveRegisterPrefillEmail($request->session()),
]));
```
- import 追加: `use App\Services\Organization\OrganizationMembershipService;`
- closure は既に `Request` を受けられる (同ファイル `resetPasswordView` が `static function (Request $request)` の先例あり)。static closure のため DI は `app()` 解決 (先例と一致)。

### PHPStan適合チェック
- [x] `$request->session()` は `Illuminate\Contracts\Session\Session` を返す (resolver 引数型と一致)
- [x] `app(OrganizationMembershipService::class)` は具象型を返す (`app()` の generic 解決)
- [x] props 値は `?string` (null 許容)

### テスト計画
- [x] S5 Feature (GET /register を実際に通す。resolver 単体で代替しない)

### リスク
- register GET が毎回 session を読む (token 無しは即 null return、DB クエリ発生せず)。パフォーマンス影響は無視できる。

---

## S4: Register.svelte に prefill + readonly 描画

### 変更箇所
- `resources/js/pages/Auth/Register.svelte` (L13-25, L76-87)

### 波及変更
- TypeScript 型定義: `Props` interface に `invitationEmail?: string | null`
- テストファイル: S5 JS

### 現行コード
```svelte
interface Props {
    appName?: string;
    socialProviders?: string[];
}
let { appName, socialProviders = [] }: Props = $props();

const form = useForm({
    name: "", email: "", password: "", terms_accepted: false,
});
```
```svelte
<FormField label="メールアドレス" id="email" error={form.errors.email}>
    {#snippet children({ id, describedBy, invalid })}
        <Input {id} type="email" bind:value={form.email} error={invalid}
            aria-describedby={describedBy} autocomplete="email" />
    {/snippet}
</FormField>
```

### 変更後コード
```svelte
interface Props {
    appName?: string;
    socialProviders?: string[];
    invitationEmail?: string | null;
}
let { appName, socialProviders = [], invitationEmail = null }: Props = $props();

// 招待リンク経由 (invitationEmail あり) は招待先 email を初期値にし、以降 readonly で固定する。
const isInvited = $derived(invitationEmail != null && invitationEmail !== "");

const form = useForm({
    name: "",
    email: invitationEmail ?? "",
    password: "",
    terms_accepted: false,
});
```
```svelte
<FormField label="メールアドレス" id="email" error={form.errors.email}>
    {#snippet children({ id, describedBy, invalid })}
        <Input {id} type="email" bind:value={form.email} error={invalid}
            aria-describedby={describedBy} autocomplete="email" readonly={isInvited} />
    {/snippet}
</FormField>
{#if isInvited}
    <p class="text-sm text-muted-foreground -mt-2">
        招待されたメールアドレスで登録します。
    </p>
{/if}
```
- `Input` atom は `{...rest}` を native input へ透過するため `readonly` はそのまま反映 (atom 変更不要)。
- 補足文言は DS の text ramp / muted トークンを使う (`DESIGN.md` 準拠。hex 直書きしない)。実際のクラス名は既存 auth 画面の muted 文言に合わせる (実装時に `resources/css` の token を確認)。

### PHPStan適合チェック
- N/A (Svelte)。`pnpm typecheck` で Props 型を検証。

### テスト計画
- [x] S5 JS: `invitationEmail` あり → email input が `readonly` かつ value = 招待 email / 補足文言表示
- [x] S5 JS: `invitationEmail` なし → email input は readonly でない・空 (既存テスト非退行)

### リスク
- readonly でも `bind:value` は維持され POST に email が乗る (readonly は編集不可であり送信除外ではない)。念のため JS/Feature で prefill 値が送信対象であることを担保 (Feature の POST テストは既存 InvitationTest が担保済、本 prefill は値が form.email に入ることを JS で確認)。
- `autocomplete="email"` は readonly でも無害。

---

## S5: テスト（Feature + JS）

### 新規 Feature: `tests/Feature/Auth/RegistrationInvitationPrefillTest.php`
Factory: `OrganizationInvitation::factory()->createWithPlainToken()` / `->expired()` / `->revoked()` / `->accepted()` を使用。session への token 投入は `withSession(['invitation_token' => $token])` または `$this->get('/invitations/accept?token='.$token)` 経由。

- [ ] `active token を session に持つ GET /register → props invitationEmail = 招待先 email`
  （`$response->assertInertia(fn ($p) => $p->where('invitationEmail', $email))`）
- [ ] `expired token → invitationEmail = null かつ session に invitation_token が無い (forget)`
- [ ] `revoked token → invitationEmail = null かつ forget`
- [ ] `accepted token → invitationEmail = null かつ forget`
- [ ] `存在しない token (DB 不在) → invitationEmail = null かつ forget`（Round 3 Suggestion）
- [ ] `非文字列 session 値 (例: 配列) → invitationEmail = null かつ forget`（Round 3 Suggestion, fail-secure）
- [ ] `token 無し GET /register → invitationEmail = null` (通常登録非退行) + `socialProviders` 表示非退行
- [ ] `GET で active prefill 後、POST 前に revoke → POST /register は登録成立 (個人組織 fallback・招待非成立) + session token forget`
  （POST 順序: MatchesInvitationEmail no-op → user 作成 → acceptInvitationIfValid null → 個人組織 provision + signup grant → forget。組織メンバーシップに招待組織が含まれないこと・個人組織が生成されたことを assert）

### JS: `tests/js/pages/Register.test.ts` に追加
- [ ] `invitationEmail props あり → getByLabelText("メールアドレス") が readonly 属性を持ち value=招待 email / 補足文言表示`
- [ ] `invitationEmail props なし → email input は readonly でない` (既存テスト維持)

### 個別 `DatabaseTransactions` を使わない
- [x] `RefreshDatabase` はグローバル (`tests/Pest.php`)。新規テストで個別 trait を使わない。

---

## 使命・禁止事項チェック
- 使命寄与: 招待オンボーディング摩擦の低減 (チーム参加 → 標準作業マニュアル生成の協働に到達しやすくする)。
- 禁止事項: #4 非該当 (Inertia props)。#8 非該当 (readonly は入力欄であり送信ボタン disabled ではない)。テストなし完了なし (S5 必須)。PHPStan widen なし。
- セキュリティ不変条件 #6: 平文 email 検索を新設せず token_hash 照合のみ。CipherSweet at-rest は不変。bearer token PII 開示は明示受容。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存の register / 招待受諾フロー (T030) に対する小さな追加・リファクタ。新モデル/新テーブルなし。単一の worktree で S1→S5 を順に積む方が、S1 の共有解決口を S2 が使う依存関係を素直に扱える。 |
| 競合リスク | 低。`OrganizationMembershipService` / `FortifyServiceProvider` / `Register.svelte` はいずれも他 in-flight 施策との競合可能性が低い。S1 のリファクタは挙動不変で既存テストが保護。 |


---

## 関連する現行コード（抜粋）

### app/Models/OrganizationInvitation.php（抜粋: token/active 判定）
```php
public static function generateToken(): string { return Str::random(64); }
public static function hashToken(string $plainToken): string { return hash('sha256', $plainToken); }
public function isExpired(): bool { $expiresAt = $this->getAttribute('expires_at'); return $expiresAt->isPast(); }
public function isAccepted(): bool { return $this->accepted_at !== null; }
public function isRevoked(): bool { return $this->revoked_at !== null; }
public function scopeActive(Builder $query): void {
    $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
}
// email は CipherSweet 暗号化 + blind index (configureCipherSweet で addField('email')+addBlindIndex)
```

### app/Rules/MatchesInvitationEmail.php（現行 validate）
```php
public function validate(string $attribute, mixed $value, Closure $fail): void {
    if (! is_string($this->invitationToken) || $this->invitationToken === '') { return; }
    if (! is_string($value)) { return; }
    $invitation = OrganizationInvitation::query()
        ->where('token_hash', OrganizationInvitation::hashToken($this->invitationToken))->first();
    if ($invitation === null) { return; }
    if ($invitation->isAccepted() || $invitation->isRevoked() || $invitation->isExpired()) { return; }
    if ($invitation->email !== $value) {
        $fail('招待されたメールアドレスと一致しません。招待メール記載のアドレスをご確認ください。');
    }
}
```

### app/Actions/Fortify/CreateNewUser.php（招待経由登録の骨子）
```php
$invitationToken = $this->resolveInvitationToken(); // session invitation_token を fail-secure に string|null
// Validator: email に new MatchesInvitationEmail($invitationToken)
$user = DB::transaction(function () use (...) {
    $user = (new User([...]))->forceFill([...]); $user->save();
    $joined = $invitationToken !== null ? $this->membership->acceptInvitationIfValid($invitationToken, $user) : null;
    if ($joined === null) { $org = $this->provisioning->provisionPersonalOrganization($user); $this->tickets->grantSignupGrant($org); }
    return $user;
});
if ($invitationToken !== null) { session()->forget('invitation_token'); }
// resolveInvitationToken(): $session->get('invitation_token') が is_string && !=='' なら返す。非文字列は forget して null。
```

### app/Services/Organization/OrganizationMembershipService.php::acceptInvitationIfValid()（現行）
```php
$invitation = OrganizationInvitation::query()
    ->where('token_hash', OrganizationInvitation::hashToken($plainToken))->first();
if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) { return null; }
if ($invitation->email !== $user->email) { return null; }
// 既メンバーなら null。join 後、register 経路限定で current_organization_id を forceFill 確定。
```

### app/Providers/FortifyServiceProvider.php（現行 registerView と先例 resetPasswordView）
```php
Fortify::registerView(static fn (): InertiaResponse => Inertia::render('Auth/Register', [
    'socialProviders' => array_keys(config()->array('template.social_providers')),
]));
Fortify::resetPasswordView(static function (Request $request): InertiaResponse {
    $token = $request->route('token'); $email = $request->query('email');
    return Inertia::render('Auth/ResetPassword', ['token' => ..., 'email' => is_string($email) ? $email : null]);
});
```

### resources/js/components/atoms/Input.svelte（rest 透過を確認）
```svelte
interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class"> { type?; value?; error?; testId?; class?; }
let { type = "text", value = $bindable(""), error = false, testId, class: extraClass = "", ...rest }: Props = $props();
<input {type} bind:value class={computedClass} aria-invalid={error || undefined} data-testid={testId} {...rest} />
```

### resources/js/pages/Auth/Register.svelte（現行 email フィールド）
```svelte
let { appName, socialProviders = [] }: Props = $props();
const form = useForm({ name: "", email: "", password: "", terms_accepted: false });
// email: <Input type="email" bind:value={form.email} ... autocomplete="email" />
```

### database/factories/OrganizationInvitationFactory.php（利用可能な state）
```php
definition(): expires_at = now()->addDays(7)
createWithPlainToken(): array{0: OrganizationInvitation, 1: string} // 平文 token tuple
expired(): expires_at = now()->subDay()
accepted(): accepted_at = now()
revoked(): revoked_at = now()
```
