<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\Inquiry\InquiryType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 送信者宛の自動受付確認メール (固定文面)。
 *
 * 公開エンドポイント由来のため、踏み台耐性として **ユーザー入力本文 (message) や氏名・
 * 連絡先を本文に echo しない**。種別 label と固定の定型文のみで構成する (万一 victim の
 * アドレスが入力されても、攻撃者の任意文面を victim へ送れないよう、Inquiry 全体ではなく
 * InquiryType のみを受け取る)。
 */
class InquiryAcknowledgementMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly InquiryType $type,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'お問い合わせを受け付けました',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.inquiry.acknowledgement',
            with: [
                'typeLabel' => $this->type->label(),
            ],
        );
    }
}
