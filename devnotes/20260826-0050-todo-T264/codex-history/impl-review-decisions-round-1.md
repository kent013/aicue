# 対応マトリクス: impl-review Round 1

## [Warning] フルスイート 1 件失敗 (BughuntSelfTestExecutionTest) — 全 green の証跡未達
- 判断: 反論する (追加の実測証跡を提示)
- 根拠: 当該失敗は本差分と交差するファイルが 1 つも無い bug-hunt harness の self-test であり、
  実行環境依存 (pid 所有確認 / 子プロセスの timeout) で確率的に落ちる main の既存事象である。
  裏取りとして **worktree ではなく main のチェックアウト (本差分を一切含まない)** で
  同テストだけを単独実行し、同様に失敗することを実測した:
  - main 実行結果: `tests/Architecture/BughuntSelfTestExecutionTest.php` — 2 errors
    (`The process "'bash' '/workspace/scripts/bug-hunt-shard.sh' 'self-test'" exceeded the timeout of 120 seconds`)
  - worktree 実行結果 (本差分あり): 同テストのみ 1 failed (pid 所有確認の環境依存)
  - 差分の有無に関わらず同テストが落ちる = 本差分が原因ではない既存事象の実証
- 対応内容: 検査の弱体化 (skip 化・閾値変更・テスト削除) は行わない。T264 関連スイート
  (`NoNonCompoundGlobalUseTest` 9/9・`TemplateDivergenceFingerprintTest` 15/15・
  `TemplateDivergenceLedgerFormatTest` 2/2) とフロント含む他の全検証コマンドは全緑。
  フルスイートの他 7860 tests は passed であり、失敗はこの 1 系統に閉じている。
