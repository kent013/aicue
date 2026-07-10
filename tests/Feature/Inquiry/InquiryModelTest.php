<?php

declare(strict_types=1);

use App\Enums\Inquiry\InquiryStatus;
use App\Models\Inquiry;

test('status→Closed 遷移で closed_at が自動設定される', function (): void {
    $inquiry = Inquiry::factory()->create();
    expect($inquiry->closed_at)->toBeNull();

    $inquiry->status = InquiryStatus::Closed;
    $inquiry->save();

    expect($inquiry->refresh()->closed_at)->not->toBeNull();
});

test('Closed から reopen すると closed_at が null に戻る', function (): void {
    $inquiry = Inquiry::factory()->closed()->create();
    expect($inquiry->closed_at)->not->toBeNull();

    $inquiry->status = InquiryStatus::InProgress;
    $inquiry->save();

    expect($inquiry->refresh()->closed_at)->toBeNull();
});

test('PII 列は暗号化保存される (DB 生値に平文が現れない)', function (): void {
    $inquiry = Inquiry::factory()->create([
        'name' => '暗号 花子',
        'email' => 'crypto@example.com',
        'message' => '秘密の相談内容',
    ]);

    $raw = DB::table('inquiries')->where('id', $inquiry->id)->first();
    expect($raw->name)->not->toContain('暗号 花子');
    expect($raw->email)->not->toContain('crypto@example.com');
    expect($raw->message)->not->toContain('秘密の相談内容');

    // 復号経路 (model read) では平文が読める
    expect($inquiry->refresh()->name)->toBe('暗号 花子');
});

test('model 単位 delete で共有 blind_indexes の該当行もクリーンアップされる', function (): void {
    $inquiry = Inquiry::factory()->create(['email' => 'cleanup@example.com']);
    expect(Inquiry::whereBlind('email', 'inquiry_email_index', 'cleanup@example.com')->exists())->toBeTrue();

    $inquiry->delete();

    expect(Inquiry::whereBlind('email', 'inquiry_email_index', 'cleanup@example.com')->exists())->toBeFalse();
    expect(DB::table('blind_indexes')
        ->where('indexable_type', $inquiry->getMorphClass())
        ->where('indexable_id', $inquiry->id)
        ->exists())->toBeFalse();
});
