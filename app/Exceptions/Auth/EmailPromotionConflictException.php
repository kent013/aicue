<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * 昇格しようとしたメールが**既に他の利用者のもの**だったことを表す例外。
 *
 * ★応答は**一様**である (存在を漏らさない)。既存の利用者は**一切変更せず・併合せず**、
 *   昇格も行わない。
 * ★変換してよいのは**メールの blind index の一意制約違反だけ**である。
 *   それ以外の一意制約違反と DB の障害は**握り潰さない** (伝播させる)。
 */
final class EmailPromotionConflictException extends RuntimeException {}
