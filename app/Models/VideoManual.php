<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use Database\Factories\VideoManualFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * VideoManual (Project 配下の動画マニュアル)。
 *
 * - project_id / created_by / category_id は保護キーのため $fillable 外
 * - category は project スコープで再解決した Category を associate する (payload 直代入しない)
 * - Project 側 relation は manuals() (route パラメータ {manual} の scopeBindings 推論と一致させ
 *   IDOR 防御を確実にするため videoManuals() は使わない)
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $category_id
 * @property int $created_by
 * @property string $title
 * @property VideoManualStatus $status
 * @property int $scenario_version
 * @property int|null $total_length_ms
 * @property-read RenderJob|null $latestSucceededRender
 */
class VideoManual extends Model
{
    /** @use HasFactory<VideoManualFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VideoManualStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SourceDocument, $this>
     */
    public function sourceDocuments(): HasMany
    {
        return $this->hasMany(SourceDocument::class);
    }

    /**
     * @return HasMany<Cut, $this>
     */
    public function cuts(): HasMany
    {
        return $this->hasMany(Cut::class);
    }

    /**
     * AI 解析ジョブ (route param {analysisJob} の scopeBindings 推論と一致する relation 名)。
     *
     * @return HasMany<AnalysisJob, $this>
     */
    public function analysisJobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class);
    }

    /**
     * レンダジョブ (route param {renderJob} の scopeBindings 推論と一致する relation 名)。
     *
     * @return HasMany<RenderJob, $this>
     */
    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    /**
     * 「いま受け取れる完成動画」の**候補行** (kind=render の最新 succeeded 1 行)。
     *
     * 世代の選び方は `CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)` と
     * **同一**である (同 manual・同 kind の最新 succeeded。旧世代へフォールバックしない)。
     * 違いは 1 点だけで、**こちらは受け取れるかを判断しない** — `output_path` を見ないため
     * NULL (掃除済み) の行も返す。受け取れるかの決定 (`output_path` を含む) は
     * `CurrentRenderArtifact` が行い、一覧向けの入口は
     * `CurrentRenderArtifact::fromLoadedRenderCandidate($manual)` である
     * (`ManualListItemData` が合成するのは published 判定と ability 判定だけ)。
     * 候補行と選択式が同じ行を指すことは `ManualRowFinishedVideoParityTest` が固定する。
     *
     * 一覧が行ごとに `currentSucceeded()` を呼ぶと N+1 になるため、eager load できる形を用意する
     * (`ManualListQueryCountTest` がクエリ数の行数非依存を固定する)。
     * 目録登録は `RenderArtifactSelectionInventory` (区分 EagerLoadCandidate)。
     *
     * @return HasOne<RenderJob, $this>
     */
    public function latestSucceededRender(): HasOne
    {
        return $this->hasOne(RenderJob::class)->ofMany(
            ['id' => 'max'],
            /** @param Builder<RenderJob> $query */
            function (Builder $query): void {
                $query->where('kind', RenderKind::Render->value)
                    ->where('status', JobStatus::Succeeded->value);
            }
        );
    }
}
