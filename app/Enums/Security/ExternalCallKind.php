<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 所有権再検証 (preflight suppression) が守る外部呼び出しの種別。
 *
 * Manual ドメイン (例外経由) と Billing ドメイン (structured return) の**双方**が
 * 同じ語彙を共有するためにここへ置く (`tests/Architecture/JobExecutionDedupInventoryTest.php`
 * の目録もこの enum を使う。テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★case を足すとき: 「取り消せない外部副作用を持つか」を基準にする。
 *   ローカル CPU (ffmpeg) や冪等な読み取り (S3 GET) は本 enum の対象ではない。
 *
 * ★ログ event 定数を enum に同居させているのはやや変則だが、代替 (専用の Support クラス新設) は
 *   「ログ event 定数だけのために専用クラスを作る」ことになり AGENTS.md 思考原則 2
 *   (今必要なものだけ作る) に反するため採らない。
 */
enum ExternalCallKind: string
{
    /**
     * 所有権喪失で**外部送信を抑止した**ときの固定 event 名。
     *
     * ログ基盤で頻度を集計し「残余窓 1 が実際にどれだけ開いているか」を測るために固定する。
     * Manual / Billing の両方がこの 1 箇所を参照する (literal の直書きは
     * JobExecutionDedupInventoryTest が deny-by-default で検出する)。
     *
     * ★この event の**必須キー集合を 7 キーで固定**する
     *   (event / job_type / job_id / expected_status / actual_status / stage / external_call)。
     *   集計 schema を揃えるためであり、**PII を含まないドメイン固有キーの追加は可**
     *   (Billing は `attempt_ulid` を 1 本足す)。必須キーを欠くログを同じ event 名で出さない。
     */
    public const string LOG_EVENT = 'job_ownership_lost';

    /**
     * 所有権喪失**後の後始末** (open invoice の終端等) の固定 event 名。
     *
     * 抑止の記録とは schema が違う (expected/actual status も stage も持たない) ため
     * 別 event にする。集計時は「抑止 → 後始末の成否」の 2 段で追える。
     */
    public const string CLEANUP_LOG_EVENT = 'job_ownership_lost_cleanup';

    /** LLM 補完 (Prism 経由)。**provider 側に冪等キーが無い** = 呼んだら取り消せない */
    case LlmCompletion = 'llm_completion';

    /** オブジェクトストレージへの PUT (レンダ出力の S3 アップロード) */
    case ObjectStoragePut = 'object_storage_put';

    /** Stripe invoice の作成 (課金の前段。open invoice を残す) */
    case StripeInvoiceCreate = 'stripe_invoice_create';

    /** Stripe invoice の off-session 支払い (実際に金が動く) */
    case StripeInvoicePay = 'stripe_invoice_pay';
}
