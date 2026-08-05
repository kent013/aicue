# Round 3: Round 2 指摘への対応 (設計の中心を作り直しました)

Round 2 の [Critical] 3 件を受けて、設計の中心を作り直しました。要点は 1 つです:

> ノイズはディレクトリの形をしていない。**id の出所 (provenance) の形**をしている。

実測し直した結果、識別子引数が `$model->getKey()` / `$model->id` の形のものを外すだけで
`app/` 全体 70 件 → **候補 33 件**になりました。母集団を entrypoint 層に絞る必要が
そもそも無かったため、**母集団を app/ 全体 + routes/*.php に戻し、検出 B を廃止**しました。
これにより Service 委譲の抜け道は検出 A が直接塞ぎます。

再レビューをお願いします。特に次を見てください:
1. provenance フィルタ (§4-2 (c)) に穴はないか。
   「識別子が解決済みモデル由来なら元モデルの解決が候補として捕まる」という遡及の議論は成立しているか
2. builder alias 追跡 (§4-3) の保守的な仕様で、実装可能かつ十分か
3. case ごとの機械副条件 (§4-4) が濫用抑止として妥当か。特に
   `PayloadIdWithGlobalExistenceRuleDebt` を準拠 case から分けた判断
4. §4-6 で「保証しないこと」を明示した範囲設定が誠実か (過小主張になっていないか)
5. §8-2 の抜け道 fixture 7 種に、まだ足りないものがあるか

---

# 対応マトリクス: conceptual-review Round 2

Round 2 の指摘を受けて**設計の中心を作り直した**。要点は 1 つ:

> ノイズ (分類しても意味がない 100 件超) は **ディレクトリの形** をしていない。**id の出所 (provenance) の形**をしている。

Round 1-2 の設計は「Services を母集団から外す」ことでノイズを避けていたが、これは
Codex の [Critical] (Service 委譲で抜ける) をそのまま招く筋の悪い解だった。
フィルタを**識別子引数の出所**に掛け直したところ、母集団を `app/` 全体に広げても
**分類対象は 33 件**にしかならないことを実測で確認した。母集団を絞る理由が消えたので絞るのをやめる。

## [Critical] builder alias (`$q = User::query(); $q->where('id', …)`) で抜ける

- 判断: **対応する**
- 根拠: 指摘どおり。token 走査で実装可能な範囲の抜け道であり、放置は成功条件と矛盾する。
- 対応内容: 同一メソッド内に限定した保守的な alias 追跡を仕様に追加
  (`$var = <静的 root>` の単純代入を静的 root として伝播、再代入で invalidate、
  それ以外の代入元は追跡しない)。§4-3。

## [Critical] 検出 B は Service 委譲の抜け道を塞いでいない

- 判断: **対応する (指摘を全面的に受け入れ、検出 B を廃止)**
- 根拠: 指摘は正しい。B は「入口の可視化」であって「後段解決の保証」ではない。
  提案 1 (Service 側も限定的に sink 検出) を採るべく実測したところ、
  **provenance フィルタを掛ければ `app/` 全体でも候補は 33 件**だった (下表)。
  つまり「母集団を絞る」必要がそもそも無かった。
  母集団を `app/` 全体に戻せば Service 委譲の抜け道は**検出 A が直接塞ぐ**ため、
  B の存在理由が消滅する。存在理由が消えた機構を残すのは思考原則 3 (並走を残さない) に反する。
- 対応内容: **検出 B を削除**。母集団を `app/**` + `routes/*.php` に拡大。
  B が担っていた「`exists:users,id` の debt 可視化」は、該当 2 箇所の case を
  `PayloadIdWithGlobalExistenceRuleDebt` (専用 debt case) に分けることで維持する
  (Round 2 [Warning] `KnownExistenceOracleDebt` の提案を採用)。

### 実測 (provenance フィルタの効き)

| | 件数 |
|---|---|
| `app/` 全体の static-rooted 主キー同一性クエリ | **70** |
| うち識別子引数が `$model->getKey()` / `$model->id` / `$model->{fk}_id` = **解決済みモデル由来** | **37** (自動除外) |
| **分類が必要な候補** | **33** |

除外が正当な理由: 識別子が解決済みモデル由来なら、**その元モデルの解決自体が候補**として
別途検査される。provenance は候補へ遡及するので取りこぼしにならない。
(旧案の `LockedRefetchOfVerifiedModel` case はこのフィルタに吸収されて**不要になった** =
Round 2 [Warning]「`User::whereKey($requestId)->lockForUpdate()` でも通る」も同時に解消。)

## [Critical] `routes/*.php` が母集団に入っていない

- 判断: **対応する**
- 根拠: 指摘どおり。route closure に業務ロジックを書けない gate は本リポジトリに存在しない。
- 対応内容: `routes/*.php` を母集団に追加。実測すると **routes に model/PK アクセスは 0 件**
  (closure は 29 個あるが全て middleware/grouping)。**コスト 0 で穴が 1 つ閉じる**ので入れない理由がない。

## [Critical] case 副条件が濫用抑止として弱い

- 判断: **対応する** (3 つの指摘すべて)
- 対応内容:
  - `OwnerScopedQueryConstraint`: 「追加の where があればよい」を廃し、
    **許可する tenant/owner 制約 signature を列挙**する
    (`organization_id` / `user_id` / `team_id` / `project_id` 列、`whereHas('users'|'organizations')`、
    `whereBelongsTo($user|$organization)`)。`where('active', true)` では通らない。
  - `LockedRefetchOfVerifiedModel`: **case ごと廃止** (provenance フィルタに吸収)。
  - `PayloadIdWithCompensatingCheck`: 「同一メソッドに marker がある」だけでは不十分という指摘を受け、
    **同一の識別子変数が検証呼び出しに渡ること**を条件に追加。さらに「fetch **後**の補償チェック」で
    あることを case 名と説明に明記し、準拠形ではなく **debt** として扱う。

## [Warning] 述語アンカーの文法が狭い / `where('id','>',…)` の誤検出

- 判断: **対応する**
- 対応内容:
  - **等価・IN に限定**する (`where('id', $x)` の 2 引数形、3 引数形は演算子が `=` / `in` のときのみ)。
    `where('id', '>', $cursor)` (`ManualRenderController:122` に実在) は候補にしない。
  - 対応構文を明文化: qualified id (`users.id` / `getQualifiedKeyName()`)、`whereId(`、
    array where (`where(['id' => …])`)、`Model::destroy(`、`DB::connection()->table(`。
  - **非対応**構文 (`whereIntegerInRaw`、`whereRaw`、動的列名) は限界として明記し fixture に negative で残す。

## [Warning] 名称と検出内容のずれ

- 判断: **一部対応する**
- 根拠: テストクラス名 `ModelDirectFetchInvariantTest` は c2c 台帳上の gate 識別子であり変えない
  (他リポジトリとの対応が切れる)。
- 対応内容: **内部概念名**を `PrimaryKeyConstrainedStaticQuery` に統一し、
  走査器を `PrimaryKeyStaticQueryScanner` と命名する。テスト名との関係を docblock に書く。

## [Warning] `PayloadIdVerifiedInLockedServiceTransaction` は lockForUpdate だけでは tenant 検証を証明しない

- 判断: **対応する**
- 根拠: 完全に正しい。ロックは競合制御であって所属検証ではない。
- 対応内容: 根拠文の `Class::method` から**当該メソッド本文を切り出し**、その本文内に
  `lockForUpdate` **と** membership/tenant marker の**両方**があることを条件にする
  (クラスファイル全体ではなくメソッド本文)。加えて呼び出し側が exact method を呼んでいることも確認する。

## [Warning] `AuthenticatedActorScope` に機械条件なしは広すぎる

- 判断: **対応する**
- 対応内容: 部分条件を置く。(a) **同一メソッド内に request accessor が存在しないこと** (negative check)、
  (b) inventory に構造化 field `actorSource` (`authenticated_user` / `validated_token_claim` /
  `passport_token_record` / `queue_payload`) を必須にする。散文だけに依存しない。

## [Warning] 完了条件を「代表的な抜け道 fixture が fail する」まで含める

- 判断: **対応する**
- 対応内容: §8 の検証表を、inventory green だけでなく**抜け道 fixture 7 種が fail すること**を
  必須項目として書き直す (builder alias / where('id') / Service 委譲 / qualified id /
  array where / destroy / DB::table)。

## [Warning] 「不変条件 3 は機械強制済み」と言うには早い

- 判断: **対応する (主張を弱める)**
- 対応内容: 「不変条件 3 を全面的に機械強制する」とは書かない。
  本 gate が保証するのは「**主キー同一性による静的起点の取得**」という具体的経路に限る、と明記する。
  relation/org-scoped 解決の一般的強制は本 gate の主張範囲外であると §4-5 (限界) に書く。

## [Warning] `末尾が id/_id` の key パターンが曖昧 (`valid` に当たる 等)

- 判断: **対応不要となった**
- 根拠: 検出 B の廃止に伴い request accessor / id key grammar の定義自体が不要になった。
  (指摘自体は正しいので、B を将来復活させる場合はこの指摘を参照する旨を残す。)


---

## 改訂後の概念設計 (全文)

# 概念設計: ModelDirectFetchInvariantTest (直 find 禁止 gate) の追従

- c2c feature id: `nested-route-idor-defense`
- 裁定: 【2026-08-04 AG-005】正典 t1 = aicue の total inventory + cross-org guard + **t0 の ModelDirectFetchInvariantTest**。
  aicue は total inventory 部を正典 origin へ昇格させた側だが、**t1 に含まれる ModelDirectFetchInvariantTest が不在のため追従要**。
- 本設計のスコープ: 「aicue でこの gate をどう実装するか」だけ。裁定そのものは与件として蒸し返さない。
- 改訂履歴: Round 1 で検出アンカーを述語側へ移動。**Round 2 で母集団の絞り方を根本的に作り直した** (§4-1)。

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
| **分類が必要な候補** | **33** |

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

### 2-4. 候補 33 件の内訳 (全件読んだ結果)

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

- `User::…` / `self::…` / `static::…` — ただし**そのクラス名がファイルの `use` import 経由で
  `App\Models\*` に解決できる場合に限る**
- `DB::table('users')->…` / `DB::connection(…)->table(…)->…`

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
- **非対応 (限界として明記)**: `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`)。
  negative fixture として残し、「検出しない」ことをテストで固定する (§4-5)。

**(c) 識別子引数が解決済みモデル由来でない**

`$model->getKey()` / `$model->id` / `$model->{fk}_id` の形なら候補から外す (§2-3 の 37 件)。
`$request->…` は除外対象に含めない (request は解決済みモデルではない)。

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
| `OwnerScopedQueryConstraint` | **同一クエリ内**に所有者/テナント制約があり、取得後に弾いていない | 同一 chain に**許可 signature が列挙一致**すること: `where('organization_id'\|'user_id'\|'team_id'\|'project_id', …)` / `whereHas('users'\|'organizations'\|'projects', …)` / `whereBelongsTo($user\|$organization)`。`where('active', true)` 等では通らない |
| `IdDerivedFromTenantScopedQuery` | 識別子が**同一メソッド内のテナントスコープ済みクエリ**で確定している | 同一メソッド本文で当該変数への代入式が relation 起点 (`$x->rel()->…`) であること |
| `AuthenticatedActorScope` | id が認証済み actor / 検証済み token claim / queue payload 由来 | (a) 同一メソッド内に request accessor が**存在しない** (negative check)、(b) inventory に構造化 field `actorSource` (`authenticated_user` / `validated_token_claim` / `passport_token_record` / `queue_payload`) を必須 |
| `LocalOnlyDiagnostics` | route 登録自体が local 限定で production から到達不能 | 同一ファイルに `LocalOnly` / `isLocal` がある |
| `OperatorInvokedConsoleCommand` | 人間の運用者が CLI で明示実行する。HTTP から到達不能 | ファイルが `app/Console/Commands/` 配下 |
| **`PayloadIdWithGlobalExistenceRuleDebt`** | **payload 由来 id を global に引いており、補償チェックは fetch の後段にある = 準拠形ではなく債務** | 根拠文に (i) 補償チェックの位置、(ii) 掛けている validation rule、(iii) 後続 TODO の起票方針を書くこと。**同一の識別子変数が検証呼び出しに渡ること**を確認 |

> **`PayloadIdWithGlobalExistenceRuleDebt` を準拠 case と分けた理由**: §2-4 の 2 件は
> 「fetch **後**に補償する」形であり、「fetch **時点で**スコープが閉じている」他 case と
> 安全性の質が違う。同列に並べると「補償チェックがあれば OK」という運用に流れる。
> **debt であることを case 名で可視化**し、後続 TODO (§7-1) の入口にする。

> **`AuthenticatedActorScope` は完全な機械証明ができない**。「id の出所が認証済み actor か」は
> データフロー解析であり token 走査の範囲外である。negative check と構造化 field で
> 濫用を抑えるが、**最終的には人手の根拠文に依存する**と明示的に記録する
> (限界を曖昧にしないことが deny-by-default 運用の前提)。

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
3. **`NestedRouteIdorDefenseTest` / `NestedRouteDefenseInventory` への変更**
   — 正典 t1 の total inventory 部は aicue が origin 側であり既に要件を満たしている。触らない。
4. **cross-org 存在オラクル封じ middleware (aicue:D4 / `EnsureProjectBelongsToCurrentOrganization`)**
   — t1 の構成要素だが aicue には既に実装済み (`ProjectRouteCurrentOrgGuardTest` が固定)。追従不要。
5. **`app/Filament/**` の Filament リソース** — 母集団には入るが、admin パネルは
   `/admin` 配下で別の認可体系。候補が出たら通常どおり分類する (特別扱いしない = 除外もしない)。
6. **c2c 台帳への `status_reported` 書き戻し** — 実装が main にマージされ commit が push された後の作業。
7. **frontend の変更** — 一切無い。Svelte / DS token / Inertia props に波及しない。

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

加えて **negative fixture** (検出してはならないもの) も固定する:
`$organization->users()->whereKey($id)` (relation 起点) /
`Project::whereKey($project->id)->lockForUpdate()` (provenance フィルタ) /
`$manual->renderJobs()->where('id', '>', $cursor)` (順序比較) /
`Plan::query()->where('code', $code)` (主キーでない) /
**docblock 中の `Foo::destroy()`** (コメント除去)。

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
本 gate は新機能を足さないが、**「今後どんな機能を足しても cross-org 直 fetch が入り込まない」**という
土台を機械化し、使命の前提を守り続けるコストを人間のレビューから CI へ移す。

