<?php

declare(strict_types=1);

use App\Enums\Inquiry\InquiryStatus;
use App\Mail\InquiryAcknowledgementMail;
use App\Mail\InquiryReceivedMail;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> 正当な問い合わせ POST payload
 */
function validInquiryPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'general',
        'name' => '山田 太郎',
        'email' => 'taro@example.com',
        'company_name' => 'テスト株式会社',
        'message' => 'テンプレートについて相談したいです。',
        'terms_accepted' => '1',
        // testing では reCAPTCHA secret 未設定 = fail-open のため任意 token で通る
        'g-recaptcha-response' => 'test-token',
    ], $overrides);
}

// CACHE_STORE=array (per-test 揮発) のため throttle:inquiry のヒットはテスト間で漏れない

test('問い合わせフォームが表示される', function (): void {
    $this->get('/contact')->assertOk();
});

test('thanks ページは直接 GET でも 200 (PII なし固定表示)', function (): void {
    $this->get('/contact/thanks')->assertOk();
});

test('正当な問い合わせが保存され、通知 2 通が queue される (同意の証跡つき)', function (): void {
    Mail::fake();

    $response = $this->post('/contact', validInquiryPayload());

    $response->assertRedirect(route('contact.thanks'));

    $inquiry = Inquiry::whereBlind('email', 'inquiry_email_index', 'taro@example.com')->firstOrFail();
    expect($inquiry->status)->toBe(InquiryStatus::Open);
    expect($inquiry->name)->toBe('山田 太郎');
    expect($inquiry->company_name)->toBe('テスト株式会社');
    expect($inquiry->terms_accepted_at)->not->toBeNull();
    expect($inquiry->consent_version)->toBe(config()->string('legal.consent_version'));

    // 運営宛 (PII あり) + 送信者宛 ack (固定文面) の 2 通
    Mail::assertQueued(InquiryReceivedMail::class, function (InquiryReceivedMail $mail): bool {
        return $mail->hasTo(config()->string('legal.inquiry_recipient'));
    });
    Mail::assertQueued(InquiryAcknowledgementMail::class, function (InquiryAcknowledgementMail $mail): bool {
        return $mail->hasTo('taro@example.com');
    });
});

test('email は正規化 (trim + 小文字化) されて保存される (blind index の同値規則)', function (): void {
    Mail::fake();

    $this->post('/contact', validInquiryPayload(['email' => '  Taro@Example.COM ']))
        ->assertRedirect(route('contact.thanks'));

    expect(Inquiry::whereBlind('email', 'inquiry_email_index', 'taro@example.com')->exists())->toBeTrue();
});

test('source は allowlist で正規化される (許可外は null)', function (): void {
    Mail::fake();

    $this->post('/contact', validInquiryPayload(['source' => 'landing', 'email' => 'a@example.com']));
    $this->post('/contact', validInquiryPayload(['source' => 'evil-input', 'email' => 'b@example.com']));

    expect(Inquiry::whereBlind('email', 'inquiry_email_index', 'a@example.com')->firstOrFail()->source?->value)
        ->toBe('landing');
    expect(Inquiry::whereBlind('email', 'inquiry_email_index', 'b@example.com')->firstOrFail()->source)
        ->toBeNull();
});

test('honeypot 充填時は保存も通知もせず、正常時と同一レスポンスを返す (silent drop)', function (): void {
    Mail::fake();

    // 必須項目が欠けた bot 送信でも validation error にならない (空ルール)
    $response = $this->post('/contact', [
        'website' => 'http://spam.example.com',
        'email' => 'bot@example.com',
    ]);

    $response->assertRedirect(route('contact.thanks'));
    expect(Inquiry::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('利用規約に同意しないと受け付けない', function (): void {
    $this->from('/contact')
        ->post('/contact', validInquiryPayload(['terms_accepted' => null]))
        ->assertSessionHasErrors('terms_accepted');

    expect(Inquiry::count())->toBe(0);
});

test('reCAPTCHA token 無しは validation error (honeypot 非充填時)', function (): void {
    $this->from('/contact')
        ->post('/contact', validInquiryPayload(['g-recaptcha-response' => null]))
        ->assertSessionHasErrors('g-recaptcha-response');

    expect(Inquiry::count())->toBe(0);
});

test('保護キー (organization_id 等) を含む payload は 422 (入口防御)', function (): void {
    $this->from('/contact')
        ->post('/contact', validInquiryPayload(['organization_id' => 1]))
        ->assertSessionHasErrors('organization_id');

    expect(Inquiry::count())->toBe(0);
});

test('運用列 (status) は payload から書き込めない ($fillable 外)', function (): void {
    Mail::fake();

    $this->post('/contact', validInquiryPayload(['status' => 'closed']))
        ->assertRedirect(route('contact.thanks'));

    $inquiry = Inquiry::whereBlind('email', 'inquiry_email_index', 'taro@example.com')->firstOrFail();
    expect($inquiry->status)->toBe(InquiryStatus::Open);
    expect($inquiry->closed_at)->toBeNull();
});

test('throttle:inquiry が同一 IP の連続送信を 429 で抑制する', function (): void {
    // limiter は validation 前に走るため、payload は空でもヒット数に数えられる
    for ($i = 0; $i < 5; $i++) {
        $this->post('/contact', [])->assertStatus(302);
    }

    $this->post('/contact', [])->assertStatus(429);
});
