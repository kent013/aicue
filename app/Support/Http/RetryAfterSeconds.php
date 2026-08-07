<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * `Retry-After` ヘッダ値 → 待ち時間 (秒) の唯一の解釈点。
 *
 * 裁定 (error-response-contract): **非負整数のみ採り、解釈不能なら非表示**。
 * HTTP 仕様上 Retry-After は delta-seconds と HTTP-date の 2 形式を採りうるが、
 * 本アプリの発行元は Laravel の ThrottleRequests (常に delta-seconds) だけであり、
 * HTTP-date を「秒数」として画面や API 封筒に載せる意味が無い。
 * (前提が変わり HTTP-date を発行する経路が入ったら、ここを見直すこと)
 *
 * 利用点は 4 つで、すべて本クラスを通る (二重解釈を作らない):
 *   1. API 封筒 JSON の details.retry_after      (ApiExceptionRenderer::rateLimitDetails)
 *   2. API 応答の Retry-After ヘッダ             (ApiExceptionRenderer::extraHeaders)
 *   3. Error 画面の retryAfterSeconds prop       (InertiaExceptionRenderer::render)
 *   4. 差し替え応答の Retry-After ヘッダ          (InertiaExceptionRenderer::render)
 */
final class RetryAfterSeconds
{
    /**
     * @return int<0, max>|null 解釈できない値は null
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            // 負数 ("-5") / HTTP-date / 任意文字列 / 空文字 / それ以外の型はここで落ちる
            return null;
        }

        $seconds = (int) $value;

        // ctype_digit が真なら非負だが、PHPStan に int<0, max> を認識させるため明示する
        return $seconds >= 0 ? $seconds : null;
    }
}
