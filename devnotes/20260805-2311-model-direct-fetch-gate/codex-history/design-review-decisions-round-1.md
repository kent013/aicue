# 対応マトリクス: design-review Round 1

実測で裏を取ってから判断した。関連する出現数:

| pattern | app/ + routes/ の出現数 |
|---|---|
| `whereKeyNot` | 6 (すべて relation 起点 or 引数が model 由来) |
| `findMany` / `findOrNew` | **0** |
| `::destroy(` | 1 (**docblock 中のみ** = 実コード 0) |
| `whereRaw` / `whereIntegerInRaw` | **0** |
| `DB::table('…')` の対象 | `organizations` / `users` / `organization_user` / `project_members` / `ticket_ledger_entries` (model 対応) + `oauth_*` (Passport 内部) |

## [Critical] 候補 key がメソッド内出現順だけでは横滑りする

- 判断: **対応する**
- 根拠: 完全に正しい。同一メソッドで候補が 1 件増減すると後続 key が全てずれ、
  **既存の裁定理由が別候補へ横滑りしても人間が気付けない**。deny-by-default の意味が消える最悪の形。
- 対応内容: key に**構造 fingerprint** を入れる。
  `{path}#{method}#{rootKind}.{predicate}:{identity}#{ordinal}`
  例 `Http/Controllers/Projects/ProjectMemberController.php#store#User.findOrFail:$userId#1`
  - `rootKind`: `User` / `DB:users`
  - `predicate`: `findOrFail` / `whereKey` / `where:id:=`
  - `identity`: 正規化した引数 (`$userId` / `$dto->user_id` / `$this->renderJobId`。cast は除去)
  - `ordinal` は**衝突解消用の従属要素**であり主識別子にしない
  fingerprint が変われば「別の候補」として stale + 未分類の**両方**が出るので、
  理由の横滑りが構造的に起きない。

## [Critical] `DB::table()` の対象テーブルが無限定

- 判断: **対応する**
- 根拠: `DB::table('oauth_access_tokens')->where('id', …)` まで候補にすると Passport 内部まで
  分類対象になり、gate の主張 (`ModelDirectFetch`) と母集団がずれる。
- 対応内容: **`App\Models\*` に対応するテーブルだけ**を対象にする。
  `DirectFetchInventory::modelTables()` が `app/Models/` のモデルを列挙して `getTable()` から
  テーブル名集合を作り、走査器へ渡す。`oauth_*` は model を持たないため自動的に対象外。
  これにより実測 `DB::table` 25 件のうち対象は `organizations` / `users` /
  `organization_user` / `project_members` / `ticket_ledger_entries` の 10 件程度に絞られる。

## [Critical] `findMany` / `destroy` / `whereKeyNot` を単数 identity と混ぜている

- 判断: **対応する**
- 根拠: 正しい。`findMany($ids)` / `destroy($ids)` は複数 id、`whereKeyNot($id)` は除外条件で、
  単数前提の副条件 (identity 引数の provenance 判定等) と噛み合わない。
- 対応内容: candidate に **`predicateKind`** を持たせる:
  `SingleIdentity` / `MultiIdentity` / `IdentityExclusion` / `DestructiveIdentity`。
  case ごとの副条件を `predicateKind` に応じて分け、失敗メッセージも分ける。
  - `whereKeyNot` は **v1 スコープに残す** (実測 6 件だが全て relation 起点 / model 由来引数で
    候補化しないため追加コスト ~0。かつ `whereKeyNot($requestId)` は列挙ベクタとして実在しうる)
  - `findMany` / `findOrNew` / `destroy` は**実コード 0 件**だが文法に残す (文字列 1 個の追加でコスト 0、
    将来の混入を止める)

## [Warning] provenance 証明が詳細設計で概念設計より後退している

- 判断: **対応する (指摘どおり後退していた)**
- 根拠: 詳細設計 §3 の表は「型付き引数が `App\Models\*`」で除外するように読め、
  概念設計 §4-2(c) の「**保証済み provenance に属する場合のみ除外**」条件が落ちていた。
- 対応内容: 詳細設計の provenance 節を概念設計に合わせて書き直し、
  **型付き引数であることに加えて、その model が保証済み provenance (route binding / relation 起点 /
  本 gate 分類済み) から来ていること**を要求する。証明できない場合は候補に残す (fail-closed)。

## [Warning] alias 追跡の fail 方向が不明

- 判断: **対応する**
- 対応内容 (**Round 2 で自己修正**): 当初 Codex 提案どおり「再代入が 1 回でもあれば alias 無効」と
  書いたが、それでは `$q = User::query(); if ($x) { $q = $other; } $q->whereKey($id);` が
  **検出されなくなり fail-open** になる (「再代入すれば gate を黙らせられる」= 最も安易な回避)。
  最終仕様は「**一度でも静的起点から代入された変数は、再代入があっても取り消さない**」
  = 過剰検出寄り (fail-closed)。Codex Round 2 でこの自己修正は approve された。

## [Warning] `OwnerScopedQueryConstraint` の右辺 provenance 判定が過大 (`whereHas` ネスト closure)

- 判断: **対応する (v1 で `whereHas` を外す)**
- 根拠: 実測すると `whereHas` を必要とする候補は**存在しない**
  (`MembershipScopedOrganizationBinder` は identity 述語が動的列名 `where($field, $value)` のため
  そもそも候補化しない)。使わない機能を先に作るのは思考原則 2 違反。
- 対応内容: v1 の許可 signature を
  `where('organization_id'|'user_id'|'team_id'|'project_id', $model->getKey()|$model->id)` と
  `whereBelongsTo($model)` の 2 形に限定。`whereHas` は**必要になったとき fixture と一緒に足す**と明記。

## [Warning] `LocalOnlyDiagnostics` の route 照合が環境依存

- 判断: **対応する**
- 対応内容: route 走査だけに依存せず 2 段にする。
  (a) `routeName` の route がテスト環境に存在し `LocalOnly` middleware を持つ
  (テスト環境では `runningUnitTests()` により登録されるため成立する、と根拠をコメントに書く)、
  (b) **`routes/` 側の登録条件リテラル** (`isLocal` / `runningUnitTests`) の存在も併せて固定する。
  片方が環境差で崩れてももう片方が残る。

## [Warning] 債務 case の marker が文字列依存で弱い

- 判断: **対応する**
- 対応内容: marker を**定数リストで明示**する:
  `->organizations()->whereKey(` / `->users()->whereKey(` / `->members()->whereKey(` /
  `whereBelongsTo($organization` / `organizationRole(`。
  **`lockForUpdate` は marker に含めない** (競合制御であって所属検証ではない — Round 3 の指摘と一貫)。
  `verifiedBy` の呼び出し照合は static/instance 両形を受理する
  (`OrganizationMembershipService::transferOwnership` を `$this->membership->transferOwnership(` で
  呼ぶ形が実際の姿) と明記する。

## [Warning] app/Enums への追加は production autoload に入る

- 判断: **対応する (明記のみ)**
- 根拠: 既存 `ControllerAuthorizationExemption` と同じ位置に置く一貫性を優先する。
- 対応内容: 詳細設計に「テスト専用語彙だが、既存 enum との一貫性を優先し production autoload への
  混入を許容する」と明記した。

## [Suggestion] metadata の getter

- 判断: **対応する** (typo が runtime まで残るのは PHPStan level 10 の趣旨に反する)
- 対応内容: `actorSource()` / `enqueuedBy()` / `routeName()` / `commandSignature()` /
  `verifiedBy()` / `validationRule()` / `todoRef()` を生やし、case 不一致なら例外を投げる。

## [Suggestion] degenerate PASS 防止の下限を上げる

- 判断: **対応する**
- 対応内容: 「1 件以上」→ **`>= 20`**。走査器が部分的に壊れて候補が激減した場合も検知できる。

## [Suggestion] `whereRaw` の 0 件 assertion の pattern

- 判断: **対応する**
- 対応内容: `whereRaw` / `whereIntegerInRaw` の**呼び出し自体**を検出し、
  第 1 引数が文字列リテラルなら正規化して `(^|[.\s(])id\b` を見る。
  引数が非リテラルなら**無条件で fail** (中身が読めない = 範囲外経路が生えた合図)。
