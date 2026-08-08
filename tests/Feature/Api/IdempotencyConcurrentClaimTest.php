<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyState;
use App\Models\IdempotencyKey;
use App\Models\Project;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\Support\OAuthTestHelpers;

/*
 * 冪等キーの「実行前 claim」契約 (T139)。
 *
 * 旧実装は本処理の**後**に保存していたため、同一キーの並行 2 本が両方 controller を
 * 実行し、後着の unique 違反を握り潰していた。本テストは
 *   (a) claim 行が本処理より**前**に作られること (テスト 1)
 *   (b) 同一スコープの 2 本目の INSERT を unique が落とすこと (テスト 3)
 * を固定する。
 *
 * ★**保証しないこと**: PHP のテストは単一プロセスであり、真の並行 2 本は走らせていない。
 *   `RefreshDatabase` 下では全操作が同一接続・同一トランザクション内で見えるため、
 *   claim の commit も別接続からの可視性も検証していない。本番で後着から claim が
 *   見えるのは「middleware を包む外側 transaction が無い + pgsql の autocommit /
 *   read committed」という前提の帰結であって、テストによる保証ではない。
 */

/** report() 経路 (運用アラート) を観測する spy を差し込む */
function spyOnIdempotencyExceptionHandler(): MockInterface
{
    $handler = Mockery::spy(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    return $handler;
}

/**
 * IdempotentRequest::hashRequest() と同じ規則で request hash を組む
 * (メソッド + パス + body の sha256)。Factory で「同一 body の先行要求」を作るために使う。
 *
 * @param  array<string, mixed>  $payload
 */
function idempotencyRequestHashFor(string $method, string $path, array $payload): string
{
    return hash(
        'sha256',
        $method.'|'.$path.'|'.json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

/**
 * `idempotent` を含む本番同等の middleware 列を持つ probe route を登録する。
 *
 * 実 route (items.store) では controller 実行中の観測や例外送出ができないため、
 * middleware の挙動だけを見たいテストで使う。URI はテストごとに固有にする
 * (`--parallel` でも衝突しないよう呼び出し側が suffix を渡す)。
 *
 * @param  Closure(): mixed  $handler
 */
function registerIdempotencyProbeRoute(string $suffix, Closure $handler): string
{
    $uri = "api/v1/__idempotency_probe_{$suffix}__";

    Route::post($uri, $handler)
        ->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])
        ->name("api.v1.__idempotency_probe_{$suffix}__");

    return '/'.$uri;
}

test('claim 行は controller 実行前に作られ、同一リクエスト内で processing として読める', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [, $plain] = issueApiKey($organization, $owner);

    $url = registerIdempotencyProbeRoute('claim_visible', function (): array {
        $row = IdempotencyKey::query()->sole();

        return ['data' => ['state' => $row->state->value]];
    });

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'probe-claim-visible',
    ])->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.state', IdempotencyState::Processing->value);

    // 応答確定後は completed になっている
    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Completed);
});

test('処理中の同一キーは controller を実行せず 409 idempotency_in_progress', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [$apiKey, $plain] = issueApiKey($organization, $owner);
    $payload = ['name' => '並行アイテム'];

    $requestHash = idempotencyRequestHashFor(
        'POST',
        "api/v1/projects/{$project->id}/items",
        $payload,
    );

    IdempotencyKey::factory()->forApiKey($apiKey)->processing()->create([
        'route_name' => 'api.v1.projects.items.store',
        'key' => 'in-progress-key',
        'request_hash' => $requestHash,
    ]);

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'in-progress-key',
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_in_progress');

    // 副作用ゼロ (controller は 1 度も走っていない)
    expect($project->items()->count())->toBe(0);
    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Processing);
});

test('claim の INSERT は同一スコープで 1 本しか通らない (unique 制約が調停者)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$apiKey] = issueApiKey($organization, $owner);

    // 属性値は Factory から生成する (手組み禁止の規約)。middleware の claim と同じ形で
    // query builder へ渡すため、enum cast を通さない state だけ ->value へ落とす。
    /** @var array<string, mixed> $row */
    $row = IdempotencyKey::factory()->forApiKey($apiKey)->processing()->raw([
        'route_name' => 'api.v1.projects.items.store',
        'key' => 'race-key',
    ]);
    $row['state'] = IdempotencyState::Processing->value;
    $row['created_at'] = now();

    expect(IdempotencyKey::query()->insertOrIgnore($row))->toBe(1);
    expect(IdempotencyKey::query()->insertOrIgnore($row))->toBe(0);
    expect(IdempotencyKey::query()->count())->toBe(1);
});

test('決着済み (completed) の行があれば controller を実行せず再生する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [$apiKey, $plain] = issueApiKey($organization, $owner);
    $payload = ['name' => '再生対象'];

    IdempotencyKey::factory()->forApiKey($apiKey)->create([
        'route_name' => 'api.v1.projects.items.store',
        'key' => 'replay-key',
        'request_hash' => idempotencyRequestHashFor(
            'POST',
            "api/v1/projects/{$project->id}/items",
            $payload,
        ),
        'response_status' => 201,
        'response_body' => ['data' => ['id' => 4242, 'name' => '保存済み']],
    ]);

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'replay-key',
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', 4242);

    expect($project->items()->count())->toBe(0);
});

test('indeterminate の行があれば 409 idempotency_indeterminate で副作用ゼロ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [$apiKey, $plain] = issueApiKey($organization, $owner);
    $payload = ['name' => '決着不明'];

    IdempotencyKey::factory()->forApiKey($apiKey)->indeterminate()->create([
        'route_name' => 'api.v1.projects.items.store',
        'key' => 'indeterminate-key',
        'request_hash' => idempotencyRequestHashFor(
            'POST',
            "api/v1/projects/{$project->id}/items",
            $payload,
        ),
    ]);

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'indeterminate-key',
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_indeterminate');

    expect($project->items()->count())->toBe(0);
});

test('例外が middleware まで抜けた場合も indeterminate に確定する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [, $plain] = issueApiKey($organization, $owner);

    $url = registerIdempotencyProbeRoute('throws', function (): never {
        throw new RuntimeException('probe explodes');
    });

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'probe-throws',
    ])->postJson($url)->assertStatus(500);

    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Indeterminate);
});

test('期限切れの processing 行は削除されて再 claim できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [$apiKey, $plain] = issueApiKey($organization, $owner);
    $payload = ['name' => '期限切れ claim'];

    IdempotencyKey::factory()->forApiKey($apiKey)->processing()->expired()->create([
        'route_name' => 'api.v1.projects.items.store',
        'key' => 'expired-processing',
        'request_hash' => str_repeat('b', 64),
    ]);

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'expired-processing',
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();

    $row = IdempotencyKey::query()->sole();
    expect($row->state)->toBe(IdempotencyState::Completed);
    expect($project->items()->count())->toBe(1);
});

test('claim 行は api_key_id / user_id のどちらか一方だけが非 NULL', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('排他検証組織');
    $project = Project::factory()->forOrganization($organization)->create();
    [$apiKey, $plain] = issueApiKey($organization, $owner);

    // ★OAuth 発行を先に済ませる (default header に Bearer を積むと consent フローが壊れる)
    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $owner,
        organization: $organization,
        client: OAuthTestHelpers::createMcpClient(name: 'Ownership CLI'),
    );

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'ownership-api-key',
    ])->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'APIキー経由'])
        ->assertCreated();

    $apiKeyRow = IdempotencyKey::query()->where('key', 'ownership-api-key')->sole();
    expect($apiKeyRow->api_key_id)->toBe($apiKey->id);
    expect($apiKeyRow->user_id)->toBeNull();

    $this->flushHeaders();
    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->withHeader('Idempotency-Key', 'ownership-user')
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'OAuth経由'])
        ->assertCreated();

    $userRow = IdempotencyKey::query()->where('key', 'ownership-user')->sole();
    expect($userRow->user_id)->toBe($owner->id);
    expect($userRow->api_key_id)->toBeNull();
});

test('409 の 3 コードはいずれも error envelope の形が同じ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [$apiKey, $plain] = issueApiKey($organization, $owner);
    $payload = ['name' => 'envelope 検証'];
    $hash = idempotencyRequestHashFor('POST', "api/v1/projects/{$project->id}/items", $payload);

    $expectations = [
        ['conflict-key', IdempotencyState::Completed, str_repeat('c', 64), 'idempotency_conflict'],
        ['progress-key', IdempotencyState::Processing, $hash, 'idempotency_in_progress'],
        ['indeterminate-key', IdempotencyState::Indeterminate, $hash, 'idempotency_indeterminate'],
    ];

    foreach ($expectations as [$key, $state, $requestHash, $code]) {
        IdempotencyKey::factory()->forApiKey($apiKey)->create([
            'route_name' => 'api.v1.projects.items.store',
            'key' => $key,
            'request_hash' => $requestHash,
            'state' => $state,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$plain}",
            'Idempotency-Key' => $key,
        ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
            ->assertStatus(409)
            ->assertJsonCount(1)
            ->assertJsonCount(3, 'error')
            ->assertJsonPath('error.code', $code)
            ->assertJsonPath('error.status', 409)
            ->assertJsonPath('error.message', fn (mixed $message): bool => is_string($message) && $message !== '');
    }

    expect($project->items()->count())->toBe(0);
});

test('finalize は processing の行しか書き換えない (terminal 行を上書きしない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [, $plain] = issueApiKey($organization, $owner);

    // ハンドラ実行中に claim 行が別経路で completed へ確定した状況を作る。
    // finalize の条件付き UPDATE (where state = processing) が無いと、
    // 先に決着した保存応答を後から上書きしてしまう。
    $url = registerIdempotencyProbeRoute('terminal_guard', function (): array {
        IdempotencyKey::query()->update([
            'state' => IdempotencyState::Completed->value,
            'response_status' => 200,
            'response_body' => json_encode(['data' => ['winner' => true]], JSON_THROW_ON_ERROR),
        ]);

        return ['data' => ['winner' => false]];
    });

    $handler = spyOnIdempotencyExceptionHandler();

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'probe-terminal-guard',
    ])->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.winner', false);

    // 先に決着した内容が保持され、上書きされない
    $row = IdempotencyKey::query()->sole();
    expect($row->state)->toBe(IdempotencyState::Completed);
    expect($row->response_body)->toBe(['data' => ['winner' => true]]);

    // 書き換えられなかったことは観測専用例外として report される
    $handler->shouldHaveReceived('report')->once();
});

test('finalize が失敗しても元の応答は壊れない (report のみ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [, $plain] = issueApiKey($organization, $owner);

    // ハンドラ内で claim 行を消す = finalize の条件付き UPDATE が 0 行になる
    $url = registerIdempotencyProbeRoute('finalize_fails', function (): array {
        IdempotencyKey::query()->delete();

        return ['data' => ['ok' => true]];
    });

    $handler = spyOnIdempotencyExceptionHandler();

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'probe-finalize-fails',
    ])->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.ok', true);

    $handler->shouldHaveReceived('report')->once();
});

test('completed の保存 body は DB へ往復してから再生される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = [
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'roundtrip-key',
    ];
    $payload = ['name' => '往復検証'];

    $first = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    // DB から読み直して配列として復元できること (json 列へ PHP 配列を渡す回帰の検出)
    $row = IdempotencyKey::query()->sole();
    expect($row->state)->toBe(IdempotencyState::Completed);
    expect($row->response_status)->toBe(201);
    expect($row->response_body)->toBe($first->json());

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($first->json());
});

test('255 文字を超える Idempotency-Key は 422 で弾かれ副作用も冪等行も作らない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $payload = ['name' => 'キー長検証'];

    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => str_repeat('a', 256),
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect($project->items()->count())->toBe(0);
    expect(IdempotencyKey::query()->count())->toBe(0);

    // 境界値 255 は正常に通る
    $this->withHeaders([
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => str_repeat('b', 255),
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();

    expect(IdempotencyKey::query()->count())->toBe(1);
});
