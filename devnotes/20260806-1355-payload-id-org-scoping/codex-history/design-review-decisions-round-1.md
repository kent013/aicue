# 対応マトリクス: design-review Round 1

## [Warning] 施策 C: bool が `filter_var` で 1 として受理され、入力分類契約が崩れる
- 判断: **対応する (指摘は半分正しい)**
- 根拠: `filter_var(true, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])` は **1** を返すことを
  PHP 8.4 で実測した。ただし現行コードには**すでに `is_bool($raw)` の早期 reject が存在**しており
  (`McpConsentOrganizationBinder` L37-39)、設計はそこを変更対象にしていない。
  問題は「変更後コード」の抜粋がその guard の下から始まっていて、
  実装者が guard ごと書き換える事故を招きうる点。
- 対応内容: 変更後コードに **bool guard を明示的に含め**、
  「filter_var(true) は 1 を返すのでこの guard は必須」というコメントを付けた。
  併せて「本施策で変えるのは fetch の撤去と不在 id の応答だけ」という境界を明記し、
  float `1.0` が受理される件は HTTP 経由では到達しないため追加 guard を入れない
  (使わない防御を足さない) と明記。
- 提案された `! is_string($raw) && ! is_int($raw)` への置換は**採らない**:
  現行の bool guard で契約は満たされ、置換は挙動を変えない純粋な書き換え
  (今必要ないリファクタ = 思考原則 2)。

## [Warning] 施策 E: `pieoObserve()` が PHPStan level 10 で落ちる
- 判断: **対応する**
- 根拠: `session('errors')` は mixed。`array` 戻り値も value type 未指定で level 10 に引っかかる。
- 対応内容: `ViewErrorBag` への `instanceof` narrowing、`array_values()` による `list<string>` 化、
  `ResponseSignature::of()` の実 shape (`array{status: int, headers: array<string, list<string>>, body: string}`)
  を含む `@return` shape を明記。`use Illuminate\Support\ViewErrorBag;` を import に追加。

## [Warning][Suggestion] 「422」表記が `assertStatus(422)` を誘導する
- 判断: **対応する**
- 根拠: web/Inertia 経路の `ValidationException` は 302 redirect + session errors であり、
  422 が返るのは `expectsJson()` のときだけ。設計書の表記が実装者を誤らせる。
- 対応内容: フォーム 2 経路の記述を **「validation failure (redirect back + `errors.user_id`)」**へ統一。
  MCP binder の 422/403 は **実 HTTP status** なので表記を変えない (HttpException のため)。
  テストは `assertSessionHasErrors()` を主とする方針を明記済み。

## [Suggestion] `PIEO_MISSING_USER_ID` のコメント「18 桁 pattern 内」が値 (9 桁) と不一致
- 判断: 対応する
- 対応内容: 「実在しない user id (9 桁。テストで生成される id と衝突しない値)」に訂正。
  (18 桁は route parameter 版 `MemberRouteExistenceOracleTest` の pattern 上限の話で、
  payload 経路には route pattern が無いため持ち込まない)

## [追加確認] MCP テストに `true` / `[]` / `'001'` / 前後空白を必ず入れる
- 判断: 対応済み (施策 C のテスト計画に列挙済み)。bool guard を残すので `true` は 422 で緑になる。

## [追加確認] A/B の応答一致テストで `from()` を両リクエストで固定する
- 判断: 対応する
- 対応内容: ケース 1 だけでなく**ケース 2 も** `->from()` を固定する旨をテスト計画に明記
  (Location が `ResponseSignature` の比較対象に残るため)。

## [Suggestion] 施策 A/B/D/F の承認コメント
- 判断: 見送る (肯定的評価。設計変更なし)
