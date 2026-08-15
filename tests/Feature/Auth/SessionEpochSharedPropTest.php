<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\SessionEpoch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;

/*
 * 描画世代 (Inertia 共有 prop `sessionEpoch`)。
 *
 * 「いま画面に出ている内容がどのセッション世代の応答で来たか」を、内容と同じ 1 通で運ぶ。
 * 世代 cookie とは**同じ出所から出た同じ値**でなければならない (ずれると
 * 「内容は A・印は B」の取り違えが起きる)。
 *
 * prop は closure で共有する。vendor の Inertia\Middleware は $next の**前**に
 * share() を呼ぶため、即値にするとセッション ID 再生成 (ログイン等) を拾えず
 * cookie と食い違う。下の「ログイン応答」のケースがその behavioral な固定である。
 */

/** Inertia 応答の props から描画世代を取り出す。 */
function renderedSessionEpoch(TestResponse $response): mixed
{
    $page = $response->viewData('page');

    expect($page)->toBeArray();

    /** @var array{props: array<string, mixed>} $page */
    return $page['props'][SessionEpoch::SHARED_PROP_KEY] ?? null;
}

/** 応答に載った世代 cookie の値。 */
function issuedSessionEpochCookie(TestResponse $response): ?string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === SessionEpoch::COOKIE_NAME) {
            return $cookie->getValue();
        }
    }

    return null;
}

test('認証済みの Inertia 応答で描画世代と世代 cookie が同値である', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');

    $epoch = renderedSessionEpoch($response);

    expect($epoch)->toBeString()
        ->and($epoch)->toBe(issuedSessionEpochCookie($response));
});

test('guest の Inertia 応答にも描画世代が載る', function (): void {
    $response = $this->get('/login');

    expect(renderedSessionEpoch($response))->toBeString();
});

test('セッション ID が要求中に再生成される経路でも描画世代と世代 cookie が同値になる', function (): void {
    // 遅延評価が効いていることの behavioral な固定。共有 prop を即値へ戻すと、
    // 描画世代は「要求前のセッション ID」で固定される一方、cookie は $next の後に
    // 導出されるので再生成後の ID になり、両者がずれて赤くなる。
    //
    // 本番の再生成経路 (ログイン / 他デバイス失効) はいずれも redirect を返すため
    // Inertia の props を持たない。そこで**再生成した上で Inertia 応答を返す route**を
    // このテストの中だけで登録し、prop と cookie を直接突き合わせる。
    Route::middleware('web')->get('/__test/session-epoch-regenerated', function (Request $request) {
        $request->session()->regenerate();

        return Inertia::render('Dashboard');
    });

    $sessionId = Str::random(40);

    $response = $this->withCookie((string) config('session.cookie'), $sessionId)
        ->get('/__test/session-epoch-regenerated');

    $epoch = renderedSessionEpoch($response);

    expect($epoch)->toBeString()
        // 再生成後の ID 由来であること (要求前の ID から導出した値ではない)
        ->and($epoch)->not->toBe(SessionEpoch::forSession($sessionId))
        // prop と cookie が同じ時点のセッション ID から出ていること
        ->and($epoch)->toBe(issuedSessionEpochCookie($response));
});

test('ログイン応答の世代 cookie は再生成後のセッション ID 由来になる', function (): void {
    $sessionId = Str::random(40);
    User::factory()->create(['email' => 'epoch-prop@example.com']);

    $login = $this->withCookie((string) config('session.cookie'), $sessionId)->post('/login', [
        'email' => 'epoch-prop@example.com',
        'password' => 'password',
    ]);

    $issued = issuedSessionEpochCookie($login);

    expect($issued)->not->toBeNull()
        ->and($issued)->not->toBe(SessionEpoch::forSession($sessionId));
});

test('部分再読み込みで別 prop だけを要求しても描画世代は載る', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');
    $component = $response->viewData('page')['component'];

    $partial = $this->actingAs($owner)->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $response->viewData('page')['version'],
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'title',
    ]);

    $props = $partial->json('props');

    expect($props)->toBeArray()
        ->and($props)->toHaveKey(SessionEpoch::SHARED_PROP_KEY)
        ->and($props[SessionEpoch::SHARED_PROP_KEY])->toBeString();
});
