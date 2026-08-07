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

# 役割・タスク

あなたは Laravel 12 + Inertia (Svelte 5) アプリ **aicue** の詳細設計レビュアである。
対象は詳細設計 `devnotes/20260807-1235-inertia-error-screen-contract/detailed-design.md`
(Inertia XHR の 4xx/5xx を Error 画面へ差し替える契約)。

本ラウンドは **確認ラウンド (Round 6)** である。
Round 5 (`detailed-review-round-5.md`) の判定は **CHANGES_REQUESTED**、
残件は **S4 の [Warning] 1 件のみ** ([Critical] 0 件。S1 / S2 / S3 / S5 / S6 は APPROVE 済み)。
その 1 件への対応を詳細設計へ反映済みなので、**その対応で指摘が解消しているかだけ**を判定せよ。

判定のルール:

1. Round 5 の [Warning] について **解消 / 未解消** を明示せよ。未解消なら何が足りないかを具体的に書け。
2. **全体判定を `APPROVED` または `CHANGES_REQUESTED` のいずれかで返せ**。
3. 解消していない指摘があるときだけ、その指摘を挙げよ。**新しい観点の粗探しを目的にしない** —
   ただし、今回の対応そのものが持ち込んだ **新規の [Critical]** (対応によって壊れたもの) があれば挙げてよい。
4. 既に APPROVE 済みの S1 / S2 / S3 / S5 / S6 を蒸し返さない。
5. 設計を不必要に複雑にする提案は避けよ (思考原則 2「今必要なものだけ作る」)。
   承認条件でない改善案は `[Suggestion]` と明記せよ。
6. 実装コードはまだ 1 行も書かれていない (設計のみ)。リポジトリの既存コードは読んでよい。

出力形式:

```
## Round 5 指摘の解消判定
### S4 [Warning] 「既存の Cache-Control directive を落とさない」テストが実装構造と一致していない → 解消 / 未解消
（理由）

## 新規 [Critical] (対応が持ち込んだもの。無ければ「なし」)

## 全体判定
APPROVED / CHANGES_REQUESTED
```

---

# 1. Round 5 の指摘 (原文)

> ### S4: REQUEST_CHANGES
>
> [Warning] 「既存の Cache-Control directive を落とさない」テストの準備方法が、実装構造と一致していません。
>
> `render()` は原応答を変更せず、新しい Inertia 応答を生成しています。
>
> ```php
> $rendered = Inertia::render(...)
>     ->toResponse($request);
> ```
>
> そのため、テスト用 route や `render()` へ渡す原応答に `must-revalidate` を設定しても、
> そのdirectiveは `$rendered` へ移植されません。現在案のテストは `set()` への退行ではなく、
> 「原応答の Cache-Control を allowlist 移植していない」という別契約で失敗します。
>
> 修正案は次のいずれかです。
>
> - キャッシュポリシー適用をテスト可能な小さなメソッドへ切り出し、`must-revalidate` を持つ対象レスポンスへ直接適用して保持を検証する。
> - Inertia が生成した `$rendered` に既存 directive が確実に存在することを確認できるなら、その実 directive の保持を Feature テストで固定する。
> - 原応答の Cache-Control も移植する要件なら、allowlist へ明示的に追加する。ただし現在の deny-by-default 方針から契約変更になるため、単にテストを通す目的では追加しない。
>
> (中略) private メソッドを直接テストするためだけに reflection を使うのは避け、
> Feature テストでは Inertia 生成応答が持つ既存 directive を事前に特定するか、
> **キャッシュポリシー自体を独立した小さな Support クラスとして扱う**かを選んでください。
> 後者は既存 directive 保持を明確な契約にする場合に限り妥当です。
>
> ## 全体判定
> **CHANGES_REQUESTED**
> 残件はテストセットアップの成立性だけです。

---

# 2. Round 5 の指摘に対する対応マトリクス (Claude 側の判断)

## [Warning] S4: 「既存の Cache-Control directive を落とさない」テストが成立していない

- **判断: 対応する** (Codex 提示の 3 案のうち「独立した小さな Support クラスへ切り出す」を採用)
- **根拠**: 指摘は完全に正当で、こちらのテスト設計の誤りだった。
  `render()` は原応答を変更せず新しい Inertia 応答を生成するため
  (`Inertia::render(...)->toResponse($request)`)、原応答へ `must-revalidate` を積んでも
  `$rendered` には移植されない。つまり前案のテストは「`set()` への退行」ではなく
  「原応答の Cache-Control を allowlist 移植していない」という **別契約**で失敗する。
  テストが検出したい対象と、失敗する理由が食い違っていた。
- **案の選択**:
  - 「原応答の Cache-Control も移植する」案は**採らない**。ヘッダ移植は allowlist
    (deny-by-default) で `Retry-After` のみと決めており、テストを通す目的で契約を広げるのは
    本末転倒 (Codex 自身も「単にテストを通す目的では追加しない」と付言)。
  - 「Inertia 生成応答が持つ実 directive の保持を Feature で固定する」案も**採らない**。
    vendor (inertia-laravel) が何を積むかに依存するテストになり、
    Inertia のバージョン更新で意味を失う / 壊れる。
  - よって **キャッシュポリシーを独立した小さな Support クラスへ切り出す**案を採る。
    Codex も「既存 directive 保持を明確な契約にする場合に限り妥当」としており、
    本設計はまさに「加算方式で既存 directive を落とさない」を契約として書いてしまっているため
    条件を満たす。reflection で private メソッドを叩く回避策は採らない。
- **対応内容**:
  1. 新規 `app/Support/Http/ErrorScreenCachePolicy.php` を追加し、`apply(Response $response): void`
     に Vary / no-store / private の適用を集約。「加算方式で既存 directive を落とさない」ことを
     クラスの契約として docblock に明記。
  2. `InertiaExceptionRenderer::render()` は `ErrorScreenCachePolicy::apply($rendered)` を呼ぶだけにし、
     **原応答ではなく生成した応答に適用する**ことをコメントで明示。
  3. S4 の「変更箇所」「施策一覧」「波及変更」「PHPStan 適合チェック」に新ファイルを追加。
  4. テスト計画を修正:
     - Feature 側から `it('既存の Cache-Control directive を落とさない')` を**削除**し、
       削除理由 (原応答と生成応答の混同) を設計書に引用注として残す。
     - 新規 `tests/Unit/Http/ErrorScreenCachePolicyTest.php` に 5 ケースを置く。
  5. mutation **M17** の対象テストを `ErrorScreenCachePolicyTest` へ付け替え。

### 補足 (Round 6 のプロンプト作成時に自己検証で見つけた反映漏れ・修正済み)

上記 4 の編集で、新規 Unit テストのブロックを挿入した位置が原因で、
Feature テスト `InertiaErrorScreenTest` に属する 2 ケース
(`戻り先が全 status で 1 件以上ある` / `cross-org 実在と不在で Error 応答が分岐しない`) が
誤って Unit テストファイルの箇条書きへ吸収されていた。
後者は DB (Project Factory) と HTTP 経路を要するため「DB 不使用」の Unit テストに置くのは矛盾する。
**両ケースを `InertiaErrorScreenTest` 側へ戻した**。下記に貼るのは修正後の本文である。

---

# 3. 対応後の詳細設計 (該当箇所)

以下は `detailed-design.md` の **S4 節の全文** (変更された節) と、
判断に必要な周辺 (施策一覧 / S6 の mutation 表の該当行) である。
S1 / S2 / S3 / S5 は Round 5 から**一切変更していない**ため省略する
(S1: status/passthrough enum、S2: `RetryAfterSeconds` 共有 SoT、
S3: `ErrorScreenDestinations` / `ErrorScreenData` DTO、S5: Error.svelte と resolver eager 化)。

## 3-1. 施策一覧 (S4 の行に新ファイル `ErrorScreenCachePolicy.php` を追加)

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 目録の型 (差し替え対象 status / 素通し理由の enum) を新設 | `app/Enums/Http/InertiaErrorScreenStatus.php` (新) / `app/Enums/Http/InertiaErrorScreenPassthrough.php` (新) | High |
| S2 | `Retry-After` パースを共有 SoT へ一本化 | `app/Support/Http/RetryAfterSeconds.php` (新) / `app/Exceptions/ApiExceptionRenderer.php` (変更) | High |
| S3 | 戻り先のサーバ固定許可一覧と props DTO | `app/Support/Http/ErrorScreenDestination.php` (新) / `app/Support/Http/ErrorScreenDestinations.php` (新) / `app/DataTransferObjects/Http/ErrorScreenData.php` (新) | High |
| S4 | Inertia 例外差し替え本体と bootstrap 配線 | `app/Exceptions/InertiaExceptionRenderer.php` (新) / `app/Support/Http/ErrorScreenCachePolicy.php` (新) / `bootstrap/app.php` (変更) | Critical |
| S5 | Error ページ (Svelte / TS 型 / resolver eager 化) | `resources/js/pages/Error.svelte` (新) / `resources/js/types/error-screen.ts` (新) / `resources/js/inertia.ts` (変更) | Critical |
| S6 | deny-by-default 目録 gate (PHP + JS) | `tests/Architecture/InertiaErrorScreenContractTest.php` (新) / `tests/js/architecture/inertia-eager-error-page.test.ts` (新) | High |

> S1 → S2 → S3 → S4 → S5 → S6 の順で実装する (S4 は S1〜S3 に依存、S6 は全部に依存)。

## 3-2. S4 節 全文 (対応後)

## S4: Inertia 例外差し替え本体と bootstrap 配線

### 変更箇所

- 新規: `app/Exceptions/InertiaExceptionRenderer.php`
- 新規: `app/Support/Http/ErrorScreenCachePolicy.php`
- 変更: `bootstrap/app.php` L356-381 (既存の**単一** `$exceptions->respond()` callback)

### 波及変更

- TypeScript 型定義: **なし** (S5 で新規作成する型を参照するだけ)
- API Resource/DTO: `ErrorScreenData` (S3) を使う。**新規の JsonResource は作らない**
  (Inertia props は JsonResource の担当ではない)
- Inertia Props インターフェース: `resources/js/pages/Error.svelte` の Props (S5)
- テストファイル:
  - 新規 `tests/Feature/Errors/InertiaErrorScreenTest.php`
  - 新規 `tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php`
  - 新規 `tests/Unit/Http/ErrorScreenCachePolicyTest.php`
  - 既存 `tests/Feature/Errors/ErrorPagesTest.php` に **admin の HTTP 経路**ケースを追加
    (既存は `view()->render()` 直叩きのため respond callback の退行を検出できない)
  - 既存 `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` は**変更しない**
    (stale version を送るため差し替え対象外。無改修で green)
  - 既存 `tests/Feature/Security/InertiaHistoryGuardTest.php` は**変更しない**
    (ログアウト着地は 302/200 で status < 400 = 素通し)

### 現行コード

`bootstrap/app.php` L350-381:

```php
        // REST API v1 の統一エラー envelope ({error: {code, message, status, details?}})。
        // api/* 以外 (web / Inertia) は null を返して既定レンダリングを保つ
        $exceptions->render(function (Throwable $exception, Request $request) {
            return ApiExceptionRenderer::render($exception, $request);
        });

        // /admin (Filament 運営) 配下の error は運用者向け中立テンプレートへ分離する
        // (顧客向けマーケ文言の errors/4xx.blade.php を出さない = customer-facing と
        // operator-facing の error ページを分離)。判定は AdminPanelPath::resolve() に一本化。
        // API/JSON 経路は不変 (ApiExceptionRenderer が先に JSON 化する)。
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if ($status < 400 || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $adminPath = AdminPanelPath::resolve();
            $isAdminPath = $request->is($adminPath) || $request->is($adminPath.'/*');
            if (! $isAdminPath) {
                return $response;
            }

            $adminView = $status >= 500 ? 'errors.admin.5xx' : 'errors.admin.4xx';
            if (! view()->exists($adminView)) {
                return $response;
            }

            return response()->view($adminView, [
                'status' => $status,
                'adminPath' => $adminPath,
            ], $status);
        });
```

**決定的な制約**: `Illuminate\Foundation\Configuration\Exceptions::respond()` は
`Handler::respondUsing()` に落ち、その実体は

```php
protected $finalizeResponseCallback;                                   // Handler.php:149
public function respondUsing($callback) { $this->finalizeResponseCallback = $callback; }  // Handler.php:751
```

= **単一スロットの last-write-wins**。2 本目の `$exceptions->respond()` を足すと
**この admin 分離が黙って無効化される**。同じ理由で inertia-laravel v3 の
`Inertia::handleExceptionsUsing()` (`ResponseFactory.php:397` で内部的に `respondUsing()` を呼ぶ)
も使わない。したがって**既存の 1 本を拡張する**。

### 変更後コード

新規 `app/Exceptions/InertiaExceptionRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DataTransferObjects\Http\ErrorScreenData;
use App\Enums\Http\InertiaErrorScreenPassthrough;
use App\Enums\Http\InertiaErrorScreenStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Http\AdminPanelPath;
use App\Support\Http\ErrorScreenCachePolicy;
use App\Support\Http\ErrorScreenDestinations;
use App\Support\Http\RetryAfterSeconds;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Inertia XHR (X-Inertia 付き) の 4xx/5xx を **Error 画面 (Inertia ページ)** へ差し替える。
 *
 * これが無いと @inertiajs/core は x-inertia ヘッダの無い応答を
 * `handleNonInertiaResponse()` → `dialog_default.show()` = エラーモーダルに流し込み、
 * 利用者は SPA から出られなくなる (URL も履歴も動かないため戻り先が無い)。
 *
 * ApiExceptionRenderer と対になる位置づけ (api/* は封筒 JSON、Inertia は Error 画面)。
 * **bootstrap/app.php に直書きしない**理由は 2 つ:
 *   1. tests/Architecture/InertiaRenderPageExistsInvariantTest の走査対象が app/ と routes/ だけで、
 *      bootstrap/ に Inertia::render を書くと「ページ実在」gate が効かない
 *   2. Controller (と例外ハンドラ) は薄く保つ (AGENTS.md 実装規約)
 *
 * **deny-by-default**: 差し替えるのは passthroughReason() が null を返す応答だけ。
 */
final class InertiaExceptionRenderer
{
    /**
     * 差し替え**しない**理由。null なら差し替えてよい。
     *
     * 判定順は「壊してはいけないものから」。呼び出し側 (bootstrap) の早期 return と
     * 重複する条件も**あえて再掲する** (この関数単体で安全側に閉じる = 呼び出し位置に依存しない)。
     *
     * ★expectsJson() を X-Inertia より**先**に見るのは意図的である。
     *   実ブラウザの Inertia client (@inertiajs/core 3.3.1) は
     *   `Accept: text/html, application/xhtml+xml` を送るため expectsJson() は偽になり、
     *   通常の SPA 遷移が誤って素通しになることはない
     *   (expectsJson は ajax()+acceptsAnyContentType または wantsJson。どちらも成立しない)。
     *   一方 `X-Inertia` を付けつつ `Accept: application/json` を送るクライアントは
     *   「JSON を期待している」と明言しているのだから、画面 HTML ではなく JSON を返すのが正しい。
     */
    public static function passthroughReason(Response $response, Request $request): ?InertiaErrorScreenPassthrough
    {
        $status = $response->getStatusCode();

        if ($status < 400) {
            return InertiaErrorScreenPassthrough::SuccessOrRedirectStatus;
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return InertiaErrorScreenPassthrough::MachineReadableEnvelope;
        }

        $adminPath = AdminPanelPath::resolve();
        if ($request->is($adminPath) || $request->is($adminPath.'/*')) {
            return InertiaErrorScreenPassthrough::OperatorFacingSurface;
        }

        if ($request->header(Header::INERTIA) === null) {
            return InertiaErrorScreenPassthrough::NonInertiaRequest;
        }

        if (! self::assetVersionMatches($request)) {
            return InertiaErrorScreenPassthrough::StaleAssetVersion;
        }

        if ($response->headers->has('Location') || $response->headers->has(Header::LOCATION)) {
            return InertiaErrorScreenPassthrough::InertiaProtocolRedirect;
        }

        $screenStatus = InertiaErrorScreenStatus::tryFrom($status);
        if ($screenStatus === null) {
            return InertiaErrorScreenPassthrough::UnlistedStatus;
        }

        if ($screenStatus->isServerError() && config('app.debug') === true) {
            return InertiaErrorScreenPassthrough::DebugServerError;
        }

        return null;
    }

    /**
     * 差し替え後の応答。差し替えない場合と、生成に失敗した場合は null
     * (呼び出し側が原応答をそのまま返す = 今日の挙動より悪くならない)。
     */
    public static function render(Response $response, Request $request): ?Response
    {
        try {
            if (self::passthroughReason($response, $request) !== null) {
                return null;
            }

            $status = InertiaErrorScreenStatus::from($response->getStatusCode());

            $retryAfterSeconds = $status->showsRetryAfter()
                ? RetryAfterSeconds::parse($response->headers->get('Retry-After'))
                : null;

            // ★419 では認証状態を**評価しない**。PHP は引数を呼び出し前に評価するため、
            //   ErrorScreenDestinations::for($status, $request->user() !== null) と書くと
            //   D1 (419 は認証状態を問わない) が真でも user resolver が走る。
            //   セッションが壊れている 419 で resolver が throw すると、
            //   本来最も救いたい画面が report() + Blade fallback に落ちてしまう
            //   (Codex design-review R2 [Warning])。
            $authenticated = $status->forcesGuestDestinations()
                ? false
                : $request->user() !== null;

            $data = new ErrorScreenData(
                status: $status,
                retryAfterSeconds: $retryAfterSeconds,
                destinations: ErrorScreenDestinations::for($status, $authenticated),
            );

            $rendered = Inertia::render('Error', $data->toInertiaProps())
                ->toResponse($request)
                ->setStatusCode($status->value);

            // ヘッダ移植は allowlist (deny-by-default)。原値をそのまま写すのではなく、
            // RetryAfterSeconds が解釈できた値だけを正規化して再設定する
            // (本文 / API details / HTTP ヘッダの三者が同じ SoT を通る)。
            if ($retryAfterSeconds !== null) {
                $rendered->headers->set('Retry-After', (string) $retryAfterSeconds);
            }

            // ─ キャッシュ表現の契約 ────────────────────────────────────
            // 同一 URL の応答が **リクエストヘッダとセッション状態の両方**で分岐する:
            //   - X-Inertia          … Blade か Inertia page か
            //   - X-Inertia-Version  … 差し替えるか (配備境界)
            //   - Accept             … JSON か画面か (expectsJson)
            //   - セッション (Cookie) … 戻り先が /dashboard か /login か
            //
            // キャッシュ表現の契約 (Vary + no-store + private) は
            // ErrorScreenCachePolicy に切り出す (下記)。**原応答ではなく生成した応答**に適用する。
            ErrorScreenCachePolicy::apply($rendered);

            return $rendered;
        } catch (Throwable $e) {
            // version 解決 (manifest 読み) / route 解決 / props 生成 / toResponse の
            // **どの段で失敗しても**原応答 (自己完結 Blade) を残す。
            //
            // ★ただし黙って握り潰さない。ここが恒常的に失敗すると
            //   「Error 画面が一度も出ないまま Blade に落ち続ける」= 改善が死んでいるのに
            //   誰も気づかない状態になる。利用者への応答は原応答へ戻しつつ、
            //   運用には report() で必ず届ける (Codex design-review R1 [Critical])。
            report($e);

            return null;
        }
    }

    /**
     * リクエストの asset version が現在の build と一致するか (配備境界)。
     *
     * 一致 = そのタブは現在の build から asset を読み込んでいる
     *      = その bundle に resources/js/pages/Error.svelte が含まれている。
     * 不一致のタブへ component 'Error' を返すと resolvePage() が throw して SPA が無反応になる
     * (= 今日のモーダル表示より悪化する)。
     *
     * ★**両辺が非空文字列のときだけ一致とみなす** (null === null を「同じ build」と読まない)。
     * ★version の取得元は HandleInertiaRequests::version()。Inertia::getVersion() は
     *   同 middleware の handle() が走った後でないと空文字になり、テナント guard 404 のように
     *   middleware より前で例外が出る経路で誤って不一致になる。
     */
    private static function assetVersionMatches(Request $request): bool
    {
        $requested = $request->header(Header::VERSION);
        if (! is_string($requested) || $requested === '') {
            return false;
        }

        $current = app(HandleInertiaRequests::class)->version($request);

        return is_string($current) && $current !== '' && $current === $requested;
    }
}
```

新規 `app/Support/Http/ErrorScreenCachePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia の Error 画面差し替え応答に適用するキャッシュ表現の契約。
 *
 * 同一 URL の応答が **リクエストヘッダとセッション状態の両方**で分岐するため、
 * 共有キャッシュが別のクライアントへ誤った表現を返さないようにする:
 *   - Vary … ヘッダ由来の分岐入力を宣言する (X-Inertia / X-Inertia-Version / Accept)
 *   - no-store + private … セッション由来の分岐 (戻り先が /dashboard か /login か) を閉じる
 *
 * セッション由来の分岐は原理的には `Vary: Cookie` でも宣言できるが、キャッシュキーの爆発と
 * cookie 全体への依存を招くため採らない。guest の 4xx/5xx には
 * NoStoreCacheHeadersForAuthenticatedPages (認証済みのみが対象) が付かないため、
 * ここで閉じる必要がある。
 *
 * ★**加算方式**で適用する (set() で Cache-Control を丸ごと書き換えない)。
 *   呼び出し側が既に積んだ directive を落とさないことが本クラスの契約であり、
 *   独立した Unit テストがそれを固定する
 *   (Response::setPrivate() は public を remove して private を add する = 矛盾も残さない)。
 */
final class ErrorScreenCachePolicy
{
    public static function apply(Response $response): void
    {
        $response->setVary([Header::INERTIA, Header::VERSION, 'Accept'], replace: false);
        $response->headers->addCacheControlDirective('no-store');
        $response->setPrivate();
    }
}
```

`bootstrap/app.php` (respond callback を拡張。**2 本目は追加しない**):

```php
use App\Exceptions\InertiaExceptionRenderer;

        /*
         | 例外応答の最終整形。**このアプリで唯一の respond callback**。
         |
         | ⚠ Illuminate\Foundation\Exceptions\Handler::respondUsing() は
         |   $finalizeResponseCallback への**単純代入** (単一スロット・last-write-wins)。
         |   2 本目の $exceptions->respond() を足すと、この callback が黙って無効化される。
         |   同じ理由で Inertia::handleExceptionsUsing() (内部で respondUsing を呼ぶ) も使わない。
         |   分岐を増やすときは**必ずこの 1 本の中に足す**こと。
         |   (tests/Architecture/InertiaErrorScreenContractTest が登録箇所 1 件を機械強制する)
         |
         | 適用順:
         |   1. status < 400 / api/* / expectsJson … 触らない (ApiExceptionRenderer の封筒 JSON を守る)
         |   2. /admin 配下 … 運営者向け中立テンプレート (customer-facing と operator-facing の分離)
         |   3. Inertia XHR … Error 画面へ差し替え (InertiaExceptionRenderer が deny-by-default で判定)
         |   4. それ以外 … 自己完結 Blade (resources/views/errors/*.blade.php) のまま
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if ($status < 400 || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $adminPath = AdminPanelPath::resolve();
            $isAdminPath = $request->is($adminPath) || $request->is($adminPath.'/*');
            if ($isAdminPath) {
                $adminView = $status >= 500 ? 'errors.admin.5xx' : 'errors.admin.4xx';
                if (! view()->exists($adminView)) {
                    return $response;
                }

                return response()->view($adminView, [
                    'status' => $status,
                    'adminPath' => $adminPath,
                ], $status);
            }

            return InertiaExceptionRenderer::render($response, $request) ?? $response;
        });
```

> 既存の分岐構造 (`if (! $isAdminPath) { return $response; }`) を
> `if ($isAdminPath) { … }` に反転しただけで、admin 側の挙動は 1 bit も変えていない。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`?InertiaErrorScreenPassthrough` / `?Response` / `bool`)
- [x] null 安全 (`$request->header()` の `?string` を `is_string` で絞る。
      `config('app.debug')` は `mixed` なので `=== true` で厳密比較)
- [x] DTO を返している (props は `ErrorScreenData::toInertiaProps()` の 1 経路のみ)
- [x] Generics の型パラメータが正しい (該当なし)
- [x] `Inertia::render(...)->toResponse($request)` は `Symfony\...\Response` を返し、
      `setStatusCode()` は `static` を返す (level 10 で型不整合にならない)
- [x] `catch (Throwable $e)` で捕捉した変数は `report($e)` に使う (未使用変数を作らない)
- [x] `ErrorScreenCachePolicy::apply()` は `void` を返し、引数は Symfony `Response` (Inertia 依存を持ち込まない)

### テスト計画

- [ ] **テストファースト**: まず `InertiaErrorScreenTest` を書いて赤を確認してから実装する
- [ ] 共通ヘルパ (ファイルローカル関数。`tests/Feature/Errors/` 内に定義)
  ```php
  /** 現 build と一致する X-Inertia ヘッダ一式。asset_url を固定して version を決定的にする。 */
  function inertiaErrorScreenHeaders(): array
  {
      config(['app.asset_url' => 'https://assets.test']);
      $version = app(HandleInertiaRequests::class)->version(request());

      return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version];
  }
  ```
  > `config('app.asset_url')` を設定するのは、テスト環境で `public/build/manifest.json` の
  > 有無に依存させないため (`Inertia\Middleware::version()` は asset_url を最優先で見る)。

- [ ] 新規 `tests/Feature/Errors/InertiaErrorScreenTest.php` (Factory 必須・RefreshDatabase グローバル)
  - `it('403 が Inertia の Error ページになる')` — 認証済み User を Factory で作り、
    403 を投げるテスト用 route ではなく**既存の権限不足経路**
    (他組織の `organizations.settings` 等) を叩く。`assertInertia(fn ($page) =>
    $page->component('Error')->where('status', 403))` + `assertForbidden()`
  - `it('404 が Inertia の Error ページになる')` — 未知 URL を X-Inertia で叩く
  - `it('419 が Inertia の Error ページになりログイン導線を返す')` —
    `$this->withMiddleware(VerifyCsrfToken::class)` 相当で CSRF 不一致を作るのが難しいため、
    `Route::get('/__test/419', fn () => abort(419))` を**テスト内で定義**して叩く
    (route 定義はテスト内に閉じ、production route を増やさない)。
    props の `destinations.0.href` が **`route('login', absolute: false)`** と一致すること
    (S3 と同じく literal path とは比較しない)
  - `it('認証済みでも 419 はログイン導線になる (D1)')` — 同上を `actingAs` で
  - `it('419 は user resolver が例外を投げても Error 画面になる (認証状態を評価しない)')` —
    `auth()->guard()` を `user()` が throw する実装に差し替えたうえで 419 を叩き、
    `component('Error')` + `destinations.0.href === route('login', absolute: false)` になること。
    かつ `Exceptions::assertNothingReported()` で **fail-safe に落ちていない**ことも確認する
    (引数評価順の罠の回帰テスト)
  - `it('429 は retryAfterSeconds を props に載せ Retry-After ヘッダも保持する')` —
    `abort(429, headers: ['Retry-After' => '30'])` を返すテスト内 route。
    props の `retryAfterSeconds` が 30、`assertHeader('Retry-After', '30')`
  - `it('429 の Retry-After が解釈不能なら retryAfterSeconds は null でヘッダも載らない')` —
    `['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']`
  - `it('500 は app.debug=false のとき Error ページになる')` —
    `config(['app.debug' => false])` + テスト内 route で `throw new RuntimeException`。
    `$this->withoutExceptionHandling()` は**使わない** (例外ハンドラ自体が検証対象のため)
  - `it('503 は app.debug=false のとき Error ページになる')`
  - `it('Error 応答は x-inertia ヘッダを持つ (クライアントがモーダルに落とさない条件)')` —
    `assertHeader('X-Inertia', 'true')`
  - `it('Error 応答のキャッシュ表現契約 (no-store + private + Vary) を満たす')` —
    未認証 (guest) の 404 で
    (a) `Cache-Control` に `no-store` **と** `private` が含まれる、
    (b) `Cache-Control` に `public` が**残らない**、
    (c) `Vary` に `X-Inertia` / `X-Inertia-Version` / `Accept` の**3 つすべて**が含まれる
    (`NoStoreCacheHeadersForAuthenticatedPages` は認証済みしか対象にしないため、
    guest ケースが本契約の主戦場)
  - `it('認証済みでも同じキャッシュ表現契約を満たす')` — 既に `no-store` が付いている
    状態で二重付与・矛盾 (`public` の混入) が起きないこと
  - `it('戻り先が全 status で 1 件以上ある')` — 6 status を `->with()` で回して
    `count($props['destinations']) >= 1`
  - `it('cross-org 実在と不在で Error 応答が分岐しない')` —
    version 一致ヘッダ付きで `/projects/{他組織の実在 id}` と `/projects/999999999` を叩き、
    **status と props (url を除く) が一致**すること。Project は Factory で作る
    (`TenantBoundaryPrecedenceTest` の契約を差し替え経路でも維持することの確認)
  > 「既存 directive を落とさない」は **Feature テストでは検証しない**。
  > `render()` は原応答を変更せず新しい Inertia 応答を生成するため、原応答へ
  > `must-revalidate` を積んでも `$rendered` には移植されず、テストは
  > 「`set()` への退行」ではなく「原応答の Cache-Control を移植していない」という
  > **別契約**で失敗する (Codex design-review R5 [Warning])。
  > この契約は下記の `ErrorScreenCachePolicy` の Unit テストが**適用対象の応答に対して**固定する。
- [ ] 新規 `tests/Unit/Http/ErrorScreenCachePolicyTest.php` (DB 不使用・reflection 不使用)
  - `it('no-store と private を付ける')` — 素の `new Response()` に適用して両方が付くこと
  - `it('public を残さない')` — `Cache-Control: public` を持つ応答に適用して `public` が消えること
  - `it('既存の directive を落とさない')` — `Cache-Control: must-revalidate` を持つ応答に適用し、
    `must-revalidate` が残ったまま `no-store` / `private` が加わること
    (`headers->set('Cache-Control', …)` で丸ごと書き換える実装への退行を検出する)
  - `it('二重適用しても矛盾しない')` — 2 回 apply して directive が壊れないこと
  - `it('既存の Vary を落とさず 3 ヘッダを追加する')` — `Vary: Accept-Encoding` を持つ応答に
    適用し、4 つすべてが並ぶこと (`replace: false` の契約)
- [ ] 新規 `tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php`
  - `it('X-Inertia なしのフルロードは Blade のまま')` — 404 に `<style>` が含まれること
  - `it('stale version の X-Inertia は差し替えない')` — `X-Inertia-Version: stale-version`
  - `it('version ヘッダ欠落の X-Inertia は差し替えない')`
  - `it('version ヘッダが空文字の X-Inertia は差し替えない')`
  - `it('現 version が空 (asset_url 未設定 + manifest 不在) なら差し替えない')` —
    `config(['app.asset_url' => null])` + manifest 不在を前提にした条件付き skip ではなく、
    `HandleInertiaRequests` を `version()` が null を返す anonymous subclass に
    `$this->app->instance()` で差し替えて固定する
  - `it('409 + X-Inertia-Location は差し替えない')` — `Inertia::location('https://…')` を返す
    テスト内 route を X-Inertia で叩き、`assertStatus(409)` + `X-Inertia-Location` 保持
  - `it('302 + Location は差し替えない')`
  - `it('4xx + Location ヘッダを持つ応答は差し替えない')` —
    `abort(404, headers: ['Location' => '/somewhere'])` 相当のテスト内 route
  - `it('422 (バリデーション) は差し替えない')` — FormRequest 失敗を X-Inertia で叩き、
    302 + errors の既定挙動になること
  - `it('api/* は封筒 JSON のまま')`
  - `it('X-Inertia + Accept: application/json は JSON のまま (expectsJson が優先)')` —
    JSON を明示要求したクライアントに画面 HTML を返さないことの固定
  - `it('実 Inertia client のヘッダ (Accept: text/html, application/xhtml+xml) では差し替わる')` —
    `expectsJson()` との優先順位が本番の client で意図どおりであることの正のコントロール
    (`InertiaErrorScreenTest` 側に置いてもよいが、優先順位の契約として本ファイルに置く)
  - `it('admin 配下は運営者向け中立テンプレートのまま')` — X-Inertia を付けても
    `errors.admin.4xx` の内容 (`管理パネルに戻る`) が返ること
  - `it('5xx は app.debug=true のとき差し替えない')`
  - `it('version resolver が throw しても原応答が完全一致で残り、例外は report される')` —
    `HandleInertiaRequests` を `version()` が例外を投げる差し替え実装にし、
    (a) 差し替え無しの応答と `ResponseSignature::of()` が一致すること
    (`Tests\Support\ResponseSignature` は**読むだけ**で変更しない)、
    (b) `Exceptions::fake()` + `Exceptions::assertReported(...)` で**その例外が報告された**こと。
    (b) が無いと将来 `report($e)` が削除されても green のままになる
    (`Illuminate\Support\Testing\Fakes\ExceptionHandlerFake::render()` は実ハンドラへ委譲するため、
    fake しても respond callback の検証は成立する)
  - `it('素通し理由 enum の全 case が実際に生成される (死んだ分類を作らない)')` —
    上記各ケースで `InertiaExceptionRenderer::passthroughReason()` を直接評価し、
    観測された case 集合が `InertiaErrorScreenPassthrough::cases()` と**一致**すること
- [ ] 既存テストの更新: `tests/Feature/Errors/ErrorPagesTest.php`
  - 追加 `it('admin 配下の 404 は HTTP 経路でも運営者向けテンプレートを返す')` —
    既存 3 ケースは `view()->render()` 直叩きで respond callback を通らないため、
    **単一スロットの退行 (respond が 2 本目に奪われる) を検出できない**。
    HTTP 経路のケースを 1 本足してこの穴を塞ぐ (S4 の最大の事故モードの behavioral 固定)
- [ ] 既存テストの非変更確認: `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` /
      `InertiaHistoryGuardTest.php` / `PasskeyRouteAccessTest.php` / `RecentAuthTest.php` /
      `PasswordSetupTest.php` ほか X-Inertia を送る 11 ファイルは
      **version ヘッダを送っていないため差し替え対象外**。無改修で green であることを
      `composer test` で確認する (1 本でも赤なら設計の前提が崩れているので実装を止める)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (全 Feature テストで grep)

### リスク

| # | リスク | 緩和 |
|---|--------|------|
| 1 | respond callback が単一スロットであることを知らずに 2 本目を足され、admin 分離が黙って死ぬ | bootstrap のコメントで明示 + S6 の Architecture gate で登録 1 件を強制 + `ErrorPagesTest` に HTTP 経路ケースを追加 (三重) |
| 2 | Inertia 手順上の 409 / Location 応答を巻き込む | `passthroughReason()` の P4/P5 + 素通し Feature テスト 4 ケース |
| 3 | `AuthenticationException` の `Inertia::clearHistory()` 契機が消える | render callback は respond より**前**に走る (`Handler::render()` → `finalizeRenderedResponse()`) ため構造的に保証。加えて既定処理は 302 = status < 400 で素通し。`InertiaHistoryGuardTest` が既存で固定 |
| 4 | Error 画面の生成中に二次例外が出て 500 が二重に壊れる | 全段 try/catch → null → 原応答 (自己完結 Blade)。専用 Feature テストで固定 |
| 5 | 旧 asset のタブで SPA が無反応になる | 配備境界 (version 一致) 判定。stale/欠落/空文字/現 version 空の 4 ケースをテスト |
| 6 | 差し替え応答が共有キャッシュに載り、別のクライアントへ誤った表現が返る | 差し替え応答に `no-store` + `private` (既存 directive は落とさず加算) + `Vary: X-Inertia, X-Inertia-Version, Accept` を付与 (戻り先はセッション依存。`Vary: Cookie` は原理的には可能だがキャッシュキー爆発を招くため採らず、no-store で閉じる)。**素通し側 (Blade) は変更しない** — 内容が全クライアントで同一の固定文言であり、共有キャッシュのヒットが起きても再現するのは今日と同じ UX (モーダル表示) だけで、後退にも情報漏えいにもならない |
| 7 | `$request->user()` がセッション不整合時に throw する | try/catch 内で `report()` して原応答へ。かつ **419 では `$authenticated` の算出自体を短絡**するため user resolver を呼ばない (引数評価順の罠を回避済み。専用テストで固定) |
| 8 | 実行時間の増加 | 差し替えは 4xx/5xx のみ。正常系に分岐は 1 つも増えない (respond callback は例外時のみ実行) |

## 3-3. S6 の mutation 表 (M16 / M17 の行のみ抜粋。M17 の対象テストを付け替えた)

| # | mutation | 期待して赤くなるテスト |
|---|---------|---------------------|
| M16 | `Vary` / `no-store` / `private` の付与を削除 | 「Error 応答のキャッシュ表現契約」テスト |
| M17 | `ErrorScreenCachePolicy` で `addCacheControlDirective` を `headers->set('Cache-Control', …)` に戻す | `ErrorScreenCachePolicyTest` の「既存の directive を落とさない」 |

---

# 4. 判定してほしいこと

この対応で Round 5 の [Warning] が解消しているかを判定し、全体判定 (APPROVED / CHANGES_REQUESTED) を返せ。
解消していない場合は**残っている指摘だけ**を挙げよ。既に APPROVE 済みの節を蒸し返さないこと。
