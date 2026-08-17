<?php

declare(strict_types=1);

namespace Tests\Support\InitialState;

/**
 * NULL が意味を持つ列の区分。
 *
 * 判定は 1 つの問いで決まる: **その行が生まれた時点で、この列は必ず NULL か**。
 *
 * 母集団は**実スキーマ**の「nullable かつ DB 既定値を持たない列」であり、
 * 人が申告したモデル一覧やディレクトリではない (申告の外に置かれた列は何をしても検出できない)。
 */
enum NullInitialStateClass: string
{
    /** 生成時は必ず NULL。NULL であること自体が「まだその段階に達していない」を意味する。 */
    case InitialStateMarker = 'initial_state_marker';

    /** 生成時に値が入りうる列。NULL は該当なし / 無期限 / 未指定であって進行段階ではない。 */
    case SetAtCreation = 'set_at_creation';

    /** どちらとも決めていない列。隠さずここへ載せる (件数と列名を gate が pin する)。 */
    case Undecided = 'undecided';

    /** 人が読む区分名 (失敗メッセージ用)。 */
    public function label(): string
    {
        return match ($this) {
            self::InitialStateMarker => '初期状態の目印',
            self::SetAtCreation => '生成時に決まりうる値',
            self::Undecided => '未確定',
        };
    }
}
