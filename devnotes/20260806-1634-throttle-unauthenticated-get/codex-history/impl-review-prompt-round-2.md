Round 1 の指摘 3 件はすべて対応した (対応マトリクス:
devnotes/20260806-1634-throttle-unauthenticated-get/codex-history/impl-review-decisions-round-1.md)。
修正差分は以下のとおり。再レビューして全体判定を出してほしい。

## 修正 1: [Warning] 2FA 秘密 GET の レーン共有テストを 3 本すべてに拡張

`tests/Feature/Security/AuthThrottleCoverageTest.php`

```php
test('2FA 秘密 GET 3 本は 1 つのレーンを共有する (描画で複数発飛ぶ GET を合算して数える)', function (): void {
    // qr-code / secret-key は 2FA 設定画面の 1 描画で 2 発飛ぶ。両者が別 bucket だと
    // 「画面を開く回数」ではなく「endpoint ごとの回数」を数えることになり、
    // 秘密の連続取得の上限としては実効が薄れる。同一 limiter 名を共有していることを示す。
    // ★3 本すべてを対象にする。1 本でも別 limiter (inline `10,1` 等) に戻ると
    //   残数が連続しなくなりここで落ちる。
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $uris = ['/user/two-factor-qr-code', '/user/two-factor-secret-key', '/user/two-factor-recovery-codes'];
    $previous = null;

    foreach ($uris as $uri) {
        $remaining = $this->get($uri)->headers->get('X-RateLimit-Remaining');
        expect($remaining)->not->toBeNull("{$uri} に X-RateLimit-* が付いていません (throttle が効いていない)");

        if ($previous !== null) {
            expect((int) $remaining)->toBe($previous - 1,
                "{$uri} が他の 2FA 秘密 GET と別 bucket へ分かれています");
        }
        $previous = (int) $remaining;
    }
});
```

補足: `two-factor.recovery-codes` には recent-auth middleware が後付けされているため
GET は 409/302 で返るが、throttle は recent-auth より先に走る (同ファイルの実効順テストが
`two-factor.recovery-codes` を含めて固定している) ため `X-RateLimit-*` は付き、枠も消費される。
実測でも 3 本の残数が連続して減ることを確認した。

## 修正 2: [Suggestion] 8-3 に「枠を消費している」ことの assert を追加

```php
    Http::preventStrayRequests();
    Socialite::spy();

    $first = $this->get('/auth/google/callback?code=dummy&state=dummy');
    $second = $this->get('/auth/google/callback?code=dummy&state=dummy');

    // ★「Socialite を呼ばない」だけでは半分。**枠を消費している**ことまで示して初めて
    //   「外向き HTTP の増幅が有界」になる (呼ばれず数えられもしないなら無制限に踏める)。
    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
        'Socialite に到達しない無効リクエストが枠を消費していません (連打で増幅を狙える)',
    );
    Socialite::shouldNotHaveReceived('driver');
```

## 修正 3: [Suggestion] /register の invitation token 分岐の前提テストを追加

`tests/Feature/Security/ThrottleExemptionPremiseTest.php`

```php
test('register の invitation token 分岐も DB 書込を発行しない (read 1 件で済むことの固定)', function (): void {
    // 上の代表 GET は token 無しの経路しか通らない。register は session に
    // invitation_token があると OrganizationMembershipService::resolveRegisterPrefillEmail() が
    // 招待を 1 件 read する **別の分岐**を持つため、そちらも読み取りに留まることを固定する
    // (「prefill のついでに何かを書く」実装へ変わったら exemption 理由が崩れる)。
    $this->withSession(['invitation_token' => 'probe-token-that-does-not-exist']);

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/register');
    expect($response->getStatusCode())->toBe(200);

    // ★分岐に入ったことの確認 (入っていなければ token 無しのテストと同じものを 2 回
    //   走らせているだけ = 空振り green になる)。
    $invitationReads = array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'organization_invitations'),
    ));
    expect($invitationReads)->not->toBe([], 'invitation token 分岐に入っていません (テストが空振りしています)');

    $writes = array_values(array_filter($queries, throttlePremiseIsWriteStatement(...)));
    expect($writes)->toBe([], '/register (token あり分岐) が DB 書込を発行しました: '.implode(' / ', $writes));
});
```

なお `resolveRegisterPrefillEmail()` は無効 token のとき
`$session->forget('invitation_token')` を行う (自セッション内の汚染値除去) が、
これは `AuthViewRenderOnly` の適用条件「副作用が自セッションの中に閉じる」に合致し、
`SESSION_DRIVER=array` (phpunit.xml で force 固定) のため DB 書込としては観測されない。

## テスト結果 (修正後)

- `composer test -- --filter="AuthThrottleCoverageTest|ThrottleExemptionPremiseTest"`:
  tests=48 passed=48 failed=0 assertions=196
