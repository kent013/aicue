<?php

declare(strict_types=1);

namespace Deployer;

use RuntimeException;

/*
 * band PRE_SHARED: deploy:shared の **前** (= deploy:prepare の中)。
 *
 * ★**正典に無い task である**。正典 (テンプレート) は deploy:verify を常に
 *   `production:preflight --strict` で回すため、`.env` が実在しなければそこで必ず落ちた。
 *   aicue は稼働 host が `APP_ENV=staging` なので `--strict` を stage から導出しており
 *   (deploy/deploy.php の preflight_strict_flag)、**非 production では preflight が
 *   「production 専用検査を skip」して成功する**。つまり正典が持っていた fail-closed が
 *   staging では効かない。その穴をここで塞ぐ。
 *
 * 何を防ぐか (Deployer の実装に由来する具体的な事故):
 *   recipe/deploy/env.php (`deploy:env`) は release に `.env` が無ければ `.env.example` を複製し、
 *   その直後の `deploy:shared` は **shared 側に実ファイルが無ければ release 側の実体を
 *   shared へ移してから symlink する**。したがって `shared/.env` を置き忘れたまま初回配布すると
 *   **`.env.example` の値が「秘密の正本」として shared に据わる**。
 *   APP_KEY 未設定・DB 未設定で起動するので即座に壊れるが、壊れ方が「意味不明な 500」になり
 *   原因の特定に時間がかかる。ここで名指しで止める方が復旧が速い。
 *
 * `|| true` は使わない / フラグで無効化する口も作らない。`shared/.env` が要らない host は
 * 存在しない (config は必ず env から来る)ので、条件付きにする理由が無い。
 */
desc('Fails the deploy when shared/.env is missing or empty');
task('deploy:check_env', function (): void {
    if (! test('[ -s {{deploy_path}}/shared/.env ]')) {
        throw new RuntimeException(
            "SHARED_ENV_MISSING: {{deploy_path}}/shared/.env が無い、または空です。\n".
            '  配布の前にサーバー上へ配置してください (docs/deployment-runbook.md の初回配布の章)。'."\n".
            '  ここで止めるのは、置き忘れると Deployer が .env.example を shared へ据えてしまうためです。'
        );
    }
});
before('deploy:shared', 'deploy:check_env');
