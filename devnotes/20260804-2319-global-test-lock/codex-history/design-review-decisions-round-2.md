# 対応マトリクス: design-review Round 2

## [Critical] 子起動後の内部エラー / `_gtl_die` 経路で、稼働中の子・孫を残したまま fd 7 が閉じる
- 判断: **対応する**
- 根拠: 完全に正しい。`_gtl_on_signal` にだけグループ収束を書き、`_gtl_cleanup` には書いていなかったため、
  「子を起動した後 → PGID 不一致で `_gtl_die`」「`set -e` による中断」など**シグナル以外の異常終了経路**では
  残党を残したままロックが解放される。設計の最重要契約
  (保持期間 = 取得〜専用プロセスグループが空になった後) が破れる穴だった。
- 対応内容: 収束処理を共通関数 **`_gtl_reap_active_group <sig>`** に切り出し、
  - `_gtl_cleanup` の**先頭**で必ず `_gtl_reap_active_group TERM` を呼ぶ
    (= あらゆる EXIT 経路でロック解放より先にグループが収束する)
  - `_gtl_on_signal` は `_gtl_reap_active_group "${sig}"` → `_gtl_cleanup` の順で呼ぶ。
    共通関数は `_GTL_CHILD_PGID` が空なら即 return する冪等実装なので**二重処理にならない**
  - 正常経路 (`global_test_lock_run`) は従来どおり wait + group 収束後に
    `_GTL_CHILD_PGID` をクリアするため、cleanup 側は no-op になる
  - cleanup の順序を明文化: **(1) グループ収束 → (2) lane の EXIT フック → (3) heartbeat 停止 →
    (4) sidecar 削除 → (5) fd 7 クローズ**。
    lane フック (orphan playwright 掃除) は「レーン本体が消えた後・次のレーンが入る前」に走るのが正しい
  - 層 1 に **C22「子起動後の内部エラーでも残党を残さず、その後にロックを解放する」**を追加

## [Warning] 施策 8 の骨子に「lane が自前で `trap ... EXIT` を張らない」検査が実装されていない
- 判断: **対応する**
- 根拠: 正しい。Round 1 で本文には書いたが、テストコード骨子へ落とし込んでいなかった
  (本文とテストの不一致 = 実装者が取りこぼす)。
- 対応内容: `globalTestLockLaneScriptViolations()` に **EXIT trap 検出**を実装。
  ご指摘どおり**コメントを除外した実行行**だけを見る (`#` 以降を削ってから
  `/^\s*trap\b.*\bEXIT\b/` を判定)。負のコントロール
  「自前 EXIT trap を張った lane スクリプトを検出する」を追加した。

## [Warning] `with-global-test-lock.sh` が層 2 の構造検査対象外
- 判断: **対応する**
- 根拠: 正しい。ラッパが将来 `exec "$@"` に戻ってもロックが即解放されるのに、
  層 2 は「存在し実行可能」だけで通過してしまう。最も守るべき回帰を見逃す構成だった。
- 対応内容: 構造検査の対象を `GLOBAL_TEST_LOCK_GUARDED_SCRIPTS`
  (lane 3 本 + `scripts/with-global-test-lock.sh`) として明示し、
  検査項目に **`global_test_lock_acquire` / `global_test_lock_run` を呼んでいること**を追加
  (source / 旧ロック不在 / `flock -n` 不在 / `exec` 不在 / 自前 EXIT trap 不在 と併せて 7 項目)。
  ライブラリ本体を対象外にする理由 (trap と `exec` fd リダイレクトを**正当に持つ唯一のファイル**)
  もコメントに明記した。
  併せて「`exec 3<>...` の fd リダイレクト形は違反にしない」正のコントロールを追加した
  (`run-browser-test.sh` の `/dev/tcp` guard を誤検出しないことの固定)。

## [Suggestion] `global_test_lock_on_exit` の登録時検証(引数数・関数名形式・`declare -F`)
- 判断: **対応する**
- 根拠: 妥当。関数名の誤記が実行時に `|| true` で黙殺されると、Browser lane の
  orphan playwright 掃除が**静かに無効化**される (最も気づきにくい後退)。
- 対応内容: `global_test_lock_on_exit` に
  (1) 引数数が 1 であること、(2) 関数名が `[A-Za-z0-9_]+` であること、
  (3) `declare -F` で**定義済み関数であること**の 3 検証を追加し、いずれも `_gtl_die` で停止。
  実行時の失敗も黙殺せず `_gtl_warn` で報告するようにした (`|| true` → `|| _gtl_warn ...`)。

## [Suggestion] `rm -f tmp` 後のリダイレクトに同一 UID の TOCTOU が残る — 保証範囲を正確に書く
- 判断: **対応する**
- 根拠: 正しい。「symlink 攻撃を完全防止」と読める書き方は保証の過大表明になる。
- 対応内容: `_gtl_write_sidecar` のコメントに保証範囲を明記した:
  これらの型検証が防ぐのは **0700 dir + 所有者検証と併せた別 UID 境界**であって、
  同一 UID プロセスとの TOCTOU は残る。同一 UID は既に自分と同じ権限を持つため、
  そこを閉じる意味がないことも書いた。

## APPROVE を受けた施策(変更なし)
- 施策 2 / 3 / 4 / 5 / 6 / 9 / 10 は指摘なしのため維持。
