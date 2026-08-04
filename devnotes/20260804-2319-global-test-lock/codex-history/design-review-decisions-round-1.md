# 対応マトリクス: design-review Round 1

## [Critical] 施策 4/1: EXIT trap 競合の解決案に穴がある
- 判断: **対応する**(提示された「exit hook を追加登録する公開 API」案を採用)
- 根拠: 完全に正しい。当初の note は「acquire より前に張るか、`trap 'cleanup_orphan_playwright; _gtl_cleanup' EXIT`
  へ統合すること」と書いていたが、前者は acquire 側の `trap '_gtl_cleanup' EXIT` に上書きされ、
  後者は lane 側が内部関数 `_gtl_cleanup` を知っている前提になり境界が壊れる。
  「実装者が正しく書けば大丈夫」という解決は解決になっていない。
- 対応内容: 公開 API **`global_test_lock_on_exit <fn>`** を新設し、**EXIT trap の所有者をライブラリ 1 箇所に固定**した。
  - `_gtl_cleanup` の先頭で登録フックを順に実行する。**ロックを保持したまま先に走らせる**
    (orphan playwright の掃除は次のレーンが入る前に終える必要があるため、この順序が正しい)。
  - owner でない経路 (flock 不在 / 再入) では `_gtl_cleanup` が呼ばれないため、
    `global_test_lock_on_exit` 側で素の EXIT trap を張ってフックが確実に走るようにした。
  - `run-browser-test.sh` は `trap cleanup_orphan_playwright EXIT` をやめ、
    `global_test_lock_on_exit cleanup_orphan_playwright` に置き換えた。
  - 層 1 の C17 を「フックが**ロック保持中に**実行され、その後ロックが解放される」に強化。
  - 層 2 に「**lane スクリプトが自前で `trap ... EXIT` を張っていないこと**」を追加(構造で固定)。

## [Warning] 施策 1: 同一プロセスでの二重 acquire で owner → reentrant に落ちる
- 判断: **対応する**
- 根拠: 正しい。`_GTL_MODE` が `reentrant` に落ちると後続の `global_test_lock_run` が素通り実行になり、
  **fd 非継承もプロセスグループ管理も失われる**。設計の中核が静かに無効化される最悪の穴。
- 対応内容: `global_test_lock_acquire` の冒頭に「`_GTL_MODE` が既に設定済みなら no-op で return」
  のガードを追加した(owner / reentrant / disabled のいずれでも状態を変えない)。
  層 1 に **C20「二重 acquire しても owner のままで、後続 run が素通り化しない」**を追加。

## [Warning] 施策 1: `lock` / `owner` ファイル自体の型検証(symlink 拒否)が不足
- 判断: **対応する**
- 根拠: 妥当。dir は 0700 + 所有者検証しているが、ファイル単体の型は見ていなかった。多層防御として安い。
- 対応内容:
  - `exec 7>"${lockfile}"` の直前に `lock` / `owner` の symlink 拒否 (`_gtl_die`) を追加。
  - `_gtl_sidecar_nonce`(読み)に `[ -L ] && return 1` を追加(偽 sidecar を読まない)。
  - `_gtl_write_sidecar`(書き)に symlink 拒否と、tmp ファイルの `rm -f` 先行を追加
    (tmp パスに置かれた symlink 経由での書き込みを防ぐ)。
  - 層 1 に **C21「`lock` / `owner` を symlink に差し替えると明示エラーで停止する」**を追加。

## [Warning] 施策 8/6: `test:coverage` の exempt が無ロック経路として残る
- 判断: **対応する**(提示された 2 案のうち「`vitest run --coverage` に寄せてラップ対象へ入れる」を採用)
- 根拠: 正しい。現行の `vitest --coverage` は watch だが、`test:watch` が既にあるので watch の二重提供であり、
  one-shot にした方が用途が明確になる。one-shot である以上、無ロックで残す理由がない
  (CPU 競合の性質は `pnpm test` と同一)。「非公式だから」という逃げの根拠を採らないという点でも
  こちらが正しい。
- 対応内容: `package.json` の `test:coverage` を
  `bash scripts/with-global-test-lock.sh pnpm exec vitest run --coverage` に変更。
  層 2 の exemption を **`test:ui` と `test:watch` の 2 つだけ**に縮小し、
  exempt の根拠を「**無期限にロックを保持してしまう常駐プロセスだから**」と明記した
  (「非公式だから」ではないことを本文に書いた)。

## [Suggestion] 施策 4: bug-hunt pre-flight guard はロック取得前に実行した方がよい
- 判断: **対応する**
- 根拠: そのとおり。取得後に落とすと、先行レーンの終了を数分待たされた末に
  「bug-hunt が走っているので実行できません」と言うことになる。無駄な待機を作らない。
- 対応内容: `run-browser-test.sh` で guard を `global_test_lock_acquire` の**前**へ移動し、
  その理由をコメントに明記。層 1 の C19 も「**ロック取得前に** fail-fast する
  (先行レーンを待たされない)」に強化した。

## [Suggestion] 施策 7: ケース ID を `C01..` 形式にする
- 判断: **対応する**
- 根拠: 妥当。当初表記は `a..s` で 19 行あるのに本文が「14 ケース」と書いており、実数とズレていた
  (指摘のとおり保守性以前に誤記だった)。
- 対応内容: 全ケースを `C01` 〜 `C21` に振り直し(新規 C20 / C21 を含む)、
  本文のケース数を実数に修正。「CI ログから失敗ケースを直接特定できるよう ID を出力する」ことを
  設計に明記した。C16 の「c〜j」という旧表記も `C03〜C10` に直した。

## APPROVE を受けた施策(変更なし)
- 施策 2 / 3 / 5 / 6(`test:coverage` を除く)/ 7 / 9 / 10 は指摘なしのため維持。
