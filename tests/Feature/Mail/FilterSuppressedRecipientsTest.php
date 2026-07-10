<?php

declare(strict_types=1);

use App\Models\EmailSuppression;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\PlainTestMail;

/**
 * 送信前チェック FilterSuppressedRecipients。
 *
 * Mail::fake() は MessageSending より前にメーラを差し替え listener を素通りさせるため使わない。
 * 実 array transport で送信し、送信済メッセージを直接検査する。
 */
beforeEach(function (): void {
    config(['mail.default' => 'array']);
});

/** @return Collection<int, SentMessage> */
function arrayMessages(): Collection
{
    $transport = Mail::mailer('array')->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return $transport->messages();
}

test('抑止アドレス宛 (to のみ) は送信されない', function (): void {
    EmailSuppression::factory()->forEmail('blocked@example.com')->create();

    Mail::to('blocked@example.com')->send(new PlainTestMail);

    expect(arrayMessages())->toHaveCount(0);
});

test('非抑止アドレスは通常送信される', function (): void {
    Mail::to('ok@example.com')->send(new PlainTestMail);

    expect(arrayMessages())->toHaveCount(1);
});

test('複数宛先は抑止分のみ除去し、残りで送信継続', function (): void {
    EmailSuppression::factory()->forEmail('blocked@example.com')->create();

    Mail::raw('body', function ($message): void {
        $message->to('keep@example.com')->cc('blocked@example.com')->subject('x');
    });

    $sent = arrayMessages();
    expect($sent)->toHaveCount(1); // to が残るため送信継続

    $email = $sent->first()->getOriginalMessage();
    assert($email instanceof Email);

    $to = array_map(fn ($a) => $a->getAddress(), $email->getTo());
    $cc = array_map(fn ($a) => $a->getAddress(), $email->getCc());

    expect($to)->toContain('keep@example.com')
        ->and($cc)->not->toContain('blocked@example.com');
});

test('キュー経路 (sync) でも listener が発火し抑止宛先は送信されない', function (): void {
    config(['queue.default' => 'sync']);
    EmailSuppression::factory()->forEmail('blocked@example.com')->create();

    Mail::to('blocked@example.com')->queue(new PlainTestMail);

    expect(arrayMessages())->toHaveCount(0);
});
