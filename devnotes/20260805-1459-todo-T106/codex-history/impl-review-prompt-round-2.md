# Round 2: Round 1 指摘への対応

以下の対応マトリクスに従って修正した。**再レビューして全体判定を出してほしい**。

---

# 対応マトリクス: impl-review Round 1

## [Critical] recent-auth/status に passkey satisfier が含まれていない (passkey-only ユーザーが stale から回復できない)

- 判断: **対応する**
- 根拠: 指摘のとおり実害がある。`canSatisfy = passwordSet || providers` のままだと、
  passkey しか持たないユーザー (SSO 未連携 + password なし) が
  - インラインモーダル: 「再認証手段が設定されていません」+ パスワードリセット導線 (踏破不能)
  - 全画面 confirm: 同上 + ログアウト誘導
  という **行き止まり**に落ちる。パスキーは実際に satisfier として成立するのに、
  UI 判定だけが古い契約に取り残されていた。
  「画面ごとに判定を持たせない (サーバの status を単一の源にする)」という指摘の原則も正しい。
- 対応内容:
  - `RecentAuthStatusDto` / `RecentAuthStatusResource` に `passkeyAvailable` を追加
  - `ConfirmRecentAuthController::buildStatus()` が
    `Features::enabled(Features::passkeys()) && $user->passkeys()->exists()` で算出し、
    **`canSatisfy` に算入**する (feature off では route ごと消えるため fail-closed で false)
  - `show()` (Inertia prop) にも `passkeyAvailable` を渡す
  - `resources/js/lib/recent-auth.ts` の `RecentAuthStatus` に `passkeyAvailable` を追加
  - `RecentAuthModal` の `passkeyAvailable` prop を **status 由来**に切り替え
    (Security page が `passkeys.length > 0` から手渡ししていたのをやめた)
  - **全画面 confirm 画面 (`Auth/ConfirmRecentAuth.svelte`) にもパスキー導線を追加**。
    こちらは 302 fallback 着地で元 URL がサーバの `url.intended` にしか無いため、
    fetch ではなく **Inertia `router.post('/passkeys/confirm')`** で送り
    `PasskeyConfirmationResponse` の `redirect()->intended()` 分岐に乗せる
    (そのために `confirmPasskeyCredential()` = ceremony のみ実行して送信しない export を追加)
  - テスト: `tests/Feature/Auth/RecentAuthTest.php` に 5 本
    (passkeyAvailable=true / passkey しか無くても canSatisfy=true / TOTP 有効でも再認証には使える /
     feature off で false かつ canSatisfy=false / confirm 画面 prop)、
    `tests/js/pages/ConfirmRecentAuthPasskey.test.ts` 5 本、
    `tests/js/pages/SettingsSecurityPasskey.test.ts` に status 由来の on/off 2 本

## [Critical] passkey 登録の positive path テストが無い / payload shape が設計と食い違う

- 判断: **対応する** (ただし shape は実装が正しく、**設計書のほうが誤り**)
- 根拠: vendor の `Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest::rules()` は
  `credential` (array) + `credential.id` / `credential.rawId` / `credential.type` /
  `credential.response` を要求する **nested** 形。設計書の `{ name, ...credential }`
  (flat 展開) だと `credential` が欠落して validation で落ちる。
  実装は `{ name, credential }` で正しいが、**その正しさを固定するテストが無かった**のが
  指摘の本体であり、これは正当。
- 対応内容:
  - サーバ側: `tests/Feature/Auth/PasskeyRouteAccessTest.php` に 2 本
    (flat 形は `credential` の validation error / nested 形は rules を通過して
     ceremony 検証まで進む) を追加し **vendor 契約を pin**
  - client 側: `tests/js/pages/SettingsSecurityPasskey.test.ts` に登録 positive path 4 本
    (`router.post('/user/passkeys', { name, credential })` の payload 固定 /
     stale なら ceremony を開始しない / cancel は騒がない / failed はトースト + POST しない)
  - ceremony 自体はラッパをモックし、送信 payload の shape だけを固定する
    (実 ceremony は仮想認証器が Chromium 限定で iOS Safari を再現できないため自動化しない方針。
     `docs/supported-browsers.md` に明記済み)

## [Critical] `SecurityEventType::PasswordChanged` の記録にテストが無い

- 判断: **対応する**
- 根拠: 監査経路も「テストなしの実装完了」に当たる (禁止事項 1)。
- 対応内容: `tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php` に 2 本
  (PUT /user/password 成功で `security_audit_events` に `password_changed` が入る /
   current_password 不一致の失敗時は記録しない = fail-closed)

## [Warning] `createPasskeyCredential()` の payload shape 検証が呼び出し側任せ

- 判断: **対応する** (上記 Critical 2 の client 側テストで解消)

## [Warning] passkey login deny 経路が event 直 dispatch の近似でしか固定されていない

- 判断: **部分的に対応する**
- 根拠: `Passkeys::allowsLogin()` の deny を実 HTTP で踏むには、**成立する WebAuthn assertion**
  (署名検証を通る credential) が必要で、仮想認証器なしでは作れない。
  一方「login route が guest session に鮮度を残さない」という**不変条件そのもの**は
  ceremony 前段の失敗でも観測できる。
- 対応内容: `tests/Feature/Auth/PasskeyRouteAccessTest.php` に
  「`POST /passkeys/login` の失敗は guest session に `recent_auth_at` を残さない + guest のまま」
  を追加 (実 vendor controller + 実 listener 配線を通る統合境界)。
  deny 分岐そのものは `Passkeys::allowsLogin()` の直接検証
  (`PasskeyTwoFactorInteractionTest`) と、guest 文脈での `PasskeyVerified` 非 stamp
  (`RecentAuthMethodStampingTest`) の 2 本で挟む。

## [Warning] 登録経路での recent-auth 失効が実経路で保証されていない

- 判断: **見送る** (理由を明示して受容)
- 根拠: 登録の実 HTTP 経路は成立する attestation を要し自動化できない。
  失効そのものは listener の契約テストで固定済みで、削除側は実 HTTP 経路で通っている。
  `PasskeyRegistered` を dispatch するのは vendor の `StorePasskey` のみ (実装確認済み) で、
  アプリ側の責務は listener の配線に限られる。

## [Warning] docs (architecture / factories / supported-browsers / template-divergence) が diff に無い

- 判断: **反論する** (指摘は diff の切り出し漏れによる誤検出)
- 根拠: Round 1 に添付した diff は `app/ resources/ tests/ routes/ config/ database/ bootstrap/ .env.example`
  に絞っており `docs/` を含めていなかった。実際には 4 ファイルとも更新済み:
  - `docs/template-divergence.md` に **D13** (phantom password の前方修正) を追加
  - `docs/auth-security-mechanisms.md` に **§5 パスキー** / **§6 ログイン手段保持 guard** を追加
  - `docs/architecture.md` に `Passkey` モデル / `LoginMethodInventory` / `PasskeyLoginPolicy` を追加
  - `docs/factories.md` に `PasskeyFactory` を追加
  - `docs/supported-browsers.md` に「パスキーの保証範囲」節 + 実機受入確認の再確認条件を追加
- 対応内容: Round 2 で docs の diff を添付する。

## [Warning] テストの穴 (登録 positive / passkey-only stale / PasswordChanged)

- 判断: **対応する** (上記 3 Critical の対応で解消)


---

## 修正後の差分 (1) recent-auth status への passkey satisfier 追加 + confirm 画面の passkey 導線

`git diff HEAD` の該当ファイルのみ抜粋
(`RecentAuthStatusDto` / `RecentAuthStatusResource` / `ConfirmRecentAuthController` /
 `lib/recent-auth.ts` / `lib/passkeys.ts` / `RecentAuthModal.svelte` /
 `Auth/ConfirmRecentAuth.svelte` / `Settings/Security.svelte`)

```diff
diff --git a/app/DataTransferObjects/Auth/RecentAuthStatusDto.php b/app/DataTransferObjects/Auth/RecentAuthStatusDto.php
index 0d43a4c..fad87c9 100644
--- a/app/DataTransferObjects/Auth/RecentAuthStatusDto.php
+++ b/app/DataTransferObjects/Auth/RecentAuthStatusDto.php
@@ -19,6 +19,10 @@ public function __construct(
         public bool $recent,
         public bool $passwordSet,
         public array $availableProviders,
+        // パスキーで再認証できるか (登録済み credential が 1 件以上あるか)。
+        // **ログイン可否 (PasskeyLoginPolicy) とは別**: TOTP 有効ユーザーは passkey で
+        // ログインできないが、再認証 (POST /passkeys/confirm) には使える。
+        public bool $passkeyAvailable,
         public bool $canSatisfy,
         // 契約: recent===true ⇒ confirmedAt は session の recent_auth_at (unix epoch 秒)。
         // recent===false (未設定 / stale) は一律 null で fail-closed に倒す。
diff --git a/app/Http/Controllers/Auth/ConfirmRecentAuthController.php b/app/Http/Controllers/Auth/ConfirmRecentAuthController.php
index ecc9625..8f77d63 100644
--- a/app/Http/Controllers/Auth/ConfirmRecentAuthController.php
+++ b/app/Http/Controllers/Auth/ConfirmRecentAuthController.php
@@ -20,6 +20,7 @@
 use Illuminate\Validation\ValidationException;
 use Inertia\Inertia;
 use Inertia\Response as InertiaResponse;
+use Laravel\Fortify\Features;
 use Webmozart\Assert\Assert;
 
 /**
@@ -30,6 +31,9 @@
  *     SSO-only (password 未設定) は **fail-closed** で拒否。
  *   - 再SSO は SocialAuthController の step-up intent (`/auth/{provider}/redirect/step-up`)。
  *     成立時の鮮度更新はそちらで RecentAuthState 経由で行う。
+ *   - パスキー検証 (`POST /passkeys/confirm`)。成立時の鮮度更新は
+ *     StampRecentAuthOnPasskeyVerified が行う。**passkey しか持たないユーザーを
+ *     この画面で詰ませない**ため、passkeyAvailable は canSatisfy に算入する。
  *
  * `status` はクライアント主導モーダル (precheck) の UI 補助。最終 gate は RequireRecentAuth。
  */
@@ -56,6 +60,7 @@ public function show(Request $request): InertiaResponse
                 ],
                 $status->availableProviders,
             ),
+            'passkeyAvailable' => $status->passkeyAvailable,
             'canSatisfy' => $status->canSatisfy,
         ]);
     }
@@ -153,7 +158,12 @@ private function buildStatus(User $user): RecentAuthStatusDto
             );
         }
 
-        $canSatisfy = $passwordSet || $providers !== [];
+        // パスキーは登録済みなら **TOTP の有無に関係なく** 再認証に使える
+        // (PasskeyLoginPolicy が縛るのは login のみ)。feature off では route ごと消えるため
+        // 手段として数えない (fail-closed)。
+        $passkeyAvailable = Features::enabled(Features::passkeys()) && $user->passkeys()->exists();
+
+        $canSatisfy = $passwordSet || $providers !== [] || $passkeyAvailable;
 
         $recentAuthAt = session()->get('recent_auth_at');
         $recent = RecentAuthWindow::isFresh($recentAuthAt);
@@ -165,6 +175,7 @@ private function buildStatus(User $user): RecentAuthStatusDto
             recent: $recent,
             passwordSet: $passwordSet,
             availableProviders: $providers,
+            passkeyAvailable: $passkeyAvailable,
             canSatisfy: $canSatisfy,
             confirmedAt: $confirmedAt,
         );
diff --git a/app/Http/Resources/Auth/RecentAuthStatusResource.php b/app/Http/Resources/Auth/RecentAuthStatusResource.php
index 2f742b8..0aea4bc 100644
--- a/app/Http/Resources/Auth/RecentAuthStatusResource.php
+++ b/app/Http/Resources/Auth/RecentAuthStatusResource.php
@@ -10,7 +10,8 @@
 use Illuminate\Http\Resources\Json\JsonResource;
 
 /**
- * recent-auth status の XHR 応答 ({ recent, passwordSet, availableProviders[], canSatisfy, confirmedAt })。
+ * recent-auth status の XHR 応答
+ * ({ recent, passwordSet, availableProviders[], passkeyAvailable, canSatisfy, confirmedAt })。
  * top-level (data ラップなし)、no-store は controller 側で付与。
  *
  * @property-read RecentAuthStatusDto $resource
@@ -25,6 +26,7 @@ final class RecentAuthStatusResource extends JsonResource
      *     recent: bool,
      *     passwordSet: bool,
      *     availableProviders: list<array{provider: string, capability: string, reauthUrl: string}>,
+     *     passkeyAvailable: bool,
      *     canSatisfy: bool,
      *     confirmedAt: int|null,
      * }
@@ -42,6 +44,7 @@ public function toArray(Request $request): array
                 ],
                 $this->resource->availableProviders,
             ),
+            'passkeyAvailable' => $this->resource->passkeyAvailable,
             'canSatisfy' => $this->resource->canSatisfy,
             'confirmedAt' => $this->resource->confirmedAt,
         ];
diff --git a/resources/js/components/organisms/RecentAuthModal.svelte b/resources/js/components/organisms/RecentAuthModal.svelte
index dbe5d51..174c61f 100644
--- a/resources/js/components/organisms/RecentAuthModal.svelte
+++ b/resources/js/components/organisms/RecentAuthModal.svelte
@@ -7,6 +7,7 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import { csrfToken } from "@/lib/csrf";
+    import { confirmWithPasskey, isPasskeySupported } from "@/lib/passkeys";
     import type { AvailableReauthProvider } from "@/lib/recent-auth";
     import { providerLabel } from "@/lib/social";
 
@@ -15,6 +16,8 @@
      * 「同一画面の再認証 (step-up) モーダル」。
      * - password 設定済みユーザー: パスワード再入力 → POST /recent-auth/password (XHR=204 成功)。
      * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
+     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 → POST /passkeys/confirm (204)。
+     *   TOTP 有効ユーザーでも **再認証には使える** (PasskeyLoginPolicy が縛るのはログインのみ)。
      * - canSatisfy=false (再認証手段なし): 回復導線 (パスワードリセット) を案内する。
      * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
      */
@@ -23,6 +26,12 @@
         passwordSet?: boolean;
         availableProviders?: AvailableReauthProvider[];
         canSatisfy?: boolean;
+        /**
+         * パスキーでの再認証を提示するか。**サーバの `/recent-auth/status` が単一の源**
+         * (`RecentAuthStatus.passkeyAvailable`)。呼び出し側が独自に判定しない
+         * — 画面ごとに判定を持つと passkey しか持たないユーザーが特定画面でだけ詰む。
+         */
+        passkeyAvailable?: boolean;
         /** password satisfier 成功時 (204)。呼び出し側が pending action を再開する */
         onConfirmed: () => void;
     }
@@ -32,9 +41,35 @@
         passwordSet = false,
         availableProviders = [],
         canSatisfy = true,
+        passkeyAvailable = false,
         onConfirmed,
     }: Props = $props();
 
+    const passkeySupported = isPasskeySupported();
+    let passkeySubmitting = $state(false);
+
+    async function submitPasskey(): Promise<void> {
+        if (passkeySubmitting) return;
+        passkeySubmitting = true;
+        error = "";
+        try {
+            const outcome = await confirmWithPasskey();
+            if (outcome.status === "ok") {
+                open = false;
+                onConfirmed();
+                return;
+            }
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            if (outcome.status === "cancelled") return;
+            error =
+                outcome.status === "unsupported"
+                    ? "このブラウザはパスキーに対応していません。"
+                    : outcome.message;
+        } finally {
+            passkeySubmitting = false;
+        }
+    }
+
     let password = $state("");
     let error = $state("");
     let submitting = $state(false);
@@ -45,6 +80,7 @@
             password = "";
             error = "";
             submitting = false;
+            passkeySubmitting = false;
         }
     });
 
@@ -121,10 +157,25 @@
             <FormError message={error} testId="recent-auth-error" />
         {/if}
 
-        {#if availableProviders.length > 0}
+        {#if passkeyAvailable && passkeySupported}
             {#if passwordSet}
                 <Divider label="または" />
             {/if}
+            <Button
+                variant="ghost"
+                fullWidth
+                loading={passkeySubmitting}
+                onclick={() => void submitPasskey()}
+                testId="recent-auth-passkey"
+            >
+                パスキーで再認証
+            </Button>
+        {/if}
+
+        {#if availableProviders.length > 0}
+            {#if passwordSet || (passkeyAvailable && passkeySupported)}
+                <Divider label="または" />
+            {/if}
             <div class="flex flex-col gap-2">
                 {#each availableProviders as provider (provider.provider)}
                     <Button
diff --git a/resources/js/lib/passkeys.ts b/resources/js/lib/passkeys.ts
new file mode 100644
index 0000000..d7f681f
--- /dev/null
+++ b/resources/js/lib/passkeys.ts
@@ -0,0 +1,391 @@
+import { csrfToken } from "@/lib/csrf";
+
+/**
+ * WebAuthn (passkey) ceremony の薄いラッパ。
+ *
+ * サーバとの JSON 契約は laravel/passkeys が定義する
+ * (`{ options }` を受け取り、credential を JSON で返す)。
+ *
+ * **feature detection を必ず経由すること**。現場 PWA が主戦場であり、
+ * 非対応端末 / 生体未設定端末は常態である (docs/supported-browsers.md)。
+ *
+ * **transport 契約 (詳細設計 施策 4-d) に対応する責務分担**:
+ *   本モジュールは「options 取得 + ceremony 実行 + 送信可能な JSON への変換」までを担う。
+ *   **登録は送信までしない** (Inertia `router.post` は呼び出し側 Svelte が行う。
+ *   passkey 一覧 prop を更新する必要があるため)。confirm / login は fetch 完結なので
+ *   送信まで担う。
+ *
+ * eslint の noInlineConfig (T102) により inline eslint-disable は使えない。
+ * base64url 変換は型安全に書き、`any` / `@ts-ignore` を持ち込まないこと。
+ */
+
+/** Settings/Security の passkey 一覧 1 件 (PasskeyListItemDto と 1:1) */
+export interface PasskeyListItem {
+    id: number;
+    name: string;
+    authenticator: string | null;
+    lastUsedAt: string | null;
+    createdAt: string | null;
+}
+
+/** ceremony の結果種別。キャンセル/タイムアウトはエラーとして騒がない */
+export type PasskeyOutcome<T> =
+    | { status: "ok"; value: T }
+    | { status: "cancelled" }
+    | { status: "unsupported" }
+    | { status: "failed"; message: string };
+
+const GENERIC_FAILURE = "パスキーの処理に失敗しました。時間をおいて再度お試しください。";
+
+/** この端末で passkey ceremony を開始できるか (API の存在確認) */
+export function isPasskeySupported(): boolean {
+    return (
+        typeof window !== "undefined" &&
+        typeof window.PublicKeyCredential === "function" &&
+        typeof navigator !== "undefined" &&
+        typeof navigator.credentials?.get === "function"
+    );
+}
+
+/** この端末で passkey を **作成** できるか (プラットフォーム認証器 + user verification) */
+export async function canCreatePasskey(): Promise<boolean> {
+    if (!isPasskeySupported()) return false;
+    try {
+        return await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
+    } catch {
+        // 端末/ブラウザによっては未実装で throw する。作成不可として畳む (騒がない)
+        return false;
+    }
+}
+
+/* ------------------------------------------------------------------ *
+ * base64url <-> ArrayBuffer
+ * サーバ (webauthn-lib の normalizer) は binary を base64url (padding なし) で送る。
+ * ------------------------------------------------------------------ */
+
+export function base64UrlToBuffer(value: string): ArrayBuffer {
+    const padded = value.replace(/-/g, "+").replace(/_/g, "/");
+    const binary = atob(padded + "=".repeat((4 - (padded.length % 4)) % 4));
+    const bytes = new Uint8Array(binary.length);
+    for (let i = 0; i < binary.length; i += 1) {
+        bytes[i] = binary.charCodeAt(i);
+    }
+    return bytes.buffer;
+}
+
+export function bufferToBase64Url(value: ArrayBuffer): string {
+    const bytes = new Uint8Array(value);
+    let binary = "";
+    for (let i = 0; i < bytes.length; i += 1) {
+        binary += String.fromCharCode(bytes[i]);
+    }
+    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
+}
+
+/* ------------------------------------------------------------------ *
+ * サーバ options の最小 shape (信用せず narrowing する)
+ * ------------------------------------------------------------------ */
+
+type JsonRecord = Record<string, unknown>;
+
+function isRecord(value: unknown): value is JsonRecord {
+    return typeof value === "object" && value !== null && !Array.isArray(value);
+}
+
+function readString(source: JsonRecord, key: string): string | null {
+    const value = source[key];
+    return typeof value === "string" && value !== "" ? value : null;
+}
+
+function readDescriptors(source: JsonRecord, key: string): PublicKeyCredentialDescriptor[] {
+    const raw = source[key];
+    if (!Array.isArray(raw)) return [];
+    const descriptors: PublicKeyCredentialDescriptor[] = [];
+    for (const entry of raw) {
+        if (!isRecord(entry)) continue;
+        const id = readString(entry, "id");
+        if (id === null) continue;
+        // type は WebAuthn 仕様上 "public-key" のみ (webauthn-lib も他を送らない)
+        descriptors.push({ id: base64UrlToBuffer(id), type: "public-key" });
+    }
+    return descriptors;
+}
+
+/**
+ * 共通 fetch。`Accept: application/json` は必須
+ * (無いと Laravel が redirect を返し、PasskeyLoginResponse も JSON 分岐に入らない)。
+ */
+async function requestJson(url: string, body?: JsonRecord): Promise<Response> {
+    const headers: Record<string, string> = {
+        Accept: "application/json",
+        "X-Requested-With": "XMLHttpRequest",
+    };
+    if (body !== undefined) {
+        headers["Content-Type"] = "application/json";
+        headers["X-XSRF-TOKEN"] = csrfToken();
+    }
+    return fetch(url, {
+        method: body === undefined ? "GET" : "POST",
+        headers,
+        credentials: "same-origin",
+        body: body === undefined ? undefined : JSON.stringify(body),
+    });
+}
+
+/** options endpoint から `{ options }` を取り出す (不正 shape は null) */
+async function fetchOptions(url: string): Promise<JsonRecord | null> {
+    try {
+        const res = await requestJson(url);
+        if (!res.ok) return null;
+        const payload: unknown = await res.json();
+        if (!isRecord(payload) || !isRecord(payload.options)) return null;
+        return payload.options;
+    } catch {
+        return null;
+    }
+}
+
+/** ユーザーキャンセル / タイムアウトを「失敗」として騒がないために畳む */
+function isCancellation(error: unknown): boolean {
+    return (
+        error instanceof Error &&
+        (error.name === "NotAllowedError" || error.name === "AbortError")
+    );
+}
+
+/* ------------------------------------------------------------------ *
+ * ceremony
+ * ------------------------------------------------------------------ */
+
+/** ArrayBuffer 相当のフィールドだけを base64url へ写す (存在しないキーは落とす) */
+function encodeBufferField(source: JsonRecord, key: string): string | null {
+    const value = source[key];
+    return value instanceof ArrayBuffer ? bufferToBase64Url(value) : null;
+}
+
+/**
+ * navigator.credentials の戻りを送信可能な JSON へ変換する。
+ *
+ * 種別判定は `instanceof AuthenticatorAttestationResponse` **ではなく** フィールドの
+ * 有無で行う。認証器レスポンスのクラスはグローバルに存在しない実行環境があり
+ * (jsdom / 一部の WebView)、instanceof は ReferenceError で ceremony 全体を落とすため。
+ */
+function serializeCredential(credential: PublicKeyCredential): JsonRecord {
+    const response = credential.response as unknown as JsonRecord;
+    const clientDataJSON = encodeBufferField(response, "clientDataJSON");
+    const attestationObject = encodeBufferField(response, "attestationObject");
+
+    const serializedResponse: JsonRecord = {};
+    if (clientDataJSON !== null) serializedResponse.clientDataJSON = clientDataJSON;
+
+    if (attestationObject !== null) {
+        // 登録 (attestation)
+        serializedResponse.attestationObject = attestationObject;
+    } else {
+        // 認証 (assertion)。userHandle は null を明示的に送る (仕様上 null がありうる)
+        const authenticatorData = encodeBufferField(response, "authenticatorData");
+        const signature = encodeBufferField(response, "signature");
+        if (authenticatorData !== null) serializedResponse.authenticatorData = authenticatorData;
+        if (signature !== null) serializedResponse.signature = signature;
+        serializedResponse.userHandle = encodeBufferField(response, "userHandle");
+    }
+
+    return {
+        id: credential.id,
+        rawId: bufferToBase64Url(credential.rawId),
+        type: credential.type,
+        response: serializedResponse,
+    };
+}
+
+function toCreationOptions(options: JsonRecord): PublicKeyCredentialCreationOptions | null {
+    const challenge = readString(options, "challenge");
+    const rp = isRecord(options.rp) ? options.rp : null;
+    const user = isRecord(options.user) ? options.user : null;
+    const userId = user === null ? null : readString(user, "id");
+    if (challenge === null || rp === null || user === null || userId === null) return null;
+
+    const params = Array.isArray(options.pubKeyCredParams)
+        ? options.pubKeyCredParams
+              .filter(isRecord)
+              .filter((entry): entry is JsonRecord => typeof entry.alg === "number")
+              .map((entry): PublicKeyCredentialParameters => ({
+                  type: "public-key",
+                  alg: entry.alg as number,
+              }))
+        : [];
+
+    return {
+        challenge: base64UrlToBuffer(challenge),
+        rp: {
+            id: readString(rp, "id") ?? undefined,
+            name: readString(rp, "name") ?? "",
+        },
+        user: {
+            id: base64UrlToBuffer(userId),
+            name: readString(user, "name") ?? "",
+            displayName: readString(user, "displayName") ?? "",
+        },
+        pubKeyCredParams: params,
+        excludeCredentials: readDescriptors(options, "excludeCredentials"),
+        timeout: typeof options.timeout === "number" ? options.timeout : undefined,
+        authenticatorSelection: isRecord(options.authenticatorSelection)
+            ? {
+                  residentKey: readString(options.authenticatorSelection, "residentKey") as
+                      | ResidentKeyRequirement
+                      | undefined,
+                  userVerification: readString(options.authenticatorSelection, "userVerification") as
+                      | UserVerificationRequirement
+                      | undefined,
+              }
+            : undefined,
+        attestation: (readString(options, "attestation") ?? undefined) as
+            | AttestationConveyancePreference
+            | undefined,
+    };
+}
+
+function toRequestOptions(options: JsonRecord): PublicKeyCredentialRequestOptions | null {
+    const challenge = readString(options, "challenge");
+    if (challenge === null) return null;
+
+    return {
+        challenge: base64UrlToBuffer(challenge),
+        rpId: readString(options, "rpId") ?? undefined,
+        allowCredentials: readDescriptors(options, "allowCredentials"),
+        timeout: typeof options.timeout === "number" ? options.timeout : undefined,
+        userVerification: (readString(options, "userVerification") ?? undefined) as
+            | UserVerificationRequirement
+            | undefined,
+    };
+}
+
+/**
+ * 登録 ceremony (GET options → navigator.credentials.create)。
+ * **送信は行わない**。呼び出し側が
+ * `router.post('/user/passkeys', { name, credential })` する (transport 契約 4-d)。
+ */
+export async function createPasskeyCredential(): Promise<PasskeyOutcome<JsonRecord>> {
+    if (!isPasskeySupported()) return { status: "unsupported" };
+
+    const options = await fetchOptions("/user/passkeys/options");
+    if (options === null) {
+        return { status: "failed", message: "パスキーの登録を開始できませんでした。" };
+    }
+
+    const creationOptions = toCreationOptions(options);
+    if (creationOptions === null) {
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+
+    try {
+        const credential = await navigator.credentials.create({ publicKey: creationOptions });
+        if (!(credential instanceof PublicKeyCredential)) {
+            return { status: "failed", message: GENERIC_FAILURE };
+        }
+        return { status: "ok", value: serializeCredential(credential) };
+    } catch (error) {
+        if (isCancellation(error)) return { status: "cancelled" };
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+}
+
+/** ログイン ceremony (GET options → navigator.credentials.get → POST → `{ redirect }`) */
+export async function loginWithPasskey(
+    remember = false,
+): Promise<PasskeyOutcome<{ redirect: string }>> {
+    const assertion = await assertPasskey("/passkeys/login/options");
+    if (assertion.status !== "ok") return assertion;
+
+    try {
+        const res = await requestJson("/passkeys/login", {
+            credential: assertion.value,
+            remember,
+        });
+        if (!res.ok) {
+            return { status: "failed", message: await readErrorMessage(res) };
+        }
+        const payload: unknown = await res.json();
+        const redirect = isRecord(payload) ? readString(payload, "redirect") : null;
+        if (redirect === null) {
+            return { status: "failed", message: GENERIC_FAILURE };
+        }
+        return { status: "ok", value: { redirect } };
+    } catch {
+        return { status: "failed", message: "通信エラーが発生しました。" };
+    }
+}
+
+/**
+ * step-up 確認 ceremony (GET confirm-options → navigator.credentials.get)。
+ * **送信は行わない**。
+ *
+ * 全画面の confirm 画面 (302 fallback 経路) 専用: そちらはサーバが保持する
+ * `url.intended` へ戻す必要があり、Inertia の `router.post` で送って
+ * PasskeyConfirmationResponse の `redirect()->intended()` 分岐に乗せる。
+ * インラインモーダルは元操作を client 側で再開するため fetch 完結の
+ * {@link confirmWithPasskey} を使う。
+ */
+export async function confirmPasskeyCredential(): Promise<PasskeyOutcome<Record<string, unknown>>> {
+    return assertPasskey("/passkeys/confirm/options");
+}
+
+/** step-up 確認 ceremony (GET confirm-options → navigator.credentials.get → POST → 204) */
+export async function confirmWithPasskey(): Promise<PasskeyOutcome<void>> {
+    const assertion = await assertPasskey("/passkeys/confirm/options");
+    if (assertion.status !== "ok") return assertion;
+
+    try {
+        const res = await requestJson("/passkeys/confirm", { credential: assertion.value });
+        // 成功は 204 No Content (recent-auth.password と同契約)
+        if (res.status === 204) return { status: "ok", value: undefined };
+        return { status: "failed", message: await readErrorMessage(res) };
+    } catch {
+        return { status: "failed", message: "通信エラーが発生しました。" };
+    }
+}
+
+/** options 取得 + assertion ceremony の共通部 */
+async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRecord>> {
+    if (!isPasskeySupported()) return { status: "unsupported" };
+
+    const options = await fetchOptions(optionsUrl);
+    if (options === null) {
+        return { status: "failed", message: "パスキーの認証を開始できませんでした。" };
+    }
+
+    const requestOptions = toRequestOptions(options);
+    if (requestOptions === null) {
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+
+    try {
+        const credential = await navigator.credentials.get({ publicKey: requestOptions });
+        if (!(credential instanceof PublicKeyCredential)) {
+            return { status: "failed", message: GENERIC_FAILURE };
+        }
+        return { status: "ok", value: serializeCredential(credential) };
+    } catch (error) {
+        if (isCancellation(error)) return { status: "cancelled" };
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+}
+
+/** サーバのエラー本文から表示可能なメッセージを取り出す (取れなければ既定文言) */
+async function readErrorMessage(response: Response): Promise<string> {
+    try {
+        const payload: unknown = await response.json();
+        if (!isRecord(payload)) return GENERIC_FAILURE;
+        const direct = readString(payload, "message");
+        if (direct !== null) return direct;
+        const errors = payload.errors;
+        if (isRecord(errors)) {
+            for (const value of Object.values(errors)) {
+                if (Array.isArray(value) && typeof value[0] === "string") return value[0];
+            }
+        }
+        return GENERIC_FAILURE;
+    } catch {
+        return GENERIC_FAILURE;
+    }
+}
diff --git a/resources/js/lib/recent-auth.ts b/resources/js/lib/recent-auth.ts
index b9f375f..5346eeb 100644
--- a/resources/js/lib/recent-auth.ts
+++ b/resources/js/lib/recent-auth.ts
@@ -23,6 +23,12 @@ export interface RecentAuthStatus {
     recent: boolean;
     passwordSet: boolean;
     availableProviders: AvailableReauthProvider[];
+    /**
+     * パスキーで再認証できるか (登録済み credential が 1 件以上ある)。
+     * **ログイン可否とは別**: 2要素認証が有効なユーザーはパスキーでログインできないが、
+     * 再認証には使える。
+     */
+    passkeyAvailable: boolean;
     canSatisfy: boolean;
     confirmedAt: number | null;
 }
@@ -43,6 +49,7 @@ export async function fetchRecentAuthStatus(): Promise<RecentAuthStatus | null>
             recent: body.recent,
             passwordSet: body.passwordSet ?? false,
             availableProviders: body.availableProviders ?? [],
+            passkeyAvailable: body.passkeyAvailable ?? false,
             canSatisfy: body.canSatisfy ?? false,
             confirmedAt: body.confirmedAt ?? null,
         };
diff --git a/resources/js/pages/Auth/ConfirmRecentAuth.svelte b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
index 051170c..6f34ff8 100644
--- a/resources/js/pages/Auth/ConfirmRecentAuth.svelte
+++ b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
@@ -7,6 +7,7 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import PasswordInput from "@/components/molecules/PasswordInput.svelte";
     import AuthLayout from "@/components/templates/AuthLayout.svelte";
+    import { confirmPasskeyCredential, isPasskeySupported } from "@/lib/passkeys";
     import type { AvailableReauthProvider } from "@/lib/recent-auth";
     import { providerLabel } from "@/lib/social";
 
@@ -16,6 +17,8 @@
      * intended URL へ戻る (server 側 redirect()->intended)。
      * - password 設定済みユーザー: password 再入力フォーム (POST /recent-auth/password)
      * - 再SSO 可能な provider: reauthUrl (/auth/{provider}/redirect/step-up) で再認証
+     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 (POST /passkeys/confirm、204)。
+     *   **パスキーしか持たないユーザーをこの画面で詰ませない**ための導線
      * - canSatisfy=false: 回復手順 (ログアウト → guest としてパスワード再設定) を案内。
      *   /forgot-password へ直接リンクしない — Fortify が `guest` middleware 付きで登録しており
      *   ログイン済みの本画面ユーザーはフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)
@@ -24,6 +27,8 @@
         appName?: string;
         passwordSet?: boolean;
         availableProviders?: AvailableReauthProvider[];
+        /** パスキーで再認証できるか (サーバが単一の源) */
+        passkeyAvailable?: boolean;
         canSatisfy?: boolean;
     }
 
@@ -31,9 +36,51 @@
         appName,
         passwordSet = false,
         availableProviders = [],
+        passkeyAvailable = false,
         canSatisfy = true,
     }: Props = $props();
 
+    const passkeySupported = isPasskeySupported();
+    let passkeyError = $state("");
+    let passkeyProcessing = $state(false);
+
+    /**
+     * パスキーで再認証する。
+     *
+     * ceremony 結果は **Inertia の router.post で送る** (fetch ではない)。
+     * この画面は RequireRecentAuth の 302 fallback 着地であり、元 URL は
+     * サーバの `url.intended` にしか無い。Inertia で送れば
+     * PasskeyConfirmationResponse が `redirect()->intended()` を返し、元の操作画面へ戻る。
+     */
+    async function submitPasskey(): Promise<void> {
+        if (passkeyProcessing) return;
+        passkeyProcessing = true;
+        passkeyError = "";
+        try {
+            const outcome = await confirmPasskeyCredential();
+            if (outcome.status === "ok") {
+                router.post(
+                    "/passkeys/confirm",
+                    { credential: outcome.value },
+                    {
+                        onError: () => {
+                            passkeyError = "パスキーでの再認証に失敗しました。";
+                        },
+                    },
+                );
+                return;
+            }
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            if (outcome.status === "cancelled") return;
+            passkeyError =
+                outcome.status === "unsupported"
+                    ? "このブラウザはパスキーに対応していません。"
+                    : outcome.message;
+        } finally {
+            passkeyProcessing = false;
+        }
+    }
+
     const form = useForm({
         password: "",
     });
@@ -89,11 +136,31 @@
         <FormError message={form.errors.password} />
     {/if}
 
-    {#if availableProviders.length > 0}
+    {#if passkeyAvailable && passkeySupported}
         <div class="mt-6 flex flex-col gap-3">
             {#if passwordSet}
                 <Divider label="または" />
             {/if}
+            {#if passkeyError}
+                <FormError message={passkeyError} testId="confirm-passkey-error" />
+            {/if}
+            <Button
+                variant="ghost"
+                fullWidth
+                loading={passkeyProcessing}
+                onclick={() => void submitPasskey()}
+                testId="confirm-passkey-button"
+            >
+                パスキーで再認証
+            </Button>
+        </div>
+    {/if}
+
+    {#if availableProviders.length > 0}
+        <div class="mt-6 flex flex-col gap-3">
+            {#if passwordSet || (passkeyAvailable && passkeySupported)}
+                <Divider label="または" />
+            {/if}
             {#each availableProviders as provider (provider.provider)}
                 <Button href={provider.reauthUrl} variant="ghost" fullWidth>
                     {providerLabel(provider.provider)}で再認証
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index df69394..0fa3d8b 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -11,12 +11,14 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
+    import PasskeySection from "@/components/features/auth/PasskeySection.svelte";
     import PageHeader from "@/components/molecules/PageHeader.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
     import { Settings } from "@lucide/svelte";
     import { useForm } from "@inertiajs/svelte";
+    import type { PasskeyListItem } from "@/lib/passkeys";
     import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
     import { providerLabel } from "@/lib/social";
@@ -25,14 +27,31 @@
     interface Props {
         socialProviders?: string[];
         linkedProviders?: string[];
+        passkeys?: PasskeyListItem[];
+        /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
+        passkeyLoginAvailable?: boolean;
     }
 
-    let { socialProviders = [], linkedProviders = [] }: Props = $props();
+    let {
+        socialProviders = [],
+        linkedProviders = [],
+        passkeys = [],
+        passkeyLoginAvailable = false,
+    }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
     const twoFactorEnabled = $derived(shared.auth?.user?.twoFactorEnabled ?? false);
 
+    /**
+     * EnsureLoginMethodRemains はログイン手段が 0 になる削除を
+     * **302 + errors.login_method** で拒否する (Inertia に 422 JSON を返すと無言失敗するため)。
+     * ここで拾って PasskeySection に渡し、画面上で明示する。
+     */
+    const loginMethodError = $derived(
+        (page.props as unknown as { errors?: Record<string, string> }).errors?.login_method,
+    );
+
     /* ----------------------------------------------------------------
      * 2FA 管理
      * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
@@ -521,6 +540,14 @@
                 {/if}
             </Card>
 
+            <PasskeySection
+                {passkeys}
+                {passkeyLoginAvailable}
+                {twoFactorEnabled}
+                {loginMethodError}
+                guard={guardWithRecentAuth}
+            />
+
             <Card padding="lg">
                 <h2 class="text-h3">ソーシャルログイン連携</h2>
                 <p class="mt-1 text-caption text-text-secondary">
@@ -577,6 +604,7 @@
             passwordSet={recentAuthStatus?.passwordSet ?? false}
             availableProviders={recentAuthStatus?.availableProviders ?? []}
             canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+            passkeyAvailable={recentAuthStatus?.passkeyAvailable ?? false}
             onConfirmed={resumePendingAction}
         />
         </PageContent>

```

---

## 修正後の差分 (2) テスト差分 (Round 1 で「diff に無い」と指摘された docs も含め、全て添付する)

```diff
diff --git a/tests/Architecture/DocumentTitleCoverageTest.php b/tests/Architecture/DocumentTitleCoverageTest.php
index 0d0f3a0..dccd144 100644
--- a/tests/Architecture/DocumentTitleCoverageTest.php
+++ b/tests/Architecture/DocumentTitleCoverageTest.php
@@ -67,6 +67,10 @@ function documentTitleUnresolvableAllowlist(): array
         'two-factor.qr-code' => 'Fortify の 2FA QR (SVG/JSON) endpoint。ページを描画しない',
         'two-factor.secret-key' => 'Fortify の 2FA secret (JSON) endpoint。ページを描画しない',
         'two-factor.recovery-codes' => 'Fortify のリカバリコード (JSON) endpoint。ページを描画しない',
+        // --- passkey (WebAuthn) の options endpoint (JSON)。ceremony 用 challenge を返すのみ ---
+        'passkey.login-options' => 'WebAuthn ログイン options (JSON) endpoint。ページを描画しない',
+        'passkey.confirm-options' => 'WebAuthn 再認証 options (JSON) endpoint。ページを描画しない',
+        'passkey.registration-options' => 'WebAuthn 登録 options (JSON) endpoint。ページを描画しない',
         // --- Route::view の Blade スタブ (Inertia ではない。title は blade 側が持つ) ---
         'legal.terms' => 'Route::view の Blade スタブ (Inertia 非経由)。NoIndex middleware 付きの文面プレースホルダ',
         'legal.privacy' => 'Route::view の Blade スタブ (Inertia 非経由)。同上',
diff --git a/tests/Architecture/LoginMethodRemovalRouteTest.php b/tests/Architecture/LoginMethodRemovalRouteTest.php
new file mode 100644
index 0000000..3f4a5dd
--- /dev/null
+++ b/tests/Architecture/LoginMethodRemovalRouteTest.php
@@ -0,0 +1,155 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\EnsureLoginMethodRemains;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * 「ログイン手段を減らす route」の分類 invariant (deny-by-default)。
+ *
+ * ログイン手段を全部消して自分で締め出す事故は復旧コストが高く、現場を止める。
+ * 候補となる route を構造的に列挙し、
+ *   (a) guard 必須 (ensure-login-method middleware を持つ) か
+ *   (b) 免除 (理由文字列つき)
+ * のどちらかに **必ず分類させる**。分類漏れは fail = 将来 SSO 解除 / passkey 削除 /
+ * パスワード削除 route を足したとき、guard の要否を必ず考えさせる。
+ *
+ * 本テストは分類漏れ・drift を落とす役割に限定する。実挙動 (投影評価・ロック・422) は
+ * tests/Feature/Auth/LoginMethodRetentionTest.php が担保する。
+ *
+ * 候補の構造的定義: 認証系 URI 空間 ('user/passkeys', 'settings/social', 'user/password',
+ * 'settings/account') に属する破壊的メソッド (DELETE / PUT / PATCH) の named route。
+ */
+
+/** @return list<string> guard 必須の route 名 */
+function loginMethodRemovalGuardedRoutes(): array
+{
+    return [
+        // passkey 削除 (credential 集合を減らす。最初の被保護 route)
+        'passkey.destroy',
+    ];
+}
+
+/** @return array<string, string> route 名 => 免除理由 (非空必須) */
+function loginMethodRemovalExemptRoutes(): array
+{
+    return [
+        // アカウント自体を消す操作。手段が 0 になるのは目的であって事故ではない。
+        // 別途 recent-auth (step-up) で保護済み。
+        'settings.account.destroy' => 'アカウント除去そのものであり、手段が残らないことが意図',
+        // 第二要素の除去であってログイン手段の除去ではない
+        // (TOTP を外してもパスワード / SSO / passkey は残る)。
+        'two-factor.disable' => '第二要素の除去でありログイン手段ではない',
+        // 変更であって除去ではない。current_password 必須で null 化できない。
+        'user-password.update' => 'パスワードの変更であり除去経路ではない (current_password 必須)',
+    ];
+}
+
+function routeHasLoginMethodGuard(RoutingRoute $route): bool
+{
+    $middleware = $route->gatherMiddleware();
+
+    return in_array('ensure-login-method', $middleware, true)
+        || in_array(EnsureLoginMethodRemains::class, $middleware, true);
+}
+
+test('ログイン手段を減らしうる route は guard 必須か免除のどちらかに分類されている', function (): void {
+    $prefixes = ['user/passkeys', 'settings/social', 'user/password', 'settings/account'];
+    $destructive = ['DELETE', 'PUT', 'PATCH'];
+
+    $guarded = loginMethodRemovalGuardedRoutes();
+    $exempt = loginMethodRemovalExemptRoutes();
+
+    $checked = 0;
+    $violations = [];
+
+    foreach (Route::getRoutes() as $route) {
+        $uri = $route->uri();
+        $matchesPrefix = false;
+        foreach ($prefixes as $prefix) {
+            if (str_starts_with($uri, $prefix)) {
+                $matchesPrefix = true;
+                break;
+            }
+        }
+        if (! $matchesPrefix || array_intersect($destructive, $route->methods()) === []) {
+            continue;
+        }
+
+        $name = $route->getName();
+        if ($name === null) {
+            $violations[] = "route {$uri} に名前が無く分類できない";
+
+            continue;
+        }
+
+        $checked++;
+
+        if (array_key_exists($name, $exempt)) {
+            expect(trim($exempt[$name]))->not->toBe('', "route '{$name}' の免除理由が空 (運用劣化)");
+
+            continue;
+        }
+
+        if (! in_array($name, $guarded, true)) {
+            $violations[] = "route '{$name}' が未分類 (guard 必須 or 免除のどちらかに登録すること)";
+
+            continue;
+        }
+
+        if (! routeHasLoginMethodGuard($route)) {
+            $violations[] = "route '{$name}' に ensure-login-method middleware が付与されていない";
+        }
+    }
+
+    expect($violations)->toBe([]);
+    // 1 本も検査されない = 候補判定が壊れた (空振り drift) ので fail させる
+    expect($checked)->toBeGreaterThan(0);
+});
+
+test('guard 必須リストの route は全て実在する', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    foreach (loginMethodRemovalGuardedRoutes() as $name) {
+        expect($routes->getByName($name))->not->toBeNull("route '{$name}' が存在しない (リネーム/削除に追従していない)");
+    }
+});
+
+test('免除リストの route は全て実在する', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    foreach (array_keys(loginMethodRemovalExemptRoutes()) as $name) {
+        expect($routes->getByName($name))->not->toBeNull("route '{$name}' が存在しない (陳腐化した免除登録)");
+    }
+});
+
+/*
+ * **allowlist 外への付与も禁じる (deny-by-default の逆方向)**。
+ *
+ * EnsureLoginMethodRemains は $next() を DB transaction 内で実行するため、
+ * controller / 同期 listener / Responsable 変換 / flash まで transaction に入る。
+ * 適用範囲が無自覚に広がると副作用範囲が急拡大する
+ * (streamed response / 外部 I/O / afterCommit でない queue dispatch は特に危険)。
+ * 付与してよい route を allowlist に固定し、増やすときは必ず判断させる。
+ */
+test('ensure-login-method middleware を持つ route は guard 必須リストのみ', function (): void {
+    $guarded = loginMethodRemovalGuardedRoutes();
+    $unexpected = [];
+
+    foreach (Route::getRoutes() as $route) {
+        if (! routeHasLoginMethodGuard($route)) {
+            continue;
+        }
+        $name = $route->getName() ?? $route->uri();
+        if (! in_array($name, $guarded, true)) {
+            $unexpected[] = "route '{$name}' に ensure-login-method が付いているが allowlist に無い"
+                .' (middleware は $next を transaction 内で実行する。適用条件を docblock で確認すること)';
+        }
+    }
+
+    expect($unexpected)->toBe([]);
+});
diff --git a/tests/Architecture/PasskeyPackageContractTest.php b/tests/Architecture/PasskeyPackageContractTest.php
new file mode 100644
index 0000000..925eb5b
--- /dev/null
+++ b/tests/Architecture/PasskeyPackageContractTest.php
@@ -0,0 +1,145 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Responses\Passkey\PasskeyConfirmationResponse;
+use App\Http\Responses\Passkey\PasskeyDeletedResponse;
+use App\Http\Responses\Passkey\PasskeyLoginResponse;
+use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
+use App\Models\Passkey;
+use App\Models\User;
+use Illuminate\Database\Eloquent\ModelNotFoundException;
+use Laravel\Fortify\Features;
+use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyUser;
+use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController;
+use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
+use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;
+use Laravel\Passkeys\Passkeys;
+
+/*
+ * laravel/passkeys (Fortify 1.37 の推移依存) とアプリの結線契約を固定する。
+ *
+ * 守る事故:
+ *   - パッケージ側 routes の二重登録 (Fortify が feature flag でゲートした route と衝突する)
+ *   - Fortify 標準の password.confirm が復活し SSO-only ユーザーが詰む
+ *   - config:cache 下で fortify-options.passkeys が落ちる
+ *   - binder が vendor 実装のまま残り、他人の passkey の存在が 403 で漏れる
+ *
+ * DB を伴う実挙動 (他人の passkey が 404 になること) は
+ * tests/Feature/Auth/PasskeyRouteAccessTest.php が担保する
+ * (Architecture レーンは RefreshDatabase を持たないため DB に触れない)。
+ */
+
+/** @return list<string> Fortify が登録する passkey route の名前 */
+function passkeyRouteNames(): array
+{
+    return [
+        'passkey.login-options',
+        'passkey.login',
+        'passkey.confirm-options',
+        'passkey.confirm',
+        'passkey.registration-options',
+        'passkey.store',
+        'passkey.destroy',
+    ];
+}
+
+test('パッケージ側の passkey routes は登録されない (Fortify 側が唯一の登録点)', function (): void {
+    expect(Passkeys::shouldRegisterRoutes())->toBeFalse();
+});
+
+test('passkeys feature が有効 (キルスイッチが on)', function (): void {
+    expect(Features::enabled(Features::passkeys()))->toBeTrue();
+});
+
+test('passkey route 7 本が実在し vendor controller に紐づく', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    $expectedControllers = [
+        PasskeyLoginController::class,
+        PasskeyConfirmationController::class,
+        PasskeyRegistrationController::class,
+    ];
+
+    foreach (passkeyRouteNames() as $name) {
+        $route = $routes->getByName($name);
+        expect($route)->not->toBeNull("route '{$name}' が存在しない");
+
+        $controller = $route->getAction('controller');
+        expect($controller)->toBeString();
+
+        $matched = false;
+        foreach ($expectedControllers as $expected) {
+            if (str_starts_with((string) $controller, $expected.'@')) {
+                $matched = true;
+                break;
+            }
+        }
+        expect($matched)->toBeTrue("route '{$name}' の action が vendor controller ではない: {$controller}");
+    }
+});
+
+test('passkeys の confirmPassword は false (generic recent-auth へ統一)', function (): void {
+    expect(config('fortify-options.passkeys.confirmPassword'))->toBeFalse();
+});
+
+test('passkeys の throttle limiter が設定されている (未認証 challenge 無制限の防止)', function (): void {
+    expect(config('fortify.limiters.passkeys'))->toBe('passkeys');
+});
+
+/*
+ * config:cache 下でも値が残ることを検査する。
+ * ConfigCacheCommand は `'<?php return '.var_export($config, true).';'` を書き出すため、
+ * その **serialize 機構そのものを再現**して往復させる
+ * (Pest から config:cache を実行すると bootstrap/cache/config.php を書き換え、
+ *  --parallel 実行を壊すため実行しない)。
+ */
+test('config cache 往復後も fortify-options.passkeys と features が残る', function (): void {
+    $subset = [
+        'fortify' => config('fortify'),
+        'fortify-options' => config('fortify-options'),
+    ];
+
+    $exported = var_export($subset, true);
+    /** @var array<string, mixed> $roundTripped */
+    $roundTripped = eval('return '.$exported.';');
+
+    expect(data_get($roundTripped, 'fortify-options.passkeys.confirmPassword'))->toBeFalse();
+    expect(data_get($roundTripped, 'fortify.features'))->toContain('passkeys');
+    expect(data_get($roundTripped, 'fortify.limiters.passkeys'))->toBe('passkeys');
+});
+
+test('モデル差し替えが app 実装になっている', function (): void {
+    expect(Passkeys::passkeyModel())->toBe(Passkey::class);
+    expect(Passkeys::userModel())->toBe(User::class);
+    expect(is_a(User::class, PasskeyUser::class, true))->toBeTrue();
+});
+
+test('Response contract 4 本が app 実装に差し替えられている (response()->json 直書きの回避)', function (): void {
+    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class);
+    expect(app(PasskeyConfirmationResponseContract::class))->toBeInstanceOf(PasskeyConfirmationResponse::class);
+    expect(app(PasskeyRegistrationResponseContract::class))->toBeInstanceOf(PasskeyRegistrationResponse::class);
+    expect(app(PasskeyDeletedResponseContract::class))->toBeInstanceOf(PasskeyDeletedResponse::class);
+});
+
+/*
+ * binder の **最終解決系**がアプリ実装であることを固定する。
+ *
+ * vendor の binder は `app($model)->resolveRouteBinding($value)` でグローバル解決するため、
+ * guest 文脈でも解決に成功しうる (= その後 controller の 403 に到達して存在が漏れる)。
+ * アプリ実装 (SelfScopedPasskeyBinder) は guest を DB へ行かずに 404 相当へ倒すので、
+ * **DB に触れずに** 差し替えの成否を判定できる。
+ */
+test('passkey binder の最終解決系がアプリ実装 (guest は DB を引かずに 404 相当)', function (): void {
+    $callback = app('router')->getBindingCallback('passkey');
+
+    expect($callback)->not->toBeNull('{passkey} の explicit binder が登録されていない');
+
+    // class binding は Router::createClassBinding により ($value, $route) の 2 引数 closure になる
+    expect(fn () => $callback('1', null))->toThrow(ModelNotFoundException::class);
+});
diff --git a/tests/Architecture/PasskeyRouteProtectionTest.php b/tests/Architecture/PasskeyRouteProtectionTest.php
new file mode 100644
index 0000000..9610876
--- /dev/null
+++ b/tests/Architecture/PasskeyRouteProtectionTest.php
@@ -0,0 +1,96 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\EnsureLoginMethodRemains;
+use App\Http\Middleware\NoStoreResponse;
+use App\Http\Middleware\RequireRecentAuth;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+
+/*
+ * passkey route の middleware 構成を列挙で固定する。
+ *
+ * passkey route は **vendor (Fortify) が登録**し、アプリ側は
+ * PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes() が booted 後に後付けする。
+ * 後付けは「配線が消えても route は生き続ける」壊れ方をするため、構成を機械的に固定する。
+ */
+
+/** @return array<string, list<string>> route 名 => 必須 middleware (alias 文字列) */
+function passkeyRouteMiddlewareInventory(): array
+{
+    return [
+        // guest。challenge を載せるため no-store を後付けする
+        // (NoStoreCacheHeadersForAuthenticatedPages は認証済みのみが対象)
+        'passkey.login-options' => ['web', 'guest:web', 'throttle:passkeys', 'no-store'],
+        'passkey.login' => ['web', 'guest:web', 'throttle:passkeys'],
+        // step-up satisfier。recent-auth は課さない (これ自体が satisfier のため)
+        'passkey.confirm-options' => ['web', 'auth:web', 'throttle:passkeys'],
+        'passkey.confirm' => ['web', 'auth:web', 'throttle:passkeys'],
+        // credential 集合を増やす管理経路
+        'passkey.registration-options' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
+        'passkey.store' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
+        // credential 集合を減らす管理経路 (手段保持 guard つき)
+        'passkey.destroy' => ['web', 'auth:web', 'recent-auth', 'ensure-login-method'],
+    ];
+}
+
+function passkeyRoute(string $name): RoutingRoute
+{
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName($name);
+
+    expect($route)->not->toBeNull("route '{$name}' が存在しない");
+
+    return $route;
+}
+
+test('passkey route の middleware 構成が inventory と一致する', function (): void {
+    foreach (passkeyRouteMiddlewareInventory() as $name => $expected) {
+        $actual = passkeyRoute($name)->gatherMiddleware();
+
+        foreach ($expected as $middleware) {
+            // toContain は可変長 needle を取るため、メッセージ付きの表明は in_array で行う
+            expect(in_array($middleware, $actual, true))->toBeTrue(
+                "route '{$name}' に middleware '{$middleware}' が付与されていない (実際: ".implode(', ', $actual).')',
+            );
+        }
+    }
+});
+
+test('passkey route に password.confirm が付いていない (generic recent-auth へ統一済み)', function (): void {
+    foreach (array_keys(passkeyRouteMiddlewareInventory()) as $name) {
+        expect(in_array('password.confirm', passkeyRoute($name)->gatherMiddleware(), true))
+            ->toBeFalse("route '{$name}' に password.confirm が復活している");
+    }
+});
+
+/*
+ * **実行順**: recent-auth を先に通し、その後で手段保持を検査する。
+ * 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
+ * alias 文字列だけでなく **解決後のクラス列** ($middlewarePriority による並べ替え込み) で見る。
+ */
+test('passkey.destroy は recent-auth が ensure-login-method より先に走る', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+    $resolved = $router->gatherRouteMiddleware(passkeyRoute('passkey.destroy'));
+
+    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);
+    $loginMethodIndex = array_search(EnsureLoginMethodRemains::class, $resolved, true);
+
+    expect($recentAuthIndex)->not->toBeFalse('RequireRecentAuth が解決後の middleware 列に無い');
+    expect($loginMethodIndex)->not->toBeFalse('EnsureLoginMethodRemains が解決後の middleware 列に無い');
+    expect($recentAuthIndex)->toBeLessThan(
+        $loginMethodIndex,
+        'recent-auth より先に ensure-login-method が走ると stale なリクエストでも User 行ロックを取る',
+    );
+});
+
+test('passkey.login-options の no-store は解決後も NoStoreResponse に落ちる', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+    $resolved = $router->gatherRouteMiddleware(passkeyRoute('passkey.login-options'));
+
+    expect($resolved)->toContain(NoStoreResponse::class);
+});
diff --git a/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php b/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php
new file mode 100644
index 0000000..6352a74
--- /dev/null
+++ b/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\Route;
+
+/*
+ * `password.confirm` middleware の **全 route での不在** を deny-by-default で固定する。
+ *
+ * 本アプリは Fortify 標準の password.confirm (3h 窓・パスワード限定) を撤去し、
+ * generic recent-auth (15 分窓・パスワード or 再SSO or パスキー) へ統一している。
+ * password.confirm が復活すると:
+ *   1. SSO-only ユーザー (password 未設定) がその route で**詰む** (satisfier が無い)
+ *   2. confirmPasswordView は recent-auth.confirm への redirect でしかなく
+ *      `auth.password_confirmed_at` を満たせないため無限ループになる (bug-hunt F-11)
+ *
+ * 特に laravel/passkeys は config 既定が `management_middleware = ['password.confirm']` で、
+ * `fortify-options.passkeys.confirmPassword` を落とすと即座に復活する。
+ */
+test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
+    $violations = [];
+    $checked = 0;
+
+    foreach (Route::getRoutes() as $route) {
+        $checked++;
+
+        foreach ($route->gatherMiddleware() as $middleware) {
+            if (! is_string($middleware)) {
+                continue;
+            }
+            if ($middleware === 'password.confirm' || str_starts_with($middleware, 'password.confirm:')) {
+                $violations[] = $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
+            }
+        }
+    }
+
+    expect($violations)->toBe(
+        [],
+        'password.confirm は generic recent-auth へ置換済み。復活すると SSO-only ユーザーが詰む: '
+        .implode(', ', $violations),
+    );
+    // route 走査自体が空振りしていないこと
+    expect($checked)->toBeGreaterThan(0);
+});
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index bc6517e..55995ba 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -2,7 +2,11 @@
 
 declare(strict_types=1);
 
+use App\Http\Controllers\Auth\ConfirmRecentAuthController;
+use App\Http\Controllers\Auth\SocialAuthController;
 use App\Http\Middleware\RequireRecentAuth;
+use App\Listeners\Auth\StampRecentAuthOnLogin;
+use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
 use Illuminate\Routing\Route as RoutingRoute;
 use Illuminate\Routing\Router;
 
@@ -40,6 +44,13 @@ function recentAuthRequiredRouteNames(): array
         // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
         // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
         'user-profile-information.update',
+        // passkey 管理 (credential 集合を増減させる経路。配線は
+        // App\Providers\PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes())。
+        // passkey.confirm / passkey.confirm-options は **satisfier 側**のため対象外
+        // (自分自身に step-up を要求すると詰む)。
+        'passkey.registration-options',
+        'passkey.store',
+        'passkey.destroy',
     ];
 }
 
@@ -70,3 +81,166 @@ function routeHasRecentAuth(RoutingRoute $route): bool
         expect(routeHasRecentAuth($route))->toBeTrue("route '{$name}' に recent-auth middleware が付与されていない (付け忘れ)");
     }
 });
+
+/*
+|--------------------------------------------------------------------------
+| satisfier 集合の inventory (deny-by-default)
+|--------------------------------------------------------------------------
+|
+| RecentAuthState::confirm() は「鮮度が成立した」と宣言する唯一の writer であり、
+| 呼び出し元が増えることは step-up の成立条件が増えることそのものである。
+| 未登録の呼び出し元が生えたら fail させ、PR review で必ず判断させる。
+|
+| ⚠ **この走査の限界**: token_get_all() ベースの静的走査であり、
+|   「RecentAuthState を参照しているファイルの中の `->confirm(` 呼び出し」という
+|   保守的な近似で検出する。完全に動的な呼び出し
+|   (`$cls = 'App\Security\RecentAuthState'; app($cls)->confirm()`) は取り逃がす。
+|   本テストの役割は「新しい satisfier を足すときに必ず PR で判断させる」ことに限定し、
+|   完全性の証明ではない。より強い保証が必要になったら AGENTS.md のコードベース探索方針
+|   どおり code-review-graph の AST グラフへ寄せること。
+*/
+
+/**
+ * RecentAuthState::confirm() を呼んでよいクラスの FQCN。
+ *
+ * @return list<string>
+ */
+function recentAuthSatisfierClasses(): array
+{
+    return [
+        // password 再入力
+        ConfirmRecentAuthController::class,
+        // 再SSO (step-up intent。本人性バインド済み)
+        SocialAuthController::class,
+        // fresh credential login (web guard・非 recaller)
+        StampRecentAuthOnLogin::class,
+        // passkey 検証成立 (confirm 経路。login 経路では Login が後勝ち。本人性バインド済み)
+        StampRecentAuthOnPasskeyVerified::class,
+    ];
+}
+
+/**
+ * @return list<string> app/ 配下の php ファイル絶対パス
+ */
+function recentAuthPhpFilesUnderApp(): array
+{
+    $files = [];
+    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
+
+    foreach ($iterator as $file) {
+        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
+            $files[] = $file->getPathname();
+        }
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * ソースが RecentAuthState::confirm() を呼んでいるか、および宣言クラスの FQCN。
+ *
+ * @return array{callsConfirm: bool, fqcn: string|null}
+ */
+function analyzeRecentAuthConfirmCalls(string $source): array
+{
+    $tokens = PhpToken::tokenize($source);
+    $count = count($tokens);
+
+    $namespace = null;
+    $class = null;
+    $referencesState = false;
+    $callsConfirm = false;
+
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
+
+        if ($token->is(T_NAMESPACE) && $namespace === null) {
+            $parts = [];
+            for ($j = $i + 1; $j < $count; $j++) {
+                if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                    $parts[] = $tokens[$j]->text;
+                } elseif ($tokens[$j]->text === ';' || $tokens[$j]->text === '{') {
+                    break;
+                }
+            }
+            $namespace = implode('\\', $parts);
+
+            continue;
+        }
+
+        if ($token->is(T_CLASS) && $class === null) {
+            for ($j = $i + 1; $j < $count; $j++) {
+                if ($tokens[$j]->is(T_STRING)) {
+                    $class = $tokens[$j]->text;
+                    break;
+                }
+                if ($tokens[$j]->text === '(') {
+                    break;   // 匿名クラス
+                }
+            }
+
+            continue;
+        }
+
+        // RecentAuthState への参照 (import / FQCN / 短縮名のいずれか)
+        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
+            && str_contains($token->text, 'RecentAuthState')) {
+            $referencesState = true;
+
+            continue;
+        }
+
+        // `->confirm(` の検出
+        if ($token->is(T_OBJECT_OPERATOR)
+            && isset($tokens[$i + 1])
+            && $tokens[$i + 1]->is(T_STRING)
+            && $tokens[$i + 1]->text === 'confirm') {
+            $callsConfirm = true;
+        }
+    }
+
+    $fqcn = ($namespace !== null && $namespace !== '' && $class !== null)
+        ? $namespace.'\\'.$class
+        : null;
+
+    return [
+        'callsConfirm' => $referencesState && $callsConfirm,
+        'fqcn' => $fqcn,
+    ];
+}
+
+test('RecentAuthState::confirm の呼び出し元は inventory に登録されたクラスのみ', function (): void {
+    $allowed = recentAuthSatisfierClasses();
+    $violations = [];
+    $checked = 0;
+
+    foreach (recentAuthPhpFilesUnderApp() as $path) {
+        $source = file_get_contents($path);
+        if (! is_string($source)) {
+            continue;
+        }
+
+        $analysis = analyzeRecentAuthConfirmCalls($source);
+        if (! $analysis['callsConfirm']) {
+            continue;
+        }
+
+        $checked++;
+
+        if ($analysis['fqcn'] === null || ! in_array($analysis['fqcn'], $allowed, true)) {
+            $violations[] = "{$path} が RecentAuthState::confirm() を呼んでいるが satisfier inventory に未登録";
+        }
+    }
+
+    expect($violations)->toBe([]);
+    // 呼び出し元が 1 件も見つからない = 走査が壊れている (空振り drift)
+    expect($checked)->toBeGreaterThan(0);
+});
+
+test('satisfier inventory のクラスは全て実在する', function (): void {
+    foreach (recentAuthSatisfierClasses() as $fqcn) {
+        expect(class_exists($fqcn))->toBeTrue("satisfier inventory のクラス {$fqcn} が存在しない");
+    }
+});
diff --git a/tests/Feature/Auth/LoginMethodInventoryTest.php b/tests/Feature/Auth/LoginMethodInventoryTest.php
new file mode 100644
index 0000000..3ebfbb8
--- /dev/null
+++ b/tests/Feature/Auth/LoginMethodInventoryTest.php
@@ -0,0 +1,193 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Auth\LoginMethodRemoval;
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+use App\Services\Auth\LoginMethodInventory;
+use App\Services\Auth\PasskeyLoginPolicy;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Features;
+use Laravel\Passkeys\Passkeys;
+
+/*
+ * LoginMethodInventory (投影後のログイン手段集合) と PasskeyLoginPolicy の契約。
+ *
+ * 基準は「データが存在する」ではなく「**使える**」。feature を落とした後も使えない手段を
+ * 数えると EnsureLoginMethodRemains が形骸化する。
+ */
+
+function inventory(): LoginMethodInventory
+{
+    return app(LoginMethodInventory::class);
+}
+
+function linkSocialAccount(User $user, string $provider = 'google'): void
+{
+    $account = new SocialAccount([
+        'provider' => $provider,
+        'provider_user_id' => 'ext-'.$user->getKey().'-'.$provider,
+    ]);
+    $account->user()->associate($user);
+    $account->save();
+}
+
+/** config/fortify.php の features から passkeys を外す (キルスイッチの再現) */
+function disablePasskeyFeature(): void
+{
+    config()->set(
+        'fortify.features',
+        array_values(array_filter(
+            config()->array('fortify.features'),
+            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
+        )),
+    );
+}
+
+test('password ユーザーは password を手段に持つ', function (): void {
+    $user = User::factory()->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toContain('password');
+});
+
+test('SSO 登録ユーザー (ssoOnly) は password を手段に持たない', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->not->toContain('password');
+});
+
+test('連携済み provider は social: 付きで数えられる', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkSocialAccount($user);
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toContain('social:google');
+});
+
+test('config から外された provider は連携行があっても数えない (fail-closed)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkSocialAccount($user);
+
+    config()->set('template.social_providers', []);
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
+});
+
+test('social 除去の投影で当該 provider が集合から消える', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkSocialAccount($user);
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::social('google'))->isEmpty())
+        ->toBeTrue();
+});
+
+test('password 除去の投影で password が集合から消える', function (): void {
+    $user = User::factory()->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::password())->isEmpty())
+        ->toBeTrue();
+});
+
+test('passkey は登録済みなら手段に数えられる', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toContain('passkey');
+});
+
+test('削除対象の passkey は残存手段として数えない (投影)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::passkey($passkey, $user))->isEmpty())
+        ->toBeTrue();
+});
+
+test('passkey が 2 件あれば 1 件削除しても手段が残る', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    $first = Passkey::factory()->for($user)->create();
+    Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::passkey($first, $user))->methods)
+        ->toContain('passkey');
+});
+
+test('allPasskeys 投影では passkey が全て消える', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->count(2)->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::allPasskeys())->isEmpty())
+        ->toBeTrue();
+});
+
+test('feature off では passkey を手段に数えない (キルスイッチが inventory に連動する)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->for($user)->create();
+
+    disablePasskeyFeature();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
+});
+
+test('TOTP confirmed ユーザーは passkey を手段に数えない (passkey login が拒否されるため)', function (): void {
+    $user = User::factory()->ssoOnly()->withTwoFactor()->create();
+    Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
+});
+
+/* ---------------------------------------------------------------- 不正状態の排除 */
+
+test('他人の passkey を LoginMethodRemoval::passkey に渡すと例外', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    $passkey = Passkey::factory()->for($other)->create();
+
+    expect(fn () => LoginMethodRemoval::passkey($passkey, $user))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('空 provider を LoginMethodRemoval::social に渡すと例外', function (): void {
+    expect(fn () => LoginMethodRemoval::social(''))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+/* -------------------------------------------- inventory と login authorization の一致 */
+
+/*
+ * 構造 gate では「同じ判定を 2 箇所に書いていない」ことしか固定できないため、
+ * 意味レベル (両者の結論が常に一致すること) をここで固定する。
+ */
+test('inventory の passkey 判定と Passkeys::allowsLogin が一致する (TOTP × feature の 4 組合せ)', function (
+    bool $twoFactor,
+    bool $featureEnabled,
+): void {
+    $factory = User::factory()->ssoOnly();
+    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    if (! $featureEnabled) {
+        disablePasskeyFeature();
+    }
+
+    $inventoryHasPasskey = in_array(
+        'passkey',
+        inventory()->remainingAfter($user->fresh() ?? $user, LoginMethodRemoval::none())->methods,
+        true,
+    );
+
+    $vendorAllows = Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
+
+    expect($inventoryHasPasskey)->toBe($vendorAllows);
+    expect($vendorAllows)->toBe(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user->fresh() ?? $user));
+})->with([
+    'TOTP なし / feature on' => [false, true],
+    'TOTP あり / feature on' => [true, true],
+    'TOTP なし / feature off' => [false, false],
+    'TOTP あり / feature off' => [true, false],
+]);
diff --git a/tests/Feature/Auth/LoginMethodRetentionTest.php b/tests/Feature/Auth/LoginMethodRetentionTest.php
new file mode 100644
index 0000000..a444a5a
--- /dev/null
+++ b/tests/Feature/Auth/LoginMethodRetentionTest.php
@@ -0,0 +1,222 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+use Illuminate\Support\Facades\DB;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * EnsureLoginMethodRemains の実挙動 (投影後評価・transport 別の拒否契約・直列化機構)。
+ *
+ * 分類 invariant (どの route に guard が必要か) は
+ * tests/Architecture/LoginMethodRemovalRouteTest.php が担う。
+ */
+
+/** password / social を持たず passkey だけでログインするユーザー */
+function passkeyOnlyUser(int $passkeys = 1): User
+{
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->count($passkeys)->for($user)->create();
+
+    return $user;
+}
+
+function linkGoogleTo(User $user): void
+{
+    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-'.$user->getKey()]);
+    $account->user()->associate($user);
+    $account->save();
+}
+
+/* ------------------------------------------------------------ 拒否 (手段が 0 になる) */
+
+/*
+ * Inertia には **422 JSON を返さない** (protocol 違反で router が解釈できず無言失敗する)。
+ * 302 + errors を返し、Inertia が DELETE の 302 を 303 へ変換する。
+ * 次の Inertia 訪問で `$page.props.errors.login_method` として読めることまで固定する
+ * (Svelte 側の表示契約そのもの)。
+ */
+test('唯一の passkey の削除は Inertia に redirect + errors.login_method で拒否される', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertStatus(303)
+        ->assertRedirect(route('settings.security'));
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+
+    // withHeaders はテスト内で永続するため明示的に捨てる。
+    // GET は素の HTML 訪問で検査する (X-Inertia を付けると asset version 不一致で 409 になる)
+    $this->flushHeaders();
+
+    $this->actingAs($user)
+        ->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Security')
+            ->where('errors.login_method', 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。'));
+});
+
+test('唯一の passkey の削除は純 XHR に 422 + login_method_required で拒否される', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->deleteJson("/user/passkeys/{$passkey->getKey()}");
+
+    $response->assertStatus(422)
+        ->assertHeader('Cache-Control', 'no-store, private')
+        ->assertJsonPath('code', 'login_method_required')
+        ->assertJsonPath('settingsUrl', route('settings.security'));
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+});
+
+test('唯一の passkey の削除は通常フォーム POST に back + errors で拒否される', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}");
+
+    $response->assertRedirect(route('settings.security'));
+    $response->assertSessionHasErrors('login_method');
+});
+
+test('TOTP confirmed ユーザーは passkey が 2 件あっても手段に数えないため削除が拒否される', function (): void {
+    $user = User::factory()->ssoOnly()->withTwoFactor()->create();
+    Passkey::factory()->count(2)->for($user)->create();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasErrors('login_method');
+
+    expect($user->passkeys()->count())->toBe(2);
+});
+
+/* ------------------------------------------------------------ 許可 (手段が残る) */
+
+test('passkey が 2 件あれば 1 件削除できる', function (): void {
+    $user = passkeyOnlyUser(passkeys: 2);
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}");
+
+    $response->assertRedirect(route('settings.security'));
+    $response->assertSessionHasNoErrors();
+    $response->assertSessionHas('success');
+    expect($user->passkeys()->count())->toBe(1);
+});
+
+test('password があれば唯一の passkey を削除できる', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
+});
+
+test('google 連携があれば唯一の passkey を削除できる', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkGoogleTo($user);
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
+});
+
+/* ------------------------------------------------------------ 直列化規約 (SQL レベル) */
+
+/*
+ * **このテストの限界を明記する**:
+ *   RefreshDatabase がテスト全体を 1 トランザクションで包むため、独立 connection による
+ *   実レース (passkey 2 件を同時削除して 0 件になる) は再現できない。
+ *   ここで固定するのは **機構**:
+ *     (a) 削除より前に users への `for update` select が発行される
+ *     (b) 両者が同一の transaction level で観測される
+ *     (c) その level がリクエスト開始前の level より大きい (middleware が新たに開いた証明)
+ *   ロックの **効果** (競合トランザクションの待機) は DB に委ねる。
+ */
+test('passkey 削除は users 行の for update ロック取得後に同一トランザクションで実行される', function (): void {
+    $user = passkeyOnlyUser(passkeys: 2);
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $baseLevel = DB::transactionLevel();
+
+    /** @var list<array{sql: string, level: int}> $observed */
+    $observed = [];
+    DB::listen(function ($query) use (&$observed): void {
+        $observed[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
+    });
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    $lockIndex = null;
+    $deleteIndex = null;
+    foreach ($observed as $index => $entry) {
+        if ($lockIndex === null && str_contains($entry['sql'], 'from "users"') && str_contains($entry['sql'], 'for update')) {
+            $lockIndex = $index;
+        }
+        if ($deleteIndex === null && str_starts_with($entry['sql'], 'delete from "passkeys"')) {
+            $deleteIndex = $index;
+        }
+    }
+
+    expect($lockIndex)->not->toBeNull('users 行の lockForUpdate が発行されていない');
+    expect($deleteIndex)->not->toBeNull('passkeys の delete が発行されていない');
+    expect($lockIndex)->toBeLessThan($deleteIndex, 'ロック取得より前に削除が走っている (TOCTOU)');
+
+    $lockLevel = $observed[$lockIndex]['level'];
+    expect($observed[$deleteIndex]['level'])->toBe($lockLevel, 'ロックと削除が別トランザクション');
+    // RefreshDatabase がテスト全体を包むため level は 1 から始まらない。必ず相対比較する
+    expect($lockLevel)->toBeGreaterThan($baseLevel, 'middleware が新しいトランザクションを開いていない');
+});
+
+test('拒否時には passkeys の delete が発行されない', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    /** @var list<string> $statements */
+    $statements = [];
+    DB::listen(function ($query) use (&$statements): void {
+        $statements[] = strtolower($query->sql);
+    });
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasErrors('login_method');
+
+    $deletes = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'delete from "passkeys"'));
+    expect($deletes)->toBe([]);
+});
diff --git a/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php b/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
new file mode 100644
index 0000000..8a98156
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+use App\Security\RecentAuthState;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+use Laravel\Passkeys\Events\PasskeyVerified;
+
+/*
+ * 2026-08-04 裁定 A: **credential 集合の変化 = recent-auth 失効**。
+ *
+ * パスキーは単独でログインできる強い資格であり、集合が変わったら直前に済ませた
+ * 本人確認は失効させる (家系統一原則)。UX の実害は「登録直後のタップ 1 回」に限られる。
+ *
+ * **裁定で見送られた強化オプション (登録直後の passkey を satisfier から除外する) は
+ * 実装しない**。そのことも本テストが明示的に固定する (実装されたら fail する)。
+ */
+
+test('passkey 削除で recent-auth 鮮度が失効する (実 HTTP 経路)', function (): void {
+    $user = User::factory()->create();   // password あり = 削除しても手段が残る
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+test('passkey 削除の直後は機微操作が step-up を要求する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    // 同一 session で続けてアカウント削除 (recent-auth 必須) を試みる
+    $this->actingAs($user)
+        ->delete('/settings/account')
+        ->assertRedirect(route('recent-auth.confirm'));
+
+    expect(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
+});
+
+/*
+ * 登録経路は WebAuthn ceremony を要するため HTTP では実走できない。
+ * vendor が dispatch する PasskeyRegistered に対する **listener の契約**を固定する。
+ */
+test('passkey 登録で recent-auth 鮮度が失効する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->startSession();
+    app(RecentAuthState::class)->confirm(method: 'password');
+    expect(session()->has('recent_auth_at'))->toBeTrue();
+
+    PasskeyRegistered::dispatch($user, $passkey);
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+    expect(session()->has('recent_auth_method'))->toBeFalse();
+});
+
+test('PasskeyDeleted イベント単体でも鮮度が失効する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->startSession();
+    app(RecentAuthState::class)->confirm(method: 'password');
+
+    PasskeyDeleted::dispatch($user, $passkey);
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+/*
+ * **裁定で見送られた強化オプションが実装されていないこと**の明示。
+ * 「登録直後の passkey は satisfier に使えない」を実装すると本テストが fail する。
+ * 再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時。
+ */
+test('登録直後の passkey でも再認証 (satisfier) は成立する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user);
+    $this->startSession();
+    request()->setUserResolver(static fn (): User => $user);
+
+    // 登録 → 鮮度失効
+    PasskeyRegistered::dispatch($user, $passkey);
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+
+    // その passkey で confirm すると鮮度が成立する (裁定どおり除外しない)
+    PasskeyVerified::dispatch($user, $passkey);
+    expect(session('recent_auth_method'))->toBe('passkey');
+});
diff --git a/tests/Feature/Auth/PasskeyRouteAccessTest.php b/tests/Feature/Auth/PasskeyRouteAccessTest.php
new file mode 100644
index 0000000..e6bca3e
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyRouteAccessTest.php
@@ -0,0 +1,167 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+
+/*
+ * passkey route の到達制御 (未認証 / step-up / 他人の credential / キャッシュ / throttle)。
+ *
+ * WebAuthn ceremony 自体はブラウザ API を要するため自動化しない。
+ * ここで固定するのは **ceremony に到達する前の関門**。
+ */
+
+test('未認証は passkey 登録 options に到達できない', function (): void {
+    $this->get('/user/passkeys/options')->assertRedirect('/login');
+});
+
+test('未認証は passkey 削除に到達できない', function (): void {
+    $this->delete('/user/passkeys/1')->assertRedirect('/login');
+});
+
+test('recent-auth 鮮度切れの Inertia mutation は 409 (step-up 要求)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->post('/user/passkeys', ['name' => 'テスト'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+});
+
+test('recent-auth 鮮度切れの登録 options 取得は confirm 画面へ誘導される', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->get('/user/passkeys/options')
+        ->assertRedirect(route('recent-auth.confirm'));
+});
+
+/*
+ * **他人の passkey と不在 id が同じ 404 になること** (AGENTS.md セキュリティ不変条件 2)。
+ * vendor 実装のままだと controller の `abort_unless(..., 403)` に到達し、
+ * 403 と 404 の差で他人の passkey の存在が漏れる。
+ */
+test('他人の passkey の削除は 404 (403 にしない)', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    $passkey = Passkey::factory()->for($other)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertNotFound();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+});
+
+test('不在 id の削除も同じ 404 (存在を漏らさない)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/999999')
+        ->assertNotFound();
+});
+
+test('非数値 id の削除は 500 ではなく 404 (pgsql 22P02 の回避)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/abc')
+        ->assertNotFound();
+});
+
+test('bigint 範囲外の id の削除も 404 (pgsql 22003 の回避)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/99999999999999999999999999')
+        ->assertNotFound();
+});
+
+test('guest の login options 応答は no-store (challenge をキャッシュさせない)', function (): void {
+    $response = $this->get('/passkeys/login/options');
+
+    $response->assertOk();
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
+
+test('passkeys limiter が未認証の challenge 発行を絞る', function (): void {
+    // limiter は 10/min。11 回目で 429 になる
+    for ($i = 0; $i < 10; $i++) {
+        $this->get('/passkeys/login/options')->assertOk();
+    }
+
+    $this->get('/passkeys/login/options')->assertStatus(429);
+});
+
+/*
+ * **登録 POST の request shape を vendor 契約に固定する**。
+ *
+ * vendor の PasskeyRegistrationRequest は
+ * `name` + `credential.{id,rawId,type,response}` の **nested** 形を要求する。
+ * client (PasskeySection.svelte) が `{ name, credential }` で送ることと対になっており、
+ * ここがズレると **登録が全面的に失敗する** (WebAuthn ceremony は自動化できないため、
+ * shape の食い違いは validation 段でしか検出できない)。
+ */
+test('登録 POST は nested な credential を要求する (flat 展開は validation で落ちる)', function (): void {
+    $user = User::factory()->create();
+
+    // 設計書の初稿にあった flat 形 ({ name, ...credential }) は credential 必須で落ちる
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/user/passkeys', [
+            'name' => 'テスト',
+            'id' => 'abc',
+            'rawId' => 'abc',
+            'type' => 'public-key',
+            'response' => ['clientDataJSON' => 'x'],
+        ])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('credential');
+});
+
+test('登録 POST の nested credential は rules を通過し ceremony 検証まで進む', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/user/passkeys', [
+            'name' => 'テスト',
+            'credential' => [
+                'id' => 'abc',
+                'rawId' => 'abc',
+                'type' => 'public-key',
+                'response' => ['clientDataJSON' => 'x'],
+            ],
+        ]);
+
+    // rules() は通過し、ceremony 検証 (credential のデシリアライズ) 側で落ちる。
+    // = 「必須項目が足りない」ではなく「中身が不正」という別の 422 になる
+    $response->assertStatus(422);
+    expect($response->json('errors.credential.0'))->not->toBe('The credential field is required.');
+});
+
+/*
+ * ログイン route は **guest session に鮮度を残さない**。
+ * (VerifyPasskey は allowsLogin より前に PasskeyVerified を dispatch するため、
+ *  StampRecentAuthOnPasskeyVerified の本人性バインドが唯一の防御線になる)
+ */
+test('passkey login の失敗は guest session に recent_auth を残さない', function (): void {
+    $response = $this->postJson('/passkeys/login', [
+        'credential' => [
+            'id' => 'abc',
+            'rawId' => 'abc',
+            'type' => 'public-key',
+            'response' => ['clientDataJSON' => 'x'],
+        ],
+    ]);
+
+    $response->assertStatus(422);
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+    $this->assertGuest();
+});
diff --git a/tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php b/tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php
new file mode 100644
index 0000000..54cf1de
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php
@@ -0,0 +1,79 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Auth\LoginMethodRemoval;
+use App\Models\Passkey;
+use App\Models\User;
+use App\Services\Auth\LoginMethodInventory;
+use App\Services\Auth\PasskeyLoginPolicy;
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Passkeys;
+
+/*
+ * passkey と TOTP (2FA) の関係 — **c2c 未裁定の論点に対する fail-closed 既定**。
+ *
+ * vendor の PasskeyLoginController::store() は $guard->login() を直接呼び、Fortify の
+ * two-factor challenge を通らない。したがって passkey login を許すと TOTP を迂回できる。
+ * PasskeyLoginPolicy が「TOTP confirmed なら passkey login を拒否」で fail-closed に倒す。
+ *
+ * 裁定が出たら PasskeyLoginPolicy 1 箇所を書き換えれば、login 認可 / inventory /
+ * UI prop の 3 経路が同時に反転する。本テストはその**現行既定**を固定する。
+ */
+
+function allowsPasskeyLoginFor(User $user): bool
+{
+    $passkey = Passkey::factory()->for($user)->create();
+
+    return Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
+}
+
+test('TOTP confirmed ユーザーは passkey login を拒否される', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    expect(allowsPasskeyLoginFor($user))->toBeFalse();
+});
+
+test('TOTP 無効ユーザーは passkey login を許可される', function (): void {
+    $user = User::factory()->create();
+
+    expect(allowsPasskeyLoginFor($user))->toBeTrue();
+});
+
+test('TOTP pending (未 confirm) ユーザーは passkey login を許可される', function (): void {
+    $user = User::factory()->create();
+    $user->forceFill(['two_factor_secret' => encrypt('pending-secret')])->save();
+
+    expect(allowsPasskeyLoginFor($user->fresh() ?? $user))->toBeTrue();
+});
+
+/*
+ * TOTP confirmed ユーザーにとって passkey は **初めからログイン手段に数えられていない**。
+ * したがって全 passkey を消しても残存手段の集合は変わらない
+ * (= passkey しか無い TOTP ユーザーの手段はもともと空)。
+ */
+test('TOTP confirmed ユーザーの手段集合は passkey の増減に影響されない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();   // password あり
+    Passkey::factory()->count(2)->for($user)->create();
+
+    $inventory = app(LoginMethodInventory::class);
+
+    expect($inventory->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toBe($inventory->remainingAfter($user, LoginMethodRemoval::allPasskeys())->methods);
+});
+
+/*
+ * passkey は **2FA 準拠判定に算入しない**。2FA 必須組織に属する TOTP 未設定ユーザーは、
+ * passkey を持っていても RequireTwoFactorForEnforcedOrganizations のゲートに掛かる。
+ */
+test('passkey 保有は 2FA 必須組織のゲートを免除しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+    Passkey::factory()->for($owner)->create();
+
+    // passkey login 自体は許可される (TOTP 未設定のため)
+    expect(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($owner))->toBeTrue();
+
+    // しかし 2FA 準拠にはならないため業務画面は 2FA 設定へ誘導される
+    $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('settings.security'));
+});
diff --git a/tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php b/tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php
index dac594e..56e9db8 100644
--- a/tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php
+++ b/tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php
@@ -2,6 +2,8 @@
 
 declare(strict_types=1);
 
+use App\Enums\SecurityEventType;
+use App\Models\SecurityAuditEvent;
 use App\Models\User;
 use Illuminate\Auth\SessionGuard;
 use Illuminate\Support\Facades\Auth;
@@ -133,3 +135,43 @@ function sessionRow(string $id, int $userId): array
 
     expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
 });
+
+/*
+ * T106 施策 2: パスワード変更の監査証跡。
+ *
+ * `SecurityEventType::PasswordChanged` は enum に存在しながら記録経路が無かった
+ * (`/reset-password` 経路のみ `Illuminate\Auth\Events\PasswordReset` 経由で記録されていた)。
+ * 「そのユーザーが自分でパスワードを設定したか」は、前方修正前に作られた
+ * legacy SSO ユーザーの phantom password (docs/template-divergence.md D13) を
+ * 将来判別する材料でもある。
+ */
+test('T106: パスワード変更が security_audit_events に記録される', function (): void {
+    $user = User::factory()->create(['password' => Hash::make('current-password')]);
+
+    $this->actingAs($user)->put('/user/password', [
+        'current_password' => 'current-password',
+        'password' => 'BrandNewPassw0rd!x',
+    ])->assertSessionHasNoErrors();
+
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::PasswordChanged->value)
+        ->where('user_id', $user->getKey())
+        ->first();
+
+    expect($event)->not->toBeNull();
+});
+
+test('T106: パスワード変更が失敗したときは記録しない (fail-closed)', function (): void {
+    $user = User::factory()->create(['password' => Hash::make('current-password')]);
+
+    $this->actingAs($user)->put('/user/password', [
+        'current_password' => 'wrong-password',
+        'password' => 'BrandNewPassw0rd!x',
+    ])->assertSessionHasErrors();
+
+    expect(
+        SecurityAuditEvent::query()
+            ->where('event_type', SecurityEventType::PasswordChanged->value)
+            ->exists(),
+    )->toBeFalse();
+});
diff --git a/tests/Feature/Auth/RecentAuthMethodStampingTest.php b/tests/Feature/Auth/RecentAuthMethodStampingTest.php
new file mode 100644
index 0000000..a797a7d
--- /dev/null
+++ b/tests/Feature/Auth/RecentAuthMethodStampingTest.php
@@ -0,0 +1,136 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
+use Laravel\Passkeys\Events\PasskeyVerified;
+use Laravel\Socialite\Contracts\Provider;
+use Laravel\Socialite\Contracts\User as SocialiteUserContract;
+use Laravel\Socialite\Facades\Socialite;
+use Mockery\MockInterface;
+
+/*
+ * recent-auth の satisfier ごとの最終 session state を経路別に固定する。
+ *
+ * PasskeyVerified は VerifyPasskey::__invoke() の**中**で dispatch されるため
+ * login 経路と confirm 経路の両方で発火する。最終状態は「順序」に依存するため
+ * (login では StampRecentAuthOnLogin が後勝ちで 'login' を書く)、経路ごとに固定する。
+ *
+ * **限界**: WebAuthn ceremony はブラウザ API を要するため自動化しない。
+ * passkey 経路は「vendor が dispatch する PasskeyVerified を直接発火させて
+ * **アプリ側 listener の契約**を検証する」形にとどめる (ceremony の正しさは vendor の責務)。
+ */
+
+function stampSocialiteCallback(string $providerUserId): void
+{
+    /** @var SocialiteUserContract&MockInterface $socialiteUser */
+    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
+    $socialiteUser->shouldReceive('getId')->andReturn($providerUserId);
+    $socialiteUser->shouldReceive('getEmail')->andReturn('stamp@example.com');
+    $socialiteUser->shouldReceive('getName')->andReturn('Stamp User');
+
+    $driver = Mockery::mock(Provider::class);
+    $driver->shouldReceive('user')->andReturn($socialiteUser);
+    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
+}
+
+test('password 再入力の satisfier は method=password を記録する', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->postJson('/recent-auth/password', ['password' => 'password'])
+        ->assertNoContent();
+
+    expect(session('recent_auth_method'))->toBe('password');
+});
+
+test('再SSO の satisfier は method=sso + provider を記録する', function (): void {
+    $user = User::factory()->create();
+    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'sso-stamp-1']);
+    $account->user()->associate($user);
+    $account->save();
+
+    stampSocialiteCallback('sso-stamp-1');
+
+    $this->actingAs($user)->get('/auth/google/redirect/step-up');
+    $this->actingAs($user)->get('/auth/google/callback');
+
+    expect(session('recent_auth_method'))->toBe('sso');
+    expect(session('recent_auth_provider'))->toBe('google');
+});
+
+test('通常ログインは method=login を記録する', function (): void {
+    $user = User::factory()->create();
+
+    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
+        ->assertRedirect();
+
+    expect(session('recent_auth_method'))->toBe('login');
+});
+
+/* ------------------------------------------------------------ passkey 経路 */
+
+test('passkey confirm 経路 (認証済み本人) は method=passkey を記録する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user);
+    $this->startSession();
+    // confirm 経路では VerifyPasskey が「認証済みユーザー本人」の文脈で dispatch する
+    request()->setUserResolver(static fn (): User => $user);
+
+    PasskeyVerified::dispatch($user, $passkey);
+
+    expect(session('recent_auth_method'))->toBe('passkey');
+    expect(session('recent_auth_at'))->toBeInt();
+});
+
+test('guest 文脈 (login 経路 / deny 経路) では鮮度を stamp しない', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->startSession();
+    request()->setUserResolver(static fn (): ?User => null);
+
+    PasskeyVerified::dispatch($user, $passkey);
+
+    // TOTP 有効ユーザーの passkey login が deny されても guest session に鮮度は残らない
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+test('他人の credential での検証は鮮度を成立させない (本人性バインド)', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    $passkey = Passkey::factory()->for($other)->create();
+
+    $this->actingAs($user);
+    $this->startSession();
+    request()->setUserResolver(static fn (): User => $user);
+
+    PasskeyVerified::dispatch($other, $passkey);
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+/*
+ * vendor の PasskeyConfirmationController::store() は `$session->passwordConfirmed()` で
+ * **Fortify の auth.password_confirmed_at を書く**。本アプリは RecentAuthState の契約で
+ * 「Fortify の鍵には書かない」としているため、Response 差し替えで確実に除去する。
+ */
+test('passkey confirm の応答は auth.password_confirmed_at を残さない', function (): void {
+    $this->startSession();
+    session()->put('auth.password_confirmed_at', time());
+
+    $request = Request::create('/passkeys/confirm', 'POST');
+    $request->setLaravelSession(session()->driver());
+
+    $response = app(PasskeyConfirmationResponseContract::class)->toResponse($request);
+
+    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
+    expect($response->getStatusCode())->toBe(204);
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
diff --git a/tests/Feature/Auth/RecentAuthTest.php b/tests/Feature/Auth/RecentAuthTest.php
index 95abd6f..61a5767 100644
--- a/tests/Feature/Auth/RecentAuthTest.php
+++ b/tests/Feature/Auth/RecentAuthTest.php
@@ -3,13 +3,17 @@
 declare(strict_types=1);
 
 use App\Listeners\Auth\StampRecentAuthOnLogin;
+use App\Models\Passkey;
 use App\Models\SocialAccount;
 use App\Models\User;
 use App\Security\RecentAuthState;
+use App\Services\Auth\PasskeyLoginPolicy;
 use Illuminate\Auth\Events\Login;
 use Illuminate\Auth\SessionGuard;
 use Illuminate\Contracts\Auth\Factory as AuthFactory;
 use Illuminate\Support\Facades\DB;
+use Inertia\Testing\AssertableInertia;
+use Laravel\Fortify\Features;
 use Laravel\Socialite\Contracts\Provider;
 use Laravel\Socialite\Contracts\User as SocialiteUserContract;
 use Laravel\Socialite\Facades\Socialite;
@@ -394,3 +398,90 @@ function linkGoogleAccount(User $user, string $providerUserId): void
     $response->assertSessionHasErrors('password');
     expect(session('recent_auth_at'))->toBeNull();
 });
+
+/*
+ * T106 施策 2: SSO 登録ユーザーの passwordSet が実挙動と一致する。
+ * phantom password 是正前は password 経路が使えないのに passwordSet=true になっていた
+ * (= 確認モーダルがパスワード入力欄を出して詰む)。
+ */
+test('T106: SSO 登録直後のユーザーは passwordSet=false / canSatisfy=true (再SSO が satisfier)', function (): void {
+    $this->withSession(['social_auth_intent' => 'register']);
+    fakeStepUpSocialiteCallback('g-t106-status');
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+
+    $user = User::whereBlind('email', 'email_index', 'step-up@example.com')->firstOrFail();
+
+    $this->actingAs($user)->getJson('/recent-auth/status')
+        ->assertOk()
+        ->assertJsonPath('passwordSet', false)
+        ->assertJsonPath('canSatisfy', true);
+});
+
+/*
+ * T106 施策 5/6: パスキーは recent-auth の satisfier であり、status 契約に載る。
+ *
+ * **passkey しか持たないユーザーを confirm 画面で詰ませない**ことが目的。
+ * 画面側が独自に判定すると特定画面でだけ詰むため、サーバの status を単一の源にする。
+ */
+test('T106: パスキー登録済みなら status の passkeyAvailable が true', function (): void {
+    $user = User::factory()->create();
+    Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)->getJson('/recent-auth/status')
+        ->assertOk()
+        ->assertJsonPath('passkeyAvailable', true)
+        ->assertJsonPath('canSatisfy', true);
+});
+
+test('T106: passkey しか持たないユーザーでも canSatisfy=true (詰ませない)', function (): void {
+    $user = User::factory()->ssoOnly()->create();   // password なし / SSO 連携なし
+    Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)->getJson('/recent-auth/status')
+        ->assertOk()
+        ->assertJsonPath('passwordSet', false)
+        ->assertJsonPath('availableProviders', [])
+        ->assertJsonPath('passkeyAvailable', true)
+        ->assertJsonPath('canSatisfy', true);
+});
+
+test('T106: TOTP 有効でも passkey は再認証手段として数える (ログイン可否とは別)', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+    Passkey::factory()->for($user)->create();
+
+    // ログインには使えない
+    expect(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user))->toBeFalse();
+
+    // 再認証には使える
+    $this->actingAs($user)->getJson('/recent-auth/status')
+        ->assertJsonPath('passkeyAvailable', true);
+});
+
+test('T106: passkeys feature off では passkeyAvailable が false (route ごと消えるため)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->for($user)->create();
+
+    config()->set(
+        'fortify.features',
+        array_values(array_filter(
+            config()->array('fortify.features'),
+            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
+        )),
+    );
+
+    $this->actingAs($user)->getJson('/recent-auth/status')
+        ->assertJsonPath('passkeyAvailable', false)
+        ->assertJsonPath('canSatisfy', false);
+});
+
+test('T106: confirm 画面 (Inertia) にも passkeyAvailable が渡る', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)->get(route('recent-auth.confirm'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Auth/ConfirmRecentAuth')
+            ->where('passkeyAvailable', true)
+            ->where('canSatisfy', true));
+});
diff --git a/tests/Feature/Auth/SocialAuthTest.php b/tests/Feature/Auth/SocialAuthTest.php
index 82015d1..efc40ee 100644
--- a/tests/Feature/Auth/SocialAuthTest.php
+++ b/tests/Feature/Auth/SocialAuthTest.php
@@ -217,3 +217,26 @@ function fakeSocialiteCallback(SocialiteUserContract $user): void
 test('無効なプロバイダは 404', function (): void {
     $this->get('/auth/unknown/redirect/login')->assertNotFound();
 });
+
+/*
+ * T106 施策 2: SSO 登録の phantom password 是正 (前方修正のみ)。
+ *
+ * 旧実装は `Str::password(32)` をハッシュ化して保存していたため、SSO-only ユーザーでも
+ * `User::hasPassword()` が常に true を返していた (recent-auth の passwordSet と
+ * EnsureLoginMethodRemains の双方が形骸化する)。
+ * **既存ユーザーへの遡及是正は行わない** (password 登録後に SSO 連携したユーザーの
+ * 実パスワード消失リスクのため。docs/template-divergence.md D13)。
+ */
+test('T106: SSO register で作られた User は password を持たない', function (): void {
+    $this->withSession(['social_auth_intent' => 'register']);
+    fakeSocialiteCallback(fakeSocialiteUser('g-t106', 'sso-t106@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+
+    $user = User::whereBlind('email', 'email_index', 'sso-t106@example.com')->firstOrFail();
+
+    expect($user->getAttribute('password'))->toBeNull();
+    expect($user->hasPassword())->toBeFalse();
+    // 施策 1 (T105) との相互作用の回帰: email_verified_at は従来どおり立つ
+    expect($user->email_verified_at)->not->toBeNull();
+});
diff --git a/tests/Feature/Settings/SecurityPagePropsTest.php b/tests/Feature/Settings/SecurityPagePropsTest.php
new file mode 100644
index 0000000..535f922
--- /dev/null
+++ b/tests/Feature/Settings/SecurityPagePropsTest.php
@@ -0,0 +1,76 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+use Inertia\Testing\AssertableInertia;
+use Laravel\Fortify\Features;
+
+/*
+ * Settings/Security の Inertia prop 契約 (passkeys 一覧 / passkeyLoginAvailable)。
+ *
+ * prop の shape は resources/js/lib/passkeys.ts の PasskeyListItem と 1:1。
+ * credential 本体 (公開鍵 / signature counter) を露出しないことも固定する。
+ */
+
+test('passkey 未登録なら passkeys は空配列', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Security')
+            ->where('passkeys', [])
+            ->where('passkeyLoginAvailable', true));
+});
+
+test('登録済み passkey が一覧 prop に載る (credential 本体は載せない)', function (): void {
+    $user = User::factory()->create();
+    Passkey::factory()->for($user)->create(['name' => '現場用スマホ']);
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Security')
+            ->has('passkeys', 1, fn (AssertableInertia $item) => $item
+                ->has('id')
+                ->where('name', '現場用スマホ')
+                ->where('authenticator', null)
+                ->where('lastUsedAt', null)
+                ->has('createdAt')
+                ->missing('credential')
+                ->missing('credential_id')
+                ->missing('user_id')));
+});
+
+test('TOTP 有効ユーザーは passkeyLoginAvailable が false (再認証には使える)', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('passkeyLoginAvailable', false));
+});
+
+test('feature off では passkeyLoginAvailable が false (キルスイッチ)', function (): void {
+    $user = User::factory()->create();
+
+    config()->set(
+        'fortify.features',
+        array_values(array_filter(
+            config()->array('fortify.features'),
+            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
+        )),
+    );
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('passkeyLoginAvailable', false));
+});
+
+test('他人の passkey は一覧に載らない', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    Passkey::factory()->for($other)->create();
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('passkeys', []));
+});
diff --git a/tests/js/architecture/passkeys-import-isolation.test.ts b/tests/js/architecture/passkeys-import-isolation.test.ts
new file mode 100644
index 0000000..3bc7b4e
--- /dev/null
+++ b/tests/js/architecture/passkeys-import-isolation.test.ts
@@ -0,0 +1,73 @@
+import { describe, expect, it } from "vitest";
+import fs from "node:fs/promises";
+import path from "node:path";
+import { fileURLToPath } from "node:url";
+
+/*
+ * `@/lib/passkeys` の import 元を allowlist で固定する (deny-by-default)。
+ *
+ * 理由: WebAuthn ceremony は「options 取得 → 認証器操作 → 送信」の 3 段で、
+ * 送信先とレスポンス契約 (Inertia か fetch か / 302 か 204 か) が operation ごとに違う
+ * (詳細設計 施策 4-d の transport 契約)。呼び出し元が無秩序に増えると
+ * 契約の食い違いが**無言失敗**として現れる (router が応答を解釈できない)。
+ *
+ * 増やすときは transport 契約の該当行と併せて判断すること。
+ */
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const RESOURCES_JS = path.resolve(HERE, "../../../resources/js");
+
+/** `@/lib/passkeys` を import してよいファイル (resources/js からの相対パス) */
+const ALLOWED_IMPORTERS: ReadonlySet<string> = new Set([
+    // パスキーの登録 / 削除 (Inertia transport)
+    "components/features/auth/PasskeySection.svelte",
+    // step-up 再認証 (fetch + 204 transport)
+    "components/organisms/RecentAuthModal.svelte",
+    // guest のパスキーログイン (fetch + {redirect} transport)
+    "pages/Auth/Login.svelte",
+    // passkeys prop の型 (PasskeyListItem) を PasskeySection へ渡す page
+    "pages/Settings/Security.svelte",
+    // 全画面の step-up confirm 画面 (Inertia transport。サーバの intended へ戻す)
+    "pages/Auth/ConfirmRecentAuth.svelte",
+]);
+
+const TARGET_EXTENSIONS: ReadonlySet<string> = new Set([".ts", ".svelte"]);
+
+const listFiles = async (dir: string): Promise<string[]> => {
+    const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
+    const files: string[] = [];
+    for (const entry of entries) {
+        if (!entry.isFile()) continue;
+        if (!TARGET_EXTENSIONS.has(path.extname(entry.name))) continue;
+        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
+        files.push(path.join(parent, entry.name));
+    }
+    return files;
+};
+
+const IMPORT_PATTERN = /from\s+["'](@\/lib\/passkeys)["']|import\s+["'](@\/lib\/passkeys)["']/;
+
+describe("passkeys import isolation", () => {
+    it("@/lib/passkeys の import 元は allowlist のみ", async () => {
+        const files = await listFiles(RESOURCES_JS);
+        const importers: string[] = [];
+
+        for (const file of files) {
+            const relative = path.relative(RESOURCES_JS, file).split(path.sep).join("/");
+            if (relative === "lib/passkeys.ts") continue;
+            const content = await fs.readFile(file, "utf-8");
+            if (IMPORT_PATTERN.test(content)) {
+                importers.push(relative);
+            }
+        }
+
+        const unexpected = importers.filter((file) => !ALLOWED_IMPORTERS.has(file));
+        expect(unexpected).toEqual([]);
+
+        // 走査が空振りしていない (allowlist の全員が実際に import している)
+        expect(importers.length).toBeGreaterThan(0);
+        for (const allowed of ALLOWED_IMPORTERS) {
+            expect(importers).toContain(allowed);
+        }
+    });
+});
diff --git a/tests/js/lib/passkeys.test.ts b/tests/js/lib/passkeys.test.ts
new file mode 100644
index 0000000..21a5c7b
--- /dev/null
+++ b/tests/js/lib/passkeys.test.ts
@@ -0,0 +1,250 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import {
+    base64UrlToBuffer,
+    bufferToBase64Url,
+    canCreatePasskey,
+    confirmWithPasskey,
+    createPasskeyCredential,
+    isPasskeySupported,
+    loginWithPasskey,
+} from "@/lib/passkeys";
+
+/*
+ * WebAuthn ラッパの分岐契約。
+ *
+ * **限界**: 実 ceremony は jsdom でエミュレートできない (仮想認証器が要る)。
+ * ここで固定するのは
+ *   - feature detection (非対応端末で throw しない / unsupported を返す)
+ *   - キャンセル (NotAllowedError) を "cancelled" に畳むこと
+ *   - base64url 変換の往復
+ *   - fetch のヘッダ契約 (Accept: application/json が無いと
+ *     PasskeyLoginResponse の JSON 分岐に入らない)
+ * 実 ceremony の確認は docs/supported-browsers.md の実機受入確認に委ねる。
+ */
+
+const originalNavigator = globalThis.navigator;
+
+interface CredentialsStub {
+    create: ReturnType<typeof vi.fn>;
+    get: ReturnType<typeof vi.fn>;
+}
+
+function stubWebAuthnApis(credentials: Partial<CredentialsStub> = {}): CredentialsStub {
+    const stub: CredentialsStub = {
+        create: vi.fn(),
+        get: vi.fn(),
+        ...credentials,
+    } as CredentialsStub;
+
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: stub },
+    });
+
+    const publicKeyCredential = function PublicKeyCredentialStub() {
+        // 実体は使わない (instanceof 判定にのみ使う)
+    } as unknown as typeof PublicKeyCredential;
+    (
+        publicKeyCredential as unknown as {
+            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+        }
+    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(true);
+
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: publicKeyCredential,
+    });
+
+    return stub;
+}
+
+function removeWebAuthnApis(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+afterEach(() => {
+    vi.restoreAllMocks();
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: originalNavigator,
+    });
+    removeWebAuthnApis();
+});
+
+describe("feature detection", () => {
+    it("PublicKeyCredential 不在では未対応と判定する", () => {
+        removeWebAuthnApis();
+        expect(isPasskeySupported()).toBe(false);
+    });
+
+    it("PublicKeyCredential があれば対応と判定する", () => {
+        stubWebAuthnApis();
+        expect(isPasskeySupported()).toBe(true);
+    });
+
+    it("未対応端末では canCreatePasskey が false (throw しない)", async () => {
+        removeWebAuthnApis();
+        await expect(canCreatePasskey()).resolves.toBe(false);
+    });
+
+    it("isUserVerifyingPlatformAuthenticatorAvailable の reject を false に畳む", async () => {
+        stubWebAuthnApis();
+        (
+            window.PublicKeyCredential as unknown as {
+                isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+            }
+        ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.reject(new Error("nope"));
+
+        await expect(canCreatePasskey()).resolves.toBe(false);
+    });
+
+    it("未対応端末では ceremony が unsupported を返す (例外にしない)", async () => {
+        removeWebAuthnApis();
+        await expect(createPasskeyCredential()).resolves.toEqual({ status: "unsupported" });
+        await expect(loginWithPasskey()).resolves.toEqual({ status: "unsupported" });
+        await expect(confirmWithPasskey()).resolves.toEqual({ status: "unsupported" });
+    });
+});
+
+describe("base64url", () => {
+    it("往復して元の文字列に戻る", () => {
+        const samples = ["AQIDBA", "-_-_", "aGVsbG8", "AA"];
+        for (const sample of samples) {
+            expect(bufferToBase64Url(base64UrlToBuffer(sample))).toBe(sample);
+        }
+    });
+
+    it("padding / + / を含まない", () => {
+        const bytes = new Uint8Array([251, 255, 190, 239]);
+        const encoded = bufferToBase64Url(bytes.buffer);
+        expect(encoded).not.toContain("=");
+        expect(encoded).not.toContain("+");
+        expect(encoded).not.toContain("/");
+    });
+});
+
+describe("ceremony の分岐", () => {
+    let fetchMock: ReturnType<typeof vi.fn>;
+
+    beforeEach(() => {
+        fetchMock = vi.fn();
+        vi.stubGlobal("fetch", fetchMock);
+    });
+
+    function optionsResponse(options: Record<string, unknown>): unknown {
+        return { ok: true, status: 200, json: () => Promise.resolve({ options }) };
+    }
+
+    const loginOptions = {
+        challenge: "AQIDBA",
+        rpId: "localhost",
+        allowCredentials: [{ id: "AQIDBA", type: "public-key" }],
+        userVerification: "required",
+        timeout: 60000,
+    };
+
+    it("ユーザーキャンセル (NotAllowedError) を cancelled に畳む", async () => {
+        const credentials = stubWebAuthnApis();
+        fetchMock.mockResolvedValue(optionsResponse(loginOptions));
+        const cancelled = new Error("cancelled");
+        cancelled.name = "NotAllowedError";
+        credentials.get.mockRejectedValue(cancelled);
+
+        await expect(loginWithPasskey()).resolves.toEqual({ status: "cancelled" });
+    });
+
+    it("options 取得失敗は failed (メッセージ付き)", async () => {
+        stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        const outcome = await loginWithPasskey();
+        expect(outcome.status).toBe("failed");
+    });
+
+    it("options 取得は Accept: application/json を送る", async () => {
+        stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        await loginWithPasskey();
+
+        expect(fetchMock).toHaveBeenCalledWith(
+            "/passkeys/login/options",
+            expect.objectContaining({
+                method: "GET",
+                credentials: "same-origin",
+                headers: expect.objectContaining({ Accept: "application/json" }),
+            }),
+        );
+    });
+
+    it("登録 ceremony は options endpoint を叩き、送信までは行わない", async () => {
+        const credentials = stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        await createPasskeyCredential();
+
+        expect(fetchMock).toHaveBeenCalledTimes(1);
+        expect(fetchMock.mock.calls[0][0]).toBe("/user/passkeys/options");
+        expect(credentials.create).not.toHaveBeenCalled();
+    });
+
+    it("confirm は POST に CSRF / Content-Type ヘッダを付ける", async () => {
+        const credentials = stubWebAuthnApis();
+        document.cookie = "XSRF-TOKEN=test-token";
+        fetchMock.mockImplementation((url: string) =>
+            url.endsWith("/options")
+                ? Promise.resolve(optionsResponse(loginOptions))
+                : Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) }),
+        );
+
+        // navigator.credentials.get が PublicKeyCredential インスタンスを返すよう偽装する
+        const credential = Object.create(
+            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
+        ) as Record<string, unknown>;
+        credential.id = "cred-id";
+        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
+        credential.type = "public-key";
+        credential.response = {};
+        credentials.get.mockResolvedValue(credential);
+
+        const outcome = await confirmWithPasskey();
+
+        expect(outcome.status).toBe("ok");
+        const postCall = fetchMock.mock.calls.find(([url]) => url === "/passkeys/confirm");
+        expect(postCall).toBeDefined();
+        expect(postCall?.[1]).toMatchObject({
+            method: "POST",
+            headers: expect.objectContaining({
+                Accept: "application/json",
+                "Content-Type": "application/json",
+                "X-XSRF-TOKEN": "test-token",
+            }),
+        });
+    });
+
+    it("login は redirect を含まない応答を failed に畳む (非 JSON / 想定外 shape の拒否)", async () => {
+        const credentials = stubWebAuthnApis();
+        fetchMock.mockImplementation((url: string) =>
+            url.endsWith("/options")
+                ? Promise.resolve(optionsResponse(loginOptions))
+                : Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }),
+        );
+
+        const credential = Object.create(
+            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
+        ) as Record<string, unknown>;
+        credential.id = "cred-id";
+        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
+        credential.type = "public-key";
+        credential.response = {};
+        credentials.get.mockResolvedValue(credential);
+
+        const outcome = await loginWithPasskey();
+        expect(outcome.status).toBe("failed");
+    });
+});
diff --git a/tests/js/pages/LoginPasskey.test.ts b/tests/js/pages/LoginPasskey.test.ts
new file mode 100644
index 0000000..31894f7
--- /dev/null
+++ b/tests/js/pages/LoginPasskey.test.ts
@@ -0,0 +1,86 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import Login from "@/pages/Auth/Login.svelte";
+
+/*
+ * ログイン画面のパスキー導線 (T106 施策 6)。
+ * - 非対応ブラウザではボタン自体を出さない (押しても何もできない導線を出さない)
+ * - 失敗時もパスワード欄と SSO ボタンを残す (回復導線を消さない)
+ */
+
+const fetchMock = vi.fn();
+
+function stubPasskeySupport(): void {
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: { create: vi.fn(), get: vi.fn() } },
+    });
+    const publicKeyCredential = function PublicKeyCredentialStub() {
+        // instanceof 判定にのみ使う
+    } as unknown as typeof PublicKeyCredential;
+    (
+        publicKeyCredential as unknown as {
+            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+        }
+    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(true);
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: publicKeyCredential,
+    });
+}
+
+function removePasskeySupport(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+beforeEach(() => {
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    removePasskeySupport();
+    fetchMock.mockReset();
+});
+
+describe("Auth/Login パスキー導線", () => {
+    it("非対応ブラウザではパスキーボタンを出さない", () => {
+        removePasskeySupport();
+        render(Login, { props: { appName: "My App", socialProviders: [] } });
+
+        expect(screen.queryByTestId("passkey-login-button")).toBeNull();
+    });
+
+    it("対応ブラウザではボタンと 2FA の但し書きを出す", () => {
+        stubPasskeySupport();
+        render(Login, { props: { appName: "My App", socialProviders: [] } });
+
+        const button = screen.getByTestId("passkey-login-button");
+        expect(button).toBeInTheDocument();
+        expect(button).not.toBeDisabled();
+        expect(
+            screen.getByText(/2要素認証を有効にしているアカウントでは、パスキーでログインできません。/),
+        ).toBeInTheDocument();
+    });
+
+    it("失敗してもパスワード欄と SSO ボタンを残す (回復導線を消さない)", async () => {
+        stubPasskeySupport();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        render(Login, { props: { appName: "My App", socialProviders: ["google"] } });
+
+        await fireEvent.click(screen.getByTestId("passkey-login-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("passkey-login-error")).toBeInTheDocument();
+        });
+        expect(screen.getByLabelText("パスワード")).toBeInTheDocument();
+        expect(screen.getByTestId("sso-login-google")).toBeInTheDocument();
+    });
+});
diff --git a/tests/js/pages/SettingsSecurityPasskey.test.ts b/tests/js/pages/SettingsSecurityPasskey.test.ts
new file mode 100644
index 0000000..2cd27cc
--- /dev/null
+++ b/tests/js/pages/SettingsSecurityPasskey.test.ts
@@ -0,0 +1,360 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import Security from "@/pages/Settings/Security.svelte";
+
+/*
+ * セキュリティ設定のパスキーカード (T106 施策 6)。
+ * - 非対応 / 作成不可の端末に理由を出す (ボタンは disabled にしない = AGENTS.md 禁止事項 8)
+ * - 2FA 有効時は「ログインには使えないが再認証には使える」を明示する (誤認防止)
+ * - 登録 / 削除は recent-auth precheck を通す (stale なら再認証モーダル)
+ * - EnsureLoginMethodRemains の拒否 (errors.login_method) を画面に出す (無言失敗にしない)
+ */
+
+const { routerPostMock, routerDeleteMock, pageState, addToastMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    routerDeleteMock: vi.fn(),
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings/security",
+    },
+    addToastMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock, delete: routerDeleteMock },
+    page: pageState,
+}));
+
+vi.mock("@/lib/stores/toast", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
+    addToast: addToastMock,
+}));
+
+/*
+ * 登録 ceremony はブラウザ API を要するため、ラッパの戻り値だけを差し替えて
+ * **送信 payload の shape** を固定する (vendor の PasskeyRegistrationRequest は
+ * `{ name, credential: {...} }` の nested 形を要求する。サーバ側の対の固定は
+ * tests/Feature/Auth/PasskeyRouteAccessTest.php)。
+ */
+const { createPasskeyCredentialMock } = vi.hoisted(() => ({
+    createPasskeyCredentialMock: vi.fn(),
+}));
+
+vi.mock("@/lib/passkeys", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/passkeys")>()),
+    createPasskeyCredential: createPasskeyCredentialMock,
+}));
+
+const CREDENTIAL_FIXTURE = {
+    id: "cred-id",
+    rawId: "cred-raw-id",
+    type: "public-key",
+    response: { clientDataJSON: "aaa", attestationObject: "bbb" },
+};
+
+const fetchMock = vi.fn();
+
+function setPageProps(options: { twoFactor?: boolean; errors?: Record<string, string> } = {}): void {
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: options.twoFactor ?? false } },
+        errors: options.errors ?? {},
+    };
+}
+
+/** WebAuthn 対応端末を偽装する */
+function stubPasskeySupport(creatable = true): void {
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: { create: vi.fn(), get: vi.fn() } },
+    });
+    const publicKeyCredential = function PublicKeyCredentialStub() {
+        // instanceof 判定にのみ使う
+    } as unknown as typeof PublicKeyCredential;
+    (
+        publicKeyCredential as unknown as {
+            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+        }
+    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(creatable);
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: publicKeyCredential,
+    });
+}
+
+function removePasskeySupport(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
+    return { ok, status, json: () => Promise.resolve(body) };
+}
+
+function stubRecentAuth(recent: boolean, passkeyAvailable = false): void {
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent,
+                    passwordSet: true,
+                    availableProviders: [],
+                    passkeyAvailable,
+                    canSatisfy: true,
+                    confirmedAt: recent ? 1 : null,
+                }),
+            );
+        }
+        return Promise.resolve(jsonResponse(false, 500, {}));
+    });
+}
+
+const passkeys = [
+    {
+        id: 7,
+        name: "現場用スマホ",
+        authenticator: "iCloud Keychain",
+        lastUsedAt: "2026-08-01T00:00:00+09:00",
+        createdAt: "2026-07-01T00:00:00+09:00",
+    },
+];
+
+beforeEach(() => {
+    setPageProps();
+    stubPasskeySupport();
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    removePasskeySupport();
+    routerPostMock.mockReset();
+    routerDeleteMock.mockReset();
+    addToastMock.mockReset();
+    fetchMock.mockReset();
+    createPasskeyCredentialMock.mockReset();
+});
+
+describe("Settings/Security パスキーカード", () => {
+    it("非対応ブラウザでは理由を出すが登録ボタンは disabled にしない", () => {
+        removePasskeySupport();
+        render(Security, { props: {} });
+
+        expect(screen.getByTestId("passkey-unsupported")).toBeInTheDocument();
+        expect(screen.getByTestId("register-passkey-button")).not.toBeDisabled();
+    });
+
+    it("非対応ブラウザで登録を押すと理由をトーストで出す (無言失敗にしない)", async () => {
+        removePasskeySupport();
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        expect(addToastMock).toHaveBeenCalledWith("error", expect.stringContaining("対応していません"));
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("プラットフォーム認証器が使えない端末には作成不可の理由を出す", async () => {
+        stubPasskeySupport(false);
+        render(Security, { props: {} });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("passkey-not-creatable")).toBeInTheDocument();
+        });
+    });
+
+    it("2FA 有効時は「ログイン不可・再認証は可」を明示する", () => {
+        setPageProps({ twoFactor: true });
+        render(Security, { props: { passkeyLoginAvailable: false } });
+
+        expect(screen.getByTestId("passkey-2fa-notice")).toBeInTheDocument();
+    });
+
+    it("2FA 無効かつ passkeyLoginAvailable なら 2FA 注意書きを出さない", () => {
+        render(Security, { props: { passkeyLoginAvailable: true } });
+
+        expect(screen.queryByTestId("passkey-2fa-notice")).toBeNull();
+    });
+
+    it("登録済みパスキーを一覧表示する", () => {
+        render(Security, { props: { passkeys } });
+
+        expect(screen.getByTestId("passkey-list")).toBeInTheDocument();
+        expect(screen.getByText("現場用スマホ")).toBeInTheDocument();
+        expect(screen.getByTestId("passkey-count")).toHaveTextContent("1 件登録済み");
+    });
+
+    it("名前未入力の登録はエラー表示のみで ceremony を開始しない", async () => {
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument();
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("削除は確認ダイアログを挟み、確認までは DELETE しない", async () => {
+        stubRecentAuth(true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+
+        // 一覧側にも同名が出るためダイアログ本体で照合する
+        expect(screen.getByTestId("delete-passkey-dialog")).toHaveTextContent("現場用スマホ");
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+    });
+
+    it("確認後は recent-auth precheck を通して DELETE する", async () => {
+        stubRecentAuth(true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(routerDeleteMock).toHaveBeenCalledWith(
+                "/user/passkeys/7",
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("recent-auth が stale なら再認証モーダルを開き DELETE しない", async () => {
+        stubRecentAuth(false);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+    });
+
+    it("ログイン手段保持 guard の拒否メッセージを画面に出す (無言失敗にしない)", () => {
+        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
+        render(Security, { props: { passkeys } });
+
+        const alert = screen.getByTestId("passkey-login-method-error");
+        expect(alert).toBeInTheDocument();
+        expect(alert).toHaveTextContent("ログインする手段がなくなります");
+        // 回復導線 (別のログイン手段を追加する) を同画面に出す
+        expect(screen.getByTestId("passkey-add-password")).toBeInTheDocument();
+    });
+
+    // passkey 導線の可否は **サーバの status が単一の源** (画面側で判定しない)
+    it("status の passkeyAvailable が true なら再認証モーダルにパスキー導線が出る", async () => {
+        stubRecentAuth(false, true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
+        });
+    });
+
+    it("status の passkeyAvailable が false ならパスキー導線を出さない", async () => {
+        stubRecentAuth(false, false);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
+    });
+});
+
+describe("パスキー登録の送信契約", () => {
+    it("ceremony 成功時に { name, credential } の nested 形で POST する", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({
+            status: "ok",
+            value: CREDENTIAL_FIXTURE,
+        });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalledWith(
+                "/user/passkeys",
+                { name: "現場用スマホ", credential: CREDENTIAL_FIXTURE },
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        // recent-auth precheck を経由している (登録も step-up 必須)
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("recent-auth が stale なら再認証モーダルを開き ceremony を開始しない", async () => {
+        stubRecentAuth(false);
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(createPasskeyCredentialMock).not.toHaveBeenCalled();
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("ceremony キャンセルは POST せず、エラートーストも出さない (騒がない)", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({ status: "cancelled" });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(createPasskeyCredentialMock).toHaveBeenCalled();
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+        expect(addToastMock).not.toHaveBeenCalled();
+    });
+
+    it("ceremony 失敗はエラーを出して POST しない", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({
+            status: "failed",
+            message: "パスキーの登録を開始できませんでした。",
+        });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => {
+            expect(addToastMock).toHaveBeenCalledWith(
+                "error",
+                "パスキーの登録を開始できませんでした。",
+            );
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+});

```

---

## 修正後の差分 (3) docs (Round 1 では diff の切り出し範囲外だったため見えていなかった)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index c066bad..22e1438 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -82,6 +82,7 @@ ## ドメインモデル (テンプレート同梱)
 | `Role` / `Permission` | Laratrust のロール・権限 (seed 固定) | Team スコープ |
 | `OrganizationInvitation` | 組織招待 (token は hash 保存) | Organization 従属 |
 | `SocialAccount` | ソーシャルログイン連携 | User 従属 |
+| `Passkey` | パスキー (WebAuthn credential)。vendor モデル (`Laravel\Passkeys\Passkey`) の app サブクラス。アカウント削除で cascade 削除。契約は [docs/auth-security-mechanisms.md](auth-security-mechanisms.md) §5 | User 従属 |
 | `ApiKey` | REST API / MCP 認証キー (組織スコープ、secret は hash 保存) | Organization 従属 |
 | `OauthSession` | OAuth セッション (CLI ログインの認可承認 1 回 = 1 行。token chain を集約、失効単位) | Organization / User 従属 |
 | `IdempotencyKey` | API 冪等キー (API キー actor / OAuth user actor 単位) | ApiKey または User 従属 |
@@ -124,7 +125,9 @@ ## 主要 Service (テンプレート同梱)
 | `Render/VideoComposer` (interface) + `Render/FfmpegVideoComposer` | AI-CUE: 動画合成の抽象 + ffmpeg v1 実装 (Process facade 経由・配列引数。filtergraph にはサーバ生成一時ファイル名と数値のみ = 字幕本文を直接埋めない) |
 | `Render/AssSubtitleWriter` | AI-CUE: ASS 字幕生成の安全境界 (唯一の字幕テキスト出力点。リテラル \N/override tag/制御文字/zero-width の正規化 + mb 安全な長さ上限) |
 | `Render/RenderObjectStorage` | AI-CUE: レンダ出力 S3 操作の集約点 (download/upload/署名 URL/削除/prefix。DL 用 Content-Disposition は RFC 5987 + ASCII fallback + ヘッダ注入不能) |
-| `Auth/SocialAccountService` | ソーシャルログイン連携。SSO 登録時の `email_verified_at` は `Auth/EmailTrust/EmailTrustPolicyResolver` (provider ごとの `email_trust` 宣言) 経由でのみ立てる (nOAuth 対策。契約は [docs/auth-security-mechanisms.md](auth-security-mechanisms.md) §4) |
+| `Auth/SocialAccountService` | ソーシャルログイン連携。SSO 登録時の `email_verified_at` は `Auth/EmailTrust/EmailTrustPolicyResolver` (provider ごとの `email_trust` 宣言) 経由でのみ立てる (nOAuth 対策。契約は [docs/auth-security-mechanisms.md](auth-security-mechanisms.md) §4)。**SSO 登録は password を持たない** (`hasPassword()` が fail-closed で判定できるようにする。前方修正のみ = 既存ユーザーの phantom password は遡及是正しない。[docs/template-divergence.md](template-divergence.md) D13) |
+| `Auth/LoginMethodInventory` | 「ログイン画面から本人がアカウントに入れる手段」の投影後集合。`EnsureLoginMethodRemains` が唯一の呼び出し点 (行ロック下で評価する契約) |
+| `Auth/PasskeyLoginPolicy` | passkey **ログイン**可否の単一判定点 (feature flag + TOTP)。vendor の login ゲート / inventory / UI prop が共有する |
 | `Billing/BillingAccess` | billing entitlement 判定。**`plan_code` は判定に一切使わない** (quota の解決キーでしかない)。`state()` が `Subscribed` (subscription が entitled) / `ActiveFreePlan` (`free_plan_code='personal'`) のいずれかなら許可、それ以外 (`NoSubscription` / `PendingCheckout` / `ExpiredCheckout`) は遮断する。かつては「plan_code null = 支払い不要 free tier は許可」だったが P4 のゲート反転で撤廃した (無料枠は明示申告へ)。**課金による利用可否の判定は本クラス経由のみ** (アプリは本クラスの差し替えで gate 方針を変更する)。適用は `require-active-subscription` middleware (業務 route group。billing / webhook は構造的 allowlist)。plan_code は Stripe Price を持つ有償プラン契約時のみ webhook が set する状態キー — 支払い不要プランを plan_code に載せる場合は本判定とセットで見直す (`RequireActiveSubscriptionMiddlewareTest` が固定) |
 | `Billing/SubscriptionService` | 契約 (Subscription) の状態管理。Stripe への I/O は Gateway 経由のみで、entitlement 導出 / webhook 受信時の状態同期 / **`attempt_token` 冪等の Checkout 開始** (`startCheckout`) に責務を絞る。§サブスク契約 Checkout の準拠実装 |
 | `Billing/PersonalPlanService` | Personal (無料) の適格性判定・有効化・退役。**free entitlement は `organizations.free_plan_code` で表現**し `subscriptions` は Stripe 実体のみという invariant を守る。farming 防止は DB partial unique (`organizations_personal_free_declarer_unique`) が hard invariant、owner 条件は eligibility の best-effort |
diff --git a/docs/auth-security-mechanisms.md b/docs/auth-security-mechanisms.md
index 5efae24..3a5bad0 100644
--- a/docs/auth-security-mechanisms.md
+++ b/docs/auth-security-mechanisms.md
@@ -4,7 +4,7 @@ # 認証・セキュリティ横断機構
 
 ## 概要
 
-テンプレート共通のセキュリティ横断機構を 4 つ束ねて記述する。いずれも特定のドメインに属さず、
+テンプレート共通のセキュリティ横断機構を 6 つ束ねて記述する。いずれも特定のドメインに属さず、
 リクエスト / デプロイの横断層で動く。
 
 1. **機微操作の再認証 (recent-auth / step-up)** — Critical Action の直前に「直近の再認証」を要求する。
@@ -13,6 +13,9 @@ ## 概要
    `no-store` baseline、production 起動時 / デプロイ前の fail-fast。
 4. **SSO email の信頼方針 (email trust policy)** — IdP が主張する email を検証済みとして
    扱ってよいかを provider ごとに宣言し、宣言のないものは fail-closed に倒す。
+5. **パスキー (WebAuthn)** — Fortify + laravel/passkeys のログイン / 再認証 / 管理経路と、
+   アプリ側が被せる不変条件 (所有者スコープ binder・応答契約・TOTP との関係)。
+6. **ログイン手段保持 guard** — ログイン手段が 0 になる操作を、投影後評価と行ロックで止める。
 
 MCP / CLI の OAuth 認可については [docs/mcp-oauth.md](mcp-oauth.md)、公開面の全体像は
 [docs/architecture.md](architecture.md) を参照。
@@ -37,16 +40,26 @@ ### 時間窓の判定契約
 ### 成立 (satisfier) と session state
 
 - session state の**唯一の writer** は `RecentAuthState`。satisfier 成立時に `recent_auth_at` /
-  `recent_auth_method` (`password` | `sso` | `login`) / `recent_auth_provider` を dedicated key に書く。
+  `recent_auth_method` (`password` | `sso` | `login` | `passkey`) / `recent_auth_provider` を dedicated key に書く。
   Fortify の `auth.password_confirmed_at` には**書かない** (意味汚染回避、横断標準は `recent_auth_at` が正本)。
 - 成立時は `session()->migrate(true)` で session ID を rotate する (OWASP: 権限上昇時の session fixation 対策)。
   `regenerate()` と違い **CSRF token は維持**するため、XHR モーダルや別タブの進行中フォームを壊さない。
-- satisfier は 2 経路: password 再入力 (`ConfirmRecentAuthController::confirmPassword`) と
-  再SSO (`SocialAuthController` の `intent=step-up`)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
+- satisfier は 3 経路: password 再入力 (`ConfirmRecentAuthController::confirmPassword`)、
+  再SSO (`SocialAuthController` の `intent=step-up`)、パスキー検証
+  (`StampRecentAuthOnPasskeyVerified`。`POST /passkeys/confirm`)。
+  **どの手段が使えるかはサーバの `/recent-auth/status` が単一の源** (`passwordSet` /
+  `availableProviders` / `passkeyAvailable`)。画面ごとに判定を持たせない
+  (持たせると passkey しか持たないユーザーが特定画面でだけ詰む)。
+  `canSatisfy` はこの 3 つの論理和であり、**パスキーは TOTP の有無に関係なく再認証に使える**
+  (`PasskeyLoginPolicy` が縛るのは login のみ)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
   再SSO へ誘導する。step-up 可能な provider は `config('template.social_providers.*.capability')` から解決 (未宣言は satisfier 不可)。
 - fresh login (`Login` event、web guard・非 recaller) は `StampRecentAuthOnLogin` が `method='login'` で自動 stamp する。
   ログイン直後の機微操作で「もう 1 回」の二重壁を消す。remember-me による自動復元 (`viaRemember()`) は fresh 扱いしない (fail-closed)。
 - 認証要素変更 (password / email / 2FA / social link·unlink) 後は `RecentAuthState::clear()` で鮮度を失効させる。
+  **パスキーの登録 / 削除**は `ClearRecentAuthOnPasskeyChange` が実際に `clear()` を呼ぶ (2026-08-04 裁定 A。§5 参照)。
+- satisfier の集合 (= `RecentAuthState::confirm()` の呼び出し元) は
+  `tests/Architecture/RecentAuthRouteTest.php` の inventory が deny-by-default で固定する。
+  新しい satisfier を足すには inventory への登録が必須 (= step-up の成立条件が増えることを PR で必ず判断させる)。
 
 ### XHR / 画面応答の差 (`RequireRecentAuth`、alias `recent-auth`)
 
@@ -217,6 +230,112 @@ ### fail-closed と機械強制
 
 ---
 
+## 5. パスキー (WebAuthn)
+
+**実装**: `app/Providers/PasskeyServiceProvider.php`, `app/Models/Passkey.php`, `app/Http/Responses/Passkey/`, `app/Http/Routing/SelfScopedPasskeyBinder.php`, `app/Services/Auth/PasskeyLoginPolicy.php`, `app/Listeners/Auth/{ClearRecentAuthOnPasskeyChange,StampRecentAuthOnPasskeyVerified}.php`, `resources/js/lib/passkeys.ts`, `resources/js/components/features/auth/PasskeySection.svelte`
+
+route / controller / action / migration は **Fortify + laravel/passkeys が提供する**
+(`config/fortify.php` の `Features::passkeys(['confirmPassword' => false])` が唯一の有効化点 =
+**実質的なキルスイッチ**)。アプリ側 (`PasskeyServiceProvider`) は「vendor にアプリ固有の
+不変条件を被せる」ことだけを担う。
+
+### アプリが被せる 4 つの不変条件
+
+| # | 内容 | 理由 |
+|---|------|------|
+| 1 | **binder 差し替え** (`SelfScopedPasskeyBinder`) | vendor binder はグローバル id 解決 → controller の `abort_unless(..., 403)` に到達し **他人の passkey の存在が漏れる**。所有者スコープで解決し「他人」と「不在」を等しく 404 にする (セキュリティ不変条件 2)。非数値 / bigint 範囲外も 404 に倒す (pgsql 22P02 / 22003 の 500 化を防ぐ) |
+| 2 | **Response contract 上書き** (`app/Http/Responses/Passkey/`) | vendor 既定は `new JsonResponse(...)` の直返しで禁止事項 4 に触れる。加えて confirm 経路が書く `auth.password_confirmed_at` をここで**除去**する (recent-auth の「Fortify の鍵には書かない」契約を守る) |
+| 3 | **route middleware の後付け** | `recent-auth` (登録 / 削除)、`ensure-login-method` (削除)、`no-store` (guest の login-options)。順序は **recent-auth → ensure-login-method** (逆順だと stale なリクエストでも行ロックを取る) |
+| 4 | **login 認可** (`PasskeyLoginPolicy`) | TOTP confirmed ユーザーの passkey login を拒否する |
+
+配線は `$app->booted()` 内で最終上書きする (auto-discovery された
+`Laravel\Passkeys\PasskeysServiceProvider` との boot 順序が `bootstrap/providers.php` では
+保証されないため)。構成は `tests/Architecture/{PasskeyPackageContractTest,PasskeyRouteProtectionTest}.php` が固定する。
+
+### TOTP との関係 (c2c 未裁定に対する fail-closed 既定)
+
+vendor の `PasskeyLoginController::store()` は `$guard->login()` を直接呼び、Fortify の
+two-factor challenge を通らない。したがって **TOTP confirmed のユーザーは passkey login を
+拒否する** (assurance の後退を作らない)。判定は `PasskeyLoginPolicy` **1 箇所**に集約してあり、
+(a) vendor の login ゲート (b) `LoginMethodInventory` の passkey 判定 (c) Settings 画面の
+`passkeyLoginAvailable` prop が同時に反転する。裁定が出たらこのクラスだけを書き換える。
+
+**passkey は 2FA 準拠判定に算入しない**。2FA 必須組織の未準拠ユーザーは passkey を持っていても
+`RequireTwoFactorForEnforcedOrganizations` のゲートに掛かる。
+
+### credential 集合の変化 = recent-auth 失効 (2026-08-04 裁定 A)
+
+パスキーは単独でログインできる強い資格であり、集合が変わったら直前に済ませた本人確認は失効させる
+(家系統一原則)。`PasskeyRegistered` / `PasskeyDeleted` を `ClearRecentAuthOnPasskeyChange` が購読する。
+UX の実害は「登録直後のタップ 1 回」に限られる。
+**「登録直後の passkey を satisfier から除外する」強化オプションは裁定で見送り済み**
+(再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時)。
+
+### transport 契約 (client ↔ server)
+
+| operation | options 取得 | 送信 | 成功応答 |
+|-----------|-------------|------|---------|
+| 登録 | `fetch GET /user/passkeys/options` | Inertia `router.post('/user/passkeys')` | `back()->with('success')` |
+| 削除 | — | Inertia `router.delete('/user/passkeys/{id}')` | `back()->with('success')` |
+| 再認証 (インラインモーダル) | `fetch GET /passkeys/confirm/options` | `fetch POST /passkeys/confirm` | `204` + `no-store` |
+| 再認証 (全画面 confirm) | 同上 | Inertia `router.post('/passkeys/confirm')` | `redirect()->intended()` |
+| ログイン | `fetch GET /passkeys/login/options` | `fetch POST /passkeys/login` | JSON `{redirect}` |
+
+`@/lib/passkeys` の import 元は `tests/js/architecture/passkeys-import-isolation.test.ts` が
+allowlist で固定する (transport 契約の食い違いは**無言失敗**として現れるため)。
+
+### 運用上の注意
+
+- 設定は `APP_URL` から導出される (relying party id = ホスト、allowed origins = `[APP_URL]`)。
+  同一オリジン PWA 前提のため専用 env は持たない。
+- **`APP_KEY` をローテートすると user handle (`hash_hmac` の鍵が `APP_KEY`) が変わり、
+  登録済みパスキーが全件無効になる**。鍵ローテートを行う場合は
+  `PASSKEYS_USER_HANDLE_SECRET` 相当の固定値を `config/passkeys.php` に持たせる設計変更が必要。
+- 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
+  `config('fortify.limiters.passkeys')` が未設定だと Fortify が throttle を外し **無制限**になる。
+
+---
+
+## 6. ログイン手段保持 guard (`EnsureLoginMethodRemains`)
+
+**実装**: `app/Http/Middleware/EnsureLoginMethodRemains.php`, `app/Services/Auth/LoginMethodInventory.php`, `app/DataTransferObjects/Auth/{LoginMethodRemoval,LoginMethodSet}.php`
+
+ログイン手段を全部消して自分で締め出す事故は復旧コストが高く、現場を止める。
+手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する。
+
+- **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。素朴に現在を数えると
+  削除対象自身が残存手段として数えられ、「唯一の passkey を削除できてしまう」= 意図と正反対になる。
+- **直列化規約 (TOCTOU 対策)**: middleware が (1) transaction を開き (2) 対象 User 行を
+  `lockForUpdate()` で取り (3) **ロック取得後に**投影を評価し (4) **同一 transaction 内で `$next()`**
+  を実行する。ロック取得順序は User → credential。
+- 手段の基準は「データが存在する」ではなく「**使える**」(`LoginMethodInventory`)。
+  config から外された provider や feature off の passkey は数えない (数えると guard が形骸化する)。
+- `canSatisfy` (recent-auth の step-up 成立可否) とは**別概念**。統合しないこと。
+
+### 応答契約 (transport で分岐)
+
+| リクエスト種別 | 応答 |
+|--------------|------|
+| Inertia | `302` (Inertia が DELETE では 303 に変換) + `errors.login_method` |
+| 純 XHR (`Accept: application/json`) | `422` + `{ code: 'login_method_required', message, settingsUrl }` (`no-store`) |
+| 通常フォーム | `back()->withErrors('login_method')` |
+
+**Inertia に 422 JSON を返さない** (protocol 違反で router が応答を解釈できず無言失敗する)。
+
+### 適用範囲の機械強制
+
+`tests/Architecture/LoginMethodRemovalRouteTest.php` が **両方向**で固定する。
+
+1. 認証系 URI 空間の破壊的 route は「guard 必須」か「理由付き免除」のどちらかに**必ず分類**される。
+2. **allowlist 外の route に付与してはならない** — `$next()` を transaction 内で実行するため、
+   streamed response / 外部 I/O / `afterCommit` でない queue dispatch を含む route に付けると
+   副作用範囲が急拡大する。
+
+将来 password 削除 / SSO 連携解除 route を追加するときも**必ずこの middleware を通す**
+(単一の直列化点。別経路を作ると TOCTOU が戻る)。
+
+---
+
 ## 関連ファイル
 
 | ファイル | 役割 |
@@ -228,6 +347,12 @@ ## 関連ファイル
 | `app/Listeners/Auth/StampRecentAuthOnLogin.php` | fresh login を recent-auth 成立として stamp (recaller 除外) |
 | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` | 認証済み応答の `no-store` baseline (bfcache 由来の PII 再表示防止) |
 | `app/Http/Controllers/Auth/SessionStatusController.php` | セッション有効性の軽量プローブ (`session.status`)。auth グループの外・guest でも 200 |
+| `app/Providers/PasskeyServiceProvider.php` | laravel/passkeys の app アダプタ (binder / Response contract / middleware 後付け / login 認可) |
+| `app/Http/Routing/SelfScopedPasskeyBinder.php` | `{passkey}` を所有者スコープで解決 (他人 / 不在 / 不正型をすべて 404) |
+| `app/Services/Auth/PasskeyLoginPolicy.php` | passkey **ログイン**可否の単一判定点 (feature flag + TOTP) |
+| `app/Services/Auth/LoginMethodInventory.php` | 投影後のログイン手段集合 (`remainingAfter`) |
+| `app/Http/Middleware/EnsureLoginMethodRemains.php` | `ensure-login-method` alias。手段が 0 になる操作を投影後評価 + 行ロックで止める |
+| `app/Http/Middleware/NoStoreResponse.php` | `no-store` alias。guest route (passkey の login-options) の challenge をキャッシュさせない |
 | `resources/js/lib/bfcache-guard.ts` | bfcache 復元時のクライアント側秘匿・再検証 (Safari 対策。正本は `docs/supported-browsers.md`) |
 | `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` | 2FA 未準拠ユーザーの全画面ゲート (allowlist 外を 302 / 409) |
 | `app/Http/Middleware/BlockTwoFactorDisableForEnforcedOrganizations.php` | 準拠ユーザーの self-disable 到達を弾く (422 / back) |
diff --git a/docs/factories.md b/docs/factories.md
index 38968bb..23171ba 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -16,6 +16,7 @@ ## Factory 一覧 (テンプレート同梱)
 |---------|-------|-----------|
 | `UserFactory` | User | `unverified()`, `ssoOnly()` (password null + 認証済み), `withTwoFactor()` (本物の TOTP secret + recovery codes + confirmed) |
 | `AdminUserFactory` | AdminUser | `withMfa()` |
+| `PasskeyFactory` | Passkey | — (`for($user)` で所有者を指定。WebAuthn ceremony を伴わない経路 = 削除 / 一覧 / 手段カウント / 認可 用の最小 credential。実 ceremony の検証は vendor の WebAuthn helper で credential を生成すること) |
 | `OrganizationFactory` | Organization | `personal()`, `freePersonal($declarer)`, `grandfathered()`, `signupGranted()`, `withBillingContact(?$email, ?$name)` (請求先連絡先。CipherSweet 暗号化列) |
 | `CustomTeamFactory` | CustomTeam | — |
 | `ProjectFactory` | Project | `forOrganization($org)` |
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index 65b7322..fabcd1c 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -95,6 +95,38 @@ ### bfcache 復元が自動回帰でカバーできていない理由 (実測)
 **skip は合格ではない**。現時点で復元シナリオを担保しているのは
 vitest のユニットテスト (分岐ロジック) と実機受入確認 (未実施) だけである。
 
+### パスキー (WebAuthn) の保証範囲
+
+**自動テストで保証しているのは「ceremony に入る前の分岐」だけ**である。
+
+| 対象 | 保証手段 |
+|------|---------|
+| feature detection (`isPasskeySupported` / `canCreatePasskey`) | `tests/js/lib/passkeys.test.ts` (ユニット) |
+| キャンセル / タイムアウトを騒がず畳むこと | 同上 |
+| fetch のヘッダ契約 (`Accept: application/json` / CSRF) | 同上 |
+| route の到達制御・認可・throttle・no-store | `tests/Feature/Auth/PasskeyRouteAccessTest.php` |
+| **実 ceremony (認証器との往復)** | **自動化しない** — 下記 |
+
+**実 ceremony は自動化しない**。jsdom は WebAuthn を実装せず、Playwright の
+仮想認証器 (CDP `WebAuthn.addVirtualAuthenticator`) は Chromium 限定で、
+本アプリの主戦場である **iOS Safari では原理的に再現できない**。
+Chromium だけ緑にしても「iOS で使える」ことの証明にはならないため、
+**片肺の自動化で安心を買わない**判断をした。
+
+**非対応時のフォールバック契約** (現場端末は非対応 / 生体未設定が常態):
+
+- 非対応ブラウザ: ログイン画面にパスキーボタンを**出さない**。設定画面は理由を出す
+  (`passkey-unsupported`)。パスワード / ソーシャルログインの導線は常に残る。
+- 対応だがプラットフォーム認証器が使えない: 設定画面に理由を出す
+  (`passkey-not-creatable`)。**ボタンは disabled にしない** (押下時にエラーを出す。
+  AGENTS.md 禁止事項 8)。
+- ceremony 失敗 / キャンセル: ログイン画面はパスワード欄と SSO ボタンを残したまま
+  同画面にエラーを出す (回復導線を消さない)。
+
+**実機受入確認の対象に含める** (下記「再確認条件」と同じ運用)。確認シナリオ:
+iOS Safari で (1) 登録 → (2) ログアウト → (3) パスキーでログイン → (4) 設定画面で再認証 →
+(5) 削除、の 5 手。
+
 ### 実機受入確認の再確認条件
 
 一度きりの確認では陳腐化する。**以下のいずれかに挙動変更が入ったら再実施する**:
@@ -103,6 +135,7 @@ ### 実機受入確認の再確認条件
 - `resources/css/app.css` の秘匿オーバーレイのスタイル (`#bfcache-guard-overlay` 周辺)
 - プローブ endpoint (`routes/web.php` の `session.status` /
   `App\Http\Controllers\Auth\SessionStatusController` / `SessionStatusResource`)
+- `resources/js/lib/passkeys.ts` (WebAuthn ラッパ本体。上記「パスキーの保証範囲」)
 
 **docblock / コメントのみの変更はトリガに当たらない** (挙動が変わっていないため)。
 不要な実機再確認を誘発しないよう、トリガは「挙動変更」に限る。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 296e52c..265ae6f 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -481,3 +481,61 @@ ### 関連
   `resources/js/lib/document-title.ts` / `config/seo.php`
 - 設計: `devnotes/20260805-0101-architecture-gate-followup/`
 - c2c 台帳: `gate-document-title-coverage` / `page-title-frontend-contract`
+
+---
+
+## D13 ✅ SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| SSO 登録時の `users.password` | `Str::password(32)` をハッシュ化して保存 | **保存しない (null のまま)** |
+| `User::hasPassword()` の意味 | SSO-only ユーザーでも常に true (docblock 自身が「テンプレート標準では常に true」と明記) | 「password 経路が実際に使えるか」を返す |
+| 既存データの扱い | — | **遡及是正しない (前方修正のみ)** |
+
+### なぜ正当な差分か(logic-driven)
+
+本アプリは password 以外のログイン手段 (SSO / パスキー) を第一級に扱い、
+「**ログイン手段が 0 になる操作を止める**」ことを不変条件にしている
+(`EnsureLoginMethodRemains` / `LoginMethodInventory`。docs/auth-security-mechanisms.md §6)。
+この不変条件は `hasPassword()` が真実を返すことに依存する。
+
+phantom password (ランダム値) が入っていると:
+
+1. `LoginMethodInventory` が SSO-only ユーザーにも `password` を数え、
+   **唯一のパスキーを削除できてしまう** (guard が形骸化する)。
+2. recent-auth の `passwordSet` が true になり、SSO-only ユーザーの再認証モーダルが
+   **入力しても必ず失敗するパスワード欄**を出す (詰み画面)。
+
+`users.password` は migration が nullable で作られており
+(`0001_01_01_000000_create_users_table.php`)、`UserFactory::ssoOnly()` も
+`password => null` を前提にしている。つまりスキーマとテスト補助は既に「null を許す」側で、
+`Str::password(32)` だけが取り残されていた。
+
+### 射程 (既知の制約として残すもの)
+
+**前方修正のみ**。本変更**以前**に SSO 登録されたユーザーの phantom password はそのまま残り、
+そのユーザーに限り上記 1 / 2 の誤差が続く。遡及移行 (既存 SSO ユーザーの password を null 化) は
+**「password を先に登録してから SSO を連携したユーザーの実パスワードを消す」**危険があり、
+`password_changed` の監査証跡が無い時代のユーザーを機械的に判別できないため行わない。
+
+判別材料を今後蓄積するため、`UpdateUserPassword` から
+`SecurityEventType::PasswordChanged` を記録するようにした
+(enum に存在しながら記録経路が無かった。`/reset-password` 経路は
+`Illuminate\Auth\Events\PasswordReset` 経由で既に記録済み)。
+
+### 揃えている不変条件(これは保証し続ける)
+
+> 「`User::hasPassword()` は password 経路の可否を **fail-closed** で判定する」
+
+- `tests/Feature/Auth/SocialAuthTest.php`: SSO register 後の `password` が null /
+  `hasPassword()` が false / `email_verified_at` は従来どおり非 null (T105 との相互作用の回帰)
+- `tests/Feature/Auth/RecentAuthTest.php`: SSO 登録直後の `/recent-auth/status` が
+  `passwordSet: false` / `canSatisfy: true` (再SSO が satisfier)
+- `tests/Feature/Auth/LoginMethodInventoryTest.php`: `ssoOnly()` ユーザーの手段集合に
+  `password` が含まれない
+
+### 関連
+
+- 実装: `app/Services/Auth/SocialAccountService.php` / `app/Actions/Fortify/UpdateUserPassword.php` /
+  `app/Services/Auth/LoginMethodInventory.php`
+- 設計: `devnotes/20260805-1244-auth-method-and-passkey/` (施策 2)

```

---

## テスト結果 (Round 2)

- `composer test`: **2809 tests / 2807 passed / 0 failed / 2 skipped** (11275 assertions)
  — Round 1 時点の 2799 から +10
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test` (vitest): **115 files / 1045 tests passed** — Round 1 時点の 1035 から +10

## 確認してほしい点

1. `canSatisfy` に `passkeyAvailable` を算入した結果、**passkey しか持たないユーザーが
   step-up で詰まなくなった**ことが、インラインモーダルと全画面 confirm の**両方**で
   成立しているか (transport が違う: 前者は fetch+204、後者は Inertia post + intended)
2. `passkeyAvailable` を `Features::enabled(Features::passkeys())` で gate したことで、
   キルスイッチ (config 1 行) が **status 契約にも連動**しているか
3. 登録 payload の shape を「サーバ側 (vendor の rules 契約) と client 側 (router.post の引数)」の
   **両側から**固定したことで、食い違いが再発したときに必ず落ちるか
4. 残した Warning (登録の実 HTTP 経路 / allowsLogin deny の実 HTTP 経路) の受容理由が妥当か
