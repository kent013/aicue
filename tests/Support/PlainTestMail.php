<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 送信前フィルタ (FilterSuppressedRecipients) テスト用の最小 Mailable。
 */
final class PlainTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'test');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>test</p>');
    }
}
