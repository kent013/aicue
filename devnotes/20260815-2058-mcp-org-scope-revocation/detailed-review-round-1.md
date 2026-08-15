**全体判定: CHANGES_REQUESTED**

設計の方向性は妥当ですが、施策 3 の refresh token 失効漏れと、施策 6 の API key 発行者再評価の固定不足はセキュリティ境界に直接関わるため、このまま実装に入るのは危険です。

**施策別判定**

| 施策 | 判定 |
|---|---|
| 1. 失効の理由と結果の型 | APPROVE |
| 2. 監査の「握り潰さない記録」 | APPROVE |
| 3. 失効の唯一の窓口 | REQUEST_CHANGES |
| 4. 役割変更 4 経路への配線 | APPROVE |
| 5. 失効の配線検査 | REQUEST_CHANGES |
| 6. 外部 API の書き込み資格検査 | REQUEST_CHANGES |
| 7. MCP の認可関門検査 | REQUEST_CHANGES |
| 8. 振る舞いのテスト | REQUEST_CHANGES |
| 9. 文書 | APPROVE |

**Critical**

[Critical] 施策 3: refresh token に失効漏れがあり得ます。  
現設計は `oauth_access_tokens` を `where('revoked', false)` で pluck してから、その token ids に紐づく refresh token を失効します。つまり、何らかの理由で `access_token.revoked = true` だが `refresh_token.revoked = false` の不整合行があると、refresh token が生き残ります。Passport 系では refresh token 側の revoke 状態が重要になるため、再発行経路になり得ます。

修正案: token id の母集団は `organization_id + user_id` だけで取得し、access token の件数更新だけ `revoked = false` を条件にしてください。

```php
$tokenIds = DB::table('oauth_access_tokens')
    ->where('organization_id', $organizationId)
    ->where('user_id', $userId)
    ->pluck('id')
    ->all();

$accessTokens = DB::table('oauth_access_tokens')
    ->whereIn('id', $tokenIds)
    ->where('organization_id', $organizationId)
    ->where('user_id', $userId)
    ->where('revoked', false)
    ->update(['revoked' => true]);

$refreshTokens = DB::table('oauth_refresh_tokens')
    ->whereIn('access_token_id', $tokenIds)
    ->where('revoked', false)
    ->update(['revoked' => true]);
```

このケースを施策 8 に追加してください: 「親 access token は既に revoked だが refresh token が未 revoked の場合も失効する」。

[Critical] 施策 6: API key の安全前提を静的検査で固定できていません。  
設計本文では「API キーは消さない。発行者の所属を毎リクエスト評価する」としていますが、検査 C は `contextFromApiKey()` が `createdBy` を参照することしか見ていません。これは弱いです。`createdBy` を読んでいるだけでは、退会者が発行した API key を拒否している保証になりません。

修正案: `contextFromApiKey()` についても、少なくとも次を静的検査対象にしてください。

- `createdBy` / `issuedBy` の取得
- `isMemberOf($organization)` 相当の所属再評価
- 書き込み route で `resolve.api-actor` が必ず通ること
- Feature test で「除名後、除名された発行者の API key では write API が拒否される」を固定

**指定事項への回答**

(A) 施策 3 の失効クエリ:  
`session_id` で絞らない判断は正しいです。セッション行を持たない legacy / MCP token を拾うために必要です。`oauth_refresh_tokens` を `access_token_id` 経由で辿る形も、スキーマ上それが正道です。  
ただし、上記の通り `revoked = false` の access token だけを token id 母集団にすると、親 access token 済み・refresh token 未済みの不整合を取り逃がします。これは修正必須です。cross-org については token id が primary key であれば直接の穴ではありませんが、update 側にも `organization_id/user_id` を再条件として入れるほうが監査上も堅いです。

(B) 施策 4 の配線位置:  
行ロック下・同一トランザクション内・役割入れ替え後、という位置は妥当です。`applyConsoleRole → changeRole` の入れ子も Laravel の savepoint 前提なら成立します。`applyConsoleRole` 自身では revoke せず、委譲先だけが呼ぶ判断も二重発火を避けています。  
`changeRole` の同値 early return で失効しない判断も、「役割変更が成功したこと」を境界にするなら妥当です。ここはテスト名で仕様として固定してください。

(C) 施策 2 の `recordOrFail` と PostgreSQL transaction:  
問題ありません。PostgreSQL は 1 文失敗後に transaction を中断状態にしますが、`recordOrFail` は例外を握り潰さず外へ出すため、Laravel の transaction が rollback します。むしろこの用途では正しい設計です。  
注意点は、既存 `record()` を transaction 内で使う経路は従来通り「例外は握り潰すが transaction は壊れ得る」ことです。本件の失効経路で `record()` を使わないことをテストで固定してください。

(D) 施策 5/6/7 の静的検査:  
既存検査との責務分離は概ね妥当です。  
ただし空振りがあります。

- 施策 5 の「理由で分岐しない」は `match/switch` だけでは弱いです。`if ($reason === ...)` も禁止対象にしてください。
- 施策 5 の位置検査は「全制御パスで revoke される」ことまでは保証しません。これは施策 8 の Feature test で補う必要があります。
- 施策 6 は API key 発行者の所属再評価を検査していないため、守れていないのに緑になり得ます。
- 施策 7 は `authorizeTool()` を呼んだだけで結果を無視する形を通し得ます。`if (! $ctx->authorizeTool(...)) { throw AuthorizationException ... }` まで構造検査するほうがよいです。

(E) 実装順序とテストファースト:  
概ね現実に回ります。ただし、施策 8 に上記の refresh token 不整合ケースと API key 発行者除名ケースを先に追加してください。  
また完了条件は設計書末尾の 3 コマンドだけでは不足です。AGENTS.md の完了基準に合わせるなら、少なくとも影響なしの根拠を明示するか、全 verification lane を走らせる計画にしてください。

**その他**

DTO/JsonResource パターン、Inertia Props vs API Response の使い分けは、本件では外部レスポンス変更がないため問題ありません。  
UI/frontend 変更はないため、DESIGN.md / Atomic Design 準拠は該当なしです。