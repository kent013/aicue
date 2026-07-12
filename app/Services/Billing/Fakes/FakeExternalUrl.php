<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use Webmozart\Assert\Assert;

/**
 * fake externals の中立帰還 URL (アプリ内画面 + 観測用 marker query)。
 * marker はアプリが解釈しない (TicketCheckoutTest が purchased=false を固定)。
 * bug-hunt のブラウザログから「外部ステップを skip した」ことを観測するためだけの query。
 */
final class FakeExternalUrl
{
    public const string MARKER = 'fake_external=stripe';

    public static function neutralReturn(string $appUrl): string
    {
        Assert::stringNotEmpty($appUrl, '中立帰還先のアプリ内 URL が空です');

        return $appUrl.(str_contains($appUrl, '?') ? '&' : '?').self::MARKER;
    }
}
