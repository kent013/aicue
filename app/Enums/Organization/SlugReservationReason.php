<?php

declare(strict_types=1);

namespace App\Enums\Organization;

/**
 * 予約語の理由 3 分類 (家系裁定 AG-039)。
 *
 * ★分類の記載は**必須**である。分類の無い語・未知の分類は
 *   `OrganizationSlugReservedWords::load()` が読み込み時に落とす (fail-closed)。
 */
enum SlugReservationReason: string
{
    /** ルート衝突: 識別名と同じ位置 (/organizations/ 直下) の静的セグメントと同名になる。 */
    case RouteConflict = 'route_conflict';

    /** 権威の詐称: 運営・管理・支援を騙れる語。 */
    case AuthorityImpersonation = 'authority_impersonation';

    /** 構文衝突: URL・DNS・予約識別子として解釈がぶれる語。 */
    case SyntaxConflict = 'syntax_conflict';

    public function label(): string
    {
        return match ($this) {
            self::RouteConflict => 'ルート衝突',
            self::AuthorityImpersonation => '権威の詐称',
            self::SyntaxConflict => '構文衝突',
        };
    }
}
