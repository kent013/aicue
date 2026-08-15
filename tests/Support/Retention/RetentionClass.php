<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

/**
 * 表ごとの「保持期限を誰が持つか」の区分。
 *
 * 分類の母集団は**実スキーマの表一覧**であり、人間が申告したディレクトリやモデル一覧ではない
 * (母集団を申告に置くと、申告の外に置かれた表は何をしても検出できない)。
 */
enum RetentionClass: string
{
    /** 課金取引の記録。期限 (7 年) と実処理の正本は App\Enums\Billing\BillingRetentionTarget 側にある。 */
    case BillingRecord = 'billing_record';

    /** 定期実行の掃除が期限を執行する表。期限の保持者 (解決点クラスとコマンド) の宣言が要る。 */
    case ScheduledDeletion = 'scheduled_deletion';

    /** 独自の期限を持たず、親行の削除に連動して消える表。 */
    case DeletedWithParent = 'deleted_with_parent';

    /** 期限を持たない基準データ。運用者が入れ替えるまで残る (プラン / 権限 / 分類)。 */
    case ReferenceData = 'reference_data';

    /** フレームワーク・キュー・セッションの実装が寿命を決める表。 */
    case FrameworkManaged = 'framework_managed';

    /** 保持期限がまだ決まっていない表。隠さずここへ載せる (件数と表名を gate が pin する)。 */
    case Undecided = 'undecided';

    /**
     * その表がいずれ消えることを前提にしている区分か。
     *
     * ReferenceData / FrameworkManaged がこの側の表を**親に持っていたら**、
     * その表自身も期限の連鎖の中にあることになる (= 分類が間違っている)。
     *
     * Undecided を true 側に置くのは「期限が要ると決まった」からではなく、
     * **期限の連鎖に入りうるので保守的にこちら側へ寄せる**という判断である
     * (未確定の表を親に持つ基準データは、期限が決まった瞬間に壊れる)。
     *
     * ★**削除期限が実在することを保証する述語ではない**。RC-7 が「基準データ / 基盤の表が
     *   親に持ってはいけない側」を選ぶためだけに使う分類上の述語である。
     */
    public function hasHorizon(): bool
    {
        return match ($this) {
            self::BillingRecord,
            self::ScheduledDeletion,
            self::DeletedWithParent,
            self::Undecided => true,
            self::ReferenceData,
            self::FrameworkManaged => false,
        };
    }

    /** 人が読む区分名 (失敗メッセージ用)。 */
    public function label(): string
    {
        return match ($this) {
            self::BillingRecord => '課金取引の記録 (7 年)',
            self::ScheduledDeletion => '定期実行が消す',
            self::DeletedWithParent => '親と一緒に消える',
            self::ReferenceData => '基準データ',
            self::FrameworkManaged => '基盤が寿命を持つ',
            self::Undecided => '未確定',
        };
    }
}
