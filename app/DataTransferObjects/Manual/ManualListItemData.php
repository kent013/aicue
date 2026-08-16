<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\ManualProgress;
use App\Enums\Manual\VideoManualStatus;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use App\Services\Manual\ManualRowAbilities;

/**
 * 動画マニュアル一覧 (Projects/Show に内包) の 1 行。TS の ManualListItem と対。
 *
 * **判断はここで 1 回だけ**行う (UI 側で published / ability を再判定しない。
 * RenderPanel の finishedJob と同じ流儀)。
 */
final readonly class ManualListItemData
{
    /**
     * @param  ManualListRefData|null  $category  null = 未分類
     * @param  ManualListRefData|null  $creator  null = 退会/削除で解決不可
     * @param  int|null  $durationMs  いま公開されている完成動画の長さ (ms)。null = 未確定
     * @param  int|null  $currentFinishedRenderJobId  いま受け取れる完成動画 (kind=render) の
     *                                                render job id。**null = 受け取れない**。非 null であることは download endpoint が
     *                                                302 を返す条件と 1 対 1 (download ability × published × 現行世代の succeeded render に
     *                                                output_path がある)。値は再生 endpoint
     *                                                `projects.manuals.render-jobs.playback` のパスにそのまま使える
     *                                                (完成動画の再生条件は download と完全同一 = ドメイン規約 13 / T154)
     */
    public function __construct(
        public int $id,
        public string $title,
        /**
         * 一覧の状態 (3 値)。**制作状態 5 値は一覧行に載せない** (行バッジ以外の用途が無く、
         * 絞り込みと語彙が食い違うため。実況は詳細画面 / ダッシュボードの責務)
         */
        public ManualProgress $progress,
        public ?ManualListRefData $category,
        public ?ManualListRefData $creator,
        public string $createdAt,
        public string $updatedAt,
        public ?int $durationMs,
        public ?int $currentFinishedRenderJobId,
        public bool $deletable,
    ) {}

    public static function fromManual(VideoManual $manual, ManualRowAbilities $abilities): self
    {
        $category = $manual->category;
        $creator = $manual->creator; // 退会/削除で null になり得る (実運用では FK RESTRICT)
        $isPublished = $manual->status === VideoManualStatus::Published;

        // 再生時間は「**いま公開されている**完成動画の長さ」。published が外れた行
        // (公開後にシナリオを保存すると ScenarioService が ready へ戻す) の total_length_ms は
        // 最新シナリオと対応しない古い尺なので出さない。
        $durationMs = $isPublished ? $manual->total_length_ms : null;

        // 「どの行か」の選択は **CurrentRenderArtifact ただ 1 箇所**に委ねる (T154)。
        // 一覧は eager load 済み候補から選ぶ入口を使う (行数に比例したクエリを撃たない)。
        // ここに残るのは Canonical が持たない責務 = published 判定と ability 判定だけである。
        // **ストレージ実体の存在確認ではない** (download / playback endpoint もしていない)。
        $currentFinishedRenderJobId = $abilities->canDownload && $isPublished
            ? CurrentRenderArtifact::fromLoadedRenderCandidate($manual)?->id
            : null;

        return new self(
            id: $manual->id,
            title: $manual->title,
            progress: ManualProgress::forStatus($manual->status),
            category: $category === null ? null : new ManualListRefData($category->id, $category->name),
            creator: $creator === null ? null : new ManualListRefData($creator->id, $creator->name),
            createdAt: $manual->created_at?->format('Y-m-d H:i') ?? '',
            updatedAt: $manual->updated_at?->format('Y-m-d H:i') ?? '',
            durationMs: $durationMs,
            currentFinishedRenderJobId: $currentFinishedRenderJobId,
            deletable: $abilities->canDelete,
        );
    }

    /**
     * @return array{id: int, title: string, progress: string,
     *   category: array{id: int, name: string}|null,
     *   creator: array{id: int, name: string}|null,
     *   created_at: string, updated_at: string,
     *   duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'progress' => $this->progress->value,
            'category' => $this->category?->toArray(),
            'creator' => $this->creator?->toArray(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'duration_ms' => $this->durationMs,
            'current_finished_render_job_id' => $this->currentFinishedRenderJobId,
            'deletable' => $this->deletable,
        ];
    }
}
