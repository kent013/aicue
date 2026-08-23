<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Organizations;

use App\Enums\AccountDeletionBlockerAction;
use App\Enums\AccountDeletionBlockReason;
use App\Models\Organization;

/**
 * 退会 (アカウント削除) をブロックしている組織 1 件分。
 *
 * 算出は OrganizationMembershipService::organizationsBlockingDeletion() の 1 本だけ。
 * 「削除前の予告 (Inertia props)」と「ブロック時の応答 (ValidationException)」の両方が
 * この DTO を入力にする (文言の二重管理を作らない)。
 *
 * @phpstan-type AccountDeletionBlockerShape array{
 *   name: string,
 *   slug: string,
 *   actions: list<string>
 * }
 */
final readonly class AccountDeletionBlockerDto
{
    /**
     * @param  list<AccountDeletionBlockReason>  $reasons  サーバ内部語彙 (wire に載せない)
     * @param  list<AccountDeletionBlockerAction>  $actions  表示用の次の一手
     */
    public function __construct(
        public string $name,
        public string $slug,
        public array $reasons,
        public array $actions,
    ) {}

    /**
     * 理由集合から action 列を導出して組み立てる。
     *
     * action 導出規則:
     *   1. OwnerlessMembers → TransferOwnership
     *   2. ActiveBilling    → OpenBilling (その組織の URL 配下の課金画面へ直接行ける)
     *   - 出力順は **TransferOwnership → billing 系** で固定 (画面の並びを安定させる。入力順に依らない)
     *   - 同じ理由を重複して渡しても action は重複しない
     *
     * @param  list<AccountDeletionBlockReason>  $reasons
     */
    public static function build(
        Organization $organization,
        array $reasons,
    ): self {
        /** @var list<AccountDeletionBlockerAction> $actions */
        $actions = [];
        if (in_array(AccountDeletionBlockReason::OwnerlessMembers, $reasons, true)) {
            $actions[] = AccountDeletionBlockerAction::TransferOwnership;
        }
        if (in_array(AccountDeletionBlockReason::ActiveBilling, $reasons, true)) {
            $actions[] = AccountDeletionBlockerAction::OpenBilling;
        }

        /** @var list<AccountDeletionBlockReason> $uniqueReasons */
        $uniqueReasons = array_values(array_unique($reasons, SORT_REGULAR));

        return new self(
            name: $organization->name,
            slug: $organization->slug,
            reasons: $uniqueReasons,
            actions: $actions,
        );
    }

    /**
     * ブロック時のエラーメッセージに埋め込む「この組織で必要な対応」の短文。
     * 例: 「現場A」オーナーの移譲 / 「現場B」サブスクリプションの解約 /
     *     「現場C」オーナーの移譲とサブスクリプションの解約
     */
    public function requirementLabel(): string
    {
        $requirements = [];
        if (in_array(AccountDeletionBlockReason::OwnerlessMembers, $this->reasons, true)) {
            $requirements[] = 'オーナーの移譲';
        }
        if (in_array(AccountDeletionBlockReason::ActiveBilling, $this->reasons, true)) {
            $requirements[] = 'サブスクリプションの解約';
        }

        return "「{$this->name}」".implode('と', $requirements);
    }

    /**
     * 画面へ渡す形 (reasons = 内部語彙は載せない)。
     *
     * @return AccountDeletionBlockerShape
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'actions' => array_map(
                static fn (AccountDeletionBlockerAction $action): string => $action->value,
                $this->actions,
            ),
        ];
    }
}
