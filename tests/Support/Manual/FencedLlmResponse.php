<?php

declare(strict_types=1);

namespace Tests\Support\Manual;

/**
 * LLM 応答の fake / fixture を**受理契約どおりの囲みつき**に包むヘルパ。
 *
 * ★`LlmJson::decode()` の受理契約は「囲みちょうど 1 つ」なので、素の JSON を渡す fake は
 *   `fence_absent` で落ちる。fixture 側の包み方を 1 か所に集めて、
 *   契約が変わったときに直す場所を 1 つにする。
 */
final class FencedLlmResponse
{
    /** 与えた JSON 文字列を ```json … ``` で包む。 */
    public static function wrap(string $json): string
    {
        return "```json\n".$json."\n```";
    }

    /**
     * 配列を JSON へ直してから包む (fixture の定型)。
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function wrapArray(array $payload): string
    {
        return self::wrap(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
