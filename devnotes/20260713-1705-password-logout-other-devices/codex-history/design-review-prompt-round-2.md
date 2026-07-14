# 詳細設計レビュー Round 2

Round 1 の指摘に対応しました。対応マトリクスと修正差分です。再レビューをお願いします。

## Round 1 指摘への対応

### [Critical] (c)/(d) 同一クライアント汚染（施策3）→ 対応（deterministic 再設計）
テストを 2 責務に分離:
- (a)(b)(e) = 施策2 のオーケストレーション（実 PUT `/user/password`、単一クライアント）
- (c)(d) = 施策1（AuthenticateSession の password_hash 照合 = correctness）。device 分離のため
  **device A の変更を out-of-band の DB ハッシュ変更（guard を立てない）**にし、device B は
  **実ログインで capture した session / recaller cookie の明示 replay** に限定。両デバイスの guard 状態を完全分離。
end-to-end は「施策2 が hash を変える → 施策1 が失効させる」の合成として (a)+(b)+(c) がカバー。

### [Warning] (a) の命名逆転 → 対応
`victim-current` を廃し、実際の `session()->getId()` と一致させた現在行（残存検証）/ `attacker-session`
（削除検証）/ `other-user-session`（別ユーザー・残存）に整理。現在 session id を取得してから upsert。
現在行残存が id ズレで不安定な場合は削除検証＋別ユーザー残存を必須とし現在行残存は (b) に委ねる縮退案も明記。

### [Warning] (d) 暗号化 cookie 不安定 → backup を第一級手順化
「旧 `password_hash_web` セッションを実ログインで確立 → out-of-band hash 変更 → session cookie replay で
logout 確認」を具体手順として (d) 節に昇格（recaller 暗号化取り回しに依存しない安定版）。

### [Warning] `session()->getId()` に isStarted ガード（施策2）→ 対応
`deleteOtherSessionRecords` 先頭に `if (! session()->isStarted()) { return; }` を追加。

### [Warning] 施策1 の Fortify 全経路の回帰観点 → 対応
DoD に個別回帰チェックリスト（login / two-factor-challenge / user/confirm-password / SSO callback /
password reset / actingAs）を追加。

## 修正後の該当セクション（抜粋）

### 施策2 deleteOtherSessionRecords（ガード追加後）
```php
private function deleteOtherSessionRecords(User $user): void
{
    if (config('session.driver') !== 'database') {
        return;
    }
    if (! session()->isStarted()) {
        return;
    }

    $connection = config('session.connection');
    $table = config('session.table', 'sessions');

    Assert::nullOrString($connection);
    Assert::string($table);

    try {
        DB::connection($connection)
            ->table($table)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', session()->getId())
            ->delete();
    } catch (Throwable $e) {
        report($e);
    }
}
```

### 施策3 (c) の骨子（device 分離）
```php
test('パスワード(ハッシュ)変更後、旧ハッシュの別セッションは次リクエストで失効する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $sessionCookieName = config('session.cookie');
    $sessionCookie = $login->getCookie($sessionCookieName);

    // device A: out-of-band にハッシュ変更（guard に触れない）
    User::query()->whereKey($user->id)->update(['password' => Hash::make('NewPassword12345')]);

    // device B: capture した session cookie のみで保護ルート → login へ
    $this->flush(); // session flush + cookie jar reset
    $this->withCookie($sessionCookieName, $sessionCookie->getValue())
        ->get('/dashboard')
        ->assertRedirect('/login');
});
```

残課題があれば指摘してください。無ければ全体判定 APPROVED をお願いします。上記以外の詳細設計本文
（施策1 の authenticateSessions() 有効化、施策2 の順序と best-effort、(a)(b)(d)(e) の骨子、DoD、
follow-up）は Round 1 提示から本対応分のみ変更しています。
