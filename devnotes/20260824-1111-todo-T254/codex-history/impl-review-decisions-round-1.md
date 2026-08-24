# Codex 実装レビュー Round 1 への対応マトリクス (T254)

判定: **CHANGES_REQUESTED** (Critical 0 / Warning 1 / Suggestion 0)

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | `bughuntNamingOffsetsOf()` が docblock で明示している 2 つの挙動 (重なり合う出現も別の出現として数える / 空 needle は例外) に**永続的な正例・負例が無い**。`$from = $at + 1` が非重複走査へ退化しても N-4 (a)〜(l) は緑のままで、走査器共通規約 (c)「検出力を負例で裏取り」を満たしていない | **対応する** | N-4 の先頭に `(0)` として 2 行足した。`expect(bughuntNamingOffsetsOf('aaa', 'aa'))->toBe([0, 1])` (重なり合う出現) と `expect(fn () => bughuntNamingOffsetsOf('aaa', ''))->toThrow(RuntimeException::class)` (空 needle)。検査の本数は 5 本のまま (詳細設計「検査は 5 本のまま」を維持) |

## 判断の根拠

指摘は妥当である。手作業の赤実測 (`red-evidence.md`) は本セッション限りの証拠であって、
**将来の退化を止める仕掛けにはならない**。位置集合の一致という本 gate の中核は
`bughuntNamingOffsetsOf()` の重複走査に依存しており、そこが非重複走査へ戻っても
N-4 の 12 ケースは全部緑で通る (旧名も needle も自己重複しない文字列なので位置が変わらない) —
つまり中核の前提が裏取りされていない穴だった。

詳細設計の「検査は 5 本のまま (N-1〜N-5)」「負のコントロールは同じ純関数を通す」方針とは
矛盾しない (N-4 = 負のコントロールの中に、述語が使う下位関数の正例・負例を足しただけ)。

## 対応後の実測

```
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":70,"duration_ms":1910}
{"tool":"pint","result":"passed"}
```

(Round 1 時点は 68 assertions。足した 2 件が乗って 70 になっている)
