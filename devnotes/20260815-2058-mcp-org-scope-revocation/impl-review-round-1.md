**app/Services/OAuth/OrganizationAccessRevoker.php**

[Warning] `revoke()` は設計どおり 4 表を `(organization_id, user_id)` で絞って失効し、親 access token が既に失効済みでも refresh token を拾う実装になっています。件数も「実際に今回 update した行数」として妥当です。

[Suggestion] `now()` を複数回呼ぶ点は設計で許容済みなので問題ありません。

**app/Services/Organization/OrganizationMembershipService.php**

[Warning] `applyConsoleRole()` の Editor/Shooter 系では `changeRole()` 内で失効した後に、同じ外側 transaction 内で project pivot 更新が続きます。pivot 更新が失敗した場合は outer transaction rollback で失効も戻るため正しさは保たれます。ただし「役割変更の後に失効」という説明は、`applyConsoleRole()` 全体では「org role 変更後、project role 更新前に失効」です。致命的ではありませんが、説明が少し強いです。

[Suggestion] `removeMember()` は detach 後に失効していますが、失効条件は `oauth_*` 側の org/user なので取り逃しはありません。

**app/Services/Security/SecurityEventRecorder.php**

[Warning] `recordOrFail()` の追加は設計どおりです。既存 `record()` の best-effort 挙動も保たれています。

**app/Http/Controllers/Organizations/OrganizationMemberController.php**

[Warning] actor を明示取得して渡しており妥当です。`Assert::isInstanceOf($actor, User::class)` も PHPStan 上の narrowing として妥当です。

**tests/Architecture/OrganizationAccessRevocationChokePointTest.php**

[Critical] 検査 D の「失効列への書き込みの単一窓口」は fail-open です。`orgRevocationHasRevocationColumnWrite()` は `update` / `forceFill` の直前が `T_OBJECT_OPERATOR` の場合だけ検出します。実装本体の `DB::table(...)->update(...)` は直前が `T_OBJECT_OPERATOR` なので拾えますが、将来 `DB::table(...)->where(...)->update([...])` の形以外、たとえば `Model::query()->update([...])` や `DB::update(...)`、Eloquent property 代入、builder 変数 `$query->update(...)` のうち表名が別行・別関数に分離された形は沈黙します。設計上「保証範囲を誇張しない」と書いてはいますが、「単一窓口」と呼ぶには弱く、回避容易な字句検査です。少なくとも検査名か文書側は「検出できる書き方に限る」に落とすべきです。

[Warning] `orgRevocationReasonUsageViolations()` は `$reason` の参照位置をかなり狭く固定できており良いです。ただし `['reason' => ($reason)->value]` のような等価な書き換えも落ちます。これは意図的な固定として許容範囲です。

**tests/Architecture/RestWriteScopeRevalidationInvariantTest.php**

[Critical] 正のコントロールが既存 route に依存して空疎化します。`正のコントロール` は新規 route を追加した後に `restWriteScopeViolations()` 全体が `[]` であることを期待していますが、既存母集団側に違反があればこのテストも落ちます。つまり「追加した write ability 付き route が違反にならない」ことを単独で証明できていません。追加 route の名前が violations に含まれない、という形で検査すべきです。

[Warning] `restWriteScopeRouteCount()` は 4 固定で母集団空振りを検出できますが、route cache や route registration の環境差で false positive になりやすい領域です。既存 `IdempotentRouteCoverageTest` と同じ母集団抽出という設計意図は良いです。

**tests/Architecture/McpAuthorizationChokePointTest.php**

[Critical] コメントが実装と矛盾しています。「戻り値を捨てる形は落とせない」と書いていますが、`mcpChokePointResultUseViolations()` はまさに戻り値を捨てる形を落とす検査です。保証範囲の記述が不正確です。設計観点 7 に反します。

[Warning] `mcpChokePointResultUseViolations()` は `authorizeTool()` の近傍 10 token 以内に `!` があればよく、`if` の条件式内で別の否定が混ざる形を誤認し得ます。通常変更では十分ですが、厳密な意味検査ではありません。

**tests/Feature/Organizations/OrganizationAccessRevocationTest.php**

[Critical] API キー書き込み拒否のテストが設計の空疎化対策を満たしていません。`postJson()` の第 3 引数に `Idempotency-Key` を渡していますが、Laravel の `postJson($uri, $data = [], $headers = [])` では第 3 引数は headers ではなく options です。結果として idempotency header が付かず、403 が「権限再評価」ではなく「冪等キー不足」等の別理由で返っても緑になり得ます。`withHeader('Idempotency-Key', ...)` を使ってから `postJson()` する必要があります。

[Warning] 「除名の後はその人のトークンで外部 API を叩けない」は `GET /api/v1/me` で確認しています。設計は「外部 API の書き込み」も要求していたため、書き込み route の OAuth token 経路で 401/403 になる確認が別途欲しいです。

[Warning] `接続セッション一覧に失効済みとして並ぶ` は `sessions.0` 固定なので、並び順の変更で壊れやすいです。対象 id を持つ要素を探索する assertion の方が意図に強いです。

**tests/Feature/Console/PurgeDeletionRequestsCommandTest.php**

[Suggestion] anonymous subclass の constructor 引数更新は妥当です。

**tests/Architecture/AccountDeletionPathGateTest.php**

[Warning] 依存閉包 allowlist の追加理由は書かれています。ただし `SecurityEventRecorder` 経由で監査書き込みまで閉包に入る点は既存 allowlist 側に依存しており、この変更自体では追加クラスが「決済 SDK に到達しない」こと以上の副作用を固定していません。

**docs / AGENTS.md**

[Warning] 差分には文書更新が含まれていません。詳細設計の施策 9 は `docs/mcp-oauth.md` / `docs/architecture.md` / `AGENTS.md` 更新を要求しています。実装差分だけを見る限り未実装です。

全体判定: CHANGES_REQUESTED