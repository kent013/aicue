# 概念設計: ModelDirectFetchInvariantTest (直 find 禁止 gate) の追従

- c2c feature id: `nested-route-idor-defense`
- 裁定: 【2026-08-04 AG-005】正典 t1 = aicue の total inventory + cross-org guard + **t0 の ModelDirectFetchInvariantTest**。
  aicue は total inventory 部を正典 origin へ昇格させた側だが、**t1 に含まれる ModelDirectFetchInvariantTest が不在のため追従要**。
- 本設計のスコープ: 「aicue でこの gate をどう実装するか」だけ。裁定そのものは与件として蒸し返さない。
- 改訂履歴: Round 1 で検出アンカーを述語側へ移動 / **Round 2 で母集団の絞り方を根本的に作り直した** (§4-1) /
  Round 3 で **provenance フィルタに型証明を要求**するよう修正 (§4-2(c))。
  Codex レビューは規定上限の 3 ラウンドを消化済み。残存リスクは
  `codex-history/conceptual-review-decisions-round-3.md` の末尾に記録。

---

## 1. 仮説

**仮説**: この gate は `NestedRouteIdorDefenseTest` の重複ではない。両者が守る母集団は**素で交わらない**。

| gate | id の出所 | 現状 |
|---|---|---|
| `NestedRouteIdorDefenseTest` | **route parameter** (`/projects/{project}/manuals/{manual}`) | 実装済み (1+param の total inventory) |
| `ModelDirectFetchInvariantTest` | **route parameter 以外**の id (POST payload / query / MCP tool 引数 / token claim / queue payload) | **不在** |

`NestedRouteDefenseInventory::candidates()` の母集団は `parameterNames() !== []` の named route である。
`POST /organizations/{organization}/transfer-ownership` の `user_id` のように **body で id を受け取る**経路は、
route parameter を 1 つも増やさないため **inventory に何も現れない**。したがって
「payload の id をテナントに閉じない global クエリでモデル化する」経路は、現状**どの Architecture テストにも捕捉されない**。

**成功条件**: 新しく「テナント/所有者スコープ外のクエリで id からモデルを取得するコード」を書いたとき、
**レビューを通り抜けても CI が落ちる**こと。かつ既存の正当な経路を分類するコストが実装者にとって現実的であること。

成功条件は「**書き方を問わず**落ちる」でなければ意味がない。`Model::find()` だけを禁じても
`Model::query()->where('id', $payloadId)->firstOrFail()` や
`$q = User::query(); $q->where('id', $payloadId)` で等価なことができる。
よって検出規則はメソッド名の列挙ではなく**「静的起点 + 主キー同一性」という意味**に対して張る (§4-2)。

---

## 2. 現状 (実査結果)

ブリーフ・台帳の記述を鵜呑みにせず、実コードを数えた結果。

### 2-1. gate の不在は事実

`tests/Architecture/ModelDirectFetchInvariantTest.php` は存在しない。
`rg 'ModelDirectFetch' .` のヒットは **devnotes と過去監査メモの 4 件のみ**で、実装・テスト・docs には無い。

### 2-2. 過去に「入れない」と判断した記録がある

`devnotes/20260802-1548-aigenba-alignment-audit/audit.md` L163-166:

> **注意**: `ModelDirectFetchInvariantTest` / … は**思想は汎用だが inventory がドメイン固有**。
> AI-CUE には既に等価物がある (`NestedRouteIdorDefenseTest` / …)。**重複導入しない**。

**この判断は §1 の実査で否定される**。`NestedRouteIdorDefenseTest` は route parameter しか見ておらず、
payload / queue payload / token claim 由来 id の global fetch を 1 件も検査していない。
2026-08-04 の c2c 裁定はこの局所判断を上書きしており、本設計は**裁定側に従う**。

### 2-3. ノイズは「ディレクトリの形」ではなく「id の出所の形」をしている ★本設計の中核

素朴に「`app/` 全体で直 fetch 禁止」とすると分類対象が 100 件超になり、inventory が形骸化する。
しかし実際に数えると、その大半は**同じ 1 つの理由**で本 gate の関心外だった。

| | 件数 |
|---|---|
| `app/` 全体の static-rooted 主キー同一性クエリ | **70** |
| うち識別子引数が `$model->getKey()` / `$model->id` / `$model->{fk}_id` = **解決済みモデル由来** | **37** |
| **分類が必要な候補** (旧 syntactic フィルタでの実測) | **33** |

> §4-2(c) で provenance に**型証明**を要求する修正を入れたため、実際の候補数はこれより増える
> (見積り **33〜40**)。正確な初期 inventory は実装者が走査器を流して確定する。

除外される 37 件の実体は `Project::whereKey($project->id)->lockForUpdate()->firstOrFail()` 型、
すなわち**既にテナント検証済みのモデルを同一 tx 内で行ロック再取得する**形である。

**この 37 件を除外するのが正当な理由**: 識別子が解決済みモデル由来なら、
**その元モデルの解決自体が候補として別途検査される**。provenance は候補へ遡及するので取りこぼしにならない。
`$project` が正しく解決されているなら `$project->id` も正しく、`$project` の解決が怪しいなら
**`$project` を作った行が候補として捕まる**。

> **Round 2 の設計転換**: 初期案はこのノイズを避けるために母集団を entrypoint 層 (`app/Http` + `app/Mcp`) に
> 絞っていた。しかしそれは「Controller が scalar id を Service に渡し Service 側で global fetch する」
> という明白な抜け道を生む。フィルタを**ディレクトリではなく識別子引数の出所**に掛け直したところ、
> **母集団を `app/` 全体に広げても候補は 33 件**に収まることが分かった。
> 母集団を絞る理由が消えたので絞るのをやめた。

### 2-4. 候補の内訳 (全件読んだ結果)

| 群 | 件数 | 実体 | 代表 |
|---|---|---|---|
| queue payload 再水和 | 8 | `Model::query()->find($this->xxxId)` — id は enqueue 時にサーバが確定 | `Jobs/Manual/RunManualRender.php` |
| token / actor 由来 | 8 | Passport grant・`ResolveApiActor`・`McpAuthorizationContext`・`RevokeSessionController` | `Http/Middleware/ResolveApiActor.php` |
| 同一クエリ内で所有者スコープ | 1 | `Passkey::query()->whereKey($id)->where('user_id', $user->getKey())` (意図的設計) | `Http/Routing/SelfScopedPasskeyBinder.php` |
| テナントスコープ済みクエリで確定した id | 〜9 | `$id = $organization->projects()->value('id')` の直後に `Project::whereKey($id)->lockForUpdate()` | `Services/Project/DefaultProjectResolver.php` |
| **payload 由来 id の global fetch** | **2** | **`User::query()->findOrFail((int) $request->input('user_id'))`** | `OrganizationOwnershipController` / `ProjectMemberController` |
| request 由来だが membership 検証が直後にある | 1 | `Organization::query()->find($orgId)` → `$user->organizations()->whereKey()->exists()` | `Http/Middleware/McpConsentOrganizationBinder.php` |
| local 限定 | 1 | route 登録自体が local + `LocalOnly` middleware | `Http/Controllers/DebugLoginController.php` |
| 運用コマンド | 1 | 対話的 admin MFA リセット | `Console/Commands/ResetAdminMfaCommand.php` |

**本当に注視すべきは 2 件**である。この 2 件は request payload の `user_id` を
**テナントに一切関係しない global クエリ**でモデル化しており、両者とも
`'user_id' => ['required', 'integer', 'exists:users,id']` という**グローバルなユーザー存在検証**を伴う。

MCP tool (`ShowProjectTool` / `ListItemsTool`) は `$ctx->organization->projects()->whereKey($projectId)` と
**relation 起点**で書かれており候補にすら上がらない。この層は既に正しい。

### 2-5. `routes/*.php` は候補 0 件 (だが母集団に入れる)

route closure は 29 個あるが、model / 主キーアクセスは **0 件** (全て middleware / grouping)。
「route closure に業務ロジックを書けない」ことを保証する既存 gate は本リポジトリに**存在しない**ため、
`User::find($request->input('user_id'))` を closure に書けば素通りする。
**コスト 0 で穴が 1 つ閉じる**ので母集団に含める。

### 2-6. 規約は文章としては既に存在する (機械強制だけが無い)

`docs/app-integration-guide.md` §7 不変条件 3 / AGENTS.md セキュリティ不変条件 3:

> **cross-org 不可**: いかなる経路でも組織を跨いだ read/write が起きない
> (Service 層 + DB CHECK の多層。**直 fetch せず relation/Builder スコープ経由**)

**「直 fetch せず」は既に宣言済みでありながら、対応する Architecture テストが無い唯一の不変条件**である
(不変条件 1/2/5/8/9 はすべて対応 gate を持つ)。AGENTS.md 禁止事項 1 に照らすと不変条件 3 は未完了である。

---

## 3. 課題

1. **id → global モデル化**の経路が機械検出されない。実害のある 2 件が
   「安全である理由」をコードコメントにしか持たず、レビュアーの注意力に依存している。
2. 新しい payload id 受け口を後から足したとき、**relation 起点で書かなかったことに誰も気付けない**。
   route を増やさないため `NestedRouteIdorDefenseTest` も `TenantBoundaryOrderingTest` も沈黙する。
3. 逆に `SelfScopedPasskeyBinder` / `MembershipScopedOrganizationBinder` のように
   **静的起点だが同一クエリでスコープを閉じている正しい実装**が存在するため、
   「`Model::` 起点を一律禁止」という素朴な規則は使えない。分類が要る。

---

## 4. 方針

**deny-by-default の inventory 型 Architecture テストを 1 本追加する。**
本リポジトリに既にある同型の gate (`ControllerAuthorizationGateTest` + `ControllerAuthorizationExemption` enum、
`ScenarioWritePathInventoryTest` の token 走査、`AuthorizationMarkerScanner` の分離) の作法を踏襲する。

### 4-1. 母集団

```
app/**/*.php        routes/*.php
```

**全層**。層で絞らない (§2-3)。ノイズは §4-2 の provenance フィルタで落とす。

### 4-2. 候補の定義

候補 = 次の 3 条件をすべて満たす式。

**(a) 静的起点である**

- `User::…` / `self::…` / `static::…` — ただし**そのクラス名が `App\Models\*` に解決できる場合に限る**。
  解決経路は 3 つとも対応する: (i) ファイルの `use` import、(ii) FQCN 直書き (`\App\Models\User::…`)、
  (iii) 同一 namespace 参照 (`app/Models/` 配下のファイル内)
- `new App\Models\*` 起点 (`(new User)->newQuery()->whereKey($id)` / `(new User)->query()->…`)
- `DB::table('users')->…` / `DB::table('users as u')->…` / `DB::connection(…)->table(…)->…`

> 内部概念名を「静的起点」ではなく **`ClassRootedPrimaryKeyQuery`** とするのは、
> `new` 起点を含むためである (Round 3 Warning 対応。`new` 起点は書く頻度こそ低いが
> gate の回避としては最も簡単な部類なので対象に含める)。

> `use` import による裏取りは `AuthorizationMarkerScanner::importsGateFacade()` と同じ作法。
> これが無いと同名の別クラスで誤検出する。実際、素朴な正規表現による事前調査では
> `LogoutResponse` の**docblock 中の `AuthenticatedSessionController::destroy()`** を誤検出した
> (トークン段でのコメント除去 + import 裏取りの両方が要ることの実例)。

**(b) 主キー同一性述語を含む**

| 対応する構文 | 例 |
|---|---|
| find 系 | `find(` / `findOrFail(` / `findOrNew(` / `findMany(` / `destroy(` |
| key 述語 | `whereKey(` / `whereKeyNot(` |
| 列指定 (**等価・IN のみ**) | `where('id', $x)` / `where('id', '=', $x)` / `whereIn('id', $xs)` / `firstWhere('id', $x)` |
| qualified 列 | `where('users.id', $x)` / `where($m->getQualifiedKeyName(), $x)` / `where($m->getKeyName(), $x)` |
| magic where | `whereId(` |
| array 形 | `where(['id' => $x])` / `where([['id', '=', $x]])` |

- **等価・IN に限る**。`where('id', '>', $cursor)` は主キー同一性ではなく順序比較であり候補にしない
  (`ManualRenderController:122` に実在する正当なカーソル処理を誤検出しないため)。
- **非対応 (範囲外)**: `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`)。
  これらは fixture 名を `outOfScope_*` とし、「検出しないことを**保証**する」ではなく
  「**既知の範囲外**である」と読める形で固定する。
  加えて、範囲外を放置しないために **`whereRaw('id` / `whereIntegerInRaw('id'` が
  app 全体で 0 件であることを本テスト内の別 assertion で固定する** (現状 0 件。
  出現した時点で fail し、範囲外の経路が実際に生えたことを検知できる)。

**(c) 識別子引数が「保証済み provenance」でない**

`$model->getKey()` / `$model->id` / `$model->{fk}_id` の**形をしている**だけでは除外しない。
`$dto->user_id` / `$payload->project_id` / `$requestData->organization_id` はトークン上まったく同じ形であり、
形だけで除外すると **payload object 由来 id の global fetch が静かに消える**。

除外は「**変数が Eloquent モデルであると証明できる場合**」に限る。証明手段は次の 3 つだけ:

1. **型付き引数**が `App\Models\*` (`public function foo(Project $project)`)
2. **PHPDoc で明示**された `App\Models\*` (`/** @var Project $locked */`)
3. **同一メソッド内で** relation 起点クエリ (`$x->rel()->…`) または本 gate の候補式から代入された変数

証明できなければ**候補に残す** (fail-closed)。

> **除外の正当性が成り立つ条件** (Round 3 Critical 対応):
> 「識別子が解決済みモデル由来なら、その元モデルの解決自体が候補として捕まる」という遡及は
> **無条件には成立しない**。元モデルが `where('uuid', $requestUuid)` / `where('slug', …)` /
> 外部 DTO / 手動 `new Model([...])` で解決されていれば、主キー同一性の候補には現れない。
>
> したがって除外してよいのは、元モデルが**別の保証済み provenance に属する**場合に限る:
>
> | 保証済み provenance | 誰が保証するか |
> |---|---|
> | route binding で解決された model | `NestedRouteIdorDefenseTest` + `TenantBoundaryOrderingTest` |
> | `{project}` の org 帰属 | `ProjectRouteCurrentOrgGuardTest` (aicue:D4 middleware) |
> | relation 起点クエリの結果 | 構造的にテナントに閉じている |
> | 本 gate の候補として分類済みの式の結果 | 本 gate 自身 |
>
> **上記のいずれでもない model-derived 引数は除外しない**。この条件を走査器の docblock と
> テストの失敗メッセージに明記し、「モデルっぽい形」で逃げられないようにする。

**この変更により候補数は §2-3 の 33 件より増える** (型証明できない `$x->{fk}_id` が候補に戻るため)。
実測ベースの見積りは **33〜40 件**。正確な初期 inventory は実装者が走査器を実際に流して確定する。

### 4-3. builder alias の追跡

`$q = User::query();` `$q->where('id', $payloadId)->firstOrFail();` は (a) の「同一 chain」を満たさない。
これを許すと規則が空洞化するため、**同一メソッド内に限った保守的な alias 追跡**を行う:

- `$var = <静的起点式>` の**単純代入**のみ静的起点として伝播する
- `$var` への**再代入**で追跡を打ち切る (invalidate)
- 引数渡し・プロパティ代入・条件分岐をまたぐ伝播は**追跡しない** (限界として明記)

完全なデータフロー解析はしない。「単純代入で逃げる」という最も安易な回避だけを塞ぐ。

### 4-4. 分類 (deny-by-default)

全候補は `App\Enums\Security\DirectFetchJustification` の case + **30 文字以上の具体的根拠**を
対で登録しなければ fail する。未登録は fail。登録があるのに実コードに無い (stale) 場合も fail する。

**根拠文の文字数だけでは case は守れない**ため、case ごとに**機械副条件**を課す:

| case | 適用条件 | 機械副条件 |
|---|---|---|
| `OwnerScopedQueryConstraint` | **同一クエリ内**に所有者/テナント制約があり、取得後に弾いていない | (a) 同一 chain に**許可 signature が列挙一致**: `where('organization_id'\|'user_id'\|'team_id'\|'project_id', …)` / `whereHas('users'\|'organizations'\|'projects', …)` / `whereBelongsTo($user\|$organization)` (`where('active', true)` では通らない)。(b) **その右辺が §4-2(c) の provenance 証明を満たすこと** (`where('organization_id', $requestOrgId)` では通らない) |
| `IdDerivedFromTenantScopedQuery` | 識別子が**同一メソッド内のテナントスコープ済みクエリ**で確定している | 同一メソッド本文で当該変数への代入式が relation 起点 (`$x->rel()->…`) であること |
| `AuthenticatedActorScope` | id が**認証済み actor / 検証済み token claim** 由来 | (a) 同一メソッド内に request accessor が**存在しない** (negative check)、(b) 構造化 field `actorSource` (`authenticated_user` / `validated_token_claim` / `passport_token_record`) を必須 |
| `QueuePayloadRehydration` | id が **enqueue 時にサーバが確定した job property** 由来 | (a) ファイルが `app/Jobs/**` 配下、(b) 識別子引数が `$this->{名前が Id で終わる property}`、(c) 構造化 field `enqueuedBy` に **dispatch 元の `Class::method`** を必須 |
| `LocalOnlyDiagnostics` | route 登録自体が local 限定で production から到達不能 | 構造化 field `routeName` を必須とし、**route 走査で当該 route に `LocalOnly` middleware が付いている**ことを照合する (ファイル内文字列一致では弱いため) |
| `OperatorInvokedConsoleCommand` | 人間の運用者が CLI で明示実行する。HTTP から到達不能 | (a) ファイルが `app/Console/Commands/` 配下、(b) 構造化 field `commandSignature` を必須 (scheduler / queue から呼ばれる command と区別するため、根拠文に呼び出し主体を書かせる) |
| **`PayloadIdWithGlobalExistenceRuleDebt`** | **payload 由来 id を global に引いており、補償チェックは fetch の後段にある = 準拠形ではなく債務** | (a) 構造化 field `verifiedBy` に検証を行う `Class::method` を必須、(b) **呼び出し側がその exact method を呼んでいる**こと、(c) **当該メソッド本文** (クラス全体ではない) に membership/tenant marker があること、(d) 構造化 field `validationRule` (例 `exists:users,id`) と **`todoRef` (後続 TODO の ID) を必須** |

> **`PayloadIdWithGlobalExistenceRuleDebt` を準拠 case と分けた理由**: §2-4 の 2 件は
> 「fetch **後**に補償する」形であり、「fetch **時点で**スコープが閉じている」他 case と
> 安全性の質が違う。同列に並べると「補償チェックがあれば OK」という運用に流れる。
> **debt であることを case 名で可視化**し、後続 TODO (§7-1) の入口にする。

> **`AuthenticatedActorScope` は完全な機械証明ができない**。「id の出所が認証済み actor か」は
> データフロー解析であり token 走査の範囲外である。negative check と構造化 field で
> 濫用を抑えるが、**最終的には人手の根拠文に依存する**と明示的に記録する
> (限界を曖昧にしないことが deny-by-default 運用の前提)。
>
> **`QueuePayloadRehydration` を `AuthenticatedActorScope` から分けた理由** (Round 3 Warning 対応):
> actor/token と queue payload は**信頼境界が違う**。前者は「リクエストごとに検証される認証情報」、
> 後者は「過去のリクエストが確定してシリアライズした値」であり、
> dispatch 元が間違っていれば queue payload は汚染されうる。同じ case に混ぜると
> 「job だから安全」という誤った一般化を生むため、`enqueuedBy` を名指しさせる別 case にする。

### 4-5. 走査器は独立させ、走査器自体をテストする

`AuthorizationMarkerScanner` と同じ思想。正規表現ではなく `token_get_all` の状態機械にし、
**コメント / docblock / 文字列リテラル中の出現を除去する** (§4-2 の誤検出実例)。
走査器の positive/negative は `tests/Unit/Architecture/` の専用テストで恒久固定する
(gate 自体がセキュリティ機構であり、走査器が壊れたら gate は静かに無力化するため)。

内部概念名は **`PrimaryKeyConstrainedStaticQuery`**、走査器は `PrimaryKeyStaticQueryScanner` とする
(テストクラス名 `ModelDirectFetchInvariantTest` は c2c 台帳上の gate 識別子なので変えない。
両者の関係は docblock に書く)。

### 4-6. 本 gate が保証しないこと (主張範囲)

「不変条件 3 を全面的に機械強制する」とは**主張しない**。本 gate が保証するのは
「**静的起点 + 主キー同一性によるモデル取得**」という具体的経路が漏れなく分類されていることだけである。
relation/org-scoped 解決の一般的強制、到達可能性、`whereRaw` 等の動的クエリ、
`exists:` validation rule による存在漏れは**範囲外**であり、範囲外であることをテストの docblock に書く。

---

## 5. 代替案と却下理由

| # | 案 | 却下理由 |
|---|---|---|
| A | **allowlist 方式** (ファイル単位で「このファイルは直 fetch してよい」) | ファイル単位 allowlist は**そのファイル内の新しい違反を丸ごと免除**する。`ScenarioWritePathInventoryTest` が検出 3/5 でわざわざ「宣言元も呼び出し元も名指しする」形にしているのと同じ理由。候補単位 + 根拠 + 機械副条件にする |
| B | **母集団を entrypoint 層に絞る** (Round 1-2 の旧案) | Controller が scalar id を Service に渡し Service 側で global fetch すると沈黙する。§2-3 の実測により**絞らなくても 33 件**と分かったため、絞る理由が消えた |
| C | **gate を入れず、該当 2 件をリファクタして終わり** | 「今あるものを直す」だけで**将来の混入を止めない**。裁定の要求 (gate の追従) を満たさない |
| D | **PHPStan のカスタムルールで実装** | 本リポジトリにカスタムルール基盤が無く extension 登録が要る。既存の不変条件はすべて `tests/Architecture/` (60 本超) に集約されており、置き場所を割るとレビュー時に発見されない |
| E | **nikic/php-parser で AST 解析** | 直接依存ではなく推移依存。既存走査器 (`ScenarioWritePathScanner` / `AuthorizationMarkerScanner`) は全て `token_get_all` 流儀で、ここだけ流儀を割る利得が無い |
| F | **route parameter も本 gate の母集団に含めて一本化** | `NestedRouteIdorDefenseTest` と母集団が重なり同じ経路を 2 か所に登録させる。思考原則 4。route param 側は既に total inventory 済み |
| G | **`Model::` 起点を一律禁止 (分類なし)** | `SelfScopedPasskeyBinder` は **static 起点であることが正しい設計** (relation は vendor 型で解決されるため `App\Models\Passkey` 型を返せない、という明示コメントがある)。一律禁止は正しい実装を壊す |

---

## 6. スコープに入れないもの (と理由)

1. **該当 2 件 (`OrganizationOwnershipController` / `ProjectMemberController`) の実装リファクタ**
   — 本タスクの目的は「機械検出を入れる」こと。`PayloadIdWithGlobalExistenceRuleDebt` として
   **根拠付きで可視化するところまで**で止め、振る舞いを変えない。
   `exists:users,id` の見直しとセットでないと存在オラクルは閉じず、403/404/422 の変化を伴うため
   単独 TODO として切り出すべき別課題である (§7-1)。
2. **`exists:` validation rule による存在漏れ一般の統制** — 攻撃面は隣接するが機構が別
   (validation rule の検査は route/FormRequest 側の話)。§4-6 のとおり本 gate の主張範囲外。
   ただし該当 2 件は `PayloadIdWithGlobalExistenceRuleDebt` の `validationRule` field に必ず現れる。
3. **route closure から helper `request('user_id')` を経て raw SQL へ至る経路**
   — `DB::select` / `whereRaw` は §4-2(b) の非対応構文であり本 gate の範囲外。
   現状 routes に該当 0 件であり、`whereRaw('id` の 0 件 assertion (§4-2) が生えたときに気付く。
4. **`NestedRouteIdorDefenseTest` / `NestedRouteDefenseInventory` への変更**
   — 正典 t1 の total inventory 部は aicue が origin 側であり既に要件を満たしている。触らない。
5. **cross-org 存在オラクル封じ middleware (aicue:D4 / `EnsureProjectBelongsToCurrentOrganization`)**
   — t1 の構成要素だが aicue には既に実装済み (`ProjectRouteCurrentOrgGuardTest` が固定)。追従不要。
6. **`app/Filament/**` の Filament リソース** — 母集団には入るが、admin パネルは
   `/admin` 配下で別の認可体系。候補が出たら通常どおり分類する (特別扱いしない = 除外もしない)。
7. **c2c 台帳への `status_reported` 書き戻し** — 実装が main にマージされ commit が push された後の作業。
8. **frontend の変更** — 一切無い。Svelte / DS token / Inertia props に波及しない。

---

## 7. 後続 TODO 候補 (本タスクでは実施しない)

1. **payload 由来 `user_id` 2 箇所の org 相対化 + `exists:users,id` の見直し**
   — `User::query()->findOrFail($userId)` を `$organization->users()->whereKey($userId)->firstOrFail()` へ。
   **fetch 側だけ直しても validation が同じ情報を漏らす**ためセットで扱う。
   振る舞い変更 (403 → 404 等) を伴うため別 TODO。本 gate 導入後は当該 2 箇所が
   `PayloadIdWithGlobalExistenceRuleDebt` として inventory に載るので、起票の材料が揃った状態になる。
2. **`whereRaw` / 動的列名の検出** — 現状 0 件のため作らない。出現したら再検討。
3. **template / 他リポジトリへの還流** — 「provenance フィルタで候補を 1/2 に落とす」着想は
   aigenba の allowlist 方式より運用コストが低い可能性がある。c2c 側の議題としてキュレーターに委ねる。

---

## 8. 検証方法

### 8-1. 通常の検証

| 段階 | コマンド | 期待 |
|---|---|---|
| 走査器の単体 | `composer test -- --filter=PrimaryKeyStaticQueryScanner` | positive/negative fixture が全 green |
| gate 本体 | `composer test -- --filter=ModelDirectFetchInvariant` | 初期 inventory (約 33 件) で green |
| 型 | `composer phpstan` | level 10 green |
| 整形 | `vendor/bin/pint --test` | green |
| 全体 | `composer test` | green (app/ のコードを 1 行も変えないため回帰面は無い) |

### 8-2. **抜け道 fixture が fail すること** (これが本体)

inventory が green になることは gate が効いている証明にならない。
**次の 7 種を走査器の positive fixture として持ち、すべて検出されることをテストで固定する**:

| # | fixture | 塞ぐ指摘 |
|---|---|---|
| 1 | `User::query()->where('id', $payloadId)->firstOrFail()` | Round 1 Critical (述語アンカー) |
| 2 | `$q = User::query(); $q->where('id', $payloadId)->first()` | Round 2 Critical (builder alias) |
| 3 | Service のメソッドが scalar `$userId` を受け `User::findOrFail($userId)` | Round 2 Critical (Service 委譲) |
| 4 | `User::query()->where('users.id', $id)` (qualified 列) | Round 2 Warning (文法) |
| 5 | `User::query()->where(['id' => $id])` (array 形) | Round 2 Warning (文法) |
| 6 | `User::destroy($id)` | Round 2 Warning (文法) |
| 7 | `DB::table('users')->where('id', $payloadId)` | Round 1 自己検出 |
| 8 | `\App\Models\User::query()->whereKey($id)` (FQCN 起点) | Round 3 Warning |
| 9 | `DB::table('users as u')->where('u.id', $id)` (alias 付き qualified) | Round 3 Warning |
| 10 | `User::whereId($id)` / `User::query()->where('id', '=', $id)` / `User::query()->whereIn('users.id', $ids)` | Round 3 Warning |
| 11 | `(new User())->newQuery()->whereKey($id)` (`new` 起点) | Round 3 Warning |
| 12 | **`$dto->user_id` を識別子引数に持つ global fetch** (型証明できない `->{fk}_id`) | **Round 3 Critical** |

加えて **negative fixture** (検出してはならないもの) も固定する:

- `$organization->users()->whereKey($id)` — relation 起点
- **型付き引数 `Project $project` の** `Project::whereKey($project->id)->lockForUpdate()` — provenance 証明あり
- `$manual->renderJobs()->where('id', '>', $cursor)` — 順序比較で主キー同一性でない
- `Plan::query()->where('code', $code)` — 主キーでない
- **docblock 中の `Foo::destroy()`** — コメント除去 (実在の誤検出例)
- `$q = User::query(); $q = $other; $q->whereKey($id)` — alias invalidation が効いて**検出しない**

`outOfScope_*` fixture: `User::query()->whereRaw('id = ?', [$id])` / `User::query()->where($col, $id)`。

### 8-3. deny-by-default が生きていることの確認

| 操作 | 期待 |
|---|---|
| inventory から 1 件削って再実行 | **fail** |
| 実在しない箇所を inventory に足して再実行 | **fail** (双方向整合) |
| `OwnerScopedQueryConstraint` を機械副条件を満たさない箇所に付ける | **fail** |
| `AuthenticatedActorScope` を request accessor のあるメソッドに付ける | **fail** |

---

## 9. 使命との整合

AI-CUE は SOP / 動画マニュアルという**組織の資産**を扱う。組織を跨いだ read/write は
「現場のノウハウが他社に漏れる」ことと同義であり、機能の魅力以前の前提条件である。
本 gate は新機能を足さないが、**「静的起点 + 主キー同一性による直 fetch が、分類なしにコードへ入り込まない」**
という土台を機械化し、使命の前提を守り続けるコストを人間のレビューから CI へ移す。

(§4-6 のとおり、これは「cross-org read/write が起きない」ことの**全面的な証明ではない**。
本 gate は不変条件 3 のうち機械化しうる具体的経路 1 本を受け持つ。過大に主張しない。)
