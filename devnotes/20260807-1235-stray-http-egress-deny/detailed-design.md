# 詳細設計: stray-http-egress-deny

> lctl feature id: `external-egress-default-deny` / 裁定 AG-105 (2026-08-06) 必須 1 点への準拠。
> 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex conceptual-review Round 1 で APPROVED)
> 実査ブリーフ: [`recon-brief.md`](./recon-brief.md)

## レビュー状況 (正直に記録する)

| ラウンド | 判定 | 内訳 | 対応 |
|---|---|---|---|
| 概念設計 Round 1 | **APPROVED** | Critical 0 / Warning 5 / Suggestion 4 | 全件対応 (`codex-history/conceptual-review-decisions-round-1.md`) |
| 詳細設計 Round 1 | CHANGES_REQUESTED | Critical 0 / Warning 4 / Suggestion 2 | 全件対応 |
| 詳細設計 Round 2 | CHANGES_REQUESTED | Critical 0 / Warning 3 / Suggestion 2 | 全件対応 |
| 詳細設計 Round 3 | CHANGES_REQUESTED | Critical 0 / Warning 1 / Suggestion 1 | 全件対応 |
| 詳細設計 Round 4 | CHANGES_REQUESTED | Critical 0 / Warning 1 / Suggestion 2 | 全件対応 (S4 の解析基盤を PhpToken 列へ全面刷新) |
| 詳細設計 Round 5 | CHANGES_REQUESTED | Critical 0 / Warning 1 / Suggestion 2 | 全件対応 (S4 の波括弧深度に補間開始トークンを追加) |
| 詳細設計 Round 6 (確認) | **APPROVED** | Critical 0 / Warning 0 / Suggestion 0 | Round 5 の修正が解消しているかの再判定。新規指摘なし |

**Critical はどのラウンドでもゼロ**。Warning / Suggestion は全 20 件を反論・見送りゼロで対応し、
Round 6 の確認ラウンドで **APPROVED** を得た。

> **Round 6 で自ら見つけて直した点 (Round 5 の指摘の前提事実の誤り)**:
> Round 5 は「`T_CURLY_OPEN` の `text` は `"{$"` だから `text === '{'` に一致しない」と述べたが、
> PHP 8.4.24 の実測では **`T_CURLY_OPEN` の `text` は `"{"`** であり、実際に深度が壊れるのは
> **`T_DOLLAR_OPEN_CURLY_BRACES` (`text` = `"${"`) の側だけ**だった。
> したがって Round 5 が提示した単体テスト入力 (`"{$json}"` 形) は**修正前の実装でも緑になる
> 空振りテスト**であり、そのまま採用すると本設計の一貫方針 (空振り gate を緑にしない) に反する。
> 修正の方向 (id 判定の追加) は採用しつつ、**回帰入力を `"${json}"` 形 + `"{$json}"` 形の
> 2 本立てに強化**し、負のコントロールの補間 fixture にも `"${json}"` 形を追加した。
> この反証は Round 6 プロンプト §3 で検算を求め、Codex は「事実認定は妥当・帰結も正しい・
> 対応は適切」と回答している。

指摘の内容と対応根拠は `codex-history/design-review-decisions-round-{1..6}.md` が正本。

## 使命・制約（絶対遵守）

### アプリの使命（North Star） — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### 思考原則 — AGENTS.md より転記

1. **フレームワークのレンジ内でやる** 2. **今必要なものだけ作る** 3. **後方互換の並走を残さない**
4. **別物の概念を「似ているから」で統合しない** 5. **テストファースト** 6. **タコツボ実装を避ける**

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
  - ⚠ **本設計の変更ファイルはすべて `tests/` 配下** であり、`phpstan.neon` の `paths` は
    `app` / `config` / `database` / `routes` **のみ**。よって新規テストコードは PHPStan の
    解析対象に**入らない**。「PHPStan が通る」を実装完了の根拠にできないため、
    各施策の「PHPStan 適合チェック」は**手動レビュー用チェックリスト**として扱い、
    型注釈は解析対象に入っているかのように厳密に書く (将来 `paths` に `tests` が
    足されたときに無改修で通る状態を維持する)。`AGENTS.md` / `docs/` は対象外。
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
  → 本設計のテストは**モデルを一切作らない** (guard は HTTP 出口の話であり DB に触れない)。
    Factory 追加も新モデル追加も無し。
- **DTO + JsonResource** パターン → 本設計は HTTP レスポンスを新設しないため非該当
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 系互換 API（実体は laravel/framework ^13.8）+ Svelte 5 + Inertia.js + TypeScript
- 日本語コメント + `declare(strict_types=1)`
- PCRE パターンに `\R` を使う場合 `/u` 必須（`PcreUnicodeModifierGateTest` が deny-by-default で強制）

---

## 前提として確認した vendor 実装 (実コードを読んで確認済み)

本設計は laravel/framework ^13.8 の以下の挙動に依存する。**すべて S2 の自己検査で
behavioral に固定する**ので、framework 更新で崩れたら CI が赤くなる。

| # | 事実 | 出典 |
|---|------|------|
| P1 | `Factory` は container の singleton (`HttpFactory::class => HttpFactory::class`)。`Http` facade の accessor も `Factory::class` なので、facade と DI (`AwsSnsSignatureVerifier` の `Illuminate\Http\Client\Factory` 注入) は**同一インスタンス** | `FoundationServiceProvider.php:57` / `Facades/Http.php:115-118` |
| P2 | `Factory::createPendingRequest()` が `stub` / `preventStrayRequests` / `allowStrayRequests` を PendingRequest へ伝播する | `Factory.php:583-590` |
| P3 | `Factory::fake()` は `preventStrayRequests` / `allowedStrayRequestUrls` を **reset しない** (`record()` と `stubCallbacks` しか触らない) → 「レーン既定 ON + 各テストの局所 `Http::fake`」が無改修で共存する | `Factory.php:309-` |
| P4 | `PendingRequest::pushHandlers()` は globalMiddleware → beforeSending → recorder → **stub handler の順に push**。Guzzle `HandlerStack::resolve()` は `array_reverse` して包むので、**最初に push された globalMiddleware が最外側**、stub handler が最内側 | `PendingRequest.php:1682-1692` |
| P5 | stub handler は stub 未登録でも常時 push され、マッチする stub が無く `isAllowedRequestUrl()` が false のとき `throw new StrayRequestException(...)` を **同期 throw** する (promise rejection ではない) | `PendingRequest.php:1692, 1755-1759` |
| P6 | `StrayRequestException extends RuntimeException` (≠ `TransferException`)。`send()` の `catch (TransferException)` に掛からず、`makePromise()` も `if ($e instanceof StrayRequestException) { throw $e; }` で ConnectionException 化から除外している | `StrayRequestException.php` / `PendingRequest.php:1090, 1201-1203` |
| P7 | `Factory::allowStrayRequests(?array $only)` は **null で prevent 自体を OFF**、配列で `array_values($only)` に**置換**する (merge しない) | `Factory.php:429-445` |
| P8 | `PendingRequest::isAllowedRequestUrl()` は `Str::is($pattern, $url)` の glob 判定 | `PendingRequest.php:1919-1935` |
| P9 | Browser lane の in-process サーバは `ServerManager::DEFAULT_HOST` で**常に 127.0.0.1 に bind** し、boot 時に `config(['app.url' => $url])` を**テスト実行中に書き換える** | `pest-plugin-browser/src/ServerManager.php:87-90` / `Drivers/LaravelHttpServer.php:153` |
| P10 | `Tests\TestCase` は `Illuminate\Foundation\Testing\TestCase` を継承。Architecture lane も `pest()->extend(TestCase::class)` で Laravel app 上を走る (`RefreshDatabase` を使っていないだけ)。各テストの `setUp()` が `refreshApplication()` するので **Factory は毎テスト新品** | `tests/TestCase.php` / `tests/Pest.php:65-69` |

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | `StrayHttpRequestGuard` の新設 (prevent + loopback allow + globalMiddleware accumulator) | `tests/Support/StrayHttpRequestGuard.php` (新規) | Critical |
| S2 | guard の自己検査 (framework 前提を behavioral に固定) | `tests/Feature/Support/StrayHttpRequestGuardTest.php` (新規) | Critical |
| S3 | 3 レーンへの既定配線 | `tests/Pest.php` (変更) | Critical |
| S4 | deny-by-default 目録型 Architecture gate | `tests/Architecture/StrayHttpEgressLaneGateTest.php` (新規) / `tests/Support/Security/StrayHttpEgressExemption.php` (新規) | High |
| S5 | 既存記述の是正 (棄却理由コメント / 局所 prevent の位置づけ / AGENTS.md / docs) | `tests/Feature/Auth/RegistrationTest.php` / `tests/Feature/Security/ThrottleExemptionPremiseTest.php` / `tests/Feature/Security/AuthThrottleCoverageTest.php` / `AGENTS.md` / `docs/testing-browser.md` (変更) | High |
| S6 | 初回導入で赤化する既存テストの是正 (`Http::fake` の追加) | 実行して判明したテストファイル (下記候補集合) | High |

**実装順序 (テストファースト / 思考原則 5)**: S2 (赤を確認) → S1 → S3 → S6 → S4 → S5。

---

## S1: `StrayHttpRequestGuard` の新設

### 変更箇所

- ファイル: `tests/Support/StrayHttpRequestGuard.php` (**新規**)

### 波及変更

- TypeScript 型定義: **なし** (PHP テスト基盤のみ)
- Inertia Props インターフェース: **なし**
- API Resource / DTO: **なし** (HTTP レスポンスを新設しない)
- テストファイル: `tests/Feature/Support/StrayHttpRequestGuardTest.php` (S2 で新規)、
  `tests/Pest.php` (S3 で配線)、`tests/Architecture/StrayHttpEgressLaneGateTest.php` (S4 で強制)
- アプリコード (`app/`): **なし** (禁止。本設計はアプリを 1 行も変えない)
- `composer.json` / 依存: **なし** (新規パッケージゼロ)

### 現行コード

存在しない (新規)。参照する既存の同型実装は `tests/Support/StrayLlmCallGuard.php`
(`install()` / `flushAndFailIfStray()` / `reset()` / `drainForAssertion()` の 4 点セット)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\StrayRequestException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * テストレーンの HTTP 出口を既定拒否にし、握り潰されても検出できるようにする guard。
 *
 * 裁定 AG-105 の必須要件 (テストレーンの既定として preventStrayRequests を常時有効化 +
 * 自機宛て loopback の明示許可) の実装。設計は devnotes/20260807-1235-stray-http-egress-deny/。
 *
 * 仕組み:
 *  1. install() で Http Factory に preventStrayRequests + allowStrayRequests(loopback) を張る。
 *  2. 同じ Factory の globalMiddleware に **自分自身** (invokable) を 1 本積む。
 *     globalMiddleware は Guzzle handler stack の**最外側**に来る (stub handler は最内側) ため、
 *     stub handler が同期 throw する StrayRequestException を確実に観測できる。
 *  3. 観測した stray を static accumulator に記録してから **再 throw** する
 *     (フレームワークの既定挙動を変えない)。
 *  4. tests/Pest.php の afterEach が flushAndFailIfStray() を呼び、記録があれば test を fail させる。
 *
 * ★4 が本 guard の存在意義。FxRateService::fetchFromFrankfurter は catch (Throwable) で、
 *   AwsSnsSignatureVerifier::certClient は catch (\Throwable) で例外を握り潰すため、
 *   preventStrayRequests **だけ**では「fx_snapshot が null になる」等の挙動変化に化けて
 *   テストが静かに緑のまま通る。accumulator があれば必ず赤くなる
 *   (StrayLlmCallGuard で既に学習済みの失敗を繰り返さない)。
 *
 * **保証範囲**: Laravel HTTP client (`Illuminate\Http\Client`) 経由の出口**のみ**。
 * 同一プロセス内でしか効かない。Socialite (Guzzle 直) / Stripe SDK / AWS SDK /
 * Playwright ブラウザ自身の fetch / bug-hunt の別プロセス実行は**対象外**。
 * さらに許可判定は**名前解決前の URL 文字列**照合なので、hosts / DNS の健全性
 * (`localhost` が loopback に解決されること) は**保証しない** — それは前提である。
 */
final class StrayHttpRequestGuard
{
    /**
     * 自機宛て loopback の明示許可パターン (単一 source of truth)。
     *
     * ★`config('app.url')` の host は**含めない**。理由:
     *  (1) Browser lane の in-process サーバは常に 127.0.0.1 に bind する
     *      (pest-plugin-browser ServerManager::DEFAULT_HOST) ので loopback リテラルで足りる。
     *  (2) その in-process サーバは boot 時に config('app.url') を**実行中に書き換える**ため、
     *      beforeEach 時点の snapshot は Browser lane で古い値になる。
     *  (3) APP_URL は環境依存 (.env は http://aicue.test、.env.example は http://localhost)。
     *      許可集合を環境依存にすると Architecture gate が固定値を検査できず、
     *      「開発者の .env 次第で外部ドメインが許可される」穴になる。
     *
     * ★`localhost` / `[::1]` を残す理由 (127.0.0.1 だけで足りるのでは、への回答):
     *   Browser lane の in-process サーバは 127.0.0.1 で足りるが、テスト本体や将来の
     *   fake 基盤が `localhost` 表記の自機 URL を組み立てることは普通に起きる
     *   (config/mcp.php の allowed origins も `http://localhost` / `http://127.0.0.1` の
     *    両方を持つ)。表記揺れで偽赤を出すコストの方が大きいので 3 ホストを持つ。
     *
     *   ★ただし判定機構の保証を誇張しない: `PendingRequest::isAllowedRequestUrl()` は
     *   **名前解決前の URL 文字列**に対する `Str::is()` 照合である。したがって
     *   `localhost` が外部 IP へ解決される環境では、この許可を通ったうえで
     *   **実際に外部へ送信されうる**。つまり本 guard は
     *   「`localhost` はテスト実行環境で loopback に解決される」を**前提として置いている**
     *   だけであり、hosts / DNS の健全性は保証しない (保証範囲の注記にも明記する)。
     *   その前提を置けないホスト名 (`aicue.test` のような任意のカスタムドメイン) は
     *   **入れない** — 解決先の前提が置けず、許可集合も環境依存になるため。
     *
     * ★末尾ワイルドカード 1 本 (`http://127.0.0.1*`) にはしない。
     *   Str::is() の glob では `http://127.0.0.1.evil.example/` まで通ってしまう。
     *   「ポート無し」「:ポート」「/パス」の 3 形で 1 ホストを覆う。
     *
     * @var list<non-empty-string>
     */
    public const ALLOWED_URL_PATTERNS = [
        'http://127.0.0.1',
        'http://127.0.0.1/*',
        'http://127.0.0.1:*',
        'https://127.0.0.1',
        'https://127.0.0.1/*',
        'https://127.0.0.1:*',
        'http://localhost',
        'http://localhost/*',
        'http://localhost:*',
        'https://localhost',
        'https://localhost/*',
        'https://localhost:*',
        'http://[::1]',
        'http://[::1]/*',
        'http://[::1]:*',
        'https://[::1]',
        'https://[::1]/*',
        'https://[::1]:*',
    ];

    /** @var list<array{method: string, url: string}> */
    private static array $strayRequests = [];

    /**
     * Pest beforeEach から呼ぶ。前テストの残留を clear したうえで guard を install する。
     *
     * 各テストの setUp() が refreshApplication() するため Factory は毎テスト新品だが、
     * 「同一テスト内で 2 回呼ばれる」「将来 refreshApplication を経ないレーンが増える」
     * ケースで middleware が二重登録されると同じ stray を 2 件記録してしまうので、
     * **冪等**にしておく (Codex conceptual-review Round 1 の Warning)。
     */
    public static function install(Application $app): void
    {
        self::$strayRequests = [];

        $factory = $app->make(HttpFactory::class);

        // 既定拒否 + loopback だけを明示許可。allowStrayRequests(array) は置換なので、
        // ここが許可集合の唯一の設定点になる。
        $factory->preventStrayRequests();
        $factory->allowStrayRequests(self::ALLOWED_URL_PATTERNS);

        /** @var mixed $middleware */
        foreach ($factory->getGlobalMiddleware() as $middleware) {
            if ($middleware instanceof self) {
                return; // 既に積まれている (冪等)
            }
        }

        $factory->globalMiddleware(new self);
    }

    /**
     * Pest afterEach から呼ぶ。stray が記録されていれば RuntimeException を throw して
     * test を fail させる。アプリ側の try/catch で例外が握り潰されても、このパスで必ず赤くなる。
     *
     * accumulator は finally で必ず clear する (プロセス内の後続テストへの二次被害を防ぐ)。
     */
    public static function flushAndFailIfStray(): void
    {
        try {
            if (self::$strayRequests === []) {
                return;
            }
            throw new RuntimeException(
                'Stray outbound HTTP request detected during test execution. '
                .'Did you forget to call Http::fake([...]) in the test body? '
                .'(test lanes deny outbound HTTP by default; only loopback is allowed)'
                .PHP_EOL.self::summarize(self::$strayRequests)
            );
        } finally {
            self::$strayRequests = [];
        }
    }

    /**
     * accumulator を空に戻す。afterEach の finally から呼び、flushAndFailIfStray() が
     * throw した場合でも次テストへ残留を漏らさないことを保証する。
     */
    public static function reset(): void
    {
        self::$strayRequests = [];
    }

    /**
     * self-test 用 drain。意図的に stray を発生させるテストで、global afterEach に
     * 到達する前に accumulator を取り出して clear する。
     *
     * @return list<array{method: string, url: string}>
     */
    public static function drainForAssertion(): array
    {
        $drained = self::$strayRequests;
        self::$strayRequests = [];

        return $drained;
    }

    /**
     * Guzzle global middleware 本体。handler stack の最外側に置かれ、
     * 最内側の stub handler が同期 throw する StrayRequestException を観測する。
     *
     * ★`->otherwise()` (promise rejection) ではなく **try/catch** で捕える。
     *   stub handler は promise を reject するのではなく同期 throw するため
     *   (PendingRequest::buildStubHandler)。async / pool 経路でも、Guzzle Client が
     *   rejection 化するのは本 middleware より**外側**なので try/catch で捕まる。
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): mixed {
            try {
                return $handler($request, $options);
            } catch (StrayRequestException $e) {
                self::$strayRequests[] = [
                    'method' => $request->getMethod(),
                    'url' => (string) $request->getUri(),
                ];

                // フレームワークの既定挙動を変えない (記録するだけで握り潰さない)
                throw $e;
            }
        };
    }

    /**
     * @param  list<array{method: string, url: string}>  $requests
     */
    private static function summarize(array $requests): string
    {
        $lines = [];
        foreach ($requests as $i => $request) {
            $lines[] = sprintf('  [%d] %s %s', $i + 1, $request['method'], $request['url']);
        }

        return implode(PHP_EOL, $lines);
    }
}
```

> `$options` は Guzzle の転送オプション連想配列。PHPStan の
> `checkMissingIterableValueType` に備え、実装時は closure シグネチャ直前に
> `/** @param array<string, mixed> $options */` を付す
> (無名関数の param docblock。Pint の設定と衝突しないことを `composer fix` で確認する)。

### PHPStan 適合チェック

> ⚠ `phpstan.neon` の `paths` に `tests` は含まれないため、実際には解析されない。
> 以下は**手動レビュー用チェックリスト**であり、将来 `tests` が対象化されても
> 無改修で通る状態を維持するために満たす。

- [x] 戻り値の型が全メソッドに明示されている (`void` / `array` / `Closure` / `string`)
- [x] `list<array{method: string, url: string}>` の shape で accumulator を型付け
- [x] `const ALLOWED_URL_PATTERNS` に `@var list<non-empty-string>`
- [x] `getGlobalMiddleware()` は vendor 側が素の `array` 返しなので、`foreach` の値に
      `/** @var mixed $middleware */` を付け `instanceof` で narrowing する
      (型を widen して黙らせているのではなく、vendor の型情報が無いところで
       正しく `mixed` から絞っている)
- [x] closure の `array $options` に `@param array<string, mixed>` を付ける
- [x] `null` 安全: null を扱う分岐が無い (Assert 不要)
- [x] DTO 返却は非該当 (テスト基盤クラス。HTTP レスポンスを作らない)
- [x] Generics: 非該当
- [x] `@phpstan-ignore-line` / baseline を使わない (禁止事項 2)

### テスト計画

S2 が本施策の検査を全面的に担う (下記)。S1 単体のテストは持たない
(guard は S2 の自己検査で behavioral に固定するのが本設計の骨格)。

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| framework 更新で handler stack の push 順が変わり、globalMiddleware が stub handler より内側になる | stray を観測できず accumulator が空 = 偽グリーン | S2 case E (握り潰し貫通) が behavioral に固定する。順序が変わった瞬間に赤くなる |
| `Http::fake()` が将来 prevent flag を reset するようになる | レーン既定が各テストの `Http::fake()` で無効化される | S2 case B が「fake 併用時も未 fake URL は stray になる」ことを固定する |
| `->retry(n)` を使う呼び出しで同じ stray が n 件記録される | 失敗メッセージが冗長になるだけ | 仕様として受容 (件数を summarize に出す)。dedupe は「今必要なものだけ作る」に反するため入れない |
| middleware が同一プロセスで積み上がる | 同じ stray を複数記録 | `install()` の冪等化 + S2 case G |

---

## S2: guard の自己検査 (framework 前提の behavioral 固定)

### 変更箇所

- ファイル: `tests/Feature/Support/StrayHttpRequestGuardTest.php` (**新規**)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 本ファイル自体が新規テスト。既存テストの更新は**なし**
  (`tests/Feature/Support/StrayLlmCallGuardTest.php` は触らない)

### 現行コード

存在しない (新規)。同型の見本は `tests/Feature/Support/StrayLlmCallGuardTest.php` (115 行、case A〜F)。

### 変更後コード (骨格)

```php
<?php

declare(strict_types=1);

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\StrayHttpRequestGuard;

/**
 * StrayHttpRequestGuard の self-test。
 *
 * 各テスト末尾で StrayHttpRequestGuard::drainForAssertion() を呼んで accumulator を
 * 空にしておかないと、tests/Pest.php の global afterEach (flushAndFailIfStray()) が
 * test 自身を fail させてしまう (StrayLlmCallGuardTest と同じ作法)。
 *
 * 本ファイルは laravel/framework の内部挙動 (handler stack の push 順 / 同期 throw /
 * fake() が prevent flag を保つこと) に対する**契約テスト**でもある。
 * framework 更新でこれらが崩れたらここが赤くなる。
 */

/**
 * ★本ファイルは tests/Pest.php のレーン既定に**依存しない**。
 *
 * 理由 (Codex design-review Round 1 の Warning):
 *  (1) 実装順序が S2 (自己検査) → S1 (guard) → S3 (レーン配線) なので、S3 の前に
 *      本ファイルを走らせる局面が必ずある。そのとき guard 未 install だと
 *      case A の `Http::get('https://api.frankfurter.dev/...')` が**実通信に進む**。
 *      これは「guard を作る作業のために外部へ出る」という本末転倒である。
 *  (2) 自己検査は「guard が install されていれば何が起きるか」の契約テストであり、
 *      その前提を自分で用意する方がテストとして自己完結する。
 *  S3 適用後は二重 install になるが、install() は冪等 (case G が固定する)。
 */
beforeEach(function (): void {
    StrayHttpRequestGuard::install($this->app);
});

// ↓ 以下は擬似コード (テスト名の一覧)。実体は本節の後半に示す。
beforeEach(...);  // ← 本ファイル自身で guard を install する (レーン既定に依存しない)
test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', ...);
test('case B: Http::fake([限定 URL]) 併用時、fake 対象は透過し未 fake の別 URL は stray になる', ...);
test('case C: Http::fake([*]) を張れば stray にならない', ...);
test('case D: loopback (127.0.0.1) は stray にならない (stray 判定を通過して送信段まで進む)', ...);
test('case E: アプリ層の catch (Throwable) で握り潰しても accumulator に残る', ...);
test('case F: flushAndFailIfStray() は accumulator 非空で throw し finally で clear する', ...);
test('case G: install() は冪等 (2 回呼んでも 1 stray に対し記録は 1 件)', ...);
test('case H: 許可パターンは loopback ホストだけに一致する (127.0.0.1.evil.example を弾く)', ...);
test('case I: async 経路でも stray が accumulator に記録される', ...);
```

主要ケースの中身:

```php
test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', function (): void {
    // ★本ケースは「**無引数** Http::preventStrayRequests() が拒否を有効化する」という
    //   vendor の既定引数の意味に対する契約テストでもある (guard は無引数で呼ぶ)。
    //   将来 framework が既定値を反転させたらここが赤くなる
    //   (Codex design-review Round 2 の指摘への対応)。
    $threw = false;
    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (StrayRequestException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('api.frankfurter.dev');
    }

    expect($threw)->toBeTrue('レーン既定の preventStrayRequests が効いていない');

    $drained = StrayHttpRequestGuard::drainForAssertion();
    expect($drained)->toHaveCount(1)
        ->and($drained[0]['method'])->toBe('GET')
        ->and($drained[0]['url'])->toContain('api.frankfurter.dev');
});

test('case B: Http::fake([限定 URL]) 併用時、fake 対象は透過し未 fake の別 URL は stray になる', function (): void {
    // 既存テストの大半 (pwnedpasswords 限定 fake など) がこの形。
    // ★これが「レーン既定 ON と局所 Http::fake は無改修で共存する」の behavioral な根拠。
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    $ok = Http::get('https://api.pwnedpasswords.com/range/AAAAA');
    expect($ok->status())->toBe(200);
    expect(StrayHttpRequestGuard::drainForAssertion())->toBe([]);

    $threw = false;
    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (StrayRequestException) {
        $threw = true;
    }
    expect($threw)->toBeTrue('fake を張ると prevent フラグが reset される = framework 前提が崩れた');
    expect(StrayHttpRequestGuard::drainForAssertion())->toHaveCount(1);
});

test('case D: loopback (127.0.0.1) は stray にならない (stray 判定を通過して送信段まで進む)', function (): void {
    // ★固定ポート (9 = discard 等) は「閉じている」前提が環境依存で flaky になる
    //   (Codex design-review Round 1 の Warning)。OS に一時ポートを割り当てさせ、
    //   すぐ close して「ほぼ確実に待ち受けが無いポート」を得る。
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($probe)->not->toBeFalse("一時ポートを確保できない: {$errstr} ({$errno})");
    /** @var resource $probe */
    $name = stream_socket_get_name($probe, false);
    expect($name)->toBeString();
    /** @var string $name */
    $port = (int) Str::afterLast($name, ':');
    fclose($probe);

    $caught = null;
    try {
        Http::connectTimeout(1)->timeout(1)->get("http://127.0.0.1:{$port}/health");
    } catch (Throwable $e) {
        $caught = $e;
    }

    // ★assert の主眼は「StrayRequestException **ではない**」こと =
    //   許可判定を通過して実際の送信段まで進んだことの behavioral な証明。
    //   接続結果 (refuse / timeout / 何かが listen していて 200) は環境依存なので固定しない。
    expect($caught)->not->toBeInstanceOf(StrayRequestException::class);
    expect(StrayHttpRequestGuard::drainForAssertion())->toBe([]);
});

test('case E: アプリ層の catch (Throwable) で握り潰しても accumulator に残る', function (): void {
    // FxRateService::fetchFromFrankfurter / AwsSnsSignatureVerifier::certClient を再現。
    // ★本 guard の存在意義そのもの。preventStrayRequests 単体ではここが静かに緑になる。
    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (Throwable) {
        // swallow (production の可用性設計を模す)
    }

    expect(StrayHttpRequestGuard::drainForAssertion())
        ->toHaveCount(1, '握り潰されても accumulator は記録する (= afterEach で必ず赤くなる)');
});

test('case G: install() は冪等 (2 回呼んでも 1 stray に対し記録は 1 件)', function (): void {
    StrayHttpRequestGuard::install($this->app);
    StrayHttpRequestGuard::install($this->app);

    try {
        Http::get('https://api.frankfurter.dev/v1/latest');
    } catch (Throwable) {
        // swallow
    }

    expect(StrayHttpRequestGuard::drainForAssertion())->toHaveCount(1);
});

test('case H: 許可パターンは loopback ホストだけに一致する', function (): void {
    $matches = static function (string $url): bool {
        foreach (StrayHttpRequestGuard::ALLOWED_URL_PATTERNS as $pattern) {
            if (Str::is($pattern, $url)) {
                return true;
            }
        }

        return false;
    };

    // 通すべきもの
    expect($matches('http://127.0.0.1'))->toBeTrue();
    expect($matches('http://127.0.0.1:8010/x/y?z=1'))->toBeTrue();
    expect($matches('http://localhost/health'))->toBeTrue();
    expect($matches('http://[::1]:8080/x'))->toBeTrue();

    // 通してはいけないもの (末尾ワイルドカード 1 本にしていたら全部 true になる)
    expect($matches('http://127.0.0.1.evil.example/'))->toBeFalse();
    expect($matches('http://localhost.evil.example/'))->toBeFalse();
    expect($matches('https://api.frankfurter.dev/v1/latest'))->toBeFalse();
    expect($matches('http://169.254.169.254/latest/meta-data/'))->toBeFalse();
});

test('case I: async 経路でも stray が accumulator に記録される', function (): void {
    // Guzzle Client::requestAsync は同期 throw を rejection 化するが、それは本 middleware
    // より外側で起きるため try/catch で捕まる。async を使う呼び出しが将来増えたときに
    // 静かに素通りしないことを固定する。
    try {
        Http::async()->get('https://api.frankfurter.dev/v1/latest')->wait();
    } catch (Throwable) {
        // swallow
    }

    expect(StrayHttpRequestGuard::drainForAssertion())->not->toBe([]);
});
```

### PHPStan 適合チェック

> ⚠ `tests/` は `phpstan.neon` の `paths` 外 (再掲)。以下は手動チェックリスト。

- [x] 各 `test()` closure に `: void` を明示
- [x] `Throwable` を `use` せずグローバル参照する場合は `\Throwable` を書かない
      (`NoNonCompoundGlobalUseTest` の規約に合わせ、既存 `StrayLlmCallGuardTest` と同じく
       グローバル名前空間の `Throwable` / `RuntimeException` はそのまま書く)
- [x] `drainForAssertion()` の戻り値 shape に依存する assertion のみ書く (配列添字は
      `['method']` / `['url']` の 2 キーに限定)
- [x] DTO 返却は非該当

### テスト計画

**本施策自体がテスト**。実装順序として**最初に書き、赤を確認してから S1 に入る** (思考原則 5)。

- [ ] **赤の確認 (テストファースト)**: S1 実装前に本ファイルを追加し
      `vendor/bin/pest tests/Feature/Support/StrayHttpRequestGuardTest.php` を実行 →
      `Tests\Support\StrayHttpRequestGuard` が存在せず fatal で赤になることを確認
- [ ] 新規テスト: `tests/Feature/Support/StrayHttpRequestGuardTest.php` の case A〜I
      (上表の 9 ケース)
- [ ] 既存テスト `tests/Feature/Support/StrayLlmCallGuardTest.php` は**変更しない**
      (禁止事項 3: 既存テストの削除・上書き)。同ファイルは既に `beforeEach` で
      `Http::fake(['*' => …])` を張っているため、レーン既定 ON でも無改修で緑のまま
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (本ファイルは DB に触れない)
- [ ] Factory は使わない (モデルを作らない)

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| case D の loopback 接続が環境によって即座に refuse されずタイムアウト待ちになる | テストが 1 秒程度遅くなる | `connectTimeout(1)->timeout(1)` で上限を 1 秒に固定。全体で無視できる |
| case D で確保した一時ポートが close 後に別プロセスへ再割当される (TOCTOU) | 接続が成功して例外が出ない | assert の主眼を「`StrayRequestException` **ではない**」に置いたため、接続が成功しても成立する。`--parallel` 実行下でも安定する |
| CI コンテナで loopback 送信自体が禁止されている | 例外型が変わる | 型を固定していない (`StrayRequestException` でないことだけを見る) ので影響しない |
| case A/B/E/I が「実際に外へ出る」 | 外部到達 | 出ない。stray として遮断されるので socket は開かない |

---

## S3: 3 レーンへの既定配線

### 変更箇所

- ファイル: `tests/Pest.php`
  - Feature/Unit lane (L36-63): `beforeEach` / `afterEach`
  - Architecture lane (L65-69): `beforeEach` 追加 + `afterEach` 新設
  - Browser lane (L78-108): `beforeEach` / `afterEach`
  - import 追加 (L23 付近)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: **全テストに影響する** (レーン既定の変更)。
  初回導入で赤化する既存テストの是正は **S6** で扱う
- アプリコード: **なし**

### 現行コード

```php
use Tests\Support\StrayLlmCallGuard;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        StrayLlmCallGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            StrayLlmCallGuard::flushAndFailIfStray();
        } finally {
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
    })
    ->in('Architecture');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled'));
        StrayLlmCallGuard::install($this->app);
        app(CannedPromptFakeRegistrar::class)->install();
    })
    ->afterEach(function (): void {
        try {
            StrayLlmCallGuard::flushAndFailIfStray();
        } finally {
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
        }
    })
    ->in('Browser');
```

(既存コメントは省略して骨格のみ示す。実装時は既存コメントを残す。)

### 変更後コード

```php
use Tests\Support\StrayHttpRequestGuard;   // ← 追加 (import 順は Pint に従う)
use Tests\Support\StrayLlmCallGuard;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();

        // (既存コメントはそのまま)
        StrayLlmCallGuard::install($this->app);

        // 未 fake の外向き HTTP を fail-fast させる guard (裁定 AG-105)。
        // レーン既定として Http::preventStrayRequests() を常時 ON にし、
        // 自機宛て loopback だけを Http::allowStrayRequests([...]) で明示許可する。
        // テスト本体で Http::fake([...]) を呼ぶと該当 URL は透過する
        // (Factory::fake() は prevent フラグを reset しないため共存する)。
        StrayHttpRequestGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray が記録されていれば test を fail させる。
            // ★2 つの guard は順に flush する。**同時発生時は先に throw した guard の
            //   詳細だけが表示される** (もう一方の accumulator は finally の reset で
            //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
            //   両方を集約する仕組みは入れない (今必要なものだけ作る)。
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
        } finally {
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

/*
| Architecture lane はファイル走査中心で DB を使わないが、HTTP 出口の既定拒否は
| **全レーン一律**にする (レーンごとに既定が違うと「どのレーンなら外へ出られるか」を
| 覚える必要が生まれ、gate も分岐だらけになる)。Tests\TestCase は
| Illuminate\Foundation\Testing\TestCase 継承で Laravel app 上を走るため install できる。
*/
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        StrayHttpRequestGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            StrayHttpRequestGuard::flushAndFailIfStray();
        } finally {
            StrayHttpRequestGuard::reset();
        }
    })
    ->in('Architecture');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled'));

        StrayLlmCallGuard::install($this->app);

        // in-process サーバ (Amp) はテストプロセス自身の HttpKernel / container を使うため、
        // ブラウザ経由リクエストの処理中に出る Laravel HTTP client の呼び出しにも効く。
        // in-process サーバは常に 127.0.0.1 に bind するので、許可パターンの loopback
        // リテラルで自機宛ては通る。
        // ★ただし Playwright のブラウザ自身が出す外部フォント / CDN 取得は**捕捉できない**
        //   (別プロセスのため)。docs/testing-browser.md に保証範囲を明記する。
        StrayHttpRequestGuard::install($this->app);

        app(CannedPromptFakeRegistrar::class)->install();
    })
    ->afterEach(function (): void {
        try {
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
        } finally {
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
        }
    })
    ->in('Browser');
```

### PHPStan 適合チェック

- [x] `tests/Pest.php` は `phpstan.neon` の `paths` 外 (再掲)。closure の `: void` は既存に合わせる
- [x] 新規 import は `Tests\Support\StrayHttpRequestGuard` の 1 本のみ
- [x] 既存 import・既存コメントを削らない (禁止事項 3 の精神)

### テスト計画

- [ ] **赤の確認**: S3 適用前に `composer test` を 1 回走らせ、現状の緑をベースラインとして記録
      (差分がすべて「本変更による検出」であることを示すため)
- [ ] S4 の gate (`tests/Architecture/StrayHttpEgressLaneGateTest.php`) が配線を機械検査する
- [ ] 既存テスト `tests/Feature/Support/StrayLlmCallGuardTest.php` が緑のままであること
      (`beforeEach` で `Http::fake(['*' => …])` を張っているため無改修で通る想定)
- [ ] Browser lane: `composer test:browser` を Chromium + WebKit の 2 レーンで実行して緑を確認
      (`docs/testing-browser.md` の契約。実行時間を理由に WebKit を落とさない)
- [ ] `composer test` 全体が緑 (S6 の是正込み)
- [ ] 個別の `DatabaseTransactions` を追加していないことを確認

**mutation で赤化を確認する手順** (S4 と共通。詳細は S4 の該当節):
M1 (install 行の一時削除) / M2 (install を afterEach の後ろへ移動) を実施し、
gate が赤くなることを確認してから復元する。

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| Architecture lane に `afterEach` を新設することで、既存 Architecture テストの後処理順が変わる | 既存 Architecture テストが落ちる | Architecture lane に既存の `afterEach` は無い (L65-69 は `beforeEach` のみ)。新設なので衝突しない |
| Browser lane で in-process サーバへの自機宛て HTTP が loopback パターンに一致しない | Browser lane が全滅 | サーバは常に 127.0.0.1 に bind する (P9) ので一致する。`composer test:browser` で実確認する |
| 2 guard の flush が直列で、LLM stray が先に throw すると HTTP stray の詳細が失われる | 失敗メッセージの情報量が減る | test は既に赤なので「静かに緑」にはならない。両方を集約する仕組みは「今必要なものだけ作る」に反するため入れない (コメントで明示) |

---

## S4: deny-by-default 目録型 Architecture gate

### 変更箇所

- ファイル: `tests/Architecture/StrayHttpEgressLaneGateTest.php` (**新規**)
- ファイル: `tests/Support/Security/StrayHttpEgressExemption.php` (**新規**)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 本 gate 自体が新規。既存 Architecture テストの変更は**なし**
- アプリコード: **なし**
  (exemption enum は `app/Enums/Security/` ではなく `tests/Support/Security/` に置く。
   分類対象が「テスト側の opt-out 箇所」でアプリのドメインではないため。
   前例: `Tests\Support\Security\PrimaryKeyPredicateKind`)

### 現行コード

存在しない (新規)。同型の見本は `tests/Architecture/GlobalTestLockInventoryTest.php` (425 行、
純関数 + fixture ベースの負のコントロール) と `tests/Architecture/ThrottleCoverageInventoryTest.php`
(型付き enum + 30 文字以上の根拠 + exact-fit cap)。

### 変更後コード

#### `tests/Support/Security/StrayHttpEgressExemption.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 「テストレーンの HTTP 出口既定拒否を opt-out することが正しい」と裁定した理由の分類。
 *
 * tests/Architecture/StrayHttpEgressLaneGateTest.php が deny-by-default で
 * 「opt-out 呼び出しを持つファイルは本 enum + 30 文字以上の具体的根拠付きで
 *  inventory に登録済みであること」を機械強制する。
 *
 * opt-out 呼び出しの定義 (gate の契約と一致させること):
 *  - `allowStrayRequests(...)` — **引数を問わず全件**
 *    (null は既定拒否を OFF に、配列は許可集合を置換する)
 *  - `preventStrayRequests(...)` のうち **引数があるもの全件**
 *    (`false` literal に限らない。`$flag` / `(bool) 0` / `prevent: false` も対象。
 *     引数ゼロの `preventStrayRequests()` はレーン既定と同値の重複宣言なので対象外)
 *
 * ★case は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「opt-out してはいけない箇所」である。
 *
 * ★case を 1 つしか持たないのは意図的 (今必要なものだけ作る)。
 *   2 つ目の opt-out が現れたときに「新しい case を足す差分」として必ず表面化し、
 *   その場で「そもそも opt-out すべきか」を再検討させるのが狙い。
 */
enum StrayHttpEgressExemption: string
{
    /**
     * レーン既定 guard そのものの定義箇所。
     *
     * 適用条件 (すべて満たすこと):
     *  - そのファイルが `Http::allowStrayRequests(...)` を呼ぶ唯一の理由が
     *    「レーン既定の許可集合を設定すること」である
     *  - 許可集合が `StrayHttpRequestGuard::ALLOWED_URL_PATTERNS` 定数 1 か所に閉じている
     *  - `allowStrayRequests(null)` / `preventStrayRequests(false)` を**呼ばない**
     *    (= 既定拒否そのものを外さない)
     */
    case GuardDefinitionSite = 'guard_definition_site';
}
```

#### `tests/Architecture/StrayHttpEgressLaneGateTest.php` (骨格)

```php
<?php

declare(strict_types=1);

use Tests\Support\Security\StrayHttpEgressExemption;
use Tests\Support\StrayHttpRequestGuard;

/*
 * Architecture invariant: テストレーンの HTTP 出口が既定拒否であること (deny-by-default)。
 *
 * 背景 (SoT = devnotes/20260807-1235-stray-http-egress-deny/conceptual-design.md):
 * 裁定 AG-105 は「テストレーンの既定として Http::preventStrayRequests() を常時有効にする」
 * を必須とし、「テスト内で局所的に張って外す形は既定と認めない」と明示している。
 * 本 gate は tests/Pest.php をソース走査して**レーン既定であること**を機械強制する。
 *
 * ★解析は PhpToken でコメントを落としてから行う。文字列 grep にすると
 *   「本 gate の説明コメント」自身や tests/Pest.php の日本語コメントで偽緑になる
 *   (PcreUnicodeModifierGateTest / GlobalTestLockInventoryTest と同じ作法)。
 *
 * ★本 gate は「素の main では赤にならない」種類のテストである。空振りしていないことは
 *   (a) fixture ベースの負のコントロール (下部) と
 *   (b) 実装時の mutation 手順 (詳細設計 S4 §mutation) の 2 本で担保する。
 */

/** 既定配線が必須のレーン。 */
const STRAY_HTTP_EGRESS_REQUIRED_LANES = ['Feature', 'Unit', 'Architecture', 'Browser'];

/** opt-out 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const STRAY_HTTP_EGRESS_REASON_MIN_LENGTH = 30;

/**
 * exemption 件数の上限。**現在値ちょうど** (exact fit)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに opt-out できる枠」
 *   になる。exact fit なら次の 1 本が必ずこの数値を変える差分として現れる。
 */
const STRAY_HTTP_EGRESS_EXEMPTION_CAP = 1;

/**
 * 走査対象から外すファイル (走査器自身)。
 * ★本 gate は検査語 (`allowStrayRequests` 等) をパターン文字列として持つため、
 *   自分を走査すると必ず自己一致する。GlobalTestLockInventoryTest が
 *   「ライブラリ本体は対象外」としたのと同じ扱い。
 */
const STRAY_HTTP_EGRESS_SCANNER_SELF = 'tests/Architecture/StrayHttpEgressLaneGateTest.php';

/**
 * opt-out 呼び出しを持つことが正しいと裁定したファイルの inventory
 * (型付き + 具体的根拠必須、単一 source of truth)。
 *
 * @return array<string, array{StrayHttpEgressExemption, non-empty-string}>
 */
function strayHttpEgressOptOutExemptions(): array
{
    return [
        'tests/Support/StrayHttpRequestGuard.php' => [
            StrayHttpEgressExemption::GuardDefinitionSite,
            'レーン既定 guard 本体。Http::allowStrayRequests() を呼ぶのは ALLOWED_URL_PATTERNS '
            .'(loopback リテラルのみ) を設定するためであり、allowStrayRequests(null) や '
            .'preventStrayRequests(false) は呼ばない = 既定拒否そのものは外していない。',
        ],
    ];
}

/**
 * PHP ソースを **トークン列** へ落とす (純関数)。以降の解析はすべてこの列の上で行う。
 *
 * `PhpToken::tokenize()` した結果から `T_COMMENT` / `T_DOC_COMMENT` を取り除くだけ
 * (空白は保持する — 位置関係の判定には使わないが、抜き出した本体を人間が読める形で
 *  エラーメッセージに載せるため)。
 *
 * ★**文字列 grep も、正規化した文字列に対する括弧カウントもやめた**
 *   (Codex design-review Round 2〜4 の一連の Warning)。文字列に落とす方式は
 *   (a) literal 中の `{` `}` `(` `)` で括弧の対応を誤認する、
 *   (b) literal 中の `function` という語をキーワードと誤認する、
 *   (c) 名前と `(` の間の空白/コメントで判定を外す、
 *   という 3 種類の穴を**個別に塞ぎ続ける**必要がある。トークン列で扱えば
 *   **文字列の中身の括弧は文字列系トークン (`T_CONSTANT_ENCAPSED_STRING` /
 *   `T_ENCAPSED_AND_WHITESPACE`) の内側に保持され、構文上の補間境界は専用トークン
 *   (`T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`) で識別できる**。
 *   キーワードは `T_FUNCTION` / `T_STATIC` の**トークン ID** で一意に判定でき、
 *   空白は「有意トークン」を辿るだけで自然に飛ばせる。穴の種類が構造的に消える。
 *
 *   ★補間文字列は 1 トークンには畳まれない。
 *     `"value={$json}"` → `"` / `T_ENCAPSED_AND_WHITESPACE` / `T_CURLY_OPEN` / `T_VARIABLE` / `}` / `"`
 *     `"value=${json}"` → `"` / `T_ENCAPSED_AND_WHITESPACE` / `T_DOLLAR_OPEN_CURLY_BRACES`
 *                          / `T_STRING_VARNAME` / `}` / `"`
 *     **開始側は専用トークン 2 種・終端は単独 `}`** という非対称があり、その扱いは
 *     `strayHttpEgressMatchingIndex()` の契約に書く (text 値の実測もそこに記載)。
 *
 * @return list<PhpToken>
 */
function strayHttpEgressTokens(string $source): array { /* tokenize → T_COMMENT / T_DOC_COMMENT を除去 */ }

/**
 * `$from` 以降で最初の**有意トークン** (`T_WHITESPACE` 以外) の index を返す (純関数)。
 *
 * @param  list<PhpToken>  $tokens
 */
function strayHttpEgressNextSignificant(array $tokens, int $from): ?int { /* … */ }

/**
 * `$openIndex` (開き括弧のトークン index) に対応する閉じ括弧の index を返す (純関数)。
 * トークン列上で深度を数えるため、文字列**内容**の括弧は文字列系トークンの内側にあり影響しない。
 *
 * ★波括弧 (`{` / `}`) を数えるときは、**補間の開始トークンも開始側に含める**
 *   (Codex design-review Round 5 の Warning):
 *
 *     $token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)
 *
 *   補間の**終端は必ず単独の `}` トークン**であるのに対し、**開始側は 2 種類の専用トークン**に
 *   分かれる。開始側を数え落とすと深度が片側だけ減り、**closure の終端を早く見つけてしまう**。
 *
 *   ★実測 (PHP 8.4.24) で確認した `text` の値 — ここが判断の分かれ目なので事実を残す:
 *
 *     "value={$json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_CURLY_OPEN(**"{"**)
 *                        / T_VARIABLE("$json") / }("}")
 *     "value=${json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_DOLLAR_OPEN_CURLY_BRACES(**"${"**)
 *                        / T_STRING_VARNAME("json") / }("}")
 *
 *   すなわち **`T_CURLY_OPEN` の `text` は `"{"` なので `text === '{'` でも偶然拾えるが、
 *   `T_DOLLAR_OPEN_CURLY_BRACES` の `text` は `"${"` で拾えない**。実際に深度が壊れるのは
 *   後者 (`"${json}"` 形) である。前者を id でも判定するのは、text 一致に依存した暗黙の
 *   前提を契約から消すため (将来 `text` の表現が変わっても壊れない)。
 *   ⚠ **したがって回帰テストの入力は `"${json}"` 形でなければならない**。
 *   `"{$json}"` 形だけで固定すると、修正前の実装でも緑になり**空振りテスト**になる
 *   (実測で確認済み: `{$json}` は修正の有無によらず closure 末尾を返す)。
 *
 *   終了側 (単独 `}`) は通常どおり深度を 1 減らすだけでよい。
 *   丸括弧 (`(` / `)`) の探索ではこの追加処理を行わない (補間に丸括弧の専用トークンは無い)。
 *
 *   ★`${...}` 補間は PHP 8.2 で deprecated (将来削除されうる) だが、fixture は nowdoc 内の
 *     **文字列としてのみ**存在し評価されないので deprecation は出ない。将来 PHP が
 *     `T_DOLLAR_OPEN_CURLY_BRACES` を生成しなくなったら、この回帰テストが
 *     「前提が変わった」ことを示して赤くなる (それが望ましい形の失敗)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  non-empty-string  $open   `(` または `{`
 * @param  non-empty-string  $close  `)` または `}`
 */
function strayHttpEgressMatchingIndex(array $tokens, int $openIndex, string $open, string $close): ?int { /* … */ }

/**
 * トークン列を `pest()->extend(` 単位のチャンクへ分解する (純関数)。
 * レーン名は `->in(` の引数にある `T_CONSTANT_ENCAPSED_STRING` から取る
 * (文字列 grep ではなくトークンから取るので、コメント内の `->in('Feature')` に反応しない)。
 *
 * @param  list<PhpToken>  $tokens
 * @return list<array{lanes: list<string>, tokens: list<PhpToken>}>
 */
function strayHttpEgressLaneChunks(array $tokens): array { /* … */ }

/**
 * chunk 内の `->{$hook}(...)` の**引数が直接 closure リテラルであること**を確認し、
 * その本体トークン列を返す (純関数)。確認できなければ **null を返して fail-closed** にする。
 *
 * 契約 (Codex design-review Round 4 の Warning への対応):
 *  1. `->` + `T_STRING($hook)` の並びを見つけ、その次の有意トークンが `(` であること
 *     (名前と `(` の間の空白は有意トークン走査で自然に飛ばす)。
 *  2. `(` の**次の有意トークン**が `T_FUNCTION`、または `T_STATIC` に続く `T_FUNCTION` であること。
 *     ★ここが要。「引数**内**のどこかにある `function` を拾う」実装だと
 *       `->beforeEach(wrap(function () { install(...); }))` を配線済みと誤認する
 *       (`beforeEach` に渡るのは `wrap(...)` の戻り値であり、その closure が
 *        Pest の hook として登録される保証は無い)。
 *  3. その `T_FUNCTION` に対応する closure 本体の `{` を
 *     `strayHttpEgressMatchingIndex()` で閉じ、本体トークン列を返す。
 *  4. closure の `}` の**次の有意トークン**が、1 で開いた `(` に対応する `)` であること
 *     (= 引数は closure ちょうど 1 個。カンマ区切りの追加引数は**許可しない**)。
 *
 * ★アロー関数 `fn () => …` は**受け付けない** (null を返す)。
 *   レーン配線は複数文 (install / flush + reset) を要するのでブロック本体が必須であり、
 *   2 つの closure 形を両方パースする価値が無い (今必要なものだけ作る)。
 *   将来 `fn` で書きたくなったら、gate が赤くなるので必ず設計判断として表面化する。
 *
 * @param  list<PhpToken>  $tokens  chunk のトークン列
 * @param  non-empty-string  $hook   'beforeEach' または 'afterEach'
 * @return list<PhpToken>|null
 */
function strayHttpEgressHookBody(array $tokens, string $hook): ?array { /* 上記 1〜4 */ }

/**
 * トークン列に `StrayHttpRequestGuard::{$method}(` の**呼び出し**があるか (純関数)。
 *
 * `T_STRING('StrayHttpRequestGuard')` → `T_DOUBLE_COLON` → `T_STRING($method)` →
 * 次の有意トークンが `(` という並びで判定する。
 * ★文字列 grep にしないのが load-bearing: literal 中の同名テキストは
 *   `T_CONSTANT_ENCAPSED_STRING` 1 個なので一致しない = コメントや説明文で偽緑にならない。
 *
 * @param  list<PhpToken>  $tokens
 * @param  non-empty-string  $method
 */
function strayHttpEgressCallsGuard(array $tokens, string $method): bool { /* … */ }

/**
 * レーン既定配線の違反一覧 (純関数)。
 *
 * 各チャンクについて:
 *  - `strayHttpEgressHookBody($chunk, 'beforeEach')` が非 null で、その本体が
 *    `StrayHttpRequestGuard::install(` を**呼んで**いる
 *  - `strayHttpEgressHookBody($chunk, 'afterEach')` が非 null で、その本体が
 *    `flushAndFailIfStray(` と `reset(` を呼んでいる
 *  - hook body が null (hook が無い / 引数が closure リテラルでない / 追加引数がある) なら
 *    **違反として扱う** (fail-closed。取り出せないものを「たぶん大丈夫」にしない)
 * さらに STRAY_HTTP_EGRESS_REQUIRED_LANES が全て、いずれかのチャンクで覆われている。
 *
 * @param  list<array{lanes: list<string>, tokens: list<PhpToken>}>  $chunks
 * @return list<string>
 */
function strayHttpEgressLaneViolations(array $chunks): array { /* … */ }

/**
 * 許可パターンが loopback ホストだけに閉じているかの違反一覧 (純関数)。
 *
 * 許容する形は `scheme://host` / `scheme://host/*` / `scheme://host:*` の 3 形のみ。
 * host は 127.0.0.1 / localhost / [::1] に限る。
 * これにより `http://127.0.0.1*` (末尾ワイルドカード) も `https://api.example.com/*` も弾かれる。
 *
 * @param  list<string>  $patterns
 * @return list<string>
 */
function strayHttpEgressPatternViolations(array $patterns): array
{
    $violations = [];
    foreach ($patterns as $pattern) {
        if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|\[::1\])(?:/\*|:\*)?$#u', $pattern) !== 1) {
            $violations[] = "許可パターンが loopback に閉じていない: {$pattern}";
        }
    }

    return $violations;
}

/**
 * 1 ファイル分の opt-out 判定 (純関数。fixture でテストできる形に切り出す)。
 *
 * 検出対象 (**deny-by-default**):
 *  - `allowStrayRequests` の呼び出し — 引数を問わず全件。
 *    null 渡しは prevent 自体を OFF にし、配列渡しは既定の許可集合を**置換**する
 *    (merge ではない: `Factory::allowStrayRequests` は `array_values($only)` 代入)。
 *    どちらもレーン既定を壊しうるので区別せず全部登録対象にする。
 *  - `preventStrayRequests` の呼び出しのうち **引数があるもの**全件。
 *    ★`preventStrayRequests(false)` の literal だけを見ると
 *      `preventStrayRequests($flag)` / `((bool) 0)` / `preventStrayRequests(prevent: false)` が
 *      素通りする (Codex design-review Round 1 の Warning)。
 *      **引数ゼロだけを許可**し (レーン既定と同値の重複宣言)、有意トークンが 1 個でもあれば
 *      inventory 必須にする = 逃げ道を構造的に消す。
 *
 * 判定はすべてトークン列上で行う:
 *  `T_STRING(メソッド名)` → 次の有意トークンが `(` → `strayHttpEgressMatchingIndex()` で
 *  対応する `)` を求め、その間の**有意トークン数**を数える。
 *  これで (a) コメント内の説明、(b) 名前と `(` の間の空白/コメント、
 *  (c) 引数中の文字列に含まれる `)`、のいずれでも誤らない。
 */
function strayHttpEgressIsOptOutSource(string $source): bool { /* strayHttpEgressTokens → 上記判定 */ }

/**
 * tests/ 配下で opt-out 呼び出しを持つファイル一覧 (リポジトリルート相対、ソート済み)。
 * Finder でファイルを集め `strayHttpEgressIsOptOutSource()` に渡すだけの薄い層。
 * 走査器自身 (STRAY_HTTP_EGRESS_SCANNER_SELF) は除外する。
 *
 * @return list<string>
 */
function strayHttpEgressOptOutSites(): array { /* Finder で tests/**\/*.php → 上記純関数 */ }

test('tests/Pest.php の全レーンが StrayHttpRequestGuard を既定配線していること', /* … */);
test('許可 URL パターンが loopback ホストだけに閉じていること', /* … */);
test('opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)', /* … */);
test('exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)', /* … */);
test('exemption の根拠が 30 文字以上であること', /* … */);
test('exemption 件数が上限 (exact fit) を超えていないこと', /* … */);

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 * 本体テスト 6 本 + 負のコントロール 13 本 = 計 19 本。
 */
test('負のコントロール: install を持たないレーンを検出する', /* … */);
test('負のコントロール: install が afterEach 側にしかない配線を検出する', /* … */);
test('負のコントロール: install が hook closure の外にある配線を検出する', /* … */);
test('負のコントロール: flush はあるが reset が無い配線を検出する', /* … */);
test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', /* … */);
test('負のコントロール: コメント内の install 記述では配線と認めない', /* … */);
test('負のコントロール: 文字列リテラル中の install 記述では配線と認めない', /* … */);
test('負のコントロール: hook 引数がネストした closure の場合を配線と認めない', /* … */);
test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', /* … */);
test('strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない', /* … */);
test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) と外部ドメインを検出する', /* … */);
test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', /* … */);
test('負のコントロール: 名前と ( の間の空白/コメント・引数中の ) で opt-out 判定を誤らない', /* … */);
```

負のコントロールの中身 (要点となる 6 本):

```php
test('負のコントロール: コメント内の install 記述では配線と認めない', function (): void {
    // ★これが無いと「// StrayHttpRequestGuard::install($this->app); を入れる予定」という
    //   コメントだけで gate が緑になる (最も現実的な偽緑シナリオ)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            // StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            // StrayHttpRequestGuard::flushAndFailIfStray();
            // StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: 文字列リテラル中の install 記述では配線と認めない', function (): void {
    // ★トークン ID ではなく文字列 grep で判定する実装だと、これが素通りする
    //   (Codex design-review Round 4 の Suggestion)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $todo = 'StrayHttpRequestGuard::install($this->app);';
        })
        ->afterEach(function (): void {
            $todo = 'StrayHttpRequestGuard::flushAndFailIfStray(); StrayHttpRequestGuard::reset();';
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: install が hook closure の外にある配線を検出する', function (): void {
    // 「beforeEach と afterEach の間にあれば OK」という位置ベースの実装だと素通りする形。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $this->withoutVite();
        })
        ->use(StrayHttpRequestGuard::install($app))
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: hook 引数がネストした closure の場合を配線と認めない', function (): void {
    // ★「引数**内**のどこかにある function を拾う」実装だと素通りする
    //   (Codex design-review Round 4 の Warning)。beforeEach に渡るのは wrap(...) の
    //   戻り値であり、この closure が hook として登録される保証は無い。
    //   引数が closure リテラルでない形 ($callback 変数渡し) も同様に fail-closed。
    $wrapped = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(wrap(function (): void {
            StrayHttpRequestGuard::install($this->app);
        }))
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $variable = str_replace(
        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
        '$callback',
        $wrapped,
    );

    // アロー関数も受け付けない (ブロック本体が必須 = 契約どおり fail-closed)
    $arrow = str_replace(
        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
        'fn () => StrayHttpRequestGuard::install($this->app)',
        $wrapped,
    );

    foreach (['wrapped' => $wrapped, 'variable' => $variable, 'arrow' => $arrow] as $label => $source) {
        $violations = strayHttpEgressLaneViolations(
            strayHttpEgressLaneChunks(strayHttpEgressTokens($source)),
        );
        expect($violations)->not->toBe([], "hook 引数の形 ({$label}) を fail-closed にできていない");
        expect(implode("\n", $violations))->toContain('install');
    }
});

test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', function (): void {
    // ★正しい配線が literal 由来の括弧で偽赤にならないこと (偽陽性側の固定)。
    // ★`${json}` 形 (T_DOLLAR_OPEN_CURLY_BRACES) を必ず含める。`{$json}` 形だけだと
    //   T_CURLY_OPEN の text が "{" のため補間開始を数え落とす実装でも緑になり、
    //   この負のコントロールが空振りする (Codex design-review Round 5 の Warning の実体)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $json = '{"enabled":true}';
            $unbalanced = '} ) { (';
            $interpolated = "value={$json}";
            $legacyInterpolated = "value=${json}";
            $doc = <<<'INNER'
            { unbalanced brace in heredoc
            INNER;
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->toBe([], 'literal 由来の括弧で closure の終端を誤認している');
});

test('strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない', function (): void {
    // ★アルゴリズムの核を単体で固定する (Codex design-review Round 5 の Warning)。
    //   補間開始トークンを開始側に数えない実装だと、返る index が補間の `}` になり
    //   closure 本体が途中で切れる。
    //
    // ★入力は 2 形とも回す。**赤を出せるのは `${json}` 形だけ**である:
    //   実測 (PHP 8.4.24) で T_CURLY_OPEN の text は "{" なので `{$json}` 形は
    //   text 比較だけの実装でも偶然通る = それだけで固定すると空振りテストになる。
    //   T_DOLLAR_OPEN_CURLY_BRACES の text は "${" で text 比較に掛からない。
    //   両方入れるのは「2 形とも契約どおり」を示すため (前者は回帰の保険)。
    $sources = [
        'dollar-open-curly (この形だけが修正前の実装で赤くなる)'
            => '<?php function () { $a = "value=${json}"; guard(); }',
        'curly-open' => '<?php function () { $a = "value={$json}"; guard(); }',
    ];

    foreach ($sources as $label => $source) {
        $tokens = strayHttpEgressTokens($source);

        $open = null;
        foreach ($tokens as $i => $token) {
            if ($token->text === '{') { // closure 本体の `{` (補間開始トークンより前にある)
                $open = $i;
                break;
            }
        }
        expect($open)->not->toBeNull($label);
        /** @var int $open */
        $close = strayHttpEgressMatchingIndex($tokens, $open, '{', '}');
        expect($close)->not->toBeNull($label);
        /** @var int $close */

        // 対応先は closure 末尾の `}` = その後ろに有意トークンが残らない
        expect(strayHttpEgressNextSignificant($tokens, $close + 1))->toBeNull($label);
        // 本体に guard() 呼び出しが含まれている (補間の } で切れていない)
        $body = array_slice($tokens, $open + 1, $close - $open - 1);
        expect(implode('', array_map(static fn (PhpToken $t): string => $t->text, $body)))
            ->toContain('guard', $label);
    }
});

test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', function (): void {
    // ★literal `false` だけを見る実装だと variable / cast / named が素通りする。
    $optOuts = [
        'literal' => 'Http::preventStrayRequests(false);',
        'variable' => 'Http::preventStrayRequests($flag);',
        'cast' => 'Http::preventStrayRequests((bool) 0);',
        'named' => 'Http::preventStrayRequests(prevent: false);',
        'spaced-comment' => 'Http::preventStrayRequests /* 理由 */ (false);',
        'nested-paren' => "Http::preventStrayRequests(str_contains(\$s, ')'));",
        'allow-null' => 'Http::allowStrayRequests();',
        'allow-array' => "Http::allowStrayRequests(['*']);",
    ];
    foreach ($optOuts as $label => $line) {
        expect(strayHttpEgressIsOptOutSource("<?php\n{$line}\n"))
            ->toBeTrue("opt-out ({$label}) を検出できていない");
    }
});

test('負のコントロール: 名前と ( の間の空白/コメント・引数中の ) で opt-out 判定を誤らない', function (): void {
    // 誤検出側 (false であるべきもの) を固定する。
    // レーン既定と同値の重複宣言 (無引数) は opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\n"))->toBeFalse();
    // 空白・改行を跨いだ無引数も opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests\n    (\n    );\n"))
        ->toBeFalse();
    // 無引数呼び出しの後ろに別の括弧があっても opt-out と誤検出しない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\nfoo(bar());\n"))
        ->toBeFalse();
    // コメント内・文字列リテラル内の記述も opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\n// Http::allowStrayRequests(['*']) は使わない\n"))
        ->toBeFalse();
    expect(strayHttpEgressIsOptOutSource("<?php\n\$doc = 'Http::allowStrayRequests([]) は禁止';\n"))
        ->toBeFalse();
});

test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) と外部ドメインを検出する', function (): void {
    foreach (['http://127.0.0.1*', 'https://api.frankfurter.dev/*', '*', 'http://127.0.0.1.evil.example/*'] as $pattern) {
        $violations = strayHttpEgressPatternViolations([$pattern]);
        expect($violations)->not->toBe([], "許可パターン ({$pattern}) を検出できていない");
        expect(implode("\n", $violations))->toContain('loopback に閉じていない');
    }

    // 正しい 3 形は違反にしない (偽陽性側の固定)
    expect(strayHttpEgressPatternViolations([
        'http://127.0.0.1', 'http://127.0.0.1/*', 'http://127.0.0.1:*', 'https://[::1]:*',
    ]))->toBe([]);
});
```

> `strayHttpEgressIsOptOutSource(string $source): bool` は
> `strayHttpEgressOptOutSites()` が 1 ファイル分の判定に使う純関数として切り出す
> (fixture でテストできる形にするため。`strayHttpEgressOptOutSites()` は
> Finder でファイルを集めてこの純関数に渡すだけの薄い層にする)。

### PHPStan 適合チェック

> ⚠ `tests/` は `phpstan.neon` の `paths` 外 (再掲)。以下は手動チェックリスト。

- [x] 全関数に戻り値型 (`array` は `@return list<string>` / `@return array<string, array{Enum, non-empty-string}>` の shape 付き)
- [x] `preg_match` の戻り値は `int|false` なので `!== 1` で比較する (真偽値の暗黙変換をしない)
- [x] `file_get_contents()` の `string|false` は `expect($source)->toBeString()` +
      `/** @var string $source */` で narrowing する (既存 `GlobalTestLockInventoryTest` と同形)
- [x] PCRE に `\R` を使う場合は `/u` 必須 (`PcreUnicodeModifierGateTest`)。
      本 gate は `\R` を使わない (トークン列で扱うため行分割が不要) が、`#…#u` を既定で付ける
- [x] `PhpToken::tokenize()` の戻り値は `list<PhpToken>` として扱い、
      配列を渡す純関数はすべて `@param list<PhpToken> $tokens` を付ける
- [x] トークン判定は **`$token->is(T_FUNCTION)` / `$token->id`** で行い、`text` の文字列比較で
      キーワードを判定しない (literal 中の同名テキストで誤判定するため)。
      記号トークン (`(` `)` `{` `}` `,`) は `text` 比較でよい (id が ASCII コードのため)。
      **ただし例外**: 波括弧の深度計算では補間の開始トークン
      `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` を **id で判定して開始側に加える**。
      実測 (PHP 8.4.24) の `text` は `T_CURLY_OPEN` = `"{"` / `T_DOLLAR_OPEN_CURLY_BRACES` = `"${"`。
      **深度が実際に壊れるのは後者**(`"${json}"` 形。`'{'` と一致しないので開始側に数えられず、
      終端の単独 `}` だけが深度を減らして closure 終端を早く見つける)。
      前者も id で判定するのは、`text` 一致という暗黙の前提を契約から外すため。
      ⚠ この例外の回帰テストは **`"${json}"` 形を必ず入力に含める**こと
      (`"{$json}"` 形だけでは修正前の実装でも緑になり空振りする)
- [x] index を返す関数は `?int`、本体を返す関数は `list<PhpToken>|null` を明示し、
      null を「見つからない = fail-closed」の意味だけに使う
- [x] enum は backed string enum。`->value` でのみ文字列化する
- [x] DTO 返却は非該当

### テスト計画

- [ ] **赤の確認 (テストファースト)**: gate を先に追加し、S3 の配線を入れる**前**に
      `vendor/bin/pest tests/Architecture/StrayHttpEgressLaneGateTest.php` を実行 →
      「Feature/Unit lane が install していない」等で赤になることを確認
- [ ] 新規テスト: 本体テスト 6 本 + 負のコントロール 13 本 (計 19 本)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Architecture lane は DB 不使用)
- [ ] Factory は使わない (モデルを作らない)

#### mutation で赤化を確認する手順 (「素の main では赤にならない gate」の受け入れ)

本 gate も S2 の自己検査も、**正しい状態では常に緑**であり、放置すると空振りに気づけない。
負のコントロール (fixture) は「純関数が壊れた入力を検出できる」ことしか示さないので、
**実ファイルに対して gate が効いているか**は mutation で確認する。
実装 PR の説明に、以下 10 本の実施結果 (赤くなったテスト名) を記録する。

| # | mutation (一時変更 → 必ず復元) | 期待して赤くなるもの |
|---|------|------|
| M1 | `tests/Pest.php` の Feature/Unit lane から `StrayHttpRequestGuard::install($this->app);` を削除 | gate「全レーンが既定配線」 |
| M2 | 同 install 行を `->afterEach(` の closure 本体へ移動 | gate「全レーンが既定配線」(beforeEach 本体に install が無い) |
| M3 | `ALLOWED_URL_PATTERNS` に `'https://api.frankfurter.dev/*'` を追加 | gate「許可パターンが loopback に閉じている」 |
| M4 | `ALLOWED_URL_PATTERNS` の `'http://127.0.0.1:*'` を `'http://127.0.0.1*'` に変更 | gate「許可パターン」 + S2 case H |
| M5 | `tests/Feature/Security/AuthThrottleCoverageTest.php` に `Http::allowStrayRequests(['*']);` を追加 | gate「opt-out が inventory 登録済み」 |
| M6 | guard の `__invoke` から `self::$strayRequests[] = …` を削除 | S2 case A / E / I (握り潰し貫通) |
| M7 | inventory から `tests/Support/StrayHttpRequestGuard.php` を削除 / 架空パスを追加 | gate「未登録」/ gate「形骸化ガード」 |
| M8 | `tests/Pest.php` の install 行を `beforeEach` closure の外 (`->use(...)` の直後など) へ移動 | gate「全レーンが既定配線」(hook 本体の内包検査) |
| M9 | `tests/Feature/Security/ThrottleExemptionPremiseTest.php` の `Http::preventStrayRequests();` を `Http::preventStrayRequests($flag);` に変更 | gate「opt-out が inventory 登録済み」(非 literal 検出) |
| M10 | `tests/Pest.php` の Feature/Unit lane の `->beforeEach(function (): void { … })` を `->beforeEach(wrap(function (): void { … }))` に変更 | gate「全レーンが既定配線」(hook 引数が closure リテラルでない → fail-closed) |

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| `tests/Pest.php` のチャンク分割が将来の書き方 (`pest()->extend()` を変数へ代入等) で壊れる | gate が偽赤 or 偽緑 | 偽赤は書いた瞬間に気づける。偽緑側は「必須レーンが全て覆われていること」の検査が残るため、チャンクが取れなければレーン未充足で赤になる (fail-closed) |
| 走査器自身の除外 (`STRAY_HTTP_EGRESS_SCANNER_SELF`) が抜け道になる | gate ファイル内で opt-out すれば検出されない | gate ファイルは Architecture lane で HTTP を出さない。かつ除外は定数 1 本で可視。GlobalTestLockInventoryTest と同じ受容 |
| exemption cap が exact fit=1 のため、正当な 2 本目でも一度赤くなる | 実装者の手間 | それが狙い (再検討の強制)。ThrottleCoverageInventoryTest と同じ設計 |
| `Finder` で `tests/**` を毎回走査するコスト | Architecture lane が数十 ms 遅くなる | 既存 gate (`DirectFetchInventory` は `app` + `routes` 全走査) と同程度。許容 |
| 将来 PHP が新しい構文 (文字列系トークン / closure 表記) を追加し、トークン走査の前提が崩れる | gate が偽赤 or 偽緑になる | 解析をトークン列で行うため、literal は 1 トークンに畳まれ、キーワードは id で判定される = 崩れる余地が構造的に小さい。負のコントロール (JSON / 補間 / heredoc / nowdoc / ネスト closure / アロー関数) が回帰を捕まえる。PHP バージョンを上げる際は本表を根拠に `strayHttpEgressHookBody()` の受理形を再確認する |

---

## S5: 既存記述の是正

### 変更箇所

- `tests/Feature/Auth/RegistrationTest.php` (L43-49): 棄却理由コメント
- `tests/Feature/Security/ThrottleExemptionPremiseTest.php` (L349, 410, 515, 544 付近): 位置づけコメント
- `tests/Feature/Security/AuthThrottleCoverageTest.php` (L262-268 付近): 位置づけコメント
- `AGENTS.md`: 新セクション 1 本追加
- `docs/testing-browser.md`: §LLM fake (in-process) 周辺に追記

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 上記 3 ファイルの**コメントのみ**変更 (assertion もテスト名も変えない
  = 禁止事項 3 の「既存テストの削除・上書き」に当たらない)
- アプリコード: **なし**

### 現行コード

`tests/Feature/Auth/RegistrationTest.php` L43-46:

```php
test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
    // HIBP エンドポイントのみ intercept して実ネットワークを遮断する
    // (preventStrayRequests は合法な他 HTTP まで例外化するため使わない = 過検出回避)。
    // uncompromised は NotPwnedVerifier (Http client factory 経由) のため Http::fake で捕捉できる。
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
```

`tests/Feature/Security/AuthThrottleCoverageTest.php` L262-268:

```php
test('social.callback の throttle は Socialite を一切呼ばずに枠を消費する (外向き HTTP の増幅が有界)', function (): void {
    // 「throttle が外向き HTTP より前にある」ことの本体。
    // intent 不在で controller が Socialite に触れる前に短絡することを spy で直接示す
    // (Socialite は Guzzle を直接使うため Http::preventStrayRequests() では捕まらない。
    //  preventStrayRequests は Laravel HTTP client 側の追加の網として併用する)。
    Http::preventStrayRequests();
```

### 変更後コード

`tests/Feature/Auth/RegistrationTest.php`:

```php
test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
    // HIBP エンドポイントのみ intercept して「呼ばれないこと」を assert 可能にする。
    //
    // ★旧コメントの棄却理由「preventStrayRequests は合法な他 HTTP まで例外化するため
    //   使わない (過検出回避)」は**前提そのものが成立していない**ので撤回した (裁定 AG-105):
    //   (1) 想定されていた「合法な他 HTTP」= HIBP は、app/Support/PasswordPolicy.php の
    //       PWNED_CHECK_DISABLED_APP_ENVS に 'testing' が含まれるため testing env では
    //       uncompromised 自体が付かず、そもそも通信が発生しない
    //       (下の Http::fake は「万一 rule が復活したら捕捉する」保険であって no-op が正常)。
    //   (2) 実際に既定拒否へ掛かるのは api.frankfurter.dev (FxRateService) と reCAPTCHA で、
    //       いずれも**外部宛て = 通してはいけない通信**。過検出ではなく検出である。
    //   (3) 自機宛て loopback は StrayHttpRequestGuard::ALLOWED_URL_PATTERNS で明示許可済み。
    //   現在は tests/Pest.php のレーン既定として preventStrayRequests が常時 ON になっている。
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
```

`tests/Feature/Security/AuthThrottleCoverageTest.php` / `ThrottleExemptionPremiseTest.php`
(4 箇所とも同趣旨の 1 行を足す):

```php
    // (Socialite は Guzzle を直接使うため Http::preventStrayRequests() では捕まらない。
    //  preventStrayRequests は Laravel HTTP client 側の追加の網として併用する)。
    // ★この 1 行は tests/Pest.php のレーン既定と**同値の重複宣言**であり、後方互換の並走ではない
    //  (Factory::preventStrayRequests は冪等。allowStrayRequests は呼んでいないので
    //   レーン既定の loopback 許可集合を置換しない)。このテストの意図
    //   「ここで外向き HTTP が起きないこと」を呼び出し側に明示する目的で残す。
    Http::preventStrayRequests();
```

`AGENTS.md` (「セキュリティ不変条件」節の**直後**に新セクションを追加。
既存の番号付き不変条件は **renumber しない** = 既存参照を壊さない):

```markdown
## テストレーンの外部 HTTP 出口 (既定拒否)

テストレーンは Laravel HTTP client (`Http::`) 経由の外向き通信を**既定で拒否**する
(裁定 AG-105 準拠。設計は `devnotes/20260807-1235-stray-http-egress-deny/`)。

- 配線は `tests/Pest.php` の**全レーン** (Feature/Unit・Architecture・Browser) が
  `Tests\Support\StrayHttpRequestGuard::install()` / `flushAndFailIfStray()` で行う。
  **テスト内で局所的に張って外す形は既定と認めない**
  (`StrayHttpEgressLaneGateTest` が deny-by-default で強制)
- 自機宛て loopback (`127.0.0.1` / `localhost` / `[::1]`) だけが
  `StrayHttpRequestGuard::ALLOWED_URL_PATTERNS` で明示許可される。
  この定数が許可集合の唯一の正本で、`config('app.url')` の host は含めない
- 外部 URL を叩くテストは `Http::fake([...])` を書く。opt-out
  (`Http::allowStrayRequests(...)` / `preventStrayRequests(false)`) は
  型付き enum + 30 文字以上の根拠付きで exemption inventory へ登録する
- アプリ側が `catch (Throwable)` で握り潰しても検出できるよう、guard は
  `Http::globalMiddleware` で `StrayRequestException` を accumulator に記録し
  afterEach で一括判定する (LLM 側の `StrayLlmCallGuard` と同じ形。両者は**並存**する)
- **保証範囲 (誇張しない)**: 効くのは **`Http::` を呼んだプロセス内**の Laravel HTTP client
  経由の出口**だけ**。以下には**無言で効かない** —
  bug-hunt (`scripts/bug-hunt-shard.sh` の別プロセス実行) /
  Socialite (Guzzle 直) / Stripe SDK / AWS SDK /
  Browser lane で Playwright のブラウザ自身が出す外部取得。
  また許可判定は**名前解決前の URL 文字列**照合なので、`localhost` が loopback に
  解決されることは**前提であって保証ではない** (hosts / DNS の健全性は対象外)。
  この非対称を対称に書かない (「テストは外部に一切出ない」と書くのは嘘になる)
```

`docs/testing-browser.md` §LLM fake (in-process) の直後に追記:

```markdown
## 外部 HTTP 出口の既定拒否 (in-process)

Browser lane は LLM (上記 2 層) に加えて **Laravel HTTP client 経由の外向き HTTP** も
既定拒否する (`tests/Pest.php` の Browser lane が `StrayHttpRequestGuard` を install)。

- in-process サーバ (Amp) はテストプロセス自身の HttpKernel / container を使うため、
  **ブラウザ経由リクエストの処理中に出るアプリ側の `Http::` 呼び出しにも効く**。
- in-process サーバは常に `127.0.0.1` に bind するので、自機宛ては
  `StrayHttpRequestGuard::ALLOWED_URL_PATTERNS` の loopback 許可で通る。
- **効かないもの**: Playwright の**ブラウザ自身**が出す外部フォント / CDN / 解析タグの取得は
  別プロセスなので捕捉できない。Socialite (Guzzle 直) / Stripe SDK / AWS SDK も対象外。
  bug-hunt (別プロセス実行) にも**無言で効かない**。
  「Browser テストは外部に一切出ない」とは書けない。
```

### PHPStan 適合チェック

- [x] コメント変更のみ (PHP の型に影響なし)。`AGENTS.md` / `docs/` は解析対象外
- [x] 既存テストの assertion / テスト名を変更しない (禁止事項 3)

### テスト計画

- [ ] `AGENTS.md` の `<!-- VERIFICATION_COMMANDS:BEGIN -->` … `END` マーカーを**触らない**
      (`tests/js/architecture/verification-commands-doc-sync.test.ts` が deny-by-default で
       同期を強制している)
- [ ] 既存の「セキュリティ不変条件」1〜10 の番号を**変えない**
      (AGENTS.md 自身が renumber 禁止と明記。`docs/app-integration-guide.md` §7 の
       既存参照を壊さないため)
- [ ] `composer test` で `tests/Feature/Auth/RegistrationTest.php` /
      `tests/Feature/Security/ThrottleExemptionPremiseTest.php` /
      `tests/Feature/Security/AuthThrottleCoverageTest.php` が緑のままであること
      (コメント変更のみなので挙動は変わらない)
- [ ] `pnpm test` (vitest) が緑 — `verification-commands-doc-sync.test.ts` と
      `logout-call-site-inventory.test.ts` が AGENTS.md / docs に反応しないことの確認
- [ ] `vendor/bin/pint --test` が緑

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| AGENTS.md へのセクション追加が既存 doc-sync テストを壊す | `pnpm test` が赤 | 追加位置は「セキュリティ不変条件」の直後で、VERIFICATION_COMMANDS マーカーにも既存番号にも触れない。実装時に `pnpm test` で確認 |
| `docs/testing-browser.md` の見出し追加が他 doc からの参照を壊す | リンク切れ | 既存見出しを変更せず**追加のみ**。参照元 (`AGENTS.md` / `docs/supported-browsers.md` / `docs/auth-security-mechanisms.md` / `scripts/run-browser-test.sh` / `BughuntShardCapInvariantTest`) はファイル名または既存見出しを指しており影響なし |
| 局所 `preventStrayRequests()` 5 箇所を残す判断が「後方互換の並走」に見える (思考原則 3) | レビュー指摘 | 並走ではない。同じ既定を局所で重ねて宣言しているだけで、旧実装は存在しない。コメントでその旨を明記する |

---

## S6: 初回導入で赤化する既存テストの是正

### 変更箇所

- ファイル: **実行して判明したもの**。事前調査による候補集合は以下。

| 候補 | 赤化する理由 (仮説) |
|------|------|
| `tests/Feature/Auth/RegistrationTest.php` | pwnedpasswords 限定 fake。登録フローが FX 解決 (`api.frankfurter.dev`) に到達すれば stray |
| `tests/Feature/Auth/RegisterVerifyFlowTest.php` | 同上 |
| `tests/Feature/Auth/RegisterPlanHandoffTest.php` | 同上 |
| `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php` | 同上 |
| `tests/Feature/.../AwsSnsSignatureVerifierTest.php` | CERT_URL 限定 fake。想定外の別 URL が出れば stray |
| LLM 実行を伴う Feature テスト | `PromptExecutionCompleted` listener → FX 解決 (`FxRateService`) が握り潰していた到達が表面化 |

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 上記。**assertion は変えず `Http::fake([...])` の追加だけで解く**
- アプリコード: **なし** (アプリの握り潰しは直さない = スコープ外)

### 現行コード / 変更後コード

一律に同じ形の変更しかしない:

```php
// 変更前 (限定 fake のみ)
Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

// 変更後 (実際に出ていた外部宛てを明示的に fake する。ワイルドカード `*` は使わない)
Http::fake([
    'api.pwnedpasswords.com/*' => Http::response('', 200),
    'api.frankfurter.dev/*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]]),
]);
```

**是正の規律 (非交渉)**:

1. **`Http::fake(['*' => …])` の全許可でごまかさない**。どの外部宛てが出ていたかを
   URL パターンで明示する (何を fake したかがコードに残らないと、次に別の出口が
   増えたときに静かに吸収される)。
   - 例外: そのテストの主題が「外部呼び出しをしない」ことの検証で、
     どの URL であれ出たら異常な場合は `'*'` でよいが、その旨をコメントに書く。
2. **`Http::allowStrayRequests(...)` / `preventStrayRequests(false)` で逃げない**
   (S4 の gate が inventory 登録を要求する = レビューで必ず目に入る)。
3. **assertion を弱めない / テストを skip しない** (禁止事項 3)。
4. 赤の原因が「アプリが本当に不要な外部呼び出しをしている」だった場合は、
   **本 TODO では直さず別 TODO を起票する** (スコープ外の宣言に従う)。

### PHPStan 適合チェック

- [x] `tests/` は解析対象外 (再掲)。`Http::fake()` 呼び出しの追加のみで型に影響なし

### テスト計画

- [ ] S3 適用直後に `composer test` を実行し、**赤になったテストの一覧を記録する**
      (実装 PR の説明に貼る = 「何が静かに緑だったか」の証拠)
- [ ] 各赤に対し上記規律で `Http::fake` を追加し、再実行して緑にする
- [ ] `composer test:browser` (Chromium + WebKit の 2 レーン) も同様
- [ ] 最終確認: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
      `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
      `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が全 green

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| 赤が想定より多く、実装コストが膨らむ | TODO の見積もり超過 | 実査で「Prism::fake / Prompt::fake を使うテストは全件が既に Http::fake を併用している (未併用は tests/Pest.php と StrayLlmCallGuard.php のみ)」「Http::fake を持つテストは 16 ファイル」を確認済み。母数は小さい |
| `--parallel` 実行で赤の再現順序が安定しない | 原因特定が難しい | 失敗メッセージに `method` + `url` が出るので、fake すべき URL は一意に決まる |
| 赤を `'*'` fake で一律に潰してしまう | 検出力の恒久的な低下 | 上記「是正の規律」1 を実装 PR のレビュー観点に明記。gate では機械強制しない (何が正しい fake かはテストの主題に依存するため、機械判定は偽陽性を生む) |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| **推奨モード** | **standalone** |
| **判断根拠** | (1) `tests/Pest.php` の**全レーン既定**を変えるため、実装中の他 TODO のテストが本変更の影響で赤くなる (S6 の是正が他ブランチのテストにも及ぶ)。incremental で他施策と混ぜると「どの変更でどのテストが赤くなったか」が切り分けられない。(2) 変更が `tests/` + `AGENTS.md` + `docs/` に閉じ、アプリコードを 1 行も触らないため、単独ブランチで完結し衝突面が小さい。(3) 効果測定 (S6 で記録する「静かに緑だったテストの一覧」) は本変更単独でしか取れない |
| **競合リスク** | `tests/Pest.php` は全 worktree が共有する中心ファイルなので、**他 TODO が同ファイルを触っていないこと**を着手前に確認する。`AGENTS.md` も同様 (セクション追加のみなので conflict は小さいが、`docs/TODO.md` 経由で並行状況を確認する)。テストレーンはホスト全体でグローバルロック (T099) により直列化されるため、`composer test` の実行自体は他 worktree と競合しない (待つだけ) |
| **想定作業量** | 新規 4 ファイル (guard / enum / 自己検査 / gate) + 変更 6 ファイル + S6 の是正数本。effort 4 相当 |

---

## 使命・禁止事項チェック (最終確認)

| 項目 | 判定 | 根拠 |
|------|------|------|
| 使命 (North Star) への寄与 | ○ | aicue は SOP → シナリオ → 撮影 → レンダの各段で LLM / 為替 / captcha / SNS という外部依存に囲まれている。「テストが緑 = 外部に触っていない」を構造的に保証できなければ CI の緑が現場品質の根拠にならない。LLM 側で既に作った保証 (`StrayLlmCallGuard`) の穴を HTTP 出口にも閉じる |
| 禁止事項 1 (テストなしの実装完了) | ○ | 不変条件は `StrayHttpEgressLaneGateTest` (Architecture) + `StrayHttpRequestGuardTest` (Feature) に登録。mutation 手順で空振りでないことまで確認する |
| 禁止事項 2 (PHPStan の widen / baseline) | ○ | `@phpstan-ignore-line` も baseline 追加もしない。`tests/` は元々解析対象外である事実を隠さず明記した |
| 禁止事項 3 (dev DB の破壊操作) | ○ | DB に一切触れない |
| 禁止事項 4 (`response()->json()` 直書き) | ○ | HTTP レスポンスを作らない |
| 禁止事項 5〜7 (Prism 直呼び / prompt 直書き / `redirect()->intended()`) | ○ | 非該当 |
| 禁止事項 8 (disabled ボタン) | ○ | UI 変更なし |
| 禁止事項 9 (Artifact の使用) | ○ | 成果物はすべてリポジトリ内ファイル |
| 思考原則 1 (フレームワークのレンジ内) | ○ | 公式機構 (`preventStrayRequests` / `allowStrayRequests` / `globalMiddleware`) のみ。新規依存ゼロ |
| 思考原則 2 (今必要なものだけ) | ○ | 裁定の必須 1 点に限定。裁量項目 (資格情報無効化 / fake 未消費検出) は明示的にスコープ外。`uninstallForTest` 相当の opt-out API も**作らない** |
| 思考原則 3 (後方互換の並走を残さない) | ○ | 旧実装が無い (新規)。局所 `preventStrayRequests` 5 箇所は「同一既定の重複宣言」であり並走ではない旨をコメントで明示 |
| 思考原則 4 (別物の統合をしない) | ○ | `StrayLlmCallGuard` と統合せず並存させる (裁定の確定事項)。責務が違う (Prism provider 解決 vs HTTP 出口) |
| 思考原則 5 (テストファースト) | ○ | S2 → S1、S4 gate → S3 配線 の順で赤を確認してから実装する手順を明記 |
| 思考原則 6 (タコツボ実装を避ける) | ○ | Browser lane の in-process サーバ / bug-hunt の別プロセス / Socialite・SDK 直叩き / vitest レーンとの結合を全て明示的に評価し、効く範囲と効かない範囲を文書化した |
| コーディングルール (Pest / RefreshDatabase / Factory / DTO / 早期 return) | ○ | DB とモデルに触れないため Factory 不要。`DatabaseTransactions` を使わない。early return は `install()` の冪等分岐と `flushAndFailIfStray()` の空判定で使用 |
