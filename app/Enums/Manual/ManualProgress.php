<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 動画マニュアル一覧の状態語彙 (doc/04 §動画一覧ページ の 3 値: 作成済 / 作成中 / 未着手)。
 *
 * **制作状態 (VideoManualStatus, 5 値) → 一覧の状態 (3 値) の写像規則は本 enum の
 * forStatus() ただ 1 か所にある**。逆写像 (statuses()) も同じ match から導出するため、
 * 写像表が 2 か所に分かれることが構造的に起きない。
 *
 * 2 つの enum は**別の問いに答える**:
 * - VideoManualStatus = いま何をしているか (制作パイプラインの進行状態。詳細画面 /
 *   ダッシュボードが実況に使う。数十秒で遷移する短命な値を含む)
 * - ManualProgress = 仕上がっているか (一覧の絞り込みと行バッジ。ポーリングしない面で使う)
 *
 * 撮影 PWA の撮影進捗 (types/capture.ts の CaptureProgress) とは**別の量**である
 * (あちらは 1 本のマニュアルのカット採用状況の導出であり、本 enum とは母集団も更新契機も違う)。
 * 語が似ているという理由で統合しないこと。
 */
enum ManualProgress: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * 制作状態 → 一覧の状態の写像 (**唯一の写像規則**)。
     *
     * - Draft: シナリオ (cuts) が未確定。解析が失敗しても cuts が無ければ Draft へ戻る
     *   (AnalysisJobService::failJob) ため「未着手」と一致する
     * - Analyzing / Ready / Rendering: シナリオはあるが完成動画が無い = 作成中
     * - Published: 現行世代の完成動画がある。シナリオを保存すると Ready へ戻る
     *   (ScenarioService) ので「作成済」の意味と一致する
     *
     * default を持たない網羅 match なので、VideoManualStatus に case を足すと
     * PHPStan level 10 が未処理の case として落とす (無音の drift を作らない)。
     */
    public static function forStatus(VideoManualStatus $status): self
    {
        return match ($status) {
            VideoManualStatus::Draft => self::NotStarted,
            VideoManualStatus::Analyzing,
            VideoManualStatus::Ready,
            VideoManualStatus::Rendering => self::InProgress,
            VideoManualStatus::Published => self::Completed,
        };
    }

    /**
     * この値に写る制作状態の集合 (forStatus からの導出。**逆写像表を別に持たない**)。
     *
     * @return list<VideoManualStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            VideoManualStatus::cases(),
            fn (VideoManualStatus $status): bool => self::forStatus($status) === $this,
        ));
    }

    /**
     * 一覧の WHERE へ渡す DB 値。**型 (enum) と SQL (文字列) の境界をここで閉じる**
     * (binding 側の暗黙変換に依存しない)。
     *
     * @return list<string>
     */
    public function statusValues(): array
    {
        return array_map(
            static fn (VideoManualStatus $status): string => $status->value,
            $this->statuses(),
        );
    }
}
