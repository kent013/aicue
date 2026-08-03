<?php

declare(strict_types=1);

use App\Models\Billing\BillingCheckoutSession;
use App\Models\Organization;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/*
 * P9 (セキュリティ不変条件 #6 / #1): 請求先 PII の保管と mass-assignment の構造的封じ。
 *
 * 「暗号化を後から外す」「$fillable に足す」変更をテストの更新なしには通さない。
 */

test('Organization は CipherSweetEncrypted を実装し両列を登録している', function (): void {
    expect(is_subclass_of(Organization::class, CipherSweetEncrypted::class))->toBeTrue();

    $row = new EncryptedRow(app(CipherSweet::class), 'organizations');
    Organization::configureCipherSweet($row);

    $fields = $row->listEncryptedFields();
    expect($fields)->toContain('billing_contact_email');
    expect($fields)->toContain('billing_contact_name');
});

test('organizations.billing_contact_* の列型は text (ciphertext を格納するため)', function (string $column): void {
    // Architecture lane は DB を持たないため migration 定義を直接読む
    // (string(255) では ciphertext が溢れる = 型の後退を機械検出する)。
    $migration = file_get_contents(
        base_path('database/migrations/2026_07_17_000400_add_billing_contact_columns_to_organizations_table.php'),
    );
    expect($migration)->toBeString();
    expect($migration)->toContain("\$table->text('{$column}')->nullable()");
})->with(['billing_contact_email', 'billing_contact_name']);

test('billing_contact_* は $fillable に無い (明示代入のみ)', function (string $column): void {
    expect((new Organization)->getFillable())->not->toContain($column);
})->with(['billing_contact_email', 'billing_contact_name']);

test('billing_checkout_sessions.pm_reuse_dispatched_at は $fillable に無い (webhook の forceFill 専用 marker)', function (): void {
    expect((new BillingCheckoutSession)->getFillable())->not->toContain('pm_reuse_dispatched_at');
    // funding_choice は checkout 開始時の入力なので fillable
    expect((new BillingCheckoutSession)->getFillable())->toContain('funding_choice');
});
