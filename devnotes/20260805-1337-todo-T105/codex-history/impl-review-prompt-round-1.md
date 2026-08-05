【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 (AGENTS.md)】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel + Svelte アプリのコードレビュアーである。以下の実装差分を詳細設計書と突き合わせてレビューせよ。

## レビュー観点

1. **設計との一致性**: 詳細設計書「施策 1」の記述どおりに実装されているか。逸脱があれば妥当か
2. **正確性**: ロジックの誤り・境界条件・fail-closed が本当に閉じているか
3. **PHPStan level 10 適合性**: 型の緩め・暗黙の mixed 依存が無いか
4. **DTO / JsonResource パターン**: 該当箇所があれば準拠しているか
5. **テスト網羅性**: 施策の不変条件が Architecture / Feature テストで機械強制されているか。テストが「実装をなぞるだけ」で回帰を検知できない形になっていないか
6. **セキュリティ**: nOAuth (IdP が主張する未検証 email による他人アカウント奪取) に対する防御として本当に機能するか。既存挙動 (google) を壊していないか
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 本差分にフロントエンド変更は無いため該当しない

## 重要な前提 (スコープ)

本 TODO (T105 = T-α) は詳細設計書の **施策 1 のみ** を実装する。
施策 2〜6 (ログイン手段 inventory / EnsureLoginMethodRemains / passkey / recent-auth 配線 / フロント) は
別 TODO (T106) の担当であり、本差分のスコープ外である。
特に `SocialAccountService` の `Str::password(32)` (phantom password) 問題は
**施策 2 の担当なので本差分では意図的に触れていない**。これをスコープ外として扱うこと。

また Microsoft provider の追加、aigenba の id_token 検証形の導入は
プロダクト判断 / 家系横断裁定 (c2c) 未裁定のため本差分では踏み込んでいない。

## 出力形式

- ファイルごとに判定を述べる
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

---

## 詳細設計書 (施策 1 の該当節)

# 施策 1: SSO email trust policy seam（T-α）

## 変更箇所

- `config/template.php` L38-47（`social_providers`）
- `app/Services/Auth/SocialAccountService.php` L62（`email_verified_at => now()`）
- 新規: `app/Services/Auth/EmailTrust/{EmailTrustPolicy,ConfirmedEmailTrustPolicy,UnconfirmedEmailTrustPolicy,EmailTrustPolicyResolver}.php`

## 波及変更

- TypeScript 型定義: **なし**（`socialProviders` の Inertia prop は `array_keys()` のままで形が変わらない）
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Auth/SocialAuthTest.php`（回帰）、新規 `tests/Architecture/SocialProviderTrustPolicyTest.php`
- ドキュメント: `docs/architecture.md`（SSO 節に Confirmed の判定基準）

## 現行コード

```php
// app/Services/Auth/SocialAccountService.php L53-64
$user = (new User([
    'name' => $socialiteUser->getName() ?? $email,
    'email' => $email,
    'password' => Str::password(32),
]))->forceFill([
    'terms_accepted_at' => now(),
    'consent_version' => config()->string('legal.consent_version'),
    // IdP 側で検証済みの email として扱う
    'email_verified_at' => now(),
]);
```

```php
// config/template.php
'social_providers' => [
    'google' => ['label' => 'Google', 'capability' => 'fresh_auth_prompt_only'],
],
```

## 変更後コード

```php
// app/Services/Auth/EmailTrust/EmailTrustPolicy.php
<?php

declare(strict_types=1);

namespace App\Services\Auth\EmailTrust;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * SSO provider が主張する email を「IdP 側で検証済み」として信頼してよいかの方針。
 *
 * **Confirmed の判定基準 (契約)**:
 *   provider が当該 email の **所有を検証済み** であり、かつ
 *   **テナント管理者が任意の email を claim できない** こと。
 *   この 2 条件を満たす provider のみ、IdP の主張だけで email_verified_at を立ててよい。
 *
 * 差し替え可能にしてある理由 = nOAuth 対策のキルスイッチ。
 * 例: Microsoft Entra ID のテナント管理者は未検証の email claim を任意に設定でき、
 * 他社ドメインの email を主張できる。そのため Microsoft は Unconfirmed 側に置く。
 *
 * 宣言は config('template.social_providers.{provider}.email_trust')。
 * 未宣言・解釈不能は Unconfirmed に倒す (fail-closed)。
 */
interface EmailTrustPolicy
{
    /** IdP の主張する email を検証済みとして扱ってよいか */
    public function trustsEmail(SocialiteUser $socialiteUser): bool;
}
```

```php
// app/Services/Auth/EmailTrust/ConfirmedEmailTrustPolicy.php
final class ConfirmedEmailTrustPolicy implements EmailTrustPolicy
{
    public function trustsEmail(SocialiteUser $socialiteUser): bool
    {
        return true;
    }
}

// app/Services/Auth/EmailTrust/UnconfirmedEmailTrustPolicy.php
final class UnconfirmedEmailTrustPolicy implements EmailTrustPolicy
{
    public function trustsEmail(SocialiteUser $socialiteUser): bool
    {
        return false;
    }
}
```

```php
// app/Enums/EmailTrustLevel.php
enum EmailTrustLevel: string
{
    case Confirmed = 'confirmed';
    case Unconfirmed = 'unconfirmed';

    /** 未宣言・解釈不能は Unconfirmed (fail-closed) */
    public static function fromRaw(mixed $raw): self
    {
        return is_string($raw) ? (self::tryFrom($raw) ?? self::Unconfirmed) : self::Unconfirmed;
    }
}
```

```php
// app/Services/Auth/EmailTrust/EmailTrustPolicyResolver.php
final class EmailTrustPolicyResolver
{
    public function for(string $provider): EmailTrustPolicy
    {
        $level = EmailTrustLevel::fromRaw(
            config('template.social_providers.'.$provider.'.email_trust'),
        );

        return match ($level) {
            EmailTrustLevel::Confirmed => new ConfirmedEmailTrustPolicy,
            EmailTrustLevel::Unconfirmed => new UnconfirmedEmailTrustPolicy,
        };
    }
}
```

```php
// app/Services/Auth/SocialAccountService.php (register の該当部)
public function __construct(
    private readonly SecurityEventRecorder $recorder,
    private readonly OrganizationProvisioningService $provisioning,
    private readonly EmailTrustPolicyResolver $emailTrust,   // ← 追加
) {}

// ...
// IdP が email 所有を検証している provider のみ検証済みとして扱う (nOAuth 対策の継ぎ目)。
// google は confirmed 宣言のため従来どおり email_verified_at が立つ (挙動不変)。
$verifiedAt = $this->emailTrust->for($provider)->trustsEmail($socialiteUser) ? now() : null;

$user = (new User([
    'name' => $socialiteUser->getName() ?? $email,
    'email' => $email,
    // 施策 2 で password は null になる (本施策では触らない)
    'password' => Str::password(32),
]))->forceFill([
    'terms_accepted_at' => now(),
    'consent_version' => config()->string('legal.consent_version'),
    'email_verified_at' => $verifiedAt,
]);
```

```php
// config/template.php
'social_providers' => [
    'google' => [
        'label' => 'Google',
        'capability' => 'fresh_auth_prompt_only',
        // email_trust: IdP の主張する email を検証済みとして扱ってよいか
        // (App\Enums\EmailTrustLevel の value)。未宣言は unconfirmed 扱い (fail-closed)。
        // Google は Gmail / Workspace とも email 所有を検証しており、管理者は
        // 所有権を証明したドメイン外を claim できないため confirmed。
        // Microsoft (Entra ID) は管理者が未検証 email claim を設定できる (nOAuth) ため
        // 追加する場合は必ず unconfirmed から始めること。
        'email_trust' => 'confirmed',
    ],
],
```

## PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`trustsEmail(): bool` / `for(): EmailTrustPolicy`）
- [x] null 安全（`config()` の戻りは `mixed` → `EmailTrustLevel::fromRaw()` が `is_string` で narrowing）
- [x] DTO を返している（enum + interface。配列返却なし）
- [x] Generics の型パラメータ: 該当なし

## テスト計画

- [ ] 新規 `tests/Architecture/SocialProviderTrustPolicyTest.php`
  - 全 provider が `email_trust` 宣言を持つ（deny-by-default。宣言漏れで fail）
  - 全 provider が `capability` 宣言を持つ（既存 fail-closed の明示化）
  - **google の `email_trust` が `confirmed`**（現行挙動の pin。緩めるならテストごと変える）
  - `EmailTrustLevel::fromRaw(null)` / `fromRaw('nonsense')` / `fromRaw(['x'])` が `Unconfirmed`
- [ ] 既存 `tests/Feature/Auth/SocialAuthTest.php` に回帰を追加
  - 「SSO register で User + SocialAccount が作成されログインされる」の `email_verified_at`
    非 null アサートは**そのまま**（挙動不変の証明）
  - 追加: provider の `email_trust` を `unconfirmed` に差し替えた場合、
    `email_verified_at` が **null** になり `/email/verify` ゲートに落ちる
    （`config()->set()` で差し替え。Socialite は既存 `fakeSocialiteCallback()` を再利用）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認（グローバル `RefreshDatabase` のみ）

## リスク

- `email_trust` 宣言漏れの provider を後から足すと**その provider だけ検証済みにならない**。
  これは fail-closed 方向の誤差であり、`SocialProviderTrustPolicyTest` が CI で先に落とす
- `SocialAccountService` のコンストラクタ引数が増えるため、DI 解決に依存する箇所の確認が必要
  （現状は `SocialAuthController::callback` のメソッドインジェクションのみ = コンテナ解決で自動追随）

---

# 施策 2: ログイン手段 inventory と phantom password 是正（T-β / S1）

## 実装差分 (git diff)

```diff
diff --git a/app/Enums/EmailTrustLevel.php b/app/Enums/EmailTrustLevel.php
new file mode 100644
index 0000000..66d42da
--- /dev/null
+++ b/app/Enums/EmailTrustLevel.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums;
+
+/**
+ * SSO provider が主張する email を「IdP 側で検証済み」として扱ってよいかの信頼段階。
+ *
+ * - Confirmed: provider が当該 email の **所有を検証済み** であり、かつ
+ *   **テナント管理者が任意の email を claim できない**。IdP の主張だけで
+ *   `email_verified_at` を立ててよい。
+ * - Unconfirmed: 上記 2 条件のいずれかを満たさない (または不明)。アプリ側で
+ *   メール到達確認 (`/email/verify`) を経てから検証済みにする。
+ *
+ * 宣言は `config('template.social_providers.{provider}.email_trust')`。
+ * 未宣言・解釈不能は Unconfirmed に倒す (fail-closed)。
+ */
+enum EmailTrustLevel: string
+{
+    case Confirmed = 'confirmed';
+    case Unconfirmed = 'unconfirmed';
+
+    /**
+     * config 由来の生値を信頼段階へ解決する。
+     * 未宣言 (null) ・非文字列・未知文字列はすべて Unconfirmed (fail-closed)。
+     */
+    public static function fromRaw(mixed $raw): self
+    {
+        if (! is_string($raw)) {
+            return self::Unconfirmed;
+        }
+
+        return self::tryFrom($raw) ?? self::Unconfirmed;
+    }
+}
diff --git a/app/Services/Auth/EmailTrust/ConfirmedEmailTrustPolicy.php b/app/Services/Auth/EmailTrust/ConfirmedEmailTrustPolicy.php
new file mode 100644
index 0000000..438c3f1
--- /dev/null
+++ b/app/Services/Auth/EmailTrust/ConfirmedEmailTrustPolicy.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth\EmailTrust;
+
+use Laravel\Socialite\Contracts\User as SocialiteUser;
+
+/**
+ * email 所有を IdP が検証済みで、かつテナント管理者が任意の email を claim できない
+ * provider 向けの方針。IdP の主張をそのまま検証済みとして扱う。
+ */
+final class ConfirmedEmailTrustPolicy implements EmailTrustPolicy
+{
+    public function trustsEmail(SocialiteUser $socialiteUser): bool
+    {
+        return true;
+    }
+}
diff --git a/app/Services/Auth/EmailTrust/EmailTrustPolicy.php b/app/Services/Auth/EmailTrust/EmailTrustPolicy.php
new file mode 100644
index 0000000..0baa638
--- /dev/null
+++ b/app/Services/Auth/EmailTrust/EmailTrustPolicy.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth\EmailTrust;
+
+use Laravel\Socialite\Contracts\User as SocialiteUser;
+
+/**
+ * SSO provider が主張する email を「IdP 側で検証済み」として信頼してよいかの方針。
+ *
+ * **Confirmed の判定基準 (契約)**:
+ *   provider が当該 email の **所有を検証済み** であり、かつ
+ *   **テナント管理者が任意の email を claim できない** こと。
+ *   この 2 条件を満たす provider のみ、IdP の主張だけで email_verified_at を立ててよい。
+ *
+ * 差し替え可能にしてある理由 = nOAuth 対策のキルスイッチ。
+ * 例: Microsoft Entra ID のテナント管理者は未検証の email claim を任意に設定でき、
+ * 他社ドメインの email を主張できる。そのため Microsoft は Unconfirmed 側に置く。
+ *
+ * 宣言は config('template.social_providers.{provider}.email_trust')。
+ * 未宣言・解釈不能は Unconfirmed に倒す (fail-closed)。
+ */
+interface EmailTrustPolicy
+{
+    /** IdP の主張する email を検証済みとして扱ってよいか */
+    public function trustsEmail(SocialiteUser $socialiteUser): bool;
+}
diff --git a/app/Services/Auth/EmailTrust/EmailTrustPolicyResolver.php b/app/Services/Auth/EmailTrust/EmailTrustPolicyResolver.php
new file mode 100644
index 0000000..fe9f369
--- /dev/null
+++ b/app/Services/Auth/EmailTrust/EmailTrustPolicyResolver.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth\EmailTrust;
+
+use App\Enums\EmailTrustLevel;
+
+/**
+ * provider ごとの EmailTrustPolicy を config 宣言から解決する。
+ * 宣言は config('template.social_providers.{provider}.email_trust')。
+ * 未宣言・解釈不能は Unconfirmed (fail-closed)。宣言漏れは
+ * tests/Architecture/SocialProviderTrustPolicyTest.php が CI で先に落とす。
+ */
+final class EmailTrustPolicyResolver
+{
+    public function for(string $provider): EmailTrustPolicy
+    {
+        $level = EmailTrustLevel::fromRaw(
+            config('template.social_providers.'.$provider.'.email_trust'),
+        );
+
+        return match ($level) {
+            EmailTrustLevel::Confirmed => new ConfirmedEmailTrustPolicy,
+            EmailTrustLevel::Unconfirmed => new UnconfirmedEmailTrustPolicy,
+        };
+    }
+}
diff --git a/app/Services/Auth/EmailTrust/UnconfirmedEmailTrustPolicy.php b/app/Services/Auth/EmailTrust/UnconfirmedEmailTrustPolicy.php
new file mode 100644
index 0000000..f3971d4
--- /dev/null
+++ b/app/Services/Auth/EmailTrust/UnconfirmedEmailTrustPolicy.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth\EmailTrust;
+
+use Laravel\Socialite\Contracts\User as SocialiteUser;
+
+/**
+ * email 所有の検証を IdP に依存できない provider 向けの方針 (fail-closed 既定)。
+ * アプリ側のメール到達確認 (`/email/verify`) を経てから検証済みにする。
+ */
+final class UnconfirmedEmailTrustPolicy implements EmailTrustPolicy
+{
+    public function trustsEmail(SocialiteUser $socialiteUser): bool
+    {
+        return false;
+    }
+}
diff --git a/app/Services/Auth/SocialAccountService.php b/app/Services/Auth/SocialAccountService.php
index d9a6754..ceba26a 100644
--- a/app/Services/Auth/SocialAccountService.php
+++ b/app/Services/Auth/SocialAccountService.php
@@ -7,6 +7,7 @@
 use App\Enums\SecurityEventType;
 use App\Models\SocialAccount;
 use App\Models\User;
+use App\Services\Auth\EmailTrust\EmailTrustPolicyResolver;
 use App\Services\Organization\OrganizationProvisioningService;
 use App\Services\Security\SecurityEventRecorder;
 use Illuminate\Support\Facades\DB;
@@ -27,6 +28,7 @@ class SocialAccountService
     public function __construct(
         private readonly SecurityEventRecorder $recorder,
         private readonly OrganizationProvisioningService $provisioning,
+        private readonly EmailTrustPolicyResolver $emailTrust,
     ) {}
 
     public function findLinkedUser(string $provider, SocialiteUser $socialiteUser): ?User
@@ -50,6 +52,14 @@ public function register(string $provider, SocialiteUser $socialiteUser): User
                 throw new \RuntimeException('SSO プロバイダから email が取得できませんでした');
             }
 
+            // IdP が email 所有を検証している provider のみ検証済みとして扱う
+            // (nOAuth 対策の継ぎ目)。宣言は config('template.social_providers.*.email_trust') で、
+            // 未宣言は Unconfirmed に倒れる (fail-closed)。google は confirmed 宣言のため
+            // 従来どおり email_verified_at が立つ (挙動不変)。
+            $verifiedAt = $this->emailTrust->for($provider)->trustsEmail($socialiteUser)
+                ? now()
+                : null;
+
             $user = (new User([
                 'name' => $socialiteUser->getName() ?? $email,
                 'email' => $email,
@@ -58,8 +68,7 @@ public function register(string $provider, SocialiteUser $socialiteUser): User
             ]))->forceFill([
                 'terms_accepted_at' => now(),
                 'consent_version' => config()->string('legal.consent_version'),
-                // IdP 側で検証済みの email として扱う
-                'email_verified_at' => now(),
+                'email_verified_at' => $verifiedAt,
             ]);
             $user->save();
 
diff --git a/config/template.php b/config/template.php
index 2fc5132..8b61828 100644
--- a/config/template.php
+++ b/config/template.php
@@ -37,11 +37,22 @@
     | id_token の auth_time を検証しないため fresh_auth_prompt_only が上限。
     | auth_time を返さない OAuth-only provider (GitHub 等) は identity_only にして
     | satisfier から除外すること。未宣言は identity_only 扱い (fail-closed)。
+    |
+    | email_trust: IdP の主張する email を検証済みとして扱ってよいか
+    | (App\Enums\EmailTrustLevel の value)。未宣言は unconfirmed 扱い (fail-closed)。
+    | confirmed を名乗ってよいのは「provider が email 所有を検証済み」かつ
+    | 「テナント管理者が任意の email を claim できない」の 2 条件を満たす provider のみ。
+    | 宣言漏れは tests/Architecture/SocialProviderTrustPolicyTest.php が検出する。
     */
     'social_providers' => [
         'google' => [
             'label' => 'Google',
             'capability' => 'fresh_auth_prompt_only',
+            // Google は Gmail / Workspace とも email 所有を検証しており、管理者は
+            // 所有権を証明したドメイン外を claim できないため confirmed。
+            // Microsoft (Entra ID) は管理者が未検証 email claim を設定できる (nOAuth) ため
+            // 追加する場合は必ず unconfirmed から始めること。
+            'email_trust' => 'confirmed',
         ],
     ],
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 2fccc20..c066bad 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -124,7 +124,7 @@ ## 主要 Service (テンプレート同梱)
 | `Render/VideoComposer` (interface) + `Render/FfmpegVideoComposer` | AI-CUE: 動画合成の抽象 + ffmpeg v1 実装 (Process facade 経由・配列引数。filtergraph にはサーバ生成一時ファイル名と数値のみ = 字幕本文を直接埋めない) |
 | `Render/AssSubtitleWriter` | AI-CUE: ASS 字幕生成の安全境界 (唯一の字幕テキスト出力点。リテラル \N/override tag/制御文字/zero-width の正規化 + mb 安全な長さ上限) |
 | `Render/RenderObjectStorage` | AI-CUE: レンダ出力 S3 操作の集約点 (download/upload/署名 URL/削除/prefix。DL 用 Content-Disposition は RFC 5987 + ASCII fallback + ヘッダ注入不能) |
-| `Auth/SocialAccountService` | ソーシャルログイン連携 |
+| `Auth/SocialAccountService` | ソーシャルログイン連携。SSO 登録時の `email_verified_at` は `Auth/EmailTrust/EmailTrustPolicyResolver` (provider ごとの `email_trust` 宣言) 経由でのみ立てる (nOAuth 対策。契約は [docs/auth-security-mechanisms.md](auth-security-mechanisms.md) §4) |
 | `Billing/BillingAccess` | billing entitlement 判定。**`plan_code` は判定に一切使わない** (quota の解決キーでしかない)。`state()` が `Subscribed` (subscription が entitled) / `ActiveFreePlan` (`free_plan_code='personal'`) のいずれかなら許可、それ以外 (`NoSubscription` / `PendingCheckout` / `ExpiredCheckout`) は遮断する。かつては「plan_code null = 支払い不要 free tier は許可」だったが P4 のゲート反転で撤廃した (無料枠は明示申告へ)。**課金による利用可否の判定は本クラス経由のみ** (アプリは本クラスの差し替えで gate 方針を変更する)。適用は `require-active-subscription` middleware (業務 route group。billing / webhook は構造的 allowlist)。plan_code は Stripe Price を持つ有償プラン契約時のみ webhook が set する状態キー — 支払い不要プランを plan_code に載せる場合は本判定とセットで見直す (`RequireActiveSubscriptionMiddlewareTest` が固定) |
 | `Billing/SubscriptionService` | 契約 (Subscription) の状態管理。Stripe への I/O は Gateway 経由のみで、entitlement 導出 / webhook 受信時の状態同期 / **`attempt_token` 冪等の Checkout 開始** (`startCheckout`) に責務を絞る。§サブスク契約 Checkout の準拠実装 |
 | `Billing/PersonalPlanService` | Personal (無料) の適格性判定・有効化・退役。**free entitlement は `organizations.free_plan_code` で表現**し `subscriptions` は Stripe 実体のみという invariant を守る。farming 防止は DB partial unique (`organizations_personal_free_declarer_unique`) が hard invariant、owner 条件は eligibility の best-effort |
diff --git a/docs/auth-security-mechanisms.md b/docs/auth-security-mechanisms.md
index 6776133..5efae24 100644
--- a/docs/auth-security-mechanisms.md
+++ b/docs/auth-security-mechanisms.md
@@ -4,13 +4,15 @@ # 認証・セキュリティ横断機構
 
 ## 概要
 
-テンプレート共通のセキュリティ横断機構を 3 つ束ねて記述する。いずれも特定のドメインに属さず、
+テンプレート共通のセキュリティ横断機構を 4 つ束ねて記述する。いずれも特定のドメインに属さず、
 リクエスト / デプロイの横断層で動く。
 
 1. **機微操作の再認証 (recent-auth / step-up)** — Critical Action の直前に「直近の再認証」を要求する。
 2. **組織 2FA 強制 (enforced two-factor)** — 組織が 2FA を必須化した場合の未準拠ユーザーゲート + self-disable 禁止。
 3. **セキュリティヘッダ / 本番ハードニング層** — baseline セキュリティヘッダ、認証済み応答の
    `no-store` baseline、production 起動時 / デプロイ前の fail-fast。
+4. **SSO email の信頼方針 (email trust policy)** — IdP が主張する email を検証済みとして
+   扱ってよいかを provider ごとに宣言し、宣言のないものは fail-closed に倒す。
 
 MCP / CLI の OAuth 認可については [docs/mcp-oauth.md](mcp-oauth.md)、公開面の全体像は
 [docs/architecture.md](architecture.md) を参照。
@@ -176,6 +178,45 @@ ### production 起動時 / デプロイ前の fail-fast
 
 ---
 
+## 4. SSO email の信頼方針 (email trust policy)
+
+**実装**: `app/Services/Auth/EmailTrust/`, `app/Enums/EmailTrustLevel.php`, `config/template.php` (`social_providers.*.email_trust`)
+
+SSO 登録 (`Auth/SocialAccountService::register`) は、IdP が主張する email を無条件に
+検証済み (`email_verified_at`) として扱わない。provider ごとの信頼段階の宣言を
+`EmailTrustPolicyResolver` が `EmailTrustPolicy` へ解決し、`trustsEmail()` が true の
+場合にのみ `email_verified_at` を立てる。
+
+### Confirmed の判定基準 (契約)
+
+`email_trust = confirmed` を名乗ってよいのは、次の **2 条件をともに満たす** provider だけ。
+
+1. provider が当該 email の **所有を検証済み** である。
+2. **テナント管理者が任意の email を claim できない** (所有権を証明していないドメインの
+   email を主張できない)。
+
+満たさない / 不明な場合は `unconfirmed` に置き、アプリ側のメール到達確認
+(`/email/verify`) を経てから検証済みにする。
+
+- **Google**: Gmail / Workspace とも email 所有を検証しており、管理者は所有権を証明した
+  ドメイン外を claim できないため `confirmed`。
+- **Microsoft (Entra ID)**: テナント管理者が未検証の email claim を任意に設定でき、
+  他社ドメインの email を主張できる (nOAuth)。追加する場合は必ず `unconfirmed` から始める。
+
+### fail-closed と機械強制
+
+`email_trust` の未宣言・解釈不能 (非文字列 / 未知値) はすべて `Unconfirmed` に倒れる
+(`EmailTrustLevel::fromRaw`)。宣言漏れは静かな機能劣化 (登録者がメール確認を求められる) に
+なるため、`tests/Architecture/SocialProviderTrustPolicyTest.php` が
+「全 provider が `capability` / `email_trust` を明示宣言していること」と
+「google の `email_trust` が `confirmed` であること (現行挙動の pin)」を CI で強制する。
+
+policy を interface にしてあるのは nOAuth 対策の**キルスイッチ**を残すため
+(provider の運用変更が判明したら宣言 1 行を `unconfirmed` に倒せば新規登録が
+メール確認経路に落ちる)。
+
+---
+
 ## 関連ファイル
 
 | ファイル | 役割 |
@@ -202,4 +243,7 @@ ## 関連ファイル
 | `app/Console/Commands/ProductionPreflightCommand.php` | `production:preflight`。デプロイ前の設定検査 (違反で exit 1) |
 | `config/security.php` | CSP / HSTS / Permissions-Policy / metadata subset / HTTPS リダイレクトの設定 |
 | `config/trusted_hosts.php` | Host header allowlist (exact / wildcard suffix) |
+| `app/Services/Auth/EmailTrust/EmailTrustPolicy.php` | SSO email を検証済みとして信頼してよいかの方針 (interface。nOAuth 対策のキルスイッチ) |
+| `app/Services/Auth/EmailTrust/EmailTrustPolicyResolver.php` | provider の `email_trust` 宣言から policy を解決 (未宣言は Unconfirmed) |
+| `app/Enums/EmailTrustLevel.php` | 信頼段階 (Confirmed / Unconfirmed)。`fromRaw()` が fail-closed 変換の単一ソース |
 | `bootstrap/app.php` | 上記 middleware の配線 (prepend / web append / alias / trustHosts / trustProxies) |
diff --git a/tests/Architecture/SocialProviderTrustPolicyTest.php b/tests/Architecture/SocialProviderTrustPolicyTest.php
new file mode 100644
index 0000000..c969f6b
--- /dev/null
+++ b/tests/Architecture/SocialProviderTrustPolicyTest.php
@@ -0,0 +1,89 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * SSO provider の信頼宣言に関する不変条件 (deny-by-default)。
+ *
+ * `config/template.php` の `social_providers` に並ぶ provider は、
+ *   - capability   : recent-auth の step-up satisfier としての保証レベル
+ *   - email_trust  : IdP の主張する email を検証済みとして扱ってよいか
+ * の 2 つを **必ず明示宣言** する。宣言を書かないまま provider を足すと
+ * fail-closed 側 (identity_only / unconfirmed) に倒れて機能が静かに劣化するため、
+ * 「宣言漏れ」を CI で先に落とす (既存 SsrfPinBoundaryTest / RecentAuthRouteTest と同型)。
+ *
+ * google の email_trust = confirmed は現行挙動 (SSO 登録で email_verified_at が立つ) の pin。
+ * 緩める / 変えるときはこのテストごと意図的に変更すること。
+ *
+ * DB 不要のため Architecture スイート (TestCase のみ) に置く。
+ */
+
+use App\Enums\EmailTrustLevel;
+use App\Enums\ProviderCapability;
+use App\Services\Auth\EmailTrust\ConfirmedEmailTrustPolicy;
+use App\Services\Auth\EmailTrust\EmailTrustPolicyResolver;
+use App\Services\Auth\EmailTrust\UnconfirmedEmailTrustPolicy;
+
+/**
+ * @return array<string, mixed>
+ */
+function socialProvidersConfig(): array
+{
+    return config()->array('template.social_providers');
+}
+
+test('全 SSO provider が capability / email_trust を明示宣言している', function (): void {
+    $providers = socialProvidersConfig();
+
+    expect($providers)->not->toBeEmpty('social_providers が空 (config の読み込み経路が壊れている?)');
+
+    foreach ($providers as $provider => $definition) {
+        expect($definition)->toBeArray("provider '{$provider}' の定義が配列でない");
+
+        // capability: 未宣言なら step-up satisfier から静かに外れる (既存 fail-closed の明示化)
+        expect(array_key_exists('capability', $definition))
+            ->toBeTrue("provider '{$provider}' に capability 宣言が無い (config/template.php へ追記すること)");
+        $capability = $definition['capability'];
+        expect(is_string($capability) ? ProviderCapability::tryFrom($capability) : null)
+            ->not->toBeNull("provider '{$provider}' の capability が ProviderCapability の値でない");
+
+        // email_trust: 未宣言なら email_verified_at が立たなくなる (nOAuth 対策の fail-closed)
+        expect(array_key_exists('email_trust', $definition))
+            ->toBeTrue("provider '{$provider}' に email_trust 宣言が無い (config/template.php へ追記すること)");
+        $emailTrust = $definition['email_trust'];
+        expect(is_string($emailTrust) ? EmailTrustLevel::tryFrom($emailTrust) : null)
+            ->not->toBeNull("provider '{$provider}' の email_trust が EmailTrustLevel の値でない");
+    }
+});
+
+test('google の email_trust は confirmed に pin されている', function (): void {
+    // 現行挙動 (SSO 登録時に email_verified_at が立つ) を固定する。
+    expect(config('template.social_providers.google.email_trust'))->toBe('confirmed');
+});
+
+test('EmailTrustLevel::fromRaw は未宣言・解釈不能を Unconfirmed に倒す', function (mixed $raw): void {
+    expect(EmailTrustLevel::fromRaw($raw))->toBe(EmailTrustLevel::Unconfirmed);
+})->with([
+    '未宣言 (null)' => [null],
+    '未知の文字列' => ['nonsense'],
+    '配列' => [['x']],
+    '真偽値' => [true],
+    '整数' => [1],
+    '空文字' => [''],
+]);
+
+test('EmailTrustLevel::fromRaw は confirmed 宣言のみ Confirmed を返す', function (): void {
+    expect(EmailTrustLevel::fromRaw('confirmed'))->toBe(EmailTrustLevel::Confirmed)
+        ->and(EmailTrustLevel::fromRaw('unconfirmed'))->toBe(EmailTrustLevel::Unconfirmed);
+});
+
+test('EmailTrustPolicyResolver は宣言に従い policy を返す (未知 provider は Unconfirmed)', function (): void {
+    $resolver = app(EmailTrustPolicyResolver::class);
+
+    expect($resolver->for('google'))->toBeInstanceOf(ConfirmedEmailTrustPolicy::class)
+        // config に存在しない provider は fail-closed
+        ->and($resolver->for('unknown-provider'))->toBeInstanceOf(UnconfirmedEmailTrustPolicy::class);
+
+    config(['template.social_providers.google.email_trust' => 'unconfirmed']);
+    expect($resolver->for('google'))->toBeInstanceOf(UnconfirmedEmailTrustPolicy::class);
+});
diff --git a/tests/Feature/Auth/SocialAuthTest.php b/tests/Feature/Auth/SocialAuthTest.php
index f4accc1..82015d1 100644
--- a/tests/Feature/Auth/SocialAuthTest.php
+++ b/tests/Feature/Auth/SocialAuthTest.php
@@ -55,9 +55,45 @@ function fakeSocialiteCallback(SocialiteUserContract $user): void
     $this->assertAuthenticated();
 
     $user = User::whereBlind('email', 'email_index', 'sso@example.com')->firstOrFail();
+    // T105: google は email_trust=confirmed 宣言のため、trust policy 導入後も検証済みで作られる (挙動不変)
     expect($user->email_verified_at)->not->toBeNull();
     expect($user->terms_accepted_at)->not->toBeNull();
     expect($user->socialAccounts()->where('provider', 'google')->exists())->toBeTrue();
+
+    // verified ゲートを素通りして dashboard に到達できる (従来どおり)
+    $this->get(route('dashboard'))->assertOk();
+});
+
+test('T105: email_trust=unconfirmed の provider では SSO register が検証済みにしない', function (): void {
+    // nOAuth 対策の継ぎ目。confirmed を名乗れない provider は IdP の email 主張を信頼せず、
+    // アプリ側のメール到達確認 (/email/verify) を経てから検証済みにする。
+    config(['template.social_providers.google.email_trust' => 'unconfirmed']);
+
+    $this->withSession(['social_auth_intent' => 'register']);
+    fakeSocialiteCallback(fakeSocialiteUser('g-untrusted', 'untrusted@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+    $this->assertAuthenticated();
+
+    $user = User::whereBlind('email', 'email_index', 'untrusted@example.com')->firstOrFail();
+    expect($user->email_verified_at)->toBeNull();
+
+    // verified ゲートに落ちる (dashboard へ到達できない)
+    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
+});
+
+test('T105: email_trust 未宣言の provider は fail-closed で検証済みにしない', function (): void {
+    // 宣言漏れ (SocialProviderTrustPolicyTest が CI で先に落とす) が万一すり抜けても、
+    // 実行時は Unconfirmed に倒れることを固定する。
+    config(['template.social_providers.google.email_trust' => null]);
+
+    $this->withSession(['social_auth_intent' => 'register']);
+    fakeSocialiteCallback(fakeSocialiteUser('g-undeclared', 'undeclared@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+
+    $user = User::whereBlind('email', 'email_index', 'undeclared@example.com')->firstOrFail();
+    expect($user->email_verified_at)->toBeNull();
 });
 
 test('SSO login は未連携アカウントを自動登録しない', function (): void {
```

## 参考: 既存の類似機構 (config 宣言 + fail-closed + Architecture gate)

`App\Enums\ProviderCapability` が既に同型の「config 宣言を enum へ fail-closed 解決する」機構として存在する
(`config('template.social_providers.{provider}.capability')` を
`ConfirmRecentAuthController::capabilityFor()` が `IdentityOnly` へ倒す)。
本差分の `EmailTrustLevel::fromRaw()` はこれと同じ作法を、再利用可能な static メソッドとして切り出したもの。

## テスト結果 (worktree 内 実測)

- `composer test` (Pest, --parallel): **2716 passed / 0 failed / 2 skipped** (assertions 10904)
  - main の直近実測は 2704 passed / 0 failed / 2 skipped。差分 +12 は本差分で追加したテスト数と一致
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages`: passed
- `pnpm test` (vitest): 110 files / 1006 passed
- `pnpm test:packages`: 10 files / 106 passed

### deny-by-default gate の実効性の実測

`config/template.php` から `'email_trust' => 'confirmed',` の 1 行を削除して
`tests/Architecture/SocialProviderTrustPolicyTest.php` を実行したところ、**3 テストが fail** した
(宣言漏れ検出 / google の pin / resolver の解決結果)。gate は宣言漏れを実際に検知する。
