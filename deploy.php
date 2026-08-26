<?php

declare(strict_types=1);

/*
 * Deployer (deployphp/deployer 8.x) によるデプロイ定義。
 *
 * ★**このファイル 1 枚がデプロイ定義の全体である**。`deploy/` ディレクトリを作らず、
 *   CI に deploy job も足さない。理由は 2 つある:
 *     1. デプロイの手順を 1 か所に閉じる (複数の置き場所に散らすと実行される手順が
 *        どれなのか読めなくなる)。
 *     2. `tests/Architecture/RouteCacheExemptionPremiseTest.php` の検査 A
 *        (既知のデプロイ基盤の形が追跡下に増えたことを早期に知らせる粗い網) を、
 *        「**この 1 枚以外**のデプロイ基盤が増えたら赤くする」検出器として生かし続ける。
 *        検査 A は網羅を主張しない網なので、本ファイルはその網の外に意図的に置いている。
 *        この判断の登録は `docs/template-divergence.md` の D19 にある。
 *
 * ★**経路キャッシュ (routing の cache 生成) は打たない**。これは省略ではなく契約である。
 *   このアプリは vendor route への middleware を `RouteThrottleBinder` /
 *   `RouteMiddlewareBinder` が `Application::booted()` で後付けしており、
 *   throttle / recent-auth / ensure-login-method / no-store はいずれもこの後付けで載る。
 *   経路キャッシュを焼いた起動では後付けが 1 本も効かないため、
 *   「焼いて出荷する」運用は stale cache のときに**無音で保護を外す**
 *   (2FA 秘密 GET が 409 でなく 200 を返す等)。したがって本定義は
 *   config / event / view の 3 つだけを焼き、routing は毎リクエスト組み立てる。
 *   機序と実測の正本は `docs/app-integration-guide.md` §7c、運用要件は `AGENTS.md`
 *   「運用要件 (route:cache)」、逸脱の登録は `docs/template-divergence.md` D19。
 *
 *   ★実装上の注意: 上記の理由から、`deploy` タスクは Deployer 同梱 recipe の既定構成
 *   (routing の cache 生成を含む複合タスクを呼ぶ) を使わず、**必要なタスクだけを自前で列挙**する。
 *   既定タスクを `remove()` して削る書き方は、削る対象のタスク名を文字列として
 *   書く必要があり、それ自体が上記トリップワイヤの検出対象になるため採らない。
 *
 * ★キューワーカーの `--timeout` の**正本は `docs/architecture.md`
 *   §キューのリース期間とワーカー制限時間の規約 の値表**である。
 *   本定義は systemd unit を start/restart するだけで、`--timeout` の値は**持たない・触らない**
 *   (値を 2 か所に置くと必ず食い違い、リース切れによる二重実行を生む)。
 *   値を変えるときは値表と server 側の systemd unit を直すこと。
 *
 * 実行は開発機 (devcontainer 内) から `vendor/bin/dep deploy` で行う。
 * サーバー構成・初回手順・ロールバック・HTTPS 切替は `docs/deployment-runbook.md` が正本。
 */

namespace Deployer;

require 'recipe/laravel.php';

/*
 * ---------------------------------------------------------------------------
 * 全体設定
 * ---------------------------------------------------------------------------
 */

set('application', 'aicue');
set('repository', 'git@github.com:kent013/aicue.git');

// リリースの保持数。1 台構成の開発/staging サーバーでディスクが小さいため既定 (10) より絞る。
set('keep_releases', 5);

/*
 * `run()` / `runLocally()` の既定 timeout (秒)。既定は 300 だが、
 * サーバー上で composer install と vite build を回すため足りない。
 * 個別に長い上限が要る run() には引数で更に上限を渡す。
 */
set('default_timeout', 900);

/*
 * shared/ に置いて全 release で共有するもの。
 * - `.env`: 秘密を含むため git に入れない。サーバーの `shared/.env` が唯一の正本。
 * - `storage`: ログ・セッション・`storage/app/public` を release 跨ぎで維持する。
 */
set('shared_files', ['.env']);
set('shared_dirs', ['storage']);

/*
 * 書き込み可能にするディレクトリ。laravel recipe の既定と同じ 2 つだけで足り、増やさない
 * (`public/build` 等はビルド成果物で、生成した deploy ユーザーが所有者になる)。
 */
set('writable_dirs', [
    'bootstrap/cache',
    'storage',
]);

/*
 * writable_mode は Deployer 既定が `acl` (setfacl で http ユーザーへ ACL を足す) だが、
 * 本サーバーは **php-fpm の pool を deploy ユーザーと同じユーザーで動かす単一ユーザー運用**
 * のため、ACL は不要で `chmod` の方が確実である
 * (`acl` は `ps` から http ユーザーを推測し、見つからないと例外で落ちる)。
 *
 * ★プロビジョニング完了後に検証すること: php-fpm pool の user が remote_user と
 *   一致していない場合はここを `chgrp` + `http_group` へ切り替える必要がある。
 */
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_recursive', true);

/*
 * composer の実行オプション。dev 依存を入れず、autoloader を最適化する。
 * `--no-interaction` はプロンプトで固まらないため、非対話実行では必須。
 */
set('composer_options', '--prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader');

/*
 * ---------------------------------------------------------------------------
 * host 定義
 * ---------------------------------------------------------------------------
 *
 * Lightsail 1 台構成の開発/staging サーバー。ドメイン (aicue.jp) は未取得のため
 * IP 直打ちで、TLS も後回しである (切替手順は docs/deployment-runbook.md)。
 */
host('aicue')
    ->setHostname('13.192.189.252')
    ->setRemoteUser('ec2-user')
    ->setLabels(['stage' => 'staging'])
    /*
     * 鍵は明示する。devcontainer には `~/.ssh/config` が無く、
     * ホスト側の `Host aicue` 定義には依存できない (docker-compose.yml の
     * app サービスがこのパスへ読み取り専用で鍵をマウントする)。
     */
    ->setIdentityFile('~/.ssh/aicue_deploy')
    ->set('branch', 'main')
    ->set('deploy_path', '/var/www/aicue')
    /*
     * サーバーの php は 8.4 を想定。Deployer 既定は `which php` の自動検出だが、
     * 複数バージョンが入った箱では意図しない方を引くため固定する。
     *
     * ★プロビジョニング完了後に実測値へ直すこと:
     *   `ssh aicue 'command -v php8.4 php'` の出力に合わせる。
     */
    ->set('bin/php', '/usr/bin/php8.4');

/*
 * ---------------------------------------------------------------------------
 * 自前タスク
 * ---------------------------------------------------------------------------
 */

/*
 * `.env` は shared/ に**人手で先に置く**運用である (秘密を git に入れない)。
 * 置き忘れたまま進むと、`package:discover` や migrate が意味不明なエラーで落ちるか、
 * 最悪 `.env.example` 相当の値で起動してしまう。ここで明示的に止める。
 *
 * ★laravel recipe の `failIfNoEnv` オプションは使わない。common recipe が
 *   `dotenv` を `false` に set しているため、recipe 側の判定が空の test 式になって
 *   常に通ってしまう (実測)。自前で shared/.env を直接見る方が確実である。
 */
desc('shared/.env が配置済みで空でないことを確認する');
task('deploy:check_env', function (): void {
    if (! test('[ -s {{deploy_path}}/shared/.env ]')) {
        throw new \RuntimeException(
            '{{deploy_path}}/shared/.env が無い、または空です。'
            .'デプロイ前にサーバー上へ配置してください (docs/deployment-runbook.md §初回デプロイ)。'
        );
    }
});

/*
 * フロントは**サーバー上でビルドする**。ビルド成果物 (`public/build`) を git に入れず、
 * 開発機のビルド結果を転送する経路も持たないため、release ディレクトリ内で完結させる。
 *
 * pnpm は package.json の `packageManager` (pnpm@11.9.0) を corepack 経由で解決する。
 * サーバーに pnpm を別途入れる必要が無く、開発機と同じバージョンが使われる。
 * `COREPACK_ENABLE_DOWNLOAD_PROMPT=0` を渡すのは、corepack が未取得の pnpm を
 * ダウンロードする前に対話確認を求めて固まるのを防ぐため。
 *
 * ★install も build も corepack 経由に揃えている (指示は build を素の `pnpm` だったが、
 *   素の pnpm はサーバーに入っている任意のバージョンを引くため、揃える方が安全)。
 */
desc('フロントエンドをサーバー上でビルドする');
task('deploy:frontend', function (): void {
    cd('{{release_path}}');

    $env = ['COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0'];

    run('corepack pnpm install --frozen-lockfile', env: $env, timeout: 1800);
    run('corepack pnpm run build', env: $env, timeout: 1800);
});

/*
 * 起動を速くするために焼くのは **config / event / view の 3 つだけ**である。
 * routing は焼かない (ファイル冒頭の契約)。
 *
 * ★composer install の post-autoload-dump が `filament:upgrade` を走らせ、
 *   その中で config / view の cache が clear される。したがってこの生成は
 *   **必ず composer install の後**に置く (前に置くと消される)。
 */
desc('config / event / view の cache を生成する (routing は焼かない)');
task('deploy:app_caches', [
    'artisan:config:cache',
    'artisan:event:cache',
    'artisan:view:cache',
]);

/*
 * 本番相当の設定検査。検査項目の正本は `App\Support\ProductionEnvGuard` で、
 * production 起動時の fail-fast と同一のリストである。
 *
 * ★`--strict` は付けない。付けると APP_ENV が production 以外で fail するため、
 *   `APP_ENV=staging` の本サーバーでは必ず落ちる。staging では
 *   「APP_ENV が production でないので production 専用検査を skip した」warning を出して
 *   通る (mail 設定検査も production 限定なので同時に skip される)。
 *   **production 環境を作るときは `--strict` を付けた別 host 定義にすること**。
 */
desc('本番相当の設定検査を実行する (violations があれば exit 1)');
task('artisan:production_preflight', artisan('production:preflight', ['showOutput']));

/*
 * php-fpm は **reload** (graceful)。実行中のリクエストを落とさずに opcache と
 * `realpath_cache` を捨てさせ、切り替わった current/ を見に行かせる。
 *
 * ★`sudo` を使うため、サーバーの sudoers で remote_user に
 *   `systemctl reload php-fpm` の NOPASSWD 実行を許可しておく必要がある
 *   (docs/deployment-runbook.md §初回デプロイ)。
 */
desc('php-fpm を graceful に reload する');
task('deploy:reload_php_fpm', function (): void {
    run('sudo systemctl reload php-fpm');
});

/*
 * キューワーカーは **restart** (reload では新しいコードを読まない)。
 * 4 本すべてを 1 コマンドで扱うのは、接続ごとに retry_after が違うので
 * 「1 本だけ古いコードで動き続ける」状態を作らないため。
 *
 * scheduler は `aicue-scheduler.timer` が起動ごとに `schedule:run` を新規プロセスで
 * 実行するため、**再起動は不要**である (次回起動で自然に新コードになる)。
 *
 * ★`--timeout` の値はここに書かない。正本は docs/architecture.md の値表であり、
 *   実際の値は server 側の systemd unit が持つ (ファイル冒頭の注意)。
 */
desc('キューワーカー (4 接続) を再起動する');
task('deploy:restart_workers', function (): void {
    run('sudo systemctl restart '.implode(' ', [
        'aicue-queue-default',
        'aicue-queue-analysis',
        'aicue-queue-render',
        'aicue-queue-media',
    ]));
});

/*
 * current/ の切り替え後に、新しいコードを読み直させるための一括処理。
 * deploy と rollback の**両方**から呼ぶ (rollback で古いコードへ戻したのに
 * ワーカーだけ新コードのまま残る、を作らない)。
 */
desc('新しい release を読み込ませるためにサービスを入れ替える');
task('deploy:reload_services', [
    'deploy:reload_php_fpm',
    'deploy:restart_workers',
]);

/*
 * ---------------------------------------------------------------------------
 * deploy タスク (実行順序の正本)
 * ---------------------------------------------------------------------------
 *
 * 同梱 recipe の `deploy` / `deploy:prepare` / `deploy:publish` を使わず、
 * 個々のタスクを明示的に並べる。理由は 2 つ:
 *   1. routing の cache 生成を含む複合タスクを呼ばないことを、読んで確認できるようにする。
 *   2. symlink の切り替えとサービス入れ替えの間に何も挟まないことを固定する。
 *
 * ★`deploy:env` (`.env.example` から `.env` を作る recipe のタスク) は**入れない**。
 *   `.env` は shared/ の実ファイルが唯一の正本で、雛形から自動生成させてはならない。
 *   代わりに `deploy:check_env` で存在を確認する。
 */
desc('aicue をデプロイする');
task('deploy', [
    // --- 準備 ---
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:check_env',
    'deploy:writable',

    // --- 依存とビルド ---
    'deploy:vendors',
    'deploy:frontend',
    'artisan:storage:link',

    // --- 起動用 cache と出荷前検査 ---
    'deploy:app_caches',
    'artisan:production_preflight',

    // --- schema ---
    'artisan:migrate',

    // --- 切り替え ---
    'deploy:symlink',
    'deploy:reload_services',

    // --- 後始末 ---
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
]);

/*
 * 失敗時は必ず lock を外す。外さないと次回の deploy が
 * 「他の誰かがデプロイ中」と誤認して始まらない。
 */
after('deploy:failed', 'deploy:unlock');
fail('deploy', 'deploy:failed');

/*
 * ロールバック (`dep rollback`) でも php-fpm とワーカーを入れ替える。
 * Deployer の `rollback` は current/ の symlink を差し替えるだけなので、
 * これを張らないと「コードは戻ったがワーカーは新コードのまま」になる。
 */
after('rollback', 'deploy:reload_services');
