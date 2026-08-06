# Round 2: Round 1 指摘への対応 (詳細設計)

Round 1 の指摘に対する対応マトリクスと、**改訂後の詳細設計の全文**です。
改訂本文を実読したうえで再判定してください (未読のまま APPROVED としないでください)。

# 対応マトリクス: design-review Round 1

Critical 0 件 / Warning 11 件 / Suggestion 3 件。**Warning は全件対応**、Suggestion は 2 件対応・1 件見送り。

## [Warning] 施策 1: params 比較が緩い
- 判断: **対応する**
- 対応内容: entry を `{class, params}` に分解する private helper
  `parseThrottleEntry(string $entry): array{class: string, params: string}` を追加し、
  - **named 形式** (`^[a-z][a-z0-9-]*$`) と **inline 形式** (`^\d+,\d+$`) を明示的に区別する
  - params が空 / 未知形式 / 余計な comma を含む場合は「想定外」として例外に落とす
  - 例外メッセージに実 entry と期待値の両方を出す

## [Warning] 施策 2: S1 の説明が実セレクタより強い
- 判断: **対応する (表現の是正)**
- 根拠: 正しい。`signed` / 定数 405 / `LocalOnly` / 署名検証など `Authenticate` 以外で
  本体到達を閉じる route も S1 に入る。
- 対応内容: S1 の説明を「**未認証で到達可能な可能性がある**変更系」に弱め、
  exemption の役割を「**本体到達しない根拠を固定すること**」と定義し直す。

## [Warning] 施策 2 / 8: `debug.login-as` の exemption 文意が逆
- 判断: **対応する**
- 根拠: 正しい。「local / テスト実行時のみ route 登録自体が起きない」は文意が逆で、
  正しくは「local / testing でのみ登録され、production では登録自体が起きない」。
  (既存 `ControllerAuthorizationExemption::LocalOnlyDebugRoute` の文面もこの曖昧さを持つが、
   本タスクでは新 enum の文面だけを正しく書く。既存 enum の文面修正は射程外)
- 対応内容: 新 enum の docblock を書き直し、施策 8 のテストを
  「testing では登録される」と「production 相当では登録されない」に分ける。

## [Warning] 施策 3: Fortify feature フラグ差分で起動 fail する
- 判断: **対応する**
- 根拠: 重要。`register.store` は `Features::registration()`、2FA 管理は
  `Features::twoFactorAuthentication()`、reset 系は `Features::resetPasswords()` に依存する。
  無条件 fail-fast だと「機能を無効化したら起動できない」という別の退行を生む。
- 対応内容: inventory を
  `array<string, array{throttle: string, feature: ?string}>` にする。
  - `feature === null` → 常に必須 (route が無ければ fail-fast)
  - `feature !== null` → `Features::enabled($feature)` が true のときだけ必須。false なら skip
  - **skip が穴にならない根拠**を設計に明記する: feature を再有効化して binder が skip したままなら、
    施策 2 の目録検査が「throttle 無しの保護対象 route」として **必ず fail する** (二重の網)。

## [Warning] 施策 4: webhook throttle は正当通知保護の全体天井ではない
- 判断: **対応する (docblock 明記 + TODO 拡張)**
- 対応内容: `webhook-*` limiter の docblock に
  「これは**署名検証コストの上限**であり、正当通知を守る全体天井ではない」と明記。
  後続 TODO B1 に「provider 側の署名済み source identity が取れる場合の bucket 再設計」を追加。

## [Warning] 施策 6: `EmailHash::compute()` の二重正規化で canonical 化の正本が曖昧
- 判断: **対応する (責務の明文化)**
- 根拠: `EmailHash::compute()` は内部で `mb_strtolower(trim($email))` を行うため、
  呼び出し側の `EmailNormalizer::normalize()` と重複する。
- 対応内容: **canonical 化の正本は `EmailNormalizer`** と定義し、
  `EmailHash` の docblock に「防御的に同じ正規化を再適用する (呼び出し漏れへの保険)」と追記する。
  `EmailHash` の**実装は変えない** (既存の呼び出し元への波及を避けるため。思考原則 2)。

## [Warning] 施策 6 / 9: validation 前 limiter の異常入力テストが不足
- 判断: **対応する**
- 対応内容: 施策 9 に
  「login limiter は username が**配列 / 空文字 / 極端に長い文字列**でも 500 にならず、
   同一 IP bucket を消費する」を追加。`password-reset-*` / `account-register` も同様。

## [Warning] 施策 7: scanner が alias import (`use RateLimiter as X`) を取りこぼす
- 判断: **対応する**
- 根拠: 正しい。deny-by-default の検査で「未知の登録が scanner から消える」は最悪の失敗モード。
- 対応内容: scanner に import 解析を追加する。
  - `use Illuminate\Support\Facades\RateLimiter;` → 短縮名 `RateLimiter` を許容 facade とする
  - `use ... RateLimiter as X;` → **`X::for(...)` を `unresolved` に入れて fail させる**
    (alias を解決するのではなく「規約から外れた書き方を禁止する」= 単純で堅い)
  - 完全修飾 `\Illuminate\Support\Facades\RateLimiter::for` は受理する

## [Warning] 施策 7: 完全修飾 / 非完全修飾の token 形の網羅
- 判断: **対応する**
- 対応内容: scanner 単体テストに
  `Illuminate\Support\Facades\RateLimiter::for('x', …)` (`T_NAME_QUALIFIED`) と
  `\Illuminate\Support\Facades\RateLimiter::for('x', …)` (`T_NAME_FULLY_QUALIFIED`) の
  両方を追加する。

## [Warning] 施策 8: `.well-known` の Feature 固定が 1 本しかない
- 判断: **対応する**
- 対応内容: 4 route すべてについて
  「DB クエリ 0 件」と「nested `{path}` を変えても status と主要 JSON shape が変わらない」を固定する。

## [Warning] 施策 9: 「throttle が署名検証より先」の証明が実効順比較だけ
- 判断: **対応する**
- 対応内容: 実効順の index 比較に加えて、
  **無署名リクエストを上限+1 回連打し、最後が 403 (invalid signature) ではなく 429 になる**ことを固定する。
  429 応答の**契約そのもの**は別 feature (`error-response-contract`) の射程なので、
  ここで見るのは status と rate-limit ヘッダの存在までに留める。

## [Suggestion] 施策 3: inline throttle の許容理由を const コメントにも書く
- 判断: **対応する**

## [Suggestion] 施策 4: 専用 `RouteSecurityServiceProvider` への分離
- 判断: **見送る (反論)**
- 根拠: 本タスクで `AppServiceProvider` に増えるのは limiter 定義 2 本と booted callback 1 本のみ。
  provider を割るのは AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。
  Codex 自身も「今回の射程では必須ではない」と述べている。
  後続 TODO 候補としてのみ記録する。

## [Suggestion] 検証コマンドの `route:cache` に trap
- 判断: **対応する**
- 対応内容: 検証表に「**手動検証**であり CI script には入れない。
  失敗時に route cache が残らないよう `route:clear` を必ず実行する」と注記する。


---

## 改訂後の詳細設計 (全文)

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

    /**
     * throttle entry を class 部 / params 部に分解する。
     *
     * @return array{class: string, params: string}
     */
    private static function parseThrottleEntry(string $entry): array;
}
```

**params 形式の厳密化** (Codex Round 1 Warning):
`throttle:6,1` (inline) と `throttle:password-reset-request` (named) はどちらも params 文字列だが、
許容形式を明示的に分けて「想定外 params」を素通ししない。

```php
/** named limiter 名の形式。 */
private const NAMED_LIMITER_PATTERN = '/^[a-z][a-z0-9-]*$/';

/** inline throttle (`{max},{decay}`) の形式。 */
private const INLINE_LIMITER_PATTERN = '/^\d+,\d+$/';
```

- 期待値 `$limiter` 自体がどちらの形式にも一致しなければ **開発時ミス**として `RuntimeException`
- 既存 entry の params が空、またはどちらの形式にも一致しなければ「想定外の throttle」として `RuntimeException`
- 例外メッセージには **実 entry** と **期待値**の両方を出す

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

    if (count($entries) === 1) {
        $parsed = self::parseThrottleEntry($entries[0]);
        if ($parsed['params'] === $limiter) {
            return; // route:cache 由来の再適用 = 冪等 no-op
        }
    }

    throw new RuntimeException(
        "route [{$routeName}] に想定外の throttle が付いています: ".implode(', ', $entries)
        .' (期待: throttle:'.$limiter.')。二重付与は実効上限を半減させるため起動を止めます。',
    );
}
```

> ⚠ `parseThrottleEntry()` は entry に `:` が無い場合 (パラメータなし throttle。
> Passport の `POST /oauth/token` が該当) に `params = ''` を返す。
> 空 params は「想定外の throttle」として必ず例外側に落ちる = 意図どおり。

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
- [ ] params なしの throttle が既にある route へ呼ぶと `RuntimeException`
- [ ] 期待値に不正形式 (`'6,1,9'` / `'Foo Bar'`) を渡すと `RuntimeException` (開発時ミスの検出)
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

    /**
     * local / testing でのみ登録され、**production では route 登録自体が起きない**デバッグ用 route。
     *
     * 適用条件: `routes/*.php` 側で `app()->isLocal() || app()->runningUnitTests()` 等により
     * 登録が囲われ、かつ `LocalOnly` 相当の middleware が二重防御であること。
     * (Architecture テストは testing 環境で走るため、この route は**母集団に現れる**。
     *  「テストからは見えない」ではなく「本番には存在しない」が exemption の根拠である)
     */
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
| S1 | method ∈ {POST,PUT,PATCH,DELETE} **かつ** 実効列に `Illuminate\Auth\Middleware\Authenticate` を含まない | **未認証で到達可能な可能性がある**変更系 |
| S2 | uri が `api/` / `oauth/` / `.well-known/oauth-` で始まる **かつ** 実効列に `Illuminate\Session\Middleware\StartSession` を含まない | ステートレスな機械向け経路 |
| S3 | route 名が認証面パターンに一致 **かつ** method ∈ {POST,PUT,PATCH,DELETE} | 認証済み側も含む credential 面 |

- 実効列は `Route::getFacadeRoot()->gatherRouteMiddleware($route)` で取得する
  (**`route:list --json` は使わない** — group 名 `'web'` が展開されないため誤判定する)。
- throttle 判定は `RouteThrottleBinder::isThrottleEntry()` を共有する。

> **S1 は「未認証で本体に到達する」ことを主張しない** (Codex Round 1 Warning)。
> `signed` / 定数 405 スタブ / `LocalOnly` / 署名検証など、`Authenticate` 以外で
> 本体到達を閉じる route も S1 に入る。セレクタは意図的に**過大に**取り、
> **exemption の役割は「本体到達しない根拠を固定すること」**と定義する
> (過小なセレクタはすり抜けを生むが、過大なセレクタは exemption 理由という形で
>  根拠が文書化されるだけで済む)。

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
| `debug.login-as` | `LocalOnlyDebugRoute` | `routes/web.php:613` の `app()->isLocal() \|\| app()->runningUnitTests()` により **production では route 登録自体が起きない** (testing では登録されるため母集団に現れる)。`LocalOnly` middleware が二重防御 |
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
 * ★inline (`6,1` / `10,1`) を使ってよいのは **認証済みかつ actor 自身に閉じる route** だけ。
 *   未認証面 / 主体が IP や email になる面は必ず named limiter を作ること。
 *
 * ★`feature` は Fortify の機能フラグ (`config/fortify.php` の `features`)。
 *   null = 常に必須 (route が無ければ起動時 fail-fast)。
 *   非 null = その機能が有効なときだけ必須 (無効なら route 自体が登録されないため skip)。
 *   **skip が穴にならない根拠**: 機能を再有効化して binder が skip したままなら、
 *   ThrottleCoverageInventoryTest が「throttle 無しの保護対象 route」として必ず fail する
 *   (binder の fail-fast と目録検査の二重の網で守る)。
 *
 * @var array<string, array{throttle: string, feature: string|null}>
 */
private const THROTTLED_FORTIFY_ROUTES = [
    'password.email' => ['throttle' => 'password-reset-request', 'feature' => Features::resetPasswords()],
    'password.update' => ['throttle' => 'password-reset-submit', 'feature' => Features::resetPasswords()],
    'register.store' => ['throttle' => 'account-register', 'feature' => Features::registration()],
    'password.confirm.store' => ['throttle' => '6,1', 'feature' => null],
    'user-password.update' => ['throttle' => '6,1', 'feature' => Features::updatePasswords()],
    'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
    'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
    'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
    'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
];

private function attachThrottleToFortifyRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        $router = $app->make(Router::class);
        foreach (self::THROTTLED_FORTIFY_ROUTES as $name => $spec) {
            if ($spec['feature'] !== null && ! Features::enabled($spec['feature'])) {
                continue; // 機能無効 = route 自体が存在しない (目録検査が二重の網)
            }
            RouteThrottleBinder::attachByName($router, $name, $spec['throttle']);
        }
    });
}
```

> ⚠ `Features::resetPasswords()` 等は**定数を返す static メソッド**であり、
> PHP の const 式では呼べない。`private const` ではなく
> **`private static function throttledFortifyRoutes(): array`** として実装すること
> (戻り値型 `array<string, array{throttle: string, feature: string|null}>` を PHPDoc で明示)。

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
 * ★これは**署名検証コストの上限**であり、正当通知を守る全体天井ではない。
 *   IP キーである以上、共有クラウド出口 / proxy 設定の誤りでは巻き添え 429 がありうる。
 *   正当通知の保護は「送信元の署名済み identity で bucket を切る」設計が要る (後続 TODO B1)。
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
 *   **canonical 化の正本は EmailNormalizer** (保存・検索・inquiry と同一)。
 *   EmailHash は内部で同じ正規化を防御的に再適用するが、それは呼び出し漏れへの保険であり
 *   正本ではない (EmailHash の docblock にもこの責務分担を追記する)。
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

`app/Support/EmailHash.php` の docblock に責務分担を 1 行追記する
(**実装は変えない**。既存呼び出し元への波及を避けるため):

```
 * 正規化の正本は EmailNormalizer である。本クラス内の mb_strtolower(trim(...)) は
 * 呼び出し漏れに対する防御的な再適用であり、canonical 化の定義を持つものではない。
```

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

1. **import を先に解析する**。
   - `use Illuminate\Support\Facades\RateLimiter;` → 短縮名 `RateLimiter` を**許容 facade 名**に加える
   - `use Illuminate\Support\Facades\RateLimiter as X;` → **`X` を「禁止 alias」として記録**する
     (alias を解決するのではなく、規約から外れた書き方を禁止する = 単純で堅い)
2. `token_get_all()` で走査し、`{facade}` → `T_DOUBLE_COLON` → `T_STRING('for')` → `(` の並びを探す。
   `{facade}` として受理する token 形:
   - `T_STRING('RateLimiter')` (許容 facade 名として import 済みの場合のみ)
   - `T_NAME_FULLY_QUALIFIED('\Illuminate\Support\Facades\RateLimiter')`
   - `T_NAME_QUALIFIED('Illuminate\Support\Facades\RateLimiter')`
   - 「禁止 alias」の `T_STRING` → **`unresolved[]` に入れる** (facade は同じでも書き方が規約外)
3. `(` の直後の非空白 token が `T_CONSTANT_ENCAPSED_STRING` なら `names[]` に追加
   (クォート除去は `substr($token, 1, -1)`。変数展開のない single/double quote のみ受理)
4. そうでなければ `unresolved[]` に `{path}:{line}` を追加

### scanner の単体テスト (新規)

`tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php`:

- [ ] 単純な `RateLimiter::for('login', ...)` を検出する (`use` 済み短縮名)
- [ ] 改行・空白を挟んだ `RateLimiter::for(\n 'login',` を検出する
- [ ] 完全修飾 `\Illuminate\Support\Facades\RateLimiter::for('x', ...)` (`T_NAME_FULLY_QUALIFIED`) を検出する
- [ ] 非完全修飾 `Illuminate\Support\Facades\RateLimiter::for('x', ...)` (`T_NAME_QUALIFIED`) を検出する
- [ ] `use ... RateLimiter as Limiter;` + `Limiter::for('x', ...)` を **`unresolved` に入れる**
- [ ] `RateLimiter::for($name, ...)` / `RateLimiter::for(self::NAME, ...)` を `unresolved` に入れる
- [ ] コメント `// RateLimiter::for('fake')` と文字列 `"RateLimiter::for('fake')"` を検出しない
- [ ] `OtherClass::for('x')` を検出しない
- [ ] `use` の無いファイル内の裸 `RateLimiter::for('x')` を `unresolved` に入れる
      (同名の別クラスかもしれないため合格にしない)

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

- [ ] `署名なしの PUT /storage/{path} は本体に到達しない` (非 production では 403。
      `Storage::fake()` で「ファイルが書かれていない」ことも確認する)
- [ ] `GET /api/v1/mcp は 405 と Allow: POST を返す` (定数スタブであることの固定)
- [ ] `DELETE /api/v1/mcp は 405 と Allow: POST を返す`
- [ ] `.well-known/oauth-* の 4 route はいずれも DB クエリ 0 件で応答する`
      (`DB::listen` でクエリを数える。「定数メタデータ」という主張の behavioral proof)
- [ ] `.well-known/oauth-*/{path} は path を変えても status と主要 JSON キーが変わらない`
      (route parameter 依存になっていないことの固定)
- [ ] `debug.login-as は testing 環境では登録される` (母集団に現れる前提の固定)
- [ ] `debug.login-as の登録条件は app()->isLocal() || app()->runningUnitTests() である`
      (production 相当では登録されないことの固定。`routes/web.php` の条件式を
       Architecture 的に読むか、`App::shouldReceive` 相当ではなく
       既存の相当テストがあれば流用する)

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
- [ ] `login limiter は username が配列 / 空文字 / 極端に長い文字列でも 500 にならず、
      同一 IP bucket を消費する` (limiter は validation より前に走るため)
- [ ] `password-reset-request / password-reset-submit / account-register も同様に異常入力で 500 にならない`
- [ ] `POST /ses/notification は throttle が署名検証より先に走る`
      (実効 middleware 列で `ThrottleRequests` の index < `VerifySnsSignature` の index)
- [ ] `POST /ses/notification に無署名リクエストを上限+1 回送ると、
      最後は 403 (invalid signature) ではなく 429 になる`
      (実効順の behavioral proof。429 応答の**契約**は別 feature の射程なので、
       ここで見るのは status と rate-limit ヘッダの存在までに留める)
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
| 10 | `php artisan route:cache && php artisan route:list > /dev/null && php artisan route:clear` | 例外なく完了 (binder の冪等性。**dev DB には触らない**)。⚠ **手動検証であり CI script には入れない**。途中で失敗すると route cache が残るため、失敗時も必ず `php artisan route:clear` を実行すること |
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
| B1 | **固定キーの全体天井** (標準形 (3)) の導入 + **署名済み source identity による bucket 再設計** | middleware 位置では署名検証前に消費され、攻撃者に「正当通知を止める手段」を与える。採るなら「署名検証成功後にだけ消費される位置」(Controller / Service 層) の設計が要る。あわせて、provider 側で署名済み identity (SNS TopicArn / Stripe account) が取れる場合はそれを bucket キーにする方が IP より正確 |
| B2 | **秘密を返す GET の保護** (`two-factor.qr-code` / `secret-key` / `recovery-codes`) | `config/fortify.php:165-168` の TODO(template) と一体で **recent-auth 化**として設計すべき。throttle だけ貼ると本質 (step-up 不足) が隠れる |
| B3 | Filament / Livewire 面の rate limit 契約の明文化 | `default-livewire.update` の exemption を恒久化するか、Filament 側の component 制限を inventory 化するかの判断 |
| B4 | DCR 後付け (`routes/ai.php:47-72`) と `PasskeyServiceProvider` の後付けを `RouteThrottleBinder` へ統合 | 両者は既に fail-fast 済みで動作しており、触る必要が無い (思考原則 2)。統合するなら DCR は route 名を持たないため `attachByUri()` の追加が要る |
| B5 | 429 応答の経路別契約 (フォーム内エラー / エラー画面 / API 形式) | AG-097 で `error-response-contract` feature へ切り出し済み。**本 feature の射程外** |
| B6 | 家系への還流 (`laravel-claude-template` への目録検査の移植) | 台帳上テンプレートは必須項目の欠落が家系最多 (9 件)。aicue で稼働実績を作ってから c2c 経由で提案する |
| B7 | limiter 定義と route security 配線の専用 provider (`RouteSecurityServiceProvider`) への分離 | 本タスクで `AppServiceProvider` に増えるのは limiter 2 本 + booted callback 1 本のみ。今 provider を割るのは思考原則 2 に反する。limiter がさらに増えたら再検討する |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 施策 1〜10 は「目録検査を入れる → 赤くなった穴を塞ぐ → キー規約を揃える」という一本の依存鎖であり、分割すると中間状態で CI が赤いまま残る。単一 worktree で順に積むのが最短 |
| 競合リスク | `app/Providers/AppServiceProvider.php` / `app/Providers/FortifyServiceProvider.php` / `routes/web.php` を触るため、認証系・課金系の他タスクと同時進行すると衝突しやすい。`config/fortify.php` は触らない (限定 4 キーしか受け付けないため) |
| テスト順序 | **テストファースト**。施策 2 のテストを先に書いて **fail を確認**してから施策 3〜6 に入る (AGENTS.md 思考原則 5) |

