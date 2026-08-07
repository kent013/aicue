<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「重複実行の保証を持たないことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/JobExecutionDedupInventoryTest.php` が deny-by-default で
 * 「保証側の登録」か「本 enum + 具体的根拠」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「保証側を作るべきジョブ」である。
 */
enum JobDedupExemption: string
{
    /**
     * 重複配信が受容されている送信系 (Mailable / Notification)。
     *
     * 適用条件 (**すべて**満たすこと):
     *  - ドメイン状態を一切書かない (送信のみ)
     *  - **重複受信時に受信者が誤った操作へ誘導されない**
     *    (「気にならない」ではなく「二重の支払い操作等を招かない」まで確認する)
     *  - `$tries` / retry 契約の上で at-least-once を受容済みである
     *
     * ★課金関連・失敗通知・セキュリティ通知を「配信系だから」で一括免除しない。
     *   クラスごとに「何が重複配信されうるか・受信者に何が起きるか・なぜ受容できるか」を書く。
     */
    case DuplicateDeliveryAccepted = 'duplicate_delivery_accepted';

    /**
     * 削除が本質的に冪等で、2 回目の実行が no-op になるジョブ。
     *
     * 適用条件: 対象の不在を正常系として扱い、状態も課金も動かさない。
     */
    case IdempotentDeletion = 'idempotent_deletion';

    /**
     * 外部の最新状態を取り込むだけの同期ジョブ (last-writer-wins が正しい)。
     *
     * 適用条件: 書き込みが冪等な upsert で、順序が入れ替わっても収束先が同じ。
     */
    case ConvergentStateSync = 'convergent_state_sync';

    /**
     * 起票の重複を DB 制約が拒否するため、ジョブ側に保証を置く必要がないもの。
     *
     * 適用条件: 起票先に partial unique index があり、重複起票が例外になる。
     * ★これは「保証がある」ではなく「保証の所在がジョブの外」であることの記録である。
     */
    case GuardedByDownstreamConstraint = 'guarded_by_downstream_constraint';
}
