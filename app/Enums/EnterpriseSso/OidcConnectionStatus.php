<?php

declare(strict_types=1);

namespace App\Enums\EnterpriseSso;

use App\Services\EnterpriseSso\OidcConnectionTransitionService;

/**
 * 組織 OIDC 接続の状態 (4 値で固定する。増やさない)。
 *
 * 遷移の唯一の書き手は {@see OidcConnectionTransitionService} である。
 */
enum OidcConnectionStatus: string
{
    /** 登録直後 / 認証材料を更新した直後。ログインに使えない。 */
    case Draft = 'draft';

    /** 接続先情報の取得に成功した。まだログインには使えない。 */
    case Verified = 'verified';

    /** ログインに使える。 */
    case Active = 'active';

    /** 運営が止めた。 */
    case Disabled = 'disabled';
}
