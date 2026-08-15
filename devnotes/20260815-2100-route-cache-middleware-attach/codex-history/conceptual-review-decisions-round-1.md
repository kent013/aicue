# 概念設計レビュー Round 1 への対応マトリクス

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning (観点 1) | 使命への直接貢献ではなく基盤不変条件として位置づけよ | 対応 | 「期待効果」冒頭を「使命への位置づけ = 撮影 PWA と組織データを安全に運用するための基盤の不変条件」に書き換えた |
| 2 | Warning (観点 2) | 施策 B のクラス名・失敗文言が「デプロイの preflight」に読まれる危険 | 対応 | クラス名を `RouteCacheExemptionPremiseTest` へ変更し、既存の `ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同系にそろえた。失敗文言は「D19 の免除の前提が崩れた」で表現し、「デプロイを正しくせよ」と書かないことを設計に明記 |
| 3 | Critical (観点 3) | 「`compile()` は `route:cache` が書き出すのと同じ配列」は強すぎる | 一部反論しつつ対応 | vendor を実読して事実を確定した: `RouteCacheCommand` は各 route に `prepareForSerialization()` を掛けてから `compile()` の戻り値を `var_export` する。`prepareForSerialization()` が触るのは `action['uses']` / `action['missing']` の Closure 直列化・正規表現の事前構築・容器参照の切り離しだけで **`action['middleware']` には触れない** (laravel/framework 13.18.0)。よって「middleware の並びについては同じ」と**範囲を限定して**書き直し、コマンド全体の成功・Closure 直列化・別プロセス起動順は保証しないことを明記した。さらに版依存の事実であることと、版が変われば焼き込みの実証テスト側が先に壊れて気づける非対称も書いた |
| 4 | Critical (観点 3) | `setCompiledRoutes()` の状態漏れ。復元を明示せよ | 対応 | 「既定では漏れない」を削除し、元の経路一覧を控えて `try` / `finally` で必ず戻す設計に変更。1 テスト内で 2 通りを続けて試す以上必須である旨を制約へ書いた |
| 5 | Warning (観点 4) | 「発生確率ゼロ」は断定が強すぎる | 対応 | 「管理下で検出できる発生経路が無い」へ書き下げ、人手で本番相当に `route:cache` を打つ可能性はリポジトリからは否定できないと明記した |
| 6 | Warning (観点 5) | 秘密値の内容まで強く検査すると Fortify の表現変更に脆い | 対応 | 判定の主役を状態コードの差 (409 / 200) にし、本文は 409 側の `assertJsonMissingPath` のみに限定。200 側の本文の形には踏み込まない |
| 7 | Warning (観点 6) | 施策 C の位置づけを「完全再現」でなく「現在の契約の局所的な固定」と書け。D19 に「暫定ではなく明示判断」と書け。再検討条件を施策 B と一致させよ | 対応 | 施策 A の記述へ 3 点とも追記。再検討条件は施策 B の検査条件と 1 文字単位で一致させる旨を明記 |
| 8 | Suggestion (観点 7) | compiled route 配列の shape を局所 PHPDoc で宣言し `mixed` を深掘りしない | 対応 | 制約へ追記 (禁止事項 2 の型を緩める注釈を使わないことも併記) |
