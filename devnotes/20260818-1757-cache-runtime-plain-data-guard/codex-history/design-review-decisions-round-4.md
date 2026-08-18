# 対応マトリクス: design-review Round 4

## [Critical] `callMacro()` が macro を残し global afterEach で失敗する

- 判断: **指摘のとおり。対応する**
- 根拠: guard の `__call()` が例外を投げるので、後続行に置いた `flushMacros()` は実行されない。
  `expectViolation()` が drain するのは accumulator だけで macro の static 状態は残る。
  さらに境界 API の呼び出しは `GuardedBoundaryProbe.php` にしか置けない (L4f) ので、
  テスト本体の `finally` から `Repository::flushMacros()` を呼ぶ形も採れない。
- 対応内容: `callMacro()` を `try { … } finally { Repository::flushMacros(); }` にした。
  例外はそのまま伝播し、macro は必ず消える。`flushmacros` も自己テスト目録に現れる (count 1)。
  残存 macro の検査 (検査 16) は **`registerMacroWithoutUsing()` を別メソッドとして分け**、
  テスト内で `flushAndFailIfStray()` を明示的に呼んで検出と復元を確認する形にした。

## [Critical] Probe メソッドの `@return never` が型契約と一致しない

- 判断: **指摘のとおり。対応する**
- 根拠: 引数の native 型は**通常の** `Illuminate\Cache\Repository` であり、
  通常の `tags()` は値を返し得る。「guard 付きを渡したときに例外になる」ことは
  振る舞いの話であって静的なメソッド契約ではない。
- 対応内容: `@return never` をすべて削除し、native の `void` のままにした。
  理由をコメントとして本文へ書いた。

## [Warning] `resolveCustomDriver()` が facade と引数 manager の同一性に暗黙依存している

- 判断: **対応する**
- 対応内容: 登録も解決も**引数の manager** に対して行う (`$manager->extend(...)`)。
  `CacheManager` は scanner の受け手型なので静的 L4 の検出力は保たれる。
  `use Illuminate\Support\Facades\Cache;` は不要になったので削除した。

## [Warning] 施策一覧の S5 補助ファイル数が古い

- 判断: **対応する**
- 対応内容: 「補助 3 本」→「補助 4 本」に直した
  (`BootTimeCacheWriteProbeProvider` / `IsolatedApplicationProbe` /
  `CachePayloadViolationAssertions` / `GuardedBoundaryProbe`)。

## [Warning] L4f のテスト名が旧表現のまま

- 判断: **対応する**
- 対応内容: 「自己テスト目録の key は `GuardedBoundaryProbe.php` ちょうどにしか無い」に直した。
