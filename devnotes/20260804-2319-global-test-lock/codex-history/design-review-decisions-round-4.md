# 対応マトリクス: design-review Round 4

## [Warning] 検証用 env の値検証がなく、`HEARTBEAT_SECS=0` 等でゼロ除算 / 算術失敗 → cleanup 途中でシェルが終了しうる
- 判断: **対応する**
- 根拠: 正しい。`waited % 0` はゼロ除算になり、`set -e` 下では算術展開の失敗でシェルが落ちる。
  落ちる場所が **cleanup の途中**だと、残存グループを収束させないままロックが解放され、
  次のレーンと併走する — 本設計が最も避けたい状態を、設定ミス 1 つで作れてしまう。
  「壊れた設定で保護が半分だけ効く」のは黙って保護を落とすのと同じで、fail-fast が正しい。
- 対応内容: `_gtl_validate_env()` を新設し、`global_test_lock_acquire` の冒頭
  (二重取得ガードの直後・lock dir 解決の前) で必ず呼ぶようにした。検証内容:
  - `GLOBAL_TEST_LOCK_HEARTBEAT_SECS`: **1 以上の整数**(非数・空・`0` は `_gtl_die`)
  - `GLOBAL_TEST_LOCK_GRACE_SECS`: **0 以上の整数**(非数・空・負数は `_gtl_die`)
  - `GLOBAL_TEST_LOCK_DIR`: 設定されている場合は**絶対パス**であること
    (相対パスだと lane の cwd 次第でロックが分裂するため。ご指摘の 2 項目に加えて自主追加)
  - 層 1 に **C24**「不正値・ゼロ・負数・相対パスで**取得時に fail-fast する**」を追加。

## [Suggestion] `globalTestLockLaneViolations()` がラッパ名を含むだけで合格する(`wrapper true && unlocked-test`)
- 判断: **対応する**(将来の偽グリーン防止として妥当)
- 根拠: 正しい。部分一致は「ラッパ名は含むが実体は無ロック」を素通りさせる。
  deny-by-default の inventory がこの形で抜けられるなら、
  worktree-local flock を削除した判断の前提 (公式 entrypoint を全て確実に包めている) が崩れる。
- 対応内容: 判定を強化した:
  - script のコマンドを行に分解し、**最終行 (= 実際に走るコマンド)** を対象にする
    (composer.json の配列形式では `Composer\Config::disableProcessTimeout` などのディレクティブ行が
    先頭に来るため、末尾行を見るのが正しい)。
  - 最終行に **シェル演算子 (`&&` / `||` / `;` / `|`) が含まれていたら違反**
    (ロック配下のコマンドを別コマンドと繋ぐ経路を塞ぐ)。
  - 最終行が `^bash\s+<公式 entrypoint>\b` に**前方一致**すること
    (`with-global-test-lock.sh` または lane スクリプト 3 本)。
  - 負のコントロール「**ラッパ名を含むだけの偽装 (演算子連結) を検出する**」を追加
    (負のコントロールは 5 本 → 6 本に)。

## [Suggestion] `_gtl_wait_group_gone` / `_gtl_reap_active_group` の「上限つき」コメントが実装と不一致
- 判断: **対応する**
- 根拠: 正しい。Round 3 で第 2 段を無期限待機に変えたのに、関数ヘッダと呼び出し側のコメントが
  「上限つき」のままだった。コメントが実装と食い違うと、次に触る人が上限を復活させてしまう。
- 対応内容:
  - `_gtl_wait_group_gone` のヘッダを
    「猶予超過でグループへ SIGKILL を送り、**その後は上限を設けず**空になるまで待ち続ける
    (契約: グループが空になるまでロックを離さない)」に修正。
  - `_gtl_reap_active_group` 内の呼び出し行コメントを
    「猶予超過で SIGKILL → 以後は空になるまで待つ」に修正。

## APPROVE を受けた施策(変更なし)
- 施策 2 / 3 / 4 / 5 / 6 / 8 / 9 / 10 は指摘なしのため維持。
