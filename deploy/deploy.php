<?php

declare(strict_types=1);

namespace Deployer;

use RuntimeException;

/*
 * Deployer の設定ファイル (家系正典 deployer-pipeline v1 の aicue 適合形)。
 *
 * 起動形は **B 形** が正典である:
 *   vendor/bin/dep -f deploy/deploy.php <task> <host>
 * 実運用の単一入口は `bash scripts/deploy.sh <host>` であり、直叩きは使わない
 * (本番ゲートは deploy:confirm-stage が fail-closed で担保するので直叩きも止まる)。
 * 開発機には php が無いため、実際の起動は `docker exec -w /workspace <container> bash scripts/deploy.sh <host>`
 * になる (コンテナ名は docker-compose.yml が正本。ここには焼き付けない)。
 *
 * recipe/laravel.php は Deployer 自身の include_path から読む。
 * `require 'vendor/autoload.php'` は **書かない** — Deployer は自身の autoloader を持っており、
 * ここで composer autoload を読んでも App\* は解決できない (phar 経路では no-op)。
 * この判断は tests/Architecture/DeployPipelineWiringTest.php W14 が pin する。
 *
 * **正典との差 1**: 正典は `declare(strict_types=1)` を付けない (Deployer recipe の作法) が、
 * aicue は `StrictTypesDeclarationGateTest` が**追跡下の PHP 全数**に宣言を要求し、
 * 免除の登録簿を持たない (docs/template-divergence.md D15)。宣言は呼び出し側のファイル単位に
 * 効くだけなので、非 strict な recipe を require することと衝突しない。
 *
 * `deploy/` を phpstan.neon の paths に含めない理由と代替 gate は phpstan.neon のコメント参照。
 *
 * ★キューワーカーの `--timeout` の**正本は docs/architecture.md の値表**である。
 *   本定義は systemd unit を restart するだけで `--timeout` の値は**持たない・触らない**
 *   (値を 2 か所に置くと必ず食い違い、リース切れによる二重実行を生む)。
 */
require 'recipe/laravel.php';

// ── アプリ座標 (初期化時に一度だけ決まる。env で動かす値ではない) ────────────
set('application', 'aicue');
set('repository', 'git@github.com:kent013/aicue.git');

// ── host 依存の座標 (既定値。hosts.yml の host 単位設定で上書きする) ─────────
set('branch', 'main');
set('git_tty', false);
// 失敗した release も枠を消費する。ロールバック可能深度に直結するので host 単位で調整する。
set('keep_releases', 5);

/*
 * `run()` の既定 timeout (秒)。Deployer 既定の 300 では、1 台構成の小さな箱で
 * composer install と vite build を回すのに足りない (**正典との差 2**。正典は既定のまま)。
 */
set('default_timeout', 900);

/*
 * `.env` の場所を **明示**する (**正典との差 3**)。
 *
 * recipe/common.php は `set('dotenv', false)` を宣言しており、この状態では
 * laravel recipe の `skipIfNoEnv` / `failIfNoEnv` が生成する条件式が `[ -s ]` になる
 * (単一引数の `[ ]` は非空文字列判定なので**常に真**) = 検査が空洞化する。実測済み。
 * ここで実パスを宣言することで `failIfNoEnv` が本当に機能し、
 * `shared/.env` を置き忘れたデプロイが deploy:verify で fail-closed に止まる。
 */
set('dotenv', '{{release_or_current_path}}/.env');

// stage: 既定は非本番。本番 host は hosts.yml で `stage: production` を宣言する。
set('stage', 'dev');
// production_ack: 本番 deploy の意思表示。scripts/deploy.sh が人間ゲート通過後に
// `-o production_ack=1` で注入する。
// **ただし `-o` は誰でも渡せる公開 option なので、ack 単体は「人間が確認した」証明にならない。**
// そのため deploy:confirm-stage は本番 host に対して ack に加えて TTY も要求する (下記)。
set('production_ack', '');

/*
 * deploy:verify (production:preflight) の `--strict` は **stage から導出**する
 * (**正典との差 4**。正典は常に `--strict`)。
 *
 * `--strict` は APP_ENV が production でないと fail する。aicue の稼働 host は
 * `APP_ENV=staging` なので、常時 `--strict` にすると staging デプロイが必ず落ちる。
 * 逆に固定で外すと本番 host を作ったときに検査が緩んだままになる。
 * stage から導出すれば「本番 host では必ず strict」が構造的に保たれる。
 */
set('preflight_strict_flag', function (): string {
    return get('stage') === 'production' ? '--strict' : '';
});

/*
 * 再起動: 有効なら fail-closed / 無効なら明示 skip (tasks/restart.php を参照)。
 *
 * ★**正典との差 5**: 正典は supervisor 前提 (`supervisor_enabled` / `supervisorctl_bin` /
 *   `supervisor_program_group`) だが、aicue のサーバーは queue worker を **systemd unit**
 *   として常駐させる。フラグ名と再起動コマンドを systemd 用に置き換えたうえで、
 *   正典の契約 (「有効なら fail-closed」「無効は明示宣言」「rollback にも配線」
 *   「`|| true` を使わない」「`->select()` を付けない」) はそのまま維持している。
 */
set('php_fpm_reload_enabled', true);
set('reload_php_fpm_command', 'sudo systemctl reload php-fpm');
set('queue_worker_restart_enabled', false);
set('queue_worker_restart_command', 'sudo systemctl restart');
/*
 * 再起動対象の systemd unit。**接続の本数と対応する**アプリ側の知識なので
 * (config/queue.php の 4 接続) host 座標ではなくここに既定を置く。
 * scheduler (`aicue-scheduler.timer`) は含めない — 起動ごとに `schedule:run` を
 * 新規プロセスで実行するため、次回起動で自然に新コードになる。
 */
set('queue_worker_units', [
    'aicue-queue-default',
    'aicue-queue-analysis',
    'aicue-queue-render',
    'aicue-queue-media',
]);

// ── shared / writable ──────────────────────────────────────────────────
set('shared_files', ['.env']);
set('shared_dirs', ['storage']);
set('writable_dirs', [
    'bootstrap/cache',
    'storage',
    'storage/app',
    'storage/app/public',
    'storage/framework',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
]);

/*
 * writable_mode は Deployer 既定が `acl` (setfacl で http ユーザーへ ACL を足す) だが、
 * aicue のサーバーは **php-fpm の pool を deploy ユーザーと同じユーザーで動かす単一ユーザー運用**
 * のため ACL は不要で `chmod` の方が確実である (**正典との差 6**)。
 * `acl` は `ps` から http ユーザーを推測し、見つからないと例外で落ちる。
 * php-fpm pool の user が remote_user と食い違う host を作るときは、その host で
 * `writable_mode: chgrp` + `http_group` を宣言すること。
 */
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_recursive', true);

// composer_options は recipe 既定 (--no-dev 込み) を **変更しない**。
// 本番 host に dev 依存 (deployer 本体を含む) が載る形を採らない。
// この不変条件は DeployPipelineWiringTest W17 が pin する。

// ── task 定義 (band = tests/Architecture/DeployPipelineWiringTest.php の台帳) ──
require __DIR__.'/tasks/check-env.php';    // band PRE_SHARED (正典に無い。理由は同ファイル冒頭)
require __DIR__.'/tasks/frontend.php';     // band POST_VENDORS_PRE_SYMLINK
require __DIR__.'/tasks/verify.php';       // band PRE_MIGRATE_VERIFY
require __DIR__.'/tasks/restart.php';      // band POST_DEPLOY_AND_ROLLBACK

/*
 * ★**正典の task のうち aicue が採らないもの** (DeployPipelineWiringTest の
 *   DEPLOY_TASK_OMITTED 台帳が「不在の申告」として機械固定する。理由もそこに書いてある):
 *   - deploy/tasks/submodules.php  … aicue に `.gitmodules` が無い
 *   - deploy/tasks/cli-oauth.php   … 有効化の前提 3 点をどれも満たさない (部分 unique index /
 *                                     ちょうど 1 件の厳密判定 / 復旧コマンドが無い)
 */

// migrate は 1 host でのみ実行する (多重実行防止)。roles=db を持つ host に限定。
task('artisan:migrate', artisan('migrate --force', ['skipIfNoEnv']))
    ->once()
    ->select('roles=db');

/*
 * 本番 host への誤爆を Deployer 側で fail-closed に止める。
 *
 * task body に run() が無いため **リモート接続前にローカルで判定が終わる**ので、
 * scripts/deploy.sh を迂回した `dep deploy <prd>` 直叩きも止まる。
 *
 * 3 つの条件を **すべて** 課す:
 *
 *  1. 本番 host には `production_ack=1` が必要 (意思表示)
 *  2. 非本番 host に `production_ack=1` を渡すのは誤り (**逆方向**。host と意思表示の
 *     不一致はどちら向きも「間違えている」)
 *  3. 本番 host への deploy は **対話端末 (TTY) からのみ**
 *
 * 3. が要る理由: `production_ack` は `-o` で誰でも渡せる公開 option なので、これ単体では
 * 「人間が確認した」ことの証明にならない。非 TTY を Deployer 側でも拒否することで、
 * **CI / pipe / agent からの自動実行**という最も危険な経路を wrapper の外側でも閉じる。
 * 人間相手の確認 (算術チャレンジ) は wrapper、機械的な下限は Deployer という役割分担である。
 *
 * 意図的に **エラー文面を 3 通りに分けている**: 振る舞いテストが「ack は受理された上で
 * TTY 条件だけが止めた」ことを区別できるようにするため。
 */
desc('Blocks production deploys that did not pass the human confirmation gate');
task('deploy:confirm-stage', function (): void {
    $stage = get('stage');
    $ack = get('production_ack');

    if ($stage === 'production' && $ack !== '1') {
        throw new RuntimeException(
            "PRODUCTION_ACK_MISSING: 本番 host への deploy は scripts/deploy.sh 経由でのみ実行できます。\n".
            '  bash scripts/deploy.sh <host> --production   (TTY + 算術確認ゲートを通ります)'
        );
    }

    // 逆方向: 非本番 host に本番 ack が渡っている = host を間違えている。
    if ($stage !== 'production' && $ack === '1') {
        throw new RuntimeException(
            'PRODUCTION_ACK_ON_NON_PRODUCTION: --production を指定しましたが host の stage は '.
            "'".(is_scalar($stage) ? (string) $stage : gettype($stage))."' です。host 指定を確認してください。"
        );
    }

    if ($stage === 'production' && ! stream_isatty(STDIN)) {
        throw new RuntimeException(
            "PRODUCTION_REQUIRES_TTY: 本番 host への deploy は対話端末 (TTY) からのみ実行できます。\n".
            '  CI / pipe / agent からの自動実行は許可されていません '.
            '(production_ack は -o で渡せる公開 option なので、それ単体では人間の確認の証明にならない)'
        );
    }
});
before('deploy', 'deploy:confirm-stage');

after('deploy:failed', 'deploy:unlock');

/*
 * rollback には release 非依存の副作用を持つ task を配線しない (家系の兄弟アプリで得た教訓)。
 * 例: CLI OAuth client は release 非依存の DB 行であり、revoke すれば全 CLI 利用者が
 * 強制ログアウトされる。この不在は DeployPipelineWiringTest W20 が pin する。
 */

// ── hosts ──────────────────────────────────────────────────────────
// hosts.yml は座標 (gitignore)。追跡下には hosts.example.yml のみを置く
// (拡張子が末尾なのは Deployer の Importer が `/\.ya?ml$/i` で判定するため)。
// 不在時はここでは何もしない (設定ファイルの top-level で writeln() を呼ぶと Deployer が
// "Context was requested but was not available." で落ちる。案内は scripts/deploy.sh が出す)。
// DEPLOY_HOSTS_FILE は Architecture gate が hosts.example.yml を検証するための差し替え口。
$hostsFile = getenv('DEPLOY_HOSTS_FILE') ?: __DIR__.'/hosts.yml';
if (file_exists($hostsFile)) {
    import($hostsFile);
}
