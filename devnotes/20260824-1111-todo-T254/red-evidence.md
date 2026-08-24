# T254 実装セッションの「赤」の記録 (テストファースト手順の実測)

正本: `devnotes/20260824-1013-rename-residual-name-gate-v1/detailed-design.md` §テストファースト手順。
本件は旧名が現役コードに 1 件も残っていない状態からの改修なので、「閉じたい穴が実際に開いていること」を
述語を一時的に退化させて実測する形で赤を出した。

## 事前実測の再現 (設計の値との突合)

| 観測 | 設計の値 (HEAD b207bafa) | 本セッションの実測 | 判定 |
|---|---|---|---|
| git 追跡下ファイル数 | 9925 | 10066 | 下限 500 を上回る (設計以降の増加のみ) |
| `docs/TODO-closed.md` の `BughuntBillingSeeder` | 2 | 2 | 一致 |
| `docs/TODO-closed.md` の `FakeExternalsServiceProvider` | 3 | 3 | 一致 |
| `docs/TODO.md` の旧名 2 種 | 0 / 0 | 0 / 0 | 一致 |
| パス名に旧名を持つ追跡ファイル | 0 | 0 | 一致 |
| 申告 needle 5 本の実ファイル内出現 | 各 1 回 | 各 1 回 | 一致 |

## 手順 1: パス名照合を持たない述語 → `(f)` が緑になってしまう (現行の穴 2 の再現)

`bughuntNamingViolationsIn()` から `(1) パス名の照合` ブロックを外して実行。

```
{"tool":"pest","result":"failed","tests":5,"passed":4,"assertions":55,"failed":1,
 "failures":[{"test":"…N-4 負のコントロール…","line":487,
 "message":"Failed asserting that actual size 0 matches expected size 1."}]}
```

`line 487` = `(f) ★パス名に旧名を持つファイルは、内容が空でも赤`。
違反 0 件 = **パス名で旧名が復活しても沈黙する**ことの実測。パス名照合を足して緑になることを確認した。

## 手順 2: 突き合わせを「申告の本数と実出現数の比較」だけに退化 → `(b)` と `(l)` が緑 (現行の穴 1 の再現)

Pest 実行 (最初に落ちるのは `(b)`):

```
{"tool":"pest","result":"failed","tests":5,"passed":4,"assertions":50,"failed":1,
 "failures":[{"test":"…N-4 負のコントロール…","line":430,
 "message":"Failed asserting that actual size 0 matches expected size 2."}]}
```

`line 430` = `(b) ★v1 の主眼: 件数は同じだが出現箇所をすり替えた入力`。

Pest は最初の失敗で止まるため、12 ケース全部の内訳は
`evidence/degraded-predicate-probe.php` で一気に測った (`php devnotes/20260824-1111-todo-T254/evidence/degraded-predicate-probe.php`):

```
ケース                                期待       退化版    本実装
(a) 申告どおり                      沈黙       ★沈黙    OK
(b) 件数同じで出現すり替え    検出       ★沈黙    OK
(c) 申告外の出現が増えた       検出       検出       OK
(d) 申告があるのに消えた       検出       検出       OK
(e) 申告の無いファイル          検出       検出       OK
(f) パス名に旧名                   検出       検出       OK
(g1) 家系名 seeder                    沈黙       ★沈黙    OK
(g2) 家系名 provider                  沈黙       ★沈黙    OK
(h1) devnotes 除外                     沈黙       ★沈黙    OK
(h2) 自ファイル除外               沈黙       ★沈黙    OK
(i) 周辺文字列が 2 回             検出       検出       OK
(j) 同じ出現を二重申告          検出       検出       OK
(k) 周辺文字列が旧名を 2 回含む 検出       検出       OK
(l) 2 申告が同じ出現・別の 1 件が未申告 検出       ★沈黙    OK

退化版 (件数比較だけ) で沈黙してしまうケース: (b) 件数同じで出現すり替え / (l) 2 申告が同じ出現・別の 1 件が未申告
```

**設計の予測どおり、退化版で沈黙するのは `(b)` と `(l)` の 2 つだけ**である
(`(j)` と `(k)` は件数比較でも赤 = 申告の入力契約の負例であって位置集合の必要性の証明ではない、という
設計の位置づけも同時に裏取りされた)。

## 手順 3: N-3 の各条件を 1 つずつ壊す (すべて赤)

| 壊し方 | 結果 | 失敗メッセージ |
|---|---|---|
| 理由を 29 文字にする | failed (4/5) | `申告の理由が短すぎる: docs/TODO-closed.md / BughuntBillingSeeder` |
| needle から旧名を除く | failed (3/5) | `申告の周辺文字列が旧名をちょうど 1 回含まない: …` (N-1 も赤) |
| needle を 2 回現れる文字列にする | failed (3/5) | `申告の周辺文字列が実物にちょうど 1 回現れない: …` (N-1 も赤) |
| 申告を 1 件消す | failed (3/5) | `申告の本数が実出現数と合わない: docs/TODO-closed.md / FakeExternalsServiceProvider` + N-1 の `申告外の出現` |
| 台帳へ存在しないパスを足す | failed (4/5) | 申告台帳のキー一致で赤 (`docs/NO-SUCH-RECORD.md`) |

## 手順 4: N-2 の正の対照を壊す (すべて赤)

| 壊し方 | 結果 | 失敗メッセージ |
|---|---|---|
| 家系名番兵のパスを存在しないものにする | failed (4/5) | `Failed asserting that an array contains 'database/seeders/NoSuchSeederFile.php'` |
| 家系名を実在しない名前にする | failed (4/5) | 写像と番兵キーの一致 / 番兵の内容照合で赤 |

## 手順 5: 最終形は緑

```
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":68,"duration_ms":850}
```

母集団 10066 ファイル / 違反 0 件 / 約 0.9 秒。
