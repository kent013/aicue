# 対応マトリクス: conceptual-review Round 3

## [Critical] 3-1 `install()` 前 (アプリ起動中) の書き込みを「静的層が覆う」は成立しない

- 判断: **指摘のとおり。結線点を起動前へ移す (設計を変える)**
- 根拠 (vendor 実読で確認した実現手段):
  - `Illuminate\Foundation\Testing\TestCase::createApplication()` は
    `$app = require bootstrap/app.php;` の**あと**に `$app->make(Kernel::class)->bootstrap();` を呼ぶ。
    つまり **bootstrap の直前に、まだ起動していない `$app` を触れる唯一の点**である
  - `Illuminate\Container\Container::extend()` は **binding がまだ無くても登録できる**
    (`$this->extenders[$abstract][] = $closure`)。その後 `CacheServiceProvider::register()` が
    `singleton('cache', …)` しても、`bind()` の `dropStaleInstances()` が消すのは
    instances と aliases だけで **extenders は残る**。よって `cache` の初回解決時に必ず適用される
  - 本リポジトリは `WithCachedConfig` / `WithCachedRoutes` を **1 件も使っていない** (走査で確認)
  - この結線なら**起動中に `cache` を解決する経路も最初から guard 付きを受け取る**。
    実際、`RateLimiter::for(...)` (本リポジトリは `AppServiceProvider::boot()` に多数持つ) は
    起動中に `cache` を解決するので、**Round 2 で入れた RateLimiter の反射差し替えは不要になる**
- 対応内容:
  - 結線点を `Tests\TestCase::createApplication()` の **bootstrap 直前**へ移した
  - Pest の beforeEach は accumulator の初期化と**結線が効いていることの確認**だけを行う
  - **RateLimiter への反射での書き込みを撤去**し、「握っている受け皿が guard 付きであること」を
    **読むだけの検査**に変えた (Round 2 の [Critical] 5-1 は、より強い形で解決される)
  - **必須負例**を追加した: テスト用の service provider の `register()` / `boot()` で
    オブジェクトをキャッシュへ書き、guard が保管前に検出することを固定する
  - vendor 追随の risk に対しては 2 段の trip-wire を置く —
    (i) `WithCachedConfig` / `WithCachedRoutes` の使用を 0 件で pin、
    (ii) vendor の `createApplication()` の本体を反射で取り出し、前提 (bootstrap/app.php の
    require と `Kernel::bootstrap()` の 2 つだけで、他に副作用のある呼び出しが増えていないこと) を
    固定する。Laravel 更新で増えたら赤くなり、人が写し直す

## [Warning] 3-2 `repository()` の可視性を概念設計で例示しない

- 判断: **対応する**
- 対応内容: 概念設計から可視性の例示を外し、「詳細設計で現行 vendor の宣言をそのまま写して固定する」
  とだけ書いた。

## [Warning] 4-1 効果を「install 後に実行された vendor 書き込み」に限定せよ

- 判断: **対応する (ただし限定の内容は起動前結線により緩む)**
- 対応内容: 起動前結線にしたので、限定は「**アプリの生成後・bootstrap 開始前に結線するため、
  起動中の書き込みも対象に入る**」「ただしテストが実行しない経路は見ない」に書き直した。

## [Warning] 5-1 RateLimiter の受け皿の復元契約が無い

- 判断: **対応する (差し替えそのものを撤去したので契約が不要になった)**
- 対応内容: 反射での**書き込み**を撤去。読み取りによる検査だけにしたので、復元も二重 install も
  問題にならない。二重 install / reset の複数回呼び出しの正負テストは施策 C に残した。

## [Warning] 5-2 「失敗後も全件走る」は実行設定に依存する

- 判断: **対応する**
- 対応内容: `phpunit.xml` / `phpunit.browser.xml` に `stopOnFailure` / `stopOnError` の指定が
  **無い**ことを確認済み (既定は継続実行) であることを工程 1 の前提として明記し、
  「途中終了したら未計測として工程 1 を完了扱いにしない」を完了条件へ加えた。

## [Warning] 6 boot 前結線を入れるか、v2 部分移植として台帳更新を保留するか

- 判断: **boot 前結線を今回に含める**
- 対応内容: 上記のとおりスコープへ入れた。よって台帳へは v2 として報告できる
  (保証しないものを併記する条件は据え置き)。

## [Warning] 7 `RateLimiter::$cache` の fail-closed 契約

- 判断: **対応する (読むだけになったため契約が簡単になった)**
- 対応内容: プロパティが存在しなければ**その場で例外** (pin の空振り防止)、
  読み出した値が guard 付き受け皿でなければ**違反として落とす**、という 2 点を契約として書いた。
  書き戻しはしないので復元契約は不要である。
