<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\DataTransferObjects\Capture\CaptureCutData;
use App\DataTransferObjects\Capture\CaptureTakeData;
use App\Enums\Manual\TakeStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Capture\MarkTakeDownloadedRequest;
use App\Http\Requests\Capture\StoreCaptureTakeRequest;
use App\Http\Requests\Capture\UpdateCaptureTakeRequest;
use App\Http\Resources\Capture\CaptureCutResource;
use App\Http\Resources\Capture\CaptureTakeResource;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\CaptureTakeService;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\TakeRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Webmozart\Assert\Assert;

/**
 * テイクの登録・管理 (doc/10 §10.3)。同一オリジン XHR (JSON 応答)。
 *
 * nested route の URL 整合は 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
 * 2. {manual} ∈ {project}, {cut} ∈ {manual}, {take} ∈ {cut} (Route::scopeBindings())
 */
class CaptureTakeController extends Controller
{
    use ResolvesCurrentOrganization;

    /** テイク登録 (チケット検証 + HeadObject 照合 + 冪等)。201 = 新規 / 200 = 冪等再送 */
    public function store(
        StoreCaptureTakeRequest $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        TakeRegistrationService $registration,
    ): JsonResponse {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [Take::class, $project]);

        $result = $registration->register($project, $manual, $cut, $request->toTakeRegistrationInput());

        return CaptureTakeResource::make(CaptureTakeData::fromTake($result->take))
            ->response($request)
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }

    /** コメント・並べ替え */
    public function update(
        UpdateCaptureTakeRequest $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        Take $take,
        CaptureTakeService $takes,
    ): CaptureTakeResource {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $take);

        $updated = $takes->update($project, $manual, $cut, $take, $request->toCaptureTakeUpdateInput());

        return CaptureTakeResource::make(CaptureTakeData::fromTake($updated));
    }

    /** 削除 (DL 済みは 422。採用中なら null 化 + S3 削除 Job) */
    public function destroy(
        Request $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        Take $take,
        CaptureTakeService $takes,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $take);

        $takes->delete($project, $manual, $cut, $take);

        return response()->noContent();
    }

    /** 採用 (adopted_take_id は VideoManual 行ロック tx 内でのみ書く) */
    public function adopt(
        Request $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        Take $take,
        CaptureTakeService $takes,
    ): CaptureCutResource {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('adopt', $take);

        $adoptedCut = $takes->adopt($project, $manual, $cut, $take);

        return CaptureCutResource::make(CaptureCutData::fromCut($adoptedCut));
    }

    /** DL 済み ACK (署名 ACK トークン検証。冪等) */
    public function markDownloaded(
        MarkTakeDownloadedRequest $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        Take $take,
        CaptureTakeService $takes,
    ): CaptureTakeResource {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('markDownloaded', $take);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);
        $acked = $takes->markDownloaded(
            $user,
            $project,
            $manual,
            $cut,
            $take,
            $request->string('ack_token')->value(),
        );

        return CaptureTakeResource::make(CaptureTakeData::fromTake($acked));
    }

    /**
     * テイク単体のプレビュー再生 (302 → S3 署名 URL)。撮影者/編集者 (capture ability)。
     * doc/04 テイクプレビュー / doc/05 個別再生。採用前テイクも再生できる (adopted 限定でない)。
     *
     * nested route 整合 (認可より前に 404):
     * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
     * 2. {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は Route::scopeBindings()
     *
     * 302 応答は Cache-Control: no-store, private (期限付き署名 URL の再利用防止)。
     * ※ これはアプリの 302 応答のみを制御し、リダイレクト先ストレージの動画本体の
     *   cache までは保証しない (動画本体の非キャッシュは v1 要件外)。
     */
    public function playback(
        Request $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        Take $take,
        TakeObjectStorage $storage,
    ): RedirectResponse {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('preview', $take);

        // 再生可能条件: ready のみ。uploading/processing/failed は 404 とし、
        // 内部状態 (処理中/失敗) を存在有無として漏らさない (状態秘匿)
        if ($take->status !== TakeStatus::Ready) {
            abort(404);
        }

        // video_path は @property string (非 null カラム) = 型絞り込み問題なし
        return redirect()
            ->away($storage->temporaryPlaybackUrl($take->video_path))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
