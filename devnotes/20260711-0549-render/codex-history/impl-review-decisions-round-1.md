# 対応マトリクス: impl-review Round 1 (最終ラウンド)

前提: 本ラウンドは前段レビュー Warning (Still レンダ経路の無検証) への修正コミット
`058bff5` (テスト 4 件追加) に対する最終確認。Critical はゼロ。

## [Critical] なし

- 判断: — (指摘なし)
- マージ条件 (未解決 Critical 無し) を充足。

## [Warning] RenderPipelineTest の config()->set('manual.preview_placeholder_seconds', 3) に明示復元がない

- 判断: 反論する (変更しない)
- 根拠: Laravel の TestCase は **テストごとにアプリケーションを再構築**するため、
  テスト内の `config()->set()` は次のテストへリークしない (公式作法)。本スイートでも
  `config()->set()` はテスト内で復元なしに使うのが確立した流儀
  (RenderTriggerTest 5 箇所・QuotaCheckAdditionTest 6 箇所・VersionEndpointTest 5 箇所ほか多数)。
  並列実行 (paratest) もプロセス分離のため相互汚染しない。`try/finally` 復元を
  この 1 箇所だけに入れると既存流儀と乖離し、かえって「復元が必要」という誤った
  シグナルを既存テスト群に与える。
- 対応内容: なし (既存流儀に準拠済みであることを本マトリクスに記録)。

## [Suggestion] ffmpeg 引数の厳密文字列依存を順序非依存の検証ヘルパへ

- 判断: 見送る
- 根拠: `-loop 1 -t 4 -i frame0.png` の並びは planTakeStill が構築する引数列の
  仕様そのもの (ffmpeg は入力オプションの位置が意味を持つ) であり、緩めると
  「-t が入力前に付く」ことを検証できなくなる。同ファイルの既存テスト
  (`-map 0:v:0 -map 1:a:0` 等) も同じ厳密文字列スタイルで統一されている。
  引数順の最適化が実際に起きた時にテストも同時に更新するのが正
  (回帰検知がこのテストの役割)。
- 対応内容: なし。
