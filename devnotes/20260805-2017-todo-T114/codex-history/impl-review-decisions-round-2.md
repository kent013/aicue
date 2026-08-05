# 対応マトリクス: impl-review Round 2

## [Warning] `--apply` の終了コード分岐がテストで固定されていない

- 判断: **対応する**
- 根拠: 指摘のとおり。Round 1 の元指摘は「失敗しても exit 0」そのものなので、
  `dropped !== targets` を確かめるだけでは**その不一致を実際に exit code へ変換するコード**が
  未検証のまま残る。回帰は「終了コードを選ぶ場所」に置かないと意味がない。
- 対応内容:
  - 終了コード判定を純関数 `dropTestDbApplyExitCode(array $outcome, int $targets): int` へ抽出し、
    entrypoint は `exit(dropTestDbApplyExitCode($outcome, $targets))` を呼ぶだけにした
    (判定ロジックが entrypoint に埋まっていない = テストと実行が同じ関数を通る)。
  - **全成功のときだけ 0**。`failed` / `skipped` が 1 件でもあれば 1。
  - テストを 4 本追加:
    1. 全件成功 → 0 / 対象 0 件 → 0
    2. 失敗・skip・件数不足の 4 データセット → 1
    3. **結合テスト**: `dropTestDbDropAll()` の実結果を `dropTestDbApplyExitCode()` に流し、
       全成功 → 0 / 部分失敗 → 1 (ループと終了コードの結線を固定)
    4. 承認リストに dev DB が紛れ込んだ場合、末端 guard が skip し apply は成功を名乗らない
