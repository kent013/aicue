<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use InvalidArgumentException;

/**
 * 母集合のパス 1 件に対する観測 (readonly DTO)。
 *
 * ★**検査不能は `ComparisonState` の 3 値では表せない**。`MissingCurrent` へ畳むと
 *   「検査不能を消滅へ畳まない」という不変条件そのものを破ってしまうので、
 *   状態を **nullable** にして「状態が付かない観測 = 検査不能」を型で表す。
 *
 * ★許す組み合わせは**次の 4 形だけ**で、それ以外はコンストラクタで例外にする:
 *    - `Matched`         + 64 桁 hex + null
 *    - `ContentMismatch` + 64 桁 hex + null
 *    - `MissingCurrent`  + null      + null   (git index / working tree から消えた)
 *    - null              + null      + 空でない理由 (symlink / 非 regular / 読めない / hash 失敗)
 *
 * 落とす 7 形と、許す 4 形が構築できることは
 * `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` が両方向で固定する。
 */
final readonly class PathObservation
{
    public function __construct(
        public ?ComparisonState $state,
        public ?string $currentHash,
        public ?string $inspectionFailure,
    ) {
        if ($inspectionFailure !== null && $state !== null) {
            throw new InvalidArgumentException('検査不能の観測に比較状態を付けられない (畳むと検査不能が消える)');
        }

        if ($inspectionFailure !== null) {
            if ($inspectionFailure === '') {
                throw new InvalidArgumentException('検査不能の理由が空文字である (理由の無い検査不能を作らない)');
            }
            if ($currentHash !== null) {
                throw new InvalidArgumentException('検査不能の観測にハッシュを付けられない');
            }

            return;
        }

        if ($state === null) {
            throw new InvalidArgumentException('比較状態も検査不能の理由も無い観測は作れない');
        }

        if ($state === ComparisonState::MissingCurrent) {
            if ($currentHash !== null) {
                throw new InvalidArgumentException('消滅した観測にハッシュを付けられない');
            }

            return;
        }

        if ($currentHash === null) {
            throw new InvalidArgumentException('内容を比較した観測にはハッシュが要る');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $currentHash) !== 1) {
            throw new InvalidArgumentException('観測のハッシュが 64 桁小文字 hex でない');
        }
    }
}
