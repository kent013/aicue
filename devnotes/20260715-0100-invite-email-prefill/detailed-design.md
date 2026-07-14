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
    // active の定義は scopeActive が単一の正 (未受諾・未失効・期限内: expires_at > now)。
    // isExpired()/isAccepted()/isRevoked() の個別判定と概念的に一致させ、ドリフトを防ぐ。
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

    // CipherSweet 復号後は string の想定だが、Assert で 500 を招かず fail-secure に握る
    // (Round 1 [Warning] 反映: 想定外型は token を破棄して null 返却)。
    $email = $invitation->email;
    if (! is_string($email) || $email === '') {
        $session->forget('invitation_token');

        return null;
    }

    return $email;
}
```

### PHPStan適合チェック
- [x] 引数型 `Illuminate\Contracts\Session\Session` (具象 Store でなく contract)
- [x] 戻り値 `?string` 明示
- [x] `$session->get()` は `mixed` → `is_string` で narrow (fail-secure)
- [x] `$invitation->email` は CipherSweet 属性 (復号後 string 想定)。`is_string` narrow で L10 確定 + 想定外型を fail-secure に握る (Assert 依存を減らす)
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
use App\Services\Organization\OrganizationMembershipService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

Fortify::registerView(static function (Request $request): SymfonyResponse {
    // 招待リンク経由 (session に active token) の場合のみ招待先 email を prefill 用に解決する。
    // resolver 内で stale/invalid token は session から破棄される (fail-secure)。
    $invitationEmail = app(OrganizationMembershipService::class)
        ->resolveRegisterPrefillEmail($request->session());

    $response = Inertia::render('Auth/Register', [
        'socialProviders' => array_keys(config()->array('template.social_providers')),
        'invitationEmail' => $invitationEmail,
    ])->toResponse($request);

    // PII(招待先 email) を含む応答を HTTP キャッシュ(共有/中間プロキシ/ブラウザ)に
    // 保存させない (Round 1 [Critical] 反映: bearer token 由来 PII の運用 fail-safe)。
    // email を含まない通常登録応答には付けない (不要なキャッシュ抑止を避ける)。
    if ($invitationEmail !== null) {
        $response->headers->set('Cache-Control', 'no-store');
    }

    return $response;
});
```
- import 追加: `use App\Services\Organization\OrganizationMembershipService;` / `use Symfony\Component\HttpFoundation\Response as SymfonyResponse;`
- closure は `Request` を受ける (同ファイル `resetPasswordView` が `static function (Request $request)` の先例あり)。static closure のため DI は `app()` 解決 (先例と一致)。
- **`->toResponse($request)` を明示的に呼ぶ理由**: Fortify の `SimpleViewResponse::toResponse()` は callback が `Responsable` を返せば `->toResponse()` する / 素の `Response` を返せばそのまま返す。header (`Cache-Control`) を付与するため concrete `Response` を返す。`Inertia::render()->toResponse()` はフレームワーク内部と同一処理のため副作用なし (Inertia の version 交渉・partial reload も保持)。

### PHPStan適合チェック
- [x] `$request->session()` は `Illuminate\Contracts\Session\Session` を返す (resolver 引数型と一致)
- [x] `app(OrganizationMembershipService::class)` は具象型を返す (`app()` の generic 解決)
- [x] closure 戻り型 `Symfony\...\Response` を明示。`Inertia\Response::toResponse()` は `Illuminate\Http\Response|JsonResponse` (= Symfony Response 部分型) を返す
- [x] props 値 `invitationEmail` は `?string` (null 許容)

### セキュリティ / キャッシュ運用 (Round 1 [Critical] 反映)
- PII を含む register 応答 (`invitationEmail !== null`) に `Cache-Control: no-store` を付与し、HTTP キャッシュ (共有キャッシュ・中間プロキシ・ブラウザの HTTP キャッシュ) への保存を禁止する (Round 2 [Suggestion] 反映: bf-cache はブラウザ実装差があるため「HTTP キャッシュへの保存禁止」と正確に表現)。
- 既存 `SecurityHeaders` middleware は Cache-Control を設定しない (CSP/HSTS/nosniff 等のみ) ため、本 no-store は register view で明示付与する。
- 検証項目: S5 Feature で「invitationEmail あり応答は `Cache-Control: no-store`」「通常登録応答は no-store を付けない (非退行)」を assert する。

### リスク
- register GET が毎回 session を読む (token 無しは即 null return、DB クエリ発生せず)。パフォーマンス影響は無視できる。
- `->toResponse()` 明示化により Inertia 応答生成のタイミングが closure 内に移るが、Fortify 経路は元々 callback を `toResponse` で解決しており挙動同値。

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
- **readonly は UX 上の“誘導”に過ぎない (真正性はサーバが担保 — Round 1 [Warning] 反映)**: devtools で `readonly` を外し別 email を POST しても、サーバ側 `MatchesInvitationEmail` (active token がある間は招待 email 以外を 422) が真正性を強制する。フロントの prefill+readonly は「正しい値を先に入れて手入力ミスを防ぐ」ためのものであり、セキュリティ境界ではない。この責務分担をコメントに明記する。

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

- [ ] `active token を session に持つ GET /register → props invitationEmail = 招待先 email` **かつ 応答が no-store** **かつ active token は session に維持される (POST で招待参加できる回帰保護 — Round 2 [Warning] 反映)**
  （`$response->assertInertia(fn ($p) => $p->where('invitationEmail', $email))` + `$response->assertSessionHas('invitation_token', $token)` + Cache-Control が `no-store` ディレクティブを含むことを検証（完全一致でなく `str_contains` 相当。既存 middleware が別ディレクティブを足す可能性を許容））
- [ ] `expired token → invitationEmail = null かつ session に invitation_token が無い (forget)`
- [ ] `revoked token → invitationEmail = null かつ forget`
- [ ] `accepted token → invitationEmail = null かつ forget`
- [ ] `存在しない token (DB 不在) → invitationEmail = null かつ forget`（Round 3 Suggestion）
- [ ] `非文字列 session 値 (例: 配列) → invitationEmail = null かつ forget`（Round 3 Suggestion, fail-secure）
- [ ] `token 無し GET /register → invitationEmail = null` (通常登録非退行) + `socialProviders` props 表示 + **`Cache-Control: no-store` を付けない** (PII 無し応答の非退行)
- [ ] **`socialProviders` props は token 有り / 無し の両系統で存在する** (registerView 変更の副作用抑止 — Round 1 [Warning] 反映)
- [ ] `GET で active prefill 後、POST 前に revoke → POST /register は登録成立 (個人組織 fallback・招待非成立) + session token forget`（Round 1 [Critical] 反映で副作用まで固定）:
  - 招待組織のメンバーシップに当該ユーザーが**含まれない**こと
  - **個人組織が生成されている**こと (signup grant 付与済)
  - **`user->current_organization_id` が個人組織側**であること (招待組織側でない)
  - session に `invitation_token` が残っていないこと
  （POST 順序: MatchesInvitationEmail no-op → user 作成 → acceptInvitationIfValid null → 個人組織 provision + signup grant → forget）

### JS: `tests/js/pages/Register.test.ts` に追加
- [ ] `invitationEmail props あり → getByLabelText("メールアドレス") が readonly 属性を持ち value=招待 email / 補足文言表示`
- [ ] `invitationEmail props なし → email input は readonly でない` (既存テスト維持)
- [ ] `invitationEmail props なし (未指定 / null) → email input の初期値が空文字` (回帰強化 — Round 1 [Suggestion] 反映)

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
