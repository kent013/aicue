提供テキストのみでレビューしました。仮説は「設計の方向性は妥当だが、Laravel/Eloquent の型・cast・migration 実行時の細部で実装時に破綻する箇所が残っている」です。成功条件は、公開契約変更がテストで固定され、PHPStan level 10 と本番 DB 動作の両方で矛盾しないことです。

**全体判定: CHANGES_REQUESTED**

## 施策別判定

### 施策 A: 保持期間 SoT と再生ヘッダ定数
判定: APPROVE

[Suggestion] `IdempotencyRetention::cutoff()` は現状 `now()` の薄い別名なので、prune 側で cutoff を一度だけ確定する意図を docblock に寄せるだけでも十分です。ただし SoT 化の方向性は妥当です。

### 施策 B: 状態列の追加
判定: REQUEST_CHANGES

[Critical] `IdempotentRequest::claim()` で `CarbonImmutable $now` を `IdempotencyKey::isExpired(?Carbon $now = null)` に渡す設計になっており、型が合いません。PHPStan以前に実行時 TypeError になり得ます。  
修正案: `IdempotencyKey::isExpired()` の引数を `Carbon\CarbonInterface` に変更してください。

```php
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

public function isExpired(?CarbonInterface $now = null): bool
{
    return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
}
```

[Warning] migration の `down()` が旧契約へ戻せません。`state` を落としても `response_status = null` の行が残り得るため、旧コードへ rollback すると replay 周辺が壊れます。  
修正案: rollback を安全にするなら `processing/indeterminate` 行を削除または退避してから `response_status` を NOT NULL に戻す手順を設計に含める。戻さない判断なら、この migration は実質 irreversible と明記し、rollback runbook に「旧コードへ戻す前に null 行を処理する」手順を入れてください。

[Suggestion] `state` は DB CHECK 制約なしでも成立しますが、query builder 経由の書き込みが増える設計なので、pgsql 前提なら `state in (...)` の CHECK を検討する価値があります。

### 施策 C: IdempotentRequest の claim → 分岐 → finalize 化
判定: REQUEST_CHANGES

[Critical] `finalize()` で `$this->decodeBody($response)` を呼んでいますが、変更後コードに `decodeBody()` の追加設計がありません。現状の提示コードのままでは未定義メソッドです。  
修正案: `storeResponse()` から削る decode ロジックを `private decodeBody(JsonResponse $response): array|null` として明示的に移してください。

[Critical] `finalize()` の `Eloquent\Builder::update($payload)` は Model cast を通しません。`response_body` に PHP 配列を入れると JSON cast が効かず、pgsql/json カラムで失敗する可能性が高いです。  
修正案: 条件付き UPDATE を維持するなら、更新 payload では JSON 文字列へ明示エンコードしてください。

```php
$body = $this->decodeBody($response);

$payload['response_status'] = $response->getStatusCode();
$payload['response_body'] = $body === null
    ? null
    : json_encode($body, JSON_THROW_ON_ERROR);
```

あわせて「completed replay が保存 body を正しく返す」テストで DB に保存された実体から再生まで確認してください。

[Warning] `Idempotency-Key` の長さ・形式検証が設計にありません。`key` カラムは `string` なので 255 超過ヘッダで DB 例外や 500 になり得ます。  
修正案: middleware 冒頭で `trim($key)` 後に `1..255` を検証し、違反時は `ApiErrorResource` 経由で `422 validation_failed` など既存 envelope に揃えて返す。テストにも「長すぎる Idempotency-Key は DB に触らず 4xx」を追加してください。

[Warning] `IdempotencyFinalizationFailure` の `routeName` は fallback で `$request->path()` になり得ます。ログに route parameter 実値が入るため、「載せるのは route 名」という契約と少しずれます。  
修正案: idempotent 対象 route は named route 必須にする、または fallback 値を固定文字列にして実 path をログへ出さない設計にしてください。

### 施策 D: 期限切れ鍵の物理削除
判定: APPROVE

[Warning] `Schedule::command(...)->onOneServer()` は cache lock に依存します。production の scheduler/cache 構成が前提を満たさないと多重実行され得ます。  
修正案: `docs/architecture.md` か運用文書に、scheduler が有効であること、`onOneServer()` が効く cache driver を使うことを追記してください。

### 施策 E: 配線目録 gate + 免除 enum
判定: APPROVE

[Warning] `IdempotencyExemptionPremiseTest` の `IdempotencyKey::count() === 0` は、現 route が idempotent 未配線であることの確認にはなりますが、「冪等層より前に止まる」ことの直接証明ではありません。  
修正案: テスト名・コメントからその過剰な主張を落とし、401 の前提は auth/resolve-api-actor の順序 gate と組み合わせて保証する、と明記してください。

### 施策 F: 契約 parity gate + 契約文書
判定: APPROVE

[Suggestion] マーカー区間だけを機械検査する限界を明記している点は良いです。散文の決着写像表については、最低限 409 の 3 error code 名だけでも marker に含めると drift 検知が少し強くなります。

### 施策 G: MCP 中央強制 gate
判定: APPROVE

[Suggestion] `default =>` の字句検査は空白差分に弱いので、`default\s*=>` の regex か token ベース検査にしてください。trip-wire の考え方自体は妥当です。

### 施策 H: 既存テスト追随 + 並行 claim テスト
判定: REQUEST_CHANGES

[Warning] テスト 1 の説明が「claim が本処理より前にコミット済み」となっていますが、`RefreshDatabase` 下の同一接続観測では commit までは証明できません。設計末尾では保証しないと書けているため、テスト名・説明側が過剰です。  
修正案: 「controller 実行前に processing 行が作成され、同一リクエスト内で可視になる」に改名してください。commit 可視性は pgsql autocommit 前提として文書化に留めるのが正確です。

[Warning] `finalize()` 失敗テストは重要ですが、上記の JSON cast 問題を捕まえるテストが明示されていません。  
修正案: completed finalize 後に DB 保存済み `response_body` を読み戻し、replay まで通すテストを `IdempotencyConcurrentClaimTest` または既存 replay テストに追加してください。

### 施策 I: 文書追随
判定: APPROVE

[Suggestion] AGENTS.md への追記では番号参照を避け、項目名「冪等キーの配線と決着規約」で相互参照する方針で問題ありません。

## 主要な修正必須点

実装前に最低限直すべきものは 3 点です。

1. `isExpired()` の `CarbonImmutable` 型不一致を解消する。
2. `finalize()` の JSON body 保存を query builder update 前提で明示エンコードする。
3. 未定義の `decodeBody()` を設計に追加し、replay まで含むテストで固定する。

この 3 点が直れば、設計の大枠、契約変更の固定方法、配線 gate の方向性は概ね承認可能です。