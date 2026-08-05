# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 1 / Suggestion 1)。
APPROVED ではあるが、Warning・Suggestion とも「より良い実装が実在する」指摘なので両方対応した。

## [Warning] `contrast-invariant.test.ts` の `fg: string` / `bg: string` 注釈は TS2367 回避のための局所的 widen

- 判断: **対応する** (Codex の代替案を採用)
- 根拠:
  詳細設計のコードをそのまま写すと `SURFACE_ROLE_TOKENS.filter((bg) => bg !== fg)` が
  リテラル型同士の「重なりが無い比較」で `pnpm typecheck` を落とす。
  初手では callback 引数を `string` 注釈にして型を通したが、これは
  **「型を緩めて検査器を黙らせた」形**であり、禁止事項 2 の精神に照らしてグレーという
  Codex の判定は妥当。そもそも自己ペア除外 filter は現時点で両集合が素なため
  **実行時には一度も枝刈りしていない no-op** であり、
  「将来の防御」を型の widen と引き換えに買っているのが割に合わない。
- 対応内容:
  1. `PAIRS` から自己ペア除外 filter を削除し、両集合の素直な直積にした
     (型注釈は一切不要になり、widen は消滅)
  2. 代わりに **「面ロールとテキストロールが素である」を独立した不変条件として明示テスト化**した
     (`new Set<string>(SURFACE_ROLE_TOKENS)` + `.has()` なのでキャストも widen も無し)。
     暗黙の防御を、名前の付いた検査可能な不変条件へ格上げしたことになる
  3. 負のコントロールを実測: `TEXT_ON_SURFACE_TOKENS` に `surface` を混ぜると
     「SURFACE_ROLE_TOKENS と TEXT_ON_SURFACE_TOKENS が重複している: surface」で fail することを確認
- 結果: `pnpm typecheck` exit 0 (widen 無し)、contrast-invariant 25 → 26 tests

## [Suggestion] mail theme を対象外と明記する追記をしたほうがよい

- 判断: **対応する**
- 根拠:
  詳細設計 §施策 6 のリスク表の「メールテンプレに `danger` は含まれない (grep 実測)」は
  事実誤認 (実際は `.button-red` / `.button-error` が `#dc2626` を直書き)。
  設計書は APPROVED 済みの履歴文書なので遡って書き換えないが、
  **誤認を根拠に「対象外」と読める状態を残すと次に触る人が同じ誤読をする**。
  結論 (対象外) は変わらないので、正しい理由をコード側に残すのが筋。
- 対応内容:
  `contrast-invariant.test.ts` の「検査しないもの」節に、
  (a) mail theme は Laravel 同梱の独立パレットであり DESIGN.md トークンの写像ではないこと、
  (b) メール HTML は CSS 変数を使えないクライアントが多く DS token 化には別設計が要ること、
  (c) 設計書の「danger は含まれない」は事実誤認だが対象外という結論は変わらないこと
  を明記した。

## 補足回答 (Codex から「非ブロッカー」と確認された項目)

| # | 内容 | Codex 判定 | 対応 |
|---|---|---|---|
| 2 | mail theme 据え置き | スコープ的に妥当 | 据え置き (理由をコードに明記) |
| 3 | rounded の `expect(section).not.toBeNull()` → `throw` 置換 | 検出力維持 | 変更なし |
| 4 | `public/capture-sw.js` の lint 対象外据え置き | 設計契約どおり | 変更なし |
