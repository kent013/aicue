# 対応マトリクス: conceptual-review Round 1

## [Critical] webhook の固定キー全体天井は新しい DoS 口になる (観点 2 / 5)
- 判断: **対応する (全面的に受け入れ)**
- 根拠: 指摘が正しい。署名検証**より前**に走る middleware で固定キーのバケットを消費させると、
  無効 body の連打だけで正当な Stripe / SES 通知を 429 にできる。
  標準形 (3) の適用条件「増幅があり、**かつ止まっても中核の業務が止まらない**口」に対しても、
  「攻撃者が任意に止められる」時点で条件を満たしていない。
- 対応内容:
  - `webhook-ses` / `webhook-stripe` から**固定キー全体天井を削除**し、**IP 単位のみ**にする。
  - 「全体天井」は本タスクの射程から外し、後続 TODO 候補へ移す。
    その際の必須条件を設計に明記する = 「**署名検証成功後に消費される位置**でしか採らない」
    (middleware では成立しないため、Controller / Service 層での設計が要る)。
  - §5 代替案表と §6-2 スコープ外表、§9 リスク表を書き換える。

## [Warning] `storage.local.upload` の exemption 理由が弱い (観点 2)
- 判断: **対応する**
- 根拠: 実査で前提が誤っていたことを確認した。`config/filesystems.php:36` は
  local disk に `'serve' => true` を設定しており、**production でも route は登録される**
  (`FilesystemServiceProvider::shouldServeFiles()` は driver=local かつ serve=true で登録)。
  「production は S3 だから route が無い」は**事実として誤り**。
- 対応内容: exemption 理由を実際の防御線に置き換える =
  `Illuminate\Filesystem\ReceiveFile::__invoke()` が本体到達前に
  `abort_unless($request->boolean('upload') && $request->hasValidRelativeSignature(), production ? 404 : 403)`
  を行う (署名は `app.key` HMAC)。
  さらに Codex の要求どおり**前提を機械検査に落とす** = 署名なし PUT が 404/403 で短絡することを
  Feature テストで固定する (前提が vendor の変更で崩れたら赤くなる)。

## [Warning] `route:list --json` を検査の正本にするのは脆い (観点 3)
- 判断: **対応する (もともとの意図を明文化)**
- 根拠: `route:list --json` は本設計の**実査**にのみ使っており、テストの正本にする意図は無かったが、
  設計文書がそう読める書き方になっていた。指摘のとおり alias / group 名 (`'web'`) が
  展開されない形で出るため、テストの入力にすると誤判定する。
- 対応内容: Architecture テストは `Illuminate\Support\Facades\Route::getRoutes()` +
  `Route::getFacadeRoot()->gatherRouteMiddleware($route)` で**解決済み class 列**を得る、と明記する。
  §2-3 の実査結果には「route:list は実査用」と注記する。

## [Warning] 後付け binder の実行タイミングと route:cache 時の挙動が未確定 (観点 3)
- 判断: **対応する**
- 根拠: 重要。とくに `php artisan route:cache` を使うと `routes/*.php` は**実行されない**ため、
  「routes ファイル内で後付けする」方式は cache 生成時に焼き込まれるかどうかで挙動が分かれる。
- 対応内容:
  - `RouteThrottleBinder` の呼び出しは `AppServiceProvider::boot()` 内の `$this->app->booted(...)`
    に一本化する (vendor provider の route 登録・route cache の読み込み後であることが確定する位置)。
  - **routes ファイル側では新たな後付けをしない** (cache 有無で挙動が変わる場所を作らない)。
  - `route:cache` した状態でも fail-fast と付与が効くことを Feature テストで固定する。

## [Warning] `POST /register` / `POST /forgot-password` の IP 単独 5/min は NAT 巻き添え (観点 5)
- 判断: **対応する (リスク明記 + TODO 接続)**
- 根拠: 閾値の見直しは AG-096 で射程外。ただしリスクとして書く価値がある。
- 対応内容: §9 リスク表に「観測すべきメトリクス (429 発生率 / 同一 IP 配下の別 email 件数)」と
  「UX 救済は `error-response-contract` feature 側」を明記する。

## [Warning] §4C と §6-2 の矛盾 (観点 6)
- 判断: **対応する**
- 対応内容: §4C を「**本 feature で新たに追加する**後付けは helper を通す。
  既存の DCR (`routes/ai.php`) / `PasskeyServiceProvider` の後付けは今回は触らない」と書き換える。

## [Warning] limiter 列挙に Reflection を使うと PHPStan level 10 で雑になる / enum 化の提案 (観点 7)
- 判断: **一部対応する (Reflection は使わない)。enum 新設は見送る (反論)**
- 根拠:
  - Reflection 回避は同意。`Illuminate\Cache\RateLimiter` に limiter 一覧の public API は無い。
  - ただし `RateLimiterName` enum の新設は、定義側 (`RateLimiter::for()`) ・route 側
    (`throttle:` 文字列) ・config 側 (`config/fortify.php` の `limiters`) の 3 面を
    同時に書き換える必要があり、AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。
    本タスクの目的は「貼り忘れの検出」であって limiter 名の型付けではない。
- 対応内容: **ソース走査 + テスト側 inventory の相互突合**方式にする
  (`app/Providers/*.php` を `RateLimiter::for\('([a-z0-9-]+)'` で走査 → `array<int,string>` を得る →
  テスト側 inventory と集合一致を検査 → 各 limiter は `app(RateLimiter::class)->limiter($name)`
  (public・`?Closure` 返り) で取得)。
  リポジトリ内に既に同型の先例がある (`tests/Support/AuthorizationMarkerScanner`) ため流儀も揃う。
  Reflection は 1 箇所も使わない。`Limit|array<int,Limit>` の絞り込みは `Webmozart\Assert\Assert` で行う。

## [Warning] `ThrottleRequests` の class 一点固定は `ThrottleRequestsWithRedis` で false negative (観点 8)
- 判断: **対応する**
- 根拠: 実査で `ThrottleRequestsWithRedis extends ThrottleRequests`
  (`vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequestsWithRedis.php:10`)
  を確認した。`is_a($class, ThrottleRequests::class, true)` にすれば両方を 1 つの述語で拾える
  (正規表現による文字列判定より頑健)。
- 対応内容: 判定述語を「解決済み class 名の basename 部を `is_a(..., ThrottleRequests::class, true)`
  で判定する」に統一し、設計に明記する。

## [Suggestion] exemption 件数の上限を置く (観点 8)
- 判断: **対応する**
- 対応内容: 母集団の floor に加えて **exemption 件数の cap** を置く
  (cap を超える追加は「テストの定数を上げる」という意図的な行為を要求する = 形骸化の抑止)。

## [Suggestion] 使命との接続の表現 (観点 1)
- 判断: **対応する (表現のみ)**
- 対応内容: §7 を「使命そのものを前に進める機能ではないが、SOP と手順動画という**現場の資産**を
  預かる基盤の前提条件である」と言い換える。
