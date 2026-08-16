# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high / label=impl-review) の全体判定は **APPROVED**。
Critical / Warning はゼロ。Suggestion 1 件のみ。

## [Suggestion] tests/js/pages/CaptureAccount.test.ts の afterEach が `vi.clearAllMocks()` 止まり

- 指摘: 「送信中」ケースが `routerPostMock.mockImplementation(...)` を仕込むが、
  `clearAllMocks()` は**呼び出し履歴しか消さず実装は残る**。現状は後続ケースが
  ログアウトを押さないため実害は無いが、将来ケースを足すと漏れる。
- 判断: **対応する**
- 根拠: 指摘のとおり `mockImplementation` はテスト間に残存する。実害が出るのは
  「後続ケースを足したとき」であり、その時に原因の分かりにくいフレークとして現れる。
  1 行の変更で構造的に閉じられるので先に閉じる。
- 対応内容: `afterEach` を `cleanup()` + `routerPostMock.mockReset()` に変更した
  (実装ごと落とす)。なぜ `clearAllMocks` では足りないかをコメントで残した。

## 実装時に設計から逸脱した 2 点についての Codex の評価

いずれも Round 1 のプロンプトで明示的にレビュー対象として提示した。

1. **タイトルの供給元を `SeoManager::setPrivateTitle()` → `config('seo.app_titles')` へ変更**
   - Codex: 「静的タイトルと bug-hunt 目録の整合という理由があり正当」
   - 判断: 逸脱を維持する。逸脱理由は本ファイルと controller の docblock に記録済み。
2. **リンク href の assert を完全一致 → 末尾一致へ変更**
   - Codex: 指摘なし (該当ファイル「問題なし」)
   - 判断: 維持する。Inertia の `Link` が jsdom で href を絶対 URL へ解決するため
     完全一致は成立しない (実測で確認)。既存 `SettingsIndex.test.ts` と同じ様式。

## 合議終了

Round 1 で APPROVED のため合議は 1 ラウンドで終了。Suggestion の反映は
APPROVED 後の軽微な改善であり、再レビューは要求しない (テストは再実行して緑を確認する)。
