<?php

declare(strict_types=1);

namespace App\Enums\Idempotency;

/**
 * 冪等キー行の状態 (REST `idempotency_keys`)。
 *
 * **決着は completed と indeterminate の 2 つだけで、release (再実行を許す) 経路は無い**。
 * processing から戻る道は無く、唯一の解放は保持期間超過による物理削除
 * (`idempotency:prune` コマンド、および claim 時の期限切れ行削除) である。
 *
 * - Processing:    claim 済み・本処理実行中。同一キーの後着は 409 idempotency_in_progress
 * - Completed:     2xx JSON を得た。保存応答を再生する (Idempotent-Replayed: true)
 * - Indeterminate: それ以外で終わった (非 2xx / 非 JSON / 例外が抜けた)。
 *                  副作用の有無を middleware から断定できないため再実行せず
 *                  409 idempotency_indeterminate を返す (クライアントは新しいキーを使う)
 *
 * 契約の正本は `docs/api-idempotency.md`。case 一覧と文書の parity は
 * `tests/Architecture/IdempotencyContractParityTest.php` が機械固定する。
 */
enum IdempotencyState: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Indeterminate = 'indeterminate';
}
