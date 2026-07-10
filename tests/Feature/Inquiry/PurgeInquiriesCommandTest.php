<?php

declare(strict_types=1);

use App\Models\Inquiry;

test('dry-run (既定) は削除しない', function (): void {
    Inquiry::factory()->spam()->create(['created_at' => now()->subDays(400)]);

    $this->artisan('inquiry:purge')
        ->assertExitCode(0);

    expect(Inquiry::count())->toBe(1);
});

test('--apply で保持期限超過の spam (created_at 基準) / closed (closed_at 基準) を削除する', function (): void {
    $oldSpam = Inquiry::factory()->spam()->create(['created_at' => now()->subDays(400)]);
    $oldClosed = Inquiry::factory()->closed(closedDaysAgo: 400)->create();
    $recentSpam = Inquiry::factory()->spam()->create(['created_at' => now()->subDays(10)]);
    $recentClosed = Inquiry::factory()->closed(closedDaysAgo: 10)->create();
    // open は期限超過でも対象外
    $oldOpen = Inquiry::factory()->staleOpen(createdDaysAgo: 400)->create();

    $this->artisan('inquiry:purge --apply')
        ->assertExitCode(0);

    expect(Inquiry::whereKey($oldSpam->id)->exists())->toBeFalse();
    expect(Inquiry::whereKey($oldClosed->id)->exists())->toBeFalse();
    expect(Inquiry::whereKey($recentSpam->id)->exists())->toBeTrue();
    expect(Inquiry::whereKey($recentClosed->id)->exists())->toBeTrue();
    expect(Inquiry::whereKey($oldOpen->id)->exists())->toBeTrue();
});

test('closed_at が null の closed 行は対象外 (fail-safe)', function (): void {
    $inquiry = Inquiry::factory()->closed()->create([
        'closed_at' => null,
        'created_at' => now()->subDays(400),
    ]);

    $this->artisan('inquiry:purge --apply')->assertExitCode(0);

    expect(Inquiry::whereKey($inquiry->id)->exists())->toBeTrue();
});

test('--older-than-days で保持日数を上書きできる', function (): void {
    $spam = Inquiry::factory()->spam()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('inquiry:purge --apply --older-than-days=5')->assertExitCode(0);

    expect(Inquiry::whereKey($spam->id)->exists())->toBeFalse();
});

test('--older-than-days の不正値はエラー終了する', function (): void {
    $this->artisan('inquiry:purge --older-than-days=abc')
        ->assertExitCode(1);
});

test('--email-file は status / 時刻を問わず本人の全 Inquiry を削除する (正規化つき blind index 照合)', function (): void {
    $subjectOpen = Inquiry::factory()->create(['email' => 'subject@example.com']);
    $subjectClosed = Inquiry::factory()->closed()->create(['email' => 'subject@example.com']);
    $other = Inquiry::factory()->create(['email' => 'other@example.com']);

    $file = sys_get_temp_dir().'/inquiry-purge-test-email.txt';
    // 大文字 + 前後空白でも EmailNormalizer で正規化されて一致する
    file_put_contents($file, "  Subject@Example.COM \n");

    try {
        $this->artisan("inquiry:purge --apply --email-file={$file}")->assertExitCode(0);
    } finally {
        @unlink($file);
    }

    expect(Inquiry::whereKey($subjectOpen->id)->exists())->toBeFalse();
    expect(Inquiry::whereKey($subjectClosed->id)->exists())->toBeFalse();
    expect(Inquiry::whereKey($other->id)->exists())->toBeTrue();
});

test('--email-file が存在しない場合はエラー終了する', function (): void {
    $this->artisan('inquiry:purge --email-file=/nonexistent/path.txt')
        ->assertExitCode(1);
});
