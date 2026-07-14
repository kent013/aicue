## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（本 PR に関係する項）
- #1 tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
- #6 PII(email/name)は CipherSweet。検索は `whereBlind()`(平文 where は hit しない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## System: コードレビュアーとしての役割

あなたは Laravel + Svelte アプリの実装レビュアーである。以下の観点で厳格にレビューし、ファイルごとに判定を述べ、Critical / Warning / Suggestion に分類し、最後に全体判定 (APPROVED / CHANGES_REQUESTED) を明示せよ。

レビュー観点:
1. **設計との一致性**: 詳細設計書 (下記) の施策 S1〜S5 が正しく実装されているか
2. **正確性**: ロジックの誤り・エッジケース漏れ・fail-secure の破れ
3. **PHPStan 適合性 (level 10)**: 型安全性。widen/baseline/不要 cast がないか
4. **DTO/JsonResource/Inertia パターン**: `response()->json()` 直書きがないか (本 PR は Inertia props)
5. **テスト網羅性**: 正常系・異常系・fail-secure・非退行が Feature/JS で担保されているか
6. **セキュリティ**: PII (招待 email) 開示範囲、列挙面、token 照合方式、キャッシュ制御
7. **DESIGN.md 準拠**: color/radius/typography は token 経由。hex 直書きを増やしていないか
8. **Atomic Design 準拠**: `resources/js/components/` の atoms/molecules/organisms 責務分離。アイコンは Lucide、SVG 直書きを増やさない。単方向 import

本タスクはセキュリティ判定を伴う (招待 email の PII を未認証 register 画面に prefill する)。概念設計での判定結論は「active token の token_hash 照合成功時のみ email を返す。列挙面は広げない。bearer token モデルで PII 開示を受容 (リンク転送・誤送信時の第三者開示は残余リスクとして受容)」である。この判定の妥当性と実装の整合も評価せよ。

---

## User

### 詳細設計書 (要約)

施策一覧:
- **S1**: active 招待の単一解決口を model に集約。`OrganizationInvitation::findActiveByPlainToken()` を新設し、`MatchesInvitationEmail` と `acceptInvitationIfValid()` の重複 active 判定を寄せる (挙動不変リファクタ)。`scopeActive` (未受諾・未失効・期限内 `expires_at > now`) + token_hash 照合のみ。
- **S2**: `OrganizationMembershipService::resolveRegisterPrefillEmail(Session)` を新設。session の invitation_token を fail-secure に解決し active 招待の email を返す。非文字列/空/stale/invalid は forget して null。正常系 (active) は forget しない (後続 POST が受諾に使う)。平文 email 検索は行わない (token_hash 照合のみ、列挙面を広げない)。
- **S3**: Fortify registerView props に `invitationEmail` を追加。resolver で解決し、`invitationEmail !== null` の応答にのみ `Cache-Control: no-store` を付与 (PII を HTTP キャッシュに保存させない)。`->toResponse($request)` を明示呼び出しして concrete Response に header 付与。
- **S4**: `Register.svelte` に prefill + readonly 描画。`invitationEmail` prop を email 初期値にし、あれば readonly + 補足文言。readonly は UX 誘導でありセキュリティ境界ではない (サーバの MatchesInvitationEmail が真正性を強制)。
- **S5**: Feature テスト (`RegistrationInvitationPrefillTest`) + JS テスト (`Register.test.ts`) 追加。

セキュリティ判定結論 (概念設計):
- 列挙面を広げない: active token の token_hash 照合成功時のみ email を返す。任意 email 存在照会の口を新設しない。
- PII 開示は bearer token モデルで受容。開示相手が招待相手本人であることは保証しない (リンク転送・誤送信時の第三者開示を残余リスクとして受容)。開示は招待先 email 1 件のみ。token は受諾後無効化されるが受諾前は複数回閲覧可。
- readonly は編集不可契約 (MatchesInvitationEmail) を UI に反映。DESIGN.md #8 (ボタン disabled) には非抵触 (入力欄の readonly でありボタン disabled ではない)。

### 補足: 既存コードの前提
- `MatchesInvitationEmail` rule: session の invitation_token と登録 email が不一致なら 422。不在/失効/受諾済/取消は pass (後段の受諾処理が中立処理)。今回 active 判定を `findActiveByPlainToken` に寄せた。
- `CreateNewUser` (Fortify): POST /register で session invitation_token を fail-secure 解決 → `MatchesInvitationEmail` 検証 → `acceptInvitationIfValid` で招待組織参加 or 個人組織 fallback。登録確定後 session の invitation_token を forget。
- `InvitationAcceptanceController::show` (GET): 未ログイン + 有効招待で `session put('invitation_token', $token)` して register へ redirect。
- `FormField` molecule: `help` prop を渡すと `<p class="text-caption text-text-secondary">` でヘルプ文言を DS token で描画し `aria-describedby` に配線する (今回この既存機構を prefill 補足文言に流用)。
- `Input` atom: `{...rest}` を native input へ透過するため `readonly` はそのまま反映 (atom 変更不要)。

### design system 参照 (DESIGN.md 抜粋)
- text ramp: `text-caption` (12px/400) 等の @utility。secondary text color は `--color-text-secondary: #52525b` (token)。
- 本 PR は色/radius/typography の token を新規追加・変更しない。hex 直書きなし。補足文言は既存 `FormField.help` 経由 (`text-caption text-text-secondary`)。
- atomic 階層: `atoms → molecules → organisms → features/{domain} → templates → pages`。本 PR は pages (`Auth/Register.svelte`) が molecules (`FormField`) / atoms (`Input`) を使うのみ (単方向、逆流なし)。新規 component 追加なし。SVG/アイコン追加なし。

### 品質ゲート結果
- `composer phpstan`: OK (No errors, level 10)
- `composer test` (--parallel, 全体): 1764 passed / 1 failed → 失敗は Architecture drift-guard `MembershipWriteLockInventoryTest` が新規メソッド `resolveRegisterPrefillEmail` の分類登録を要求したもの。read-only (membership/role/DB 書き込みなし) として `exempt` に根拠付き登録し解消済み (再実行 green)。
- 新規 Feature `RegistrationInvitationPrefillTest`: 8 passed
- `vendor/bin/pint --test`: passed / `pnpm lint`: passed / `pnpm typecheck`: passed / `pnpm build`: passed
- `pnpm test` (JS 全体、Register.test.ts 含む): 単独再実行で green (初回は PHP 並列実行との資源競合で timeout flake)

### 実装差分 (git diff)

```diff
（下記は app/ resources/ tests/ の全差分）
```

diff --git a/app/Models/OrganizationInvitation.php b/app/Models/OrganizationInvitation.php
index df1cbcc..7394bb0 100644
--- a/app/Models/OrganizationInvitation.php
+++ b/app/Models/OrganizationInvitation.php
@@ -60,6 +60,26 @@ public static function hashToken(string $plainToken): string
         return hash('sha256', $plainToken);
     }
 
+    /**
+     * 平文 token から「受諾可能 (active: 未受諾・未失効・期限内)」な招待を解決する。
+     * token_hash 照合 + scopeActive のみ (平文 email 検索は行わない = 列挙面を広げない)。
+     * active でない (不在/失効/取消/受諾済) 場合は null。
+     *
+     * MatchesInvitationEmail / acceptInvitationIfValid / register prefill resolver が共有し、
+     * active 判定条件のドリフトを防ぐ単一解決口。
+     * (POST 受諾 acceptInvitation() は revoked/accepted/expired を個別メッセージに出し分けるため
+     *  本メソッドを使わない)
+     */
+    public static function findActiveByPlainToken(string $plainToken): ?self
+    {
+        // active の定義は scopeActive が単一の正 (未受諾・未失効・期限内: expires_at > now)。
+        // isExpired()/isAccepted()/isRevoked() の個別判定と概念的に一致させ、ドリフトを防ぐ。
+        return self::query()
+            ->active()
+            ->where('token_hash', self::hashToken($plainToken))
+            ->first();
+    }
+
     public static function configureCipherSweet(EncryptedRow $encryptedRow): void
     {
         $encryptedRow
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 0e2d6d9..be3b830 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -17,6 +17,7 @@
 use App\Http\Responses\Fortify\RegisterResponse;
 use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
 use App\Http\Responses\Fortify\VerificationNotificationSentResponse;
+use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Cache\RateLimiting\Limit;
 use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Http\RedirectResponse;
@@ -40,6 +41,7 @@
 use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
 use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
 use Laravel\Fortify\Fortify;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
 
 class FortifyServiceProvider extends ServiceProvider
 {
@@ -177,9 +179,26 @@ private function configureViews(): void
             'socialProviders' => array_keys(config()->array('template.social_providers')),
         ]));
 
-        Fortify::registerView(static fn (): InertiaResponse => Inertia::render('Auth/Register', [
-            'socialProviders' => array_keys(config()->array('template.social_providers')),
-        ]));
+        Fortify::registerView(static function (Request $request): SymfonyResponse {
+            // 招待リンク経由 (session に active token) の場合のみ招待先 email を prefill 用に解決する。
+            // resolver 内で stale/invalid token は session から破棄される (fail-secure)。
+            $invitationEmail = app(OrganizationMembershipService::class)
+                ->resolveRegisterPrefillEmail($request->session());
+
+            $response = Inertia::render('Auth/Register', [
+                'socialProviders' => array_keys(config()->array('template.social_providers')),
+                'invitationEmail' => $invitationEmail,
+            ])->toResponse($request);
+
+            // PII (招待先 email) を含む応答を HTTP キャッシュ (共有/中間プロキシ/ブラウザの
+            // HTTP キャッシュ) に保存させない (bearer token 由来 PII の運用 fail-safe)。
+            // email を含まない通常登録応答には付けない (不要なキャッシュ抑止を避ける)。
+            if ($invitationEmail !== null) {
+                $response->headers->set('Cache-Control', 'no-store');
+            }
+
+            return $response;
+        });
 
         Fortify::requestPasswordResetLinkView(
             static fn (): InertiaResponse => Inertia::render('Auth/ForgotPassword'),
diff --git a/app/Rules/MatchesInvitationEmail.php b/app/Rules/MatchesInvitationEmail.php
index 8f98f41..d72b952 100644
--- a/app/Rules/MatchesInvitationEmail.php
+++ b/app/Rules/MatchesInvitationEmail.php
@@ -36,20 +36,13 @@ public function validate(string $attribute, mixed $value, Closure $fail): void
             return;
         }
 
-        // 平文 token は DB 非保存。sha256 hash で照合する
-        /** @var OrganizationInvitation|null $invitation */
-        $invitation = OrganizationInvitation::query()
-            ->where('token_hash', OrganizationInvitation::hashToken($this->invitationToken))
-            ->first();
+        // 平文 token は DB 非保存。active 判定は findActiveByPlainToken に集約 (単一解決口)。
+        // 不在/失効/受諾済/取り消しはここでは弾かず、後段の受諾処理が中立メッセージで扱う。
+        $invitation = OrganizationInvitation::findActiveByPlainToken($this->invitationToken);
         if ($invitation === null) {
             return;
         }
 
-        // 受諾不能 (失効/受諾済/取り消し) は後段の受諾処理が中立メッセージで扱う
-        if ($invitation->isAccepted() || $invitation->isRevoked() || $invitation->isExpired()) {
-            return;
-        }
-
         if ($invitation->email !== $value) {
             $fail('招待されたメールアドレスと一致しません。招待メール記載のアドレスをご確認ください。');
         }
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index ff2edc4..fa01900 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -15,6 +15,7 @@
 use App\Services\Notification\NotificationCenterService;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
+use Illuminate\Contracts\Session\Session;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Support\Collection;
 use Illuminate\Support\Facades\DB;
@@ -146,12 +147,9 @@ public function acceptInvitation(string $plainToken, User $user): Organization
      */
     public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
     {
-        $invitation = OrganizationInvitation::query()
-            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
-            ->first();
-
-        // active (未受諾・未失効・期限内) でなければ join しない
-        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
+        // active (未受諾・未失効・期限内) 解決は findActiveByPlainToken に集約 (単一解決口)。
+        $invitation = OrganizationInvitation::findActiveByPlainToken($plainToken);
+        if ($invitation === null) {
             return null;
         }
 
@@ -184,6 +182,49 @@ public function acceptInvitationIfValid(string $plainToken, User $user): ?Organi
         return $organization;
     }
 
+    /**
+     * register 画面のメール prefill 用に、session の invitation_token から
+     * 「active な招待の招待先 email」を解決する。fail-secure:
+     *  - session 値が非文字列/空 → forget して null
+     *  - findActiveByPlainToken が null (不在/失効/取消/受諾済) → session から forget して null
+     *    (GET 時点で stale/invalid な token を破棄し「UI は通常登録・サーバは招待フロー」の
+     *    不整合を除去する)
+     *  - active → 招待先 email (CipherSweet 自動復号後は string) を返す
+     *
+     * 平文 email 検索は行わない (token_hash 照合のみ)。列挙面を広げない。
+     * 正常系 (active) では forget しない: 後続 POST の CreateNewUser が受諾に token を使う。
+     */
+    public function resolveRegisterPrefillEmail(Session $session): ?string
+    {
+        $raw = $session->get('invitation_token');
+
+        if (! is_string($raw) || $raw === '') {
+            if ($raw !== null) {
+                $session->forget('invitation_token'); // 汚染値を除去
+            }
+
+            return null;
+        }
+
+        $invitation = OrganizationInvitation::findActiveByPlainToken($raw);
+        if ($invitation === null) {
+            $session->forget('invitation_token'); // stale/invalid を GET 時点で破棄
+
+            return null;
+        }
+
+        // CipherSweet 復号後の email。空文字 (想定外の欠損) は fail-secure に握り、
+        // token を破棄して null 返却する (prefill しない)。
+        $email = $invitation->email;
+        if ($email === '') {
+            $session->forget('invitation_token');
+
+            return null;
+        }
+
+        return $email;
+    }
+
     /**
      * 招待の取り消し (論理失効)。行削除ではなく revoked_at を立てる (監査痕跡を残す)。
      * 既に失効/受諾済みなら冪等 no-op (二重取り消しを例外にしない)。
diff --git a/resources/js/pages/Auth/Register.svelte b/resources/js/pages/Auth/Register.svelte
index 38b200e..8810238 100644
--- a/resources/js/pages/Auth/Register.svelte
+++ b/resources/js/pages/Auth/Register.svelte
@@ -13,13 +13,20 @@
     interface Props {
         appName?: string;
         socialProviders?: string[];
+        invitationEmail?: string | null;
     }
 
-    let { appName, socialProviders = [] }: Props = $props();
+    let { appName, socialProviders = [], invitationEmail = null }: Props = $props();
+
+    // 招待リンク経由 (invitationEmail あり) は招待先 email を初期値にし、以降 readonly で固定する。
+    // readonly は UX 上の "誘導" に過ぎない: devtools で外して別 email を POST しても、サーバの
+    // MatchesInvitationEmail (active token がある間は招待 email 以外を 422) が真正性を強制する。
+    // prefill + readonly は「正しい値を先に入れて手入力ミスを防ぐ」ためのものでセキュリティ境界ではない。
+    const isInvited = $derived(invitationEmail != null && invitationEmail !== "");
 
     const form = useForm({
         name: "",
-        email: "",
+        email: invitationEmail ?? "",
         password: "",
         terms_accepted: false,
     });
@@ -73,7 +80,12 @@
             {/snippet}
         </FormField>
 
-        <FormField label="メールアドレス" id="email" error={form.errors.email}>
+        <FormField
+            label="メールアドレス"
+            id="email"
+            error={form.errors.email}
+            help={isInvited ? "招待されたメールアドレスで登録します。" : undefined}
+        >
             {#snippet children({ id, describedBy, invalid })}
                 <Input
                     {id}
@@ -82,6 +94,7 @@
                     error={invalid}
                     aria-describedby={describedBy}
                     autocomplete="email"
+                    readonly={isInvited}
                 />
             {/snippet}
         </FormField>
diff --git a/tests/Architecture/MembershipWriteLockInventoryTest.php b/tests/Architecture/MembershipWriteLockInventoryTest.php
index e3b434a..01084ee 100644
--- a/tests/Architecture/MembershipWriteLockInventoryTest.php
+++ b/tests/Architecture/MembershipWriteLockInventoryTest.php
@@ -26,6 +26,9 @@
         'revokeInvitation', // 招待の論理失効のみ (membership/role 不変)
         // 読み取り専用判定 (ロック不要・表示スナップショット)。deleteAccount がロック下で権威判定する
         'organizationsBlockingDeletion',
+        // register prefill 用の read + session forget のみ (membership/role/DB 書き込みなし)。
+        // token_hash 照合で active 招待の email を返すだけで、共通ロック規約の対象外。
+        'resolveRegisterPrefillEmail',
     ];
 
     $reflection = new ReflectionClass(OrganizationMembershipService::class);
diff --git a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
new file mode 100644
index 0000000..34837d5
--- /dev/null
+++ b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
@@ -0,0 +1,167 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use App\Services\Billing\TicketLedgerService;
+use Inertia\Testing\AssertableInertia;
+
+/**
+ * 招待経由の register 画面での招待 email prefill (T055)。
+ *
+ * - active token を session に持つ GET /register は招待先 email を prop `invitationEmail` に返し、
+ *   PII を含むため応答に Cache-Control: no-store を付ける。active token は session に維持される
+ *   (後続 POST の受諾に必要)。
+ * - stale/invalid token (失効/取消/受諾済/不在/非文字列) は GET 時点で null + session forget。
+ * - token 無し (通常登録) は prop null かつ no-store を付けない (非退行)。
+ */
+
+/**
+ * 招待先 email に固定した active 招待を作り、平文 token を session に載せた状態を作る。
+ *
+ * @return array{OrganizationInvitation, string, string, Organization}
+ */
+function makeInvitationWithToken(string $email = 'invitee@example.com'): array
+{
+    [$organization] = createOrganizationWithOwner();
+    /** @var OrganizationInvitation $invitation */
+    [$invitation, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => $email]);
+
+    return [$invitation, $token, $email, $organization];
+}
+
+test('active token を session に持つ GET /register は招待 email を prefill し no-store を付け token を維持する', function (): void {
+    [, $token, $email] = makeInvitationWithToken();
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(
+            fn (AssertableInertia $page) => $page
+                ->component('Auth/Register')
+                ->where('invitationEmail', $email)
+                ->has('socialProviders'),
+        );
+
+    // PII を含むため HTTP キャッシュへの保存を禁止する
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+
+    // active token は POST 受諾のため session に維持される (GET で forget しない)
+    $response->assertSessionHas('invitation_token', $token);
+});
+
+test('expired token → invitationEmail null かつ session から forget', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->expired()
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('revoked token → invitationEmail null かつ forget', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->revoked()
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('accepted token → invitationEmail null かつ forget', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->accepted()
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('存在しない token (DB 不在) → invitationEmail null かつ forget', function (): void {
+    $response = $this->withSession(['invitation_token' => 'nonexistent-token'])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('非文字列 session 値 (配列) → invitationEmail null かつ forget (fail-secure)', function (): void {
+    $response = $this->withSession(['invitation_token' => ['tampered']])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('token 無し GET /register は invitationEmail null・socialProviders あり・no-store を付けない', function (): void {
+    $response = $this->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(
+            fn (AssertableInertia $page) => $page
+                ->component('Auth/Register')
+                ->where('invitationEmail', null)
+                ->has('socialProviders'),
+        );
+
+    // PII を含まない通常応答には no-store を付けない (不要なキャッシュ抑止を避ける)
+    expect((string) $response->headers->get('Cache-Control'))->not->toContain('no-store');
+});
+
+test('GET で active prefill 後 POST 前に revoke されても登録は成立し個人組織へ fallback する', function (): void {
+    [$invitation, $token, $email, $organization] = makeInvitationWithToken('fallback@example.com');
+
+    // GET: active なので prefill され token は維持される
+    $this->withSession(['invitation_token' => $token])->get('/register')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', $email));
+
+    // POST 前に招待が取り消される
+    $invitation->forceFill(['revoked_at' => now()])->save();
+
+    // POST: MatchesInvitationEmail は no-op (active 不在) → 登録成立 → 招待受諾は null → 個人組織 fallback
+    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
+        'name' => '山田 太郎',
+        'email' => $email,
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ]);
+
+    $response->assertRedirect(route('verification.notice'));
+    $this->assertAuthenticated();
+
+    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();
+
+    // 招待組織のメンバーシップには含まれない
+    expect($organization->users()->whereKey($user->getKey())->exists())->toBeFalse();
+
+    // 個人組織が生成され signup grant 済み
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+    expect(app(TicketLedgerService::class)->balance($personalOrg))
+        ->toBe(config()->integer('billing.signup_grant_tickets'));
+
+    // current_organization_id は個人組織側 (招待組織側でない)
+    expect($user->current_organization_id)->toBe($personalOrg->id);
+
+    // session の invitation_token は登録確定で forget されている
+    $response->assertSessionMissing('invitation_token');
+});
diff --git a/tests/js/pages/Register.test.ts b/tests/js/pages/Register.test.ts
index 7c586c2..bbbbf21 100644
--- a/tests/js/pages/Register.test.ts
+++ b/tests/js/pages/Register.test.ts
@@ -49,4 +49,38 @@ describe("Auth/Register", () => {
         );
         expect(screen.queryByText("利用規約への同意が必要です。")).toBeNull();
     });
+
+    it("invitationEmail props あり → email 欄が readonly で招待 email を prefill し補足文言を表示する", () => {
+        render(Register, {
+            props: {
+                appName: "My App",
+                socialProviders: [],
+                invitationEmail: "invited@example.com",
+            },
+        });
+
+        const email = screen.getByLabelText("メールアドレス");
+        expect(email).toHaveAttribute("readonly");
+        expect(email).toHaveValue("invited@example.com");
+        expect(screen.getByText("招待されたメールアドレスで登録します。")).toBeInTheDocument();
+    });
+
+    it("invitationEmail props なし → email 欄は readonly でなく空 (通常登録)", () => {
+        render(Register, { props: { appName: "My App", socialProviders: [] } });
+
+        const email = screen.getByLabelText("メールアドレス");
+        expect(email.hasAttribute("readonly")).toBe(false);
+        expect(email).toHaveValue("");
+        expect(screen.queryByText("招待されたメールアドレスで登録します。")).toBeNull();
+    });
+
+    it("invitationEmail = null → email 欄は readonly でなく空 (回帰強化)", () => {
+        render(Register, {
+            props: { appName: "My App", socialProviders: [], invitationEmail: null },
+        });
+
+        const email = screen.getByLabelText("メールアドレス");
+        expect(email.hasAttribute("readonly")).toBe(false);
+        expect(email).toHaveValue("");
+    });
 });

