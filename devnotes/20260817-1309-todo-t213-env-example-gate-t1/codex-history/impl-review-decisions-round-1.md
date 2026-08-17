# 実装レビュー Round 1 への対応マトリクス (aicue:T213)

| # | 分類 | 指摘 | 判断 | 根拠 |
|---|---|---|---|---|
| 1 | Warning | 反証テストの closure 引数 `array $expected` に iterable の値の型が無い。詳細設計は「将来 tests/ を解析対象へ入れても通る書き方」を求めている | **対応する** | closure の直前に `@param array{values: …, duplicateKeys: …, malformedLineNumbers: …} $expected` を付けた。docblock は closure に直接付けないと PHPStan の `missingType.iterableValue` を消せないため、`test(…, /** … */ function (…) { … })` の形にした (名前を新設しないので受け入れ条件 AC6 の宣言 10 件は変わらない) |
| 2 | Suggestion | docblock は CRLF / CR / LF の全対応を保証しているが、反証は CRLF と LF だけ。CR 単独の反証を足すと保証範囲とテストが一致する | **見送る (代わりに保証範囲を明記)** | 反証の表は詳細設計が 16 行で確定しており、受け入れ条件 AC4 が件数 22 を pin している。実装側の判断で表と AC の件数を同時に動かすと、設計と実装の正本がどちらか分からなくなる。代わりに **「反証の表に CR 単独の行は無い = 分割の規則が将来 CR 単独を落としても赤くならない」** を docblock に明記し、誇張しない形で保証範囲を閉じた。必要になれば設計の表を直す TODO として起こす |
| 3 | Suggestion | AC5 は行 ID の存在しか保証せず、証跡の内容の真偽は人間依存である | **見送る (設計どおり)** | 詳細設計 AC5 の注記と `red-first-evidence.md` 冒頭に同じ趣旨を既に明記済み。機械で内容の真偽まで確かめるには実行ログをリポジトリへ取り込む必要があり、機械出力をコミットしない方針と衝突する |
