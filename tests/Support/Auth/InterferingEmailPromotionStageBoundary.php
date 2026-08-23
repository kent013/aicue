<?php

declare(strict_types=1);

namespace Tests\Support\Auth;

use App\Contracts\Auth\EmailPromotionStageBoundary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 検査用の継ぎ目。**第 1 段が閉じた直後に別経路のメール更新を割り込ませる**。
 *
 * ★狙いは「第 1 段の commit と第 2 段の適用の間」という窓を実際に作ることである。
 *   モデルイベントの listener で代用すると割り込みが**第 1 段の内側**で走ってしまい
 *   (同じ接続なので自分が持つ行ロックへ再入できる)、測っているものが主張と食い違う。
 * ★暗号文は**呼び出し側が作って渡す** (暗号化の手順を 2 か所に持たない)。
 * ★**1 回だけ**割り込む。
 */
final class InterferingEmailPromotionStageBoundary implements EmailPromotionStageBoundary
{
    private bool $done = false;

    /**
     * 継ぎ目に着いた時点の transaction level。
     *
     * ★呼び出し前の level と等しければ「第 1 段が開いた層をすべて閉じた」ことになる。
     *   検査は**この等号**で「段を抜けた」を固定する (「commit した」とは言わない —
     *   `RefreshDatabase` の外側の層があるので、実際に閉じるのは savepoint である)。
     */
    public ?int $transactionLevelAtSeam = null;

    /** @param string $encryptedEmail `users.email` へそのまま入れる暗号文 */
    public function __construct(private readonly string $encryptedEmail) {}

    public function afterConsume(User $user): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->transactionLevelAtSeam = DB::transactionLevel();

        // ★昇格の経路を通さずに `users.email` を入れる (プロフィール更新などの別経路を模す)。
        $user->newQuery()->whereKey($user->getKey())->update(['email' => $this->encryptedEmail]);
    }
}
