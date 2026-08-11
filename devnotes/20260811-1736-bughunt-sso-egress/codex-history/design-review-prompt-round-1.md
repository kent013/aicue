## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【追加コンテキスト】
- リポジトリは /workspace。ファイル読み込みは許可されているので現行コードを確認してよい。
- 本設計は aicue:T138 (devnotes/20260809-0027-external-seam-funnel/) が先送りした課題を引き受ける。
- 概念設計は同一セッション外で APPROVED 済み (devnotes/20260811-1736-bughunt-sso-egress/conceptual-design.md)。
- **UI 変更は無い** (Svelte / DESIGN.md / Atomic Design には触れない) ため観点 10/11 は該当しない想定。

---

## 詳細設計書

# 詳細設計: bughunt-sso-egress (bug-hunt の SSO 外部遷移を塞ぐ)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase は `tests/Pest.php` でグローバル適用**、`--parallel` 実行。
  個別 `DatabaseTransactions` 使用禁止
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン**推奨 / `declare(strict_types=1)` + 日本語コメント
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex 合議 Round 3 で APPROVED）
- 一次入力: [recon-brief.md](./recon-brief.md)
- 先行設計: `devnotes/20260809-0027-external-seam-funnel/`（aicue:T138）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | Socialite driver 解決点の切り出し | `app/Services/Auth/SocialiteDriverResolver.php`(新) / `app/Http/Controllers/Auth/SocialAuthController.php` | 必須 |
| 2 | SSO fake の実装 | `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php`(新) / `app/Services/Auth/Fakes/FakeSocialiteProvider.php`(新) | 必須 |
| 3 | fake 配線（capability flag 再利用・`local` 除外） | `app/Providers/FakeExternalsServiceProvider.php` | 必須 |
| 4 | fake 配線 inventory への登録 | `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php` | 必須 |
| 5 | 外部到達点目録の funnel retarget | `tests/Support/ExternalSeam/ExternalSeamInventory.php` / `tests/Architecture/ExternalSeamInventoryTest.php`（名称のみ） | 必須 |
| 6 | `stateless()` 封鎖の走査対象を funnel に追随させる | `tests/Feature/Security/ThrottleExemptionPremiseTest.php` | 必須 |
| 7 | 「SSO は fake しない」記述の是正 | `config/testing.php` / `AGENTS.md` / `docs/architecture.md` / `.env.bughunt.local.example` | 必須 |
| 8 | bughunt provision の実効 env 検証に `fake_externals` を追加 | `scripts/bug-hunt-shard.sh` | 必須 |
| 9 | 新規 behavioral テスト（負のコントロール込み） | `tests/Feature/Auth/FakeSocialiteWiringTest.php`(新) | 必須 |

---

## 施策 1: Socialite driver 解決点の切り出し

### 変更箇所

- 新規: `app/Services/Auth/SocialiteDriverResolver.php`
- 変更: `app/Http/Controllers/Auth/SocialAuthController.php`
  - L16 `use Laravel\Socialite\Facades\Socialite;` を削除
  - L36-38 constructor に resolver を追加
  - L67 `$driver = Socialite::driver($provider);`
  - L88 `$socialiteUser = Socialite::driver($provider)->user();`

### 波及変更

- TypeScript 型定義: **なし**（Inertia props も UI も変えない）
- API Resource / DTO: **なし**
- テストファイル: 施策 5 / 6 / 9 で扱う。**`SocialAuthTest` / `RecentAuthTest` /
  `RecentAuthMethodStampingTest` / `SecurityAuditTrailCoverageTest` / `AuthThrottleCoverageTest` は無変更**
  （理由は後述「既存テスト無変更の根拠」）

### 現行コード

```php
// app/Http/Controllers/Auth/SocialAuthController.php
use Laravel\Socialite\Facades\Socialite;

    public function __construct(
        private readonly IntendedPlanResolver $intendedPlanResolver,
    ) {}

    // redirect() 内
        $driver = Socialite::driver($provider);

    // callback() 内
        $socialiteUser = Socialite::driver($provider)->user();
```

### 変更後コード

```php
// app/Services/Auth/SocialiteDriverResolver.php （新規）
<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;

/**
 * Socialite driver の唯一の解決点（SSO の正規経路）。
 *
 * ★本クラスが `ExternalSeamInventory::socialLoginFunnel()` の名指し先である。
 *   他クラスに `Socialite::driver()` を書くと `ExternalSeamInventoryTest` が赤くなる。
 * ★非本番（testing / bughunt.local）では `FakeSocialiteDriverResolver` へ container bind
 *   される（`ExternalFakeWiringInventory`）。**差し替え点なので `final` にしない**。
 * ★責務は driver の解決 1 つだけ。intent 分岐・user 変換・`stateless()` 等を足さない
 *   （太らせるとサブクラス差し替えが崩れる。`stateless()` の封鎖は
 *   `ThrottleExemptionPremiseTest` が本ファイルも走査して守る）。
 */
class SocialiteDriverResolver
{
    public function driver(string $provider): Provider
    {
        return Socialite::driver($provider);
    }
}
```

```php
// app/Http/Controllers/Auth/SocialAuthController.php （差分のみ）
-use Laravel\Socialite\Facades\Socialite;
+use App\Services\Auth\SocialiteDriverResolver;

     public function __construct(
         private readonly IntendedPlanResolver $intendedPlanResolver,
+        private readonly SocialiteDriverResolver $socialiteDriver,
     ) {}

     // redirect() 内
-        $driver = Socialite::driver($provider);
+        $driver = $this->socialiteDriver->driver($provider);

     // callback() 内
-        $socialiteUser = Socialite::driver($provider)->user();
+        $socialiteUser = $this->socialiteDriver->driver($provider)->user();
```

`redirect()` 内の step-up 分岐（`method_exists($driver, 'with')`）は**そのまま残す**。
`$driver` の静的型が `Laravel\Socialite\Contracts\Provider` である点は現行と同じであり、
PHPStan の `method_exists()` narrowing も現行と同じ形で効く。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`driver(): Provider`）
- [x] null 安全（新たな null 経路を作らない。`Socialite::driver()` は facade docblock 上 `Provider` を返す）
- [x] DTO を返している（外部ライブラリの契約型を返す。配列返却なし）
- [x] Generics の型パラメータ: 該当なし

### リスク

- resolver がコンストラクタ注入になるため、`SocialAuthController` を `new` している箇所があると壊れる
  → 実測で 0 件（route action としてのみ使われる）。

---

## 施策 2: SSO fake の実装

### 変更箇所

- 新規: `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php`
- 新規: `app/Services/Auth/Fakes/FakeSocialiteProvider.php`

配置は `Fakes/` 配下が必須（`FakeClassReferenceInvariantTest` の `4-1 配置規約`）。

### 波及変更

- TypeScript 型定義 / API Resource / DTO: **なし**
- テストファイル: 施策 9 の新規テストが契約を固定する

### 変更後コード

```php
// app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php （新規）
<?php

declare(strict_types=1);

namespace App\Services\Auth\Fakes;

use App\Services\Auth\SocialiteDriverResolver;
use Laravel\Socialite\Contracts\Provider;

/**
 * SSO (Socialite) driver 解決点の fake。
 *
 * bug-hunt / 自動テストレーンのブラウザが SSO ボタンから**実 IdP へ出ないようにする**ための
 * 差し替え先。配線条件は `FakeExternalsServiceProvider::registerSocialAuthFake()`
 * （`config('testing.fake_externals') === true` ∧ env ∈ {testing, bughunt.local}）。
 */
final class FakeSocialiteDriverResolver extends SocialiteDriverResolver
{
    public function driver(string $provider): Provider
    {
        return new FakeSocialiteProvider($provider);
    }
}
```

```php
// app/Services/Auth/Fakes/FakeSocialiteProvider.php （新規）
<?php

declare(strict_types=1);

namespace App\Services\Auth\Fakes;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Two\User as SocialiteTwoUser;

/**
 * IdP へ出ない Socialite provider の fake。
 *
 * - `redirect()` は**自アプリの `social.callback` へ 302** する（IdP 風の中間画面は作らない。
 *   IdP の同意画面はアプリが所有する UX ではないため、作っても検証対象が増えない）。
 * - `user()` は provider 名から決定論的に導出した canned な identity を返す。
 *   決定論にするのは、`register` / `link` で作った連携へ次の `login` / `step-up` が
 *   同じ identity で戻れるようにするため。
 * - OAuth の `state` は持たない。`SocialAuthController` / `SocialAccountService` は
 *   `state` を一切参照しない（session に置くのは `social_auth_intent` だけ）ため、
 *   アプリ層の契約は 1 つも飛ばさない。
 * - `with()` は実装しない。controller の step-up 分岐は `method_exists($driver, 'with')` で
 *   守られており、未実装なら単に skip される。
 */
final readonly class FakeSocialiteProvider implements Provider
{
    public function __construct(private string $provider) {}

    public function redirect(): RedirectResponse
    {
        // 自アプリ内で round-trip を閉じる（APP_URL の host。bughunt は 127.0.0.1:801x）
        return new RedirectResponse(route('social.callback', ['provider' => $this->provider]));
    }

    public function user(): SocialiteUserContract
    {
        return (new SocialiteTwoUser)
            ->setRaw([])
            ->map([
                'id' => 'fake-'.$this->provider.'-user',
                'nickname' => 'fake-'.$this->provider,
                'name' => 'SSO Fake User ('.$this->provider.')',
                'email' => 'fake-'.$this->provider.'-sso@example.com',
                'avatar' => null,
            ]);
    }
}
```

**identity の決定規則**（アプリが読むのは実測で `getId()` / `getEmail()` / `getName()` の 3 つのみ。
`EmailTrustPolicy` は socialite user から値を読まない）:

| フィールド | 値 |
|---|---|
| `id` | `fake-{provider}-user` |
| `nickname` | `fake-{provider}` |
| `name` | `SSO Fake User ({provider})` |
| `email` | `fake-{provider}-sso@example.com` |
| `avatar` | `null` |

`example.com` は RFC 2606 予約ドメインで、`ManualTestSeeder` の `{role}-{plan}@example.com` 等とは
衝突しない（`fake-` 接頭辞）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`redirect(): RedirectResponse` / `user(): SocialiteUserContract`）。
      `Contracts\Provider` は戻り値型を宣言していない（docblock のみ）ため、具体型の付与は共変で合法
- [x] null 安全（`avatar` は `null` を明示。`map()` は `property_exists` で代入するため未知キーは無視される）
- [x] 配列返却なし
- [x] `final readonly class` + promoted property

### リスク

- `route('social.callback', …)` は `config('app.url')` 由来の絶対 URL を返す。bughunt の
  `APP_URL=http://127.0.0.1:801x` は `PLAYWRIGHT_MCP_ALLOWED_ORIGINS` と一致する（多層防御は維持）。
- `social.callback` は `throttle:social-callback`（10/min/IP）を持つ。SSO を 1 分間に 10 回超えて
  押すと 429 になるが、これは**本番と同じ挙動**であり fake 固有の詰みではない。

---

## 施策 3: fake 配線（capability flag 再利用・`local` 除外）

### 変更箇所

- `app/Providers/FakeExternalsServiceProvider.php`
  - クラス docblock（「SSO は fake しない」の記述を是正）
  - `EXTERNAL_FAKE_ENVIRONMENTS` の docblock（同上）
  - 定数 `SSO_FAKE_ENVIRONMENTS` を追加
  - `register()` に `registerSocialAuthFake()` の呼び出しを追加
  - private method `registerSocialAuthFake()` を追加

### 波及変更

- テストファイル: `ExternalFakeWiringInvariantTest` は inventory 駆動なので**無変更**
  （施策 4 の entry 追加で 3-1 / 3-2 / 3-3 のケースが自動増殖する）

### 変更後コード

```php
    /**
     * SSO (Socialite) fake を許可する環境 allowlist。
     *
     * ★`EXTERNAL_FAKE_ENVIRONMENTS` と**別定数にする**（値が同じでも概念が違う。
     *   思考原則 4「別物の概念を似ているからで統合しない」）。
     * ★`local` を意図的に除外する。SSO fake は未認証 GET 2 本
     *   （`/auth/{p}/redirect/login` → `/auth/{p}/callback`）で canned アカウントへ
     *   ログインできる = **認証バイパス**であり、かつ `local` は開発者が
     *   実 IdP 連携を確認する唯一の環境である（無言で fake が立つと本番 SSO の回帰を見逃す）。
     */
    private const array SSO_FAKE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    public function register(): void
    {
        $this->registerExternalServiceFakes(); // Stripe + captcha: fake_externals 依存
        $this->registerSocialAuthFake();       // SSO: fake_externals 依存 / env allowlist は別
        $this->registerStorageFakes();         // storage: fake_storage (FakeStorageGate) 依存
    }

    /**
     * SSO fake (fake_externals + SSO_FAKE_ENVIRONMENTS)。
     *
     * ★warning ログは出さない。`local` の除外は**誤設定ではなく設計上の除外**であり
     *   （LLM fake と同じ理由）、ここで warning を出すと既存の
     *   `3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す`
     *   が `once()` で固定している呼び出し回数を壊す。
     */
    private function registerSocialAuthFake(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        if (! in_array($this->app->environment(), self::SSO_FAKE_ENVIRONMENTS, true)) {
            return;
        }

        // ★abstract が具象クラスのため、bind を消しても Laravel が本物を自動組み立てし、
        //   **無言で**実 IdP へのリダイレクトに戻る（captcha と同じ構図）。
        $this->app->bind(SocialiteDriverResolver::class, FakeSocialiteDriverResolver::class);
    }
```

### なぜ `Laravel\Socialite\Contracts\Factory` に bind しないのか（設計の要）

`vendor/laravel/socialite/src/SocialiteServiceProvider.php` は **`DeferrableProvider`** で
`provides()` が `[Factory::class]` を返す。`Container::bind()` は `deferredServices` を消さないため:

1. `bind(Factory::class, Fake…)` する
2. 最初の `app(Factory::class)` で `Application::loadDeferredProviderIfNeeded()`（framework L1087）が
   `isDeferredService() === true` かつ `instances[Factory::class]` 未設定を見て deferred provider を読み込む
3. その `register()` の `singleton(Factory::class, …)` が**後勝ちで fake を消す**
4. **無言で実 IdP へ戻る**

回避には `instance()` か `registerDeferredProvider()` が必要だが、
`FakeWiringSourceScanner::ALLOWED_APP_CALLS` は provider 内の container 呼び出しを
`bind` / `make` / `environment` の 3 形に閉じており、その docblock は
「allowlist を広げる方向へ倒さない」と明記している。**自前の具象クラスを差し替えキーにすれば
gate の文法にも既存 fake の形にも一切触らずに済む**（`RecaptchaVerifier` と同一形）。

### gate 適合チェック（`ExternalFakeWiringInvariantTest` / `FakeWiringSourceScanner`）

| gate | 適合 |
|---|---|
| 3-8 bind 組の集合一致 | `bind(SocialiteDriverResolver::class, FakeSocialiteDriverResolver::class)` は位置引数 2 個・両方 `::class` = `classPair` |
| 3-9 許可された呼び出し形のみ | 使うのは `bind`（classPair）と `environment`（引数なし）だけ。`app()` / `resolve()` ヘルパは使わない |
| 3-10 参照クラスの集合一致 | provider が参照する fake は `FakeSocialiteDriverResolver` のみ（inventory の fake 集合に入る）。`FakeSocialiteProvider` は provider から参照しない |
| 4-1 配置規約 | 2 クラスとも `app/Services/Auth/Fakes/` 配下 |
| 4-3 本番コードは fake を参照しない | `FakeSocialiteDriverResolver` → `FakeSocialiteProvider` の参照は「fake 実装クラス同士」なので除外条件に該当。`FAKE_REFERENCE_ALLOWED` は **5 件のまま増やさない** |
| 3-4 warning once | SSO ブロックは warning を出さないので回数不変 |

### リスク

- `register()` の呼び出し順を変えると 3-6（`AppServiceProvider` より後）が崩れる → 順序は触らない。

---

## 施策 4: fake 配線 inventory への登録

### 変更箇所

- `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php`
  - `use` 追加（`SocialiteDriverResolver` / `FakeSocialiteDriverResolver`）
  - 定数 `SSO_ENVIRONMENTS` を追加
  - `bindings()` に entry を 1 本追加

### 変更後コード

```php
    /** SSO fake の env allowlist（FakeExternalsServiceProvider::SSO_FAKE_ENVIRONMENTS と対）*/
    private const array SSO_ENVIRONMENTS = ['testing', 'bughunt.local'];

            // bindings() の末尾へ
            new ExternalFakeBinding(
                abstract: SocialiteDriverResolver::class,
                real: SocialiteDriverResolver::class,
                fake: FakeSocialiteDriverResolver::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::SSO_ENVIRONMENTS,
                risk: 'SSO (Socialite) の driver 解決点。abstract が具象クラスのため、bind を消しても '
                    .'Laravel が本物を自動組み立てし、**無言で**実 IdP (accounts.google.com 等) への '
                    .'リダイレクトに戻る。bug-hunt のブラウザは別プロセスなので StrayHttpRequestGuard は効かない。',
            ),
```

`ExternalFakeWiringInventory` の ⚠️ 注記（Architecture lane は `RefreshDatabase` を使わないので
abstract / real / fake の constructor が DB に触れないこと）を確認済み:
`SocialiteDriverResolver` は constructor を持たず、`FakeSocialiteDriverResolver` も持たない。

### 自動的に増える検査（新テストを書かずに得られる）

| 既存テスト | 増えるケース |
|---|---|
| `3-1 対照: flag off では real 実装が厳密一致で解決される` | `SocialiteDriverResolver` → real |
| `3-2 実証: flag on + allowlist 環境で fake が厳密一致で解決される` | `@ testing` / `@ bughunt.local` の 2 件 |
| `3-3 provider 単体: flag on でも allowlist 外 env では real のまま` | `@ production` / `@ staging` の 2 件 |

判定は `$resolved::class === $expected` の**厳密一致**なので、fake が real のサブクラスでも
対照が無意味にならない（`FakeTakeObjectStorage` と同じ理由）。

### リスク

- `SSO_ENVIRONMENTS` と `SSO_FAKE_ENVIRONMENTS` の二重管理。既存の
  `EXTERNAL_ENVIRONMENTS` / `EXTERNAL_FAKE_ENVIRONMENTS`、`STORAGE_ENVIRONMENTS` /
  `FakeStorageGate` も同じ二重管理であり、**本設計で新しい構造を持ち込まない**（同形を保つ）。
  ズレたときは 3-2 / 3-3 が赤くなる（gate が検出する）。

---

## 施策 5: 外部到達点目録の funnel retarget

### 変更箇所

- `tests/Support/ExternalSeam/ExternalSeamInventory.php`
  - `use App\Http\Controllers\Auth\SocialAuthController;` → `use App\Services\Auth\SocialiteDriverResolver;`
  - `socialLoginFunnel()` の戻り値と docblock
  - `entries()` の SocialLogin entry（`class` と `rationale`）
- `tests/Architecture/ExternalSeamInventoryTest.php`
  - L51 の M5 説明文と L233 のテスト名を**クラス名非依存**に改める（assert は無変更）

### 変更後コード

```php
    /**
     * `SocialLogin` の正規経路（**名指し固定**）。
     *
     * ★標準形 v1「正規経路へ集約し直呼びを構文解析で禁止」の機械化。
     *   この 1 クラス以外は `Guarded` でも `Exempt` でも登録できない。
     *   ★T153: 差し替え先（SSO fake）を作るため、集約先を controller から
     *     `SocialiteDriverResolver` へ切り出した。container の差し替えキーになれるのは
     *     controller ではなくこの薄い解決点である。
     */
    public static function socialLoginFunnel(): string
    {
        return SocialiteDriverResolver::class;
    }

            // --- social_login (1 クラス。名指し固定) ---
            new ExternalSeamEntry(
                class: SocialiteDriverResolver::class,
                kind: ExternalSeamKind::SocialLogin,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'SSO の唯一の正規経路。他クラスからの Socialite::driver() は本目録に登録できず必ず赤くなる。'
                    .'非本番は FakeSocialiteDriverResolver へ container bind で差し替わる',
            ),
```

```php
// tests/Architecture/ExternalSeamInventoryTest.php
-    'M5' => 'SocialAuthController 以外のクラスに Socialite::driver() を書くと名指し固定が赤くなる',
+    'M5' => 'funnel クラス以外に Socialite::driver() を書くと名指し固定が赤くなる',

-test('外部到達: SocialLogin は SocialAuthController 1 クラスに固定される', function (): void {
+test('外部到達: SocialLogin は funnel 1 クラスに固定される', function (): void {
```

### 波及変更

- テストファイル: 上記 2 ファイルのみ。**assert のロジックは 1 文字も変えない**
  （`socialLoginFunnel()` を動的に参照する設計なので retarget だけで追随する）。
- テスト名の変更はリポジトリ内から参照されていないことを確認済み
  （grep でヒットするのは `devnotes/` の履歴のみ）。

### リスク

- `SocialAuthController` から `Socialite` facade 参照が消えるため、走査集合が
  `{SocialiteDriverResolver}` になる。ここが一致しないと当該テストが赤くなる = 検出は働く。

---

## 施策 6: `stateless()` 封鎖の走査対象を funnel に追随させる

### 変更箇所

- `tests/Feature/Security/ThrottleExemptionPremiseTest.php` L574-581

### なぜ必要か（見落とすとセキュリティ不変条件が静かに弱まる）

現行の走査は `app/Http/Controllers/Auth/SocialAuthController.php` の**ソース文字列**に
`stateless(` が現れないことを見ている。施策 1 で `Socialite::driver()` が resolver へ移ると、
`->stateless()` を書く自然な場所も resolver へ移る。走査対象を移さないと
**OAuth state 照合の無効化が無検出で入り得る**。

### 変更後コード

```php
test('SSO の driver 解決経路は stateless() を使わない (state 照合を無効化する最短経路の封鎖)', function (): void {
    // ソース走査は**補助**。単独の根拠にはしない (上の実挙動テストが本体)。
    // stateless() 化は state 照合を丸ごと無効化する最短経路なので二重に塞ぐ。
    // ★T153: driver 解決点を SocialiteDriverResolver へ切り出したため、走査対象は
    //   controller と resolver の 2 本である (片方だけ見ると移設で無音になる)。
    $paths = [
        'Http/Controllers/Auth/SocialAuthController.php',
        'Services/Auth/SocialiteDriverResolver.php',
    ];

    $sources = [];
    foreach ($paths as $path) {
        $source = file_get_contents(app_path($path));
        // 読み取り失敗を「違反なし」と解釈させない (fail-closed)
        expect($source)->toBeString()->not->toBe('', "走査対象を読めません: {$path}");
        $sources[$path] = $source;
    }

    // 母集団 0 件で緑にならないことの保証
    expect($sources)->toHaveCount(count($paths));

    foreach ($sources as $path => $source) {
        expect($source)->not->toContain('stateless(', "{$path} が stateless() を使っています");
    }
});
```

### 波及変更

- 既存テストの**削除・無効化はしない**。走査対象の追加（1 → 2 本）と名称の一般化のみ。

### リスク

- 将来 funnel がさらに移設されたとき、`$paths` の更新漏れが起こり得る。
  それを機械化するには funnel から path を導出する必要があるが、本テストは
  Feature lane にあり `ExternalSeamInventory`（Architecture 用 support）へ依存させると
  責務が混ざる。**現時点は 2 本の明示列挙で止める**（過剰に作らない）。
  funnel を動かす PR は `ExternalSeamInventoryTest` が必ず赤くなるので、そこから本テストへ辿れる。

---

## 施策 7: 「SSO は fake しない」記述の是正

**残すと嘘になる**ため同一 PR で直す。文言は「SSO も fake する。ただし `local` は除外」で統一する。

### 7-1. `config/testing.php`

```diff
 | true のとき FakeExternalsServiceProvider::register() が以下を fake 実装へ bind する:
 |   - Stripe 課金 gateway (checkout / portal / auto-recharge)
 |   - captcha 検証器 (RecaptchaVerifier → RecaptchaVerifierTestFake)
-| **SSO (Socialite) は fake しない** (差し替え先を作っていない。
-|  bug-hunt のブラウザは SSO ボタンから実 IdP へ遷移する。
-|  docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
-| 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
+|   - SSO driver 解決点 (SocialiteDriverResolver → FakeSocialiteDriverResolver)
+| **SSO だけは env allowlist が狭い** (testing / bughunt.local のみ。**local を除外**)。
+|  SSO fake は未認証 GET 2 本で canned アカウントへログインできる = 認証バイパスであり、
+|  かつ local は実 IdP 連携を確認する唯一の環境であるため
+|  (docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
+| Stripe / captcha の有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
 | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
```

### 7-2. `app/Providers/FakeExternalsServiceProvider.php`

クラス docblock の「**SSO (Socialite) は fake しない**」2 箇所（クラス docblock と
`EXTERNAL_FAKE_ENVIRONMENTS` の docblock）を、
「SSO は `SSO_FAKE_ENVIRONMENTS`（`local` を除く）で fake する」へ差し替える。

### 7-3. `AGENTS.md` ドメイン規約 9

```diff
-   - **保証範囲を誇張しない**: これは**検知**であって**遮断ではない**。bug-hunt のブラウザは
-     SSO ボタンから実 IdP へ遷移する。走査根は `app/` のみで `routes/` / `config/` は見ない。
+   - **保証範囲を誇張しない**: これは**検知**であって**遮断ではない**。
+     SSO だけは別途 fake 配線 (testing / bughunt.local) で実 IdP への遷移を塞いでいるが、
+     それは**本目録の効果ではない** (`ExternalFakeWiringInventory` が正本)。
+     走査根は `app/` のみで `routes/` / `config/` は見ない。
...
-   - 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifierTestFake` へ bind される
-     (`ExternalFakeWiringInventory`)。**SSO は fake しない**。
+   - 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifierTestFake` へ bind される
+     (`ExternalFakeWiringInventory`)。**SSO も同じ flag で fake する**が、env allowlist は
+     `testing` / `bughunt.local` のみで **`local` を除く** (認証バイパス面の最小化と
+     実 IdP 連携の確認手段の温存)。
```

### 7-4. `docs/architecture.md`

- §SSO の集約と captcha の fake 配線: 「SSO は `SocialAuthController` に固定」→
  「`SocialiteDriverResolver` に固定」。「**SSO は fake しない**」の項を、
  fake 配線の説明（flag / env allowlist / `local` 除外の理由 / deferred provider を避けた理由）へ差し替える。
- §保証しないもの 1（**本節が正本**）:

```diff
-1. **出口の遮断**。本目録は新経路の**検知**であり、実行時の外部通信は止めない。
-   bug-hunt のブラウザが SSO ボタンから実 IdP へ遷移する現状は変わらない
+1. **出口の遮断**。本目録は新経路の**検知**であり、実行時の外部通信は止めない。
+   SSO の実 IdP 遷移は別機構 (fake 配線) が塞いでおり、本目録の効果ではない。
+   また塞がるのは**アプリが返すリダイレクト先**までで、ブラウザ自身が出す通信は
+   Playwright の origin allowlist が担う別の層である
```

### 7-5. `.env.bughunt.local.example`

`TESTING_FAKE_EXTERNALS` のコメントから「**SSO (Socialite) は fake しない** — bug-hunt のブラウザは
SSO ボタンから実 IdP へ遷移する」を削除し、
「SSO: `SocialiteDriverResolver` を `FakeSocialiteDriverResolver` へ bind し、SSO ボタンは
自アプリの `social.callback` へ戻る（実 IdP へ出ない）」へ差し替える。

### 7-6. `.claude/skills/app-bug-hunt/SKILL.md` — **変更不要**（判断の記録）

禁止事項 4（L72-76）と環境表（L194）は**既に「決済 / Captcha / SSO / mail / S3 は fake / 外部通信なし」と
書いてある**。つまり現状はスキル正本の記述が**先行して嘘**になっている状態であり、
本 PR はその記述を**真にする**変更である。したがって SKILL.md 側の修正は不要。

副次的に、run `20260811-003230` の shard 4 で行っていた
「実 IdP ドメインへの遷移を検知したら即中断して報告」という**人手の回避指示は不要になる**
（禁止事項 4 の一般則で足りる）。スキル本文に個別の但し書きを足さない。

---

## 施策 8: bughunt provision の実効 env 検証に `fake_externals` を追加

### 変更箇所

- `scripts/bug-hunt-shard.sh`（`provision` の「(c) 実効 env 検証」ブロック、L1071-1114 付近）

### なぜ必要か

`.env.bughunt.local` は **git 管理外**であり、`TESTING_FAKE_EXTERNALS=true` の行が欠けても
現状は**誰も気付かない**（Stripe / captcha fake も同時に外れる）。provision の実効 env 検証は
既に `fake_llm` / `fake_storage` を見ているのに `fake_externals` だけ見ていない。

### 変更後コード

```diff
             "admin_mfa_required" => config("admin.mfa_required"),
+            "fake_externals" => config("testing.fake_externals"),
             "fake_llm" => config("testing.fake_llm"),
             "fake_storage" => config("testing.fake_storage"),
         ]);' --env=bughunt.local | grep -o '{.*}' | tail -1)"
```

```diff
     "admin_mfa_required": False,
+    # bughunt は外部 fake (Stripe / captcha / SSO) が必須。.env.bughunt.local は git 管理外なので
+    # 行の欠落を provision で fail-fast させる (モード派生ではない固定期待値)。
+    "fake_externals": True,
     # モードから期待値を導出 (serve/worker と同一フラグで config が解決されることを固定)。
     "fake_llm": (os.environ["LLM_MODE"] == "fake"),
```

### 波及変更

- self-test `[z5]`（実効 env 期待値**導出**の単体評価）は**モード派生キーだけ**を検証する断片であり、
  `fake_externals` は固定値なので **`[z5]` は無変更**。
- `MODE_ENV` に `TESTING_FAKE_EXTERNALS` を**注入しない**。注入すると
  「スクリプトが入れた値をスクリプトが検証する」トートロジーになり、検査が空振りする。
  ここで検証したいのは **dotenv 側の欠落**である。

### リスク

- 既存の `.env.bughunt.local` に当該行が無い開発者は provision が落ちるようになる。
  これは**意図した破壊的変更**（fail-fast）で、`.env.bughunt.local.example` に既に記載がある。
  失敗メッセージが `('fake_externals', (None, True))` の形で出るので原因が特定できる。

---

## 施策 9: 新規 behavioral テスト（負のコントロール込み）

### 変更箇所

- 新規: `tests/Feature/Auth/FakeSocialiteWiringTest.php`

Feature lane（`RefreshDatabase` はグローバル適用・`--parallel` 実行・Factory 必須）。
env は phpunit.xml が `testing` に固定しており、これは `SSO_FAKE_ENVIRONMENTS` に含まれるため
HTTP レベルの round-trip をそのまま書ける。

### 共通ヘルパ（ファイル内）

```php
/** このテスト内でだけ SSO fake を配線する (レーン既定は flag off のまま) */
function enableSsoFake(): void
{
    config(['testing.fake_externals' => true]);
    (new FakeExternalsServiceProvider(app()))->register();
}
```

`config()` はテストごとに巻き戻り、bind は当該テストの container にしか入らない
（Pest は test case ごとに app を再構築する）。他ファイル・他プロセスへ漏れない。

### テストケース一覧（ファイル名 + ケース名まで）

`tests/Feature/Auth/FakeSocialiteWiringTest.php`

| # | テストケース名 | 検証内容 |
|---|---|---|
| 1 | `負のコントロール: fake 無効 (レーン既定) では social.redirect が実 IdP ホストへ出る` | flag を触らずに `GET /auth/google/redirect/login` → `Location` の host が `accounts.google.com` で、**自アプリ host ではない**。これが緑でないと 2 番以降は空振りしている |
| 2 | `fake 有効: 宣言済み全 provider の social.redirect が自アプリ host に閉じる` | `config()->array('template.social_providers')` の全キーを走査。**母集団が空なら fail**（`expect($providers)->not->toBeEmpty()`）。各 provider で `Location` の host が `parse_url(config('app.url'), PHP_URL_HOST)` と一致し、`social.callback` の path であること |
| 3 | `fake 有効: register intent の round-trip で User と SocialAccount と個人組織が作られる` | `GET /auth/google/redirect/register?terms_accepted=1` → 302 を追って callback → `dashboard` へ着地・`assertAuthenticated()`・`User::whereBlind('email','email_index','fake-google-sso@example.com')` が 1 件・`socialAccounts()` に `provider_user_id='fake-google-user'`・個人組織が 1 件 |
| 4 | `fake 有効: login intent の round-trip で連携済みユーザーとしてログインする` | `User::factory()` + `SocialAccount::factory()` で `fake-google-user` を連携 → `GET /auth/google/redirect/login` → 追従 → `dashboard`・`assertAuthenticatedAs($user)` |
| 5 | `fake 有効: link intent の round-trip でログイン中ユーザーに連携が付く` | `actingAs(User::factory()->create())` → `GET /auth/google/redirect/link` → 追従 → `settings.security` へ redirect・`success` flash・`social_accounts` に 1 行 |
| 6 | `fake 有効: step-up intent の round-trip で recent-auth の鮮度が stamp される` | 連携済みユーザーで `actingAs` + `url.intended='/settings'` → `GET /auth/google/redirect/step-up` → 追従 → `/settings` へ・`session('recent_auth_method') === 'sso'`・`session('recent_auth_provider') === 'google'` |
| 7 | `fake の identity は provider ごとに決定論的で、一目で fake と分かる` | `FakeSocialiteProvider('google')->user()` の `getId()` / `getEmail()` / `getName()` を値で固定（契約 pin）。`getId()` が `fake-` で始まること |
| 8 | `fake は local 環境では配線されない (実 IdP 連携の確認手段を残す)` | `$this->app['env'] = 'local'` + flag on + `register()` → `app(SocialiteDriverResolver::class)::class` が **real と厳密一致**。try/finally で env を復元 |
| 9 | `fake 有効でも social.callback は intent 不在なら Socialite に触れずログインへ戻す` | `withSession([])` で callback 直叩き → `login` へ redirect・`assertGuest()`（fake が短絡分岐を素通りさせていないことの確認） |

### 既存テスト無変更の根拠（実コードで確認済み）

| テスト | 無変更で通る理由 |
|---|---|
| `tests/Feature/Auth/SocialAuthTest.php` | `Socialite::shouldReceive('driver')->with('google')` は**ファサード root を差し替える**。resolver は呼び出しのたびに `Socialite::driver()` を実行するので mock が介入する。レーンは flag off なので fake も立たない |
| `tests/Feature/Auth/RecentAuthTest.php` | 同上（`fakeStepUpSocialiteCallback`） |
| `tests/Feature/Auth/RecentAuthMethodStampingTest.php` | 同上 |
| `tests/Feature/Security/SecurityAuditTrailCoverageTest.php` | 同上 |
| `tests/Feature/Security/AuthThrottleCoverageTest.php` | `Socialite::spy()` / `shouldNotHaveReceived('driver')`。controller は intent 不在で resolver へ到達する前に短絡するので不変 |
| `tests/Feature/Security/ThrottleExemptionPremiseTest.php`（`stateless()` 以外） | 実 `SocialiteManager` へ HTTP spy を差す方式。flag off なので resolver は実 facade を通す。`accounts.google.com` を期待する assert も flag off のまま通る |
| `tests/Architecture/RecentAuthRouteTest.php` | `SocialAuthController::class` を allowlist に持つが、クラスは残る |
| `tests/Architecture/ExternalFakeWiringInvariantTest.php` | inventory 駆動。entry 追加でケースが自動増殖する（テスト本体は無変更） |
| Browser lane（`phpunit.browser.xml`） | `TESTING_FAKE_EXTERNALS` を宣言していないので flag off。挙動不変 |

**唯一の既存テスト変更は施策 6（`stateless()` 走査対象の追加）と施策 5（テスト名・M5 説明文）である。**

### UI / Browser lane について

本設計は **UI を 1 行も変えない**（Svelte / DESIGN.md token / Atomic Design 層に触れない）。
したがって **Browser lane（Chromium + WebKit）の追加は行わない**。
Browser lane は flag off で走るため、そこで fake の挙動を検証することもできない
（有効化すると既存 Browser テストの前提を変えるので**しない**）。

### mutation で赤化を確認する手順（実装時に必ず実施し、結果を PR に残す）

| # | mutation | 期待して赤くなるテスト |
|---|---|---|
| M1 | `registerSocialAuthFake()` の `bind(...)` 行を削除 | `3-2 実証`（testing / bughunt.local の 2 ケース）、新規 #2〜#6 |
| M2 | `SSO_FAKE_ENVIRONMENTS` に `'local'` を追加 | 新規 #8 |
| M3 | `SSO_FAKE_ENVIRONMENTS` を `['production']` に変更 | `3-2 実証`、`3-3`（production ケース）、新規 #2〜#6 |
| M4 | `FakeSocialiteProvider::redirect()` を `new RedirectResponse('https://accounts.google.com/o/oauth2/auth')` に変更 | 新規 #2（host 一致）、#3〜#6（round-trip） |
| M5 | `FakeSocialiteProvider::user()` の `id` を `'g-1'` に変更 | 新規 #7 |
| M6 | `SocialAuthController` の resolver 呼び出しを `Socialite::driver()` へ戻す | `外部到達: SocialLogin は funnel 1 クラスに固定される`（走査集合が 2 クラスになる）、新規 #2〜#6 |
| M7 | `ExternalFakeWiringInventory` の SSO entry を削除 | `3-8 網羅性`（bind 組の集合一致）、`3-10`（参照クラスの集合一致） |
| M8 | `SocialiteDriverResolver::driver()` に `->stateless()` を追加 | 施策 6 のテスト |
| M9 | 施策 6 の `$paths` から resolver を外す | 直接は赤くならない（**設計上の限界**。M8 との組み合わせでのみ検出）→ 「保証しないもの」に明記 |
| M10 | `.env.bughunt.local` の `TESTING_FAKE_EXTERNALS` を `false` にして `provision` | provision が `('fake_externals', (False, True))` で fail（手動確認。CI では回らない） |
| M11 | 新規テストの `expect($providers)->not->toBeEmpty()` を消し、`social_providers` を空 config にする | 消す前は #2 が fail、消すと緑になる = **空振り防止の存在確認**（確認後に戻す） |

### 検査が空振りしないことの保証（3 点）

1. **負のコントロール**: #1 が「flag off なら実 IdP host へ出る」を示す。#1 と #2 が同時に緑でなければ、
   #2 は「もともと外に出ていなかった」を見ているだけになる。
2. **母集団 0 件で fail**: #2 は `config()->array('template.social_providers')` を母集団とし、
   空なら `expect(...)->not->toBeEmpty()` で落ちる。provider が増えれば検査も自動で増える（exact-fit）。
   施策 6 も走査対象 2 本の**件数一致**を assert する。
3. **厳密一致 / 集合一致**: `ExternalFakeWiringInvariantTest` は `$resolved::class === $expected` の
   厳密一致（継承で誤魔化されない）、`3-8` / `3-10` は**集合一致**（`toBe`）。
   `ExternalSeamInventoryTest` の funnel 固定も**完全一致**。

---

## この設計が保証しないもの（誇張しない）

1. **ブラウザ自身が出す通信**。塞ぐのは「アプリが返すリダイレクト先」までである。
   Playwright の origin allowlist（`PLAYWRIGHT_MCP_ALLOWED_ORIGINS`）は多層防御として**残す**。
   ページ内の `<img>` / `<script>` / fetch が外部を叩く経路には本 PR は沈黙する。
2. **`.env.bughunt.local`（git 管理外）の内容**。施策 8 の検査は **provision 実行時にしか走らない**。
   provision 後にファイルを書き換えて serve を再起動する経路は検出しない。
3. **`local` 環境の SSO**。fake は `local` に立たない = SSO ボタンは実 IdP へ出る（意図した仕様）。
4. **vendor 内部から出る通信**（Socialite / Cashier の内部実装）。
5. **1 provision 内での 4 intent 成功経路の同時成立**。`link` の成功と `register` の新規作成成功は
   「`fake-{provider}-user` が未連携」という状態を**先着 1 回**で消費するため排他である
   （2 回目以降はアプリの正当な競合分岐へ落ちる。詰みではない）。
   排他は provision（`migrate:fresh --seed`）でリセットされ、shard ごとに独立している。
6. **`stateless()` 走査対象の自動追随**。施策 6 は 2 本の明示列挙であり、funnel をさらに
   移設したときの追随は人手（`ExternalSeamInventoryTest` が先に赤くなるので気付ける）。
7. **`ExternalSeamInventory` は検知であって遮断ではない**。SSO の遮断は fake 配線側の効果であり、
   目録の効果ではない（両者を混ぜて書かない）。
8. **既存の Stripe / captcha fake の env allowlist は変えていない**（`local` を含んだまま）。
   本 PR が狭めたのは SSO だけである。

---

## セキュリティレビュー観点（自己点検）

| 観点 | 結論 |
|---|---|
| 本番混入 | `config('testing.fake_externals')` 既定 `false` + env allowlist（`production` / `staging` は 3-3 が実証）+ `ProductionEnvGuard` の deploy 時 fail-fast。**新しい防壁は足さない**（既存の三重を再利用） |
| 認証バイパス面 | fake は「未認証 GET 2 本で canned アカウントへログイン」できる。だから env allowlist から `local` を除いた。identity は**外部入力で切り替えられない**（provider 名からのみ導出）ので、面は 1 identity/provider に固定される |
| tenant キー不信 | 新しい payload 受け口を作らない |
| 認可 | 変更系 route を追加しない（route は 1 本も増えない） |
| throttle | `social.callback` の `throttle:social-callback` は不変（`ThrottleExemptionPremiseTest` が 1 本であることを固定） |
| PII | fake email は `example.com` 固定。CipherSweet の `whereBlind` 経路は変えない |
| SSRF | 外部 URL 取得を増やさない |
| OAuth state | fake は state を持たないが、アプリ層は state を参照しない。実経路（flag off）の state 照合は無変更で、`stateless()` 封鎖は施策 6 が resolver まで拡張する |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `AGENTS.md` / `docs/architecture.md` / `app/Providers/FakeExternalsServiceProvider.php` / `tests/Support/ExternalFakes/` / `tests/Support/ExternalSeam/` という**他の設計も触りやすい共有ファイル**を広く変更する。本設計と並列でもう 1 件の設計が走っており、incremental で重ねると衝突面が大きい。施策 1〜9 は互いに依存しており分割して段階マージする意味も薄い |
| 競合リスク | `AGENTS.md`（ドメイン規約 9）・`docs/architecture.md`（§外部到達点の目録）・`config/testing.php`・`scripts/bug-hunt-shard.sh`。いずれも編集は数行なので rebase で解消可能。`FakeExternalsServiceProvider` は method 追加のみで既存 method を触らない |

## 検証コマンド（全 green でコミット）

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
```

`pnpm` 系は本 PR で変更が無いが、レーン既定として実行する。
`composer test:browser` は UI 無変更のため必須ではない（回すなら任意）。
`scripts/bug-hunt-shard.sh self-test` も実行する（施策 8 の周辺を壊していないことの確認）。

---

## 関連する現行コード (抜粋)

### app/Http/Controllers/Auth/SocialAuthController.php (現行・全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Security\RecentAuthState;
use App\Services\Auth\SocialAccountService;
use App\Services\Onboarding\IntendedPlanResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Webmozart\Assert\Assert;

/**
 * SSO (Socialite) フロー。
 *
 * 開始導線は GET の anchor リンクであること (form POST にしない)。
 * form POST だと 302 リダイレクト先 (IdP) にも CSP form-action が適用され
 * ブロックされる (devnotes/20260611-template-extraction/14 §3)。
 *
 * intent (login / register / link / step-up) は session に保存し、callback で分岐する。
 * step-up は recent-auth (再認証) の SSO satisfier: ログイン済ユーザーが自分に連携済みの
 * provider で OAuth round-trip を完了すると RecentAuthState が鮮度を stamp する。
 */
class SocialAuthController extends Controller
{
    private const INTENTS = ['login', 'register', 'link', 'step-up'];

    public function __construct(
        private readonly IntendedPlanResolver $intendedPlanResolver,
    ) {}

    public function redirect(Request $request, string $provider, string $intent): RedirectResponse|SymfonyRedirectResponse
    {
        $this->ensureProviderEnabled($provider);
        abort_unless(in_array($intent, self::INTENTS, true), 404);

        if ($intent === 'link' || $intent === 'step-up') {
            abort_unless($request->user() !== null, 403);
        }

        // register のみ規約同意が必要 (query で受けて server 側でも検証)
        if ($intent === 'register' && $request->query('terms_accepted') !== '1') {
            return redirect()->route('register')
                ->withErrors(['terms_accepted' => '利用規約への同意が必要です。']);
        }

        // 料金表由来のプラン意図。register 開始では ?plan= を pending に書き換え (不在は forget)、
        // login 開始では常に forget する (前回中断の stale pending を次の登録へ持ち越さない)。
        // link / step-up は登録経路ではないため触らない。
        if ($intent === 'register') {
            $this->intendedPlanResolver->rememberPendingFromQuery($request);
        } elseif ($intent === 'login') {
            $this->intendedPlanResolver->forgetPending();
        }

        $request->session()->put('social_auth_intent', $intent);

        $driver = Socialite::driver($provider);

        // step-up は IdP に再認証を促す (OIDC 標準の prompt=login)。RP 側で auth_time は
        // 検証しない最小実装 (capability=fresh_auth_prompt_only)。対応しない provider では
        // 単に無視される。
        if ($intent === 'step-up' && method_exists($driver, 'with')) {
            $driver->with(['prompt' => 'login']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider, SocialAccountService $service, RecentAuthState $recentAuthState): RedirectResponse
    {
        $this->ensureProviderEnabled($provider);

        $intent = $request->session()->pull('social_auth_intent');
        if (! is_string($intent) || ! in_array($intent, self::INTENTS, true)) {
            return redirect()->route('login')->withErrors([
                'email' => 'ログインフローが無効です。もう一度お試しください。',
            ]);
        }

        $socialiteUser = Socialite::driver($provider)->user();

        if ($intent === 'step-up') {
            return $this->completeStepUp($request, $provider, $socialiteUser->getId(), $recentAuthState);
        }

        if ($intent === 'link') {
            $user = $request->user();
            Assert::isInstanceOf($user, User::class);
            $linked = $service->linkToUser($provider, $socialiteUser, $user);

            return $linked
                ? redirect()->route('settings.security')->with('success', 'アカウントを連携しました')
                : redirect()->route('settings.security')->withErrors([
                    'social' => 'このアカウントは既に別のユーザーに連携されています。',
                ]);
        }

        $linkedUser = $service->findLinkedUser($provider, $socialiteUser);

        if ($linkedUser !== null) {
            // login / register どちらの intent でも、連携済みならログイン扱い
            Auth::login($linkedUser, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        if ($intent === 'login') {
            // 未連携: 自動登録はしない (明示的な register 経由を要求)
            $this->intendedPlanResolver->forgetPending();

            return redirect()->route('login')->withErrors([
                'email' => 'このアカウントは登録されていません。新規登録からやり直してください。',
            ]);
        }

        // register: メール一致ユーザーへの自動リンクはしない (乗っ取り防止)。
        // 同一 email の既存ユーザーがいる場合は中立メッセージで弾く。
        $email = $socialiteUser->getEmail();
        if (is_string($email) && User::whereBlind('email', 'email_index', $email)->exists()) {
            // 登録拒否分岐: stale pending を残さない。
            $this->intendedPlanResolver->forgetPending();

            return redirect()->route('register')->withErrors([
                'email' => 'このメールアドレスではアカウントを作成できません。',
            ]);
        }

        $user = $service->register($provider, $socialiteUser);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // pending → 個人組織へ移送 (pending は必ず forget で消費される)。
        // 個人組織が無い (= 招待経由等) 場合は promote 対象が存在しないため pending だけ落とす。
        $personalOrganization = $user->organizations()->where('is_personal', true)->first();
        if ($personalOrganization instanceof Organization) {
            $this->intendedPlanResolver->promotePendingToOrganization($personalOrganization);
        } else {
            $this->intendedPlanResolver->forgetPending();
        }

        return redirect()->route('dashboard');
    }

    /**
     * recent-auth の SSO satisfier (step-up intent)。
     *
     * 本人性バインド: callback の provider_user_id が **現在ログイン中ユーザーの**連携済み
     * SocialAccount と一致した場合のみ鮮度を stamp する (他人のアカウントでの
     * round-trip 完了を成立させない)。成立後は RequireRecentAuth が保持した intended へ戻す。
     */
    private function completeStepUp(Request $request, string $provider, mixed $providerUserId, RecentAuthState $recentAuthState): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            // OAuth round-trip 中にセッションが切れた等。再ログインへ (fail-closed)。
            return redirect()->route('login');
        }

        $bound = $user->socialAccounts()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->exists();

        if (! $bound) {
            return redirect()->route('recent-auth.confirm')->withErrors([
                'password' => '現在ログイン中のアカウントに連携されたソーシャルアカウントで再認証してください。',
            ]);
        }

        $recentAuthState->confirm(method: 'sso', provider: $provider);

        // 302 fallback 経路で mutation を破棄していた場合は再操作を促す
        // (RequireRecentAuth の one-shot flag。password satisfier と同じ契約)。
        $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;

        $redirect = redirect()->intended(route('dashboard'));
        if ($droppedMutation) {
            $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
        }

        return $redirect;
    }

    private function ensureProviderEnabled(string $provider): void
    {
        abort_unless(
            array_key_exists($provider, config()->array('template.social_providers')),
            404,
        );
    }
}
```

### app/Providers/FakeExternalsServiceProvider.php (現行・全文)

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Support\FakeStorageGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス fake の配線 (系統別に capability flag を分離)。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
 *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
 *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
 *
 * fake 対象は 2 系統で capability flag も allowlist も異なる:
 * - 外部サービス (Stripe 課金 gateway + captcha 検証器): config('testing.fake_externals') が
 *   capability flag。container bind (per-test 隔離が効くため testing 可)。register() で配線。
 *   **SSO (Socialite) は fake しない** (差し替え先を作っていない。
 *   docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
 * - LLM (Prism): config('testing.fake_llm') が capability flag (fake_externals から分離)。
 *   Prompt::$fake は static (プロセスグローバル) のため testing/local を除外し bughunt.local のみ配線。
 *   bughunt 既定は real-llm (fake_llm off) で install しない。--fake-llm 時のみ install する。
 *   LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /**
     * 外部サービス fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可)。
     *
     * ★対象は **Stripe 課金 gateway と captcha 検証器**。SSO (Socialite) は fake しない
     *   (差し替え先を作っていない。docs/architecture.md §外部到達点の目録)。
     */
    private const array EXTERNAL_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

    public function register(): void
    {
        // capability ごとに独立 private method へ分離する (early return が他 capability を巻き込まない)。
        $this->registerExternalServiceFakes(); // Stripe + captcha: fake_externals 依存 (挙動不変)
        $this->registerStorageFakes();         // storage: fake_storage (FakeStorageGate) 依存 — 独立
    }

    public function boot(): void
    {
        $this->bootLlmFake();       // LLM: fake_llm 依存 (挙動不変)
        $this->bootStorageRoutes(); // storage signed route — 独立
    }

    /** 外部サービス fake (fake_externals + EXTERNAL_FAKE_ENVIRONMENTS。挙動不変) */
    private function registerExternalServiceFakes(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::EXTERNAL_FAKE_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
        $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);

        // captcha 到達点を fake へ rebind。
        // ★abstract が具象クラスのため、bind を消しても Laravel が本物を自動組み立てし、
        //   RECAPTCHA_SECRET_KEY が設定された瞬間に**無言で** Google siteverify を叩く。
        //   StrayHttpRequestGuard は bug-hunt の別プロセス実行には効かない (AGENTS.md)。
        $this->app->bind(RecaptchaVerifier::class, RecaptchaVerifierTestFake::class);
    }

    /** LLM (Prism) fake (fake_llm + LLM_FAKE_ENVIRONMENTS。挙動不変) */
    private function bootLlmFake(): void
    {
        // LLM fake は fake_llm (既定 false = real LLM) で判定する。bughunt 既定は real-llm で、
        // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
        // Stripe fake (register) は従来どおり fake_externals 依存で不変。
        if (config('testing.fake_llm') !== true) {
            return;
        }

        // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
        // per-test で static を占有する testing、実 API 検証を潰す local は allowlist から除外する。
        // LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
        // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
        if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
            return;
        }

        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
        $this->app->make(CannedPromptFakeRegistrar::class)->install();
    }

    /**
     * storage fake: FakeStorageGate 成立時のみ concrete → fake へ rebind (gate = predicate SSOT)。
     * env allowlist / production 拒否は gate に一元化される。
     */
    private function registerStorageFakes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        $this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);
        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);
    }

    /** storage fake の signed route (gate 成立時のみ。web CSRF group 外 = signed のみ) */
    private function bootStorageRoutes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        // 冪等化: boot() が複数回走っても (route:cache 併用・テストの provider 再実走等)
        // 同名 route を二重登録しない。通常の bootstrap では未登録 = そのまま登録される。
        if (Route::has('bughunt.storage.put')) {
            return;
        }

        Route::middleware('signed')->group(function (): void {
            Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
                ->name('bughunt.storage.put');
            Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
                ->name('bughunt.storage.get');
        });
    }
}
```

### tests/Support/ExternalFakes/ExternalFakeWiringInventory.php (現行・全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Support\FakeStorageGate;

/**
 * 外部 fake の container 差し替え inventory (deny-by-default の正本)。
 *
 * 責務境界:
 * - 本 inventory と ExternalFakeWiringInvariantTest が見るのは **非本番側の配線**だけ。
 * - **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = production:preflight /
 *   起動時 = AppServiceProvider::boot の 2 経路) + `tests/Feature/Support/ProductionEnvGuardTest`。
 *   ここで二重実装しない。
 * - LLM (Prism) fake は container ではなく `Prompt::$fake` (プロセスグローバル static) を書き換える
 *   ため inventory の対象外 (ExternalFakeWiringInvariantTest の 3-11 が別枠で見る)。
 */
final class ExternalFakeWiringInventory
{
    /** 外部サービス fake (Stripe 課金 + captcha) の capability flag */
    public const string EXTERNALS_FLAG = 'testing.fake_externals';

    /** storage fake の capability flag */
    public const string STORAGE_FLAG = 'testing.fake_storage';

    /** LLM fake の capability flag (container 差し替えではないため bindings() には現れない) */
    public const string LLM_FLAG = 'testing.fake_llm';

    /** 外部サービス fake の env allowlist (FakeExternalsServiceProvider::EXTERNAL_FAKE_ENVIRONMENTS と対) */
    private const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** storage fake の env allowlist (FakeStorageGate の predicate と対。testing は runningUnitTests 前提) */
    private const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /**
     * fake の実体ではないが FakeExternalsServiceProvider が参照してよい配線基盤クラス。
     *
     * 「provider が参照する fake 系クラス = bindings() の fake ∪ 本集合」を集合一致で検査するため、
     * ここに載っていないクラスを provider が参照した時点で gate が赤くなる。
     *
     * @return list<class-string>
     */
    public static function providerReferenceExceptions(): array
    {
        return [
            // LLM static fake の install 窓口 (container 配線を行わない)
            CannedPromptFakeRegistrar::class,
            // storage fake の有効化 predicate (SSOT。container 配線を行わない)
            FakeStorageGate::class,
            // fake storage signed route の受け口 (route action。container 配線を行わない)
            PutFakeStorageObjectController::class,
            GetFakeStorageObjectController::class,
        ];
    }

    /**
     * container 差し替えの全宣言。
     *
     * ここに entry を足すと、ExternalFakeWiringInvariantTest の data-driven 検査
     * (対照 / 実証 / allowlist 外) が自動的に増える = 書き忘れが構造的に起きない。
     *
     * ⚠️ 新 entry を足す実装者へ: Architecture lane は RefreshDatabase を使わない。
     * abstract / real / fake の constructor が DB に触れないことを必ず確認すること
     * (現行 5 本は確認済み)。
     *
     * @return list<ExternalFakeBinding>
     */
    public static function bindings(): array
    {
        return [
            new ExternalFakeBinding(
                abstract: TicketCheckoutGateway::class,
                real: CashierTicketCheckoutGateway::class,
                fake: FakeTicketCheckoutGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'チケットスポット購入の Stripe Checkout。配線が外れると実 Stripe に実課金セッションを作る。',
            ),
            new ExternalFakeBinding(
                abstract: StripeGatewayInterface::class,
                real: CashierStripeGateway::class,
                fake: FakeStripeGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'サブスク Checkout / Customer Portal。配線が外れると実 Stripe に契約を作る。',
            ),
            new ExternalFakeBinding(
                abstract: AutoRechargeGatewayInterface::class,
                real: CashierAutoRechargeGateway::class,
                fake: FakeAutoRechargeGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'オートリチャージの off-session invoice。配線が外れると実カードへ請求が飛ぶ。',
            ),
            new ExternalFakeBinding(
                abstract: TakeObjectStorage::class,
                real: TakeObjectStorage::class,
                fake: FakeTakeObjectStorage::class,
                flag: self::STORAGE_FLAG,
                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
                risk: '撮影テイクの S3 presign / HeadObject。abstract が具象クラスのため、'
                    .'bind を消しても Laravel が本物を自動組み立てして無音で実 S3 を叩く。',
            ),
            new ExternalFakeBinding(
                abstract: RenderObjectStorage::class,
                real: RenderObjectStorage::class,
                fake: FakeRenderObjectStorage::class,
                flag: self::STORAGE_FLAG,
                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
                risk: 'レンダ出力の S3 read/write。TakeObjectStorage と同じく具象クラス起点で無音になる。',
            ),
            new ExternalFakeBinding(
                abstract: RecaptchaVerifier::class,
                real: RecaptchaVerifier::class,
                fake: RecaptchaVerifierTestFake::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'Google reCAPTCHA siteverify への外向き POST。abstract が具象クラスのため、'
                    .'bind を消しても Laravel が本物を自動組み立てし、RECAPTCHA_SECRET_KEY が'
                    .'設定された環境では無言で実 Google を叩く (bug-hunt の別プロセスには '
                    .'StrayHttpRequestGuard が効かない)。',
            ),
        ];
    }
}
```

### vendor/laravel/socialite/src/SocialiteServiceProvider.php (現行・全文)

```php
<?php

namespace Laravel\Socialite;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory;

class SocialiteServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Factory::class, function ($app) {
            return new SocialiteManager($app);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [Factory::class];
    }
}
```

### tests/Feature/Security/ThrottleExemptionPremiseTest.php (stateless 走査の該当部)

```php
});

test('SocialAuthController は stateless() を使わない (state 照合を無効化する最短経路の封鎖)', function (): void {
    // ソース走査は**補助**。単独の根拠にはしない (上の実挙動テストが本体)。
    // stateless() 化は state 照合を丸ごと無効化する最短経路なので二重に塞ぐ。
    $source = file_get_contents(app_path('Http/Controllers/Auth/SocialAuthController.php'));
    expect($source)->toBeString();
    expect($source)->not->toContain('stateless(');
});
```

### config/testing.php (現行の fake_externals 節)

```php
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 外部サービス fake 化の capability flag
    |--------------------------------------------------------------------------
    |
    | fake_externals: **外部サービス fake の capability flag** (既定 false = no-op)。
    | true のとき FakeExternalsServiceProvider::register() が以下を fake 実装へ bind する:
    |   - Stripe 課金 gateway (checkout / portal / auto-recharge)
    |   - captcha 検証器 (RecaptchaVerifier → RecaptchaVerifierTestFake)
    | **SSO (Socialite) は fake しない** (差し替え先を作っていない。
    |  bug-hunt のブラウザは SSO ボタンから実 IdP へ遷移する。
    |  docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
    | 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
    | 既定 false = 本 flag 未設定の環境では完全 no-op。
    |
    | ※ LLM (Prism) fake はこの flag から分離され fake_llm が capability flag。
    |
    */

    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),

    /*
    |--------------------------------------------------------------------------
```
