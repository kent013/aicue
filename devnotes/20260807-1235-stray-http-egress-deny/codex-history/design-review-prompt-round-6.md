【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割 (Round 6 = 確認ラウンド)

あなたは Round 1〜5 で `devnotes/20260807-1235-stray-http-egress-deny/detailed-design.md`
(テストレーンの外部 HTTP 出口を既定拒否にする設計) をレビューしてきた設計レビュアである。
**セッションは残っていないので、必要な文脈は下記にすべて貼ってある。**

Round 5 の判定は **CHANGES_REQUESTED**、内訳は
**必須修正 (Warning) 1 件 + Suggestion 2 件、Critical はゼロ**。
S1 / S2 / S3 / S5 / S6 は Round 5 で APPROVE 済みで、以降変更していない。

本ラウンドの依頼は 1 つだけ:

> **Round 5 の指摘に対する下記の対応で、Round 5 の [Critical] / [Warning] が解消しているかを判定し、
> 全体判定 (APPROVED / CHANGES_REQUESTED) を返せ。解消していない場合は、残っている指摘だけを挙げよ。**

新しい観点の掘り起こしは求めていない。ただし **この対応自体が新たな欠陥を作っている場合はそれを指摘せよ**。
また、下記 §3 に **Round 5 の指摘の前提事実に対する反証** を実測付きで提示している。
そこは特に厳しく検算してほしい (こちらが間違っているなら明確にそう言え)。

---

# 1. Round 5 の指摘 (原文)

### S4 [Warning]

> `strayHttpEgressMatchingIndex()` が、補間文字列の開始トークンを波括弧の開始として数える契約になっていません。
>
> PHPの補間文字列は必ずしも1トークンに畳まれません。例えば `$value = "value={$json}";` は概念的に次のようなトークン列になります。
>
> ```text
> T_ENCAPSED_AND_WHITESPACE("value=")
> T_CURLY_OPEN("{$")
> T_VARIABLE("$json")
> "}"
> ```
>
> PHPStanチェックリストでは、記号トークンの `{` / `}` を `text` 比較するとしています。このまま単独の `{` だけを開始として数えると、`T_CURLY_OPEN` は数えられず、補間終端の単独 `}` だけがclosure深度を減らします。その結果、closure終端を早く見つける可能性があります。
>
> 提示された「JSON文字列 / 補間 / heredoc」の負のコントロールは、この問題により実装時に赤くなるはずです。空振りではありませんが、設計されたアルゴリズムのままではそのテストを通せません。
>
> 修正案:
> - `{` / `}` の対応を調べる場合、次を開始側として深度に加える。
>   `$token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)`
> - 終了側の単独 `}` は通常どおり深度を減らす。
> - `(` / `)` の探索ではこの追加処理を行わない。
> - PHPStanチェックリストを「記号トークンだけtext比較可」から、補間開始トークンをID判定する例外込みに修正する。
> - 次の単体テストを `strayHttpEgressMatchingIndex()` 自体に追加する。
>   `$tokens = strayHttpEgressTokens('<?php function () { $a = "value={$json}"; guard(); }');`
>   この入力で、返される対応位置が補間の `}` ではなくclosure末尾の `}` になることを直接固定してください。

### S4 [Suggestion] 1

> `strayHttpEgressTokens()` の説明にある「literal は1個の `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` にまとまり」は修正した方が正確です。補間文字列は複数トークンに分割されます。「文字列内容の括弧は文字列系トークン内に保持され、構文上の補間境界は専用トークンで識別できる」としてください。

### S4 [Suggestion] 2

> exemption enumのクラスdocblockは、検出対象を現在も `preventStrayRequests(false)` と記載しています。実際の契約は「引数付き `preventStrayRequests(...)` 全件」なので同期してください。

---

# 2. 対応マトリクス (Round 5 の指摘をどう捌いたか)

| # | 指摘 | 判断 | 要旨 |
|---|------|------|------|
| S4-W | `matchingIndex()` が補間開始トークンを数えていない | **対応する** | 修正案どおり `text === '{' \|\| is(T_CURLY_OPEN) \|\| is(T_DOLLAR_OPEN_CURLY_BRACES)` を契約化。PHPStan チェックリストに例外条項を追加。単体テストを新設。**ただし提示された単体テスト入力は不十分だったので強化した (下記 §3)** |
| S4-S1 | `strayHttpEgressTokens()` の説明が不正確 | **対応する** | docblock を書き換え。2 形の実トークン列を併記 |
| S4-S2 | exemption enum の docblock が `preventStrayRequests(false)` のまま | **対応する** | 「opt-out 呼び出しの定義 (gate の契約と一致させること)」節を追加 |
| S1/S2/S3/S5/S6 | Round 5 で APPROVE | 見送る | 変更していない |

**反論・見送りはゼロ**。3 件すべて設計へ反映した。
ただし S4-W については、**指摘の前提事実に誤りがあり、提示された単体テストのままでは
空振りになる**ことを実測で確認したため、修正の方向は採用しつつ入力を強化した。

---

# 3. ★重要: Round 5 の前提事実に対する反証 (実測)

Round 5 は `T_CURLY_OPEN` の `text` を `"{$"` と述べ、「だから `text === '{'` に一致しない」
としている。**この前提は事実と異なる**。PHP 8.4.24 (本リポジトリの PHP) で
`PhpToken::tokenize()` の実出力を取ったところ、次のとおりだった。

入力 `<?php function () { $a = "value={$json}"; guard(); }`:

```text
 0 T_OPEN_TAG                     "<?php "
 1 T_FUNCTION                     "function"
 3 (                              "("
 4 )                              ")"
 6 {                              "{"          ← closure 本体の開き
 8 T_VARIABLE                     "$a"
10 =                              "="
12 "                              "\""
13 T_ENCAPSED_AND_WHITESPACE      "value="
14 T_CURLY_OPEN                   "{"          ← ★text は "{"。"{$" ではない
15 T_VARIABLE                     "$json"
16 }                              "}"
17 "                              "\""
18 ;                              ";"
20 T_STRING                       "guard"
21 (                              "("
22 )                              ")"
23 ;                              ";"
25 }                              "}"          ← closure 本体の閉じ
```

入力 `<?php $a = "x${json}y";`:

```text
 6 T_ENCAPSED_AND_WHITESPACE      "x"
 7 T_DOLLAR_OPEN_CURLY_BRACES     "${"         ← ★こちらの text は "${" で '{' に一致しない
 8 T_STRING_VARNAME               "json"
 9 }                              "}"
```

つまり:

- **`T_CURLY_OPEN` の `text` は `"{"`** なので、`text === '{'` だけの実装でも**偶然拾えており深度は壊れない**。
- **実際に深度が壊れるのは `T_DOLLAR_OPEN_CURLY_BRACES` (`"${"`) の側だけ**である。

修正前 / 修正後の実装を両方書いて実測した結果:

| 入力 | 修正前 (text 比較のみ) | 修正後 (id 判定を追加) |
|---|---|---|
| `<?php function () { $a = "value={$json}"; guard(); }` | close=25 (**closure 末尾。正しい**) | close=25 (正しい) |
| `<?php function () { $a = "value=${json}"; guard(); }` | close=16 (**補間の `}`。誤り**) | close=25 (正しい) |

### この反証から導いた 2 つの帰結 (設計に反映済み)

1. **修正 (id 判定の追加) 自体は採用する。** `T_DOLLAR_OPEN_CURLY_BRACES` で実際に壊れるのは事実であり、
   `T_CURLY_OPEN` を id でも判定するのは「`text` が `"{"` である」という暗黙の前提を契約から外す価値がある。

2. **Round 5 が提示した単体テスト入力 (`{$json}` 形) は、そのままでは空振りテストになる。**
   上表のとおり `{$json}` 形は修正前の実装でも close=25 を返して緑になる。
   Round 5 本文の「提示された『JSON文字列 / 補間 / heredoc』の負のコントロールは、この問題により
   実装時に赤くなるはずです」という評価も、同じ理由で成り立たない
   (当該負のコントロールの補間例も `{$json}` 形だった)。
   本設計は「空振り gate を緑にしない」ことを一貫した方針としているので、
   **回帰入力を `${json}` 形 (+ 保険として `{$json}` 形) の 2 本立てに強化した**。

**この §3 の事実認定と帰結が正しいかを検算してほしい。** 誤りがあれば明確に指摘せよ。

---

# 4. 対応後の詳細設計 (変更された節の全文)

以下はすべて `detailed-design.md` の **S4: deny-by-default 目録型 Architecture gate** の中の記述。
S4 の全体構造 (何を作るか) は Round 5 から変わっていない。

## 4-1. exemption enum (S4-S2 への対応。`tests/Support/Security/StrayHttpEgressExemption.php`)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 「テストレーンの HTTP 出口既定拒否を opt-out することが正しい」と裁定した理由の分類。
 *
 * tests/Architecture/StrayHttpEgressLaneGateTest.php が deny-by-default で
 * 「opt-out 呼び出しを持つファイルは本 enum + 30 文字以上の具体的根拠付きで
 *  inventory に登録済みであること」を機械強制する。
 *
 * opt-out 呼び出しの定義 (gate の契約と一致させること):
 *  - `allowStrayRequests(...)` — **引数を問わず全件**
 *    (null は既定拒否を OFF に、配列は許可集合を置換する)
 *  - `preventStrayRequests(...)` のうち **引数があるもの全件**
 *    (`false` literal に限らない。`$flag` / `(bool) 0` / `prevent: false` も対象。
 *     引数ゼロの `preventStrayRequests()` はレーン既定と同値の重複宣言なので対象外)
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

## 4-2. `strayHttpEgressTokens()` の docblock (S4-S1 への対応)

```php
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
 *   **文字列の中身の括弧は文字列系トークン (`T_CONSTANT_ENCAPSED_STRING` /
 *   `T_ENCAPSED_AND_WHITESPACE`) の内側に保持され、構文上の補間境界は専用トークン
 *   (`T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`) で識別できる**。
 *   キーワードは `T_FUNCTION` / `T_STATIC` の**トークン ID** で一意に判定でき、
 *   空白は「有意トークン」を辿るだけで自然に飛ばせる。穴の種類が構造的に消える。
 *
 *   ★補間文字列は 1 トークンには畳まれない。
 *     `"value={$json}"` → `"` / `T_ENCAPSED_AND_WHITESPACE` / `T_CURLY_OPEN` / `T_VARIABLE` / `}` / `"`
 *     `"value=${json}"` → `"` / `T_ENCAPSED_AND_WHITESPACE` / `T_DOLLAR_OPEN_CURLY_BRACES`
 *                          / `T_STRING_VARNAME` / `}` / `"`
 *     **開始側は専用トークン 2 種・終端は単独 `}`** という非対称があり、その扱いは
 *     `strayHttpEgressMatchingIndex()` の契約に書く (text 値の実測もそこに記載)。
 *
 * @return list<PhpToken>
 */
function strayHttpEgressTokens(string $source): array { /* tokenize → T_COMMENT / T_DOC_COMMENT を除去 */ }
```

## 4-3. `strayHttpEgressMatchingIndex()` の契約 (S4-W への対応の中核)

```php
/**
 * `$openIndex` (開き括弧のトークン index) に対応する閉じ括弧の index を返す (純関数)。
 * トークン列上で深度を数えるため、文字列**内容**の括弧は文字列系トークンの内側にあり影響しない。
 *
 * ★波括弧 (`{` / `}`) を数えるときは、**補間の開始トークンも開始側に含める**
 *   (Codex design-review Round 5 の Warning):
 *
 *     $token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)
 *
 *   補間の**終端は必ず単独の `}` トークン**であるのに対し、**開始側は 2 種類の専用トークン**に
 *   分かれる。開始側を数え落とすと深度が片側だけ減り、**closure の終端を早く見つけてしまう**。
 *
 *   ★実測 (PHP 8.4.24) で確認した `text` の値 — ここが判断の分かれ目なので事実を残す:
 *
 *     "value={$json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_CURLY_OPEN(**"{"**)
 *                        / T_VARIABLE("$json") / }("}")
 *     "value=${json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_DOLLAR_OPEN_CURLY_BRACES(**"${"**)
 *                        / T_STRING_VARNAME("json") / }("}")
 *
 *   すなわち **`T_CURLY_OPEN` の `text` は `"{"` なので `text === '{'` でも偶然拾えるが、
 *   `T_DOLLAR_OPEN_CURLY_BRACES` の `text` は `"${"` で拾えない**。実際に深度が壊れるのは
 *   後者 (`"${json}"` 形) である。前者を id でも判定するのは、text 一致に依存した暗黙の
 *   前提を契約から消すため (将来 `text` の表現が変わっても壊れない)。
 *   ⚠ **したがって回帰テストの入力は `"${json}"` 形でなければならない**。
 *   `"{$json}"` 形だけで固定すると、修正前の実装でも緑になり**空振りテスト**になる
 *   (実測で確認済み: `{$json}` は修正の有無によらず closure 末尾を返す)。
 *
 *   終了側 (単独 `}`) は通常どおり深度を 1 減らすだけでよい。
 *   丸括弧 (`(` / `)`) の探索ではこの追加処理を行わない (補間に丸括弧の専用トークンは無い)。
 *
 *   ★`${...}` 補間は PHP 8.2 で deprecated (将来削除されうる) だが、fixture は nowdoc 内の
 *     **文字列としてのみ**存在し評価されないので deprecation は出ない。将来 PHP が
 *     `T_DOLLAR_OPEN_CURLY_BRACES` を生成しなくなったら、この回帰テストが
 *     「前提が変わった」ことを示して赤くなる (それが望ましい形の失敗)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  non-empty-string  $open   `(` または `{`
 * @param  non-empty-string  $close  `)` または `}`
 */
function strayHttpEgressMatchingIndex(array $tokens, int $openIndex, string $open, string $close): ?int { /* … */ }
```

## 4-4. 新設した単体テスト (S4-W への対応。入力を 2 形に強化)

```php
test('strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない', function (): void {
    // ★アルゴリズムの核を単体で固定する (Codex design-review Round 5 の Warning)。
    //   補間開始トークンを開始側に数えない実装だと、返る index が補間の `}` になり
    //   closure 本体が途中で切れる。
    //
    // ★入力は 2 形とも回す。**赤を出せるのは `${json}` 形だけ**である:
    //   実測 (PHP 8.4.24) で T_CURLY_OPEN の text は "{" なので `{$json}` 形は
    //   text 比較だけの実装でも偶然通る = それだけで固定すると空振りテストになる。
    //   T_DOLLAR_OPEN_CURLY_BRACES の text は "${" で text 比較に掛からない。
    //   両方入れるのは「2 形とも契約どおり」を示すため (前者は回帰の保険)。
    $sources = [
        'dollar-open-curly (この形だけが修正前の実装で赤くなる)'
            => '<?php function () { $a = "value=${json}"; guard(); }',
        'curly-open' => '<?php function () { $a = "value={$json}"; guard(); }',
    ];

    foreach ($sources as $label => $source) {
        $tokens = strayHttpEgressTokens($source);

        $open = null;
        foreach ($tokens as $i => $token) {
            if ($token->text === '{') { // closure 本体の `{` (補間開始トークンより前にある)
                $open = $i;
                break;
            }
        }
        expect($open)->not->toBeNull($label);
        /** @var int $open */
        $close = strayHttpEgressMatchingIndex($tokens, $open, '{', '}');
        expect($close)->not->toBeNull($label);
        /** @var int $close */

        // 対応先は closure 末尾の `}` = その後ろに有意トークンが残らない
        expect(strayHttpEgressNextSignificant($tokens, $close + 1))->toBeNull($label);
        // 本体に guard() 呼び出しが含まれている (補間の } で切れていない)
        $body = array_slice($tokens, $open + 1, $close - $open - 1);
        expect(implode('', array_map(static fn (PhpToken $t): string => $t->text, $body)))
            ->toContain('guard', $label);
    }
});
```

## 4-5. 既存の負のコントロール (補間 fixture を `${json}` 形込みに強化)

```php
test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', function (): void {
    // ★正しい配線が literal 由来の括弧で偽赤にならないこと (偽陽性側の固定)。
    // ★`${json}` 形 (T_DOLLAR_OPEN_CURLY_BRACES) を必ず含める。`{$json}` 形だけだと
    //   T_CURLY_OPEN の text が "{" のため補間開始を数え落とす実装でも緑になり、
    //   この負のコントロールが空振りする (Codex design-review Round 5 の Warning の実体)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $json = '{"enabled":true}';
            $unbalanced = '} ) { (';
            $interpolated = "value={$json}";
            $legacyInterpolated = "value=${json}";
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
```

## 4-6. PHPStan 適合チェックリスト (S4-W の「チェックリストを修正せよ」への対応)

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
      記号トークン (`(` `)` `{` `}` `,`) は `text` 比較でよい (id が ASCII コードのため)。
      **ただし例外**: 波括弧の深度計算では補間の開始トークン
      `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` を **id で判定して開始側に加える**。
      実測 (PHP 8.4.24) の `text` は `T_CURLY_OPEN` = `"{"` / `T_DOLLAR_OPEN_CURLY_BRACES` = `"${"`。
      **深度が実際に壊れるのは後者**(`"${json}"` 形。`'{'` と一致しないので開始側に数えられず、
      終端の単独 `}` だけが深度を減らして closure 終端を早く見つける)。
      前者も id で判定するのは、`text` 一致という暗黙の前提を契約から外すため。
      ⚠ この例外の回帰テストは **`"${json}"` 形を必ず入力に含める**こと
      (`"{$json}"` 形だけでは修正前の実装でも緑になり空振りする)
- [x] index を返す関数は `?int`、本体を返す関数は `list<PhpToken>|null` を明示し、
      null を「見つからない = fail-closed」の意味だけに使う
- [x] enum は backed string enum。`->value` でのみ文字列化する
- [x] DTO 返却は非該当

## 4-7. テスト本数の同期

本体テスト 6 本 + 負のコントロール 13 本 = **計 19 本** (Round 5 時点の 12 本から 1 本増、
新設の `strayHttpEgressMatchingIndex` 単体テスト分)。テスト計画にも同じ数字を反映済み。

負のコントロール 13 本の一覧:

```php
test('負のコントロール: install を持たないレーンを検出する', /* … */);
test('負のコントロール: install が afterEach 側にしかない配線を検出する', /* … */);
test('負のコントロール: install が hook closure の外にある配線を検出する', /* … */);
test('負のコントロール: flush はあるが reset が無い配線を検出する', /* … */);
test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', /* … */);
test('負のコントロール: コメント内の install 記述では配線と認めない', /* … */);
test('負のコントロール: 文字列リテラル中の install 記述では配線と認めない', /* … */);
test('負のコントロール: hook 引数がネストした closure の場合を配線と認めない', /* … */);
test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', /* … */);
test('strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない', /* … */);
test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) と外部ドメインを検出する', /* … */);
test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', /* … */);
test('負のコントロール: 名前と ( の間の空白/コメント・引数中の ) で opt-out 判定を誤らない', /* … */);
```

---

# 5. 判断に必要な周辺の節 (Round 5 から変更なし。文脈確認用)

## 5-1. `strayHttpEgressHookBody()` の契約 (matchingIndex の唯一の呼び出し元系)

```php
/**
 * chunk 内の `->{$hook}(...)` の**引数が直接 closure リテラルであること**を確認し、
 * その本体トークン列を返す (純関数)。確認できなければ **null を返して fail-closed** にする。
 *
 * 契約 (Codex design-review Round 4 の Warning への対応):
 *  1. `->` + `T_STRING($hook)` の並びを見つけ、その次の有意トークンが `(` であること
 *     (名前と `(` の間の空白は有意トークン走査で自然に飛ばす)。
 *  2. `(` の**次の有意トークン**が `T_FUNCTION`、または `T_STATIC` に続く `T_FUNCTION` であること。
 *     ★ここが要。「引数**内**のどこかにある `function` を拾う」実装だと
 *       `->beforeEach(wrap(function () { install(...); }))` を配線済みと誤認する。
 *  3. その `T_FUNCTION` に対応する closure 本体の `{` を
 *     `strayHttpEgressMatchingIndex()` で閉じ、本体トークン列を返す。
 *  4. closure の `}` の**次の有意トークン**が、1 で開いた `(` に対応する `)` であること
 *     (= 引数は closure ちょうど 1 個。カンマ区切りの追加引数は**許可しない**)。
 *
 * ★アロー関数 `fn () => …` は**受け付けない** (null を返す)。
 *
 * @param  list<PhpToken>  $tokens  chunk のトークン列
 * @param  non-empty-string  $hook   'beforeEach' または 'afterEach'
 * @return list<PhpToken>|null
 */
function strayHttpEgressHookBody(array $tokens, string $hook): ?array { /* 上記 1〜4 */ }
```

## 5-2. opt-out 検出の契約 (S4-S2 の enum docblock と一致させた側)

```php
/**
 * 1 ファイル分の opt-out 判定 (純関数。fixture でテストできる形に切り出す)。
 *
 * 検出対象 (**deny-by-default**):
 *  - `allowStrayRequests` の呼び出し — 引数を問わず全件。
 *    null 渡しは prevent 自体を OFF にし、配列渡しは既定の許可集合を**置換**する
 *    (merge ではない: `Factory::allowStrayRequests` は `array_values($only)` 代入)。
 *  - `preventStrayRequests` の呼び出しのうち **引数があるもの**全件。
 *    **引数ゼロだけを許可**し (レーン既定と同値の重複宣言)、有意トークンが 1 個でもあれば
 *    inventory 必須にする = 逃げ道を構造的に消す。
 *
 * 判定はすべてトークン列上で行う:
 *  `T_STRING(メソッド名)` → 次の有意トークンが `(` → `strayHttpEgressMatchingIndex()` で
 *  対応する `)` を求め、その間の**有意トークン数**を数える。
 */
function strayHttpEgressIsOptOutSource(string $source): bool { /* strayHttpEgressTokens → 上記判定 */ }
```

## 5-3. exemption inventory (enum docblock の対応先)

```php
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
```

---

# 6. 出力形式

以下の形式で返せ。

```
## Round 5 指摘の解消判定

### S4 [Warning] 補間開始トークンを波括弧深度に含める
判定: 解消 / 未解消
(未解消なら理由と、残っている具体的な欠陥だけを書く)

### S4 [Suggestion] 1 / 2
判定: 解消 / 未解消

## §3 の反証に対する検算
(こちらの事実認定「T_CURLY_OPEN の text は "{" であり、実際に壊れるのは
 T_DOLLAR_OPEN_CURLY_BRACES だけ」「Round 5 提示の単体テスト入力は空振り」が
 正しいかを述べよ。誤っているなら明確に指摘せよ)

## この対応が作った新たな欠陥 (あれば)
(無ければ「なし」と書く)

## 全体判定

**APPROVED** または **CHANGES_REQUESTED**
```

新規の掘り起こしは不要。**Round 5 の指摘が解消しているかだけを判定せよ。**
