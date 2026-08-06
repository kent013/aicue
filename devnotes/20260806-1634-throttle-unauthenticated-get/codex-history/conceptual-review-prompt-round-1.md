# レビュー依頼: 概念設計 (throttle-unauthenticated-get)

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

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10 を通せるか

【本件固有の重点】
- 本件はセキュリティ不変条件 (流量制限の付与規約) の gate 拡張である。deny-by-default の
  母集団セレクタを広げ、19 本を「貼る / 免除」に分類する。
- 分類判断 (貼る 5 本 / 免除 14 本) が妥当か、免除カテゴリの定義が緩すぎないかを重点的に見よ。
- 新規に throttle を貼ることは**既存ユーザーの振る舞いを変える**。巻き添え (同一 NAT) の
  評価が妥当かを見よ。
- リポジトリの実ファイル (tests/Architecture/ThrottleCoverageInventoryTest.php,
  app/Support/Http/RouteThrottleBinder.php, app/Enums/Security/ThrottleCoverageExemption.php,
  routes/web.php, app/Providers/FortifyServiceProvider.php,
  app/Http/Controllers/Auth/SocialAuthController.php,
  app/Http/Controllers/Organizations/InvitationAcceptanceController.php) は読んでよい。
  設計の記述が実コードと食い違っていないかを確認せよ。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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
| `throttleCoverageExemptionCap()` | 14 | 26 |
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
- **キーに route parameter / query token を入れない**: `social.callback` の `{provider}`、
  `invitations.accept` の `?token=` を key に混ぜると bucket が分かれ、
  「429 になるまでの回数」が実在オラクルになる (`NamedRateLimiterKeyTest` の思想)。

### 4-3. 残り 14 本を exemption として分類する

新しい enum case を **1 つだけ**追加する:

```php
/**
 * 認証面の非変更系 (GET/HEAD) だが、応答が画面 / ステータスの描画にすぎない route。
 *
 * 適用条件 (すべて満たすこと):
 *  - HTTP メソッドが GET/HEAD のみ (変更系には適用しない)
 *  - 外部呼び出し・メール送信・重い計算・DB 書込を伴わない
 *  - 推測可能な秘密を開示しない (自セッションが既に保持する情報の再表示は可)
 *  - 副作用が自セッション (CSRF token / OAuth state 等) の中に閉じる
 */
case AuthViewRenderOnly = 'auth_view_render_only';
```

対象 14 本 (individual な 30 字以上の理由は詳細設計に全文を置く):

`login` / `register` / `password.request` / `password.reset` / `two-factor.login` /
`password.confirm` / `password.confirmation` / `recent-auth.confirm` / `recent-auth.status` /
`verification.notice` / `social.redirect` / `filament.admin.auth.login` /
`filament.admin.auth.profile` / `filament.admin.auth.multi-factor-authentication.set-up-required`

**カテゴリを 1 つに絞る理由**: enum の docblock が「汎用に見えるものほど適用条件を狭く」と
定めている。上の 4 条件はいずれも behavioral に検証できる (§4-5) ため、
「静的フォーム」「認証済み描画」「Filament ページ」に細分しても検証手段が同じで、
分類の情報量が増えない (思考原則 2)。

### 4-4. cap の更新 (14 → 26)

母集団 47 → 70 (+49%) に対し exemption は 11 → 25。
比率は 23.4% → 35.7% に上がるが、これは「増分 23 本のうち 19 本が
**認証済み or 未認証の画面描画**」という母集団の質の変化そのものであり、
セレクタが広すぎることの証拠ではない。cap は 26 (= 25 + 1 の余裕) とし、
**上げた根拠をテストのコメントに残す**。

### 4-5. exemption 前提の behavioral proof

`ThrottleExemptionPremiseTest` に `AuthViewRenderOnly` の前提を固定するテストを足す。
14 本すべてに個別テストを書くのではなく、**カテゴリの適用条件のうち最も壊れやすい 1 つ**
= 「**外部呼び出し・メール送信を伴わない**」を、代表 route への実リクエストで固定する。

- `Http::preventStrayRequests()` + `Mail::fake()` の下で
  `login` / `register` / `password.request` / `social.redirect` へ GET し、
  外向き HTTP 0 件 / メール送信 0 件で応答することを確認する。
- `social.redirect` を必ず含める (唯一「vendor SDK を呼ぶが外向き通信をしない」route であり、
  Socialite の実装変更で最初に崩れる)。

**DB クエリ 0 件は条件に入れない** — `register` は session に自分で置いた
`invitation_token` から prefill を解決するため DB read が 1 件発生する
(`OrganizationMembershipService::resolveRegisterPrefillEmail()`)。
条件を「DB 書込を伴わない」に留め、read が許される理由を個別 exemption の理由文に書く。

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
| `php artisan test tests/Architecture/ThrottleCoverageInventoryTest.php` | green (母集団 70 / exemption 25 / cap 26) |
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
| `social-callback` 10/min IP が同一 NAT 配下の一斉 SSO ログインを巻き添えにする | 展示会・現場 Wi-Fi で 11 人目以降がログインできない | 閾値は既存 `passkeys` guest 分岐と同値 (実運用で問題が出ていない値)。429 発生率を監視項目に入れる。詰みにはならない (1 分後に再試行可) |
| `invitation-accept` 10/min IP が一斉招待の同時クリックを巻き添えにする | 同一オフィスから 11 人目が招待リンクを開けない | 同上。招待リンクは消えないため 1 分後に再試行可 |
| `two-factor.*` GET への `10,1` が 2FA 設定画面のリロード連打を止める | 自分の設定画面が一時的に開けない | actor 自身のバケットであり他者への影響ゼロ。姉妹 POST と同値 |
| exemption 25 件で台帳が形骸化する | gate がハンコになる | cap を 26 で締める + 新カテゴリの前提を `ThrottleExemptionPremiseTest` で behavioral に固定する |
| S3 拡張により将来の認証面 GET 追加が必ず fail する | 開発時の摩擦 | それが deny-by-default の目的であり、意図した摩擦 |
