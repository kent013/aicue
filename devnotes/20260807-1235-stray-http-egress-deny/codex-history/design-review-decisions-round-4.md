# 対応マトリクス: design-review Round 4

全体判定 **CHANGES_REQUESTED**。[Critical] ゼロ。必須修正 1 点 + [Suggestion] 2 件。
**すべて対応した** (反論・見送りゼロ)。S1 / S2 / S3 / S5 / S6 は APPROVE 済み。

Round 2 → 3 → 4 と S4 の解析方式に指摘が続いたため、**個別の穴を塞ぐのをやめ、
解析基盤そのものを「正規化した文字列 + 括弧カウント」から「PhpToken 列」へ作り直した**。

## [Warning] S4: `beforeEach(...)` の引数内にある任意の `function` を拾う設計では、引数そのものが closure である保証がない
- 判断: **対応する** (指摘どおり。加えて解析方式を根本から変更)
- 根拠: 指摘のとおり `->beforeEach(wrap(function () { install(...); }))` は
  `beforeEach` に `wrap(...)` の**戻り値**を渡しており、その closure が Pest の hook として
  登録される保証は無い。配列や別関数呼び出しの内部にある closure も同様に拾えてしまう。
  gate の主張は「レーン既定として install されている」なので、hook に**直接**渡された
  closure 以外を本体と認めてはならない。
  さらに Round 2〜4 で指摘が続いた 3 つの穴
  (a) literal 中の括弧で対応を誤認 / (b) literal 中の `function` をキーワードと誤認 /
  (c) 名前と `(` の間の空白・コメントで判定を外す
  は、いずれも「PHP ソースを文字列として扱う」ことに起因する。個別に塞ぎ続けるより
  **トークン列で扱えば穴の種類が構造的に消える**と判断した。
- 対応内容: S4 の純関数群を全面的に置き換えた。
  - `strayHttpEgressCode()` (文字列正規化) / `strayHttpEgressBalancedInner()` (文字列上の括弧カウント) /
    `strayHttpEgressCallOpenParen()` / `strayHttpEgressClosureBody()` を**廃止**。
  - 新設: `strayHttpEgressTokens()` (コメント除去済みトークン列) /
    `strayHttpEgressNextSignificant()` (次の有意トークン) /
    `strayHttpEgressMatchingIndex()` (トークン列上の対応括弧) /
    `strayHttpEgressLaneChunks(list<PhpToken>)` /
    **`strayHttpEgressHookBody(array $tokens, string $hook): ?array`** /
    `strayHttpEgressCallsGuard(array $tokens, string $method): bool`。
  - `strayHttpEgressHookBody()` の契約を Codex の修正案どおり 4 段で明記:
    (1) `->` + `T_STRING($hook)` の次の有意トークンが `(`、
    (2) その `(` の**次の有意トークン**が `T_FUNCTION`、または `T_STATIC` に続く `T_FUNCTION`
        (それ以外は null = fail-closed。`wrap(...)` も `$callback` 変数渡しも弾かれる)、
    (3) `T_FUNCTION` に対応する closure 本体の `{` を深度で閉じて本体を返す、
    (4) closure の `}` の次の有意トークンが (1) の `(` に対応する `)` であること
        (= 引数は closure ちょうど 1 個。カンマ区切りの追加引数は**許可しない**と契約に明記)。
  - アロー関数 `fn () => …` は**受け付けない** (null = fail-closed) ことを契約に明記。
    レーン配線は複数文を要するのでブロック本体が必須であり、2 形をパースする価値が無い。
    将来 `fn` で書きたくなったら gate が赤くなり、設計判断として必ず表面化する。
  - `strayHttpEgressLaneViolations()` の契約を「hook body が null なら違反 (fail-closed)」に更新。
  - 負のコントロール「hook 引数がネストした closure の場合を配線と認めない」を追加
    (Codex 提示の `wrap(...)` fixture + `$callback` 変数渡し + アロー関数の 3 形)。
  - S4 リスク表の「PHP の新しい文字列系トークン」行を、トークン走査前提に書き換え。
  - mutation 手順に **M10** (`->beforeEach(wrap(function () {...}))` へ変更 → gate 赤) を追加。

## [Suggestion] S4: `function` を単なる文字列検索で判定しないこと (literal 中の `function` に一致する)
- 判断: **対応する**
- 根拠: 上記の方式変更で構造的に解決するが、実装者が「トークン列を文字列に戻してから
  grep する」誘惑に負けないよう、契約とテストの両方で固定する必要がある。
- 対応内容:
  - `strayHttpEgressCallsGuard()` の契約に
    「`T_STRING('StrayHttpRequestGuard')` → `T_DOUBLE_COLON` → `T_STRING($method)` →
     次の有意トークンが `(`」というトークン並びでの判定を明記し、
    「文字列 grep にしないのが load-bearing」と理由を添えた。
  - S4 の PHPStan チェックリストに
    「トークン判定は `$token->is(T_FUNCTION)` / `$token->id` で行い、`text` の文字列比較で
     キーワードを判定しない (記号トークンのみ `text` 比較可)」を追加。
  - 負のコントロール**「文字列リテラル中の install 記述では配線と認めない」**を新設
    (`$todo = 'StrayHttpRequestGuard::install($this->app);';` だけの hook が違反になること)。
  - 同様に opt-out 側にも「文字列リテラル内の記述は opt-out ではない」assertion を追加。

## [Suggestion] S4: 「負のコントロール計 11 本」と実際の一覧の件数が合っていない
- 判断: **対応する**
- 根拠: 設計書内の自己矛盾は実装者を迷わせる。
- 対応内容: 負のコントロールを整理・統合したうえで件数を実数に同期した
  (本体テスト 6 本 + 負のコントロール 12 本 = 計 18 本)。
  コメントブロック内・テスト計画の該当箇所も同じ数字に更新した。

## S1 / S2 / S3 / S5 / S6 (Round 4 で APPROVE)
- 判断: **見送る** (対応不要)
- 根拠: Round 3 までの指摘の解消を確認済みとの評価。追加変更は入れない。
