<?php

declare(strict_types=1);

namespace App\Enums\Organization;

/**
 * 組織文脈を持たない入口が、組織を確定したあとに向かう先 (家系裁定 AG-037)。
 *
 * ★遷移先は入口ごとの**固定表**から選ぶ。query string で受け取らない (open redirect を作らない)。
 * ★値は Inertia props にも載る (選ぶ画面が「どこへ向かう選択なのか」を描くため)。
 */
enum EntryTarget: string
{
    /** 撮影 PWA の入口 (`/app` = manifest の start_url)。 */
    case Capture = 'capture';

    /** 汎用の入口 (`/go`)。 */
    case Dashboard = 'dashboard';

    /** 遷移先の route 名 (route 名は移設後も不変)。 */
    public function routeName(): string
    {
        return match ($this) {
            self::Capture => 'capture.home',
            self::Dashboard => 'dashboard',
        };
    }
}
