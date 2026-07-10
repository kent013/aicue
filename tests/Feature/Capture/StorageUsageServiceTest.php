<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\StorageUsageService;
use Illuminate\Support\Str;

/*
 * StorageUsageService (施策3): 容量 Quota の使用量集計 (§10.8-4 の真実源)。
 * bytes_used = takes.size_bytes の org 合計、bytes_pending = pending 未失効 + verifying 全件。
 */

/**
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function storageUsageContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();

    return [$organization, $owner, $project, $manual, $cut];
}

test('bytesUsed は自 org の takes.size_bytes 合計のみ (他 org を混ぜても漏れない)', function (): void {
    [$organization, , , , $cut] = storageUsageContext();
    Take::factory()->forCut($cut)->create(['size_bytes' => 100]);
    Take::factory()->forCut($cut)->create(['size_bytes' => 250, 'client_take_id' => (string) Str::ulid()]);

    // 他 org のテイク (集計に混ざってはいけない)
    [, , , , $otherCut] = storageUsageContext();
    Take::factory()->forCut($otherCut)->create(['size_bytes' => 9_999]);

    expect(app(StorageUsageService::class)->bytesUsed($organization))->toBe(350);
});

test('bytesPending は「pending 未失効 + verifying 全件」のみ計上する', function (): void {
    [$organization, , , , $cut] = storageUsageContext();

    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => 100]);                 // pending 未失効 → 計上
    TakeUploadReservation::factory()->forCut($cut)->verifying()->create(['size_bytes' => 200]);    // verifying → 計上 (claim 中の占有継続)
    TakeUploadReservation::factory()->forCut($cut)->expired()->create(['size_bytes' => 400]);      // 期限切れ pending → 不算入
    TakeUploadReservation::factory()->forCut($cut)->released()->create(['size_bytes' => 800]);     // released → 不算入
    TakeUploadReservation::factory()->forCut($cut)->completed()->create(['size_bytes' => 1_600]);  // completed → 不算入 (takes 側が真実源)

    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe(300);
});

test('bytesPending は org 分離される', function (): void {
    [$organization, , , , $cut] = storageUsageContext();
    [, , , , $otherCut] = storageUsageContext();
    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => 100]);
    TakeUploadReservation::factory()->forCut($otherCut)->create(['size_bytes' => 9_999]);

    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe(100);
});

test('occupiedBytes = bytes_used + bytes_pending (overflow は PHP_INT_MAX に丸める)', function (): void {
    [$organization, , , , $cut] = storageUsageContext();
    Take::factory()->forCut($cut)->create(['size_bytes' => 100]);
    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => 200]);

    expect(app(StorageUsageService::class)->occupiedBytes($organization))->toBe(300);

    // overflow 側の丸め (合計が PHP_INT_MAX を跨ぐ)
    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => PHP_INT_MAX - 150]);
    expect(app(StorageUsageService::class)->occupiedBytes($organization))->toBe(PHP_INT_MAX);
});

test('occupiedBytes は pending → used の順で読む (finalize 競合時に二重計上=安全側へ倒す)', function (): void {
    // READ COMMITTED 下で used→pending の順だと、2 読取の隙間に verifying 予約の
    // finalize (予約消滅 + Take 出現) がコミットした場合に「どちらにも数えられず」
    // Quota を過少計上でバイパスできる。pending→used なら同じ競合は二重計上に倒れる。
    [$organization] = storageUsageContext();

    $service = new class extends StorageUsageService
    {
        /** @var list<string> */
        public array $readOrder = [];

        public function bytesPending(Organization $organization): int
        {
            $this->readOrder[] = 'pending';

            return parent::bytesPending($organization);
        }

        public function bytesUsed(Organization $organization): int
        {
            $this->readOrder[] = 'used';

            return parent::bytesUsed($organization);
        }
    };

    $service->occupiedBytes($organization);

    expect($service->readOrder)->toBe(['pending', 'used']);
});
