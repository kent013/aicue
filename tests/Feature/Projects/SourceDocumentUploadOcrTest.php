<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\SourceDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Manual\MinimalImageFixture;

/*
 * 画像・スキャン SOP の OCR 対応 (施策 1/11):
 * - フラグ true 時のみ jpg/png アップロードが成功する (先に赤くする: フラグ既定 false で 422)
 * - HEIC は引き続き拒否され、文言に「JPEG / PNG で保存し直す」と出る
 * - 画像専用の容量上限は sniff MIME だけで判定される (偽装で迂回できない)
 * - webp/gif は引き続き拒否される (回帰)
 * - 公開面 (FormRequest / Service / Inertia Props) の一貫性
 * - 画像は 1 手順書につき 1 枚まで
 */

/**
 * @return array{Organization, User, Project, VideoManual}
 */
function ocrUploadContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    return [$organization, $owner, $project, $manual];
}

function fakeJpegFile(string $name = 'sop.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, MinimalImageFixture::jpeg(10, 10));
}

function fakePngFile(string $name = 'sop.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, MinimalImageFixture::png(10, 10));
}

test('先に赤くする: フラグ既定 (false) では jpg アップロードが 422 になる', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', false);
    [, $owner, $project, $manual] = ocrUploadContext();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeJpegFile()],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    expect(SourceDocument::query()->count())->toBe(0);
});

test('フラグ true では jpg/png アップロードが成功する', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    [, $owner, $project, $manual] = ocrUploadContext();

    $this->actingAs($owner)->post(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeJpegFile()],
    )->assertRedirect();

    $document = SourceDocument::query()->firstOrFail();
    expect($document->mime)->toBe('image/jpeg');
});

test('フラグ true でも HEIC は引き続き 422 で拒否される', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    [, $owner, $project, $manual] = ocrUploadContext();

    // finfo は HEIC を image/heic (または application/octet-stream) と判定する。
    // ここでは許可集合に無いことを固定する目的で明示的に mime 指定する。
    $heic = UploadedFile::fake()->create('sop.heic', 10, 'image/heic');

    $response = $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $heic],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    // 拒否文言に「JPEG・PNG で保存し直す」という次アクションが示されること
    $response->assertJsonFragment(['document' => ['対応していないファイル形式です。'
        .'PDF・Excel・テキスト形式、または JPEG・PNG の画像でアップロードし直してください。']]);
});

test('新規マニュアル作成時 (StoreVideoManualRequest) もフラグに応じて jpg 受理可否が変わる', function (): void {
    Storage::fake();
    [, $owner, $project] = ocrUploadContext();

    config()->set('manual.ocr_analysis_enabled', false);
    $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
        'title' => 'フラグ無効テスト',
        'category' => null,
        'document' => fakeJpegFile('create-false.jpg'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['document']);

    config()->set('manual.ocr_analysis_enabled', true);
    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => 'フラグ有効テスト',
        'category' => null,
        'document' => fakeJpegFile('create-true.jpg'),
    ])->assertRedirect();

    expect(SourceDocument::query()->where('mime', 'image/jpeg')->count())->toBe(1);
});

test('webp/gif はフラグ true でも引き続き拒否される (回帰)', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    [, $owner, $project, $manual] = ocrUploadContext();

    foreach (['image/webp' => 'sop.webp', 'image/gif' => 'sop.gif'] as $mime => $name) {
        $file = UploadedFile::fake()->create($name, 10, $mime);
        $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
            ['document' => $file],
        )->assertUnprocessable()->assertJsonValidationErrors(['document']);
    }
    expect(SourceDocument::query()->count())->toBe(0);
});

test('画像の容量上限超過 (source_document_image_max_bytes 基準) は 422', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    config()->set('manual.source_document_image_max_bytes', 1024); // 1KB に絞る
    [, $owner, $project, $manual] = ocrUploadContext();

    $big = UploadedFile::fake()->create('big.jpg', 2, 'image/jpeg'); // 2KB (指定 mime を sniff 相当として扱う fake)

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $big],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
});

test('容量上限の判定材料は sniff MIME である (偽装で迂回できない)', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
    config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
    [, $owner, $project, $manual] = ocrUploadContext();

    // JPEG バイト (2KB) にファイル名だけ .pdf を付けても、画像専用の (より厳しい) 上限が適用される
    $fakeJpegAsPdf = UploadedFile::fake()->create('fake.pdf', 2, 'image/jpeg');

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $fakeJpegAsPdf],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
});

test('容量上限の判定材料は sniff MIME である (実バイトと client 申告が食い違う場合)', function (): void {
    // UploadedFile::fake()->create() の "宣言 mime" 挙動 (Laravel 内部実装依存) に頼らず、
    // 実際にディスク上へ書いた JPEG バイトを持つファイルへ、client 申告 (拡張子・mime) だけ
    // .pdf を偽装した UploadedFile を組み立てる。getMimeType() (finfo 実 sniff) は
    // 'image/jpeg' を返し、getClientMimeType()/getClientOriginalExtension() は 'application/pdf'
    // 側を返す (この乖離を実ファイルで固定する)。
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
    config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
    [, $owner, $project, $manual] = ocrUploadContext();

    $realBytes = MinimalImageFixture::jpeg(10, 10).str_repeat("\x00", 2048); // 実サイズ 2KB 超
    $tmpPath = tempnam(sys_get_temp_dir(), 'sniff-bypass-');
    file_put_contents($tmpPath, $realBytes);
    $disguised = new UploadedFile($tmpPath, 'fake.pdf', 'application/pdf', null, true);

    expect($disguised->getMimeType())->toBe('image/jpeg'); // 実 sniff
    expect($disguised->getClientMimeType())->toBe('application/pdf'); // client 申告 (偽装)

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $disguised],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    @unlink($tmpPath);
});

test('公開面の一貫性: FormRequest / Service / Inertia Props がフラグに応じて同じ集合を表す', function (): void {
    Storage::fake();
    [, $owner, $project] = ocrUploadContext();

    foreach ([false, true] as $flag) {
        config()->set('manual.ocr_analysis_enabled', $flag);
        // 各分岐を独立したマニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
        $httpManual = VideoManual::factory()->forProject($project)->create();
        $serviceManual = VideoManual::factory()->forProject($project)->create();

        // FormRequest: jpg 受理可否
        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/manuals/{$httpManual->id}/source-documents",
            ['document' => fakeJpegFile("sop-{$flag}.jpg")],
        );
        if ($flag) {
            $response->assertRedirect();
        } else {
            $response->assertUnprocessable();
        }

        // Service: allowedMimeTypes に image/jpeg が含まれるか (appendDocument の成否で確認)
        if ($flag) {
            $doc = app(SourceDocumentService::class)->appendDocument(
                $serviceManual,
                fakeJpegFile("service-{$flag}.jpg"),
            );
            expect($doc->mime)->toBe('image/jpeg');
        } else {
            expect(fn () => app(SourceDocumentService::class)->appendDocument(
                $serviceManual,
                fakeJpegFile("service-{$flag}.jpg"),
            ))->toThrow(ValidationException::class);
        }

        // Inertia Props: sourceDocumentAccept / imageSourceDocumentsEnabled
        $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('imageSourceDocumentsEnabled', $flag)
                ->where('sourceDocumentAccept', $flag
                    ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
                    : '.pdf,.xlsx,.xls,.txt'));
    }
});

test('画像の手順書は 1 枚まで (2 枚目は明示的に拒否される)', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    [, $owner, $project, $manual] = ocrUploadContext();

    $this->actingAs($owner)->post(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeJpegFile('first.jpg')],
    )->assertRedirect();
    expect(SourceDocument::query()->count())->toBe(1);

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakePngFile('second.png')],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
    expect(SourceDocument::query()->count())->toBe(1);
});

test('非画像 (PDF) の 2 枚目は画像制約の対象外で受理される', function (): void {
    Storage::fake();
    config()->set('manual.ocr_analysis_enabled', true);
    [, $owner, $project, $manual] = ocrUploadContext();

    $this->actingAs($owner)->post(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeJpegFile('first.jpg')],
    )->assertRedirect();

    $pdf = UploadedFile::fake()->createWithContent(
        'second.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
    );
    $this->actingAs($owner)->post(
        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $pdf],
    )->assertRedirect();

    expect(SourceDocument::query()->count())->toBe(2);
});
