<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use Stripe\HttpClient\ClientInterface;
use Webmozart\Assert\Assert; // ★import 必須 (無いと Tests\Support\Billing\Assert に解決される)

/**
 * Stripe SDK の HTTP 呼び出し回数を数える fake client (**送信しない**)。
 *
 * ★`ApiRequestor::setHttpClient()` は Stripe SDK 公式の差し込み口である。
 *   Cashier 内部 (`createOrGetStripeCustomer` 等) の呼び出しもここを通るため、
 *   静的な呼び出し site 計数では数えられない分まで含めて数えられる。
 * ★外部 HTTP は一切発生しない (AGENTS.md の egress 規約に抵触しない)。
 * ★`Stripe\HttpClient\ClientInterface` は **generic ではない**ため `@implements` は書かない
 *   (PHPStan で不正な PHPDoc になる)。型の情報は `request()` の `@param` / `@return` で与える。
 */
final class CountingStripeHttpClient implements ClientInterface
{
    public int $calls = 0;

    /** @var list<array{status: int, body: string}> 先頭から消費する応答列 */
    private array $responses;

    /** @var list<string> 診断用の呼び出し URL 履歴 (経路のずれを読めるようにする) */
    public array $requestedUrls = [];

    /** @param list<array{status: int, body: string}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /** 応答列を使い切ったか (使い切っていなければ経路が想定より短い = 偽グリーン) */
    public function isExhausted(): bool
    {
        return $this->responses === [];
    }

    /**
     * vendor の `Stripe\HttpClient\ClientInterface::request()` に型宣言が無いため、
     * **全引数に `@param` を付けて** PHPStan level 10 で mixed が伝播しないようにする。
     *
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $absUrl
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>|string  $params
     * @param  bool  $hasFile
     * @param  'v1'|'v2'  $apiMode
     * @param  int|null  $maxNetworkRetries
     * @return array{0: string, 1: int, 2: array<string, list<string>>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls++;
        $this->requestedUrls[] = $method.' '.$absUrl;
        $response = array_shift($this->responses);
        // 応答列が尽きたら fail-loud (黙って空 body を返さない)
        Assert::isArray(
            $response,
            'CountingStripeHttpClient: 想定より多い Stripe 呼び出しが発生しました ('
            .implode(' / ', $this->requestedUrls).')',
        );

        return [$response['body'], $response['status'], []];
    }
}
