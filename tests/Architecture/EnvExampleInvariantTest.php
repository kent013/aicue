<?php

declare(strict_types=1);

/*
 * production deploy 時に SESSION_SECURE_COOKIE / SESSION_ENCRYPT を立て忘れないよう
 * .env.example に必ず提示する invariant (aigenba T425 SEC03 由来)。
 */

test('.env.example に SESSION_SECURE_COOKIE=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_SECURE_COOKIE=true');
});

test('.env.example に SESSION_ENCRYPT=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_ENCRYPT=true');
});

/*
 * テンプレート規約: 環境座標 (config/template.php) のキーは .env.example に必ず提示する。
 */

test('.env.example に TEMPLATE_APP_SLUG が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('TEMPLATE_APP_SLUG=');
});
