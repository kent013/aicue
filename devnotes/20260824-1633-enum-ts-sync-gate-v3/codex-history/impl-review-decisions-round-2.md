# 実装レビュー Round 2 の対応マトリクス (Claude 側)

| # | 区分 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Critical | 語へ分割できない **PHP 列挙名**が規則 2b から黙って消える (`enumWords.length === 0` が `null` を返す) | **対応する** | `wordNameCorrespondence()` の `enumWords` 空も**例外**にし、`matchReverseRule()` では**候補側と列挙側の両方**を交差条件の早期 return より前で見るようにした。`ReverseSweepNameError` に対象 (`宣言名` / `列挙名`) を持たせ、交差が半分未満・半分以上の**両方**を負例で固定した |
| 2 | Warning | 内部矛盾の分岐 (`nameResolved === true && correspondenceName === null`) に負例が無い | **対応する** | 手組みの候補で例外になることを固定した |
| 3 | Warning | `program.ts` の冒頭 docblock が「直和検査が赤くなる」のまま | **対応する** | 「所有者の解決 (`resolveOwner()`) で例外になる。起点の重複・欠落は別の検査」へ書き直した |
| 4 | Warning | `findExcludedSurvivors()` が `.svelte` の**読み込み失敗**まで「期待した構文不正」として吸収する | **対応する** | `fs.readFileSync()` を `try` の外へ出し、捕捉するのは `toVirtualUnit()` の拒否だけにした |
| 5 | Warning | tsconfig なしパッケージの「所有者解決で落ちる分岐」を直接試験していない (所属を tsconfig で絞る回帰を検出できない) | **対応する** | 所属と解決を純関数 `ownerNameOf()` / `resolveOwner()` へ切り出し、`createMirrorPrograms()` はそれを呼ぶだけにした。「所属は `packages/without-config` と決まるが program が無いので例外」を直接固定した (所属を tsconfig で絞る実装へ戻すと `ownerNameOf` が `<root>` を返してこの試験が赤くなる) |
| 6 | Warning | `docs/architecture.md` に弱めた主張と強い旧主張が同居している | **対応する** | 節の冒頭を「ルート設定で読むと `any` へ落ちる**恐れ**がある。ただしこの解決の失敗は現物では観測されていない。機械が固定するのは (a) パッケージの設定で組まれた program に載ること (b) どのファイルもちょうど 1 本の起点に載ること の 2 つ」へ統一し、重複していた後段の記述を畳んだ。「保証しないもの」側も「tsconfig なしは**所有者の解決時**に落ちる / 直和検査は別」へ直した |
| 7 | Warning | `composer test` のクリーンなフル実行が未完了 | **対応する** | 他のレーンを止めて再実行した (結果は最終報告に記載) |
