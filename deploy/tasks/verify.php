<?php

declare(strict_types=1);

namespace Deployer;

/*
 * band PRE_MIGRATE_VERIFY: artisan:optimize の後・artisan:migrate の **前**。
 *
 * この位置にする理由:
 *  - ProductionEnvGuard::violations() と operations:check-mail-config に DB アクセスは無いため
 *    migrate 後である必要が無い
 *  - migrate 後に止めると「DB は進んだのに current は旧コード」という mixed state を作る。
 *    ここで止まれば DB 無変更 + current 旧リリース維持でクリーンに復旧できる
 *    (復旧は shared/.env を直して再 deploy。rollback ではない)
 *
 * 検査対象は「これから publish される release の実効設定」である:
 *  - .env は deploy:shared (deploy:prepare 内) で shared/.env から symlink 済み
 *  - config は artisan:optimize で新 release 側にキャッシュ済み
 *
 * production:preflight は内部で operations:check-mail-config を $this->call() するため、
 * check-mail-config を別途配線しない (重複させない)。
 *
 * ★**正典との差 1**: anchor が `artisan:config:cache` ではなく `artisan:optimize` である。
 *   Deployer 8 の recipe/laravel.php の既定 deploy は起動キャッシュ生成を
 *   `artisan:optimize` (config / event / route / view を一括) で行う (正典は Deployer 7.5 系)。
 *   「起動キャッシュ生成の後・migrate の前」という区間の意味は同じである。
 *
 * ★**正典との差 2**: `--strict` を固定で付けず `{{preflight_strict_flag}}` で stage から導出する
 *   (deploy/deploy.php の該当 set() にその理由を書いてある)。
 *
 * ★**正典との差 3**: option を `skipIfNoEnv` から `failIfNoEnv` へ強めた。
 *   deploy/deploy.php で `dotenv` の実パスを宣言したことで判定が本当に働くようになったため、
 *   `shared/.env` の置き忘れを「警告して skip」ではなく **fail** で止める
 *   (skip のままだと preflight を通さずに migrate へ進んでしまう)。
 */
desc('Runs production preflight (env baseline + mail config) before migrating');
task('deploy:verify', artisan('production:preflight {{preflight_strict_flag}}', ['failIfNoEnv', 'showOutput']));
before('artisan:migrate', 'deploy:verify');
