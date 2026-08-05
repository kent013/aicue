全体判定: **CHANGES_REQUESTED**

Round 1 よりかなり強くなっていますが、まだ「CI が落ちる」と言える保証に穴があります。特に **検出 B は Service 側 global fetch の抜け道を閉じていません**。source を inventory 化しても、その id が後段でどう使われるかを機械的に縛らない限り、sink の移動を検出できません。

**1. 使命との整合**

[Suggestion] 使命との整合は妥当です。SOP・動画マニュアルは組織資産なので、cross-org read/write を CI で防ぐ gate は North Star の前提を守るものです。

**2. 禁止事項違反**

[Suggestion] 設計自体に禁止事項違反は見えません。Architecture テスト化、PHPStan baseline 不使用、アプリコード非変更の方針も妥当です。

[Warning] ただし「不変条件 3 はこの gate で機械強制済み」と言うにはまだ早いです。現設計は source/sink の一部を inventory 化しますが、relation/org-scoped 解決を一般に強制できていません。  
修正提案: 完了条件を「A/B inventory が green」ではなく、「代表的な抜け道 fixture が fail する」まで含めて定義してください。特に Service 委譲、builder alias、qualified id、request accessor variant は必須です。

**3. 実現可能性**

[Critical] 検出 A は「同一 chain の静的 root」に依存しており、builder alias で簡単に抜けます。

```php
$query = User::query();
$query->where('id', $request->input('user_id'))->firstOrFail();
```

これは token_get_all でも比較的実装可能な範囲の抜け道です。  
修正提案: 同一メソッド内に限って、`$q = User::query()` / `$q = DB::table('users')` のような builder alias を追跡し、alias 変数に対する PK predicate も候補化してください。完全データフローでなく、単純代入・再代入で invalidation する保守的な実装で十分です。

[Warning] 述語アンカーの文法がまだ狭いです。`where('users.id', ...)`、`where('u.id', ...)`、`whereId($id)`、`where(['id' => $id])`、`where([['id', '=', $id]])`、`whereIntegerInRaw('id', ...)`、`getQualifiedKeyName()`、`DB::connection()->table(...)`、`Model::destroy($id)` が未定義です。  
修正提案: 「対応する構文」と「非対応だが source B / 別 gate で見る構文」を明文化し、positive/negative fixture に入れてください。少なくとも qualified id、array where、whereId、destroy は実装対象に寄せるべきです。

[Warning] `where('id', ...)` を「主キー同一性」と呼ぶなら、`where('id', '>', $cursor)` は誤検出です。一方で `whereKeyNot` は同一性というより除外述語です。  
修正提案: 名前を `PrimaryKeyConstrainedStaticQuery` に寄せるか、等価・IN・find 系だけに絞ってください。現在の名称と検出内容がずれています。

**4. 検出規則の妥当性**

[Critical] 検出 B は Service 委譲の抜け道を塞いでいません。source を登録しても、後日その同じ `$userId` を別 Service に渡して Service 側で `User::findOrFail($userId)` しても、source の候補数は増えず inventory は stale になりません。  
修正提案: 次のどちらかにしてください。

1. Service 側も限定的に sink 検出する: `app/Services/**` のうち、メソッド引数・プロパティに `*Id` scalar を持ち、その値を static-root PK query に渡すものだけを候補化する。103 件全体ではなく、request 由来 id が入り得る形に絞る。
2. 検出 B の inventory を構造化する: source ごとに `resolution_contract` を持たせ、`ResolvedThroughTenantRelation` は同一メソッド内で同じ変数が relation `whereKey` に入ること、`DelegatedToScopedService` は呼び出し先 `Class::method` の該当メソッド本文に tenant/membership 制約があることを機械確認する。

[Warning] source B の候補定義は取りこぼしがあります。`$request->validated()` を一度 `$data` に入れて `$data['user_id']`、`$request->safe()->integer('user_id')`、`$request->query('user_id')`、`$request->post('user_id')`、`$request->json('user_id')`、`$request['user_id']`、`request('user_id')`、`data_get($request->validated(), 'user_id')` が未定義です。  
修正提案: request accessor grammar を明文化し、`validated()` / `safe()` / `query()` / `post()` / `json()` / array access / global `request()` までは対象にしてください。

[Warning] `末尾が id/_id` は曖昧です。単純な `id$` だと `valid` も当たり得ます。一方で `userId`、`user_ids`、`ids`、`organizationUlid`、`project_uuid`、`public_id` は漏れます。  
修正提案: key パターンを `id`, `*_id`, `*Id`, `ids`, `*_ids`, `*Ids`, `*_uuid`, `*Uuid`, `*_ulid`, `*Ulid` に明示し、`valid` のような単語末尾一致は除外してください。

**5. entrypoint 層限定の妥当性**

[Critical] `routes/*.php` が母集団に入っていません。route closure に `User::find($request->input('user_id'))` を書けば A/B ともに通る可能性があります。  
修正提案: `routes/*.php` を母集団に含めるか、route closure に業務ロジックを書けない既存 Architecture gate があるなら、その gate 名を設計に明記してください。なければ今回の gate 側で routes を見るべきです。

[Warning] Service 除外の根拠が、検出 B 追加後も成立していません。B は「入口の可視化」であって「後段解決の保証」ではありません。  
修正提案: §6 の「検出 B が source 側で塞ぐ」は削り、限定 Service sink 検出または structured resolution contract に置き換えてください。

**6. スコープの適切さ**

[Warning] `PayloadIdVerifiedInLockedServiceTransaction` は、`lockForUpdate` の存在だけでは tenant 検証を証明しません。ロックは競合制御であって、組織所属検証ではありません。さらに「クラスファイルに `lockForUpdate` がある」では、対象メソッド内にあることすら保証しません。  
修正提案: 根拠文の `Class::method` から実メソッド本文を切り出し、その本文内に `lockForUpdate` と membership/tenant 制約 marker の両方があることを確認してください。可能なら call site がその exact method を呼んでいることも確認対象にしてください。

[Warning] `exists:users,id` を根拠文に出すだけでは、存在オラクルは統制されません。可視化としては意味がありますが、cross-org 不可の不変条件とは別の例外として残ります。  
修正提案: この 2 箇所は `KnownExistenceOracleDebt` のような専用 case または TODO ID 必須にして、通常の準拠 case と同列に見えないようにしてください。

**7. リスク**

[Critical] case ごとの機械副条件が、現状だと濫用抑止として弱いです。

`OwnerScopedQueryConstraint`: 追加の `where('active', true)` でも通ります。  
修正提案: `organization_id`, `user_id`, `team_id`, `whereHas('users')`, `whereBelongsTo($user|$organization)` など、許可する tenant/owner 制約 signature を列挙してください。

`LockedRefetchOfVerifiedModel`: `User::whereKey($requestId)->lockForUpdate()` でも通ります。  
修正提案: identity 引数が `$model->getKey()` / `$model->id` / binding 済み型付き引数由来であることを syntactic に要求し、request/validated/input 由来変数を拒否してください。

`PayloadIdWithCompensatingCheck`: 同一メソッド内に `users()` があるだけでは、同じ id を検証している保証も、取得前に拒否している保証もありません。  
修正提案: 少なくとも同じ id 変数が検証 call に渡ること、かつ marker が候補 fetch の後段チェックなら「補償チェック」として debt 扱いにすることを明記してください。

[Warning] `AuthenticatedActorScope` に「機械条件なし」は広すぎます。完全な provenance 解析は無理でも、部分条件は置けます。  
修正提案: この case は対象ファイルまたは対象 root を狭くし、同一メソッド内に request id accessor が無いことを negative check してください。さらに inventory を prose だけでなく `actor_source: authenticated_user | validated_token_claim | passport_token_record` のような構造化 field にしてください。

**結論**

述語アンカー化は方向として正しいです。ただし現案はまだ「同一 chain の静的 root」と「入口 inventory」に寄りすぎています。承認条件は、少なくとも次の 4 点の反映です。

1. builder alias 追跡を入れる。
2. source B は「塞ぐ」ではなく、structured resolution contract か限定 Service sink 検出で補強する。
3. case 副条件を tenant/owner 制約・検証対象変数・対象メソッド本文に結びつける。
4. routes と request accessor / id key grammar の母集団を明文化して fixture 化する。