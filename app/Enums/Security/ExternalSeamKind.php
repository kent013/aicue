<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 外部到達点の種別 (標準形 v1 の 6 種 + aicue 固有の 1 種)。
 *
 * ★閉じた語彙にする。新しい `Http::` 直呼びが増えたとき、既存 case のどれにも当てはまらなければ
 *   **case を足す判断**を通す (新しい外向きの種類が黙って増えないようにするための摩擦)。
 * ★`ObjectStorage` / `Llm` は**委譲専用**。本目録の母集団には現れない
 *   (`ExternalSeamInventoryTest` が機械で固定する)。
 */
enum ExternalSeamKind: string
{
    case Payment = 'payment';
    case SocialLogin = 'social_login';
    case Captcha = 'captcha';
    case Mail = 'mail';

    /**
     * 市場データ取得 (標準形 v1 の 6 種に無い aicue 固有の外向き経路)。
     *
     * `App\Services\FxRateService` が為替 API を叩く。captcha と同じ `Http` facade 規則で
     * 検出されるため、除外する方が不自然な規則になる。
     */
    case MarketData = 'market_data';

    /** 委譲専用: `ExternalClientTimeoutInventoryTest` の到達境界目録が正本。 */
    case ObjectStorage = 'object_storage';

    /** 委譲専用: `PromptGuardrailTest` の Prism 直呼び禁止が正本。 */
    case Llm = 'llm';
}
