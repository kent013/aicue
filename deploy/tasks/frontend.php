<?php

declare(strict_types=1);

namespace Deployer;

/*
 * band POST_VENDORS_PRE_SYMLINK: deploy:vendors の後・deploy:symlink の前。
 *
 * build:packages を build より **前**に置く理由: workspace package (packages/*) の dist/ が
 * 無いとアプリ側の import が ERR_MODULE_NOT_FOUND で落ちる。
 * この順序は DeployPipelineWiringTest W10 が静的にも pin する。
 *
 * `--frozen-lockfile` を使う (再現性の担保)。host の pnpm 版が package.json の packageManager と
 * 食い違うと lockfileVersion 非互換でここが落ちる。**落ちるのが正しい**
 * (別の依存ツリーが解決されて svelte の TS preprocess が外れる事故が家系で実在した)。
 *
 * ★**正典との差**: pnpm を **corepack 経由**で呼ぶ (`corepack pnpm ...`)。
 *   素の `pnpm` は host に入っている任意の版を引くが、corepack は package.json の
 *   `packageManager` を見て解決するので、開発機と同じ版が必ず使われる
 *   (host 側に pnpm を常設する運用が要らなくなる)。
 *   `COREPACK_ENABLE_DOWNLOAD_PROMPT=0` を渡すのは、corepack が未取得の pnpm を
 *   ダウンロードする前に対話確認を求めて**固まる**のを防ぐため
 *   (サーバー側の環境変数としても設定済みだが、非対話実行の前提はここで明示する)。
 *   timeout を個別に伸ばすのは、1 台構成の小さな箱では install と vite build が
 *   default_timeout でも足りないことがあるため。
 */
desc('Installs JS deps and builds workspace packages then app assets');
task('build:frontend', function (): void {
    cd('{{release_path}}');

    $env = ['COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0'];

    run('corepack pnpm install --frozen-lockfile', env: $env, timeout: 1800);
    run('corepack pnpm run build:packages', env: $env, timeout: 1800);
    run('corepack pnpm run build', env: $env, timeout: 1800);
})->select('roles=front');
after('deploy:vendors', 'build:frontend');
