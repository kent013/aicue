# 対応マトリクス: design-review Round 3

全体判定 **CHANGES_REQUESTED**。[Critical] ゼロ。必須修正 1 点 + [Suggestion] 1 件。
**両方とも対応した** (反論・見送りゼロ)。S1 / S2 / S3 / S5 / S6 は APPROVE 済み。

## [Warning] S4: `strayHttpEgressClosureBody()` の探索範囲が `beforeEach(...)` の引数内に閉じていない
- 判断: **対応する**
- 根拠: 指摘のとおり致命的な偽グリーン要因。
  `->beforeEach($callback)->use(function () { install(...); })` のように
  beforeEach の引数が closure でない場合、「`$openOffset` 以降で最初の `{`」を探す実装は
  **後続の別 closure を beforeEach 本体として拾う**。
  gate の目的は「レーン既定であることの保証」なので、拾ってはいけないものを拾う実装は
  gate が無いのと同じになる。
- 対応内容: `strayHttpEgressClosureBody()` の手順を 3 段に作り直した。
  1. `strayHttpEgressBalancedInner($code, $openOffset, '(', ')')` で
     **当該呼び出しの引数全体**を先に取り出す (探索範囲がここで閉じる)。
  2. その引数**内**で `function` トークンに続く最初の `{` を探す。
     引数が closure でなければ `function` が無いので **null を返す**。
  3. その `{` から `strayHttpEgressBalancedInner(..., '{', '}')` で本体を得る。
  併せて `strayHttpEgressLaneViolations()` の契約に
  「`strayHttpEgressClosureBody()` が null なら**違反として扱う** (fail-closed)」を明記した。
  負のコントロール「beforeEach の後続 closure を本体と誤認しない」
  (Codex 提示の fixture をそのまま採用) を追加した。

## [Suggestion] S4: opt-out 検出でメソッド名と `(` の間の空白・改行・除去済みコメントを許容する
- 判断: **対応する**
- 根拠: `Http::preventStrayRequests /* reason */ (false);` は PHP として有効で、
  `strayHttpEgressCode()` がコメントを空にする以上、名前の直後が `(` でない形が現実に生じる。
  deny-by-default gate が書式の揺れで opt-out を見逃すのは穴。
- 対応内容: 純関数 `strayHttpEgressCallOpenParen(string $code, int $nameEndOffset): ?int` を新設し、
  「名前の直後から**最初の非空白文字**を探し、それが `(` のときだけ引数解析へ進む」形にした。
  `strayHttpEgressOptOutSites()` / `strayHttpEgressIsOptOutSource()` の契約もこれに更新。
  負のコントロール「メソッド名と `(` の間に空白/コメントを挟んだ opt-out を検出する」を追加
  (コメント挟み / 改行挟み / 改行を跨いだ**無引数**は false のまま、の 3 形)。

## [参考] 正規化方式への Codex 回答 (heredoc ラベル / property hooks / first-class callable / `${expr}`)
- 判断: **対応する** (回答を受けてリスク表に 1 行追加)
- 根拠: Codex の回答どおり、現行 PHP 8.4 の構文ではいずれも深度カウントを壊さない。
  ただし「残る括弧はすべて構文上の括弧」という前提は、**PHP が新しい文字列系トークンを
  追加したら再確認が要る**という指摘は正しい。前提を明文化せずに置くと、
  次の PHP バージョン更新時に誰も見直さない。
- 対応内容: S4 のリスク表に
  「将来 PHP が新しい文字列系トークンを追加し、正規化の前提が崩れる」行を追加し、
  緩和策として「負のコントロールが回帰を捕まえる / PHP バージョンを上げる際は
  `strayHttpEgressCode()` の無害化対象トークン一覧を再確認する」を記録した。

## S1 / S2 / S3 / S5 / S6 (Round 3 で APPROVE)
- 判断: **見送る** (対応不要)
- 根拠: Round 2 の必須修正 2 点の解消を確認済みとの評価。追加変更は入れない。
