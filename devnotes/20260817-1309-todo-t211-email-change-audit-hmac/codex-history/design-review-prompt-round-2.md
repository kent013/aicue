Round 1 の指摘への対応が終わったので再レビューをお願いします。

## 対応マトリクス (Round 1)

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning (施策 1) | 「2 値が互いに異なる」は実態に反する (正規化を挟まない変更判定のため一致し得る) | 対応する | 受け入れ条件 A3 から削除し、条件にしない旨を理由つきで明記 |
| 2 | Warning (施策 2) | 2 本目のテストの不一致検査を削除せよ | 対応する | `not->toBe(...)` を削除。理由をテスト内コメントとして残した |
| 3 | Suggestion | `$user->email` の型は「string に解決するはず」ではなく「落ちたら narrow する」と受け入れ条件に書くべき | 対応する | A7 に `Assert::string($oldEmail)` で narrowing する旨と、`@phpstan-ignore` / widen / baseline を使わない旨を追記 |
| 4 | Suggestion | 生 JSON を引くクエリに `where('user_id', $user->id)` を足す | 対応する | 条件を 1 つ追加 |
| 5 | Suggestion | 「大文字小文字だけの変更では 2 値が一致し得る」ことを別テストで明示してもよい | **見送る** | そのテストは**現行の変更判定の挙動を固定してしまう**。正規化の是正は本件のスコープ外の別 TODO 候補であり、そこで挙動が変わると本件と無関係の理由でこのテストが赤くなる。代わりに実装コメント・テストコメント・設計の「保証しないもの」の 3 か所に文章として残した |

見送り (#5) について異論があれば根拠を添えて指摘してください。

## 修正後の該当箇所 (差分のみ)

### 受け入れ条件 A3 (修正後)

> A3 | metadata のキーは `old_email_hash` / `new_email_hash` のちょうど 2 つで、値はいずれも `/^[0-9a-f]{64}$/` に一致する。**2 値が互いに異なることは条件にしない** (正規化を挟まない変更判定のため一致し得るため) | 2 本目のテスト

### 受け入れ条件 A7 (修正後)

> A7 | 型を緩めずに level 10 を通る。`$user->email` が `string` に解決されず引数型で落ちた場合は、**同ファイルで既に使っている `Assert::string($oldEmail)` で narrowing して通す** (`@phpstan-ignore` / 型の widen / baseline は使わない) | `composer phpstan` がエラー 0

### 施策 2 の 2 本目のテスト (修正後の全文)

```php
test('email_changed の監査 metadata は鍵つきハッシュだけで生アドレスを含まない', function (): void {
    $user = User::factory()->create(['email' => 'before@example.com']);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->put('/user/profile-information', [
            'name' => '変更後の名前',
            'email' => 'after@example.com',
        ])
        ->assertSessionHasNoErrors();

    // ★モデルの cast を通した配列ではなく、**DB に保存された JSON 文字列**を見る
    //   (cast 越しでは「保存された文字列に平文が混ざっていないこと」を言えないため)。
    $raw = DB::table('security_audit_events')
        ->where('event_type', 'email_changed')
        ->where('user_id', $user->id)
        ->value('metadata');
    expect($raw)->toBeString();
    $json = (string) $raw;

    // 局所部・ドメインのいずれも現れない。3 語とも hex (0-9a-f) 以外の文字を含むため、
    // 64 桁のハッシュ値に偶然含まれることはない ('o' / 't' / 'x' が hex ではない)。
    expect($json)->not->toContain('before')
        ->and($json)->not->toContain('after')
        ->and($json)->not->toContain('example.com');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    // ★2 値が互いに異なることは**検査しない**。変更判定が正規化を挟まないため、
    //   大文字小文字だけが違う変更では 2 値が一致し得る (観測専用なので一致に意味を持たせない)。
    expect(array_keys($decoded))->toBe(['old_email_hash', 'new_email_hash']);
    expect($decoded['old_email_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($decoded['new_email_hash'])->toMatch('/^[0-9a-f]{64}$/');
});
```

他の節 (施策 1 の実装コード・テストファースト計画・波及変更・保証しないもの・実装モード) は
Round 1 で提示したものから変更していません。

全体判定 (APPROVED / CHANGES_REQUESTED) と、残る指摘があればその分類・修正案をお願いします。
