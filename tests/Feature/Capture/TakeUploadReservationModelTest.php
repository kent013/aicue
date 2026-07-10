<?php

declare(strict_types=1);

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use Illuminate\Support\Carbon;

/*
 * TakeUploadReservation (施策1): Factory 生成・casts・relation・(cut_id, client_take_id) 検索。
 * 保護キー (cut_id / organization_id) の fillable 不含は MassAssignmentSafetyTest が自動検査。
 */

test('Factory が cut の親 chain から organization_id を導出して生成する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();

    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();

    expect($reservation->cut_id)->toBe($cut->id);
    expect($reservation->organization_id)->toBe($organization->id);
    expect($reservation->status)->toBe(TakeUploadReservationStatus::Pending);
    expect($reservation->expires_at)->toBeInstanceOf(Carbon::class);
    expect($reservation->cut)->toBeInstanceOf(Cut::class);
    expect($reservation->organization)->toBeInstanceOf(Organization::class);
    expect(strlen($reservation->checksum_sha256))->toBe(44);
});

test('cut relation (uploadReservations) から (cut_id, client_take_id) で予約を検索できる', function (): void {
    $cut = Cut::factory()->create();
    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
    TakeUploadReservation::factory()->forCut($cut)->create(); // 別 client_take_id

    $found = $cut->uploadReservations()
        ->where('client_take_id', $reservation->client_take_id)
        ->first();

    expect($found?->id)->toBe($reservation->id);
    expect($cut->uploadReservations()->count())->toBe(2);
});

test('status state (verifying / completed / released / expired) が反映される', function (): void {
    $cut = Cut::factory()->create();

    $verifying = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
    $completed = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
    $released = TakeUploadReservation::factory()->forCut($cut)->released()->create();
    $expired = TakeUploadReservation::factory()->forCut($cut)->expired()->create();

    expect($verifying->status)->toBe(TakeUploadReservationStatus::Verifying);
    expect($completed->status)->toBe(TakeUploadReservationStatus::Completed);
    expect($released->status)->toBe(TakeUploadReservationStatus::Released);
    expect($expired->expires_at->isPast())->toBeTrue();
});

test('TakeFactory::downloaded() state で downloaded_at が打刻される', function (): void {
    $take = Take::factory()->downloaded()->create();

    expect($take->downloaded_at)->not->toBeNull();
    expect($take->fresh()?->downloaded_at)->not->toBeNull();
});
