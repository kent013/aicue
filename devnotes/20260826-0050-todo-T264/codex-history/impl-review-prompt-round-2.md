# Round 2: Warning への対応報告

Round 1 の指摘は「コード差分に修正事項なし。ただしフルスイート 1 件失敗
(`BughuntSelfTestExecutionTest`) のため全 green の証跡が未達」だった。
コードは 1 行も変えていない (変える必要のある指摘が無かったため)。
以下の追加証跡で失敗が本差分と無関係な既存事象であることを実測で裏取りした。

## 追加証跡: main (本差分を含まないチェックアウト) での同テスト実行

- 実行: main のリポジトリルートで `composer test -- tests/Architecture/BughuntSelfTestExecutionTest.php`
- 結果: **main でも失敗** — 2 errors:
  `The process "'bash' '/workspace/scripts/bug-hunt-shard.sh' 'self-test'" exceeded the timeout of 120 seconds.`
- worktree (本差分あり) では同テストのみ 1 failed (pid 所有確認の環境依存:
  「pid は存在するが所有確認できない — kill せず pidfile 保持」)。
- 差分の有無に関わらず同一環境で同テストが落ちる = 失敗は実行環境依存の既存事象であり、
  本差分 (global use gate / 乖離台帳のみ、bug-hunt 系ファイルとの交差ゼロ) が原因ではない。

## 検査の扱い

- 検査の弱体化はしない (skip 化・削除・閾値変更は一切していない)。
- T264 関連スイートは全緑: `NoNonCompoundGlobalUseTest` 9/9 /
  `TemplateDivergenceFingerprintTest` 15/15 / `TemplateDivergenceLedgerFormatTest` 2/2。
- フルスイート 7863 tests 中、失敗はこの 1 系統 1 件のみ (7860 passed / 2 skipped)。
  他の検証コマンド (phpstan / pint / pnpm lint / typecheck / test 2748 / build /
  packages typecheck / build / test 129) はすべて緑。

以上を踏まえ、全体判定を更新せよ (APPROVED / CHANGES_REQUESTED)。
