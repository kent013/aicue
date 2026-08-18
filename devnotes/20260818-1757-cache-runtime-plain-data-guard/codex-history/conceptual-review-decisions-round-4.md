# 対応マトリクス: conceptual-review Round 4

## [Critical] 3-1 accumulator の初期化が beforeEach では遅い

- 判断: **指摘のとおり。対応する**
- 根拠: 結線が bootstrap 前に入る以上、起動中に記録された違反が beforeEach の初期化で消える。
  provider が自分で握り潰した場合、その記録こそが唯一の証拠なので、消すと二重検出の意味が消える。
- 対応内容:
  - **accumulator の初期化を `createApplication()` の中へ移す**。順序は
    **(1) accumulator を初期化 → (2) extender を登録 → (3) bootstrap** に固定する
    (登録の前に初期化するので、前テストの残骸を消してから新しい起動を観測することになる)
  - **beforeEach は結線が効いていることの確認だけ**にする (accumulator に触らない)
  - afterEach が走らなかった場合 (前テストの異常終了) も、次のアプリ生成の (1) で必ず消える
  - **必須負例**: service provider の `boot()` 中に違反を書き、**その provider 自身が例外を握り潰して**
    bootstrap を継続させ、afterEach の flush で失敗することを固定する

## [Warning] 3-2 起動中の負例をどう組むか

- 判断: **対応する (組み方を概念設計に明記し、詳細は Phase 2)**
- 対応内容: 通常のテスト用アプリへ provider を足すと bootstrap 中に落ちてテスト本体へ到達しないので、
  **負例はテストの中で第 2 のアプリを組み立てる**。手順は
  `$app = require base_path('bootstrap/app.php')` →
  `PlainDataCacheGuard::registerBeforeBootstrap($app)` →
  違反を書く provider を `$app->register(...)` →
  `$app->make(Kernel::class)->bootstrap()`。
  **`Tests\TestCase::createApplication()` と負例が同じ関数を通ることを施策 D の gate が pin する**
  (「同じ結線経路を通った」ことの証明)。
  第 2 のアプリを作るあいだ `Container::getInstance()` と facade の解決済みインスタンスを
  退避・復元する契約を詳細設計で確定する。

## [Warning] 2 工程 5 の完了条件が検証コマンドの全件を含んでいない

- 判断: **対応する**
- 対応内容: 最終の完了条件を **AGENTS.md の `VERIFICATION_COMMANDS` 全件 green** に直し、
  「省略したコマンドがある状態では実装完了を報告しない」と明記した。

## [Warning] 5-1 extender が受け取る manager の状態の引き継ぎ

- 判断: **対応する (fail-closed で塞ぐ)**
- 対応内容: extender の入口で**受け取った実体が素の `Illuminate\Cache\CacheManager` ちょうど
  であること**を検査し、違えば**その場で例外**にする (黙って捨てない)。
  引き継ぐ状態が無いことの根拠は、独自 creator の登録口である `Cache::extend()` を
  **L4 が 0 件で pin する**ことである (2 つの検査が互いの前提になっていることを設計に明記した)。

## [Warning] 5-2 vendor の `createApplication()` の trip-wire を fail-closed にせよ

- 判断: **対応する**
- 対応内容: trip-wire を「文字列があるか」ではなく、
  **vendor のメソッド本体を字句に割り、既知の形のどれにも当てはまらない文が 1 つでもあれば落とす**
  形にした (未解決を落とす = AGENTS.md 走査規約 (b))。あわせて
  正例・負例 (合成入力に未知の文を混ぜて検出できること)、
  空振り検知 (本体が取得でき、文の数が 0 でないこと) を施策 D の必須項目に加えた。

## [Warning] 6 「A〜F・H は tests/ だけを触る」が変更表と矛盾する

- 判断: **指摘のとおり。対応する**
- 対応内容: 「**A〜E はテスト機構、F・H は文書のみ (どちらも本番の挙動を変えない)、
  G だけが本番の設定を変える**」に整理し直した。

## [Warning] 7 extender の入口の型検査

- 判断: **対応する** (5-1 と同じ対応で満たす)
