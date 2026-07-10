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
   aigenba/spirux 間の divergence registry と同じ規律: 逸脱が正当なのは logic-driven
   (ドメイン要件起因)のときだけ。互換・UX・作業量を理由にした逸脱は不可。
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
| URL 整合 guard(認可より**前**に 404) | {project} ∈ current org は `app/Http/Concerns/ResolvesCurrentOrganization.php` の `resolveOrganizationProject()`(inline guard)。{item} ∈ {project} は `routes/web.php` の `Route::scopeBindings()`(`$project->items()` 経由で解決) |
| guard inventory への登録 | `tests/Architecture/NestedRouteIdorDefenseTest.php`(Web の `projects.items.update/destroy` = ScopeBindings、API の `api.v1.projects.items.update/destroy` = UrlIntegrityGuard) |
| REST API v1 controller(Web と同じ FormRequest 再利用、org-scoped 解決) | `app/Http/Controllers/Api/V1/ItemController.php`(`ResolvesApiOrganization`) |
| API リソース(レスポンス整形) | `app/Http/Resources/Api/V1/ItemResource.php` |
| API ルート(nested + dual guard + ability + idempotent) | `routes/api.php` の `api.v1.projects.items.{index,store,update,destroy}` |
| API Feature テスト | `tests/Feature/Api/{ApiEndpointTest,ApiKeyTest,IdempotencyTest,OAuthDualGuardTest}.php` |
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

## 5. API・外部公開面のマッピング

- REST API: nested route + flat ability。新リソースの ability は `{resource}:read` /
  `{resource}:write` / 動詞付き(`evaluations:run` 型)で定義し、ability 定義 1 箇所に追記。
- すべての書き込みエンドポイントに Idempotency-Key を配線する(テンプレの middleware を使う)。
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
- end-user 由来の自由テキストは **UserInput 型を経由してのみ** prompt に入れる
  (生 string を prompt に渡すと PHPStan が落ちる構成を維持)
- prompt は YAML テンプレート(laravel-prism-prompt)。コード内に prompt 文字列を直書きしない
- LLM 呼び出しは PromptOperation 経由(Prism Facade 直呼び禁止のguardrailテストが存在する)
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
   (Service 層 + DB CHECK の多層。直 fetch せず relation/Builder スコープ経由)
4. **untrusted 文字列は安全処理を経てのみ prompt に入る**(UserInput 型強制)
5. **権限判定は常に呼び出し側組織の team スコープに束縛**(team 明示 + strict_check=true)
6. **任意 class の逆シリアライズを許さない**(cache serializable_classes は既定 false。
   object cache が必要になったときだけ最小 allowlist)
7. **課金系の冪等性**: webhook は冪等マシン経由、消費は 2 フェーズ、通知は dedup_key。
   課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止)
8. **テストなしの実装完了はない**(不変条件 1-7 はそれぞれ対応する Architecture/Feature
   テストに新リソースを登録して初めて「実装済み」)

## 8. 設計ドキュメントの書き方(このテンプレ上の流儀)

アプリ固有機能の設計時は、両アプリで実証された運用をそのまま使う:

1. 概念設計 → レビュー → 詳細設計 → レビュー(app-design スキルのフロー)
2. 設計には必ず「**テンプレートのどの構造に何をマップしたか**」の節を設ける
   (§2〜§6 の判定結果を表で明記。判定に迷った項目は理由ごと残す)
3. テンプレ構造から逸脱する場合は `docs/template-divergence.md` に
   aigenba/spirux divergence registry と同じ形式(なぜ logic-driven か・どの不変条件を
   どの機構で保証し続けるか)で記録する
4. 中間成果物は `devnotes/YYYYMMDD-HHMM-<topic>/` に置く
