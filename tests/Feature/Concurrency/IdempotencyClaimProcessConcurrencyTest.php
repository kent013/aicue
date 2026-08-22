<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Enums\Idempotency\IdempotencyState;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ConcurrencyFixtureKeys;
use Tests\Support\Concurrency\ConcurrencyProbeRunner;
use Tests\Support\Concurrency\ConcurrentProbeObservation;
use Tests\Support\Concurrency\OutOfTransactionFixtures;
use Tests\Support\Concurrency\ProbeDatabaseCoordinates;

/*
 * 冪等キーの実行前 claim を**実プロセス 2 本**で証明する
 * (正典 v1 の要素 (6): 実プロセス版はこの 1 本だけ)。
 *
 * 守られている実装 (App\Http\Middleware\IdempotentRequest::claim) は
 * 「unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない」と宣言している。
 * 本テストはその宣言を実経路の証拠にする — 子の cache を配列固定にし
 * **Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態**を作ってから測る。
 *
 * ★**本テストが測る範囲**: 既定 cache が array であることから直接言えるのは
 *   「Laravel の既定 cache を経由するプロセス間共有ロックが使えない」までである。
 *   「アプリ側ロックが 1 つも無い」とは書かない (観測を超える)。
 *   claim が unique 制約以外の補助機構を持たないことは**実装の実読**が担い、
 *   2 つを合わせて初めて「DB の一意制約だけで 1 回に収まる」と読む。
 *
 * ★probe 経路の middleware 列は「冪等 middleware の前提を満たす最小構成」であり
 *   **「本番同等」とは主張しない** (throttle は 2 本の到達を乱すため入れていない)。
 *
 * ★細かい分岐 (再生 / conflict / indeterminate / 期限切れ再 claim / 順序) は
 *   **同一プロセス**の tests/Feature/Api/IdempotencyConcurrentClaimTest.php が持つ。ここへ足さない。
 */

test('実プロセス 2 本の同時 claim で本処理はちょうど 1 回だけ通る', function (): void {
    $expectedCoordinates = ProbeDatabaseCoordinates::fromParentConfig();

    // 検体はテストの transaction の**外**に作る (子から見えなければ成立しない)。
    // ★route 名はここでは決まらない (runner が決める) ので、鍵は 4 つだけ持つ。
    [$keys, $plainKey] = OutOfTransactionFixtures::create(function (): array {
        [$organization, $owner] = createOrganizationWithOwner();
        [$apiKey, $plain] = issueApiKey($organization, $owner);

        return [new ConcurrencyFixtureKeys(
            organizationId: $organization->id,
            laratrustTeamId: $organization->laratrust_team_id,
            userId: $owner->id,
            apiKeyId: $apiKey->id,
        ), $plain];
    });

    try {
        // ★同一性 (childId / nonce / go token) の検査は runner の中で完結している。
        //   ここで再検査しない = 内部プロトコルをテストへ漏らさない。
        $result = ConcurrencyProbeRunner::run(
            idempotencyKey: (string) Str::uuid(),
            plainApiKey: $plainKey,
            requestBody: ['title' => '並行 claim の検体'],
        );

        expect($result->observations)->toHaveCount(2);

        // (1) ハンドラ実行回数の**合計が 1** ← 一次観測。本テストの核心
        $executions = array_sum(array_map(
            fn (ConcurrentProbeObservation $observation): int => $observation->handlerExecutions,
            $result->observations,
        ));
        expect($executions)->toBe(1);

        // (2) 勝者は 201 / entered=true、敗者は 409 + idempotency_in_progress / entered=false
        //     ★status だけでは足りない — 409 は 3 コードあり、body 違いの conflict でも
        //       (1) まで成立して**緑になる**。error_code の完全一致で塞ぐ。
        [$winner, $loser] = $result->partition();
        expect($winner->enteredHandler)->toBeTrue();
        expect($loser->enteredHandler)->toBeFalse();
        expect($winner->httpStatus)->toBe(201);
        expect($winner->handlerExecutions)->toBe(1);
        expect($winner->errorCode)->toBeNull();
        expect($loser->httpStatus)->toBe(409);
        expect($loser->errorCode)->toBe(ApiErrorCode::IdempotencyInProgress->value);
        expect($loser->handlerExecutions)->toBe(0);

        // (3) 2 子は**同一要求**だった。親の期待 hash を含めた**3 点一致**で見る
        //     (2 子の一致だけだと「2 本とも同じ誤った body を送った」形と区別がつかない)
        expect($winner->requestHash)->toBe($result->expectedRequestHash);
        expect($loser->requestHash)->toBe($result->expectedRequestHash);
        expect($winner->routeName)->toBe($result->routeName);
        expect($loser->routeName)->toBe($result->routeName);

        // (4) 認証結果の api_key_id が**検体のもの**と一致する
        //     (★入力のコピーではなく ApiActorContext から観測した値である)
        expect($winner->apiKeyId)->toBe($keys->apiKeyId);
        expect($loser->apiKeyId)->toBe($keys->apiKeyId);

        // (5) 2 子とも既定 cache が array
        //     (= Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態)
        foreach ($result->observations as $observation) {
            $observation->assertAppLocksDisabled();
        }

        // (6) 2 子の実効 DB 座標が親の値と**完全一致**
        //     (driver/host/port/database/username/charset/sslmode。url は空のみ許可)
        foreach ($result->observations as $observation) {
            $observation->assertDatabaseCoordinates($expectedCoordinates);
        }

        // (7) 裏取り: 行は 1 本だけで completed (**別名接続で読む**)。
        //     ★スコープ (api_key_id + route_name + key) まで絞り、
        //       保存された request_hash も親の期待値と突き合わせる。
        $rows = OutOfTransactionFixtures::connection()
            ->table('idempotency_keys')
            ->where('api_key_id', $keys->apiKeyId)
            ->where('route_name', $result->routeName)
            ->where('key', $result->idempotencyKey)
            ->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->state)->toBe(IdempotencyState::Completed->value);
        // pgsql ドライバは整数列を文字列で返しうるので緩い比較で見る (値の一致が論点)
        expect($rows[0]->response_status)->toEqual(201);
        expect($rows[0]->request_hash)->toBe($result->expectedRequestHash);

        // (8) スコープ外に余分な行が無い (api_key_id 全体で 1 件)
        $all = OutOfTransactionFixtures::connection()
            ->table('idempotency_keys')->where('api_key_id', $keys->apiKeyId)->count();
        expect($all)->toBe(1);
    } finally {
        // 子が commit した行は RefreshDatabase の rollback では消えない。必ず片付ける。
        // ★cleanup() は削除後に自分で 8 表の残留ゼロを検査する。
        OutOfTransactionFixtures::cleanup($keys);
    }
});
