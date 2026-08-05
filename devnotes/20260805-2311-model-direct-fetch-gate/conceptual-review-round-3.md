全体判定: **CHANGES_REQUESTED**

Round 3 の方向転換は正しいです。`entrypoint` ではなく provenance で絞る設計にしたことで、Service 委譲の根本穴はかなり閉じています。ただし、今の provenance フィルタは **「モデル由来」と syntactic に見えるものを広く信じすぎている**ため、ここは承認前に詰める必要があります。

**1. 使命との整合**

[Suggestion] 使命との整合は維持されています。組織資産を扱う AI-CUE で、id からの global fetch を Architecture gate にする判断は妥当です。

[Warning] §9 の「今後どんな機能を足しても cross-org 直 fetch が入り込まない」は、§4-6 の「whereRaw / 動的列名 / relation 一般は保証しない」と矛盾気味です。  
修正提案: §9 は「静的起点 + 主キー同一性の直 fetch が分類なしに入り込まない」まで弱めてください。

**2. 禁止事項違反**

[Suggestion] 禁止事項違反は見えません。設計のみ、Architecture テスト追加、PHPStan baseline 不使用の方針は問題ありません。

[Warning] `PayloadIdWithGlobalExistenceRuleDebt` は green にできる debt case なので、放置が常態化する運用リスクがあります。  
修正提案: 根拠文の「TODO 起票方針」では弱いです。実装時には TODO ID または tracking issue ID を必須 field にし、既存 2 件以外でこの case が増えたらレビューで目立つようにしてください。

**3. 実現可能性**

[Critical] provenance フィルタの `$model->id` / `$model->{fk}_id` 除外は、型を証明しないと抜け道になります。`$dto->user_id`、`$payload->project_id`、`$requestData->organization_id` も token 上は同じ形です。ここを除外すると payload object 由来 id の global fetch が消えます。  
修正提案: 除外は「Eloquent Model と証明できる変数」に限定してください。具体的には、型付き引数が `App\Models\*`、PHPDoc で明示された `App\Models\*`、または同一メソッド内で relation/static query から得た変数だけを model-derived と扱い、証明できなければ候補に残すべきです。

[Critical] 「元モデルの解決自体が候補として捕まる」という遡及は、常には成立しません。元モデルが `where('uuid', $requestUuid)`、`where('slug', ...)`、外部 DTO、手動 `new Model([...])`、implicit binding、あるいは別 gate 管轄で解決された場合、この gate の PK identity 候補には出ません。  
修正提案: 遡及が成立する条件を明記してください。少なくとも「この gate 内で捕まる」ではなく、「route binding / NestedRoute / current-org guard / relation query / actor 解決など、別の保証済み provenance に属する場合だけ除外可」とする必要があります。

[Warning] builder alias 追跡は実装可能で、単純回避を塞ぐには十分です。ただし fixture が足りません。  
修正提案: alias invalidation、`$q = User::where(...)` 型の初期 chain、FQCN root、`DB::connection()->table()` alias を positive/negative に追加してください。

**4. 検出規則の妥当性**

[Warning] `use import` だけで `App\Models\*` を解決する仕様だと、`\App\Models\User::query()` の FQCN や同一 namespace 参照を逃します。  
修正提案: import 解決に加えて、FQCN `\App\Models\...` と current namespace 解決も対象にしてください。

[Warning] `new User()->newQuery()->whereKey($id)` / `(new User)->query()` 型が抜けます。書く頻度は低いですが、security gate の回避としては簡単です。  
修正提案: 「静的起点」ではなく内部概念を `ClassRootedPrimaryKeyQuery` に広げ、`new App\Models\*` root も候補にするか、別 gate で禁止すると明記してください。

[Warning] 非対応の `whereRaw` / `whereIntegerInRaw` / 動的列名を negative fixture にする判断は誠実ですが、抜け道でもあります。  
修正提案: negative fixture 名は「検出しないことを保証」ではなく「既知の範囲外」と分かる名前にし、実コードに出現したら fail する軽量 gate を別途置くのが安全です。たとえば app 内の `whereRaw('id` / `whereIntegerInRaw('id'` は 0 件 inventory にできます。

**5. 母集団の妥当性**

[Suggestion] `app/**/*.php + routes/*.php` へ戻した判断は妥当です。Round 2 の Service 委譲穴に対する一番自然な修正です。

[Warning] `routes/*.php` を入れたのは正しいですが、route closure の中で helper `request('user_id')` から raw SQL へ行く経路は本 gate 外です。  
修正提案: 今回の gate の対象外として明記するか、routes 内の `DB::select` / `whereRaw` / `request(` の軽量 inventory を別 gate 候補にしてください。

**6. スコープの適切さ**

[Warning] `OwnerScopedQueryConstraint` の signature は改善されていますが、右辺 provenance を見ていません。`where('organization_id', $requestOrgId)` でも signature だけなら通ります。既存の protected key gate に依存するなら、その依存を明記すべきです。  
修正提案: 右辺が `$organization->getKey()`、`$user->getKey()`、`$ctx->organization->id`、型付き model property など trusted provenance であることを副条件に入れるか、「tenant key 不信 gate が request 由来 tenant key を先に禁止する」ことを前提として書いてください。

[Warning] `AuthenticatedActorScope` に `queue_payload` が混ざっています。actor/token と queue payload は信頼境界が違います。  
修正提案: `QueuePayloadRehydration` を別 case に分けてください。副条件は `app/Jobs/**` 配下、property 名が `*Id`、根拠文に enqueue 元または job constructor を書く、程度で十分です。

[Warning] `LocalOnlyDiagnostics` の「同一ファイルに LocalOnly / isLocal」はまだ弱いです。  
修正提案: route 定義側で対象 controller/action に `LocalOnly` middleware が付いていることを確認するか、少なくとも inventory に route name を持たせて route scan と照合してください。

**7. リスク**

[Critical] `PayloadIdWithGlobalExistenceRuleDebt` の最終仕様が対応マトリクスより弱くなっています。マトリクスでは exact service method の本文確認まで書いていますが、本文 §4-4 では「根拠文 + 同一識別子変数が検証呼び出しに渡る」になっており、#1 のような Service 内検証を十分に縛れていません。  
修正提案: debt case でも、Service に逃がす場合は `Class::method` を構造化 field にし、呼び出し側が exact method を呼ぶこと、対象 method 本文に membership/tenant marker があることを確認してください。lock は debt case では必須でなくてもよいですが、検証 marker は method-scoped に見るべきです。

[Warning] `OperatorInvokedConsoleCommand` は「Console 配下」だけでは広いです。queue worker や scheduler から呼ばれる command、引数で任意 id を受ける command もあり得ます。  
修正提案: `interacts_with_operator` 的な根拠、confirmation/prompt、または command signature の引数名を inventory に持たせてください。少なくとも新規 console 候補がこの case で増えたら目立つ設計にしてください。

**追加すべき fixture**

§8-2 の 7 種は良いです。追加するなら以下です。

- `\App\Models\User::query()->whereKey($id)`。
- `DB::table('users as u')->where('u.id', $id)`。
- `User::whereId($id)`。
- `User::query()->where('id', '=', $id)`。
- `User::query()->whereIn('users.id', $ids)`。
- `$q = User::query(); $q = $other; $q->whereKey($id)` は検出しない、または alias invalidation される。
- `$dto->user_id` を provenance フィルタで除外しない。
- 型付き `Project $project` の `$project->id` は除外する。
- `(new User())->newQuery()->whereKey($id)` を対象にするか、対象外として固定する。

承認に近いですが、provenance フィルタだけは設計の中核なので、ここを「型証明できる model-derived のみ除外」に変えないと gate が静かに弱くなります。