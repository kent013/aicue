<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「決済 gateway を注入されるが、gateway 例外を**観測しない**ことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` が deny-by-default で
 * 「観測目録に登録する」か「本 enum + 具体的根拠付きの exemption」かを機械強制する。
 *
 * ★置き場所は既存の gate 語彙 enum (ThrottleCoverageExemption / JobDedupExemption /
 *   DirectFetchJustification / ControllerAuthorizationExemption / NestedRouteDefenseMode) と揃える。
 */
enum GatewayFailureObservationExemption: string
{
    /**
     * gateway 例外を catch せず**伝播させる**。
     *
     * 適用条件: クラス内に gateway 呼び出しを囲む catch が 1 つも無く、失敗が
     * キューの再試行 / `failed_jobs` に載ることで可観測性が担保されること。
     * ★根拠欄には「catch しないから安全」ではなく
     *   **「catch しない結果どこに何が残るか」**を書くこと
     *   (伝播先には vendor 例外の message が載る = 本設計の保証範囲外である)。
     */
    case PropagatesToQueueFailure = 'propagates_to_queue_failure';
}
