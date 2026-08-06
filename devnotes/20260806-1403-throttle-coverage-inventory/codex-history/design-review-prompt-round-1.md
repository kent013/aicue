# Codex レビュー依頼: 詳細設計 (path-based-throttle / 流量制限の付与漏れ検査 + キー規約の是正)

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

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)。
   **クラス起点の主キー同一性クエリ**(`User::find($payloadId)` /
   `User::query()->where('id', …)` / `DB::table('users')->where('id', …)`)は
   deny-by-default で分類が要る(`ModelDirectFetchInvariantTest` + `DirectFetchInventory`。
   route parameter 由来の id は `NestedRouteIdorDefenseTest` の担当で母集団が交わらない)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
> を壊すため、どちらの側も renumber しない。

> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。`trustProxies(at: '*')` はレート制限を
> 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
> `docs/trusted-proxies-runbook.md` が正本。

【思考原則 — 全議論に適用】
まず仮説を立てろ。仮説なき改善はただの試行錯誤である。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest
- DTO + JsonResource パターン / Laratrust RBAC

【重要な前提 — 蒸し返さないこと】
上位裁定 (c2c 台帳 AG-096 / AG-097) は確定与件:
- 機構は自前で作らずフレームワーク標準の名前付きリミッタを使う
- 貼る仕組みは 3 段の優先順 (設定 > route 名の後付け + 起動時 fail-fast > URL パス表は原則禁止)
- 閾値はプロダクト依存であり既存値は変えない
- 429 応答の契約と信頼するプロキシの設定は別 feature (射程外)
本設計は概念設計フェーズで Codex 合議 3 ラウンドを完了しており、Critical は解消済みです。

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性
4. テスト計画の網羅性 (各施策に Pest テスト、RefreshDatabase グローバル適用)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク (とくに「throttle を貼ったことで新たな DoS / 巻き添え / 存在オラクルが生まれないか」)
8. 波及変更の網羅性 (既存テストへの影響が変更対象に含まれているか)
9. セキュリティ (AGENTS.md のセキュリティ不変条件、とくに不変条件 2/10 の pre-binding 短絡)
10. Laravel 12 の実挙動として成立するか (booted callback / route:cache / gatherRouteMiddleware / priority list / RateLimiter facade)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: 流量制限の付与漏れ検査 + キー規約の是正 (path-based-throttle)

- 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex 合議 3 ラウンド。Critical 解消済み)
- 対象 feature (c2c): `path-based-throttle` / 裁定: AG-096・AG-097 (2026-08-06)

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本施策の位置づけ: 使命そのものを前に進める機能ではなく、
**現場の SOP と手順動画という顧客資産を預かる基盤の前提条件**である。

### 禁止事項 (AGENTS.md より、本施策に効くもの)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
9. Artifact の使用

### セキュリティ不変条件 (本施策が守るもの / 壊してはならないもの)

- **不変条件 2 / 10**: 層 2 (テナント境界 404) は認可より前・binding 直後。
  `ThrottleRequests` は Laravel 既定 priority で **`Authenticate` の後・`SubstituteBindings` の前** =
  pre-binding の短絡であり、429 は route parameter の実在を漏らさない。
  `bootstrap/app.php` は `appendToPriorityList()` しか使っておらず既定順を置換していない (実査確認済み)。
  **本施策はこの位置関係を一切変更しない**。
- 既存の `tests/Feature/Security/NamedRateLimiterKeyTest.php`
  (limiter キーが route parameter を含まないことの behavioral proof) を壊さない。

### コーディングルール

- PHPStan level 10 (`composer phpstan`) / Pest (`composer test`) / `RefreshDatabase` はグローバル適用
- テストデータは Factory 経由 / `declare(strict_types=1)` + 日本語コメント
- `Webmozart\Assert\Assert` で null 安全 / アーリーリターン
- `composer fix` (Pint) でフォーマット
- PHP 8.4 + Laravel 12

---

## 施策一覧

| # | 施策名 | 変更ファイル (新規 N / 変更 M) | 優先度 |
|---|--------|------------------------------|--------|
| 1 | throttle 判定述語と後付け binder | N `app/Support/Http/RouteThrottleBinder.php` | Critical |
| 2 | 付与漏れの目録検査 | N `app/Enums/Security/ThrottleCoverageExemption.php` / N `tests/Architecture/ThrottleCoverageInventoryTest.php` | Critical |
| 3 | 認証系 vendor route への throttle 後付け | M `app/Providers/FortifyServiceProvider.php` | Critical |
| 4 | webhook 2 本への throttle 付与 | M `app/Providers/AppServiceProvider.php` / M `routes/web.php` | High |
| 5 | 招待受諾への throttle 付与 | M `routes/web.php` | High |
| 6 | キー規約 `{レーン}:{種別}:{値}` への統一 | M `app/Providers/FortifyServiceProvider.php` / M `app/Providers/AppServiceProvider.php` | High |
| 7 | キー規約の機械検査 | N `tests/Support/RateLimiterRegistrationScanner.php` / N `tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php` / N `tests/Architecture/RateLimiterKeyConventionTest.php` | High |
| 8 | exemption 前提の Feature 固定 | N `tests/Feature/Security/ThrottleExemptionPremiseTest.php` | Medium |
| 9 | 新 limiter の behavioral テスト | N `tests/Feature/Security/AuthThrottleCoverageTest.php` | High |
| 10 | ドキュメント追記 | M `docs/app-integration-guide.md` / M `AGENTS.md` (ドメイン固有規約) | Medium |

---

## 施策 1: throttle 判定述語と後付け binder

### 変更箇所

- 新規: `app/Support/Http/RouteThrottleBinder.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: 施策 2・3・4 のテストが本クラスを参照する

### 設計

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * vendor が登録した named route へ throttle middleware を後付けする binder
 * (標準形「貼る仕組みの 3 段優先順」の第 2 段。設定で貼れない route 専用)。
 *
 * ★冪等性の契約 (route:cache と両立させるため必須):
 *   `php artisan route:cache` の RouteCacheCommand::getFreshApplicationRoutes() は
 *   **アプリを再 bootstrap してから** router->getRoutes() を直列化する。
 *   provider の boot → booted callback も走るため、本 binder が付けた throttle は
 *   **route cache に焼き込まれる**。その cache を読んだ次回起動でも booted は走るので、
 *   「既存があれば常に例外」にすると cached 起動が必ず落ちる。
 *   したがって「同じ limiter がちょうど 1 本なら no-op」を正とする。
 *
 * ★判定は文字列の完全一致にしない:
 *   gatherRouteMiddleware() の entry は `{class}:{params}` 形式で出る。
 *   class 部は cache driver によって ThrottleRequests / ThrottleRequestsWithRedis の
 *   どちらにもなりうる (後者は前者を継承)。class 部は is_a() で、params 部は
 *   limiter 名の完全一致で比較する。
 */
final class RouteThrottleBinder
{
    /**
     * named route へ `throttle:{$limiter}` を冪等に後付けする。
     *
     * @param  string  $routeName  Fortify / Cashier 等が登録した route 名
     * @param  string  $limiter    named limiter 名 または `{max},{decay}` 形式
     *
     * @throws RuntimeException route が引けない / 別の throttle が既に付いている / 2 本以上ある
     */
    public static function attachByName(Router $router, string $routeName, string $limiter): void;

    /**
     * 実効 middleware 列のうち throttle middleware の entry を返す。
     *
     * 目録検査 (ThrottleCoverageInventoryTest) と共有する唯一の判定点。
     *
     * @return list<string> `{class}:{params}` 形式の entry (params なしなら class のみ)
     */
    public static function throttleEntries(Router $router, Route $route): array;

    /** entry の class 部が throttle middleware か。 */
    public static function isThrottleEntry(string $middlewareEntry): bool;
}
```

実装骨子:

```php
public static function isThrottleEntry(string $middlewareEntry): bool
{
    $class = Str::before($middlewareEntry, ':'); // class 名に ':' は含まれない
    return is_a($class, ThrottleRequests::class, true);
}

public static function throttleEntries(Router $router, Route $route): array
{
    return array_values(array_filter(
        $router->gatherRouteMiddleware($route),
        static fn (string $entry): bool => self::isThrottleEntry($entry),
    ));
}

public static function attachByName(Router $router, string $routeName, string $limiter): void
{
    $routes = $router->getRoutes();
    // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
    // (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes と同じ前提)
    $routes->refreshNameLookups();

    $route = $routes->getByName($routeName);
    if (! $route instanceof Route) {
        throw new RuntimeException(
            "throttle を後付けすべき route [{$routeName}] が見つかりません。"
            .'vendor package が update で route 名を変えた可能性があります。'
            .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
        );
    }

    $entries = self::throttleEntries($router, $route);
    if ($entries === []) {
        $route->middleware('throttle:'.$limiter);

        return;
    }

    if (count($entries) === 1 && Str::after($entries[0], ':') === $limiter) {
        return; // route:cache 由来の再適用 = 冪等 no-op
    }

    throw new RuntimeException(
        "route [{$routeName}] に想定外の throttle が付いています: ".implode(', ', $entries)
        .' (期待: throttle:'.$limiter.')。二重付与は実効上限を半減させるため起動を止めます。',
    );
}
```

> ⚠ `Str::after($entries[0], ':')` は entry に `:` が無い場合 (パラメータなし throttle) に
> entry 全体を返す。この場合は limiter 名と一致しないため必ず例外側に落ちる = 意図どおり。
> 実装時は `str_contains($entries[0], ':')` の明示チェックを添えて可読性を上げること。

### PHPStan 適合チェック

- [x] 戻り値型を明示 (`void` / `list<string>` / `bool`)
- [x] `getByName()` の `?Route` を `instanceof` で narrowing
- [x] 配列返却は `list<string>` を PHPDoc で明示 (DTO 化は不要 — 内部 helper)
- [x] `mixed` を扱わない (Reflection 不使用)

### テスト計画

`tests/Feature/Security/RouteThrottleBinderTest.php` (新規):

- [ ] 未登録の route 名を渡すと `RuntimeException` (メッセージに route 名を含む)
- [ ] throttle 無しの route に付与すると実効列に 1 本増える
- [ ] 同じ limiter で 2 回呼んでも実効列は 1 本のまま (冪等 no-op)
- [ ] 別 limiter が既にある route へ呼ぶと `RuntimeException`
- [ ] `isThrottleEntry()` が `ThrottleRequests` / `ThrottleRequestsWithRedis` の両方で true、
      `Illuminate\Auth\Middleware\Authenticate:web` で false

### リスク

- vendor package の update で route 名が変わると**起動しなくなる**。
  これは意図した挙動 (silent degradation を作らない) だが、
  アップグレード時に気付けるよう例外メッセージへ対処方法を書く。

---

## 施策 2: 付与漏れの目録検査

### 変更箇所

- 新規: `app/Enums/Security/ThrottleCoverageExemption.php`
- 新規: `tests/Architecture/ThrottleCoverageInventoryTest.php`

### enum (新規)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「保護対象群に属する route が throttle を持たないことが正しい」と裁定された理由の分類。
 *
 * tests/Architecture/ThrottleCoverageInventoryTest.php が deny-by-default で
 * 「throttle ちょうど 1 本」か「本 enum + 具体的根拠付きの exemption」かを機械強制する。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「throttle を貼るべき route」である。
 */
enum ThrottleCoverageExemption: string
{
    /**
     * 定数メタデータ応答。
     * 適用条件: DB アクセス・暗号処理・外部呼び出し・メール送信・ファイル書込を一切伴わず、
     * 応答が config と url() だけで決まる。
     */
    case StaticMetadataResponse = 'static_metadata_response';

    /**
     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
     */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';

    /**
     * セッション破棄のみを行い、推測可能な秘密を一切扱わない route。
     * 適用条件: 認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い。
     */
    case SessionTeardownOnly = 'session_teardown_only';

    /** local / テスト実行時のみ **route 登録自体が起きない**デバッグ用 route。 */
    case LocalOnlyDebugRoute = 'local_only_debug_route';

    /**
     * 防御が route ではなく component 内にある。
     * 適用条件: 単一 endpoint に多数の操作が相乗りしており、route 単位の bucket では
     * 無関係な操作を巻き添えにする。かつ component 側に実際の制限実装がある。
     */
    case ComponentLevelLimiter = 'component_level_limiter';

    /**
     * 有効な署名が無ければ本体処理に到達しない route。
     * 適用条件: ハンドラ冒頭で署名検証を行い、不成立なら副作用ゼロで短絡する。
     */
    case SignatureRequiredBeforeEffect = 'signature_required_before_effect';
}
```

### テスト (新規) — 母集団セレクタ

```php
/** 変更系 HTTP メソッド。 */
function throttleCoverageMutatingMethods(): array; // ['POST','PUT','PATCH','DELETE']

/** 認証面の route 名パターン (S3)。 */
function throttleCoverageAuthSurfacePattern(): string;
// '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
//  .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#'

/** 母集団件数の下限 (空振り drift ガード。実測 47 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int; // 40

/** exemption 件数の上限 (形骸化ガード。実測 11 に対し余裕 3)。 */
function throttleCoverageExemptionCap(): int; // 14

/** exemption 理由の最低文字数。 */
function throttleCoverageReasonMinLength(): int; // 30

/**
 * route の inventory キー (名前があれば名前、無ければ `{METHOD} /{uri}`)。
 * HEAD は methods() から除外して主メソッドを使う。
 */
function throttleCoverageRouteLabel(Route $route): string;

/** 保護対象群 (S1 ∪ S2 ∪ S3)。 */
function throttleCoverageProtectedRoutes(): array;
```

セレクタの実装契約:

| 群 | 条件 | 意図 |
|----|------|------|
| S1 | method ∈ {POST,PUT,PATCH,DELETE} **かつ** 実効列に `Illuminate\Auth\Middleware\Authenticate` を含まない | 未認証で本体に到達しうる変更系 |
| S2 | uri が `api/` / `oauth/` / `.well-known/oauth-` で始まる **かつ** 実効列に `Illuminate\Session\Middleware\StartSession` を含まない | ステートレスな機械向け経路 |
| S3 | route 名が認証面パターンに一致 **かつ** method ∈ {POST,PUT,PATCH,DELETE} | 認証済み側も含む credential 面 |

- 実効列は `Route::getFacadeRoot()->gatherRouteMiddleware($route)` で取得する
  (**`route:list --json` は使わない** — group 名 `'web'` が展開されないため誤判定する)。
- throttle 判定は `RouteThrottleBinder::isThrottleEntry()` を共有する。

### テスト (新規) — exemption inventory

```php
/**
 * throttle を持たないことが正しいと裁定した route の inventory。
 *
 * @return array<string, array{ThrottleCoverageExemption, string}>
 */
function throttleCoverageExemptions(): array
```

実装時に登録する 11 件 (実査に基づく確定リスト):

| inventory キー | 分類 | 理由の骨子 (30 文字以上・具体的根拠必須) |
|---------------|------|------------------------------------------|
| `mcp.oauth.authorization-server` | `StaticMetadataResponse` | `Laravel\Mcp\Server\Registrar::authorizationServerMetadata()` が config と `url()` と `route()` だけで組む定数 JSON。DB・暗号・外部呼び出しを伴わない |
| `mcp.oauth.authorization-server.nested` | 同上 | 同上 (`{path}` は応答内容に影響しない) |
| `mcp.oauth.protected-resource` | 同上 | `protectedResourceMetadata()` が同様に定数 JSON を返す |
| `mcp.oauth.protected-resource.nested` | 同上 | 同上 |
| `GET /api/v1/mcp` | `VendorMethodNotAllowedStub` | `Registrar::web()` が登録する `response('', 405)->header('Allow','POST')` の固定応答。MCP 仕様上の SSE 非対応表明であり本体へ到達しない |
| `DELETE /api/v1/mcp` | 同上 | 同上 |
| `logout` | `SessionTeardownOnly` | `auth:web` 必須。セッション破棄と `Inertia::clearHistory()` のみで、失敗しても攻撃者が得る情報が無い |
| `filament.admin.auth.logout` | 同上 | Filament panel の logout。同上 |
| `debug.login-as` | `LocalOnlyDebugRoute` | `routes/web.php:613` の `app()->isLocal() \|\| app()->runningUnitTests()` で **route 登録自体が起きない**。`LocalOnly` middleware が二重防御 |
| `default-livewire.update` | `ComponentLevelLimiter` | Filament 管理画面の全操作が相乗りする単一 endpoint。route 単位の bucket は無関係操作を巻き添えにする。実際の制限は component 内 (`vendor/filament/filament/src/Auth/Pages/Login.php` の `rateLimit(5)`、Register / ResetPassword / EmailVerificationPrompt も同様) |
| `storage.local.upload` | `SignatureRequiredBeforeEffect` | `Illuminate\Filesystem\ReceiveFile::__invoke()` が本体到達前に `abort_unless($request->boolean('upload') && $request->hasValidRelativeSignature(), production ? 404 : 403)`。前提は施策 8 の Feature テストが固定する |

### テストケース

- [ ] `保護対象 route は throttle をちょうど 1 本持つか exemption inventory に明示分類されている (未知は fail)`
- [ ] `二重付与 (throttle 2 本以上) は fail する`
- [ ] `exemption inventory の key は現存 route ラベル (stale 検出)`
- [ ] `exemption inventory の値は enum + 30 文字以上の理由`
- [ ] `母集団件数が floor を下回らない (セレクタの空振り検出)`
- [ ] `exemption 件数が cap を超えない (形骸化ガード)`

### リスク

- セレクタが広すぎると exemption が増えて形骸化する → cap で検出する。
- vendor が新しい route を増やすと母集団に入り fail する → **それが目的** (無音の付与漏れを止める)。

---

## 施策 3: 認証系 vendor route への throttle 後付け

### 変更箇所

- `app/Providers/FortifyServiceProvider.php`
  - `configureRateLimiters()` に新 limiter 3 本を追加 (施策 6 と同じ差分の一部)
  - `attachRecentAuthToSensitiveRoutes()` と**同じ booted callback の流儀**で
    `attachThrottleToFortifyRoutes()` を新設

### 現行コード (根拠)

`vendor/laravel/fortify/routes/routes.php` が `config('fortify.limiters.*')` を読むのは
**`login` / `two-factor` / `passkeys` / `verification` の 4 キーだけ**である (実査確認)。
`forgot-password` / `reset-password` / `register` / `confirm-password` / `user/password` /
2FA 管理は **設定で貼れない** → 標準形の第 2 段 (route 名で後付け + 起動時 fail-fast) が正しい手段。

### 変更後コード

```php
/**
 * Fortify が登録する認証系 route への throttle 後付け表。
 *
 * config/fortify.php の `limiters` は login / two-factor / passkeys / verification の
 * 4 キーしか受け付けないため、それ以外は route 名での後付けで賄う
 * (標準形「貼る仕組みの 3 段優先順」の第 2 段。第 1 段で貼れるものは第 1 段のまま)。
 *
 * 閾値の根拠:
 *  - password-reset-request / password-reset-submit / account-register は
 *    「未認証 + メール送信または credential 総当り」であり、**既に本番稼働中の
 *    同性質エンドポイント (inquiry / login) と同値**にする (新しい値を発明しない)。
 *  - `6,1` は recent-auth.password / settings.password.store と同値 (自分の credential 操作)。
 *  - `10,1` は onboarding.activate-personal と同値 (認証済みの管理操作)。
 *
 * @var array<string, string> route name => throttle 指定
 */
private const THROTTLED_FORTIFY_ROUTES = [
    'password.email' => 'password-reset-request',
    'password.update' => 'password-reset-submit',
    'register.store' => 'account-register',
    'password.confirm.store' => '6,1',
    'user-password.update' => '6,1',
    'two-factor.enable' => '10,1',
    'two-factor.confirm' => '10,1',
    'two-factor.disable' => '10,1',
    'two-factor.regenerate-recovery-codes' => '10,1',
];

private function attachThrottleToFortifyRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        $router = $app->make(Router::class);
        foreach (self::THROTTLED_FORTIFY_ROUTES as $name => $throttle) {
            RouteThrottleBinder::attachByName($router, $name, $throttle);
        }
    });
}
```

`boot()` に `$this->attachThrottleToFortifyRoutes();` を追加する。

> **なぜ `throttle:6,1` / `throttle:10,1` の inline 指定を許すか**:
> 対象はいずれも「**認証済みかつ actor 自身に閉じる操作**」であり、
> Laravel 既定のキー (認証済みなら `sha1($user->getAuthIdentifier())`) が
> ちょうど求める数える単位になる。ここで named limiter を 9 本発明するのは
> AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。
> **キー明示規約 (`{レーン}:{種別}:{値}`) の対象は named limiter に限る**ことを
> 施策 7 のテスト docblock に明記する。

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: `tests/Architecture/RecentAuthRouteTest.php` は middleware 集合を
  完全一致で見ていないか要確認 (見ている場合は期待値を更新)。
  `tests/Architecture/PasskeyRouteProtectionTest.php` は passkey 系のみを対象とするため影響なし。

### リスク

- **実効順**: `ThrottleRequests` は priority list に載っているため `Authenticate` の後・
  `SubstituteBindings` の前に整列する。`recent-auth` (`RequireRecentAuth`) は priority list に
  無いため append 位置に留まる = throttle が先行する。
  passkey 系の既存契約 (`PasskeyRouteProtectionTest`) と同じ関係になる。
  **施策 9 のテストでこの順序を明示的に固定する**。
- 2FA 管理 route に throttle を付けても「step-up なしで到達可能」という別課題
  (`config/fortify.php:165-168` の TODO) は解決しない。**解決したことにしない**。

---

## 施策 4: webhook 2 本への throttle 付与

### 変更箇所

- `app/Providers/AppServiceProvider.php`: `configureWebhookRateLimiters()` を新設 + `boot()` から呼ぶ
- `app/Providers/AppServiceProvider.php`: `cashier.webhook` への後付け (booted callback)
- `routes/web.php`: `webhooks.ses` に `->middleware('throttle:webhook-ses')` を追加 (自前 route なので直書き)

### 変更後コード

```php
/**
 * 未認証 webhook (SES/SNS 通知・Stripe) の RateLimiter。
 *
 * ★固定キーの全体天井は**置かない**。throttle middleware は署名検証より前に走るため、
 *   固定キーのバケットを署名前に消費させると「無効 body の連打で正当な通知を 429 にできる」
 *   = 攻撃者が任意に業務を止められる口になる (標準形 (3) の適用条件を満たさない)。
 *
 * ★レーンは送信元ごとに分ける。SES への攻撃で Stripe を止めない。
 *
 * 閾値の根拠: 正常時ピークは分あたり数件〜数十件 (SES bounce/complaint、Stripe イベント)。
 * 単一送信元からの署名検証コスト増幅 (SNS は証明書取得を伴う) を有界にする値として
 * ピークの 1〜2 桁上の 300/min を置く。429 は SNS も Stripe も再送対象であり
 * 恒久喪失しない (Stripe は最大 3 日間の指数バックオフ)。
 */
private function configureWebhookRateLimiters(): void
{
    RateLimiter::for('webhook-ses', fn (Request $request): Limit => Limit::perMinute(300)
        ->by('webhook-ses:ip:'.($request->ip() ?? 'unknown')));

    RateLimiter::for('webhook-stripe', fn (Request $request): Limit => Limit::perMinute(300)
        ->by('webhook-stripe:ip:'.($request->ip() ?? 'unknown')));
}

/** Cashier が自動登録する webhook route への後付け (設定で貼れないため第 2 段)。 */
private function attachThrottleToVendorRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        RouteThrottleBinder::attachByName($app->make(Router::class), 'cashier.webhook', 'webhook-stripe');
    });
}
```

`routes/web.php` の SES webhook:

```php
Route::post('/ses/notification', ...)
    ->withoutMiddleware([...])          // 既存のまま
    ->middleware(['throttle:webhook-ses', 'sns.signature'])   // ← throttle を追加
    ->name('webhooks.ses');
```

> 宣言順ではなく**実効順**が契約である (priority list により ThrottleRequests が先行)。
> 施策 9 のテストで「throttle → 署名検証」の実効順を固定する。

### リスク

- 共有クラウド出口・proxy 設定の誤りで正当送信元が巻き添えになりうる。
  **監視項目**: 送信元 IP の分布 / 429 発生率 (運用への申し送りとして TODO 本文に書く)。

---

## 施策 5: 招待受諾への throttle 付与

### 変更箇所

- `routes/web.php`: `invitations.accept.store` に `->middleware('throttle:10,1')`

招待トークンは hash 照合されるが、総当り試行そのものを有界にする。
`onboarding.activate-personal` と同値 (認証済みの一回性操作)。

---

## 施策 6: キー規約 `{レーン}:{種別}:{値}` への統一

**閾値は 1 つも変えない**。キー文字列のみを変更する。

### 6-1. `login` (実害の是正 — 最優先)

現行 (`app/Providers/FortifyServiceProvider.php:169-176`):

```php
RateLimiter::for('login', function (Request $request) {
    $username = $request->input(Fortify::username());
    $throttleKey = Str::transliterate(
        Str::lower(is_string($username) ? $username : '').'|'.$request->ip(),
    );

    return Limit::perMinute(5)->by($throttleKey);
});
```

変更後:

```php
/**
 * ログイン試行の RateLimiter。閾値 5/min は据え置き (プロダクト依存の既定値)。
 *
 * ★Str::transliterate を廃止した理由:
 *   app/Support/EmailNormalizer の docblock が「legitimate な Unicode email を
 *   別 user に collapse させるリスクがあるため使わない」と明記しているのに、
 *   本 limiter だけが使っており設計意図と実装が矛盾していた。
 *   実害は「無関係アカウントの巻き添えロックアウト」。
 *
 * ★email は EmailHash (HMAC-SHA256 / app.key 鍵付き) でハッシュ化してからキーに入れる。
 *   正規化は保存・検索・inquiry と同一の EmailNormalizer に集約する。
 *   limiter は validation より前に走るため email が非 string で来うる → is_string ガード必須。
 */
RateLimiter::for('login', function (Request $request): Limit {
    $raw = $request->input(Fortify::username());
    $email = is_string($raw) && $raw !== '' ? EmailNormalizer::normalize($raw) : '';
    $emailKey = $email !== '' ? EmailHash::compute($email) : 'anon';

    return Limit::perMinute(5)->by(
        'login:email-ip:'.$emailKey.':'.($request->ip() ?? 'unknown'),
    );
});
```

### 6-2. `two-factor` / `passkeys`

```php
RateLimiter::for('two-factor', function (Request $request): Limit {
    $loginId = $request->session()->get('login.id');

    return is_scalar($loginId)
        ? Limit::perMinute(5)->by('two-factor:login-id:'.$loginId)
        : Limit::perMinute(5)->by('two-factor:ip:'.($request->ip() ?? 'unknown'));
});

RateLimiter::for('passkeys', function (Request $request): Limit {
    $identifier = $request->user()?->getAuthIdentifier();

    return is_scalar($identifier)
        ? Limit::perMinute(10)->by('passkeys:user:'.$identifier)
        : Limit::perMinute(10)->by('passkeys:ip:'.($request->ip() ?? 'unknown'));
});
```

### 6-3. 新規 3 本 (施策 3 が参照する)

```php
/**
 * 未認証 + メール送信 / credential 総当りを伴う認証系 POST の RateLimiter。
 *
 * 閾値は**既に本番稼働中の同性質エンドポイントと同値**にする (新しい値を発明しない):
 *  - IP 単独 5/min      = inquiry (公開問い合わせフォーム) / login と同値
 *  - IP+email 10/60min  = inquiry と同値
 *
 * 2 系統に分ける理由: IP 単独は「1 本の回線からのメール爆撃」を、
 * IP+email は「同一宛先への長時間の反復」を止める (数える単位が違う)。
 */
private function configureAuthFormRateLimiters(): void
{
    foreach ([
        'password-reset-request' => Fortify::email(),
        'password-reset-submit' => Fortify::email(),
        'account-register' => Fortify::username(),
    ] as $lane => $field) {
        RateLimiter::for($lane, function (Request $request) use ($lane, $field): array {
            [$ip, $emailKey] = self::ipAndEmailKey($request, $field);

            return [
                Limit::perMinute(5)->by($lane.':ip:'.$ip),
                Limit::perMinutes(60, 10)->by($lane.':ip-email:'.$ip.':'.$emailKey),
            ];
        });
    }
}

/**
 * limiter キー用の IP と email ハッシュを組み立てる。
 *
 * @return array{0: string, 1: string} [ip, emailKey]
 */
private static function ipAndEmailKey(Request $request, string $field): array
{
    $raw = $request->input($field);
    $email = is_string($raw) && $raw !== '' ? EmailNormalizer::normalize($raw) : '';

    return [
        $request->ip() ?? 'unknown',
        $email !== '' ? EmailHash::compute($email) : 'anon',
    ];
}
```

> ⚠ **施策 7 の scanner は `RateLimiter::for($lane, ...)` を「非リテラル」として fail させる**。
> ループでの一括登録は採らず、**3 本を明示的に 3 回書く** (scanner の deny-by-default と両立させる)。
> 共通部分は `ipAndEmailKey()` と private helper `registerAuthFormLimiter(string $lane, string $field)`
> に切り出し、`RateLimiter::for('password-reset-request', ...)` のように
> **第 1 引数だけは必ずリテラル**で書くこと。

### 6-4. `render-trigger` / `inquiry` / `api-*` / `mcp`

| limiter | 現行キー | 変更後キー |
|---------|---------|-----------|
| `render-trigger` | `render-trigger:{userId}:{orgId}` | `render-trigger:actor-org:{userId}:{orgId}` |
| `inquiry` (email) | `hash('sha256', $email)` | `EmailHash::compute($email)` (鍵付き HMAC) |
| `apiRateKey()` | `api-key:{id}` / `oauth-user:{id}` / `ip:{ip}` | `api:api-key:{id}` / `api:oauth-user:{id}` / `api:ip:{ip}` |
| `mcpRateKey()` | `mcp:user:{id}` / `ip:mcp:{ip}` | `mcp:user:{id}` (据え置き) / `mcp:ip:{ip}` |

`inquiry` の docblock も「sha256 でハッシュ化」→「EmailHash (鍵付き HMAC) でハッシュ化。
単純 sha256 は低エントロピーな email に対して辞書攻撃に弱い」へ更新する。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Feature/Security/NamedRateLimiterKeyTest.php` はヘッダの残数のみを見るため影響なし。
  既存の login throttle テスト (存在すれば) は閾値不変のため通るはずだが、
  キー文字列を直接 assert しているテストがあれば更新する (実装時に `rg 'transliterate|throttleKey'` で確認)。

### リスク

- デプロイ時に既存バケットがリセットされる (一過性、閾値不変)。

---

## 施策 7: キー規約の機械検査

### 変更箇所

- 新規: `tests/Support/RateLimiterRegistrationScanner.php`
- 新規: `tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php`
- 新規: `tests/Architecture/RateLimiterKeyConventionTest.php`

### scanner (新規)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * `RateLimiter::for(...)` 登録の token 走査器。
 *
 * ★正規表現を使わない理由 (deny-by-default では誤合格が最悪の失敗モード):
 *   `RateLimiter::for(\n    'name',` のような改行入り呼び出しや
 *   `RateLimiter::for(self::NAME, …)` のような非リテラル引数を取りこぼしつつ、
 *   「検出した名前の集合が inventory と一致」は成功してしまう。
 *   token 走査で**全呼び出しを数え、分類できなかったものを明示的に fail させる**。
 *
 * ★コメント / 文字列リテラル中の記述で誤検出しないよう、判定は token 列上で行う。
 */
final class RateLimiterRegistrationScanner
{
    /**
     * PHP ソース中の `RateLimiter::for(...)` 呼び出しを走査する。
     *
     * @return array{names: list<string>, unresolved: list<string>}
     *   names      = 第 1 引数がリテラル文字列だった limiter 名
     *   unresolved = 第 1 引数がリテラルでなかった呼び出しの位置 (`{path}:{line}`)
     */
    public static function scan(string $source, string $relativePath): array;

    /**
     * ディレクトリ配下の *.php を再帰走査して集計する。
     *
     * @return array{names: list<string>, unresolved: list<string>}
     */
    public static function scanDirectory(string $absoluteDirectory, string $relativeRoot): array;
}
```

判定アルゴリズム (token 列):

1. `token_get_all()` で走査し、`T_STRING('RateLimiter')` → `T_DOUBLE_COLON` → `T_STRING('for')` → `(` の並びを探す
   (`\Illuminate\Support\Facades\RateLimiter::for` のような完全修飾も `T_NAME_FULLY_QUALIFIED` で受理する)
2. `(` の直後の非空白 token が `T_CONSTANT_ENCAPSED_STRING` なら `names[]` に追加
   (クォート除去は `substr($token, 1, -1)`。変数展開のない single/double quote のみ受理)
3. そうでなければ `unresolved[]` に `{path}:{line}` を追加

### scanner の単体テスト (新規)

`tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php`:

- [ ] 単純な `RateLimiter::for('login', ...)` を検出する
- [ ] 改行・空白を挟んだ `RateLimiter::for(\n 'login',` を検出する
- [ ] 完全修飾 `\Illuminate\Support\Facades\RateLimiter::for('x', ...)` を検出する
- [ ] `RateLimiter::for($name, ...)` / `RateLimiter::for(self::NAME, ...)` を `unresolved` に入れる
- [ ] コメント `// RateLimiter::for('fake')` と文字列 `"RateLimiter::for('fake')"` を検出しない
- [ ] `OtherClass::for('x')` を検出しない

### 規約テスト (新規)

```php
/**
 * named limiter のキー規約 `{レーン}:{種別}:{値}` の behavioral proof。
 *
 * ★検査対象は **named limiter のみ**。inline throttle (`throttle:6,1` 等) は
 *   フレームワーク既定のキー (認証済み = user id / 未認証 = ハッシュ化 IP) を使い、
 *   これは「認証済みかつ actor 自身に閉じる操作」では正しい数える単位である。
 *   キー明示規約は「自前でキーを組み立てるとき」の規約であり、対象を named limiter に限る。
 */

/** キー規約の正規表現。 */
function rateLimiterKeyConventionPattern(): string; // '#^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:#'

/**
 * limiter ごとの評価シナリオと期待されるキー接頭辞。
 *
 * @return array<string, array{
 *   scenarios: array<string, callable(): \Illuminate\Http\Request>,
 *   expectedKeyPrefixes: list<string>,
 *   emailPlaintexts: array<string, string>,
 * }>
 *   scenarios          = 分岐名 => Request ビルダ (guest / authenticated / with-email 等)
 *   expectedKeyPrefixes = produce されるべき `{レーン}:{種別}` の**完全な**集合
 *   emailPlaintexts     = email を扱う scenario 名 => その scenario が使う平文 email
 */
function rateLimiterKeyInventory(): array;
```

inventory の内容 (15 本):

| limiter | scenarios | expectedKeyPrefixes |
|---------|-----------|---------------------|
| `login` | `with-email`, `no-email` | `login:email-ip` |
| `two-factor` | `with-login-id`, `guest` | `two-factor:login-id`, `two-factor:ip` |
| `passkeys` | `authenticated`, `guest` | `passkeys:user`, `passkeys:ip` |
| `render-trigger` | `authenticated-with-org`, `guest` | `render-trigger:actor-org` |
| `inquiry` | `with-email`, `no-email` | `inquiry:ip`, `inquiry:ip-email` |
| `password-reset-request` | `with-email`, `no-email` | `password-reset-request:ip`, `password-reset-request:ip-email` |
| `password-reset-submit` | 同上 | 同上 (レーン名のみ差し替え) |
| `account-register` | 同上 | 同上 |
| `api-read` / `api-write` / `api-status` | `with-api-key`, `guest` | `api:api-key`, `api:ip` |
| `api-mcp` | `guest` | `mcp:ip` |
| `oauth-register` | `guest` | `oauth-register:ip` |
| `webhook-ses` | `guest` | `webhook-ses:ip` |
| `webhook-stripe` | `guest` | `webhook-stripe:ip` |

> `two-factor` の scenario は `$request->setLaravelSession(new \Illuminate\Session\Store(...))` で
> session を注入する (session 無しの Request では `RuntimeException` になる)。
> `api-*` の `with-api-key` は `$request->attributes->set('api_key', ApiKey::factory()->...->make())` で作る
> (`oauth-user` 分岐は guard 解決が要るため scenario から外し、`expectedKeyPrefixes` にも入れない。
>  入れると「宣言したのに produce されない prefix」で fail する = 設計どおり)。

テストケース:

- [ ] `scan で検出した limiter 名の集合が inventory と完全一致する (未知 limiter は fail)`
- [ ] `scan の unresolved が 0 件である (解析できない登録を沈黙させない)`
- [ ] `全 scenario の全 Limit::$key が規約パターンに一致する`
- [ ] `produce された {レーン}:{種別} 集合が expectedKeyPrefixes と完全一致する`
- [ ] `email を扱う limiter のキーに平文 email も正規化済み email も含まれない`
- [ ] `email を扱う limiter のキーに EmailHash::compute($plaintext) の値が含まれる`

### PHPStan 適合チェック

- [x] `app(\Illuminate\Cache\RateLimiter::class)->limiter($name)` は `?Closure` → `Assert::notNull()`
- [x] closure の戻り値は `mixed` → `Assert::isInstanceOf(Limit::class)` / `Assert::allIsInstanceOf()` で
      `Limit` / `array<int, Limit>` に絞る
- [x] Reflection は 1 箇所も使わない
- [x] `scan()` の戻り値は `array{names: list<string>, unresolved: list<string>}` を PHPDoc で明示

---

## 施策 8: exemption 前提の Feature 固定

### 変更箇所

- 新規: `tests/Feature/Security/ThrottleExemptionPremiseTest.php`

exemption は「throttle を持たないことが**正しい**」という主張であり、
その根拠 (前提) が vendor 更新で崩れたら検出できなければならない。

- [ ] `署名なしの PUT /storage/{path} は本体に到達しない` (非 production では 403)
- [ ] `GET /api/v1/mcp は 405 と Allow: POST を返す` (定数スタブであることの固定)
- [ ] `DELETE /api/v1/mcp は 405 と Allow: POST を返す`
- [ ] `.well-known/oauth-protected-resource は DB クエリ 0 件で応答する`
      (`DB::listen` でクエリを数える。「定数メタデータ」という主張の behavioral proof)
- [ ] `debug.login-as は testing 環境でのみ登録される` (既存の相当テストがあれば流用)

---

## 施策 9: 新 limiter の behavioral テスト

### 変更箇所

- 新規: `tests/Feature/Security/AuthThrottleCoverageTest.php`

- [ ] `POST /forgot-password は 5 回目まで通り 6 回目で 429`
- [ ] `429 応答は Retry-After と X-RateLimit-Limit / X-RateLimit-Remaining を持つ`
      (フレームワーク既定のヘッダを削らない・書き換えないことの固定)
- [ ] `POST /forgot-password は大文字小文字違いの email で同じ bucket を消費する` (正規化の証明)
- [ ] `POST /forgot-password は同一 IP なら email を変えても IP レーンで止まる` (メール爆撃の抑制)
- [ ] `POST /reset-password / POST /register も同様に 429 になる`
- [ ] `POST /ses/notification は throttle が署名検証より先に走る`
      (実効 middleware 列で `ThrottleRequests` の index < `VerifySnsSignature` の index)
- [ ] `2FA 管理 route は throttle が recent-auth より先に走る`
      (`PasskeyRouteProtectionTest` と同じ検証形式。`two-factor.disable` で確認)
- [ ] `login limiter は Unicode で異なる 2 つの email を同じ bucket に collapse させない`
      (`Str::transliterate` 廃止の回帰テスト。巻き添えロックアウトの防止)

### 個別 `DatabaseTransactions` を使わないこと

`tests/Pest.php` の `RefreshDatabase` グローバル適用に従う。
RateLimiter は cache store を使うため、テストごとに `RateLimiter::clear()` するか
`Cache::flush()` を `beforeEach` で行う (既存 `NamedRateLimiterKeyTest` の流儀に合わせる)。

---

## 施策 10: ドキュメント追記

- `docs/app-integration-guide.md`: 「新しい route を足すときの throttle 規約」を追記
  (3 段の優先順 / キー規約 / 目録検査が deny-by-default であること)
- `AGENTS.md` の「ドメイン固有規約」に 1 項追加:
  **流量制限の付与規約** — 保護対象群 (未認証変更系 / ステートレス機械向け / 認証面変更系) は
  throttle をちょうど 1 本持つか `ThrottleCoverageExemption` へ理由付き登録が必須。
  named limiter のキーは `{レーン}:{種別}:{値}`。email は `EmailNormalizer` + `EmailHash` を通す。
  vendor route への後付けは `RouteThrottleBinder` 経由 (起動時 fail-fast)。

---

## 検証コマンドと期待結果

| # | コマンド | 期待結果 |
|---|---------|---------|
| 1 | `composer test -- --filter=ThrottleCoverageInventoryTest` (**実装前**) | **fail**。23 件の未分類 route が列挙される (テストファースト) |
| 2 | `composer test -- --filter=ThrottleCoverageInventoryTest` (実装後) | green。母集団 47 / exemption 11 |
| 3 | `composer test -- --filter=RateLimiterKeyConventionTest` | green |
| 4 | `composer test -- --filter=RateLimiterRegistrationScannerTest` | green |
| 5 | `composer test -- --filter=RouteThrottleBinderTest` | green |
| 6 | `composer test -- --filter=AuthThrottleCoverageTest` | green |
| 7 | `composer test -- --filter=ThrottleExemptionPremiseTest` | green |
| 8 | `composer test -- --filter=NamedRateLimiterKeyTest` | green (既存の回帰なし) |
| 9 | `composer test -- --filter=PasskeyRouteProtectionTest` | green (既存の回帰なし) |
| 10 | `php artisan route:cache && php artisan route:list > /dev/null && php artisan route:clear` | 例外なく完了 (binder の冪等性。**dev DB には触らない**) |
| 11 | `composer phpstan` | level 10 green |
| 12 | `vendor/bin/pint --test` | green |
| 13 | `composer test` | 全 green (最後に 1 回だけ。グローバルロック配下のため待ちは正常) |

---

## 段階分け

### このタスクでやる

施策 1〜10 (上表のすべて)。

### 後続 TODO 候補 (このタスクでは**やらない**)

| # | 内容 | 理由 |
|---|------|------|
| B1 | **固定キーの全体天井** (標準形 (3)) の導入 | middleware 位置では署名検証前に消費され、攻撃者に「正当通知を止める手段」を与える。採るなら「署名検証成功後にだけ消費される位置」(Controller / Service 層) の設計が要る |
| B2 | **秘密を返す GET の保護** (`two-factor.qr-code` / `secret-key` / `recovery-codes`) | `config/fortify.php:165-168` の TODO(template) と一体で **recent-auth 化**として設計すべき。throttle だけ貼ると本質 (step-up 不足) が隠れる |
| B3 | Filament / Livewire 面の rate limit 契約の明文化 | `default-livewire.update` の exemption を恒久化するか、Filament 側の component 制限を inventory 化するかの判断 |
| B4 | DCR 後付け (`routes/ai.php:47-72`) と `PasskeyServiceProvider` の後付けを `RouteThrottleBinder` へ統合 | 両者は既に fail-fast 済みで動作しており、触る必要が無い (思考原則 2)。統合するなら DCR は route 名を持たないため `attachByUri()` の追加が要る |
| B5 | 429 応答の経路別契約 (フォーム内エラー / エラー画面 / API 形式) | AG-097 で `error-response-contract` feature へ切り出し済み。**本 feature の射程外** |
| B6 | 家系への還流 (`laravel-claude-template` への目録検査の移植) | 台帳上テンプレートは必須項目の欠落が家系最多 (9 件)。aicue で稼働実績を作ってから c2c 経由で提案する |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 施策 1〜10 は「目録検査を入れる → 赤くなった穴を塞ぐ → キー規約を揃える」という一本の依存鎖であり、分割すると中間状態で CI が赤いまま残る。単一 worktree で順に積むのが最短 |
| 競合リスク | `app/Providers/AppServiceProvider.php` / `app/Providers/FortifyServiceProvider.php` / `routes/web.php` を触るため、認証系・課金系の他タスクと同時進行すると衝突しやすい。`config/fortify.php` は触らない (限定 4 キーしか受け付けないため) |
| テスト順序 | **テストファースト**。施策 2 のテストを先に書いて **fail を確認**してから施策 3〜6 に入る (AGENTS.md 思考原則 5) |


---

## 関連する現行コード

### app/Providers/FortifyServiceProvider.php (抜粋 L52-196)
```php

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
     * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
     * 到達させない (姉妹操作: organizations.members.two-factor.reset /
     * settings.account.destroy 等と同基準)。
     * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
     * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
     *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
     *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
     *     self-disable が許可される非 enforced 組織のユーザー。
     * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
     *
     * @var list<string>
     */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.disable',
    ];

    /**
     * email 変更時のみ recent-auth を課す条件付き付与 (氏名のみ変更は素通し)。
     * profile 更新は Fortify 登録ルートのため booted で後付けする。
     *
     * @var array<string, string> route name => middleware alias
     */
    private const CONDITIONAL_RECENT_AUTH_ROUTES = [
        'user-profile-information.update' => 'recent-auth.on-email-change',
    ];

    public function register(): void
    {
        // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
        // 挙動の意図は各 Response クラスの docblock を参照。
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        // verify 完了着地: continuation があれば onboarding.checkout、無ければ Fortify 既定と同値。
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
        $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
        // profile / password 更新は success flash に統一し保存完了を toast 化する
        // (status キーは flash-to-toast が gating するため toast にならない)。
        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileUpdatedResponse::class);
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdatedResponse::class);
        // password reset は Fortify が constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(PasswordResetResponseContract::class, PasswordResetResponse::class);
        // forgot-password は成功/失敗の両契約を enumeration-safe な同一応答へ差し替える。
        // Fortify は constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        // ログアウト着地で Inertia::clearHistory() を発火させる (bug-hunt F-4-01)。
        // 着地 route を固定する理由と順序の前提は LogoutResponse の docblock を参照。
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->configureRateLimiters();
        $this->configureViews();
        $this->attachRecentAuthToSensitiveRoutes();
    }

    /**
     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
     *
     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決して append する。route:cache 下でも
     * CompiledRouteCollection::getByName() が nameCache に memoize した同一 instance を
     * match() が返すため、この変更は dispatch にも有効。
     */
    private function attachRecentAuthToSensitiveRoutes(): void
    {
        $this->app->booted(static function (Application $app): void {
            $routes = $app->make(Router::class)->getRoutes();
            // fluent な ->name() 付与はコレクションの name index に遅延反映のため明示 refresh
            $routes->refreshNameLookups();

            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
            }

            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
                self::appendMiddlewareIfMissing($routes, $name, $alias);
            }
        });
    }

    /**
     * named route に middleware alias を idempotent に append する (未登録時のみ)。
     *
     * booted callback (static クロージャ) から呼ぶため **static** で定義し
     * `self::appendMiddlewareIfMissing(...)` で呼ぶ。長寿命プロセス等で callback が
     * 同一 Route instance に複数回届いても重複付与しない (idempotent)。
     */
    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            $throttleKey = Str::transliterate(
                Str::lower(is_string($username) ? $username : '').'|'.$request->ip(),
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->session()->get('login.id');

            return Limit::perMinute(5)->by(is_scalar($loginId) ? (string) $loginId : $request->ip().'|2fa');
        });

        // passkey (WebAuthn) endpoint。config/fortify.php の limiters.passkeys が
        // この名前を指しており、未設定だと Fortify が throttle 自体を外す
        // (= 未認証の challenge 発行 GET /passkeys/login/options が無制限になる)。
        // 未認証の login-options を含むため、認証済みは user 単位・未認証は IP 単位で絞る。
        RateLimiter::for('passkeys', function (Request $request) {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(10)->by(
                is_scalar($identifier) ? 'passkey|'.$identifier : 'passkey|'.$request->ip(),
            );
        });
    }

```

### app/Providers/AppServiceProvider.php (抜粋 L230-340)
```php
        // MessageSending は実送信直前 (キュー worker 内含む) に発火し、全 Mailable / Fortify
        // 経路を横断して一律適用される (返り値契約は FilterSuppressedRecipients docblock 参照)。
        Event::listen(MessageSending::class, FilterSuppressedRecipients::class);

        $this->configureApiRateLimiters();
        $this->configureInquiryRateLimiter();
        $this->configureRenderRateLimiter();
    }

    /**
     * レンダ/プレビュートリガー (POST .../render, .../preview) の RateLimiter。
     * preview はチケット非消費のため、この rate limit + org 同時 preview 上限
     * (RenderJobService::triggerPreview) の 2 段が無料 ffmpeg 実行の負荷上限を構造的に決める
     * (概念設計 §2 の abuse 耐性契約)。キーは user id + org id 単位。
     */
    private function configureRenderRateLimiter(): void
    {
        RateLimiter::for('render-trigger', function (Request $request): Limit {
            $user = $request->user();
            $userId = $user instanceof User ? (string) $user->id : 'guest';
            $orgId = $user instanceof User && $user->current_organization_id !== null
                ? (string) $user->current_organization_id
                : 'none';

            return Limit::perMinute(6)->by("render-trigger:{$userId}:{$orgId}");
        });
    }

    /**
     * 公開問い合わせフォーム (POST /contact) の RateLimiter。IP 単独 + IP+email の 2 系統。
     * email 正規化は保存・検索と同一の EmailNormalizer に集約 (大文字小文字での limiter 回避防止)。
     * email はキャッシュキーへの平文残存を避けるため sha256 でハッシュ化する。
     * limiter は validation 前に走るため email が非 string で来うる → is_string ガード必須。
     */
    private function configureInquiryRateLimiter(): void
    {
        RateLimiter::for('inquiry', function (Request $request): array {
            $rawEmail = $request->input('email', '');
            $email = is_string($rawEmail) && $rawEmail !== '' ? EmailNormalizer::normalize($rawEmail) : '';
            $emailKey = $email !== '' ? hash('sha256', $email) : 'anon';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('inquiry:ip:'.$ip),
                Limit::perMinutes(60, 10)->by('inquiry:ip-email:'.$ip.':'.$emailKey),
            ];
        });
    }

    /**
     * REST API v1 / MCP / OAuth DCR の rate limit バケット (定義はここに集約、適用は route 側)。
     * キーは認証済みなら API キー id (MCP は user id)、未認証 (api-status の /version 等) は IP。
     * 新バケットの追加は要件に明示的根拠があるときだけ (docs/app-integration-guide.md §5)。
     */
    private function configureApiRateLimiters(): void
    {
        RateLimiter::for('api-read', fn (Request $request): Limit => Limit::perMinute(120)->by($this->apiRateKey($request)));
        RateLimiter::for('api-write', fn (Request $request): Limit => Limit::perMinute(60)->by($this->apiRateKey($request)));
        RateLimiter::for('api-status', fn (Request $request): Limit => Limit::perMinute(30)->by($this->apiRateKey($request)));
        RateLimiter::for('api-mcp', fn (Request $request): Limit => Limit::perMinute(60)->by($this->mcpRateKey($request)));

        // DCR (POST /oauth/register) 用 (WP23)。未認証で client 登録できる endpoint のため
        // IP 単位で絞る。正常 client は 1 回 / session なので 10 req/min で連打を十分弾ける。
        RateLimiter::for('oauth-register', fn (Request $request): Limit => Limit::perMinute(10)->by('oauth-register:ip:'.($request->ip() ?? 'unknown')));
    }

    private function apiRateKey(Request $request): string
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey instanceof ApiKey) {
            return 'api-key:'.$apiKey->id;
        }

        // dual guard の OAuth user-token 経路 (throttle は resolve.api-actor より前段の
        // ため guard から直接引く)。actor 単位で数える (IP 共有環境での巻き添え防止)
        $oauthUser = $request->user('api-oauth');
        if ($oauthUser instanceof User) {
            return 'oauth-user:'.$oauthUser->id;
        }

        return 'ip:'.($request->ip() ?? 'unknown');
    }

    /**
     * MCP (auth:mcp-oauth) は user 単位で bucket を分ける (1 token = 1 user 1 org)。
     * 未認証 (auth 前に throttle が走る) は IP fallback。
     */
    private function mcpRateKey(Request $request): string
    {
        $user = $request->user('mcp-oauth');
        if ($user instanceof User) {
            return 'mcp:user:'.$user->id;
        }

        return 'ip:mcp:'.($request->ip() ?? 'unknown');
    }
}

```

### app/Support/EmailNormalizer.php
```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * email 値の canonical 正規化。
 *
 * 用途 (適用範囲を明示限定):
 *   - rate limiter key 生成 (login / register / forgot 等での大文字小文字による throttle bypass 防止)
 *   - email 同値判定 (例: 現在の email と入力された email の比較)
 *
 * 用途外 (= 「raw 値で blind index 生成 / 保存」の既存規約と一致させるため正規化しない):
 *   - `whereBlind('email', 'email_index', $value)` の入力 → raw を渡す
 *   - `users.email` 列への保存 → raw を保存する
 *
 * 設計判断: `Str::transliterate()` は legitimate な Unicode email を別 user に collapse
 * させるリスクがあるため使わない。`trim + 小文字化` の最小正規化に留める。
 */
final class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        $trimmed = trim($email);
        if ($trimmed === '') {
            return '';
        }

        return Str::lower($trimmed);
    }
}

```

### app/Support/EmailHash.php
```php
<?php

declare(strict_types=1);

namespace App\Support;

use Webmozart\Assert\Assert;

/**
 * email の keyed hash (HMAC-SHA256) 算出 helper。
 *
 * 単純 sha256 は辞書攻撃に弱いため、ログ・補助検索用には HMAC(app.key) で keyed hash を作る。
 * 平文 email をログに出さないための識別子として使う。
 *
 * 制約: APP_KEY ローテーション時、前後の hash は突合不可になる。
 */
final class EmailHash
{
    public static function compute(string $email): string
    {
        $key = config('app.key');
        Assert::string($key);

        return hash_hmac('sha256', mb_strtolower(trim($email)), $key);
    }
}

```

### tests/Architecture/ControllerAuthorizationGateTest.php (inventory gate の先例。冒頭 L1-70 と L260-330)
```php
<?php

declare(strict_types=1);

use App\Enums\Security\ControllerAuthorizationExemption;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorizationMarkerScanner;

/*
 * 変更系 route の認可 invariant (deny-by-default)。
 *
 * 「状態を変える route (POST/PUT/PATCH/DELETE) のハンドラは、必ず認可判断を 1 回通る」
 * を機械強制する。通らないものは理由付きで exemption inventory へ明示登録させる。
 *
 * ★本テストの核心は「何を認可と認めないか」:
 *   membership binder (MembershipScopedOrganizationBinder) / resolveOrganization 系 /
 *   auth・verified・recent-auth・require-active-subscription・api-key.ability middleware /
 *   FormRequest::authorize() は **合格条件に数えない**。
 *   これらはテナント境界 (層 2) や認証・契約状態であって認可 (層 3) ではなく、
 *   数えると gate が形骸化する。
 *
 * ★受理する認可手段は Gate ファサード 1 系統のみ:
 *   - can: middleware は Controller より前に走るため、inline guard 方式の route で
 *     「認可より前に 404」(不変条件 2) を壊す (cross-org が 403 になり存在が漏れる)。
 *   - $this->authorize() は base Controller が AuthorizesRequests trait を持たず呼べない。
 *   いずれも使用実績 0 件のため受理しない (使いたくなったら本テストごと設計し直す)。
 *
 * 本テストは「認可判断の入口が存在しない route を作らせない」役割に限定する。
 * 認可の**内容**の正当性 (対象が正しいか / Policy が妥当か / actor が正しいか) は
 * 各 Feature / Policy テストの責務 (NestedRouteIdorDefenseTest と同じ責務設計)。
 *
 * 字句解析は tests/Support/AuthorizationMarkerScanner に切り出し、解析器自体の
 * positive/negative は tests/Unit/Architecture/AuthorizationMarkerScannerTest が固定する。
 */

/** 変更系 HTTP メソッド。 */
function controllerAuthorizationMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/** 候補数の下限 (空振り drift ガード。実測に対し余裕を持たせた値。上限は設けない)。 */
function controllerAuthorizationRouteFloor(): int
{
    return 40;
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function controllerAuthorizationReasonMinLength(): int
{
    return 30;
}

/** inline URL 整合 guard とみなすメソッド名 (認可より前に 404 を返す層 2b)。 */
function controllerAuthorizationInlineGuards(): array
{
    return ['resolveOrganizationProject', 'resolveProjectItem', 'resolveOrganizationMember'];
}

/**
 * 認可を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ControllerAuthorizationExemption, string}>
 */
function controllerAuthorizationExemptions(): array
{
    $membership = ControllerAuthorizationExemption::MembershipIsTheAuthorization;
    $noSubject = ControllerAuthorizationExemption::NoAuthorizableSubject;
    $selfScoped = ControllerAuthorizationExemption::SelfScopedResource;
    $tokenBearer = ControllerAuthorizationExemption::TokenBearerIsTheSubject;
...

test('変更系 route は認可を持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = controllerAuthorizationExemptions();
    $violations = [];
    $checked = 0;

    foreach (controllerAuthorizationMutatingRoutes() as $route) {
        $resolved = controllerAuthorizationHandlerSource($route);
        if ($resolved['status'] === 'vendor') {
            continue;
        }
        if ($resolved['status'] === 'fail') {
            // 解決失敗は専用テストが詳細を出す。ここでは合格に倒さないことだけ担保する
            $violations[] = controllerAuthorizationRouteLabel($route).': ハンドラを解決できませんでした';

            continue;
        }
        $checked++;

        $name = $route->getName();

        if (AuthorizationMarkerScanner::hasAuthorizationMarker($resolved['fragment'])) {
            // 同名の別クラスによる誤合格を防ぐ: Facade の名前空間 import を必須にする
            $source = file_get_contents($resolved['file']);
            if ($source === false || ! AuthorizationMarkerScanner::importsGateFacade($source)) {
                $violations[] = controllerAuthorizationRouteLabel($route)
                    .': Gate:: の認可マーカーはあるが use Illuminate\Support\Facades\Gate; の'
                    .' import がありません (同名の別クラスの可能性があるため合格にしません)';
            }

            continue;
        }

        if ($name !== null && array_key_exists($name, $inventory)) {
            continue;
        }

        $violations[] = controllerAuthorizationRouteLabel($route).' が未分類';
    }

    expect($violations)->toBe([],
        '認可判断 (Gate::authorize / Gate::forUser(...)->authorize) を持たない変更系 route があります。'
        .'ハンドラに認可を足すか、認可が不要な理由を controllerAuthorizationExemptions() に'
        .'ControllerAuthorizationExemption + 具体的根拠付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    expect($checked)->toBeGreaterThan(0);
});

test('exemption inventory の key は現存 named route (逆方向整合・stale 検出)', function (): void {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        $n = $route->getName();
        if ($n !== null) {
            $named[$n] = true;
        }
    }

    $stale = [];
    foreach (array_keys(controllerAuthorizationExemptions()) as $key) {
        if (! isset($named[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([],
        'exemption inventory に現存しない route 名 (削除/rename 済) があります: '.implode(', ', $stale));
});

test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
    $minLength = controllerAuthorizationReasonMinLength();
```

### tests/Feature/Security/NamedRateLimiterKeyTest.php (既存・家系唯一のキー不変条件テスト)
```php
<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Testing\TestResponse;

/*
 * named rate limiter のキーが **route parameter を含まない** ことの behavioral proof
 * (T108 S4 検査 4(d))。
 *
 * ThrottleRequests は SubstituteBindings より前 (pre-binding) に走る短絡であり、
 * 「全 id で同一の応答になる」ことが存在オラクル不成立の根拠になっている。
 * しかし ThrottleRequests 自身が route parameter を読まなくても、
 * `throttle:{bucket}` の limiter closure が `$request->route(...)` を読めば
 * bucket が id ごとに分かれ、「429 になるまでの回数」が id の実在を漏らす。
 * 静的検査 (TenantBoundaryOrderingTest 検査 4(a)) は closure まで届かないため、
 * ここで実挙動として固定する。
 *
 * 検証方法: **同じ actor で route parameter だけを変えた 2 連続リクエスト**の
 * `X-RateLimit-Remaining` が連続して減ること。limiter キーに route parameter が
 * 混ざっていれば 2 回目の remaining は 1 回目と同じ値に戻る (= bucket が分かれた証拠)。
 * bucket を実際に使い切る方式より少ないリクエストで同じ性質を証明できる。
 */

/** 応答の X-RateLimit-Remaining を int で取り出す。 */
function limiterRemaining(TestResponse $response): int
{
    $remaining = $response->headers->get('X-RateLimit-Remaining');
    expect($remaining)->not->toBeNull('X-RateLimit-Remaining が無い (throttle が付いていない?)');

    return (int) $remaining;
}

test('api-read bucket は route parameter ごとに分かれない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner, ['read']);
    $headers = ['Authorization' => "Bearer {$plain}"];

    $first = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectA->id}/items");
    $second = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectB->id}/items");

    expect(limiterRemaining($second))->toBe(
        limiterRemaining($first) - 1,
        'route parameter を変えたら残数が戻った = limiter キーが route parameter を含んでいる',
    );
});

test('api-write bucket は route parameter ごとに分かれない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner, ['write']);
    $headers = ['Authorization' => "Bearer {$plain}"];

    $first = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectA->id}/items", ['name' => 'A']);
    $second = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectB->id}/items", ['name' => 'B']);

    expect(limiterRemaining($second))->toBe(limiterRemaining($first) - 1);
});

test('api-read bucket は不在 project id のリクエストでも同じ bucket を消費する', function (): void {
    // 不在 id が別 bucket に落ちると「429 になるまでの回数」で実在が漏れる
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner, ['read']);
    $headers = ['Authorization' => "Bearer {$plain}"];

    $existing = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/items");
    $missing = $this->withHeaders($headers)->getJson('/api/v1/projects/999999999/items');

    expect($missing->getStatusCode())->toBe(404);
    expect(limiterRemaining($missing))->toBe(limiterRemaining($existing) - 1);
});

test('render-trigger bucket は route parameter ごとに分かれない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();

    // 応答が 4xx でも throttle は最外周で消費される (limiter キーの検証が目的)
    $first = $this->actingAs($owner)
        ->postJson("/projects/{$projectA->id}/manuals/999999999/render");
    $second = $this->actingAs($owner)
        ->postJson("/projects/{$projectB->id}/manuals/999999999/render");

    expect(limiterRemaining($second))->toBe(limiterRemaining($first) - 1);
});

```

### routes/ai.php (DCR 後付け + 起動時 fail-fast の先例 L33-72)
```php

/*
|--------------------------------------------------------------------------
| DCR (/oauth/register) の rate limit 後付け配線 + 起動 fail-fast
|--------------------------------------------------------------------------
|
| DCR は未認証で誰でも client 登録できる endpoint のため IP 単位の throttle を
| 必須にする。laravel/mcp の `oauthRoutes()` は Route を return しないので、
| router 走査で該当 route を見つけて throttle を追加する。
|
| 二段検出 (URI マッチ + action name に vendor controller 基準語を含むこと) で
| laravel/mcp の update による route 構造変更を早期検出。未達なら起動時
| fail-fast して DCR が無制限のまま公開される事故を防ぐ。
| limiter 本体 (oauth-register) は AppServiceProvider で定義する。
*/
$mcpOauthRegisterRoute = collect(app('router')->getRoutes()->getRoutes())
    ->first(function (Route $r): bool {
        if (! in_array('POST', $r->methods(), true)) {
            return false;
        }
        if ($r->uri() !== 'oauth/register') {
            return false;
        }

        // action name が OAuthRegisterController を含むことで誤マッチを防ぐ。
        return str_contains((string) $r->getActionName(), 'OAuthRegisterController');
    });

if (! $mcpOauthRegisterRoute instanceof Route) {
    throw new RuntimeException(
        'laravel/mcp の /oauth/register route が見つかりません。'
        .'laravel/mcp が update で route 構造を変えた可能性があります。'
        .'oauth-register bucket が middleware に配線されないまま起動すると DCR endpoint の'
        .'abuse 耐性が失われるため、fail-fast で起動を止めます。',
    );
}

$mcpOauthRegisterRoute->middleware('throttle:oauth-register');

/*
```

### vendor/laravel/framework .../ThrottleRequests::handleRequestUsingNamedLimiter (キー導出)
```php
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $limiterName
     * @param  \Closure  $limiter
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    protected function handleRequestUsingNamedLimiter($request, Closure $next, $limiterName, Closure $limiter)
    {
        $limiterResponse = $limiter($request);

        if ($limiterResponse instanceof Response) {
            return $limiterResponse;
        } elseif ($limiterResponse instanceof Unlimited) {
            return $next($request);
        }

        return $this->handleRequest(
            $request,
            $next,
            Collection::wrap($limiterResponse)->map(function ($limit) use ($limiterName) {
                return (object) [
                    'key' => self::$shouldHashKeys ? md5($limiterName.$limit->key) : $limiterName.':'.$limit->key,
                    'maxAttempts' => $limit->maxAttempts,
                    'decaySeconds' => $limit->decaySeconds,
                    'afterCallback' => $limit->afterCallback,
                    'responseCallback' => $limit->responseCallback,
                ];
            })->all()
        );
    }

    /**
     * Handle an incoming request.
```

### 実査で得た保護対象群 (母集団 47 / throttle 無し 23)
```
S2 GET  /.well-known/oauth-authorization-server        mcp.oauth.authorization-server
S2 GET  /.well-known/oauth-authorization-server/{path} mcp.oauth.authorization-server.nested
S2 GET  /.well-known/oauth-protected-resource          mcp.oauth.protected-resource
S2 GET  /.well-known/oauth-protected-resource/{path}   mcp.oauth.protected-resource.nested
S1+S3 POST   /admin/logout                filament.admin.auth.logout
S2 GET  /api/v1/mcp                       (名前なし)
S1+S2 DELETE /api/v1/mcp                  (名前なし)
S1 POST   /debug/login/{userId}           debug.login-as
S1+S3 POST   /forgot-password             password.email
S3 POST   /invitations/accept             invitations.accept.store
S1 POST   /livewire-*/update              default-livewire.update
S3 POST   /logout                         logout
S1+S3 POST   /register                    register.store
S1+S3 POST   /reset-password              password.update
S1 POST   /ses/notification               webhooks.ses
S1 PUT    /storage/{path}                 storage.local.upload
S1 POST   /stripe/webhook                 cashier.webhook
S3 POST   /user/confirm-password          password.confirm.store
S3 POST   /user/confirmed-two-factor-authentication two-factor.confirm
S3 PUT    /user/password                  user-password.update
S3 POST   /user/two-factor-authentication two-factor.enable
S3 DELETE /user/two-factor-authentication two-factor.disable
S3 POST   /user/two-factor-recovery-codes two-factor.regenerate-recovery-codes
```

