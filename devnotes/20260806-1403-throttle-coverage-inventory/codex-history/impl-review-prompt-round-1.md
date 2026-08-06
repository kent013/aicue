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

あなたは Laravel + Svelte アプリ "AI-CUE" のコードレビュアーです。日本語で回答してください。

## 使命 (North Star)
AI-CUE は、現場の作業手順書(SOP)を起点に AI が動画シナリオを設計し、スマホ(PWA)の
ナビ撮影で標準化されたマニュアル動画を作れるようにするアプリです。本施策は使命を直接
前進させる機能ではなく、顧客資産(SOP と手順動画)を預かる基盤の前提条件 (流量制限) です。

## レビュー観点
1. 詳細設計との一致性 (逸脱があれば、その逸脱が正当か)
2. 正確性 (セキュリティ機構としての壊れ方。とくに「無音で無防備になる」経路)
3. PHPStan level 10 適合性 / 型安全
4. テスト網羅性 (deny-by-default 検査の**誤合格**が最悪の失敗モード)
5. セキュリティ (throttle の実効順・キー設計・存在オラクル・巻き添え DoS)
6. オーバーエンジニアリング (AGENTS.md 思考原則 2「今必要なものだけ作る」)

## 禁止事項 (提案してはいけないこと)
- PHPStan エラーの @phpstan-ignore-line / baseline / 型 widen による黙らせ
- 既存テストの削除・アサーション緩和
- response()->json() の直書き
- 閾値の変更提案 (AG-096 で「閾値はプロダクト依存」と裁定済み。既存値は変えない)

## 出力形式
ファイルごとに判定。指摘は [Critical] / [Warning] / [Suggestion] に分類。
最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を書いてください。

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

```php
/**
 * 期待値の形式を検証する (開発時ミスの検出)。
 *
 * @throws RuntimeException named / inline のどちらの形式にも一致しない場合
 */
private static function assertValidLimiter(string $limiter): void;
```

- 期待値 `$limiter` 自体がどちらの形式にも一致しなければ **開発時ミス**として `RuntimeException`。
  **`attachByName()` の冒頭で無条件に呼ぶ** (route 解決や既存 entry の有無より前)。
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
    // ★期待値の検証を最初に行う (route 解決や既存 entry の有無に依存させない)。
    //   ここを後回しにすると「初回呼び出しでは `6,1,9` のような不正形式を素通しする」
    //   非対称な穴になる。
    self::assertValidLimiter($limiter);

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
- [ ] 期待値に不正形式 (`'6,1,9'` / `'Foo Bar'` / `''`) を渡すと `RuntimeException`
      (**throttle が 1 本も無い route に対しても**例外になること = 初回呼び出しの穴が無いこと)
- [ ] 既存 entry の params が不正形式 (`'6,1,9'` / `'Foo Bar'`) の route へ呼ぶと `RuntimeException`
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
   - **`T_NAME_QUALIFIED` は受理しない → `unresolved[]` に入れる**。
     名前空間内の `Illuminate\Support\Facades\RateLimiter::for(...)` は PHP の解決規則では
     `App\Foo\Illuminate\Support\Facades\RateLimiter` を意味し、**Laravel の Facade ではない**。
     無条件受理すると deny-by-default が偽グリーンになる (Codex Round 2 Warning)。
     受理条件を「グローバル名前空間のときだけ」に緩めることもできるが、
     規約としては「完全修飾か `use` 済み短縮名のどちらかで書く」に倒すほうが単純で堅い。
   - 「禁止 alias」の `T_STRING` → **`unresolved[]` に入れる** (facade は同じでも書き方が規約外)
3. `(` の直後の非空白 token が `T_CONSTANT_ENCAPSED_STRING` なら `names[]` に追加
   (クォート除去は `substr($token, 1, -1)`。変数展開のない single/double quote のみ受理)
4. そうでなければ `unresolved[]` に `{path}:{line}` を追加

### scanner の単体テスト (新規)

`tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php`:

- [ ] 単純な `RateLimiter::for('login', ...)` を検出する (`use` 済み短縮名)
- [ ] 改行・空白を挟んだ `RateLimiter::for(\n 'login',` を検出する
- [ ] 完全修飾 `\Illuminate\Support\Facades\RateLimiter::for('x', ...)` (`T_NAME_FULLY_QUALIFIED`) を検出する
- [ ] 名前空間内の非完全修飾 `Illuminate\Support\Facades\RateLimiter::for('x', ...)`
      (`T_NAME_QUALIFIED`) を **`unresolved` に入れる** (PHP の解決規則では別クラスを指すため)
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
異常入力の契約は **3 つに分ける** (Codex Round 2 Warning。
極端に長い文字列も有効な `string` なので `EmailHash` が計算され、`anon` bucket とは別になる):

- [ ] `login limiter は username が配列 / 空文字のとき anon fallback として同じ bucket を消費する`
- [ ] `login limiter は極端に長い文字列でも 500 にならず、同一値の反復では同じ bucket を消費する`
- [ ] `password-reset-* / account-register は異なる異常文字列でも IP レーンを共有する`
      (2 系統のうち IP 単独レーンは email に依存しないため。
       IP-email レーンは値ごとに分かれるのが正しい挙動)
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

## 実装者への申し送り (Codex 詳細設計レビュー Round 3 / APPROVED 時の Suggestion)

1. **施策 1**: `parseThrottleEntry()` は単なる文字列分割で終わらせず、
   **既存 params の形式検証まで自身で完結**させること。設計済みの「不正既存 entry」テストで固定できる。
2. **施策 3**: 例示した `private const THROTTLED_FORTIFY_ROUTES` は**実装しない**。
   `Features::resetPasswords()` 等は const 式で呼べないため、注記どおり
   `private static function throttledFortifyRoutes(): array` として実装すること。
3. **施策 7**: import 解析では、**トップレベルの名前空間 import** と
   **クロージャの `use (...)`** / **trait の `use`** を区別すること (同じ `T_USE` トークン)。
   また「禁止 alias」は、実際に `::for()` の呼び出し元として使われた場合にだけ
   `unresolved` に入れる (import しただけで未使用なら fail させない)。
4. **テスト**: `RateLimiter::clear()` は**キー指定が必要**。引数なしで呼ばないこと。
   既存 `NamedRateLimiterKeyTest` / `tests/Pest.php` の方式を先に確認し、
   テスト専用 cache であることが確認できる場合に限り `Cache::flush()` を使う。
5. **検証**: 手動の route cache 検証は `&&` 連結だけでは途中失敗時に `route:clear` が走らない。
   失敗時にも**必ず別途** `php artisan route:clear` を実行すること。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 施策 1〜10 は「目録検査を入れる → 赤くなった穴を塞ぐ → キー規約を揃える」という一本の依存鎖であり、分割すると中間状態で CI が赤いまま残る。単一 worktree で順に積むのが最短 |
| 競合リスク | `app/Providers/AppServiceProvider.php` / `app/Providers/FortifyServiceProvider.php` / `routes/web.php` を触るため、認証系・課金系の他タスクと同時進行すると衝突しやすい。`config/fortify.php` は触らない (限定 4 キーしか受け付けないため) |
| テスト順序 | **テストファースト**。施策 2 のテストを先に書いて **fail を確認**してから施策 3〜6 に入る (AGENTS.md 思考原則 5) |

---

## 実装差分 (git diff)
diff --git a/AGENTS.md b/AGENTS.md
index 0da753a..c3970e7 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -291,3 +291,20 @@ ## ドメイン固有規約
    専用画面で受ける** (行き先のない詰みを作らない)。運用契約は `docs/architecture.md`
    §サブスク契約 Checkout とオンボーディング着地、デプロイ順序は
    `docs/billing-gate-inversion-runbook.md`
+5. **流量制限 (throttle) の付与規約**: 保護対象群 (未認証で到達しうる変更系 /
+   ステートレスな機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` / 認証面の変更系) は
+   **throttle をちょうど 1 本**持つか、`ThrottleCoverageExemption` + 30 文字以上の根拠付きで
+   exemption inventory へ登録する (`ThrottleCoverageInventoryTest` が deny-by-default で強制。
+   exemption の**前提**は `ThrottleExemptionPremiseTest` が behavioral に固定する)。
+   - named limiter のキーは **`{レーン}:{種別}:{値}`** (`RateLimiterKeyConventionTest` が
+     全 limiter を実評価して検査)。email は `EmailNormalizer` → `EmailHash` を通し、
+     平文をキャッシュキーに残さない。**`Str::transliterate()` は使わない**
+     (legitimate な Unicode email を別 user へ collapse させ巻き添えロックアウトになる)。
+     inline throttle (`throttle:6,1`) は「認証済みかつ actor 自身に閉じる操作」限定
+   - vendor 登録 route への後付けは **`RouteThrottleBinder::attachByName()`** 経由
+     (`$this->app->booted()` 内。route 名が消えたら起動時 fail-fast。route:cache と両立する冪等付与)
+   - **閾値は既存値を変えない**。新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる
+   - 未認証 webhook に**固定キーの全体天井を置かない** (throttle は署名検証より前に走るため、
+     無効 body の連打で正当通知を 429 にできる = 攻撃者が業務を止められる口になる)。
+     IP 単位は署名検証コストの上限であり正当通知の保護ではない (429 発生率を監視する)
+   - 詳細は `docs/app-integration-guide.md` §7b
diff --git a/app/Enums/Security/ThrottleCoverageExemption.php b/app/Enums/Security/ThrottleCoverageExemption.php
new file mode 100644
index 0000000..11911a6
--- /dev/null
+++ b/app/Enums/Security/ThrottleCoverageExemption.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 「保護対象群に属する route が throttle を持たないことが正しい」と裁定された理由の分類。
+ *
+ * `tests/Architecture/ThrottleCoverageInventoryTest.php` が deny-by-default で
+ * 「throttle ちょうど 1 本」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
+ * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   当てはまる case が無ければ、それは「throttle を貼るべき route」である。
+ */
+enum ThrottleCoverageExemption: string
+{
+    /**
+     * 定数メタデータ応答。
+     *
+     * 適用条件: DB アクセス・暗号処理・外部呼び出し・メール送信・ファイル書込を一切伴わず、
+     * 応答が config と url() だけで決まる。
+     */
+    case StaticMetadataResponse = 'static_metadata_response';
+
+    /**
+     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
+     *
+     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
+     */
+    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';
+
+    /**
+     * セッション破棄のみを行い、推測可能な秘密を一切扱わない route。
+     *
+     * 適用条件: 認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い。
+     */
+    case SessionTeardownOnly = 'session_teardown_only';
+
+    /**
+     * local / testing でのみ登録され、**production では route 登録自体が起きない**デバッグ用 route。
+     *
+     * 適用条件: `routes/*.php` 側で `app()->isLocal() || app()->runningUnitTests()` 等により
+     * 登録が囲われ、かつ `LocalOnly` 相当の middleware が二重防御であること。
+     * (Architecture テストは testing 環境で走るため、この route は**母集団に現れる**。
+     *  「テストからは見えない」ではなく「本番には存在しない」が exemption の根拠である)
+     */
+    case LocalOnlyDebugRoute = 'local_only_debug_route';
+
+    /**
+     * 防御が route ではなく component 内にある。
+     *
+     * 適用条件: 単一 endpoint に多数の操作が相乗りしており、route 単位の bucket では
+     * 無関係な操作を巻き添えにする。かつ component 側に実際の制限実装がある。
+     */
+    case ComponentLevelLimiter = 'component_level_limiter';
+
+    /**
+     * 有効な署名が無ければ本体処理に到達しない route。
+     *
+     * 適用条件: ハンドラ冒頭で署名検証を行い、不成立なら副作用ゼロで短絡する。
+     */
+    case SignatureRequiredBeforeEffect = 'signature_required_before_effect';
+}
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 39c946e..e42217f 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -34,7 +34,9 @@
 use App\Services\Render\FfmpegVideoComposer;
 use App\Services\Render\VideoComposer;
 use App\Support\CriticalActionContext;
+use App\Support\EmailHash;
 use App\Support\EmailNormalizer;
+use App\Support\Http\RouteThrottleBinder;
 use App\Support\PasswordPolicy;
 use App\Support\ProductionEnvGuard;
 use Aws\Sns\SnsClient;
@@ -234,6 +236,49 @@ public function boot(): void
         $this->configureApiRateLimiters();
         $this->configureInquiryRateLimiter();
         $this->configureRenderRateLimiter();
+        $this->configureWebhookRateLimiters();
+        $this->attachThrottleToVendorRoutes();
+    }
+
+    /**
+     * 未認証 webhook (SES/SNS 通知・Stripe) の RateLimiter。
+     *
+     * ★固定キーの全体天井は**置かない**。throttle middleware は署名検証より前に走るため、
+     *   固定キーのバケットを署名前に消費させると「無効 body の連打で正当な通知を 429 にできる」
+     *   = 攻撃者が任意に業務を止められる口になる。
+     *
+     * ★レーンは送信元ごとに分ける。SES への攻撃で Stripe を止めない。
+     *
+     * ★これは**署名検証コストの上限**であり、正当通知を守る全体天井ではない。
+     *   IP キーである以上、共有クラウド出口 / proxy 設定の誤りでは巻き添え 429 がありうる
+     *   (運用は送信元 IP の分布と 429 発生率を監視すること)。
+     *   正当通知の保護は「送信元の署名済み identity で bucket を切る」設計が要る (後続 TODO)。
+     *
+     * 閾値の根拠: 正常時ピークは分あたり数件〜数十件 (SES bounce/complaint、Stripe イベント)。
+     * 単一送信元からの署名検証コスト増幅 (SNS は証明書取得を伴う) を有界にする値として
+     * ピークの 1〜2 桁上の 300/min を置く。429 は SNS も Stripe も再送対象であり
+     * 恒久喪失しない (Stripe は最大 3 日間の指数バックオフ)。
+     */
+    private function configureWebhookRateLimiters(): void
+    {
+        RateLimiter::for('webhook-ses', fn (Request $request): Limit => Limit::perMinute(300)
+            ->by('webhook-ses:ip:'.($request->ip() ?? 'unknown')));
+
+        RateLimiter::for('webhook-stripe', fn (Request $request): Limit => Limit::perMinute(300)
+            ->by('webhook-stripe:ip:'.($request->ip() ?? 'unknown')));
+    }
+
+    /**
+     * vendor が自動登録する route への throttle 後付け (設定で貼れないため第 2 段)。
+     *
+     * Cashier の POST /stripe/webhook は middleware が 1 本も無い状態で公開されており、
+     * 署名検証 (VerifyWebhookSignature) は Cashier 側の設定次第で外れうる。
+     * 後付けは冪等で、route 名が消えていれば起動時 fail-fast する
+     * (route:cache 起動時の扱いは RouteThrottleBinder::attachOnBooted の docblock を参照)。
+     */
+    private function attachThrottleToVendorRoutes(): void
+    {
+        RouteThrottleBinder::attachOnBooted($this->app, ['cashier.webhook' => 'webhook-stripe']);
     }
 
     /**
@@ -251,14 +296,15 @@ private function configureRenderRateLimiter(): void
                 ? (string) $user->current_organization_id
                 : 'none';
 
-            return Limit::perMinute(6)->by("render-trigger:{$userId}:{$orgId}");
+            return Limit::perMinute(6)->by("render-trigger:actor-org:{$userId}:{$orgId}");
         });
     }
 
     /**
      * 公開問い合わせフォーム (POST /contact) の RateLimiter。IP 単独 + IP+email の 2 系統。
      * email 正規化は保存・検索と同一の EmailNormalizer に集約 (大文字小文字での limiter 回避防止)。
-     * email はキャッシュキーへの平文残存を避けるため sha256 でハッシュ化する。
+     * email はキャッシュキーへの平文残存を避けるため EmailHash (app.key 鍵付き HMAC-SHA256) で
+     * ハッシュ化する (単純 sha256 は低エントロピーな email に対して辞書攻撃に弱い)。
      * limiter は validation 前に走るため email が非 string で来うる → is_string ガード必須。
      */
     private function configureInquiryRateLimiter(): void
@@ -266,8 +312,8 @@ private function configureInquiryRateLimiter(): void
         RateLimiter::for('inquiry', function (Request $request): array {
             $rawEmail = $request->input('email', '');
             $email = is_string($rawEmail) && $rawEmail !== '' ? EmailNormalizer::normalize($rawEmail) : '';
-            $emailKey = $email !== '' ? hash('sha256', $email) : 'anon';
-            $ip = (string) $request->ip();
+            $emailKey = $email !== '' ? EmailHash::compute($email) : 'anon';
+            $ip = $request->ip() ?? 'unknown';
 
             return [
                 Limit::perMinute(5)->by('inquiry:ip:'.$ip),
@@ -297,17 +343,17 @@ private function apiRateKey(Request $request): string
     {
         $apiKey = $request->attributes->get('api_key');
         if ($apiKey instanceof ApiKey) {
-            return 'api-key:'.$apiKey->id;
+            return 'api:api-key:'.$apiKey->id;
         }
 
         // dual guard の OAuth user-token 経路 (throttle は resolve.api-actor より前段の
         // ため guard から直接引く)。actor 単位で数える (IP 共有環境での巻き添え防止)
         $oauthUser = $request->user('api-oauth');
         if ($oauthUser instanceof User) {
-            return 'oauth-user:'.$oauthUser->id;
+            return 'api:oauth-user:'.$oauthUser->id;
         }
 
-        return 'ip:'.($request->ip() ?? 'unknown');
+        return 'api:ip:'.($request->ip() ?? 'unknown');
     }
 
     /**
@@ -321,6 +367,6 @@ private function mcpRateKey(Request $request): string
             return 'mcp:user:'.$user->id;
         }
 
-        return 'ip:mcp:'.($request->ip() ?? 'unknown');
+        return 'mcp:ip:'.($request->ip() ?? 'unknown');
     }
 }
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 24c2318..dbb87e6 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -23,6 +23,9 @@
 use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Support\Auth\EmailVerificationContinuation;
+use App\Support\EmailHash;
+use App\Support\EmailNormalizer;
+use App\Support\Http\RouteThrottleBinder;
 use Illuminate\Cache\RateLimiting\Limit;
 use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Http\RedirectResponse;
@@ -31,7 +34,6 @@
 use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\RateLimiter;
 use Illuminate\Support\ServiceProvider;
-use Illuminate\Support\Str;
 use Inertia\Inertia;
 use Inertia\Response as InertiaResponse;
 use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
@@ -47,6 +49,7 @@
 use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
 use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
 use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
+use Laravel\Fortify\Features;
 use Laravel\Fortify\Fortify;
 use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
 
@@ -119,6 +122,72 @@ public function boot(): void
         $this->configureRateLimiters();
         $this->configureViews();
         $this->attachRecentAuthToSensitiveRoutes();
+        $this->attachThrottleToFortifyRoutes();
+    }
+
+    /**
+     * Fortify が登録する認証系 route への throttle 後付け表。
+     *
+     * config/fortify.php の `limiters` は login / two-factor / passkeys / verification の
+     * 4 キーしか受け付けないため、それ以外は route 名での後付けで賄う
+     * (「貼る仕組みの 3 段優先順」の第 2 段。第 1 段で貼れるものは第 1 段のまま)。
+     *
+     * 閾値の根拠:
+     *  - password-reset-request / password-reset-submit / account-register は
+     *    「未認証 + メール送信または credential 総当り」であり、**既に本番稼働中の
+     *    同性質エンドポイント (inquiry / login) と同値**にする (新しい値を発明しない)。
+     *  - `6,1` は recent-auth.password / settings.password.store と同値 (自分の credential 操作)。
+     *  - `10,1` は onboarding.activate-personal と同値 (認証済みの管理操作)。
+     *
+     * ★inline (`6,1` / `10,1`) を使ってよいのは **認証済みかつ actor 自身に閉じる route** だけ。
+     *   フレームワーク既定のキー (認証済み = user id) がちょうど求める数える単位になる。
+     *   未認証面 / 主体が IP や email になる面は必ず named limiter を作ること。
+     *
+     * ★`feature` は Fortify の機能フラグ (config/fortify.php の `features`)。
+     *   null = 常に必須 (route が無ければ起動時 fail-fast)。
+     *   非 null = その機能が有効なときだけ必須 (無効なら route 自体が登録されないため skip)。
+     *   **skip が穴にならない根拠**: 機能を再有効化して binder が skip したままなら、
+     *   ThrottleCoverageInventoryTest が「throttle 無しの保護対象 route」として必ず fail する
+     *   (binder の fail-fast と目録検査の二重の網で守る)。
+     *
+     * @return array<string, array{throttle: string, feature: string|null}>
+     */
+    private static function throttledFortifyRoutes(): array
+    {
+        return [
+            'password.email' => ['throttle' => 'password-reset-request', 'feature' => Features::resetPasswords()],
+            'password.update' => ['throttle' => 'password-reset-submit', 'feature' => Features::resetPasswords()],
+            'register.store' => ['throttle' => 'account-register', 'feature' => Features::registration()],
+            'password.confirm.store' => ['throttle' => '6,1', 'feature' => null],
+            'user-password.update' => ['throttle' => '6,1', 'feature' => Features::updatePasswords()],
+            'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
+            'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
+            'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
+            'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
+        ];
+    }
+
+    /**
+     * Fortify 登録 route へ throttle を後付けする (設定で貼れないものだけ)。
+     *
+     * route 登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
+     * booted callback で名前解決する (attachRecentAuthToSensitiveRoutes と同じ流儀)。
+     * 後付けは冪等で、route 名が消えていれば fail-fast する
+     * (route:cache 起動時の扱いは RouteThrottleBinder::attachOnBooted の docblock を参照)。
+     */
+    private function attachThrottleToFortifyRoutes(): void
+    {
+        $routes = [];
+
+        foreach (self::throttledFortifyRoutes() as $name => $spec) {
+            if ($spec['feature'] !== null && ! Features::enabled($spec['feature'])) {
+                continue; // 機能無効 = route 自体が存在しない (目録検査が二重の網)
+            }
+
+            $routes[$name] = $spec['throttle'];
+        }
+
+        RouteThrottleBinder::attachOnBooted($this->app, $routes);
     }
 
     /**
@@ -166,32 +235,108 @@ private static function appendMiddlewareIfMissing(RouteCollectionInterface $rout
 
     private function configureRateLimiters(): void
     {
-        RateLimiter::for('login', function (Request $request) {
-            $username = $request->input(Fortify::username());
-            $throttleKey = Str::transliterate(
-                Str::lower(is_string($username) ? $username : '').'|'.$request->ip(),
+        /*
+         * ログイン試行の RateLimiter。閾値 5/min は据え置き (プロダクト依存の既定値)。
+         *
+         * ★Str::transliterate を廃止した理由:
+         *   App\Support\EmailNormalizer の docblock が「legitimate な Unicode email を
+         *   別 user に collapse させるリスクがあるため使わない」と明記しているのに、
+         *   本 limiter だけが使っており設計意図と実装が正面から矛盾していた。
+         *   実害は「無関係アカウントの巻き添えロックアウト」。
+         *
+         * ★email は EmailHash (HMAC-SHA256 / app.key 鍵付き) でハッシュ化してからキーに入れる。
+         *   **canonical 化の正本は EmailNormalizer** (保存・検索・inquiry と同一)。
+         *   limiter は validation より前に走るため email が非 string で来うる → is_string ガード必須。
+         */
+        RateLimiter::for('login', function (Request $request): Limit {
+            return Limit::perMinute(5)->by(
+                'login:email-ip:'.self::emailKey($request, Fortify::username())
+                .':'.($request->ip() ?? 'unknown'),
             );
-
-            return Limit::perMinute(5)->by($throttleKey);
         });
 
-        RateLimiter::for('two-factor', function (Request $request) {
+        RateLimiter::for('two-factor', function (Request $request): Limit {
             $loginId = $request->session()->get('login.id');
 
-            return Limit::perMinute(5)->by(is_scalar($loginId) ? (string) $loginId : $request->ip().'|2fa');
+            return is_scalar($loginId)
+                ? Limit::perMinute(5)->by('two-factor:login-id:'.$loginId)
+                : Limit::perMinute(5)->by('two-factor:ip:'.($request->ip() ?? 'unknown'));
         });
 
         // passkey (WebAuthn) endpoint。config/fortify.php の limiters.passkeys が
         // この名前を指しており、未設定だと Fortify が throttle 自体を外す
         // (= 未認証の challenge 発行 GET /passkeys/login/options が無制限になる)。
         // 未認証の login-options を含むため、認証済みは user 単位・未認証は IP 単位で絞る。
-        RateLimiter::for('passkeys', function (Request $request) {
+        RateLimiter::for('passkeys', function (Request $request): Limit {
             $identifier = $request->user()?->getAuthIdentifier();
 
-            return Limit::perMinute(10)->by(
-                is_scalar($identifier) ? 'passkey|'.$identifier : 'passkey|'.$request->ip(),
-            );
+            return is_scalar($identifier)
+                ? Limit::perMinute(10)->by('passkeys:user:'.$identifier)
+                : Limit::perMinute(10)->by('passkeys:ip:'.($request->ip() ?? 'unknown'));
         });
+
+        $this->configureAuthFormRateLimiters();
+    }
+
+    /**
+     * 未認証 + メール送信 / credential 総当りを伴う認証系 POST の RateLimiter。
+     *
+     * 閾値は**既に本番稼働中の同性質エンドポイントと同値**にする (新しい値を発明しない):
+     *  - IP 単独 5/min      = inquiry (公開問い合わせフォーム) / login と同値
+     *  - IP+email 10/60min  = inquiry と同値
+     *
+     * 2 系統に分ける理由: IP 単独は「1 本の回線からのメール爆撃」を、
+     * IP+email は「同一宛先への長時間の反復」を止める (数える単位が違う)。
+     *
+     * ★`RateLimiter::for()` の第 1 引数は必ずリテラルで書く (ループで一括登録しない)。
+     *   RateLimiterKeyConventionTest の scanner が非リテラル引数を deny-by-default で
+     *   fail させる契約になっており、沈黙する登録を作らないため。
+     */
+    private function configureAuthFormRateLimiters(): void
+    {
+        RateLimiter::for(
+            'password-reset-request',
+            fn (Request $request): array => self::authFormLimits('password-reset-request', $request, Fortify::email()),
+        );
+
+        RateLimiter::for(
+            'password-reset-submit',
+            fn (Request $request): array => self::authFormLimits('password-reset-submit', $request, Fortify::email()),
+        );
+
+        RateLimiter::for(
+            'account-register',
+            fn (Request $request): array => self::authFormLimits('account-register', $request, Fortify::username()),
+        );
+    }
+
+    /**
+     * 認証フォーム系 limiter の 2 系統 (IP 単独 / IP+email) を組み立てる。
+     *
+     * @return list<Limit>
+     */
+    private static function authFormLimits(string $lane, Request $request, string $field): array
+    {
+        $ip = $request->ip() ?? 'unknown';
+
+        return [
+            Limit::perMinute(5)->by($lane.':ip:'.$ip),
+            Limit::perMinutes(60, 10)->by($lane.':ip-email:'.$ip.':'.self::emailKey($request, $field)),
+        ];
+    }
+
+    /**
+     * limiter キーに埋め込む email の鍵付きハッシュ (平文を cache キーに残さない)。
+     *
+     * 正規化の正本は EmailNormalizer (保存・検索・inquiry と同一関数)。
+     * limiter は validation より前に走るため、非 string / 空文字は `anon` へ倒す。
+     */
+    private static function emailKey(Request $request, string $field): string
+    {
+        $raw = $request->input($field);
+        $email = is_string($raw) && $raw !== '' ? EmailNormalizer::normalize($raw) : '';
+
+        return $email !== '' ? EmailHash::compute($email) : 'anon';
     }
 
     /**
diff --git a/app/Support/EmailHash.php b/app/Support/EmailHash.php
index 5158e24..28b42c2 100644
--- a/app/Support/EmailHash.php
+++ b/app/Support/EmailHash.php
@@ -12,6 +12,9 @@
  * 単純 sha256 は辞書攻撃に弱いため、ログ・補助検索用には HMAC(app.key) で keyed hash を作る。
  * 平文 email をログに出さないための識別子として使う。
  *
+ * 正規化の正本は EmailNormalizer である。本クラス内の mb_strtolower(trim(...)) は
+ * 呼び出し漏れに対する防御的な再適用であり、canonical 化の定義を持つものではない。
+ *
  * 制約: APP_KEY ローテーション時、前後の hash は突合不可になる。
  */
 final class EmailHash
diff --git a/app/Support/Http/RouteThrottleBinder.php b/app/Support/Http/RouteThrottleBinder.php
new file mode 100644
index 0000000..1eb02d3
--- /dev/null
+++ b/app/Support/Http/RouteThrottleBinder.php
@@ -0,0 +1,254 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Http;
+
+use Illuminate\Contracts\Foundation\Application;
+use Illuminate\Contracts\Foundation\CachesRoutes;
+use Illuminate\Routing\Middleware\ThrottleRequests;
+use Illuminate\Routing\Route;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Str;
+use RuntimeException;
+
+/**
+ * vendor が登録した named route へ throttle middleware を後付けする binder
+ * (「貼る仕組みの 3 段優先順」の第 2 段。設定で貼れない route 専用)。
+ *
+ * ★冪等性の契約 (route:cache と両立させるため必須):
+ *   `php artisan route:cache` の RouteCacheCommand::getFreshApplicationRoutes() は
+ *   **アプリを再 bootstrap してから** router->getRoutes() を直列化する。
+ *   provider の boot → booted callback も走るため、本 binder が付けた throttle は
+ *   **route cache に焼き込まれる**。その cache を読んだ次回起動でも booted は走るので、
+ *   「既存があれば常に例外」にすると cached 起動が必ず落ちる。
+ *   したがって「同じ limiter がちょうど 1 本なら no-op」を正とする。
+ *
+ * ★判定は文字列の完全一致にしない:
+ *   gatherRouteMiddleware() の entry は `{class}:{params}` 形式で出る。
+ *   class 部は cache driver によって ThrottleRequests / ThrottleRequestsWithRedis の
+ *   どちらにもなりうる (後者は前者を継承)。class 部は is_a() で、params 部は
+ *   limiter 名の完全一致で比較する。
+ */
+final class RouteThrottleBinder
+{
+    /** named limiter 名の形式。 */
+    private const NAMED_LIMITER_PATTERN = '/^[a-z][a-z0-9-]*$/';
+
+    /** inline throttle (`{max},{decay}`) の形式。 */
+    private const INLINE_LIMITER_PATTERN = '/^\d+,\d+$/';
+
+    /**
+     * 起動完了後に named route 群へ throttle を後付けする (登録の唯一の入口)。
+     *
+     * ★route:cache 起動では **skip する**。実測した provider 順序:
+     *   framework の RouteServiceProvider は `withRouting()` が booting callback で
+     *   登録するため **最後に boot** され、compiled route の読み込み
+     *   (`loadCachedRoutes()`) はさらにその中の `$app->booted()` へ積まれる。
+     *   よって本 callback が走る時点では compiled route collection がまだ読まれておらず、
+     *   named route を 1 本も解決できない (`loadRoutesFrom()` が cache 時に require を
+     *   飛ばすのと同じ事情)。
+     *
+     * ★skip が穴にならない根拠 (fail-fast は失われない):
+     *   `php artisan route:cache` は `route:clear` してから**アプリを再 bootstrap** して
+     *   route を直列化する。その再 bootstrap は cache 無しなので本後付けが完全に走り、
+     *   route 名が消えていればそこで**デプロイが止まる**。付与済みの throttle は
+     *   そのまま cache へ焼き込まれる。CI (テスト) も cache 無しで走るため、
+     *   目録検査 (ThrottleCoverageInventoryTest) の deny-by-default も素通りしない。
+     *
+     * @param  array<string, string>  $routes  route 名 => limiter (named 名 or `{max},{decay}`)
+     */
+    public static function attachOnBooted(Application $app, array $routes): void
+    {
+        $app->booted(static function (Application $app) use ($routes): void {
+            self::attachAll(
+                $app->make(Router::class),
+                $routes,
+                $app instanceof CachesRoutes && $app->routesAreCached(),
+            );
+        });
+    }
+
+    /**
+     * named route 群へ throttle を後付けする (`$routesAreCached` なら何もしない)。
+     *
+     * skip 判定を引数で受けることで、判定と後付けの両方を純粋関数として検証できる
+     * ({@see attachOnBooted} が実アプリの状態を渡す唯一の配線点)。
+     *
+     * @param  array<string, string>  $routes  route 名 => limiter
+     */
+    public static function attachAll(Router $router, array $routes, bool $routesAreCached): void
+    {
+        if ($routesAreCached) {
+            return; // 後付けは route:cache 生成時に焼き込み済み
+        }
+
+        foreach ($routes as $name => $limiter) {
+            self::attachByName($router, $name, $limiter);
+        }
+    }
+
+    /**
+     * named route へ `throttle:{$limiter}` を冪等に後付けする。
+     *
+     * @param  string  $routeName  Fortify / Cashier 等が登録した route 名
+     * @param  string  $limiter  named limiter 名 または `{max},{decay}` 形式
+     *
+     * @throws RuntimeException route が引けない / 別の throttle が既に付いている / 2 本以上ある
+     */
+    public static function attachByName(Router $router, string $routeName, string $limiter): void
+    {
+        // ★期待値の検証を最初に行う (route 解決や既存 entry の有無に依存させない)。
+        //   ここを後回しにすると「初回呼び出しでは `6,1,9` のような不正形式を素通しする」
+        //   非対称な穴になる。
+        self::assertValidLimiter($limiter, "throttle の期待値 [{$limiter}] (route [{$routeName}])");
+
+        $routes = $router->getRoutes();
+        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
+        // (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes と同じ前提)
+        $routes->refreshNameLookups();
+
+        $route = $routes->getByName($routeName);
+        if (! $route instanceof Route) {
+            throw new RuntimeException(
+                "throttle を後付けすべき route [{$routeName}] が見つかりません。"
+                .'vendor package が update で route 名を変えた可能性があります。'
+                .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
+            );
+        }
+
+        $entries = self::routeThrottleEntries($router, $route);
+        if ($entries === []) {
+            $route->middleware('throttle:'.$limiter);
+
+            // ★memoization の破棄が必須:
+            //   Route::gatherMiddleware() は結果を $computedMiddleware に memoize し、
+            //   dispatch 時の Router::gatherRouteMiddleware() もこの値を読む。
+            //   直前の throttleEntries() が memo を温めてしまうため、破棄しないと
+            //   「middleware() には載っているのに実行されない throttle」= 無音の無防備になる。
+            $route->computedMiddleware = null;
+
+            return;
+        }
+
+        if (count($entries) === 1) {
+            // 既存 entry 側の params も形式検証する (想定外の throttle を素通ししない)
+            $parsed = self::parseThrottleEntry($entries[0], "route [{$routeName}] の既存 throttle [{$entries[0]}]");
+            if ($parsed['params'] === $limiter) {
+                return; // route:cache 由来の再適用 = 冪等 no-op
+            }
+        }
+
+        throw new RuntimeException(
+            "route [{$routeName}] に想定外の throttle が付いています: ".implode(', ', $entries)
+            .' (期待: throttle:'.$limiter.')。二重付与は実効上限を半減させるため起動を止めます。',
+        );
+    }
+
+    /**
+     * 実効 middleware 列 (controller middleware 込み) のうち throttle entry を返す。
+     *
+     * 目録検査 (ThrottleCoverageInventoryTest) が使う**完全な**判定点。
+     * `Route::gatherMiddleware()` は controller を container から解決するため、
+     * **boot 中に呼んではならない** ({@see routeThrottleEntries} を使うこと)。
+     *
+     * @return list<string> `{class}:{params}` 形式の entry (params なしなら class のみ)
+     */
+    public static function throttleEntries(Router $router, Route $route): array
+    {
+        return self::filterThrottleEntries($router->gatherRouteMiddleware($route));
+    }
+
+    /**
+     * route 自身 (group 展開込み) の middleware のうち throttle entry を返す。
+     *
+     * ★controller middleware を見ない理由 (boot 中の副作用を避ける):
+     *   `Route::gatherMiddleware()` は controller middleware を集めるために
+     *   **controller を container から解決する**。boot 中にこれを行うと、
+     *   controller が constructor injection で要求する request scope の singleton
+     *   (`StatefulGuard` → `session.store` 等) が boot 時点で確定してしまい、
+     *   その後の設定変更・request 生成に追随しなくなる
+     *   (実測: Fortify の ConfirmablePasswordController が StatefulGuard を要求する)。
+     *
+     * ★見落としが穴にならない根拠:
+     *   controller middleware が throttle を足していた場合、本 binder は二重付与になるが、
+     *   目録検査 ({@see throttleEntries} を使う ThrottleCoverageInventoryTest) が
+     *   「throttle 2 本以上」として必ず fail させる。
+     *
+     * @return list<string>
+     */
+    public static function routeThrottleEntries(Router $router, Route $route): array
+    {
+        return self::filterThrottleEntries(
+            $router->resolveMiddleware($route->middleware(), $route->excludedMiddleware()),
+        );
+    }
+
+    /**
+     * 解決済み middleware 列から throttle entry だけを取り出す。
+     *
+     * @param  iterable<mixed>  $resolved
+     * @return list<string>
+     */
+    private static function filterThrottleEntries(iterable $resolved): array
+    {
+        $entries = [];
+
+        foreach ($resolved as $middleware) {
+            // 解決後の列には Closure middleware も混ざりうる (throttle ではない)
+            if (is_string($middleware) && self::isThrottleEntry($middleware)) {
+                $entries[] = $middleware;
+            }
+        }
+
+        return $entries;
+    }
+
+    /** entry の class 部が throttle middleware か。 */
+    public static function isThrottleEntry(string $middlewareEntry): bool
+    {
+        $class = Str::before($middlewareEntry, ':'); // class 名に ':' は含まれない
+
+        return is_a($class, ThrottleRequests::class, true);
+    }
+
+    /**
+     * throttle entry を class 部 / params 部に分解し、params の形式まで検証する。
+     *
+     * @return array{class: string, params: string}
+     *
+     * @throws RuntimeException params が named / inline のどちらの形式にも一致しない場合
+     */
+    private static function parseThrottleEntry(string $entry, string $context): array
+    {
+        $class = Str::before($entry, ':');
+        // ★`:` を含まない entry (パラメータなし throttle) は params = '' になり、
+        //   assertValidLimiter が必ず例外側へ落とす (意図どおり)。
+        $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
+
+        self::assertValidLimiter($params, $context);
+
+        return ['class' => $class, 'params' => $params];
+    }
+
+    /**
+     * limiter 指定の形式を検証する (開発時ミス / 想定外 throttle の検出)。
+     *
+     * @throws RuntimeException named / inline のどちらの形式にも一致しない場合
+     */
+    private static function assertValidLimiter(string $limiter, string $context): void
+    {
+        if (preg_match(self::NAMED_LIMITER_PATTERN, $limiter) === 1) {
+            return;
+        }
+        if (preg_match(self::INLINE_LIMITER_PATTERN, $limiter) === 1) {
+            return;
+        }
+
+        throw new RuntimeException(
+            $context.' が throttle の許容形式に一致しません。'
+            .'named limiter 名 (`[a-z][a-z0-9-]*`) か inline 形式 (`{max},{decay}`) のいずれかで指定してください。'
+            .'想定外の形式を素通しすると、意図しない上限のまま公開される事故になります。',
+        );
+    }
+}
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index f1b0aa2..a84434e 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -284,9 +284,62 @@ ### 新規 route(特に変更系)を足すときのチェックリスト
    「権限の無い actor が実際に 403 になること」は Feature テストの責務
    (見本: `tests/Feature/Api/V1/ItemAuthorizationTest.php`)。
    この 2 層(入口 = Architecture / 実挙動 = Feature)はセットで維持する
-6. `composer test` で 3 つの gate
+6. **流量制限 (throttle) を付ける**。保護対象群(未認証で到達しうる変更系 /
+   ステートレスな機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` /
+   認証面の変更系)は **throttle をちょうど 1 本**持つか、
+   `ThrottleCoverageInventoryTest` の exemption inventory へ
+   `ThrottleCoverageExemption` + 30 文字以上の根拠付きで登録する(deny-by-default)。
+   詳細は下の「§7b 流量制限の付与規約」
+7. `composer test` で 4 つの gate
    (`ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` /
-   `ProjectRouteCurrentOrgGuardTest`)が green であることを確認する
+   `ProjectRouteCurrentOrgGuardTest` / `ThrottleCoverageInventoryTest`)が
+   green であることを確認する
+
+### §7b 流量制限の付与規約
+
+**貼る仕組みの 3 段優先順**(上から順に検討し、上で貼れるなら下は使わない):
+
+1. **route 定義に直接書く**(自前 route)。`->middleware('throttle:{limiter}')`
+2. **`RouteThrottleBinder::attachByName()` で後付けする**(vendor 登録 route で、
+   package 側の設定では貼れないもの)。`$this->app->booted()` の中で呼び、
+   route 名が消えていれば**起動時に fail-fast** する
+   (silent degradation = 無音の無防備を作らない)。
+   付与は冪等で `php artisan route:cache` と両立する
+   (実装: `app/Support/Http/RouteThrottleBinder.php`)
+3. **package の設定で貼る**(`config/fortify.php` の `limiters` など。
+   受け付けるキーが限られるため、賄えない分は 2 に落とす)
+
+**キー規約**: named limiter のキーは `{レーン}:{種別}:{値}`
+(例 `login:email-ip:{hash}:{ip}` / `webhook-ses:ip:{ip}`)。
+`RateLimiterKeyConventionTest` が全 limiter を実際に評価して機械検査する。
+
+- **email をキーに入れるときは `EmailNormalizer::normalize()` → `EmailHash::compute()`**。
+  平文も正規化済み平文もキャッシュキーに残さない。
+  `Str::transliterate()` は**使わない**(legitimate な Unicode email を別 user へ
+  collapse させ、無関係アカウントの巻き添えロックアウトになる)
+- **inline throttle (`throttle:6,1`) を使ってよいのは「認証済みかつ actor 自身に
+  閉じる操作」だけ**。フレームワーク既定のキー(認証済み = user id)が
+  ちょうど求める数える単位になる場合に限る。未認証面 / 主体が IP や email に
+  なる面は必ず named limiter を作る
+- **limiter キーに route parameter を入れない**(`NamedRateLimiterKeyTest`)。
+  bucket が id ごとに分かれると「429 になるまでの回数」が実在を漏らす
+
+**閾値**: プロダクト依存のため既存値を勝手に変えない。新しい面には
+**既に本番稼働している同性質エンドポイントと同値**を充てる
+(公開フォーム = IP 5/min + IP+email 10/60min、自分の credential 操作 = 6/min、
+認証済みの管理操作 = 10/min)。
+
+**未認証 webhook の注意**: throttle は署名検証より**先**に走る。したがって
+固定キー(全体天井)を置くと「無効 body の連打で正当な通知を 429 にできる」
+= 攻撃者が業務を止められる口になる。IP 単位に留め、これは
+**署名検証コストの上限であって正当通知を守る全体天井ではない**と理解する
+(共有クラウド出口では巻き添え 429 がありうるため、送信元 IP の分布と
+429 発生率を監視項目に入れる)。
+
+**exemption を書くときの原則**: exemption は「throttle が無いことが**正しい**」
+という主張であり、その**前提**(署名で短絡する / 定数応答である /
+production では登録されない)は `ThrottleExemptionPremiseTest` で
+behavioral に固定する。前提が崩れたのに気づけない状態を作らない。
 
 ## 8. 設計ドキュメントの書き方(このテンプレ上の流儀)
 
diff --git a/routes/web.php b/routes/web.php
index 4646772..485c693 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -105,7 +105,10 @@
         PreventRequestForgery::class,
         HandleInertiaRequests::class,
     ])
-    ->middleware('sns.signature')
+    // throttle は署名検証より**先**に走る (priority list により ThrottleRequests が先行)。
+    // これは署名検証コスト (SNS は証明書取得を伴う) の上限であり、正当通知を守る全体天井ではない。
+    // 実効順は tests/Feature/Security/AuthThrottleCoverageTest が固定する。
+    ->middleware(['throttle:webhook-ses', 'sns.signature'])
     ->name('webhooks.ses');
 
 /*
@@ -596,8 +599,10 @@
 */
 Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
     ->name('invitations.accept');
+// 招待トークンは hash 照合されるが、総当り試行そのものを有界にする
+// (onboarding.activate-personal と同値 = 認証済みの一回性操作)。
 Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
-    ->middleware('auth')
+    ->middleware(['auth', 'throttle:10,1'])
     ->name('invitations.accept.store');
 
 /*
diff --git a/tests/Architecture/RateLimiterKeyConventionTest.php b/tests/Architecture/RateLimiterKeyConventionTest.php
new file mode 100644
index 0000000..e0bb522
--- /dev/null
+++ b/tests/Architecture/RateLimiterKeyConventionTest.php
@@ -0,0 +1,346 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\ApiKey;
+use App\Models\User;
+use App\Support\EmailHash;
+use Illuminate\Cache\RateLimiter as CacheRateLimiter;
+use Illuminate\Cache\RateLimiting\Limit;
+use Illuminate\Http\Request;
+use Illuminate\Session\ArraySessionHandler;
+use Illuminate\Session\Store;
+use Tests\Support\RateLimiterRegistrationScanner;
+use Webmozart\Assert\Assert;
+
+/*
+ * named limiter のキー規約 `{レーン}:{種別}:{値}` の behavioral proof。
+ *
+ * ★検査対象は **named limiter のみ**。inline throttle (`throttle:6,1` 等) は
+ *   フレームワーク既定のキー (認証済み = user id / 未認証 = ハッシュ化 IP) を使い、
+ *   これは「認証済みかつ actor 自身に閉じる操作」では正しい数える単位である。
+ *   キー明示規約は「**自前でキーを組み立てるとき**」の規約であり、対象を named limiter に限る。
+ *
+ * ★2 層で守る:
+ *   (1) 登録の網羅 — token 走査 (RateLimiterRegistrationScanner) で見つけた
+ *       `RateLimiter::for()` の名前集合が inventory と完全一致すること。
+ *       解析できない登録 (unresolved) は 1 件でも fail (沈黙する登録を作らせない)。
+ *   (2) キーの実挙動 — 各 limiter を実際に評価し、produce されたキーが規約に合うこと。
+ */
+
+/** キー規約の正規表現 (`{レーン}:{種別}:` の接頭辞)。 */
+function rateLimiterKeyConventionPattern(): string
+{
+    return '#^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:#';
+}
+
+/** 評価シナリオが使う固定 IP (キーに現れることを前提にしない。単に決定性のため)。 */
+function rateLimiterScenarioIp(): string
+{
+    return '203.0.113.7';
+}
+
+/** email を扱う scenario が使う平文 email (大文字混じり = 正規化の検証を兼ねる)。 */
+function rateLimiterScenarioEmail(): string
+{
+    return 'Throttle.Probe@Example.COM';
+}
+
+/** guest な Request (session なし / user なし)。 */
+function rateLimiterGuestRequest(array $input = []): Request
+{
+    $request = Request::create('/probe', 'POST', $input, server: ['REMOTE_ADDR' => rateLimiterScenarioIp()]);
+    $request->setUserResolver(static fn (): ?User => null);
+
+    return $request;
+}
+
+/** 認証済み Request (指定 user を全 guard で返す)。 */
+function rateLimiterAuthenticatedRequest(User $user, array $input = []): Request
+{
+    $request = rateLimiterGuestRequest($input);
+    $request->setUserResolver(static fn (): User => $user);
+
+    return $request;
+}
+
+/** session 付き Request (two-factor limiter は session 必須)。 */
+function rateLimiterSessionRequest(?string $loginId): Request
+{
+    $request = rateLimiterGuestRequest();
+    $session = new Store('probe-session', new ArraySessionHandler(120));
+    if ($loginId !== null) {
+        $session->put('login.id', $loginId);
+    }
+    $request->setLaravelSession($session);
+
+    return $request;
+}
+
+/** DB に触れずに id を持つ User を組み立てる (Architecture レーンは RefreshDatabase 非適用)。 */
+function rateLimiterProbeUser(?int $organizationId = null): User
+{
+    $user = User::factory()->make();
+    Assert::isInstanceOf($user, User::class);
+    $user->forceFill(['id' => 4242, 'current_organization_id' => $organizationId]);
+
+    return $user;
+}
+
+/** DB に触れずに id を持つ ApiKey を組み立てる。 */
+function rateLimiterProbeApiKey(): ApiKey
+{
+    $apiKey = ApiKey::factory()->make(['organization_id' => 77]);
+    Assert::isInstanceOf($apiKey, ApiKey::class);
+    $apiKey->forceFill(['id' => 99]);
+
+    return $apiKey;
+}
+
+/** api-* limiter の with-api-key scenario。 */
+function rateLimiterApiKeyRequest(): Request
+{
+    $request = rateLimiterGuestRequest();
+    $request->attributes->set('api_key', rateLimiterProbeApiKey());
+
+    return $request;
+}
+
+/**
+ * limiter ごとの評価シナリオと期待されるキー接頭辞。
+ *
+ * @return array<string, array{
+ *   scenarios: array<string, callable(): Request>,
+ *   expectedKeyPrefixes: list<string>,
+ *   emailScenarios: list<string>,
+ * }>
+ *   scenarios           = 分岐名 => Request ビルダ
+ *   expectedKeyPrefixes = produce されるべき `{レーン}:{種別}` の**完全な**集合
+ *   emailScenarios      = email をキーに含む scenario 名 (平文残存 / ハッシュ化の検証対象)
+ */
+function rateLimiterKeyInventory(): array
+{
+    $email = rateLimiterScenarioEmail();
+    $withEmail = static fn (string $field): callable => static fn (): Request => rateLimiterGuestRequest([$field => $email]);
+    $noEmail = static fn (): Request => rateLimiterGuestRequest();
+
+    /** @var array<string, array{scenarios: array<string, callable(): Request>, expectedKeyPrefixes: list<string>, emailScenarios: list<string>}> $inventory */
+    $inventory = [
+        'login' => [
+            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
+            'expectedKeyPrefixes' => ['login:email-ip'],
+            'emailScenarios' => ['with-email'],
+        ],
+        'two-factor' => [
+            'scenarios' => [
+                'with-login-id' => static fn (): Request => rateLimiterSessionRequest('4242'),
+                'guest' => static fn (): Request => rateLimiterSessionRequest(null),
+            ],
+            'expectedKeyPrefixes' => ['two-factor:login-id', 'two-factor:ip'],
+            'emailScenarios' => [],
+        ],
+        'passkeys' => [
+            'scenarios' => [
+                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
+                'guest' => $noEmail,
+            ],
+            'expectedKeyPrefixes' => ['passkeys:user', 'passkeys:ip'],
+            'emailScenarios' => [],
+        ],
+        'render-trigger' => [
+            'scenarios' => [
+                'authenticated-with-org' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser(7)),
+                'guest' => $noEmail,
+            ],
+            'expectedKeyPrefixes' => ['render-trigger:actor-org'],
+            'emailScenarios' => [],
+        ],
+        'inquiry' => [
+            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
+            'expectedKeyPrefixes' => ['inquiry:ip', 'inquiry:ip-email'],
+            'emailScenarios' => ['with-email'],
+        ],
+        'password-reset-request' => [
+            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
+            'expectedKeyPrefixes' => ['password-reset-request:ip', 'password-reset-request:ip-email'],
+            'emailScenarios' => ['with-email'],
+        ],
+        'password-reset-submit' => [
+            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
+            'expectedKeyPrefixes' => ['password-reset-submit:ip', 'password-reset-submit:ip-email'],
+            'emailScenarios' => ['with-email'],
+        ],
+        'account-register' => [
+            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
+            'expectedKeyPrefixes' => ['account-register:ip', 'account-register:ip-email'],
+            'emailScenarios' => ['with-email'],
+        ],
+        'api-mcp' => [
+            'scenarios' => ['guest' => $noEmail],
+            'expectedKeyPrefixes' => ['mcp:ip'],
+            'emailScenarios' => [],
+        ],
+        'oauth-register' => [
+            'scenarios' => ['guest' => $noEmail],
+            'expectedKeyPrefixes' => ['oauth-register:ip'],
+            'emailScenarios' => [],
+        ],
+        'webhook-ses' => [
+            'scenarios' => ['guest' => $noEmail],
+            'expectedKeyPrefixes' => ['webhook-ses:ip'],
+            'emailScenarios' => [],
+        ],
+        'webhook-stripe' => [
+            'scenarios' => ['guest' => $noEmail],
+            'expectedKeyPrefixes' => ['webhook-stripe:ip'],
+            'emailScenarios' => [],
+        ],
+    ];
+
+    // api-read / api-write / api-status は同一 apiRateKey() を共有する
+    // (oauth-user 分岐は guard 解決が要るため scenario から外す = expectedKeyPrefixes にも入れない)。
+    foreach (['api-read', 'api-write', 'api-status'] as $lane) {
+        $inventory[$lane] = [
+            'scenarios' => [
+                'with-api-key' => static fn (): Request => rateLimiterApiKeyRequest(),
+                'guest' => $noEmail,
+            ],
+            'expectedKeyPrefixes' => ['api:api-key', 'api:ip'],
+            'emailScenarios' => [],
+        ];
+    }
+
+    return $inventory;
+}
+
+/**
+ * limiter を評価して produce された Limit を返す。
+ *
+ * @return list<Limit>
+ */
+function rateLimiterProduceLimits(string $name, Request $request): array
+{
+    $limiter = app(CacheRateLimiter::class)->limiter($name);
+    Assert::notNull($limiter, "named limiter [{$name}] が登録されていません");
+
+    $result = $limiter($request);
+    $limits = is_array($result) ? array_values($result) : [$result];
+    Assert::allIsInstanceOf($limits, Limit::class);
+
+    return $limits;
+}
+
+/** キーから `{レーン}:{種別}` の接頭辞を取り出す。 */
+function rateLimiterKeyPrefix(string $key): string
+{
+    $segments = explode(':', $key);
+
+    return ($segments[0] ?? '').':'.($segments[1] ?? '');
+}
+
+test('scan で検出した limiter 名の集合が inventory と完全一致する (未知 limiter は fail)', function (): void {
+    $scanned = RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app');
+
+    $found = array_values(array_unique($scanned['names']));
+    sort($found);
+    $expected = array_keys(rateLimiterKeyInventory());
+    sort($expected);
+
+    expect($found)->toBe($expected,
+        'app/ 配下の RateLimiter::for() 登録と rateLimiterKeyInventory() が食い違っています。'
+        .'limiter を足したらキー規約の検証シナリオも同時に登録してください。');
+});
+
+test('scan の unresolved が 0 件である (解析できない登録を沈黙させない)', function (): void {
+    $scanned = RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app');
+
+    expect($scanned['unresolved'])->toBe([],
+        'RateLimiter::for() の登録で解析できないものがあります。'
+        .'第 1 引数はリテラル文字列で、呼び出しは use 済み短縮名か完全修飾名で書いてください。'
+        .PHP_EOL.implode(PHP_EOL, $scanned['unresolved']));
+});
+
+test('全 scenario の全 Limit キーが規約パターン {レーン}:{種別}:{値} に一致する', function (): void {
+    $pattern = rateLimiterKeyConventionPattern();
+    $violations = [];
+
+    foreach (rateLimiterKeyInventory() as $name => $spec) {
+        foreach ($spec['scenarios'] as $scenario => $build) {
+            foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
+                $key = (string) $limit->key;
+                if (preg_match($pattern, $key) !== 1) {
+                    $violations[] = "{$name}/{$scenario}: キー [{$key}] が規約に一致しません";
+                }
+            }
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('produce された {レーン}:{種別} 集合が expectedKeyPrefixes と完全一致する', function (): void {
+    $violations = [];
+
+    foreach (rateLimiterKeyInventory() as $name => $spec) {
+        $produced = [];
+        foreach ($spec['scenarios'] as $build) {
+            foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
+                $produced[rateLimiterKeyPrefix((string) $limit->key)] = true;
+            }
+        }
+
+        $actual = array_keys($produced);
+        sort($actual);
+        $expected = $spec['expectedKeyPrefixes'];
+        sort($expected);
+
+        if ($actual !== $expected) {
+            $violations[] = "{$name}: 期待 [".implode(', ', $expected).'] 実際 ['.implode(', ', $actual).']';
+        }
+    }
+
+    expect($violations)->toBe([],
+        'limiter が produce するキー接頭辞が宣言と食い違っています。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('email を扱う limiter のキーに平文 email も正規化済み email も含まれない', function (): void {
+    $plain = rateLimiterScenarioEmail();
+    $normalized = mb_strtolower($plain);
+    $violations = [];
+
+    foreach (rateLimiterKeyInventory() as $name => $spec) {
+        foreach ($spec['emailScenarios'] as $scenario) {
+            foreach (rateLimiterProduceLimits($name, $spec['scenarios'][$scenario]()) as $limit) {
+                $key = (string) $limit->key;
+                if (str_contains($key, $plain) || str_contains($key, $normalized)) {
+                    $violations[] = "{$name}/{$scenario}: キーに email 平文が残っています";
+                }
+            }
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('email を扱う limiter のキーに EmailHash::compute の値が含まれる (正規化 + 鍵付きハッシュ)', function (): void {
+    // 大文字混じりの平文を正規化してからハッシュ化する = 大文字小文字での bypass が起きない
+    $hash = EmailHash::compute(mb_strtolower(rateLimiterScenarioEmail()));
+    $violations = [];
+
+    foreach (rateLimiterKeyInventory() as $name => $spec) {
+        foreach ($spec['emailScenarios'] as $scenario) {
+            $found = false;
+            foreach (rateLimiterProduceLimits($name, $spec['scenarios'][$scenario]()) as $limit) {
+                if (str_contains((string) $limit->key, $hash)) {
+                    $found = true;
+                }
+            }
+            if (! $found) {
+                $violations[] = "{$name}/{$scenario}: キーに EmailHash::compute() の値が含まれていません";
+            }
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
diff --git a/tests/Architecture/ThrottleCoverageInventoryTest.php b/tests/Architecture/ThrottleCoverageInventoryTest.php
new file mode 100644
index 0000000..208e58e
--- /dev/null
+++ b/tests/Architecture/ThrottleCoverageInventoryTest.php
@@ -0,0 +1,282 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\ThrottleCoverageExemption;
+use App\Support\Http\RouteThrottleBinder;
+use Illuminate\Auth\Middleware\Authenticate;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Session\Middleware\StartSession;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Str;
+
+/*
+ * 流量制限 (throttle) の付与漏れ invariant (deny-by-default)。
+ *
+ * 「保護対象群に属する route は throttle をちょうど 1 本持つ」を機械強制する。
+ * 持たないものは理由付きで exemption inventory へ明示登録させる。
+ *
+ * ★保護対象群 (S1 ∪ S2 ∪ S3) は意図的に**過大に**取る:
+ *   S1 は「未認証で本体に到達する」ことを主張しない。signed / 定数 405 スタブ /
+ *   LocalOnly / 署名検証など、Authenticate 以外で本体到達を閉じる route も S1 に入る。
+ *   **exemption の役割は「本体到達しない根拠を固定すること」**である
+ *   (過小なセレクタはすり抜けを生むが、過大なセレクタは exemption 理由という形で
+ *    根拠が文書化されるだけで済む)。
+ *
+ * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
+ *   (`route:list --json` は group 名 'web' が展開されず誤判定するため使わない)。
+ *   throttle 判定は RouteThrottleBinder::isThrottleEntry() を唯一の判定点として共有する。
+ */
+
+/** 変更系 HTTP メソッド。 */
+function throttleCoverageMutatingMethods(): array
+{
+    return ['POST', 'PUT', 'PATCH', 'DELETE'];
+}
+
+/** 認証面の route 名パターン (S3)。 */
+function throttleCoverageAuthSurfacePattern(): string
+{
+    return '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
+        .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#';
+}
+
+/** 母集団件数の下限 (空振り drift ガード。実測 47 に対し余裕を持たせた値)。 */
+function throttleCoverageRouteFloor(): int
+{
+    return 40;
+}
+
+/** exemption 件数の上限 (形骸化ガード)。 */
+function throttleCoverageExemptionCap(): int
+{
+    return 14;
+}
+
+/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+function throttleCoverageReasonMinLength(): int
+{
+    return 30;
+}
+
+/**
+ * throttle を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
+ *
+ * @return array<string, array{ThrottleCoverageExemption, string}>
+ */
+function throttleCoverageExemptions(): array
+{
+    $metadata = ThrottleCoverageExemption::StaticMetadataResponse;
+    $stub = ThrottleCoverageExemption::VendorMethodNotAllowedStub;
+    $teardown = ThrottleCoverageExemption::SessionTeardownOnly;
+    $localOnly = ThrottleCoverageExemption::LocalOnlyDebugRoute;
+    $component = ThrottleCoverageExemption::ComponentLevelLimiter;
+    $signature = ThrottleCoverageExemption::SignatureRequiredBeforeEffect;
+
+    return [
+        'mcp.oauth.authorization-server' => [$metadata,
+            'Laravel\Mcp\Server\Registrar::authorizationServerMetadata() が config と url() と route() だけで'
+            .'組む定数 JSON を返す。DB アクセス・暗号処理・外部呼び出し・メール送信を一切伴わないため、'
+            .'連打しても増幅する処理コストが存在しない。前提は ThrottleExemptionPremiseTest が固定する。'],
+
+        'mcp.oauth.authorization-server.nested' => [$metadata,
+            '上記 authorization-server と同一ハンドラ。{path} は応答内容に影響せず (RFC 8414 の'
+            .'path-insertion 形式に対応するためだけの別 URI)、定数 JSON を返す点も同じ。'],
+
+        'mcp.oauth.protected-resource' => [$metadata,
+            'Laravel\Mcp\Server\Registrar::protectedResourceMetadata() が同様に config と url() だけで'
+            .'組む定数 JSON を返す。DB アクセス・暗号処理・外部呼び出しを伴わない。'],
+
+        'mcp.oauth.protected-resource.nested' => [$metadata,
+            '上記 protected-resource と同一ハンドラ。{path} は応答内容に影響しない定数 JSON。'],
+
+        'GET /api/v1/mcp' => [$stub,
+            'Laravel\Mcp\Server\Registrar::web() が登録する response(\'\', 405)->header(\'Allow\', \'POST\') の'
+            .'固定応答。MCP 仕様上の SSE 非対応表明であり、ハンドラは本体処理へ一切到達しない。'],
+
+        'DELETE /api/v1/mcp' => [$stub,
+            'GET と同じく Registrar::web() の定数 405 スタブ (Allow: POST)。session 終了 API 非対応の'
+            .'表明であり本体処理へ到達しない。'],
+
+        'logout' => [$teardown,
+            'auth:web 必須。セッション破棄と Inertia::clearHistory() のみを行い、'
+            .'推測可能な秘密を一切扱わないため失敗しても攻撃者が得る情報が無い。'],
+
+        'filament.admin.auth.logout' => [$teardown,
+            'Filament panel の logout。認証済みでのみ到達でき、セッション破棄以外の副作用が無い。'
+            .'秘密の推測に使えないため連打しても攻撃者の利得が無い。'],
+
+        'debug.login-as' => [$localOnly,
+            'routes/web.php の if (app()->isLocal() || app()->runningUnitTests()) により'
+            .'**production では route 登録自体が起きない** (testing では登録されるため母集団に現れる)。'
+            .'加えて LocalOnly middleware (local 以外 404 + Basic 認証 + 未設定 404) が二重防御。'],
+
+        'default-livewire.update' => [$component,
+            'Filament 管理画面の全 Livewire 操作が相乗りする単一 endpoint。route 単位の bucket を貼ると'
+            .'無関係な管理操作を巻き添えにする。実際の制限は component 内にあり'
+            .'(vendor/filament/filament/src/Auth/Pages/Login.php の rateLimit(5)。Register / '
+            .'ResetPassword / EmailVerificationPrompt も同様)、認証面はそこで有界化されている。'],
+
+        'storage.local.upload' => [$signature,
+            'Illuminate\Filesystem\ReceiveFile::__invoke() が本体到達前に abort_unless('
+            .'$request->boolean(\'upload\') && $request->hasValidRelativeSignature(), ...) で短絡し、'
+            .'署名が無ければファイル書込を含む副作用がゼロになる。前提は ThrottleExemptionPremiseTest が固定する。'],
+    ];
+}
+
+/** 解決後 middleware 列 (Closure を除いた文字列 entry のみ)。 */
+function throttleCoverageResolvedMiddleware(RoutingRoute $route): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+
+    return array_values(array_filter(
+        $router->gatherRouteMiddleware($route),
+        static fn (mixed $entry): bool => is_string($entry),
+    ));
+}
+
+/** 解決後 middleware 列に指定クラス (パラメータ付き entry を含む) があるか。 */
+function throttleCoverageHasMiddlewareClass(RoutingRoute $route, string $class): bool
+{
+    foreach (throttleCoverageResolvedMiddleware($route) as $entry) {
+        if (is_a(Str::before($entry, ':'), $class, true)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * route の inventory キー (名前があれば名前、無ければ `{METHOD} /{uri}`)。
+ * HEAD は methods() から除外して主メソッドを使う。
+ */
+function throttleCoverageRouteLabel(RoutingRoute $route): string
+{
+    $name = $route->getName();
+    if ($name !== null && $name !== '') {
+        return $name;
+    }
+
+    $methods = array_values(array_diff($route->methods(), ['HEAD']));
+
+    return implode('|', $methods).' /'.$route->uri();
+}
+
+/** @return list<RoutingRoute> 保護対象群 (S1 ∪ S2 ∪ S3)。 */
+function throttleCoverageProtectedRoutes(): array
+{
+    $mutating = throttleCoverageMutatingMethods();
+    $pattern = throttleCoverageAuthSurfacePattern();
+    $protected = [];
+
+    foreach (Route::getRoutes() as $route) {
+        $isMutating = array_intersect($mutating, $route->methods()) !== [];
+        $uri = $route->uri();
+        $name = $route->getName() ?? '';
+
+        // S1: 未認証で到達可能な可能性がある変更系
+        $s1 = $isMutating
+            && ! throttleCoverageHasMiddlewareClass($route, Authenticate::class);
+
+        // S2: ステートレスな機械向け経路
+        $s2 = (str_starts_with($uri, 'api/') || str_starts_with($uri, 'oauth/')
+                || str_starts_with($uri, '.well-known/oauth-'))
+            && ! throttleCoverageHasMiddlewareClass($route, StartSession::class);
+
+        // S3: 認証済み側も含む credential 面
+        $s3 = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;
+
+        if ($s1 || $s2 || $s3) {
+            $protected[] = $route;
+        }
+    }
+
+    return $protected;
+}
+
+test('保護対象 route の母集団が下限を下回らない (セレクタの空振り検出)', function (): void {
+    $count = count(throttleCoverageProtectedRoutes());
+
+    expect($count)->toBeGreaterThanOrEqual(
+        throttleCoverageRouteFloor(),
+        "保護対象 route が {$count} 件しか検出されませんでした。"
+        .'セレクタ (S1/S2/S3) が空振りしている可能性があります。',
+    );
+});
+
+test('保護対象 route は throttle をちょうど 1 本持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $inventory = throttleCoverageExemptions();
+    $violations = [];
+
+    foreach (throttleCoverageProtectedRoutes() as $route) {
+        $label = throttleCoverageRouteLabel($route);
+        $entries = RouteThrottleBinder::throttleEntries($router, $route);
+
+        if (count($entries) === 1) {
+            continue;
+        }
+
+        if ($entries === [] && array_key_exists($label, $inventory)) {
+            continue;
+        }
+
+        $violations[] = $entries === []
+            ? "{$label}: throttle が 1 本も無く exemption inventory にも未登録"
+            : "{$label}: throttle が ".count($entries).' 本ある ('.implode(', ', $entries).')';
+    }
+
+    expect($violations)->toBe([],
+        '保護対象 route の throttle 付与が不正です。throttle を貼るか、'
+        .'貼らないことが正しい理由を throttleCoverageExemptions() に'
+        .'ThrottleCoverageExemption + 具体的根拠付きで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption inventory の key は現存する保護対象 route (stale 検出)', function (): void {
+    $labels = [];
+    foreach (throttleCoverageProtectedRoutes() as $route) {
+        $labels[throttleCoverageRouteLabel($route)] = true;
+    }
+
+    $stale = [];
+    foreach (array_keys(throttleCoverageExemptions()) as $key) {
+        if (! isset($labels[$key])) {
+            $stale[] = $key;
+        }
+    }
+
+    expect($stale)->toBe([],
+        'exemption inventory に現存しない route ラベル (削除/rename 済、または throttle 付与済で'
+        .'exemption が不要になったもの) があります: '.implode(', ', $stale));
+});
+
+test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
+    $minLength = throttleCoverageReasonMinLength();
+    $violations = [];
+
+    foreach (throttleCoverageExemptions() as $label => [$exemption, $reason]) {
+        if (! $exemption instanceof ThrottleCoverageExemption) {
+            $violations[] = "{$label}: 第 1 要素が ThrottleCoverageExemption ではありません";
+        }
+        if (mb_strlen($reason) < $minLength) {
+            $violations[] = "{$label}: 理由が {$minLength} 文字未満です (「同上」「N/A」で埋める運用を止めます)";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption 件数が上限を超えない (形骸化ガード)', function (): void {
+    $count = count(throttleCoverageExemptions());
+
+    expect($count)->toBeLessThanOrEqual(
+        throttleCoverageExemptionCap(),
+        "exemption が {$count} 件あります。セレクタが広すぎるか、throttle を貼るべき route を"
+        .'exemption で逃がしている可能性があります (上限を上げる前に必ず再検討すること)。',
+    );
+});
diff --git a/tests/Feature/Security/AuthThrottleCoverageTest.php b/tests/Feature/Security/AuthThrottleCoverageTest.php
new file mode 100644
index 0000000..504dbec
--- /dev/null
+++ b/tests/Feature/Security/AuthThrottleCoverageTest.php
@@ -0,0 +1,206 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\RequireRecentAuth;
+use App\Http\Middleware\VerifySnsSignature;
+use Illuminate\Routing\Middleware\ThrottleRequests;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Testing\TestResponse;
+
+/*
+ * T120 で新設した認証系 / webhook throttle の behavioral proof。
+ *
+ * 目録検査 (ThrottleCoverageInventoryTest) は「throttle が付いているか」までしか見ない。
+ * 実際に 429 で止まるか・どの単位で数えるか・どの middleware より先に走るかは
+ * 実挙動でしか固定できないため、ここで契約として固定する。
+ *
+ * cache store はテスト実行時 array に強制されている (phpunit.xml) ため、
+ * app を作り直す各テストで RateLimiter のバケットは空から始まる。
+ */
+
+/** 何回叩いても同じ結果になる POST helper。 */
+function throttleProbePost(string $uri, array $payload = []): TestResponse
+{
+    return test()->post($uri, $payload);
+}
+
+test('POST /forgot-password は 5 回目まで通り 6 回目で 429 (IP レーン 5/min)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        $response = throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
+        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で既に 429 になりました");
+    }
+
+    expect(throttleProbePost('/forgot-password', ['email' => 'probe@example.com'])->getStatusCode())->toBe(429);
+});
+
+test('429 応答は Retry-After と X-RateLimit-* ヘッダを持つ (既定ヘッダを削らない)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
+    }
+    $response = throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
+
+    expect($response->getStatusCode())->toBe(429);
+    expect($response->headers->get('Retry-After'))->not->toBeNull();
+    expect($response->headers->get('X-RateLimit-Limit'))->not->toBeNull();
+    expect($response->headers->get('X-RateLimit-Remaining'))->not->toBeNull();
+});
+
+/*
+ * IP+email レーン (10/60min) は 2 番目の Limit のため、応答ヘッダの残数はこのレーンを表す
+ * (ThrottleRequests は limits を順に処理し、ヘッダは最後の Limit で上書きする)。
+ * 大文字小文字違いで残数が連続して減れば「同じ bucket を消費した」= 正規化が効いている。
+ */
+test('POST /forgot-password は大文字小文字違いの email で同じ bucket を消費する (正規化の証明)', function (): void {
+    $first = throttleProbePost('/forgot-password', ['email' => 'Probe.User@Example.COM']);
+    $second = throttleProbePost('/forgot-password', ['email' => 'probe.user@example.com']);
+
+    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
+        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
+        '大文字小文字違いで残数が戻った = 別 bucket に分かれている (throttle bypass)',
+    );
+});
+
+test('POST /forgot-password は同一 IP なら email を変えても IP レーンで止まる (メール爆撃の抑制)', function (): void {
+    // email レーン (10/60min) はそれぞれ余裕があるが、IP レーン (5/min) が先に尽きる
+    for ($i = 1; $i <= 5; $i++) {
+        $response = throttleProbePost('/forgot-password', ['email' => "probe{$i}@example.com"]);
+        expect($response->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost('/forgot-password', ['email' => 'probe6@example.com'])->getStatusCode())->toBe(429);
+});
+
+test('POST /reset-password も 6 回目で 429 (reset token 総当りの抑制)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        throttleProbePost('/reset-password', ['token' => 'invalid', 'email' => 'probe@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!']);
+    }
+
+    expect(throttleProbePost('/reset-password', ['token' => 'invalid', 'email' => 'probe@example.com'])->getStatusCode())->toBe(429);
+});
+
+test('POST /register も 6 回目で 429 (アカウント量産の抑制)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        throttleProbePost('/register', ['email' => "probe{$i}@example.com"]);
+    }
+
+    expect(throttleProbePost('/register', ['email' => 'probe6@example.com'])->getStatusCode())->toBe(429);
+});
+
+/*
+ * 異常入力の契約は 3 つに分ける。
+ * 極端に長い文字列も有効な string なので EmailHash が計算され、anon bucket とは別になる。
+ */
+test('login limiter は username が配列 / 空文字のとき anon fallback として同じ bucket を消費する', function (): void {
+    $payloads = [
+        ['email' => ['array-value'], 'password' => 'x'],
+        ['email' => '', 'password' => 'x'],
+        ['password' => 'x'],
+        ['email' => ['a'], 'password' => 'x'],
+        ['email' => '', 'password' => 'x'],
+    ];
+
+    foreach ($payloads as $payload) {
+        expect(throttleProbePost('/login', $payload)->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost('/login', ['email' => '', 'password' => 'x'])->getStatusCode())->toBe(429);
+});
+
+test('login limiter は極端に長い文字列でも 500 にならず、同一値の反復では同じ bucket を消費する', function (): void {
+    $long = str_repeat('a', 10000).'@example.com';
+
+    for ($i = 1; $i <= 5; $i++) {
+        $response = throttleProbePost('/login', ['email' => $long, 'password' => 'x']);
+        expect($response->getStatusCode())->toBeLessThan(500, '極端に長い入力で 500 になりました');
+        expect($response->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost('/login', ['email' => $long, 'password' => 'x'])->getStatusCode())->toBe(429);
+});
+
+test('password-reset-request は異なる異常文字列でも IP レーンを共有する', function (): void {
+    // IP 単独レーンは email に依存しない (IP-email レーンは値ごとに分かれるのが正しい挙動)
+    $weird = [['array'], '', str_repeat('z', 500), 12345, null];
+
+    foreach ($weird as $value) {
+        $response = throttleProbePost('/forgot-password', $value === null ? [] : ['email' => $value]);
+        expect($response->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost('/forgot-password', ['email' => 'probe@example.com'])->getStatusCode())->toBe(429);
+});
+
+/*
+ * Unicode で異なる 2 つの email が同じ bucket に落ちると、無関係アカウントが
+ * 巻き添えでロックアウトされる (Str::transliterate 廃止の回帰テスト)。
+ */
+test('login limiter は Unicode で異なる 2 つの email を同じ bucket に collapse させない', function (): void {
+    // transliterate はどちらも "cafe@example.com" へ潰す
+    $first = throttleProbePost('/login', ['email' => 'café@example.com', 'password' => 'x']);
+    $second = throttleProbePost('/login', ['email' => 'cafe@example.com', 'password' => 'x']);
+
+    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
+        (int) $first->headers->get('X-RateLimit-Remaining'),
+        'Unicode の異なる email が同じ bucket に collapse しています (巻き添えロックアウト)',
+    );
+});
+
+/** 解決後 middleware 列のクラス名リスト。 */
+function throttleProbeResolvedClasses(string $routeName): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName($routeName);
+    expect($route)->not->toBeNull("route [{$routeName}] が存在しない");
+
+    return array_map(
+        static fn (mixed $entry): string => is_string($entry) ? explode(':', $entry, 2)[0] : '(closure)',
+        $router->gatherRouteMiddleware($route),
+    );
+}
+
+test('POST /ses/notification は throttle が署名検証より先に走る (実効順の固定)', function (): void {
+    $resolved = throttleProbeResolvedClasses('webhooks.ses');
+
+    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
+    $signatureIndex = array_search(VerifySnsSignature::class, $resolved, true);
+
+    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
+    expect($signatureIndex)->not->toBeFalse('VerifySnsSignature が実効列に無い');
+    expect($throttleIndex)->toBeLessThan(
+        $signatureIndex,
+        '署名検証が throttle より先だと、署名検証コスト (証明書取得を伴う) が無制限に増幅する',
+    );
+});
+
+test('POST /ses/notification は不正 body でも上限を超えると 400 ではなく 429 になる', function (): void {
+    // 上限未満では VerifySnsSignature まで到達して 400 (envelope 不正)。
+    // 署名不正 (403) は証明書取得を伴うため対照には使わない (テストから外部通信を出さない)。
+    expect(throttleProbePost('/ses/notification')->getStatusCode())->toBe(400);
+
+    $status = 400;
+    // webhook-ses は 300/min。上限 + 1 まで叩くと throttle が先に短絡する
+    for ($i = 2; $i <= 301; $i++) {
+        $status = throttleProbePost('/ses/notification')->getStatusCode();
+        if ($status === 429) {
+            break;
+        }
+    }
+
+    expect($status)->toBe(429);
+})->group('slow');
+
+test('2FA 管理 route は throttle が recent-auth より先に走る', function (): void {
+    $resolved = throttleProbeResolvedClasses('two-factor.disable');
+
+    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
+    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);
+
+    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
+    expect($recentAuthIndex)->not->toBeFalse('RequireRecentAuth が実効列に無い');
+    expect($throttleIndex)->toBeLessThan($recentAuthIndex);
+});
diff --git a/tests/Feature/Security/RouteThrottleBinderTest.php b/tests/Feature/Security/RouteThrottleBinderTest.php
new file mode 100644
index 0000000..6086e6d
--- /dev/null
+++ b/tests/Feature/Security/RouteThrottleBinderTest.php
@@ -0,0 +1,196 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Http\RouteThrottleBinder;
+use Illuminate\Routing\Middleware\ThrottleRequests;
+use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * RouteThrottleBinder (vendor route への throttle 後付け) の契約テスト。
+ *
+ * 本 binder は「設定で貼れない vendor route」に throttle を付ける唯一の手段であり、
+ * 壊れ方が **無音の無防備** (route は生きているが制限が消える) になる。
+ * そのため fail-fast (route 名の消失 / 想定外 throttle / 不正形式) と
+ * 冪等性 (route:cache 下の再適用) の両方を機械固定する。
+ */
+
+/** テスト用の router。 */
+function binderRouter(): Router
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+
+    return $router;
+}
+
+/** throttle 実効 entry (`{class}:{params}`) を取り出す。 */
+function binderThrottleEntries(string $name): array
+{
+    $router = binderRouter();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName($name);
+    expect($route)->not->toBeNull("route [{$name}] が登録されていない");
+
+    return RouteThrottleBinder::throttleEntries($router, $route);
+}
+
+test('未登録の route 名を渡すと RuntimeException (メッセージに route 名を含む)', function (): void {
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.absent-route', 'api-read');
+})->throws(RuntimeException::class, 'binder.absent-route');
+
+test('throttle 無しの route に付与すると実効列に 1 本増える', function (): void {
+    Route::post('/binder-probe/plain', fn (): string => 'ok')->name('binder.plain');
+
+    expect(binderThrottleEntries('binder.plain'))->toBe([]);
+
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.plain', 'api-read');
+
+    $entries = binderThrottleEntries('binder.plain');
+    expect($entries)->toHaveCount(1);
+    expect($entries[0])->toBe(ThrottleRequests::class.':api-read');
+});
+
+test('同じ limiter で 2 回呼んでも実効列は 1 本のまま (route:cache 下の再適用が冪等)', function (): void {
+    Route::post('/binder-probe/idempotent', fn (): string => 'ok')->name('binder.idempotent');
+
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.idempotent', 'api-read');
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.idempotent', 'api-read');
+
+    expect(binderThrottleEntries('binder.idempotent'))->toHaveCount(1);
+});
+
+test('別 limiter が既にある route へ呼ぶと RuntimeException (二重付与で実効上限が半減するため)', function (): void {
+    Route::post('/binder-probe/other', fn (): string => 'ok')
+        ->middleware('throttle:api-write')
+        ->name('binder.other');
+
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.other', 'api-read');
+})->throws(RuntimeException::class, '想定外の throttle');
+
+test('params なしの throttle が既にある route へ呼ぶと RuntimeException', function (): void {
+    Route::post('/binder-probe/bare', fn (): string => 'ok')
+        ->middleware(ThrottleRequests::class)
+        ->name('binder.bare');
+
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.bare', 'api-read');
+})->throws(RuntimeException::class, '許容形式');
+
+test('既存 entry の params が不正形式の route へ呼ぶと RuntimeException', function (): void {
+    Route::post('/binder-probe/malformed', fn (): string => 'ok')
+        ->middleware('throttle:6,1,9')
+        ->name('binder.malformed');
+
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.malformed', 'api-read');
+})->throws(RuntimeException::class, '許容形式');
+
+/*
+ * 期待値の形式検証は **route 解決や既存 entry の有無より前**に行う。
+ * 後回しにすると「throttle が 1 本も無い route への初回呼び出しでは不正形式を素通しする」
+ * という非対称な穴になる (= 意図しない上限のまま公開される)。
+ */
+test('期待値が不正形式なら throttle が 1 本も無い route に対しても RuntimeException', function (string $limiter): void {
+    Route::post('/binder-probe/bad-expect', fn (): string => 'ok')->name('binder.bad-expect');
+
+    RouteThrottleBinder::attachByName(binderRouter(), 'binder.bad-expect', $limiter);
+})->with(['6,1,9', 'Foo Bar', '', 'API-Read'])->throws(RuntimeException::class, '許容形式');
+
+/*
+ * route:cache 起動時の契約。
+ *
+ * framework の RouteServiceProvider は `withRouting()` が booting callback で登録するため
+ * **最後に boot** され、compiled route の読み込みはさらにその中の `$app->booted()` へ積まれる。
+ * つまり本 binder の booted callback が走る時点では compiled route collection がまだ無く、
+ * named route を 1 本も解決できない。ここで fail-fast すると **cached 起動が必ず落ちる**ため、
+ * cache 済みなら skip する (付与自体は route:cache 生成時の再 bootstrap で焼き込み済み)。
+ */
+test('routes が cache 済みなら attachAll は何もしない (cached 起動を落とさない)', function (): void {
+    // 実在しない route 名を渡しても skip されるため例外にならない
+    RouteThrottleBinder::attachAll(binderRouter(), ['binder.absent-route' => 'api-read'], true);
+})->throwsNoExceptions();
+
+test('routes が cache されていなければ attachAll は route 不在で fail-fast する', function (): void {
+    RouteThrottleBinder::attachAll(binderRouter(), ['binder.absent-route' => 'api-read'], false);
+})->throws(RuntimeException::class, 'binder.absent-route');
+
+test('attachAll は cache されていなければ全 route へ付与する', function (): void {
+    Route::post('/binder-probe/all-a', fn (): string => 'ok')->name('binder.all-a');
+    Route::post('/binder-probe/all-b', fn (): string => 'ok')->name('binder.all-b');
+
+    RouteThrottleBinder::attachAll(
+        binderRouter(),
+        ['binder.all-a' => 'api-read', 'binder.all-b' => '6,1'],
+        false,
+    );
+
+    expect(binderThrottleEntries('binder.all-a'))->toBe([ThrottleRequests::class.':api-read']);
+    expect(binderThrottleEntries('binder.all-b'))->toBe([ThrottleRequests::class.':6,1']);
+});
+
+/*
+ * boot 中の後付けが **controller を container 解決しない**ことを固定する。
+ *
+ * `Route::gatherMiddleware()` は controller middleware を集めるために controller を
+ * 解決する。boot 中にこれが起きると、controller が constructor injection で要求する
+ * request scope の singleton (Fortify の ConfirmablePasswordController → StatefulGuard
+ * → session.store) が boot 時点で確定し、その後の設定変更・request 生成に追随しなくなる
+ * (実測で PasswordUpdateSessionInvalidationTest が壊れた)。
+ */
+test('boot 中の後付けは Fortify controller を解決していない', function (): void {
+    $routes = binderRouter()->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName('password.confirm.store');
+
+    expect($route)->not->toBeNull('前提: password.confirm.store は後付け対象として存在する');
+    expect($route->controller)->toBeNull(
+        'boot 中の後付けが controller を解決しています'
+        .' (StatefulGuard → session.store が boot 時点で確定し、request scope が壊れます)',
+    );
+});
+
+test('routeThrottleEntries は group 展開後の throttle を検出する (controller は解決しない)', function (): void {
+    Route::post('/binder-probe/grouped', fn (): string => 'ok')
+        ->middleware(['web', 'throttle:api-read'])
+        ->name('binder.grouped');
+
+    $router = binderRouter();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName('binder.grouped');
+
+    expect(RouteThrottleBinder::routeThrottleEntries($router, $route))
+        ->toBe([ThrottleRequests::class.':api-read']);
+});
+
+test('isThrottleEntry は throttle 系 middleware だけを true にする', function (): void {
+    expect(RouteThrottleBinder::isThrottleEntry(ThrottleRequests::class.':api-read'))->toBeTrue();
+    expect(RouteThrottleBinder::isThrottleEntry(ThrottleRequestsWithRedis::class.':api-read'))->toBeTrue();
+    expect(RouteThrottleBinder::isThrottleEntry(ThrottleRequests::class))->toBeTrue();
+    expect(RouteThrottleBinder::isThrottleEntry('Illuminate\Auth\Middleware\Authenticate:web'))->toBeFalse();
+    expect(RouteThrottleBinder::isThrottleEntry('throttle:api-read'))->toBeFalse();
+});
+
+/*
+ * ★memoization の破棄がなければ「middleware() には載っているのに実行されない throttle」になる。
+ *   Route::gatherMiddleware() は結果を memoize し、dispatch 時もその値が使われるため、
+ *   後付け前に実効列を覗いた時点で memo が温まっていると付与が無効化される (無音の無防備)。
+ */
+test('後付けした throttle は dispatch 経路の実効列にも反映される (memoization を破棄している)', function (): void {
+    Route::post('/binder-probe/memoized', fn (): string => 'ok')->name('binder.memoized');
+
+    $router = binderRouter();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName('binder.memoized');
+
+    // 先に実効列を確定させる (memo を温める) = 後付け前に覗く状況の再現
+    $router->gatherRouteMiddleware($route);
+
+    RouteThrottleBinder::attachByName($router, 'binder.memoized', 'api-read');
+
+    $resolved = $router->gatherRouteMiddleware($route);
+    expect($resolved)->toContain(ThrottleRequests::class.':api-read');
+});
diff --git a/tests/Feature/Security/ThrottleExemptionPremiseTest.php b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
new file mode 100644
index 0000000..4898952
--- /dev/null
+++ b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\LocalOnly;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Facades\Storage;
+
+/*
+ * ThrottleCoverageInventoryTest の exemption が依拠する**前提**の behavioral proof。
+ *
+ * exemption は「throttle を持たないことが**正しい**」という主張であり、
+ * その根拠 (署名で短絡する / 定数応答である / production には存在しない) が
+ * vendor 更新やリファクタで崩れたら検出できなければならない。
+ * 崩れたのに気づけない = 「対処済みに見える無防備」であり最悪の失敗モードになる。
+ */
+
+test('署名なしの PUT /storage/{path} は本体に到達しない (副作用ゼロで短絡する)', function (): void {
+    // storage.local.upload の exemption 根拠 = SignatureRequiredBeforeEffect
+    $disk = config('filesystems.default');
+    expect($disk)->toBeString();
+    Storage::fake($disk);
+
+    $response = $this->call('PUT', '/storage/probe.txt', content: 'payload');
+
+    // 非 production では 403 (production は 404)。いずれにせよ本体へ到達しない
+    expect($response->getStatusCode())->toBe(403);
+    Storage::disk($disk)->assertMissing('probe.txt');
+});
+
+test('GET /api/v1/mcp は定数 405 スタブ (Allow: POST) を返す', function (): void {
+    $response = $this->get('/api/v1/mcp');
+
+    expect($response->getStatusCode())->toBe(405);
+    expect($response->headers->get('Allow'))->toBe('POST');
+});
+
+test('DELETE /api/v1/mcp は定数 405 スタブ (Allow: POST) を返す', function (): void {
+    $response = $this->delete('/api/v1/mcp');
+
+    expect($response->getStatusCode())->toBe(405);
+    expect($response->headers->get('Allow'))->toBe('POST');
+});
+
+/** OAuth メタデータ route の URI 一覧 (定数応答であることの検証対象)。 */
+function throttlePremiseMetadataUris(): array
+{
+    return [
+        '/.well-known/oauth-authorization-server',
+        '/.well-known/oauth-authorization-server/mcp',
+        '/.well-known/oauth-protected-resource',
+        '/.well-known/oauth-protected-resource/mcp',
+    ];
+}
+
+test('.well-known/oauth-* の 4 route はいずれも DB クエリ 0 件で応答する', function (): void {
+    // StaticMetadataResponse の exemption 根拠 = 「DB アクセスを伴わない定数 JSON」
+    foreach (throttlePremiseMetadataUris() as $uri) {
+        $queries = [];
+        DB::listen(static function ($query) use (&$queries): void {
+            $queries[] = $query->sql;
+        });
+
+        $response = $this->getJson($uri);
+
+        expect($response->getStatusCode())->toBe(200, "{$uri} が 200 を返しません");
+        expect($queries)->toBe([], "{$uri} が DB クエリを発行しました: ".implode(' / ', $queries));
+    }
+});
+
+test('.well-known/oauth-*/{path} は path を変えても応答が変わらない (route parameter 非依存)', function (): void {
+    foreach ([
+        '/.well-known/oauth-authorization-server',
+        '/.well-known/oauth-protected-resource',
+    ] as $base) {
+        $first = $this->getJson($base.'/mcp');
+        $second = $this->getJson($base.'/some/other/path');
+
+        expect($second->getStatusCode())->toBe($first->getStatusCode());
+        expect(array_keys($second->json()))->toBe(
+            array_keys($first->json()),
+            "{$base} の応答 JSON キーが path に依存しています (定数メタデータではありません)",
+        );
+    }
+});
+
+test('debug.login-as は testing 環境では登録される (母集団に現れる前提の固定)', function (): void {
+    // LocalOnlyDebugRoute の exemption 根拠は「production では登録自体が起きない」であり、
+    // 「テストから見えない」ではない。testing で登録されること自体が前提の一部。
+    $routes = Route::getRoutes();
+    $routes->refreshNameLookups();
+
+    expect($routes->getByName('debug.login-as'))->not->toBeNull();
+});
+
+test('debug.login-as の登録は isLocal || runningUnitTests で囲われている (production 不在の根拠)', function (): void {
+    $source = file_get_contents(base_path('routes/web.php'));
+    expect($source)->toBeString();
+
+    // 登録条件そのものをソース上で固定する (条件が外れれば production にも生える)
+    expect($source)->toContain('if (app()->isLocal() || app()->runningUnitTests()) {');
+    expect($source)->toContain("->name('debug.login-as')");
+
+    // 二重防御 (LocalOnly middleware) が実効列に残っていること
+    $routes = Route::getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName('debug.login-as');
+    expect($route)->not->toBeNull();
+    expect($route->gatherMiddleware())->toContain(LocalOnly::class);
+});
diff --git a/tests/Support/RateLimiterRegistrationScanner.php b/tests/Support/RateLimiterRegistrationScanner.php
new file mode 100644
index 0000000..ce867eb
--- /dev/null
+++ b/tests/Support/RateLimiterRegistrationScanner.php
@@ -0,0 +1,388 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use FilesystemIterator;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use SplFileInfo;
+
+/**
+ * `RateLimiter::for(...)` 登録の token 走査器。
+ *
+ * ★正規表現を使わない理由 (deny-by-default では誤合格が最悪の失敗モード):
+ *   `RateLimiter::for(\n    'name',` のような改行入り呼び出しや
+ *   `RateLimiter::for(self::NAME, …)` のような非リテラル引数を取りこぼしつつ、
+ *   「検出した名前の集合が inventory と一致」は成功してしまう。
+ *   token 走査で**全呼び出しを数え、分類できなかったものを明示的に fail させる**。
+ *
+ * ★コメント / 文字列リテラル中の記述で誤検出しないよう、判定は token 列上で行う。
+ *
+ * ★受理する呼び出し元の書き方は 2 通りだけ (それ以外は unresolved = 明示 fail):
+ *   - `use Illuminate\Support\Facades\RateLimiter;` 済みの短縮名 `RateLimiter`
+ *   - 完全修飾 `\Illuminate\Support\Facades\RateLimiter`
+ *   名前空間内の非完全修飾 `Illuminate\Support\Facades\RateLimiter::for(...)` は
+ *   PHP の解決規則では `App\Foo\Illuminate\…` を意味し **Laravel の Facade ではない**ため
+ *   受理しない。alias 付き import (`use … as X;`) は、実際に `X::for()` の呼び出し元として
+ *   使われたときだけ unresolved にする (import しただけで未使用なら fail させない)。
+ *
+ * ★`RateLimiter::for` の `for` は PHP の予約語のため **T_FOR** としてトークン化される
+ *   (T_STRING ではない)。判定は token 型ではなく token のテキストで行う。
+ */
+final class RateLimiterRegistrationScanner
+{
+    /** 受理する Facade の完全修飾名。 */
+    private const FACADE = 'Illuminate\Support\Facades\RateLimiter';
+
+    /** Facade の短縮名 (import 済みのときだけ受理する)。 */
+    private const FACADE_SHORT_NAME = 'RateLimiter';
+
+    /**
+     * PHP ソース中の `RateLimiter::for(...)` 呼び出しを走査する。
+     *
+     * @return array{names: list<string>, unresolved: list<string>}
+     *                                                              names      = 第 1 引数がリテラル文字列だった limiter 名
+     *                                                              unresolved = 解析できなかった呼び出しの位置 (`{path}:{line}: {理由}`)
+     */
+    public static function scan(string $source, string $relativePath): array
+    {
+        $tokens = token_get_all($source);
+        [$importsFacade, $forbiddenAliases] = self::parseImports($tokens);
+
+        $names = [];
+        $unresolved = [];
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $callerStatus = self::callerStatus($tokens[$i], $importsFacade, $forbiddenAliases);
+            if ($callerStatus === null) {
+                continue;
+            }
+
+            $doubleColon = self::nextSignificant($tokens, $i + 1);
+            if ($doubleColon === null || ! self::isTokenType($tokens[$doubleColon], T_DOUBLE_COLON)) {
+                continue;
+            }
+
+            $method = self::nextSignificant($tokens, $doubleColon + 1);
+            if ($method === null || self::tokenText($tokens[$method]) !== 'for') {
+                continue;
+            }
+
+            $paren = self::nextSignificant($tokens, $method + 1);
+            if ($paren === null || self::tokenText($tokens[$paren]) !== '(') {
+                continue;
+            }
+
+            $line = self::tokenLine($tokens, $i);
+            $position = $relativePath.':'.$line;
+
+            if ($callerStatus !== 'allowed') {
+                $unresolved[] = $position.': 呼び出し元の書き方が規約外です ('.$callerStatus.')';
+
+                continue;
+            }
+
+            $argument = self::nextSignificant($tokens, $paren + 1);
+            $literal = $argument === null ? null : self::literalString($tokens[$argument]);
+            if ($literal === null) {
+                $unresolved[] = $position.': 第 1 引数がリテラル文字列ではありません';
+
+                continue;
+            }
+
+            $names[] = $literal;
+        }
+
+        return ['names' => $names, 'unresolved' => $unresolved];
+    }
+
+    /**
+     * ディレクトリ配下の *.php を再帰走査して集計する。
+     *
+     * @return array{names: list<string>, unresolved: list<string>}
+     */
+    public static function scanDirectory(string $absoluteDirectory, string $relativeRoot): array
+    {
+        $names = [];
+        $unresolved = [];
+
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS),
+        );
+
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if (! $file->isFile() || $file->getExtension() !== 'php') {
+                continue;
+            }
+
+            $source = file_get_contents($file->getPathname());
+            if ($source === false) {
+                $unresolved[] = $file->getPathname().':0: ファイルを読み取れません';
+
+                continue;
+            }
+
+            $relative = $relativeRoot.'/'.ltrim(
+                str_replace($absoluteDirectory, '', $file->getPathname()),
+                DIRECTORY_SEPARATOR,
+            );
+
+            $result = self::scan($source, $relative);
+            $names = array_merge($names, $result['names']);
+            $unresolved = array_merge($unresolved, $result['unresolved']);
+        }
+
+        sort($names);
+        sort($unresolved);
+
+        return ['names' => $names, 'unresolved' => $unresolved];
+    }
+
+    /**
+     * トップレベルの名前空間 import を解析する。
+     *
+     * `T_USE` は 3 用途 (名前空間 import / クロージャの lexical use / trait use) あるため、
+     * **波括弧の深さ 0** かつ **直後が `(` でない** ものだけを名前空間 import とみなす。
+     *
+     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
+     * @return array{0: bool, 1: array<string, true>} [Facade を素で import 済みか, 禁止 alias 集合]
+     */
+    private static function parseImports(array $tokens): array
+    {
+        $count = count($tokens);
+        $depth = 0;
+        $importsFacade = false;
+        /** @var array<string, true> $forbiddenAliases */
+        $forbiddenAliases = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+
+            if (is_array($token)) {
+                // 文字列内の `{$var}` / `${var}` も対応する `}` は生トークンのため深さに数える
+                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
+                    $depth++;
+                }
+                if ($token[0] !== T_USE || $depth !== 0) {
+                    continue;
+                }
+
+                $parsed = self::parseUseStatement($tokens, $i);
+                if ($parsed === null) {
+                    continue;
+                }
+                if ($parsed['alias'] === null) {
+                    $importsFacade = true;
+                } else {
+                    $forbiddenAliases[$parsed['alias']] = true;
+                }
+
+                continue;
+            }
+
+            if ($token === '{') {
+                $depth++;
+            } elseif ($token === '}') {
+                $depth--;
+            }
+        }
+
+        return [$importsFacade, $forbiddenAliases];
+    }
+
+    /**
+     * `use` トークン位置から RateLimiter Facade の import かを判定する。
+     *
+     * group use (`use Illuminate\Support\Facades\{RateLimiter, Auth};`) は受理しない
+     * (deny-by-default。その場合 `RateLimiter::for` は unresolved になる)。
+     *
+     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
+     * @return array{alias: string|null}|null Facade の import でなければ null
+     */
+    private static function parseUseStatement(array $tokens, int $useIndex): ?array
+    {
+        $count = count($tokens);
+        $i = self::nextSignificant($tokens, $useIndex + 1);
+        if ($i === null) {
+            return null;
+        }
+
+        // クロージャの lexical use (`function ($x) use ($y) {}`) / `use function` / `use const`
+        if (self::tokenText($tokens[$i]) === '('
+            || self::isTokenType($tokens[$i], T_FUNCTION)
+            || self::isTokenType($tokens[$i], T_CONST)) {
+            return null;
+        }
+
+        $name = '';
+        for (; $i < $count; $i++) {
+            $token = $tokens[$i];
+
+            if (is_array($token)) {
+                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+                    continue;
+                }
+                if (in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+                    $name .= $token[1];
+
+                    continue;
+                }
+                if ($token[0] === T_AS) {
+                    break;
+                }
+
+                return null;
+            }
+
+            if ($token === '\\') {
+                $name .= '\\';
+
+                continue;
+            }
+
+            if ($token === ';') {
+                return ltrim($name, '\\') === self::FACADE ? ['alias' => null] : null;
+            }
+
+            // group use の `{` や複数 import の `,` は受理しない
+            return null;
+        }
+
+        if (ltrim($name, '\\') !== self::FACADE) {
+            return null;
+        }
+
+        // `as` の直後が alias 名
+        $aliasIndex = self::nextSignificant($tokens, $i + 1);
+        if ($aliasIndex === null || ! self::isTokenType($tokens[$aliasIndex], T_STRING)) {
+            return null;
+        }
+
+        return ['alias' => self::tokenText($tokens[$aliasIndex])];
+    }
+
+    /**
+     * token が `::for(` の呼び出し元候補かを判定する。
+     *
+     * @param  string|array{0: int, 1: string, 2: int}  $token
+     * @param  array<string, true>  $forbiddenAliases
+     * @return string|null 'allowed' / 理由文字列 (候補だが規約外) / null (候補ですらない)
+     */
+    private static function callerStatus(string|array $token, bool $importsFacade, array $forbiddenAliases): ?string
+    {
+        if (! is_array($token)) {
+            return null;
+        }
+
+        $text = $token[1];
+
+        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($text, '\\') === self::FACADE ? 'allowed' : null;
+        }
+
+        if ($token[0] === T_NAME_QUALIFIED) {
+            // PHP の解決規則では現在の名前空間からの相対解決 = Facade ではない
+            return self::lastSegment($text) === self::FACADE_SHORT_NAME
+                ? '名前空間内の非完全修飾名は Facade を指しません: '.$text
+                : null;
+        }
+
+        if ($token[0] !== T_STRING) {
+            return null;
+        }
+
+        if ($text === self::FACADE_SHORT_NAME) {
+            return $importsFacade
+                ? 'allowed'
+                : 'use '.self::FACADE.'; の import がありません (同名の別クラスの可能性)';
+        }
+
+        if (isset($forbiddenAliases[$text])) {
+            return 'alias 経由の呼び出しです: '.$text;
+        }
+
+        return null;
+    }
+
+    /** 名前空間区切りの最終セグメント。 */
+    private static function lastSegment(string $name): string
+    {
+        $parts = explode('\\', $name);
+
+        return end($parts) ?: $name;
+    }
+
+    /**
+     * 変数展開を含まない文字列リテラルを素の値へ復元する (できなければ null)。
+     *
+     * エスケープ解釈は行わず、**エスケープを含むリテラルは受理しない**
+     * (limiter 名にエスケープが要る事態は規約違反であり、素通しさせない)。
+     *
+     * @param  string|array{0: int, 1: string, 2: int}  $token
+     */
+    private static function literalString(string|array $token): ?string
+    {
+        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+            return null;
+        }
+
+        $raw = $token[1];
+        if (preg_match("/^'[^'\\\\]*'$/", $raw) === 1 || preg_match('/^"[^"\\\\$]*"$/', $raw) === 1) {
+            return substr($raw, 1, -1);
+        }
+
+        return null;
+    }
+
+    /**
+     * 空白・コメントを読み飛ばした次の token 位置。
+     *
+     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
+     */
+    private static function nextSignificant(array $tokens, int $from): ?int
+    {
+        $count = count($tokens);
+        for ($i = $from; $i < $count; $i++) {
+            $token = $tokens[$i];
+            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+                continue;
+            }
+
+            return $i;
+        }
+
+        return null;
+    }
+
+    /** @param  string|array{0: int, 1: string, 2: int}  $token */
+    private static function tokenText(string|array $token): string
+    {
+        return is_array($token) ? $token[1] : $token;
+    }
+
+    /** @param  string|array{0: int, 1: string, 2: int}  $token */
+    private static function isTokenType(string|array $token, int $type): bool
+    {
+        return is_array($token) && $token[0] === $type;
+    }
+
+    /**
+     * token の行番号 (生トークンには行情報が無いため直前の配列トークンから引く)。
+     *
+     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
+     */
+    private static function tokenLine(array $tokens, int $index): int
+    {
+        for ($i = $index; $i >= 0; $i--) {
+            if (is_array($tokens[$i])) {
+                return $tokens[$i][2];
+            }
+        }
+
+        return 0;
+    }
+}
diff --git a/tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php b/tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php
new file mode 100644
index 0000000..e9ea30d
--- /dev/null
+++ b/tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php
@@ -0,0 +1,145 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\RateLimiterRegistrationScanner;
+
+/*
+ * RateLimiterRegistrationScanner の positive/negative 固定。
+ *
+ * 走査器そのものが deny-by-default 検査 (RateLimiterKeyConventionTest) の土台であり、
+ * 「検出漏れ = 偽グリーン」になるため、解析器の挙動をここで恒久固定する
+ * (AuthorizationMarkerScannerTest と同じ責務設計)。
+ */
+
+/** @return array{names: list<string>, unresolved: list<string>} */
+function scanRateLimiterSource(string $body, string $prelude = "use Illuminate\\Support\\Facades\\RateLimiter;\n"): array
+{
+    return RateLimiterRegistrationScanner::scan("<?php\n\n".$prelude."\n".$body, 'fake.php');
+}
+
+test('use 済み短縮名の単純な呼び出しを検出する', function (): void {
+    $result = scanRateLimiterSource("RateLimiter::for('login', fn () => 1);");
+
+    expect($result['names'])->toBe(['login']);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('改行・空白を挟んだ呼び出しを検出する', function (): void {
+    $result = scanRateLimiterSource("RateLimiter::for(\n    'login',\n    fn () => 1,\n);");
+
+    expect($result['names'])->toBe(['login']);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('完全修飾名の呼び出しを検出する', function (): void {
+    $result = scanRateLimiterSource("\\Illuminate\\Support\\Facades\\RateLimiter::for('x', fn () => 1);", '');
+
+    expect($result['names'])->toBe(['x']);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('名前空間内の非完全修飾名は unresolved に入る (PHP の解決規則では別クラス)', function (): void {
+    $result = scanRateLimiterSource("Illuminate\\Support\\Facades\\RateLimiter::for('x', fn () => 1);", '');
+
+    expect($result['names'])->toBe([]);
+    expect($result['unresolved'])->toHaveCount(1);
+    expect($result['unresolved'][0])->toContain('非完全修飾');
+});
+
+test('alias 経由の呼び出しは unresolved に入る', function (): void {
+    $result = scanRateLimiterSource(
+        "Limiter::for('x', fn () => 1);",
+        "use Illuminate\\Support\\Facades\\RateLimiter as Limiter;\n",
+    );
+
+    expect($result['names'])->toBe([]);
+    expect($result['unresolved'])->toHaveCount(1);
+    expect($result['unresolved'][0])->toContain('alias');
+});
+
+test('alias を import しただけで未使用なら fail させない', function (): void {
+    $result = scanRateLimiterSource(
+        "RateLimiter::for('x', fn () => 1);",
+        "use Illuminate\\Support\\Facades\\RateLimiter;\nuse Some\\Other\\RateLimiter as Limiter;\n",
+    );
+
+    expect($result['names'])->toBe(['x']);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('非リテラルな第 1 引数は unresolved に入る', function (): void {
+    $result = scanRateLimiterSource(
+        "RateLimiter::for(\$name, fn () => 1);\nRateLimiter::for(self::NAME, fn () => 1);",
+    );
+
+    expect($result['names'])->toBe([]);
+    expect($result['unresolved'])->toHaveCount(2);
+});
+
+test('コメント / 文字列リテラル中の記述は検出しない', function (): void {
+    $result = scanRateLimiterSource(
+        "// RateLimiter::for('fake')\n/* RateLimiter::for('fake2') */\n\$s = \"RateLimiter::for('fake3')\";",
+    );
+
+    expect($result['names'])->toBe([]);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('別クラスの ::for は検出しない', function (): void {
+    $result = scanRateLimiterSource("OtherClass::for('x', fn () => 1);");
+
+    expect($result['names'])->toBe([]);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('import の無い裸の RateLimiter::for は unresolved に入る', function (): void {
+    $result = scanRateLimiterSource("RateLimiter::for('x', fn () => 1);", '');
+
+    expect($result['names'])->toBe([]);
+    expect($result['unresolved'])->toHaveCount(1);
+    expect($result['unresolved'][0])->toContain('import');
+});
+
+test('group use は受理しない (RateLimiter::for が unresolved になる)', function (): void {
+    $result = scanRateLimiterSource(
+        "RateLimiter::for('x', fn () => 1);",
+        "use Illuminate\\Support\\Facades\\{RateLimiter, Auth};\n",
+    );
+
+    expect($result['names'])->toBe([]);
+    expect($result['unresolved'])->toHaveCount(1);
+});
+
+test('クロージャの lexical use / trait use を名前空間 import と誤認しない', function (): void {
+    $source = <<<'PHP'
+<?php
+
+use Illuminate\Support\Facades\RateLimiter;
+
+class Foo
+{
+    use SomeTrait;
+
+    public function bar(string $lane): void
+    {
+        $fn = function () use ($lane) {
+            return $lane;
+        };
+
+        RateLimiter::for('login', $fn);
+    }
+}
+PHP;
+
+    $result = RateLimiterRegistrationScanner::scan($source, 'fake.php');
+
+    expect($result['names'])->toBe(['login']);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('unresolved の位置情報にはファイルパスと行番号が入る', function (): void {
+    $result = scanRateLimiterSource("\n\nRateLimiter::for(\$name, fn () => 1);");
+
+    expect($result['unresolved'][0])->toStartWith('fake.php:');
+});

---

## テスト結果
composer test: 3308 tests, 3306 passed, 0 failed, 2 skipped (12663 assertions)
composer phpstan: level 10 No errors
vendor/bin/pint --test: passed
pnpm lint / pnpm typecheck: passed (フロント差分なし)
php artisan route:cache → route:list → route:clear: 例外なく完了 (throttle 43 route が cache へ焼き込み済み)

## 実装中に判明した設計との差分 (レビュー対象)
1. 設計は「route:cache 起動でも booted callback が走り、既存 throttle 1 本なら冪等 no-op」
   と想定していたが、実測では **compiled route collection がまだ読まれていない**
   (framework の RouteServiceProvider は withRouting() の booting callback 登録のため最後に
   boot され、loadCachedRoutes() はさらにその中の $app->booted() に積まれる)。
   そのため cached 起動では named route が 1 本も解決できず fail-fast が必ず発火した。
   → RouteThrottleBinder::attachOnBooted() で routesAreCached() 時は skip する形にした。
2. Route::gatherMiddleware() は controller middleware 収集のため **controller を container 解決**する。
   boot 中にこれを呼ぶと Fortify の ConfirmablePasswordController が StatefulGuard →
   session.store を boot 時点で確定させ、既存テスト
   (PasswordUpdateSessionInvalidationTest の recaller 失効ケース) が壊れた。
   → binder 側は routeThrottleEntries() (resolveMiddleware ベース、controller 非解決) を使い、
   目録検査だけが完全な gatherRouteMiddleware() を使う 2 段構成にした。
3. Route::gatherMiddleware() の memoization ($computedMiddleware) を破棄しないと
   「middleware() には載っているのに dispatch では実行されない throttle」になる。
   → 付与後に $route->computedMiddleware = null; を明示。
4. 設計の scanner 仕様は `T_STRING('for')` を想定していたが、実測では `::for` は **T_FOR**。
   → token 型ではなくテキストで判定。
