<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Storage\S3OperationSurface;
use App\Models\Cut;
use App\Models\Project;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use Carbon\CarbonImmutable;
use Tests\Support\Storage\S3SurfaceInventory;

/*
 * 撮影テイク登録の web 経路が `Bulk` 面の S3 操作を呼ばないことを固定する (T126 施策 7)。
 *
 * ★「Bulk を web から呼ばない」は**規約であって機械証明ではない** (呼び出しグラフ解析が要る)。
 *   **既存の web 経路については behavioral に固定する**、が本テストの位置づけである。
 * ★**保証範囲を誇張しない**: 固定するのは**登録成功パス**である。
 *   三点照合の不一致など**異常系では `delete()` (Bulk 面) を意図的に呼ぶ**
 *   (置かれた不正オブジェクトの後始末)。これは「失敗を返す側」なので web の待ちを
 *   引き延ばす主経路にはならない、という判断であり、本テストはその判断を覆さない。
 */

test('テイク登録エンドポイントは BoundedControl / NoObjectRequest 面しか呼ばない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
    $reservation->refresh(); // DB 保存後の秒精度 expires_at で claims を作る
    $ticket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation));

    $spy = new class extends TakeObjectStorage
    {
        /** @var list<string> 呼び出し順を保つ (意図しない追加呼び出しの診断用) */
        public array $calls = [];

        public ?ObjectMetadataData $headResult = null;

        public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
        {
            $this->calls[] = __FUNCTION__;

            return new PresignedUploadData(url: 'https://spy.invalid/put', headers: [], expiresAt: $expiresAt);
        }

        public function headObject(string $path): ?ObjectMetadataData
        {
            $this->calls[] = __FUNCTION__;

            return $this->headResult;
        }

        public function temporaryPlaybackUrl(string $path): string
        {
            $this->calls[] = __FUNCTION__;

            return 'https://spy.invalid/get';
        }

        public function delete(string $path): void
        {
            $this->calls[] = __FUNCTION__;
        }

        public function exists(string $path): bool
        {
            $this->calls[] = __FUNCTION__;

            return true;
        }

        public function downloadToLocal(string $path, string $localPath): void
        {
            $this->calls[] = __FUNCTION__;
        }

        public function upload(string $localPath, string $path, string $contentType): void
        {
            $this->calls[] = __FUNCTION__;
        }

        public function temporaryThumbnailUrl(string $path): string
        {
            $this->calls[] = __FUNCTION__;

            return 'https://spy.invalid/thumbnail';
        }
    };
    $spy->headResult = new ObjectMetadataData(
        contentLength: $reservation->size_bytes,
        contentType: $reservation->content_type,
        checksumSha256: $reservation->checksum_sha256,
    );
    $this->app->instance(TakeObjectStorage::class, $spy);

    $response = $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes",
        [
            'ticket' => $ticket,
            'client_take_id' => $reservation->client_take_id,
            'duration_ms' => 5_000,
            'captured_at' => now()->toIso8601String(),
        ],
    );

    $response->assertCreated();

    // ★spy の脆さ対策: 親に public method が増えたら気づく (未 override があれば fail)。
    //   interface 抽出は本タスクの目的 (timeout の有限化) と無関係なので今回は行わない
    //   (AGENTS.md 思考原則 2)。代わりに「取りこぼしを検出する」側で担保する。
    $inventoryMethods = array_keys(S3SurfaceInventory::all()[TakeObjectStorage::class]);
    $overridden = [];
    foreach ((new ReflectionClass($spy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== TakeObjectStorage::class) {
            $overridden[] = $method->getName();
        }
    }
    expect(array_values(array_diff($inventoryMethods, $overridden)))->toBe(
        [],
        'spy が override していない public method がある (親にメソッドが増えた可能性)',
    );

    $bulkMethods = S3SurfaceInventory::methodsWithSurface(TakeObjectStorage::class, S3OperationSurface::Bulk);

    expect($bulkMethods)->not->toBeEmpty();  // 目録側の空振り防止
    expect($spy->calls)->not->toBeEmpty();   // 呼び出し記録の空振り防止
    expect(array_values(array_intersect($spy->calls, $bulkMethods)))->toBe(
        [],
        'テイク登録の web 同期経路が Bulk 面の S3 操作を呼びました (呼び出し順: '.implode(', ', $spy->calls).')',
    );
});
