# 対応マトリクス: design-review Round 2

Round 2 の [Critical] 3 件はいずれも「**v1 のスコープを安全側へ縮めろ**」という指摘であり、
思考原則 2 (今必要なものだけ作る) と方向が一致する。全件対応した。

## [Critical] `routes/*.php` の closure が key 化できない

- 判断: **対応する**
- 根拠: 指摘のとおり。走査器がメソッド境界前提だったため、概念設計 §2-5 で母集団に入れた
  「route closure の直 fetch」を key 化できず、**入れた意味が無かった**。
- 対応内容: `routes/*.php` に**疑似スコープ**を導入 (`__file` / `__closure{n}` / `__fn{n}`)。
  key 例 `routes/web.php#__closure1#User.find:$userId#1`。
  Unit テストに positive fixture 18 (`Route::post(..., function () { User::findOrFail(request('user_id')); })`) を追加。

## [Critical] 「本 gate で分類済みの式の結果」provenance が循環している

- 判断: **対応する (v1 から外す)**
- 根拠: 完全に正しい。`candidates()` が provenance 判定をする時点で inventory を知らない。
  `rawCandidates()` → gate が照合 → dependent 除外の 2 段構成はコストが跳ねる。
- 対応内容: **v1 から削除**。`$var = <候補式>` 由来の `$var->id` 再利用も**候補に残す** (fail-closed)。

## [Critical] route binding provenance が広すぎる

- 判断: **対応する (v1 から外す)**
- 根拠: 「型付き引数である」は安全性を証明しない、という指摘は正しい。
  route list から `Controller::method` → parameter 名を突合し
  `NestedRouteDefenseInventory` 側の分類まで確認するのは v1 には過大。
- 対応内容: **第 2 段 provenance 証明を v1 では実装しない**と明記し、
  残る理論的リスク (呼び出し側が非主キー一意列で untrusted 入力から model を解決している)
  に対して**代償措置**を置いた。

### 代償措置の根拠 (実測)

`app/` の非主キー一意列による解決を数えた:

| pattern | 件数 |
|---|---|
| `where('uuid'` / `where('slug'` / `where('public_id'` / `where('ulid'` | **0** |
| `where('code', …)` | 3 (すべて `Plan` = グローバルカタログ) |
| `whereBlind(…)` | 十数件 (すべて CipherSweet の email/name 検索) |

**リスクは現時点で空**である。よって **テスト 13 (非主キー一意列解決の 0 件固定)** を置き、
1 件でも生えたら fail させて provenance 前提を再検討させる形にした。
`where('code', …)` と `whereBlind(…)` のみ理由付きで除外する
(前者はテナント資源でない、後者は AGENTS.md 不変条件 6 が別途統制)。

> これは「第 2 段を諦めた」のではなく「**第 2 段が守っていた前提そのものを 0 件で固定した**」
> 形であり、実装コストを跳ね上げずに同じ性質を担保する。

## [Warning] 対応マトリクスに古い alias 記述が残っている

- 判断: **対応する**
- 対応内容: `design-review-decisions-round-1.md` の当該項目を最終仕様
  (「再代入があっても取り消さない」= fail-closed) に統一した。

## [Warning] 同一 fingerprint 重複時の横滑りが残る

- 判断: **対応する**
- 対応内容: **テスト 15** を追加。同一 `{path, method, rootKind, predicate, identity}` が
  複数ある場合、duplicate group と `chainSource` の preview をメッセージに出して
  人間の明示確認を強制する。

## [Warning] enum docblock と v1 の許可 signature が不一致 (`whereHas`)

- 判断: **対応する**
- 対応内容: `OwnerScopedQueryConstraint` の docblock から `whereHas` を外し、
  「v1 の gate では未対応。必要になったら fixture と同時に足す」と明記。

## [Warning] 初期 inventory 例が旧 key 形式のまま

- 判断: **対応する**
- 対応内容: 例 7 件をすべて fingerprint 入りの新 key 形式へ更新
  (例 `Jobs/Manual/RunManualRender.php#handle#RenderJob.find:$this->renderJobId#1`)。

## [Warning] `QueuePayloadRehydration` と `predicateKind` の対応が未整理

- 判断: **対応する**
- 対応内容: **case × predicateKind の許可表**を追加し、表に無い組み合わせは fail とした。
  - `QueuePayloadRehydration + SingleIdentity`: `$this->{…Id}`
  - `QueuePayloadRehydration + MultiIdentity`: `$this->{…Ids}` を許可
  - **`QueuePayloadRehydration + DestructiveIdentity`: v1 禁止**
    (「queue payload の id で無検証に削除」を設計段階で塞ぐ。現状 0 件)
  - 債務 case は `SingleIdentity` 限定 (債務を新しい形へ広げさせない)

## [Warning] `verifiedBy` の instance 呼び出しは exact class を証明できない

- 判断: **対応する (前者=コストを抑える案を採用)**
- 根拠: 指摘のとおり `$this->membership` の型は token だけでは追えない。
- 対応内容: v1 は「メソッド名の呼び出しが同一メソッド内にある」+「`verifiedBy` の
  クラスファイルが実在し当該メソッド本文に marker がある」+「根拠文」の 3 点で受理し、
  **「exact class / exact method を証明した」とは主張しない**とテスト docblock に明記。

## [Suggestion] `modelTables()` の Reflection 前提

- 判断: **対応する**
- 対応内容: 導出手順を 4 段で明記 (`isInstantiable()` + `is_subclass_of(Model::class)` 確認 →
  `newInstanceWithoutConstructor()` で `getTable()`)。
  加えて `$explicitTables` (v1 は空) を持ち、model の無い security-sensitive table を
  後から足せる形にした。

## [Suggestion] request accessor を fixture 化

- 判断: **対応する**
- 対応内容: positive fixture 19 として
  `$request->input()` / `$request->query()` / `$request->validated()` / `request()` / `request('x')`
  をすべて accessor と認識することを固定。
