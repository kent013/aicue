# アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 思考原則 — AGENTS.md より転記

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

# 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件固有の補足】
- 実体の framework は laravel/framework ^13.8。設計中の vendor 行番号はこのバージョンの実コードを読んで確認したもの。
- 本設計は **アプリコード (`app/`) を 1 行も変更しない**。変更は `tests/` + `AGENTS.md` + `docs/` に閉じる。
- UI / frontend の変更は無いため観点 10・11 は非該当のはず。もし該当箇所があれば指摘してほしい。
- 本件は複数リポジトリ共有の機能台帳 lctl における裁定 AG-105 への準拠であり、必須は 1 点のみ「テストレーンの既定として `Http::preventStrayRequests()` を常時有効にする + 自機宛て loopback を `Http::allowStrayRequests([...])` で明示許可する」。裁量項目 (資格情報の無効化 / 代替実装の到達性確認・未消費検出) は意図的にスコープ外。
- 概念設計は Codex conceptual-review Round 1 で APPROVED 済み。その Warning への対応 (install の冪等化 / Architecture lane の bootstrapping 確認 / 期待効果の限定 / 局所 allowlist 上書きの gate 化 / 負テスト追加 / exemption の型付け) は本詳細設計に反映済み。

---

## 詳細設計書

# 詳細設計: stray-http-egress-deny

> lctl feature id: `external-egress-default-deny` / 裁定 AG-105 (2026-08-06) 必須 1 点への準拠。
> 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex conceptual-review Round 1 で APPROVED)
> 実査ブリーフ: [`recon-brief.md`](./recon-brief.md)

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

use Illuminate\Http\Client\ConnectionException;
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

test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', ...);
test('case B: Http::fake([限定 URL]) 併用時、fake 対象は透過し未 fake の別 URL は stray になる', ...);
test('case C: Http::fake([*]) を張れば stray にならない', ...);
test('case D: loopback (127.0.0.1) は stray にならない (ConnectionException まで到達する)', ...);
test('case E: アプリ層の catch (Throwable) で握り潰しても accumulator に残る', ...);
test('case F: flushAndFailIfStray() は accumulator 非空で throw し finally で clear する', ...);
test('case G: install() は冪等 (2 回呼んでも 1 stray に対し記録は 1 件)', ...);
test('case H: 許可パターンは loopback ホストだけに一致する (127.0.0.1.evil.example を弾く)', ...);
test('case I: async 経路でも stray が accumulator に記録される', ...);
```

主要ケースの中身:

```php
test('case A: 未 fake の外向き HTTP は StrayRequestException + accumulator 記録', function (): void {
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

test('case D: loopback (127.0.0.1) は stray にならない (ConnectionException まで到達する)', function (): void {
    // ポート 9 (discard) は待ち受けが無いので即 ECONNREFUSED。
    // ★ここで期待するのは ConnectionException であって StrayRequestException **ではない**。
    //   「許可されて実際に送信段まで進んだ」ことの behavioral な証明になる。
    $caught = null;
    try {
        Http::connectTimeout(1)->timeout(1)->get('http://127.0.0.1:9/health');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ConnectionException::class);
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
| case D の `127.0.0.1:9` 接続が環境によって即座に refuse されずタイムアウト待ちになる | テストが 1 秒程度遅くなる | `connectTimeout(1)->timeout(1)` で上限を 1 秒に固定。全体で無視できる |
| CI コンテナで loopback 送信自体が禁止されている | case D が別の例外型で落ちる | `ConnectionException` は Laravel が `ConnectException` / レスポンス無し `RequestException` を包む型なので、refuse も送信禁止も同じ型に収まる (`PendingRequest::marshalConnectionException`)。それでも落ちたら**それは検出**であり、握り潰さず原因を直す |
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
            // ★2 つの guard は順に flush する。先に throw した方が失敗理由になり、
            //   もう一方の accumulator は finally の reset で捨てられるが、
            //   test は既に赤いので「静かに緑」にはならない (検出目的は達成される)。
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
 * 「opt-out 呼び出し (allowStrayRequests / preventStrayRequests(false)) を持つファイルは
 *  本 enum + 30 文字以上の具体的根拠付きで inventory に登録済みであること」を機械強制する。
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
 * PHP ソースからコメントを除去した「コードだけ」の文字列を返す (純関数)。
 *
 * PhpToken を使う (行頭 `//` の正規表現除去では行末コメントや docblock を取りこぼす)。
 * オフセットは再構成後の文字列内で一貫するので、順序検査にそのまま使える。
 */
function strayHttpEgressCode(string $source): string { /* PhpToken::tokenize → T_COMMENT / T_DOC_COMMENT を '' に */ }

/**
 * tests/Pest.php のコードを `pest()->extend(` 単位のチャンクへ分解する (純関数)。
 *
 * @return list<array{lanes: list<string>, body: string}>
 */
function strayHttpEgressLaneChunks(string $code): array { /* … */ }

/**
 * レーン既定配線の違反一覧 (純関数)。
 *
 * 各チャンクについて:
 *  - `StrayHttpRequestGuard::install(` が `->beforeEach(` より後・`->afterEach(` より前にある
 *  - `StrayHttpRequestGuard::flushAndFailIfStray(` が `->afterEach(` より後にある
 *  - `StrayHttpRequestGuard::reset(` が同じく `->afterEach(` より後にある
 * さらに STRAY_HTTP_EGRESS_REQUIRED_LANES が全て、いずれかのチャンクで覆われている。
 *
 * @param  list<array{lanes: list<string>, body: string}>  $chunks
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
 * tests/ 配下で opt-out 呼び出しを持つファイル一覧 (リポジトリルート相対、ソート済み)。
 *
 * 検出対象:
 *  - `allowStrayRequests(` (引数を問わず。null 渡しは prevent 自体を OFF にし、
 *     配列渡しは既定の許可集合を**置換**するため、どちらも既定を壊しうる)
 *  - `preventStrayRequests(false)` / `preventStrayRequests( false )`
 *
 * @return list<string>
 */
function strayHttpEgressOptOutSites(): array { /* Finder で tests/**\/*.php → strayHttpEgressCode() → 正規表現 */ }

test('tests/Pest.php の全レーンが StrayHttpRequestGuard を既定配線していること', /* … */);
test('許可 URL パターンが loopback ホストだけに閉じていること', /* … */);
test('opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)', /* … */);
test('exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)', /* … */);
test('exemption の根拠が 30 文字以上であること', /* … */);
test('exemption 件数が上限 (exact fit) を超えていないこと', /* … */);

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 */
test('負のコントロール: install を持たないレーンを検出する', /* … */);
test('負のコントロール: install が afterEach の後ろに来ている配線を検出する', /* … */);
test('負のコントロール: flush はあるが reset が無い配線を検出する', /* … */);
test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', /* … */);
test('負のコントロール: コメント内の install 記述では配線と認めない', /* … */);
test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) を検出する', /* … */);
test('負のコントロール: 外部ドメインの許可パターンを検出する', /* … */);
```

負のコントロールの中身 (代表 2 本):

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

    $chunks = strayHttpEgressLaneChunks(strayHttpEgressCode($fixture));
    $violations = strayHttpEgressLaneViolations($chunks);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) を検出する', function (): void {
    $violations = strayHttpEgressPatternViolations(['http://127.0.0.1*']);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('loopback に閉じていない');
});
```

### PHPStan 適合チェック

> ⚠ `tests/` は `phpstan.neon` の `paths` 外 (再掲)。以下は手動チェックリスト。

- [x] 全関数に戻り値型 (`array` は `@return list<string>` / `@return array<string, array{Enum, non-empty-string}>` の shape 付き)
- [x] `preg_match` の戻り値は `int|false` なので `!== 1` で比較する (真偽値の暗黙変換をしない)
- [x] `file_get_contents()` の `string|false` は `expect($source)->toBeString()` +
      `/** @var string $source */` で narrowing する (既存 `GlobalTestLockInventoryTest` と同形)
- [x] PCRE に `\R` を使う場合は `/u` 必須 (`PcreUnicodeModifierGateTest`)。
      本 gate は `\R` を使わない (PhpToken でコメントを落とすため行分割が不要) が、
      `#…#u` を既定で付ける
- [x] `PhpToken::tokenize()` の戻り値は `list<PhpToken>` として扱う
- [x] enum は backed string enum。`->value` でのみ文字列化する
- [x] DTO 返却は非該当

### テスト計画

- [ ] **赤の確認 (テストファースト)**: gate を先に追加し、S3 の配線を入れる**前**に
      `vendor/bin/pest tests/Architecture/StrayHttpEgressLaneGateTest.php` を実行 →
      「Feature/Unit lane が install していない」等で赤になることを確認
- [ ] 新規テスト: 上記 6 本の本体テスト + 7 本の負のコントロール
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Architecture lane は DB 不使用)
- [ ] Factory は使わない (モデルを作らない)

#### mutation で赤化を確認する手順 (「素の main では赤にならない gate」の受け入れ)

本 gate も S2 の自己検査も、**正しい状態では常に緑**であり、放置すると空振りに気づけない。
負のコントロール (fixture) は「純関数が壊れた入力を検出できる」ことしか示さないので、
**実ファイルに対して gate が効いているか**は mutation で確認する。
実装 PR の説明に、以下 7 本の実施結果 (赤くなったテスト名) を記録する。

| # | mutation (一時変更 → 必ず復元) | 期待して赤くなるもの |
|---|------|------|
| M1 | `tests/Pest.php` の Feature/Unit lane から `StrayHttpRequestGuard::install($this->app);` を削除 | gate「全レーンが既定配線」 |
| M2 | 同 install 行を `->afterEach(` の後ろへ移動 | gate「全レーンが既定配線」(順序違反) |
| M3 | `ALLOWED_URL_PATTERNS` に `'https://api.frankfurter.dev/*'` を追加 | gate「許可パターンが loopback に閉じている」 |
| M4 | `ALLOWED_URL_PATTERNS` の `'http://127.0.0.1:*'` を `'http://127.0.0.1*'` に変更 | gate「許可パターン」 + S2 case H |
| M5 | `tests/Feature/Security/AuthThrottleCoverageTest.php` に `Http::allowStrayRequests(['*']);` を追加 | gate「opt-out が inventory 登録済み」 |
| M6 | guard の `__invoke` から `self::$strayRequests[] = …` を削除 | S2 case A / E / I (握り潰し貫通) |
| M7 | inventory から `tests/Support/StrayHttpRequestGuard.php` を削除 / 架空パスを追加 | gate「未登録」/ gate「形骸化ガード」 |

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| `tests/Pest.php` のチャンク分割が将来の書き方 (`pest()->extend()` を変数へ代入等) で壊れる | gate が偽赤 or 偽緑 | 偽赤は書いた瞬間に気づける。偽緑側は「必須レーンが全て覆われていること」の検査が残るため、チャンクが取れなければレーン未充足で赤になる (fail-closed) |
| 走査器自身の除外 (`STRAY_HTTP_EGRESS_SCANNER_SELF`) が抜け道になる | gate ファイル内で opt-out すれば検出されない | gate ファイルは Architecture lane で HTTP を出さない。かつ除外は定数 1 本で可視。GlobalTestLockInventoryTest と同じ受容 |
| exemption cap が exact fit=1 のため、正当な 2 本目でも一度赤くなる | 実装者の手間 | それが狙い (再検討の強制)。ThrottleCoverageInventoryTest と同じ設計 |
| `Finder` で `tests/**` を毎回走査するコスト | Architecture lane が数十 ms 遅くなる | 既存 gate (`DirectFetchInventory` は `app` + `routes` 全走査) と同程度。許容 |

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

---

## 関連する現行コード

### `tests/Pest.php` (L1-110 抜粋。以降はヘルパ関数群のため省略)

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Providers\FakeExternalsServiceProvider;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\PersonalPlanService;
use App\Services\Organization\OrganizationProvisioningService;
use App\Services\Storage\Fakes\FakeObjectStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Kent013\PrismPrompt\Prompt;
use Laravel\Cashier\Subscription;
use Tests\Support\StrayLlmCallGuard;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature / Unit は TestCase + RefreshDatabase。
| Architecture はファイル走査中心のため DB を使わない (TestCase のみ)。
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Vite manifest 不在でも view が描画できるよう test では Vite をスタブする
        $this->withoutVite();

        // 未 fake の LLM 呼び出しを fail-fast させる guard。
        // (1) accumulator clear → (2) Prompt::stopFaking() → (3) PrismManager 差し替え
        // の 3 段で前テスト残留状態を一掃しつつ install する。テスト本体で
        // Prism::fake([...]) / Prompt::fake([...]) を呼ぶと guard は透過される。
        // Prism 基盤を直接テストする稀な Unit テストのみ
        // StrayLlmCallGuard::uninstallForTest($this->app) で opt-out できる。
        StrayLlmCallGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            StrayLlmCallGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
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

/*
| Browser lane (pest-plugin-browser / Playwright)。phpunit.browser.xml +
| scripts/run-browser-test.sh (composer test:browser) 経由でのみ動く
| (既定 phpunit.xml の testsuite には含まれない)。in-process サーバが
| テストプロセス自身の HttpKernel を叩くため TestCase + RefreshDatabase で動く。
| 詳細は docs/testing-browser.md。
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Browser は実ブラウザが public/build のビルド済アセットを読むため
        // withoutVite() は絶対に適用しない (pnpm build 前提)。代わりに、dev の
        // vite dev server が出す public/hot を読んで白画面になる事故を防ぐため
        // hot file の参照先を存在しないパスへ逃がす。
        Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled'));

        // Feature/Unit と同じ stray LLM guard を適用する (in-process サーバの
        // リクエスト処理はテストプロセス内で走るため、未 fake の LLM 呼び出しは
        // accumulator に記録され afterEach で fail する)。
        StrayLlmCallGuard::install($this->app);

        // Browser lane は Prompt を常時 canned fake 化する (SystemMessage signature 別の
        // 決定論応答。未登録の Prompt から呼ばれると fail-fast)。canned PromptFake は
        // Browser lane と bughunt 実行時の両方で共有 (registrar 参照)。install() 内の
        // stopFaking の後に上書きインストールするのが load-bearing。
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

/*
```

### `tests/Support/StrayLlmCallGuard.php` (全文。本設計が API 形を揃える対象)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use RuntimeException;

/**
 * テスト中に未 fake の LLM 呼び出しを runtime で検知する PrismManager 差し替え guard。
 *
 * 仕組み:
 *  1. resolve() を override し、static accumulator に stray call を記録 → RuntimeException を throw。
 *  2. tests/Pest.php の beforeEach で install(), afterEach で flushAndFailIfStray() を呼ぶ。
 *  3. テスト本体で Prism::fake([...]) / Prompt::fake([...]) を呼ぶと PrismManager binding が
 *     上書きされ、または Prompt 層で short-circuit するため guard は無効化される。
 *  4. Service 層の try/catch fallback で例外が握り潰されても accumulator に残るため
 *     afterEach の flushAndFailIfStray() で必ず test を fail させる (= 主防御の核)。
 *
 * phpunit.xml の API キーダミー値強制 (OPENAI_API_KEY 等) は本 guard が万一
 * 無効化された場合の最終防壁 (tests/Feature/Config/PrismApiKeyDummyTest が到達を検証)。
 */
final class StrayLlmCallGuard extends PrismManager
{
    /** @var list<array{provider: string, providerConfig: array<string, mixed>}> */
    private static array $strayCalls = [];

    /**
     * Pest beforeEach から呼ぶ。前テストの残留状態を clear したうえで guard を install する。
     *
     * 順序:
     *  (1) accumulator を空にする (前テスト異常終了で残った記録を捨てる)
     *  (2) Prompt::stopFaking() で Kent013\PrismPrompt\Prompt::$fake static を reset
     *      (前テストの Prompt::fake が次テストにリークすると、本来 guard が catch すべき
     *       fake 漏れが Prompt 層で short-circuit して見逃される)
     *  (3) 既解決の PrismManager singleton を破棄
     *  (4) guard を PrismManager binding に差し込む
     */
    public static function install(Application $app): void
    {
        self::$strayCalls = [];
        Prompt::stopFaking();
        $app->forgetInstance(PrismManager::class);
        $app->instance(PrismManager::class, new self($app));
    }

    /**
     * Pest afterEach から呼ぶ。stray call が記録されていれば RuntimeException を throw して
     * test を fail させる。Service 層の try/catch fallback で例外が握り潰されても
     * このパスで必ず CI が赤くなるのが本 guard の存在意義。
     *
     * accumulator は finally で必ず clear する (process 内の後続テストへの二次被害を防ぐ)。
     */
    public static function flushAndFailIfStray(): void
    {
        try {
            if (self::$strayCalls === []) {
                return;
            }
            $summary = self::summarize(self::$strayCalls);
            throw new RuntimeException(
                'Stray LLM call detected during test execution. '
                .'Did you forget to call Prism::fake([...]) or Prompt::fake([...]) in the test body? '
                .PHP_EOL.$summary
            );
        } finally {
            self::$strayCalls = [];
        }
    }

    /**
     * accumulator を空に戻す。afterEach の finally から呼び、flushAndFailIfStray() が
     * throw した場合でも次テストへ残留状態を漏らさないことを保証する。
     */
    public static function reset(): void
    {
        self::$strayCalls = [];
    }

    /**
     * self-test 用 drain。意図的に stray call を発生させるテストで、global afterEach に
     * 到達する前に accumulator を取り出して clear する。
     *
     * @return list<array{provider: string, providerConfig: array<string, mixed>}>
     */
    public static function drainForAssertion(): array
    {
        $drained = self::$strayCalls;
        self::$strayCalls = [];

        return $drained;
    }

    /**
     * Prism 基盤を直接テストする Unit テストで guard 自体を opt-out するための helper。
     * Prism 基盤を直接テストする Unit テストでのみ使用する (通常テストでの opt-out 禁止)。
     */
    public static function uninstallForTest(Application $app): void
    {
        Prompt::stopFaking();
        $app->forgetInstance(PrismManager::class);
        self::$strayCalls = [];
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    #[\Override]
    public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        $providerName = $name instanceof ProviderEnum ? $name->value : $name;
        self::$strayCalls[] = [
            'provider' => strtolower($providerName),
            'providerConfig' => $providerConfig,
        ];

        throw new RuntimeException(
            "Stray LLM call to provider [{$providerName}]. "
            .'Call Prism::fake([...]) or Prompt::fake([...]) in the test body before the LLM call.'
        );
    }

    /**
     * @param  list<array{provider: string, providerConfig: array<string, mixed>}>  $calls
     */
    private static function summarize(array $calls): string
    {
        $lines = [];
        foreach ($calls as $i => $call) {
            $lines[] = sprintf('  [%d] provider=%s', $i + 1, $call['provider']);
        }

        return implode(PHP_EOL, $lines);
    }
}
```

### `tests/Feature/Support/StrayLlmCallGuardTest.php` (全文。自己検査の見本)

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake as PromptTextResponseFake;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\Support\Prompts\MinimalLlmCallPrompt;
use Tests\Support\StrayLlmCallGuard;

/**
 * StrayLlmCallGuard の self-test。
 *
 * 各テスト末尾で `StrayLlmCallGuard::drainForAssertion()` を呼んで accumulator を空にしておかないと、
 * tests/Pest.php の global afterEach (`flushAndFailIfStray()`) が test 自身を fail させてしまう。
 * drain することで「stray call が記録された」事実を assert したうえで accumulator を空にする。
 */
beforeEach(function (): void {
    // executeSync は fake 中も PromptExecutionCompleted を発火し、listener → writer が
    // FX 解決 (HTTP) を試みるため stray request を防ぐ
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

test('case A: fake なしで Prompt サブクラスを呼ぶと guard が RuntimeException + accumulator 記録', function (): void {
    $prompt = new MinimalLlmCallPrompt;

    $threw = false;
    try {
        $prompt->executeSync();
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Stray LLM call');
    }

    expect($threw)->toBeTrue('expected RuntimeException from guard');

    $drained = StrayLlmCallGuard::drainForAssertion();
    expect($drained)->toHaveCount(1)
        ->and($drained[0]['provider'])->toBe('openai');
});

test('case B: Prism::fake([...]) を install すれば guard は無効化される', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('hello')->withUsage(new Usage(10, 5)),
    ]);

    $result = (new MinimalLlmCallPrompt)->executeSync();

    expect($result)->toBe('hello');
    expect(StrayLlmCallGuard::drainForAssertion())->toBe([]);
});

test('case C: Prompt::fake([...]) を install すれば guard は無効化される (Prompt 層 short-circuit)', function (): void {
    Prompt::fake([
        PromptTextResponseFake::make()->withText('prompt-fake-ok'),
    ]);

    $result = (new MinimalLlmCallPrompt)->executeSync();

    expect($result)->toBe('prompt-fake-ok');
    expect(StrayLlmCallGuard::drainForAssertion())->toBe([]);
});

test('case D: Prism::text() 直叩き経由でも guard が効く', function (): void {
    $threw = false;
    try {
        Prism::text()->using('openai', 'gpt-4o-mini');
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Stray LLM call');
    }

    expect($threw)->toBeTrue();
    $drained = StrayLlmCallGuard::drainForAssertion();
    expect($drained)->toHaveCount(1);
});

test('case E: try/catch で guard 例外を握り潰しても accumulator に残る (= afterEach で必ず fail する)', function (): void {
    // Service 層の `try { executeSync() } catch (Throwable) { fallback }` を再現
    try {
        (new MinimalLlmCallPrompt)->executeSync();
    } catch (Throwable) {
        // swallow (fallback の挙動を模す)
    }

    // 握り潰しても accumulator に記録されている = global afterEach の flushAndFailIfStray() が
    // ここで test 自身を fail させる契機。本 test では drain して afterEach を素通りさせ、
    // 「accumulator に 1 件記録された」事実だけを assert する。
    $drained = StrayLlmCallGuard::drainForAssertion();
    expect($drained)->toHaveCount(1, 'guard が握り潰されても accumulator は記録する');
});

test('case F: flushAndFailIfStray() は accumulator 非空のとき RuntimeException を throw + finally で clear する', function (): void {
    // 握り潰し
    try {
        (new MinimalLlmCallPrompt)->executeSync();
    } catch (Throwable) {
        // swallow
    }

    $threw = false;
    try {
        StrayLlmCallGuard::flushAndFailIfStray();
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Stray LLM call detected');
    }
    expect($threw)->toBeTrue('flushAndFailIfStray must throw when accumulator is non-empty');

    // finally で accumulator が clear されていることを確認
    expect(StrayLlmCallGuard::drainForAssertion())->toBe([]);
});
```

### `tests/Architecture/GlobalTestLockInventoryTest.php` (L1-160 抜粋。deny-by-default 目録型 gate の見本)

```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: 全テストレーンがグローバルテストロックを経由すること。
 *
 * 背景 (SoT = devnotes/20260804-2319-global-test-lock/conceptual-design.md):
 * 複数 worktree の並行実装でテストレーンが同時に走ると、PostgreSQL サーバ・実ブラウザ・
 * CPU/メモリを奪い合い、Browser lane の machine-wide な playwright 掃除が他レーンの
 * run-server を巻き込む。旧実装は worktree-local な flock (cross-worktree 排他ゼロ) かつ
 * flock -n (待たずに即エラー) だったため、これを scripts/global-test-lock.sh へ一本化した。
 *
 * worktree-local flock を「残さず削除する」判断が安全なのは、公式 entrypoint を
 * **全て確実に包めている場合に限る**。よって本テストは deny-by-default の inventory とする:
 * composer.json / package.json の test 系スクリプトは、明示 exemption に無い限り
 * ロック経由でなければ fail する (新レーン追加時に落ちて気づける)。
 *
 * 並行挙動そのものは scripts/verify-global-test-lock.sh (層 1) が検証する。
 * **本テストから層 1 を実行してはならない**: 本テストは composer test の内側
 * = グローバルロック保持中に走るため、自分自身と競合する。
 */

/** watch / 対話用途のため意図的にラップしない script と、その理由。 */
const GLOBAL_TEST_LOCK_EXEMPT = [
    'test:ui' => 'vitest --ui (常駐 UI サーバ)。無期限にロックを保持するため対象外',
    'test:watch' => 'vitest --watch (常駐 watch)。同上',
];

/** ロック経由と認められる呼び出し先 (これ自身がライブラリを source していることも検査する)。 */
const GLOBAL_TEST_LOCK_LANE_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * 構造検査の対象スクリプト = lane スクリプト 3 本 + 汎用ラッパ。
 * ラッパを対象外にすると、将来 `exec "$@"` へ戻されても層 2 は
 * 「存在し実行可能」だけで通過してしまう (ロックが即解放される致命的回帰を見逃す)。
 * ライブラリ本体 (scripts/global-test-lock.sh) は対象外 —
 * trap / exec fd リダイレクトを**正当に持つ唯一のファイル**だから。
 */
const GLOBAL_TEST_LOCK_GUARDED_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
    'scripts/with-global-test-lock.sh',
];

/**
 * JSON の scripts セクションを「script 名 => コマンド文字列」へ正規化する (純関数)。
 * composer.json は配列形式を採るため、改行連結して 1 文字列にする。
 *
 * @return array<string, string>
 */
function globalTestLockScriptsFromJson(string $json): array
{
    /** @var mixed $decoded */
    $decoded = json_decode($json, true);
    if (! is_array($decoded)) {
        return [];
    }

    /** @var mixed $scripts */
    $scripts = $decoded['scripts'] ?? null;
    if (! is_array($scripts)) {
        return [];
    }

    $normalized = [];
    /** @var mixed $command */
    foreach ($scripts as $name => $command) {
        $lines = is_array($command) ? $command : [$command];
        /** @var array<array-key, mixed> $lines */
        $normalized[(string) $name] = implode("\n", array_map(
            static fn (mixed $line): string => is_scalar($line) ? (string) $line : '',
            $lines,
        ));
    }

    return $normalized;
}

/**
 * composer.json / package.json の test 系 script が全てロック経由かを検査する (純関数)。
 *
 * @param  array<string, string>  $scripts  script 名 => コマンド文字列 (配列形式は改行連結済み)
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneViolations(array $scripts): array
{
    $violations = [];

    foreach ($scripts as $name => $command) {
        if ($name !== 'test' && ! str_starts_with($name, 'test:')) {
            continue;
        }
        if (array_key_exists($name, GLOBAL_TEST_LOCK_EXEMPT)) {
            continue;
        }
        // 部分一致で通すと `with-global-test-lock.sh true && unlocked-test` のような
        // 「ラッパ名は含むが実体は無ロック」が素通りする。
        // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
        // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $command) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if (preg_match('/(&&|\|\||;|(?<!\|)\|(?!\|))/', $last) === 1) {
            $violations[] = "script '{$name}' がロック配下のコマンドをシェル演算子で連結している: {$last}";

            continue;
        }

        $entrypoints = array_merge(['scripts/with-global-test-lock.sh'], GLOBAL_TEST_LOCK_LANE_SCRIPTS);
        $viaEntrypoint = false;
        foreach ($entrypoints as $entrypoint) {
            if (preg_match('#^bash\s+'.preg_quote($entrypoint, '#').'(?:\s|$)#', $last) === 1) {
                $viaEntrypoint = true;
                break;
            }
        }
        if (! $viaEntrypoint) {
            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$last}";
        }
    }

    return $violations;
}

/**
 * shell ソースから **実行行だけ** を取り出す (純関数)。
 *
 * 全ての静的検査はこの結果を単一の解析入力として使う。変更後スクリプトは
 * 「旧 worktree-local な test.lock を廃止した」「flock -n をやめた」といった説明を
 * **コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる。
 *
 * 行頭 (空白を除く) が `#` の行だけを落とす。行末コメントの除去はしない —
 * `'#'` のような引用符内の `#` を壊してコードを誤って削るリスクの方が大きい。
 */
function globalTestLockCodeLines(string $source): string
{
    // `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
    // 文字途中で分断して「コメント断片がコードとして漏出する」(PcreUnicodeModifierGateTest)。
    $lines = preg_split('/\R/u', $source) ?: [];
    $code = array_filter(
        $lines,
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    );

    return implode("\n", $code);
}

/**
 * `CI` 環境変数の参照禁止を検査する対象 = ロック機構の全ファイル (ライブラリ本体を含む)。
 *
 * 「CI では素通り」の分岐は、**正しさが最も要求される場所に、ローカルでは一度も
```

### `app/Enums/Security/ThrottleCoverageExemption.php` (L1-50 抜粋。型付き exemption enum の見本)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「保護対象群に属する route が throttle を持たないことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/ThrottleCoverageInventoryTest.php` が deny-by-default で
 * 「throttle ちょうど 1 本」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「throttle を貼るべき route」である。
 */
enum ThrottleCoverageExemption: string
{
    /**
     * 定数メタデータ応答。
     *
     * 適用条件: DB アクセス・暗号処理・外部呼び出し・メール送信・ファイル書込を一切伴わず、
     * 応答が config と url() だけで決まる。
     */
    case StaticMetadataResponse = 'static_metadata_response';

    /**
     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
     *
     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
     */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';

    /**
     * セッション破棄のみを行い、推測可能な秘密を一切扱わない route。
     *
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

```

### `tests/Architecture/ThrottleCoverageInventoryTest.php` (L30-105 抜粋。cap / 根拠最低文字数 / inventory 形式)

```php
 */

/** 変更系 HTTP メソッド。 */
function throttleCoverageMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/** 認証面の route 名パターン (S3)。 */
function throttleCoverageAuthSurfacePattern(): string
{
    return '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
        .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#';
}

/** 母集団件数の下限 (空振り drift ガード。実測 70 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 60;
}

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

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function throttleCoverageReasonMinLength(): int
{
    return 30;
}

/**
 * throttle を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ThrottleCoverageExemption, string}>
 */
function throttleCoverageExemptions(): array
{
    $metadata = ThrottleCoverageExemption::StaticMetadataResponse;
    $stub = ThrottleCoverageExemption::VendorMethodNotAllowedStub;
    $teardown = ThrottleCoverageExemption::SessionTeardownOnly;
    $localOnly = ThrottleCoverageExemption::LocalOnlyDebugRoute;
    $component = ThrottleCoverageExemption::ComponentLevelLimiter;
    $signature = ThrottleCoverageExemption::SignatureRequiredBeforeEffect;
    $render = ThrottleCoverageExemption::AuthViewRenderOnly;
    $flowInit = ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall;

    return [
```

### `tests/Support/Security/PrimaryKeyPredicateKind.php` (全文。tests 側 enum の置き場の前例)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 主キー述語の種別。
 *
 * `findMany($ids)` と `findOrFail($id)` を同じ扱いにすると、
 * identity 引数を単数前提で判定する副条件 (provenance 除外など) が破綻するため分けている。
 */
enum PrimaryKeyPredicateKind
{
    /** `find` / `findOrFail` / `findOrNew` / `whereKey` / `where('id', …)` / `firstWhere('id', …)` */
    case SingleIdentity;

    /** `findMany` / `whereIn('id', …)` / `where('id', 'in', …)` */
    case MultiIdentity;

    /** `whereKeyNot` — 「同一性」ではなく除外条件 (列挙ベクタになりうる) */
    case IdentityExclusion;

    /** `destroy` — 取得ではなく削除 */
    case DestructiveIdentity;
}
```

### `tests/Feature/Auth/RegistrationTest.php` (L40-60 抜粋。S5 で書き換える棄却理由コメント)

```php
    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
});

test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
    // HIBP エンドポイントのみ intercept して実ネットワークを遮断する
    // (preventStrayRequests は合法な他 HTTP まで例外化するため使わない = 過検出回避)。
    // uncompromised は NotPwnedVerifier (Http client factory 経由) のため Http::fake で捕捉できる。
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'newuser@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1', // 既存 RegistrationTest と同じ表現 (Fortify 契約)
    ]);

    // シナリオ成立を固定 (別要因の早期失敗で「未送信」だけ通るのを防ぐ)。
    // 既存「登録できる」テストと同じく verification.notice へ誘導される。
    $response->assertSessionHasNoErrors();
```

### `tests/Feature/Security/AuthThrottleCoverageTest.php` (L260-275 抜粋。S5 の位置づけコメント対象)

```php
});

test('social.callback の throttle は Socialite を一切呼ばずに枠を消費する (外向き HTTP の増幅が有界)', function (): void {
    // 「throttle が外向き HTTP より前にある」ことの本体。
    // intent 不在で controller が Socialite に触れる前に短絡することを spy で直接示す
    // (Socialite は Guzzle を直接使うため Http::preventStrayRequests() では捕まらない。
    //  preventStrayRequests は Laravel HTTP client 側の追加の網として併用する)。
    Http::preventStrayRequests();
    Socialite::spy();

    $first = $this->get('/auth/google/callback?code=dummy&state=dummy');
    $second = $this->get('/auth/google/callback?code=dummy&state=dummy');

    // ★「Socialite を呼ばない」だけでは半分。**枠を消費している**ことまで示して初めて
    //   「外向き HTTP の増幅が有界」になる (呼ばれず数えられもしないなら無制限に踏める)。
    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
```

### `app/Services/FxRateService.php` (L54-103 抜粋。握り潰しの実例 1)

```php

    private function fetchFromFrankfurter(): ?FxSnapshotDto
    {
        try {
            $url = config('llm-pricing.frankfurter_url', 'https://api.frankfurter.dev/v1/latest');
            Assert::string($url);

            $timeout = config('llm-pricing.frankfurter_timeout', 2);
            Assert::integer($timeout);

            $connectTimeout = config('llm-pricing.frankfurter_connect_timeout', 1);
            Assert::integer($connectTimeout);

            /** @var mixed $response */
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->get($url, ['base' => 'USD', 'symbols' => 'JPY'])
                ->throw()
                ->json();

            if (! is_array($response)
                || ! isset($response['rates'])
                || ! is_array($response['rates'])
                || ! isset($response['rates']['JPY'])
                || ! is_numeric($response['rates']['JPY'])
            ) {
                Log::warning('Frankfurter response malformed', ['response' => $response]);

                return null;
            }

            $rate = (float) $response['rates']['JPY'];
            if ($rate <= 0) {
                Log::warning('Frankfurter returned non-positive rate', ['rate' => $rate]);

                return null;
            }

            return new FxSnapshotDto(
                rate: $rate,
                pair: 'USDJPY',
                source: 'frankfurter',
                fetchedAt: CarbonImmutable::now(),
            );
        } catch (Throwable $e) {
            Log::warning('Frankfurter fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
```

### `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` (L52-72 抜粋。握り潰しの実例 2)

```php
     *
     * @return callable(string): string
     */
    private function certClient(): callable
    {
        return function (string $url): string {
            try {
                return $this->http
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->get($url)
                    ->throw()
                    ->body();
            } catch (\Throwable $e) {
                throw new SnsVerificationUnavailableException('certificate fetch failed', 0, $e);
            }
        };
    }

    private function certUrl(Message $message): string
```

### `app/Services/Captcha/RecaptchaVerifier.php` (L30-60 抜粋。ConnectionException のみ catch)

```php
    public function verify(?string $token, ?string $ip): bool
    {
        if ($token === null || $token === '') {
            // token なし送信は許可しない (captcha bypass を作らない)。fail-closed。
            return false;
        }

        $secret = config('services.recaptcha.secret_key');
        if (! is_string($secret) || $secret === '') {
            // secret 未設定 (設定漏れ)。production のみ fail-closed。
            $allowed = ! app()->environment('production');
            $this->reportUnavailable('missing_secret', $allowed);

            return $allowed;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ], static fn (?string $v): bool => $v !== null && $v !== ''));
        } catch (ConnectionException) {
            // transport error / timeout → fail-open。
            $this->reportUnavailable('transport', allowed: true);

            return true;
        }

```

### `phpstan.neon` (L1-20。tests が paths に含まれない事実)

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 10
    paths:
        - app
        - config
        - database
        - routes
    excludePaths:
        - vendor
    ignoreErrors:
        # AppliesCriticalActionContextToAudit は派生アプリの Auditable モデル向けに
        # テンプレートが同梱する trait (テンプレート本体は Auditable モデルを同梱しない
        # ため使用箇所ゼロ)。派生アプリで使用された時点で通常解析される。
        # 実挙動は tests/Feature/Audit/ModelAuditGatingTest.php が検証している。
        -
            identifier: trait.unused
            path: app/Models/Concerns/AppliesCriticalActionContextToAudit.php
```

### vendor 抜粋: `Illuminate\Http\Client\PendingRequest::pushHandlers()` と `buildStubHandler()`

```php
    /**
     * Add the necessary handlers to the given handler stack.
     *
     * @param  \GuzzleHttp\HandlerStack  $handlerStack
     * @return \GuzzleHttp\HandlerStack
     */
    public function pushHandlers($handlerStack)
    {
        return tap($handlerStack, function ($stack) {
            $this->middleware->each(function ($middleware) use ($stack) {
                $stack->push($middleware);
            });

            $stack->push($this->buildBeforeSendingHandler());
            $stack->push($this->buildRecorderHandler());
            $stack->push($this->buildStubHandler());
        });
    }

    /**
     * Build the before sending handler.
...
    /**
     * Build the stub handler.
     *
     * @return \Closure
     *
     * @throws \Illuminate\Http\Client\Exceptions\StrayRequestException
     */
    public function buildStubHandler()
    {
        return function ($handler) {
            return function ($request, $options) use ($handler) {
                $response = ($this->stubCallbacks ?? new Collection)
                    ->map
                    ->__invoke(
                        (new Request($request))
                            ->withData($options['laravel_data'] ?? [])
                            ->setRequestAttributes($this->attributes),
                        $options
                    )
                    ->filter()
                    ->first();

                if (is_null($response)) {
                    if (! $this->isAllowedRequestUrl((string) $request->getUri())) {
                        throw new StrayRequestException((string) $request->getUri());
                    }

                    return $handler($request, $options);
                }

                $response = is_array($response) ? Factory::response($response) : $response;

                $sink = $options['sink'] ?? null;

                if ($sink) {
                    $response->then($this->sinkStubHandler($sink));
                }

                return $response;
            };
        };
    }
...
    {
        $this->preventStrayRequests = $prevent;

        return $this;
    }

    /**
     * Allow stray, unfaked requests entirely, or optionally allow only specific URLs.
     *
     * @param  array<int, string>  $only
     * @return $this
     */
    public function allowStrayRequests(array $only)
    {
        $this->allowedStrayRequestUrls = array_values($only);

        return $this;
    }

    /**
     * Determine if the given URL is allowed as a stray request.
     *
     * @param  string  $url
     * @return bool
     */
    public function isAllowedRequestUrl($url)
    {
        if (! $this->preventStrayRequests) {
            return true;
        }

        foreach ($this->allowedStrayRequestUrls as $pattern) {
            if (Str::is($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
```

### vendor 抜粋: `Illuminate\Http\Client\Factory` の allowStrayRequests / createPendingRequest / globalMiddleware

```php
    /**
     * Add middleware to apply to every request.
     *
     * @param  callable  $middleware
     * @return $this
     */
    public function globalMiddleware($middleware)
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    /**
     * Add request middleware to apply to every request.
     *
     * @param  callable  $middleware
     * @return $this
     */
    public function globalRequestMiddleware($middleware)
    {
        $this->globalMiddleware[] = Middleware::mapRequest($middleware);

        return $this;
    }

    /**
     * Add response middleware to apply to every request.
     *
     * @param  callable  $middleware
     * @return $this
     */
    public function globalResponseMiddleware($middleware)
    {
        $this->globalMiddleware[] = Middleware::mapResponse($middleware);

        return $this;
    }

    /**
     * Set the options to apply to every request.
...
     *
     * @return bool
     */
    public function preventingStrayRequests()
    {
        return $this->preventStrayRequests;
    }

    /**
     * Allow stray, unfaked requests entirely, or optionally allow only specific URLs.
     *
     * @param  array<int, string>|null  $only
     * @return $this
     */
    public function allowStrayRequests(?array $only = null)
    {
        if (is_null($only)) {
            $this->preventStrayRequests(false);

            $this->allowedStrayRequestUrls = [];
        } else {
            $this->allowedStrayRequestUrls = array_values($only);
        }

        return $this;
    }

    /**
     * Begin recording request / response pairs.
     *
     * @return $this
     */
    public function record()
    {
        $this->recording = true;

...
    /**
     * Create a new pending request instance for this factory.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    public function createPendingRequest()
    {
        return tap($this->newPendingRequest(), function ($request) {
            $request
                ->stub($this->stubCallbacks)
                ->preventStrayRequests($this->preventStrayRequests)
                ->allowStrayRequests($this->allowedStrayRequestUrls);
        });
    }

    /**
     * Instantiate a new pending request instance for this factory.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function newPendingRequest()
    {
        return (new PendingRequest($this, $this->globalMiddleware))->withOptions(value($this->globalOptions));
    }

    /**
     * Get the current event dispatcher implementation.
     *
     * @return \Illuminate\Contracts\Events\Dispatcher|null
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * Get the array of global middleware.
     *
     * @return array
     */
    public function getGlobalMiddleware()
    {
        return $this->globalMiddleware;
    }

    /**
     * Execute a method against a new pending request instance.
     *
```
