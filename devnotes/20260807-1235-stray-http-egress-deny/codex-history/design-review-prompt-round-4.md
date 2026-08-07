# design-review Round 4

Round 3 の必須修正 1 点 + Suggestion 1 件をすべて対応しました (反論・見送りゼロ)。
対応マトリクスと修正後の該当箇所を示します。再レビューをお願いします。

---

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

---

## 修正後の該当箇所 (詳細設計書からの抜粋)

### S4: 純関数群 (正規化 / 対応括弧 / 呼び出し括弧の特定 / closure 本体抽出 / レーン違反判定)

```php
/**
 * PHP ソースを **構文的に安全な解析入力** へ正規化する (純関数)。
 *
 * PhpToken::tokenize() を通し、
 *  (1) `T_COMMENT` / `T_DOC_COMMENT` の text を空にする
 *      (行頭 `//` の正規表現除去では行末コメントや docblock を取りこぼす)。
 *  (2) **文字列リテラル系トークン** (`T_CONSTANT_ENCAPSED_STRING` /
 *      `T_ENCAPSED_AND_WHITESPACE` = 補間文字列と heredoc/nowdoc の本文 /
 *      `T_INLINE_HTML`) の text 中の `{` `}` `(` `)` を `_` に置換する。
 *  それ以外のトークンは text をそのまま連結する。
 *
 * ★(2) が Codex design-review Round 2 の Warning への回答である。
 *   生文字列で波括弧を数えると
 *     $json = '{"enabled":true}';   // 括弧が閉じない literal
 *     $fixture = <<<'PHP' { PHP;    // heredoc 本文の裸の {
 *   のようなコードで closure の終端を誤認する。トークン種別で literal と分かるものの
 *   括弧だけを潰せば、残る `{` `}` `(` `)` は**すべて構文上の括弧**になる。
 *   補間中の `{$x}` は `T_CURLY_OPEN` + `}` という**構文トークンの対**なので、
 *   潰さなくても深度は必ず戻る (誤認しない)。
 *
 * ★括弧を消すのではなく `_` に**置換**するのは、文字列の中身 (レーン名 `'Feature'` など)
 *   を残したまま構造だけ無害化するため。オフセットも 1:1 で保たれる。
 */
function strayHttpEgressCode(string $source): string { /* PhpToken ベースの正規化 */ }

/**
 * `$openOffset` (開き括弧の位置) から対応する閉じ括弧までの**内側**を返す (純関数)。
 * 入力は strayHttpEgressCode() 済みなので、括弧はすべて構文上のものである。
 *
 * @param  non-empty-string  $open   `(` または `{`
 * @param  non-empty-string  $close  `)` または `}`
 */
function strayHttpEgressBalancedInner(string $code, int $openOffset, string $open, string $close): ?string { /* 深度カウント */ }

/**
 * tests/Pest.php のコードを `pest()->extend(` 単位のチャンクへ分解する (純関数)。
 *
 * @return list<array{lanes: list<string>, body: string}>
 */
function strayHttpEgressLaneChunks(string $code): array { /* … */ }

/**
 * メソッド名の直後にある `(` の位置を返す (純関数)。見つからなければ null。
 *
 * ★`Http::preventStrayRequests /* reason *\/ (false)` のように、名前と `(` の間に
 *   空白・改行・(除去済み) コメントが挟まる形は PHP として有効
 *   (Codex design-review Round 3 の Suggestion)。名前の直後から
 *   **最初の非空白文字**を探し、それが `(` のときだけ引数解析へ進む。
 */
function strayHttpEgressCallOpenParen(string $code, int $nameEndOffset): ?int { /* … */ }

/**
 * `->beforeEach(...)` / `->afterEach(...)` の**引数 closure の本体**を切り出す (純関数)。
 * 取り出せない (引数が closure でない / `{` が無い) 場合は **null を返して fail-closed** にする。
 *
 * 手順 (Codex design-review Round 3 の Warning への対応):
 *  1. `$openOffset` (= メソッド名直後の `(`) から
 *     `strayHttpEgressBalancedInner($code, $openOffset, '(', ')')` で
 *     **引数全体**を取り出す。ここで探索範囲が当該呼び出しの内側に閉じる。
 *  2. 取り出した引数**内**で `function` トークンに続く最初の `{` を探す。
 *     引数が closure でない (`->beforeEach($callback)` のような変数渡し) 場合は
 *     `function` が無いので null を返す。
 *  3. その `{` を起点に `strayHttpEgressBalancedInner($args, $bracePos, '{', '}')` で本体を得る。
 *
 * ★1 を省いて「$openOffset 以降で最初の `{`」を探すと、
 *   `->beforeEach($callback)->use(function () { install(...); })` のように
 *   **後続の別 closure を beforeEach 本体と誤認**して偽グリーンになる。
 * ★入力が strayHttpEgressCode() で正規化済みなので、literal 由来の裸の括弧は混ざらない
 *   (Codex design-review Round 2 の Warning)。
 */
function strayHttpEgressClosureBody(string $code, int $openOffset): ?string { /* 上記 1→2→3 */ }

/**
 * レーン既定配線の違反一覧 (純関数)。
 *
 * 各チャンクについて:
 *  - `->beforeEach(` の **closure 本体内**に `StrayHttpRequestGuard::install(` がある
 *  - `->afterEach(` の **closure 本体内**に `StrayHttpRequestGuard::flushAndFailIfStray(` がある
 *  - 同じ closure 本体内に `StrayHttpRequestGuard::reset(` がある
 *  - `->beforeEach(` / `->afterEach(` がそもそも存在する
 *  - `strayHttpEgressClosureBody()` が null (引数が closure でない / `{` が無い) なら
 *    **違反として扱う** (fail-closed。取り出せないものを「たぶん大丈夫」にしない)
 * さらに STRAY_HTTP_EGRESS_REQUIRED_LANES が全て、いずれかのチャンクで覆われている。
 *
 * @param  list<array{lanes: list<string>, body: string}>  $chunks
 * @return list<string>
 */
function strayHttpEgressLaneViolations(array $chunks): array { /* … */ }


```

### S4: opt-out 検出の契約

```php
/**
 * tests/ 配下で opt-out 呼び出しを持つファイル一覧 (リポジトリルート相対、ソート済み)。
 *
 * 検出対象 (**deny-by-default**):
 *  - `allowStrayRequests(` — 引数を問わず全件。
 *    null 渡しは prevent 自体を OFF にし、配列渡しは既定の許可集合を**置換**する
 *    (merge ではない: Factory::allowStrayRequests は array_values($only) 代入)。
 *    どちらもレーン既定を壊しうるので区別せず全部登録対象にする。
 *  - `preventStrayRequests(` に **引数がある**呼び出し全件。
 *    ★`preventStrayRequests(false)` の literal だけを見ると
 *      `preventStrayRequests($flag)` / `preventStrayRequests((bool) 0)` /
 *      `preventStrayRequests(prevent: false)` が素通りする
 *      (Codex design-review Round 1 の Warning)。
 *      **無引数 `preventStrayRequests()` だけを許可**し (レーン既定と同値の重複宣言)、
 *      引数が 1 文字でもあれば inventory 必須にする = 逃げ道を構造的に消す。
 *      判定は「`preventStrayRequests` の直後 (空白・除去済みコメントを跨ぐ) にある `(` から
 *      **対応する** `)` までが空白のみか」で、`(` の特定は
 *      `strayHttpEgressCallOpenParen()`、対応括弧の探索は
 *      `strayHttpEgressBalancedInner()` (深度カウント) を使う。
 *      ★単純な「次の `)` を探す」実装は、引数中の文字列や closure に `)` が含まれると
 *        終端を誤認する (Codex design-review Round 2 の Warning)。
 *
 * 走査は strayHttpEgressCode() で正規化した後に行う
 * (コメント内の説明で偽赤にせず、literal 由来の括弧で終端を誤認しない)。
 *
 * @return list<string>
 */
function strayHttpEgressOptOutSites(): array { /* Finder で tests/**\/*.php → strayHttpEgressCode() → 上記判定 */ }


```

### S4: 負のコントロール一覧 (Round 3 で 2 本追加、計 11 本)

```php
test('負のコントロール: install を持たないレーンを検出する', /* … */);
test('負のコントロール: install が afterEach の後ろに来ている配線を検出する', /* … */);
test('負のコントロール: install が beforeEach closure の外にある配線を検出する', /* … */);
test('負のコントロール: flush はあるが reset が無い配線を検出する', /* … */);
test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', /* … */);
test('負のコントロール: コメント内の install 記述では配線と認めない', /* … */);
test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) を検出する', /* … */);
test('負のコントロール: 外部ドメインの許可パターンを検出する', /* … */);
test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', /* … */);
test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', /* … */);
test('負のコントロール: 引数中の文字列に ) を含む opt-out を誤判定しない', /* … */);
test('負のコントロール: beforeEach の後続 closure を本体と誤認しない', /* … */);
test('負のコントロール: メソッド名と ( の間に空白/コメントを挟んだ opt-out を検出する', /* … */);

```

### S4: Round 3 で追加した負のコントロールの中身

test('負のコントロール: beforeEach の後続 closure を本体と誤認しない', function (): void {
    // ★探索範囲を beforeEach(...) の引数内に閉じないと、後続の別 closure を
    //   beforeEach 本体と誤認して偽グリーンになる (Codex design-review Round 3 の Warning)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach($callback)
        ->use(function (): void {
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressCode($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: メソッド名と ( の間に空白/コメントを挟んだ opt-out を検出する', function (): void {
    // PHP として有効な書き方 (Codex design-review Round 3 の Suggestion)。
    // strayHttpEgressCode() でコメントは空になるため、名前の直後から
    // 最初の非空白文字を探す実装なら検出できる。
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests /* 理由 */ (false);\n"))
        ->toBeTrue();
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests\n    (\$flag);\n"))
        ->toBeTrue();
    // 無引数側も空白/改行を跨いで正しく「引数なし」と判定できること
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests\n    (\n    );\n"))
        ->toBeFalse();
});
```



### S4: リスク表 (PHP の新しい文字列系トークンへの再確認条件を追加)

| リスク | 影響 | 緩和 |
|--------|------|------|
| `tests/Pest.php` のチャンク分割が将来の書き方 (`pest()->extend()` を変数へ代入等) で壊れる | gate が偽赤 or 偽緑 | 偽赤は書いた瞬間に気づける。偽緑側は「必須レーンが全て覆われていること」の検査が残るため、チャンクが取れなければレーン未充足で赤になる (fail-closed) |
| 走査器自身の除外 (`STRAY_HTTP_EGRESS_SCANNER_SELF`) が抜け道になる | gate ファイル内で opt-out すれば検出されない | gate ファイルは Architecture lane で HTTP を出さない。かつ除外は定数 1 本で可視。GlobalTestLockInventoryTest と同じ受容 |
| exemption cap が exact fit=1 のため、正当な 2 本目でも一度赤くなる | 実装者の手間 | それが狙い (再検討の強制)。ThrottleCoverageInventoryTest と同じ設計 |
| `Finder` で `tests/**` を毎回走査するコスト | Architecture lane が数十 ms 遅くなる | 既存 gate (`DirectFetchInventory` は `app` + `routes` 全走査) と同程度。許容 |
| 将来 PHP が新しい文字列系トークンを追加し、「残る括弧はすべて構文上の括弧」という正規化の前提が崩れる | closure 本体抽出が誤り、gate が偽赤 or 偽緑になる | 負のコントロール (JSON / 補間 / heredoc / nowdoc) が回帰を捕まえる。PHP バージョンを上げる際は `strayHttpEgressCode()` の無害化対象トークン一覧を再確認する (本表を根拠として残す) |


---

## 再レビューの依頼事項

1. Round 3 の必須修正 (`strayHttpEgressClosureBody()` の探索範囲を当該呼び出しの引数内へ閉じる + fail-closed) が解消しているか。
2. 残っている [Critical] / [Warning] があれば必ず修正案付きで挙げてください。無ければ **全体判定 APPROVED** を明示してください。
3. なお本設計は「設計のみ」で実装は別 TODO です。実装時に必ず踏むべき落とし穴が他にあれば、[Suggestion] として挙げてください (全体判定には影響させないでください)。
