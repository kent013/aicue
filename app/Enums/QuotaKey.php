<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Quota (多次元上限) のキー。config/quota.php の limits キーの機械可読 SSOT。
 *
 * 規約: config/quota.php に limits キーを追加するときは必ずここに case を追加する
 * (tests/Architecture/QuotaKeyConfigInvariantTest が集合整合を CI で固定する)。
 * QuotaService::check は本 enum 経由でのみキーを受け取る (文字列直書き禁止)。
 */
enum QuotaKey: string
{
    case MaxProjects = 'max_projects';
    case MaxMembers = 'max_members';

    /** 上限超過エラー等でユーザーに見せる表示名 */
    public function label(): string
    {
        return match ($this) {
            self::MaxProjects => 'プロジェクト数',
            self::MaxMembers => 'メンバー数',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
