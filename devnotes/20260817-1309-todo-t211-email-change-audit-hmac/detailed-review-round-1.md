**全体判定: CHANGES_REQUESTED**

設計の方向は妥当です。`EmailHash` を再利用し、保存前に HMAC を算出し、`record()` の best-effort 性を維持する判断も既存規約と整合しています。ただし、受け入れ条件とテストに 1 つ実態と矛盾する不変条件が入っています。

**施策 1: REQUEST_CHANGES**

[Warning] `old_email_hash` と `new_email_hash` が常に異なる前提は成り立ちません。  
`EmailHash::compute()` は `trim` + 小文字化後に HMAC するため、現行の変更判定が `$email === $user->email` のままなら、`User@example.com` → `user@example.com` のような変更で監査行は作られつつ 2 値は一致し得ます。設計書の「保証しないもの」では正しく宣言していますが、受け入れ条件 A3 とテストの `not->toBe()` がそれに反しています。

修正案: A3 から「互いに異なる」を外してください。テストも以下の形に寄せるのが安全です。

```php
expect($decoded['old_email_hash'])->toMatch('/^[0-9a-f]{64}$/')
    ->and($decoded['new_email_hash'])->toMatch('/^[0-9a-f]{64}$/');
```

必要なら別テストで「大文字小文字だけの変更では 2 値が一致し得る」ことを明示して、将来の読者がこの値を分岐条件に使わないよう固定する方がよいです。

[Suggestion] `$oldEmail = $user->email` については、PHPStan が通らなければ `Assert::string($oldEmail)` を足す方針で問題ありません。ただし設計段階では「larastan が string に解決するはず」ではなく、「落ちたら同ファイル内で narrow する」と受け入れ条件側にも明記しておくと実装者が迷いません。

**施策 2: REQUEST_CHANGES**

[Warning] テスト名と A3 が実態より強い保証を表明しています。  
`email_changed の監査 metadata は鍵つきハッシュだけで生アドレスを含まない` の目的はよいですが、最後の `old_email_hash` と `new_email_hash` の不一致検査は「この fixture では違う」以上の意味を持ちません。設計書全体では「一致し得る」と書いているため、ここは削除してください。

修正案: 2 本目のテストは「キーがちょうど 2 つ」「値が 64 桁 hex」「生文字列が raw JSON にない」までにする。1 本目で具体値一致を見ているので、十分に赤になります。

[Suggestion] `DB::table(...)->where('event_type', 'email_changed')->value('metadata')` は FreshDatabase 前提なら動きますが、読み手の安心感として `where('user_id', $user->id)` も足すとよいです。並列実行では DB 分離されるはずですが、同一テスト内の副作用が増えた時にも対象行が明確になります。

**承認できる点**

`recordOrFail()` にしない判断、migration を作らない判断、DTO/JsonResource を追加しない判断、Inertia/API に露出しない整理はいずれも妥当です。Architecture 目録を変更しない理由も、記録経路と covered_by が変わらない前提なら筋が通っています。

修正後の受け入れ条件は「2 値が `EmailHash::compute(旧/新)` と一致する」「生アドレスが保存 JSON に含まれない」「キーがちょうど 2 つ」に絞れば、機械検証可能で誇張のない設計になります。