<?php

declare(strict_types=1);

// 本番 mail 設定の fail-fast 検証 (operations:check-mail-config)。

test('production で MAIL_MAILER=log なら fail する', function (): void {
    config(['app.env' => 'production']);
    config(['mail.default' => 'log']);
    config(['mail.from.address' => 'noreply@app.test']);
    config(['app.url' => 'https://app.test']);

    $this->artisan('operations:check-mail-config')
        ->expectsOutputToContain('emails will NOT be delivered')
        ->assertFailed();
});

test('production で FROM domain が APP_URL host と乖離していれば fail する', function (): void {
    config(['app.env' => 'production']);
    config(['mail.default' => 'ses']);
    config(['mail.from.address' => 'noreply@other.example']);
    config(['app.url' => 'https://app.test']);

    $this->artisan('operations:check-mail-config')
        ->expectsOutputToContain('does not match APP_URL host')
        ->assertFailed();
});

test('mailer が log でなく domain が一致すれば成功する', function (): void {
    config(['app.env' => 'production']);
    config(['mail.default' => 'ses']);
    config(['mail.from.address' => 'noreply@app.test']);
    config(['app.url' => 'https://app.test']);

    $this->artisan('operations:check-mail-config')
        ->expectsOutputToContain('mail config OK')
        ->assertSuccessful();
});

test('www. prefix の差だけは同一 domain とみなす', function (): void {
    config(['app.env' => 'production']);
    config(['mail.default' => 'ses']);
    config(['mail.from.address' => 'noreply@app.test']);
    config(['app.url' => 'https://www.app.test']);

    $this->artisan('operations:check-mail-config')->assertSuccessful();
});
