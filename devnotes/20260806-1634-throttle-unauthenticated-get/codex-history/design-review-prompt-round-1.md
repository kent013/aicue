# レビュー依頼: 詳細設計 (throttle-unauthenticated-get)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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
8. 波及変更の網羅性
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠 / Atomic Design準拠（UI/frontend 変更を含む場合。本件は frontend 無変更）

【本件固有の重点】
- 本件は deny-by-default の Architecture gate (流量制限の付与漏れ検査) の母集団拡張である。
  「機械が検出できない状態を作らないこと」が最重要。抜け穴があれば必ず指摘せよ。
- 新規に throttle を貼る 5 本は**既存ユーザーの振る舞いを変える**。壊れ方を見よ。
- exemption 14 件の理由文が、その route の実装と食い違っていないかを確認せよ
  (リポジトリの実ファイルは読んでよい)。
- 施策 6 / 8 / 9 のテストコード断片に、PHPStan level 10 で落ちる書き方や
  Pest/Laravel の API 誤用が無いか確認せよ。

【関連する現行コードの所在】(必要なら読むこと)
- tests/Architecture/ThrottleCoverageInventoryTest.php
- tests/Architecture/RateLimiterKeyConventionTest.php
- tests/Feature/Security/ThrottleExemptionPremiseTest.php
- tests/Feature/Security/AuthThrottleCoverageTest.php
- tests/Feature/Security/NamedRateLimiterKeyTest.php
- app/Support/Http/RouteThrottleBinder.php
- app/Enums/Security/ThrottleCoverageExemption.php
- app/Providers/AppServiceProvider.php / app/Providers/FortifyServiceProvider.php
- routes/web.php
- app/Http/Controllers/Auth/SocialAuthController.php
- app/Http/Controllers/Auth/ConfirmRecentAuthController.php
- app/Http/Controllers/Organizations/InvitationAcceptanceController.php
- vendor/laravel/fortify/routes/routes.php

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: 未認証 GET の認証面を流量制限の母集団へ (throttle-unauthenticated-get)

概念設計: [`conceptual-design.md`](./conceptual-design.md)

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

**本タスクの位置づけ**: 使命に直接寄与する機能ではなく、使命を支える
**セキュリティ不変条件「流量制限 (throttle) の付与規約」の gate 是正**である。
SSO ログインと組織招待は現場導入の主動線であり、そこが未認証で無制限に踏める状態は
可用性の問題として直接ユーザーに跳ね返る。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

**本タスクで特に効く禁止事項**: 1 (テストなしの完了報告)。
不変条件の変更なので、**Architecture / Feature テストへの登録まで含めて「実装済み」**である。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。型の widen / baseline 禁止
- **Pest**。`RefreshDatabase` は `tests/Pest.php` でグローバル適用済 (個別 `DatabaseTransactions` 禁止)
- テストデータは Factory 生成 (`Model::create()` 手組み禁止)
- `declare(strict_types=1)` + 日本語コメント
- `composer fix` (Pint) / `vendor/bin/pint --test`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### 本タスク固有の規約 (AGENTS.md §流量制限の付与規約)

- named limiter のキーは **`{レーン}:{種別}:{値}`**
- **inline throttle (`throttle:6,1`) は「認証済みかつ actor 自身に閉じる操作」限定**。
  未認証面は必ず named limiter
- **閾値は既存値を変えない**。新しい面には本番稼働中の同性質エンドポイントと同値を充てる
- `RateLimiter::for()` の第 1 引数は**必ずリテラル**でベタ書き (ループ登録しない。
  `RateLimiterRegistrationScanner` が非リテラルを deny-by-default で fail させる)
- vendor 登録 route への後付けは `RouteThrottleBinder::attachOnBooted()` 経由
- **limiter キーに route parameter を入れない** (`NamedRateLimiterKeyTest`)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | S3 セレクタから `$isMutating` を外す + floor 更新 | `tests/Architecture/ThrottleCoverageInventoryTest.php` | 必須 (最初に単独で当てて fail を見る) |
| 2 | named limiter 2 本の新設 | `app/Providers/AppServiceProvider.php` | 必須 |
| 3 | 自前 route 2 本への throttle 付与 | `routes/web.php` | 必須 |
| 4 | Fortify route 3 本への throttle 後付け | `app/Providers/FortifyServiceProvider.php` | 必須 |
| 5 | exemption enum に case 2 つ追加 | `app/Enums/Security/ThrottleCoverageExemption.php` | 必須 |
| 6 | exemption inventory 14 件追加 + cap 更新 + 検査 2 本追加 | `tests/Architecture/ThrottleCoverageInventoryTest.php` | 必須 |
| 7 | limiter キー規約テストへの登録 | `tests/Architecture/RateLimiterKeyConventionTest.php` | 必須 |
| 8 | 新 throttle 5 本の behavioral proof | `tests/Feature/Security/AuthThrottleCoverageTest.php` | 必須 |
| 9 | 新 exemption case の前提 proof | `tests/Feature/Security/ThrottleExemptionPremiseTest.php` | 必須 |
| 10 | ドキュメント更新 | `docs/app-integration-guide.md` | 必須 |

**実装順序 (テストファースト)**: 1 → (fail を確認) → 5 → 2 → 3 → 4 → 6 → 7 → 8 → 9 → 10

---

## 施策 1: S3 セレクタから `$isMutating` を外す + floor 更新

### 変更箇所

- `tests/Architecture/ThrottleCoverageInventoryTest.php` (L45-49, L193-194)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイル自身。施策 6 と同一ファイルだが**別コミットに分ける**
  (fail を観測するため)

### 現行コード

```php
/** 母集団件数の下限 (空振り drift ガード。実測 47 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 40;
}
```

```php
        // S3: 認証済み側も含む credential 面
        $s3 = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;
```

### 変更後コード

```php
/** 母集団件数の下限 (空振り drift ガード。実測 70 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 60;
}
```

```php
        // S3: credential 面 (認証済み側も含む)。
        // ★**メソッドを問わない** (GET/HEAD も母集団に入れる)。
        //   認証面は「読むだけ」の GET でも秘密の開示・外部呼び出し・状態生成を伴いうる。
        //   $isMutating を条件に残していた頃は認証面 GET が 1 本も母集団に入らず、
        //   パターン中の `social\.` は 1 件も一致しない**死んだ条件**だった
        //   (social route は 2 本とも GET)。
        // ★S1 (未認証の変更系) は $isMutating のまま残す。S1 まで GET へ広げると
        //   母集団が数百本になり、exemption 台帳に埋もれて gate が機能しなくなる。
        $s3 = $name !== '' && preg_match($pattern, $name) === 1;
```

### この時点で観測すべき fail (テストファーストの証拠)

```
composer test -- --filter=ThrottleCoverageInventoryTest
```

→ 「保護対象 route の throttle 付与が不正です」に **19 本**が列挙されて fail する。
(増分 23 本 - 既に throttle 済み 4 本 = `verification.verify` / `passkey.login-options` /
`passkey.confirm-options` / `passkey.registration-options`)

**この fail 出力をコミットメッセージか devnotes に貼ってから分類作業に入ること。**

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`int` / `bool`)
- [x] null 安全 (変更なし)
- [x] DTO 返却 (対象外)
- [x] Generics (対象外)

### リスク

- floor を 60 にすると、`Features::twoFactorAuthentication()` / `Features::passkeys()` を
  同時に無効化した場合に fail しうる。**それは意図した挙動** (母集団が縮んだら気づきたい)。

---

## 施策 2: named limiter 2 本の新設

### 変更箇所

- `app/Providers/AppServiceProvider.php` (L236-240 の呼び出し + 新 private メソッド)

### 波及変更

- テストファイル: `tests/Architecture/RateLimiterKeyConventionTest.php` (施策 7 で必須登録)
- TypeScript 型定義 / DTO: なし

### 変更後コード

`boot()` の限定登録ブロックに 1 行追加:

```php
        $this->configureApiRateLimiters();
        $this->configureAuthSurfaceRateLimiters();   // ★追加
        $this->configureInquiryRateLimiter();
        $this->configureRenderRateLimiter();
        $this->configureWebhookRateLimiters();
```

新規メソッド (`configureApiRateLimiters()` の直前あたりに置く):

```php
    /**
     * 未認証で到達する認証面 GET の RateLimiter (T120 事後監査の是正)。
     *
     * ★どちらも**未認証**面のため named limiter で数える単位を明示する。
     *   inline throttle (`10,1`) はフレームワーク既定キーに依存するため、
     *   AGENTS.md の規約どおり「認証済みかつ actor 自身に閉じる操作」以外では使わない。
     *
     * ★閾値は発明しない (AG-096 = 閾値はプロダクト依存):
     *   - social-callback  = 10/min。未認証で到達する認証面の IP レーンとして
     *     本番稼働中の `passkeys` limiter の guest 分岐 (10/min) と同値。
     *   - invitation-accept = 10/min。姉妹操作 invitations.accept.store の
     *     `throttle:10,1` と同値 (同じ token 照合を行う 2 本の非対称を解消する)。
     *
     * ★キーに route parameter / query token を混ぜない (NamedRateLimiterKeyTest)。
     *   social.callback の {provider} や invitations.accept の ?token= を key に入れると
     *   bucket が分かれ、「429 になるまでの回数」が実在オラクルになる。
     *
     * ★巻き添えの扱い: IP レーンである以上、同一 NAT 配下の一斉ログイン / 一斉招待受諾は
     *   巻き添え 429 になりうる。limiter は恒久ロックを作らないが到達は保証しない。
     *   運用は 429 発生率を監視し、**初動は閾値変更ではなく TRUSTED_PROXIES / 実 client IP の
     *   解決の確認**とする (docs/trusted-proxies-runbook.md)。
     */
    private function configureAuthSurfaceRateLimiters(): void
    {
        // SSO callback。1 リクエストで IdP へ token エンドポイント POST が飛びうる
        // (state + intent が揃った場合)。未認証で外部へ HTTP を発射できる唯一の経路。
        RateLimiter::for('social-callback', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('social-callback:ip:'.($request->ip() ?? 'unknown')));

        // 招待受諾の確認画面 (GET)。未認証入力の token を sha256 照合して DB を 1 件引き、
        // 有効/無効で応答が分岐する。姉妹の POST は既に throttle:10,1 で有界化されている。
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('invitation-accept:ip:'.($request->ip() ?? 'unknown')));
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示 (`fn (Request $request): Limit` / メソッドは `void`)
- [x] null 安全 — `$request->ip()` は `?string` なので `?? 'unknown'` で潰す
      (既存 `webhook-ses` / `oauth-register` と同じ書き方。型を緩めない)
- [x] DTO 返却 (対象外)
- [x] Generics (対象外)

### リスク

- `RateLimiter::for()` の第 1 引数をリテラルで書かないと
  `RateLimiterRegistrationScanner` が `unresolved` として fail する。**必ずベタ書き**。

---

## 施策 3: 自前 route 2 本への throttle 付与 (第 1 段)

### 変更箇所

- `routes/web.php` L164-165 (`social.callback`) / L600-601 (`invitations.accept`)

### 波及変更

- テストファイル: `tests/Feature/Security/AuthThrottleCoverageTest.php` (施策 8)
- frontend: なし (429 の UI 表現は別 feature `error-response-contract`)

### 現行コード

```php
Route::get('/auth/{provider}/redirect/{intent}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->name('social.callback');
```

```php
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.accept');
```

### 変更後コード

```php
Route::get('/auth/{provider}/redirect/{intent}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
// callback は SocialAuthController::callback() 内の Socialite::driver()->user() で
// **IdP への外向き HTTP** が起きる (未認証で外部へ HTTP を発射できる唯一の経路)。
// 未認証面のため named limiter で IP レーンを明示する (閾値は passkeys guest と同値)。
// redirect 側は外向き通信をしないため throttle を貼らず、exemption
// (AuthFlowInitiationWithoutOutboundCall) として理由を inventory に残す。
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:social-callback')
    ->name('social.callback');
```

```php
// GET も token を sha256 照合して DB を 1 件引き、有効/無効で応答が分岐する
// (未認証で観測できる分、姉妹の POST より攻撃面として広い)。
// POST 側の `10,1` と同値にする。未認証面のため named limiter でキーを明示する。
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:invitation-accept')
    ->name('invitations.accept');
```

### PHPStan 適合チェック

- [x] 型 (route 定義のため対象外)

### リスク

- `social.callback` は `{provider}` を持つが、throttle は `SubstituteBindings` より前に走り
  limiter が provider を読まないため、存在オラクルにはならない。
- 巻き添え: §概念設計 §10-1。

---

## 施策 4: Fortify route 3 本への throttle 後付け (第 3 段)

### 変更箇所

- `app/Providers/FortifyServiceProvider.php` L155-168 (`throttledFortifyRoutes()`)

### 波及変更

- テストファイル: `tests/Feature/Security/AuthThrottleCoverageTest.php` (施策 8)

### 現行コード

```php
            'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
        ];
```

### 変更後コード

```php
            'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            // ★秘密を返す GET 3 本 (T120 事後監査の是正)。
            //   いずれも auth 配下で **actor 自身の 2FA 秘密**しか返さないため、
            //   inline (`10,1`) の適用条件「認証済みかつ actor 自身に閉じる操作」を満たし、
            //   フレームワーク既定キー (user id) がちょうど求める数える単位になる。
            //   閾値は姉妹の enable / confirm / disable / regenerate と同値 (新しい値を発明しない)。
            //
            //   ★これは **連続取得の回数上限**であって、秘密の漏えい防止でも
            //     step-up (再認証) の代替でもない。「throttle を貼ったから秘密 GET の保護は
            //     済んだ」と読まないこと。認証強度 (recent-auth 化) は
            //     aicue:T120 の後続 TODO B2 の担当であり、本付与では 1 mm も進んでいない。
            'two-factor.qr-code' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.secret-key' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
        ];
```

### 注意 (実装者向け)

- `two-factor.recovery-codes` は **GET** の route 名で、POST は
  `two-factor.regenerate-recovery-codes`。別 route なので二重付与にはならない
  (`vendor/laravel/fortify/routes/routes.php:170,174`)。
- `two-factor.recovery-codes` には `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()`
  が `recent-auth` を後付けしている。**throttle は `recent-auth` より先に走る**
  (`bootstrap/app.php` の priority list)。既存テスト
  「2FA 管理 route は throttle が recent-auth より先に走る」の対象に GET も加える (施策 8)。
- `RouteThrottleBinder::attachByName()` は既存 throttle があれば期待値と比較して
  一致すれば no-op、不一致なら **起動時に RuntimeException** で止まる。
  Fortify が将来この 3 本に limiter を付けたら起動が止まり、そこで気づける (fail-fast)。

### PHPStan 適合チェック

- [x] 戻り値の型 (`array<string, array{throttle: string, feature: string|null}>` のまま)

### リスク

- 2FA 設定画面は `qr-code` と `secret-key` を同一表示で 2 回叩く実装なら、
  リロード 5 回で上限に達する。**actor 自身のバケットであり他者への影響はゼロ**。
  施策 8 の behavioral テストで「初期表示 1 回が 2 消費」であることを固定する。

---

## 施策 5: exemption enum に case 2 つ追加

### 変更箇所

- `app/Enums/Security/ThrottleCoverageExemption.php` (末尾に追加)

### 波及変更

- テストファイル: `tests/Architecture/ThrottleCoverageInventoryTest.php` (施策 6) /
  `tests/Feature/Security/ThrottleExemptionPremiseTest.php` (施策 9)

### 変更後コード (追加分のみ)

```php
    /**
     * 認証面の非変更系 (GET/HEAD) で、応答が画面 / ステータスの描画にすぎない route。
     *
     * 適用条件 (すべて満たすこと):
     *  - HTTP メソッドが GET/HEAD のみ (変更系には適用しない)
     *  - 外部呼び出し・メール送信・重い計算・**DB 書込**を伴わない (DB read は可)
     *  - 推測可能な秘密を開示しない
     *    (自セッションが既に保持する情報の再表示・自分が提示した token の prefill は可)
     *  - 副作用が自セッション (CSRF token / flash / 汚染値の除去) の中に閉じる
     *
     * ★credential の検証・生成が同 URI の変更系側にある場合は、その変更系が
     *   throttle か exemption のどちらかで分類済みであることまで確認して使う。
     */
    case AuthViewRenderOnly = 'auth_view_render_only';

    /**
     * 認証フローを開始するが、その場では外向き通信を一切行わない非変更系 route。
     *
     * 適用条件 (すべて満たすこと):
     *  - HTTP メソッドが GET/HEAD のみ
     *  - **その場で外向き HTTP を発行しない** (発行するのは対になる完了経路)
     *  - 生成する状態が自セッション内に閉じ、他セッションから消費できない
     *  - **対になる完了経路が throttle 済みである** (増幅はそちらで有界化されている)
     *
     * ★4 番目の条件が本 case の要である。完了経路の throttle を外すと
     *   この exemption の前提が崩れるため、ThrottleExemptionPremiseTest が
     *   完了経路の throttle 実在と limiter 名を behavioral に固定する。
     */
    case AuthFlowInitiationWithoutOutboundCall = 'auth_flow_initiation_without_outbound_call';
```

### PHPStan 適合チェック

- [x] backed enum の値は `string` 固定

---

## 施策 6: exemption inventory 14 件追加 + cap 更新 + 検査 2 本追加

### 変更箇所

- `tests/Architecture/ThrottleCoverageInventoryTest.php`
  (L51-55 cap / L63-130 inventory / 末尾へテスト 2 本追加 + 新関数 1 本)

### 6-a. cap の更新

```php
/** exemption 件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function throttleCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。exact fit なら次の 1 本が必ず「この数値を変える差分」
    //   として現れ、個別理由・前提テスト追加要否・そもそも貼るべきでないかの
    //   再検討を強制できる。上げる前に必ず再検討すること。
    return 25;
}

/**
 * exemption の case 別上限 (分類の偏り検出)。全体 cap とは役割が違う
 * (全体 = セレクタの広さ / case 別 = どのカテゴリが膨らんだか)。
 * ★array_sum() で全体 cap を導出しない (両方を独立に検査する)。
 *
 * @return array<string, int> ThrottleCoverageExemption::value => 上限
 */
function throttleCoverageExemptionCapByCase(): array
{
    return [
        ThrottleCoverageExemption::StaticMetadataResponse->value => 4,
        ThrottleCoverageExemption::VendorMethodNotAllowedStub->value => 2,
        ThrottleCoverageExemption::SessionTeardownOnly->value => 2,
        ThrottleCoverageExemption::LocalOnlyDebugRoute->value => 1,
        ThrottleCoverageExemption::ComponentLevelLimiter->value => 1,
        ThrottleCoverageExemption::SignatureRequiredBeforeEffect->value => 1,
        // ★ここが膨らむ = 「貼るべき route を描画系として逃がした」疑い。
        ThrottleCoverageExemption::AuthViewRenderOnly->value => 13,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall->value => 1,
    ];
}
```

### 6-b. inventory への 14 件追加

`throttleCoverageExemptions()` の先頭 alias 群に 2 行追加:

```php
    $render = ThrottleCoverageExemption::AuthViewRenderOnly;
    $flowInit = ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall;
```

既存 11 件の**後ろ**に、以下 14 件を追加する (**既存 11 件は 1 文字も触らない**)。
理由はすべて 30 文字以上 (`throttleCoverageReasonMinLength()`)。

```php
        // ─────────────────────────────────────────────────────────────
        // 認証面の非変更系 GET (T120 事後監査の是正で母集団に加わった 23 本のうち、
        // throttle を貼らないことが正しいと裁定した 14 本)。
        // 判断基準は「1 リクエストで外向き通信・重い計算・状態生成が起きるか」。
        // ─────────────────────────────────────────────────────────────

        'login' => [$render,
            'Fortify::loginView() が config(template.social_providers) のキー一覧だけを props にした '
            .'Inertia ページ (Auth/Login) を描画する。credential 検証は POST /login '
            .'(throttle:login) 側にあり、GET は DB 書込・外部呼び出し・メール送信を伴わない。'],

        'register' => [$render,
            'Fortify::registerView() の Inertia 描画。session に**自分で置いた** invitation_token が '
            .'ある場合のみ OrganizationMembershipService::resolveRegisterPrefillEmail() が招待を '
            .'1 件 read するが、token を持たない要求は DB へ到達しない。DB 書込・外部呼び出しは無い。'],

        'password.request' => [$render,
            'Fortify::requestPasswordResetLinkView() が props 無しの Inertia ページ '
            .'(Auth/ForgotPassword) を描画するだけ。メール送信は POST /forgot-password '
            .'(throttle:password-reset-request) 側で、GET は DB にも外部にも触れない。'],

        'password.reset' => [$render,
            'Fortify::resetPasswordView() が route parameter の token と query の email を props へ '
            .'写すだけの Inertia 描画。token の DB 照合は POST /reset-password '
            .'(throttle:password-reset-submit) 側で行われ、GET は token の有効性を判定しない '
            .'(応答が token に依存しないためオラクルにならない)。'],

        'two-factor.login' => [$render,
            'Fortify の TwoFactorAuthenticatedSessionController::create() が session の login.id に '
            .'対応する user の存在を read し、無ければ login へ 302 する。コード検証は '
            .'POST /two-factor-challenge (throttle:two-factor) 側。DB 書込・外部呼び出しは無い。'],

        'password.confirm' => [$render,
            'FortifyServiceProvider::configureViews() が confirmPasswordView を '
            .'recent-auth.confirm への 302 に差し替えており、応答は redirect 1 本のみ。'
            .'DB アクセス・外部呼び出し・秘密の開示を一切伴わない。'],

        'password.confirmation' => [$render,
            'Fortify の ConfirmedPasswordStatusController::show() が session の '
            .'auth.password_confirmed_at と設定値を比較した bool を返すだけ。auth 必須で '
            .'actor 自身の session 状態しか見ず、DB にも外部にも触れない。'],

        'recent-auth.confirm' => [$render,
            'auth 必須。ConfirmRecentAuthController::show() が actor 自身の recent-auth 鮮度と '
            .'利用可能な satisfier を props にした Inertia 描画を返す。password 検証は '
            .'POST /recent-auth/password (throttle:6,1) 側にあり、GET は DB 書込を伴わない。'],

        'recent-auth.status' => [$render,
            'auth 必須の軽量プローブ。ConfirmRecentAuthController::status() が actor 自身の鮮度を '
            .'JsonResource で返し no-store を付けるだけで、DB 書込・外部呼び出し・'
            .'秘密の開示を伴わない (bfcache 再検証のため頻繁に叩かれる前提の endpoint)。'],

        'verification.notice' => [$render,
            'auth 必須。Fortify::verifyEmailView() が EmailVerificationContinuation::hasContinuation() '
            .'の bool だけを props にした Inertia 描画を返す。検証メールの再送は '
            .'POST /email/verification-notification (throttle:6,1) 側で有界化されている。'],

        'filament.admin.auth.login' => [$render,
            'Filament panel のログインページ描画。credential 検証は Livewire の POST '
            .'(default-livewire.update) 側にあり、そこは ComponentLevelLimiter として登録済みで '
            .'Auth/Pages/Login の rateLimit(5) が実在する (ThrottleExemptionPremiseTest が固定)。'],

        'filament.admin.auth.profile' => [$render,
            'auth 必須の Filament プロフィールページ描画。パスワード変更等の実処理は Livewire POST '
            .'(default-livewire.update) 側にあり ComponentLevelLimiter で分類済み。'
            .'GET は actor 自身のフォーム描画のみで、秘密の生成も外部呼び出しも伴わない。'],

        'filament.admin.auth.multi-factor-authentication.set-up-required' => [$render,
            'auth 必須の Filament MFA 設定要求ページ描画 (SetUpRequiredMultiFactorAuthentication)。'
            .'MFA 秘密の生成・登録は Livewire POST (default-livewire.update) 側の action で行われ '
            .'ComponentLevelLimiter で分類済み。GET はプロバイダ一覧と導線の描画のみ。'],

        'social.redirect' => [$flowInit,
            'SocialAuthController::redirect() は provider allowlist (config) と intent を検証し、'
            .'session へ intent と OAuth state を書いて IdP へ 302 するだけで、**その場では '
            .'外向き HTTP を発行しない**。外向き HTTP は対になる social.callback で起き、'
            .'そちらは throttle:social-callback で有界化されている (前提は Premise テストが固定)。'],
```

### 6-c. 検査 2 本の追加 (既存テストの構造的な穴を塞ぐ)

```php
test('exemption inventory の key は throttle を 1 本も持たない (死んだ exemption の検出)', function (): void {
    // ★既存の「ちょうど 1 本 or exemption」検査は count($entries) === 1 で先に continue するため、
    //   *throttle 済みなのに exemption にも登録されている* 状態を検出できない。
    //   stale 検出も「母集団に存在するか」しか見ないため素通りする。
    //   放置すると「もう不要な免除理由」が台帳に溜まり、次に読む人を誤らせる。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $inventory = throttleCoverageExemptions();
    $violations = [];

    foreach (throttleCoverageProtectedRoutes() as $route) {
        $label = throttleCoverageRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }

        $entries = RouteThrottleBinder::throttleEntries($router, $route);
        if ($entries !== []) {
            $violations[] = "{$label}: throttle ({$entries[0]}) が付いているのに exemption にも登録されています";
        }
    }

    expect($violations)->toBe([],
        'throttle を貼ったら exemption inventory から削除してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('認証面 GET 用の exemption case は非変更系 route にしか使われない', function (): void {
    // AuthViewRenderOnly / AuthFlowInitiationWithoutOutboundCall の適用条件 1 番目
    // (GET/HEAD のみ) を機械化する。変更系がこの箱に落ちると、
    // 「描画だから」という理由で副作用のある route が免除される。
    $getOnlyCases = [
        ThrottleCoverageExemption::AuthViewRenderOnly,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall,
    ];
    $mutating = throttleCoverageMutatingMethods();
    $inventory = throttleCoverageExemptions();
    $violations = [];

    foreach (throttleCoverageProtectedRoutes() as $route) {
        $label = throttleCoverageRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }
        if (! in_array($inventory[$label][0], $getOnlyCases, true)) {
            continue;
        }
        if (array_intersect($mutating, $route->methods()) !== []) {
            $violations[] = "{$label}: 変更系 (".implode('|', $route->methods()).') に GET 専用 case が使われています';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
    $caps = throttleCoverageExemptionCapByCase();
    $counts = [];
    foreach (throttleCoverageExemptions() as [$exemption, $reason]) {
        $counts[$exemption->value] = ($counts[$exemption->value] ?? 0) + 1;
    }

    $violations = [];
    foreach ($counts as $case => $count) {
        $cap = $caps[$case] ?? null;
        if ($cap === null) {
            $violations[] = "{$case}: throttleCoverageExemptionCapByCase() に上限が登録されていません";

            continue;
        }
        if ($count > $cap) {
            $violations[] = "{$case}: {$count} 件 (上限 {$cap})";
        }
    }

    expect($violations)->toBe([],
        'exemption の case 別件数が上限を超えました。上限を上げる前に、'
        .'その case へ落とした route が本当に throttle 不要かを 1 本ずつ再検討してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

> **注意**: `throttleCoverageExemptionCapByCase()` は「上限が未登録の case」も fail させる
> (新 case を足したら上限も同時に決めさせる = deny-by-default)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示 (`array<string, int>` / `void`)
- [x] `$inventory[$label][0]` は `array{ThrottleCoverageExemption, string}` の 0 番目で型が確定
- [x] `$caps[$case] ?? null` の null 分岐を明示 (widen しない)
- [x] DTO 返却 (テストのため対象外)

### リスク

- 新規 3 テストは既存 5 テストと同一ファイルに増える (合計 8 本)。
  ファイルが長くなるが、判定関数を共有しており分割すると母集団定義が二重化するため
  同一ファイルに置く。

---

## 施策 7: limiter キー規約テストへの登録

### 変更箇所

- `tests/Architecture/RateLimiterKeyConventionTest.php` (`rateLimiterKeyInventory()`)

### 波及変更

- **必須**。登録しないと「scan で検出した limiter 名の集合が inventory と完全一致する」が fail する。

### 変更後コード (追加分)

```php
        'social-callback' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['social-callback:ip'],
            'emailScenarios' => [],
        ],
        'invitation-accept' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['invitation-accept:ip'],
            'emailScenarios' => [],
        ],
```

`webhook-ses` / `oauth-register` と同じ形 (guest シナリオのみ、IP レーン 1 本)。

### PHPStan 適合チェック

- [x] 既存 inventory と同一の shape (`array{scenarios: ..., expectedKeyPrefixes: list<string>, emailScenarios: list<string>}`)

---

## 施策 8: 新 throttle 5 本の behavioral proof

### 変更箇所

- `tests/Feature/Security/AuthThrottleCoverageTest.php` (末尾に追加)

### テスト計画

| # | テスト名 (日本語) | 検証内容 |
|---|------------------|---------|
| 8-1 | `GET /auth/{provider}/callback は 10 回目まで通り 11 回目で 429 (IP レーン 10/min)` | `social-callback` の閾値と数える単位。**外向き HTTP を起こさない状態** (intent 無し = login へ 302) で叩き、throttle が controller より前で数えていることを示す |
| 8-2 | `social.callback は provider を変えても同じ bucket を消費する (存在オラクル不成立)` | limiter キーに route parameter が混ざっていないこと。`X-RateLimit-Remaining` が連続して減ることで示す (`NamedRateLimiterKeyTest` と同じ方式) |
| 8-3 | `GET /invitations/accept は 10 回目まで通り 11 回目で 429` | `invitation-accept` の閾値 |
| 8-4 | `invitations.accept は token を変えても同じ bucket を消費する (token 総当りが有界)` | query token がキーに混ざっていないこと。**混ざっていたら総当りが有界にならない** |
| 8-5 | `2FA 秘密を返す GET 3 本は 10,1 で有界 — これは回数上限であって認証強度ではない (認証強度は後続 TODO B2)` | `two-factor.qr-code` / `.secret-key` / `.recovery-codes` に throttle が**ちょうど 1 本**あり、`ThrottleRequests:10,1` であること。**テスト名に誤読防止の一文を入れる** |
| 8-6 | 既存テスト `2FA 管理 route は throttle が recent-auth より先に走る` に `two-factor.recovery-codes` (GET) を追加 | 実効順の固定 (GET 側も同じ順序であること) |

### 実装上の注意

- 8-1 / 8-3 は**バケットを実際に使い切る**方式 (11 回叩く)。既存の
  「`POST /forgot-password` は 5 回目まで通り 6 回目で 429」と同じ書き方に揃える。
- 8-2 / 8-4 は `X-RateLimit-Remaining` の連続減少で示す (11 回叩かない)。
- 8-1 で `social.callback` を叩くとき、**session に `social_auth_intent` を置かない**こと。
  置くと Socialite の state 検証を通ろうとして実 HTTP に近づく。
  intent 無しなら controller が即 302 するため、外向き HTTP は起きない。
  念のため `Http::preventStrayRequests()` を張る。
- 8-3 は存在しない token で叩く (`Invitations/Invalid` が返る)。**DB を汚さない**。
- 8-5 は認証済み user を `actingAs` する。2FA feature が有効であることが前提
  (`Features::twoFactorAuthentication()`)。

### リスク

- 11 回リクエストするテストは実行時間が増える。既存の 6 回方式と同オーダーなので許容。

---

## 施策 9: 新 exemption case の前提 proof

### 変更箇所

- `tests/Feature/Security/ThrottleExemptionPremiseTest.php` (末尾に追加)

### テスト計画

| # | テスト名 | 検証内容 |
|---|---------|---------|
| 9-1 | `AuthViewRenderOnly の代表 GET は外向き HTTP もメール送信も起こさない` | `Http::preventStrayRequests()` + `Mail::fake()` の下で `/login` `/register` `/forgot-password` `/auth/{provider}/redirect/login` を GET。`Mail::assertNothingSent()` |
| 9-2 | `AuthViewRenderOnly の代表 GET は DB 書込を 1 件も発行しない (read は許す)` | `DB::listen` で収集した SQL に `insert` / `update` / `delete` / `truncate` で始まるものが 0 件 |
| 9-3 | `SQL 書込判定の検出器そのものが機能する` | 判定関数の単体ケース (先頭空白付き `  insert into` / `select` / `with x as (...) insert ...`) |
| 9-4 | `social.redirect の exemption 前提: 対になる social.callback が throttle:social-callback を**ちょうど 1 本**持つ` | `AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目。callback の throttle を外すとここで fail する |

### 判定関数のシグネチャ

```php
/**
 * SQL が書込文か (先頭の空白を除いた動詞で判定する)。
 *
 * ★SQL パーサは導入しない。対象 route が発行するのは Eloquent / query builder 生成の
 *   SQL のみで先頭コメントが付かないため前方一致で足りる。ただし検出器が黙って壊れると
 *   「DB 書込があるのに exemption は通り続ける」= deny-by-default の最悪失敗になるため、
 *   判定関数自身の単体ケースを同ファイルに置く。
 *
 * @return bool insert / update / delete / truncate で始まれば true
 */
function throttlePremiseIsWriteStatement(string $sql): bool
```

### 9-4 の実装メモ

```php
test('social.redirect の exemption 前提: social.callback が throttle:social-callback を持つ', function (): void {
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    $callback = $routes->getByName('social.callback');
    expect($callback)->not->toBeNull();

    // ★throttleEntries() は Router::gatherRouteMiddleware() の**解決後**の実効 middleware 列を
    //   filter する (RouteThrottleBinder.php:171-174)。第 3 段の付与台帳ではないため、
    //   routes/web.php に直書きした第 1 段の throttle もここに現れる。
    $entries = RouteThrottleBinder::throttleEntries($router, $callback);

    expect($entries)->toHaveCount(1);
    // limiter 名まで固定する (throttle は付いているが別 limiter に差し替わっていた、を検出)
    expect(Str::after($entries[0], ':'))->toBe('social-callback');
});
```

### リスク

- 9-1 / 9-2 の対象は 4 route (13 本すべてではない)。
  **13 本すべてに広げない理由**: `filament.admin.auth.*` は panel 権限を持つ user の用意が要り、
  `password.reset/{token}` / `two-factor.login` は分岐条件を満たさないと
  「描画されなかっただけ」の**空振り green** になる。空振りする 13 本の網より、
  実効する 4 本の網 + `auth_view_render_only` の exact-fit cap (13) の方が
  deny-by-default として強い (14 本目が必ず再レビューを強制する)。

---

## 施策 10: ドキュメント更新

### 変更箇所

- `docs/app-integration-guide.md` §7b (L298-349 のブロック)

### 追記内容 (要点)

1. **保護対象群セレクタの非対称**:
   S1 (未認証の変更系) は変更系のみ / S3 (認証面) は**メソッドを問わない**。
   理由 = 認証面は GET でも秘密の開示・外部呼び出し・状態生成を伴いうるが、
   S1 まで GET へ広げると母集団が数百本になり gate が機能しなくなる。
2. **認証面 GET の分類方針**:
   「1 リクエストで外向き通信・重い計算・状態生成が起きるか」で貼る / 免除を決める。
   描画にすぎないものは `AuthViewRenderOnly`、フロー開始で外向き通信をしないものは
   `AuthFlowInitiationWithoutOutboundCall` (完了経路が throttle 済みであることが条件)。
3. **exemption cap は exact fit** で運用する (余裕枠を作らない)。case 別上限も併設。
4. **429 発生率の監視対象に `social-callback` / `invitation-accept` を追加**。
   巻き添え時の初動は閾値変更ではなく `TRUSTED_PROXIES` / 実 client IP 解決の確認。

### 波及変更

- `AGENTS.md` §流量制限 (throttle) の付与規約: **変更しない**
  (規約そのものは変わらず、適用範囲の明確化のみ。詳細は §7b が正本という既存の構造を維持)。
- `docs/architecture.md`: 変更なし (新モデル・新リソースは無い)

---

## 検証コマンドと期待結果

| # | コマンド | 期待結果 |
|---|---------|---------|
| 1 | `composer test -- --filter=ThrottleCoverageInventoryTest` | 施策 1 直後は **19 本列挙で fail** / 施策 6 完了後は green |
| 2 | `composer test -- --filter=RateLimiterKeyConventionTest` | green (limiter 名集合が一致、キーが `social-callback:ip:` / `invitation-accept:ip:`) |
| 3 | `composer test -- --filter=ThrottleExemptionPremiseTest` | green |
| 4 | `composer test -- --filter=AuthThrottleCoverageTest` | green |
| 5 | `composer test -- --filter=NamedRateLimiterKeyTest` | green (既存回帰) |
| 6 | `composer test -- --filter=RouteThrottleBinderTest` | green (既存回帰) |
| 7 | `composer test -- --filter=RecentAuthRouteTest` | green (2FA route への middleware 後付けの回帰) |
| 8 | `php artisan route:cache && php artisan route:list && php artisan route:clear` | 例外なく往復。`route:list` で `social.callback` / `invitations.accept` / `two-factor.qr-code` 等に throttle が出る |
| 9 | `composer phpstan` | level 10 green (ignore / baseline 追加なし) |
| 10 | `vendor/bin/pint --test` | green |
| 11 | `composer test` | 全 green (最後に 1 回。グローバルロック配下で待たされるのは正常) |

**フロントは無変更**のため `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` は
差分が無いことの確認として最後に 1 回で足りる。

### 母集団件数の実測手順 (実装者向け)

```bash
# devnotes 配下の一時スクリプトで再現する (恒久 scripts/ には昇格させない)
APP_ENV=testing php devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php
```

期待: `現行母集団: 47` → `拡張後母集団: 70` (`throttleCoverageRouteFloor()` = 60 の根拠)。

---

## 段階分け

### このタスクでやる

- 施策 1〜10 のすべて (S3 拡張 / throttle 5 本 / exemption 14 件 / 検査 3 本 / 前提 4 本 / docs)

### 後続 TODO 候補 (このタスクではやらない)

| 候補 | 理由 |
|------|------|
| **秘密を返す GET の recent-auth 化** (`two-factor.qr-code` / `.secret-key` / `.recovery-codes`) | `aicue:T120` の後続 TODO **B2** として既に切り出し済み。本タスクは throttle の付与漏れ検査であり認証強度の話ではない。混ぜると「throttle を貼った = 秘密の保護が済んだ」という誤った完了感を生む |
| **429 応答の経路別契約** (Inertia / XHR / API での見せ方) | 別 feature `error-response-contract` の担当 |
| **`social-callback` / `invitation-accept` の 429 発生率メトリクス実装** | 本タスクでは docs の監視項目に載せるところまで。メトリクス基盤は別件 |
| **`AuthViewRenderOnly` 13 本すべてへの premise テスト拡張** | 施策 9 のリスク欄参照。空振り green になる route があるため、必要になったら Filament panel テスト基盤とセットで |
| **S1 (未認証の変更系) の GET 拡張** | 母集団が数百本になり gate が機能しなくなる (概念設計 §5 案 3) |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存機構 (`RouteThrottleBinder` / exemption inventory / limiter キー規約テスト) の**上に載せる**変更であり、新機構を作らない。施策 1 の fail 観測 → 分類 → green という段階が明確で、途中状態でも意味が通る |
| 競合リスク | `tests/Architecture/ThrottleCoverageInventoryTest.php` を施策 1 と 6 の両方が触る (同一ブランチ内の順次変更なので競合なし)。`routes/web.php` は他の実装中タスクと衝突しうるため、**worktree 作成時に main の最新を取り込む** |

---

## 実装時に確認すること (設計で断定しきれなかった点)

1. **`filament.admin.auth.multi-factor-authentication.set-up-required` の GET が
   TOTP 秘密を生成・永続化しないこと**。
   `vendor/filament/filament/src/Auth/MultiFactor/Pages/SetUpRequiredMultiFactorAuthentication.php`
   を読む限り、`mount()` は enable 済み判定と redirect のみで、秘密生成は
   management schema の action (Livewire POST) 側にある。
   **もし GET で秘密が生成・保存されるなら exemption ではなく `throttle:10,1` を貼る**
   (auth 配下・actor 自身に閉じるため inline の適用条件を満たす)。
   その場合 `auth_view_render_only` の cap を 13 → 12 に下げ、全体 cap も 25 → 24 にする。
2. **`recent-auth.confirm` の `show()` が DB 書込をしないこと**。
   satisfier 一覧の組み立てで `socialAccounts` を read する想定 (read は許容)。
   書込があれば exemption 理由の文言を実態に合わせる。
3. 施策 1 の fail 出力が **19 本ちょうど**であること。
   本設計の実測 (23 本 - throttle 済み 4 本) と食い違ったら、
   その差分の原因 (機能フラグ / vendor 更新) を突き止めてから分類に入る。

---

## 参考: 概念設計 (確定済み・蒸し返し不要)

# 概念設計: 未認証 GET の認証面を流量制限の母集団へ (T120 事後監査 Warning の是正)

- 対象識別子: `throttle-unauthenticated-get`
- c2c feature: `path-based-throttle` (aicue は `aicue:T120` で v2 主要部を実装済み)
- 位置づけ: **新規追従ではなく、`aicue:T120` の事後監査が見つけた構造的な取りこぼしの是正**
- 裁定: `AG-096`「認証経路の流量制限を全リポジトリで必須とする」(閾値はプロダクト依存)

---

## 1. 仮説

**仮説**: `ThrottleCoverageInventoryTest` の保護対象群セレクタ S1/S3 が `$isMutating`
(method ∈ POST/PUT/PATCH/DELETE) を前提にしているため、**認証面の GET が 1 本も
deny-by-default の網に入っていない**。母集団を「認証面 = 全メソッド」へ広げて 1 本ずつ
分類させれば、(a) 実害のある未保護経路が機械的に炙り出され、(b) 残りは
「throttle が無いことが正しい理由」として文書化される。

**成功判定** (実装後に機械で確認できる形):

| 指標 | 現在 | 期待 |
|------|------|------|
| 保護対象群の件数 (testing env) | 47 | 70 |
| `throttleCoverageRouteFloor()` | 40 | 60 |
| 新規に throttle が付く route | 0 | 5 本 |
| exemption inventory 件数 | 11 | 25 |
| `throttleCoverageExemptionCap()` (全体) | 14 | 25 (exact fit) |
| case 別 exemption 上限マップ | 無し | 8 case 分を新設 (§4-4) |
| exemption 側の検査 | 3 本 | 5 本 (§4-5 で 2 本追加) |
| 認証面パターンの `social\.` が一致する route | 0 本 (死んだ条件) | 2 本 |

**この仮説が外れる形**: 母集団を広げた結果、増幅も総当り面も持たない描画 route ばかりが
20 本以上並び、exemption が inventory の大半を占めて形骸化する。→ §6 でこのリスクの
扱い (cap の根拠と、貼れるものは貼る方針) を明示する。

---

## 2. 現状 (実コードでの実査結果)

### 2-1. セレクタの実装

`tests/Architecture/ThrottleCoverageInventoryTest.php:172-202`

```php
$isMutating = array_intersect($mutating, $route->methods()) !== [];
// S1: 未認証で到達可能な可能性がある変更系
$s1 = $isMutating && ! throttleCoverageHasMiddlewareClass($route, Authenticate::class);
// S2: ステートレスな機械向け経路 (api/ oauth/ .well-known/oauth-)
$s2 = (...) && ! throttleCoverageHasMiddlewareClass($route, StartSession::class);
// S3: 認証済み側も含む credential 面
$s3 = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;
```

S1 と S3 が両方 `$isMutating` を要求し、S2 は URI 接頭辞で絞られる。よって
**認証面の GET/HEAD は S1/S2/S3 のどれにも入らない**。

### 2-2. 実測 (本セッションで再現)

`php artisan route:list --json` + 実アプリを bootstrap して `Router::gatherRouteMiddleware()`
で middleware を解決する検証スクリプト (devnotes 一時スクリプト) を `APP_ENV=testing` で実行:

```
現行母集団: 47      ← テスト内コメント「実測 47」と一致 (台帳・コメントとも正しい)
拡張後母集団: 70
増分: 23
```

増分 23 本の内訳 (ブリーフの列挙と完全一致):

| # | route 名 | 認証 | 既存 throttle |
|---|----------|------|--------------|
| 1 | `verification.verify` | auth | **`6,1`** (Fortify `limiters.verification` 既定) |
| 2 | `passkey.login-options` | PUBLIC | **`passkeys`** |
| 3 | `passkey.confirm-options` | auth | **`passkeys`** |
| 4 | `passkey.registration-options` | auth | **`passkeys`** |
| 5 | `social.callback` | PUBLIC | なし |
| 6 | `social.redirect` | PUBLIC | なし |
| 7 | `invitations.accept` | PUBLIC | なし |
| 8 | `login` | PUBLIC | なし |
| 9 | `register` | PUBLIC | なし |
| 10 | `password.request` | PUBLIC | なし |
| 11 | `password.reset` | PUBLIC | なし |
| 12 | `two-factor.login` | PUBLIC (guest) | なし |
| 13 | `filament.admin.auth.login` | PUBLIC | なし |
| 14 | `password.confirm` | auth | なし |
| 15 | `password.confirmation` | auth | なし |
| 16 | `recent-auth.confirm` | auth | なし |
| 17 | `recent-auth.status` | auth | なし |
| 18 | `verification.notice` | auth | なし |
| 19 | `two-factor.qr-code` | auth | なし |
| 20 | `two-factor.secret-key` | auth | なし |
| 21 | `two-factor.recovery-codes` | auth | なし |
| 22 | `filament.admin.auth.profile` | auth | なし |
| 23 | `filament.admin.auth.multi-factor-authentication.set-up-required` | auth | なし |

**ブリーフとの食い違い (重要)**:
ブリーフ「実装上の注意」は `passkey.login-options` を「同じ観点で該当しうるので実コードを見て判断する」
としているが、**実測では既に `throttle:passkeys` が付いている** (`config/fortify.php:124` の
`limiters.passkeys => 'passkeys'` により Fortify が付与)。同様に `verification.verify` も
Fortify 既定 `limiters.verification = '6,1'` により throttle 済み。
つまり **23 本のうち 4 本は既に保護されており、判断が要るのは 19 本**である。

### 2-3. `social.callback` の増幅を実コードで検証した結果

`app/Http/Controllers/Auth/SocialAuthController.php:77-92`:

```php
$intent = $request->session()->pull('social_auth_intent');
if (! is_string($intent) || ! in_array($intent, self::INTENTS, true)) {
    return redirect()->route('login')->withErrors([...]);   // ← ここで短絡
}
$socialiteUser = Socialite::driver($provider)->user();       // ← 外向き HTTP
```

さらに `vendor/laravel/socialite/src/Two/AbstractProvider.php:236-244` で
`hasInvalidState()` (session の `state` と query の `state` の hash_equals) が
**外向き HTTP より先**に走る。

したがって:

- **素の連打では外向き HTTP は 1 回も起きない** (intent 不在 / state 不一致で短絡)。
- 攻撃者が外向き HTTP を起こすには `GET /auth/{provider}/redirect/{intent}` を先に叩き、
  Location ヘッダから `state` を読んで callback に載せる必要がある
  = **2 リクエストで IdP への token エンドポイント POST 1 回**。
- `intent` も `state` も session から `pull` される (1 回で消費される) ため、
  ペアを崩せない。

**結論**: 増幅は実在するが、ブリーフ / 監査所見の「1 リクエストあたり IdP への外向き HTTP が
1 回発生する」は**誇張**である。正しくは「2 リクエストで 1 回、攻撃者が自由に反復できる」。
それでも **未認証・throttle なしで外部 IdP へ HTTP を発射できる唯一の経路**であり、
throttle を貼る根拠としては十分 (増幅比が 0.5 に落ちるだけで、質は変わらない)。

### 2-4. `invitations.accept` (GET) の未保護

`routes/web.php:600-606` / `app/Http/Controllers/Organizations/InvitationAcceptanceController.php:43-77`

- **GET (guest 可・throttle なし)**: query の `token` を `sha256` して
  `OrganizationInvitation` を 1 件引き、有効なら session へ token を保存して
  `register` へ 302、無効なら `Invitations/Invalid` を 200 で描画する。
- **POST (auth 必須・`throttle:10,1`)**: 同じ token 照合を行う。
  route コメントに「招待トークンは hash 照合されるが、**総当り試行そのものを有界にする**」と
  明記されている。

つまり**同じ token 照合を行う 2 本のうち、認証不要で応答分岐まで観測できる GET の方だけが
無制限**という非対称になっている。token は `Str::random(64)` (≈380bit) のため総当り自体は
非現実的だが、「未認証入力で 1 リクエスト 1 DB 参照 + 有効時は session 生成」が
無制限に踏める面であることに変わりはない。

### 2-5. 死んだ条件

認証面パターン (`:41-42`) の `social\.` は、`social.redirect` / `social.callback` が
2 本とも GET のため **現状 1 件も一致しない**。「social も見ている」という誤った安心を与える。
`invitations\.` も、変更系は `invitations.accept.store` 1 本のみが一致している
(GET の `invitations.accept` は視界外)。

### 2-6. 既存の周辺機構 (壊してはいけないもの)

| 機構 | 実体 | 本設計への影響 |
|------|------|--------------|
| 貼る仕組みの 3 段優先順 | `docs/app-integration-guide.md` §7b | 自前 route は第 1 段、Fortify route は第 3 段 (`RouteThrottleBinder`) |
| named limiter キー規約 | `RateLimiterKeyConventionTest` (`{レーン}:{種別}:{値}` を実評価) | 新 limiter は `rateLimiterKeyInventory()` への登録が必須 |
| limiter キーに route parameter を入れない | `NamedRateLimiterKeyTest` | `social.callback` の `{provider}`、`invitations.accept` の `?token=` を**キーに入れない** |
| exemption 前提の behavioral proof | `ThrottleExemptionPremiseTest` | 新カテゴリの前提も behavioral に固定する |
| inline throttle の適用条件 | AGENTS.md「認証済みかつ actor 自身に閉じる操作」限定 | 未認証面は必ず named limiter |
| `route:cache` 前提 | `RouteThrottleBinder::attachOnBooted()` は cached 起動で skip | Fortify route への新規付与も同じ経路 |

---

## 3. 課題

1. **セレクタの取りこぼし** — 認証面 GET 23 本が deny-by-default の外にある。
   結果として `social.callback` (外向き HTTP) と `invitations.accept` (未認証 token 照合) が
   無音で無防備なまま通っている。
2. **死んだ条件** — `social\.` が 1 件も一致せず、「見ている」という誤った安心を生む。
3. **設計文書の欠落** — `aicue:T120` の詳細設計は「秘密を返す GET」を後続 TODO B2 として
   明示的に外したが、**GET の認証面一般 / SSO callback については記述が無い**。
   `AG-096` に照らせば「なぜ今回入れないか」を残すべきだった。
4. **cap の追随** — 母集団を +49% 広げると exemption も増える。
   `throttleCoverageExemptionCap()` = 14 のままでは、正しい分類をしても gate が fail する。

---

## 4. 方針

### 4-1. セレクタ: S3 から `$isMutating` を外す (S1/S2 は触らない)

```php
// S3: credential 面 (認証済み側も含む)。★非変更系 (GET/HEAD) も母集団に入れる。
//     認証面は「読むだけ」の GET でも秘密の開示・外部呼び出し・状態生成を伴いうるため、
//     メソッドではなく **面** で母集団を取る。
$s3 = $name !== '' && preg_match($pattern, $name) === 1;
```

- S1 (未認証の変更系) と S2 (ステートレス機械向け) は**変更しない**。
  S1 まで GET へ広げると母集団が数百本になり、gate が exemption 台帳に埋もれて機能しなくなる
  (§5 の却下案 3)。
- `throttleCoverageRouteFloor()` を 40 → **60** に更新する
  (実測 70 に対し、機能フラグ無効化等での目減りを許容する余裕を持たせた値。
  現行の 47 に対する 40 と同じ比率感)。

### 4-2. throttle を新たに貼る 5 本

| route | 貼り方 | limiter | 閾値の根拠 (既存値を発明しない) |
|-------|--------|---------|------------------------------|
| `social.callback` | 第 1 段 (`routes/web.php`) | 新 named `social-callback` | 未認証で到達する認証面・IP 単位の既存本番値 = `passkeys` limiter の guest 分岐 **10/min** と同値 |
| `invitations.accept` | 第 1 段 (`routes/web.php`) | 新 named `invitation-accept` | 姉妹操作 `invitations.accept.store` の **`10,1`** と同値 |
| `two-factor.qr-code` | 第 3 段 (`RouteThrottleBinder`) | inline `10,1` | 姉妹 `two-factor.enable` / `.confirm` / `.disable` / `.regenerate-recovery-codes` と同値 |
| `two-factor.secret-key` | 第 3 段 | inline `10,1` | 同上 |
| `two-factor.recovery-codes` | 第 3 段 | inline `10,1` | 同上 |

- **未認証面に inline を使わない**: `social.callback` / `invitations.accept` は未認証で到達するため
  named limiter を新設し、キーを `{レーン}:ip:{値}` で明示する
  (フレームワーク既定キーへの暗黙依存を作らない = AGENTS.md §7b)。
- **`two-factor.*` GET 3 本に inline `10,1` を使える理由**: いずれも `auth` middleware 配下で
  **actor 自身の 2FA 秘密**しか返さない = 「認証済みかつ actor 自身に閉じる操作」に該当し、
  フレームワーク既定キー (user id) がちょうど求める数える単位になる。
- **誤読防止 (必須)**: `two-factor.qr-code` / `.secret-key` / `.recovery-codes` への `10,1` は
  **連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
  「throttle を貼ったから秘密 GET の保護は済んだ」と次に触る人が誤読すると、後続 TODO **B2**
  (recent-auth 化) が静かに落ちる。付与箇所の docblock と behavioral テスト名の両方に
  「回数上限であって認証強度ではない / 認証強度は B2」と明記する。
- **limiter closure の型**: 新 limiter は `fn (Request $request): Limit` で戻り値型を明示し、
  `$request->ip()` の `?string` を `?? 'unknown'` で潰す (既存 limiter と同じ書き方)。
  PHPStan level 10 を型の widen なしで通す。
- **キーに route parameter / query token を入れない**: `social.callback` の `{provider}`、
  `invitations.accept` の `?token=` を key に混ぜると bucket が分かれ、
  「429 になるまでの回数」が実在オラクルになる (`NamedRateLimiterKeyTest` の思想)。

### 4-3. 残り 14 本を exemption として分類する (新 enum case は 2 つ)

**`social.redirect` を「描画にすぎない route」に混ぜない**。これは OAuth state を生成して
外部 IdP へ遷移させる**認証フローの開始**であり、描画系と同じ箱に入れると
「GET だが認証フローを開始する route」を将来まとめて免除する穴になる。
よって case を 2 つに分ける (enum docblock「汎用に見えるものほど適用条件を狭く」に従う)。

```php
/**
 * 認証面の非変更系 (GET/HEAD) で、応答が画面 / ステータスの描画にすぎない route。
 *
 * 適用条件 (すべて満たすこと):
 *  - HTTP メソッドが GET/HEAD のみ (変更系には適用しない)
 *  - 外部呼び出し・メール送信・重い計算・**DB 書込**を伴わない (DB read は可)
 *  - 推測可能な秘密を開示しない (自セッションが既に保持する情報の再表示は可)
 *  - 副作用が自セッション (CSRF token / flash 等) の中に閉じる
 */
case AuthViewRenderOnly = 'auth_view_render_only';

/**
 * 認証フローを開始するが、その場では外向き通信を一切行わない非変更系 route。
 *
 * 適用条件 (すべて満たすこと):
 *  - HTTP メソッドが GET/HEAD のみ
 *  - **その場で外向き HTTP を発行しない** (発行するのは対になる完了経路)
 *  - 生成する状態が自セッション内に閉じ、他セッションから消費できない
 *  - **対になる完了経路が throttle 済みである** (増幅はそちらで有界化されている)
 */
case AuthFlowInitiationWithoutOutboundCall = 'auth_flow_initiation_without_outbound_call';
```

| case | 対象 | 件数 |
|------|------|------|
| `AuthViewRenderOnly` | `login` / `register` / `password.request` / `password.reset` / `two-factor.login` / `password.confirm` / `password.confirmation` / `recent-auth.confirm` / `recent-auth.status` / `verification.notice` / `filament.admin.auth.login` / `filament.admin.auth.profile` / `filament.admin.auth.multi-factor-authentication.set-up-required` | 13 |
| `AuthFlowInitiationWithoutOutboundCall` | `social.redirect` | 1 |

`AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目が
「`social.callback` に throttle を貼る」という本設計の施策に**構造的に依存**している点が重要で、
将来 callback の throttle を外すと exemption の前提が崩れる。
これは §4-5 の前提テストで behavioral に固定する。

### 4-4. cap の更新 (全体 14 → 25 + **case 別上限**)

母集団 47 → 70 (+49%) に対し exemption は 11 → 25。
比率は 23.4% → 35.7% に上がるが、これは「増分 23 本のうち 19 本が
**認証済み or 未認証の画面描画**」という母集団の質の変化そのものであり、
セレクタが広すぎることの証拠ではない。

ただし**全体 cap だけでは「どのカテゴリが膨らんだか」が見えない**。
新カテゴリ 13 件が将来 20 件・30 件へ増えても全体 cap の一言でしか止まらず、
レビュー時に「増えたのは描画系か、それとも本来貼るべきものが逃げたのか」を区別できない。
そこで **case 別の上限マップ**を併設する:

```php
/** @return array<string, int> ThrottleCoverageExemption::value => 上限 */
function throttleCoverageExemptionCapByCase(): array
```

| case | 現在 | 上限 | 上限の意味 |
|------|------|------|-----------|
| `static_metadata_response` | 4 | 4 | vendor が登録する OAuth メタデータ 4 本で固定 |
| `vendor_method_not_allowed_stub` | 2 | 2 | `GET|DELETE /api/v1/mcp` の 2 本で固定 |
| `session_teardown_only` | 2 | 2 | web / filament の logout 2 本で固定 |
| `local_only_debug_route` | 1 | 1 | `debug.login-as` のみ |
| `component_level_limiter` | 1 | 1 | `default-livewire.update` のみ |
| `signature_required_before_effect` | 1 | 1 | `storage.local.upload` のみ |
| `auth_view_render_only` | 13 | 13 | 認証面の描画 GET。**ここが膨らむ = 貼るべきものを逃がした疑い** |
| `auth_flow_initiation_without_outbound_call` | 1 | 1 | `social.redirect` のみ。増えたら必ず再設計 |

**上限は現在値ちょうど (exact fit) にする**。余裕を 1 でも持たせると、
その 1 本は「個別の behavioral proof も再レビューも無しに免除できる枠」になる。
exact fit なら 14 本目を足す作業が必ず「上限の数値を変える差分」として現れ、
個別理由・代表テストへの追加要否・そもそも貼るべきでないかの再検討を強制できる。

同じ理由で**全体 cap も 25 (exact)** とする (`array_sum()` にはせず独立の定数)。
全体はセレクタ全体の広さを、case 別は分類の偏りを見る。役割が違うので両方を検査する。

### 4-5. 検査の強化 (exemption の穴を 3 つ塞ぐ)

母集団を広げるだけでなく、**exemption 側の検査を 3 点足す**。
いずれも既存テストが構造的に見落としている点である。

1. **`ThrottleCoverageInventoryTest`**: exemption inventory の key は
   **throttle を 1 本も持たない**こと。
   現行は「throttle 1 本 → continue」で先に抜けるため、
   *throttle 済みなのに exemption にも登録されている* 状態を検出できない
   (stale 検出も「母集団に存在するか」しか見ない)。放置すると台帳に死んだ行が溜まる。
2. **`ThrottleCoverageInventoryTest`**: 新 2 case を使う entry は
   **非変更系 (GET/HEAD のみ) の route** であること。
   両 case の適用条件 1 番目を機械化する (`logout` 等の変更系がこの箱に落ちない)。
3. **`ThrottleExemptionPremiseTest`**: 新 2 case の前提を behavioral に固定する。

前提テストの内容 (14 本すべてに個別テストは書かない。**壊れやすい条件**だけを固定する):

| 検証 | 対象 | 方法 |
|------|------|------|
| 外向き HTTP 0 件 / メール送信 0 件 | `login` / `register` / `password.request` / `social.redirect` | `Http::preventStrayRequests()` + `Mail::fake()` の下で GET |
| **DB 書込 0 件** | 同上 | `DB::listen` で `insert` / `update` / `delete` / `truncate` で始まる SQL が 0 件 (read は許す) |
| `social.callback` が throttle を**ちょうど 1 本**持ち、その limiter が `social-callback` である | `social.callback` | `AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目 |

**`social.callback` の検査に使う判定点**: `RouteThrottleBinder::throttleEntries($router, $route)`
を使う。これは `Router::gatherRouteMiddleware($route)` の**解決後**の実効 middleware 列を
filter する実装 (`RouteThrottleBinder.php:171-174`) であり、「第 3 段の付与台帳」ではない。
したがって第 1 段 (`routes/web.php` 直書き) で貼った throttle も確実に見える
(`ThrottleCoverageInventoryTest` が母集団全体の判定に使っているのと同じ関数)。
その上で **entry 文字列の params 部が `social-callback` であること**まで固定し、
「throttle は付いているが別 limiter に差し替わっていた」を検出できるようにする。

**SQL 書込判定の頑健性について**: 先頭コメント / CTE 付き SQL では前方一致が崩れうる。
対象 4 route が発行する SQL は Eloquent / query builder 生成のもの (先頭コメント無し) に
限られるため前方一致で足りるが、判定関数は `ltrim()` してから
`insert|update|delete|truncate` を前方一致する形に切り出し、
**その判定関数自身の単体ケース** (先頭空白付き / `select` / `with ... insert`) を
同ファイル内に置いて、検出器が黙って壊れないようにする。

**DB read を 0 件にしない理由**: `register` は session に自分で置いた
`invitation_token` から prefill を解決するため DB read が 1 件発生する
(`OrganizationMembershipService::resolveRegisterPrefillEmail()`。token 不在なら DB へ到達しない)。
条件は「DB **書込**を伴わない」に留め、read が許される理由を個別 exemption の理由文に書く。

### 4-6. ドキュメント

`docs/app-integration-guide.md` §7b に、**セレクタが「面」で取ること**と
**認証面 GET の分類方針**を追記する (S1 は変更系のまま / S3 は全メソッド、という非対称の理由)。

---

## 5. 代替案と却下理由

| # | 案 | 却下理由 |
|---|----|---------|
| 1 | **S3 も GET も母集団に入れるが、`Authenticate` 付きは除く** (未認証 GET だけ 9 本追加) | exemption が 6 本増えるだけで済み一見魅力的だが、**`two-factor.qr-code` / `.secret-key` / `.recovery-codes` (秘密を返す GET) が視界外に残る**。今回まさに throttle を貼るべきと判定した 3 本を落とす案であり、ブリーフの与件 (23 本を母集団へ) からも外れる |
| 2 | **23 本すべてに throttle を貼る** | `GET /login` に IP 単位の throttle を貼ると、展示会・現場 Wi-Fi の同一 NAT 配下で正当な利用者が巻き添えで**ログイン画面すら開けなくなる**。gate の思想は「1 本ずつ貼る / 免除を決めさせる」であって全部貼ることではない |
| 3 | **S1 も `$isMutating` を外す** (全 GET を母集団へ) | 母集団が数百本になり、exemption 台帳が数百件に膨れる。deny-by-default の信号対雑音比が破壊され、gate が「通すためのハンコ」に堕ちる。認証面という**面**の定義があるからこそ 70 本で収まる |
| 4 | **exemption を prefix パターン (`filament.admin.auth.*` 等) で一括免除できるようにする** | 台帳の 1 行が将来追加される未知 route まで免除してしまい、deny-by-default が prefix 単位で穴になる。既存設計 (route ラベル 1 件 = 1 entry) を維持する |
| 5 | **`social.redirect` にも throttle を貼る** | 増幅 (外向き HTTP) は `social.callback` 側で有界化されるため、redirect を絞っても外向き HTTP の総量は減らない。session レコード生成は `GET /login` を含む全 web route と同質のコストであり、SSO 固有ではない。「今必要なものだけ作る」に倒して exemption とする |
| 6 | **`social-callback` limiter を session id でキーする** (巻き添えゼロ) | session は `social.redirect` を叩けば無限に取得できるため、攻撃者に対して実質無制限になる。巻き添え回避のために防御を無効化する取引は成立しない |
| 7 | **cap を上げず、exemption を減らすために `two-factor.*` 以外にも throttle を広げる** | §5-2 と同じ巻き添え問題。cap は「セレクタが広すぎないか」の検出器であって、貼る/貼らないの判断を歪める理由にしてはいけない |

---

## 6. スコープに入れないもの (と、その理由)

**この節は必須**である。前周回の監査 Warning は「落としたこと」ではなく
「落としたと書かなかったこと」に対する指摘だった。

| 除外するもの | 理由 |
|------------|------|
| **秘密を返す GET の recent-auth 化** (`two-factor.qr-code` / `.secret-key` / `.recovery-codes` への step-up 要求) | `aicue:T120` の後続 TODO **B2** として既に切り出し済み。本タスクは**流量制限の付与漏れ検査**の話であり、**認証強度**の話ではない。混ぜると「throttle を貼った = 秘密の保護が済んだ」という誤った完了感を生む。本タスクでは同 3 本に `10,1` を貼るが、これは**回数の上限**であって step-up の代替ではない |
| **429 応答の経路別契約** (Inertia / XHR / API での 429 の見せ方) | 別 feature `error-response-contract` の担当。新規 throttle 5 本の 429 応答も既定の `ThrottleRequests` 応答に従う |
| **閾値の家系統一** | `AG-096` が「閾値はプロダクト依存」と裁定済み。既存値 (`5,1` / `6,1` / `10,1` / `10/min`) を 1 つも変えない。新設 limiter にも**既存の同性質エンドポイントと同値**を充てる |
| **S1 (未認証の変更系) の GET 拡張** | §5 案 3。母集団が数百になり gate が機能しなくなる |
| **S2 (`api/` / `oauth/` / `.well-known/oauth-`) の変更** | 現行セレクタは URI 接頭辞 + `StartSession` 不在で取っており、メソッド前提を持たない = 今回の欠陥の対象外 |
| **`social.redirect` への throttle 付与** | §5 案 5。exemption として理由を残す |
| **Filament panel の GET 3 本への throttle 付与** | credential 検証は `default-livewire.update` (POST) 側にあり、そこは既に `ComponentLevelLimiter` として exemption 登録済み + component 内 `rateLimit(5)` が実在する (`ThrottleExemptionPremiseTest` が固定)。GET はページ描画のみ |
| **`ThrottleCoverageExemption` の既存 6 case / 既存 11 件の分類の見直し** | 母集団を広げる作業であって、既存分類を書き換える作業ではない (ブリーフ「実装上の注意」)。既存 11 件は 1 文字も触らない |
| **`RouteThrottleBinder` 本体の変更** | 新規付与はすべて既存の第 1 段 / 第 3 段の経路に乗る。binder のロジックに変更は要らない |
| **frontend (Svelte) の変更** | throttle は middleware 層で完結する。429 の UI 表現は上記 `error-response-contract` の担当 |

---

## 7. 期待効果 (使命への貢献)

AI-CUE の使命は「現場作業者が SOP から標準化されたマニュアル動画を作れるようにする」。
本改善は使命に直接寄与する機能ではなく、**使命を支える基盤の不変条件**の是正である:

- **SSO ログインが外部 IdP への増幅口になる状態を閉じる**。現場での導入は
  組織単位の SSO ログインが主動線になるため、この経路が攻撃者に自由に踏まれる状態は
  「サービスが止まる / IdP 側にレート制限で締め出される」という形で直接ユーザーに跳ね返る。
- **招待受諾 (GET) の未保護を閉じる**。組織へのメンバー招待は導入初期の必須動線であり、
  ここが未認証で無制限に踏める状態は運用上のノイズ源になる。
- **gate の誤った安心 (死んだ条件) を解消する**。「social も見ている」と読める条件が
  1 件も一致していない状態は、次に触る人を確実に誤らせる。

---

## 8. 検証方法

### 8-1. テストファースト (fail を先に確認する)

1. S3 から `$isMutating` を外すコミットを**単独で**当て、`ThrottleCoverageInventoryTest` を走らせる。
   → 「throttle が 1 本も無く exemption inventory にも未登録」が **19 本**列挙されて fail する
   (23 本 - 既に throttle 済み 4 本) ことを確認する。
2. その fail 出力を分類 (貼る 5 / 免除 14) の入力とする。

### 8-2. 機械検証コマンドと期待結果

| コマンド | 期待 |
|---------|------|
| `php artisan test tests/Architecture/ThrottleCoverageInventoryTest.php` | green (母集団 70 / exemption 25 / cap 26) |
| `php artisan test tests/Architecture/RateLimiterKeyConventionTest.php` | green (新 limiter 2 本が inventory と一致し、キーが `{レーン}:ip:{値}`) |
| `php artisan test tests/Feature/Security/ThrottleExemptionPremiseTest.php` | green (新カテゴリの前提テストを含む) |
| `php artisan test tests/Feature/Security/AuthThrottleCoverageTest.php` | green (新規 5 本の behavioral proof を含む) |
| `php artisan test tests/Feature/Security/NamedRateLimiterKeyTest.php` | green |
| `php artisan route:cache && php artisan route:list && php artisan route:clear` | 例外なく往復する (`RouteThrottleBinder` の fail-fast と焼き込みの確認) |
| `composer phpstan` / `vendor/bin/pint --test` | green |

### 8-3. 振る舞いの回帰確認 (新規 throttle が正当利用者を壊さないこと)

- SSO ログイン 1 往復 (`social.redirect` → IdP → `social.callback`) が
  `social-callback` バケットを **1 だけ**消費する。
- 招待リンクのクリック 1 回が `invitation-accept` バケットを 1 だけ消費する。
- 2FA 設定画面の初期表示 (`two-factor.qr-code` + `.secret-key` の 2 リクエスト) が
  `10,1` の枠内に収まる。

---

## 9. 制約・前提

- **`php artisan route:cache` を毎デプロイ再生成すること**が前提条件
  (`RouteThrottleBinder` は cached 起動で後付けを skip する)。
  今回の Fortify route への新規付与 3 本も同じ経路に乗る。
- production は `TRUSTED_PROXIES` の明示宣言が必須 (T108)。IP 単位の新 limiter 2 本は
  この設定に依存する (`trustProxies(at: '*')` を復活させると総当りに無効化される)。
- テストは `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` は使わない。
- cache store はテスト時 `array` に強制 (`phpunit.xml`) されており、各テストでバケットは空から始まる。

---

## 10. リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| `social-callback` 10/min IP が同一 NAT 配下の一斉 SSO ログインを巻き添えにする | 現場 Wi-Fi・オフィス NAT で、同じ 1 分内に 11 人目以降が SSO ログインを完了できない | §10-1 |
| `invitation-accept` 10/min IP が一斉招待の同時クリックを巻き添えにする | 同一オフィスから 11 人目が招待リンクを開けない | §10-1 |
| `two-factor.*` GET への `10,1` が 2FA 設定画面のリロード連打を止める | 自分の設定画面が一時的に開けない | actor 自身のバケットであり他者への影響ゼロ。姉妹 POST と同値 |
| exemption 25 件で台帳が形骸化する | gate がハンコになる | cap を 26 で締める + 新カテゴリの前提を `ThrottleExemptionPremiseTest` で behavioral に固定する |
| S3 拡張により将来の認証面 GET 追加が必ず fail する | 開発時の摩擦 | それが deny-by-default の目的であり、意図した摩擦 |

### 10-1. 未認証 IP レーン (10/min) の巻き添えリスクをどう扱うか

**正直な評価**: AI-CUE の想定現場 (朝礼後に作業者が一斉ログイン、導入時に管理者が一斉招待) では
「同一グローバル IP から 1 分内に 11 回の SSO callback / 招待リンク open」は**起こりうる**。
「起こらないから安全」とは言わない。

**それでも 10/min IP を採る理由**:

1. **詰みにならない**。429 は 1 分で解け、`Retry-After` ヘッダで再試行時刻が示される。
   - `social-callback`: **`login` / `register` の入口ページは throttle しない**ため
     「画面すら開けない」状態にはならない。止まるのは SSO の完了往復だけ。
   - `invitation-accept`: **こちらは入口ページそのもの** (`GET /invitations/accept`) を
     絞るため、11 人目は**招待リンクを開いた時点で 429 になり画面も出ない**。
     ここを「入口は開く」と書くのは誤りなので明記する。言えるのは
     **招待そのものは 429 で消費されず、通常は `Retry-After` 後に再試行できる**ことまでで、
     **到達を保証はしない** (共有 IP で継続的に枠が競合すれば、解除直後の枠を
     他の利用者に取られ続けうる。待機中に招待が失効・取消される可能性もある)。
   正確に言えるのは「**limiter 自体は恒久ロックを作らない**」ことであり、
   「必ず開ける」ではない。共有 IP 配下の一時的な DoS が成立する余地は残る
   (これが §10-1 末尾の監視要件を運用契約にする理由である)。
2. **閾値を発明しない** (`AG-096` / AGENTS.md §7b)。未認証の認証面 IP レーンで本番稼働中の
   最大値が `passkeys` guest 分岐の 10/min であり、これ以上の値は本リポジトリに前例がない。
   前例のない値を「巻き添えが怖いから」で発明すると、gate の閾値規律が崩れる。
3. **キーの単位を変える代替が成立しない**。session id でキーすると
   `social.redirect` を叩くだけで新しい bucket を無限に取れるため、攻撃者に対し実質無制限になる
   (§5 案 6)。provider / token をキーに混ぜると存在オラクルになる (`NamedRateLimiterKeyTest`)。

**運用要件 (実装者への申し送り)**:

- `social-callback` / `invitation-accept` の **429 発生率を監視項目に入れる**
  (既存の webhook レーンと同じ扱い。`docs/app-integration-guide.md` §7b の
  「429 発生率を監視する」運用に 2 レーンを追加する)。
- **実測で巻き添えが出たときの初動は「閾値を上げる」ではない**。まず
  `TRUSTED_PROXIES` / 実 client IP の解決が正しいか (`docs/trusted-proxies-runbook.md`) を疑う。
  IP が実質 1 個に潰れていれば、閾値をいくら上げても同じことが起きる。
  それが健全な上で不足するなら、**プロダクト判断として閾値変更の TODO を起票する**
  (`AG-096` は閾値をプロダクト依存と裁定しており、エージェント判断で動かさない)。
