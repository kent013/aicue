<?php

declare(strict_types=1);

namespace Tests\Support\Bughunt;

/**
 * bug-hunt レーンにおける投入データ (seeder) の区分。
 *
 * 「bug-hunt で明示投入するか」と「環境ガードを要求するか」の 2 軸で分ける。
 * 値を持つ必要が無いので backed enum にしない。
 */
enum BughuntSeedRole
{
    /** bug-hunt 環境専用。三重ガード必須・通常の投入経路 (DatabaseSeeder) には載せない */
    case BughuntOnly;

    /** 通常経路にも載るが bug-hunt でも明示投入する。環境ガード必須 */
    case SharedWithBughunt;

    /** 開発者が手で流す fixture。bug-hunt でも明示投入するがガードは要求しない */
    case ManualFixture;

    /** bug-hunt レーンでは明示投入しない (`migrate:fresh --seed` 経由か、そもそも流さない) */
    case NotSeededInBughunt;
}
