<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\SourceDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * SOP アップロード (作成時 multipart + 専用 route POST .../source-documents):
 * - 追記型 immutable (差し替え = 新規行。既存行・ファイルは不変)
 * - draft/ready のみ許可 (analyzing 等は 422)
 * - サーバ側 MIME 内容 sniff (polyglot 対策) / サイズ上限 / 保護キー 422
 * - 撮影者 403 / cross-org 404
 */

/**
 * @return array{Organization, User, Project, VideoManual}
 */
function sourceDocumentTestContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    return [$organization, $owner, $project, $manual];
}

/** finfo が application/pdf と判定する最小 PDF バイト列 */
function fakePdfFile(string $name = 'sop.pdf'): UploadedFile
{
    $body = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, $body);
}

/** text/plain 判定のテキスト SOP */
function fakeTxtFile(string $name = 'sop.txt', string $body = "手順1 部品を取り付ける\n手順2 ネジを締める\n"): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $body);
}

test('作成時アップロード: manual と同時に SourceDocument 行 + ファイルが作られる', function (): void {
    Storage::fake();
    [$organization, $owner, $project] = sourceDocumentTestContext();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/projects/{$project->id}/manuals", [
        'title' => '組立マニュアル',
        'category' => null,
        'document' => fakePdfFile(),
    ])->assertRedirect();

    $document = SourceDocument::query()->firstOrFail();
    expect($document->original_name)->toBe('sop.pdf');
    expect($document->mime)->toBe('application/pdf');
    expect($document->file_path)->toContain("projects/{$project->id}/manuals/");
    Storage::assertExists($document->file_path);
});

test('作成時アップロード: document 省略でも作成できる (任意フィールド)', function (): void {
    Storage::fake();
    [$organization, $owner, $project] = sourceDocumentTestContext();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/projects/{$project->id}/manuals", [
        'title' => '手順書なしマニュアル',
        'category' => null,
    ])->assertRedirect();

    expect(SourceDocument::query()->count())->toBe(0);
});

test('専用 route: draft manual に追加でき、2 回目は行が増える (immutable 追記)', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = sourceDocumentTestContext();
    $url = "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents";

    $this->actingAs($owner)->from("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->post($url, ['document' => fakeTxtFile('rev1.txt')])
        ->assertRedirect()->assertSessionHas('success');
    $first = SourceDocument::query()->firstOrFail();

    $this->actingAs($owner)->post($url, ['document' => fakeTxtFile('rev2.txt')])
        ->assertRedirect();

    // 追記型: 既存行は不変のまま行が増える (解析は latest 勝ち)
    expect(SourceDocument::query()->count())->toBe(2);
    expect($first->refresh()->original_name)->toBe('rev1.txt');
    Storage::assertExists($first->file_path);
    expect($manual->sourceDocuments()->latest('id')->firstOrFail()->original_name)->toBe('rev2.txt');
});

test('analyzing 中の専用 route は 422 (行・ファイル不変)', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = sourceDocumentTestContext();
    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeTxtFile()],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    expect(SourceDocument::query()->count())->toBe(0);
});

test('許可外拡張子 (gif) は 422', function (): void {
    // png/jpg/jpeg は画像・スキャン SOP の OCR 対応 (常時有効) により受理対象になったため、
    // ここでは許可集合に残らない gif を「許可外拡張子」の代表として使う
    // (AcceptedSourceDocumentTypesTest の「webp/gif は含まれない」pin と対応する)。
    Storage::fake();
    [$organization, $owner, $project, $manual] = sourceDocumentTestContext();

    $gif = UploadedFile::fake()->create('image.gif', 10, 'image/gif');

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $gif],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
});

test('拡張子偽装 (.pdf だが内容が GIF) は 422 (polyglot 対策)', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = sourceDocumentTestContext();

    // fake UploadedFile は getMimeType() が宣言 mime を返すため、「内容 sniff が
    // image/gif を検出した」状況を mime 指定で再現する (.pdf 拡張子 + GIF 内容)
    $polyglot = UploadedFile::fake()->create('fake.pdf', 10, 'image/gif');

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $polyglot],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    expect(SourceDocument::query()->count())->toBe(0);
});

test('Service の内容 sniff 二層目: 許可外 mime は appendDocument が拒否する (行・ファイルなし)', function (): void {
    Storage::fake();
    [, , , $manual] = sourceDocumentTestContext();
    $polyglot = UploadedFile::fake()->create('fake.pdf', 10, 'image/gif');

    expect(fn () => app(SourceDocumentService::class)->appendDocument($manual, $polyglot))
        ->toThrow(ValidationException::class);

    expect(SourceDocument::query()->count())->toBe(0);
    expect(Storage::allFiles())->toBe([]);
});

test('サイズ超過は 422', function (): void {
    Storage::fake();
    config()->set('manual.source_document_max_bytes', 1024); // 1KB に絞って検証
    [$organization, $owner, $project, $manual] = sourceDocumentTestContext();

    $big = fakeTxtFile('big.txt', str_repeat('あ', 2_000));

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $big],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
});

test('保護キー (video_manual_id 等) の直送は 422', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = sourceDocumentTestContext();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeTxtFile(), 'video_manual_id' => 999],
    )->assertUnprocessable()->assertJsonValidationErrors(['video_manual_id']);
});

test('撮影者 (project_member) は 403', function (): void {
    Storage::fake();
    [$organization, , $project, $manual] = sourceDocumentTestContext();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeTxtFile()],
    )->assertForbidden();
});

test('cross-org の manual への POST は 404', function (): void {
    Storage::fake();
    [$organization, $stranger] = createOrganizationWithOwner('別組織');
    [, , $project, $manual] = sourceDocumentTestContext();

    $this->actingAs($stranger)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeTxtFile()],
    )->assertNotFound();
});
