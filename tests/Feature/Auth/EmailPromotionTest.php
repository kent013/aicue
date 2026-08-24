<?php

declare(strict_types=1);

use App\Contracts\Auth\EmailPromotionStageBoundary;
use App\Enums\SecurityEventType;
use App\Http\Requests\Auth\ConfirmEmailPromotionRequest;
use App\Mail\EmailPromotionMail;
use App\Models\EmailPromotion;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\Auth\EmailPromotionService;
use App\Services\Auth\InertEmailPromotionStageBoundary;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Auth\InterferingEmailPromotionStageBoundary;

/*
 * メールアドレスの昇格 (E1)。
 *
 * ★昇格フローも**メールで利用者を引かない**。引き当ての鍵は常に自分自身であり、
 *   メール文字列は「その利用者に紐づける値」としてしか現れない。
 */

function promotionUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['email' => null, 'email_verified_at' => now()])->save();

    return $user->fresh() ?? $user;
}

/** 発行して平文トークンを取り出す (メールの本文から)。 */
function issuePromotion(User $user, string $email = 'new@corp.example'): string
{
    Mail::fake();

    test()->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->post(route('settings.email-promotion.store'), ['email' => $email])
        ->assertSessionHas('success');

    $token = null;
    // ★Mailable は ShouldQueue なのでキューへ載る (assertSent ではなく assertQueued)。
    Mail::assertQueued(EmailPromotionMail::class, function (EmailPromotionMail $mail) use (&$token): bool {
        $rendered = $mail->render();
        preg_match('/token=([A-Za-z0-9_-]+)/', $rendered, $matches);
        $token = $matches[1] ?? null;

        return true;
    });

    expect($token)->toBeString();

    return (string) $token;
}

test('トークンを踏むまで昇格しない', function (): void {
    $user = promotionUser();
    issuePromotion($user);

    expect($user->fresh()?->email)->toBeNull();
    expect(EmailPromotion::query()->count())->toBe(1);
});

test('確認画面 (GET) は状態を変えない', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    $this->actingAs($user)
        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]))
        ->assertSuccessful();

    expect($user->fresh()?->email)->toBeNull();
    expect(EmailPromotion::query()->count())->toBe(1);
});

test('確認画面は Inertia ではない (トークンが履歴へ載る経路が存在しない)', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    $response = $this->actingAs($user)
        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]));

    $response->assertHeaderMissing('X-Inertia');
    expect($response->getContent())->not->toContain('data-page');
});

test('確認画面がトークンを hidden 項目として描き、no-referrer と no-store を持つ', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    $response = $this->actingAs($user)
        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]));

    $body = (string) $response->getContent();
    expect($body)->toContain('name="token"');
    expect($body)->toContain('type="hidden"');
    expect($body)->toContain('<meta name="referrer" content="no-referrer">');
    $response->assertHeader('Cache-Control', 'no-store, private');
});

test('確認画面が外部リソースを 1 つも読み込まない', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    $body = (string) $this->actingAs($user)
        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]))
        ->getContent();

    expect($body)->not->toContain('<link');
    expect($body)->not->toContain('<script');
    expect($body)->not->toContain('<img');

    // ★本文に現れる絶対 URL は**自分自身の host だけ**である
    //   (form の action は同一オリジンなので許される。外部 host が 1 つでもあれば Referer の経路ができる)。
    preg_match_all('#https?://[^"\'\s>]+#', $body, $matches);
    $ownHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    foreach ($matches[0] as $url) {
        expect(parse_url($url, PHP_URL_HOST))->toBe($ownHost);
    }
});

test('確認画面はトークンの有効・無効で変わらない (存在の探り当てを作らない)', function (): void {
    $user = promotionUser();
    $valid = issuePromotion($user);

    $withValid = $this->actingAs($user)
        ->get(route('settings.email-promotion.confirm.show', ['token' => $valid]));
    $withInvalid = $this->actingAs($user)
        ->get(route('settings.email-promotion.confirm.show', ['token' => 'never-issued']));

    expect($withValid->getStatusCode())->toBe($withInvalid->getStatusCode());
    // トークンの値だけが違う (画面の構造は同じ)
    expect(str_replace($valid, 'X', (string) $withValid->getContent()))
        ->toBe(str_replace('never-issued', 'X', (string) $withInvalid->getContent()));
});

test('POST で確定すると email と email_verified_at が更新される', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);
    $before = $user->fresh()?->email_verified_at;

    $this->travelTo(now()->addMinute());

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertRedirect(route('settings.security'))
        ->assertSessionHas('success');

    $fresh = $user->fresh();
    expect($fresh?->email)->toBe('new@corp.example');
    // ★「以前の値のまま」にせず、新しいメールを確認した時刻へ更新する
    expect($fresh?->email_verified_at?->greaterThan($before))->toBeTrue();
    expect(EmailPromotion::query()->count())->toBe(0);
});

test('確定後はパスワード再設定が使えるようになる (昇格前は宛先が無い)', function (): void {
    $user = promotionUser();
    expect($user->routeNotificationFor('mail'))->toBeNull();

    $token = issuePromotion($user);
    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);

    expect($user->fresh()?->routeNotificationFor('mail'))->toBe('new@corp.example');
});

test('同じトークンは 2 回使えない', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');
});

test('他人のトークンでは昇格しない (user_id の結合が認可そのものである)', function (): void {
    $owner = promotionUser();
    $token = issuePromotion($owner);
    $attacker = promotionUser();

    $this->actingAs($attacker)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');

    expect($attacker->fresh()?->email)->toBeNull();
    // ★他人の行を消してもいない
    expect(EmailPromotion::query()->where('user_id', $owner->id)->count())->toBe(1);
});

test('期限切れのトークンは拒否される', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    EmailPromotion::query()->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');

    expect($user->fresh()?->email)->toBeNull();
});

test('再送で旧トークンが失効する', function (): void {
    $user = promotionUser();
    $first = issuePromotion($user);

    Mail::fake();
    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->post(route('settings.email-promotion.resend'), ['email' => 'second@corp.example'])
        ->assertSessionHas('success');

    // 利用者ごとに未消費は 1 件だけ
    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(1);

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $first])
        ->assertSessionHasErrors('email_promotion');
});

test('確認済みメールが既存利用者と重なったとき、既存を一切変更せず一様に断る', function (): void {
    $existing = User::factory()->create(['email' => 'taken@corp.example']);
    $existingName = $existing->name;

    $user = promotionUser();
    $token = issuePromotion($user, 'taken@corp.example');

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertRedirect(route('settings.security'))
        ->assertSessionHasErrors('email_promotion');

    // ★既存利用者は 1 バイトも変わっていない (併合もしない)
    $freshExisting = $existing->fresh();
    expect($freshExisting?->email)->toBe('taken@corp.example');
    expect($freshExisting?->name)->toBe($existingName);
    // ★昇格も起きていない
    expect($user->fresh()?->email)->toBeNull();
});

test('衝突の応答が「無効なトークン」と見分けられない (存在を漏らさない)', function (): void {
    User::factory()->create(['email' => 'taken@corp.example']);
    $user = promotionUser();
    $conflicting = issuePromotion($user, 'taken@corp.example');

    $conflict = $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $conflicting]);
    $invalid = $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => 'never-issued']);

    expect($conflict->getStatusCode())->toBe($invalid->getStatusCode());
    expect($conflict->headers->get('Location'))->toBe($invalid->headers->get('Location'));
});

test('blind index 以外の一意制約違反は握り潰さない (負のコントロール)', function (): void {
    $user = promotionUser();
    issuePromotion($user);

    // 同じ利用者の 2 件目を直接作ると `email_promotions_user_unique` に当たる。
    // ★これは blind index の違反ではないので、一様な応答へ畳まず**そのまま伝播する**。
    expect(fn () => DB::table('email_promotions')->insert([
        'user_id' => $user->id,
        'token_fingerprint' => str_repeat('a', 64),
        'email_encrypted' => 'x',
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('トークンは原文で保存されない (指紋だけ)', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    /** @var object{token_fingerprint: string} $raw */
    $raw = DB::table('email_promotions')->where('user_id', $user->id)->first();

    expect($raw->token_fingerprint)->not->toContain($token);
    expect(json_encode((array) $raw, JSON_THROW_ON_ERROR))->not->toContain($token);
});

test('ログにトークンが出ない', function (): void {
    $records = [];
    Log::listen(function (MessageLogged $event) use (&$records): void {
        $records[] = $event->message.json_encode($event->context);
    });

    $user = promotionUser();
    $token = issuePromotion($user);
    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);

    expect(implode("\n", $records))->not->toContain($token);
});

test('確定に失敗してもトークンが old input に残らない', function (): void {
    $user = promotionUser();

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => 'never-issued']);

    /** @var array<string, mixed> $old */
    $old = session()->get('_old_input', []);
    expect($old)->not->toHaveKey('token');
});

test('validation の失敗でもトークンが old input に残らない', function (array $payload): void {
    $user = promotionUser();

    // ★Laravel は validation の失敗時、controller へ到達する**前に**入力を退避する。
    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), $payload)
        ->assertRedirect(route('settings.security'));

    /** @var array<string, mixed> $old */
    $old = session()->get('_old_input', []);
    expect($old)->not->toHaveKey('token');
    expect(json_encode($old, JSON_THROW_ON_ERROR))->not->toContain('super-secret-token');
})->with([
    // ★**規則上たしかに不正になる値**を使う (上限から生成する)。
    //   短い値だと validation を通ってしまい、`failedValidation()` の回帰にならない
    //   (controller が withInput() を使わないことしか測れない)。
    'トークンが長すぎる' => [['token' => 'super-secret-token'.str_repeat('x', AttemptFingerprint::HEX_LENGTH * 4)]],
    'トークンが無い' => [[]],
    'トークンが配列' => [['token' => ['super-secret-token']]],
]);

test('確認の入力規則が、テストが送る値を実際に不正と判定する (空振りしていないことの証明)', function (mixed $token, bool $shouldFail): void {
    // ★データセットの値が**規則上たしかに不正**であることを直接固定する。
    //   短い値のまま「old input に無い」だけを見ると、validation を通っていても緑になり、
    //   `failedValidation()` の回帰にならない (Round 1 の Critical が再発しても気付けない)。
    $rules = (new ConfirmEmailPromotionRequest)->rules();
    $validator = Validator::make(['token' => $token], $rules);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    '上限ちょうどは通る' => [str_repeat('x', AttemptFingerprint::HEX_LENGTH * 4), false],
    '上限 + 1 は落ちる' => [str_repeat('x', AttemptFingerprint::HEX_LENGTH * 4 + 1), true],
    '配列は落ちる' => [['x'], true],
    '空は落ちる' => ['', true],
]);

test('衝突してもトークンは消費済みで、同じトークンを再利用できない', function (): void {
    User::factory()->create(['email' => 'taken@corp.example']);
    $user = promotionUser();
    $token = issuePromotion($user, 'taken@corp.example');

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');

    // ★消費 (行の削除) は commit 済みである (同じトランザクションで巻き戻さない)
    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);

    // ★同じトークンの 2 回目は無効である
    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');

    expect($user->fresh()?->email)->toBeNull();
});

test('既にメールを持つ利用者は発行できない (既存の変更経路を迂回させない)', function (): void {
    Mail::fake();
    $user = User::factory()->create(['email' => 'existing@corp.example']);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->post(route('settings.email-promotion.store'), ['email' => 'new@corp.example'])
        ->assertSessionHasErrors('email_promotion');

    Mail::assertNothingQueued();
    expect(EmailPromotion::query()->count())->toBe(0);
    expect($user->fresh()?->email)->toBe('existing@corp.example');
});

test('発行後に別経路でメールが入ったら確定できない', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    // 別経路でメールが入る
    $user->forceFill(['email' => 'other@corp.example'])->save();

    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');

    expect($user->fresh()?->email)->toBe('other@corp.example');
});

test('確定を監査に残す (トークンも平文のメールも載せない)', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);

    $event = SecurityAuditEvent::query()
        ->where('user_id', $user->id)
        ->where('event_type', SecurityEventType::EmailChanged->value)
        ->firstOrFail();

    $encoded = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($token);
    expect($encoded)->not->toContain('new@corp.example');
});

test('確認メールはキュー payload を暗号化する (jobs 表からトークンを読めない)', function (): void {
    // ★private property でも job payload には直列化される。ShouldBeEncrypted が無いと
    //   キューを読める主体がトークンと宛先を取り出して利用者として確定できてしまう。
    expect(is_subclass_of(EmailPromotionMail::class, ShouldBeEncrypted::class))->toBeTrue();
});

test('4 route とも未認証では到達できない', function (string $method, string $name): void {
    $this->call($method, route($name))->assertRedirect(route('login'));
})->with([
    ['POST', 'settings.email-promotion.store'],
    ['POST', 'settings.email-promotion.resend'],
    ['GET', 'settings.email-promotion.confirm.show'],
    ['POST', 'settings.email-promotion.confirm'],
]);

test('発行と再送は再認証なしで弾かれる', function (string $name): void {
    $user = promotionUser();

    $this->actingAs($user)
        ->post(route($name), ['email' => 'new@corp.example'])
        ->assertRedirect(route('recent-auth.confirm'));
})->with([
    'settings.email-promotion.store',
    'settings.email-promotion.resend',
]);

test('確認には再認証を課さない (救済経路に関門を足すと詰む)', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    // ★recent-auth のセッションを持たないまま確定できる
    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHas('success');

    expect($user->fresh()?->email)->toBe('new@corp.example');
});

test('メールを持たない利用者の設定画面に登録の導線が出る', function (): void {
    $user = promotionUser();

    $this->actingAs($user)->get(route('settings.security'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('canPromoteEmail', true));
});

test('メールを持つ利用者には導線が出ない (既存の変更経路を使う)', function (): void {
    $user = User::factory()->create(['email' => 'existing@corp.example']);

    $this->actingAs($user)->get(route('settings.security'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('canPromoteEmail', false));
});

test('本番の継ぎ目は何もしない (公開入口は confirm() 1 本のまま)', function (): void {
    // ★2 段を公開メソッドにしていないことの担保。継ぎ目の本番実装が不活性であること
    //   (= 操作面が広がっていないこと) を container の解決で直接固定する。
    expect(app(EmailPromotionStageBoundary::class))->toBeInstanceOf(InertEmailPromotionStageBoundary::class);

    $reflection = new ReflectionClass(EmailPromotionService::class);
    foreach (['consumeToken', 'applyConfirmedEmail'] as $stage) {
        expect($reflection->getMethod($stage)->isPrivate())->toBeTrue(
            "{$stage}() は private であること: 公開するとトークンの照合・期限・本人結合を迂回する"
            .'第 2 の入口ができます。'
        );
    }
});

test('消費の確定と適用の間に別経路がメールを入れたら、その更新を上書きしない', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    // ★**実際の継ぎ目で**割り込む。第 1 段 (消費) のトランザクションは既に閉じている。
    //   モデルイベントの listener に頼ると割り込みが**第 1 段の内側**で走ってしまい、
    //   「閉じた後」という筋書きにならない (かつ listener の全削除は後続テストを汚す)。
    //   段そのものを公開する代わりに、継ぎ目だけを差し替える。
    $boundary = new InterferingEmailPromotionStageBoundary(encryptedEmailFor('other@corp.example'));
    $this->app->instance(EmailPromotionStageBoundary::class, $boundary);

    $levelBefore = DB::transactionLevel();

    // ★第 2 段はロックの下で読み直し、**上書きしない** (入口は confirm() のまま)
    expect(app(EmailPromotionService::class)->confirm($user, $token))->toBeFalse();

    // ★継ぎ目では第 1 段が開いた層がすべて閉じている (= 段を抜けている)。
    //   `RefreshDatabase` の外側の層があるので「commit 済み」ではなく
    //   **呼び出し前の level へ戻っている**ことを固定する。
    expect($boundary->transactionLevelAtSeam)->toBe($levelBefore);

    // ★別経路の更新が残る
    expect($user->fresh()?->email)->toBe('other@corp.example');
    // ★トークンは消費済みである (一回使用は保たれる)
    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);
    // ★昇格の監査は作られない (適用していないため)
    expect(SecurityAuditEvent::query()
        ->where('user_id', $user->id)
        ->where('event_type', SecurityEventType::EmailChanged->value)
        ->count())->toBe(0);
    // ★同じトークンは再利用できない
    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');
});

test('割り込みが無ければ第 2 段は適用する (正のコントロール)', function (): void {
    // ★弾く側だけを固定して「常に false」でも緑になる形にしない。
    //   継ぎ目は本番のまま (何もしない) である。
    $user = promotionUser();
    $token = issuePromotion($user);

    expect(app(EmailPromotionService::class)->confirm($user, $token))->toBeTrue();
    expect($user->fresh()?->email)->toBe('new@corp.example');
});

test('監査を記録できなければメールの変更も巻き戻る (記録の無い変更を作らない)', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    // ★監査の書き込みを**挿入の後**で壊す。`creating` で止めると行がそもそも生まれないので
    //   「監査行が無い」は巻き戻しの証拠にならない (壊し方が弱いと主張が空振りする)。
    //   `created` なら**一度は挿入されている**ので、外側の「監査行も無い」が
    //   巻き戻しそのものを固定する。
    // ★後始末は `flushEventListeners()` (そのモデルの**全 event** を静的に削除する) ではなく、
    //   **張った 1 つの event 名だけ**を忘れさせる。モデルに trait / observer が足された日に
    //   後続テストを汚す形にしない (`EmailPromotion` は `UsesCipherSweet` が
    //   `retrieved` / `saving` / `saved` を張るので、全削除は暗号化を殺す)。
    $listened = 'eloquent.created: '.SecurityAuditEvent::class;

    SecurityAuditEvent::created(static function (SecurityAuditEvent $event): never {
        // ★この時点では挿入済みで見える (巻き戻しが効いていることを外側と対で示す)
        expect(SecurityAuditEvent::query()->whereKey($event->getKey())->exists())->toBeTrue();

        throw new RuntimeException('監査の書き込みに失敗した');
    });

    try {
        expect(fn () => app(EmailPromotionService::class)->confirm($user, $token))
            ->toThrow(RuntimeException::class);
    } finally {
        Event::forget($listened);
    }

    $fresh = $user->fresh();
    // ★メールは入っていない
    expect($fresh?->email)->toBeNull();
    // ★確認時刻も昇格の時刻へ動いていない
    expect($fresh?->email_verified_at?->equalTo($user->email_verified_at))->toBeTrue();
    // ★監査行も無い
    expect(SecurityAuditEvent::query()
        ->where('user_id', $user->id)
        ->where('event_type', SecurityEventType::EmailChanged->value)
        ->count())->toBe(0);
    // ★トークンは第 1 段で消費済みである (設計どおり戻さない)
    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);
});

/** CipherSweet で暗号化した email 値 (別経路の更新を模すための補助)。 */
function encryptedEmailFor(string $email): string
{
    $row = User::getCipherSweetEncryptedRow();

    /** @var array<string, mixed> $encrypted */
    $encrypted = $row->encryptRow(['email' => $email, 'name' => 'x']);

    /** @var string $value */
    $value = $encrypted['email'];

    return $value;
}
