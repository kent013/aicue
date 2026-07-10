<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ProductionEnvGuard;
use Illuminate\Console\Command;

/**
 * 本番デプロイ前の設定検査。deploy パイプラインの pre-deploy / post-deploy ステップで
 * `--strict` 付きで実行し、APP_ENV=production と env baseline を機械判定する。
 *
 * 検査項目の SSOT は ProductionEnvGuard (AppServiceProvider の production 起動時
 * fail-fast と同一のリスト)。1 件でも違反があれば exit 1 (デプロイを止める)。
 */
class ProductionPreflightCommand extends Command
{
    protected $signature = 'production:preflight {--strict : APP_ENV が production でない場合も fail させる}';

    protected $description = '本番デプロイ前にセキュリティ必須設定を検査する (violations があれば exit 1)';

    public function handle(ProductionEnvGuard $guard): int
    {
        $envValue = config('app.env');
        $env = is_string($envValue) ? $envValue : '';
        $this->line("APP_ENV: {$env}");

        if ($env !== 'production') {
            if ((bool) $this->option('strict')) {
                $this->error('--strict mode: APP_ENV must be production but got: '.$env);

                return self::FAILURE;
            }
            $this->warn('production 以外の環境のため production 専用の検査を skip しました。');
            $this->info('CI/CD では --strict を付けて APP_ENV の設定ミスも fail させること。');

            return self::SUCCESS;
        }

        $errors = $guard->violations();
        if ($errors !== []) {
            $this->error('Production env baseline violations:');
            foreach ($errors as $err) {
                $this->error('  - '.$err);
            }

            return self::FAILURE;
        }

        // mail 設定の fail-fast 検査 (MAIL_MAILER=log / FROM domain 乖離のサイレント未配信防止)。
        // 検査本体は operations:check-mail-config (単体実行も可) に委譲する
        if ($this->call('operations:check-mail-config') !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->info('production preflight: すべての検査を通過しました');

        return self::SUCCESS;
    }
}
