<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Enums\Recovery\NonRecoveryScheduleReasonKind;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Recovery\Streams\ExpiredTicketReservationStream;
use App\Services\Recovery\Streams\StaleAnalysisJobStream;
use App\Services\Recovery\Streams\StaleRenderJobStream;
use App\Services\Recovery\Streams\StaleUploadReservationStream;
use App\Services\Recovery\Streams\StaleWebhookEventStream;

/**
 * 滞留回収の目録 (単一の source of truth)。
 *
 * `StuckWorkRecoveryInventoryTest` が deny-by-default で、
 * 「registry の系列集合 == RecoveryStream の全 case == 本目録の申告集合」と
 * 「Schedule に載る全コマンドが回収の入口かここの非回収申告のどちらかに属する」を強制する。
 */
final class StuckWorkRecoveryInventory
{
    /** 回収コマンドの名前 (系列の指定と --apply を除いた部分) */
    public const string RECOVERY_COMMAND = 'work:recover-stuck';

    /**
     * 系列ごとの申告。
     *
     * @return array<value-of<RecoveryStream>, RecoveryStreamEntry>
     */
    public static function streams(): array
    {
        $entries = [
            new RecoveryStreamEntry(
                stream: RecoveryStream::AnalysisJob,
                implementation: StaleAnalysisJobStream::class,
                sweepItemLimit: null,
                possibleOutcomes: [RecoveryOutcome::Recovered, RecoveryOutcome::Skipped],
                description: '投入待ちのまま動き出さない / 動き出したまま進まない AI 解析ジョブを失敗として'
                    .'確定し、押さえていたチケット予約を解放する',
            ),
            new RecoveryStreamEntry(
                stream: RecoveryStream::RenderJob,
                implementation: StaleRenderJobStream::class,
                sweepItemLimit: null,
                possibleOutcomes: [RecoveryOutcome::Recovered, RecoveryOutcome::Skipped],
                description: '滞留したレンダジョブを失敗として確定し、編集ロック (manual の rendering) と'
                    .'チケット予約を解放する。閾値は投入待ちと実行中で分かれている',
            ),
            new RecoveryStreamEntry(
                stream: RecoveryStream::TicketReservation,
                implementation: ExpiredTicketReservationStream::class,
                sweepItemLimit: null,
                possibleOutcomes: [RecoveryOutcome::Recovered, RecoveryOutcome::Skipped],
                description: '有効期限を過ぎたチケット予約と、消費元が失効した月次 hold を解放して'
                    .'残高の拘束を解く (放置すると翌期間の残高を侵食する)',
            ),
            new RecoveryStreamEntry(
                stream: RecoveryStream::WebhookEvent,
                implementation: StaleWebhookEventStream::class,
                sweepItemLimit: null,
                possibleOutcomes: [
                    RecoveryOutcome::Recovered,
                    RecoveryOutcome::Deferred,
                    RecoveryOutcome::Escalated,
                    RecoveryOutcome::Skipped,
                ],
                description: '本処理中に落ちて受理済みのまま残った Stripe の通知を再実行する。'
                    .'再実行してよい種類かは通知の分類が決め、対象外と試行上限は人手へ渡す',
            ),
            new RecoveryStreamEntry(
                stream: RecoveryStream::UploadReservation,
                implementation: StaleUploadReservationStream::class,
                sweepItemLimit: 500,
                possibleOutcomes: [
                    RecoveryOutcome::Recovered,
                    RecoveryOutcome::RecoveredWithCleanupFailure,
                    RecoveryOutcome::Skipped,
                ],
                description: '期限切れの撮影アップロード予約を解放して容量の予約枠を戻し、'
                    .'置かれたまま登録されていないファイルを削除する (削除の失敗は別の件数で数える)',
            ),
        ];

        $indexed = [];
        foreach ($entries as $entry) {
            $indexed[$entry->stream->value] = $entry;
        }

        return $indexed;
    }

    /**
     * 「滞留回収ではない定期実行」の申告 (コマンド名 => 申告)。
     *
     * @return array<string, NonRecoveryScheduleEntry>
     */
    public static function nonRecoverySchedules(): array
    {
        $entries = [
            new NonRecoveryScheduleEntry(
                'billing:reconcile-auto-recharge',
                NonRecoveryScheduleReasonKind::ExternalReconciliation,
                'チケット自動購入の未決の支払いを Stripe を真実として 5 分岐で収束させる。'
                .'DB の状態だけでは行き先が決まらないため滞留の前進とは別の判断が要る',
            ),
            new NonRecoveryScheduleEntry(
                'billing:reconcile-schedules',
                NonRecoveryScheduleReasonKind::ExternalReconciliation,
                '契約の予約 (Subscription Schedule) の作りかけを Stripe と突き合わせて直す。'
                .'こちらの状態を進めるのではなく外部の状態に合わせる処理である',
            ),
            new NonRecoveryScheduleEntry(
                'billing:reconcile-subscription-status',
                NonRecoveryScheduleReasonKind::ExternalReconciliation,
                '通知の欠落で固まった契約状態を Stripe を真実として日次で収束させる。'
                .'金銭 (チケット) には触れず、状態の写しを合わせるだけである',
            ),
            new NonRecoveryScheduleEntry(
                'render:reconcile-outputs',
                NonRecoveryScheduleReasonKind::ArtifactCleanup,
                '世代交代済みのレンダ出力を削除ジョブへ再投入する。止まった処理を前へ進めるのではなく'
                .'不要になった生成物を片付ける処理なので滞留回収には含めない',
            ),
            new NonRecoveryScheduleEntry(
                'billing:send-billing-reminders',
                NonRecoveryScheduleReasonKind::Notification,
                '更新予告の送信。業務状態は前へ進めず、通知台帳の重複防止キーで冪等に送るだけである',
            ),
            new NonRecoveryScheduleEntry(
                'billing:detect-orphan-billing-organizations',
                NonRecoveryScheduleReasonKind::DetectionOnly,
                'Owner 不在かつ課金中の組織を検知して報告する。状態を 1 バイトも書かないので'
                .'回収ではなく観測である',
            ),
            new NonRecoveryScheduleEntry(
                'inquiry:purge',
                NonRecoveryScheduleReasonKind::RetentionSettlement,
                '保持期限を過ぎた問い合わせ記録の削除。期限の決着であって滞留の前進ではない',
            ),
            new NonRecoveryScheduleEntry(
                'idempotency:prune',
                NonRecoveryScheduleReasonKind::RetentionSettlement,
                '保持期間を過ぎた冪等キーの物理削除。期限の決着であって滞留の前進ではない',
            ),
            new NonRecoveryScheduleEntry(
                'account:purge-deletion-requests',
                NonRecoveryScheduleReasonKind::RetentionSettlement,
                '猶予期間を過ぎた退会予約の執行。利用者が申し込んだ予定の実行であって回収ではない',
            ),
            new NonRecoveryScheduleEntry(
                'billing:purge-retention-expired',
                NonRecoveryScheduleReasonKind::RetentionSettlement,
                '保持期限 (7 年) を過ぎた課金記録の削除と畳み込み。期限の決着であって滞留の前進ではない',
            ),
            new NonRecoveryScheduleEntry(
                'enterprise-sso:prune-login-attempts',
                NonRecoveryScheduleReasonKind::RetentionSettlement,
                '期限切れの企業 SSO ログイン試行の物理削除。期限の決着であって滞留の前進ではない'
                .'(戻ってこなかった試行を消すだけで、止まった処理を進めるわけではない)',
            ),
            new NonRecoveryScheduleEntry(
                'auth:prune-email-promotions',
                NonRecoveryScheduleReasonKind::RetentionSettlement,
                '期限切れのメール昇格の確認待ちの物理削除。期限の決着であって滞留の前進ではない'
                .'(消さないと利用者ごとの 1 件の枠が空かない)',
            ),
            new NonRecoveryScheduleEntry(
                'capture:purge-upload-reservations',
                NonRecoveryScheduleReasonKind::RetentionSettlement,
                '保持期間を過ぎた解放済み / 登録済みのアップロード予約の物理削除。肥大の防止であり、'
                .'止まった処理を前へ進める回収とは責務が違うので入口を分けている',
            ),
        ];

        $indexed = [];
        foreach ($entries as $entry) {
            $indexed[$entry->commandName] = $entry;
        }

        return $indexed;
    }
}
