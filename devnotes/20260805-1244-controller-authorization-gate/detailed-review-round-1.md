前提: コマンド実行・ファイル書き込みはせず、提示された設計本文だけをレビューしました。

**全体判定: CHANGES_REQUESTED**

設計方針自体は妥当です。特に「層2の 404」と「層3の 403」を分け、API v1 では `Gate::forUser($this->apiActor($request)->user)` を使う判断は正しいです。ただし、静的 gate の誤合格リスクと、Laravel の FormRequest 実行順による cross-org 404 保証の穴が未解決です。

**施策 1: APPROVE**

[Suggestion] enum の分類粒度と docblock は妥当です。`NoAuthorizableSubject` は濫用されやすいので、docs 側にも「親テナントがある create は対象外」と明記するとさらによいです。

**施策 2: REQUEST_CHANGES**

[Warning] `Gate :: forUser .*?-> authorize` は誤合格します。  
例: `Gate::forUser($user); $something->authorize();` でも通る可能性があります。修正案: 正規表現ではなく token state machine にして、`Gate::forUser(...)` の閉じ括弧直後から `;` までに連鎖した `->authorize` がある場合だけ合格にしてください。

[Warning] 完全修飾 `\Illuminate\Support\Facades\Gate::authorize` を許容すると書いていますが、提示された検出仕様は `Gate :: authorize` 前提です。修正案: どちらかに寄せてください。実装単純化を優先するなら「`use Illuminate\Support\Facades\Gate;` 必須」とし、FQCN 許容を削るのがよいです。

[Warning] `file($file)` / `realpath()` / `getFileName()` の失敗時処理が骨子では曖昧です。修正案: すべて fail-secure に集約し、失敗 route 名・URI・原因を violation に出す形にしてください。

**施策 3: REQUEST_CHANGES**

[Critical] 「URL 整合 guard は認可より前に 404」としていますが、`StoreItemRequest` / `UpdateItemRequest` は controller 本体より前に validation されます。つまり cross-org の既存 project/item に対して不正 payload を送ると、inline guard に到達せず 422 になり、存在オラクルになる可能性があります。  
修正案: 少なくとも施策 4 に「cross-org + invalid payload / protected key payload でも 404 か」を追加してください。もし 422 になるなら、URL 整合 guard を controller inline ではなく FormRequest より前に走る route middleware 等へ移すか、設計上の不変条件を「valid payload に限る」と明記する必要があります。セキュリティ不変条件としては前者が望ましいです。

[Suggestion] `Gate::forUser(...)` の追加位置と ability は妥当です。`create: [Item::class, $project]`, `update/delete: $item` は web 側と揃っています。

**施策 4: REQUEST_CHANGES**

[Warning] write API は `idempotent` middleware 配下ですが、テスト骨子に `Idempotency-Key` header がありません。修正案: `postJson` / `patchJson` / `deleteJson` の全 write request に一意な `Idempotency-Key` を付けてください。付けないと 403/404 ではなく idempotency middleware のエラーを検証してしまう可能性があります。

[Warning] OAuth ケース 6/7 の setup が不足しています。修正案: OAuth client 作成、CLI session token 発行、`Authorization: Bearer <access_token>`、必要 scope を明示した helper 呼び出しまでテスト骨子に含めてください。

[Warning] cross-org テストは valid payload だけでは不十分です。修正案: 施策 3 の Critical と対応し、invalid payload / protected key payload でも 404 が維持されるかを追加してください。

**施策 5: REQUEST_CHANGES**

[Warning] `function issueCliSessionTokens(object $test, ...)` で `$test->user` / `$test->org` / `$test->client` を読む委譲は PHPStan level 10 と相性が悪いです。修正案: 既存呼び出し側も `OAuthTestHelpers::issueCliSessionTokens($this, $user, $org, $client, $scope)` の明示引数へ置き換え、global wrapper を残さない方が安全です。

**施策 6: REQUEST_CHANGES**

[Warning] docs の「ハンドラ冒頭（URL 整合 guard の後）に Gate」は、FormRequest が先に走る点を隠しています。修正案: 「FormRequest validation より前に 404 を保証できているか」を新規 route チェックリストに追加してください。

設計の方向性はよいですが、このまま実装すると「gate は入ったが静的テストが誤合格する」「cross-org が invalid payload で 422 になり得る」という穴が残ります。そこを潰せば APPROVE に近いです。