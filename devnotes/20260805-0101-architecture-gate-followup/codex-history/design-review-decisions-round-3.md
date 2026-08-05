# 対応マトリクス: design-review Round 3

Round 3 の指摘は **全 3 件すべて対応**（反論なし）。いずれも実測 / プロトタイプ実走で検証した。

## 施策 6 [Warning] `documentTitleIsFirstClassCallable()` が引数アンパック `(...$args)` を誤認する

- 判断: **対応する**
- 根拠: 指摘のとおり。実測で tokenizer 出力を確認した:

  ```
  Inertia::render(...)       → '(' T_ELLIPSIS ')'             = first-class callable
  Inertia::render(...$args)  → '(' T_ELLIPSIS T_VARIABLE ')'  = 通常呼び出し
  ```

  `T_ELLIPSIS` の有無だけで判定していたため、`Inertia::render(...$args)` を
  「呼び出していない」と誤認し、**Inertia を描画する route を取りこぼす**（偽陰性）。
  Round 2 の修正が別方向の穴を開けていた。
- 対応内容: `...` の**次の significant token が `)` の場合だけ** first-class callable と
  判定するようにした。docblock に両者のトークン列を並べて理由を固定。
- 検証: プロトタイプで
  「unpack (`...$args` は通常呼び出し)」→ `rendersInertia`/`callsMethod` が両方 true = **PASS**

## 施策 6 [Warning] `$seo->setPrivateTitle(...)` / `$this->applyTitle(...)` も呼び出しと誤認する

- 判断: **対応する**
- 根拠: 指摘のとおり。Round 2 では `documentTitleBodyRendersInertia()` にしか
  first-class callable 判定を入れていなかった。`setPrivateTitle(...)` /
  1 hop の `applyTitle(...)` は Closure を作るだけで実行しないのに
  「タイトルを供給済み」と判定してしまう = **取りこぼす方向の偽陰性**（gate の最悪の失敗）。
- 対応内容: `documentTitleIsFirstClassCallable()` を
  - `documentTitleBodyCallsMethod()` の括弧判定
  - `documentTitleOneHopHasSetPrivateTitle()` の `$this->name(` / `self::name(` 判定
  の**両方**にも適用した（計 3 形態すべて）。
  正のコントロール fixture に `$seo->setPrivateTitle(...)` / `$this->applyTitle(...)` /
  `self::applyTitle(...)` の 3 形態を追加し、
  `rendersInertia` / `callsMethod` / `oneHop` が**すべて false** になることを固定。
  さらに**負のコントロール**「引数アンパック `(...$args)` は通常呼び出しとして扱う」を新設。
- 検証: プロトタイプで
  「FCC (render/setPrivateTitle/1hop すべて false)」= **PASS**、
  「unpack」= **PASS**。
  加えて **gate 6 の実 route 走査を再実行**し、結果が不変であることを確認:
  `inertiaRoutes=32 / missing=4 / unresolvable=0`

## 施策 9 [Suggestion] テスト計画のケース数と `meta[dynamic-attr]` の表記が実装と不一致

- 判断: **対応する**（文書のみの追随）
- 根拠: 指摘のとおり。Round 2 でケースを増やし定数名も変えたのに、
  テスト計画とリスク節が追随していなかった。
- 対応内容:
  - 「全 15 ケース」→「**全 17 ケース（負 8 / 正 9）**」
  - リスク節の `meta[dynamic-attr]` → **`meta[dynamic-name]`**（実装の識別子に一致）
  - 負のコントロールの説明を「式・スプレッド属性」→「**name が式**・スプレッド属性」に修正
    （`content={...}` は禁止しないという Round 2 の契約変更を文言にも反映）
  - 正のコントロールの内訳を実際のケース（紛らわしい語 3 種 /
    name 静的 + content 式）に合わせて記述
  - 施策 6 のテスト計画の実走検証注記も「f1〜f5 + first-class callable /
    引数アンパックの 2 ケース」に更新
