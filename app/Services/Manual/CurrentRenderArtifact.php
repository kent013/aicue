<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Webmozart\Assert\Assert;

/**
 * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式**
 * (playback / download / 詳細画面 props / 一覧行 props)。
 *
 * 入口は 2 つあるが**規則は 1 つ**である:
 *   - currentSucceeded()            … 1 件表示・endpoint 用 (クエリを 1 本撃つ)
 *   - fromLoadedRenderCandidate()   … 一覧用 (eager load 済みの候補行から選ぶ。クエリを撃たない)
 * どちらも private receivable() を通る。
 *
 * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
 * **同じ世代定義**である: 実体が残るのは「同 manual・同 kind の最新 succeeded」だけなので、
 * 最新 succeeded の output_path が NULL (= 生成に失敗した / 掃除された) なら
 * **旧世代へフォールバックしない** (削除済みオブジェクトの署名 URL を出さないため)。
 *
 * **持たない責務**: published 判定 (完成動画の公開状態) と ability 判定は呼び出し側にある。
 * ここは「どの行か」だけを答える (名前が示す役割を超えない)。読み取り専用。
 */
final class CurrentRenderArtifact
{
    /** 同 manual・同 kind で現在受け取れる succeeded job (無ければ null) */
    public static function currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob
    {
        $job = $manual->renderJobs()
            ->where('kind', $kind->value)
            ->where('status', JobStatus::Succeeded->value)
            ->latest('id')
            ->first();

        return self::receivable($job);
    }

    /**
     * eager load 済みの候補行 (VideoManual::latestSucceededRender = kind=render ∧ succeeded の
     * 最新 1 行) から、現在受け取れる完成動画を選ぶ。**一覧専用の入口で追加クエリを撃たない**
     * (行数に比例したクエリを増やさないという一覧の前提を守るため)。
     * 候補 relation が kind=render 固定なので kind 引数は取らない
     * (取れるように見せると「一覧から preview を選べる」という誤読を生む)。
     *
     * **未ロードでの呼び出しは例外**にする。名前が「ロード済みの候補行から」と約束している一方、
     * 素直に読むと lazy load で黙って N+1 になるためである。呼び出し側が eager load を外したら
     * **その場で落ちる** (一覧のクエリ数が行数に比例して増える退行を、遅い本番ではなく
     * テストで検出する)。
     */
    public static function fromLoadedRenderCandidate(VideoManual $manual): ?RenderJob
    {
        Assert::true(
            $manual->relationLoaded('latestSucceededRender'),
            'latestSucceededRender を eager load してから呼ぶこと (一覧の N+1 防止)',
        );

        return self::receivable($manual->latestSucceededRender);
    }

    /**
     * 実体が残っている行だけを返す共通規則。
     * output_path が NULL の最新 succeeded は「生成に失敗した / 掃除された」であり、
     * 旧世代へフォールバックしない (削除済みオブジェクトの署名 URL を出さないため)。
     */
    private static function receivable(?RenderJob $job): ?RenderJob
    {
        if ($job === null || $job->output_path === null) {
            return null;
        }

        return $job;
    }
}
