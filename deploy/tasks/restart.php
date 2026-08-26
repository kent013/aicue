<?php

declare(strict_types=1);

namespace Deployer;

use RuntimeException;

/*
 * band POST_DEPLOY_AND_ROLLBACK: deploy の後 **かつ** rollback の後。
 *
 * rollback にも配線するのが要点。コードだけ戻して php-fpm / queue worker を再起動しないと
 * **旧コードが動き続ける** (家系の donor が after('deploy', ...) だけを配線してこの欠陥を持っていた)。
 *
 * `|| true` は使わない。「worker 再起動が失敗しても deploy 成功」という無言 fail-open は、
 * この task が防ごうとしている欠陥そのものである (W15 が不在を pin する)。
 * 使わない環境は hosts.yml で `*_enabled: false` を **明示宣言**する (無言 skip を作らない)。
 *
 * **`->select()` を付けない**のも同じ理由である。role 名の typo / 未宣言で 0 host にマッチすると
 * 再起動が無言で skip され、まさにこの task が防ぐ欠陥が復活する (配線 gate の W12 は
 * hosts.example.yml に対して回るため、hosts.yml で role を落としたケースは検出できない)。
 * 対象外の host は上記フラグを false と明示宣言して外す。
 *
 * ★**正典との差**: queue worker を supervisor ではなく **systemd** で常駐させる。
 *   フラグ名 (`queue_worker_restart_enabled`) と再起動コマンドが systemd 用になっただけで、
 *   契約 (fail-closed / 明示 skip / rollback 配線 / no `|| true` / no `->select()`) は同じ。
 *   有効かつ unit 未宣言のときは **例外で止める** (空の restart コマンドを撃って
 *   「成功した」ことにしない = 無言 fail-open を作らない)。
 *
 * ★php-fpm は **reload** (graceful)。実行中のリクエストを落とさずに opcache と realpath_cache を
 *   捨てさせ、切り替わった current/ を見に行かせる。
 *   queue worker は **restart** (reload では新しいコードを読まない)。
 *   scheduler timer は触らない (起動ごとに新プロセスなので次回起動から新コードになる)。
 */
desc('Reloads php-fpm and restarts systemd queue workers (fail-closed when enabled)');
task('deploy:restart', function (): void {
    if (get('php_fpm_reload_enabled') === true) {
        $command = get('reload_php_fpm_command');
        if (! is_string($command) || trim($command) === '') {
            throw new RuntimeException(
                'PHP_FPM_RELOAD_COMMAND_MISSING: php_fpm_reload_enabled=true ですが '.
                'reload_php_fpm_command が空です (reload しないまま成功にはしません)'
            );
        }
        run($command);
    } else {
        info('php_fpm_reload_enabled=false: php-fpm の reload を skip しました (host が明示宣言)');
    }

    if (get('queue_worker_restart_enabled') === true) {
        $restart = get('queue_worker_restart_command');
        $units = get('queue_worker_units');

        if (! is_string($restart) || trim($restart) === '') {
            throw new RuntimeException(
                'QUEUE_WORKER_RESTART_COMMAND_MISSING: queue_worker_restart_enabled=true ですが '.
                'queue_worker_restart_command が空です'
            );
        }

        if (! is_array($units) || $units === []) {
            throw new RuntimeException(
                'QUEUE_WORKER_UNITS_MISSING: queue_worker_restart_enabled=true ですが '.
                'queue_worker_units が空です。再起動対象を 1 つも持たないまま '.
                '「worker を再起動した」ことにはしません (旧コードの worker が動き続ける)'
            );
        }

        $arguments = [];
        foreach ($units as $unit) {
            if (! is_string($unit) || trim($unit) === '') {
                throw new RuntimeException(
                    'QUEUE_WORKER_UNITS_INVALID: queue_worker_units に空でない文字列以外が含まれています'
                );
            }
            $arguments[] = escapeshellarg($unit);
        }

        run($restart.' '.implode(' ', $arguments));
    } else {
        info('queue_worker_restart_enabled=false: queue worker の再起動を skip しました。'
            .'worker を常駐させる host では hosts.yml で true を宣言すること '
            .'(未宣言だと deploy 後も旧コードの worker が動き続ける)');
    }
});
after('deploy', 'deploy:restart');
after('rollback', 'deploy:restart');
