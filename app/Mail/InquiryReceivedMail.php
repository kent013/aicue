<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 運営宛の問い合わせ受信通知 (PII あり)。
 *
 * 氏名・連絡先・本文を含む PII の派生物であり、通知先 mailbox は PII 保管先として扱う。
 * ShouldQueue で非同期送信 (失敗は failed_jobs + report で監視、受付処理を巻き込まない)。
 * ユーザー入力を含むため markdown mailable にしない (記法が解釈され内容忠実性が落ちる)。
 */
class InquiryReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly string $recordUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "新しいお問い合わせ（{$this->inquiry->type->label()}）",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.inquiry.received',
            with: [
                'typeLabel' => $this->inquiry->type->label(),
                'sourceLabel' => $this->inquiry->source?->label(),
                'name' => $this->inquiry->name,
                'email' => $this->inquiry->email,
                'companyName' => $this->inquiry->company_name,
                'inquiryMessage' => $this->inquiry->message,
                'receivedAt' => $this->inquiry->created_at?->format('Y年m月d日 H:i'),
                'recordUrl' => $this->recordUrl,
            ],
        );
    }
}
