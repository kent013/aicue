<?php

declare(strict_types=1);

namespace App\Console\Commands\Account;

use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * 退会予約 (猶予期間つき削除) の日次執行。
 *
 * ★**判定コードを分岐させない**。期限到来の再確認は
 *   `OrganizationMembershipService::executeAccountDeletionRequest()` が行い、削除そのものは
 *   既存の `deleteAccount()` をそのまま呼ぶ (課金ガードのロック下再評価をそのまま継承する)。
 *
 * ★終了コードは **2 分類**である。退会ブロッカー (ValidationException) は**業務上の保留**で
 *   SUCCESS のまま次へ進み、インフラ障害や不変条件違反は `unexpected` として FAILURE を返す。
 *   全件 DB 障害でも SUCCESS を返すと scheduler の失敗通知も終了コード監視も機能しなくなる
 *   (`report()` の成功自体も保証されない)。
 *
 * ★ログには **件数のみ**。user id / email を出さない (PII 非出力。既存
 *   `billing:detect-orphan-billing-organizations` の報告契約と同水準)。
 *
 * ★`chunkById` を使う (走査中に行が消えても飛ばない)。`chunk` は使わない。
 */
class PurgeDeletionRequestsCommand extends Command
{
    protected $signature = 'account:purge-deletion-requests
        {--apply : 実削除する (未指定は dry-run)}';

    protected $description = '猶予期間を過ぎた退会予約を執行する (既定 dry-run)';

    public function handle(OrganizationMembershipService $membership): int
    {
        $apply = $this->option('apply') === true;
        $due = 0;
        $deleted = 0;
        $blocked = 0;      // 業務上の保留 (ValidationException)
        $unexpected = 0;   // インフラ障害 / 不変条件違反

        // 片列だけの非正規行を **due 走査より前に** 数える。DB の CHECK 制約に対する
        // defense-in-depth であり、制約の代替ではない (状態機械を閉じているのは DB 側)。
        // 件数だけを report し、user id は出さない。
        $invalidStateCount = User::query()
            ->where(function (Builder $query): void {
                $query->whereNull('deletion_requested_at')->whereNotNull('deletion_purge_after');
            })
            ->orWhere(function (Builder $query): void {
                $query->whereNotNull('deletion_requested_at')->whereNull('deletion_purge_after');
            })
            // CHECK 制約 2 本と対称にする (制約が無効化されたとき、期限が予約時刻より前の行が
            // 早期削除候補に入る異常も検知できる)
            ->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')
            ->count();
        if ($invalidStateCount > 0) {
            $unexpected += $invalidStateCount;
            report(new RuntimeException(
                "退会予約列が非正規な行を検出: count={$invalidStateCount}",
            ));
        }

        // 片列だけの非正規行を due に数えないため両列を条件にする
        // (DTO の pending 定義「両列が揃う」と一致させる)。
        User::query()
            ->whereNotNull('deletion_requested_at')
            ->whereNotNull('deletion_purge_after')
            // ★**非正規な組 (期限 < 予約時刻) を due に入れない** (fail-closed)。
            //   入れると「猶予が経過していない行を早期に物理削除する」向きに倒れる。
            //   同じ判断は AccountDeletionStateDto::isDue() 側にもあり (二重防御)、
            //   非正規行は上の invalidStateCount が件数だけ report して FAILURE にする。
            ->whereColumn('deletion_purge_after', '>=', 'deletion_requested_at')
            ->where('deletion_purge_after', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->chunkById(100, function (Collection $users) use (&$due, &$deleted, &$blocked, &$unexpected, $apply, $membership): void {
                /** @var Collection<int, User> $users */
                foreach ($users as $user) {
                    $due++;
                    if (! $apply) {
                        continue;
                    }
                    try {
                        // ロック取得後に「予約が生きているか」「期限到来か」を再確認する
                        // (抽出後に取消されたユーザーを古いスナップショットで消さない)。
                        if ($membership->executeAccountDeletionRequest($user)) {
                            $deleted++;
                        }
                    } catch (ValidationException $e) {
                        // 退会ブロッカー = **業務上の保留**。予約は維持し次へ進む。
                        // ★ここで `report($e)` はしない。Laravel の既定 dontReport が
                        //   ValidationException を握り潰すため**何も起きない** (実測)。
                        //   保留は走査後に件数だけを集約 report する (下記)。
                        $blocked++;
                    } catch (Throwable $e) {
                        // インフラ障害 / 不変条件違反 = **想定外**。継続はするが終了コードは FAILURE。
                        $unexpected++;
                        report($e);
                    }
                }
            });

        if ($blocked > 0) {
            // 業務上の保留は終了コードを FAILURE にしない (障害ではない) が、
            // **放置されると 30 日を過ぎた予約が消えないまま滞留する**ので観測はさせる。
            // 件数のみ (user id / email は載せない)。
            report(new RuntimeException(
                "退会予約の執行を保留 (退会ブロッカーあり): count={$blocked}",
            ));
        }

        $this->info("due={$due} deleted={$deleted} blocked={$blocked} unexpected={$unexpected}");

        return $unexpected > 0 ? self::FAILURE : self::SUCCESS;
    }
}
