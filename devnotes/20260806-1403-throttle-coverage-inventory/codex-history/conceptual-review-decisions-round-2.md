# 対応マトリクス: conceptual-review Round 2

## [Critical] `route:cache` と `RouteThrottleBinder` の自己矛盾 (観点 3)
- 判断: **対応する (全面的に受け入れ)**
- 根拠: 指摘のとおり。`RouteCacheCommand` は `getFreshApplicationRoutes()` で
  **アプリを再 bootstrap してから** `router->getRoutes()` を直列化する。
  provider の boot → `booted()` callback も走るため、**binder が付けた throttle が cache に焼き込まれる**。
  その cache を読んだ次回起動でも `booted()` は走るので、
  「既存 throttle があれば常に例外」だと **cached 起動が必ず落ちる**。設計の欠陥だった。
- 対応内容: `attachByName()` を**真の冪等**に作り直す。実効 throttle entry を数え、
  - 0 本 → 付与する
  - ちょうど 1 本 かつ `ThrottleRequests::class.':'.$limiter` と**完全一致** → **no-op**
  - それ以外 (別 limiter / 2 本以上) → `RuntimeException`
  検証は「uncached 起動 / cache 生成 / cached 起動」の 3 状態を個別にテストする。

## [Warning] `gatherRouteMiddleware()` の entry はパラメータ付き文字列 (観点 3)
- 判断: **対応する**
- 根拠: 実効列の entry は `Illuminate\Routing\Middleware\ThrottleRequests:login` のように
  `{class}:{params}` 形式で出る。class 名には `:` を含まないため
  `Str::before($entry, ':')` で class 部を切り出せる (Laravel の `Pipeline::parsePipeString` と同じ分割)。
- 対応内容: 判定述語を「`Str::before($entry, ':')` に対して
  `is_a($class, ThrottleRequests::class, true)`」に修正し、目録検査・binder の両方で共有する。

## [Warning] limiter closure を 1 回実行するだけでは分岐を網羅できない (観点 5)
- 判断: **対応する**
- 根拠: 正しい。`passkeys` / `two-factor` / `api-*` は「認証済み / 未認証」で別のキーを返すため、
  片方だけ評価すると規約違反の分岐を見逃す。
- 対応内容: inventory を
  `array{scenarios: array<string, callable(): Request>, expectedKeyPrefixes: list<string>}` にする。
  検査は 2 段:
  1. 全 scenario の全 `Limit::$key` が規約 regex に一致する
  2. **produce された `{レーン}:{種別}` の集合が `expectedKeyPrefixes` と完全一致**する
     (未宣言の分岐が出れば fail = 新しい分岐の見逃し防止。
      宣言したのに produce されない prefix があっても fail = 死んだ scenario の検出)

## [Warning] 正規表現走査では非リテラル / 空白・改行の登録がすり抜ける (観点 8)
- 判断: **対応する**
- 根拠: 正しい。`RateLimiter::for(\n    'name',` や `RateLimiter::for(self::NAME, …)` を
  素の正規表現は取りこぼし、しかも「集合一致」は成功してしまう (最悪の壊れ方)。
- 対応内容: 走査を `token_get_all()` ベースの scanner
  (`tests/Support/RateLimiterRegistrationScanner`) に変更し、
  - `app/` 配下の**全** `RateLimiter::for(...)` 呼び出しを数える
  - 第 1 引数が `T_CONSTANT_ENCAPSED_STRING` でない呼び出しが 1 件でもあれば **fail**
    (「解析できなかった」を沈黙させない)
  - 「検出した名前の集合が inventory と一致」に加えて「**全呼び出しを分類できた**」ことを検証する
  scanner 自体の positive/negative は `tests/Unit/Architecture/` に単体テストを置く
  (`AuthorizationMarkerScannerTest` と同じ流儀)。

## [Warning] webhook の IP 単位制限に対する断定が強すぎる (観点 2)
- 判断: **対応する (表現の修正 + 監視項目の追加)**
- 対応内容: 「攻撃者が正当送信元のバケットを消費させることはできない」→
  「通常のネットワーク条件では第三者が同一バケットを選びにくい。
  ただし共有クラウド出口・送信元構成の変更・proxy 設定の誤りでは巻き添えがありうる」に修正し、
  **送信元 IP の分布と 429 発生率**を監視項目に加える。

## [Suggestion] exemption cap を §9 リスク表にも明記 (観点 8)
- 判断: **対応する**

## [Suggestion] テスト側の型を `array<int, Limit>` まで明示 (観点 7)
- 判断: **対応する** (詳細設計のシグネチャで明示する)
