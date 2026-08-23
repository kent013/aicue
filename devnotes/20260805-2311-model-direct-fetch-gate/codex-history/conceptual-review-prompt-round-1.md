## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上記に挿入済み）

【本件の前提 — 蒸し返し禁止】
- 本件は複数リポジトリ共有の機能台帳 c2c における 2026-08-04 のオーナー裁定済み案件である。
  「ModelDirectFetchInvariantTest 相当の gate を aicue に追従導入する」ことは確定した与件であり、
  導入するかどうかの是非は論点ではない。論点は「aicue でどう実装するか」だけ。
- 本タスクは設計のみ。アプリのコードは 1 行も変更しない。

【レビュー観点】
1. 使命との整合性
2. 禁止事項違反
3. 実現可能性: token_get_all ベースの静的走査でこの検出規則が実装できるか。誤検出/検出漏れの穴はどこか
4. 検出規則の妥当性: 「key 終端 fetch かつ chain root が静的クラス参照」でセキュリティ上の関心事を捉えられているか。
   この規則を回避できる書き方（gate の抜け道）が残っていないか
5. 母集団を entrypoint 層に絞る判断の妥当性。絞ったことで実際に守れなくなる経路が具体的に存在するか
6. スコープの適切さ（過大/過小）。特に「スコープに入れないもの」の理由が実測に裏付けられているか
7. リスク: 既存の正しい実装（binder 等）を壊さないか。運用が形骸化する経路はないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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
| **`app/Services/**` + `app/Jobs/**` の key 終端 static fetch** | **103** |
| **`app/Http/**` + `app/Mcp/**` の key 終端 static fetch** | **12** |

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

### 2-4. 実際の穴は「entrypoint 層の 12 件」に閉じている

`app/Http/**` + `app/Mcp/**` の key 終端 static fetch を全件読んだ結果:

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

さらに、**entrypoint 層で resource id を payload から受けている箇所は全部で 2 つしかない**
(`rg "input\('[a-z_]*id'"` が #1 と #2 のみを返す)。MCP tool (`ShowProjectTool` / `ListItemsTool`) は
`$ctx->organization->projects()->whereKey($projectId)` と **relation 起点**で書かれており、この層は既に正しい。

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

### 4-2. 検出規則

「**key 終端の fetch であって、method chain の根が静的クラス参照であるもの**」を候補とする。

- key 終端: `find(` / `findOrFail(` / `findOrNew(` / `findMany(` / `whereKey(`
- 根が静的: `User::findOrFail(...)` / `Organization::query()->find(...)` / `self::query()->whereKey(...)`
- 根が変数/プロパティのもの (`$organization->users()->whereKey(...)`、
  `$ctx->organization->projects()->whereKey(...)`) は **relation 起点 = 準拠形**として検出しない

この規則が「relation 経由のみ」という規約文言と 1:1 で対応する点が重要である。
`->where('code', $planCode)` のような **key でない絞り込み** (`Plan` カタログ、`IdempotencyKey`) は
そもそもリソース所有権の話ではないので候補にしない。

### 4-3. 分類 (deny-by-default)

検出された全候補は `App\Enums\Security\DirectFetchJustification` の case と
**30 文字以上の具体的根拠**を対で登録しなければ fail する。未登録は fail。
逆に、登録があるのに実コードに無い (stale) 場合も fail する (双方向整合)。

case は §2-4 の実物から**帰納**する (汎用に見える case ほど適用条件を狭く書く。
`ControllerAuthorizationExemption` の作法):

| case | 適用条件 |
|---|---|
| `OwnerScopedQueryConstraint` | **同一クエリ内**に所有者/テナント制約 (`where('user_id', …)` / `whereHas`) を持ち、取得後に弾いていない (#3 #4) |
| `LockedRefetchOfVerifiedModel` | id が**既にテナント検証済みのモデル**由来で、行ロック目的の再取得 (#5 #6 #7) |
| `AuthenticatedActorScope` | id が認証済み actor / 検証済み token claim 由来で、request payload 由来でない (#8 #9 #11) |
| `PayloadIdWithCompensatingCheck` | payload 由来 id だが、**同一メソッド内**に組織所属検証があり不整合を拒否する (#2 #10) |
| `LocalOnlyDiagnostics` | route 登録自体が local 限定で production から到達不能 (#12) |

**#1 (`OrganizationOwnershipController`) はどの case にも素直に当てはまらない** — 検証が別クラス
(`OrganizationMembershipService::transferOwnership` のロック下) にあり `PayloadIdWithCompensatingCheck` の
「同一メソッド内」を満たさない。これは gate が最初から**当てているべき所に当たっている**証拠であり、
案を歪めてまで case を広げない (§7-1 に扱いを書く)。

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
   将来 Service が request 由来 id を直接受ける設計が出てきたら母集団を広げる (§7-2)。
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

1. **`OrganizationOwnershipController` の移譲先解決を org 相対にする**
   — `User::query()->findOrFail($userId)` を `$organization->users()->whereKey($userId)->firstOrFail()` に
   寄せられるか。`exists:users,id` ルールの見直しとセットでないと存在オラクルは閉じないため、
   本 gate とは別 TODO として起票する。
2. **母集団の Services 層への拡張** — Service が request 由来 id を直接引数に取る設計が現れたときに再検討。
3. **template / 他リポジトリへの還流** — 「entrypoint 層に絞った母集団定義」は
   aigenba の app/ 全体版より運用コストが低い可能性がある。c2c 側の議題としてキュレーターに委ねる。

---

## 8. 検証方法

| 段階 | コマンド | 期待 |
|---|---|---|
| 走査器の単体 | `composer test -- --filter=DirectFetchScanner` | positive/negative fixture が全 green |
| gate 本体 | `composer test -- --filter=ModelDirectFetchInvariant` | 初期 inventory 12 件で green |
| **fail 確認 (テストファースト)** | inventory から 1 件削って再実行 | **fail する**こと (deny-by-default が効いている証明) |
| **stale 確認** | 実在しない箇所を inventory に足して再実行 | **fail する**こと (双方向整合) |
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

