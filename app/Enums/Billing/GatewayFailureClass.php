<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 決済 gateway 消費経路で観測された失敗の分類。
 *
 * ★語彙は「**呼び出し側 / 運用担当が取れる行動**」で切る。Stripe の error code を
 *   そのまま採らない (外部語彙に依存すると増えたときに追随できない)。
 * ★case を足す条件は「運用担当が取る行動が既存 case と異なる」ことだけ。
 *   分類の粒度を過剰にしない (AGENTS.md 思考原則 2)。
 * ★**この分類は観測のためであり、制御フローを変えない。**
 *   分岐に使いたくなったら、そのときは型 (ドメイン例外) を検討し直すこと。
 * ★カード拒否 (`card_declined` / `authentication_required`) は本 enum の担当ではない。
 *   既に `OffSessionChargeResultDto` の typed 結果が持っている (語彙を二重管理しない)。
 */
enum GatewayFailureClass: string
{
    /** 決済事業者側の一時的な不能 (接続断・タイムアウト・レート制限・5xx)。同じ要求の再送で収束しうる */
    case ProviderUnavailable = 'provider_unavailable';

    /** 決済事業者が要求を受理しなかった。同じ要求を再送しても収束しない (要求内容・認証情報・利用者操作のいずれかが要る) */
    case ProviderRejected = 'provider_rejected';

    /** アプリ自身が検出した不変条件違反 (Assert / 明示的な例外 / SDK・Cashier の誤用) */
    case InvariantViolation = 'invariant_violation';

    /** 自インフラ層 (DB / cache) が返した失敗。障害・SQL 不備・制約違反のいずれもありうる */
    case LocalFailure = 'local_failure';

    /**
     * **写像表に一致が無かった**。
     *
     * ★この case が出ること自体が「分類器に欠落がある」という通知である。
     *   したがって**写像表の値として使ってはならない** (登録済みなのに unknown、という
     *   状態を作ると運用契約「unknown が出たら表へ足せ」と矛盾する)。
     *   `BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する。
     */
    case Unknown = 'unknown';
}
