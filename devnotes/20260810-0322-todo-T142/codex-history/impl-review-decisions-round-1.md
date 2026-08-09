# 対応マトリクス: impl-review (harness) Round 1

Critical はゼロ。Warning 3 件 + Suggestion 1 件。**全件対応**した（反論なし）。
うち 1 件は対応の過程で**テストが空振りしていたこと**が判明し、テスト設計をやり直した。

## [Warning] `setup-worktree.sh` — 関数呼び出しを `if` の条件に置くと失敗が隠れる
- 判断: **対応する**
- 根拠: 指摘のとおり。`if provision_bughunt_env_file ... && [[ -f ... ]]` では
  条件内で `set -e` が効かず、`install -m 600` の失敗が
  「親に無いためスキップ」に化ける。**秘密ファイルのコピー失敗を握り潰す**のは契約として弱い。
- 対応内容: 条件を `[[ -f "${REPO_ROOT}/.env.bughunt.local" ]]` に変え、
  **関数呼び出しを本体側**へ移した（失敗すれば `set -e` で止まる）。理由もコメントに残した。

## [Warning] `group_scan_once` がフィールド数を検査していない
- 判断: **対応する**
- 根拠: 指摘のとおり。`read -r live zomb unknown` は**余分なトークンを最後の変数へ吸わせる**ため、
  `0 0 0 garbage` のような壊れ方を検出できない。設計の「3 値が非負整数」という安全弁が抜けていた。
- 対応内容: `read -r -a parts` で配列に読み、**`${#parts[@]} -ne 3` を先に弾く**形にした。
  self-test (y7m) に **「余分な 4 フィールド目」ケース**を追加した。

## [Warning] 受入条件 1・9・10 の固定が静的/人工的（呼び出し側の帰結を見ていない）
- 判断: **対応する（指摘が正しく、直す過程でテストの空振りも見つかった）**
- 根拠: 指摘のとおり。y7c は `group_stopped` の stub 検証で
  「`stop_shard_workers` が zombie-only 成功時に pidfile を削除する」ことまでは見ておらず、
  y7h も `cmd_teardown` 本体ではなく同等の if 断片を検証していた。
- 対応内容: **実挙動ケースを 3 つ追加**した。
  - **(y7p)**: zombie のみの group で `stop_shard_workers` が成功し、**pidfile を削除する**
  - **(y7q)**: **`cmd_teardown` 本体**を stub 環境で走らせ、停止失敗 shard について
    (a) teardown が非ゼロ、(b) 当該 shard の dropdb が呼ばれない、(c) pidfile が保持される、
    を同時に見る。**対照**として「停止対象なしの shard では dropdb が呼ばれる」ことも見る
    （これが無いと「常に何も呼ばれない」実装でも通ってしまう）
  - **(y7r)**: **再確認層そのもの**を突く。y7q は `workers_stopped=0` で止まるため
    第 1 層しか見ていないことが分かったので、
    「停止判定は成功するが dropdb 直前の再確認で live が出る」状況を作った

### 途中で判明した問題（正直に記録する）
1. **最初の y7q は空振りだった**。`cmd_teardown` の `require_orchestrator` が先に die するため、
   「停止失敗だから非ゼロ」ではなく「gate だから非ゼロ」で通っていた。
   → `require_orchestrator` を stub して本体を実際に走らせる形に直した。
2. **対照ケースが先に pidfile を消していた**。positive control を同じ shard で回したため、
   `kill` stub の影響で stale 判定に落ちて pidfile が削除され、後続の assert が誤って落ちた。
   → 対照を「同一 run 内の別 shard」で取る形に作り替えた。
3. **y7r の phase 切替をコール回数で書いていたのが脆かった**。`group_stopped` が
   1 回の判定で 2 回走査するため、閾値が実装の走査回数に依存していた。
   → **意味的なトリガ**（停止成功時に pidfile が削除される）で切り替える形に変えた。
   さらに、停止フェーズで `live=0 zombie=0` を返すと
   「kill -0 は成功するのに procfs で 0 件」= 確認不能の fail-closed に掛かって
   停止判定自体が失敗し再確認層へ到達しないため、**zombie を 1 件返す**ようにした。

### mutation による実効性の確認
`recheck_shard_workers_stopped` の呼び出しを `if false` に置換して self-test を走らせた:
- 対応前: **構造検査 (y7i) しか赤くならなかった**（実挙動テストは素通り）
- 対応後: **y7i + y7r の 2 件が赤**になる（構造と実挙動の両方で検出）

## [Suggestion] `cmd_self_test()` 冒頭コメントが末尾の実装と食い違う
- 判断: **対応する**
- 根拠: 「内部生成も現時点では削除しない」と書いたが、末尾は `sandbox_owned == 1` で削除する。
  実装が設計どおりなので、コメントだけが古かった。
- 対応内容: コメントを「**内部生成 (sandbox_owned=1) は従来どおり末尾で削除する**」に修正した。

## 副次: raw DB コマンド目録が想定どおり機能した
上記のテスト追加で `dropdb` の literal を含む行が 6 行増え、
`BughuntRawDbCommandInventoryTest` が**赤くなった**（未登録の literal 行を検出）。
理由付きで目録へ 6 件追加して緑に戻した。deny-by-default が意図どおり働いた実例である。

## 検証（対応後）
- `scripts/bug-hunt-shard.sh self-test`: all passed
- `composer test`: 4114 tests / 4112 passed / 2 skipped / **17709 assertions**（対応前は 17697）
- `composer phpstan`: No errors / `vendor/bin/pint --test`: passed
