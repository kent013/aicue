<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Inquiry\DeleteInquiryAction;
use App\Enums\Inquiry\InquiryStatus;
use App\Models\Inquiry;
use App\Support\EmailNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 問い合わせ (Inquiry) の retention purge / 本人削除要請対応。
 *
 * - 既定 dry-run (件数のみ表示)。実削除は --apply 指定時のみ。
 * - 時刻 purge: 対象 status は spam / closed 固定。基準は spam=created_at / closed=closed_at
 *   (closed_at null は対象外 = fail-safe)。保持日数は --older-than-days で上書き、
 *   未指定は config('legal.inquiry_retention_days') (daily スケジュールはこの既定で回る)。
 * - 本人削除要請: --email-file (平文 email を argv / shell history に残さない)。
 *   指定時は status / 時刻の制限を外し本人の全 Inquiry を対象にする。
 * - 削除は DeleteInquiryAction 経由 (CipherSweet deleting フック発火 + 構造化ログ)。
 */
class PurgeInquiriesCommand extends Command
{
    protected $signature = 'inquiry:purge
        {--apply : 実削除する (未指定は dry-run)}
        {--older-than-days= : 保持日数を上書き (未指定は config legal.inquiry_retention_days)}
        {--email-file= : 本人削除要請: email を記したファイルパス (argv に平文を置かない)}';

    protected $description = '問い合わせ (Inquiry) の retention purge / 本人削除要請対応 (既定 dry-run)';

    public function handle(DeleteInquiryAction $deleteAction): int
    {
        $emailFile = $this->option('email-file');
        $hasEmail = is_string($emailFile) && $emailFile !== '';

        $normalizedEmail = null;
        if ($hasEmail) {
            if (! is_file($emailFile)) {
                $this->error("email-file が見つかりません: {$emailFile}");

                return self::FAILURE;
            }
            // 保存時と同一の正規化 (EmailNormalizer) を通して blind index を exact-match させる。
            $normalizedEmail = EmailNormalizer::normalize((string) file_get_contents($emailFile));
            if ($normalizedEmail === '') {
                $this->error('email-file の中身が空です。');

                return self::FAILURE;
            }
        }

        $threshold = $this->resolveThreshold($hasEmail);
        if ($threshold === false) {
            return self::FAILURE;
        }

        /** @var Collection<int, Inquiry> $matched */
        $matched = $this->buildQuery($normalizedEmail, $threshold)->get();

        if ($matched->isEmpty()) {
            $this->info('対象 0 件。');

            return self::SUCCESS;
        }

        // dry-run: status 別件数を表示して終了。
        if (! $this->option('apply')) {
            $this->info("[dry-run] 対象 {$matched->count()} 件 (--apply で実削除):");
            foreach ($matched->groupBy(fn (Inquiry $i): string => $i->status->value) as $status => $rows) {
                $this->line("  {$status}: ".$rows->count());
            }

            return self::SUCCESS;
        }

        $reason = $hasEmail ? 'subject_request' : 'retention';

        $deleted = 0;
        $failed = 0;
        foreach ($matched as $inquiry) {
            try {
                $deleteAction($inquiry, $reason);
                $deleted++;
            } catch (Throwable $e) {
                $failed++;
                // PII を載せない: inquiry_id + exception_class のみ。
                $exceptionClass = $e::class;
                $this->warn("削除失敗 inquiry_id={$inquiry->id} ({$exceptionClass})");
            }
        }

        $this->info("削除 {$deleted} 件".($failed > 0 ? " / 失敗 {$failed} 件" : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 閾値時刻を解決する。email-file 経路では時刻条件を付けない (本人の全データ削除)。
     * 時刻 purge 経路は --older-than-days 上書き、未指定は config 既定。
     *
     * @return Carbon|null|false null=条件なし(email 経路) / false=入力エラー
     */
    private function resolveThreshold(bool $hasEmail): Carbon|null|false
    {
        if ($hasEmail) {
            return null;
        }

        $override = $this->option('older-than-days');
        if (is_string($override) && $override !== '') {
            if (! ctype_digit($override)) {
                $this->error('--older-than-days は 0 以上の整数で指定してください。');

                return false;
            }

            return Carbon::now()->subDays((int) $override);
        }

        $days = config('legal.inquiry_retention_days');
        Assert::integerish($days);

        return Carbon::now()->subDays((int) $days);
    }

    /**
     * @return Builder<Inquiry>
     */
    private function buildQuery(?string $normalizedEmail, Carbon|null|false $threshold): Builder
    {
        $query = Inquiry::query();

        if ($normalizedEmail !== null) {
            // 本人削除要請: status / 時刻を問わず本人の全 Inquiry。
            return $query->whereBlind('email', 'inquiry_email_index', $normalizedEmail);
        }

        Assert::isInstanceOf($threshold, Carbon::class);

        // 時刻 purge: spam=created_at 基準 / closed=closed_at 基準 (null は対象外)。
        return $query->where(function (Builder $outer) use ($threshold): void {
            $outer
                ->where(fn (Builder $q): Builder => $q
                    ->where('status', InquiryStatus::Spam->value)
                    ->where('created_at', '<=', $threshold))
                ->orWhere(fn (Builder $q): Builder => $q
                    ->where('status', InquiryStatus::Closed->value)
                    ->whereNotNull('closed_at')
                    ->where('closed_at', '<=', $threshold));
        });
    }
}
