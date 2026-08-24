<?php

declare(strict_types=1);

use App\Services\Auth\SocialiteDriverResolver;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
use Webmozart\Assert\Assert;

/*
 * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
 *
 * ★責務は 7 つだけ:
 *   1. DB へ接続しない
 *   2. container から解決する
 *   3. 転送先 URL を組み立てて読む (**偽物が有効なときだけ**)
 *   4. **実働証明の印を storage_path() 経由で 1 本書く** (正典 v1 (5))
 *   5. **起動しきったアプリが解決した書き出し先 8 種と、効いた鍵 2 種の digest を報告する**
 *   6. **実際に読んだ環境ファイルの絶対パスを報告する** (P-17。専用ファイルへの固定が効いた証拠)
 *   7. 終了コードを返す
 * ★**観測しないもの**: HTTP サーバもブラウザも起動しない /
 *   設定キャッシュ**有り**の起動は観測しない / 外部へ 1 度も通信しない
 *   (転送先は組み立てて URL を読むだけ)。
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

    /*
     * ★正典 v1 (5) の**実働証明**の観測点 (lctl feature: subprocess-boot-probe-harness)。
     *   「書き出し先を環境変数で退避した」ことは、退避が**効いていなければ**既定の場所
     *   (リポジトリの storage/) へ書かれ、観測は緑のまま嘘になる。そこで
     *   Laravel の storage_path() 経由で印を 1 本置き、それが起動器の一時ディレクトリ配下に
     *   現れたことを呼び出し側 (P-13) が確かめる。
     *   置き場所 (storage/app/private) は起動器が事前に掘っている。
     */
    $markerPath = $app->storagePath(FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
    if (file_put_contents($markerPath, 'fake-wiring-probe') === false) {
        throw new RuntimeException("観測の印を書けない: {$markerPath}");
    }

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
        // ★P-17 (環境ファイルの隔離): 起動しきったアプリが**実際に読んだ**環境ファイルの
        //   絶対パス。呼び出し側が「起動側が用意した専用ファイルと完全一致する」ことを確かめる
        //   (= リポジトリの .env を読んでいない、を実挙動で示す唯一の観測点)。
        'env_file_path' => $app->environmentFilePath(),
        // ★P-14 (向き): 起動しきったアプリが解決した書き出し先。呼び出し側が
        //   「1 件残らず一時ディレクトリ配下で、リポジトリの外」であることを確かめる。
        'write_targets' => [
            'storage' => $app->storagePath(),
            'config_cache' => $app->getCachedConfigPath(),
            'routes_cache' => $app->getCachedRoutesPath(),
            'services_cache' => $app->getCachedServicesPath(),
            'packages_cache' => $app->getCachedPackagesPath(),
            'events_cache' => $app->getCachedEventsPath(),
            'view_compiled' => (string) config('view.compiled'),
            'log_path' => (string) config('logging.channels.single.path'),
        ],
        // ★P-8 (使い捨て鍵が子で効いたこと)。鍵そのものは出力しない (テスト出力へ鍵を流さない)。
        'key_digests' => [
            'app' => hash('sha256', (string) config('app.key')),
            'ciphersweet' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
        ],
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

    exit(1);
}
