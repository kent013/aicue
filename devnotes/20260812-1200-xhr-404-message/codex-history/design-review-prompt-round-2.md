# Round 2: Round 1 指摘への対応

Warning 7 件・Suggestion 4 件を**すべて対応**しました。反論はありません。
APPROVED にできるか確認してください。

---

# 対応マトリクス: design-review Round 1

判定 REQUEST_CHANGES。Critical 0 / Warning 7 / Suggestion 4。**すべて対応**(反論なし)。

## [Warning] `oauth/*` は `oauth` 直下に一致しない

- 判断: **対応する**
- 対応内容: `MACHINE_FACING_PATTERNS` を `['oauth', 'oauth/*', '.well-known', '.well-known/*']` にし、
  **prefix 直下そのものも含める**と docblock に明記した。

## [Warning] 契約 1 は `Accept: application/json` を明示せよ (M3 の検出条件)

- 判断: **対応する**
- 根拠: M3 (callback を前に置く) は API 要求が `expectsJson()` を満たす場合にだけ collapse へ食われる。
  Accept が無いと**M3 が赤くならない**。
- 対応内容: 契約 1 に「`Accept: application/json` を明示」「**未定義 URL と route model binding 由来の
  404 の両方**を見る」を追記した。

## [Warning] 契約 7 に `.well-known/*` も足せ

- 判断: **対応する**
- 対応内容: 契約 7b を追加 (`MACHINE_FACING_PATTERNS` から誤って消したときに気づける)。

## [Warning] M3 の「契約 1 だけ」は不正確

- 判断: **対応する**
- 対応内容: 「**本ファイル内の最小検出契約が契約 1**。既存 API テストも赤くなりうる」と書き換えた。

## [Warning] Architecture テストは named argument と multiline を拾え

- 判断: **対応する**
- 対応内容: `abort(404, message: '…')` や改行で分かれた記法も対象にすると明記し、
  実装方針 (token 走査、または改行を畳んだ上での正規表現) も書いた。

## [Warning] render callback の型 / `api/*` を条件に書かない判断 (肯定)

- 判断: 対応不要 (妥当と評価)。ただし**登録順が契約になる**ので契約 1 で固定する、という前提は
  上記の契約 1 強化で満たした。

## [Suggestion] 契約 4 は status ごとに dataset で分けよ

- 判断: **対応する**
- 対応内容: 「status ごとに dataset で分ける」と明記 (1 本集約は失敗時の切り分けが重い)。

## [Suggestion] PHPStan 適合 / enum docblock だけ是正 / 分担の整理 (肯定)

- 判断: 対応不要 (いずれも妥当と評価)。import 漏れの注意は実装時に守る。
\n\n---\n\n## 改訂後の詳細設計 (全文)\n\n# 詳細設計: xhr-404-message

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
| 7 | 機械向け経路の 404 は英語 `Not Found` | `oauth/` 配下の不在 URL |
| 7b | **`.well-known/` 配下も**英語 `Not Found` | `MACHINE_FACING_PATTERNS` から誤って消したときに気づけるようにする |
| 8 | **内部クラス名が本文に出ない**ことを直接見る | body に `App\\Models` / `No query results` が含まれない |

契約 4 は「既存応答を維持」なので、**既存テストが緑のままであること**でも担保されるが、
本 TODO の変更で壊れやすい箇所なので**この 1 本に集約して明示的に固定する**。

## 施策 5: Architecture テスト (`tests/Architecture/NoMessageCarrying404Test.php`)

「**文言つきの 404 を投げていない**」ことの変更検知。走査対象は `app/` だけにしない:

- `app/` / `routes/` / `bootstrap/`
- 記法: `abort(404, …)` / `abort_if(…, 404, …)` / `abort_unless(…, 404, …)` /
  `new NotFoundHttpException(<非空>)` / `new HttpException(404, <非空>)`
- **named argument と複数行にまたがる記法も拾う** (Round 1 [Warning]):
  `abort(404, message: '…')` / 引数が改行で分かれた形。
  実装は token 走査 (`Tests\Support\PhpReferenceScanner` があればそれに乗る) か、
  改行を畳んだ上での正規表現で行う

**保証範囲を誇張しない**: これは**列挙した直接記法の変更検知**であり、
変数経由・別表現・動的生成は捕捉できない。**collapse の安全性は施策 4 が担う**
(この分担は Round 3 [Suggestion] の指示どおり)。

## fail 先行

契約 2 / 6 / 7 / 8 が**赤くなる**ことを確認してから実装する
(契約 1 / 3 / 4 / 5 は現状でも緑の想定。実測して記録する)。

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
