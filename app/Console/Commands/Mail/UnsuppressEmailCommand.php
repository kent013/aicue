<?php

declare(strict_types=1);

namespace App\Console\Commands\Mail;

use App\Models\EmailSuppression;
use App\Support\EmailNormalizer;
use Illuminate\Console\Command;
use Webmozart\Assert\Assert;

/**
 * メール抑止リストから指定アドレスを解除する。
 *
 * 誤 complaint・再有効化されたアドレスの復旧用。行削除で解除する (reason に manual を
 * 足さない)。SES account-level 抑止は別途 runbook (DeleteSuppressedDestination) で消す。
 */
final class UnsuppressEmailCommand extends Command
{
    protected $signature = 'mail:unsuppress {email}';

    protected $description = 'メール抑止リストから指定アドレスを解除する (誤抑止・再有効化の復旧用)';

    public function handle(): int
    {
        $emailArg = $this->argument('email');
        Assert::string($emailArg);
        if ($emailArg === '') {
            $this->error('email is required.');

            return self::FAILURE;
        }
        $normalized = EmailNormalizer::normalize($emailArg);

        $deleted = EmailSuppression::query()->where('email', $normalized)->delete();

        if ($deleted === 0) {
            $this->warn('抑止リストに該当アドレスはありません。');

            return self::SUCCESS;
        }

        $this->info('抑止を解除しました。');

        return self::SUCCESS;
    }
}
