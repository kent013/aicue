<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Http\AdminPanelPath;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/*
 * Inertia history guard (bug-hunt F-4-01) の契約検証。
 *
 * 契約:
 *  - web グループの Inertia\Middleware\EncryptHistory により、Inertia 応答の page に
 *    `encryptHistory: true` が載る (認証済み / 公開の区別なくグローバル適用)。
 *  - ログアウト応答は Inertia::clearHistory() を発火し、**着地の Inertia 応答**の page に
 *    `clearHistory: true` が載る (着地が非 Inertia 化するとフラグが宙に浮き防御が消える)。
 *  - **認証失敗 (AuthenticationException) も clearHistory の発行契機である**
 *    (bootstrap/app.php の render callback)。セッション期限切れ / 他デバイスからの
 *    強制ログアウトは「利用者が明示的に終わらせた」契機を持たないため、ログアウト応答だけでは
 *    履歴鍵が残る。積むのは `expectsJson()` 偽 かつ `hasSession()` 真 のときだけ。
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
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard");

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
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard");

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
    $version = inertiaPagePayload($this->get(route('home')))['version'];
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

/*
|--------------------------------------------------------------------------
| 認証失敗 (AuthenticationException) を契機とする clearHistory (T089-b)
|--------------------------------------------------------------------------
|
| ログアウト応答 (LogoutResponse) が拾えるのは「利用者が明示的に終わらせた」契機だけで、
| セッション期限切れと、パスワード変更による他デバイスの強制ログアウト
| (Auth::logoutOtherDevices → web グループの AuthenticateSession) は拾えない。
| どちらも AuthenticationException として現れるため、bootstrap/app.php の render callback で
| Inertia::clearHistory() を積み、着地の /login (Inertia 応答) に消費させる。
|
| 保証するのは「**認証失敗を契機に、以後の戻るによる復元を無効化する**」ことであり、
| 過去に遡って無効化するものではない (docs/supported-browsers.md が正本)。
|
| フラグは session に積まれ、消費は**次の Inertia 応答**なので、テストは
| **302 を自動追従させず、別リクエストとして着地を叩く**形でリダイレクト境界ごと固定する
| (既存のログアウト系テストと同じ書き方)。
*/

test('未認証 guest の認証失敗でも、着地の Inertia 応答に clearHistory が載る', function (): void {
    // セッション期限切れ後のリクエストと同じ形 (guest が auth 保護 route を踏む)。
    $response = $this->get('/settings');
    $response->assertRedirect(route('login'));

    // 別リクエストとして着地を叩く (302 を自動追従させない = 境界そのものを固定する)。
    $landing = $this->get(route('login'));

    $landing->assertOk();
    expect(inertiaPagePayload($landing))->toHaveKey('clearHistory', true);
});

test('他デバイスからの強制ログアウト (AuthenticateSession) で clearHistory が積まれる', function (): void {
    // 再現手順は tests/Feature/Auth/PasswordUpdateSessionInvalidationTest と同型:
    // 旧 password_hash を持つセッションのまま保護 route を踏むと AuthenticateSession が logout する。
    $user = User::factory()->create();
    $oldHash = $user->getAuthPassword();

    $user->forceFill(['password' => Hash::make('NewPassword12345')])->save();

    $this->actingAs($user)
        ->withSession(['password_hash_web' => $oldHash])
        ->get('/settings')
        ->assertRedirect('/login');

    expect(inertiaPagePayload($this->get(route('login'))))->toHaveKey('clearHistory', true);
});

test('guest が /login を直接開いてもフラグは積まれない (負のコントロール)', function (): void {
    // 「guest 向け Inertia 応答すべてに clearHistory を載せる」代案は却下済み。
    // 匿名回遊の戻るが毎回サーバ再取得になり、認証と無関係のトラフィックを恒久的に劣化させる。
    expect(inertiaPagePayload($this->get(route('login'))))->not->toHaveKey('clearHistory');
});

test('expectsJson の 401 ではフラグを積まない (負のコントロール)', function (): void {
    // API / MCP など Inertia 応答が返らない経路で積むと、フラグが宙に浮いて
    // 後続の無関係な Inertia 応答で消費される。
    $this->getJson('/settings')->assertUnauthorized();

    expect(inertiaPagePayload($this->get(route('home'))))->not->toHaveKey('clearHistory');
});

test('認証失敗で積まれたフラグは次の Inertia 応答で 1 度だけ消費される', function (): void {
    // 素の auth 保護 route で発生させる (3rd party の実装差分に契約を依存させない)。
    $this->get('/settings')->assertRedirect(route('login'));

    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
    // pull 済みなので 2 度目には載らない (無関係なページで履歴が飛ぶ事故を防ぐ)。
    expect(inertiaPagePayload($this->get(route('home'))))->not->toHaveKey('clearHistory');
});

test('非 Inertia 面 (/admin) の認証失敗でもフラグは積まれる (安全側の偽陽性)', function (): void {
    // ※ これは**契約テストではなく docblock の主張の裏付け**である。
    //   bootstrap/app.php の callback は guards() で面を判別しない (Filament の Authenticate は
    //   override により guards が [] になり、実装詳細に依存する判別になるため)。その結果
    //   /admin の認証失敗でもフラグが積まれるが、影響は「Inertia 面の履歴が 1 度だけ
    //   再キーされる」ことだけなので安全側の偽陽性として許容している。
    //   **Filament の認証失敗の実装が変わったら、本テストと bootstrap/app.php の docblock を
    //   一緒に更新すること** (テストだけ直して docblock を放置しない)。
    $this->get('/'.AdminPanelPath::resolve());

    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
});
