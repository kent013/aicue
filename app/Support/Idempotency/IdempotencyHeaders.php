<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

/**
 * 冪等応答のヘッダ名の唯一の正本。
 *
 * `Idempotent-Replayed` は **外部標準 (IETF の Idempotency-Key draft) には無い拡張**である。
 * **再生応答にのみ**付与する (初回応答・409・素通しには付けない = クライアントが
 * 「これは再生か」を判定できる)。名前と付与条件の契約は docs/api-idempotency.md、
 * 機械固定は tests/Architecture/IdempotencyContractParityTest。
 */
final class IdempotencyHeaders
{
    /** 保存済み応答を再生したときにだけ付与する (値は 'true' 固定) */
    public const REPLAYED = 'Idempotent-Replayed';

    /** REPLAYED の値 (真偽の表現をここに固定し、呼び出し側で文字列を組まない) */
    public const REPLAYED_VALUE = 'true';
}
