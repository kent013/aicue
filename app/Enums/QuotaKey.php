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
    /** ProjectService::create が QuotaService::check で強制する (超過するとプロジェクトを作れない) */
    case MaxProjects = 'max_projects';

    /**
     * **現在どこからも強制されていない** (QuotaService::check / checkAddition の呼び出し元が無い)。
     * config/quota.php の値は表示上の目安であり、増員はブロックされない。
     * (personal プランの人数上限は PersonalPlanService::MAX_MEMBERS という別のハードキャップで、
     *  本 quota とは別機構である。)
     * したがって UI で「超えると止まる」と読める表示をしないこと。強制するなら
     * 招待・メンバー追加経路への配線と Feature テストまでが同一作業になる。
     */
    case MaxMembers = 'max_members';

    /** TakeUploadService が QuotaService::checkAddition で強制する (超過するとアップロードできない) */
    case MaxStorageBytes = 'max_storage_bytes';

    /** 上限超過エラー等でユーザーに見せる表示名 */
    public function label(): string
    {
        return match ($this) {
            self::MaxProjects => 'プロジェクト数',
            self::MaxMembers => 'メンバー数',
            self::MaxStorageBytes => '保存容量',
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
