# T265 テストファースト証跡 — 変異での赤確認記録

設計 (devnotes/20260826-0029-bughunt-executed-route-capture-t2/detailed-design.md Round 4
APPROVED) のテスト計画に従い、追加・改訂した各検査が**狙った理由で赤になる**ことを、
correlate.py / operations.md への一時変異で確認した。変異はすべて確認後に復元し、
恒久コードには残していない (git status で復元を機械確認済み)。

実行環境: worktree todo/T265、`python3 -m unittest` 単独メソッド指定。

## 施策 A: MainInputAvailabilityTest

| # | 変異 (一時) | 対象テスト | 結果 |
|---|---|---|---|
| A1 | correlate.py main() の except 節から `OSError` を除去 | `test_file_missing_is_rejected_per_input` | 赤 (errors=4)。`FileNotFoundError` の traceback で落ちる = except 節の OSError 捕捉が return 1 契約を支えていることを確認 |
| A2 | main() の `--executed` 可用性検査 (`if args.executed is None:`) を `if False:` に無効化 | `test_option_missing_is_rejected_per_input` | 赤 (errors=1)。`TypeError` (None が load_executed へ到達) = 手動検査が executed_missing→3 の写像を支えていることを確認 |

正の対照: `test_baseline_is_green` (全 6 入力が揃えば 0 + worklist 出力) が緑。

## 施策 B: LoadOperationsTest.test_real_operations_md_name_column_join_keys (全面改訂)

| # | 変異 (一時) | 結果 |
|---|---|---|
| B1 | load_operations の `name_cell = cols[name_idx]` を `cols[1]` (URL 列) に固定 | 赤 (failures=1)。独立オラクルとの**集合不一致**で落ちる。併せて観察: この変異下で ops=78 / mismatched=73 のため旧集約形 (`mismatched ≥ 1`) だけなら**緑のまま** — 本命がオラクル照合であることの根拠 (設計 Round 2 [Critical] の裏取り) |
| B2 | `_NAME_HEADERS` を `("zzz-broken",)` に破壊 (ヘッダ認識の退行) | 赤 (failures=1)。期待キー集合 (オラクル・非空) vs 実装 0 件の集合不一致で落ちる |
| B3 | `_NAME_HEADERS` と実 operations.md ヘッダの**双方**を `joinkey` へ変更 (共倒れ再現) | 赤 (failures=1)。「パイプ形式の行が 80 行あるのに 5 列固定ヘッダを認識できない (生成物のヘッダ契約が変わった — オラクルと前提の見直しが要る)」= candidate_lines の独立計数が共倒れ緑を防ぐことを確認 (設計 Round 3 [Critical] の裏取り) |

正の対照: 現行の実 operations.md (79 データ行) で緑。

## 施策 C: RealRouterTest

| # | 変異 (一時) | 対象テスト | 結果 |
|---|---|---|---|
| C1 | load_route_list の subprocess 引数を `route:lists` に破壊 | `test_load_route_list_fallback_returns_named_routes` | 赤 (failures=1)。`self.fail` の読める診断「php artisan route:list --json が失敗: rc=1 + stderr」で落ちる |
| C2 | 登録判定トークンを `route:listX` へ一時変更 | `test_route_list_command_is_registered` | 赤 (failures=1)。registered 集合 (route:list を含む実登録一覧) に対する assertIn で落ちる = トークン完全一致判定が機能していることを確認 |

正の対照: 復元後 `RealRouterTest` 2 テスト緑 (追加所要 約 1 秒)。

## 復元確認

各変異の直後にバックアップから復元し、最終状態で
`git status --porcelain` が correlate.py / operations.md の変更を報告しないこと、
`python3 -m unittest test_correlate` が全緑 (66 tests OK) であることを確認した。
