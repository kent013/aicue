【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富な Laravel コードレビュアーです。実装をレビューしてください。

【レビュー観点】
1. 詳細設計との一致性 2. 正確性 3. PHPStan level 10 適合 4. テストが退行を検出できるか (mutation 実測を添付)
5. 副作用・後退リスク 6. セキュリティ 7. 禁止事項 (特に response()->json() 直書き)

【特に見てほしい点】
- 設計からの乖離 2 点 (下記) の判断は妥当か
- mutation M2 が最初赤くならなかった原因と対処は正しいか
- Architecture テストの自己検査 (負例で 4 件検出) は、検出器が壊れたときに気づける形になっているか

【出力形式】ファイルごとに APPROVE / REQUEST_CHANGES、[Critical][Warning][Suggestion]、全体判定、日本語

---

## 詳細設計書 (APPROVED 済み)

# 詳細設計: xhr-404-message

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作
4. **`response()->json()` の直書き** (DTO / JsonResource / Inertia を使う) 5. Prism 直呼び
6. prompt 文字列のコード直書き 7. 操作系 POST の `redirect()->intended()`
8. 必須条件未充足での disabled 9. Artifact の使用

### コーディングルール

- `declare(strict_types=1)` + 日本語コメント / PHPStan level 10 / Pest (RefreshDatabase グローバル)
- テストデータは Factory 経由 / DTO + JsonResource / アーリーリターン

## 概念設計リファレンス

- `devnotes/20260812-1200-xhr-404-message/conceptual-design.md` (Round 3 APPROVED)
- 合議: `conceptual-review-round-{1..3}.md` / `codex-history/conceptual-review-decisions-round-{1,2}.md`

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 404 の message を collapse する JsonResource + DTO | `app/Support/Http/NotFoundMessage.php` (新規) / `app/Http/Resources/NotFoundMessageResource.php` (新規) | High |
| 2 | `bootstrap/app.php` に render callback を 1 本追加 | `bootstrap/app.php` | High |
| 3 | `InertiaErrorScreenPassthrough` の docblock 是正 | `app/Enums/Http/InertiaErrorScreenPassthrough.php` | Medium |
| 4 | 契約を Feature テストで固定 | `tests/Feature/Errors/JsonNotFoundMessageTest.php` (新規) | High |
| 5 | 「文言つき 404 が 0 件」の変更検知 | `tests/Architecture/NoMessageCarrying404Test.php` (新規) | Medium |

---

## 施策 1: collapse する値と応答の組み立て

**`response()->json()` を直書きしない** (禁止事項 4)。`ApiExceptionRenderer` が
`ApiError` (DTO) + `ApiErrorResource` (JsonResource) で組んでいるのと**同じ形**にする。

```php
<?php // app/Support/Http/NotFoundMessage.php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * JSON 404 の body に載せる固定文言 (bug-hunt F-1-03)。
 *
 * **例外の message は載せない**。Laravel は ModelNotFoundException を
 * NotFoundHttpException($e->getMessage()) へ変換するため、既定のままだと
 * `No query results for model [App\Models\Take] 1` のように内部の名前空間が漏れる。
 *
 * 文言は面で変える (collapse 自体は api/* 以外へ全面適用する = 除外は作らない):
 * - 機械向け経路 (oauth/* / .well-known/*) … プロトコル中立の英語
 * - それ以外 (撮影 PWA / web 面の XHR / 未定義 URL) … 人間向けの日本語
 *
 * **prefix は「安全性」ではなく「文言」しか決めない**。分類から漏れても
 * 起きるのは「機械向けに日本語が返る」見た目の問題だけで、情報露出は起きない。
 */
final readonly class NotFoundMessage
{
    /**
     * 機械向け経路の prefix (文言選択専用。安全性には影響しない)。
     * **prefix 直下そのものも含める** — `is('oauth/*')` は `oauth` に一致しないため
     * (Round 1 [Warning])。
     */
    private const MACHINE_FACING_PATTERNS = ['oauth', 'oauth/*', '.well-known', '.well-known/*'];

    public const HUMAN_MESSAGE = 'お探しのページまたはデータは見つかりませんでした。';

    public const MACHINE_MESSAGE = 'Not Found';

    public function __construct(public string $message) {}

    public static function forRequest(Request $request): self
    {
        foreach (self::MACHINE_FACING_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return new self(self::MACHINE_MESSAGE);
            }
        }

        return new self(self::HUMAN_MESSAGE);
    }
}
```

```php
<?php // app/Http/Resources/NotFoundMessageResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Http\NotFoundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * JSON 404 の body。**形は Laravel 既定と同じ `{"message": "…"}` に保つ**
 * (撮影 PWA のクライアントが `record.message` / `body.code` を読むため、
 *  api/* の封筒形 `{error:{…}}` に変えると壊れる)。
 *
 * @mixin NotFoundMessage
 */
class NotFoundMessageResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array{message: string} */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, NotFoundMessage::class);

        return ['message' => $this->resource->message];
    }
}
```

---

## 施策 2: render callback

`bootstrap/app.php` の `withExceptions` 内、**`ApiExceptionRenderer` の配線より後**に置く。

```php
/*
 | JSON を期待された 404 の message を固定文言へ collapse する (bug-hunt F-1-03)。
 |
 | Laravel は ModelNotFoundException を NotFoundHttpException($e->getMessage()) へ変換するため、
 | 既定のままだと `No query results for model [App\Models\Take] 1` のように内部の名前空間が漏れる。
 | api/* は ApiExceptionRenderer が統一封筒を確定させるので**ここには来ない** (向こうが先に返す)。
 |
 | ★型は Throwable で受け、HttpExceptionInterface かつ status 404 のときだけ非 null を返す
 |   (例外クラスを ModelNotFoundException に狭めると、Laravel が変換した後の経路 =
 |    実際に漏れている経路を取り逃がす)。
 */
$exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
    if (! $request->expectsJson()) {
        return null; // HTML / Inertia は既存のエラー画面に任せる
    }

    if (! $exception instanceof HttpExceptionInterface || $exception->getStatusCode() !== 404) {
        return null;
    }

    return NotFoundMessageResource::make(NotFoundMessage::forRequest($request))
        ->response($request)
        ->setStatusCode(404);
});
```

### 設計判断

- **`api/*` の除外を条件に書かない。** `ApiExceptionRenderer` の callback が先に非 null を
  返すため構造的に到達しない。二重に書くと「どちらが正か」が曖昧になる。
  **到達しないことはテスト契約 1 で固定する**。
- **`expectsJson()` を最初に見る** (HTML 経路を 1 行目で外す = 既存挙動に一切触れない)。
- **status 404 だけ**を見る。401 / 402 / 403 / 409 / 422 は素通し (契約 4)。
- 例外を握って握り潰さない (`catch` を書かない)。

---

## 施策 3: `InertiaErrorScreenPassthrough` の docblock 是正

`MachineReadableEnvelope` の説明は現在「(c) の統一エラー封筒 JSON が正しい応答形」だが、
**`api/*` 以外では封筒は作られない** (本 TODO で collapse した `{"message"}` になる)。
名前が事実に反したままにしないため、docblock に「`api/*` は封筒、それ以外は
collapse 済みの `{"message"}`」と明記する。**enum の case 名・値は変えない**
(呼び出し側と既存テストに波及するため。名前の意味は docblock で確定させる)。

---

## 施策 4: Feature テスト (`tests/Feature/Errors/JsonNotFoundMessageTest.php`)

| # | 契約 | 検査 |
|---|---|---|
| 1 | `api/*` の 404 は**既存の API 封筒**を維持する | **`Accept: application/json` を明示して** `{"error":{"code":"not_found",…}}` の形が返り、`message` トップレベルではない。**未定義 URL と route model binding 由来の 404 の両方**を見る (M3 の検出条件なので、Accept が無いと赤くならない) |
| 2 | 非 API の JSON 404 が collapse される | 撮影 PWA の nested route に不在 id → body に `App\Models` を含まず、固定文言と一致 |
| 3 | HTML / Inertia の 404 は既存のエラー画面を維持する | `Accept: text/html` で従来どおり (component / 文言) |
| 4 | 401 / 402 / 403 / 409 / 422 は既存応答を維持する | **status ごとに dataset で分ける** (1 本に集約すると失敗時の切り分けが重くなる)。それぞれ既存の body 形・文言が変わらない |
| 5 | OAuth の仕様内エラー応答は既存形を維持する | `oauth/token` の invalid grant 等が既存 body のまま |
| 6 | **未定義 URL** への JSON 要求も内部 message を返さない | `/no-such-path` に `Accept: application/json` → 固定文言 |
| 7 | 機械向け経路の 404 は英語 `Not Found`。**prefix の直下と配下を dataset で両方**検査する | `/oauth` / `/oauth/no-such-path` / `/.well-known` / `/.well-known/no-such-path` の 4 件。**直下を検査しないと `'oauth'` / `'.well-known'` を定数から消しても緑のまま**になる (Round 2 [Warning]) |
| 8 | **内部クラス名が本文に出ない**ことを直接見る | body に `App\\Models` / `No query results` が含まれない |

契約 4 は「既存応答を維持」なので既存テストでも間接的に担保されるが、本 TODO の変更で
壊れやすい箇所なので**このテストファイルに集約して**明示的に固定する
(ファイル内では status ごとに dataset を分け、失敗時に原因が切り分けられるようにする)。

## 施策 5: Architecture テスト (`tests/Architecture/NoMessageCarrying404Test.php`)

「**文言つきの 404 を投げていない**」ことの変更検知。走査対象は `app/` だけにしない:

- `app/` / `routes/` / `bootstrap/`
- 記法: `abort(404, …)` / `abort_if(…, 404, …)` / `abort_unless(…, 404, …)` /
  `new NotFoundHttpException(<非空>)` / `new HttpException(404, <非空>)`
- **実装は token ベースに固定する** (Round 2 [Warning]。正規表現へフォールバックしない)。
  `token_get_all()` で関数呼び出しと引数を構文的に見て、次をすべて扱う:
  - named argument の引数順不同 (`new HttpException(message: 'x', statusCode: 404)` /
    `abort_if(condition: $c, code: 404, message: 'x')`)
  - 複数行にまたがる呼び出し / ネストした引数式
  - コメント・文字列リテラル中の疑似コードを**拾わない** (token なので構造的に除外できる)
  - 完全修飾名 / import alias (`use ... as ...`) の解決
  既存の `Tests\Support\PhpReferenceScanner` が名前空間解決と alias 追跡を持つので、
  **適合するならそれに乗る**。適合しない場合も token ベースで自前実装する

**保証範囲を誇張しない**: これは**列挙した直接記法の変更検知**であり、
変数経由・別表現・動的生成は捕捉できない。**collapse の安全性は施策 4 が担う**
(この分担は Round 3 [Suggestion] の指示どおり)。

## fail 先行

契約 **2 / 6 / 7 (4 dataset のうち少なくとも日本語を期待しない側) / 8** が**赤くなる**ことを
確認してから実装する (契約 1 / 3 / 4 / 5 は現状でも緑の想定)。
**ただし `Not Found` は Laravel 既定の 404 message でもあるため、契約 7 の一部は
最初から緑になりうる**。その場合は「偶然一致しているだけで実装前後の意味が違う」ことを
実測として記録し、fail-first の対象から外した理由を書く (誇張しない)。

## mutation 計画

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | callback の `expectsJson()` 判定を外す | 契約 3 (HTML 404 が JSON 化する) |
| M2 | status 404 の判定を外す (全 status で collapse) | 契約 4 |
| M3 | callback を `ApiExceptionRenderer` より**前**に置く | 契約 1 (封筒が collapse に食われる)。**本ファイル内の最小検出契約が契約 1** という意味で、既存 API テストも赤くなりうる |
| M4 | 例外型の判定を `ModelNotFoundException` に狭める | 契約 2 (変換後の経路を取り逃がす) |
| M5 | 文言選択を常に日本語にする | 契約 7 |
| M6 | `NotFoundMessage` に `$exception->getMessage()` を載せる | 契約 8 |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 新規 2 クラス + callback 1 本 + docblock + テスト 2 本。既存の応答経路は条件で外す |
| 競合リスク | `bootstrap/app.php` は他 TODO が触っていない |

## 保証しないもの (誇張しない)

- **404 以外の status は変えない。** 403 / 422 / 409 の body は現行のまま。
  棚卸しで「クラス名を出す箇所 0 件」は確認したが、**独自例外の message までは追っていない**。
- **封筒形にはしない。** `api/*` の統一封筒とは別の形のままで、「JSON 応答が統一された」とは書かない。
- **認可・存在秘匿の挙動は変えない。** 変わるのは message 文字列だけで、
  tenant 解決・認可・middleware priority には触れない。
- **Architecture テストは直接記法の変更検知**であり、動的生成や変数経由は捕捉しない。


---

## テスト結果 (worktree 内)

`composer test` 4527 passed / 2 skipped (4529) / `pnpm test` 1357 passed /
`composer phpstan` No errors / `vendor/bin/pint --test` passed / `pnpm lint` / `pnpm build`: 全緑。

### fail 先行 (予測と実測)

予測「契約 2 / 6 / 7 / 8 が赤、1 / 3 / 4 / 5 は緑」。**実測**: 新規クラス未作成のため
契約 2 / 6 / 7 (4 dataset) / 8 が `Class "App\Support\Http\NotFoundMessage" not found` で赤。
契約 1 の "model binding" dataset だけは**別の理由で赤**だった (401。API キー認証が要る) ため、
テスト側に `issueApiKey` を足して是正した。

### 設計からの乖離 2 点

1. **契約 4 のデータセットを 401 だけにしていたら mutation M2 を検出できなかった。**
   401 (`AuthenticationException`) は `HttpExceptionInterface` を実装しないため、
   「status 404 の判定を外す」mutation が素通りする。**403 (`AccessDeniedHttpException`) と
   409 (`abort`)** を dataset に足して是正し、M2 の赤化を実測した。
   この理由はテスト本文にコメントとして残した。
2. `bootstrap/app.php` から `use Throwable;` を**削除**した。このファイルは名前空間を持たないため
   非複合名の use は無効で、PHP の警告 (`The use statement with non-compound name 'Throwable'
   has no effect`) が出ていた (このリポジトリには同種の gate もある)。

### mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | `expectsJson()` 判定を外す | 契約 3 | **一致** (1 件) |
| M2 | status 404 の判定を外す | 契約 4 | **当初は赤化せず → 契約 4 を 403/409 へ拡張して一致** (2 件) |
| M3 | callback を `ApiExceptionRenderer` より前へ置く | 契約 1 | **一致** (契約 1 の 2 dataset) |
| M4 | 例外型を `ModelNotFoundException` に狭める | 契約 2 | **一致** (契約 8 ほか) |
| M5 | 文言選択を常に日本語にする | 契約 7 | **一致** (4 件 = 機械向け dataset 全部) |
| M6 | `$exception->getMessage()` を載せる | 契約 8 | **一致** |

---

## 実装差分 (git diff)

```diff
diff --git a/app/Enums/Http/InertiaErrorScreenPassthrough.php b/app/Enums/Http/InertiaErrorScreenPassthrough.php
index 9f5aaa6..7396044 100644
--- a/app/Enums/Http/InertiaErrorScreenPassthrough.php
+++ b/app/Enums/Http/InertiaErrorScreenPassthrough.php
@@ -19,7 +19,14 @@ enum InertiaErrorScreenPassthrough: string
     /** status が 400 未満 (2xx / 3xx)。Location を持つ遷移や成功応答を触らない。 */
     case SuccessOrRedirectStatus = 'success_or_redirect_status';
 
-    /** api/* または expectsJson。(c) の統一エラー封筒 JSON が正しい応答形。 */
+    /**
+     * api/* または expectsJson。**機械可読な JSON が正しい応答形**である。
+     *
+     * ★形は面で違う (T158): `api/*` は (c) の統一エラー封筒 `{error: {...}}`
+     * (`ApiExceptionRenderer`)、それ以外の JSON は Laravel 既定と同じ `{"message": ...}` で、
+     * **404 の message は固定文言へ collapse 済み** (`NotFoundMessage`。
+     * 内部クラス名を漏らさないため)。「封筒が返る」と読める書き方にしない。
+     */
     case MachineReadableEnvelope = 'machine_readable_envelope';
 
     /** admin panel 配下。運営者向け中立テンプレート (errors.admin.*) が正しい応答形。 */
diff --git a/bootstrap/app.php b/bootstrap/app.php
index f82bdf6..5cca806 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -29,18 +29,22 @@
 use App\Http\Middleware\VerifySnsSignature;
 use App\Http\Resources\Billing\InsufficientTicketsResource;
 use App\Http\Resources\Billing\QuotaExceededResource;
+use App\Http\Resources\NotFoundMessageResource;
 use App\Support\Http\AdminPanelPath;
+use App\Support\Http\NotFoundMessage;
 use Illuminate\Auth\AuthenticationException;
 use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
 use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
 use Illuminate\Foundation\Application;
 use Illuminate\Foundation\Configuration\Exceptions;
 use Illuminate\Foundation\Configuration\Middleware;
+use Illuminate\Http\JsonResponse;
 use Illuminate\Http\Request;
 use Illuminate\Routing\Middleware\SubstituteBindings;
 use Inertia\Inertia;
 use Inertia\Middleware\EncryptHistory;
 use Symfony\Component\HttpFoundation\Response;
+use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
 
 return Application::configure(basePath: dirname(__DIR__))
     ->withRouting(
@@ -365,6 +369,34 @@
             return ApiExceptionRenderer::render($exception, $request);
         });
 
+        /*
+         | JSON を期待された 404 の message を固定文言へ collapse する (T158 / bug-hunt F-1-03)。
+         |
+         | Laravel は ModelNotFoundException を NotFoundHttpException($e->getMessage()) へ
+         | 変換するため、既定のままだと `No query results for model [App\Models\Take] 1` のように
+         | 内部の名前空間が JSON body へ漏れる (HTML 経路は日本語の 404 画面 = 経路で露出が非対称)。
+         |
+         | ★`api/*` の除外を条件に書かない。上の ApiExceptionRenderer が先に非 null を返すため
+         |   構造的に到達しない (二重に書くと「どちらが正か」が曖昧になる)。
+         |   **到達しないことは JsonNotFoundMessageTest の契約 1 が固定する** = 登録順が契約である。
+         | ★型は Throwable で受け、HttpExceptionInterface かつ status 404 のときだけ非 null を返す
+         |   (ModelNotFoundException に狭めると、Laravel が変換した後の経路 = 実際に漏れている
+         |    経路を取り逃がす)。
+         */
+        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
+            if (! $request->expectsJson()) {
+                return null; // HTML / Inertia は既存のエラー画面に任せる
+            }
+
+            if (! $exception instanceof HttpExceptionInterface || $exception->getStatusCode() !== 404) {
+                return null; // collapse するのは 404 だけ (401/402/403/409/422 は素通し)
+            }
+
+            return NotFoundMessageResource::make(NotFoundMessage::forRequest($request))
+                ->response($request)
+                ->setStatusCode(404);
+        });
+
         /*
          | 例外応答の最終整形。**このアプリで唯一の respond callback**。
          |
```
