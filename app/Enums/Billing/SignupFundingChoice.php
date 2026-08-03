<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * P8a (D29): 新規登録フローの資金選択。
 *
 *  - AutoRecharge: オートリチャージを設定する (既定・おすすめ)。activate 完了後に
 *    カード登録 (mode=setup Checkout) へ誘導する。登録だけでは課金されない。
 *  - Later       : あとで決める (初回 signup grant で試用)。
 *  - Tickets     : チケットを買う (購入ページ直行)。
 *    UI からは出さない (移植元 T1002 で撤去済み) — 永続値の読み出し互換のため case は残し、
 *    validation 上も引き続き受理する (**3 case verbatim**。case 縮小はしない)。
 */
enum SignupFundingChoice: string
{
    case AutoRecharge = 'auto_recharge';
    case Tickets = 'tickets';
    case Later = 'later';
}
