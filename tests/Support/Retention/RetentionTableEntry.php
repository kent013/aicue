<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

/**
 * 1 表分の保持期限の宣言。
 *
 * **コンストラクタは private で、区分ごとの名前付き生成子からしか作れない**。
 * 「定期実行が消すのに保持者が無い」宣言は書けない
 * (実行時の検査に頼らず、型で不正な状態を作らせない)。
 */
final readonly class RetentionTableEntry
{
    /** 根拠の最低文字数 (「同上」「N/A」を機械的に弾く。検査は gate の RC-3)。 */
    public const int RATIONALE_MIN_LENGTH = 30;

    /**
     * @param  class-string|null  $ownerClass  期限 / 削除責務の解決点クラス
     * @param  string|null  $ownerCommand  期限を執行する artisan コマンド名
     */
    private function __construct(
        public string $table,
        public RetentionClass $class,
        public string $rationale,
        public ?string $ownerClass = null,
        public ?string $ownerCommand = null,
    ) {}

    /** 課金取引の記録。年数・起算点・purger は書かない (正本は BillingRetentionTarget)。 */
    public static function billingRecord(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::BillingRecord, $rationale);
    }

    /**
     * 定期実行が消す表。保持者の宣言は**必須**。
     *
     * @param  class-string  $ownerClass
     */
    public static function scheduledDeletion(
        string $table,
        string $rationale,
        string $ownerClass,
        string $ownerCommand,
    ): self {
        return new self($table, RetentionClass::ScheduledDeletion, $rationale, $ownerClass, $ownerCommand);
    }

    /**
     * 親と一緒に消える表。
     *
     * `on delete cascade` の外部キーを 1 本以上持つなら $ownerClass は不要。
     * 連動が DB ではなくアプリ側にある (cascade が無い) 場合は、削除責務を持つクラスを宣言する。
     *
     * @param  class-string|null  $ownerClass
     */
    public static function deletedWithParent(string $table, string $rationale, ?string $ownerClass = null): self
    {
        return new self($table, RetentionClass::DeletedWithParent, $rationale, $ownerClass);
    }

    public static function referenceData(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::ReferenceData, $rationale);
    }

    public static function frameworkManaged(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::FrameworkManaged, $rationale);
    }

    /** 保持期限が未確定の表。$rationale には**何が決まっていないか**を書く。 */
    public static function undecided(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::Undecided, $rationale);
    }
}
