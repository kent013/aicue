<?php

declare(strict_types=1);

namespace App\Exceptions\EnterpriseSso;

use App\DataTransferObjects\EnterpriseSso\AttemptConsumeResult;
use RuntimeException;

/**
 * ログイン試行の保管そのものが壊れたことを表す例外 (**業務上の拒否ではない**)。
 *
 * ★「行を消したのに 1 行も影響しなかった」のような **DB の障害**だけがここに来る。
 *   業務上の拒否 (行が無い / 期限切れ / 結合の不一致) は
 *   {@see AttemptConsumeResult} の分類として**返る**。
 *   混ぜると「排他が壊れた」という重大な事実が一様な拒否に隠れる。
 */
final class EnterpriseSsoAttemptStoreFailure extends RuntimeException {}
