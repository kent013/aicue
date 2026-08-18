# アプリケーション組み込みガイド(LLM 設計者向け)

> このテンプレートの上に新しいアプリケーションのドメインロジックを実装するとき、
> 設計・実装を担当する LLM が従うべき手順と判定規則。
> 前提知識: `docs/default-team-pattern.md`(Team 層の扱い)。設計の経緯は
> `devnotes/20260611-template-extraction/`(調査・決定の記録)を参照。

## 0. 大原則

1. **テンプレートの不変条件(§6)は要件より優先する**。要件がそのままでは不変条件と衝突する
   場合、要件を不変条件の上に再設計する(例外を作らない)。
2. **変えてよい層と変えてはいけない層を区別する**(§1 の表)。
3. **テンプレートからの構造的逸脱は `docs/template-divergence.md` に記録してからやる**。
   逸脱が正当なのは logic-driven(ドメイン要件起因)のときだけ。互換・UX・作業量を
   理由にした逸脱は不可。**書式の正本は `docs/template-divergence.md` の規約節**で、
   形式は `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
4. **フレームワークのレンジ内でやる**。自前機構を発明する前に Laravel / 同梱モジュールの
   公式の作法で実現できないか確認する。

## 1. 層ごとの変更可否

| 層 | 変更 | 内容 |
|---|---|---|
| 組織階層スキーマ(Org/CustomTeam/Project) | **禁止** | Default Team パターンで吸収(06 参照) |
| 認可の軸(Laratrust org-team + project membership) | **禁止** | strict_check=true、team 常時明示 |
| セキュリティ防御層(mass-assignment trait / URL 整合 guard / UserInput 型 / SSRF pin / CipherSweet 対象の追加以外の削減) | **禁止** | Architecture テストが落ちる変更は要件側を見直す |
| 課金プリミティブ(Checkout Saga / Webhook 冪等 / チケット台帳 / Quota)の内部 | **禁止** | 設定とプラン定義で使う |
| ロール・permission の**中身**(enum 値、シーダー) | **変更前提** | §3 |
| プラン定義・Quota 項目・チケット消費点 | **変更前提** | §4 |
| ドメインモデル・画面・API リソース | **追加前提** | §2, §5 |
| config(teams_visible / API prefix / ability 定義 / プロバイダ) | **変更前提** | 各所 |

## 2. ドメインモデルの配置(tenancy マッピング)

新しいエンティティが来たら、必ず次の順で所属を決める:

1. **Project 配下が既定**。`foreignId('project_id')->constrained()` NOT NULL。
   迷ったら Project 配下に置く(spirux の Site/Page/Evaluation、aigenba の project-scoped Scenario と同型)。
2. **組織直下に置くのは「複数プロジェクトから共有される」要件が明文である場合のみ**
   (設定・マスタデータ等)。`organization_id` NOT NULL。
3. **「組織共有とプロジェクト固有の両方になり得る」多態スコープが要件にある場合**は、
   テンプレートの範囲外の設計判断。aigenba D1(body discriminator + 狭い trait + per-request 制約)を
   レシピとして参照し、`template-divergence.md` に記録の上で実装する。
4. **ユーザー個人に属するもの**(通知設定等)のみ `user_id` 直下。テナントデータを user 直下に
   置かない(組織を跨いで漏れる)。

配置を決めたら機械的に従うこと:
- マイグレーション: 親 FK は `constrained()` + NOT NULL(多態以外で nullable な tenant キーを作らない)
- FormRequest: `ProhibitsProtectedKeys` を適用。新エンティティの FK(例: `site_id` 相当)を
  prohibited キー集合に**追記**する
- ルート: 親リソースの nested route(`/api/v1/projects/{project}/things`)。
  Web は org-scoped 解決 + URL 整合 guard の inventory テストに新ルートを登録
- Policy: 親の Policy を経由して org 所属を確認(直 fetch 禁止)
- Factory: 親 Factory に連鎖させる(`docs/factories.md` 規約)

### 見本: Item リソース(この手順の実演)

テンプレートには Project 配下のサンプルリソース **Item** が同梱されている。
**新しいドメインリソースを足すときは Item を見本として参照する**(またはリネームして使う)。
上の手順と実際のファイルの対応は次のチェックリストの通り:

| 手順 | Item での実装ファイル |
|---|---|
| マイグレーション(親 FK `constrained()` + NOT NULL + cascade) | `database/migrations/2026_06_11_080000_create_items_table.php` |
| Model(FK は `$fillable` 外、親 BelongsTo) | `app/Models/Item.php` + `app/Models/Project.php` の `items()` |
| 保護キー集合への FK 追記 | `app/Support/Security/MassAssignmentProtectedKeys.php`(Item の FK `project_id` は**既存リストに含まれる**ため追記不要。新規 FK 名のときだけ追記する) |
| FormRequest(`ProhibitsProtectedKeys` + missing rule) | `app/Http/Requests/Projects/StoreItemRequest.php` / `UpdateItemRequest.php` |
| nested route(Team セグメントなし = Default Team パターン) | `routes/web.php` の `/projects/{project}/items` 系 |
| URL 整合 guard(認可より**前**に 404) | {project} ∈ current org は 2 層: `project.in-current-org` middleware(`app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`。FormRequest の DB ルールより**前**に cross-org を 404 に落とす = 存在オラクル防止。web の {project} route group に一括付与、網羅性は `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`)+ `app/Http/Concerns/ResolvesCurrentOrganization.php` の `resolveOrganizationProject()`(inline guard、二重防御)。{item} ∈ {project} は `routes/web.php` の `Route::scopeBindings()`(`$project->items()` 経由で解決) |
| API 側の URL 整合 guard(認可より**前**に 404、**FormRequest より前**) | {project} ∈ actor の組織は 2 層: `api.project-in-org` middleware(`app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php`。組織は API キー / OAuth token から確定。網羅性と middleware 順序契約は `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`)+ `ResolvesApiOrganization::resolveOrganizationProject()`(inline guard、二重防御)。{item} ∈ {project} は `routes/api.php` の `Route::scopeBindings()` |
| guard inventory への登録 | `tests/Architecture/NestedRouteIdorDefenseTest.php`(Web の `projects.items.update/destroy`、API の `api.v1.projects.items.update/destroy` = いずれも ScopeBindings) |
| 変更系 route の認可 gate | `tests/Architecture/ControllerAuthorizationGateTest.php`(POST/PUT/PATCH/DELETE は `Gate` を通るか exemption inventory に理由付き登録。§7 不変条件 8) |
| REST API v1 controller(Web と同じ FormRequest 再利用、org-scoped 解決、`Gate::forUser` 認可) | `app/Http/Controllers/Api/V1/ItemController.php`(`ResolvesApiOrganization` + `ReadsApiActor`) |
| API リソース(レスポンス整形) | `app/Http/Resources/Api/V1/ItemResource.php` |
| API ルート(nested + dual guard + ability + idempotent) | `routes/api.php` の `api.v1.projects.items.{index,store,update,destroy}` |
| API Feature テスト | `tests/Feature/Api/{ApiEndpointTest,ApiKeyTest,IdempotencyTest,OAuthDualGuardTest}.php` + `tests/Feature/Api/V1/ItemAuthorizationTest.php`(認可境界 / cross-org 404 / 存在オラクル封じ) |
| Policy(親 Policy へ委譲、直 fetch 禁止) | `app/Policies/ItemPolicy.php` → `app/Policies/ProjectPolicy.php` |
| Service(transaction + 所有権キーの明示代入) | 親側の見本: `app/Services/Project/ProjectService.php`(Default Team 自動割当)。Item は単一 insert のため relation 経由で Controller 直書き |
| Factory(親 Factory 連鎖) | `database/factories/ItemFactory.php`(project 未指定なら `ProjectFactory` 連鎖) |
| 画面(一覧は親の Show に内包。DS token/ramp のみ) | `resources/js/pages/Projects/Show.svelte`(+ Index/Create/Edit) |
| Feature テスト(保護キー 422 / cross-org・cross-project 404 / 権限) | `tests/Feature/Item/ItemCrudTest.php` / `ItemUrlIntegrityTest.php`(親側: `tests/Feature/Project/`) |
| フロント単体テスト | `tests/js/pages/ProjectsShow.test.ts` |

注意点(手順との差分・補足):
- Item の親 FK `project_id` はテンプレートの保護キー集合に最初から含まれているため
  「prohibited キー集合への追記」は発生しない。アプリ固有の新 FK(`site_id` 等)を持つ
  リソースを足すときに追記が必要になる。
- API(`/api/v1/...`)も REST API v1 として実装済み。Item は Web ルートに加えて API リソース
  (`Api/V1/ItemController` + `ItemResource`、nested route `api.v1.projects.items.*`)としても見本が
  存在する。API 追加時は同じ nested 形状 + flat ability(§5)+ dual guard(`auth:api-key,api-oauth`)
  + 書き込みへの `idempotent` middleware に従う。
- 子リソースの作成は親 FK を **relation 経由で代入**する
  (`$project->items()->create([...])`)。FK の mass assignment を書かない。

## 3. ロール・権限のマッピング

1. テンプレ既定ロール: `organization_owner / organization_admin / organization_member`、
   `project_admin / project_member`。**構造(2 軸 5 ロール)は維持**する。
2. 要件のアクター名(講師・受講者・編集者・閲覧者など)は、まず既定 5 ロールへの
   **リネーム**で表現できないか試す(aigenba は project_admin→tutor, project_member→trainee 相当)。
   ロール定義は enum + シーダーの 1 箇所にあるので、そこだけ変更する。
3. ロールを**追加**したくなったら一度立ち止まる。多くの場合「ロール追加」ではなく
   「permission の追加と既存ロールへの割当」で足りる。ロール数を増やすのは
   アクター間で**到達できる画面集合そのものが違う**ときだけ。
4. permission は機能カテゴリごとに enum を切る(aigenba の CoursePermission 等と同型)。
   permission チェックは常に `$org->laratrust_team_id` を明示する(strict_check=true)。
5. 「組織管理者は配下プロジェクトに暗黙アクセス」の継承規則は維持する。
   これを外したい要件(厳格な情報隔離)が来たら divergence として記録・設計する。

## 4. 課金・上限のマッピング

要件の課金的な記述を 2 つのプリミティブに分解する:

| 要件の形 | マップ先 |
|---|---|
| 「月 N 回まで実行できる」「実行ごとに消費」「追加購入」 | **チケット台帳**(reserve → commit/release の 2 フェーズ)。消費点を Service 層で 1 箇所に定義 |
| 「プロジェクトは N 個まで」「メンバー N 人まで」「機能 X は上位プランのみ」 | **多次元 Quota**(`max_*` 項目 / boolean 項目を Quota 定義に追加し、作成経路で check) |

規則:
- 消費を伴う長時間処理は必ず reserve → (成功) commit / (失敗) release。直接デクリメントを書かない。
  stale reservation 解放 cron が前提として存在する。
- Quota チェックは `QuotaService` 経由のみ。コントローラに直書きしない。超過は 402 を返す
  (API エラー envelope 規約に従う)。
- 能力の定義先は 2 箇所に分かれる: チケット付与数(`monthly_ticket_grant`)は `PlanSeeder`、
  Quota 値(`max_*` 等の limits)は `config/quota.php` の `plans.{code}`。コードにプラン名で
  分岐を書かない(`if ($plan === 'business')` 禁止。能力は常に Quota/チケット値で表現)。
- 価格の真実源は `plan_prices`(DB snapshot)。`PlanSeeder` は bootstrap 行を投入し、
  `billing:sync-stripe-prices` が Stripe Catalog の実 Price ID へ上書きする as-code 構成
  (`SyncStripePrices` / `VerifyStripePrices`)。
- 席数課金・買い切りチケット・プラン自動移行が要件に出たら、aigenba の実装例
  (SubscriptionItem / TicketPurchase / StarterMigrationConsent)をレシピとして参照する。
- **課金による利用可否 (課金ゲート) の判定は `BillingAccess` 経由のみ**。middleware /
  controller / service で subscription を直参照して gate 分岐を書かない。gate の適用は
  `require-active-subscription` middleware(業務 route group に付与。billing / webhook は
  group に含めない構造的 allowlist)。判定方針を変えたいアプリは `BillingAccess` の
  書き換え(または container での差し替え bind)だけで済ませる(spirux は
  billing_access_state カラム判定、aigenba は entitlement 導出に差し替えた実績)。
  本アプリ (AI-CUE) は entitlement 判定へ書き換え済み。**`plan_code` は利用可否の判定に
  一切使わない** (quota の解決キーでしかない)。`BillingAccess::state()` が
  `Subscribed` (subscription が entitled) / `ActiveFreePlan` (`organizations.free_plan_code`
  = `'personal'`) のいずれかなら許可し、それ以外は onboarding へ遮断する。
  無料枠は「plan_code が null であること」ではなく **明示申告** で表現する (P4 ゲート反転)。
  plan_code は Stripe Price を持つ有償プランの契約時のみ webhook が set し
  subscription.deleted で null に戻す状態キー (`RequireActiveSubscriptionMiddlewareTest` が固定)。

## 5. API・外部公開面のマッピング

- REST API: nested route + flat ability。新リソースの ability は `{resource}:read` /
  `{resource}:write` / 動詞付き(`evaluations:run` 型)で定義し、ability 定義 1 箇所に追記。
- すべての書き込みエンドポイントに Idempotency-Key を配線する(テンプレの middleware を使う)。
- **冪等配線は deny-by-default で機械強制される**: `api/v1/*` の変更系 route は
  `idempotent` を**ちょうど 1 本**持つか、`IdempotencyWiringExemption` + 30 文字以上の根拠で
  `tests/Architecture/IdempotentRouteCoverageTest.php` の目録へ登録する
  (免除の**前提**は `tests/Feature/Security/IdempotencyExemptionPremiseTest.php` が behavioral に固定)。
  決着は `completed` / `indeterminate` の 2 つだけで **release (再実行を許す) 経路を持たない**ため、
  **4xx/5xx の後に同じキーは再利用できない**(破壊的契約変更)。保持期間の SoT は
  `config/idempotency.php`(env 不使用)。契約の正本は [docs/api-idempotency.md](api-idempotency.md)、
  文書と実装の parity は `tests/Architecture/IdempotencyContractParityTest.php` が固定する。
  gate が見るのは `api/v1/` 配下だけで、web の書込 route・`oauth/*`・別 prefix の API には**沈黙する**。
- **API の権限境界は ability(トークンの能力)と Policy(actor の権限)の 2 段**。
  ability 不足は `code: "insufficient_ability"`、Policy 不足は `code: "forbidden"` で返り、
  クライアントは「トークン設定不足」と「権限不足」を判別できる。
  認可の主体は `ApiActorContext::$user`(API キー = 発行者 / OAuth = トークン所有者)であり、
  controller では `Gate::forUser($this->apiActor($request)->user)->authorize(...)` を使う
  (`Gate::authorize` は dual guard 下で `ApiKey` を Policy に渡してしまい 500 になる)。
  OAuth CLI セッションは**組織メンバーなら誰でも開始できる**ため、
  組織メンバーであることは書き込み権限を意味しない(Policy が別途判定する)。
- **エラーの優先順位は「actor 解決 → テナント境界 404 → ability 403 → Policy 403」**。
  ability の 403 をテナント境界より先に返すと、read-only キーで write route を叩くだけで
  「他組織に実在 = 403 / 不在 = 404」と分岐し、**project id の存在オラクル**になる
  (監査サイクル 2 High-1)。actor 解決の失敗 (401 / `actor_not_resolvable` 403) は
  **route binding より前**に返す — 不在 id に対しても 404 ではなく 401/403 になるが、
  実在 id と不在 id で応答が同一なので存在は漏れない。
  順序は `ProjectRouteCurrentOrgGuardTest` / `TenantBoundaryOrderingTest` が機械固定する。
- rate limit は既存 4 バケット(api-read / api-write / api-status / api-mcp)に割り当てる。
  新バケットを増やすのは要件に明示的な根拠があるときだけ。
- MCP tool: whoami / list-projects / show-project / list-items の雛形に倣う。書き込み tool は McpIdempotencyService 経由。
- API キー prefix は config で必ずアプリ固有値に変更する。
- プラットフォーム運用 API(テナント横断の管理操作)はテンプレートに同梱していない。
  要件に出たときだけ、組織スコープの API キーとは別系統の認証機構として設計する
  (組織キーにテナント横断権限を持たせない)。

## 6. LLM 機能のマッピング

LLM を使う機能が要件に来たら、まず利用形態を分類する:

| 形態 | 例 | 使うレシピ |
|---|---|---|
| 構造化 one-shot | 採点・要約・分類・評価 | コアのみ(UserInput + prompt YAML + canary)。spirux 型 |
| マルチターン対話 | チャット・NPC・エージェント対話 | + 会話履歴ビルダー(ConversationContextBuilder レシピ、aigenba 型) |
| LLM tools / 外部アクション | web_search、関数呼び出しで実世界に作用 | + tool allowlist config(config/llm-defense レシピ) |

どの形態でも必ず:
- end-user 由来の自由テキストは **窓口 (`App\Support\Llm\PromptDefense`) を経由してのみ**
  prompt に入れる。窓口が無害化 → タグ境界化 (`UserInput`) → 合言葉の合流を行い、
  実行単位 (`GuardedPrompt`) が応答検査まで束ねる (`docs/architecture.md`
  §LLM プロンプト防御の窓口方式 が正本)
- prompt は YAML テンプレート(laravel-prism-prompt)。コード内に prompt 文字列を直書きしない
- LLM 呼び出しは `app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ
  (Prism Facade 直呼び禁止の guardrail テストが app/ routes/ database/ config/ bootstrap/ の
   5 走査根で存在する)
- コストは LlmCallLog に記録される構成を崩さない。新しい呼び出し点もテンプレの呼び出し経路を通す
- **使わない防御 config を足さない**(読まれない config は config theater。aigenba D3 の教訓)

## 7. 守るべき不変条件(チェックリスト)

実装完了前に、追加したコードすべてについて確認する。これらは aigenba/spirux 両方で
実証され Architecture テストで強制されている不変条件であり、**アプリ都合で緩めない**:

1. **tenant キー不信**: サーバが Auth/route/生成で導出する ownership/actor/tenant キー
   (user_id, organization_id, project_id, team_id, custom_team_id, created_by 系)を
   クライアント payload から受け取らない(`missing`)
2. **子は親に属する**: nested route の子リソースは URL 上の親/テナントに属することを
   構造的に保証し、不整合は**認可より前に 404**(403 で存在を漏らさない)
3. **cross-org 不可**: いかなる経路でも組織を跨いだ read/write が起きない
   (Service 層 + DB CHECK の多層。直 fetch せず relation/Builder スコープ経由)。
   「直 fetch しない」は `ModelDirectFetchInvariantTest` が機械強制する:
   **クラス起点 (`User::` / `new User` / `DB::table('users')`) の主キー同一性クエリ**は
   `tests/Support/Security/DirectFetchInventory` へ `DirectFetchJustification` +
   30 文字以上の具体的根拠 + case ごとの構造化 field を登録しない限り fail する。
   **新しい id 受け口 (POST payload / MCP tool 引数 / token claim / queue payload) を足すときは、
   まず relation 起点 (`$organization->users()->whereKey($id)`) で書けないかを検討する**
   (書けるなら候補にすら上がらない)。route parameter 由来の id は
   `NestedRouteIdorDefenseTest` の担当で母集団が交わらない
4. **untrusted 文字列は安全処理を経てのみ prompt に入る**
   (窓口 `App\Support\Llm\PromptDefense` 強制。無害化 → タグ境界化 → 合言葉の合流 →
   応答検査。`PromptDefenseWindowGateTest` / `LlmDefenseConfigGateTest`)
5. **権限判定は常に呼び出し側組織の team スコープに束縛**(team 明示 + strict_check=true)
6. **任意 class の逆シリアライズを許さない / キャッシュに入れるのは素のデータだけ**:
   `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧は作らない
   (例外を作らない)。**キーごと消すのも不可** — Laravel は宣言が無いと制限なしの
   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは
   配列 / 文字列 / 数値 / 真偽値 / `null` だけで、
   オブジェクトは `toArray()` で素の配列にしてから入れ、読み戻しは `fromArray()` 等で
   **明示的に組み立て直して検査し、失敗したら `forget`** する
   (準拠実装: `App\Services\FxRateService` + `App\DataTransferObjects\FxSnapshotDto`)。
   強制は **2 層**である(家系の裁定 AG-151 = 正典 v2):
   - **静的層** (`tests/Architecture/CachePayloadPlainDataGateTest.php`) —
     キャッシュ書き込み経路とキャッシュに触れるファイルは目録へ登録必須(deny-by-default)。
     受け皿の境界を迂回する書き方は**3 つの目録**で pin する:
     (a) `Cache::extend` / `getStore` / `setStore` / `tags` / macro 登録 /
     受け手型・保管先型の直接生成 は**通常経路 0 件 + 実行時層の自己テストだけ**を
     名指しの目録へ exact-fit、
     (b) 受け手型・保管先型・実行時層の実装クラスの**継承・実装の宣言**は
     別の名指し目録で**実行時層の実装 2 本だけ**、
     (c) `new $class` のように生成対象が静的に決まらない形は deny-by-default で、
     キャッシュの保管先ではない既知の用途を理由付きの目録へ登録する
   - **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) —
     テスト中のキャッシュ書き込みを受け皿の側で捕まえ、保管先へ渡す**前の値**を再帰検査する。
     結線はアプリ起動の前(`Tests\TestCase::createApplication()`)、後始末は
     `tests/Pest.php` の全レーン(`tests/Architecture/CacheGuardWiringGateTest.php` が固定)
   **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
   **値**を見るので、直列化しない保管方式でも同じように発火する。
   ただし **`getStore()` は実行時には落とせない**(vendor 自身が流量制限・排他の正常系で呼ぶ)
   ため、そこは静的層だけが塞ぐ。したがって
   **vendor が `getStore()` 経由で書く値は 2 層とも見えない**。
   網羅的な保証外一覧の正本は**実行時層の docblock**であり、本書と AGENTS.md には写さない。
   配列往復は `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` が固定する
7. **課金系の冪等性**: webhook は冪等マシン経由、消費は 2 フェーズ、通知は dedup_key。
   課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止。
   AI-CUE の判定は billing entitlement: `state()` が Subscribed / ActiveFreePlan なら許可。
   plan_code は判定に使わない — 無料枠は free_plan_code='personal' の明示申告)
8. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE のアプリ所有 route は
   `Gate::authorize` / `Gate::forUser(...)->authorize` を持つか、
   `tests/Architecture/ControllerAuthorizationGateTest.php` の exemption inventory に
   `App\Enums\Security\ControllerAuthorizationExemption` + 具体的根拠(30 文字以上)付きで
   登録する(deny-by-default で強制)。**層 2(テナント境界 = 404)と層 3(認可 = 403)の
   順序は不可侵** — inline guard は必ず `Gate` より前に置く(逆にすると cross-org が
   403 を返し、リソースの存在が漏れる)。
   なお `can:` middleware / `FormRequest::authorize()` / membership binder /
   `auth`・`verified`・`recent-auth`・`require-active-subscription`・`api-key.ability`
   middleware は**認可(層 3)として数えない**(数えると gate が形骸化する)
9. **層 2 は binding の直後で閉じる**: `SubstituteBindings` は**不在 id だけ**を 404 にする。
   したがって binding とテナント境界 404 の間に 404 以外で短絡する middleware が 1 つでもあると、
   「他組織に実在 = その短絡の応答 / 不在 = 404」という **1 bit の存在オラクル**になる。
   監査サイクル 2 では 課金ゲート 302・verified 302・2FA 強制 302・
   Inertia version mismatch 409・`api-key.ability` 403 のすべてがテナント境界より先に走っていた。
   - **`SubstituteBindings` とテナント guard の間に短絡 middleware を置かない**。
     実行順の正本は `bootstrap/app.php` の **priority list**(route の宣言順ではない)。
     ⚠ Laravel の priority list は「載っている middleware 同士の相対順序」しか強制しないため、
     間に挟まる web グループの middleware も guard より後として priority list に載せる必要がある
   - API の順序契約: `resolve.api-actor` → `SubstituteBindings` → **`api.project-in-org`** →
     `api-key.ability:*` → `idempotent`。**ability の 403 はテナント境界 404 より後**
   - `{project}` を持つ route は web = `project.in-current-org` /
     **API = `api.project-in-org`** middleware を必ず付ける
   - 子リソースは `Route::scopeBindings()` で routing 層に解決させる。
     scopeBindings に乗らない param は **implicit binding を使わず** controller が
     owner-scoped relation から手動解決する(binding 段で解決しない = 不在 id と
     実在の他テナント id が同じ経路を辿る = 分岐しない)
   (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
   **`TenantBoundaryOrderingTest`** が強制)
10. **テストなしの実装完了はない**(不変条件 1-9 はそれぞれ対応する Architecture/Feature
    テストに新リソースを登録して初めて「実装済み」)

> **関連 (番号ではなく項目名で参照する)**: 外部サービスへ出る**コード到達点**そのものの
> deny-by-default 目録は本節ではなく `docs/architecture.md`
> §外部到達点の目録 (標準形 v1 / 検知 v1) が正本
> (`ExternalSeamInventoryTest`)。本節の「外部 URL 取得は SSRF 検査経由」は**宛先の安全性**、
> あちらは**到達点の身元検査**で目的が違う。

### 新規 route(特に変更系)を足すときのチェックリスト

1. **層 2(テナント境界)が binding の直後で閉じているか**を確認する。
   controller の inline guard は **FormRequest の後**に走るため、それだけでは不十分。
   - `{project}` を持つ route → web は `project.in-current-org`、
     **API は `api.project-in-org`** middleware が付いていること
   - 子リソース(`{item}` 等)→ `Route::scopeBindings()` で routing 層に解決させること
   - 確認方法 1: **cross-org の実在リソース + 不正 payload** を送って
     **404**(422 ではない)が返ること
   - 確認方法 2: 後段の短絡が起きる状態(未契約組織 / メール未確認 / 2FA 強制未準拠)で
     **cross-org の実在 id と 不在 id の応答が status/ヘッダ/body まで完全一致**すること
2. ハンドラ冒頭(URL 整合 guard の**後**)に `Gate::authorize(...)` を置く。
   **REST API v1 では `Gate::forUser($this->apiActor($request)->user)->authorize(...)`**
   (dual guard では通過した guard が default に昇格し `Auth::user()` が `ApiKey` を返すため、
   `Gate::authorize` は Policy の `User $user` 型に対して TypeError = 500 になる)
3. 認可が不要なら `ControllerAuthorizationGateTest` の exemption inventory に
   enum + 「**何が代わりに守っているか**」を 30 文字以上で登録する。
   当てはまる enum case が無ければ、それは**認可を足すべき route** である
   (特に `NoAuthorizableSubject` は「親テナントすら無い新規作成」限定。
   親テナントがある create は**対象外** = `Gate::authorize('create', [Model::class, $parent])` を書く)
4. **1 個以上の param を持つ route**なら `NestedRouteIdorDefenseTest` の inventory に
   **parameter 単位で**防御方式(`NestedRouteDefenseMode`)を登録する。
   テナント親子でない param は `NonResourceParameter` / `PublicGlobalResource` を宣言し、
   `NestedRouteDefenseInventory::nonTenantReasons()` に理由を書く(無記名の逃げ道は作らない)
4b. **新しい middleware を足したら** `TenantBoundaryOrderingTest` の
   `middlewareShortCircuitInventory()` に「短絡しうるか」を必ず分類する
   (未分類は fail。疑わしきは `true` 側に倒す)。`SubstituteBindings` より前に置く
   短絡 middleware は `preBindingShortCircuitInventory()` にも登録し、
   「生 route parameter を読まない」ことを宣言する
5. **認可の「内容」を Feature テストで固定する**(必須)。
   `ControllerAuthorizationGateTest` はトークン走査であり、
   **到達可能性(`if (false) { Gate::authorize(...); }` のような死んだ認可)は判定しない**。
   gate が守るのは「認可判断の入口が存在すること」だけで、
   「権限の無い actor が実際に 403 になること」は Feature テストの責務
   (見本: `tests/Feature/Api/V1/ItemAuthorizationTest.php`)。
   この 2 層(入口 = Architecture / 実挙動 = Feature)はセットで維持する
6. **流量制限 (throttle) を付ける**。保護対象群(未認証で到達しうる変更系 /
   ステートレスな機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` /
   認証面の変更系)は **throttle をちょうど 1 本**持つか、
   `ThrottleCoverageInventoryTest` の exemption inventory へ
   `ThrottleCoverageExemption` + 30 文字以上の根拠付きで登録する(deny-by-default)。
   詳細は下の「§7b 流量制限の付与規約」
7. `composer test` で 4 つの gate
   (`ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` /
   `ProjectRouteCurrentOrgGuardTest` / `ThrottleCoverageInventoryTest`)が
   green であることを確認する

### §7b 流量制限の付与規約

**貼る仕組みの 3 段優先順**(上から順に検討し、**上で貼れるなら下は使わない**):

1. **route 定義に直接書く**(自前 route)。`->middleware('throttle:{limiter}')`
2. **package の設定で貼る**(vendor 登録 route。`config/fortify.php` の `limiters` など)。
   受け付けるキーが限られる(Fortify は login / two-factor / passkeys / verification の 4 つだけ)ため、
   賄えない分だけ 3 に落とす
3. **`RouteThrottleBinder::attachOnBooted()` で後付けする**(2 でも貼れない vendor route 専用)。
   `$this->app->booted()` の中で走り、route 名が消えていれば **fail-fast** する
   (silent degradation = 無音の無防備を作らない)。付与は冪等
   (実装: `app/Support/Http/RouteThrottleBinder.php`)
   - ⚠ fail-fast が効くのは**後付けが実際に走る起動**、すなわち route cache が無い起動
     (ローカル開発・テスト・`php artisan route:cache` 生成時の再 bootstrap) **すべて**である。
     **cached 起動では後付けごと skip される**ため route 名が消えていても静かに起動する
     (「どんな起動でも必ず落ちる」ではない)。cached 運用の本番で意味を持つ検出点は
     `route:cache` **生成時**。詳細は下の §7c
   - **`php artisan route:cache` を毎デプロイ再生成すること**。契約の正本は
     **下の「§7c vendor route への後付け機構と route:cache の契約」**
     (この要件は throttle 専用ではなく、後付け機構**全体**の前提条件である)
   - 後付け側の判定は controller middleware を見ない
     (boot 中に controller を container 解決すると request scope の singleton が
      早すぎるタイミングで確定するため)。controller 側 throttle との二重付与は
     目録検査が「2 本以上」として検出する

**キー規約**: named limiter のキーは `{レーン}:{種別}:{値}`
(例 `login:email-ip:{hash}:{ip}` / `webhook-ses:ip:{ip}`)。
`RateLimiterKeyConventionTest` が全 limiter を実際に評価して機械検査する。

- **email をキーに入れるときは `EmailNormalizer::normalize()` → `EmailHash::compute()`**。
  平文も正規化済み平文もキャッシュキーに残さない。
  `Str::transliterate()` は**使わない**(legitimate な Unicode email を別 user へ
  collapse させ、無関係アカウントの巻き添えロックアウトになる)
- **inline throttle (`throttle:6,1`) は自前 route では使えない**(T125 で全廃)。
  残る inline は **vendor 由来の 3 本だけ**で、`InlineThrottleInventoryTest` の目録に
  `InlineThrottleBucketRationale` + 30 文字以上の根拠付きで登録済み
  (`passport.token` / `passport.device.code` / `livewire.upload-file`)。
  - ⚠ **inline の bucket は route ごとではない**。`ThrottleRequests::handle()` が組む
    キーは `$prefix`(既定 `''`)+ `resolveRequestSignature()` で、後者は認証済みなら
    **user id だけ**を返す(route も limiter 名も入らない)。つまり
    **同一 actor の全 inline throttle route が 1 つの bucket を共有する**
    (route ごとに違うのは `maxAttempts` の比較値だけ)。
    named limiter はキーに limiter 名が入るため**レーンが独立する**
  - **`InlineThrottleBucketRationale` に自前 route 向けの case は 1 つも無い**(意図的)。
    各 case の premise が **action class の vendor 名前空間**(`Laravel\Passport\` /
    `Livewire\`)を機械検査するため、`App\...` の自前 controller はどの case にも
    当てはまらず**目録に登録できない** = 自前 route への inline 追加は必ず fail する。
    これが「レーンを分けたいときは inline ではなく named limiter を新設する」の機械化
  - 恒久回帰は `AuthThrottleCoverageTest` の T125 セクション
    (あるレーンを使い切っても別レーンが生きていることを実 HTTP で固定する)

**レーンの切り方の 2 基準**(混同しない):

| 基準 | 数える対象 | 例 |
|---|---|---|
| **credential 単位の試行予算** | 同じ秘密を照合する面をまとめる(分けると同じ秘密を n 倍試せる) | `password-verify`(recent-auth / confirm-password / update-password の 3 面で合算 6/min) |
| **feature 単位の操作予算** | 同じフローの操作をまとめる(フロー内の相互消費は許容し、別 feature との巻き添えを断つ) | `two-factor-manage`(10/min)/ `email-verification`(6/min) |

T125 で新設したレーンと割当(正本は `ThrottleLaneAssignmentTest` の
`throttleLaneAssignments()`。相乗りは deny-by-default で fail する):

| limiter | 上限 | route |
|---|---|---|
| `password-verify` | 6/min | `recent-auth.password` / `password.confirm.store` / `user-password.update` |
| `password-set` | 6/min | `settings.password.store` |
| `email-verification` | 6/min | `verification.send` / `verification.verify` |
| `two-factor-manage` | 10/min | `two-factor.{enable,confirm,disable,regenerate-recovery-codes}` |
| `invitation-accept-submit` | 10/min | `invitations.accept.store` |
| `plan-activate` | 10/min | `onboarding.activate-personal` |

- 閾値は**移行元の inline 値そのまま**(新しい値を発明していない)。
  増えたのは「認証面 12 本の受理リクエスト総数が合算 10/min から各レーン合計 48/min になる」
  ことだけで、これは受容済み。安全性の主張は**巻き添え 429 についての単調緩和**に限る
  (新レーンの route 集合は移行前の共有 bucket の部分集合なので、
   新たに 429 になる経路は増えない。ただし「後退リスクゼロ」ではない)
- キーの組み立ては `App\Support\Http\RateLimiterKeys::actorOrIp()` に一点集約する
  (認証済み = `{lane}:user:{id}` / 未認証 = `{lane}:ip:{ip}`)。
  full key は `RateLimiterKeyConventionTest` が固定し、
  **レーンをまたぐキー衝突**(分けたつもりで分かれていない)も同テストが検出する
- **limiter キーに route parameter を入れない**(`NamedRateLimiterKeyTest`)。
  bucket が id ごとに分かれると「429 になるまでの回数」が実在を漏らす

**閾値**: プロダクト依存のため既存値を勝手に変えない。新しい面には
**既に本番稼働している同性質エンドポイントと同値**を充てる
(公開フォーム = IP 5/min + IP+email 10/60min、自分の credential 操作 = 6/min、
認証済みの管理操作 = 10/min)。

**未認証 webhook の注意**: throttle は署名検証より**先**に走る。したがって
固定キー(全体天井)を置くと「無効 body の連打で正当な通知を 429 にできる」
= 攻撃者が業務を止められる口になる。IP 単位に留め、これは
**署名検証コストの上限であって正当通知を守る全体天井ではない**と理解する
(共有クラウド出口では巻き添え 429 がありうるため、送信元 IP の分布と
429 発生率を監視項目に入れる)。

**保護対象群セレクタの非対称**(意図的):

| セレクタ | 対象 | メソッド条件 |
|---------|------|------------|
| S1 | 未認証で到達しうる route | **変更系のみ**(POST/PUT/PATCH/DELETE) |
| S2 | ステートレスな機械向け経路(`api/` / `oauth/` / `.well-known/oauth-`) | 問わない |
| S3 | 認証面(login / password. / two-factor. / social. / invitations. …) | **問わない(GET/HEAD も入る)** |

S3 がメソッドを問わない理由は、**認証面は「読むだけ」の GET でも秘密の開示・
外部呼び出し・状態生成を伴いうる**から(SSO callback は 1 リクエストで IdP へ
外向き HTTP を出しうるし、招待受諾の GET は未認証入力の token を DB 照合する)。
逆に S1 まで GET へ広げない理由は、母集団が数百本になり
**exemption 台帳に埋もれて gate が機能しなくなる**から。

**認証面 GET の分類方針**: 判断基準は「**1 リクエストで外向き通信・重い計算・
状態生成が起きるか**」の 1 本。

- 起きる → throttle を貼る(未認証面なので必ず named limiter)
- 描画にすぎない → `AuthViewRenderOnly`
- フロー開始だがその場で外向き通信をしない → `AuthFlowInitiationWithoutOutboundCall`
  (**対になる完了経路が throttle 済みであること**が適用条件。前提テストが固定する)

**exemption の cap は exact fit で運用する**(`throttleCoverageExemptionCap()` は
現在値ちょうど)。余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
免除できる枠」になる。exact fit なら次の 1 本が必ず「この数値を変える差分」として現れる。
併せて `throttleCoverageExemptionCapByCase()` が case 別上限を持ち、
**どのカテゴリが膨らんだか**を検出する(全体 cap の単なる内訳ではなく独立した検査)。

**監視項目**: 429 発生率は `social-callback` / `invitation-accept` も対象に含める。
併せて **invalid callback 比率**(intent 不在で `login` へ差し戻された割合)も見る。
どちらも **IP レーン**のため、同一 NAT 配下の一斉ログイン / 一斉招待受諾で
巻き添え 429 がありうる。limiter は恒久ロックを作らないが**到達は保証しない**
(共有 IP の継続競合では解除直後の枠を取られ続けうる)。
**巻き添えが出たときの初動は閾値変更ではなく `TRUSTED_PROXIES` / 実 client IP 解決の確認**
(`docs/trusted-proxies-runbook.md`)。閾値変更はプロダクト判断として別 TODO を起票する。
なお `social.redirect` は throttle しないため、**同一 IP から callback 枠を意図的に
枯らす一時 DoS は残る**(許容リスク。redirect を絞っても外向き HTTP の総量は
callback 側で有界化されており減らない)。

**exemption を書くときの原則**: exemption は「throttle が無いことが**正しい**」
という主張であり、その**前提**(署名で短絡する / 定数応答である /
production では登録されない / 完了経路が throttle 済みである)は
`ThrottleExemptionPremiseTest` で behavioral に固定する。
前提が崩れたのに気づけない状態を作らない。

### §7c vendor route への後付け機構と route:cache の契約

vendor (Fortify / laravel-passkeys / Cashier) が登録した route へ、アプリ側が
boot 後に middleware を後付けする経路は **2 つの binder に限られる**:

| binder | 付けるもの | 呼び出し元 |
|---|---|---|
| `RouteThrottleBinder::attachOnBooted()` | `throttle:{limiter}` | FortifyServiceProvider / AppServiceProvider |
| `RouteMiddlewareBinder::attachOnBooted()` | `recent-auth` / `recent-auth.on-email-change` / `ensure-login-method` / `no-store` / `throttle:passkeys` | FortifyServiceProvider / PasskeyServiceProvider |

**2 つの事象を混ぜないこと**:

1. **生成時**(`php artisan route:cache` 実行中)= 後付けが完全に走り、cache へ焼き込まれる。
   `RouteCacheCommand::handle()` が先頭で `route:clear` してから **cache 無しのアプリを
   再 bootstrap** するため、`loadRoutesFrom()` が `require` を通して対象 route が登録される。
   route 名が消えていればここでデプロイが止まる。
   なお fail-fast 自体は「**後付けが実際に走る起動すべて**」= route cache が無い起動
   (ローカル開発・テストを含む)で効く。`route:cache` 生成時**だけ**に効くのではない。
   ただし **cached 運用の本番で意味を持つ検出点はここだけ**である
   (ここで止まらなければ、cached 起動は skip するのでサービス投入まで誰も気づかない)。
2. **起動時**(cached 起動)= 後付けは **1 本も効かない**。
   `loadRoutesFrom()` が require を飛ばすため、**binder の callback が走る時点では**
   対象 named route が 1 本も登録されていない(compiled routes はこの callback より
   **後**に読まれる。「route が永久に存在しない」の意味ではない)。
   仮に触れていても `Router::setCompiledRoutes()` が collection を新品へ丸ごと
   差し替えるため捨てられる。ゆえに binder は明示 skip する
   (**ここで例外を投げると `php artisan route:list` が必ず落ちる** = T120 の事故)。

⇒ **運用要件: `php artisan route:cache` を毎デプロイ再生成すること。**
   これは throttle だけの要件ではない。**2FA 秘密の露出防止 (recent-auth) /
   passkey 削除の手段保持 (ensure-login-method) / WebAuthn challenge の no-store も
   同じ前提条件に乗っている**。stale な route cache は古い付与状態のまま起動し、
   **無音で保護が外れる**(実測: 剥がした cache では鮮度切れセッションの
   2FA 秘密 GET が 409 でなく **200 で秘密を返す**、`force=true` の enable も 200、
   `passkey.destroy` の 429 が消える)。

**現状**: 本リポジトリに**デプロイ定義は存在しない**(deploy/ / terraform / k8s manifest /
CI のデプロイ job のいずれも無い)。したがって上記は**今日は人手で守られている要件**であり、
デプロイ基盤を作る PR が**必ず実装しなければならない要件**である(AGENTS.md の運用要件ブロック)。
今その基盤を先回りして作らない(思考原則 2)。

家系の正典が採る「経路の一覧が組み上がった後に走らせる専用の実行点へ集約する」形へ**移行しない**
判断は、`docs/template-divergence.md` の **D19** に登録済みである。主前提は
「`route:cache` が実行されないこと」で、`tests/Architecture/RouteCacheExemptionPremiseTest.php` が
**追跡下に直接書かれた `route:cache`** と **`artisan` と `optimize` の間が空白だけの実行記述**が
無いことを機械で固定する。検出できるのは直接書かれた文字列までで、動的に組み立てた実行・
オプションを挟む書き方・リポジトリの外にある手順は対象外である。
説明として `route:cache` の語を持つ既存ファイルは**件数を完全一致で pin** して扱い
(増減のどちらでも赤になる)、走査から丸ごと外れているのは**同テスト自身の 1 件だけ**である
(自分が検出したい語を負のコントロールの入力として持つため。その 1 ファイルの中は見えない)。
デプロイ定義の検出も
同テストが併せて行うが、そちらは**早期の気づき**であって網羅を主張しない。
焼き込みの入力に後付けが欠落なく載ることと、欠けたときに保護が実際に外れることは
`tests/Feature/Security/RouteCacheBakedProtectionTest.php` が実測で固定する
(同一プロセス内で完結する検査であり、**cached 起動そのものの再現ではない**)。

**新しい後付け経路を足すとき**: 必ず上記 2 binder のどちらかを通す。
`PostBootRouteMutationInventoryTest` が deny-by-default で強制する
(`app/` 配下で起動後に named route を名前で引くコードを allowlist 2 ファイルに限る)。
ただしこの gate が守るのは**入口が絞られていること**までで、
**docblock の主張が機序と一致していること**も**起動時の cache 鮮度**も検査しない
(前者は機械照合できず、後者は本番デプロイで mtime が揃うため正しく作れない)。

## 8. 設計ドキュメントの書き方(このテンプレ上の流儀)

アプリ固有機能の設計時は、両アプリで実証された運用をそのまま使う:

1. 概念設計 → レビュー → 詳細設計 → レビュー(app-design スキルのフロー)
2. 設計には必ず「**テンプレートのどの構造に何をマップしたか**」の節を設ける
   (§2〜§6 の判定結果を表で明記。判定に迷った項目は理由ごと残す)
3. テンプレ構造から逸脱する場合は `docs/template-divergence.md` に記録する。
   **書式の正本は同ファイルの規約節**(登録メタ表 9 行・状態の値域・件数の明示行)で、
   登録は逸脱を作る変更そのものに含める
4. 中間成果物は `devnotes/YYYYMMDD-HHMM-<topic>/` に置く
