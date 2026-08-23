<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Http\NotFoundMessage;

/*
 * JSON を期待された 404 の message を固定文言へ collapse する (T158 / bug-hunt F-1-03)。
 *
 * 背景: Laravel は ModelNotFoundException を NotFoundHttpException($e->getMessage()) へ
 * 変換するため、既定のままだと `No query results for model [App\Models\Take] 1` のように
 * 内部の名前空間が JSON body に漏れる。HTML 経路は日本語の 404 画面なので**経路で露出が非対称**。
 *
 * 固定する契約:
 * - collapse は `api/*` 以外の JSON 404 へ**全面適用**する (除外は作らない)。
 *   prefix は「安全性」ではなく**文言**しか決めない。
 * - 応答の**形は変えない** (`{"message": …}`)。撮影 PWA のクライアントが message を読むため、
 *   api/* の封筒形 `{error:{…}}` に変えると壊れる。
 * - `api/*` / HTML / 401・402・403・409・422 / OAuth の仕様内エラーは**一切変えない**。
 */

test('契約 1: api/* の 404 は既存の統一エラー封筒を維持する', function (string $path, bool $authenticated): void {
    // Accept を明示するのが要点。これが無いと「callback を ApiExceptionRenderer より前に置く」
    // mutation を検出できない (expectsJson が偽になり collapse へ入らないため)。
    // model binding 由来の 404 は認証を通さないと 401 で手前に落ちるため API キーを与える。
    if ($authenticated) {
        [$organization, $owner] = createOrganizationWithOwner();
        [, $plain] = issueApiKey($organization, $owner);
        $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    $response = $this->getJson($path);

    $response->assertNotFound();
    $body = $response->json();
    expect($body)->toHaveKey('error');
    expect($body['error'])->toHaveKey('code');
    // 封筒形なのでトップレベル message は持たない
    expect($body)->not->toHaveKey('message');
})->with([
    'undefined url' => ['/api/v1/no-such-path', false],
    'model binding' => ['/api/v1/projects/999999', true],
]);

test('契約 2: 撮影 PWA の JSON 404 は固定文言へ collapse される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create();

    $response = $this->actingAs($owner)
        ->getJson("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/999999");

    $response->assertNotFound();
    expect($response->json('message'))->toBe(NotFoundMessage::HUMAN_MESSAGE);
});

test('契約 3: HTML の 404 は既存のエラー画面を維持する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/projects/999999")
        ->assertNotFound()
        ->assertSee('見つかりません');
});

test('契約 4: 404 以外の status (%s) は既存応答を維持する', function (string $case): void {
    // ★ここは **HttpExceptionInterface を実装する status を必ず含める** こと。
    //   401 (AuthenticationException) は HttpException ではないため、
    //   「status 404 の判定を外す」mutation を検出できない (実測済み)。
    //   403 (AccessDeniedHttpException) と 409 (abort) が検出役である。
    if ($case === '401') {
        // 未認証なので組織は解決されない。実在する識別名の形だけを使う (auth が binding より先)
        [$organization] = createOrganizationWithOwner();
        $response = $this->getJson("/organizations/{$organization->slug}/app/projects/1/manuals/1");
        $response->assertStatus(401);
    }

    if ($case === '403') {
        [$organization, $owner] = createOrganizationWithOwner();
        $project = Project::factory()->forOrganization($organization)->create();
        $member = attachOrganizationMember($organization);
        attachProjectMember($project, $member, ProjectRole::Member);

        $response = $this->actingAs($member)
            ->postJson("/organizations/{$organization->slug}/projects/{$project->id}/manuals", ['title' => 'x']);
        $response->assertStatus(403);
    }

    if ($case === '409') {
        [$organization, $owner] = createOrganizationWithOwner();
        app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
        $owner->refresh();

        $response = $this->actingAs($owner)->getJson("/organizations/{$organization->slug}/dashboard");
        $response->assertStatus(409);
    }

    if ($case === '422') {
        [$organization, $owner] = createOrganizationWithOwner();
        $project = Project::factory()->forOrganization($organization)->create();

        // 変更対象外の validation 応答 (ValidationException) が素通しであることを固定する
        $response = $this->actingAs($owner)
            ->postJson("/organizations/{$organization->slug}/projects/{$project->id}/manuals", ['title' => '']);
        $response->assertStatus(422);
        expect($response->json('errors'))->toBeArray();
    }

    // collapse は 404 だけ。他 status の body を 404 の文言で書き換えていないこと
    expect($response->json('message'))->not->toBe(NotFoundMessage::HUMAN_MESSAGE);
    expect($response->json('message'))->not->toBe(NotFoundMessage::MACHINE_MESSAGE);
})->with(['401', '403', '409', '422']);

test('契約 5: OAuth の仕様内エラー応答は既存形を維持する', function (): void {
    $response = $this->postJson('/oauth/token', []);

    // Passport が返す仕様内エラー (400 系)。collapse は 404 だけなので触らない
    expect($response->getStatusCode())->not->toBe(404);
    expect($response->json('message'))->not->toBe(NotFoundMessage::MACHINE_MESSAGE);
});

test('契約 6: 未定義 URL への JSON 要求も内部 message を返さない', function (): void {
    $response = $this->getJson('/no-such-path');

    $response->assertNotFound();
    expect($response->json('message'))->toBe(NotFoundMessage::HUMAN_MESSAGE);
});

test('契約 7: 機械向け経路 %s の 404 は英語の固定文言', function (string $path): void {
    // **直下と配下の両方**を見る。配下だけだと 'oauth' / '.well-known' を
    // MACHINE_FACING_PATTERNS から消しても緑のままになる
    $response = $this->getJson($path);

    $response->assertNotFound();
    expect($response->json('message'))->toBe(NotFoundMessage::MACHINE_MESSAGE);
})->with([
    'oauth 直下' => ['/oauth'],
    'oauth 配下' => ['/oauth/no-such-path'],
    '.well-known 直下' => ['/.well-known'],
    '.well-known 配下' => ['/.well-known/no-such-path'],
]);

test('契約 8: JSON 404 の本文に内部クラス名や Eloquent の例外文が出ない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $body = $this->actingAs($owner)
        ->getJson("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/999999")
        ->getContent();

    expect($body)->toBeString();
    expect($body)->not->toContain('App\\Models');
    expect($body)->not->toContain('No query results');
});
