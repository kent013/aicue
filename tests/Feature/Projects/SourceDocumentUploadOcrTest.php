<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\SourceDocumentService;
use App\Support\Manual\AcceptedSourceDocumentTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Manual\MinimalImageFixture;

/*
 * 画像・スキャン SOP の OCR 対応 (常時有効。旧 `manual.ocr_analysis_enabled` フラグは
 * オーナー決定により撤去済み):
 * - jpg/png アップロードが成功する
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

test('jpg/png アップロードが成功する', function (): void {
    Storage::fake();
    [$organization, $owner, $project] = ocrUploadContext();

    // jpg/png それぞれ別マニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
    $jpgManual = VideoManual::factory()->forProject($project)->create();
    $pngManual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->post(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$jpgManual->id}/source-documents",
        ['document' => fakeJpegFile()],
    )->assertRedirect();
    $jpgDocument = $jpgManual->sourceDocuments()->firstOrFail();
    expect($jpgDocument->mime)->toBe('image/jpeg');

    $this->actingAs($owner)->post(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$pngManual->id}/source-documents",
        ['document' => fakePngFile()],
    )->assertRedirect();
    $pngDocument = $pngManual->sourceDocuments()->firstOrFail();
    expect($pngDocument->mime)->toBe('image/png');
});

test('HEIC は引き続き 422 で拒否される', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = ocrUploadContext();

    // finfo は HEIC を image/heic (または application/octet-stream) と判定する。
    // ここでは許可集合に無いことを固定する目的で明示的に mime 指定する。
    $heic = UploadedFile::fake()->create('sop.heic', 10, 'image/heic');

    $response = $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $heic],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    // 拒否文言に「JPEG・PNG で保存し直す」という次アクションが示されること
    $response->assertJsonFragment(['document' => ['対応していないファイル形式です。'
        .'PDF・Excel・テキスト形式、または JPEG・PNG の画像でアップロードし直してください。']]);
});

test('新規マニュアル作成時 (StoreVideoManualRequest) でも jpg アップロードが成功する', function (): void {
    Storage::fake();
    [$organization, $owner, $project] = ocrUploadContext();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/projects/{$project->id}/manuals", [
        'title' => '画像アップロードテスト',
        'category' => null,
        'document' => fakeJpegFile('create-true.jpg'),
    ])->assertRedirect();

    expect(SourceDocument::query()->where('mime', 'image/jpeg')->count())->toBe(1);
});

test('webp/gif は引き続き拒否される (回帰)', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = ocrUploadContext();

    foreach (['image/webp' => 'sop.webp', 'image/gif' => 'sop.gif'] as $mime => $name) {
        $file = UploadedFile::fake()->create($name, 10, $mime);
        $this->actingAs($owner)->postJson(
            "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
            ['document' => $file],
        )->assertUnprocessable()->assertJsonValidationErrors(['document']);
    }
    expect(SourceDocument::query()->count())->toBe(0);
});

test('画像の容量上限超過 (source_document_image_max_bytes 基準) は 422', function (): void {
    Storage::fake();
    config()->set('manual.source_document_image_max_bytes', 1024); // 1KB に絞る
    [$organization, $owner, $project, $manual] = ocrUploadContext();

    $big = UploadedFile::fake()->create('big.jpg', 2, 'image/jpeg'); // 2KB (指定 mime を sniff 相当として扱う fake)

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $big],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
});

test('容量上限の判定材料は sniff MIME である (偽装で迂回できない)', function (): void {
    Storage::fake();
    config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
    config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
    [$organization, $owner, $project, $manual] = ocrUploadContext();

    // JPEG バイト (2KB) にファイル名だけ .pdf を付けても、画像専用の (より厳しい) 上限が適用される
    $fakeJpegAsPdf = UploadedFile::fake()->create('fake.pdf', 2, 'image/jpeg');

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
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
    config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
    config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
    [$organization, $owner, $project, $manual] = ocrUploadContext();

    $realBytes = MinimalImageFixture::jpeg(10, 10).str_repeat("\x00", 2048); // 実サイズ 2KB 超
    $tmpPath = tempnam(sys_get_temp_dir(), 'sniff-bypass-');
    file_put_contents($tmpPath, $realBytes);
    $disguised = new UploadedFile($tmpPath, 'fake.pdf', 'application/pdf', null, true);

    expect($disguised->getMimeType())->toBe('image/jpeg'); // 実 sniff
    expect($disguised->getClientMimeType())->toBe('application/pdf'); // client 申告 (偽装)

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $disguised],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    @unlink($tmpPath);
});

test('公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す', function (): void {
    Storage::fake();
    [$organization, $owner, $project] = ocrUploadContext();

    // 各分岐を独立したマニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
    $httpManual = VideoManual::factory()->forProject($project)->create();
    $serviceManual = VideoManual::factory()->forProject($project)->create();

    // FormRequest 境界: jpg が StoreSourceDocumentRequest の mimes: ルールを通過する
    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$httpManual->id}/source-documents",
        ['document' => fakeJpegFile('sop.jpg')],
    )->assertRedirect();

    // Service 境界: appendDocument() が例外を投げずに image/jpeg の SourceDocument を返す
    $doc = app(SourceDocumentService::class)->appendDocument(
        $serviceManual,
        fakeJpegFile('service.jpg'),
    );
    expect($doc->mime)->toBe('image/jpeg');

    // Inertia Props 境界: create()/show() 両方の sourceDocumentAccept が画像込み固定値で一致する。
    // 旧 imageSourceDocumentsEnabled prop が再追加されないことも固定する (完全撤去の回帰検出)
    $showResponse = $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $httpManual]));
    $showResponse->assertInertia(fn (Assert $page) => $page
        ->where('sourceDocumentAccept', '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png')
        ->missing('imageSourceDocumentsEnabled'));

    $createResponse = $this->actingAs($owner)->get(route('projects.manuals.create', [$organization->slug, $project]));
    $createResponse->assertInertia(fn (Assert $page) => $page
        ->where('sourceDocumentAccept', '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png')
        ->where('sourceDocumentFormatsLabel', 'PDF・Excel・テキスト形式、または JPEG・PNG の画像')
        ->missing('imageSourceDocumentsEnabled'));

    // 面をまたいだ同値性 (作成画面と詳細画面が同じ情報源を経由していること)
    $showProps = Assert::fromTestResponse($showResponse)->toArray();
    $createProps = Assert::fromTestResponse($createResponse)->toArray();
    expect($createProps['sourceDocumentAccept'] ?? null)->toBe(
        $showProps['sourceDocumentAccept'] ?? null,
        '作成画面と詳細画面で props sourceDocumentAccept が食い違っている (単一の情報源を経由していない)',
    );
});

/*
 * StoreVideoManualRequest (作成と同時のアップロード経路) の 422 出力契約。
 *
 * **このテストが保証する範囲 (誇張しない)**: 固定できるのは両エンドポイントの 422 出力契約
 * である。「formatsLabel() を実際に呼んでいること」は保証しない — 置換前の文字列を
 * 残しても同じ文言を返すため本テストは緑になる。中央メソッドへの構造的な結線は
 * コードレビューで確認する。逆に **文面が経路ごとにずれたら** 本テストが検出する。
 */
test('作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = ocrUploadContext();
    $makeFile = fn (): UploadedFile => UploadedFile::fake()->create('rejected.heic', 10, 'image/heic');
    $expected = '対応していないファイル形式です。'
        .AcceptedSourceDocumentTypes::formatsLabel()
        .'でアップロードし直してください。';

    $this->actingAs($owner)->postJson("/organizations/{$organization->slug}/projects/{$project->id}/manuals", [
        'title' => '422 文言の経路差テスト',
        'category' => null,
        'document' => $makeFile(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document'])
        ->assertJsonFragment(['document' => [$expected]]);

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $makeFile()],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['document'])
        ->assertJsonFragment(['document' => [$expected]]);
});

test('画像の手順書は 1 枚まで (2 枚目は明示的に拒否される)', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = ocrUploadContext();

    $this->actingAs($owner)->post(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeJpegFile('first.jpg')],
    )->assertRedirect();
    expect(SourceDocument::query()->count())->toBe(1);

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakePngFile('second.png')],
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
    expect(SourceDocument::query()->count())->toBe(1);
});

test('非画像 (PDF) の 2 枚目は画像制約の対象外で受理される', function (): void {
    Storage::fake();
    [$organization, $owner, $project, $manual] = ocrUploadContext();

    $this->actingAs($owner)->post(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => fakeJpegFile('first.jpg')],
    )->assertRedirect();

    $pdf = UploadedFile::fake()->createWithContent(
        'second.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
    );
    $this->actingAs($owner)->post(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/source-documents",
        ['document' => $pdf],
    )->assertRedirect();

    expect(SourceDocument::query()->count())->toBe(2);
});
