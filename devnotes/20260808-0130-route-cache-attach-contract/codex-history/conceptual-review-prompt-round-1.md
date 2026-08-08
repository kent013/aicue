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

（アプリの使命・禁止事項は上に挿入済み）

【この設計の性質 — 誤読しないこと】
- これは**振る舞いをほぼ変えない是正**である。現状の保護は route:cache 生成時の焼き込みで実効しており、緊急のセキュリティ修正ではない。
- 直すのは「誤った機序の記述が次の担当を誤らせ、運用要件を隠している」ことである。
- 「後付け実装そのものの方式変更」は実測に基づき**スコープ外と確定済み**（既存の目録検査群との整合を壊すため）。方式変更を提案しないこと。
- 本リポジトリには**デプロイ基盤（deploy/ / terraform / k8s / CI デプロイ job）が存在しない**。存在しない基盤のための仕組みを作るのは思考原則 2（今必要なものだけ作る）違反である。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 禁止事項に抵触していないか（特に「テストなしの実装完了」「やたらに複雑な案」）
3. 実現可能性: 技術的に実現可能か（Laravel 12 / PHP 8.4）。特に施策 B（fail-fast の作法揃え）が過去の事故（cached 起動で route:list が必ず落ちた aicue:T120）を再発させない形になっているか
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（feature flag off の正常系を fail-fast で巻き込まないか等）
6. スコープの適切さ: 過大または過小になっていないか。**過剰な機構を足す方向の指摘は、思考原則 2 との整合を自分で示してから出すこと**
7. 型安全性: PHPStan level 10 を通せるか

【特に判断を求める 3 点】
(1) 施策 B（silent no-op を廃し fail-fast へ揃える）を採るべきか。採らず docblock 修正だけで済ませる案と比較して、どちらが妥当か。
(2) デプロイ基盤が存在しない件の扱い。本設計は「記述として残す（AGENTS.md の運用要件ブロック + guide §7c）」を選んでいる。これで十分か、過剰か、不足か。
(3) 機械検査の線引き。本設計は「純粋関数の振る舞いテスト」と「後付け経路の deny-by-default 目録」の 2 つだけを作り、docblock 文面の検査と起動時 cache 鮮度検査は作らないと決めている。この線引きは妥当か。**検査を作ること自体が目的にならないよう**、追加検査を提案する場合はその検査が防ぐ具体的な失敗シナリオと発生頻度の見積もりを添えること。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---
## 概念設計

# 概念設計: route-cache-attach-contract

> 一次入力: `devnotes/20260808-0130-route-cache-attach-contract/recon-brief.md`
> (2026-08-08 に独立 2 系統で実測した確定事実)。
> 本設計者も vendor / アプリコードを再読して裏取り済み (下記「自分で取った裏」)。

## 背景・課題

vendor (Fortify / laravel/passkeys) が登録する named route へ、アプリ側が boot 後に
middleware を後付けする経路が **3 系統**ある:

| # | 後付け元 | 対象 | 付ける middleware |
|---|---|---|---|
| 1 | `RouteThrottleBinder::attachOnBooted()` (`FortifyServiceProvider` / `AppServiceProvider` から) | Fortify 12 route + `cashier.webhook` | `throttle:{limiter}` |
| 2 | `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` | `two-factor.*` 6 本 + `user-profile-information.update` | `recent-auth` / `recent-auth.on-email-change` |
| 3 | `PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes()` | `passkey.*` 7 本 | `throttle:passkeys` / `recent-auth` / `ensure-login-method` / `no-store` |

この 3 系統は **同じ機序**で動いているのに、**docblock の説明が食い違っている**。

- 1 (`RouteThrottleBinder`) は「cached 起動では named route を 1 本も解決できないので skip する。
  実効になるのは `route:cache` 生成時の焼き込み。よって毎デプロイ再生成が前提条件」と
  **正しく**書いてある (T120 の事故後に是正済み)。
- 2 (`FortifyServiceProvider` L232-234) と 3 (`PasskeyServiceProvider` L129) は
  「route:cache 下でも `CompiledRouteCollection` の `nameCache` が同一 instance を返すため
  dispatch にも有効」と書いてある。**これは誤り**。

### 誤りの正体 (「結論は合っているが理由が違う」)

`nameCache` の性質の記述それ自体は正しい (`CompiledRouteCollection::getByName()` は
`nameCache` に memoize し、`match()` はその `getByName()` を通る)。誤っているのは
**前提**である — この callback は compiled route collection に**到達しない**。

1. `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を飛ばす
   (`vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php`)。
   Fortify (`FortifyServiceProvider::configureRoutes()`) も passkeys
   (`PasskeysServiceProvider::registerRoutes()`) もこれを使う。
   → **cached 起動では対象 named route がそもそも登録されない**。
2. framework の `RouteServiceProvider::register()` は `$this->booted(...)` の中で
   `loadCachedRoutes()` を呼び、それが**さらに** `$this->app->booted(fn () => require cached routes)`
   を積む。`withRouting()` 経由で最後に boot されるため、この app-booted callback は
   アプリ provider の `$app->booted()` **より後**に走る。
   → 後付け callback の時点で compiled collection はまだ読まれていない。
3. `Router::setCompiledRoutes()` は `new CompiledRouteCollection(...)` を作って
   `$this->routes` と container の `'routes'` instance を**丸ごと差し替える**。
   → 仮に触れていても捨てられる (二重の理由で効かない)。

結果、`appendMiddlewareIfMissing()` の `$route !== null` ガードが **無音 no-op** する。
直接証拠は boot 完了直後の `CompiledRouteCollection::$nameCache` が **0 件**であること
(後付けが compiled collection に一度も触れていない)。

### それでも保護が効いている理由

`RouteCacheCommand::handle()` は先頭で `route:clear` してから
`getFreshApplicationRoutes()` で **cache 無しのアプリを再 bootstrap** する。そこでは
`loadRoutesFrom()` が require を通すため後付けが**完全に走り**、付与済み middleware が
そのまま cache へ**焼き込まれる** (実測: `two-factor.qr-code` の attributes に `recent-auth`、
cache 全体で 33 箇所)。正規 cache での cached 起動では 2FA step-up テスト 11 本が green。

**壊れるのは stale cache のときだけ**である。剥がした cache での実 HTTP 実測:
鮮度切れセッションで 2FA 秘密 GET が **409 でなく 200 で秘密を返す**、`force=true` の
enable も 200 で通る、`passkey.destroy` の 429 が消えて 404 になる。

### したがって課題は 3 つ

1. **誤った機序の記述**が次の担当を誤らせる (「cached でも効くのだから安心」と読める)。
2. **運用要件が隠れている**。`php artisan route:cache` の毎デプロイ再生成は
   throttle だけでなく **recent-auth / ensure-login-method / no-store の前提条件**でもあるのに、
   `docs/app-integration-guide.md` では**流量制限の節 (§7b) にしか書かれていない**。
   T124 の 2FA step-up がこの要件に乗っていることが読み取れない。
3. **無音の no-op が残っている**。cached 起動で 1 本も引けないのは正常だが、
   非 cached で引けないのは「vendor が route 名を変えた = 無防備」であり、
   両者を同じ `$route !== null` で黙って畳んでいる。1 (binder) は既にこの 2 事象を
   分離しているので、家系内で作法が割れている。

## 改善アイデア

**振る舞いを変えないのが原則**。現状の保護は焼き込みで実効しており、緊急のセキュリティ
修正ではない。直すのは記述と作法である。ただし 3 (無音の no-op) だけは**意図的に振る舞いを
変える** — 非 cached で route が引けない場合に fail-fast する。以下 4 施策。

### 施策 A: 誤った docblock の是正 (振る舞い不変)

`FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` と
`PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes()` の docblock を、
`RouteThrottleBinder` と同じ **2 事象分離**で書き直す:

- **生成時** (`route:cache` 実行時) = `route:clear` 後の再 bootstrap で後付けが完全に走り、
  cache へ焼き込まれる。route 名が消えていればここでデプロイが止まる。
- **起動時** (cached 起動) = 対象 named route が未登録のため後付けは **1 本も効かない**
  (`loadRoutesFrom()` の require skip + `setCompiledRoutes()` の差し替えの二重)。
- ゆえに **`route:cache` の毎デプロイ再生成が T124 保護の前提条件**である。

あわせて Passkey 側には**区別**を明記する: 同じ booted callback 内の
`Route::bind('passkey', SelfScopedPasskeyBinder::class)` は `Router::$binders`
(route collection とは別の連想配列) への登録であり、collection 差し替えの影響を受けない。
**cached 起動でも有効**。「callback ごと無効」と一括りに誤読させない。

### 施策 B: 後付け作法の統一 (silent no-op の廃止)

2 provider に重複している private `appendMiddlewareIfMissing()` を、
`RouteThrottleBinder` と**同じ形**の共有 helper へ寄せる:

- 公開入口 `attachOnBooted(Application $app, array $specs): void` が
  `$app->booted()` の中で `$app instanceof CachesRoutes && $app->routesAreCached()` を評価し、
  **skip 判定を引数で渡す**。
- 実体 `attachAll(Router $router, array $specs, bool $routesAreCached): void` は
  `$routesAreCached` なら理由コメント付きで **early return** (純粋関数)。
- 非 cached で route が引けなければ **`RuntimeException` で fail-fast**
  (vendor が route 名を変えた = 無防備なまま公開される事故を止める)。
- feature flag で route ごと消える正常系を fail-fast させないため、spec は
  `throttledFortifyRoutes()` と**同じ形**で `feature` 条件を持つ
  (`Features::twoFactorAuthentication()` / `Features::updateProfileInformation()` /
  `Features::passkeys()`)。無効なら対象から外す。

**T120 を踏まない根拠**: 例外を投げうるのは `$routesAreCached === false` の枝だけであり、
cached 起動 (= `route:list` / 本番起動) では判定より前に early return する。
判定を引数で受けることで「cached 相当を渡したら 1 本も触らず例外も投げない」ことを
**純粋関数のテストで直接固定できる**。

### 施策 C: 運用要件を「後付け機構全体の前提条件」へ格上げ

- `docs/app-integration-guide.md` に **§7c「vendor route への後付け機構と route:cache の契約」**
  を新設し、route:cache 要件の記述をそこへ移す (§7b は throttle 固有の話に戻し、§7c を参照する)。
  対象は throttle / recent-auth / ensure-login-method / no-store の**全部**であると明記。
- `AGENTS.md` の `TRUSTED_PROXIES` 運用要件ブロック (T108) の**隣**に、同じ形式で
  route:cache 運用要件ブロックを置く。**デプロイ基盤が未整備であることを明記**し、
  「デプロイ基盤を作る PR は本要件を実装してからでないと完了にできない」と書く。
- ドメイン固有規約 5 の既存記述 (「毎デプロイ再生成する」) は残し、**対象が throttle だけでない**
  ことが読めるよう §7c を指すようにする。

**新しい仕組みは作らない**。今存在しないデプロイ基盤のために preflight コマンドや
起動時の cache 鮮度検査を作るのは AGENTS.md 思考原則 2 に反する (詳細はスコープ外の節)。

### 施策 D: 機械で守れるところだけ守る

「docblock の主張が実際の機序と一致している」ことは機械検査できない。検査するのは
**それの代理になる 2 点だけ**にする:

1. **純粋関数の振る舞いテスト** (禁止事項 1 により必須):
   - `routesAreCached: true` を渡すと **1 本も middleware を足さない / 例外も投げない**
     (= T120 の恒久回帰)。
   - `routesAreCached: false` で対象 route が存在しないと `RuntimeException`。
   - 冪等: 既に付いている alias を二重に足さない。
2. **後付け経路の deny-by-default 目録** (Architecture テスト):
   `app/` 配下で「起動後に route collection から named route を引いて middleware を足す」
   コードを持ってよいのは **`RouteThrottleBinder` と新 helper の 2 クラスだけ**とし、
   それ以外の出現を token 走査で fail させる。
   → 4 本目の後付け経路が旧作法 (無音 no-op) や生の inline 実装で足されるのを止める。
   これは T120 で実際に起きた事故 (cached 起動で `route:list` が必ず落ちる) の再発防止であり、
   新しい検査文化の発明ではない (本リポジトリの deny-by-default 目録は既に 30 本弱ある)。

**作らない検査**を明記する:
- docblock の文面と機序の一致検査 (自然言語の主張は機械で照合できない)。
- 起動時の route cache 鮮度検査。**原理的に判定できない** — 本番デプロイは全ファイルを
  新規展開するため mtime は揃い、cache が古いソースから作られたかは起動時からは見えない。
  「作れるが作らない」ではなく「正しく作れない」ものを置かない。

## 期待効果

- **使命への貢献**: 撮影 PWA の主戦場はスマホで、2FA 秘密の露出 / 第二要素の差し替え /
  passkey 削除は「現場作業者のアカウントが乗っ取られたときの被害」を直接左右する。
  この保護が **stale cache のときだけ無音で外れる**ことが読み取れる状態にすることは、
  「専門知識ゼロの現場作業者でも安全に使える」ための最低条件。
- 次の担当が「cached 起動でも効く」と誤読しない (家系 3 系統の記述が 1 つの機序に揃う)。
- 4 本目の後付け経路が T120 の事故を再発させない。
- デプロイ基盤を作る人が、要件を知らずに作ることを防ぐ (AGENTS.md の運用要件ブロック)。

## 実装方針 (概要)

| 対象 | 変更内容 |
|---|---|
| `app/Providers/FortifyServiceProvider.php` | docblock 是正 / private `appendMiddlewareIfMissing()` を共有 helper 呼び出しへ置換 / spec に feature 条件 |
| `app/Providers/PasskeyServiceProvider.php` | 同上 + `Route::bind` が cached でも有効である旨の区別を追記 |
| `app/Support/Http/RouteMiddlewareBinder.php` (新規) | skip 判定を引数で受ける純粋関数 + booted 配線の唯一の入口 |
| `app/Support/Http/RouteThrottleBinder.php` | **変更しない** (契約記述は既に正しい。§7c への参照だけ 1 行足すか検討) |
| `docs/app-integration-guide.md` | §7c 新設 / §7b から参照 |
| `AGENTS.md` | 運用要件ブロック追加 / ドメイン固有規約 5 から §7c を指す |
| `tests/Unit/Support/Http/RouteMiddlewareBinderTest.php` (新規) | 純粋関数の振る舞い固定 |
| `tests/Architecture/PostBootRouteMutationInventoryTest.php` (新規) | 後付け経路の deny-by-default 目録 |

既存の目録検査 (`ThrottleCoverageInventoryTest` / `RecentAuthRouteTest` /
`TwoFactorStepUpInventoryTest` / `PasskeyRouteProtectionTest` / `InlineThrottleInventoryTest`) は
**1 行も変更しない**。これらは非 cached レーンで走るため、施策 B のあとも同じ結果になる
(付与内容が変わらないことの回帰になる)。

## 制約・前提

- **後付け方式そのものは変えない**。焼き込み方式のままにする (recon-brief「やらなくてよいこと」)。
  無理に変えると T120 / T121 で固めた目録検査との整合を壊す。
- 施策 B は**唯一振る舞いを変える施策**である。変わるのは「非 cached 起動で対象 route が
  引けないとき、無音で素通りしていたのが起動時例外になる」ことだけ。
  feature flag off の正常系を巻き込まないよう spec の `feature` 条件で防ぐ。
- PHPStan level 10 / Pest / `declare(strict_types=1)` / 日本語コメントは既存どおり。
- フロント差分はゼロ (TypeScript / Inertia Props / DS token に波及なし)。

## スコープ外

- **後付け実装の方式変更** (compiled collection への後付け、cache 生成時 hook など)。
- **デプロイ基盤の新設**・`deploy:preflight` 相当のコマンド・CI での `route:cache` 検証。
  今存在しない基盤のために仕組みを作るのは思考原則 2 に反する。**記述として残す**のが本設計の答え。
- **起動時の route cache 鮮度検査** (上記のとおり原理的に判定できない)。
- 閾値・limiter・middleware 構成の変更 (振る舞い不変)。
- `RouteThrottleBinder` の一般化 / 新 helper との統合。throttle 側は形式検証・二重付与検出・
  `computedMiddleware` 破棄という固有責務を持ち、統合すると禁止事項 6 (やたらに複雑な案) に触れる。

## 自分で取った裏 (recon-brief の再検証)

| 主張 | 確認した実体 |
|---|---|
| cached で require を飛ばす | `Illuminate\Support\ServiceProvider::loadRoutesFrom()` の `if (! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) { require $path; }` |
| Fortify / passkeys がそれを使う | `FortifyServiceProvider::configureRoutes()` / `PasskeysServiceProvider::registerRoutes()` |
| compiled 読み込みが後 | `RouteServiceProvider::register()` の `$this->booted(...)` → `loadCachedRoutes()` → `$this->app->booted(fn () => require ...)` の二段 |
| collection 丸ごと差し替え | `Router::setCompiledRoutes()` が `new CompiledRouteCollection` を作り `container->instance('routes', ...)` |
| nameCache の性質自体は正しい | `CompiledRouteCollection::getByName()` L200-211 の memoize / `match()` L116-130 が `getByName()` を通る |
| 焼き込みが実効の理由 | `RouteCacheCommand::handle()` が `route:clear` → `getFreshApplicationRoutes()` |
| `Route::bind` は別経路 | `Router::bind()` が `$this->binders[...]` に入れる (route collection ではない) |
| feature flag で route が消える | `vendor/laravel/fortify/routes/routes.php` の `Features::enabled(Features::passkeys())` / `twoFactorAuthentication()` 分岐、`config/fortify.php` の「この 1 行が実質的なキルスイッチ」 |
| T120 の事故 | `docs/TODO-closed.md` T120 行「binder の callback 時点で named route が 1 本も解決できず `route:list` が必ず RuntimeException で落ちた」 |


## 一次入力（実測ブリーフ）

# 実査ブリーフ: route:cache 起動での middleware 後付けの契約是正

> 2026-08-08 の実測で確定した事実に基づく。lctl 台帳の
> `route-cache-safe-middleware-attach` (新規 feature) と家系全体の agenda に対応する。

## 確定した事実 (独立 2 系統の実測が完全に一致。confidence: high)

**cached 起動では booted callback からの後付けは 1 本も効かない。**

機序 (vendor 実読で完全に閉じている):
1. `Illuminate\Support\ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき
   require を飛ばす。Fortify (`vendor/laravel/fortify/src/FortifyServiceProvider.php:228`) と
   Passkeys (`vendor/laravel/passkeys/src/PasskeysServiceProvider.php:68`) はこれを使う。
   → **cached 起動では対象の named route がそもそも登録されない**。
2. したがって `getByName()` は null を返し、`appendMiddlewareIfMissing()` は
   `$route !== null` ガードで**無音 no-op** する。
3. さらに `Router::setCompiledRoutes()` が collection を新品へ丸ごと差し替えるため、
   仮に触れていても捨てられる。

直接証拠: boot 完了直後・probe が getByName する前の
`CompiledRouteCollection::$nameCache` が **0 件** (後付けが compiled collection に
一度も触れていないことの証明)。

**にもかかわらず保護は効いている。理由が違う。**
`RouteCacheCommand::handle()` が先頭で `route:clear` してから cache 無しで再 bootstrap するため、
そこで後付けが完全に走り **cache へ焼き込まれる** (実測: `two-factor.qr-code` の attributes に
`recent-auth`、cache 全体で 33 箇所)。正規 cache での cached 起動では 2FA step-up テスト 11 本が green。

**stale cache のときだけ無音で外れる。** 剥がした cache での実 HTTP 実測:
鮮度切れセッションで 2FA 秘密 GET が **409 でなく 200 で秘密を返す**、
`force=true` の enable も 200 で通る、passkey.destroy の 429 が消えて 404 になる。

## どの記述が誤りか

| ファイル | 記述 | 判定 |
|---|---|---|
| `app/Support/Http/RouteThrottleBinder.php` L23-29 / L50-69 | 「cached 起動では named route を 1 本も解決できない」+ 生成時と起動時を明確に分離 | **正しい** (家系で唯一正確) |
| `app/Providers/FortifyServiceProvider.php` L232-234 | 「route:cache 下でも nameCache が同一 instance を返すため dispatch にも有効」 | **誤り**。nameCache の性質の記述自体は正しいが、この callback は compiled collection に**到達しない**。前提が成立していない |
| `app/Providers/PasskeyServiceProvider.php` L129 | 同上 | **同じ誤り** |

**「両方正しい」ではない**。ただし Fortify / Passkey の**結論**(保護は効いている) は
まったく別の理由 (生成時の焼き込み) によって偶然に真である。この
「結論は合っているが理由が違う」形が最も誤読しやすい。

## やるべきこと (実測の結論)

**振る舞いは変えない。現状の保護は実効しているので慌てて実装を書き換えないこと。**

必須 2 件 (docblock のみ = 振る舞い不変):
1. `FortifyServiceProvider` L232-234 を `RouteThrottleBinder` と同じ 2 事象分離で書き直す。
   cached 起動では no-op であること / 実効になるのは生成時の焼き込みであること /
   よって **`route:cache` の毎デプロイ再生成が T124 保護の前提条件**であること。
2. `PasskeyServiceProvider` L129 も同様。あわせて同じ callback 内の
   `Route::bind('passkey', SelfScopedPasskeyBinder::class)` は `Router::$binders` への登録で
   collection 差し替えの影響を受けないため cached 起動でも有効、という**区別**を明記する
   (一括りに「callback ごと無効」と誤読させないため)。

推奨 (穴を無音にしない。ただし過剰にしない):
3. `appendMiddlewareIfMissing()` の silent no-op を `RouteThrottleBinder` と同じ作法へ揃える。
   `routesAreCached()` なら明示的 early return (理由コメント付き)、
   非 cached で route が引けなければ fail-fast。
   **★実装上の注意**: cached 起動で例外を投げてはならない。
   aicue:T120 で `route:list` が必ず落ちる事故が既に起きている (docs/TODO-closed.md T120 参照)。
   skip 判定を引数で受ける純粋関数に切り出す `RouteThrottleBinder` の形をそのまま踏襲するのが安全。
4. `docs/app-integration-guide.md §7b` の運用要件を「throttle の前提条件」から
   **「後付け機構全体 (throttle / recent-auth / ensure-login-method) の前提条件」**へ広げる。
   現状 §7b は流量制限の節にしかなく、aicue:T124 の step-up がこの要件に乗っていることが読み取れない。

## やらなくてよいこと

- **後付け実装そのものの方式変更は不要**。現行方式でも焼き込みで実効しており、
  無理に変えると aicue:T120 / aicue:T121 で固めた目録検査との整合を壊す。
- 緊急のセキュリティ修正ではない。これは「誤った機序の記述が次の担当を誤らせ、
  運用要件を隠している」ことへの是正である。

## 残る未解決点 (設計で扱うか判断する)

**本番デプロイが実際に毎回 `route:cache` を再生成しているかは確認できていない。**
リポジトリにデプロイ定義そのものが存在しない (lctl も `deployer-pipeline` を
「デプロイ基盤未整備」と記録)。運用要件は AGENTS.md ドメイン固有規約 5 /
`docs/app-integration-guide.md §7b` / binder docblock の 3 箇所に文書としてあるだけ。
**是正の中で唯一、実際の安全度を動かすのはこの項目**だが、
今無いデプロイ基盤のために仕組みを作るのは AGENTS.md 思考原則 2 に反する。
設計では「デプロイ基盤を作るときに必ず踏む要件」として記述を残す形が妥当と思われるが、
判断は設計者に委ねる。
