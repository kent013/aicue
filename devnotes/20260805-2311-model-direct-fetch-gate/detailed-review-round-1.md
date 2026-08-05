**仮説**

この設計の成功条件は「静的起点 + 主キー同一性の global fetch が、新規混入時に CI で落ちる」ことです。全体方針は妥当ですが、詳細設計には **走査器の候補キー安定性** と **検出対象の表現力** に未解決の穴があります。特に token_get_all で実装可能な範囲を超えている箇所と、逆に簡単な回避が残る箇所が混在しています。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1. 分類 enum | APPROVE |
| 2. inventory エントリ型 | REQUEST_CHANGES |
| 3. 走査器 | REQUEST_CHANGES |
| 4. inventory 本体 | REQUEST_CHANGES |
| 5. gate 本体 | REQUEST_CHANGES |
| 6. 走査器 Unit テスト | REQUEST_CHANGES |
| 7. 規約ドキュメント登録 | APPROVE |

## 指摘

[Critical] **候補 key が「メソッド内出現順」だけでは安全に安定しない**

`path#method#1` は行番号よりは安定しますが、同一メソッド内で候補を 1 件追加・削除すると後続 key が全てずれます。これは inventory の stale 検出を大量に起こし、レビュー時に「番号を付け替えるだけ」の作業を誘発します。最悪、既存の裁定理由が別候補へ横滑りしても、人間が見落とす余地があります。

修正案: key に短い構造 fingerprint を加えてください。

例:

`Http/Controllers/Foo.php#store#2#User.whereKey:$targetUserId`

fingerprint は少なくとも以下を含めるべきです。

- root 種別: `User` / `DB:users`
- 主キー述語: `findOrFail` / `whereKey` / `where:id:=`
- identity 引数の正規化文字列: `$userId` / `$dto->user_id` / `$this->renderJobId`

出現順は衝突解消用に残してよいですが、主識別子にしない方がよいです。

[Critical] **`DB::table()` の対象テーブル検証が不足している**

設計では `DB::table('users')` を候補に含めていますが、`DB::table('plans')->where('id', $id)` のような全テーブルの `id` が候補になるのか、Eloquent model に対応するテーブルだけなのかが曖昧です。主張が「ModelDirectFetch」なら、DB table も対象にする場合は `App\Models\*` の `$table` 解決、または明示 table allowlist が必要です。

修正案: `DirectFetchInventory::modelTables()` か scanner 内の `model table map` を定義し、`App\Models` の既知テーブルだけを対象にしてください。DB::table を全 table に張るなら、テスト名・説明を `Model` ではなく `PrimaryKeyDirectFetchInvariantTest` 相当に寄せるべきです。

[Critical] **`findMany` / `destroy` / `whereKeyNot` の扱いが同一性 fetch と混ざっている**

`findMany($ids)` と `destroy($ids)` は複数 ID 操作で、read/write の危険性はむしろ高い一方、`identityArgument` が単数前提の副条件と合いません。`whereKeyNot($id)` は「同一性」ではなく除外条件であり、`whereKeyNot($requestId)` を候補にする意義はありますが、`findOrFail($id)` と同じ分類で扱うと副条件が破綻します。

修正案: candidate に `predicateKind` を持たせてください。

- `single_identity`
- `multi_identity`
- `identity_exclusion`
- `destructive_identity`

その上で、分類 case ごとの副条件を `predicateKind` に応じて分けるべきです。最低限、`whereKeyNot` は初期スコープから外すか、別 fixture と別失敗メッセージに分けてください。

[Warning] **provenance 証明の詳細設計が概念設計より後退している**

詳細設計 §3 では「型付き引数が `App\Models\*`」だけで候補から外すように読めます。一方、概念設計では route binding / tenant guard / relation 起点 / 本 gate 分類済みという保証済み provenance が条件になっています。この差は危険です。

修正案: 詳細設計側も概念設計の条件に合わせてください。少なくとも、型付き引数だけで除外するのではなく、候補には残した上で `ModelDerivedFromGuardedModel` のような機械副条件付き分類にするか、controller route model か relation assignment だけを除外対象にしてください。

[Warning] **alias 追跡の「再代入で伝播を打ち切る」は token 走査では実装定義が甘い**

`$q = User::query(); if ($x) { $q = $other; } $q->whereKey($id);` のような分岐では、単純な線形走査だと「再代入ありなので打ち切り」か「分岐外なので維持」かを決める必要があります。設計は分岐をまたぐ伝播を追わないと言っていますが、どちらに fail-closed するのかが不明です。

修正案: alias は「代入位置から候補位置までのトークン範囲に同名変数への再代入が 1 回でもあれば無効」と明記してください。これは過剰検出寄りですが deny-by-default に合います。

[Warning] **OwnerScopedQueryConstraint の右辺 provenance 判定が過大**

`whereBelongsTo($organization)` や `where('organization_id', $organization->getKey())` の右辺検証は token_get_all でも可能ですが、`whereHas('organizations', fn ($q) => $q->whereKey($organization))` のようなネスト closure 内検証まで含めると実装コストが跳ねます。

修正案: v1 では許可 signature を狭めてください。

- `where('organization_id', $model->getKey()|$model->id)`
- `whereBelongsTo($model)`
- `whereKey($id)` と同一 chain の単純 `where(...)`

`whereHas` は初期対象外にし、必要になったら fixture と一緒に足す方がよいです。

[Warning] **LocalOnlyDiagnostics の route 照合はテスト環境で route が存在する前提に依存する**

設計では local + runningUnitTests 限定 route を route 走査で確認するとあります。これは CI/unit test 環境で route が登録されるなら成立しますが、`app()->isLocal()` と `runningUnitTests()` の条件差で環境依存の fail になりやすいです。

修正案: LocalOnly の副条件は「route に `LocalOnly` middleware がある」だけでなく、「登録条件が `isLocal || runningUnitTests` であること」を scanner か別 assertion で固定するか、既存の local route gate があるならそれを参照する形にしてください。

[Warning] **債務 case の検証が文字列 marker 依存で弱い**

`verifiedBy` メソッド本文に membership/tenant marker があること、呼び出し側が exact method を呼ぶことは良いですが、marker の定義が未確定です。`membership` という文字列や relation 名の断片で通すと簡単に形骸化します。

修正案: marker は enum または定数リストで明示してください。例:

- `->organizations()->whereKey(`
- `->members()->whereKey(`
- `whereBelongsTo($organization`
- `lockForUpdate` は marker にしない

また、`verifiedBy` の exact call は `Class::method` の static/instance 呼び出し両方をどう扱うか明記してください。

[Warning] **`response()->json()` 等と違い app/Enums 追加は本番 autoload に入る**

施策 1 の enum は振る舞いに関与しないとはいえ `app/` 配下です。既存の `ControllerAuthorizationExemption` に合わせる判断は理解できますが、テスト専用語彙を production namespace に追加する負債はあります。

修正案: 既存作法を優先するなら APPROVE でよいですが、設計書に「既存 enum との一貫性を優先し、production autoload への混入を許容する」と明記してください。

[Suggestion] **`DirectFetchJustificationEntry` は metadata accessor を持つ方が PHPStan とテストが書きやすい**

`array<string,string>` 直参照だと typo が runtime まで残ります。

提案: `actorSource()`, `enqueuedBy()`, `routeName()` などの getter を用意し、存在しない case で呼んだら fail する形にすると gate 本体が読みやすくなります。

[Suggestion] **degenerate PASS 防止は「1 件以上」より期待最小件数の方が強い**

現行候補が 33〜40 件見込みなら、1 件以上は弱いです。

提案: 初期導入後に `>= 20` 程度の下限を固定してください。候補が大幅減したら scanner 破損の可能性が高いです。

[Suggestion] **`whereRaw('id` の 0 件 assertion は pattern を広げる余地あり**

`whereRaw('users.id = ?')` や `"id = ?"` の quote 差で漏れます。

提案: v1 では `whereRaw` / `whereIntegerInRaw` の呼び出し自体を検出し、引数先頭 token が string なら正規化して `(^|[.\s])id\b` を見る程度にしてください。

## 全体判定

**CHANGES_REQUESTED**

方針は採用可能です。特に「全層を母集団にし、provenance でノイズを落とす」「debt case を準拠 case と分ける」「走査器自体を Unit テストする」は妥当です。

ただし現状のまま実装に入ると、候補 key の横滑り、DB::table の範囲曖昧さ、provenance 条件の後退で gate の信頼性が落ちます。上記 Critical 3 件と Warning の provenance 差分は、実装前に設計へ反映してください。