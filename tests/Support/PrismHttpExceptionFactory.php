<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

/**
 * previous に指定 status の RequestException を持つ generic PrismException を作る。
 *
 * Prism の Anthropic provider は 429/413/529 以外を status に依らず generic
 * PrismException へ潰すが、previous に Illuminate の RequestException を残す
 * (Providers/Anthropic/Anthropic.php)。AnalysisPipeline はそこから status を
 * 型安全に読んで transient 判定と文言分岐を行うため、その形の例外を再現する。
 */
final class PrismHttpExceptionFactory
{
    public static function withStatus(int $status): PrismException
    {
        $response = new Response(
            new Psr7Response($status, [], '{"error":{"type":"x","message":"y"}}')
        );

        return PrismException::providerRequestErrorWithDetails(
            provider: 'Anthropic',
            statusCode: $status,
            errorType: 'x',
            errorMessage: 'y',
            previous: new RequestException($response),
        );
    }

    /** previous を持たない generic PrismException (status 判定不能 → fail-fast 側) */
    public static function withoutPrevious(): PrismException
    {
        return PrismException::providerResponseError('Anthropic Error: unknown');
    }
}
