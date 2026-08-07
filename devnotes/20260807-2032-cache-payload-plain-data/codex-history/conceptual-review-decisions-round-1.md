# 対応マトリクス: conceptual-review Round 1

全体判定は **APPROVED** (Warning 付き)。Critical はゼロ。Warning はすべて詳細設計 (Phase 2) に反映する。
Round 2 は回さず、指摘の反映先を詳細設計書の該当施策に落とし込む。

## [Warning] 完了報告時のテスト実行記録 (観点 2: 禁止事項 1)

- 判断: **対応する**
- 根拠: 素の main では緑になる予防 gate なので、「赤を一度も見ずに緑だから完了」は禁止事項 1 の
  実質的な回避になる。AGENTS.md 思考原則 5 (テストファースト) とも一致する。
- 対応内容: 詳細設計に **mutation チェックリスト (M1〜M10)** を置き、worktree 内で 1 件ずつ注入して
  赤を確認 → revert する手順を実装受入条件に格上げする。実装報告に mutation ごとの
  「赤化したか / どのテストが落ちたか」を記録することを明記した。

## [Warning] receiver 解決のカテゴリ分けと `Cache::lock()->get()` の誤分類 (観点 3)

- 判断: **対応する**
- 根拠: `Cache::lock('k', 10)->get()` の `get` を「キャッシュ read」と数えると語彙分類 (L1) の
  母集団が汚れ、`->block(fn () => ...)` の中の呼び出しも巻き込みかねない。
- 対応内容: `lock` / `restoreLock` を **terminal** と定義し、以降の chain を**辿らない**ことを
  設計で明示した (Lock オブジェクトの語彙 `block` / `get` / `release` / `owner` / `forceRelease` は
  キャッシュ語彙ではない)。正のコントロール fixture に
  `Cache::lock(...)->block(...)` / `->get()` / `->release()` を入れ、
  「lock 由来の `get` を write にも read にも数えない」ことを恒久固定する。

## [Warning] `tests/` を走査対象に含めると gate 自身の fixture が混入する (観点 3)

- 判断: **対応する** (ただし指摘の前提は一部訂正する)
- 根拠: 本 gate は `PhpToken::tokenize` で **code token だけ**を見る。fixture は nowdoc
  (`<<<'PHP' … PHP;`) の中にあり、PHP のトークナイザでは `T_ENCAPSED_AND_WHITESPACE` /
  `T_CONSTANT_ENCAPSED_STRING` になるため、fixture 内の `Cache::put(...)` は**コードとして走査されない**。
  これは `CarbonOverflowArithmeticGateTest` が regex ではなく token を使う理由そのもので、
  同テストの「正のコントロール: コメント・文字列中の記述を誤検出しない」が既に実証している。
  したがって fixture ディレクトリの分離や走査除外という**追加機構は不要** (思考原則 2)。
- 対応内容: 混入しないことを推論で済ませず、**自己参照コントロール**をテストとして置く:
  「本 gate ファイル自身を走査したとき、書き込み経路 0 件・surface hit 0 件」を assert する。
  将来 gate 自身に code として cache 呼び出しを書いたら落ちる = 正しい挙動。
  加えて L3 の「文字列経由」検出規則は callee を `app` / `resolve` / `make` に限定し、
  引数が完全一致 `'cache'` / `'cache.store'` のときだけ hit させる (裸の `'cache'` 文字列を
  拾わないので、config キー `'cache.serializable_classes'` や無関係な文字列で誤爆しない)。

## [Warning] L2 が「場所」だけを exact-fit しても payload が素データであることは保証しない (観点 4)

- 判断: **対応する** (指摘の核心。設計の弱点を正しく突いている)
- 根拠: 「登録さえすれば object を入れられる」なら gate は儀式になる。一方で payload 式が
  素データかを静的に判定するのは (変数・メソッド戻り値の追跡が要り) 費用対効果が合わない。
- 対応内容: inventory entry を 4 フィールドの構造体にした:
  `method` / `count` (exact-fit) / `payload` (どの式が何を返すか) / `proof`
  (往復を固定している単体テストのパス) / `rationale` (30 文字以上)。
  gate は **`proof` のファイルが実在すること**を assert する。これで「新しい書き込み経路を足すなら
  往復の単体テストを書け」が機械強制になり、台帳標準形の「往復を単体テストで固定する」と
  1:1 で対応する。静的に payload の型までは見ない**という限界**は gate 冒頭に明記する。

## [Warning] L3 の摩擦。fail message に復旧手順を書くこと (観点 5)

- 判断: **対応する**
- 根拠: deny-by-default gate は「落ちたときに何を書けばよいか」が message に無いと、
  読んだ人が gate を消す方向に動く。
- 対応内容: L3 の fail message に「payload を書くなら L2 inventory へ / lock だけなら
  surface inventory に `lock-only` として / read だけなら `read-only` として登録する」という
  分岐付きの復旧手順を入れる設計にした (メッセージ文面を詳細設計に記載)。

## [Warning] PHPStan level 10: token 走査ヘルパの shape が曖昧になりやすい (観点 7)

- 判断: **対応する**
- 根拠: `tests/` も level 10 の対象。array shape を書かないと `mixed` が伝播して落ちる。
- 対応内容: `@phpstan-type` で `CacheCallSite` / `CacheScanResult` / `CacheWriteInventoryEntry` の
  3 shape を定義し、全ヘルパ関数の戻り値に付ける方針を詳細設計に記載した。
  `PhpToken::tokenize()` の戻り値は `list<PhpToken>` として明示 (既存 gate と同じ)。
  S2 の単体テストは `Webmozart\Assert\InvalidArgumentException` を**例外型まで**固定する。

## [Suggestion] 使命への貢献は間接 / スコープの絞り方は適切 / enum 昇格見送りは妥当

- 判断: **そのまま採用** (変更なし)
- 根拠: 概念設計の主張と一致。inventory の enum 昇格見送りは思考原則 2 に沿う。

## 判断論点への回答 (Codex 回答の受領)

1. L3 は採用する (静的解析の穴を補う粗い網として正当と判定された)
2. `tests/` は走査対象に**含める**。fixture 混入は token 方式で構造的に起きず、
   自己参照コントロールで固定する
3. 実行時検出は併用しない (空振りする)
4. inventory は gate 内 const のまま
5. 「今やるべきでない」理由は無い → 設計を完成させ実装へ進む
