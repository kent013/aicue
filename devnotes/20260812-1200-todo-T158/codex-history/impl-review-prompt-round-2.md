# Round 2: Round 1 指摘への対応

Warning 2 件を対応しました (契約 4 に 422 を追加 / Architecture の自己検査を記法ごと 13 dataset へ)。
402 だけは**追加していません** — 課金ゲートの 402 は組織状態の作り込みが要り、
本 TODO の変更 (404 だけを触る) との距離が遠いためです。入れなかった判断の妥当性も見てください。

テスト: `composer test` 4527 passed / 2 skipped、`pnpm test` 1357 passed、PHPStan No errors、
pint / lint / build 緑。対象テストは Feature 15 件 + Architecture 14 件が緑。

---

# 対応マトリクス: impl-review Round 1

判定 REQUEST_CHANGES。Critical 0 / Warning 2 (+ APPROVE 4 ファイル)。**すべて対応**(反論なし)。

## [Warning] 契約 4 に 402 / 422 が残っているか不明

- 判断: **対応する** (422 を追加。402 は追加しない)
- 根拠: M2 検出には 403 / 409 で足りるが、契約 4 の趣旨は「**変更対象外の応答を固定する**」こと。
  422 (ValidationException) は変更対象外の代表なので入れる価値がある。
- 対応内容: 契約 4 に **422 の dataset を追加**し、`errors` 配列が返ることも見るようにした
  (validation 応答の形が保たれていることの固定)。
  **402 は追加しない** — 課金ゲートの 402 は組織状態の作り込みが要り、
  本 TODO の変更 (404 だけを触る) との距離が遠い。**入れなかったことを明記する**。

## [Warning] Architecture テストの自己検査が「件数 4」で弱い

- 判断: **対応する**
- 根拠: 妥当。件数だけでは「どの記法を取りこぼしたか」が分からない。
- 対応内容: 自己検査を**記法ごとの dataset (13 件)** に作り替えた。
  正例 7 (`abort` 位置引数 / `abort` named / 複数行 + ネスト引数 / `abort_if` / `abort_unless` /
  `new HttpException` の named 順不同 / `new NotFoundHttpException`) と
  負例 6 (文言なし / 別 status / 空文字 / コメント内 / 文字列リテラル内 / message 無しの `new HttpException`)。
  検出結果を「記法ラベル => 行番号」で返すヘルパーに切り出し、記法単位で判定できるようにした。

## [Suggestion] docblock がやや長い / M2 のコメントは正しい

- 判断: 対応不要 (肯定的評価)。
\n\n---\n\n## 実装差分 (git diff)\n\n```diff\ndiff --git a/app/Enums/Http/InertiaErrorScreenPassthrough.php b/app/Enums/Http/InertiaErrorScreenPassthrough.php
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
diff --git a/app/Http/Resources/NotFoundMessageResource.php b/app/Http/Resources/NotFoundMessageResource.php
new file mode 100644
index 0000000..83e4776
--- /dev/null
+++ b/app/Http/Resources/NotFoundMessageResource.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Resources;
+
+use App\Support\Http\NotFoundMessage;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+use Webmozart\Assert\Assert;
+
+/**
+ * JSON 404 の body。**形は Laravel 既定と同じ `{"message": "…"}` に保つ** (T158)。
+ *
+ * 撮影 PWA のクライアントは `lib/capture/http.ts` が `record.message` を、
+ * `lib/capture/upload-queue.ts` が `body.code` を読むため、
+ * `api/*` の封筒形 (`{error: {...}}`) に変えるとクライアントが壊れる。
+ *
+ * @mixin NotFoundMessage
+ */
+class NotFoundMessageResource extends JsonResource
+{
+    /** @var string|null */
+    public static $wrap = null;
+
+    /** @return array{message: string} */
+    public function toArray(Request $request): array
+    {
+        Assert::isInstanceOf($this->resource, NotFoundMessage::class);
+
+        return ['message' => $this->resource->message];
+    }
+}
diff --git a/app/Support/Http/NotFoundMessage.php b/app/Support/Http/NotFoundMessage.php
new file mode 100644
index 0000000..7022a5e
--- /dev/null
+++ b/app/Support/Http/NotFoundMessage.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Http;
+
+use Illuminate\Http\Request;
+
+/**
+ * JSON 404 の body に載せる固定文言 (bug-hunt F-1-03 / T158)。
+ *
+ * **例外の message は載せない**。Laravel は `ModelNotFoundException` を
+ * `NotFoundHttpException($e->getMessage())` へ変換するため、既定のままだと
+ * `No query results for model [App\Models\Take] 1` のように内部の名前空間が漏れる。
+ * HTML 経路は日本語の 404 画面なので、**同じ 404 でも経路によって露出が非対称**になっていた。
+ *
+ * 文言は面で変える (**collapse 自体は `api/*` 以外へ全面適用する = 除外は作らない**):
+ * - 機械向け経路 (`oauth` / `.well-known` とその配下) … プロトコル中立の英語
+ * - それ以外 (撮影 PWA / web 面の XHR / 未定義 URL) … 人間向けの日本語
+ *
+ * **prefix は「安全性」ではなく「文言」しか決めない**。分類から漏れても起きるのは
+ * 「機械向けに日本語が返る」見た目の問題だけで、情報露出は起きない。
+ */
+final readonly class NotFoundMessage
+{
+    /**
+     * 機械向け経路の prefix (文言選択専用。安全性には影響しない)。
+     * **prefix 直下そのものも含める** — `is('oauth/*')` は `oauth` に一致しないため。
+     *
+     * @var list<string>
+     */
+    private const MACHINE_FACING_PATTERNS = ['oauth', 'oauth/*', '.well-known', '.well-known/*'];
+
+    public const HUMAN_MESSAGE = 'お探しのページまたはデータは見つかりませんでした。';
+
+    public const MACHINE_MESSAGE = 'Not Found';
+
+    public function __construct(public string $message) {}
+
+    public static function forRequest(Request $request): self
+    {
+        foreach (self::MACHINE_FACING_PATTERNS as $pattern) {
+            if ($request->is($pattern)) {
+                return new self(self::MACHINE_MESSAGE);
+            }
+        }
+
+        return new self(self::HUMAN_MESSAGE);
+    }
+}
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
diff --git a/tests/Architecture/NoMessageCarrying404Test.php b/tests/Architecture/NoMessageCarrying404Test.php
new file mode 100644
index 0000000..dfaef04
--- /dev/null
+++ b/tests/Architecture/NoMessageCarrying404Test.php
@@ -0,0 +1,280 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\PhpReferenceScanner;
+
+/*
+ * 「文言つきの 404 を投げていない」ことの**変更検知** (T158 / bug-hunt F-1-03)。
+ *
+ * T158 は JSON 404 の message を固定文言へ collapse する。つまり **404 に載せた文言は
+ * JSON 経路では捨てられる**。いま文言つきの 404 が 0 件だからこそ「捨てても情報が失われない」
+ * と言えるので、その前提が変わったら気づけるようにする。
+ *
+ * ★保証範囲を誇張しない: これは**列挙した直接記法の変更検知**であって、
+ *   collapse の安全性の証明ではない (安全性は tests/Feature/Errors/JsonNotFoundMessageTest.php)。
+ *   変数経由・動的生成・helper 経由の 404 は捕捉できない。
+ *
+ * ★実装は token ベース (正規表現へフォールバックしない)。named argument の引数順不同・
+ *   複数行にまたがる呼び出し・ネストした引数式を構文的に扱い、
+ *   コメントや文字列リテラル中の疑似コードは拾わない (normalize が comment を落とす)。
+ */
+
+/**
+ * 呼び出しの引数を「トップレベルのカンマ」で分割して返す。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @param  int  $openIndex  `(` の位置
+ * @return list<list<array{id: int|null, text: string, line: int}>>
+ */
+function messageCarrying404Arguments(array $tokens, int $openIndex): array
+{
+    $depth = 0;
+    $current = [];
+    $arguments = [];
+
+    for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+
+        if (in_array($text, ['(', '[', '{'], true)) {
+            $depth++;
+            if ($depth === 1) {
+                continue; // 呼び出しの `(` 自体は引数に含めない
+            }
+        }
+
+        if (in_array($text, [')', ']', '}'], true)) {
+            $depth--;
+            if ($depth === 0) {
+                if ($current !== []) {
+                    $arguments[] = $current;
+                }
+
+                return $arguments;
+            }
+        }
+
+        if ($depth === 1 && $text === ',') {
+            $arguments[] = $current;
+            $current = [];
+
+            continue;
+        }
+
+        $current[] = $tokens[$i];
+    }
+
+    return $arguments;
+}
+
+/**
+ * 引数列から「404 を status として渡し、かつ非空の message を渡している」かを判定する。
+ *
+ * named argument (`code:` / `statusCode:` / `message:`) は順不同で扱い、
+ * 位置引数は helper ごとの位置で見る。
+ *
+ * @param  list<list<array{id: int|null, text: string, line: int}>>  $arguments
+ * @param  int  $statusPosition  位置引数における status の index
+ * @param  int  $messagePosition  位置引数における message の index
+ */
+function messageCarrying404Detected(array $arguments, int $statusPosition, int $messagePosition): bool
+{
+    $status = null;
+    $message = null;
+    $positional = 0;
+
+    foreach ($arguments as $argument) {
+        if ($argument === []) {
+            continue;
+        }
+
+        // named argument は `name` `:` で始まる (T_STRING の直後に ':')
+        if (count($argument) >= 2 && $argument[0]['id'] === T_STRING && $argument[1]['text'] === ':') {
+            $name = $argument[0]['text'];
+            $value = array_slice($argument, 2);
+            if (in_array($name, ['code', 'statusCode', 'status'], true)) {
+                $status = $value;
+            }
+            if ($name === 'message') {
+                $message = $value;
+            }
+
+            continue;
+        }
+
+        if ($positional === $statusPosition) {
+            $status = $argument;
+        }
+        if ($positional === $messagePosition) {
+            $message = $argument;
+        }
+        $positional++;
+    }
+
+    if ($status === null || count($status) !== 1 || $status[0]['text'] !== '404') {
+        return false;
+    }
+
+    if ($message === null || $message === []) {
+        return false;
+    }
+
+    // 空文字リテラルは「文言なし」と同じなので拾わない
+    if (count($message) === 1 && in_array($message[0]['text'], ["''", '""'], true)) {
+        return false;
+    }
+
+    return true;
+}
+
+/**
+ * 走査対象ディレクトリの PHP ファイルから、文言つき 404 の site を集める。
+ *
+ * @return list<string>
+ */
+function messageCarrying404Sites(): array
+{
+    /** 呼び出し名 => [status の位置, message の位置] */
+    $helpers = [
+        'abort' => [0, 1],
+        'abort_if' => [1, 2],
+        'abort_unless' => [1, 2],
+    ];
+    /** クラス名 => [status の位置, message の位置] (new 経由) */
+    $classes = [
+        'HttpException' => [0, 1],
+        'NotFoundHttpException' => [-1, 0], // status を取らない = message が第 1 引数
+    ];
+
+    $sites = [];
+
+    foreach (['app', 'routes', 'bootstrap'] as $root) {
+        foreach (PhpReferenceScanner::phpFiles(base_path($root), $root) as $relativePath => $source) {
+            $tokens = PhpReferenceScanner::tokens($source);
+
+            for ($i = 0, $count = count($tokens); $i < $count; $i++) {
+                $text = $tokens[$i]['text'];
+                $next = $tokens[$i + 1]['text'] ?? '';
+
+                if ($next !== '(') {
+                    continue;
+                }
+
+                if (isset($helpers[$text]) && $tokens[$i]['id'] === T_STRING) {
+                    [$statusPosition, $messagePosition] = $helpers[$text];
+                    $arguments = messageCarrying404Arguments($tokens, $i + 1);
+                    if (messageCarrying404Detected($arguments, $statusPosition, $messagePosition)) {
+                        $sites[] = "{$relativePath}:{$tokens[$i]['line']} {$text}";
+                    }
+
+                    continue;
+                }
+
+                if (! isset($classes[$text]) || ($tokens[$i - 1]['id'] ?? null) !== T_NEW) {
+                    continue;
+                }
+
+                [$statusPosition, $messagePosition] = $classes[$text];
+                $arguments = messageCarrying404Arguments($tokens, $i + 1);
+
+                if ($statusPosition === -1) {
+                    // NotFoundHttpException は status を取らないので message の非空だけを見る
+                    $first = $arguments[0] ?? [];
+                    if ($first !== [] && ! (count($first) === 1 && in_array($first[0]['text'], ["''", '""'], true))) {
+                        $sites[] = "{$relativePath}:{$tokens[$i]['line']} new {$text}";
+                    }
+
+                    continue;
+                }
+
+                if (messageCarrying404Detected($arguments, $statusPosition, $messagePosition)) {
+                    $sites[] = "{$relativePath}:{$tokens[$i]['line']} new {$text}";
+                }
+            }
+        }
+    }
+
+    return $sites;
+}
+
+test('文言つきの 404 は 1 件も無い (JSON 経路では collapse され失われるため)', function (): void {
+    expect(messageCarrying404Sites())->toBe([]);
+});
+
+/**
+ * 与えたソースから、検出した site を「記法ラベル => 行番号の配列」で返す (自己検査用)。
+ *
+ * @return array<string, list<int>>
+ */
+function messageCarrying404DetectInSource(string $source): array
+{
+    $helpers = ['abort' => [0, 1], 'abort_if' => [1, 2], 'abort_unless' => [1, 2]];
+    $classes = ['HttpException' => [0, 1], 'NotFoundHttpException' => [-1, 0]];
+
+    $tokens = PhpReferenceScanner::tokens($source);
+    $detected = [];
+
+    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+            continue;
+        }
+
+        if (isset($helpers[$text]) && $tokens[$i]['id'] === T_STRING) {
+            [$statusPosition, $messagePosition] = $helpers[$text];
+            if (messageCarrying404Detected(messageCarrying404Arguments($tokens, $i + 1), $statusPosition, $messagePosition)) {
+                $detected[$text][] = $tokens[$i]['line'];
+            }
+
+            continue;
+        }
+
+        if (! isset($classes[$text]) || ($tokens[$i - 1]['id'] ?? null) !== T_NEW) {
+            continue;
+        }
+
+        [$statusPosition, $messagePosition] = $classes[$text];
+        $arguments = messageCarrying404Arguments($tokens, $i + 1);
+
+        if ($statusPosition === -1) {
+            $first = $arguments[0] ?? [];
+            if ($first !== [] && ! (count($first) === 1 && in_array($first[0]['text'], ["''", '""'], true))) {
+                $detected["new {$text}"][] = $tokens[$i]['line'];
+            }
+
+            continue;
+        }
+
+        if (messageCarrying404Detected($arguments, $statusPosition, $messagePosition)) {
+            $detected["new {$text}"][] = $tokens[$i]['line'];
+        }
+    }
+
+    return $detected;
+}
+
+test('走査器は %s を検出する (自己検査。件数ではなく記法ごとに固定する)', function (string $label, string $snippet, bool $expected): void {
+    // 検出器が壊れて常に空を返すと上のテストが無意味になるため、**記法ごとに**負例/正例を当てる。
+    // 件数だけの assert では「どの記法を取りこぼしたか」が分からない (Round 1 [Warning])。
+    $source = "<?php\n".$snippet."\n";
+
+    $detected = messageCarrying404DetectInSource($source);
+
+    expect($detected !== [])->toBe($expected);
+})->with([
+    // 拾うべき記法
+    ['abort の位置引数', "abort(404, 'これは文言つき');", true],
+    ['abort の named argument', "abort(404, message: 'named');", true],
+    ['abort の複数行 + ネスト引数', "abort(\n    404,\n    message: sprintf('%s は不在', \$name),\n);", true],
+    ['abort_if', "abort_if(\$missing, 404, '不在');", true],
+    ['abort_unless', "abort_unless(\$found, 404, '不在');", true],
+    ['new HttpException の named 順不同', "new HttpException(message: 'x', statusCode: 404);", true],
+    ['new NotFoundHttpException', "new NotFoundHttpException('内部の説明');", true],
+    // 拾ってはいけないもの
+    ['文言なしの abort', 'abort(404);', false],
+    ['別 status の abort', "abort(403, '別 status は対象外');", false],
+    ['空文字の message', "abort(404, '');", false],
+    ['コメント内の疑似コード', "// abort(404, 'コメント内は拾わない');", false],
+    ['文字列リテラル内の疑似コード', "\$s = \"abort(404, 'literal')\";", false],
+    ['status を取らない new HttpException (message 空)', "new HttpException(404);", false],
+]);
diff --git a/tests/Feature/Errors/JsonNotFoundMessageTest.php b/tests/Feature/Errors/JsonNotFoundMessageTest.php
new file mode 100644
index 0000000..602fc8d
--- /dev/null
+++ b/tests/Feature/Errors/JsonNotFoundMessageTest.php
@@ -0,0 +1,157 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ProjectRole;
+use App\Models\Project;
+use App\Models\VideoManual;
+use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Http\NotFoundMessage;
+
+/*
+ * JSON を期待された 404 の message を固定文言へ collapse する (T158 / bug-hunt F-1-03)。
+ *
+ * 背景: Laravel は ModelNotFoundException を NotFoundHttpException($e->getMessage()) へ
+ * 変換するため、既定のままだと `No query results for model [App\Models\Take] 1` のように
+ * 内部の名前空間が JSON body に漏れる。HTML 経路は日本語の 404 画面なので**経路で露出が非対称**。
+ *
+ * 固定する契約:
+ * - collapse は `api/*` 以外の JSON 404 へ**全面適用**する (除外は作らない)。
+ *   prefix は「安全性」ではなく**文言**しか決めない。
+ * - 応答の**形は変えない** (`{"message": …}`)。撮影 PWA のクライアントが message を読むため、
+ *   api/* の封筒形 `{error:{…}}` に変えると壊れる。
+ * - `api/*` / HTML / 401・402・403・409・422 / OAuth の仕様内エラーは**一切変えない**。
+ */
+
+test('契約 1: api/* の 404 は既存の統一エラー封筒を維持する', function (string $path, bool $authenticated): void {
+    // Accept を明示するのが要点。これが無いと「callback を ApiExceptionRenderer より前に置く」
+    // mutation を検出できない (expectsJson が偽になり collapse へ入らないため)。
+    // model binding 由来の 404 は認証を通さないと 401 で手前に落ちるため API キーを与える。
+    if ($authenticated) {
+        [$organization, $owner] = createOrganizationWithOwner();
+        [, $plain] = issueApiKey($organization, $owner);
+        $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
+    }
+
+    $response = $this->getJson($path);
+
+    $response->assertNotFound();
+    $body = $response->json();
+    expect($body)->toHaveKey('error');
+    expect($body['error'])->toHaveKey('code');
+    // 封筒形なのでトップレベル message は持たない
+    expect($body)->not->toHaveKey('message');
+})->with([
+    'undefined url' => ['/api/v1/no-such-path', false],
+    'model binding' => ['/api/v1/projects/999999', true],
+]);
+
+test('契約 2: 撮影 PWA の JSON 404 は固定文言へ collapse される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->create();
+
+    $response = $this->actingAs($owner)
+        ->getJson("/app/projects/{$project->id}/manuals/999999");
+
+    $response->assertNotFound();
+    expect($response->json('message'))->toBe(NotFoundMessage::HUMAN_MESSAGE);
+});
+
+test('契約 3: HTML の 404 は既存のエラー画面を維持する', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->get('/projects/999999')
+        ->assertNotFound()
+        ->assertSee('見つかりません');
+});
+
+test('契約 4: 404 以外の status (%s) は既存応答を維持する', function (string $case): void {
+    // ★ここは **HttpExceptionInterface を実装する status を必ず含める** こと。
+    //   401 (AuthenticationException) は HttpException ではないため、
+    //   「status 404 の判定を外す」mutation を検出できない (実測済み)。
+    //   403 (AccessDeniedHttpException) と 409 (abort) が検出役である。
+    if ($case === '401') {
+        $response = $this->getJson('/app/projects/1/manuals/1');
+        $response->assertStatus(401);
+    }
+
+    if ($case === '403') {
+        [$organization, $owner] = createOrganizationWithOwner();
+        $project = Project::factory()->forOrganization($organization)->create();
+        $member = attachOrganizationMember($organization);
+        $member->forceFill(['current_organization_id' => $organization->id])->save();
+        attachProjectMember($project, $member, ProjectRole::Member);
+
+        $response = $this->actingAs($member)
+            ->postJson("/projects/{$project->id}/manuals", ['title' => 'x']);
+        $response->assertStatus(403);
+    }
+
+    if ($case === '409') {
+        [, $owner] = createOrganizationWithOwner();
+        app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+        $owner->refresh();
+
+        $response = $this->actingAs($owner)->getJson('/dashboard');
+        $response->assertStatus(409);
+    }
+
+    if ($case === '422') {
+        [$organization, $owner] = createOrganizationWithOwner();
+        $project = Project::factory()->forOrganization($organization)->create();
+
+        // 変更対象外の validation 応答 (ValidationException) が素通しであることを固定する
+        $response = $this->actingAs($owner)
+            ->postJson("/projects/{$project->id}/manuals", ['title' => '']);
+        $response->assertStatus(422);
+        expect($response->json('errors'))->toBeArray();
+    }
+
+    // collapse は 404 だけ。他 status の body を 404 の文言で書き換えていないこと
+    expect($response->json('message'))->not->toBe(NotFoundMessage::HUMAN_MESSAGE);
+    expect($response->json('message'))->not->toBe(NotFoundMessage::MACHINE_MESSAGE);
+})->with(['401', '403', '409', '422']);
+
+test('契約 5: OAuth の仕様内エラー応答は既存形を維持する', function (): void {
+    $response = $this->postJson('/oauth/token', []);
+
+    // Passport が返す仕様内エラー (400 系)。collapse は 404 だけなので触らない
+    expect($response->getStatusCode())->not->toBe(404);
+    expect($response->json('message'))->not->toBe(NotFoundMessage::MACHINE_MESSAGE);
+});
+
+test('契約 6: 未定義 URL への JSON 要求も内部 message を返さない', function (): void {
+    $response = $this->getJson('/no-such-path');
+
+    $response->assertNotFound();
+    expect($response->json('message'))->toBe(NotFoundMessage::HUMAN_MESSAGE);
+});
+
+test('契約 7: 機械向け経路 %s の 404 は英語の固定文言', function (string $path): void {
+    // **直下と配下の両方**を見る。配下だけだと 'oauth' / '.well-known' を
+    // MACHINE_FACING_PATTERNS から消しても緑のままになる
+    $response = $this->getJson($path);
+
+    $response->assertNotFound();
+    expect($response->json('message'))->toBe(NotFoundMessage::MACHINE_MESSAGE);
+})->with([
+    'oauth 直下' => ['/oauth'],
+    'oauth 配下' => ['/oauth/no-such-path'],
+    '.well-known 直下' => ['/.well-known'],
+    '.well-known 配下' => ['/.well-known/no-such-path'],
+]);
+
+test('契約 8: JSON 404 の本文に内部クラス名や Eloquent の例外文が出ない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $body = $this->actingAs($owner)
+        ->getJson("/app/projects/{$project->id}/manuals/999999")
+        ->getContent();
+
+    expect($body)->toBeString();
+    expect($body)->not->toContain('App\\Models');
+    expect($body)->not->toContain('No query results');
+});
```\n