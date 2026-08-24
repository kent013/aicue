<?php

declare(strict_types=1);

namespace Tests\Support\Manual;

use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Support\Manual\LlmJson;
use RuntimeException;

/**
 * 復号点の拒否ケースを組み立てるテスト共有ヘルパ。
 *
 * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 *   `Tests\Support\QueueLeaseConfig` 等と同じくクラスの static メソッドへ集約する
 *   (AGENTS.md §走査器・gate の置き方に倣う)。
 */
final class LlmJsonRejection
{
    /** 応答本文が例外へ漏れていないことを見るための目印 (正典 i9)。 */
    public const string SENTINEL = 'SENTINEL-SOP-BODY-9f2c';

    /**
     * `LlmJson::decode()` が拒否したときの例外を返す。受理してしまったら失敗させる。
     */
    public static function capture(string $text): LlmOutputInvalidException
    {
        try {
            LlmJson::decode($text);
        } catch (LlmOutputInvalidException $exception) {
            return $exception;
        }

        throw new RuntimeException('LlmOutputInvalidException が投げられていない (受理された)');
    }
}
