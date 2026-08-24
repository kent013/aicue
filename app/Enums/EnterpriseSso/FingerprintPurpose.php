<?php

declare(strict_types=1);

namespace App\Enums\EnterpriseSso;

use App\Support\EnterpriseSso\AttemptFingerprint;

/**
 * 指紋の用途。**相互に使い回せない** (domain separation)。
 *
 * ★**永続する値の用途をここへ足さない**。
 *   足すと {@see AttemptFingerprint} の
 *   「APP_KEY 由来の鍵でよい」という根拠が崩れる (ローテートで永続的なものが失われる)。
 *   この禁止は tests/Unit/Support/AttemptFingerprintTest.php が case を名指しで pin する。
 */
enum FingerprintPurpose: string
{
    /** 寿命 10 分。 */
    case State = 'enterprise-sso.state';

    /** 寿命 10 分。 */
    case Nonce = 'enterprise-sso.nonce';

    /** 寿命 10 分。 */
    case BrowserBinding = 'enterprise-sso.browser-binding';

    /** 寿命 60 分。 */
    case EmailPromotionToken = 'auth.email-promotion';
}
