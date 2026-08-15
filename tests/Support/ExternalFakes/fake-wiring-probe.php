<?php

declare(strict_types=1);

use App\Services\Auth\SocialiteDriverResolver;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Webmozart\Assert\Assert;

/*
 * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
 *
 * ★責務は 4 つだけ: DB へ接続しない / container から解決する /
 *   転送先 URL を組み立てて読む / 終了コードを返す。
 *   HTTP サーバもブラウザも起動しない。
 * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md §禁止する文)。
 * ★読み込む環境ファイルを**専用の一時ファイルだけ**に固定する (親のチェックアウトの
 *   .env / .env.bughunt.local を読ませない = 実資格情報が子の設定へ入らない)。
 */

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';

try {
    Assert::isInstanceOf($app, Application::class);

    // ★**Dotenv を読む前に**、子が実際に受け取ったプロセス環境を観測する。
    //   起動側が組み立てた配列を検査しても `env -i` を外した退行は映らない
    //   (組み立ては同じまま、親の環境だけが流れ込むため)。観測できるのは子だけである。
    $initialProcessEnvironment = getenv();
    Assert::isArray($initialProcessEnvironment);
    $processEnvironmentKeys = array_keys($initialProcessEnvironment);
    sort($processEnvironmentKeys);

    $environmentDirectory = getenv('FAKE_WIRING_PROBE_ENV_DIR');
    $environmentFile = getenv('FAKE_WIRING_PROBE_ENV_FILE');
    Assert::stringNotEmpty($environmentDirectory);
    Assert::stringNotEmpty($environmentFile);

    $app->useEnvironmentPath($environmentDirectory);
    $app->loadEnvironmentFrom($environmentFile);

    $app->make(Kernel::class)->bootstrap();

    $resolved = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
    }

    // 外部ログインは「解決したクラス名」だけでは足りない。転送先が実際に自ホストへ
    // 閉じているかまで見る (クラス名が合っていても転送先を戻す退行を緑で通すため)。
    // ★転送先の組み立ては**偽物が有効なときだけ**行う。無効なときに呼ぶと本物の
    //   身元確認サービス向けの URL を組み立てることになり、観測の目的から外れる。
    $redirectHost = null;
    if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) === true) {
        // 観測する外部ログインの種類は設定から取る (名前を写経しない)。
        $providers = config('template.social_providers');
        Assert::isArray($providers);
        $provider = array_key_first($providers);
        Assert::stringNotEmpty($provider);

        $target = $app->make(SocialiteDriverResolver::class)->driver($provider)->redirect()->getTargetUrl();
        $host = parse_url($target, PHP_URL_HOST);
        $redirectHost = is_string($host) ? $host : null;
    }

    fwrite(STDOUT, json_encode([
        'resolved' => $resolved,
        'redirect_host' => $redirectHost,
        'process_environment_keys' => $processEnvironmentKeys,
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

    exit(1);
}
