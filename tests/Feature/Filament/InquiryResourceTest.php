<?php

declare(strict_types=1);

use App\Enums\Inquiry\InquiryStatus;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\InquiryResource;
use App\Models\AdminUser;
use App\Models\Inquiry;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(AdminUser::factory()->withMfa()->create(), 'admin');
});

test('一覧 / 編集ページが表示できる', function (): void {
    $inquiry = Inquiry::factory()->create();

    $this->get(InquiryResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->get(InquiryResource::getUrl('edit', ['record' => $inquiry], panel: 'admin'))->assertOk();
});

test('管理画面から作成はできない (受付は公開フォームのみ)', function (): void {
    expect(InquiryResource::canCreate())->toBeFalse();
});

test('status を編集すると closed_at が自動集約される (PII は編集されない)', function (): void {
    $inquiry = Inquiry::factory()->create(['name' => '編集前 太郎']);

    Livewire::test(EditInquiry::class, ['record' => $inquiry->getRouteKey()])
        ->fillForm(['status' => InquiryStatus::Closed->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $inquiry->refresh();
    expect($inquiry->status)->toBe(InquiryStatus::Closed);
    expect($inquiry->closed_at)->not->toBeNull();
    // PII は disabled (read-only) のまま変更されない
    expect($inquiry->name)->toBe('編集前 太郎');
});

test('email 検索は正規化 + blind index (inquiry_email_index) で exact-match する', function (): void {
    $target = Inquiry::factory()->create(['email' => 'target@example.com']);
    $other = Inquiry::factory()->create(['email' => 'other@example.com']);

    Livewire::test(ListInquiries::class)
        // 大文字入力でも EmailNormalizer で正規化されて一致する
        ->searchTable('Target@Example.COM')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

test('削除は DeleteInquiryAction 経由で blind_indexes ごと物理削除される', function (): void {
    $inquiry = Inquiry::factory()->create(['email' => 'delete-me@example.com']);

    // EditRecord の DeleteAction は削除成功後にリダイレクトし Livewire component が
    // 差し替わる (instance() が null になる) ため、mounted-action schema を参照する
    // assertHasNoActionErrors は使えない。generic な assertHasNoErrors (直近レスポンスの
    // error bag 検査) で「バリデーションエラー無し」を確認し、物理削除は下の DB で固定する。
    Livewire::test(EditInquiry::class, ['record' => $inquiry->getRouteKey()])
        ->callAction(DeleteAction::class)
        ->assertHasNoErrors();

    expect(Inquiry::whereKey($inquiry->id)->exists())->toBeFalse();
    expect(DB::table('blind_indexes')
        ->where('indexable_type', $inquiry->getMorphClass())
        ->where('indexable_id', $inquiry->id)
        ->exists())->toBeFalse();
});
