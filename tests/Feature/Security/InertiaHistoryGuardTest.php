<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

/*
 * Inertia history guard (bug-hunt F-4-01) の契約検証。
 *
 * 契約:
 *  - web グループの Inertia\Middleware\EncryptHistory により、Inertia 応答の page に
 *    `encryptHistory: true` が載る (認証済み / 公開の区別なくグローバル適用)。
 *  - ログアウト応答は Inertia::clearHistory() を発火し、**着地の Inertia 応答**の page に
 *    `clearHistory: true` が載る (着地が非 Inertia 化するとフラグが宙に浮き防御が消える)。
 *  - 通常の応答には `clearHistory` が載らない (負のコントロール)。
 *
 * 目的は「ログアウト後の戻る」で Inertia のクライアント履歴から認証済み画面 (PII) が
 * 復元されるのを防ぐこと。経路 A (HTTP キャッシュ / bfcache evict) は NoStoreCacheHeadersTest、
 * 経路 B (Safari の真の bfcache) は tests/js/lib/bfcache-guard.test.ts が受け持つ。
 */

/**
 * Inertia の root view から page オブジェクトを取り出す (Inertia 応答でなければ失敗させる)。
 *
 * @return array<string, mixed>
 */
function inertiaPagePayload(TestResponse $response): array
{
    $page = $response->viewData('page');

    expect($page)->toBeArray('Inertia 応答ではない (clearHistory / encryptHistory を消費できない)');

    /** @var array<string, mixed> $page */
    return $page;
}

test('認証済み Inertia 応答の page に encryptHistory が載る', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');

    $response->assertOk();
    expect(inertiaPagePayload($response))->toHaveKey('encryptHistory', true);
});

test('公開ページの Inertia 応答にも encryptHistory が載る (グローバル適用)', function (): void {
    // 認証済み route への限定適用にしない設計判断をテストに刻む
    // (限定適用に変えるなら inventory と Architecture テストをセットで作ること)。
    $response = $this->get('/');

    $response->assertOk();
    expect(inertiaPagePayload($response))->toHaveKey('encryptHistory', true);
});

test('通常の応答には clearHistory が載らない (負のコントロール)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');

    expect(inertiaPagePayload($response))->not->toHaveKey('clearHistory');
});

test('ログアウトの着地 Inertia 応答に clearHistory が載る', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $logout = $this->actingAs($owner)->post('/logout');
    $logout->assertRedirect(route('home'));

    $landing = $this->get((string) $logout->headers->get('Location'));

    $landing->assertOk();
    // 着地が Inertia 応答でなければ inertiaPagePayload が失敗する = 契約違反を検出
    expect(inertiaPagePayload($landing))->toHaveKey('clearHistory', true);
    $this->assertGuest();
});

test('clearHistory は 1 度きりで、次の Inertia 応答には持ち越さない', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/logout');
    $this->get(route('home'));

    // pull 済みなので 2 度目には載らない (無関係なページで履歴が飛ぶ事故を防ぐ)。
    // 依存 route は「ログアウト着地 = Inertia 応答」の 1 本に集約する
    // (他 route の Inertia 性に依存すると、その route の変更で false negative になる)。
    expect(inertiaPagePayload($this->get(route('home'))))->not->toHaveKey('clearHistory');
});

test('実運用経路 (X-Inertia visit) でも着地の page JSON に clearHistory が載る', function (): void {
    // 実ブラウザのログアウトは router.post('/logout') = X-Inertia 付き XHR。
    // 302 を XHR が追従し、着地は **JSON の page オブジェクト**になる。
    // root view 経由 (viewData) だけでなく、この実経路も直接固定する。
    // ※ 「XHR が 302 を追従すること」自体はブラウザ / axios の責務であり、
    //    ここでは追従後の最終リクエストが実ブラウザと同じ形になることを検証する
    //    (追従を含む一気通貫は Browser テストが担う)。
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    // Inertia の asset version は HandleInertiaRequests::version() が
    // **リクエスト処理中に**設定する (Middleware.php L112-114) ため、
    // リクエスト前に Inertia::getVersion() を読むと空になり得て 409 (version mismatch) を招く。
    // サーバ応答が自己申告した version をそのまま使う。
    // ※ ResponseFactory::render() は Response に getVersion(): string を渡すため
    //    page.version は常に string (空文字はあり得る)。前提を明示 assert する。
    $version = inertiaPagePayload($this->get('/dashboard'))['version'];
    expect($version)->toBeString();

    $inertiaHeaders = ['X-Inertia' => 'true'];
    if ($version !== '') {
        // 空のときはヘッダ自体を付けない (実ブラウザの挙動に揃える)
        $inertiaHeaders['X-Inertia-Version'] = $version;
    }

    $this->withHeaders($inertiaHeaders)
        ->post('/logout')
        ->assertRedirect(route('home'));

    $this->withHeaders($inertiaHeaders)
        ->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJson(['clearHistory' => true]);
});

test('JSON クライアントのログアウトは 204 のまま (既定挙動の維持)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->postJson('/logout')
        ->assertNoContent();

    $this->assertGuest();
});

test('JSON ログアウトでもフラグは積まれ、次の Inertia 応答で clearHistory が消費される', function (): void {
    // clearHistory は X-Inertia の有無で分岐しない (LogoutResponse docblock の根拠 1)。
    // ※ これは「JSON logout 経路の履歴復元が安全」であることの証明**ではない**。
    //    204 応答ではクライアント鍵は消えず、次の Inertia page を適用するまで残る。
    //    経路 C の保証条件は
    //    「clearHistory: true を含む Inertia page をクライアントが適用したタブ」。
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->postJson('/logout')->assertNoContent();

    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
});
