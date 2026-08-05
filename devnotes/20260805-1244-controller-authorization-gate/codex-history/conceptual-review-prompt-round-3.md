Round 2 の指摘への対応が完了しました。対応マトリクスと修正後の概念設計全文を示します。

# 対応マトリクス: conceptual-review Round 2

## [Warning] `can:` middleware は Controller より前に走るため、inline guard と併用すると「404 より先に認可」になる
- 判断: **対応する (指摘より踏み込んで、`can:` を受理対象から完全に外す)**
- 根拠: 指摘は完全に正しい。`can:` は route middleware なので、
  層 2 が **`scopeBindings` / route model binding** で完結している route では安全だが
  (`SubstituteBindings` は web group にあり `can:` より前)、
  本アプリで多用されている **controller の inline guard 方式**
  (`resolveOrganizationProject` 等) では `can:` の方が先に走り、
  cross-org が 404 ではなく 403 になる = 存在オラクルが開く。
  これは AGENTS.md 不変条件 2 の直接違反。
  Codex は 3 つの選択肢を提示したが、**実査で `can:` の使用箇所は 0 件**
  (`grep "'can:" routes/ app/` が空) であり、使っていない機構のために
  条件分岐ロジック (層 2 の完結性を route ごとに判定する) を書くのは
  思考原則「今必要なものだけ作る」に反する。
  受理対象から外すのが最も単純かつ最も厳格。
- 対応内容: 「#### 核心: 受理する認可手段は `Gate::` ファサード 1 系統だけにする」節を新設し、
  `can:` を「数えないもの」表にも追加。スコープ外にも「`can:` middleware の導入」を追記し、
  将来使いたくなったら層 2 の完結性検証とセットで gate を更新する、という
  意図的な設計判断を要求する構造にした。

## [Warning] `$this->authorize()` を無条件で合格扱いするのは誤合格要因
- 判断: **対応する (受理対象から外す)**
- 根拠: 指摘どおり。実査の結果 `App\Http\Controllers\Controller` は
  `abstract class Controller {}` の空クラスで `AuthorizesRequests` trait を use していない。
  つまり `$this->authorize()` は**呼べば致命的エラーになる**書き方であり、
  これを合格マーカーにすると「動かないコードで gate を通す」抜け道になる。
  Codex は「Reflection で trait 利用を確認する」案も提示したが、
  使用箇所 0 件のためロジックを足す価値がない。
- 対応内容: 受理形を `Gate::authorize` / `Gate::forUser(...)->authorize` の 1 系統に限定。
  スコープ外の記述 (旧「受理はするが追加はしない」) も矛盾していたので修正した。

## [Warning] Reflection で切り出した断片は PHP 開始タグが無く `T_INLINE_HTML` になる
- 判断: **対応する**
- 根拠: 指摘どおり。開始タグ無しで `token_get_all()` に渡すと全体が 1 個の
  `T_INLINE_HTML` になり、マーカー検出が全滅する
  (全 route が「認可なし」に倒れる = fail 側なので事故にはならないが gate が機能しない)。
  プロトタイプ実装で `token_get_all('<?php '.$fragment)` としたところ正しく動作した。
- 対応内容: 概念設計に開始タグ付与を**実装契約**として明記した。

## [Warning] 堅牢化した検出器で 61 本を再集計し、既存数値を盲目的に採用しないこと
- 判断: **対応する (実際に再集計を実施)**
- 根拠: 正しい指摘。検出器を変えたら分類結果が変わりうるので、
  設計段階で実測しておくべき。
- 対応内容: トークン化 + 開始タグ付与 + 定義ファイルによる所有権判定を実装した
  プロトタイプで再集計し、結果を概念設計に表として記載した。
  結果は素朴版と一致 (候補 61 / 認可あり 46 / 認可なし 15 / 解決失敗 0 / 順序違反 0)。
  加えて **「数値をテストに固定値として埋め込まない」**方針を明記した
  (埋め込むと route 追加のたびにテストが壊れる)。固定するのは
  「候補数の下限」「inventory の網羅性」「stale 検出」の 3 つだけ。

## 追加で自主的に強化した点 (Codex 指摘の派生)

### closure route の所有権判定
- Round 2 のプロトタイプ実行中に、変更系 **closure route が 3 本**存在することを発見
  (`api/v1/mcp` ×2、`storage.local.upload`)。
  当初の「`App\Http\Controllers\` 名前空間で所有権判定」だと closure を扱えず、
  `routes/web.php` に直接書いた変更系 closure を**取りこぼす**ことが判明した。
- 対応: 所有権判定を**名前空間一致から「Reflection で得た定義ファイルのパス」**に変更。
  `vendor/` 配下ならパッケージ所有として除外、それ以外は必ず分類が要る、
  `getFileName()` が取れなければ即 fail、とした。
  実査した 3 本の closure はいずれも vendor 定義
  (`vendor/laravel/mcp/src/Server/Registrar.php:45,47`、
  `vendor/laravel/framework/.../FilesystemServiceProvider.php:119`) のため本規則で正しく除外される。
  この規則は `__invoke` / resource route / closure を統一的に扱える点でも優れる。

## [Suggestion] 使命整合 / enum 配置の反論妥当性 / 効果表現 / スコープ / 型安全性 / exemption enum 構造
- 判断: 対応不要 (いずれも肯定的評価。enum の `app/Enums/Security/` 配置は妥当と認められた)


---

## 修正後の概念設計 (全文)

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

を持つことを強制する。持たないものは
**型付き inventory (`ControllerAuthorizationExemption` enum) + 理由文字列**への
明示登録を必須にし、未分類は fail させる。

#### 核心: 受理する認可手段は `Gate::` ファサード 1 系統だけにする

当初は `$this->authorize(...)` と `can:` middleware も受理する設計だったが、
実査の結果**いずれも受理してはならない**と判断した。

**`can:` middleware を受理しない理由 (不変条件 2 を壊すため)**

`can:` は **route middleware = Controller より前**に実行される。
本アプリのテナント境界 (層 2) には 2 方式あり、

- `scopeBindings` / route model binding … `SubstituteBindings` (web group) が `can:` より前 → 安全
- **controller の inline guard** (`resolveOrganizationProject` 等) … **`can:` より後** → **危険**

後者と `can:` を組み合わせると「**cross-org が 404 ではなく 403 になる**」= 存在オラクルが開く。
これは AGENTS.md の不変条件 2 (「不整合は認可より前に 404」) の直接的な違反である。
gate が `can:` を合格と認めると、この危険な構成を**推奨してしまう**。

実査では `can:` middleware の使用箇所は **0 件** (`grep "'can:" routes/ app/` が空)。
使っていないものを、危険な構成を許すために受理する理由がない
(思考原則「今必要なものだけ作る」)。将来 `can:` を使いたくなったら、
層 2 が routing 層で完結していることの検証とセットで gate を更新する、という
**意図的な設計判断を要求する**構造にしておく。

**`$this->authorize(...)` を受理しない理由 (構造的に到達不能なため)**

`App\Http\Controllers\Controller` は `abstract class Controller {}` の**空クラス**で、
`Illuminate\Foundation\Auth\Access\AuthorizesRequests` trait を **use していない** (実査済み)。
つまり `$this->authorize()` は現状**呼べば致命的エラーになる**書き方であり、
これを合格マーカーとして受理すると「動かないコードで gate を通す」抜け道になる。
本アプリは全 46 箇所が `Gate::` ファサードで統一されている。

結果として**受理する形は `Gate::authorize` / `Gate::forUser(...)->authorize` の 1 系統だけ**になり、
gate の判定はより単純かつ厳格になる。

#### 核心: 「合格条件に数えないもの」を先に決める

この gate の価値は**何を認可と認めないか**で決まる。以下は**認可ではない**ため
合格条件に数えない (数えると gate が形骸化する):

| 数えないもの | 理由 |
|---|---|
| `MembershipScopedOrganizationBinder` (`{organization}` binding) | **所属判定 = テナント境界**であって権限判定ではない。owner/admin/member を区別しない |
| `resolveOrganization` / `resolveOrganizationProject` / `resolveProjectItem` / `resolveCurrentOrganization` | 同上。これは「認可より前の 404」層 (不変条件 2) であって認可層ではない |
| `auth` / `verified` / `recent-auth` / `require-active-subscription` / `api-key.ability:*` middleware | 認証・鮮度・契約状態・トークン能力。いずれも**誰が何をしてよいか**を判定しない |
| `FormRequest::authorize()` | 本アプリでは**全 FormRequest が `return true;`** (実査済み。認可は controller の Gate に集約する既存規約)。数えると全 route が自動的に合格する |
| `can:` middleware | Controller より前に走るため inline guard 方式の route で**不変条件 2 を壊す**。使用箇所 0 件 (下記) |
| `$this->authorize()` | base Controller が `AuthorizesRequests` trait を持たず**構造的に呼べない** (下記) |

#### 核心: 空振り (drift) を必ず落とす

`__invoke` / resource route / closure route の解決漏れは「候補 0 件で全 green」という
最悪の空振りを生む。以下を必須にする:

- 候補 route 件数が**下限を下回ったら fail** (`ManageRouteAuthGuardTest` の `toBeGreaterThan(0)` を強化した形)。
  下限は「現在値そのもの」ではなく余裕を持たせた値にし、**上限は設けない** (route 追加を妨げない)
- ハンドラのソース取得は**正規表現ではなく Reflection** で行い、
  **ソースが取得できなかった候補は「認可なし」ではなく即 fail** (解決失敗を合格側に倒さない)
- inventory / exemption の key が現存 route 名でなければ fail (stale 検出。`NestedRouteIdorDefenseTest` と同じ逆方向整合)

##### 所有権判定は「名前空間」ではなく「定義ファイルの場所」で行う

`__invoke` / resource route / **closure route** を漏れなく扱うため、
ハンドラの所有権は `App\Http\Controllers\` の名前空間一致ではなく、
**Reflection で得た定義ファイルのパス**で判定する:

- `ReflectionMethod` (controller@method / `__invoke`) または
  `ReflectionFunction` (closure) から `getFileName()` を取る
- パスが **`vendor/` 配下 → パッケージ所有として候補から除外**
  (`NestedRouteIdorDefenseTest` の「パッケージ側が防御を担う」方針と同じ)
- それ以外 (`app/` / `routes/`) → **候補。必ず分類が要る**
- **`getFileName()` が取れない候補は即 fail**

この規則にすると、`routes/web.php` に直接書いた変更系 closure route も
自動的に候補入りする (名前空間判定では取りこぼす)。
実査では変更系 closure は 3 本あり (`api/v1/mcp` ×2 = `vendor/laravel/mcp`、
`storage.local.upload` = `vendor/laravel/framework`) **全て vendor 定義**のため、
本規則で正しく除外される。

#### 核心: 「誤って合格」させない (静的検出の堅牢性)

deny-by-default gate では**誤検出 (false negative) より誤合格 (false positive) が危険**。
素の文字列一致だと、コメント (`// 認可は controller の Gate::authorize が行う` は
本アプリの FormRequest に実在する定型文) や文字列リテラル・docblock を認可と誤認する。

- ハンドラ本体は **`token_get_all()` でトークン化し、`T_COMMENT` / `T_DOC_COMMENT` /
  `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` を除去してから**マーカーを判定する
- **Reflection で切り出したメソッド断片には PHP 開始タグが無い**ため、そのまま
  `token_get_all()` に渡すと全体が `T_INLINE_HTML` になり検出が全滅する
  (= 全 route が「認可なし」に倒れる。fail 側なので事故にはならないが gate が使い物にならない)。
  **`token_get_all('<?php '.$fragment)` の形で開始タグを付与**することを実装契約とする
- マーカーはトークン列パターンで見る
  (`Gate` `::` `authorize` / `Gate` `::` `forUser` … `->` `authorize`)

なお本 gate が保証するのは「**認可判断の呼び出しが入口に存在する**」ことまでである
(§期待効果の限界)。

##### 堅牢化した検出器での再集計 (実施済み)

上記の堅牢化 (トークン化 + 開始タグ付与 + 定義ファイル所有権判定) を実装した
プロトタイプで**実際に再集計した**。結果は素朴な正規表現版と一致した:

| 指標 | 値 |
|---|---|
| 変更系 route 総数 (vendor 込み) | 64 |
| うち vendor 定義 closure (除外) | 3 |
| **アプリ所有の候補** | **61** |
| 認可あり (`Gate::` 検出) | 46 |
| 認可なし (要裁定) | **15** |
| ソース解決失敗 (即 fail 対象) | **0** |
| 「guard → 認可」順序違反 | **0** |

コメント誤検出の懸念 (FormRequest の定型コメント等) は、候補が controller の
ハンドラメソッド本体に限定されるため実際には発火しなかったが、
**将来の誤合格を防ぐ構造としてトークン化は必須**とする。
数値 46/15 は設計時点のスナップショットであり、**テストに固定値として埋め込まない**
(埋め込むと route 追加のたびにテストが壊れる)。埋め込むのは
「候補数の下限」「inventory の網羅性」「stale 検出」の 3 つだけ。

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

`ApiActorContext::$user` は `public User $user` と**ネイティブ非 null 型**で宣言されており
(`ReadsApiActor::apiActor()` の戻り値型も `ApiActorContext`)、PHPStan level 10 で
`Gate::forUser()` に `User` を渡すことが型として保証される (production 型の補強は不要)。

#### Laratrust の team 文脈 (不変条件 5) は既に満たされている

`ProjectPolicy` は `$user->organizationRole($project->organization)` を呼び、
`User::organizationRole()` は `hasRole($role->value, $organization->laratrust_team_id)` と
**team id を明示**する (`config/laratrust.php` の `strict_check => true`)。
判定に使う組織は **URL 上の `{project}` から導出**され、
actor の `current_organization_id` には依存しない。
したがって API 経路 (current org の概念がない) でもそのまま正しく評価される。
この性質は Feature テストで明示的に固定する (§施策 4)。

### 施策 3: 9 controller (15 route) の裁定を inventory に記録

各 route を「認可を足す」か「exemption + 型付き理由」かに 1 件ずつ裁定し、
enum + 理由文字列で `ControllerAuthorizationGateTest` に記録する (§裁定結果)。

#### exemption の形骸化を防ぐ規律 (テストで強制する)

exemption は増えるほど gate を空洞化させる。以下を**テストで機械的に強制**する:

- exemption entry は `[route 名 => [enum, 理由文字列]]` の形を取り、
  **enum だけでは登録できない** (同じ enum を使い回す場合でも route ごとの具体的根拠が必須)
- 理由文字列は**空でないこと**に加え、**最低文字数**を課す
  (「同上」「N/A」の 1 語で埋める運用を機械的に止める)
- 理由には「何が代わりに守っているか」(=どの防御層・どの不変条件) を書く、を
  docblock の記入規約として明示する
- enum の各 case には「この分類を使ってよい条件」を docblock で定義し、
  `SelfScopedResource` / `NoAuthorizableSubject` のような**汎用的に見える分類ほど
  条件を狭く**書く (例: `SelfScopedResource` は「route に他者を指せる param が
  1 つも無い」ことを要件にする)

### 施策 4: `tests/Feature/Api/V1/ItemAuthorizationTest.php` 新設

施策 2 の挙動を Feature で固定する:

- viewer (組織 member かつ project ロールなし) の API キー / OAuth トークンで `store`/`update`/`destroy` → **403**
- editor (組織 admin または `project_admin`) → **成功 (201/200/200)**
- **cross-org は 403 ではなく 404 のまま** (不変条件 2・情報漏洩防止)
- **actor の `current_organization_id` が別組織でも、URL 上の `{project}` の組織で
  判定される** (Laratrust team 文脈が current org に汚染されないことの固定)
- API キー経路と OAuth トークン経路の**両方**で同じ境界になる
  (actor 解決が `ApiActorContext::$user` に一本化されていることの固定)

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
| `debug.login-as` | **exemption** (`LocalOnlyDebugRoute`) | **local / unit test 実行時のみ route が登録され、staging / production では route 登録自体が起きない** (`routes/web.php:594` の `if (app()->isLocal() \|\| app()->runningUnitTests())` による fail-safe) + `LocalOnly` middleware (local 以外 404 + Basic 認証 + 未設定 404) の二重防御 |

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

- 変更系 route に**認可判断も明示裁定も存在しない状態**を機械検出できるようになる
  (新規 route は認可か明示裁定のどちらか必須。§期待効果の限界も参照)
- API 認可漏れ 3 本の是正 (viewer が Item を CRUD できる穴を塞ぐ)
- 「認可不要」判断 12 件が型付きで記録され、レビュー時に読める資産になる
- 不変条件 2 (認可より前に 404) の順序慣行が固定される

### 期待効果の限界 (この gate が保証しないこと)

静的マーカー検出である以上、以下は**検出できない**。過信しないことを設計として明記する:

- 認可呼び出しはあるが**対象リソースが違う** (別の `$project` を渡している等)
- Policy が実質的に常に `true` を返す
- **誤った actor** を渡している (まさに施策 2 が扱う `Gate::authorize` vs `Gate::forUser` の差)

これらは **Feature テスト / Policy テストの責務**であり、本 gate の責務ではない。
本 gate の責務は「**認可判断の入口が存在しない route を 1 本も作らせない**」ことに限定する
(`NestedRouteIdorDefenseTest` が「分類漏れ・drift を落とす役割に限定する」と
docblock で宣言しているのと同じ責務設計)。

---

## 実装方針 (概要)

| 対象 | 変更 |
|---|---|
| `tests/Architecture/ControllerAuthorizationGateTest.php` | **新規**。候補抽出 → Reflection でハンドラ本体取得 → 認可マーカー判定 → inventory 照合 → drift ガード → 順序検証 |
| `app/Enums/Security/ControllerAuthorizationExemption.php` | **新規**。exemption 理由の型。配置理由は下記 |
| `app/Http/Controllers/Api/V1/ItemController.php` | `store`/`update`/`destroy` に `Gate::forUser($actor->user)->authorize(...)` を URL 整合 guard の後に追加。`ReadsApiActor` trait を use |
| `tests/Feature/Api/V1/ItemAuthorizationTest.php` | **新規**。viewer 403 / editor 成功 / cross-org 404 |
| `docs/app-integration-guide.md` §7 | 不変条件 + 新規変更系 route 追加時のチェックリストを追記 (下記) |

#### enum の配置先を `app/Enums/Security/` にする理由

`app/Enums/Security/` には既に **`NestedRouteDefenseMode` 1 件のみ**が存在し、
これも「Architecture テストからしか参照されないセキュリティ不変条件の語彙」である。
本アプリでは**セキュリティ不変条件の分類語彙を production の型として置く**という
先例が確立しており (AGENTS.md §セキュリティ不変条件が「すべて Architecture テストで
強制されている」と宣言している構造の一部)、tests 配下に置くと
`NestedRouteDefenseMode` と語彙の置き場が 2 箇所に割れる。
**先例踏襲 + 語彙の一元化**のため `app/Enums/Security/` に置く。

#### `docs/app-integration-guide.md` §7 に追記するチェックリスト

新しい POST / PUT / PATCH / DELETE route を足すときに踏む手順:

1. ハンドラ冒頭 (URL 整合 guard の**後**) に `Gate::authorize(...)` を置く。
   REST API v1 では `Gate::forUser($this->apiActor($request)->user)->authorize(...)`
2. 認可が不要なら `ControllerAuthorizationGateTest` の exemption inventory に
   enum + 具体的根拠 (何が代わりに守っているか) を登録する
3. 2+param route なら `NestedRouteIdorDefenseTest` の inventory にも防御方式を登録する
4. `composer test` で両 gate が green であることを確認する

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
  gate は `$this->authorize` を**受理しない** (§受理する認可手段は `Gate::` ファサード 1 系統だけにする)
- `can:` middleware の導入。不変条件 2 との実行順序を壊しうるため、
  受理も導入もしない。必要になった時点で層 2 の完結性検証とセットで別途設計する
- 新しい Policy の作成 (既存 `ItemPolicy` をそのまま使う)


---

上記を踏まえて再レビューしてください。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
