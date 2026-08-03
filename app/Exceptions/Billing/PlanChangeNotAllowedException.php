<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * **業務上の理由**でプラン変更を受け付けられないとき (契約が無い / 変更できない state /
 * schedule 管理下)。メッセージは**そのまま利用者に見せる文言**として書く。
 *
 * 前提違反・実装不備 (`Assert` の `InvalidArgumentException` / `TypeError`) とは**別型**にする:
 * Controller は本例外だけを error flash に変換し、`InvalidArgumentException` は catch せず
 * 500 に落とす (Assert の内部文言を利用者へ露出させない / 実装不備を握り潰さない)。
 */
final class PlanChangeNotAllowedException extends RuntimeException {}
