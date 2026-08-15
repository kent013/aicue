<?php

declare(strict_types=1);

use App\Enums\Recovery\RecoveryStream;
use App\Services\Billing\AccountDeletionBillingGuard;
use App\Services\Manual\RenderJobService;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 滞留回収 (AG-083 標準形 v1)
|--------------------------------------------------------------------------
| 系列ごとに 1 本ずつ登録する (実行間隔は RecoveryStream::cadenceMinutes が正本)。
| **--apply の付け忘れは回収が全面停止しても無音**なので、配線は
| StuckWorkRecoveryInventoryTest が系列のキー単位で機械固定する。
|
| 監視対象 (必須): 各実行の出力と onFailure。**5 つを見る**。
|   - errors > 0 が続く        = 特定の行で回収が失敗し続けている
|   - deferred > 0 が続く      = 再実行が失敗し続けている (webhook。**errors には出ない** —
|                                失敗は行に書き戻して次回へ回すため、errors=0 のまま滞留しうる)
|   - escalated の件数         = 自動回収の対象外として人手へ渡した件数 (webhook)
|   - cleanup-failed > 0       = S3 の孤児削除に失敗した件数 (**手動確認が要る**。
|                                行は解放済みなので自動では拾い直せない)
|   - limit-reached=yes が続く = 上限で打ち切っており後続候補が残っている
| 詳細は docs/architecture.md の「滞留回収の共通基盤」が正本。
*/
foreach (RecoveryStream::cases() as $recoveryStream) {
    Schedule::command('work:recover-stuck --stream='.$recoveryStream->value.' --apply')
        ->cron('*/'.$recoveryStream->cadenceMinutes().' * * * *')
        ->onOneServer()
        // 期限を明示する。既定 (24 時間) だと異常終了で残ったロックが丸 1 日回収を止める
        ->withoutOverlapping($recoveryStream->overlapExpiryMinutes())
        // RuntimeException は import しない (本ファイルは namespace 宣言が無く global 解決される)
        ->onFailure(static fn () => report(new RuntimeException(
            'work:recover-stuck --stream='.$recoveryStream->value.' 失敗 — 滞留が前へ進んでいない可能性',
        )));
}

/*
|--------------------------------------------------------------------------
| 課金 daily バッチ
|--------------------------------------------------------------------------
| - send-billing-reminders: 更新予告 (renewal 3 日前)。冪等は通知台帳の dedup_key。
| - reconcile-schedules: Subscription Schedule の部分完了 / local-remote 差分の復旧。
*/
Schedule::command('billing:send-billing-reminders')->daily()->onOneServer()->withoutOverlapping();
Schedule::command('billing:reconcile-schedules')->daily();

/*
|--------------------------------------------------------------------------
| Stripe 契約状態の突き合わせ (AG-035 (6))
|--------------------------------------------------------------------------
| webhook 欠落でローカルの契約状態が固まると、支払い失敗の遮断も復旧も起きない。
| 日次で Stripe を真実として収束させる。**チケット (金銭) には触れない**。
|
| 既存の 2 本とは書く列が重ならない (相乗りさせない):
|   - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金
|   - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけ
|
| **監視対象**: 終了コードと report() (未確認・失敗はここにしか出ない)。
*/
Schedule::command('billing:reconcile-subscription-status')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-subscription-status 失敗 — Stripe と契約状態が突き合わせられていない',
    )));

/*
|--------------------------------------------------------------------------
| 課金孤児の検知 (退会ガードの second layer)
|--------------------------------------------------------------------------
| 退会ガード (AccountDeletionBillingGuard) は通常経路を止めるが、webhook トランザクションと
| 同時刻に退会が commit される競合までは排他しない (subscription 行を作るのは Cashier の
| WebhookController = vendor 側で、自前 listener の排他では覆えないため)。
| 予防で漏れた分と、本機能より前から存在する孤児組織を daily で検知する。
|
| 報告契約 (通知洪水を作らない):
|   - 1 実行につき **集約して 1 回だけ** report() する
|   - 内容は **件数と organization id のみ** (組織名・メール等の PII を載せない)
|   - 未解消なら翌日も同じ内容で再報告する (抑制状態を持たない = 冪等な観測)
|
| **監視対象**: 本コマンドの report()。
*/
Artisan::command('billing:detect-orphan-billing-organizations', function (
    OrganizationMembershipService $membership,
    AccountDeletionBillingGuard $guard,
) {
    $ids = $guard->orphanBillingOrganizationIds($membership->organizationsWithoutOwner());
    if ($ids === []) {
        $this->info('課金孤児なし');

        return;
    }

    $this->warn(count($ids).' 件の課金孤児組織を検出しました');
    // RuntimeException は import しない (本ファイルは namespace 宣言が無く global 解決される。
    // 非複合 use は NoNonCompoundGlobalUseTest が禁止する)。
    report(new RuntimeException(
        'Owner 不在かつ課金中の組織を検出: count='.count($ids).' ids='.implode(',', $ids),
    ));
})->purpose('Owner 不在かつ生きた課金責務がある組織 (課金孤児) を検知して報告する');

Schedule::command('billing:detect-orphan-billing-organizations')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| 課金 cron (オートリチャージ / P8a)
|--------------------------------------------------------------------------
| reconcile-auto-recharge: pending attempt の回収 (課金済み回収 / 再実行 / SCA リマインド /
| 期限切れ終端 / 取りこぼし起票)。
|
| **監視対象 (必須)**: webhook が MAX_PROCESSING_ATTEMPTS=8 で恒久 drop した
| 「課金済み・付与なし」を回収する**唯一の**経路であり、停止すると資金回収済み・チケット
| 未付与が滞留する。AI-CUE の運用アラート経路は report() のみのため、onFailure をそこへ繋ぐ。
| 滞留の観測点は ticket_auto_recharge_attempts.status='pending' の件数
| (docs/architecture.md の監視対象リストを参照)。
*/
Schedule::command('billing:reconcile-auto-recharge')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-auto-recharge 失敗 — 資金回収済み・チケット未付与が滞留する可能性',
    )));

/*
|--------------------------------------------------------------------------
| 問い合わせ (Inquiry) retention purge
|--------------------------------------------------------------------------
| 保持期限 (config legal.inquiry_retention_days) を超過した spam / closed を日次で削除する。
| 手動運用 (dry-run / 本人削除要請) は docs/inquiry-deletion-runbook.md を参照。
*/
Schedule::command('inquiry:purge --apply')->daily();

/*
|--------------------------------------------------------------------------
| 退会予約の執行 (猶予期間つき削除)
|--------------------------------------------------------------------------
| deletion_purge_after を過ぎた退会予約を執行する。判定は既存の
| OrganizationMembershipService::deleteAccount() が行う (課金ガードのロック下再評価を
| そのまま継承する)。退会ブロッカーは**業務上の保留**として次へ進み、想定外例外があれば
| FAILURE で終わる (全件 DB 障害を SUCCESS で隠さない)。
|
| **監視対象**: 本コマンドの終了コードと report()。
| 取消は利用者が /settings からいつでも行える (誤操作救済の本体)。
*/
Schedule::command('account:purge-deletion-requests --apply')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| レンダ出力世代の収束
|--------------------------------------------------------------------------
| 世代交代済みの output_path を削除 job へ再投入する。**滞留の前進ではない**ため
| 滞留回収 (work:recover-stuck) には含めず、別コマンドのまま残す
| (StuckWorkRecoveryInventoryTest の「回収でない定期実行」へ理由付きで登録している)。
*/
Artisan::command('render:reconcile-outputs', function (RenderJobService $jobs) {
    $result = $jobs->reconcileOutputs();
    $this->info("dispatched {$result['dispatched']} delete job(s), skipped {$result['skipped']}");
})->purpose('世代交代済みのレンダ出力を走査し S3 削除ジョブを再投入する (最新 1 世代へ収束)');

Schedule::command('render:reconcile-outputs')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| 撮影アップロード予約の保持期間の決着 (doc/10 §10.8-4)
|--------------------------------------------------------------------------
| released / completed の古い行 (retention 超過) を物理削除する。**滞留の回収ではなく
| 期限の決着**なので回収 (work:recover-stuck --stream=upload_reservation) とは入口を分ける。
| 肥大の防止であって緊急性が無いため日次でよい (既存の purge 系と同じ扱い)。
*/
Schedule::command('capture:purge-upload-reservations')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| 冪等キーの保持期間 purge (T139)
|--------------------------------------------------------------------------
| 保持期間 (config idempotency.retention_hours) を超えた冪等キーを
| REST / MCP 両テーブルから物理削除する。claim 時の lazy delete だけでは
| 「二度と再送されなかったキー」が残り続け単調増加するため。
|
| **監視対象**: 本コマンドの report() (processing のまま期限切れ = 確定できなかった claim。
| プロセス強制終了か finalize 失敗の痕跡)。
|
| ⚠ onOneServer() は **scheduler が動いていること + ロックを提供する cache driver** を
|   前提にする (既存の billing:send-billing-reminders / render:reconcile-outputs と同じ前提)。
*/
Schedule::command('idempotency:prune')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| 課金記録の保持期間 (7 年) の決着 (T144 / PR-C2)
|--------------------------------------------------------------------------
| 保持期限 (config legal.billing_retention_years) を超えた課金記録を日次で決着させる。
| 削除で決着する 6 target と、**畳み込み**で決着する台帳 (ticket_ledger_entries) がある
| (台帳は残高の真実源なので消すと残高が変わる。古い行は残高スナップショットへ置換する)。
|
| **監視対象**: 本コマンドの終了コードと出力の `horizon:` 行。
|   - `horizon: NG` が続く = 規約 (/privacy が宣言する最長 7 年) を満たせていない状態である。
|     **`fail_closed` は「安全に残した」であって「規約を満たした」ではない**ため残存に数える。
|   - `fail_closed` の**継続・増加**を正常成功として扱わないこと (解消手順は
|     docs/billing-retention-runbook.md)。
|
| 本コマンドは PR-C2 のデプロイ時点から --apply で有効である (runbook の初回 apply は
| 「初回を能動的に完走させて結果を確認する」ためのもので、schedule の抑止ではない)。
*/
Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
