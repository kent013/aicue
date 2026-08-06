# 概念設計: 未認証 GET の認証面を流量制限の母集団へ (T120 事後監査 Warning の是正)

- 対象識別子: `throttle-unauthenticated-get`
- c2c feature: `path-based-throttle` (aicue は `aicue:T120` で v2 主要部を実装済み)
- 位置づけ: **新規追従ではなく、`aicue:T120` の事後監査が見つけた構造的な取りこぼしの是正**
- 裁定: `AG-096`「認証経路の流量制限を全リポジトリで必須とする」(閾値はプロダクト依存)

---

## 1. 仮説

**仮説**: `ThrottleCoverageInventoryTest` の保護対象群セレクタ S1/S3 が `$isMutating`
(method ∈ POST/PUT/PATCH/DELETE) を前提にしているため、**認証面の GET が 1 本も
deny-by-default の網に入っていない**。母集団を「認証面 = 全メソッド」へ広げて 1 本ずつ
分類させれば、(a) 実害のある未保護経路が機械的に炙り出され、(b) 残りは
「throttle が無いことが正しい理由」として文書化される。

**成功判定** (実装後に機械で確認できる形):

| 指標 | 現在 | 期待 |
|------|------|------|
| 保護対象群の件数 (testing env) | 47 | 70 |
| `throttleCoverageRouteFloor()` | 40 | 60 |
| 新規に throttle が付く route | 0 | 5 本 |
| exemption inventory 件数 | 11 | 25 |
| `throttleCoverageExemptionCap()` (全体) | 14 | 25 (exact fit) |
| case 別 exemption 上限マップ | 無し | 8 case 分を新設 (§4-4) |
| exemption 側の検査 | 3 本 | 5 本 (§4-5 で 2 本追加) |
| 認証面パターンの `social\.` が一致する route | 0 本 (死んだ条件) | 2 本 |

**この仮説が外れる形**: 母集団を広げた結果、増幅も総当り面も持たない描画 route ばかりが
20 本以上並び、exemption が inventory の大半を占めて形骸化する。→ §6 でこのリスクの
扱い (cap の根拠と、貼れるものは貼る方針) を明示する。

---

## 2. 現状 (実コードでの実査結果)

### 2-1. セレクタの実装

`tests/Architecture/ThrottleCoverageInventoryTest.php:172-202`

```php
$isMutating = array_intersect($mutating, $route->methods()) !== [];
// S1: 未認証で到達可能な可能性がある変更系
$s1 = $isMutating && ! throttleCoverageHasMiddlewareClass($route, Authenticate::class);
// S2: ステートレスな機械向け経路 (api/ oauth/ .well-known/oauth-)
$s2 = (...) && ! throttleCoverageHasMiddlewareClass($route, StartSession::class);
// S3: 認証済み側も含む credential 面
$s3 = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;
```

S1 と S3 が両方 `$isMutating` を要求し、S2 は URI 接頭辞で絞られる。よって
**認証面の GET/HEAD は S1/S2/S3 のどれにも入らない**。

### 2-2. 実測 (本セッションで再現)

`php artisan route:list --json` + 実アプリを bootstrap して `Router::gatherRouteMiddleware()`
で middleware を解決する検証スクリプト (devnotes 一時スクリプト) を `APP_ENV=testing` で実行:

```
現行母集団: 47      ← テスト内コメント「実測 47」と一致 (台帳・コメントとも正しい)
拡張後母集団: 70
増分: 23
```

増分 23 本の内訳 (ブリーフの列挙と完全一致):

| # | route 名 | 認証 | 既存 throttle |
|---|----------|------|--------------|
| 1 | `verification.verify` | auth | **`6,1`** (Fortify `limiters.verification` 既定) |
| 2 | `passkey.login-options` | PUBLIC | **`passkeys`** |
| 3 | `passkey.confirm-options` | auth | **`passkeys`** |
| 4 | `passkey.registration-options` | auth | **`passkeys`** |
| 5 | `social.callback` | PUBLIC | なし |
| 6 | `social.redirect` | PUBLIC | なし |
| 7 | `invitations.accept` | PUBLIC | なし |
| 8 | `login` | PUBLIC | なし |
| 9 | `register` | PUBLIC | なし |
| 10 | `password.request` | PUBLIC | なし |
| 11 | `password.reset` | PUBLIC | なし |
| 12 | `two-factor.login` | PUBLIC (guest) | なし |
| 13 | `filament.admin.auth.login` | PUBLIC | なし |
| 14 | `password.confirm` | auth | なし |
| 15 | `password.confirmation` | auth | なし |
| 16 | `recent-auth.confirm` | auth | なし |
| 17 | `recent-auth.status` | auth | なし |
| 18 | `verification.notice` | auth | なし |
| 19 | `two-factor.qr-code` | auth | なし |
| 20 | `two-factor.secret-key` | auth | なし |
| 21 | `two-factor.recovery-codes` | auth | なし |
| 22 | `filament.admin.auth.profile` | auth | なし |
| 23 | `filament.admin.auth.multi-factor-authentication.set-up-required` | auth | なし |

**ブリーフとの食い違い (重要)**:
ブリーフ「実装上の注意」は `passkey.login-options` を「同じ観点で該当しうるので実コードを見て判断する」
としているが、**実測では既に `throttle:passkeys` が付いている** (`config/fortify.php:124` の
`limiters.passkeys => 'passkeys'` により Fortify が付与)。同様に `verification.verify` も
Fortify 既定 `limiters.verification = '6,1'` により throttle 済み。
つまり **23 本のうち 4 本は既に保護されており、判断が要るのは 19 本**である。

### 2-3. `social.callback` の増幅を実コードで検証した結果

`app/Http/Controllers/Auth/SocialAuthController.php:77-92`:

```php
$intent = $request->session()->pull('social_auth_intent');
if (! is_string($intent) || ! in_array($intent, self::INTENTS, true)) {
    return redirect()->route('login')->withErrors([...]);   // ← ここで短絡
}
$socialiteUser = Socialite::driver($provider)->user();       // ← 外向き HTTP
```

さらに `vendor/laravel/socialite/src/Two/AbstractProvider.php:236-244` で
`hasInvalidState()` (session の `state` と query の `state` の hash_equals) が
**外向き HTTP より先**に走る。

したがって:

- **素の連打では外向き HTTP は 1 回も起きない** (intent 不在 / state 不一致で短絡)。
- 攻撃者が外向き HTTP を起こすには `GET /auth/{provider}/redirect/{intent}` を先に叩き、
  Location ヘッダから `state` を読んで callback に載せる必要がある
  = **2 リクエストで IdP への token エンドポイント POST 1 回**。
- `intent` も `state` も session から `pull` される (1 回で消費される) ため、
  ペアを崩せない。

**結論**: 増幅は実在するが、ブリーフ / 監査所見の「1 リクエストあたり IdP への外向き HTTP が
1 回発生する」は**誇張**である。正しくは「2 リクエストで 1 回、攻撃者が自由に反復できる」。
それでも **未認証・throttle なしで外部 IdP へ HTTP を発射できる唯一の経路**であり、
throttle を貼る根拠としては十分 (増幅比が 0.5 に落ちるだけで、質は変わらない)。

### 2-4. `invitations.accept` (GET) の未保護

`routes/web.php:600-606` / `app/Http/Controllers/Organizations/InvitationAcceptanceController.php:43-77`

- **GET (guest 可・throttle なし)**: query の `token` を `sha256` して
  `OrganizationInvitation` を 1 件引き、有効なら session へ token を保存して
  `register` へ 302、無効なら `Invitations/Invalid` を 200 で描画する。
- **POST (auth 必須・`throttle:10,1`)**: 同じ token 照合を行う。
  route コメントに「招待トークンは hash 照合されるが、**総当り試行そのものを有界にする**」と
  明記されている。

つまり**同じ token 照合を行う 2 本のうち、認証不要で応答分岐まで観測できる GET の方だけが
無制限**という非対称になっている。token は `Str::random(64)` (≈380bit) のため総当り自体は
非現実的だが、「未認証入力で 1 リクエスト 1 DB 参照 + 有効時は session 生成」が
無制限に踏める面であることに変わりはない。

### 2-5. 死んだ条件

認証面パターン (`:41-42`) の `social\.` は、`social.redirect` / `social.callback` が
2 本とも GET のため **現状 1 件も一致しない**。「social も見ている」という誤った安心を与える。
`invitations\.` も、変更系は `invitations.accept.store` 1 本のみが一致している
(GET の `invitations.accept` は視界外)。

### 2-6. 既存の周辺機構 (壊してはいけないもの)

| 機構 | 実体 | 本設計への影響 |
|------|------|--------------|
| 貼る仕組みの 3 段優先順 | `docs/app-integration-guide.md` §7b | 自前 route は第 1 段、Fortify route は第 3 段 (`RouteThrottleBinder`) |
| named limiter キー規約 | `RateLimiterKeyConventionTest` (`{レーン}:{種別}:{値}` を実評価) | 新 limiter は `rateLimiterKeyInventory()` への登録が必須 |
| limiter キーに route parameter を入れない | `NamedRateLimiterKeyTest` | `social.callback` の `{provider}`、`invitations.accept` の `?token=` を**キーに入れない** |
| exemption 前提の behavioral proof | `ThrottleExemptionPremiseTest` | 新カテゴリの前提も behavioral に固定する |
| inline throttle の適用条件 | AGENTS.md「認証済みかつ actor 自身に閉じる操作」限定 | 未認証面は必ず named limiter |
| `route:cache` 前提 | `RouteThrottleBinder::attachOnBooted()` は cached 起動で skip | Fortify route への新規付与も同じ経路 |

---

## 3. 課題

1. **セレクタの取りこぼし** — 認証面 GET 23 本が deny-by-default の外にある。
   結果として `social.callback` (外向き HTTP) と `invitations.accept` (未認証 token 照合) が
   無音で無防備なまま通っている。
2. **死んだ条件** — `social\.` が 1 件も一致せず、「見ている」という誤った安心を生む。
3. **設計文書の欠落** — `aicue:T120` の詳細設計は「秘密を返す GET」を後続 TODO B2 として
   明示的に外したが、**GET の認証面一般 / SSO callback については記述が無い**。
   `AG-096` に照らせば「なぜ今回入れないか」を残すべきだった。
4. **cap の追随** — 母集団を +49% 広げると exemption も増える。
   `throttleCoverageExemptionCap()` = 14 のままでは、正しい分類をしても gate が fail する。

---

## 4. 方針

### 4-1. セレクタ: S3 から `$isMutating` を外す (S1/S2 は触らない)

```php
// S3: credential 面 (認証済み側も含む)。★非変更系 (GET/HEAD) も母集団に入れる。
//     認証面は「読むだけ」の GET でも秘密の開示・外部呼び出し・状態生成を伴いうるため、
//     メソッドではなく **面** で母集団を取る。
$s3 = $name !== '' && preg_match($pattern, $name) === 1;
```

- S1 (未認証の変更系) と S2 (ステートレス機械向け) は**変更しない**。
  S1 まで GET へ広げると母集団が数百本になり、gate が exemption 台帳に埋もれて機能しなくなる
  (§5 の却下案 3)。
- `throttleCoverageRouteFloor()` を 40 → **60** に更新する
  (実測 70 に対し、機能フラグ無効化等での目減りを許容する余裕を持たせた値。
  現行の 47 に対する 40 と同じ比率感)。

### 4-2. throttle を新たに貼る 5 本

| route | 貼り方 | limiter | 閾値の根拠 (既存値を発明しない) |
|-------|--------|---------|------------------------------|
| `social.callback` | 第 1 段 (`routes/web.php`) | 新 named `social-callback` | 未認証で到達する認証面・IP 単位の既存本番値 = `passkeys` limiter の guest 分岐 **10/min** と同値 |
| `invitations.accept` | 第 1 段 (`routes/web.php`) | 新 named `invitation-accept` | 姉妹操作 `invitations.accept.store` の **`10,1`** と同値 |
| `two-factor.qr-code` | 第 3 段 (`RouteThrottleBinder`) | inline `10,1` | 姉妹 `two-factor.enable` / `.confirm` / `.disable` / `.regenerate-recovery-codes` と同値 |
| `two-factor.secret-key` | 第 3 段 | inline `10,1` | 同上 |
| `two-factor.recovery-codes` | 第 3 段 | inline `10,1` | 同上 |

- **未認証面に inline を使わない**: `social.callback` / `invitations.accept` は未認証で到達するため
  named limiter を新設し、キーを `{レーン}:ip:{値}` で明示する
  (フレームワーク既定キーへの暗黙依存を作らない = AGENTS.md §7b)。
- **`two-factor.*` GET 3 本に inline `10,1` を使える理由**: いずれも `auth` middleware 配下で
  **actor 自身の 2FA 秘密**しか返さない = 「認証済みかつ actor 自身に閉じる操作」に該当し、
  フレームワーク既定キー (user id) がちょうど求める数える単位になる。
- **誤読防止 (必須)**: `two-factor.qr-code` / `.secret-key` / `.recovery-codes` への `10,1` は
  **連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
  「throttle を貼ったから秘密 GET の保護は済んだ」と次に触る人が誤読すると、後続 TODO **B2**
  (recent-auth 化) が静かに落ちる。付与箇所の docblock と behavioral テスト名の両方に
  「回数上限であって認証強度ではない / 認証強度は B2」と明記する。
- **limiter closure の型**: 新 limiter は `fn (Request $request): Limit` で戻り値型を明示し、
  `$request->ip()` の `?string` を `?? 'unknown'` で潰す (既存 limiter と同じ書き方)。
  PHPStan level 10 を型の widen なしで通す。
- **キーに route parameter / query token を入れない**: `social.callback` の `{provider}`、
  `invitations.accept` の `?token=` を key に混ぜると bucket が分かれ、
  「429 になるまでの回数」が実在オラクルになる (`NamedRateLimiterKeyTest` の思想)。

### 4-3. 残り 14 本を exemption として分類する (新 enum case は 2 つ)

**`social.redirect` を「描画にすぎない route」に混ぜない**。これは OAuth state を生成して
外部 IdP へ遷移させる**認証フローの開始**であり、描画系と同じ箱に入れると
「GET だが認証フローを開始する route」を将来まとめて免除する穴になる。
よって case を 2 つに分ける (enum docblock「汎用に見えるものほど適用条件を狭く」に従う)。

```php
/**
 * 認証面の非変更系 (GET/HEAD) で、応答が画面 / ステータスの描画にすぎない route。
 *
 * 適用条件 (すべて満たすこと):
 *  - HTTP メソッドが GET/HEAD のみ (変更系には適用しない)
 *  - 外部呼び出し・メール送信・重い計算・**DB 書込**を伴わない (DB read は可)
 *  - 推測可能な秘密を開示しない (自セッションが既に保持する情報の再表示は可)
 *  - 副作用が自セッション (CSRF token / flash 等) の中に閉じる
 */
case AuthViewRenderOnly = 'auth_view_render_only';

/**
 * 認証フローを開始するが、その場では外向き通信を一切行わない非変更系 route。
 *
 * 適用条件 (すべて満たすこと):
 *  - HTTP メソッドが GET/HEAD のみ
 *  - **その場で外向き HTTP を発行しない** (発行するのは対になる完了経路)
 *  - 生成する状態が自セッション内に閉じ、他セッションから消費できない
 *  - **対になる完了経路が throttle 済みである** (増幅はそちらで有界化されている)
 */
case AuthFlowInitiationWithoutOutboundCall = 'auth_flow_initiation_without_outbound_call';
```

| case | 対象 | 件数 |
|------|------|------|
| `AuthViewRenderOnly` | `login` / `register` / `password.request` / `password.reset` / `two-factor.login` / `password.confirm` / `password.confirmation` / `recent-auth.confirm` / `recent-auth.status` / `verification.notice` / `filament.admin.auth.login` / `filament.admin.auth.profile` / `filament.admin.auth.multi-factor-authentication.set-up-required` | 13 |
| `AuthFlowInitiationWithoutOutboundCall` | `social.redirect` | 1 |

`AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目が
「`social.callback` に throttle を貼る」という本設計の施策に**構造的に依存**している点が重要で、
将来 callback の throttle を外すと exemption の前提が崩れる。
これは §4-5 の前提テストで behavioral に固定する。

### 4-4. cap の更新 (全体 14 → 25 + **case 別上限**)

母集団 47 → 70 (+49%) に対し exemption は 11 → 25。
比率は 23.4% → 35.7% に上がるが、これは「増分 23 本のうち 19 本が
**認証済み or 未認証の画面描画**」という母集団の質の変化そのものであり、
セレクタが広すぎることの証拠ではない。

ただし**全体 cap だけでは「どのカテゴリが膨らんだか」が見えない**。
新カテゴリ 13 件が将来 20 件・30 件へ増えても全体 cap の一言でしか止まらず、
レビュー時に「増えたのは描画系か、それとも本来貼るべきものが逃げたのか」を区別できない。
そこで **case 別の上限マップ**を併設する:

```php
/** @return array<string, int> ThrottleCoverageExemption::value => 上限 */
function throttleCoverageExemptionCapByCase(): array
```

| case | 現在 | 上限 | 上限の意味 |
|------|------|------|-----------|
| `static_metadata_response` | 4 | 4 | vendor が登録する OAuth メタデータ 4 本で固定 |
| `vendor_method_not_allowed_stub` | 2 | 2 | `GET|DELETE /api/v1/mcp` の 2 本で固定 |
| `session_teardown_only` | 2 | 2 | web / filament の logout 2 本で固定 |
| `local_only_debug_route` | 1 | 1 | `debug.login-as` のみ |
| `component_level_limiter` | 1 | 1 | `default-livewire.update` のみ |
| `signature_required_before_effect` | 1 | 1 | `storage.local.upload` のみ |
| `auth_view_render_only` | 13 | 13 | 認証面の描画 GET。**ここが膨らむ = 貼るべきものを逃がした疑い** |
| `auth_flow_initiation_without_outbound_call` | 1 | 1 | `social.redirect` のみ。増えたら必ず再設計 |

**上限は現在値ちょうど (exact fit) にする**。余裕を 1 でも持たせると、
その 1 本は「個別の behavioral proof も再レビューも無しに免除できる枠」になる。
exact fit なら 14 本目を足す作業が必ず「上限の数値を変える差分」として現れ、
個別理由・代表テストへの追加要否・そもそも貼るべきでないかの再検討を強制できる。

同じ理由で**全体 cap も 25 (exact)** とする (`array_sum()` にはせず独立の定数)。
全体はセレクタ全体の広さを、case 別は分類の偏りを見る。役割が違うので両方を検査する。

### 4-5. 検査の強化 (exemption の穴を 3 つ塞ぐ)

母集団を広げるだけでなく、**exemption 側の検査を 3 点足す**。
いずれも既存テストが構造的に見落としている点である。

1. **`ThrottleCoverageInventoryTest`**: exemption inventory の key は
   **throttle を 1 本も持たない**こと。
   現行は「throttle 1 本 → continue」で先に抜けるため、
   *throttle 済みなのに exemption にも登録されている* 状態を検出できない
   (stale 検出も「母集団に存在するか」しか見ない)。放置すると台帳に死んだ行が溜まる。
2. **`ThrottleCoverageInventoryTest`**: 新 2 case を使う entry は
   **非変更系 (GET/HEAD のみ) の route** であること。
   両 case の適用条件 1 番目を機械化する (`logout` 等の変更系がこの箱に落ちない)。
3. **`ThrottleExemptionPremiseTest`**: 新 2 case の前提を behavioral に固定する。

前提テストの内容 (14 本すべてに個別テストは書かない。**壊れやすい条件**だけを固定する):

| 検証 | 対象 | 方法 |
|------|------|------|
| 外向き HTTP 0 件 / メール送信 0 件 | `login` / `register` / `password.request` / `social.redirect` | `Http::preventStrayRequests()` + `Mail::fake()` の下で GET |
| **DB 書込 0 件** | 同上 | `DB::listen` で `insert` / `update` / `delete` / `truncate` で始まる SQL が 0 件 (read は許す) |
| `social.callback` が throttle を**ちょうど 1 本**持ち、その limiter が `social-callback` である | `social.callback` | `AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目 |

**`social.callback` の検査に使う判定点**: `RouteThrottleBinder::throttleEntries($router, $route)`
を使う。これは `Router::gatherRouteMiddleware($route)` の**解決後**の実効 middleware 列を
filter する実装 (`RouteThrottleBinder.php:171-174`) であり、「第 3 段の付与台帳」ではない。
したがって第 1 段 (`routes/web.php` 直書き) で貼った throttle も確実に見える
(`ThrottleCoverageInventoryTest` が母集団全体の判定に使っているのと同じ関数)。
その上で **entry 文字列の params 部が `social-callback` であること**まで固定し、
「throttle は付いているが別 limiter に差し替わっていた」を検出できるようにする。

**SQL 書込判定の頑健性について**: 先頭コメント / CTE 付き SQL では前方一致が崩れうる。
対象 4 route が発行する SQL は Eloquent / query builder 生成のもの (先頭コメント無し) に
限られるため前方一致で足りるが、判定関数は `ltrim()` してから
`insert|update|delete|truncate` を前方一致する形に切り出し、
**その判定関数自身の単体ケース** (先頭空白付き / `select` / `with ... insert`) を
同ファイル内に置いて、検出器が黙って壊れないようにする。

**DB read を 0 件にしない理由**: `register` は session に自分で置いた
`invitation_token` から prefill を解決するため DB read が 1 件発生する
(`OrganizationMembershipService::resolveRegisterPrefillEmail()`。token 不在なら DB へ到達しない)。
条件は「DB **書込**を伴わない」に留め、read が許される理由を個別 exemption の理由文に書く。

### 4-6. ドキュメント

`docs/app-integration-guide.md` §7b に、**セレクタが「面」で取ること**と
**認証面 GET の分類方針**を追記する (S1 は変更系のまま / S3 は全メソッド、という非対称の理由)。

---

## 5. 代替案と却下理由

| # | 案 | 却下理由 |
|---|----|---------|
| 1 | **S3 も GET も母集団に入れるが、`Authenticate` 付きは除く** (未認証 GET だけ 9 本追加) | exemption が 6 本増えるだけで済み一見魅力的だが、**`two-factor.qr-code` / `.secret-key` / `.recovery-codes` (秘密を返す GET) が視界外に残る**。今回まさに throttle を貼るべきと判定した 3 本を落とす案であり、ブリーフの与件 (23 本を母集団へ) からも外れる |
| 2 | **23 本すべてに throttle を貼る** | `GET /login` に IP 単位の throttle を貼ると、展示会・現場 Wi-Fi の同一 NAT 配下で正当な利用者が巻き添えで**ログイン画面すら開けなくなる**。gate の思想は「1 本ずつ貼る / 免除を決めさせる」であって全部貼ることではない |
| 3 | **S1 も `$isMutating` を外す** (全 GET を母集団へ) | 母集団が数百本になり、exemption 台帳が数百件に膨れる。deny-by-default の信号対雑音比が破壊され、gate が「通すためのハンコ」に堕ちる。認証面という**面**の定義があるからこそ 70 本で収まる |
| 4 | **exemption を prefix パターン (`filament.admin.auth.*` 等) で一括免除できるようにする** | 台帳の 1 行が将来追加される未知 route まで免除してしまい、deny-by-default が prefix 単位で穴になる。既存設計 (route ラベル 1 件 = 1 entry) を維持する |
| 5 | **`social.redirect` にも throttle を貼る** | 増幅 (外向き HTTP) は `social.callback` 側で有界化されるため、redirect を絞っても外向き HTTP の総量は減らない。session レコード生成は `GET /login` を含む全 web route と同質のコストであり、SSO 固有ではない。「今必要なものだけ作る」に倒して exemption とする |
| 6 | **`social-callback` limiter を session id でキーする** (巻き添えゼロ) | session は `social.redirect` を叩けば無限に取得できるため、攻撃者に対して実質無制限になる。巻き添え回避のために防御を無効化する取引は成立しない |
| 7 | **cap を上げず、exemption を減らすために `two-factor.*` 以外にも throttle を広げる** | §5-2 と同じ巻き添え問題。cap は「セレクタが広すぎないか」の検出器であって、貼る/貼らないの判断を歪める理由にしてはいけない |

---

## 6. スコープに入れないもの (と、その理由)

**この節は必須**である。前周回の監査 Warning は「落としたこと」ではなく
「落としたと書かなかったこと」に対する指摘だった。

| 除外するもの | 理由 |
|------------|------|
| **秘密を返す GET の recent-auth 化** (`two-factor.qr-code` / `.secret-key` / `.recovery-codes` への step-up 要求) | `aicue:T120` の後続 TODO **B2** として既に切り出し済み。本タスクは**流量制限の付与漏れ検査**の話であり、**認証強度**の話ではない。混ぜると「throttle を貼った = 秘密の保護が済んだ」という誤った完了感を生む。本タスクでは同 3 本に `10,1` を貼るが、これは**回数の上限**であって step-up の代替ではない |
| **429 応答の経路別契約** (Inertia / XHR / API での 429 の見せ方) | 別 feature `error-response-contract` の担当。新規 throttle 5 本の 429 応答も既定の `ThrottleRequests` 応答に従う |
| **閾値の家系統一** | `AG-096` が「閾値はプロダクト依存」と裁定済み。既存値 (`5,1` / `6,1` / `10,1` / `10/min`) を 1 つも変えない。新設 limiter にも**既存の同性質エンドポイントと同値**を充てる |
| **S1 (未認証の変更系) の GET 拡張** | §5 案 3。母集団が数百になり gate が機能しなくなる |
| **S2 (`api/` / `oauth/` / `.well-known/oauth-`) の変更** | 現行セレクタは URI 接頭辞 + `StartSession` 不在で取っており、メソッド前提を持たない = 今回の欠陥の対象外 |
| **`social.redirect` への throttle 付与** | §5 案 5。exemption として理由を残す |
| **Filament panel の GET 3 本への throttle 付与** | credential 検証は `default-livewire.update` (POST) 側にあり、そこは既に `ComponentLevelLimiter` として exemption 登録済み + component 内 `rateLimit(5)` が実在する (`ThrottleExemptionPremiseTest` が固定)。GET はページ描画のみ |
| **`ThrottleCoverageExemption` の既存 6 case / 既存 11 件の分類の見直し** | 母集団を広げる作業であって、既存分類を書き換える作業ではない (ブリーフ「実装上の注意」)。既存 11 件は 1 文字も触らない |
| **`RouteThrottleBinder` 本体の変更** | 新規付与はすべて既存の第 1 段 / 第 3 段の経路に乗る。binder のロジックに変更は要らない |
| **frontend (Svelte) の変更** | throttle は middleware 層で完結する。429 の UI 表現は上記 `error-response-contract` の担当 |

---

## 7. 期待効果 (使命への貢献)

AI-CUE の使命は「現場作業者が SOP から標準化されたマニュアル動画を作れるようにする」。
本改善は使命に直接寄与する機能ではなく、**使命を支える基盤の不変条件**の是正である:

- **SSO ログインが外部 IdP への増幅口になる状態を閉じる**。現場での導入は
  組織単位の SSO ログインが主動線になるため、この経路が攻撃者に自由に踏まれる状態は
  「サービスが止まる / IdP 側にレート制限で締め出される」という形で直接ユーザーに跳ね返る。
- **招待受諾 (GET) の未保護を閉じる**。組織へのメンバー招待は導入初期の必須動線であり、
  ここが未認証で無制限に踏める状態は運用上のノイズ源になる。
- **gate の誤った安心 (死んだ条件) を解消する**。「social も見ている」と読める条件が
  1 件も一致していない状態は、次に触る人を確実に誤らせる。

---

## 8. 検証方法

### 8-1. テストファースト (fail を先に確認する)

1. S3 から `$isMutating` を外すコミットを**単独で**当て、`ThrottleCoverageInventoryTest` を走らせる。
   → 「throttle が 1 本も無く exemption inventory にも未登録」が **19 本**列挙されて fail する
   (23 本 - 既に throttle 済み 4 本) ことを確認する。
2. その fail 出力を分類 (貼る 5 / 免除 14) の入力とする。

### 8-2. 機械検証コマンドと期待結果

| コマンド | 期待 |
|---------|------|
| `php artisan test tests/Architecture/ThrottleCoverageInventoryTest.php` | green (母集団 70 / exemption 25 / 全体 cap 25 exact + case 別上限) |
| `php artisan test tests/Architecture/RateLimiterKeyConventionTest.php` | green (新 limiter 2 本が inventory と一致し、キーが `{レーン}:ip:{値}`) |
| `php artisan test tests/Feature/Security/ThrottleExemptionPremiseTest.php` | green (新カテゴリの前提テストを含む) |
| `php artisan test tests/Feature/Security/AuthThrottleCoverageTest.php` | green (新規 5 本の behavioral proof を含む) |
| `php artisan test tests/Feature/Security/NamedRateLimiterKeyTest.php` | green |
| `php artisan route:cache && php artisan route:list && php artisan route:clear` | 例外なく往復する (`RouteThrottleBinder` の fail-fast と焼き込みの確認) |
| `composer phpstan` / `vendor/bin/pint --test` | green |

### 8-3. 振る舞いの回帰確認 (新規 throttle が正当利用者を壊さないこと)

- SSO ログイン 1 往復 (`social.redirect` → IdP → `social.callback`) が
  `social-callback` バケットを **1 だけ**消費する。
- 招待リンクのクリック 1 回が `invitation-accept` バケットを 1 だけ消費する。
- 2FA 設定画面の初期表示 (`two-factor.qr-code` + `.secret-key` の 2 リクエスト) が
  `10,1` の枠内に収まる。

---

## 9. 制約・前提

- **`php artisan route:cache` を毎デプロイ再生成すること**が前提条件
  (`RouteThrottleBinder` は cached 起動で後付けを skip する)。
  今回の Fortify route への新規付与 3 本も同じ経路に乗る。
- production は `TRUSTED_PROXIES` の明示宣言が必須 (T108)。IP 単位の新 limiter 2 本は
  この設定に依存する (`trustProxies(at: '*')` を復活させると総当りに無効化される)。
- テストは `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` は使わない。
- cache store はテスト時 `array` に強制 (`phpunit.xml`) されており、各テストでバケットは空から始まる。

---

## 10. リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| `social-callback` 10/min IP が同一 NAT 配下の一斉 SSO ログインを巻き添えにする | 現場 Wi-Fi・オフィス NAT で、同じ 1 分内に 11 人目以降が SSO ログインを完了できない | §10-1 |
| `invitation-accept` 10/min IP が一斉招待の同時クリックを巻き添えにする | 同一オフィスから 11 人目が招待リンクを開けない | §10-1 |
| `two-factor.*` GET への `10,1` が 2FA 設定画面のリロード連打を止める | 自分の設定画面が一時的に開けない | actor 自身のバケットであり他者への影響ゼロ。姉妹 POST と同値 |
| exemption 25 件で台帳が形骸化する | gate がハンコになる | 全体 cap 25 (exact fit) + case 別上限で締める + 新カテゴリの前提を `ThrottleExemptionPremiseTest` で behavioral に固定する |
| S3 拡張により将来の認証面 GET 追加が必ず fail する | 開発時の摩擦 | それが deny-by-default の目的であり、意図した摩擦 |

### 10-1. 未認証 IP レーン (10/min) の巻き添えリスクをどう扱うか

**正直な評価**: AI-CUE の想定現場 (朝礼後に作業者が一斉ログイン、導入時に管理者が一斉招待) では
「同一グローバル IP から 1 分内に 11 回の SSO callback / 招待リンク open」は**起こりうる**。
「起こらないから安全」とは言わない。

**それでも 10/min IP を採る理由**:

1. **詰みにならない**。429 は 1 分で解け、`Retry-After` ヘッダで再試行時刻が示される。
   - `social-callback`: **`login` / `register` の入口ページは throttle しない**ため
     「画面すら開けない」状態にはならない。止まるのは SSO の完了往復だけ。
   - `invitation-accept`: **こちらは入口ページそのもの** (`GET /invitations/accept`) を
     絞るため、11 人目は**招待リンクを開いた時点で 429 になり画面も出ない**。
     ここを「入口は開く」と書くのは誤りなので明記する。言えるのは
     **招待そのものは 429 で消費されず、通常は `Retry-After` 後に再試行できる**ことまでで、
     **到達を保証はしない** (共有 IP で継続的に枠が競合すれば、解除直後の枠を
     他の利用者に取られ続けうる。待機中に招待が失効・取消される可能性もある)。
   正確に言えるのは「**limiter 自体は恒久ロックを作らない**」ことであり、
   「必ず開ける」ではない。共有 IP 配下の一時的な DoS が成立する余地は残る
   (これが §10-1 末尾の監視要件を運用契約にする理由である)。
2. **閾値を発明しない** (`AG-096` / AGENTS.md §7b)。未認証の認証面 IP レーンで本番稼働中の
   最大値が `passkeys` guest 分岐の 10/min であり、これ以上の値は本リポジトリに前例がない。
   前例のない値を「巻き添えが怖いから」で発明すると、gate の閾値規律が崩れる。
3. **キーの単位を変える代替が成立しない**。session id でキーすると
   `social.redirect` を叩くだけで新しい bucket を無限に取れるため、攻撃者に対し実質無制限になる
   (§5 案 6)。provider / token をキーに混ぜると存在オラクルになる (`NamedRateLimiterKeyTest`)。

**運用要件 (実装者への申し送り)**:

- `social-callback` / `invitation-accept` の **429 発生率を監視項目に入れる**
  (既存の webhook レーンと同じ扱い。`docs/app-integration-guide.md` §7b の
  「429 発生率を監視する」運用に 2 レーンを追加する)。
- **実測で巻き添えが出たときの初動は「閾値を上げる」ではない**。まず
  `TRUSTED_PROXIES` / 実 client IP の解決が正しいか (`docs/trusted-proxies-runbook.md`) を疑う。
  IP が実質 1 個に潰れていれば、閾値をいくら上げても同じことが起きる。
  それが健全な上で不足するなら、**プロダクト判断として閾値変更の TODO を起票する**
  (`AG-096` は閾値をプロダクト依存と裁定しており、エージェント判断で動かさない)。
