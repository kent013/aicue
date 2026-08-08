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
