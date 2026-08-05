# 対応マトリクス: design-review Round 2

Round 2 の指摘は **全 6 件すべて対応**（反論なし）。いずれも実測 / プロトタイプ実走で検証した。

## 施策 6 [Critical] `$callee` が null のまま `strtolower($callee)` に到達し TypeError

- 判断: **対応する**（実装バグ。指摘のとおり）
- 根拠: Round 1 の修正（`$ranges[$callee]` → `$ranges[strtolower($callee)]`）を入れた際に、
  元々あった `if ($callee === null || ! isset(...))` の **null ガードを落としてしまった**。
  ループは全 token を舐めるので、`$this` / `self` 以外の token では `$callee` が null のまま
  到達する。PHP 8.4（`strict_types=1`）では `TypeError`。fixture の最初の token で落ちる。
- 対応内容: `strtolower()` の前に明示的なアーリーリターンを復活させた:

  ```php
  if ($callee === null) {
      continue; // 追跡条件を満たさない token (この分岐が無いと strtolower(null) で TypeError)
  }

  $key = strtolower($callee);
  ```

- 検証: プロトタイプで f4（1 hop 対象外 4 パターン）を実走 → **PASS**
  （修正前はここで TypeError になっていた）

## 施策 6 [Warning] first-class callable `Inertia::render(...)` を呼び出しと誤認する

- 判断: **対応する**
- 根拠: 指摘のとおり。PHP 8.1 の first-class callable は `(` に続く `...` で、
  **Closure を作るだけで実行しない**。これを「Inertia を描画する」証拠と誤認すると
  偽陽性（描画しない route を候補に入れる）になる。
- 対応内容: `documentTitleIsFirstClassCallable()` を新設し、
  `(` の直後の significant token が `T_ELLIPSIS` なら呼び出しと見なさないようにした。
  `Inertia::render(` と `inertia(` の両方の判定に適用。

## 施策 6 [Suggestion] `setPrivateTitle` の callable 参照を除外する正のコントロールも明示追加

- 判断: **対応する**
- 根拠: コメントで「callable 参照 `[$seo, 'setPrivateTitle']` でも通ってしまう」と
  書いたのにテストが無いのは、コメントとテスト契約の不一致。
- 対応内容: 正のコントロール
  **「first-class callable / callable 参照を呼び出しと誤認しない」**を追加。
  `Inertia::render(...)` / `[$seo, 'setPrivateTitle']` / `$named = 'setPrivateTitle'` /
  `[Inertia::class, 'render']` を 1 つの fixture にまとめ、
  `documentTitleBodyRendersInertia` と `documentTitleBodyCallsMethod` の**両方**が
  false になることを固定した。
- 検証: プロトタイプ f5 → **PASS**

## 施策 1 [Warning] テスト計画に旧記述 `safeCalls > 0` が残っている

- 判断: **対応する**
- 根拠: 実装は `methodCalls` に変更済みだが、テスト計画の箇条書きが追随していなかった。
- 対応内容: 「files > 0 かつ **methodCalls > 0**」に更新し、
  「`methodCalls` = `->name(` 形の呼び出し件数。実コードの Carbon 使用有無に依存しない」
  という補足も付けた。

## 施策 1 [Suggestion] `CARBON_OVERFLOW_DYNAMIC_LITERAL_ENABLED` が未参照のデッドコード

- 判断: **対応する（定数を削除）**
- 根拠: 指摘のとおり。分岐で使っておらず常時有効なので、定数の存在が誤解を生む
  （「off にできる」と読める）。
- 対応内容: 定数を削除し、同じ内容を**通常のコメント**として残した
  （「実装は `carbonOverflowCollectFromSource()` の `->{` 分岐が担う」と参照先も明記）。

## 施策 9 [Warning] `META_DYNAMIC_ATTR` が `content={color}` まで禁止し、宣言した契約を超える

- 判断: **対応する**
- 根拠: 指摘のとおり。`/<meta\b[^>]*\{/i` は `<meta name="theme-color" content={color}>` に
  一致してしまう。これは正当な使い方であり、宣言した契約
  「title / description のみ禁止」を超えて落とすのは gate の越権。
- 対応内容: 定数を 2 つに分割し、**`name` の不確定性とスプレッドだけ**に限定した:
  - `META_DYNAMIC_NAME = /<meta\b[^>]*\bname\s*=\s*\{/i`
  - `META_SPREAD_ATTR  = /<meta\b[^>]*\{\s*\.\.\./i`
  正のコントロールに `<meta name="theme-color" content={themeColor}>` を追加。
  「name が静的なら content が式でも許可する」理由もコメントに明記した。

## 施策 9 [Warning] 無引用値の `description\b` は `name=description-like` にも一致する

- 判断: **対応する**
- 根拠: 実測で確認。`-` は非単語文字なので `description` の直後に `\b` が成立し、
  `name=description-like` に**一致してしまう**（誤検出）:

  ```
  "<meta name=description-like content=\"x\">"  旧: true  新: false
  "<meta name=descriptionfoo content=\"x\">"    旧: false 新: false
  "<meta name=description content=\"x\">"       旧: true  新: true
  "<meta name=description>"                     旧: true  新: true
  "<meta name=description />"                   旧: true  新: true
  ```

- 対応内容: 無引用形を `description(?=[\s/>]|$)` に変更し、**属性値の終端**まで確認する
  ようにした。正のコントロールに `name=description-like` を追加。
- 検証: **全 17 ケースを Node で実走して ALL OK**（負 8 / 正 9）

## [誤記] 環境制約表の「Feature（施策 9 の追加テスト）」は施策 8 の誤り

- 判断: **対応する**
- 対応内容: 「Feature (施策 8 の追加テスト / 施策 2 の置換後回帰)」に修正。
  施策 2 の回帰確認も同じレーンなので併記した。

## 追加で行った検証（指摘外）

Round 2 の修正が実走査結果を変えていないことを確認するため、
**修正後の検出器で gate 6 の全 route 走査を再実行**した:

```
inertiaRoutes = 32     ← 空振り検知 (> 0) を満たす
missing       = 4      ← 施策 7 で config へ追加する 4 件と完全一致
unresolvable  = 0      ← allowlist 9 件で解消
```

= 施策 7 を適用すれば gate は green になる。この結果を詳細設計に転記した。
