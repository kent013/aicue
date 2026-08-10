<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 救済 route に対する middleware の扱いの分類 (3 値)。
 *
 * `RescueRouteGateDisposition` の各 case がこのどれかを宣言する。
 * ★**機械照合できるのは `PassesRescueRoute` だけ**である (実装の allowlist を実際に引く)。
 *   残る 2 値は**人手申告**であり、middleware 本体に新しい短絡分岐が足されても沈黙する。
 *   この非対称を「経路上の全 middleware を通過できることの保証」と誇張しない。
 */
enum RescueRouteGateKind: string
{
    /** 短絡しうるゲートだが、救済 route を allowlist で明示的に通す (機械照合の対象)。 */
    case PassesRescueRoute = 'passes_rescue_route';

    /** 救済 route を短絡させうるが、短絡先で本人が条件を解消でき、その解消面は救済経路の外にある。 */
    case ShortCircuitsButEscapable = 'short_circuits_but_escapable';

    /** この route を短絡させない (応答加工のみ / 他 route 名限定の判定)。 */
    case NeverShortCircuitsRescueRoute = 'never_short_circuits_rescue_route';
}
