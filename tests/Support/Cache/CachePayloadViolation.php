<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use RuntimeException;

/**
 * キャッシュへ素のデータでない値を書き込もうとした / 受け皿の境界を迂回したときに投げる。
 *
 * 書き込み呼び出しの**中で** throw されるため、失敗は書き込み元のテストへ帰属する
 * (「読み出しで壊れる」形の弱い検出にしない)。呼び出し元が握り潰しても
 * PlainDataCacheGuard の accumulator に残り、afterEach で必ず赤くなる。
 */
final class CachePayloadViolation extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public static function forWrite(string $method, string $key, array $violations): self
    {
        return new self(
            "Cache::{$method}('{$key}') に素のデータでない値が渡されました:".PHP_EOL
            .'  '.implode(PHP_EOL.'  ', $violations).PHP_EOL
            .'キャッシュに入れてよいのは配列 / 文字列 / 数値 / 真偽値 / null だけです。'
            .'読み出し側がアプリのコードで組み立て直せる形 (例: DTO なら toArray()) にしてください。'
            .'規約: AGENTS.md セキュリティ不変条件 11 / '
            .'静的層: tests/Architecture/CachePayloadPlainDataGateTest.php / '
            .'実行時層: tests/Support/Cache/PlainDataGuardedRepository.php'
            .' (LIMIT_EXCEEDED / UNKNOWN_TYPE は「guard が素のデータであることを証明できなかった」'
            .'ことを表す。値を小さくするか、キャッシュに入れる形を見直すこと)',
        );
    }

    public static function forBoundary(string $operation, string $detail): self
    {
        return new self(
            "キャッシュ受け皿の境界を迂回しました: {$operation} ({$detail})。".PHP_EOL
            .'受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く経路は、'
            .'実行時層が値を見られないため使えません。'
            .'規約: AGENTS.md セキュリティ不変条件 11 / 家系の裁定 AG-151 の境界迂回の hard fail',
        );
    }
}
