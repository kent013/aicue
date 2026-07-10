<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\CaptureTakeUpdateInput;
use App\Enums\Manual\ScenarioConflictType;
use App\Enums\Manual\TakeStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Exceptions\Manual\ScenarioConflictException;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * テイクの採用・並べ替え・コメント・削除・DL ACK (概念設計 D5, D6)。
 *
 * adopted_take_id (cuts 列) の書き込みは共有ロック規約 (AGENTS.md ドメイン固有規約 1) に従い
 * VideoManual 行ロック tx 内のみ。経路は ScenarioWritePathInventoryTest 検出 4 が
 * deny-by-default で固定する。
 */
class CaptureTakeService
{
    public function __construct(
        private readonly UploadTicketCodec $codec,
    ) {}

    /**
     * 採用 (doc/10 §10.3 adopt)。cross-cut は 404、ready 前 422、analyzing/rendering 中 409。
     */
    public function adopt(Project $project, VideoManual $manual, Cut $cut, Take $take): Cut
    {
        return DB::transaction(function () use ($project, $manual, $cut, $take): Cut {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            if ($lockedManual->status === VideoManualStatus::Rendering) {
                throw new ScenarioConflictException(ScenarioConflictType::Rendering, $lockedManual->scenario_version);
            }
            if ($lockedManual->status === VideoManualStatus::Analyzing) {
                throw new ScenarioConflictException(ScenarioConflictType::Analyzing, $lockedManual->scenario_version);
            }
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
            // 採用テイクは cut->takes() 経由でのみ解決 (cross-cut = 404。フェーズ1 の将来必須条件)
            /** @var Take $lockedTake */
            $lockedTake = $lockedCut->takes()->whereKey($take->id)->firstOrFail();
            if ($lockedTake->status !== TakeStatus::Ready) {
                throw ValidationException::withMessages(['take' => ['このテイクはまだ採用できません（処理中/失敗）。']]);
            }
            $lockedCut->forceFill(['adopted_take_id' => $lockedTake->id])->save();

            return $lockedCut;
        });
    }

    /**
     * コメント・並べ替え (position = cut 内 0 始まり)。sort_order はサーバ再採番。
     */
    public function update(Project $project, VideoManual $manual, Cut $cut, Take $take, CaptureTakeUpdateInput $input): Take
    {
        return DB::transaction(function () use ($project, $manual, $cut, $take, $input): Take {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
            /** @var Take $lockedTake */
            $lockedTake = $lockedCut->takes()->whereKey($take->id)->firstOrFail();

            if ($input->hasComment) {
                $lockedTake->fill(['comment' => $input->comment])->save();
            }
            if ($input->position !== null) {
                $this->reorderWithinCut($lockedCut, $lockedTake, $input->position);
                $lockedTake->refresh();
            }

            return $lockedTake;
        });
    }

    /**
     * 削除。DL 済み (downloaded_at 非 null) は 422。採用中なら null 化 + S3 削除 Job (tx 成功後)。
     */
    public function delete(Project $project, VideoManual $manual, Cut $cut, Take $take): void
    {
        $paths = DB::transaction(function () use ($project, $manual, $cut, $take): array {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
            /** @var Take $lockedTake */
            $lockedTake = $lockedCut->takes()->whereKey($take->id)->firstOrFail();
            if ($lockedTake->downloaded_at !== null) {
                throw ValidationException::withMessages(['take' => ['ダウンロード済みのテイクは削除できません。']]);
            }
            if ($lockedCut->adopted_take_id === $lockedTake->id) {
                // §10.8-4: 採用テイクが消えたら null 化 (DB nullOnDelete は最終防波堤)
                $lockedCut->forceFill(['adopted_take_id' => null])->save();
            }
            /** @var list<string> $paths */
            $paths = array_values(array_filter([$lockedTake->video_path, $lockedTake->thumbnail_path]));
            $lockedTake->delete();
            $this->renumber($lockedCut);

            return $paths;
        });

        if ($paths !== []) {
            DeleteTakeObjectsJob::dispatch($paths); // tx 成功後に media queue へ
        }
    }

    /**
     * DL 済み ACK (冪等。初回のみ打刻)。概念設計 D6: 署名 ACK トークン方式。
     * 詳細 GET が採用テイクの署名 DL URL と同時に発行した DownloadAckClaims
     * (take_id + user_id + 期限。Crypt 封緘・DL URL と同 TTL) を検証する:
     * - 復号不能 / 期限切れ / claims.take_id !== route take / claims.user_id !== 現ユーザ → 422
     * - 検証成功: downloaded_at 未設定なら now() を打刻 (再送は no-op = 冪等)
     *
     * 「現在採用中か」の動的検証はしない (DL→ACK 間の採用変更 race を排除。
     * ACK トークンは採用テイクの DL URL としか一緒に発行されないため濫用も不能)。
     */
    public function markDownloaded(User $user, Project $project, VideoManual $manual, Cut $cut, Take $take, string $ackToken): Take
    {
        $claims = $this->codec->openAck($ackToken);
        if ($claims === null || $claims->takeId !== $take->id || $claims->userId !== $user->id) {
            throw ValidationException::withMessages([
                'ack_token' => ['ダウンロード確認トークンが無効です。'],
            ]);
        }

        return DB::transaction(function () use ($project, $manual, $cut, $take): Take {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
            /** @var Take $lockedTake */
            $lockedTake = $lockedCut->takes()->whereKey($take->id)->firstOrFail();
            if ($lockedTake->downloaded_at === null) {
                $lockedTake->forceFill(['downloaded_at' => now()])->save();
            }

            return $lockedTake;
        });
    }

    /** cut 内の並べ替え (対象を position に挿入し 0..n-1 でサーバ再採番)。行ロック下で呼ぶ */
    private function reorderWithinCut(Cut $lockedCut, Take $target, int $position): void
    {
        /** @var list<Take> $ordered */
        $ordered = $lockedCut->takes()
            ->whereKeyNot($target->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
        $position = min($position, count($ordered));
        array_splice($ordered, $position, 0, [$target]);
        foreach ($ordered as $index => $take) {
            if ($take->sort_order !== $index) {
                $take->forceFill(['sort_order' => $index])->save();
            }
        }
    }

    /** 削除後の詰め直し (0..n-1)。行ロック下で呼ぶ */
    private function renumber(Cut $lockedCut): void
    {
        /** @var list<Take> $ordered */
        $ordered = $lockedCut->takes()->orderBy('sort_order')->orderBy('id')->get()->all();
        foreach ($ordered as $index => $take) {
            if ($take->sort_order !== $index) {
                $take->forceFill(['sort_order' => $index])->save();
            }
        }
    }
}
