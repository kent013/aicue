<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use Illuminate\Console\Command;
use Webmozart\Assert\Assert;

/**
 * production deploy 時に mail 設定を assert する command。
 *
 * サイレント未配信を防ぐため deploy 直後に fail-fast 化する 2 点:
 *  1. `MAIL_MAILER=log` のまま production に出ると、本来送信されるはずのメールが
 *     `storage/logs/laravel.log` に書き出されるだけで配送されない
 *  2. `MAIL_FROM_ADDRESS` の domain と APP_URL host の domain が乖離していると、招待メール等が
 *     SPF / DKIM / DMARC で reject される
 *
 * deploy パイプラインでは `production:preflight` が本 command を呼ぶ (単体実行も可)。
 */
final class CheckMailConfig extends Command
{
    protected $signature = 'operations:check-mail-config';

    protected $description = 'Validate production mail config (FROM domain vs APP_URL host, MAIL_MAILER != log).';

    public function handle(): int
    {
        $env = config('app.env');
        Assert::string($env);
        $isProduction = $env === 'production';

        $errors = [];

        // 1. MAIL_MAILER が production で `log` になっていないことを確認
        $mailer = config('mail.default');
        Assert::string($mailer);
        if ($isProduction && $mailer === 'log') {
            $errors[] = 'MAIL_MAILER=log in production: emails will NOT be delivered.';
        }

        // 2. MAIL_FROM_ADDRESS の domain と APP_URL host の domain が一致することを確認
        //    (production 以外でも警告は出すが、return code には影響させない)
        $fromAddress = config('mail.from.address');
        if (! is_string($fromAddress) || $fromAddress === '') {
            $errors[] = 'MAIL_FROM_ADDRESS is empty.';
        } else {
            $atPos = strrpos($fromAddress, '@');
            $fromDomain = $atPos !== false ? substr($fromAddress, $atPos + 1) : null;

            $appUrl = config('app.url');
            Assert::string($appUrl);
            $appHost = parse_url($appUrl, PHP_URL_HOST);

            if ($fromDomain !== null && is_string($appHost) && $appHost !== '' && $fromDomain !== $appHost) {
                // `www.` prefix の差だけは同一 domain とみなす (soft-equivalence)
                $normFrom = preg_replace('/^www\./', '', $fromDomain) ?? $fromDomain;
                $normHost = preg_replace('/^www\./', '', $appHost) ?? $appHost;
                if ($normFrom !== $normHost) {
                    $msg = sprintf(
                        'MAIL_FROM_ADDRESS domain (%s) does not match APP_URL host (%s).',
                        $fromDomain,
                        $appHost,
                    );
                    if ($isProduction) {
                        $errors[] = $msg;
                    } else {
                        $this->warn('[non-production warning] '.$msg);
                    }
                }
            }
        }

        if ($errors === []) {
            $fromForOutput = (is_string($fromAddress) && $fromAddress !== '') ? $fromAddress : '<empty>';
            $this->info('mail config OK ('.$mailer.', '.$fromForOutput.')');

            return self::SUCCESS;
        }

        foreach ($errors as $err) {
            $this->error($err);
        }

        return self::FAILURE;
    }
}
