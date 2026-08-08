【アプリの使命 (North Star) — AGENTS.md より】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。



【禁止事項 — AGENTS.md より】
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

# system: 実装レビュアー (Round 2)

あなたは Laravel 12 (PHP 8.4) + Svelte 5 アプリ **aicue** のコードレビュアーである。
以下の詳細設計書 (Codex 合議で APPROVED 済み) に対する実装差分をレビューせよ。

**重要**: これは Round 2 だが、Round 1 のセッションが失効したため one-shot で再依頼している。
Round 1 の指摘全文と、それに対する実装側の対応マトリクスを下に添付する。
文脈は添付テキストのみから読み取ること (前回の記憶は無いものとして扱ってよい)。

## レビュー観点

1. **設計との一致性** — 設計から外れている箇所と、その逸脱が妥当か
2. **正確性** — 特に **docblock / ドキュメントの主張が実際の機序と一致しているか**。
   本 TODO の中心は「誤った機序の記述が次の担当を誤らせ、運用要件を隠していた」ことの是正であり、
   **不正確な記述が残っていること自体が本 TODO の失敗**である。断定の強さが実態を超えていないか
   (誇張していないか)、逆に弱すぎて運用要件が伝わらなくなっていないかを見よ。
3. **PHPStan level 10 適合性** (ignore / baseline / widen は禁止)
4. **テスト網羅性** — 新設テストが空振り green になっていないか。negative control が効いているか
5. **セキュリティ** — 付与内容・付与順序が 1 つも変わっていないか (本 TODO は振る舞い不変が完了条件)
6. **T120 の再発防止** — cached 起動で例外を投げる枝に到達しない設計が崩れていないか

## 特に確認してほしいこと (Round 2)

- Round 1 の 4 Warning + 1 Suggestion への対応が、**言い換えただけでなく実態と一致**しているか。
  過剰修正 (今度は逆方向に不正確) になっていないか。
- Round 1 で指摘されなかった箇所に、同種の不正確な記述が残っていないか。

## 出力形式

ファイルごとに **APPROVED / REQUEST_CHANGES** を判定し、指摘は
**[Critical] / [Warning] / [Suggestion]** で分類すること。
最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書くこと。

---

## 詳細設計書 (APPROVED 済み)

# 詳細設計: route-cache-attach-contract

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成（本設計では DB を触らないため該当なし）
- **DTO + JsonResource** パターン（本設計では HTTP 応答を作らないため該当なし）
- `declare(strict_types=1)` + 日本語コメント / アーリーリターン推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

- `devnotes/20260808-0130-route-cache-attach-contract/conceptual-design.md`（APPROVED / Round 1）
- 一次入力: `devnotes/20260808-0130-route-cache-attach-contract/recon-brief.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `RouteMiddlewareBinder` の新設（skip 判定を引数で受ける純粋関数 + booted 配線） | `app/Support/Http/RouteMiddlewareBinder.php`（新規） | 高 |
| 2 | `FortifyServiceProvider` の後付けを binder 経由へ + docblock 是正 | `app/Providers/FortifyServiceProvider.php` | 高 |
| 3 | `PasskeyServiceProvider` の後付けを binder 経由へ + docblock 是正（`Route::bind` の区別を明記） | `app/Providers/PasskeyServiceProvider.php` | 高 |
| 4 | binder の契約テスト（T120 恒久回帰を含む） | `tests/Feature/Security/RouteMiddlewareBinderTest.php`（新規） | 高 |
| 5 | 後付け経路の deny-by-default 目録 | `tests/Architecture/PostBootRouteMutationInventoryTest.php`（新規） | 中 |
| 6 | 運用契約の格上げ（§7c 新設 / §7b から参照） | `docs/app-integration-guide.md` | 中 |
| 7 | AGENTS.md に運用要件ブロック（デプロイ基盤未整備の明示を含む） | `AGENTS.md` | 中 |

**変更しないもの**（明示）:

- `app/Support/Http/RouteThrottleBinder.php` — 契約記述は家系で唯一正しい。統合も一般化もしない。
- `tests/Architecture/{ThrottleCoverageInventoryTest,ThrottleLaneAssignmentTest,InlineThrottleInventoryTest,RecentAuthRouteTest,TwoFactorStepUpInventoryTest,PasskeyRouteProtectionTest}.php`
  および `tests/Feature/Security/RouteThrottleBinderTest.php` — **1 行も変更しない**。
  これらが差分なしで green であることが「付与内容が変わっていない = 振る舞い不変」の直接の証拠になる。
- `app/Providers/AppServiceProvider.php` — throttle 後付けのみで、記述も既に正しい。§7c 参照の
  1 行追記は**行わない**（正しい記述を触らない。参照は `RouteThrottleBinder` の docblock が既に担う）。

---

## 施策 1: `RouteMiddlewareBinder` の新設

### 変更箇所

- ファイル: `app/Support/Http/RouteMiddlewareBinder.php`（新規）

### 波及変更

- TypeScript 型定義: なし（フロント差分ゼロ）
- API Resource/DTO: なし
- テストファイル: 施策 4（新規）/ 施策 5（新規）。既存テストの変更は**なし**

### 現行コード

該当なし（新規）。同等機能は `FortifyServiceProvider` と `PasskeyServiceProvider` に
private static `appendMiddlewareIfMissing()` として**重複実装**されている（下記 施策 2 / 3 参照）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use RuntimeException;

/**
 * vendor が登録した named route へ **middleware alias** を後付けする binder。
 *
 * ★責務境界: throttle の後付けは {@see RouteThrottleBinder} が担当する（統合しない）。
 *   あちらは limiter の形式検証・二重付与検出という固有責務を持ち、ここは
 *   「alias を冪等に足す」だけである。両者は route:cache との契約だけを共有する。
 *
 * ★**2 つの事象を混ぜないこと**（ここが本 binder の存在理由）:
 *
 *   1. **生成時**（`php artisan route:cache` の実行中）
 *      `RouteCacheCommand::handle()` は先頭で `route:clear` してから
 *      `getFreshApplicationRoutes()` で **cache 無しのアプリを再 bootstrap** する。
 *      そこでは `loadRoutesFrom()` が `require` を通すため本後付けが**完全に走り**、
 *      付与済み middleware がそのまま cache へ**焼き込まれる**。
 *      route 名が消えていれば**ここでデプロイが止まる**（fail-fast が効くのはここ）。
 *
 *   2. **起動時**（route cache がある状態でのリクエスト処理 / artisan）
 *      本後付けは **1 本も効かない**。理由は 2 つあり、片方だけでも成立する:
 *        (a) `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を
 *            飛ばす。Fortify / laravel-passkeys はこれを使うため、**この callback が走る
 *            時点では対象 named route が 1 本も登録されていない**
 *            （compiled routes は後で読まれるので「route が永久に存在しない」の意味ではない。
 *            ここを誤読すると次の担当がまた別の誤った結論に着く）。
 *        (b) 仮に触れていても、framework の `RouteServiceProvider` が本 callback より**後**の
 *            app-booted で compiled routes を読み、`Router::setCompiledRoutes()` が
 *            route collection を**新品へ丸ごと差し替える**ため捨てられる。
 *      よって cached 起動では `$routesAreCached` を見て**明示 skip** する。
 *      **ここで例外を投げてはならない**（`route:list` が必ず落ちる = T120 の事故）。
 *
 *   ⇒ **cached 起動での保護を持っているのは cache の中身である**。したがって
 *     **`php artisan route:cache` を毎デプロイ再生成することが本機構の前提条件**になる。
 *     stale な route cache は古い付与状態のまま起動し、**無音で保護が外れる**
 *     （実測: 剥がした cache では 2FA 秘密 GET が 409 でなく 200 を返す）。
 *     運用契約の正本は `docs/app-integration-guide.md` §7c。
 *
 * ★よくある誤読の否定（この記述を消さないこと）:
 *   `CompiledRouteCollection::getByName()` が Route instance を `nameCache` へ memoize し、
 *   `match()` がその `getByName()` を通るのは**事実**である。しかしそれは
 *   「**compiled collection が読まれた後に** getByName して書き換えた場合」の話であり、
 *   本 callback はその前に走って**別の collection** を見ているため前提が成立しない。
 *   「nameCache があるから cached 起動でも後付けが効く」とは書かない。
 */
final class RouteMiddlewareBinder
{
    /**
     * 起動完了後に named route 群へ middleware alias を後付けする（登録の唯一の入口）。
     *
     * ★spec を **resolver（callable）で受け、ここでは呼ばない**。理由は 2 つ:
     *   1. 呼び出し側の feature flag 判定を `boot()` へ前倒ししないため。現行 2 経路は
     *      判定タイミングが異なる（Fortify = boot 内 / Passkey = booted 内）ので、
     *      resolver を booted の中で解決することで**どちらのタイミングも変えない**。
     *   2. **cached 起動では resolver 自体を実行しない**ため。resolver をここで呼んで
     *      配列にしてから渡す形にすると、将来 resolver が route collection を覗く実装に
     *      なったとき early return の**前**に落ちる = T120 の再導入になる。
     *      ★ただし「型で不可能」なわけではない（`$specs = $specResolver();` してから
     *      `static fn () => $specs` を渡す退行は型検査を通る）。**誇張しない**。
     *      この配線が前倒し評価へ退行していないことは
     *      `RouteMiddlewareBinderTest` の配線テスト（`routes.cached` を true に束ねて
     *      「呼ばれたら throw する resolver」を渡す）が**振る舞いで**固定する。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     *         route 名 => 付与する middleware alias の列
     */
    public static function attachOnBooted(Application $app, callable $specResolver): void
    {
        $app->booted(static function (Application $app) use ($specResolver): void {
            self::attachAll(
                $app->make(Router::class),
                $specResolver,
                $app instanceof CachesRoutes && $app->routesAreCached(),
            );
        });
    }

    /**
     * named route 群へ middleware alias を後付けする（`$routesAreCached` なら何もしない）。
     *
     * skip 判定を**引数で受ける**ことで、判定と後付けの両方を純粋関数として検証できる
     * （{@see attachOnBooted} が実アプリの状態を渡す唯一の配線点）。
     *
     * ★`RouteThrottleBinder::attachAll()` は spec を **array** で受けるが、こちらは
     *   **resolver** で受ける。揃っていないのは意図である — あちらの spec は副作用の無い
     *   定数表で cached 起動でも評価してよいが、こちらは feature flag 判定を含み、
     *   「cached では resolver すら呼ばない」ことを保証したいため。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     */
    public static function attachAll(Router $router, callable $specResolver, bool $routesAreCached): void
    {
        if ($routesAreCached) {
            // 後付けは route:cache 生成時に焼き込み済み。
            // ★**resolver を呼ぶ前に**返すこと。route 解決はもちろん spec の構築にも
            //   到達させない（到達させると T120 型の事故を再導入する余地が残る）。
            return;
        }

        $routes = $router->getRoutes();
        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
        $routes->refreshNameLookups();

        foreach ($specResolver() as $name => $aliases) {
            self::attachByName($routes, $name, $aliases);
        }
    }

    /**
     * named route へ middleware alias 群を冪等に後付けする。
     *
     * @param  list<string>  $aliases
     *
     * @throws RuntimeException route が引けない場合（= 無防備なまま公開される事故を止める）
     */
    public static function attachByName(RouteCollectionInterface $routes, string $routeName, array $aliases): void
    {
        $route = $routes->getByName($routeName);
        if (! $route instanceof Route) {
            throw new RuntimeException(
                "middleware を後付けすべき route [{$routeName}] が見つかりません。"
                .'vendor package が update で route 名を変えたか、feature flag が無効化された'
                .'可能性があります（無効化が正しいなら呼び出し側の spec から外すこと）。'
                .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
            );
        }

        $changed = false;
        foreach ($aliases as $alias) {
            if (in_array($alias, $route->middleware(), true)) {
                continue; // 冪等（同一 bootstrap 内の重複呼び出し / 既に定義側で貼られている）
            }

            $route->middleware($alias);
            $changed = true;
        }

        if ($changed) {
            // ★memo の破棄（RouteThrottleBinder と同じ作法）。
            //   **本経路では現時点 no-op である**ことを実読で確認している
            //   （ここは gatherMiddleware() を呼ばないため $computedMiddleware は温まらない）。
            //   それでも置くのは、この memo を取りこぼしたときの壊れ方が
            //   「middleware() には載るのに dispatch では実行されない = 無音の無防備」であり、
            //   本 binder が潰そうとしている失敗形そのものだから。
            $route->computedMiddleware = null;
        }
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（すべて `void`）
- [x] null 安全: `getByName()` の戻りを `instanceof Route` で絞ってから使う
      （`RouteThrottleBinder::attachByName()` と同じ形）
- [x] DTO を返している: 該当なし（値を返さない）
- [x] Generics の型パラメータ: `array<string, list<string>>` /
      `callable(): array<string, list<string>>` を phpdoc で明示。
      `Route::$computedMiddleware` は framework の public property（`RouteThrottleBinder`
      が既に同じ代入を level 10 で通している）

### テスト計画

施策 4 で全面カバー（下記）。

### リスク

- **唯一の振る舞い変化**: 非 cached 起動で spec の route が引けないとき、
  無音 no-op → `RuntimeException`。呼び出し側が feature flag を正しく反映していないと
  起動しなくなる。→ 施策 2 / 3 で feature 条件を明示的に持たせ、施策 4 のテストで固定する。
- cached 起動では skip するため、T120 の事故（`route:list` が落ちる）は起こらない。
  ただし「構造的に不可能」とは書かない（配線側で resolver を前倒し評価する退行は
  型検査では止まらない）。**純粋関数の契約（#1）と配線の振る舞い（#8 / #8b）を
  2 本で固定する**のが本設計の担保である。
- **`attachOnBooted()` に skip 判定を二重に置くことはしない**。skip の決定点を
  `attachAll()` の 1 箇所に保つ（二重化すると配線側の flag が実質デッドになり、
  「どちらが正なのか」が次の担当に読めなくなる）。退行の検出は #8 / #8b が担う。

---

## 施策 2: `FortifyServiceProvider` の後付けを binder 経由へ

### 変更箇所

- ファイル: `app/Providers/FortifyServiceProvider.php`
  （L225-266 = `attachRecentAuthToSensitiveRoutes()` と `appendMiddlewareIfMissing()`）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 既存の `RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` /
  `PasswordConfirmMiddlewareAbsenceTest` は**変更しない**（差分なしで green であることが回帰）

### 現行コード

```php
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
```

> `L232-234` の「route:cache 下でも … dispatch にも有効」が**是正対象の誤り**。
> `nameCache` の性質の記述自体は正しいが、この callback は compiled collection に到達しない。

### 変更後コード

```php
    /**
     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
     *
     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決して append する。
     *
     * ★route:cache との契約（**cached 起動では 1 本も効かない**）と、
     *   「毎デプロイ `php artisan route:cache` を再生成する」という前提条件は
     *   {@see \App\Support\Http\RouteMiddlewareBinder} の docblock が正本。
     *   **旧記述の訂正**: かつてここには「route:cache 下でも nameCache が同一 instance を
     *   返すため dispatch にも有効」と書いてあったが、**誤り**である。cached 起動では
     *   Fortify の loadRoutesFrom() が require を飛ばして対象 route を登録しないため、
     *   この callback は compiled collection に到達しない。実効しているのは
     *   route:cache **生成時**の焼き込みである。
     *
     * ★feature flag の扱い: 対象 route は Fortify の機能フラグで登録有無が決まるため、
     *   有効な機能の分だけを spec に載せる（無効な機能の route まで要求すると
     *   binder が fail-fast して起動できなくなる）。skip が穴にならない根拠は
     *   throttledFortifyRoutes() の docblock と同じ = 目録検査（RecentAuthRouteTest /
     *   TwoFactorStepUpInventoryTest）が二重の網になる。
     */
    private function attachRecentAuthToSensitiveRoutes(): void
    {
        // first-class callable で渡す（`static fn (): array => …` にすると
        // 匿名関数の戻り値に iterable value type が付かず PHPStan level 10 で詰まる。
        // メソッド参照なら recentAuthRouteSpecs() の @return がそのまま効く）
        RouteMiddlewareBinder::attachOnBooted($this->app, self::recentAuthRouteSpecs(...));
    }

    /**
     * recent-auth 系 middleware を後付けする Fortify route の spec。
     *
     * @return array<string, list<string>> route 名 => middleware alias の列
     */
    private static function recentAuthRouteSpecs(): array
    {
        $specs = [];

        if (Features::enabled(Features::twoFactorAuthentication())) {
            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                $specs[$name] = ['recent-auth'];
            }
        }

        if (Features::enabled(Features::updateProfileInformation())) {
            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
                $specs[$name] = [$alias];
            }
        }

        return $specs;
    }
```

**use 文の増減（明示）**:

- **追加**: `use App\Support\Http\RouteMiddlewareBinder;`
- **削除候補**（他で使われていなければ削除。`composer fix` / PHPStan で確認）:
  `Illuminate\Routing\RouteCollectionInterface` / `Illuminate\Routing\Router` /
  `Illuminate\Contracts\Foundation\Application`
- `Laravel\Fortify\Features` は既に import 済み（`throttledFortifyRoutes()` が使用）
- `appendMiddlewareIfMissing()` は**削除**する（思考原則 3: 後方互換の並走を残さない）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`void` / `array<string, list<string>>`）
- [x] null 安全: binder 側で `instanceof Route` 判定。provider 側に null は現れない
- [x] DTO: 該当なし
- [x] Generics: spec の array shape を phpdoc で明示

### テスト計画

- [x] 既存 `tests/Architecture/RecentAuthRouteTest.php` が**変更なしで** green
      （`two-factor.*` 6 本の `recent-auth` 付与が保たれる）
- [x] 既存 `tests/Architecture/TwoFactorStepUpInventoryTest.php` が**変更なしで** green
- [x] 既存 `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` が**変更なしで** green
- [x] 個別の `DatabaseTransactions` を使っていない（新規テストは施策 4 / 5 のみ）

### リスク

- feature flag の対応付けを誤ると起動しなくなる。
  `RECENT_AUTH_ROUTE_NAMES` = `Features::twoFactorAuthentication()`、
  `CONDITIONAL_RECENT_AUTH_ROUTES`（`user-profile-information.update`）=
  `Features::updateProfileInformation()` であることを
  `vendor/laravel/fortify/routes/routes.php` の分岐（L104 / L133）で確認済み。
- `attachRecentAuthToSensitiveRoutes()` は `boot()` 内で
  `attachThrottleToFortifyRoutes()` **より先**に呼ばれる = booted callback の発火順も先。
  両者が同じ route に触れても互いに独立（alias 追加と throttle 追加）で、順序依存はない。

---

## 施策 3: `PasskeyServiceProvider` の後付けを binder 経由へ

### 変更箇所

- ファイル: `app/Providers/PasskeyServiceProvider.php`（L99-164）

### 波及変更

- TypeScript 型定義 / API Resource/DTO: なし
- テストファイル: `PasskeyRouteProtectionTest` / `PasskeyPackageContractTest` /
  `LoginMethodRemovalRouteTest` は**変更しない**

### 現行コード

```php
    public function boot(): void
    {
        $this->configureLoginAuthorization();

        // binder と middleware は **全 provider boot 後** に最終上書きする
        // (PasskeysServiceProvider::boot() の Route::bind に確実に後勝ちするため)。
        $this->app->booted(static function (Application $app): void {
            Route::bind('passkey', SelfScopedPasskeyBinder::class);
            self::attachMiddlewareToPasskeyRoutes($app);
        });
    }

    /**
     * Fortify が登録した passkey route へアプリ側 middleware を後付けする。
     * FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() と同じ作法
     * (route:cache 下でも CompiledRouteCollection の nameCache が同一 instance を返すため有効)。
     */
    private static function attachMiddlewareToPasskeyRoutes(Application $app): void
    {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        // **順序が重要**: throttle → recent-auth → 手段保持 の順に通す。…（略）
        foreach (self::THROTTLE_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'throttle:passkeys');
        }

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // WebAuthn challenge を載せる応答をキャッシュさせない。
        self::appendMiddlewareIfMissing($routes, 'passkey.login-options', 'no-store');
    }

    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }
```

> `L129` が是正対象。あわせて、同じ callback 内の `Route::bind()` が **cached 起動でも有効**
> であることが読み取れず、「callback ごと無効」と誤読されうる。

### 変更後コード

```php
    public function boot(): void
    {
        $this->configureLoginAuthorization();

        // ★この booted callback には **cached 起動での有効/無効が異なる 2 種類**が同居する。
        //   一括りに「cached では無効」と読まないこと:
        //
        //   - Route::bind() は Router::$binders（route collection とは**別の**連想配列）への
        //     登録であり、Router::setCompiledRoutes() の collection 差し替えの影響を受けない。
        //     **cached 起動でも有効**。ここに置いてあるのは boot 順序の問題
        //     （PasskeysServiceProvider::boot() の Route::bind に後勝ちする）だけが理由。
        //   - middleware の後付けは route collection への書き込みであり、
        //     **cached 起動では 1 本も効かない**（RouteMiddlewareBinder の docblock が正本）。
        $this->app->booted(static function (): void {
            Route::bind('passkey', SelfScopedPasskeyBinder::class);
        });

        // first-class callable（理由は FortifyServiceProvider 側と同じ）
        RouteMiddlewareBinder::attachOnBooted($this->app, self::passkeyRouteSpecs(...));
    }

    /**
     * Fortify が登録した passkey route へ後付けするアプリ側 middleware の spec。
     *
     * ★**順序が重要**: throttle → recent-auth → 手段保持 の順に並べる。
     *   throttle を先に並べることで、priority 適用後も ThrottleRequests が
     *   RequireRecentAuth より前になる（無制限のロック競合を最外周で止める）。
     *   逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
     *   PasskeyRouteProtectionTest が解決後のクラス列上の index 比較で固定する。
     *   → 1 route あたりの alias 列も**この順**で並べる（binder は列の順に append する）。
     *
     * ★route:cache との契約（cached 起動では 1 本も効かない / 実効は生成時の焼き込み /
     *   毎デプロイ再生成が前提条件）は {@see \App\Support\Http\RouteMiddlewareBinder} が正本。
     *   **旧記述の訂正**: かつてここには「route:cache 下でも nameCache が同一 instance を
     *   返すため有効」と書いてあったが誤りである。
     *
     * ★feature flag: passkey route は `Features::passkeys()` が有効なときだけ登録される
     *   （config/fortify.php の「この 1 行が実質的なキルスイッチ」）。無効時は spec を空にする。
     *
     * @return array<string, list<string>> route 名 => middleware alias の列
     */
    private static function passkeyRouteSpecs(): array
    {
        if (! Features::enabled(Features::passkeys())) {
            return [];
        }

        $specs = [];

        foreach (self::THROTTLE_ROUTE_NAMES as $name) {
            $specs[$name] = ['throttle:passkeys'];
        }

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            $specs[$name] = [...($specs[$name] ?? []), 'recent-auth'];
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            $specs[$name] = [...($specs[$name] ?? []), 'ensure-login-method'];
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // WebAuthn challenge を載せる応答をキャッシュさせない。
        $specs['passkey.login-options'] = [...($specs['passkey.login-options'] ?? []), 'no-store'];

        return $specs;
    }
```

**use 文の増減（明示）**:

- **追加**: `use App\Support\Http\RouteMiddlewareBinder;` / `use Laravel\Fortify\Features;`
- **削除候補**: `Illuminate\Routing\RouteCollectionInterface` / `Illuminate\Routing\Router`
  （`Illuminate\Contracts\Foundation\Application` は `configureLoginAuthorization()` 等で
  使われていなければ削除。`Illuminate\Support\Facades\Route` は `Route::bind` で引き続き必要）
- `attachMiddlewareToPasskeyRoutes()` と `appendMiddlewareIfMissing()` は**削除**する。
- 付与内容・付与順序は現行と**完全に同一**（`passkey.destroy` は
  `throttle:passkeys` → `recent-auth` → `ensure-login-method` の順で並ぶ）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全: `$specs[$name] ?? []` で未定義キーを避ける
- [x] Generics: `array<string, list<string>>` を phpdoc で明示。
      spread `[...$a, $b]` は list を保つ

### テスト計画

- [x] 既存 `tests/Architecture/PasskeyRouteProtectionTest.php` が**変更なしで** green
      （middleware 構成の inventory 一致 + **解決後クラス列の index 比較による順序固定**）
- [x] 既存 `tests/Architecture/PasskeyPackageContractTest.php` が**変更なしで** green
      （`Route::bind` の差し替えが生きていること = binder 分離の回帰）
- [x] 既存 `tests/Architecture/LoginMethodRemovalRouteTest.php` が**変更なしで** green

### リスク

- `Route::bind` を別の `booted()` に切り出したことで**発火順**が変わる。
  現行は「bind → middleware 後付け」の順で 1 つの callback、変更後は
  「bind の callback」→「binder の callback」の 2 つ。**登録順は変わらないため発火順も同じ**
  で、かつ両者は独立（binders 連想配列 vs route collection）なので影響はない。
  `PasskeyPackageContractTest` が bind の最終解決系を固定しているため、崩れれば検出される。
- alias 列の順序が変わると `PasskeyRouteProtectionTest` の index 比較が落ちる
  = 順序は機械固定されている（設計どおりであることの確認になる）。

---

## 施策 4: binder の契約テスト

### 変更箇所

- ファイル: `tests/Feature/Security/RouteMiddlewareBinderTest.php`（新規）

配置は既存の `tests/Feature/Security/RouteThrottleBinderTest.php` に**揃える**
（Router facade を使うため Feature レーン。DB は触らない）。

### テスト計画（実装する項目）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | `routesAreCached: true では resolver すら呼ばれない` | **呼ばれたら `RuntimeException` を投げる resolver** を渡して `attachAll($router, $resolver, routesAreCached: true)` が例外なく完了する。**= T120 の恒久回帰**。resolver に到達しない ⇒ route 解決にも到達しない、を 1 本で表明できる（Application の stub を作らずに配線側のリスクを純粋関数へ閉じ込めた形。理由は `attachOnBooted` の docblock） |
| 1b | `routesAreCached: true では middleware が 1 本も増えない` | 既存 probe route を用意し、その route を含む spec を返す resolver を渡しても `middleware()` が不変 |
| 2 | `routesAreCached: false で route が引けないと RuntimeException（メッセージに route 名を含む）` | fail-fast |
| 3 | `alias が実効列に 1 本増える` | probe route を作り `attachAll(..., false)` 後に `gatherMiddleware()` に含まれる |
| 4 | `2 回呼んでも 1 本のまま（冪等）` | 重複付与しない |
| 5 | `列の順に append される` | `['throttle:passkeys', 'recent-auth', 'ensure-login-method']` が `middleware()` 上でその順に並ぶ（Passkey の順序契約の土台） |
| 6 | `付与後に computedMiddleware が破棄されている` | 事前に `gatherMiddleware()` を呼んで memo を温めてから付与し、再度 `gatherMiddleware()` に新 alias が現れる（**無音の無防備**の回帰） |
| 7 | `変更が無いときは既存 route を壊さない` | 既に alias を持つ route へ同じ alias を渡しても `middleware()` が不変 |
| 8 | **`attachOnBooted()` は cached 起動で resolver を呼ばない（配線の固定）** | `app()->instance('routes.cached', true)` としてから `attachOnBooted(app(), 呼ばれたら throw する resolver)` を実行し、**例外が出ない**ことを表明する |
| 8b | **negative control: 非 cached なら resolver は実際に呼ばれる** | `app()->instance('routes.cached', false)` で同じ resolver を渡すと**例外が出る**。#8 が「配線が死んでいるから green」になっていないことの担保 |

#### #8 / #8b の実装メモ（stub を作らない）

`Illuminate\Foundation\Application::routesAreCached()` は
**まず container binding `routes.cached` を見る**（`if ($this->bound('routes.cached')) { return (bool) $this->make('routes.cached'); }`）。
したがって `app()->instance('routes.cached', true)` で cached 起動を再現でき、
Application の stub / mock も route cache ファイルの生成も要らない。
また `Application::booted()` は `isBooted()` なら **その場で callback を発火する**ため、
テスト内で `attachOnBooted()` を呼べば同期的に配線が走る。
（`routes.cached` の束ね直しはテストごとに作り直されるアプリに閉じるので、
他テストへ漏れない）

- [x] バグ修正ではないため再現テストは不要（振る舞い是正ではなく作法の統一）
- [x] 個別の `DatabaseTransactions` を使わない（`tests/Pest.php` のグローバル適用に従う）
- [x] 既存テストの削除・上書きなし

### リスク

- テスト内で `Route::post(...)` して probe route を作るため、name index の refresh 忘れで
  偽 fail しうる。`attachAll()` 側が `refreshNameLookups()` を呼ぶ設計なので、
  テスト側でも既存 `RouteThrottleBinderTest` と同じ helper 形にして揃える。

---

## 施策 5: 後付け経路の deny-by-default 目録

### 変更箇所

- ファイル: `tests/Architecture/PostBootRouteMutationInventoryTest.php`（新規）

### 何を検査するか（明確化）

**検査するのは「起動後に route collection から named route を引くコードが、
skip 判定を引数で受ける 2 つの binder に限られていること」だけ**である。

- 対象トークン: `app/` 配下の PHP ファイルに現れる `getByName(` と `refreshNameLookups(`
  （**実測**: 現在の出現は 3 ファイル・7 箇所のみ = `FortifyServiceProvider` 3 /
  `PasskeyServiceProvider` 2 / `RouteThrottleBinder` 2。施策 2 / 3 の後は allowlist の
  2 ファイルだけになる。素のトークン走査で false positive が出ないことを確認済みなので、
  `token_get_all()` によるコメント除外は**今は入れない** — 必要になってから入れる）
- allowlist: `app/Support/Http/RouteThrottleBinder.php` /
  `app/Support/Http/RouteMiddlewareBinder.php` の **2 ファイルのみ**
- allowlist 外に出現したら fail し、
  「後付けは RouteThrottleBinder / RouteMiddlewareBinder 経由にすること。
  cached 起動で例外を投げると `route:list` が必ず落ちる（T120）」と案内する
- **negative control**: allowlist の 2 ファイルが実際にそのトークンを**含む**ことも表明する
  （実装が消えたり改名されたときに gate が空振り green にならないようにする）

### 何を検査しないか（誇張しない）

- **docblock の主張が機序と一致していること**は検査しない。自然言語の主張は機械で
  照合できない。ここで守れるのは「後付けの**入口**が 1 本に絞られていること」までである。
- **起動時の route cache 鮮度**は検査しない。本番デプロイは全ファイルを新規展開するため
  mtime は揃い、cache が古いソースから作られたかは起動時から判定できない
  （「作れるが作らない」ではなく **正しく作れない**）。
- トークン走査であるため、`$router->getRoutes()->getByName(...)` を変数越しに
  組み立てるような書き方は**すり抜ける**。この gate は「うっかり」を止めるものであって、
  意図的な迂回を止めるものではない。

### なぜ作るか（検査を作ること自体が目的にならないよう）

止めたい具体的失敗は 1 つで、**過去に実際に起きている**:
新しい後付け経路を追加した人が skip 判定を書かず、cached 起動で例外を投げて
`php artisan route:list` が必ず落ちる（T120）。後付け経路はこの 1 年で 3 本増えており
（T120 / T121 / T124）、4 本目が足される確率は低くない。
入口を 2 クラスに絞れば、その 2 クラスが持つ「skip 判定を引数で受ける」形が自動的に効く。

### PHPStan適合チェック

- [x] Pest のテストファイル（`declare(strict_types=1)`、クロージャの戻り型 `void` 明示）
- [x] `file_get_contents()` の戻りは `string|false` なので `expect(...)->toBeString()` で絞る
      （既存 `TrustedProxiesRunbookTest` と同じ作法）

### テスト計画

自身がテストのため該当なし。ただし**実装前に fail を確認する**
（思考原則 5 テストファースト）: allowlist を空にした状態で 3 ファイルが列挙されて落ちること、
allowlist を入れて green になることを実測してから確定する。

### リスク

- 将来 vendor route への後付けが本当に別クラスで必要になったとき、allowlist を足す判断が要る。
  そのときこそ「skip 判定を引数で受けているか」を review する契機になるので、
  摩擦は**意図した摩擦**である。

---

## 施策 6: 運用契約の格上げ（§7c 新設）

### 変更箇所

- ファイル: `docs/app-integration-guide.md`
  - §7b「流量制限の付与規約」の第 3 段のうち **`php artisan route:cache` 要件の箇条書き**を
    §7c へ移し、§7b にはポインタだけを残す
  - §7b の直後に **§7c「vendor route への後付け機構と route:cache の契約」**を新設

### 変更後コード（§7c の骨子）

```markdown
### §7c vendor route への後付け機構と route:cache の契約

vendor (Fortify / laravel-passkeys / Cashier) が登録した route へ、アプリ側が
boot 後に middleware を後付けする経路は **2 つの binder に限られる**:

| binder | 付けるもの | 呼び出し元 |
|---|---|---|
| `RouteThrottleBinder::attachOnBooted()` | `throttle:{limiter}` | FortifyServiceProvider / AppServiceProvider |
| `RouteMiddlewareBinder::attachOnBooted()` | `recent-auth` / `recent-auth.on-email-change` / `ensure-login-method` / `no-store` / `throttle:passkeys` | FortifyServiceProvider / PasskeyServiceProvider |

**2 つの事象を混ぜないこと**:

1. **生成時**（`php artisan route:cache` 実行中）= 後付けが完全に走り、cache へ焼き込まれる。
   route 名が消えていればここでデプロイが止まる（fail-fast が効くのはここだけ）。
2. **起動時**（cached 起動）= 後付けは **1 本も効かない**。
   `loadRoutesFrom()` が require を飛ばすため、**binder の callback が走る時点では**
   対象 named route が 1 本も登録されていない（compiled routes はこの callback より
   **後**に読まれる。「route が永久に存在しない」の意味ではない）。
   ゆえに binder は明示 skip する
   （ここで例外を投げると `route:list` が必ず落ちる = T120）。

⇒ **運用要件: `php artisan route:cache` を毎デプロイ再生成すること。**
   これは throttle だけの要件ではない。**2FA 秘密の露出防止 (recent-auth) /
   passkey 削除の手段保持 (ensure-login-method) / WebAuthn challenge の no-store も
   同じ前提条件に乗っている**。stale な route cache は古い付与状態のまま起動し、
   **無音で保護が外れる**（実測: 剥がした cache では鮮度切れセッションの
   2FA 秘密 GET が 409 でなく **200 で秘密を返す**、`force=true` の enable も 200、
   `passkey.destroy` の 429 が消える）。

**現状**: 本リポジトリに**デプロイ定義は存在しない**（deploy/ / terraform / k8s manifest /
CI のデプロイ job のいずれも無い）。したがって上記は**今日は人手で守られている要件**であり、
デプロイ基盤を作る PR が**必ず実装しなければならない要件**である（AGENTS.md の運用要件ブロック）。
今その基盤を先回りして作らない（思考原則 2）。

**新しい後付け経路を足すとき**: 必ず上記 2 binder のどちらかを通す。
`PostBootRouteMutationInventoryTest` が deny-by-default で強制する。
```

### 波及変更

- テストファイル: **なし**（§7c の存在を検査するテストは作らない。
  ドキュメント節の存在検査は `TrustedProxiesRunbookTest` のような
  「運用者が埋めないと fail する欄」がある場合にだけ意味があり、ここには埋める欄がない）

### リスク

- §7b の既存記述を移動するため、`docs/app-integration-guide.md §7b` を参照している
  既存記述（AGENTS.md ドメイン固有規約 5 / `RouteThrottleBinder` の docblock /
  `AppServiceProvider` の docblock）が指す先がずれる。
  → §7b は**残したまま**ポインタを置く（節番号を消さない）。施策 7 で
  AGENTS.md 側の参照を §7c にも向ける。

---

## 施策 7: AGENTS.md に運用要件ブロック

### 変更箇所

- ファイル: `AGENTS.md`
  - 「セキュリティ不変条件」節末尾の **`> **運用要件 (T108)**` ブロックの直後**に、
    同じ書式で route:cache の運用要件ブロックを追加
  - ドメイン固有規約 5 の「vendor 登録 route への後付けは …」の箇条書きから
    §7c を指すよう 1 行修正（§7b への既存参照は残す）

### 変更後コード（追加ブロック）

```markdown
> **運用要件 (route:cache)**: production は `php artisan route:cache` を**毎デプロイ再生成する**。
> vendor route への middleware 後付け(`RouteThrottleBinder` / `RouteMiddlewareBinder`)は
> **cache 生成時に焼き込まれ、cached 起動では 1 本も効かない**ため、stale cache は
> **無音で保護を外す**(実測: 2FA 秘密 GET が 409 でなく 200 を返し、passkey 削除の
> 手段保持 guard も消える)。対象は throttle だけではない(recent-auth /
> ensure-login-method / no-store も同じ前提条件)。機序と実測は
> `docs/app-integration-guide.md` §7c が正本。
> **本リポジトリにデプロイ定義は無い**(deploy/ / terraform / k8s / CI デプロイ job のいずれも無い)。
> よって現在この要件は**人手でのみ守られている**。**デプロイ基盤を作る PR は、
> 本要件と TRUSTED_PROXIES 運用要件 (T108) の 2 つを実装するまで完了にできない**。
> 存在しない基盤のための preflight 機構を先回りして作らないこと(思考原則 2)。
```

### 波及変更

- テストファイル: **なし**。AGENTS.md の記述を機械検査するテストは作らない
  （文言検査は形骸化しやすく、守りたい不変条件の代理になっていない）

### リスク

- AGENTS.md が長くなる。ただし T108 の先例と同じ書式・同じ位置なので、
  「運用要件はここに並ぶ」という読み筋は保たれる。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `app/Providers/` の 2 ファイルと `app/Support/Http/` の新規 1 ファイルに閉じており、他ドメインと重ならない。(2) 施策 5 の目録テストは実装前に fail を観測する必要があり（テストファースト）、他タスクの差分が混ざると観測が濁る。(3) `AGENTS.md` / `docs/app-integration-guide.md` を触るため、同時進行の設計タスクと衝突しやすい |
| 競合リスク | `AGENTS.md`（ドメイン固有規約 5 / セキュリティ不変条件節）と `docs/app-integration-guide.md` §7b は他タスクも触りやすい。マージ順を先にするか、rebase 時に節番号の重複を確認する |

## 実装順序（テストファースト）

1. 施策 5 の目録テストを allowlist 空で作り、**3 ファイルが列挙されて fail** することを観測する。
2. 施策 1 の binder を新設し、施策 4 のテストを書いて **fail → green** を観測する
   （特に #1 = 純粋関数の lazy resolver 契約、#8 / #8b = 実配線の振る舞いと negative control、
   #6 = computedMiddleware 破棄）。
3. 施策 2 / 3 で provider を binder 経由へ移し、旧 private helper を**同じコミットで削除**する
   （思考原則 3）。ここで施策 5 の allowlist を確定し green にする。
4. 既存の route 保護系テスト群が **1 行も変更されないまま** green であることを確認する。
5. 施策 6 / 7 のドキュメントを更新する。
6. `php artisan route:cache` → `php artisan route:list` → `php artisan route:clear` の
   往復が例外なく完了することを手で確認する（T120 と同じ確認手順）。

## 検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test`
（フロント差分ゼロのため `pnpm` 系は影響なしだが、CI 契約どおり全 green を確認する）

---

## 最終確認: 使命・禁止事項チェック（Phase 2-5）

| 項目 | 判定 | 根拠 |
|---|---|---|
| 全施策が使命に寄与するか | ○（間接） | 撮影 PWA の認証面（2FA 秘密の露出防止 / passkey 削除の手段保持）を守る機構の**運用要件を隠さない**ための是正。直接のユーザー価値は増やさない（誇張しない） |
| 禁止事項 1（テストなしの実装完了） | ○ | 施策 4（binder 契約テスト 9 本）と施策 5（Architecture 目録）を施策に含む。既存の route 保護系テストが**差分なしで green** であることも完了条件 |
| 禁止事項 2（PHPStan の widen / baseline） | ○ | `@phpstan-type` 相当の array shape と first-class callable で level 10 を通す。ignore / baseline を使わない |
| 禁止事項 3（dev DB 破壊操作） | ○ | DB に触れない |
| 禁止事項 4（`response()->json()` 直書き） | ○ | HTTP 応答を作らない |
| 禁止事項 5 / 6（Prism 直呼び / prompt 直書き） | ○ | 非該当 |
| 禁止事項 9（Artifact 使用） | ○ | 成果物はすべて `devnotes/` 配下のファイル |
| 思考原則 1（フレームワークのレンジ内） | ○ | 後付け方式は現行のまま。framework の `booted()` / `routesAreCached()` の公式作法だけを使う |
| 思考原則 2（今必要なものだけ） | ○ | デプロイ基盤の先回り実装なし / 起動時 cache 鮮度検査なし / `attachOnBooted` の二重 skip なし / tokenizer なし |
| 思考原則 3（後方互換の並走を残さない） | ○ | 旧 `appendMiddlewareIfMissing()` 2 本を**同じコミットで削除**する |
| 思考原則 4（似ているからで統合しない） | ○ | `RouteThrottleBinder` と統合しない（責務もシグネチャも意図的に揃えない。理由を docblock に残す） |
| 思考原則 5（テストファースト） | ○ | 「実装順序」に fail 観測の手順を明記 |
| セキュリティ不変条件 | ○ | 保護の付与内容を 1 つも変えない。既存の目録検査群が差分なしで green であることが証拠 |

## Codex 合議の結果

| フェーズ | ラウンド | 判定 |
|---|---|---|
| 概念設計レビュー（gpt-5.5 / medium） | 1 | **APPROVED**（Critical 0 / Warning 4 / Suggestion 4。Warning は 3 件対応・1 件は一部反論） |
| 詳細設計レビュー（gpt-5.5 / high） | 1 | CHANGES_REQUESTED（施策 1 / 3 / 4 が REQUEST_CHANGES） |
| 詳細設計レビュー | 2 | CHANGES_REQUESTED（施策 1 / 4 が REQUEST_CHANGES。「型として不可能」の誇張を指摘され是正） |
| 詳細設計レビュー | 3 | **APPROVED**（Critical / Warning ともに 0。二重防御を採らない反論も受理された） |

議論履歴: `devnotes/20260808-0130-route-cache-attach-contract/codex-history/`
（プロンプト 4 本 / 対応マトリクス 3 本）、Codex 返答は同ディレクトリ直上の
`conceptual-review-round-1.md` / `detailed-review-round-{1,2,3}.md`。


---

## Round 1 の Codex 指摘 (全文)

レビュー結果です。実装挙動そのものに Critical は見つけていませんが、今回の主目的が「誤った機序記述の是正」なので、残っている不正確な記述は修正対象です。

**AGENTS.md — REQUEST_CHANGES**

[Warning] [AGENTS.md](/workspace/.claude/worktrees/tasks/T135/AGENTS.md:375)  
ドメイン固有規約 5 に残っている「route 名が消えたら起動時 fail-fast」は、cached 起動では skip するという今回の契約と衝突します。  
「非 cached 起動 / route:cache 生成時に fail-fast。cached 起動では skip」のように限定してください。

**app/Providers/FortifyServiceProvider.php — APPROVED**

指摘なし。  
feature flag で spec を絞り、既存 helper を削除して binder 経由へ寄せた点は設計通りです。route:cache に関する旧説明も適切に否定されています。

**app/Providers/PasskeyServiceProvider.php — APPROVED**

[Suggestion] [app/Providers/PasskeyServiceProvider.php](/workspace/.claude/worktrees/tasks/T135/app/Providers/PasskeyServiceProvider.php:178)  
`withAlias()` 追加は妥当です。PHPStan level 10 の shape 推論を避けつつ `array<string, list<string>>` の契約を維持しており、付与順序も保たれています。  
ただしコメントの「型を緩めず」は少し強いので、「`mixed` 化や ignore に逃げず、公開契約を保ったまま具体 shape 推論を避ける」程度にするとより正確です。

**app/Support/Http/RouteMiddlewareBinder.php — REQUEST_CHANGES**

[Warning] [RouteMiddlewareBinder.php](/workspace/.claude/worktrees/tasks/T135/app/Support/Http/RouteMiddlewareBinder.php:15)  
docblock の「throttle の後付けは RouteThrottleBinder が担当する」は、実装と矛盾しています。`RouteMiddlewareBinder` は実際に `throttle:passkeys` を付与します。  
「RouteThrottleBinder は limiter 検証・二重付与検出が必要な throttle 後付けを担当し、こちらは既存挙動維持のため任意 alias を順序通り付ける。`throttle:passkeys` も alias として扱う」などに修正してください。

[Warning] [RouteMiddlewareBinder.php](/workspace/.claude/worktrees/tasks/T135/app/Support/Http/RouteMiddlewareBinder.php:65)  
「現行 2 経路は判定タイミングが異なる（Fortify = boot 内 / Passkey = booted 内）」「どちらのタイミングも変えない」は、現在の配線では不正確です。どちらの resolver も `attachOnBooted()` 内の booted callback で評価されます。  
ここは「resolver を `attachOnBooted()` 呼び出し時に前倒し評価しない。cached 起動では resolver も実行しない」とだけ書けば十分です。

**docs/app-integration-guide.md — REQUEST_CHANGES**

[Warning] [docs/app-integration-guide.md](/workspace/.claude/worktrees/tasks/T135/docs/app-integration-guide.md:317)  
§7b に残っている「route 名が消えていれば起動時に fail-fast」が §7c の説明とズレています。ここも AGENTS.md と同じく、fail-fast が効くのは非 cached 起動 / route:cache 生成時であり、cached 起動では skip と明記してください。

§7c 自体の機序説明、T120 の再発条件、stale cache の運用リスクの書き方は妥当です。

**tests/Architecture/PostBootRouteMutationInventoryTest.php — APPROVED**

指摘なし。  
allowlist 外検出と negative control があり、空振り green への対策も入っています。検査範囲を誇張していない点も良いです。

**tests/Feature/Security/RouteMiddlewareBinderTest.php — APPROVED**

指摘なし。  
T120 回帰、lazy resolver、非 cached の negative control、computedMiddleware 破棄、順序保持まで押さえられています。mutation 実測も新設テストが効いている証拠として十分です。

**全体判定: CHANGES_REQUESTED**

コード挙動・テスト網羅は概ね設計通りです。修正対象は主に docblock / 運用記述の精度で、特に `RouteMiddlewareBinder` が `throttle:passkeys` を扱う事実との矛盾は直してください。

---

## Round 1 への対応マトリクス (実装側)

# Round 1 対応マトリクス

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 4 / Suggestion 1)。
コード挙動・テスト網羅には指摘なし。指摘は**すべて記述精度**であり、本 TODO の中心が
「誤った機序記述の是正」であるため 5 件すべて対応した。

| # | 指摘 | 種別 | 対応 | 内容 |
|---|---|---|---|---|
| 1 | `AGENTS.md` ドメイン固有規約 5 の「route 名が消えたら**起動時** fail-fast」が今回の契約 (cached 起動は skip) と衝突 | Warning | **対応** | 「効くのは非 cached 起動 = `route:cache` 生成時。cached 起動では後付けごと skip される。そこで例外を投げると `route:list` が必ず落ちる (T120)」へ限定 |
| 2 | `RouteMiddlewareBinder` の docblock「throttle の後付けは RouteThrottleBinder が担当」が実装と矛盾 (本 binder は `throttle:passkeys` を付ける) | Warning | **対応** | 責務境界を書き直した。RouteThrottleBinder = limiter 形式検証・二重付与検出を持つ throttle 専用、こちら = 任意 alias を列順に冪等付与。**「throttle は必ず向こう」ではない**ことを明示し、passkey 系を 1 route の alias 列として扱う理由 (throttle → recent-auth → 手段保持 の順序契約が割れるため) を書いた |
| 3 | 「現行 2 経路は判定タイミングが異なる (Fortify = boot 内 / Passkey = booted 内)」が現在の配線では不正確 (どちらも `attachOnBooted()` 内の booted callback で評価される) | Warning | **対応** | 詳細設計の文面をそのまま持ち込んでいた箇所。「spec の構築 (feature flag 判定を含む) を `boot()` へ前倒し評価しない。resolver は booted callback の中でだけ評価される」へ差し替えた。**詳細設計の文面からの意図的な逸脱** (設計時の記述が実装後の事実と合わなくなったため) |
| 4 | `docs/app-integration-guide.md` §7b の「route 名が消えていれば**起動時に** fail-fast」が §7c とズレる | Warning | **対応** | #1 と同じ限定を §7b に入れた (§7c への導線つき)。§7c 側 (L457「fail-fast が効くのはここだけ」) は元から正しいので触っていない |
| 5 | `PasskeyServiceProvider::withAlias()` の「型を緩めず」が強すぎる | Suggestion | **対応** | 「`mixed` 化や `@phpstan-ignore` に逃げず、公開契約の型をそのまま保ったまま具体 shape の推論だけを切る」へ書き換えた |

## 触っていないもの (意図)

- `app/Support/Http/RouteThrottleBinder.php` — L26 の fail-fast 記述は元から正しい
  (「ここで**デプロイが止まる**」= 生成時)。詳細設計どおり **1 行も変更しない**。
- 既存の route 保護系テスト群 — 1 行も変更しない (差分なしで green = 振る舞い不変の直接証拠)。


---

## 現在の実装差分 (git diff HEAD -- app/ tests/ docs/ AGENTS.md)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index e9e72ae..48f6909 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -89,6 +89,18 @@ ## セキュリティ不変条件(アプリ都合で緩めない)
 > 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
 > `docs/trusted-proxies-runbook.md` が正本。
 
+> **運用要件 (route:cache)**: production は `php artisan route:cache` を**毎デプロイ再生成する**。
+> vendor route への middleware 後付け(`RouteThrottleBinder` / `RouteMiddlewareBinder`)は
+> **cache 生成時に焼き込まれ、cached 起動では 1 本も効かない**ため、stale cache は
+> **無音で保護を外す**(実測: 2FA 秘密 GET が 409 でなく 200 を返し、passkey 削除の
+> 手段保持 guard も消える)。対象は throttle だけではない(recent-auth /
+> ensure-login-method / no-store も同じ前提条件)。機序と実測は
+> `docs/app-integration-guide.md` §7c が正本。
+> **本リポジトリにデプロイ定義は無い**(deploy/ / terraform / k8s / CI デプロイ job のいずれも無い)。
+> よって現在この要件は**人手でのみ守られている**。**デプロイ基盤を作る PR は、
+> 本要件と TRUSTED_PROXIES 運用要件 (T108) の 2 つを実装するまで完了にできない**。
+> 存在しない基盤のための preflight 機構を先回りして作らないこと(思考原則 2)。
+
 ## テストレーンの外部 HTTP 出口 (既定拒否)
 
 テストレーンは Laravel HTTP client (`Http::`) 経由の外向き通信を**既定で拒否**する
@@ -361,9 +373,17 @@ ## ドメイン固有規約
      レーンをまたぐキー衝突は `RateLimiterKeyConventionTest`、
      巻き添え 429 が消えたことの実挙動は `AuthThrottleCoverageTest` が固定する
    - vendor 登録 route への後付けは **`RouteThrottleBinder::attachOnBooted()`** 経由
-     (route 名が消えたら起動時 fail-fast)。**`php artisan route:cache` は毎デプロイ再生成する**
+     (route 名が消えたら fail-fast。ただし**効くのは非 cached 起動 =
+     `route:cache` 生成時**であり、**cached 起動では後付けごと skip される**。
+     そこで例外を投げると `route:list` が必ず落ちるため = T120)。
+     **`php artisan route:cache` は毎デプロイ再生成する**
      (後付けは cache 生成時に焼き込まれ cached 起動では skip されるため、stale cache は
-     古い付与状態のまま起動する)
+     古い付与状態のまま起動する)。
+     throttle 以外の alias 後付け(recent-auth / ensure-login-method / no-store)は
+     **`RouteMiddlewareBinder::attachOnBooted()`** 経由で、**同じ前提条件に乗っている**。
+     後付け経路を新設するときの契約と、入口を 2 binder に絞る
+     `PostBootRouteMutationInventoryTest` の説明は
+     `docs/app-integration-guide.md` **§7c** が正本
    - **閾値は既存値を変えない**。新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる
    - 未認証 webhook に**固定キーの全体天井を置かない** (throttle は署名検証より前に走るため、
      無効 body の連打で正当通知を 429 にできる = 攻撃者が業務を止められる口になる)。
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 76884f2..b648ad9 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -26,13 +26,11 @@
 use App\Support\EmailHash;
 use App\Support\EmailNormalizer;
 use App\Support\Http\RateLimiterKeys;
+use App\Support\Http\RouteMiddlewareBinder;
 use App\Support\Http\RouteThrottleBinder;
 use Illuminate\Cache\RateLimiting\Limit;
-use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
-use Illuminate\Routing\RouteCollectionInterface;
-use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\RateLimiter;
 use Illuminate\Support\ServiceProvider;
 use Inertia\Inertia;
@@ -229,40 +227,53 @@ private function attachThrottleToFortifyRoutes(): void
      * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
      * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
      * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
-     * booted callback で名前解決して append する。route:cache 下でも
-     * CompiledRouteCollection::getByName() が nameCache に memoize した同一 instance を
-     * match() が返すため、この変更は dispatch にも有効。
+     * booted callback で名前解決して append する。
+     *
+     * ★route:cache との契約 (**cached 起動では 1 本も効かない**) と、
+     *   「毎デプロイ `php artisan route:cache` を再生成する」という前提条件は
+     *   {@see RouteMiddlewareBinder} の docblock が正本。
+     *   **旧記述の訂正**: かつてここには「route:cache 下でも nameCache が同一 instance を
+     *   返すため dispatch にも有効」と書いてあったが、**誤り**である。cached 起動では
+     *   Fortify の loadRoutesFrom() が require を飛ばして対象 route を登録しないため、
+     *   この callback は compiled collection に到達しない。実効しているのは
+     *   route:cache **生成時**の焼き込みである。
+     *
+     * ★feature flag の扱い: 対象 route は Fortify の機能フラグで登録有無が決まるため、
+     *   有効な機能の分だけを spec に載せる (無効な機能の route まで要求すると
+     *   binder が fail-fast して起動できなくなる)。skip が穴にならない根拠は
+     *   throttledFortifyRoutes() の docblock と同じ = 目録検査 (RecentAuthRouteTest /
+     *   TwoFactorStepUpInventoryTest) が二重の網になる。
      */
     private function attachRecentAuthToSensitiveRoutes(): void
     {
-        $this->app->booted(static function (Application $app): void {
-            $routes = $app->make(Router::class)->getRoutes();
-            // fluent な ->name() 付与はコレクションの name index に遅延反映のため明示 refresh
-            $routes->refreshNameLookups();
-
-            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
-                self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
-            }
-
-            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
-                self::appendMiddlewareIfMissing($routes, $name, $alias);
-            }
-        });
+        // first-class callable で渡す (`static fn (): array => …` にすると
+        // 匿名関数の戻り値に iterable value type が付かず PHPStan level 10 で詰まる。
+        // メソッド参照なら recentAuthRouteSpecs() の @return がそのまま効く)
+        RouteMiddlewareBinder::attachOnBooted($this->app, self::recentAuthRouteSpecs(...));
     }
 
     /**
-     * named route に middleware alias を idempotent に append する (未登録時のみ)。
+     * recent-auth 系 middleware を後付けする Fortify route の spec。
      *
-     * booted callback (static クロージャ) から呼ぶため **static** で定義し
-     * `self::appendMiddlewareIfMissing(...)` で呼ぶ。長寿命プロセス等で callback が
-     * 同一 Route instance に複数回届いても重複付与しない (idempotent)。
+     * @return array<string, list<string>> route 名 => middleware alias の列
      */
-    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
+    private static function recentAuthRouteSpecs(): array
     {
-        $route = $routes->getByName($name);
-        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
-            $route->middleware($alias);
+        $specs = [];
+
+        if (Features::enabled(Features::twoFactorAuthentication())) {
+            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
+                $specs[$name] = ['recent-auth'];
+            }
+        }
+
+        if (Features::enabled(Features::updateProfileInformation())) {
+            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
+                $specs[$name] = [$alias];
+            }
         }
+
+        return $specs;
     }
 
     private function configureRateLimiters(): void
diff --git a/app/Providers/PasskeyServiceProvider.php b/app/Providers/PasskeyServiceProvider.php
index 1e4a67b..ecf8da0 100644
--- a/app/Providers/PasskeyServiceProvider.php
+++ b/app/Providers/PasskeyServiceProvider.php
@@ -12,12 +12,11 @@
 use App\Models\Passkey;
 use App\Models\User;
 use App\Services\Auth\PasskeyLoginPolicy;
-use Illuminate\Contracts\Foundation\Application;
+use App\Support\Http\RouteMiddlewareBinder;
 use Illuminate\Http\Request;
-use Illuminate\Routing\RouteCollectionInterface;
-use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\Route;
 use Illuminate\Support\ServiceProvider;
+use Laravel\Fortify\Features;
 use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
 use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
 use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
@@ -100,12 +99,21 @@ public function boot(): void
     {
         $this->configureLoginAuthorization();
 
-        // binder と middleware は **全 provider boot 後** に最終上書きする
-        // (PasskeysServiceProvider::boot() の Route::bind に確実に後勝ちするため)。
-        $this->app->booted(static function (Application $app): void {
+        // ★この 2 つには **cached 起動での有効/無効に差がある**。
+        //   一括りに「booted の後付けは cached では無効」と読まないこと:
+        //
+        //   - Route::bind() は Router::$binders (route collection とは**別の**連想配列) への
+        //     登録であり、Router::setCompiledRoutes() の collection 差し替えの影響を受けない。
+        //     **cached 起動でも有効**。booted に置いてあるのは boot 順序の問題
+        //     (PasskeysServiceProvider::boot() の Route::bind に後勝ちする) だけが理由。
+        //   - middleware の後付けは route collection への書き込みであり、
+        //     **cached 起動では 1 本も効かない** (RouteMiddlewareBinder の docblock が正本)。
+        $this->app->booted(static function (): void {
             Route::bind('passkey', SelfScopedPasskeyBinder::class);
-            self::attachMiddlewareToPasskeyRoutes($app);
         });
+
+        // first-class callable (理由は FortifyServiceProvider 側と同じ)
+        RouteMiddlewareBinder::attachOnBooted($this->app, self::passkeyRouteSpecs(...));
     }
 
     /**
@@ -124,42 +132,69 @@ private function configureLoginAuthorization(): void
     }
 
     /**
-     * Fortify が登録した passkey route へアプリ側 middleware を後付けする。
-     * FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() と同じ作法
-     * (route:cache 下でも CompiledRouteCollection の nameCache が同一 instance を返すため有効)。
+     * Fortify が登録した passkey route へ後付けするアプリ側 middleware の spec。
+     *
+     * ★**順序が重要**: throttle → recent-auth → 手段保持 の順に並べる。
+     *   throttle を先に並べることで、priority 適用後も ThrottleRequests が
+     *   RequireRecentAuth より前になる (無制限のロック競合を最外周で止める)。
+     *   逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
+     *   PasskeyRouteProtectionTest が解決後のクラス列上の index 比較で固定する。
+     *   → 1 route あたりの alias 列も**この順**で並べる (binder は列の順に append する)。
+     *
+     * ★route:cache との契約 (cached 起動では 1 本も効かない / 実効は生成時の焼き込み /
+     *   毎デプロイ再生成が前提条件) は {@see RouteMiddlewareBinder} が正本。
+     *   **旧記述の訂正**: かつてここには「route:cache 下でも nameCache が同一 instance を
+     *   返すため有効」と書いてあったが誤りである。
+     *
+     * ★feature flag: passkey route は `Features::passkeys()` が有効なときだけ登録される
+     *   (config/fortify.php の「この 1 行が実質的なキルスイッチ」)。無効時は spec を空にする。
+     *
+     * @return array<string, list<string>> route 名 => middleware alias の列
      */
-    private static function attachMiddlewareToPasskeyRoutes(Application $app): void
+    private static function passkeyRouteSpecs(): array
     {
-        $routes = $app->make(Router::class)->getRoutes();
-        $routes->refreshNameLookups();
-
-        // **順序が重要**: throttle → recent-auth → 手段保持 の順に通す。
-        // throttle を先に並べることで、priority 適用後も ThrottleRequests が
-        // RequireRecentAuth より前になる (無制限のロック競合を最外周で止める)。
-        // 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
-        // PasskeyRouteProtectionTest が解決後のクラス列上の index 比較で固定する。
+        if (! Features::enabled(Features::passkeys())) {
+            return [];
+        }
+
+        $specs = [];
+
         foreach (self::THROTTLE_ROUTE_NAMES as $name) {
-            self::appendMiddlewareIfMissing($routes, $name, 'throttle:passkeys');
+            $specs = self::withAlias($specs, $name, 'throttle:passkeys');
         }
 
         foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
-            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
+            $specs = self::withAlias($specs, $name, 'recent-auth');
         }
 
         foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
-            self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
+            $specs = self::withAlias($specs, $name, 'ensure-login-method');
         }
 
         // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
         // WebAuthn challenge を載せる応答をキャッシュさせない。
-        self::appendMiddlewareIfMissing($routes, 'passkey.login-options', 'no-store');
+        $specs = self::withAlias($specs, 'passkey.login-options', 'no-store');
+
+        return $specs;
     }
 
-    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
+    /**
+     * spec の route へ alias を**列の末尾**に足す (列の順がそのまま append 順になる)。
+     *
+     * ★helper に切り出しているのは PHPStan level 10 のため。const 由来のリテラル配列へ
+     *   `[...($specs[$name] ?? []), $alias]` を直接書くと、shape が完全に推論されて
+     *   「`??` の左辺は常に存在する / 存在しない」の nullCoalesce.offset で落ちる。
+     *   一般型 `array<string, list<string>>` を跨がせることで、`mixed` 化や
+     *   ignore 注釈に逃げず、公開契約の型をそのまま保ったまま具体 shape の推論だけを
+     *   切り、「未定義キーなら空列から始める」という**意図**をそのまま書ける。
+     *
+     * @param  array<string, list<string>>  $specs
+     * @return array<string, list<string>>
+     */
+    private static function withAlias(array $specs, string $routeName, string $alias): array
     {
-        $route = $routes->getByName($name);
-        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
-            $route->middleware($alias);
-        }
+        $specs[$routeName] = [...($specs[$routeName] ?? []), $alias];
+
+        return $specs;
     }
 }
diff --git a/app/Support/Http/RouteMiddlewareBinder.php b/app/Support/Http/RouteMiddlewareBinder.php
new file mode 100644
index 0000000..7e80f39
--- /dev/null
+++ b/app/Support/Http/RouteMiddlewareBinder.php
@@ -0,0 +1,163 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Http;
+
+use Illuminate\Contracts\Foundation\Application;
+use Illuminate\Contracts\Foundation\CachesRoutes;
+use Illuminate\Routing\Route;
+use Illuminate\Routing\RouteCollectionInterface;
+use Illuminate\Routing\Router;
+use RuntimeException;
+
+/**
+ * vendor が登録した named route へ **middleware alias** を後付けする binder。
+ *
+ * ★責務境界 (統合しない): {@see RouteThrottleBinder} は **limiter の形式検証・二重付与検出**
+ *   という固有責務を持つ throttle 後付け専用の binder であり、こちらは
+ *   **任意の alias を spec の列の順に冪等に足すだけ**である。
+ *   **「throttle は必ず向こう」ではない**: 本 binder も `passkey.destroy` へ
+ *   `throttle:passkeys` を付ける (既存の付与内容・付与順を 1 つも変えないため
+ *   passkey 系は 1 route の alias 列として **throttle → recent-auth → 手段保持** の順で
+ *   まとめて扱う必要があり、throttle だけ別 binder へ切り出すと順序契約が割れる)。
+ *   本 binder にとって `throttle:passkeys` は**検証対象ではないただの alias 文字列**である。
+ *   両者は route:cache との契約 (下記) を共有する。
+ *
+ * ★**2 つの事象を混ぜないこと** (ここが本 binder の存在理由):
+ *
+ *   1. **生成時** (`php artisan route:cache` の実行中)
+ *      `RouteCacheCommand::handle()` は先頭で `route:clear` してから
+ *      `getFreshApplicationRoutes()` で **cache 無しのアプリを再 bootstrap** する。
+ *      そこでは `loadRoutesFrom()` が `require` を通すため本後付けが**完全に走り**、
+ *      付与済み middleware がそのまま cache へ**焼き込まれる**。
+ *      route 名が消えていれば**ここでデプロイが止まる** (fail-fast が効くのはここ)。
+ *
+ *   2. **起動時** (route cache がある状態でのリクエスト処理 / artisan)
+ *      本後付けは **1 本も効かない**。理由は 2 つあり、片方だけでも成立する:
+ *        (a) `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を
+ *            飛ばす。Fortify / laravel-passkeys はこれを使うため、**この callback が走る
+ *            時点では対象 named route が 1 本も登録されていない**
+ *            (compiled routes は後で読まれるので「route が永久に存在しない」の意味ではない。
+ *            ここを誤読すると次の担当がまた別の誤った結論に着く)。
+ *        (b) 仮に触れていても、framework の `RouteServiceProvider` が本 callback より**後**の
+ *            app-booted で compiled routes を読み、`Router::setCompiledRoutes()` が
+ *            route collection を**新品へ丸ごと差し替える**ため捨てられる。
+ *      よって cached 起動では `$routesAreCached` を見て**明示 skip** する。
+ *      **ここで例外を投げてはならない** (`route:list` が必ず落ちる = T120 の事故)。
+ *
+ *   ⇒ **cached 起動での保護を持っているのは cache の中身である**。したがって
+ *     **`php artisan route:cache` を毎デプロイ再生成することが本機構の前提条件**になる。
+ *     stale な route cache は古い付与状態のまま起動し、**無音で保護が外れる**
+ *     (実測: 剥がした cache では 2FA 秘密 GET が 409 でなく 200 を返す)。
+ *     運用契約の正本は `docs/app-integration-guide.md` §7c。
+ *
+ * ★よくある誤読の否定 (この記述を消さないこと):
+ *   `CompiledRouteCollection::getByName()` が Route instance を `nameCache` へ memoize し、
+ *   `match()` がその `getByName()` を通るのは**事実**である。しかしそれは
+ *   「**compiled collection が読まれた後に** getByName して書き換えた場合」の話であり、
+ *   本 callback はその前に走って**別の collection** を見ているため前提が成立しない。
+ *   「nameCache があるから cached 起動でも後付けが効く」とは書かない。
+ */
+final class RouteMiddlewareBinder
+{
+    /**
+     * 起動完了後に named route 群へ middleware alias を後付けする (登録の唯一の入口)。
+     *
+     * ★spec を **resolver (callable) で受け、ここでは呼ばない**。理由は 2 つ:
+     *   1. spec の構築 (呼び出し側の feature flag 判定を含む) を `boot()` の時点へ
+     *      前倒し評価しないため。resolver は **booted callback の中でだけ**評価される。
+     *   2. **cached 起動では resolver 自体を実行しない**ため。resolver をここで呼んで
+     *      配列にしてから渡す形にすると、将来 resolver が route collection を覗く実装に
+     *      なったとき early return の**前**に落ちる = T120 の再導入になる。
+     *      ★ただし「型で不可能」なわけではない (`$specs = $specResolver();` してから
+     *      `static fn () => $specs` を渡す退行は型検査を通る)。**誇張しない**。
+     *      この配線が前倒し評価へ退行していないことは
+     *      `RouteMiddlewareBinderTest` の配線テスト (`routes.cached` を true に束ねて
+     *      「呼ばれたら throw する resolver」を渡す) が**振る舞いで**固定する。
+     *
+     * @param  callable(): array<string, list<string>>  $specResolver
+     *                                                                 route 名 => 付与する middleware alias の列
+     */
+    public static function attachOnBooted(Application $app, callable $specResolver): void
+    {
+        $app->booted(static function (Application $app) use ($specResolver): void {
+            self::attachAll(
+                $app->make(Router::class),
+                $specResolver,
+                $app instanceof CachesRoutes && $app->routesAreCached(),
+            );
+        });
+    }
+
+    /**
+     * named route 群へ middleware alias を後付けする (`$routesAreCached` なら何もしない)。
+     *
+     * skip 判定を**引数で受ける**ことで、判定と後付けの両方を純粋関数として検証できる
+     * ({@see attachOnBooted} が実アプリの状態を渡す唯一の配線点)。
+     *
+     * ★`RouteThrottleBinder::attachAll()` は spec を **array** で受けるが、こちらは
+     *   **resolver** で受ける。揃っていないのは意図である — あちらの spec は副作用の無い
+     *   定数表で cached 起動でも評価してよいが、こちらは feature flag 判定を含み、
+     *   「cached では resolver すら呼ばない」ことを保証したいため。
+     *
+     * @param  callable(): array<string, list<string>>  $specResolver
+     */
+    public static function attachAll(Router $router, callable $specResolver, bool $routesAreCached): void
+    {
+        if ($routesAreCached) {
+            // 後付けは route:cache 生成時に焼き込み済み。
+            // ★**resolver を呼ぶ前に**返すこと。route 解決はもちろん spec の構築にも
+            //   到達させない (到達させると T120 型の事故を再導入する余地が残る)。
+            return;
+        }
+
+        $routes = $router->getRoutes();
+        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
+        $routes->refreshNameLookups();
+
+        foreach ($specResolver() as $name => $aliases) {
+            self::attachByName($routes, $name, $aliases);
+        }
+    }
+
+    /**
+     * named route へ middleware alias 群を冪等に後付けする。
+     *
+     * @param  list<string>  $aliases
+     *
+     * @throws RuntimeException route が引けない場合 (= 無防備なまま公開される事故を止める)
+     */
+    public static function attachByName(RouteCollectionInterface $routes, string $routeName, array $aliases): void
+    {
+        $route = $routes->getByName($routeName);
+        if (! $route instanceof Route) {
+            throw new RuntimeException(
+                "middleware を後付けすべき route [{$routeName}] が見つかりません。"
+                .'vendor package が update で route 名を変えたか、feature flag が無効化された'
+                .'可能性があります (無効化が正しいなら呼び出し側の spec から外すこと)。'
+                .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
+            );
+        }
+
+        $changed = false;
+        foreach ($aliases as $alias) {
+            if (in_array($alias, $route->middleware(), true)) {
+                continue; // 冪等 (同一 bootstrap 内の重複呼び出し / 既に定義側で貼られている)
+            }
+
+            $route->middleware($alias);
+            $changed = true;
+        }
+
+        if ($changed) {
+            // ★memo の破棄 (RouteThrottleBinder と同じ作法)。
+            //   **本経路では現時点 no-op である**ことを実読で確認している
+            //   (ここは gatherMiddleware() を呼ばないため $computedMiddleware は温まらない)。
+            //   それでも置くのは、この memo を取りこぼしたときの壊れ方が
+            //   「middleware() には載るのに dispatch では実行されない = 無音の無防備」であり、
+            //   本 binder が潰そうとしている失敗形そのものだから。
+            $route->computedMiddleware = null;
+        }
+    }
+}
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 92b3c42..0e305bc 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -314,13 +314,15 @@ ### §7b 流量制限の付与規約
    受け付けるキーが限られる(Fortify は login / two-factor / passkeys / verification の 4 つだけ)ため、
    賄えない分だけ 3 に落とす
 3. **`RouteThrottleBinder::attachOnBooted()` で後付けする**(2 でも貼れない vendor route 専用)。
-   `$this->app->booted()` の中で走り、route 名が消えていれば**起動時に fail-fast** する
+   `$this->app->booted()` の中で走り、route 名が消えていれば **fail-fast** する
    (silent degradation = 無音の無防備を作らない)。付与は冪等
    (実装: `app/Support/Http/RouteThrottleBinder.php`)
-   - **`php artisan route:cache` を毎デプロイ再生成すること**。後付けは route cache 生成時
-     (`route:clear` 後の再 bootstrap) に焼き込まれ、**cached 起動では skip される**
-     (compiled route collection が booted callback より後に読まれるため参照できない)。
-     stale な route cache を残すと古い付与状態のまま起動する
+   - ⚠ **fail-fast が効くのは非 cached 起動 = `php artisan route:cache` 生成時**である
+     (「起動すれば必ず落ちる」ではない)。**cached 起動では後付けごと skip される**ため
+     route 名が消えていても静かに起動する。詳細は下の §7c
+   - **`php artisan route:cache` を毎デプロイ再生成すること**。契約の正本は
+     **下の「§7c vendor route への後付け機構と route:cache の契約」**
+     (この要件は throttle 専用ではなく、後付け機構**全体**の前提条件である)
    - 後付け側の判定は controller middleware を見ない
      (boot 中に controller を container 解決すると request scope の singleton が
       早すぎるタイミングで確定するため)。controller 側 throttle との二重付与は
@@ -440,6 +442,50 @@ ### §7b 流量制限の付与規約
 `ThrottleExemptionPremiseTest` で behavioral に固定する。
 前提が崩れたのに気づけない状態を作らない。
 
+### §7c vendor route への後付け機構と route:cache の契約
+
+vendor (Fortify / laravel-passkeys / Cashier) が登録した route へ、アプリ側が
+boot 後に middleware を後付けする経路は **2 つの binder に限られる**:
+
+| binder | 付けるもの | 呼び出し元 |
+|---|---|---|
+| `RouteThrottleBinder::attachOnBooted()` | `throttle:{limiter}` | FortifyServiceProvider / AppServiceProvider |
+| `RouteMiddlewareBinder::attachOnBooted()` | `recent-auth` / `recent-auth.on-email-change` / `ensure-login-method` / `no-store` / `throttle:passkeys` | FortifyServiceProvider / PasskeyServiceProvider |
+
+**2 つの事象を混ぜないこと**:
+
+1. **生成時**(`php artisan route:cache` 実行中)= 後付けが完全に走り、cache へ焼き込まれる。
+   `RouteCacheCommand::handle()` が先頭で `route:clear` してから **cache 無しのアプリを
+   再 bootstrap** するため、`loadRoutesFrom()` が `require` を通して対象 route が登録される。
+   route 名が消えていればここでデプロイが止まる(fail-fast が効くのはここだけ)。
+2. **起動時**(cached 起動)= 後付けは **1 本も効かない**。
+   `loadRoutesFrom()` が require を飛ばすため、**binder の callback が走る時点では**
+   対象 named route が 1 本も登録されていない(compiled routes はこの callback より
+   **後**に読まれる。「route が永久に存在しない」の意味ではない)。
+   仮に触れていても `Router::setCompiledRoutes()` が collection を新品へ丸ごと
+   差し替えるため捨てられる。ゆえに binder は明示 skip する
+   (**ここで例外を投げると `php artisan route:list` が必ず落ちる** = T120 の事故)。
+
+⇒ **運用要件: `php artisan route:cache` を毎デプロイ再生成すること。**
+   これは throttle だけの要件ではない。**2FA 秘密の露出防止 (recent-auth) /
+   passkey 削除の手段保持 (ensure-login-method) / WebAuthn challenge の no-store も
+   同じ前提条件に乗っている**。stale な route cache は古い付与状態のまま起動し、
+   **無音で保護が外れる**(実測: 剥がした cache では鮮度切れセッションの
+   2FA 秘密 GET が 409 でなく **200 で秘密を返す**、`force=true` の enable も 200、
+   `passkey.destroy` の 429 が消える)。
+
+**現状**: 本リポジトリに**デプロイ定義は存在しない**(deploy/ / terraform / k8s manifest /
+CI のデプロイ job のいずれも無い)。したがって上記は**今日は人手で守られている要件**であり、
+デプロイ基盤を作る PR が**必ず実装しなければならない要件**である(AGENTS.md の運用要件ブロック)。
+今その基盤を先回りして作らない(思考原則 2)。
+
+**新しい後付け経路を足すとき**: 必ず上記 2 binder のどちらかを通す。
+`PostBootRouteMutationInventoryTest` が deny-by-default で強制する
+(`app/` 配下で起動後に named route を名前で引くコードを allowlist 2 ファイルに限る)。
+ただしこの gate が守るのは**入口が絞られていること**までで、
+**docblock の主張が機序と一致していること**も**起動時の cache 鮮度**も検査しない
+(前者は機械照合できず、後者は本番デプロイで mtime が揃うため正しく作れない)。
+
 ## 8. 設計ドキュメントの書き方(このテンプレ上の流儀)
 
 アプリ固有機能の設計時は、両アプリで実証された運用をそのまま使う:
diff --git a/tests/Architecture/PostBootRouteMutationInventoryTest.php b/tests/Architecture/PostBootRouteMutationInventoryTest.php
new file mode 100644
index 0000000..eabf51d
--- /dev/null
+++ b/tests/Architecture/PostBootRouteMutationInventoryTest.php
@@ -0,0 +1,146 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: 起動後に route collection から named route を引いて
+ * 加工するコードは **skip 判定を引数で受ける 2 つの binder に限る**。
+ *
+ * ★止めたい具体的失敗は 1 つで、**過去に実際に起きている**:
+ *   新しい後付け経路を追加した人が cached 起動の skip 判定を書かず、
+ *   `routesAreCached()` の起動で例外を投げて `php artisan route:list` が必ず落ちる
+ *   (aicue T120。docs/TODO-closed.md の T120 行に記録)。
+ *   後付け経路はこの 1 年で 3 本増えており (T120 / T121 / T124)、4 本目が足される
+ *   確率は低くない。入口を 2 クラスに絞れば、その 2 クラスが持つ
+ *   「skip 判定を引数で受ける純粋関数」の形が自動的に効く。
+ *
+ * ★何を検査するか: `app/` 配下の PHP ファイルに現れる `getByName(` /
+ *   `refreshNameLookups(` の出現ファイルが allowlist の 2 ファイルだけであること。
+ *
+ * ★何を検査しないか (誇張しない):
+ *   - **docblock の主張が機序と一致していること**は検査しない。自然言語の主張は
+ *     機械で照合できない。ここで守れるのは「後付けの**入口**が 1 本に絞られている」までである。
+ *   - **起動時の route cache 鮮度**は検査しない。本番デプロイは全ファイルを新規展開するため
+ *     mtime が揃い、cache が古いソースから作られたかは起動時から判定できない
+ *     (「作れるが作らない」ではなく **正しく作れない**)。
+ *   - トークン走査であるため `$router->getRoutes()->{$m}(...)` のように変数越しに
+ *     組み立てる書き方は**すり抜ける**。この gate は「うっかり」を止めるものであって、
+ *     意図的な迂回を止めるものではない。
+ *
+ * ★素の文字列走査で足りる理由: 現在の出現は 3 ファイル・7 箇所のみで、
+ *   コメント中の記述を誤検出しないための `token_get_all()` 除外は**今は入れない**
+ *   (思考原則 2: 今必要なものだけ作る)。必要になってから入れる。
+ *   ただし docblock 内の記述も検出対象になるため、allowlist 外のファイルで
+ *   これらの識別子に**言及**するときは「メソッド名 + `(`」の形を避けること。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * 後付け経路の唯一の入口として許可されたファイル (repo 相対)。
+ *
+ * 増やすときは「skip 判定を引数で受ける純粋関数になっているか」を必ず review すること。
+ * これは**意図した摩擦**である。
+ *
+ * @var list<string>
+ */
+const POST_BOOT_ROUTE_MUTATION_ALLOWLIST = [
+    'app/Support/Http/RouteMiddlewareBinder.php',
+    'app/Support/Http/RouteThrottleBinder.php',
+];
+
+/**
+ * 後付けの痕跡となるトークン。
+ *
+ * @var list<string>
+ */
+const POST_BOOT_ROUTE_MUTATION_TOKENS = [
+    'getByName(',
+    'refreshNameLookups(',
+];
+
+/**
+ * `app/` 配下の PHP ファイル一覧 (repo 相対パス)。
+ *
+ * @return list<string>
+ */
+function postBootRouteMutationScanTargets(): array
+{
+    $root = base_path();
+    $files = [];
+
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)
+    );
+
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+            continue;
+        }
+
+        $absolute = $file->getRealPath();
+        if (! is_string($absolute)) {
+            continue;
+        }
+
+        $files[] = ltrim(str_replace($root, '', $absolute), '/');
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * 対象トークンを含むファイルの一覧 (repo 相対パス)。
+ *
+ * @return list<string>
+ */
+function postBootRouteMutationOffenders(): array
+{
+    $offenders = [];
+
+    foreach (postBootRouteMutationScanTargets() as $relative) {
+        $source = file_get_contents(base_path().'/'.$relative);
+        expect($source)->toBeString("読み取れないファイル: {$relative}");
+
+        foreach (POST_BOOT_ROUTE_MUTATION_TOKENS as $token) {
+            if (str_contains($source, $token)) {
+                $offenders[] = $relative;
+                break;
+            }
+        }
+    }
+
+    return $offenders;
+}
+
+test('起動後の named route 加工は 2 つの binder だけが行う (deny-by-default)', function (): void {
+    $unexpected = array_values(array_diff(postBootRouteMutationOffenders(), POST_BOOT_ROUTE_MUTATION_ALLOWLIST));
+
+    expect($unexpected)->toBe([], implode("\n", [
+        '起動後に named route を引いて加工するコードが allowlist 外にあります:',
+        '  '.implode("\n  ", $unexpected),
+        '',
+        '後付けは RouteThrottleBinder / RouteMiddlewareBinder 経由にすること。',
+        'cached 起動で例外を投げると `php artisan route:list` が必ず落ちる (T120 の事故)。',
+        '両 binder は skip 判定を引数で受ける純粋関数の形になっており、この形が回帰を防いでいる。',
+    ]));
+});
+
+/*
+ * negative control: allowlist の実装が消えたり改名されたときに、
+ * 上の検査が「対象 0 件だから green」という空振りにならないことを固定する。
+ */
+test('allowlist の 2 ファイルは実際に後付けトークンを含む (空振り green の排除)', function (): void {
+    $offenders = postBootRouteMutationOffenders();
+
+    foreach (POST_BOOT_ROUTE_MUTATION_ALLOWLIST as $allowed) {
+        // ★`toContain()` は可変長 needle を取るためメッセージ引数を持てない。
+        //   bool へ落としてから toBeTrue() で理由を書く。
+        expect(in_array($allowed, $offenders, true))->toBeTrue(
+            "allowlist の [{$allowed}] が後付けトークンを 1 つも含みません。"
+            .'実装が消えた / 改名された場合は allowlist も同時に更新すること。',
+        );
+    }
+});
diff --git a/tests/Feature/Security/RouteMiddlewareBinderTest.php b/tests/Feature/Security/RouteMiddlewareBinderTest.php
new file mode 100644
index 0000000..dcc5ae4
--- /dev/null
+++ b/tests/Feature/Security/RouteMiddlewareBinderTest.php
@@ -0,0 +1,217 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\NoStoreResponse;
+use App\Support\Http\RouteMiddlewareBinder;
+use Illuminate\Routing\RouteCollectionInterface;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * RouteMiddlewareBinder (vendor route への middleware alias 後付け) の契約テスト。
+ *
+ * 本 binder が守るのは 2 つの失敗形である:
+ *   1. **無音の無防備** — route 名が消えたのに no-op して保護が外れる
+ *      (= 非 cached では fail-fast する)
+ *   2. **cached 起動が落ちる** — cached 起動で例外を投げると `php artisan route:list` が
+ *      必ず落ちる (T120 で実際に起きた事故)。よって cached では resolver すら呼ばない
+ *
+ * 配置は姉妹の tests/Feature/Security/RouteThrottleBinderTest.php に揃える
+ * (Router facade を使うため Feature レーン。DB は触らない)。
+ */
+
+/** テスト用の router。 */
+function middlewareBinderRouter(): Router
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+
+    return $router;
+}
+
+/** name index を refresh 済みの route collection。 */
+function middlewareBinderRoutes(): RouteCollectionInterface
+{
+    $routes = middlewareBinderRouter()->getRoutes();
+    $routes->refreshNameLookups();
+
+    return $routes;
+}
+
+/** route 自身の middleware 列 (group 展開前)。 */
+function middlewareBinderMiddleware(string $name): array
+{
+    $route = middlewareBinderRoutes()->getByName($name);
+    expect($route)->not->toBeNull("route [{$name}] が登録されていない");
+
+    return $route->middleware();
+}
+
+/** 呼ばれたら必ず落ちる resolver (到達したことを振る舞いで表明するための道具)。 */
+function middlewareBinderExplodingResolver(): callable
+{
+    return static function (): array {
+        throw new RuntimeException('resolver が呼ばれました');
+    };
+}
+
+/*
+ * #1 = **T120 の恒久回帰**。
+ *
+ * cached 起動では resolver に**到達しない**ことを表明する。resolver に到達しない
+ * ⇒ route 解決にも到達しない、が 1 本で言えるため、Application の stub を作らずに
+ * 「cached 起動で落ちない」の核を純粋関数へ閉じ込められる。
+ */
+test('routesAreCached: true では resolver すら呼ばれない (T120 恒久回帰)', function (): void {
+    RouteMiddlewareBinder::attachAll(
+        middlewareBinderRouter(),
+        middlewareBinderExplodingResolver(),
+        true,
+    );
+})->throwsNoExceptions();
+
+test('routesAreCached: true では middleware が 1 本も増えない', function (): void {
+    Route::post('/mw-binder-probe/cached', fn (): string => 'ok')->name('mw-binder.cached');
+
+    $before = middlewareBinderMiddleware('mw-binder.cached');
+
+    RouteMiddlewareBinder::attachAll(
+        middlewareBinderRouter(),
+        static fn (): array => ['mw-binder.cached' => ['no-store']],
+        true,
+    );
+
+    expect(middlewareBinderMiddleware('mw-binder.cached'))->toBe($before);
+});
+
+test('routesAreCached: false で route が引けないと RuntimeException (メッセージに route 名を含む)', function (): void {
+    RouteMiddlewareBinder::attachAll(
+        middlewareBinderRouter(),
+        static fn (): array => ['mw-binder.absent-route' => ['no-store']],
+        false,
+    );
+})->throws(RuntimeException::class, 'mw-binder.absent-route');
+
+test('alias が実効列に 1 本増える', function (): void {
+    Route::post('/mw-binder-probe/plain', fn (): string => 'ok')->name('mw-binder.plain');
+
+    expect(middlewareBinderMiddleware('mw-binder.plain'))->not->toContain('no-store');
+
+    RouteMiddlewareBinder::attachAll(
+        middlewareBinderRouter(),
+        static fn (): array => ['mw-binder.plain' => ['no-store']],
+        false,
+    );
+
+    $router = middlewareBinderRouter();
+    $route = middlewareBinderRoutes()->getByName('mw-binder.plain');
+    expect($route)->not->toBeNull();
+    expect($router->gatherRouteMiddleware($route))
+        ->toContain(NoStoreResponse::class);
+});
+
+test('2 回呼んでも 1 本のまま (冪等)', function (): void {
+    Route::post('/mw-binder-probe/idempotent', fn (): string => 'ok')->name('mw-binder.idempotent');
+
+    $resolver = static fn (): array => ['mw-binder.idempotent' => ['no-store']];
+
+    RouteMiddlewareBinder::attachAll(middlewareBinderRouter(), $resolver, false);
+    RouteMiddlewareBinder::attachAll(middlewareBinderRouter(), $resolver, false);
+
+    $middleware = middlewareBinderMiddleware('mw-binder.idempotent');
+    expect(array_values(array_filter($middleware, fn (string $m): bool => $m === 'no-store')))
+        ->toHaveCount(1);
+});
+
+/*
+ * 列の順に append することは Passkey の順序契約 (throttle → recent-auth → 手段保持) の土台。
+ * ここが崩れると PasskeyRouteProtectionTest の index 比較が落ちる。
+ */
+test('列の順に append される', function (): void {
+    Route::post('/mw-binder-probe/ordered', fn (): string => 'ok')->name('mw-binder.ordered');
+
+    RouteMiddlewareBinder::attachAll(
+        middlewareBinderRouter(),
+        static fn (): array => [
+            'mw-binder.ordered' => ['throttle:passkeys', 'recent-auth', 'ensure-login-method'],
+        ],
+        false,
+    );
+
+    $middleware = middlewareBinderMiddleware('mw-binder.ordered');
+    $indexes = array_map(
+        static fn (string $alias): int|false => array_search($alias, $middleware, true),
+        ['throttle:passkeys', 'recent-auth', 'ensure-login-method'],
+    );
+
+    expect($indexes[0])->toBeInt();
+    expect($indexes[1])->toBeInt();
+    expect($indexes[2])->toBeInt();
+    expect($indexes[0])->toBeLessThan($indexes[1]);
+    expect($indexes[1])->toBeLessThan($indexes[2]);
+});
+
+/*
+ * ★memo を破棄しなければ「middleware() には載っているのに dispatch では実行されない」
+ *   = **無音の無防備**になる。本 binder が潰そうとしている失敗形そのもの。
+ */
+test('付与後に computedMiddleware が破棄されている', function (): void {
+    Route::post('/mw-binder-probe/memoized', fn (): string => 'ok')->name('mw-binder.memoized');
+
+    $router = middlewareBinderRouter();
+    $route = middlewareBinderRoutes()->getByName('mw-binder.memoized');
+    expect($route)->not->toBeNull();
+
+    // 先に実効列を確定させる (memo を温める) = 後付け前に覗く状況の再現
+    $router->gatherRouteMiddleware($route);
+
+    RouteMiddlewareBinder::attachAll(
+        $router,
+        static fn (): array => ['mw-binder.memoized' => ['no-store']],
+        false,
+    );
+
+    expect($router->gatherRouteMiddleware($route))
+        ->toContain(NoStoreResponse::class);
+});
+
+test('変更が無いときは既存 route を壊さない', function (): void {
+    Route::post('/mw-binder-probe/unchanged', fn (): string => 'ok')
+        ->middleware('no-store')
+        ->name('mw-binder.unchanged');
+
+    $before = middlewareBinderMiddleware('mw-binder.unchanged');
+
+    RouteMiddlewareBinder::attachAll(
+        middlewareBinderRouter(),
+        static fn (): array => ['mw-binder.unchanged' => ['no-store']],
+        false,
+    );
+
+    expect(middlewareBinderMiddleware('mw-binder.unchanged'))->toBe($before);
+});
+
+/*
+ * #8 / #8b = **配線 (attachOnBooted) の振る舞い固定**。
+ *
+ * `Illuminate\Foundation\Application::routesAreCached()` は **まず container binding
+ * `routes.cached` を見る**ため、`app()->instance('routes.cached', true)` で cached 起動を
+ * 再現できる (Application の stub も route cache ファイルの生成も要らない)。
+ * また `Application::booted()` は `isBooted()` なら **その場で callback を発火する**ので、
+ * テスト内で `attachOnBooted()` を呼べば同期的に配線が走る。
+ *
+ * #8 だけだと「配線が死んでいるから green」でも通ってしまうため、
+ * #8b (negative control) と**必ずセットで**扱うこと。
+ */
+test('attachOnBooted() は cached 起動で resolver を呼ばない (配線の固定)', function (): void {
+    app()->instance('routes.cached', true);
+
+    RouteMiddlewareBinder::attachOnBooted(app(), middlewareBinderExplodingResolver());
+})->throwsNoExceptions();
+
+test('negative control: 非 cached なら attachOnBooted() は resolver を実際に呼ぶ', function (): void {
+    app()->instance('routes.cached', false);
+
+    RouteMiddlewareBinder::attachOnBooted(app(), middlewareBinderExplodingResolver());
+})->throws(RuntimeException::class, 'resolver が呼ばれました');

```

---

## 検証結果 (すべて実測)

- `composer phpstan` (level 10): **OK / No errors** (821 files)
- `composer test`: **3885 tests, 3883 passed, 2 skipped, 0 failed**
- `vendor/bin/pint --test`: passed / `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test`: 128 files / 1268 passed。`pnpm test:packages`: 10 files / 106 passed
- `pnpm typecheck:packages` / `pnpm build:packages`: passed
- `composer test:browser`: chromium 11 passed / 3 skipped、webkit 11 passed / 3 skipped

### 受入条件の実測 (T120 の再発確認)

```
$ ls bootstrap/cache/routes-v7.php   # 実行前 = 無い
No such file or directory
$ php artisan route:cache            # INFO Routes cached successfully.
$ php artisan route:list             # exit 0 / 例外なし  ← T120 ではここが必ず落ちた
$ php artisan route:list --name=two-factor.qr-code -v
 ⇂ web
 ⇂ Illuminate\Auth\Middleware\Authenticate:web
 ⇂ App\Http\Middleware\RequireRecentAuth              ← cached 起動でも保護が載っている
 ⇂ Illuminate\Routing\Middleware\ThrottleRequests:two-factor-secret-read
$ php artisan route:clear            # 元の状態 (cache 無し) へ復帰
```

### mutation 実測 (新設テストが空振り green でないことの確認)

| mutation | 結果 |
|---|---|
| `PostBootRouteMutationInventoryTest` の allowlist を空にする | **fail**: 2 binder が offender として列挙される (deny 枝が生きている) |
| `RouteMiddlewareBinder::attachAll()` の `if ($routesAreCached)` を `if (false && $routesAreCached)` にする (= T120 の再現) | **fail 3 本**: 「resolver すら呼ばれない (T120 恒久回帰)」/「middleware が 1 本も増えない」/「attachOnBooted() は cached 起動で resolver を呼ばない」 |

mutation はすべて即座に戻し、最終差分に 1 つも残っていないことを確認済み。

### 振る舞い不変の証拠

既存の route 保護系テスト (`RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` /
`PasskeyRouteProtectionTest` / `PasswordConfirmMiddlewareAbsenceTest` /
`LoginMethodRemovalRouteTest` / `PasskeyPackageContractTest` / `ThrottleCoverageInventoryTest` /
`RouteThrottleBinderTest`) と `app/Support/Http/RouteThrottleBinder.php` は
**1 行も変更していない** (`git diff HEAD --stat` が空)。それらが差分なしで green である。
