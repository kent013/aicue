以下、`main...todo/T084` 差分レビュー結果です。  
（指示どおり、提示テキストのみを分析。コマンド実行はしていません。）

- [Critical] `tests/Architecture/BughuntOrchestratorGateInvariantTest.php:78`
  - **なぜ問題か**: `bughuntGateFirstEffectiveStatement()` が `local ...` 行を無条件で「副作用なし」とみなしてスキップしています（`/^local\s/`）。bash では `local x="$(some_cmd)"` のように**コマンド置換が実行されうる**ため、`require_orchestrator` より前で副作用が起きてもテストが green になり得ます。施策 11-2 の「default-deny を先頭で強制」の gate に silent hole が残ります。
  - **どう直すか**: `local` を一律スキップしないで、少なくとも `$(...)` / backtick / process substitution / 関数呼び出しを含む `local` は「実効文」と扱って fail させてください。もしくは「最初の実効文」判定をやめ、`require_orchestrator` が**関数先頭ブロックで最初のコマンド実行前**にあることを AST 近似で検査してください。

- [Warning] `tests/Architecture/BugHuntInventoryCheckInvariantTest.php:118`
  - **なぜ問題か**: exit code 契約の実走テストが `python3` 不在時に `skip` されます。これにより、施策 11-3 の核心（`0=一致 / 3=ドリフト` の実証）が環境次第で未検証のまま green になり得ます。
  - **どう直すか**: `skip` ではなく明示 fail（「この invariant には python3 必須」）にするか、テスト内で python 非依存の最小判定器を同梱して fallback 実行してください。

- [Warning] `tests/Architecture/InertiaRenderPageExistsInvariantTest.php:262`
  - **なぜ問題か**: `Inertia::render` 検出が `token->text === 'Inertia'`、helper 検出が `token->text === 'inertia'` の**大文字小文字厳密一致**です（PHP の関数/クラス参照は実質 case-insensitive）。`inertia::render(...)` や `Inertia('Dashboard')` のような非正準ケースが、literal/dynamic どちらにも載らずに通過する可能性があります。
  - **どう直すか**: 検出を `strcasecmp(...)===0` ベースにするか、case 不一致の呼び出しを `nonCanonical` として明示的に fail させてください。

- [Suggestion] `tests/Architecture/BugHuntSkillInvariantTest.php:57`
  - **なぜ問題か**: 継続規約の pin が文言一致に強く依存しており、正当なリライトで誤爆しやすいです（施策 13 の文書運用が重くなる）。
  - **どう直すか**: セクション見出し＋キー概念（例: 停止信号ではない/分母を縮めない/逐次書き出し）への**意味 pin**へ寄せ、文言そのものの完全一致は最小化すると運用耐性が上がります。

**verdict: CHANGES_REQUESTED**

（上記 [Critical] は gate の空振りにつながるため、マージ前修正を推奨します。）