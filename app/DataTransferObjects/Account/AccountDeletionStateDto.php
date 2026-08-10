<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Account;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * 退会予約 (猶予期間つき削除・凍結方式) の状態スナップショット。
 *
 * users 行の 2 列 (`deletion_requested_at` / `deletion_purge_after`) をそのまま写した値
 * オブジェクトで、**予約中かどうかの判定はこの DTO に一本化する** (middleware / Service /
 * Command / 画面 props が同じ述語を見る)。
 *
 * ★`isPending()` は**両列が揃っているときだけ** true を返す = 片列だけの非正規状態を
 *   「予約中」と認めない。DB 側の CHECK 制約 (users_deletion_request_pair_check) と同じ定義で、
 *   制約が無効化された場合でもアプリ側の判定がぶれない。
 * ★猶予日数は列を持たず `purgeAfter - requestedAt` から**導出**する (2 つの表現を持たない)。
 */
final readonly class AccountDeletionStateDto
{
    public function __construct(
        public ?CarbonImmutable $requestedAt,
        public ?CarbonImmutable $purgeAfter,
    ) {}

    /**
     * users 行から組み立てる。
     *
     * cast は `immutable_datetime` だが、`CarbonImmutable::instance()` で明示変換して
     * **cast 設定の変更に対して二重に守る** (cast が 'datetime' へ戻されても型が崩れない)。
     */
    public static function fromUser(User $user): self
    {
        $requestedAt = $user->deletion_requested_at;
        $purgeAfter = $user->deletion_purge_after;

        return new self(
            $requestedAt === null ? null : CarbonImmutable::instance($requestedAt),
            $purgeAfter === null ? null : CarbonImmutable::instance($purgeAfter),
        );
    }

    /** 予約中か (両列が揃っているときだけ true = 片方だけの非正規状態を pending と認めない)。 */
    public function isPending(): bool
    {
        return $this->requestedAt !== null && $this->purgeAfter !== null;
    }

    /**
     * **予約中の状態として正規**か (両列が揃い、かつ期限が予約時刻以降)。
     *
     * ★「DB の CHECK 制約を満たすか」ではない — 制約は「両列とも null」も正常と認めるが、
     *   本述語は未予約 (両列 null) に対して false を返す。見ているのは
     *   **「予約として扱ってよい組か」**である (名前もそれに合わせている)。
     *   制約が無効化された場合でもアプリ側が同じ判断をするための述語。
     */
    public function isValidPendingRequest(): bool
    {
        return $this->requestedAt !== null
            && $this->purgeAfter !== null
            && $this->purgeAfter->greaterThanOrEqualTo($this->requestedAt);
    }

    /**
     * 執行期限が到来しているか (比較演算子ではなく Carbon API を使う。意図と型が明確)。
     *
     * ★**非正規な組 (期限 < 予約時刻) は決して due にしない** (fail-closed)。
     *   `isPending()` ではなく `isValidPendingRequest()` を前提にするのは、CHECK 制約が壊れた場合に
     *   「猶予が経過していない行を早期に物理削除する」向きに倒れるのを防ぐためである
     *   (非正規行は日次バッチが件数だけ report し、削除せず FAILURE で終わる)。
     */
    public function isDue(CarbonImmutable $now): bool
    {
        return $this->isValidPendingRequest()
            && $this->purgeAfter?->lessThanOrEqualTo($now) === true;
    }

    /**
     * 予約が「この (requestedAt, purgeAfter) の組」と一致するか。
     *
     * キュー実行時の再確認に使う (取消済み / 再予約で値が変わった場合に古い通知を送らない)。
     * 秒未満の丸め差で偽陰性にならないよう、**秒精度**で比較する。
     *
     * ★**保証範囲 (誇張しない)**: 秒精度で比較するため、**同一秒内に取消 → 再予約**が起きると
     *   組が一致し、古い job も「現在の予約」と判定される。ただしその場合の値は新しい予約と
     *   同一 (= 案内する期日も同一) なので、利用者に誤った期日が届くことはない。
     *   ここが保証するのは「**値が変わった**予約に対して古い job を送らない」ことまでである。
     * ★非正規な組 (期限 < 予約時刻) では **false** を返す = 外部通知も出さない (fail-closed)。
     */
    public function matches(CarbonImmutable $requestedAt, CarbonImmutable $purgeAfter): bool
    {
        return $this->isValidPendingRequest()
            && $this->requestedAt?->startOfSecond()->equalTo($requestedAt->startOfSecond()) === true
            && $this->purgeAfter?->startOfSecond()->equalTo($purgeAfter->startOfSecond()) === true;
    }

    /** 猶予日数 (表示用。導出値であり列を持たない)。未予約なら null。 */
    public function graceDays(): ?int
    {
        if ($this->requestedAt === null || $this->purgeAfter === null) {
            return null;
        }

        return (int) round($this->requestedAt->diffInDays($this->purgeAfter));
    }

    /** 執行予定日のラベル (flash 文言用)。未予約なら null。 */
    public function purgeAfterLabel(): ?string
    {
        return $this->purgeAfter?->format('Y年n月j日');
    }

    /**
     * Inertia props 形。日時は **ISO 8601 文字列** (クライアントで Date に起こす)。
     *
     * @return array{requestedAt: string|null, purgeAfter: string|null, graceDays: int|null}
     */
    public function toArray(): array
    {
        return [
            'requestedAt' => $this->requestedAt?->toIso8601String(),
            'purgeAfter' => $this->purgeAfter?->toIso8601String(),
            'graceDays' => $this->graceDays(),
        ];
    }
}
