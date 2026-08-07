# 対応マトリクス: design-review Round 6 (確認ラウンド)

全体判定 **APPROVED**。Critical 0 / Warning 0 / Suggestion 0 (新規指摘なし)。

Round 6 は「Round 5 の指摘への対応が実際に解消しているか」だけを問う確認ラウンドとして
one-shot モード (`--ephemeral`) で実施した。Codex の返答は
`../detailed-review-round-6.md` が正本。

## 実施前の自己検証で見つけた欠陥 (Round 5 の対応に対する追加修正)

Round 5 の指摘は「`T_CURLY_OPEN` の `text` は `"{$"` なので `text === '{'` に一致しない」
という前提に立っていた。**この前提事実は誤り**であることを PHP 8.4.24 の
`PhpToken::tokenize()` 実出力で確認した。

| トークン | 実測の `text` | `text === '{'` で拾えるか |
|---|---|---|
| `T_CURLY_OPEN` (`"…{$json}…"`) | `"{"` | **拾える** |
| `T_DOLLAR_OPEN_CURLY_BRACES` (`"…${json}…"`) | `"${"` | **拾えない** |

修正前 / 修正後の `strayHttpEgressMatchingIndex()` を両方書いて実測した結果:

| 入力 | 修正前 (text 比較のみ) | 修正後 (id 判定を追加) |
|---|---|---|
| `<?php function () { $a = "value={$json}"; guard(); }` | close=25 (closure 末尾。**正しい**) | close=25 |
| `<?php function () { $a = "value=${json}"; guard(); }` | close=16 (補間の `}`。**誤り**) | close=25 |

帰結:

1. 修正 (id 判定の追加) 自体は正しく、そのまま採用する。実際に深度が壊れるのは
   `T_DOLLAR_OPEN_CURLY_BRACES` 側である。
2. **Round 5 が提示した単体テスト入力 (`{$json}` 形) は空振りテストだった。**
   修正前の実装でも緑になる。Round 5 本文の「提示された負のコントロールはこの問題により
   実装時に赤くなるはず」という評価も、当該 fixture の補間例が `{$json}` 形だったため
   同じ理由で成り立たない。本設計は「空振り gate を緑にしない」を一貫方針としているので、
   **回帰入力を `${json}` 形 + `{$json}` 形の 2 本立てに強化した**。

この反証と帰結を Round 6 プロンプト §3 に明示して検算を求めた
→ Codex は「提示された事実認定は妥当」「帰結も正しい」「対応は適切」と回答。

## [Warning] S4: `strayHttpEgressMatchingIndex()` が補間開始トークンを数えていない

- Round 6 判定: **解消**
- 反映内容:
  1. `strayHttpEgressMatchingIndex()` の docblock に開始側判定
     `text === '{' || is(T_CURLY_OPEN) || is(T_DOLLAR_OPEN_CURLY_BRACES)` を契約として明記。
     **実測した `text` 値と「実際に壊れるのは `${json}` 形だけ」を事実として併記**し、
     「回帰入力は `${json}` 形でなければ空振りする」という警告を契約に埋め込んだ。
     終了側は単独 `}` のみ / 丸括弧の探索ではこの追加処理を行わないことも明記。
     `${...}` 補間が PHP で将来削除された場合はこの回帰テストが赤くなる (望ましい失敗) と注記。
  2. PHPStan チェックリストの「記号トークンは `text` 比較でよい」に例外条項を追加
     (補間開始トークンは id 判定。実測値と「回帰入力は `${json}` 形」の注意付き)。
  3. 単体テスト `strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない` を新設。
     入力を `${json}` 形 (赤を出せる本体) と `{$json}` 形 (保険) の 2 本に強化し、
     ラベル付きループで両方を固定。
  4. 負のコントロール `closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない` の
     fixture に `$legacyInterpolated = "value=${json}";` を追加
     (これが無いとこの負のコントロール自体が空振りする)。
  5. テスト本数を本体 6 + 負のコントロール 13 = 計 19 本に同期。

## [Suggestion] S4: `strayHttpEgressTokens()` の説明が不正確

- Round 6 判定: **解消**
- 反映内容: docblock を「文字列の中身の括弧は文字列系トークンの内側に保持され、
  構文上の補間境界は専用トークンで識別できる」に書き換え、
  `{$json}` / `${json}` 両形の実トークン列を併記。
  「開始側は専用トークン 2 種・終端は単独 `}`」という非対称を明示し、
  詳細 (text 値の実測) は `strayHttpEgressMatchingIndex()` の契約へ参照を張った。

## [Suggestion] S4: exemption enum の docblock が `preventStrayRequests(false)` のまま

- Round 6 判定: **解消**
- 反映内容: `StrayHttpEgressExemption` のクラス docblock に
  「opt-out 呼び出しの定義 (gate の契約と一致させること)」節を追加。
  `allowStrayRequests(...)` は引数を問わず全件 /
  `preventStrayRequests(...)` は引数があるもの全件 (`$flag` / `(bool) 0` / `prevent: false` も対象) /
  引数ゼロは対象外、を明記した。

## Round 6 で新たに出た指摘

**なし**。「この対応が作った新たな欠陥」への Codex 回答は「なし」。

## 結論

詳細設計 `detailed-design.md` は **Round 6 で APPROVED**。
Critical は全 6 ラウンドを通してゼロ。Warning / Suggestion は計 20 件をすべて対応
(反論・見送りゼロ)。実装 TODO へ進んでよい。
