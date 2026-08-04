**施策別判定**
- 施策1 `global-test-lock.sh`: **REQUEST_CHANGES**
- 施策2 `with-global-test-lock.sh`: **APPROVE**
- 施策3 `run-test.sh` 置換: **APPROVE**
- 施策4 `run-browser-test.sh` 置換: **REQUEST_CHANGES**
- 施策5 `run-vitest.sh` 置換: **APPROVE**
- 施策6 `test:packages` ラップ: **APPROVE**
- 施策7 層1検証スイート: **APPROVE**
- 施策8 Architectureテスト: **REQUEST_CHANGES**
- 施策9 CIゲート: **APPROVE**
- 施策10 ドキュメント更新: **APPROVE**

**指摘**
- [Critical] 施策4/1: `EXIT` trap 競合の解決案に穴があります（`global_test_lock_acquire` 後に `trap ... EXIT` を張ると `_gtl_cleanup` を上書き、逆に acquire 前に張っても acquire 側が上書き）。  
  修正案: `scripts/global-test-lock.sh:1` に「exit hook を追加登録する公開API」を用意し、Browser側はそのAPIで `cleanup_orphan_playwright` を登録する形に統一してください（または Browser 側で `trap 'cleanup_orphan_playwright; _gtl_cleanup' EXIT` を acquire 後に明示し、層1で固定）。
- [Warning] 施策1: 同一プロセスで `global_test_lock_acquire` を2回呼ぶと `_GTL_MODE` が `owner -> reentrant` に落ち、後続 `global_test_lock_run` が素通り化します。  
  修正案: owner時は再 acquire を no-op（`_GTL_MODE` を変更しない）にするガードを追加。
- [Warning] 施策1: ロックdirは堅牢ですが、`lock` / `owner` ファイル自体の型検証（symlink拒否）が不足しています。  
  修正案: `exec 7>"${lockfile}"` 前と sidecar read/write 前に「symlink なら fail-secure」を追加。
- [Warning] 施策8/6: `test:coverage` を watch 扱いで exempt していますが、運用次第で one-shot 実行されるため、無ロック経路として残ります。  
  修正案: `vitest run --coverage` に寄せてラップ対象へ入れるか、exempt の根拠を「非公式/ローカル専用」に明示して設計境界を固定。

- [Suggestion] 施策4: bug-hunt pre-flight guard は lock取得前に先に実行した方が、不要な待機を減らせます（TOCTOUは残る前提のまま）。
- [Suggestion] 施策7: ケース表記が `a..s` で実数とズレて見えるため、CIログと対応しやすいID（`C01..C14`など）にすると保守性が上がります。

**全体判定**
- **CHANGES_REQUESTED**

（補足）設計全体の方向性は非常に良く、特に「zombie除外の群生存判定」「wait前のシグナル収束」「層1/層2分離」は妥当です。上記3点（trap競合・owner再acquire・ファイル型検証）を塞げば、ロック契約の穴はかなり小さくなります。