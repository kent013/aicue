<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\ScenarioDocumentData;
use App\DataTransferObjects\Manual\ScenarioPointInput;
use App\DataTransferObjects\Manual\ScenarioSaveInput;
use App\DataTransferObjects\Manual\ScenarioStepInput;
use App\Enums\Manual\CutType;
use App\Enums\Manual\ScenarioConflictType;
use App\Enums\Manual\VideoManualStatus;
use App\Exceptions\Manual\ScenarioConflictException;
use App\Models\Cut;
use App\Models\Project;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Webmozart\Assert\Assert;

/**
 * シナリオ (Cut 群) の document 単位保存 (doc/09 §9.4 / doc/10 §10.8-2,5,6)。
 *
 * シナリオ整合の共有不変条件 (本サービスが最初の準拠実装。後続の AI 解析 materialize /
 * RenderJob 状態遷移 / テイク採用 API も同じ規約に従う。docs/architecture.md 参照):
 *   「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
 *     対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
 *
 * - 直列化点: VideoManual 行ロック (親 relation 経由再解決で「子は親に属する」も同時担保)
 * - 409: rendering / analyzing 中、または expected_version 不一致 (ScenarioConflictException)
 * - 404: payload に他 manual の cut id 混入 (ModelNotFoundException。存在を漏らさない)
 * - 422: payload 内 id 重複、既存 cut の階層/型不一致 (v1 は階層/型変更禁止)
 */
class ScenarioService
{
    public function save(Project $project, VideoManual $manual, ScenarioSaveInput $input): ScenarioDocumentData
    {
        return DB::transaction(function () use ($project, $manual, $input): ScenarioDocumentData {
            // 1. 行ロック + 親子再解決 (cross-project は 404)
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            // 2. 状態 guard (§10.8-6。analyzing は AI materialize との clobber 防止)
            if ($locked->status === VideoManualStatus::Rendering) {
                throw new ScenarioConflictException(ScenarioConflictType::Rendering, $locked->scenario_version);
            }
            if ($locked->status === VideoManualStatus::Analyzing) {
                throw new ScenarioConflictException(ScenarioConflictType::Analyzing, $locked->scenario_version);
            }

            // 3. 楽観ロック照合 (§10.8-2)
            if ($locked->scenario_version !== $input->expectedVersion) {
                throw new ScenarioConflictException(ScenarioConflictType::VersionMismatch, $locked->scenario_version);
            }

            // 4. 既存 cut 集合のロード (step / point を事前分離し、payload 位置と厳密照合)
            $cuts = $locked->cuts()->get();
            /** @var Collection<int, Cut> $existingSteps */
            $existingSteps = $cuts->toBase()->where('type', CutType::Step)->keyBy('id');
            /** @var Collection<int, Cut> $existingPoints */
            $existingPoints = $cuts->toBase()->where('type', CutType::Point)->keyBy('id');
            $this->assertPayloadIds($existingSteps, $existingPoints, $input);

            // 5. reconcile (2 段階 + 削除)。$changed で「意味のある実変更」を追跡する
            $changed = false;
            $keptIds = [];
            foreach ($input->steps as $stepIndex => $stepInput) {
                $step = $this->upsertCut($locked, $existingSteps, $stepInput, CutType::Step, null, $stepIndex, $changed);
                $keptIds[] = $step->id;
                foreach ($stepInput->points as $pointIndex => $pointInput) {
                    $point = $this->upsertCut($locked, $existingPoints, $pointInput, CutType::Point, $step->id, $pointIndex, $changed);
                    $keptIds[] = $point->id;
                }
            }

            // 段階 3: payload に現れなかった既存 cut を削除 (配下 Take は FK cascade)。
            // bulk delete でなく each->delete() (将来 Cut に deleting イベントが付いても素通りしない。
            // 件数は有界: steps≤100 × points≤20 のため chunk 化はしない)
            $removed = $cuts->pluck('id')->diff($keptIds);
            if ($removed->isNotEmpty()) {
                $locked->cuts()->whereIn('id', $removed->all())->get()->each->delete();
                $changed = true;
            }

            // 6. version は成功保存で常に +1 (§10.8-2 の確定契約)。実変更時のみ状態遷移
            $locked->forceFill(['scenario_version' => $locked->scenario_version + 1]);
            if ($changed) {
                $this->transitionStatusAfterEdit($locked, hasCuts: $keptIds !== []);
            }
            $locked->save();

            return ScenarioDocumentData::fromManual($locked);
        });
    }

    /**
     * AI 解析結果の materialize (共有ロック規約の第 2 の書き込み経路。概念設計 §5)。
     *
     * **ロック済み前提メソッド**: transaction / lockForUpdate は呼び出し側 (AnalysisPipeline::
     * finalize の terminal tx) が最外層で張る。本メソッドは内側 transaction を張らない
     * (transaction/lock の層を 1 箇所に統一しロック順逆転を構造的に防ぐ)。
     * 前提の担保は 2 層 (PHPDoc 前提だけに依存しない):
     *  1. 呼び出し経路の構造的限定: 呼び出し元は AnalysisPipeline のみ
     *     (ScenarioWritePathInventoryTest が deny-by-default で機械検証する)
     *  2. runtime 検査 (defensive): tx 外呼び出し / analyzing 以外は LogicException
     *     (terminal tx ごと rollback → failJob)
     *
     * - 既存 cuts 全削除 → 生成ツリー挿入 (再解析は全置換)。sort_order/parent/type はサーバ導出
     * - version+1 と status(analyzing→ready) を cuts と同一 tx で反映 (共有ロック規約)
     *
     * @param  list<ScenarioStepInput>  $steps
     */
    public function materializeIntoLockedManual(VideoManual $lockedManual, array $steps): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('materialize はロック済みトランザクション内からのみ呼び出せます');
        }
        if ($lockedManual->status !== VideoManualStatus::Analyzing) {
            throw new LogicException('materialize は analyzing 中のみ実行できます');
        }

        // 全置換 (save() と同じ理由で bulk delete を避ける。配下 Take は FK cascade)
        $lockedManual->cuts()->get()->each->delete();

        $changed = true; // 生成は常に実変更 (upsertCut の isDirty 追跡は新規行で必ず true)
        foreach ($steps as $stepIndex => $stepInput) {
            /** @var Collection<int, Cut> $noExisting */
            $noExisting = new Collection;
            $step = $this->upsertCut($lockedManual, $noExisting, $stepInput, CutType::Step, null, $stepIndex, $changed);
            foreach ($stepInput->points as $pointIndex => $pointInput) {
                $this->upsertCut($lockedManual, $noExisting, $pointInput, CutType::Point, $step->id, $pointIndex, $changed);
            }
        }

        $lockedManual->forceFill([
            'scenario_version' => $lockedManual->scenario_version + 1,
            'status' => VideoManualStatus::Ready,
        ])->save();
    }

    /**
     * 1 cut の update/create。本文は fill、parent/sort/type はサーバ導出値の forceFill 明示代入。
     * 新規は relation 経由 make (video_manual_id を payload から受けない)。
     * 保存前の isDirty() 検査で $changed を更新する (意味差分 = 実変更判定。
     * サーバ導出値の変化 = 並べ替えも実変更になる)。
     *
     * @param  Collection<int, Cut>  $existing
     */
    private function upsertCut(
        VideoManual $locked,
        Collection $existing,
        ScenarioStepInput|ScenarioPointInput $input,
        CutType $type,
        ?int $parentCutId,
        int $sortOrder,
        bool &$changed,
    ): Cut {
        $cut = $input->id === null ? $locked->cuts()->make() : $existing->get($input->id);
        // 既存 id は assertPayloadIds で存在検証済み
        Assert::isInstanceOf($cut, Cut::class);

        $cut->fill([
            'scene' => $input->scene,
            'shot_type' => $input->shotType,
            'shooting_point' => $input->shootingPoint,
            'narration' => $input->narration,
            'subtitle_primary' => $input->subtitlePrimary,
            'subtitle_secondary' => $input->subtitleSecondary,
            'material_type' => $input->materialType,
            'static_display_seconds' => $input->staticDisplaySeconds,
        ]);
        $cut->forceFill([
            'type' => $type,
            'parent_cut_id' => $parentCutId,
            'sort_order' => $sortOrder,
        ]);

        if (! $cut->exists || $cut->isDirty()) {
            $changed = true;
        }
        $cut->save();

        return $cut;
    }

    /**
     * payload id の検証 (step / point の既存集合を分離して厳密照合):
     * - 重複 id → ValidationException (422)
     * - 当該 manual に無い id → ModelNotFoundException (404。tenant キー不信・存在を漏らさない)
     * - step 位置の id が existingSteps に無く existingPoints にある (またはその逆)
     *   → ValidationException (422。v1 は階層/型変更禁止)
     *
     * @param  Collection<int, Cut>  $existingSteps
     * @param  Collection<int, Cut>  $existingPoints
     */
    private function assertPayloadIds(Collection $existingSteps, Collection $existingPoints, ScenarioSaveInput $input): void
    {
        /** @var array<int, true> $seen */
        $seen = [];
        foreach ($input->steps as $stepIndex => $stepInput) {
            if ($stepInput->id !== null) {
                $this->assertPayloadId(
                    $stepInput->id,
                    $existingSteps,
                    $existingPoints,
                    $seen,
                    "steps.{$stepIndex}.id",
                );
            }
            foreach ($stepInput->points as $pointIndex => $pointInput) {
                if ($pointInput->id !== null) {
                    $this->assertPayloadId(
                        $pointInput->id,
                        $existingPoints,
                        $existingSteps,
                        $seen,
                        "steps.{$stepIndex}.points.{$pointIndex}.id",
                    );
                }
            }
        }
    }

    /**
     * 1 id の照合。$expected = その位置に許される既存集合、$opposite = 逆階層の既存集合。
     *
     * @param  Collection<int, Cut>  $expected
     * @param  Collection<int, Cut>  $opposite
     * @param  array<int, true>  $seen
     */
    private function assertPayloadId(int $id, Collection $expected, Collection $opposite, array &$seen, string $key): void
    {
        if (isset($seen[$id])) {
            throw ValidationException::withMessages([
                $key => ['同じカット id が複数回指定されています。'],
            ]);
        }
        $seen[$id] = true;

        if ($expected->has($id)) {
            return;
        }
        if ($opposite->has($id)) {
            // 当該 manual には属するが階層/型が不一致 (v1 は変更禁止)
            throw ValidationException::withMessages([
                $key => ['カットの階層 (手順/急所) は変更できません。'],
            ]);
        }

        // 当該 manual に属さない id は存在を漏らさない (404)
        throw (new ModelNotFoundException)->setModel(Cut::class, [$id]);
    }

    /**
     * 実変更後の状態遷移:
     * - published → ready (§10.8-6: 完成動画は要再合成)
     * - draft → ready (cuts が 1 件以上になったとき。§10.2「ready = シナリオ確定・編集/撮影可」。
     *   自作シナリオ経路で撮影フェーズへ進めるようにする v1 設計判断)
     * ready→ready / cut 全削除での後退遷移は行わない (過剰な状態機械を作らない)。
     */
    private function transitionStatusAfterEdit(VideoManual $locked, bool $hasCuts): void
    {
        if ($locked->status === VideoManualStatus::Published) {
            $locked->forceFill(['status' => VideoManualStatus::Ready]);

            return;
        }
        if ($locked->status === VideoManualStatus::Draft && $hasCuts) {
            $locked->forceFill(['status' => VideoManualStatus::Ready]);
        }
    }
}
