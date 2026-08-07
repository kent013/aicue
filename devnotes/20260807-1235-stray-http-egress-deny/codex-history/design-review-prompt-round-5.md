# design-review Round 5 (最終)

Round 4 の必須修正 1 点 + Suggestion 2 件をすべて対応しました (反論・見送りゼロ)。

Round 2 → 3 → 4 と S4 の解析方式に指摘が続いたため、個別の穴を塞ぐのをやめ、
**解析基盤そのものを「正規化した文字列 + 括弧カウント」から「PhpToken 列」へ作り直しました**。
これにより (a) literal 中の括弧、(b) literal 中の `function` という語、(c) 名前と `(` の間の空白/コメント、
(d) hook 引数がネストした closure、の 4 種の穴が構造的に消えています。

---

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

---

## 修正後の S4 全文 (詳細設計書からの抜粋)

## S4: deny-by-default 目録型 Architecture gate

### 変更箇所

- ファイル: `tests/Architecture/StrayHttpEgressLaneGateTest.php` (**新規**)
- ファイル: `tests/Support/Security/StrayHttpEgressExemption.php` (**新規**)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 本 gate 自体が新規。既存 Architecture テストの変更は**なし**
- アプリコード: **なし**
  (exemption enum は `app/Enums/Security/` ではなく `tests/Support/Security/` に置く。
   分類対象が「テスト側の opt-out 箇所」でアプリのドメインではないため。
   前例: `Tests\Support\Security\PrimaryKeyPredicateKind`)

### 現行コード

存在しない (新規)。同型の見本は `tests/Architecture/GlobalTestLockInventoryTest.php` (425 行、
純関数 + fixture ベースの負のコントロール) と `tests/Architecture/ThrottleCoverageInventoryTest.php`
(型付き enum + 30 文字以上の根拠 + exact-fit cap)。

### 変更後コード

#### `tests/Support/Security/StrayHttpEgressExemption.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 「テストレーンの HTTP 出口既定拒否を opt-out することが正しい」と裁定した理由の分類。
 *
 * tests/Architecture/StrayHttpEgressLaneGateTest.php が deny-by-default で
 * 「opt-out 呼び出し (allowStrayRequests / preventStrayRequests(false)) を持つファイルは
 *  本 enum + 30 文字以上の具体的根拠付きで inventory に登録済みであること」を機械強制する。
 *
 * ★case は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「opt-out してはいけない箇所」である。
 *
 * ★case を 1 つしか持たないのは意図的 (今必要なものだけ作る)。
 *   2 つ目の opt-out が現れたときに「新しい case を足す差分」として必ず表面化し、
 *   その場で「そもそも opt-out すべきか」を再検討させるのが狙い。
 */
enum StrayHttpEgressExemption: string
{
    /**
     * レーン既定 guard そのものの定義箇所。
     *
     * 適用条件 (すべて満たすこと):
     *  - そのファイルが `Http::allowStrayRequests(...)` を呼ぶ唯一の理由が
     *    「レーン既定の許可集合を設定すること」である
     *  - 許可集合が `StrayHttpRequestGuard::ALLOWED_URL_PATTERNS` 定数 1 か所に閉じている
     *  - `allowStrayRequests(null)` / `preventStrayRequests(false)` を**呼ばない**
     *    (= 既定拒否そのものを外さない)
     */
    case GuardDefinitionSite = 'guard_definition_site';
}
```

#### `tests/Architecture/StrayHttpEgressLaneGateTest.php` (骨格)

```php
<?php

declare(strict_types=1);

use Tests\Support\Security\StrayHttpEgressExemption;
use Tests\Support\StrayHttpRequestGuard;

/*
 * Architecture invariant: テストレーンの HTTP 出口が既定拒否であること (deny-by-default)。
 *
 * 背景 (SoT = devnotes/20260807-1235-stray-http-egress-deny/conceptual-design.md):
 * 裁定 AG-105 は「テストレーンの既定として Http::preventStrayRequests() を常時有効にする」
 * を必須とし、「テスト内で局所的に張って外す形は既定と認めない」と明示している。
 * 本 gate は tests/Pest.php をソース走査して**レーン既定であること**を機械強制する。
 *
 * ★解析は PhpToken でコメントを落としてから行う。文字列 grep にすると
 *   「本 gate の説明コメント」自身や tests/Pest.php の日本語コメントで偽緑になる
 *   (PcreUnicodeModifierGateTest / GlobalTestLockInventoryTest と同じ作法)。
 *
 * ★本 gate は「素の main では赤にならない」種類のテストである。空振りしていないことは
 *   (a) fixture ベースの負のコントロール (下部) と
 *   (b) 実装時の mutation 手順 (詳細設計 S4 §mutation) の 2 本で担保する。
 */

/** 既定配線が必須のレーン。 */
const STRAY_HTTP_EGRESS_REQUIRED_LANES = ['Feature', 'Unit', 'Architecture', 'Browser'];

/** opt-out 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const STRAY_HTTP_EGRESS_REASON_MIN_LENGTH = 30;

/**
 * exemption 件数の上限。**現在値ちょうど** (exact fit)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに opt-out できる枠」
 *   になる。exact fit なら次の 1 本が必ずこの数値を変える差分として現れる。
 */
const STRAY_HTTP_EGRESS_EXEMPTION_CAP = 1;

/**
 * 走査対象から外すファイル (走査器自身)。
 * ★本 gate は検査語 (`allowStrayRequests` 等) をパターン文字列として持つため、
 *   自分を走査すると必ず自己一致する。GlobalTestLockInventoryTest が
 *   「ライブラリ本体は対象外」としたのと同じ扱い。
 */
const STRAY_HTTP_EGRESS_SCANNER_SELF = 'tests/Architecture/StrayHttpEgressLaneGateTest.php';

/**
 * opt-out 呼び出しを持つことが正しいと裁定したファイルの inventory
 * (型付き + 具体的根拠必須、単一 source of truth)。
 *
 * @return array<string, array{StrayHttpEgressExemption, non-empty-string}>
 */
function strayHttpEgressOptOutExemptions(): array
{
    return [
        'tests/Support/StrayHttpRequestGuard.php' => [
            StrayHttpEgressExemption::GuardDefinitionSite,
            'レーン既定 guard 本体。Http::allowStrayRequests() を呼ぶのは ALLOWED_URL_PATTERNS '
            .'(loopback リテラルのみ) を設定するためであり、allowStrayRequests(null) や '
            .'preventStrayRequests(false) は呼ばない = 既定拒否そのものは外していない。',
        ],
    ];
}

/**
 * PHP ソースを **トークン列** へ落とす (純関数)。以降の解析はすべてこの列の上で行う。
 *
 * `PhpToken::tokenize()` した結果から `T_COMMENT` / `T_DOC_COMMENT` を取り除くだけ
 * (空白は保持する — 位置関係の判定には使わないが、抜き出した本体を人間が読める形で
 *  エラーメッセージに載せるため)。
 *
 * ★**文字列 grep も、正規化した文字列に対する括弧カウントもやめた**
 *   (Codex design-review Round 2〜4 の一連の Warning)。文字列に落とす方式は
 *   (a) literal 中の `{` `}` `(` `)` で括弧の対応を誤認する、
 *   (b) literal 中の `function` という語をキーワードと誤認する、
 *   (c) 名前と `(` の間の空白/コメントで判定を外す、
 *   という 3 種類の穴を**個別に塞ぎ続ける**必要がある。トークン列で扱えば
 *   literal は 1 個の `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` に
 *   まとまり、キーワードは `T_FUNCTION` / `T_STATIC` の**トークン ID** で一意に判定でき、
 *   空白は「有意トークン」を辿るだけで自然に飛ばせる。穴の種類が構造的に消える。
 *
 * @return list<PhpToken>
 */
function strayHttpEgressTokens(string $source): array { /* tokenize → T_COMMENT / T_DOC_COMMENT を除去 */ }

/**
 * `$from` 以降で最初の**有意トークン** (`T_WHITESPACE` 以外) の index を返す (純関数)。
 *
 * @param  list<PhpToken>  $tokens
 */
function strayHttpEgressNextSignificant(array $tokens, int $from): ?int { /* … */ }

/**
 * `$openIndex` (開き括弧のトークン index) に対応する閉じ括弧の index を返す (純関数)。
 * トークン列上で深度を数えるため、literal 中の括弧は 1 個のトークンに畳まれていて影響しない。
 *
 * @param  list<PhpToken>  $tokens
 * @param  non-empty-string  $open   `(` または `{`
 * @param  non-empty-string  $close  `)` または `}`
 */
function strayHttpEgressMatchingIndex(array $tokens, int $openIndex, string $open, string $close): ?int { /* … */ }

/**
 * トークン列を `pest()->extend(` 単位のチャンクへ分解する (純関数)。
 * レーン名は `->in(` の引数にある `T_CONSTANT_ENCAPSED_STRING` から取る
 * (文字列 grep ではなくトークンから取るので、コメント内の `->in('Feature')` に反応しない)。
 *
 * @param  list<PhpToken>  $tokens
 * @return list<array{lanes: list<string>, tokens: list<PhpToken>}>
 */
function strayHttpEgressLaneChunks(array $tokens): array { /* … */ }

/**
 * chunk 内の `->{$hook}(...)` の**引数が直接 closure リテラルであること**を確認し、
 * その本体トークン列を返す (純関数)。確認できなければ **null を返して fail-closed** にする。
 *
 * 契約 (Codex design-review Round 4 の Warning への対応):
 *  1. `->` + `T_STRING($hook)` の並びを見つけ、その次の有意トークンが `(` であること
 *     (名前と `(` の間の空白は有意トークン走査で自然に飛ばす)。
 *  2. `(` の**次の有意トークン**が `T_FUNCTION`、または `T_STATIC` に続く `T_FUNCTION` であること。
 *     ★ここが要。「引数**内**のどこかにある `function` を拾う」実装だと
 *       `->beforeEach(wrap(function () { install(...); }))` を配線済みと誤認する
 *       (`beforeEach` に渡るのは `wrap(...)` の戻り値であり、その closure が
 *        Pest の hook として登録される保証は無い)。
 *  3. その `T_FUNCTION` に対応する closure 本体の `{` を
 *     `strayHttpEgressMatchingIndex()` で閉じ、本体トークン列を返す。
 *  4. closure の `}` の**次の有意トークン**が、1 で開いた `(` に対応する `)` であること
 *     (= 引数は closure ちょうど 1 個。カンマ区切りの追加引数は**許可しない**)。
 *
 * ★アロー関数 `fn () => …` は**受け付けない** (null を返す)。
 *   レーン配線は複数文 (install / flush + reset) を要するのでブロック本体が必須であり、
 *   2 つの closure 形を両方パースする価値が無い (今必要なものだけ作る)。
 *   将来 `fn` で書きたくなったら、gate が赤くなるので必ず設計判断として表面化する。
 *
 * @param  list<PhpToken>  $tokens  chunk のトークン列
 * @param  non-empty-string  $hook   'beforeEach' または 'afterEach'
 * @return list<PhpToken>|null
 */
function strayHttpEgressHookBody(array $tokens, string $hook): ?array { /* 上記 1〜4 */ }

/**
 * トークン列に `StrayHttpRequestGuard::{$method}(` の**呼び出し**があるか (純関数)。
 *
 * `T_STRING('StrayHttpRequestGuard')` → `T_DOUBLE_COLON` → `T_STRING($method)` →
 * 次の有意トークンが `(` という並びで判定する。
 * ★文字列 grep にしないのが load-bearing: literal 中の同名テキストは
 *   `T_CONSTANT_ENCAPSED_STRING` 1 個なので一致しない = コメントや説明文で偽緑にならない。
 *
 * @param  list<PhpToken>  $tokens
 * @param  non-empty-string  $method
 */
function strayHttpEgressCallsGuard(array $tokens, string $method): bool { /* … */ }

/**
 * レーン既定配線の違反一覧 (純関数)。
 *
 * 各チャンクについて:
 *  - `strayHttpEgressHookBody($chunk, 'beforeEach')` が非 null で、その本体が
 *    `StrayHttpRequestGuard::install(` を**呼んで**いる
 *  - `strayHttpEgressHookBody($chunk, 'afterEach')` が非 null で、その本体が
 *    `flushAndFailIfStray(` と `reset(` を呼んでいる
 *  - hook body が null (hook が無い / 引数が closure リテラルでない / 追加引数がある) なら
 *    **違反として扱う** (fail-closed。取り出せないものを「たぶん大丈夫」にしない)
 * さらに STRAY_HTTP_EGRESS_REQUIRED_LANES が全て、いずれかのチャンクで覆われている。
 *
 * @param  list<array{lanes: list<string>, tokens: list<PhpToken>}>  $chunks
 * @return list<string>
 */
function strayHttpEgressLaneViolations(array $chunks): array { /* … */ }

/**
 * 許可パターンが loopback ホストだけに閉じているかの違反一覧 (純関数)。
 *
 * 許容する形は `scheme://host` / `scheme://host/*` / `scheme://host:*` の 3 形のみ。
 * host は 127.0.0.1 / localhost / [::1] に限る。
 * これにより `http://127.0.0.1*` (末尾ワイルドカード) も `https://api.example.com/*` も弾かれる。
 *
 * @param  list<string>  $patterns
 * @return list<string>
 */
function strayHttpEgressPatternViolations(array $patterns): array
{
    $violations = [];
    foreach ($patterns as $pattern) {
        if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|\[::1\])(?:/\*|:\*)?$#u', $pattern) !== 1) {
            $violations[] = "許可パターンが loopback に閉じていない: {$pattern}";
        }
    }

    return $violations;
}

/**
 * 1 ファイル分の opt-out 判定 (純関数。fixture でテストできる形に切り出す)。
 *
 * 検出対象 (**deny-by-default**):
 *  - `allowStrayRequests` の呼び出し — 引数を問わず全件。
 *    null 渡しは prevent 自体を OFF にし、配列渡しは既定の許可集合を**置換**する
 *    (merge ではない: `Factory::allowStrayRequests` は `array_values($only)` 代入)。
 *    どちらもレーン既定を壊しうるので区別せず全部登録対象にする。
 *  - `preventStrayRequests` の呼び出しのうち **引数があるもの**全件。
 *    ★`preventStrayRequests(false)` の literal だけを見ると
 *      `preventStrayRequests($flag)` / `((bool) 0)` / `preventStrayRequests(prevent: false)` が
 *      素通りする (Codex design-review Round 1 の Warning)。
 *      **引数ゼロだけを許可**し (レーン既定と同値の重複宣言)、有意トークンが 1 個でもあれば
 *      inventory 必須にする = 逃げ道を構造的に消す。
 *
 * 判定はすべてトークン列上で行う:
 *  `T_STRING(メソッド名)` → 次の有意トークンが `(` → `strayHttpEgressMatchingIndex()` で
 *  対応する `)` を求め、その間の**有意トークン数**を数える。
 *  これで (a) コメント内の説明、(b) 名前と `(` の間の空白/コメント、
 *  (c) 引数中の文字列に含まれる `)`、のいずれでも誤らない。
 */
function strayHttpEgressIsOptOutSource(string $source): bool { /* strayHttpEgressTokens → 上記判定 */ }

/**
 * tests/ 配下で opt-out 呼び出しを持つファイル一覧 (リポジトリルート相対、ソート済み)。
 * Finder でファイルを集め `strayHttpEgressIsOptOutSource()` に渡すだけの薄い層。
 * 走査器自身 (STRAY_HTTP_EGRESS_SCANNER_SELF) は除外する。
 *
 * @return list<string>
 */
function strayHttpEgressOptOutSites(): array { /* Finder で tests/**\/*.php → 上記純関数 */ }

test('tests/Pest.php の全レーンが StrayHttpRequestGuard を既定配線していること', /* … */);
test('許可 URL パターンが loopback ホストだけに閉じていること', /* … */);
test('opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)', /* … */);
test('exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)', /* … */);
test('exemption の根拠が 30 文字以上であること', /* … */);
test('exemption 件数が上限 (exact fit) を超えていないこと', /* … */);

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 * 本体テスト 6 本 + 負のコントロール 12 本 = 計 18 本。
 */
test('負のコントロール: install を持たないレーンを検出する', /* … */);
test('負のコントロール: install が afterEach 側にしかない配線を検出する', /* … */);
test('負のコントロール: install が hook closure の外にある配線を検出する', /* … */);
test('負のコントロール: flush はあるが reset が無い配線を検出する', /* … */);
test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', /* … */);
test('負のコントロール: コメント内の install 記述では配線と認めない', /* … */);
test('負のコントロール: 文字列リテラル中の install 記述では配線と認めない', /* … */);
test('負のコントロール: hook 引数がネストした closure の場合を配線と認めない', /* … */);
test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', /* … */);
test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) と外部ドメインを検出する', /* … */);
test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', /* … */);
test('負のコントロール: 名前と ( の間の空白/コメント・引数中の ) で opt-out 判定を誤らない', /* … */);
```

負のコントロールの中身 (要点となる 6 本):

```php
test('負のコントロール: コメント内の install 記述では配線と認めない', function (): void {
    // ★これが無いと「// StrayHttpRequestGuard::install($this->app); を入れる予定」という
    //   コメントだけで gate が緑になる (最も現実的な偽緑シナリオ)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            // StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            // StrayHttpRequestGuard::flushAndFailIfStray();
            // StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: 文字列リテラル中の install 記述では配線と認めない', function (): void {
    // ★トークン ID ではなく文字列 grep で判定する実装だと、これが素通りする
    //   (Codex design-review Round 4 の Suggestion)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $todo = 'StrayHttpRequestGuard::install($this->app);';
        })
        ->afterEach(function (): void {
            $todo = 'StrayHttpRequestGuard::flushAndFailIfStray(); StrayHttpRequestGuard::reset();';
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: install が hook closure の外にある配線を検出する', function (): void {
    // 「beforeEach と afterEach の間にあれば OK」という位置ベースの実装だと素通りする形。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $this->withoutVite();
        })
        ->use(StrayHttpRequestGuard::install($app))
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: hook 引数がネストした closure の場合を配線と認めない', function (): void {
    // ★「引数**内**のどこかにある function を拾う」実装だと素通りする
    //   (Codex design-review Round 4 の Warning)。beforeEach に渡るのは wrap(...) の
    //   戻り値であり、この closure が hook として登録される保証は無い。
    //   引数が closure リテラルでない形 ($callback 変数渡し) も同様に fail-closed。
    $wrapped = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(wrap(function (): void {
            StrayHttpRequestGuard::install($this->app);
        }))
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $variable = str_replace(
        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
        '$callback',
        $wrapped,
    );

    // アロー関数も受け付けない (ブロック本体が必須 = 契約どおり fail-closed)
    $arrow = str_replace(
        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
        'fn () => StrayHttpRequestGuard::install($this->app)',
        $wrapped,
    );

    foreach (['wrapped' => $wrapped, 'variable' => $variable, 'arrow' => $arrow] as $label => $source) {
        $violations = strayHttpEgressLaneViolations(
            strayHttpEgressLaneChunks(strayHttpEgressTokens($source)),
        );
        expect($violations)->not->toBe([], "hook 引数の形 ({$label}) を fail-closed にできていない");
        expect(implode("\n", $violations))->toContain('install');
    }
});

test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', function (): void {
    // ★正しい配線が literal 由来の括弧で偽赤にならないこと (偽陽性側の固定)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $json = '{"enabled":true}';
            $unbalanced = '} ) { (';
            $interpolated = "value={$json}";
            $doc = <<<'INNER'
            { unbalanced brace in heredoc
            INNER;
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->toBe([], 'literal 由来の括弧で closure の終端を誤認している');
});

test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', function (): void {
    // ★literal `false` だけを見る実装だと variable / cast / named が素通りする。
    $optOuts = [
        'literal' => 'Http::preventStrayRequests(false);',
        'variable' => 'Http::preventStrayRequests($flag);',
        'cast' => 'Http::preventStrayRequests((bool) 0);',
        'named' => 'Http::preventStrayRequests(prevent: false);',
        'spaced-comment' => 'Http::preventStrayRequests /* 理由 */ (false);',
        'nested-paren' => "Http::preventStrayRequests(str_contains(\$s, ')'));",
        'allow-null' => 'Http::allowStrayRequests();',
        'allow-array' => "Http::allowStrayRequests(['*']);",
    ];
    foreach ($optOuts as $label => $line) {
        expect(strayHttpEgressIsOptOutSource("<?php\n{$line}\n"))
            ->toBeTrue("opt-out ({$label}) を検出できていない");
    }
});

test('負のコントロール: 名前と ( の間の空白/コメント・引数中の ) で opt-out 判定を誤らない', function (): void {
    // 誤検出側 (false であるべきもの) を固定する。
    // レーン既定と同値の重複宣言 (無引数) は opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\n"))->toBeFalse();
    // 空白・改行を跨いだ無引数も opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests\n    (\n    );\n"))
        ->toBeFalse();
    // 無引数呼び出しの後ろに別の括弧があっても opt-out と誤検出しない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\nfoo(bar());\n"))
        ->toBeFalse();
    // コメント内・文字列リテラル内の記述も opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\n// Http::allowStrayRequests(['*']) は使わない\n"))
        ->toBeFalse();
    expect(strayHttpEgressIsOptOutSource("<?php\n\$doc = 'Http::allowStrayRequests([]) は禁止';\n"))
        ->toBeFalse();
});

test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) と外部ドメインを検出する', function (): void {
    foreach (['http://127.0.0.1*', 'https://api.frankfurter.dev/*', '*', 'http://127.0.0.1.evil.example/*'] as $pattern) {
        $violations = strayHttpEgressPatternViolations([$pattern]);
        expect($violations)->not->toBe([], "許可パターン ({$pattern}) を検出できていない");
        expect(implode("\n", $violations))->toContain('loopback に閉じていない');
    }

    // 正しい 3 形は違反にしない (偽陽性側の固定)
    expect(strayHttpEgressPatternViolations([
        'http://127.0.0.1', 'http://127.0.0.1/*', 'http://127.0.0.1:*', 'https://[::1]:*',
    ]))->toBe([]);
});
```

> `strayHttpEgressIsOptOutSource(string $source): bool` は
> `strayHttpEgressOptOutSites()` が 1 ファイル分の判定に使う純関数として切り出す
> (fixture でテストできる形にするため。`strayHttpEgressOptOutSites()` は
> Finder でファイルを集めてこの純関数に渡すだけの薄い層にする)。

### PHPStan 適合チェック

> ⚠ `tests/` は `phpstan.neon` の `paths` 外 (再掲)。以下は手動チェックリスト。

- [x] 全関数に戻り値型 (`array` は `@return list<string>` / `@return array<string, array{Enum, non-empty-string}>` の shape 付き)
- [x] `preg_match` の戻り値は `int|false` なので `!== 1` で比較する (真偽値の暗黙変換をしない)
- [x] `file_get_contents()` の `string|false` は `expect($source)->toBeString()` +
      `/** @var string $source */` で narrowing する (既存 `GlobalTestLockInventoryTest` と同形)
- [x] PCRE に `\R` を使う場合は `/u` 必須 (`PcreUnicodeModifierGateTest`)。
      本 gate は `\R` を使わない (トークン列で扱うため行分割が不要) が、`#…#u` を既定で付ける
- [x] `PhpToken::tokenize()` の戻り値は `list<PhpToken>` として扱い、
      配列を渡す純関数はすべて `@param list<PhpToken> $tokens` を付ける
- [x] トークン判定は **`$token->is(T_FUNCTION)` / `$token->id`** で行い、`text` の文字列比較で
      キーワードを判定しない (literal 中の同名テキストで誤判定するため)。
      記号トークン (`(` `)` `{` `}` `,`) のみ `text` 比較でよい (id が ASCII コードのため)
- [x] index を返す関数は `?int`、本体を返す関数は `list<PhpToken>|null` を明示し、
      null を「見つからない = fail-closed」の意味だけに使う
- [x] enum は backed string enum。`->value` でのみ文字列化する
- [x] DTO 返却は非該当

### テスト計画

- [ ] **赤の確認 (テストファースト)**: gate を先に追加し、S3 の配線を入れる**前**に
      `vendor/bin/pest tests/Architecture/StrayHttpEgressLaneGateTest.php` を実行 →
      「Feature/Unit lane が install していない」等で赤になることを確認
- [ ] 新規テスト: 本体テスト 6 本 + 負のコントロール 12 本 (計 18 本)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Architecture lane は DB 不使用)
- [ ] Factory は使わない (モデルを作らない)

#### mutation で赤化を確認する手順 (「素の main では赤にならない gate」の受け入れ)

本 gate も S2 の自己検査も、**正しい状態では常に緑**であり、放置すると空振りに気づけない。
負のコントロール (fixture) は「純関数が壊れた入力を検出できる」ことしか示さないので、
**実ファイルに対して gate が効いているか**は mutation で確認する。
実装 PR の説明に、以下 10 本の実施結果 (赤くなったテスト名) を記録する。

| # | mutation (一時変更 → 必ず復元) | 期待して赤くなるもの |
|---|------|------|
| M1 | `tests/Pest.php` の Feature/Unit lane から `StrayHttpRequestGuard::install($this->app);` を削除 | gate「全レーンが既定配線」 |
| M2 | 同 install 行を `->afterEach(` の closure 本体へ移動 | gate「全レーンが既定配線」(beforeEach 本体に install が無い) |
| M3 | `ALLOWED_URL_PATTERNS` に `'https://api.frankfurter.dev/*'` を追加 | gate「許可パターンが loopback に閉じている」 |
| M4 | `ALLOWED_URL_PATTERNS` の `'http://127.0.0.1:*'` を `'http://127.0.0.1*'` に変更 | gate「許可パターン」 + S2 case H |
| M5 | `tests/Feature/Security/AuthThrottleCoverageTest.php` に `Http::allowStrayRequests(['*']);` を追加 | gate「opt-out が inventory 登録済み」 |
| M6 | guard の `__invoke` から `self::$strayRequests[] = …` を削除 | S2 case A / E / I (握り潰し貫通) |
| M7 | inventory から `tests/Support/StrayHttpRequestGuard.php` を削除 / 架空パスを追加 | gate「未登録」/ gate「形骸化ガード」 |
| M8 | `tests/Pest.php` の install 行を `beforeEach` closure の外 (`->use(...)` の直後など) へ移動 | gate「全レーンが既定配線」(hook 本体の内包検査) |
| M9 | `tests/Feature/Security/ThrottleExemptionPremiseTest.php` の `Http::preventStrayRequests();` を `Http::preventStrayRequests($flag);` に変更 | gate「opt-out が inventory 登録済み」(非 literal 検出) |
| M10 | `tests/Pest.php` の Feature/Unit lane の `->beforeEach(function (): void { … })` を `->beforeEach(wrap(function (): void { … }))` に変更 | gate「全レーンが既定配線」(hook 引数が closure リテラルでない → fail-closed) |

### リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| `tests/Pest.php` のチャンク分割が将来の書き方 (`pest()->extend()` を変数へ代入等) で壊れる | gate が偽赤 or 偽緑 | 偽赤は書いた瞬間に気づける。偽緑側は「必須レーンが全て覆われていること」の検査が残るため、チャンクが取れなければレーン未充足で赤になる (fail-closed) |
| 走査器自身の除外 (`STRAY_HTTP_EGRESS_SCANNER_SELF`) が抜け道になる | gate ファイル内で opt-out すれば検出されない | gate ファイルは Architecture lane で HTTP を出さない。かつ除外は定数 1 本で可視。GlobalTestLockInventoryTest と同じ受容 |
| exemption cap が exact fit=1 のため、正当な 2 本目でも一度赤くなる | 実装者の手間 | それが狙い (再検討の強制)。ThrottleCoverageInventoryTest と同じ設計 |
| `Finder` で `tests/**` を毎回走査するコスト | Architecture lane が数十 ms 遅くなる | 既存 gate (`DirectFetchInventory` は `app` + `routes` 全走査) と同程度。許容 |
| 将来 PHP が新しい構文 (文字列系トークン / closure 表記) を追加し、トークン走査の前提が崩れる | gate が偽赤 or 偽緑になる | 解析をトークン列で行うため、literal は 1 トークンに畳まれ、キーワードは id で判定される = 崩れる余地が構造的に小さい。負のコントロール (JSON / 補間 / heredoc / nowdoc / ネスト closure / アロー関数) が回帰を捕まえる。PHP バージョンを上げる際は本表を根拠に `strayHttpEgressHookBody()` の受理形を再確認する |



---

## 再レビューの依頼事項

1. Round 4 の必須修正 (hook 引数が**直接** closure リテラルであることの確認 + fail-closed) が解消しているか。
2. トークン列ベースの新方式に、これまで指摘された穴の**再発**や新たな穴が無いか。
3. 残っている [Critical] / [Warning] があれば必ず修正案付きで挙げてください。無ければ **全体判定 APPROVED** を明示してください。

なお本フローの上限は 5 ラウンドで、本ラウンドが最終です。全体判定を必ず明示してください。
