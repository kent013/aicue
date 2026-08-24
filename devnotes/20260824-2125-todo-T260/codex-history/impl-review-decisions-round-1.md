# 対応マトリクス: impl-review Round 1

## [Critical] `analyseFileContext()` の取り込み対応表がファイルに 1 つしか無い (名前空間をまたぐ fail-open)

- 判断: **対応する**
- 根拠: 指摘のとおり実際に見逃す。`classifyFunctionCall()` は `unresolved` の判定より**先に**
  別名表で候補を絞っていたため、2 つ目の名前空間の `use function` が同じ別名を別の完全修飾名へ
  上書きすると、1 つ目の `putenv` 別名呼び出しが候補から外れて `null` を返していた
  (unresolved にすらならない = i12 の直接の穴)。
- 対応内容:
  - `analyseFileContext()` を**名前空間の領域ごと**の文脈 (`regions`) へ書き換えた。
    各領域が自分の `namespace` と取り込み対応表を持ち、`classifyFunctionCall()` は
    呼び出し位置を含む領域 (`regionAt()`) で解決する。
  - 候補判定 (`$isCandidate`) を**上書きされたものも含む全別名の集合** (`aliasKeys`) で行うようにした。
    最終的な対応表だけを見ると同じ穴が残るため。
  - 負例を 2 本追加: 「同じ別名が 2 つ目の名前空間で別の完全修飾関数へ上書きされる形」
    (両方の呼び出しが `unresolved` になること) と
    「別名を `putenv` 以外へ向けた取り込みは名前空間をまたいでも誤検出しないこと」。

## [Critical] 分割代入の lvalue 判定が連想の値の側と参照 target を見逃す

- 判断: **対応する**
- 根拠: `['key' => $_SERVER['K']] = $value;` は直前が `=>`、`[&$_ENV['K']] = $value;` は直前が `&` で、
  どちらも旧実装の要素先頭判定 (`[` / `(` / `,`) に当たらず `null` を返していた。列挙対象の直接書き込みである。
- 対応内容:
  - 判定を `isDestructuringTargetRoot()` へ切り出し、3 条件で見るようにした —
    (1) 要素の先頭 (`[` / `(` / `,` / `=>` の直後。**参照記号を挟んだ直後**も含む)、
    (2) 範囲の根との間に添字の括弧が無い、
    (3) 添字の連鎖の直後が `=>` **でない** (`=>` なら連想の**鍵** = 読み出し)。
  - 参照記号つきの target は `reference_taken` として報告する (書き込みの分類としてはこちらが正しい)。
  - 正例 (連想の値の側 / 参照つき) と負例 (連想の鍵の側) を追加した。

## [Critical] `conditionMatches()` が包含判定で結合関係を見ていない

- 判断: **対応する**
- 根拠: 指摘のとおり `if (! $applied && $other === false)` を `$applied === false` と誤認する。
  動的に検査できない性質 (適用途中の巻き戻り / 復元が最初の失敗で止まらないこと) の
  **唯一の代替保証**なので、緩い判定は保証そのものを空洞にする。
- 対応内容: `conditionEquals()` (条件のトークンの綴りの列を**完全一致**で見る) へ置き換え、
  `restoreStructureIsDeferred()` が `['$applied', '===', 'false']` /
  `['$failed', '!==', '[', ']']` と完全一致で突き合わせるようにした。
  負例 (`! $applied && $other === false`) と正例 (条件の綴りの列の取り出し) を追加した。

## [Warning] `constructions()` の短名末尾一致が AGENTS.md 走査器共通規約 (a) に反する

- 判断: **対応する**
- 根拠: `str_ends_with($name, '\RuntimeException')` は `Vendor\RuntimeException` を誤認する。
  規約 (a) は「クラス参照は完全修飾名で突き合わせる」と定めている。
- 対応内容: `constructions(array $tokens, class-string $declaringClass, class-string $expected)` へ変更し、
  **宣言元ファイルの `use` (クラスの取り込み。group use / 別名を含む) と名前空間を解いた完全修飾名**で
  突き合わせるようにした (`nameResolver()` / `resolveClassName()`)。
  実行時に決まるクラス (`new $class(`) と `new self/static(` は候補に入らないので、
  「ちょうど 1 件」を要求する利用側が偽を返して赤くなる (fail-closed)。この限界は docblock に明記した。
  負例 (`new \Vendor\RuntimeException(...)` が期待クラスと一致しないこと) を追加した。

## [Warning] 走査器の自己検査に上記 2 件を捕捉する負例が無い

- 判断: **対応する**
- 対応内容: 上の 3 つの Critical それぞれに対応する負例を
  `RawEnvDirectWriteScannerTest` (3 本) と `RawEnvGuardStructureTest` (3 本) へ追加した。

## [Warning] `callArguments()` の括弧不整合を自己検査で固定していない (Suggestion 相当)

- 判断: **対応する**
- 対応内容: `fail-closed 6` を追加し、丸括弧が閉じない入力で例外になることを固定した。

## [Warning] h-3 が不完全な `restoreStructureIsDeferred()` を使っている

- 判断: **対応する** (上の Critical 3 の修正で解消)
- 対応内容: `conditionEquals()` 化により、h-3 は「`$applied === false` のときだけ蓄積する」を
  完全一致で固定するようになった。`constructionArgumentMatches()` の署名変更にも追随した。

## [Warning] G4 が自己参照的である

- 判断: **対応する**
- 根拠: 走査側も検査側も同じ定数を使うため、定数を書き換えると除外が黙って広がりうる。
- 対応内容: G4 の先頭で `expect(RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX)->toBe('devnotes/')` を
  独立した期待値として突き合わせるようにした。

## [Warning] D53 の「3 面を触る経路は 1 本だけ」が許可 3 か所と矛盾する

- 判断: **対応する**
- 根拠: 契約テストと `tests/bootstrap.php` は意図的に直接触るし、間接呼び出しは走査の対象外である。
  誇張した主張は AGENTS.md の「保証範囲を誇張しない」に反する。
- 対応内容: D53 の「揃えている不変条件」を
  「走査器が字句として列挙した直接書き込みの形は、許可 3 か所以外に 1 件も現れない」へ改め、
  「3 面を触る経路が 1 本だけ、とは書かない」理由と、正本が走査器の docblock であることを明記した。

## [Warning] `pnpm test` / `pnpm test:packages` の結果が未提示

- 判断: **対応する**
- 対応内容: Round 2 のプロンプトで全 10 コマンドの結果を提示する。
