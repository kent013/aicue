# 対応マトリクス: design-review Round 3

全体判定は **CHANGES_REQUESTED**（Critical 0 / Warning 1 / Suggestion 1）。両方とも対応した。反論はゼロ。

## [Warning] S1-1: `role=read-only` が実測メソッドと整合していない

- 判断: **対応する**
- 根拠: 指摘のとおり、現行の検査 5 は read-only について「L2 に write entry が無い」しか見ていない。
  `(new Repository($store))->put('k', new stdClass, 60)` のように**静的に受け手を解決できない書き込み**を
  持つファイルは `methods` が空のまま L3 surface にだけ現れるため、`read-only` と宣言すれば通過する。
  これは「L3 は L1/L2 の原理的な穴を粗い網で補う」という本設計の主張と矛盾する
  （穴を補うはずの層が、穴を通した書き込みに合法的なラベルを与えてしまう）。
  さらに `Cache::lock()` だけのファイルを read-only と宣言することも通ってしまう。
  **read-only entry が現状 0 件のうちに固定するのが最も安い**という指摘にも同意する。
- 対応内容: 検査 5 に read-only の 3 条件を追加した。
  1. 実測メソッドが 1 件以上ある（0 件は「触っていないのに面に載っている」= 宣言が実態と乖離）
  2. 実測メソッドが NON_WRITE ∪ CHAIN ∪ `cache`（ヘルパの読み出しマーカー）に収まる
     （WRITE / TERMINAL = lock・mock が混ざったら fail）
  3. 終端の読み出し（NON_WRITE か `cache`）が 1 件以上ある
     （CHAIN だけで終わる = 受け手を取り回しているのに読んでいない形も role の意味を壊すため）
  これで write / read-only / lock-only の 3 role すべてが実測と突き合わされる。

## [Suggestion] S1-2: mutation の参照が M1-M10 のまま

- 判断: **対応する**
- 対応内容: テスト計画の「下表 M1-M10」と実装モード表の「mutation で赤化確認（M1-M10）」を
  **M1-M13** に同期した。

## Codex による走査確認の受領（変更なし）

- `\cache([...])` / `\cache($values)` / `\app(Repository::class)->put()` の完全修飾形が
  それぞれ WRITE / unclassified / followChain へ到達することを確認済みとの回答を受領。
- `\App\Support\cache(...)` の除外が取りこぼしを生まないこと、非修飾 `cache()` の過剰検出は
  安全側であることも確認済み。
- 限界の記述（完全修飾 docblock / 動的に得られる受け手 / facade mock）が実装と一致しているとの確認を受領。
- テスト本数 21（通常検査 9 + fixture 12）が正しいことの確認を受領。

## S2 / S3 / S4 / S5: APPROVE（変更なし）
