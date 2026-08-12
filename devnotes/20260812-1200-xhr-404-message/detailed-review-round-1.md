**全体判定: REQUEST_CHANGES**

設計方向は妥当です。`ApiExceptionRenderer` の後段に、非 API の JSON 404 だけを `JsonResource` で collapse する方針は既存構造と整合しています。  
ただし、実装前に Feature 契約と Architecture 走査の粒度を少し締めてください。現状だと一部 mutation / 仕様範囲がテストで固定しきれていません。

**施策 1: APPROVE**

[Suggestion] `NotFoundMessage` + `NotFoundMessageResource` の構成は妥当です。`response()->json()` 直書きを避け、既存の `ApiErrorResource` と同じ流儀になっています。

[Warning] `MACHINE_FACING_PATTERNS = ['oauth/*', '.well-known/*']` は `oauth` / `.well-known` 直下そのものには一致しない可能性があります。配下だけを対象にするなら問題ありませんが、機械向け prefix と言うなら `oauth` / `.well-known` も含めるか、保証範囲を明記した方がよいです。

[Suggestion] PHPStan level 10 的には提示コードは概ね通る形です。`@return array{message: string}`、`Assert::isInstanceOf()`、`public static $wrap = null` は既存 `ApiErrorResource` と整合しています。

**施策 2: APPROVE**

[Warning] render callback の型は `Throwable $exception, Request $request): ?JsonResponse` で問題ない判断です。Laravel 側は render callback を順に評価し、非 null が返った時点で採用するため、`HttpExceptionInterface && status 404` で絞る設計も妥当です。

[Warning] `api/*` を条件に書かず「先に `ApiExceptionRenderer` が返す」ことへ依存する判断は成立します。ただしこれは **callback 登録順が契約** になるため、契約 1 は必ず「API の 404 が envelope のまま」を実経路で確認してください。単に `/api/no-such-path` だけでなく、可能なら route model binding 由来の 404 も見ると強いです。

[Suggestion] 実装時は `JsonResponse` / `HttpExceptionInterface` / `NotFoundMessage` / `NotFoundMessageResource` の import 漏れに注意してください。型を `Response|null` に広げる必要はありません。

**施策 3: APPROVE**

[Suggestion] enum case 名・値を変えず docblock だけ是正する判断は妥当です。既存呼び出し側への波及を避けつつ、`api/*` と非 API JSON の応答形の違いを明文化できます。

**施策 4: REQUEST_CHANGES**

[Warning] 契約 1 は M3 の検出条件です。M3「callback を `ApiExceptionRenderer` より前に置く」は、API リクエストが `expectsJson()` を満たしている場合にだけ collapse へ食われます。テストでは必ず `Accept: application/json` を付けてください。これがないと M3 が赤くならない可能性があります。

[Warning] 契約 7 は `oauth/*` だけでなく、設計上 `MACHINE_FACING_PATTERNS` に入れている `.well-known/*` も固定した方がよいです。現状だと `.well-known/*` を誤って消しても M1..M6 以外の退行として見逃します。

[Suggestion] 契約 4 を 401 / 402 / 403 / 409 / 422 すべて 1 本に集約するのは可ですが、失敗時の原因切り分けが重くなります。少なくとも data provider / dataset で status ごとに assertion 名が分かれる形にしてください。

[Warning] M3 は、この新規テストファイル内では契約 1 だけを赤くする設計にできます。ただし既存 API Feature テストが十分にあるなら、そちらも赤くなる可能性はあります。「契約 1 だけ」は mutation 表の最小検出契約として表現するのが正確です。

**施策 5: REQUEST_CHANGES**

[Warning] Architecture テストを「直接記法の列挙」に限定する方針は現実的です。ただし、最低限 named arguments と multiline は拾う設計にしてください。

拾いたい例:

```php
abort(404, message: '...');
abort_if($cond, 404, '...');
abort_unless($cond, 404, '...');
new NotFoundHttpException(message: '...');
new HttpException(statusCode: 404, message: '...');
```

[Suggestion] 正規表現だけでやるなら保証範囲をかなり狭く書くべきです。可能なら `token_get_all()` ベースで関数呼び出し / `new` 式の近傍を見る方が、コメントや文字列リテラルによる誤検出を減らせます。

[Warning] この Architecture テストは安全性の主保証にはしない、という分担は正しいです。安全性は Feature 契約 2 / 6 / 8、変更検知は Architecture、という整理で問題ありません。

**特に見てほしい点への回答**

- render callback の戻り型・引数型: `Throwable` で受けて `?JsonResponse` を返す形で問題ありません。`ModelNotFoundException` に狭めるのは不適切です。
- `api/*` を条件に書かない判断: 妥当です。ただし登録順を契約 1 で固定する前提です。
- 契約 1..8 と M1..M6: 大枠は対応しています。補強点は契約 1 の `Accept: application/json` 明示、`.well-known/*` の契約追加、契約 4 の切り分けです。
- Architecture 走査: 現実的です。ただし named argument / multiline を対象に含めないと、実運用の変更検知として弱いです。