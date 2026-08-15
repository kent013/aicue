<?php

declare(strict_types=1);

namespace Tests\Support\Bughunt;

use Database\Seeders\AdminUserSeeder;
use Database\Seeders\BughuntBillingSeeder;
use Database\Seeders\BughuntOAuthSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ManualTestSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TicketVolumePriceSeeder;

/**
 * database/seeders の全 seeder の区分目録 (deny-by-default・母集団は過不足なく一致)。
 *
 * 母集団を全 seeder に取るのは「登録しなければ検査対象から外れる」抜け道を作らないためで、
 * bug-hunt に関係しない seeder の区分は 1 行で終わる。
 *
 * 固定する事故は 3 つ (概念設計の穴 2):
 * 1. provision にだけ seeder を足して reseed に足し忘れる (子セッションの reseed で状態が消える)
 * 2. bug-hunt 専用 seeder を DatabaseSeeder に足す (全環境の `migrate:fresh --seed` で走る)
 * 3. 新しい bug-hunt 専用 seeder を環境ガード無しで足す (手元の db:seed で dev DB が汚れる)
 */
final class BughuntSeedWiringInventory
{
    /**
     * 区分と理由の目録。
     *
     * `guardPremiseTest` はガードの論理 (かつ / または) を実際に動かして固定している
     * 振る舞いテストのパス。静的走査は「判定語が条件に現れること」までしか見られず、
     * `||` と `&&` の取り違えのような論理の退行は読めないため、ガードを要求する区分には
     * 前提テストを必ず紐づける (免除の前提を振る舞いで固定する
     * ThrottleExemptionPremiseTest / IdempotencyExemptionPremiseTest と同じ作法)。
     * ガードを要求しない区分では null 固定 (値があったら赤)。
     *
     * @return array<class-string, array{
     *     role: BughuntSeedRole,
     *     reason: string,
     *     guardPremiseTest: non-empty-string|null,
     * }>
     */
    public static function entries(): array
    {
        return [
            BughuntBillingSeeder::class => [
                'role' => BughuntSeedRole::BughuntOnly,
                'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
                'guardPremiseTest' => 'tests/Feature/Database/BughuntBillingSeederTest.php',
            ],
            BughuntOAuthSeeder::class => [
                'role' => BughuntSeedRole::BughuntOnly,
                'reason' => 'CLI の認証状態と旧 MCP トークンを直付与する。通常経路に載せると開発 DB へ既知の資格情報が入る。',
                'guardPremiseTest' => 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php',
            ],
            AdminUserSeeder::class => [
                'role' => BughuntSeedRole::SharedWithBughunt,
                'reason' => '固定の管理者を作る。開発環境では通常経路に載るが、bug-hunt では管理画面の探索用に明示投入する。',
                'guardPremiseTest' => 'tests/Feature/Admin/AdminUserSeederTest.php',
            ],
            ManualTestSeeder::class => [
                'role' => BughuntSeedRole::ManualFixture,
                'reason' => '手順書と動画マニュアルの見本を作る開発用 fixture。既知の資格情報を持たないためガードを要求しない。',
                'guardPremiseTest' => null,
            ],
            DatabaseSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '通常経路の束ね役。bug-hunt では migrate:fresh --seed 経由で走るため明示投入しない。',
                'guardPremiseTest' => null,
            ],
            RoleSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '役割の定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            PermissionSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '権限の定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            RolePermissionSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => '役割と権限の紐付け。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            PlanSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => 'プラン定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
            TicketVolumePriceSeeder::class => [
                'role' => BughuntSeedRole::NotSeededInBughunt,
                'reason' => 'チケットの傾斜単価。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
                'guardPremiseTest' => null,
            ],
        ];
    }

    /**
     * bug-hunt レーンで明示投入する区分のクラス (provision / reseed の列と集合一致させる相手)。
     *
     * @return list<class-string>
     */
    public static function seededInBughunt(): array
    {
        $classes = [];
        foreach (self::entries() as $class => $entry) {
            if ($entry['role'] !== BughuntSeedRole::NotSeededInBughunt) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * 区分ごとに `run()` の最初の実効文の条件へ要求する判定語。
     *
     * ★見るのは「語が条件に現れること」までである。条件の論理 (かつ / または) までは見ない
     *   — 現行の 2 本はいずれも「否定の論理和 → 早期 return」の形であり、`&&` を要求する
     *   検査は**誤り**になる。論理そのものは guardPremiseTest が振る舞いで固定する。
     *
     * @return list<string>
     */
    public static function requiredGuardMarkers(BughuntSeedRole $role): array
    {
        return match ($role) {
            BughuntSeedRole::BughuntOnly => [
                'EXTERNALS_FLAG',
                "environment('bughunt.local')",
                'isBughuntDatabase',
            ],
            BughuntSeedRole::SharedWithBughunt => ['shouldSeed'],
            BughuntSeedRole::ManualFixture, BughuntSeedRole::NotSeededInBughunt => [],
        };
    }
}
