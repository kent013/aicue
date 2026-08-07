<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * **結果の一回性**を担う永続状態遷移の機構 (裁定 AG-082 の「保証側」)。
 *
 * ★これは preflight (外部呼び出し直前の再検証) とは**別概念**である。
 *   preflight は「既に失われた所有権を検出して送信を止める」抑止策であり、
 *   一回性そのものを保証しない。目録では別フィールドで持つ
 *   (`tests/Support/JobDedup/GuaranteeEntry`)。
 * ★case を足すとき: 「同じ行に対する 2 回目の確定を DB が構造的に拒否するか」を基準にする。
 *   「先に検査してから書く」だけのものは保証ではない。
 */
enum JobDedupGuarantee: string
{
    /**
     * 条件付き UPDATE (`where(status=…)->update(…)`) で 0 行更新なら後続を行わない。
     *
     * 適用条件: 遷移元 status を WHERE に含み、戻り値 (更新行数) で分岐している。
     */
    case ConditionalStatusUpdate = 'conditional_status_update';

    /**
     * 行ロック (`lockForUpdate`) + status guard を同一トランザクション内で行う。
     *
     * 適用条件: ロック取得と status 検査と確定の書き込みが**同じ tx** に入っている。
     */
    case PessimisticLockWithStatusGuard = 'pessimistic_lock_with_status_guard';

    /**
     * 予約行の CAS (pending→verifying→completed/released)。
     *
     * 適用条件: 各遷移が条件付き UPDATE で、対になる回収経路 (sweeper) が存在する。
     */
    case ReservationCas = 'reservation_cas';

    /**
     * 一意制約 (partial unique index) が 2 回目の**起票**そのものを拒否する。
     *
     * 適用条件: DB の制約で重複行が**書けない**こと (アプリ側の事前検査は根拠にならない)。
     * ★対象は「行の起票」である。効果 (計上・付与) の重複拒否は下の別 case を使う。
     */
    case DatabaseUniqueConstraint = 'database_unique_constraint';

    /**
     * 冪等キーの UNIQUE が、同じキーによる 2 回目の**効果確定**を拒否する。
     *
     * 適用条件: 効果 (台帳計上・付与など) の挿入が冪等キー付きで行われ、
     * DB の UNIQUE により 2 回目が 0 行挿入になり、呼び出し側がそれを検出できること。
     * ★起票の拒否 (`DatabaseUniqueConstraint`) とは対象が違うため別 case にする
     *   (AGENTS.md 思考原則 4)。準拠実装:
     *   `TicketLedgerService::grantAutoRecharge()` の `recharge:{invoiceId}`。
     */
    case IdempotentLedgerKeyUniqueConstraint = 'idempotent_ledger_key_unique_constraint';
}
