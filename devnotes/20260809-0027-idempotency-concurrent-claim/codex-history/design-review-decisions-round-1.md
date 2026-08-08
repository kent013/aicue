# 対応マトリクス: design-review Round 1

## [Critical] 施策 B — `isExpired(?Carbon)` に `CarbonImmutable` を渡すと TypeError
- 判断: **対応する**
- 根拠: 実コードで確認。`Illuminate\Support\Carbon` は `Carbon\Carbon` (mutable) を継承しており、
  `CarbonImmutable` はその子ではない。middleware が `CarbonImmutable::now()` を基準時刻に使う設計なので
  現行シグネチャのままなら実行時 TypeError になる。指摘は正しい。
- 対応内容: 施策 B の変更箇所に `isExpired()` の引数型変更 (`?CarbonInterface`) を追加し、
  変更後コードとコメントを明示。施策 C の波及変更に呼び出し元 2 箇所を列挙。
  mutation 表に「引数型を戻すと期限切れ claim テストが赤」を追加 (#26)。

## [Critical] 施策 C — `decodeBody()` が未定義
- 判断: **対応する**
- 根拠: 指摘のとおり、変更後コードで参照だけして定義していなかった。
- 対応内容: 現行 `storeResponse()` の decode ロジックを
  `private function decodeBody(JsonResponse $response): ?array` として明示的に移設し、
  戻り値 PHPDoc (`array<array-key, mixed>|null`) を付けた。

## [Critical] 施策 C — `Eloquent\Builder::update()` は cast を通さない (json 列に配列を渡すと壊れる)
- 判断: **対応する**
- 根拠: vendor 実装を確認 (`Builder::update()` は `$this->toBase()->update($this->addUpdatedAtColumn($values))`
  で base builder へ素通し)。cast は適用されないため PHP 配列を binding できない。指摘は正しい。
- 対応内容: finalize の payload で `json_encode($body, JSON_THROW_ON_ERROR)` を明示。
  `null` は正当な保存値なのでそのまま null を入れる。
  施策 H に「保存 body が DB へ往復してから再生される」テスト (テスト 11) を追加し、
  mutation 表に「json_encode を外すと赤」(#24) を追加。

## [Warning] 施策 C — `Idempotency-Key` の長さ検証が無い
- 判断: **対応する**
- 根拠: `key` は varchar(255)。現行は save の QueryException を握り潰していたため
  「実行はされるが保存されない」で済んでいたが、claim を実行前に持ってくると
  INSERT が落ちて本処理を実行しないまま 500 になる。実害のある後退なので塞ぐ。
- 対応内容: `MAX_KEY_LENGTH = 255` を定数化し、DB に触る前に 422 `validation_failed`
  (`ApiErrorResource` 経由 = 禁止事項 4 遵守) で弾く。テスト 12 と mutation #25 を追加。

## [Warning] 施策 C — ログの route 名が path fallback で実値を含みうる
- 判断: **対応する**
- 根拠: 行のスコープには path fallback が要るが、ログに project id / item id を出すのは
  「載せるのは 5 項目」という契約とずれる。
- 対応内容: `loggableRouteName()` を追加し、名前が無ければ `(unnamed-api-route)` を使う。
  `finalize()` の引数を「スコープ用 `$routeName`」と「ログ用 `$logRouteName`」に分けた。

## [Warning] 施策 B — migration の `down()` が旧契約へ戻せない
- 判断: **対応する** (irreversible と明記する側を採る)
- 根拠: 指摘のとおり。`state` を落としても `response_status = null` の行が残り、
  旧 `replayResponse()` が null status で壊れる。
- 対応内容: `down()` に「実質 irreversible」と明記し、旧コードへ戻す前に
  `DELETE FROM idempotency_keys WHERE response_status IS NULL` を人手で実行する手順を書いた。
  この手順は `docs/api-idempotency.md` の「ロールバック手順」節にも置く (施策 I)。

## [Warning] 施策 D — `onOneServer()` は cache lock 依存
- 判断: **対応する** (文書側で)
- 根拠: 既存の複数 schedule が同じ前提に乗っており、本設計が新しく持ち込む前提ではないが、
  前提が明文化されていないのは事実。
- 対応内容: 施策 D のリスクに追記し、`docs/architecture.md` の cron 節へ
  「scheduler 稼働 + ロック可能な cache driver が全 `onOneServer()` の前提」を 1 行で書く。

## [Warning] 施策 E — 前提テストの主張が過剰
- 判断: **対応する**
- 根拠: `IdempotencyKey::count() === 0` は観測であって「冪等層より前で止まった」ことの証明ではない。
- 対応内容: テスト名を「session revoke 後の同一 token 再送は 401 になり冪等行を 1 件も作らない」に変え、
  実行位置の証明は順序 gate の担当であることをコメントで明示した。

## [Warning] 施策 H — テスト 1 の説明が過剰 (commit まで証明していない)
- 判断: **対応する**
- 対応内容: テスト名を「claim 行は controller 実行前に作られ、同一リクエスト内で processing として読める」に変更。
  「保証しないもの」2 番も「commit の証明はしていない」と書き直した。

## [Suggestion] 施策 A — `cutoff()` は `now()` の薄い別名
- 判断: **対応する** (縮小)
- 対応内容: `IdempotencyRetention::cutoff()` を**作らない**ことにし、prune コマンド側で
  `CarbonImmutable::now()` を 1 回だけ確定させる形にした (思考原則 2)。

## [Suggestion] 施策 F — 409 の error code もマーカーに入れる
- 判断: **対応する**
- 対応内容: マーカー区間に `conflict_codes` 行を追加し、parity gate のテスト 9
  (`ApiErrorCode` の 409 系 case 集合との一致) を新設した。

## [Suggestion] 施策 G — `default =>` の字句検査は空白差分に弱い
- 判断: **対応する**
- 対応内容: `/\bdefault\s*=>/` の regex に変更。中央分岐の検査も `/->isWriteTool\(\s*\)/` に明示。

## [Suggestion] 施策 B — `state` に DB CHECK 制約
- 判断: **見送る** (反論)
- 根拠: 書き込み経路は claim の `insertOrIgnore` と finalize の条件付き UPDATE の 2 箇所だけで、
  どちらも enum の `->value` しか渡さない。読み戻しは enum cast なので未知値は `ValueError` で即死する
  (fail-fast は既に成立)。一方 pgsql 専用の raw `ALTER TABLE … ADD CONSTRAINT` を持ち込むと
  config 既定の sqlite で migration が動かなくなる副作用がある。得るものより失うものが大きい (思考原則 2)。
- 対応内容: 施策 B に反論を明記した。

## [Suggestion] 施策 I — AGENTS.md は項目名で相互参照
- 判断: **対応不要** (既に設計どおり)
