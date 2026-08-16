<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Enums\Manual\TakeStatus;
use App\Enums\Security\ExternalCallKind;
use App\Models\Cut;
use App\Models\Take;
use App\Models\VideoManual;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webmozart\Assert\Assert;

/**
 * サムネイル生成パイプライン (S3 GET → ffmpeg → S3 PUT → 条件付き UPDATE)。
 *
 * **結果の一回性** (AGENTS.md ドメイン固有規約 6):
 * - 保証側の機構は**条件付き UPDATE** (`where status=ready and thumbnail_path is null`)。
 *   0 行更新なら後続を行わない (= 先着したワーカーの結果を壊さない)
 * - 取り消せない外部副作用 (S3 PUT) の**直前**に所有権の再検証 (`stillEligible`) を置く。
 *   検証と PUT の間に自前の書き込みを挟まない
 * - S3 キーは take の主キーから**決定的**に組む。重複配送された 2 つのワーカーは
 *   同じキーへ同じ意味の PUT をするだけなので、敗者が勝者のオブジェクトを消す事故が起きない
 *   (= 0 行更新のとき**オブジェクトを削除してはならない**)
 * - work dir は **run() 実行ごとに一意** (take id + 実行ごとの UUID)。
 *   ローカル作業領域まで決定的にすると、重複配送された 2 つのワーカーが互いの入力・出力を壊す
 *
 * **ロック**: VideoManual 行ロックは取らない。本パイプラインは `cuts` /
 * `video_manuals.status` / `scenario_version` を 1 列も書かず (ドメイン固有規約 1 の対象外)、
 * 単一行の条件付き UPDATE で足りる。バックグラウンドジョブが新しいロック順序の辺を作らない。
 */
class TakeThumbnailPipeline
{
    public function __construct(
        private readonly TakeObjectStorage $storage,
        private readonly TakeThumbnailExtractor $extractor,
    ) {}

    public function run(int $takeId): void
    {
        $take = Take::query()->find($takeId);
        if ($take === null || ! $this->isEligible($take)) {
            return; // 行が消えた / 既に生成済み / ready でない = 正常な no-op (再配送の冪等短絡)
        }

        // ★ 実行ごとに一意な作業領域。take id だけで決定的にすると重複配送で互いを壊す
        $workDir = storage_path("app/capture/thumbnails/{$takeId}/".(string) Str::uuid());
        File::ensureDirectoryExists($workDir);

        try {
            $source = "{$workDir}/source";
            $thumbnail = "{$workDir}/thumbnail.jpg";

            // S3 GET は冪等な読み取り / ffmpeg はローカル CPU = どちらも preflight の対象ではない
            $this->storage->downloadToLocal($take->video_path, $source);
            $this->extractor->extract($source, $thumbnail, $take->material_type);

            $size = File::isFile($thumbnail) ? File::size($thumbnail) : 0;
            if ($size === 0) {
                return; // extract が成功を返した以上ここには来ない (防御的)
            }

            // ★ S3 キーは preflight の**前**に確定させる。key が使うのは take / cut / manual /
            //   project の識別子だけ (= 行の生存中に変化しない不変値) なので、preflight より前に
            //   組んでも値は変わらない。こうすることで **preflight と PUT の間には
            //   書き込みどころか読み取り (relation の遅延読み込み) も 1 つも無い**状態になる
            $key = $this->thumbnailKeyFor($take);

            // ★ preflight (裁定 AG-082 標準形 (2)): 取り消せない S3 PUT の直前で所有権を再検証する。
            //   ここから PUT までの間に自前の書き込みを挟まない
            if (! $this->stillEligible($take)) {
                return;
            }

            $this->storage->upload($thumbnail, $key, 'image/jpeg');

            // 結果の一回性: preflight と同じ述語を条件へ再掲する。
            // 0 行 = 先着したワーカーか状態変化 → 何もしない (**オブジェクトは消さない**。
            // キーが決定的なので、消すと勝者の実体を壊すことになる)
            Take::query()
                ->whereKey($take->getKey())
                ->where('status', TakeStatus::Ready->value)
                ->whereNull('thumbnail_path')
                ->update([
                    'thumbnail_path' => $key,
                    'thumbnail_size_bytes' => $size,
                ]);
        } finally {
            File::deleteDirectory($workDir); // 自分の作業領域だけを消す (他人のものには触れない)
        }
    }

    /**
     * S3 キー (take の主キーから決定的に組む。文字列加工を一切しない)。
     * cut / manual は relation 経由で解決する (payload 不信任)。
     *
     * ★ 材料は**すべて行の生存中に変化しない識別子**である (take が別の cut へ移る経路も、
     *   cut が別の manual へ移る経路も存在しない)。したがって preflight の前に確定させても、
     *   再取得後のスナップショットで組んだ場合と同じ値になる。
     */
    private function thumbnailKeyFor(Take $take): string
    {
        // relation は nullable 型を返す (外部キーは非 null だが型では表せない)。
        // 欠けていたら整合性異常なので fail-loud にする (キーを黙って崩さない)。
        $cut = $take->cut;
        Assert::isInstanceOf($cut, Cut::class, 'テイクの所属カットを解決できません');
        $manual = $cut->videoManual;
        Assert::isInstanceOf($manual, VideoManual::class, 'カットの所属マニュアルを解決できません');

        return sprintf(
            'projects/%d/manuals/%d/cuts/%d/takes/thumbnails/%d.jpg',
            $manual->project_id,
            $manual->id,
            $cut->id,
            $take->id,
        );
    }

    /** 生成してよい状態か (ready かつ未生成)。純粋な述語 = 再検証と入口検査で同じ式を使う */
    private function isEligible(Take $take): bool
    {
        return $take->status === TakeStatus::Ready && $take->thumbnail_path === null;
    }

    /**
     * 所有権の再検証 (preflight suppression)。Billing の `AttemptOwnershipPreflight::stillPending()`
     * と**同じ制御方式** (structured return = bool)。Manual の 2 パイプラインが使う
     * `JobOwnershipLostException` は「ジョブ行の JobStatus」を語彙に持つため、
     * ジョブ行を持たない本経路では流用しない (別物の概念を似ているからで統合しない)。
     *
     * @return bool PUT してよいか (false = 所有権喪失 → 呼び出し側が中断する)
     */
    private function stillEligible(Take $take): bool
    {
        // $take は型付き引数 (App\Models\Take) = 解決済みモデル由来の主キー
        $fresh = Take::query()->whereKey($take->getKey())->first();
        if ($fresh !== null && $this->isEligible($fresh)) {
            return true; // アーリーリターン (正常系)
        }

        // Manual / Billing と**同じ必須 7 キー**で観測する (集計語彙を 1 本に保つ)。
        // 本経路固有の追加キーは PII-free な thumbnail_present の 1 本だけ
        Log::warning('サムネイル生成: 所有権を失ったため S3 への書き込みを中止しました', [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => Take::class,
            'job_id' => $take->id,
            'expected_status' => TakeStatus::Ready->value,
            'actual_status' => $fresh?->status->value,
            'stage' => 'thumbnail_upload',
            'external_call' => ExternalCallKind::ObjectStoragePut->value,
            'thumbnail_present' => $fresh?->thumbnail_path !== null,
        ]);

        return false;
    }
}
