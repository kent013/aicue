<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * 「2 つの応答が観測上まったく同じか」を比較するための正規化ヘルパ。
 *
 * 存在オラクル (実在 id / 不在 id で応答が分岐すること) の不成立を検証するには
 * status / body だけでなく**ヘッダも**一致していなければならない
 * (302 同士でも Location が違えば 1 bit 漏れる)。
 * ただし連続リクエストで必ず差分が出る volatile ヘッダ (Date / Set-Cookie /
 * X-RateLimit-* / Retry-After / request id 系) を含めた生の完全一致比較は
 * 恒常的に flaky になるため、それらを除外した signature で比較する。
 *
 * **除外は「観測者にとって意味を持たない差分」に限定する**。
 * Location / Content-Type / Content-Length など、遷移先や中身を示すヘッダは
 * 必ず比較対象に残す (ここを緩めると検証が空洞化する)。
 */
final class ResponseSignature
{
    /**
     * 連続リクエストで必ず差分が出る (= 存在の証拠にならない) ヘッダ名 (小文字)。
     *
     * @var list<string>
     */
    private const VOLATILE_EXACT = [
        'date',
        'set-cookie',
        'retry-after',
        // Expires / Age は「現在時刻から導出される値」なので連続リクエストで必ずズレる。
        // ETag / Last-Modified は **除外しない** — リソース内容や更新時刻に由来する
        // 安定した差分になりうるため、存在オラクル検査の対象に残す (impl-review R1 Warning)。
        'expires',
        'age',
    ];

    /**
     * 上記に加え、prefix 一致で除外するヘッダ名 (小文字)。
     *
     * @var list<string>
     */
    private const VOLATILE_PREFIX = [
        'x-ratelimit-',
        'x-request-id',
        'x-correlation-id',
        'request-id',
    ];

    /**
     * 応答の観測可能な signature (status + 正規化ヘッダ + body)。
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public static function of(TestResponse $response): array
    {
        /** @var array<string, list<string>> $headers */
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $lower = strtolower((string) $name);
            if (self::isVolatile($lower)) {
                continue;
            }
            $normalized = [];
            foreach ($values as $value) {
                $normalized[] = (string) $value;
            }
            sort($normalized);
            $headers[$lower] = $normalized;
        }
        ksort($headers);

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => $response->getContent() === false ? '' : $response->getContent(),
        ];
    }

    private static function isVolatile(string $lowerName): bool
    {
        if (in_array($lowerName, self::VOLATILE_EXACT, true)) {
            return true;
        }

        foreach (self::VOLATILE_PREFIX as $prefix) {
            if (str_starts_with($lowerName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
