<?php

declare(strict_types=1);

use App\Filament\Resources\ModelAuditResource;
use App\Models\AdminUser;
use App\Models\ModelAudit;

beforeEach(function (): void {
    $this->actingAs(AdminUser::factory()->withMfa()->create(), 'admin');
});

test('一覧 / 詳細ページが表示できる', function (): void {
    $audit = ModelAudit::factory()->create();

    $this->get(ModelAuditResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->get(ModelAuditResource::getUrl('view', ['record' => $audit], panel: 'admin'))->assertOk();
});

test('閲覧専用: 作成・編集・削除は提供しない (監査証跡の改竄防止)', function (): void {
    $audit = ModelAudit::factory()->create();

    expect(ModelAuditResource::canCreate())->toBeFalse();
    expect(ModelAuditResource::canEdit($audit))->toBeFalse();
    expect(ModelAuditResource::canDelete($audit))->toBeFalse();
});
