# Round 2: Round 1 指摘への対応

Round 1 の指摘を全て捌きました。対応マトリクスと改訂後の概念設計全文を送ります。
特に [Critical] の検出規則については、指摘どおり `where('id', ...)` で抜けられることを認め、
**アンカーを終端メソッドから「主キー同一性述語」へ移す**ことで解決しました。
また Service へ id を渡す抜け道は、母集団を広げるのではなく
**検出 B (source 側 = id が entrypoint に入る瞬間) を追加**して塞いでいます。

再レビューをお願いします。特に次を見てください:
1. 述語アンカー方式に、まだ残っている抜け道はあるか (token 走査で実装可能な範囲で)
2. 検出 B (source 側) の候補定義 (末尾が id/_id の key で request から scalar を読む) に
   取りこぼしはあるか
3. case ごとの機械副条件が、実装可能かつ濫用抑止として妥当か
4. `AuthenticatedActorScope` だけ機械副条件を持てないことを明示した扱いで妥当か

---

# 対応マトリクス: conceptual-review Round 1

## [Critical] 検出規則が狭すぎる (`where('id', …)` で抜ける)

- 判断: **対応する**
- 根拠: 指摘のとおり。`User::query()->where('id', $request->input('user_id'))->firstOrFail()` は
  「payload 由来 id を tenant scope 外でモデル化する」ものそのものであり、旧案の
  「key **終端**」規則では 1 件も検出しない。§1 の成功条件を自分で満たしていなかった。
- 対応内容: 検出のアンカーを**終端メソッドから主キー同一性述語 (PK-identity predicate) へ移す**。
  - 述語: `find` / `findOrFail` / `findOrNew` / `findMany` / `whereKey` / `whereKeyNot` /
    `where('id', …)` / `whereIn('id', …)` / `firstWhere('id', …)` / `where($m->getKeyName(), …)`
  - これにより終端 (`first` / `sole` / `get` / `exists` / `delete` / `update`) を列挙する必要が
    無くなり、規則が**単純化しつつ広がる** (終端の網羅漏れという失敗モードが構造的に消える)。
  - 実測し直した結果、entrypoint 層の検出数は 12 → **13** (+1)。運用コストはほぼ変わらない。

## [Warning] `DB::table()` 経由の抜け道 (Round 1 では Codex 未指摘。自己検出)

- 判断: **対応する**
- 根拠: 述語アンカー化にあたり実測したところ `ResolveApiActor.php:146` に
  `DB::table('oauth_access_tokens')->where('id', $tokenId)` があった。root が静的である以上
  `Model::` と同じ抜け道になる (`DB::table('users')->where('id', $payloadId)` が素通りする)。
- 対応内容: 静的 root に `DB::table(…)` を含める。該当は 1 件のみで `AuthenticatedActorScope` に分類。

## [Warning] #1 (`OrganizationOwnershipController`) の扱いが「初期 green」と矛盾

- 判断: **対応する** (指摘どおり論理矛盾していた)
- 根拠: 「どの case にも当てはまらない」と書きながら「初期 inventory 全件 green」を成功条件に
  置いており、両立しない。
- 対応内容: case `PayloadIdVerifiedInLockedServiceTransaction` を**新設**する。ただし
  `PayloadIdWithCompensatingCheck` を広げる形は採らない (それは case を歪める)。新 case の
  適用条件は「検証が**行ロック下の named Service メソッド**で行われる」と狭く定義し、
  根拠文に `Class::method` を書かせ、その**クラスファイルが実在し `lockForUpdate` を含むこと**を
  機械検証する。case を増やして逃がすのではなく、**より強い条件を機械で確認する**方向で解く。

## [Warning] entrypoint 限定だと Service に id を渡す経路が抜ける

- 判断: **対応する** (設計を 1 段強くする)
- 根拠: 指摘は正しい。Controller が scalar id を Service に渡し Service 側で global fetch すると
  検出 A (sink 側) は沈黙する。「将来再検討」で流すのは gate として弱い。
- 対応内容: **検出 B (source 側) を追加**する。「entrypoint 層で request 由来の resource id scalar を
  読む箇所」を deny-by-default で inventory 登録させる。実測すると母集団は**わずか 5 件**で、
  追加コストが極小のわりに「id が entrypoint に入った瞬間」を押さえるため、
  fetch がどの層で起きても取り逃がさない。sink だけでなく source を押さえるほうが本質的だった。

## [Warning] `exists:users,id` の存在オラクルが残る

- 判断: **一部対応する** (gate の責務は広げない / 可視化はする)
- 根拠: 本 gate は「モデル取得の経路」を守るものであり、validation rule の存在漏れは別の攻撃面。
  ただし指摘どおり #1 #2 と**同じ 2 箇所**に集中しているため、切り離すと片手落ちに見える。
- 対応内容: 検出 B の根拠文に「その id に掛けている validation rule」を書かせる。これにより
  `exists:users,id` が inventory 上に**必ず現れる**。ルール自体の是正は後続 TODO のまま
  (振る舞い変更を伴うため。§7-1)。

## [Warning] 根拠文 30 文字だけでは case が形骸化する

- 判断: **対応する**
- 根拠: 妥当。`ControllerAuthorizationExemption` も文字数だけで守られているわけではない。
- 対応内容: case ごとに**機械副条件**を課す (完全解析はしない。安価に効く分だけ):
  | case | 機械副条件 |
  |---|---|
  | `OwnerScopedQueryConstraint` | 同一 chain 内に identity 述語**以外の** `where(` / `whereHas(` / `whereBelongsTo(` がある |
  | `PayloadIdWithCompensatingCheck` | 同一メソッド本体に既知 marker (`organizationRole(` / `organizations()` / `users()` / `whereHas(`) がある |
  | `PayloadIdVerifiedInLockedServiceTransaction` | 根拠文が `Class::method` を含み、そのクラスファイルが実在し `lockForUpdate` を含む |
  | `LocalOnlyDiagnostics` | 同一ファイルに `LocalOnly` / `isLocal` がある |
  | `AuthenticatedActorScope` | 機械条件なし (id の出所が actor であることは静的に決められない) — **この case のみ人手根拠に依存**する旨を明記 |

## [Suggestion] 使命との整合

- 判断: 対応不要 (肯定的評価)

## [Warning] `app/Http/Requests/**` を母集団に入れるなら validation も

- 判断: **反論する (母集団には残す)**
- 根拠: `app/Http/Requests/**` に fetch は 1 件も無い (実測 0 件) が、母集団から外すと
  「FormRequest に fetch を書けば通る」という抜け道になる。**0 件のまま母集団に置く**のが正しい
  (deny-by-default の空 inventory は最も安いガード)。validation rule 自体は上記のとおり検出 B で可視化する。


---

## 改訂後の概念設計 (全文)

# 概念設計: ModelDirectFetchInvariantTest (直 find 禁止 gate) の追従

- c2c feature id: `nested-route-idor-defense`
- 裁定: 【2026-08-04 AG-005】正典 t1 = aicue の total inventory + cross-org guard + **t0 の ModelDirectFetchInvariantTest**。
  aicue は total inventory 部を正典 origin へ昇格させた側だが、**t1 に含まれる ModelDirectFetchInvariantTest が不在のため追従要**。
- 本設計のスコープ: 「aicue でこの gate をどう実装するか」だけ。裁定そのものは与件として蒸し返さない。

---

## 1. 仮説

**仮説**: この gate は `NestedRouteIdorDefenseTest` の重複ではない。両者が守る母集団は**素で交わらない**。

| gate | id の出所 | 現状 |
|---|---|---|
| `NestedRouteIdorDefenseTest` | **route parameter** (`/projects/{project}/manuals/{manual}`) | 実装済み (1+param の total inventory) |
| `ModelDirectFetchInvariantTest` | **route parameter 以外**の untrusted 入力 (POST payload / query string / MCP tool 引数 / token claim) | **不在** |

`NestedRouteDefenseInventory::candidates()` の母集団は `parameterNames() !== []` の named route である。
`POST /organizations/{organization}/transfer-ownership` の `user_id` のように **body で id を受け取る**経路は、
route parameter を 1 つも増やさないため **inventory に何も現れない**。したがって
「payload の id をテナントに閉じない global クエリでモデル化する」経路は、現状**どの Architecture テストにも捕捉されない**。

**検証したいこと**: aicue に実際にその経路が存在するか。存在するなら gate は純増の防御であり、
存在しないなら「将来の混入を deny-by-default で止める」予防 gate として意味を持つか。

**成功条件**: 新しく「request 由来の id を tenant/owner スコープ外のクエリでモデル化するコード」を書いたとき、
**レビューを通り抜けても CI が落ちる**こと。かつ、既存の正当な経路を分類するコストが実装者にとって現実的であること。

なお成功条件は「**書き方を問わず**落ちる」でなければ意味がない。`Model::find()` だけを禁じても
`Model::query()->where('id', $payloadId)->firstOrFail()` で等価なことができてしまうため、
検出規則はメソッド名の列挙ではなく**主キー同一性という意味**に対して張る (§4-2)。

---

## 2. 現状 (実査結果)

ブリーフ・台帳の記述を鵜呑みにせず、`rg` で実コードを数えた結果。

### 2-1. gate の不在は事実

```
tests/Architecture/ModelDirectFetchInvariantTest.php  → 存在しない
```

`rg 'ModelDirectFetch' .` のヒットは **devnotes と過去監査メモの 4 件のみ**で、実装・テスト・docs には無い。

### 2-2. 過去に「入れない」と判断した記録がある (重要)

`devnotes/20260802-1548-aigenba-alignment-audit/audit.md` L163-166:

> **注意**: `ModelDirectFetchInvariantTest` / `WebGuardLoginPathInvariantTest` /
> `WebhookAsyncDispatchInvariantTest` / `PolicyResolutionInvariantTest` は**思想は汎用だが
> inventory がドメイン固有**。AI-CUE には既に等価物がある (`NestedRouteIdorDefenseTest` /
> `ManageRouteAuthGuardTest` / `BillingSyncDispatchInvariantTest`)。**重複導入しない**。

**この判断は §1 の実査で否定される**。`NestedRouteIdorDefenseTest` は route parameter しか見ておらず、
「payload 由来 id の global fetch」を 1 件も検査していない (§2-4 で実物を示す)。
2026-08-04 の c2c 裁定はこの局所判断を上書きしており、本設計は**裁定側に従う**。
ただし「aigenba 版をそのまま持ってくる」形は §2-3 の実測により採らない。

### 2-3. 「app/ 全体で直 fetch 禁止」は aicue では成立しない (実測)

| 母集団 | 件数 |
|---|---|
| `app/` 全体の fetch 系呼び出し (`find*` / `whereKey` / `findOrFail` …) | **131** |
| うち chain root が静的クラス参照 (`Model::` / `self::`) | **170** (`::query()` を含む) |
| **`app/Services/**` + `app/Jobs/**` の static-rooted 主キー同一性クエリ** | **103** |
| **`app/Http/**` + `app/Mcp/**` の static-rooted 主キー同一性クエリ** | **13** |

Services / Jobs 側 103 件の実体は圧倒的に次の 2 パターンで、**本 gate が守りたい不変条件とは別物**である:

1. `Project::whereKey($project->id)->lockForUpdate()->firstOrFail()` —
   **既にテナント検証済みのモデル**を同一 tx 内で行ロック再取得する形
   (`VideoManualService` 11 / `CategoryService` 7 / `RenderJobService` 9 …)。
   id の出所は request ではなく解決済みモデルであり、こちらは
   **`ScenarioWritePathInventoryTest` / `MembershipWriteLockInventoryTest` という別の既存 gate**が既に統制している。
2. `AnalysisJob::query()->findOrFail($this->analysisJobId)` — **queue payload からの再水和**。
   id の出所は enqueue 時にサーバが確定した値であって untrusted 入力ではない。

つまり app/ 全体を母集団にすると、**分類 100 件超のうち 9 割が「本 gate の関心外」**という
形骸化した inventory になる。AGENTS.md 思考原則 2 (今必要なものだけ作る) に真っ向から反する。

### 2-4. 実際の穴は「entrypoint 層の 13 件」に閉じている

`app/Http/**` + `app/Mcp/**` の static-rooted 主キー同一性クエリを全件読んだ結果:

| # | 箇所 | id の出所 | 現状の防御 |
|---|---|---|---|
| 1 | `Http/Controllers/Organizations/OrganizationOwnershipController.php:35` | **request payload `user_id`** | `User::query()->findOrFail()` = **テナント無関係の global fetch**。membership 検証は後段 `transferOwnership()` のロック下 |
| 2 | `Http/Controllers/Projects/ProjectMemberController.php:50` | **request payload `user_id`** | `User::query()->findOrFail()` = **global fetch**。直後に `organizationRole() === null → 403` |
| 3 | `Http/Routing/SelfScopedPasskeyBinder.php:53` | route param | `Passkey::query()->whereKey($id)->where('user_id', $user->getKey())` = **同一クエリ内で所有者スコープ**(意図的設計) |
| 4 | `Http/Routing/MembershipScopedOrganizationBinder.php:92` | route param | `Organization::query()->where(...)->whereHas('users', …whereKey($user->id))` = **同一クエリ内で membership スコープ**(意図的設計) |
| 5 | `Http/Middleware/EnsureLoginMethodRemains.php:67` | 認証済み自分自身 | `whereKey($user->getKey())->lockForUpdate()` = 解決済みモデルのロック再取得 |
| 6 | `Http/Controllers/Organizations/OrganizationMemberController.php:89` | binding 済み `{user}` | ロック再取得 + L93 で membership 再検証 |
| 7 | `Http/Controllers/Organizations/OrganizationController.php:149` | binding 済み `{organization}` | ロック再取得 |
| 8 | `Http/Middleware/ResolveApiActor.php:156` | DB 上の token 行 | actor 解決の内部 |
| 9 | `Http/Middleware/ResolveApiActor.php:168` | token claim の org id | actor 解決の内部 |
| 10 | `Http/Middleware/McpConsentOrganizationBinder.php:59` | request の org id | 直後 L65 で `$user->organizations()->whereKey()->exists()` 検証 |
| 11 | `Http/Controllers/Api/V1/Me/RevokeSessionController.php:45` | **解決済み actor の** session id | self scope |
| 12 | `Http/Controllers/DebugLoginController.php:52` | request `userId` | route 登録自体が local 限定 + `LocalOnly` middleware |
| 13 | `Http/Middleware/ResolveApiActor.php:146` | Passport の access token id | `DB::table('oauth_access_tokens')->where('id', $tokenId)` = **Eloquent ではない静的起点**。#8 #9 の前段 |

MCP tool (`ShowProjectTool` / `ListItemsTool`) は `$ctx->organization->projects()->whereKey($projectId)` と
**relation 起点**で書かれており、この層は既に正しい。

### 2-4-b. request 由来 resource id の**入口**も 5 件しかない

sink (fetch) だけでなく source (id が entrypoint に入る瞬間) も数えた:

| # | 箇所 | 受け取る id | その後 |
|---|---|---|---|
| S1 | `Mcp/Tools/ListItemsTool.php:39` | `project_id` | `$ctx->organization->projects()->whereKey()` = relation 解決 (準拠) |
| S2 | `Mcp/Tools/ShowProjectTool.php:37` | `project_id` | 同上 (準拠) |
| S3 | `Http/Middleware/McpConsentOrganizationBinder.php:28` | `organization_id` | global fetch → 直後に membership 検証 |
| S4 | `Http/Controllers/Projects/ProjectMemberController.php:44` | `user_id` | validation `exists:users,id` → global fetch → 403 |
| S5 | `Http/Controllers/Organizations/OrganizationOwnershipController.php:31` | `user_id` | validation `exists:users,id` → global fetch → Service のロック下で検証 |

この母集団が小さいことは設計上重要である (§4-2-b)。

### 2-5. 規約は文章としては既に存在する (機械強制だけが無い)

`docs/app-integration-guide.md` §7 不変条件 3:

> **cross-org 不可**: いかなる経路でも組織を跨いだ read/write が起きない
> (Service 層 + DB CHECK の多層。**直 fetch せず relation/Builder スコープ経由**)

AGENTS.md セキュリティ不変条件 3 も同文。**「直 fetch せず」は既に宣言済みの規約でありながら、
対応する Architecture テストが無い唯一の不変条件**である
(不変条件 1/2/5/8/9 はすべて対応 gate を持つ)。AGENTS.md 禁止事項 1
「不変条件は対応する Architecture/Feature テストへの登録まで含めて実装済み」に照らすと、
不変条件 3 は**現時点で未完了**という整理になる。

---

## 3. 課題

1. **untrusted id → global モデル化**の経路が機械検出されない。今は 2 件だけだが、
   その 2 件が「安全である理由」がコードコメントにしか無く、レビュアーの注意力に依存している。
2. 新しい payload id 受け口 (例: 「他組織のユーザーを検索して招待する」機能) を後から足したとき、
   **relation 起点で書かなかったことに誰も気付けない**。route を増やさないため
   `NestedRouteIdorDefenseTest` も `TenantBoundaryOrderingTest` も沈黙する。
3. 逆に、`SelfScopedPasskeyBinder` / `MembershipScopedOrganizationBinder` のように
   **static 起点だが同一クエリでスコープを閉じている正しい実装**が存在するため、
   「`Model::` 起点を一律禁止」という素朴な規則は使えない。分類が要る。

---

## 4. 方針

**deny-by-default の inventory 型 Architecture テストを 1 本追加する。母集団は entrypoint 層に限定する。**

本リポジトリに既にある同型の gate (`ControllerAuthorizationGateTest` + `ControllerAuthorizationExemption` enum、
`ScenarioWritePathInventoryTest` の token 走査) の作法をそのまま踏襲する。

### 4-1. 母集団 (population)

```
app/Http/Controllers/**    app/Http/Middleware/**
app/Http/Routing/**        app/Http/Concerns/**
app/Http/Requests/**       app/Mcp/Tools/**
```

= **untrusted な外部入力が最初にモデルへ変換される層**。
Services / Jobs / Models / Console / Passport / Filament は母集団に入れない (§2-3 の実測が根拠。§6 に明記)。

### 4-2. 検出 A (sink): static 起点の主キー同一性クエリ

「**method chain の根が静的で、その chain が主キー同一性述語を含むもの**」を候補とする。

- **主キー同一性述語**:
  `find(` / `findOrFail(` / `findOrNew(` / `findMany(` / `whereKey(` / `whereKeyNot(` /
  `where('id', …)` / `whereIn('id', …)` / `firstWhere('id', …)` / `where($x->getKeyName(), …)`
- **静的な根**: `User::findOrFail(…)` / `Organization::query()->…` / `self::query()->…` /
  **`DB::table('users')->…`**
- **根が変数/プロパティのもの** (`$organization->users()->whereKey(…)`、
  `$ctx->organization->projects()->whereKey(…)`、`$manual->renderJobs()->where('id', '>', …)`) は
  **relation 起点 = 準拠形**として検出しない

> **アンカーを「終端メソッド」ではなく「述語」に置いた理由** (Round 1 Critical 対応)。
> 終端 (`first` / `firstOrFail` / `sole` / `get` / `exists` / `delete` / `update`) を列挙する方式は
> 列挙漏れがそのまま**サイレントな抜け道**になる。`Model::query()->where('id', $payloadId)->firstOrFail()` は
> 旧案では 1 件も検出されなかった。述語側に張れば終端を一切列挙しなくてよく、規則が**単純化しつつ広がる**。
> `DB::table()` を含めるのも同じ理由 — 根が静的である限り Eloquent かどうかは攻撃者に関係がない。

`->where('code', $planCode)` (`Plan` カタログ) や `->where('api_key_id', …)` (`IdempotencyKey`) のような
**主キー同一性でない絞り込み**は、リソース所有権の話ではないので候補にしない。

### 4-2-b. 検出 B (source): request 由来 resource id の入口

検出 A は「fetch が entrypoint 層で起きる」ことを前提にしている。Controller が scalar id を
Service に渡し、**Service 側で global fetch する**と検出 A は沈黙する。
これは母集団を entrypoint 層に絞ったことの直接の代償であり、「将来再検討」で流してはならない。

そこで **id が entrypoint に入る瞬間**を押さえる:

- 候補: entrypoint 層における `$request->input('…id')` / `->integer('…id')` / `->string('…id')` /
  `validated()['…id']` / MCP tool の `requireIntParam($request, '…id')` など、
  **末尾が `id` / `_id` の key で request から scalar を読む箇所**
- 実測母集団は **5 件のみ** (§2-4-b)。全件に「**その id をどの relation / Service 経由で解決するか**」と
  「**掛けている validation rule**」を根拠文として書かせる

sink がどの層に移動しても source は entrypoint に残るため、この 2 本立てで
「entrypoint 限定」の抜け道が塞がる。母集団 5 件なので追加コストは実質ゼロである。

`exists:users,id` のような**グローバルな存在検証ルール**は検出 B の根拠文に必ず現れる形になり、
「fetch は直したが validation で存在が漏れている」状態が inventory 上で可視化される
(ルール自体の是正は振る舞い変更を伴うため §7-1 の後続 TODO)。

### 4-3. 分類 (deny-by-default)

検出 A / B の全候補は `App\Enums\Security\DirectFetchJustification` の case と
**30 文字以上の具体的根拠**を対で登録しなければ fail する。未登録は fail。
逆に、登録があるのに実コードに無い (stale) 場合も fail する (双方向整合)。

case は §2-4 / §2-4-b の実物から**帰納**する (汎用に見える case ほど適用条件を狭く書く。
`ControllerAuthorizationExemption` の作法)。

**根拠文の文字数だけでは case は守れない** ため、case ごとに**安価な機械副条件**を課す。
完全な意味解析はしない (走査器の限界は §4-4 で明示する) が、
「後段で確認済み」のような雑な根拠文が素通りしないだけの強度を持たせる:

| case | 適用条件 | 機械副条件 | 該当 |
|---|---|---|---|
| `OwnerScopedQueryConstraint` | **同一クエリ内**に所有者/テナント制約を持ち、取得後に弾いていない | 同一 chain に identity 述語**以外の** `where(` / `whereHas(` / `whereBelongsTo(` がある | #3 #4 |
| `LockedRefetchOfVerifiedModel` | id が**既にテナント検証済みのモデル**由来で、行ロック目的の再取得 | 同一 chain に `lockForUpdate(` / `sharedLock(` がある | #5 #6 #7 |
| `AuthenticatedActorScope` | id が認証済み actor / 検証済み token claim 由来で request payload 由来でない | **なし** (§下記の注記) | #8 #9 #11 #13 |
| `PayloadIdWithCompensatingCheck` | payload 由来 id だが、**同一メソッド内**に組織所属検証があり不整合を拒否する | 同一メソッド本体に既知 marker (`organizationRole(` / `organizations()` / `users()` / `whereHas(`) がある | #2 #10 |
| `PayloadIdVerifiedInLockedServiceTransaction` | payload 由来 id で、検証が**行ロック下の named Service メソッド**にある | 根拠文が `Class::method` 形式を含み、その**クラスファイルが実在**し `lockForUpdate` を含む | #1 |
| `LocalOnlyDiagnostics` | route 登録自体が local 限定で production から到達不能 | 同一ファイルに `LocalOnly` / `isLocal` がある | #12 |
| `ResolvedThroughTenantRelation` | (検出 B 専用) 読み取った id を relation 起点でのみ解決する | 同一メソッド本体に `->whereKey(` を伴う relation 呼び出しがある | S1 S2 |

> **`AuthenticatedActorScope` だけは機械副条件を持てない**。「id の出所が認証済み actor か」は
> データフロー解析であり token 走査の範囲外である。この case のみ人手の根拠文に依存する、と
> 明示的に記録する (限界を曖昧にしないことが deny-by-default 運用の前提)。
> 濫用を抑えるため、根拠文に**その actor をどの middleware / claim が確定したか**を書かせる。

**#1 (`OrganizationOwnershipController`) の扱い** (Round 1 Warning 対応):
検証が別クラス (`OrganizationMembershipService::transferOwnership` の `lockForUpdate` 下) にあるため
`PayloadIdWithCompensatingCheck` の「同一メソッド内」を満たさない。
ここで既存 case を広げると case が歪む (思考原則 4)。かわりに
`PayloadIdVerifiedInLockedServiceTransaction` を**より狭い条件で新設**し、
「検証クラスを名指しさせ、そのクラスが実際にロックを取っていることを機械で確認する」形にした。
case を増やして逃がすのではなく、**逃げるために強い証拠を要求する**設計である。

### 4-4. 走査器は独立させ、走査器自体をテストする

`AuthorizationMarkerScanner` と同じ思想。正規表現ではなく `token_get_all` の状態機械にし、
コメント / 文字列リテラル中の出現を除去する。走査器の positive/negative は
`tests/Unit/Architecture/` の専用テストで恒久固定する
(gate 自体がセキュリティ機構であり、走査器が壊れたら gate は静かに無力化するため)。

---

## 5. 代替案と却下理由

| # | 案 | 却下理由 |
|---|---|---|
| A | **aigenba/template 版をそのまま移植** (app/ 全体で `::find` 禁止 + allowlist) | §2-3 の実測。分類 100 件超のうち 9 割が「解決済みモデルのロック再取得」「queue payload の再水和」で、本 gate の関心外。しかもそれらは `ScenarioWritePathInventoryTest` / `MembershipWriteLockInventoryTest` が既に統制済み = **二重統制**。思考原則 2 違反 |
| B | **gate を入れず、#1 #2 を relation 起点にリファクタして終わり** | 「今あるものを直す」だけで**将来の混入を止めない**。裁定の要求 (gate の追従) を満たさない。加えて #2 は payload 由来ゆえ 403 が正しい仕様であり、404 に倒す relation 起点化は仕様変更になる |
| C | **PHPStan のカスタムルールで実装** | 本リポジトリにカスタムルール基盤が無く、extension 登録・`phpstan.neon` 拡張が必要。既存の不変条件はすべて Architecture テスト側に集約されており (`tests/Architecture/` 60 本超)、置き場所を割るとレビュー時に発見されない |
| D | **nikic/php-parser で AST 解析** | 直接依存ではなく推移依存 (composer.lock にのみ存在)。既存の走査器 (`ScenarioWritePathScanner` / `PrismDirectDispatchScanner` / `AuthorizationMarkerScanner`) は全て `token_get_all` 流儀で、ここだけ流儀を割る利得が無い |
| E | **route parameter も本 gate の母集団に含めて一本化** | `NestedRouteIdorDefenseTest` と母集団が重なり、同じ経路を 2 か所に登録させることになる。思考原則 4 (別物の概念を似ているからで統合しない)。route param 側は既に total inventory 済み |
| F | **`Model::` 起点を一律禁止 (分類なし)** | #3 #4 の binder は **static 起点であることが正しい設計**(relation は vendor 型で解決されるため `App\Models\Passkey` 型を返せない、という明示コメントがある)。一律禁止は正しい実装を壊す |

---

## 6. スコープに入れないもの (と理由)

1. **Services / Jobs / Models / Console / Passport / Filament 層の直 fetch (103 件)**
   — §2-3 の実測どおり id の出所が untrusted 入力でない。別の既存 gate
   (`ScenarioWritePathInventoryTest` / `MembershipWriteLockInventoryTest`) が統制済みの領域と重なる。
   **「Service に scalar id を渡して Service 側で global fetch する」抜け道は
   検出 B (§4-2-b) が source 側で塞ぐ**ため、母集団を広げずに閉じられる。
   なお `app/Http/Requests/**` は現状 fetch 0 件だが**母集団には残す**
   (外すと「FormRequest に書けば通る」抜け道になる。空の deny-by-default は最も安いガード)。
2. **`OrganizationOwnershipController` / `ProjectMemberController` の実装リファクタ**
   — 本タスクは「機械検出を入れる」ことが目的。#1 の扱いは §7-1 の後続 TODO 候補とし、
   本 gate では**根拠付きで可視化する**ところまでで止める (振る舞いを変えない)。
   なお `exists:users,id` バリデーションが既に global なユーザー存在を漏らしており、
   fetch 側だけ直しても閉じない = 単独で切り出すべき別課題である。
3. **`NestedRouteIdorDefenseTest` / `NestedRouteDefenseInventory` への変更**
   — 正典 t1 の total inventory 部は aicue が origin 側であり、既に要件を満たしている。触らない。
4. **cross-org 存在オラクル封じ middleware (aicue:D4 / `EnsureProjectBelongsToRouteOrganization`)**
   — t1 の構成要素だが aicue には既に実装済み (`ProjectRouteCurrentOrgGuardTest` が固定)。追従不要。
5. **c2c 台帳への `status_reported` 書き戻し**
   — 実装が main にマージされ commit が push された後の作業。設計フェーズでは行わない。
6. **frontend の変更** — 一切無い。Svelte / DS token / Inertia props に波及しない。

---

## 7. 後続 TODO 候補 (本タスクでは実施しない)

1. **`OrganizationOwnershipController` / `ProjectMemberController` の移譲先・追加先解決を org 相対にする**
   — `User::query()->findOrFail($userId)` を `$organization->users()->whereKey($userId)->firstOrFail()` に
   寄せられるか。**`exists:users,id` (グローバルなユーザー存在検証) の見直しとセットでないと
   存在オラクルは閉じない** — fetch 側だけ直しても validation が同じ情報を漏らす。
   振る舞い変更 (403 → 404、あるいは 422 の文言変化) を伴うため本 gate とは別 TODO として起票する。
   本 gate 導入後は当該 2 箇所が inventory に根拠付きで載るので、TODO 化の材料は揃った状態になる。
2. **母集団の Services 層への拡張** — 検出 B が source 側を押さえるため当面は不要。
   Service が request 以外の untrusted 入力 (webhook payload 等) から id を得る設計が現れたら再検討する。
3. **template / 他リポジトリへの還流** — 「entrypoint 層に絞った母集団定義」は
   aigenba の app/ 全体版より運用コストが低い可能性がある。c2c 側の議題としてキュレーターに委ねる。

---

## 8. 検証方法

| 段階 | コマンド | 期待 |
|---|---|---|
| 走査器の単体 | `composer test -- --filter=DirectFetchScanner` | positive/negative fixture が全 green |
| gate 本体 | `composer test -- --filter=ModelDirectFetchInvariant` | 初期 inventory (検出 A 13 件 / 検出 B 5 件) で green |
| **fail 確認 (テストファースト)** | inventory から 1 件削って再実行 | **fail する**こと (deny-by-default が効いている証明) |
| **stale 確認** | 実在しない箇所を inventory に足して再実行 | **fail する**こと (双方向整合) |
| **抜け道確認 (Round 1 Critical)** | `Model::query()->where('id', $payloadId)->firstOrFail()` を 1 行足す | **fail する**こと (述語アンカーが効いている証明) |
| **副条件確認** | `OwnerScopedQueryConstraint` を機械副条件を満たさない箇所に付ける | **fail する**こと |
| 型 | `composer phpstan` | level 10 green |
| 整形 | `vendor/bin/pint --test` | green |
| 全体 | `composer test` | green (既存テストへの影響なし。app/ のコードは 1 行も変えないため回帰面は無い) |

**期待効果の測り方**: 「payload 由来 id を relation 経由でなく引くコード」を 1 行足したパッチを
ローカルで作り、`composer test` が落ちることを確認する。落ちなければこの gate は目的を果たしていない。

---

## 9. 使命との整合

AI-CUE は SOP / 動画マニュアルという**組織の資産**を扱う。組織を跨いだ read/write は
「現場のノウハウが他社に漏れる」ことと同義であり、機能の魅力以前の前提条件である。
本 gate は新機能を足さないが、**「今後どんな機能を足しても cross-org 直 fetch が入り込まない」**という
土台を機械化するもので、使命の前提を守り続けるコストを人間のレビューから CI へ移す。

