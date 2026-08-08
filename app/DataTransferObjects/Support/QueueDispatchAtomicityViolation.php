<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Support;

use App\Enums\Support\QueueAtomicityRule;

/**
 * QueueDispatchAtomicityGuard が報告する 1 件の違反 (内部 DTO)。
 *
 * ★ **mixed を公開しない**。実測値は表示用に正規化した string (`var_export()` 相当) で持つ。
 *   config から読んだ値をそのまま公開すると、level 10 の呼び出し側で narrowing が必要になり
 *   「違反を報告するための DTO」が新たな型不明値の出口になる。
 */
final readonly class QueueDispatchAtomicityViolation
{
    /**
     * @param  QueueAtomicityRule  $rule  違反した規則
     * @param  string  $connection  検査対象のキュー接続名 (接続に紐づかない違反は '-')
     * @param  string  $actual  実測値を表示用に正規化した文字列
     * @param  string  $message  起動時例外に載せる説明 (原因と対処を含む)
     */
    public function __construct(
        public QueueAtomicityRule $rule,
        public string $connection,
        public string $actual,
        public string $message,
    ) {}
}
