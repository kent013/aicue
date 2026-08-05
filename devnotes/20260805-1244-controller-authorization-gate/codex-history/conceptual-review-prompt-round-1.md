# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: controller-authorization-gate

## 背景・課題

### 1. 「変更系 route は認可を通る」が機械強制されていない

既存の `tests/Architecture/ManageRouteAuthGuardTest` は `/manage/` 配下の
**auth + verified middleware の有無**しか見ておらず、docblock でも
「認可 (manageMembers 等) は各 Controller の Gate::authorize の責務 (Feature テストで固定)」と
**明示的に対象外**を宣言している。つまり本アプリには

> 「状態を変える route のハンドラは、必ず認可判断を 1 回通る」

を deny-by-default で守る構造が**存在しない**。認可漏れは「その route の Feature テストを
書き忘れたら誰も気づかない」状態で、新規 route 追加のたびに同じ穴が開きうる。

### 2. 実査結果 (本設計で再確認済み)

`php artisan route:list --json` から POST/PUT/PATCH/DELETE を取り、
アプリ所有 (`App\Http\Controllers\*`) のものだけに絞ると **61 本**。
各ハンドラメソッド本体を走査したところ **46 本が認可あり / 15 本が認可なし**。

認可なし 15 本 (= 9 controller。タスク指示の 8 controller に対し**実査で 4 route / 3
controller 多く見つかった**):

| # | route | handler | 指示にあったか |
|---|-------|---------|--------------|
| 1 | `api.v1.projects.items.store` | `Api\V1\ItemController@store` | ✔ (実害) |
| 2 | `api.v1.projects.items.update` | `Api\V1\ItemController@update` | ✔ (実害) |
| 3 | `api.v1.projects.items.destroy` | `Api\V1\ItemController@destroy` | ✔ (実害) |
| 4 | `api.v1.me.session.revoke` | `Api\V1\Me\RevokeSessionController@destroy` | ✔ |
| 5 | `contact.store` | `ContactController@store` | ✔ |
| 6 | `invitations.accept.store` | `InvitationAcceptanceController@store` | ✔ |
| 7 | `organizations.switch` | `OrganizationSwitchController@store` | ✔ |
| 8 | `settings.account.destroy` | `Settings\AccountController@destroy` | ✔ |
| 9 | `recent-auth.password` | `Auth\ConfirmRecentAuthController@confirmPassword` | ✔ |
| 10 | `webhooks.ses` | `Webhooks\SesNotificationController@__invoke` | ✔ |
| 11 | `organizations.store` | `Organizations\OrganizationController@store` | **✘ 追加発見** |
| 12 | `notifications.open` | `NotificationController@open` | **✘ 追加発見** |
| 13 | `notifications.read` | `NotificationController@read` | **✘ 追加発見** |
| 14 | `notifications.read-all` | `NotificationController@readAll` | **✘ 追加発見** |
| 15 | `debug.login-as` | `DebugLoginController@loginAs` | **✘ 追加発見** |

> `Auth\SocialAuthController` はタスク指示の 8 件に挙がっていたが、
> `social.redirect` / `social.callback` はいずれも **GET** であり本 gate の
> 対象 (変更系 verb) に入らない。裁定は inventory に「verb 対象外」として記録する
> (§スコープ外)。

### 3. 実害: API と web でロール境界が食い違っている

`app/Http/Controllers/Api/V1/ItemController.php` の `store:46` / `update:62` / `destroy:82` は
`resolveOrganization` + `resolveOrganizationProject`(+`resolveProjectItem`) の
**URL 整合 guard (テナント所属確認) だけ**で `ItemPolicy` を通っていない。
対する web 側 `app/Http/Controllers/Projects/ItemController.php:38,57,75` は
`Gate::authorize('create'|'update'|'delete', ...)` を通す。

`ItemPolicy` は `ProjectPolicy::update` に委譲し、
「組織 owner / admin **または** 当該 project の `project_admin` (= 表示名『編集者』)」だけを許す。
一方 API は「組織に所属していればよい」= `organization_member` かつ project ロールなし
(表示名『撮影者』相当・実質 viewer) でも **Item を作成・更新・削除できる**。

**同じドメイン操作が、経路 (web / REST API) によって権限境界が違う**という不整合であり、
使命 (現場作業者が触る標準作業資産の一貫した統制) に対する実害である。

### 4. 「認可不要」の判断が記録されていない

15 本のうち大半は「認可不要が正しい」。しかしその根拠がコードにもテストにも
**構造化されて残っていない**ため、次に足される route が同じ形をしていても
「これは認可不要な仲間だ」と機械的に検証できない。

---

## 改善アイデア

### 施策 1: `tests/Architecture/ControllerAuthorizationGateTest.php` 新設 (deny-by-default gate)

変更系 (POST/PUT/PATCH/DELETE) の**アプリ所有 route** を全件候補とし、各ハンドラメソッドが

- `Gate::authorize(...)` / `Gate::forUser(...)->authorize(...)`
- `$this->authorize(...)`
- route の `can:` middleware

の**いずれか**を持つことを強制する。持たないものは
**型付き inventory (`ControllerAuthorizationExemption` enum) + 理由文字列**への
明示登録を必須にし、未分類は fail させる。

#### 核心: 「合格条件に数えないもの」を先に決める

この gate の価値は**何を認可と認めないか**で決まる。以下は**認可ではない**ため
合格条件に数えない (数えると gate が形骸化する):

| 数えないもの | 理由 |
|---|---|
| `MembershipScopedOrganizationBinder` (`{organization}` binding) | **所属判定 = テナント境界**であって権限判定ではない。owner/admin/member を区別しない |
| `resolveOrganization` / `resolveOrganizationProject` / `resolveProjectItem` / `resolveCurrentOrganization` | 同上。これは「認可より前の 404」層 (不変条件 2) であって認可層ではない |
| `auth` / `verified` / `recent-auth` / `require-active-subscription` / `api-key.ability:*` middleware | 認証・鮮度・契約状態・トークン能力。いずれも**誰が何をしてよいか**を判定しない |
| `FormRequest::authorize()` | 本アプリでは**全 FormRequest が `return true;`** (実査済み。認可は controller の Gate に集約する既存規約)。数えると全 route が自動的に合格する |

#### 核心: 空振り (drift) を必ず落とす

`__invoke` / resource route / closure route の解決漏れは「候補 0 件で全 green」という
最悪の空振りを生む。以下を必須にする:

- 候補 route 件数が閾値未満なら fail (`ManageRouteAuthGuardTest` の `toBeGreaterThan(0)` を強化した形)
- ハンドラのソース取得は**正規表現ではなく Reflection** (`ReflectionMethod::getFileName/getStartLine/getEndLine`) で行い、
  **ソースが取得できなかった候補は「認可なし」ではなく即 fail** (解決失敗を合格側に倒さない)
- inventory / exemption の key が現存 route 名でなければ fail (stale 検出。`NestedRouteIdorDefenseTest` と同じ逆方向整合)

#### 追加: 「URL 整合 guard → 認可」の順序も同じ場所で固定する

不変条件 2 (子は親に属する = **認可より前に 404**) を守るため、
ハンドラ本体に inline guard マーカー (`resolveOrganizationProject` 等) と
authorize マーカーの**両方**がある場合、ソース上のオフセットで
**guard が authorize より前**であることを検証する。
実査時点で全 26 箇所が既に準拠しているため、これは新規制約ではなく**現行慣行の固定**である
(施策 2 で API 側に認可を足すときに順序を間違えないための構造的な歯止めでもある)。

### 施策 2: `Api\V1\ItemController` に `ItemPolicy` 経由の認可を追加

`store` / `update` / `destroy` に、URL 整合 guard の**後**で認可を追加し、
web 側 (`Projects\ItemController`) と viewer / editor 境界を揃える。

#### 核心: API では `Gate::authorize()` が使えない (実証済み)

`auth:api-key,api-oauth` は `Illuminate\Auth\Middleware\Authenticate` が
**通過した guard を default guard に昇格**させる (`$this->auth->shouldUse($guard)`)。
API キー経路では `ApiKeyGuard::user()` が返すのは **`App\Models\ApiKey`** であり `User` ではない。

実際に probe テストを走らせて確認した結果:

```
request_user => "App\Models\ApiKey"
auth_user    => "App\Models\ApiKey"
Gate::authorize('create', [Item::class, $project])
  => TypeError: App\Policies\ItemPolicy::create(): Argument #1 ($user)
     must be of type App\Models\User, App\Models\ApiKey given
```

つまり**素朴に `Gate::authorize` を書くと 403 ではなく HTTP 500 (TypeError) になる**。
正しい actor は `ResolveApiActor` が解決済みの `ApiActorContext::$user`
(API キー経路 = 発行者 `createdBy`、OAuth 経路 = トークン所有者。**非 null 保証**) であり、

```php
Gate::forUser($this->apiActor($request)->user)->authorize('update', $item);
```

の形にしなければならない。`ReadsApiActor` trait が既にこの context を読む正規経路として存在する。
**これが本施策の技術的な核心**であり、ここを外すと「認可を足したつもりで 500 を量産する」。

### 施策 3: 9 controller (15 route) の裁定を inventory に記録

各 route を「認可を足す」か「exemption + 型付き理由」かに 1 件ずつ裁定し、
enum + 理由文字列で `ControllerAuthorizationGateTest` に記録する (§裁定結果)。

### 施策 4: `tests/Feature/Api/V1/ItemAuthorizationTest.php` 新設

施策 2 の挙動を Feature で固定する:

- viewer (組織 member かつ project ロールなし) の API キー / OAuth トークンで `store`/`update`/`destroy` → **403**
- editor (組織 admin または `project_admin`) → **成功 (201/200/200)**
- **cross-org は 403 ではなく 404 のまま** (不変条件 2・情報漏洩防止)

---

## 裁定結果 (9 controller / 15 route)

「認可を足す」= 3 route (1 controller)、「exemption 登録」= 12 route (8 controller)。

| route | 裁定 | 理由 |
|---|---|---|
| `api.v1.projects.items.store` / `.update` / `.destroy` | **認可を足す** | web 側と権限境界が食い違う実害。`Gate::forUser($actor->user)` 経由で `ItemPolicy` を通す |
| `organizations.switch` | **exemption** (`MembershipIsTheAuthorization`) | `{organization}` は `MembershipScopedOrganizationBinder` が membership スコープで解決し、非所属は**認可より前に 404**。「所属組織なら誰でも自分の current org を切り替えてよい」が仕様であり、membership 判定が実質的な認可そのもの。ここに Policy を足すと**同じ条件の二重判定**になり、かつ 404 の存在秘匿を 403 に劣化させる危険がある |
| `organizations.store` | **exemption** (`NoAuthorizableSubject`) | 新規組織の作成。判定対象となる既存リソースが存在しない (誰でも自分の組織を作れる)。制約は `verified.or-back` と FormRequest のみ |
| `invitations.accept.store` | **exemption** (`TokenBearerIsTheSubject`) | 認可主体は「有効な招待トークンの保持者」。`OrganizationMembershipService::acceptInvitation` が token hash 照合と失効/期限/受諾済み判定を行う。**受諾前の user は対象組織の非メンバー**なので、組織 Policy を通すと構造的に必ず拒否になる |
| `settings.account.destroy` | **exemption** (`SelfScopedResource`) | 対象は `$request->user()` 自身のみ。他人のアカウントを指す経路が存在しない (route に対象 param なし)。step-up (`recent-auth`) が別軸の防御 |
| `recent-auth.password` | **exemption** (`SelfScopedResource`) | 自分の再認証鮮度の更新。認証そのものが主体判定であり、これを Policy で再判定する意味がない。`throttle:6,1` が総当り防御 |
| `notifications.open` / `.read` / `.read-all` | **exemption** (`SelfScopedResource`) | `NotificationCenterService::findOwnOrFail($user, ...)` が `$user->notifications()` 経由で解決するため cross-user は**構造的に 404** (存在オラクル封じ)。controller docblock が「open は認可判断 (Gate) を一切複製しない」と明示済みで、遷移先 `projects.manuals.show` が唯一の判断点。ここに Gate を足すと既存の設計意図に逆行する |
| `api.v1.me.session.revoke` | **exemption** (`ScopeIsTheAuthorization`) | 失効対象は actor 自身の OAuth session (`ApiActorContext::$oauthSessionId` と一致する 1 件) のみ。加えて `abort_unless($actor->hasScope(SessionRevoke), 403)` という**トークンスコープによる明示的な 403 判定**が既に存在する。Policy 対象となる他者リソースが存在しない |
| `contact.store` | **exemption** (`PublicUnauthenticated`) | 公開問い合わせフォーム (auth 不要)。認可すべき主体が存在しない。防御は `throttle:inquiry` + honeypot + reCAPTCHA |
| `webhooks.ses` | **exemption** (`SignatureVerified`) | SNS 署名検証 (`sns.signature` middleware) + TopicArn allowlist (fail-closed) が防御線。人間の actor が存在しない machine-to-machine 経路 |
| `debug.login-as` | **exemption** (`LocalOnlyDebugRoute`) | `app()->isLocal() \|\| runningUnitTests()` のときだけ**route 登録自体が起きない** fail-safe + `LocalOnly` middleware の二重防御。staging/production には存在しない |

`Auth\SocialAuthController` は `social.redirect` / `social.callback` とも **GET** のため
本 gate の候補 (変更系 verb) に入らない。裁定は「verb 対象外」として
inventory コメントに記録し、将来 POST 化されたら自動的に候補入りする。

---

## 期待効果

### 使命への貢献

- **AI-CUE の資産統制の一貫性**: SOP・シナリオ・撮影素材という現場資産に対し
  「誰が変更してよいか」が経路 (web / REST API / CLI) によらず同じになる。
  API 経由で撮影者 (viewer) が編集者 (editor) の権限を越えられる状態を塞ぐ
- **「思考ゼロ」を支える前提の保護**: 標準作業資産が意図せず書き換わらないことは、
  ナビ撮影の信頼性そのもの

### 具体的な改善見込み

- 変更系 route の認可漏れが**構造的に不可能**になる (新規 route は認可か明示裁定のどちらか必須)
- API 認可漏れ 3 本の是正 (viewer が Item を CRUD できる穴を塞ぐ)
- 「認可不要」判断 12 件が型付きで記録され、レビュー時に読める資産になる
- 不変条件 2 (認可より前に 404) の順序慣行が固定される

---

## 実装方針 (概要)

| 対象 | 変更 |
|---|---|
| `tests/Architecture/ControllerAuthorizationGateTest.php` | **新規**。候補抽出 → Reflection でハンドラ本体取得 → 認可マーカー判定 → inventory 照合 → drift ガード → 順序検証 |
| `app/Enums/Security/ControllerAuthorizationExemption.php` | **新規**。exemption 理由の型 (`NestedRouteDefenseMode` と同じ作法。`app/Enums/Security/` に既存の置き場がある) |
| `app/Http/Controllers/Api/V1/ItemController.php` | `store`/`update`/`destroy` に `Gate::forUser($actor->user)->authorize(...)` を URL 整合 guard の後に追加。`ReadsApiActor` trait を use |
| `tests/Feature/Api/V1/ItemAuthorizationTest.php` | **新規**。viewer 403 / editor 成功 / cross-org 404 |
| `docs/app-integration-guide.md` §7 | 不変条件として「変更系 route は認可か明示裁定」を追記 |

---

## 制約・前提

### 既存 Architecture テストとの関係 (層の分離)

```
リクエスト
  │
  ├─ [層1] 認証        auth / auth:api-key,api-oauth        … ManageRouteAuthGuardTest, ApiGuardAllowlistInvariantTest
  ├─ [層2] テナント境界 binder / scopeBindings / inline guard … NestedRouteIdorDefenseTest   ← 不整合は 404
  └─ [層3] 認可        Gate::authorize / Gate::forUser      … ★ ControllerAuthorizationGateTest (新設) ← 不足は 403
```

- 本 gate は **層 3 専任**。層 2 の手段 (binder / resolve*) を層 3 の合格条件に数えない、が設計の核心
- **層 2 → 層 3 の順序は不可侵**。cross-org は今後も **404** であり、
  認可を足したことで **403 になってはならない** (403 は「そのリソースが存在する」ことを漏らす)。
  施策 2 では `resolveOrganizationProject` / `resolveProjectItem` を通した**後**に
  `Gate::forUser(...)->authorize()` を置くことでこれを担保し、
  施策 1 の順序検証と `ItemAuthorizationTest` の cross-org 404 ケースで二重に固定する
- `NestedRouteIdorDefenseTest` の inventory は**変更しない** (層 2 の分類は据え置き)。
  両テストは inventory を共有せず、それぞれ自己完結する (テスト間の関数依存を作らない)

### 環境

- PostgreSQL (192.168.117.3 / 18.4) 利用可。`composer test` 実走可 (直近 2704 passed / 0 failed / 2 skipped)
- テストは T099 のグローバルロック経由で直列化される
- 本設計の技術的核心 (API 経路の `Auth::user()` が `ApiKey`) は
  probe テストを実走させて実証済み (§施策 2)

---

## 回帰の観点 (既存 API クライアントが 403 を受け始める)

施策 2 は**意図的な締め付け**であり、以下の後方非互換が発生しうる:

| 経路 | 影響 |
|---|---|
| API キー (`auth:api-key`) | 発行できるのは `manageApiKeys` 保持者 = 組織 owner / admin のみ。`ProjectPolicy::update` は owner/admin を許すため**通常は影響なし**。ただし**発行者が後から member へ降格**した場合、そのキーの write は 403 になる (これは是正であって退行ではない) |
| OAuth CLI トークン (`auth:api-oauth`) | CLI セッションは**組織メンバーなら誰でも**開始できる。`organization_member` かつ `project_admin` でない利用者は Item の write が **403 になる**。これが最大の非互換 |

対応方針:

- 403 は統一エラー envelope (`ApiErrorResource`) で返し、`api-key.ability` 不足と
  区別できるメッセージにする (「権限不足」であってトークンの問題ではないと伝える)
- 既存 Feature テスト (`tests/Feature/Api/ApiEndpointTest.php` / `IdempotencyTest.php` /
  `OAuthDualGuardTest.php`) は `createOrganizationWithOwner` 由来の owner を使っており
  実査上は影響しない見込みだが、**詳細設計で全件確認し、必要なら fixture を明示 editor 化**する
- リリースノート / `docs/app-integration-guide.md` §5 に権限境界を明記する

---

## スコープ外

- **GET route の認可** (`social.redirect` / `social.callback` を含む)。
  本 gate は「状態を変える route」に限定する。GET の認可漏れは別テーマ
  (情報開示の観点は `ProjectShowEmailVisibilityTest` 等が個別に担う)
- **vendor 所有 route** (Fortify / Cashier / Passport / Laravel MCP / Filament / Livewire)。
  パッケージ側が防御を担うため候補から構造的に除外する (`NestedRouteIdorDefenseTest` と同じ方針)
- **既存 46 route の認可内容の妥当性検証**。本 gate は「認可判断を 1 回通るか」の
  網羅性のみを見る (Policy のロジック正当性は各 Feature テストの責務)
- `ManageRouteAuthGuardTest` の廃止・統合。層が違うため並存させる
- `$this->authorize()` を使えるようにする (`AuthorizesRequests` trait の
  base Controller への追加)。実査の結果、本アプリの `App\Http\Controllers\Controller` は
  trait を持たず全箇所が `Gate::` ファサード経由で統一されている。
  gate は `$this->authorize` も**受理はする**が、追加はしない
- 新しい Policy の作成 (既存 `ItemPolicy` をそのまま使う)

