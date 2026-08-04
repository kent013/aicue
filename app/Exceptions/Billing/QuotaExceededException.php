<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\QuotaKey;
use RuntimeException;

/**
 * Quota 上限超過 (QuotaService::check)。
 * web 経路では bootstrap/app.php の exceptions.render で back + error flash に変換される。
 * メッセージは機械可読キーではなく QuotaKey::label() の日本語表示に揃える。
 */
class QuotaExceededException extends RuntimeException
{
    /**
     * 文言には**回復先の画面名**を含める。失敗するのは撮影・プロジェクト作成の現場であり、
     * そこから「どこを見れば現状と上限が分かるか」が分からないと詰みになるため
     * (/billing は課金ゲートの構造的 allowlist 内で未契約組織からも到達できる)。
     * flash は素の文字列なので、リンク化のための構造化 flash 機構は作らない。
     */
    public static function forLimit(QuotaKey $key, int $limit): self
    {
        return new self(
            "現在のプランの上限 ({$key->label()}: {$limit}) に達しています。"
            .'現在のご利用状況と上限は「お支払い」画面で確認できます。プランのアップグレードをご検討ください。'
        );
    }
}
