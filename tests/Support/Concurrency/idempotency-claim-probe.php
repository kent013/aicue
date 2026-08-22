<?php

declare(strict_types=1);

use App\Auth\Context\ApiActorContext;
use App\Http\Middleware\ResolveApiActor;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\Ci\TestDatabaseEnv;
use Tests\Support\Concurrency\ProbeDatabaseCoordinates;
use Tests\Support\Concurrency\ProbeEnvironment;
use Tests\Support\Concurrency\ProcessBarrier;
use Tests\Support\Concurrency\SignalName;
use Webmozart\Assert\Assert;

/*
 * 実プロセス並行テストの子 (正典 v1 の要素 (1))。
 *
 * ★責務は 6 つだけ: 受け取った環境を検査する / 設定の出所を固定する /
 *   起動前に DB 座標を検査する / 起動後に「守りたい層以外の無効化」を検査してから
 *   準備完了を告げる / 要求を 1 回だけ投げる / 観測を JSON で書く。
 * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md)。
 * ★秘密 (plain API key / body) は argv に載せない。0600 の入力ファイルから読む。
 * ★**マイグレーションを一切実行しない** (スキーマは親のレーンが用意済み)。`RefreshDatabase` も使わない。
 *
 * 終了コード:
 *   0  正常 (観測を stdout と out 合図へ書いた)
 *   70 継承された環境変数がある (env -i の退行)
 *   71 既定 cache を array に固定できていない (守りたい層以外を無効化できていない)
 *   72 実効 DB 座標が宣言と一致しない (二重解釈のずれ / 別 DB への到達)
 *   73 それ以外の失敗 (stderr に例外を書く)
 */

require __DIR__.'/../../../vendor/autoload.php';

// ─────────────────────────────────────────────────────────────────────────────
// [段 6] bootstrap の**前**に、子が実際に受け取ったプロセス環境を検査する。
//        組み立て側の配列を見ても env -i の退行は映らない (観測できるのは子だけ)。
//        phpdotenv は immutable なので、環境変数が残っていると env ファイルより優先され、
//        遮断を迂回する。
// ─────────────────────────────────────────────────────────────────────────────
try {
    $received = getenv();
    Assert::isArray($received);
    ProbeEnvironment::assertProcessEnvironmentKeys(array_keys($received));
} catch (Throwable $e) {
    // ★この段のメッセージは**環境変数のキー名**しか含まない (値は 1 つも載らない) ので出してよい。
    fwrite(STDERR, $e->getMessage()."\n");

    exit(70);
}

try {
    Assert::count($argv, 4, '引数は workspace / childId / inputFileName の 3 つである');
    $workspaceDirectory = $argv[1];
    $childId = $argv[2];
    $inputFileName = $argv[3];

    $environmentDirectory = getenv('CONCURRENCY_PROBE_ENV_DIR');
    $environmentFile = getenv('CONCURRENCY_PROBE_ENV_FILE');
    Assert::stringNotEmpty($environmentDirectory);
    Assert::stringNotEmpty($environmentFile);

    // ─────────────────────────────────────────────────────────────────────────
    // [段 7] env ファイルを**自前の厳格パーサ**で解析し、bootstrap 前に DB 名を検査する。
    //        `loadEnvironmentFrom()` はその場では解析しない (読む場所を指定するだけ) ので、
    //        bootstrap 前の検査には自前解析が要る。
    // ─────────────────────────────────────────────────────────────────────────
    $declaredValues = ProbeEnvironment::parseEnvFile($environmentDirectory.'/'.$environmentFile);
    ProbeEnvironment::assertEnvFileKeys($declaredValues);
    TestDatabaseEnv::assertPgsqlTestDatabaseSafe($declaredValues['DB_DATABASE']);

    $input = json_decode((string) file_get_contents($workspaceDirectory.'/'.$inputFileName), true);
    Assert::isArray($input);
    Assert::same($input['child_id'] ?? null, $childId, '入力ファイルの child ID が引数と違う');
    $nonce = $input['nonce'];
    $routeName = $input['route_name'];
    $uri = $input['uri'];
    $rawBody = $input['raw_body'];
    $idempotencyKey = $input['idempotency_key'];
    $plainApiKey = $input['plain_api_key'];
    $timeoutSeconds = $input['timeout_seconds'];
    Assert::stringNotEmpty($nonce);
    Assert::stringNotEmpty($routeName);
    Assert::stringNotEmpty($uri);
    Assert::string($rawBody);
    Assert::stringNotEmpty($idempotencyKey);
    Assert::stringNotEmpty($plainApiKey);
    // JSON の数値は int / float のどちらにもなりうる (60.0 と 0.2 で型が変わる)。
    Assert::numeric($timeoutSeconds);
    $timeoutSeconds = (float) $timeoutSeconds;
    Assert::greaterThan($timeoutSeconds, 0.0);

    // ★合図の締切は**単調時計**で測る (壁時計は NTP 補正で戻りうる)。
    $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
    $remainingSeconds = static function () use ($deadline): float {
        $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
        Assert::greaterThan($remaining, 0.0, '子の締切を使い切った');

        return $remaining;
    };

    // ─────────────────────────────────────────────────────────────────────────
    // [段 8] 設定の出所を専用の一時 env ファイル 1 つへ固定してから起動する。
    //        `APP_CONFIG_CACHE` は一時ディレクトリ配下の**存在しない絶対パス**
    //        (共有の bootstrap/cache を作らない・消さない)。
    // ─────────────────────────────────────────────────────────────────────────
    /** @var Application $app */
    $app = require __DIR__.'/../../../bootstrap/app.php';
    Assert::isInstanceOf($app, Application::class);

    $app->useEnvironmentPath($environmentDirectory);
    $app->loadEnvironmentFrom($environmentFile);

    $httpKernel = $app->make(HttpKernel::class);
    $httpKernel->bootstrap();

    // ─────────────────────────────────────────────────────────────────────────
    // [段 9] **ready を出す前に**「守りたい層以外の無効化」と実効 DB 座標を検査する。
    //        測った後に「実は無効化できていなかった」と分かって赤くなるのでは、
    //        正典の要素 (3) を満たしたことにならない。
    // ─────────────────────────────────────────────────────────────────────────
    // ★**cache API は 1 つも呼ばない**。設定だけを読む形にしてあるのは、
    //   `tests/Architecture/CachePayloadPlainDataGateTest.php` の L3 目録
    //   (キャッシュに触れるファイルの exact-fit) へ本スクリプトを登録すると、
    //   同ファイルが採用時債務 (adoption-debt.tsv) に在るため乖離台帳の 3 択が発生するためである。
    //   詳細設計は `Cache::getDefaultDriver()` を挙げていたが、その戻り値は vendor 実装上
    //   `config('cache.default')` そのもの (`CacheManager::getDefaultDriver()`) で**同じ事実の写し**にすぎない。
    //   代わりに「既定 store を裏打ちする driver」を見る — こちらは
    //   「array という名前の store が実は別の driver で裏打ちされている」形まで落とせるので**より強い**。
    $cacheDefault = config('cache.default');
    Assert::stringNotEmpty($cacheDefault);
    $cacheStoreDriver = config("cache.stores.{$cacheDefault}.driver");

    if ($cacheDefault !== 'array' || $cacheStoreDriver !== 'array') {
        fwrite(STDERR, 'cache が array に固定できていない (守りたい層以外を無効化できていない)'."\n");

        exit(71);
    }

    $effectiveCoordinates = ProbeDatabaseCoordinates::fromParentConfig();
    Assert::regex($declaredValues['DB_PORT'], '/^[0-9]+$/');
    $declaredCoordinates = new ProbeDatabaseCoordinates(
        driver: $declaredValues['DB_CONNECTION'],
        host: $declaredValues['DB_HOST'],
        port: (int) $declaredValues['DB_PORT'],
        database: $declaredValues['DB_DATABASE'],
        username: $declaredValues['DB_USERNAME'],
        charset: $declaredValues['DB_CHARSET'],
        sslmode: $declaredValues['DB_SSLMODE'],
        url: $declaredValues['DB_URL'],
    );

    // ★自前パーサの結果と bootstrap 後の実効値の一致まで見る (二重解釈のずれの検出)。
    if (! $effectiveCoordinates->equals($declaredCoordinates)) {
        fwrite(STDERR, sprintf(
            "実効 DB 座標が宣言と一致しない (宣言 %s / 実効 %s)\n",
            $declaredCoordinates->describe(),
            $effectiveCoordinates->describe(),
        ));

        exit(72);
    }

    $barrier = new ProcessBarrier($workspaceDirectory);

    $handlerExecutions = 0;
    $goToken = null;
    $apiKeyId = null;

    /** 認証結果 (ApiActorContext) から api_key_id を観測する (入力のコピーではない) */
    $observedApiKeyId = static function (Request $request): int {
        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        Assert::isInstanceOf($actor, ApiActorContext::class, '認証後の actor を観測できない');
        Assert::notNull($actor->apiKey, 'API キー actor でない');

        return $actor->apiKey->id;
    };

    // probe route を**この子の app インスタンスへ**登録する。
    // ハンドラは**テスト側コード**なので、アプリコードを 1 バイトも触らずに待たせられる。
    //
    // ★middleware 列は「**冪等 middleware の前提を満たす最小 probe 経路**」である。
    //   本番の順序契約は auth → throttle → resolve.api-actor → api.project-in-org
    //   → api-key.ability → idempotent → controller だが、throttle を挟むと 2 本の到達が
    //   乱れて測りたいものと別の分岐になるため入れない。**「本番同等」とは主張しない**。
    //
    // ★`$goToken` は**参照キャプチャ**である。closure を定義する時点ではまだ go を待っておらず、
    //   値キャプチャでは後の代入が反映されない (この closure は go の後にしか実行されないが、
    //   値キャプチャだと**空文字を合図に書いてしまう**)。
    //   先頭の Assert は「万一 go より先に handler へ入った」場合に**黙って空を書かず落ちる**ための門である。
    Route::post($uri, function (Request $request) use (
        $barrier,
        $childId,
        $nonce,
        &$goToken,
        &$apiKeyId,
        $remainingSeconds,
        &$handlerExecutions,
        $observedApiKeyId,
    ): JsonResponse {
        Assert::stringNotEmpty($goToken);
        $handlerExecutions++;
        $apiKeyId = $observedApiKeyId($request);

        // 勝者だけがここへ来る。入ったことを告げ、親の release を待つ。
        // これで敗者は**勝者の claim 行が processing のまま在る間に必ず claim へ到達する**。
        $barrier->signal(SignalName::make('entered', $childId), $nonce.':'.$goToken);
        $barrier->await(SignalName::make('release'), $remainingSeconds());

        return new JsonResponse(['data' => ['ok' => true]], 201);
    })->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])->name($routeName);

    // 準備完了を告げ、go を待つ (起動コストはここまでで払い切る)。
    $barrier->signal(SignalName::make('ready', $childId), $nonce);
    $goToken = $barrier->await(SignalName::make('go'), $remainingSeconds());
    Assert::stringNotEmpty($goToken);

    // 要求を 1 回だけ投げる (実サーバは立てない。プロセス内の実 middleware 列を通す)。
    //
    // ★第 3 引数 ($parameters) は**空配列**である。ここへ body を渡すと form parameter として
    //   扱われ `getContent()` が空になり、middleware が hash する内容が親の期待値と食い違う。
    //   raw bytes は**第 7 引数 (content)** へ渡す。
    $probeRequest = Request::create(
        uri: '/'.$uri,
        method: 'POST',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => "Bearer {$plainApiKey}",
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        ],
        content: $rawBody,
    );

    $response = $httpKernel->handle($probeRequest);

    // 敗者は handler へ入らないので、middleware が置いた attribute から認証結果を取る
    // (`resolve.api-actor` は `idempotent` より前に走るので、409 の場合も attribute は在る)。
    $apiKeyId ??= $observedApiKeyId($probeRequest);

    $status = $response->getStatusCode();
    $errorCode = null;
    if ($status < 200 || $status >= 300) {
        $decodedBody = json_decode((string) $response->getContent(), true);
        $code = is_array($decodedBody) && is_array($decodedBody['error'] ?? null)
            ? ($decodedBody['error']['code'] ?? null)
            : null;
        // ★読めなくても黙って null にしない (親の fail-closed 検査で弾かれる非空文字列を入れる)。
        $errorCode = is_string($code) && $code !== '' ? $code : 'unreadable_error_body';
    }

    $observedRouteName = $probeRequest->route()?->getName();

    $json = json_encode([
        'child_id' => $childId,
        'nonce' => $nonce,
        'go_token' => $goToken,
        'http_status' => $status,
        'error_code' => $errorCode,
        'handler_executions' => $handlerExecutions,
        'entered_handler' => $handlerExecutions > 0,
        'route_name' => is_string($observedRouteName) && $observedRouteName !== ''
            ? $observedRouteName
            : '(unnamed-probe-route)',
        'uri' => $probeRequest->path(),
        // ★middleware と同一規則で、**実際に送った Request** から計算する
        //   (body を form parameter で渡す事故があれば親の期待値と食い違って落ちる)。
        'request_hash' => hash(
            'sha256',
            $probeRequest->method().'|'.$probeRequest->path().'|'.$probeRequest->getContent()
        ),
        'api_key_id' => $apiKeyId,
        'cache_default' => $cacheDefault,
        'cache_store_driver' => $cacheStoreDriver,
        ...$effectiveCoordinates->toObservationValues(),
    ], JSON_THROW_ON_ERROR);

    // 観測を書く。stdout と out ファイルへ**同じ JSON** を出す (親が一致を検査する)。
    fwrite(STDOUT, $json);
    $barrier->signal(SignalName::make('out', $childId), $json);

    exit(0);
} catch (Throwable $e) {
    // ★**メッセージも trace も出さない**。子は plain API キー / raw body / 実鍵を握っており、
    //   `getTraceAsString()` は文字列引数を 15 文字まで含める = 秘密の先頭が CI ログへ残る。
    //   親は stderr を例外へ埋める前に既知の秘密を伏せ字にするが、切り詰められた断片は
    //   完全一致では伏せられないので、**出さない側**で閉じる。
    //   診断に要るのは「どの型の例外がどこで出たか」までである。
    fwrite(STDERR, sprintf("%s at %s:%d\n", $e::class, $e->getFile(), $e->getLine()));

    exit(73);
}
