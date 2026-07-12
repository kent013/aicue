<?php

declare(strict_types=1);

use Database\Seeders\BughuntOAuthSeeder;
use Illuminate\Support\Facades\DB;

/*
 * BughuntOAuthSeeder の fail-secure guard 回帰: config/testing.php の新設
 * (fake_externals flag の点火) により第 1 ガードが bughunt 環境で成立し始めたため、
 * 「bughunt 外では従来どおり no-op」の境界をテストで固定する。
 */

test('既定の testing env では no-op (oauth_clients / oauth_sessions が増えない)', function (): void {
    $clientsBefore = DB::table('oauth_clients')->count();
    $sessionsBefore = DB::table('oauth_sessions')->count();

    $this->seed(BughuntOAuthSeeder::class);

    expect(DB::table('oauth_clients')->count())->toBe($clientsBefore);
    expect(DB::table('oauth_sessions')->count())->toBe($sessionsBefore);
});

test('fake_externals=true でも env=testing なら no-op (flag 単独では点火しない)', function (): void {
    config(['testing.fake_externals' => true]);

    $clientsBefore = DB::table('oauth_clients')->count();
    $sessionsBefore = DB::table('oauth_sessions')->count();

    $this->seed(BughuntOAuthSeeder::class);

    expect(DB::table('oauth_clients')->count())->toBe($clientsBefore);
    expect(DB::table('oauth_sessions')->count())->toBe($sessionsBefore);
});
