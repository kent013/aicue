<?php

declare(strict_types=1);

namespace App\Actions\Inquiry;

use App\DataTransferObjects\Inquiry\CreateInquiryData;
use App\Enums\Inquiry\InquiryStatus;
use App\Filament\Resources\InquiryResource;
use App\Mail\InquiryAcknowledgementMail;
use App\Mail\InquiryReceivedMail;
use App\Models\Inquiry;
use App\Support\Legal\LegalConsent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 公開フォームから受け付けた問い合わせを保存し、通知を送る単一の書き込み経路。
 *
 * - 単一 save のため明示 DB::transaction は使わない (不要な複雑化を避ける)。
 * - 通知 2 通 (運営宛 / 送信者宛 ack) は保存成功後に queue へ投入する。dispatch 失敗が
 *   受付 (= 保存済み) を 500 で巻き込まないよう、それぞれ try/catch + log で保護する。
 * - honeypot bot はここに到達しない (Controller が silent drop する) ため、
 *   本 Action は「正当な受付のみ」を前提にできる。
 */
final class CreateInquiryAction
{
    public function __invoke(CreateInquiryData $data): Inquiry
    {
        // 同意の証跡: 受付時刻 + 同意時点のポリシー版。空版の fail-fast は LegalConsent が持つ。
        $consentVersion = LegalConsent::version();

        // 運営宛通知の宛先を save 前に解決して fail-fast (設定破損時に孤児行を作らない)。
        $recipient = config('legal.inquiry_recipient');
        Assert::stringNotEmpty($recipient, 'legal.inquiry_recipient (or MAIL_FROM_ADDRESS) must be configured');

        $inquiry = new Inquiry;
        $inquiry->fill([
            'name' => $data->name,
            'email' => $data->email,
            'company_name' => $data->companyName,
            'message' => $data->message,
            'type' => $data->type,
            'source' => $data->source,
        ]);
        // 運用列は $guarded のため明示代入。初回 INSERT に同時設定 (save は 1 回)。
        $inquiry->status = InquiryStatus::Open;
        $inquiry->forceFill([
            'terms_accepted_at' => now(),
            'consent_version' => $consentVersion,
        ]);
        $inquiry->save();

        // 公開 (非 Filament) リクエストから呼ばれるため panel を明示する。
        $recordUrl = InquiryResource::getUrl('edit', ['record' => $inquiry], panel: 'admin');

        $this->dispatchNotification(
            fn () => Mail::to($recipient)->queue(new InquiryReceivedMail($inquiry, $recordUrl)),
            'inquiry_received',
            $inquiry->id,
        );
        // 送信者宛 ack は固定文面 (type のみ渡す = ユーザー入力を echo しない)。
        $this->dispatchNotification(
            fn () => Mail::to($inquiry->email)->queue(new InquiryAcknowledgementMail($inquiry->type)),
            'inquiry_acknowledgement',
            $inquiry->id,
        );

        return $inquiry;
    }

    /**
     * 通知 dispatch の失敗が受付を巻き込まないよう保護する。
     * 例外 message に宛先 email 等の PII が混じり得るため warning context には載せず、
     * 詳細は report() (例外ハンドラ) に委ねる。
     */
    private function dispatchNotification(callable $dispatch, string $kind, int $inquiryId): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
            Log::warning('inquiry.notification_dispatch_failed', [
                'kind' => $kind,
                'inquiry_id' => $inquiryId,
                'exception_class' => $e::class,
            ]);
        }
    }
}
