# 対応マトリクス: design-review Round 5 (最終ラウンド)

全体判定 **CHANGES_REQUESTED**。[Critical] ゼロ。必須修正 1 点 + [Suggestion] 2 件。
**3 件とも設計に反映済み** (反論・見送りゼロ)。
S1 / S2 / S3 / S5 / S6 は Round 4 に続き APPROVE。

> **合議ループの終了について**: app-design スキルの上限は 5 ラウンドで、本ラウンドが最終。
> Round 5 の指摘は下記のとおりすべて設計へ反映したが、**その修正に対する Codex の
> 再判定は取っていない**。したがって本設計フローの final_verdict は
> **Round 5 の判定 (CHANGES_REQUESTED) をそのまま報告する** (APPROVED を騙らない)。
> 実装 TODO 着手時に、実装レビュー (`app-implement` の impl-review) で本ラウンドの
> 修正が妥当かを再確認すること。

## [Warning] S4: `strayHttpEgressMatchingIndex()` が補間文字列の開始トークンを波括弧の開始として数えていない
- 判断: **対応する**
- 根拠: 指摘は正確かつ致命的。`"value={$json}"` のトークン列は
  `T_ENCAPSED_AND_WHITESPACE("value=")` / `T_CURLY_OPEN("{$")` / `T_VARIABLE` / `}` であり、
  **終端は単独の `}` だが開始は `T_CURLY_OPEN`** という非対称がある。
  PHPStan チェックリストに書いた「記号トークンは `text` 比較でよい」をそのまま適用すると
  `T_CURLY_OPEN`(`text` は `"{$"`) が `'{'` と一致せず開始側に数えられないため、
  深度が片側だけ減り **closure の終端を早く見つける**。
  Round 4 で「補間の `{$x}` は構文トークンの対なので深度は必ず戻る」と書いたのは
  **開始側も `text === '{'` で拾える前提の誤り**だった。Codex の指摘どおり訂正する。
  (なお負のコントロール「JSON 文字列 / 補間 / heredoc で終端を誤認しない」は
   この不具合を実際に検出する = 空振りではない。ただしアルゴリズムの契約側にも
   明記しないと実装者が同じ誤りを踏むため、契約を直す。)
- 対応内容:
  1. `strayHttpEgressMatchingIndex()` の docblock に、波括弧を数えるときの開始側判定を
     `$token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)`
     と明記。終了側は単独 `}` のみ、丸括弧の探索ではこの追加処理を行わないことも明記。
  2. S4 の PHPStan チェックリストの「記号トークンは `text` 比較でよい」に
     **例外条項**を追加 (補間開始トークンは id で判定して開始側に加える。理由付き)。
  3. 単体テスト **`strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない`** を新設。
     Codex 提示の入力
     `'<?php function () { $a = "value={$json}"; guard(); }'` を使い、
     返る対応位置が closure 末尾であること (後ろに有意トークンが残らない) と
     本体に `guard` が含まれること (補間の `}` で切れていない) を直接固定する。
  4. 負のコントロール件数を 12 → 13 (計 19 本) に同期。

## [Suggestion] S4: `strayHttpEgressTokens()` の説明「literal は 1 トークンにまとまる」が不正確
- 判断: **対応する**
- 根拠: 指摘のとおり。補間文字列は複数トークンに分割されるため、「1 トークンに畳まれる」は
  誤り。設計文書に事実と異なる説明を残すと、上記 Warning と同じ誤りを再生産する。
- 対応内容: docblock を
  「**文字列の中身の括弧は文字列系トークンの内側に保持され、構文上の補間境界は
  専用トークン (`T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`) で識別できる**」に書き換え、
  「補間文字列は 1 トークンには畳まれない」ことと具体的なトークン列を明示。
  この非対称の扱いは `strayHttpEgressMatchingIndex()` の契約に書く、と参照を張った。

## [Suggestion] S4: exemption enum の docblock が検出対象を `preventStrayRequests(false)` と書いたまま
- 判断: **対応する**
- 根拠: gate の契約 (引数付き `preventStrayRequests(...)` 全件) と enum の説明が食い違うと、
  分類する人が「literal false だけが対象」と誤解して inventory 登録を怠る。
- 対応内容: `StrayHttpEgressExemption` のクラス docblock に
  「opt-out 呼び出しの定義 (gate の契約と一致させること)」節を追加し、
  `allowStrayRequests(...)` は引数を問わず全件 / `preventStrayRequests(...)` は
  引数があるもの全件 (`$flag` / `(bool) 0` / `prevent: false` も対象) /
  引数ゼロは対象外、を明記した。

## S1 / S2 / S3 / S5 / S6 (Round 5 で APPROVE)
- 判断: **見送る** (対応不要)
- 根拠: Round 4 までの指摘の解消を確認済みとの評価。追加変更は入れない。
