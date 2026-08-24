<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * 契約文書 §2 のゲート表が指すゲート名の**実在・件数・一意性**を固定する。
 *
 * 家系の裁定 AG-116 が定めた合成版の一部として、本アプリは
 * docs/app-integration-guide.md §2 に「新規リソースで必ず踏むゲート」と
 * 「条件付きで発火するゲート」の 2 表を持つ (設計は
 * devnotes/20260822-2305-integration-guide-gate-table-restore/)。表はゲート名を名指しするため、
 * ゲートの改名・削除で**表だけが古い名前を指し続ける**と、索引を読んで登録しに行った設計者が
 * 存在しないゲートを探すことになる。それを機械で落とす。
 *
 * ★走査対象: docs/app-integration-guide.md の **§2 の範囲だけ**。
 *   `## 2. ` の行から次の `## ` の行の手前までを切り出し、その中の 2 つのアンカー小見出しの
 *   直後にある表を見る。§2 の外の同名文字列は 1 件も見ない。
 * ★アンカーは**ちょうど 1 件**でなければならない (0 件 = 表が無い / 2 件以上 = 曖昧)。
 * ★表は**1 つの連続ブロック**でなければならない。アンカーの領域 (次の見出し行までの範囲) の中で
 *   `|` で始まる行がブロックの後にもう一度現れたら例外にする (表の切り詰めを件数 pin だけに
 *   頼らず、その場で落とす)。
 * ★区切り文字の宣言: 表の行を割るのは**半角縦棒 `|` の 1 文字だけ**である。
 *   セルは前後の空白を落として比較し、ゲート名は完全一致の正規表現で判定する。
 *   (走査器共通規約 (e) は許可語彙の除去や否定形の判定を持つ走査に掛かる条項であり、
 *   本検査は対象外である。ここでの宣言は独立した走査契約として置いている。)
 * ★列数はヘッダを基準に**完全一致**で見る — 区切り行もデータ行もヘッダと同じ列数でなければ
 *   例外にする (`INTEGRATION_GUIDE_MINIMUM_COLUMNS` はヘッダ自身の最低列数にだけ使う)。
 *   未エスケープの `|` による意図しない列分割もこれで落ちる。
 * ★ヘッダ区切り行は**セル単位**で検査する — ヘッダと同じ列数かつ 3 列以上で、
 *   各セルが配置指定を許す区切りセルの形 (`:` 任意 + ハイフン 3 つ以上 + `:` 任意) に
 *   完全一致すること。`||||` のような空セルだけの行や列数違いは受理しない。
 * ★**列 0 の限定文法である**。見出し・アンカー・表行は**行頭 (列 0) から始まる**ものだけを見る
 *   (右端の空白だけは落とす)。CommonMark が許す 3 スペースまでの字下げは受理しない。
 *   4 スペース字下げ (indented code) の見出し・表は候補に入らないので、本物の表を字下げへ退避させると
 *   「アンカーが無い」「表が無い」の例外になる (黙って 0 件にならない)。
 * ★**fenced code block の内側は 1 行も見ない**。列 0 の ``` / ~~~ で開いた領域は見出し・アンカー・表の
 *   どれとしても数えない。閉じ判定は CommonMark に従い**同じ記号・開始時以上の長さ・後ろは空白だけ**の
 *   行だけが閉じる (info string 付きの行や短い記号列では閉じない)。**閉じない fence**と
 *   **字下げされた fence** は例外にする (領域の終端が決まらない / 描画と走査が食い違うため)。
 *   本文の例示としてコードブロックの中に表を書いても、それが正本と誤認されることはない。
 * ★**表が始まった後の非空行は、列 0 の `|` 行でなければ例外**にする。GFM は先頭の `|` を省いた行も
 *   同じ表の行として解釈しうるため、無言で捨てると「実在しないゲートを足したのに件数も実在も
 *   素通りする」経路になる。表の**前** (アンカーと表の間の説明文) は自由に書いてよい。
 * ★**契約文書に HTML は書けない**。HTML コメント (`<!--` 〜 `-->`) も、`<pre>` のような HTML block も、
 *   現れたら**例外**にする。コメントの中に索引を退避させる / 偽の `## 3. ` で §2 を早期終了させる /
 *   `<pre>` で偽の `## 2. ` ごと覆う、といった「描画された §2 には索引が無いのに検査は緑」の経路を、
 *   個別に潰すのではなく**構文ごと拒否**して閉じる (走査対象は本アプリの 1 文書に限られるため、
 *   受理範囲を広げるより契約を狭める方が安全である)。
 *   ★**拒否は §2 を探す前に文書全体へ掛ける**。切り出してから見ると、HTML の開始行が §2 の手前に
 *   ある形 (切り出し結果に `<pre>` が入らない) を見逃すため、**章の境界を安全に確定する前提**として
 *   全体を先に見る。切り出した §2 にも同じ検査を掛ける (合成入力を直接受け取る経路のため)。
 * ★名前解決 (走査器共通規約 (a)) は行わない。見るのは
 *   `tests/Architecture/<名前>.php` が**regular file として実在するか**だけである。
 *   `.php` で終わるディレクトリは母集団に入れない。
 *
 * ---------------------------------------------------------------------------
 * **この検査が保証しないもの** (誇張しない。ここが正本であり AGENTS.md や
 * 契約文書本文には詳細を写さない):
 *
 *  1. **表の構成集合そのものは固定しない**。ある行を**別の実在するゲート名へ差し替える**ことは
 *     検出しない。21 件の期待集合を本ファイルへ写すと表と検査の 2 か所に同じ一覧を持つことになり、
 *     必ず食い違う。**正本は文書側の表 1 か所**とし、ここは件数・実在・一意性に限る
 *     (`LedgerPins` の 3 定数や ForbiddenStatement の件数 pin と同じ作法)。
 *  2. 表に書かれた**発火条件・登録先の意味的な正確さ**は見ない。表が宣言する実装単位
 *     (「この単位は必ず FormRequest を持つ」等) と 8 件の対応も見ない。
 *  3. **設計者が実際に §2 を読んで登録したかは見ない**。家系の正典が
 *     「設計時に §2〜§7 の判定を踏んだかどうかを確かめる機械は家系のどこにも無い」と
 *     記録しており、本検査はその状況を変えない。
 *  4. **索引の網羅性は主張しない**。表に載っていないゲートの存在は見ないので、
 *     「ここに無いゲートは発火しない」とは読めない。
 *  5. ゲートの**中身**が生きているか (その検査が空振りしていないか) は各ゲート自身の責務である。
 *  6. 表の列のうち 2 列目以降は見ない (パス表記や別ゲート名を書いてよい欄である)。
 *  7. **ゲート母集団の全体件数は見ない**。本検査の不変条件は「表に載せた 21 件が実在すること」で
 *     あって「ゲートが N 本あること」ではないため、根拠の無い下限値は持たない。
 *  8. **CommonMark / GFM の完全な構文解析はしない**。上に書いた列 0 の限定文法だけを受理し、
 *     規格上は表・見出しとして描画されうる形 (3 スペースまでの字下げ、先頭 `|` の省略、
 *     字下げ fence、文書内の HTML) は受理せず**例外**にする。つまり「規格で表になる書き方を
 *     すべて理解する」保証ではなく、「この文書はこの限定文法で書く」という契約を強制する保証である。
 *     文法を広げる (別記法を受理する) 変更をするなら、その記法の負例を同じ変更で足すこと。
 * ---------------------------------------------------------------------------
 *
 * 実行不能 (文書が読めない / §2 が無い / アンカーが 1 件でない / 表が無い / ヘッダや区切りが
 * 規定を外れる / 表が分割されている / 1 列目からゲート名を取り出せない) は
 * skip でも緑でもなく**不合格**にする。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/** 走査根 (リポジトリ相対)。 */
const INTEGRATION_GUIDE_SOURCE_PATH = 'docs/app-integration-guide.md';

/** ゲートの実装が置かれるディレクトリ (リポジトリ相対)。 */
const INTEGRATION_GUIDE_GATE_DIRECTORY = 'tests/Architecture';

/**
 * アンカー小見出し => 期待するゲート件数 (完全一致)。
 *
 * ★件数は**完全一致**で、増えても減っても赤になる。表の行を増減させるときは
 *   同じ変更でこの値を直す (無断の縮小を黙らせない)。
 * ★小見出しの文字列は文書側と本定数の 2 か所に現れる。**同じ変更で直す**こと
 *   (アンカーが無ければ例外になるので、片方だけ変えると必ず気付く)。
 *
 * @var array<string, int>
 */
const INTEGRATION_GUIDE_GATE_TABLES = [
    '#### 新規リソースで必ず踏む Architecture ゲート' => 8,
    '#### 条件付きで発火するゲート' => 13,
];

/** 1 列目のセルが満たすべき形 (バッククォート 1 対で囲まれた、末尾が Test の英数字)。 */
const INTEGRATION_GUIDE_GATE_CELL = '/^`([A-Za-z][A-Za-z0-9]*Test)`$/';

/**
 * ヘッダ自身の最低列数 (ゲート / 説明 / 登録先)。
 *
 * ★区切り行とデータ行はこの値ではなく**ヘッダの列数との完全一致**で見る。
 */
const INTEGRATION_GUIDE_MINIMUM_COLUMNS = 3;

/** ヘッダ区切り行の 1 セルが満たすべき形 (配置指定の `:` は任意、ハイフンは 3 つ以上)。 */
const INTEGRATION_GUIDE_SEPARATOR_CELL = '/^:?-{3,}:?$/';

/**
 * 走査が空振りでないことを確かめる代表ゲート (母集団に必ず在るもの)。
 *
 * @var list<string>
 */
const INTEGRATION_GUIDE_SENTINEL_GATES = [
    'ControllerAuthorizationGateTest',
    'NestedRouteIdorDefenseTest',
];

/**
 * 契約文書の本文 (読めないことは空ではなく不合格)。
 */
function integrationGuideMarkdown(): string
{
    $markdown = @file_get_contents(base_path(INTEGRATION_GUIDE_SOURCE_PATH));
    Assert::string($markdown, INTEGRATION_GUIDE_SOURCE_PATH.' を読めない (実行不能は不合格)');

    return $markdown;
}

/**
 * 本文を行へ割る (改行の種類に依存しない)。
 *
 * @return list<string>
 */
function integrationGuideLines(string $text): array
{
    $lines = preg_split('/\R/u', $text);
    Assert::isArray($lines, '本文を行へ割れない');
    Assert::allString($lines, '本文の行が文字列ではない');

    return array_values($lines);
}

/** 走査上「描画される本文」である行。 */
const INTEGRATION_GUIDE_LINE_VISIBLE = 'visible';

/** fenced code block の行 (fence 行そのものを含む)。 */
const INTEGRATION_GUIDE_LINE_FENCE = 'fence';

/** HTML コメントの行 (開始行・終了行を含む)。 */
const INTEGRATION_GUIDE_LINE_COMMENT = 'comment';

/**
 * 行ごとに「描画されない領域」を分類する。
 *
 * ★fence は**列 0 から始まる** ``` / ~~~ (3 つ以上の連続) だけを認める。CommonMark が許す
 *   1〜3 スペース字下げの fence は**受理せず例外**にする — 「描画上はコードだが走査器は構造行として
 *   数える」という食い違いを残さないため (列 0 の限定文法という契約を字下げ側にも適用する)。
 * ★閉じ判定は CommonMark に従う: **開いたときと同じ記号**で、**開始時以上の長さ**で、
 *   **記号の後が空白だけ**の行だけが閉じる。つまり ```` ```not-a-close ```` のように info string が付く行は
 *   閉じ fence ではなく中身であり、4 連で開いた fence を 3 連の行では閉じられない。
 *   (これを緩めると「見た目はコードブロックの中なのに走査器は本物の表と見なす」経路が開く。)
 * ★開始 fence の info string に fence 記号そのものを混ぜた形は曖昧なので例外にする。
 * ★fence 行そのものも「内側」として扱う (見出し・表として数えない)。
 * ★**HTML コメント (`<!--` 〜 `-->`) も描画されない領域**として同じように扱う。コメントの中の
 *   見出し・表・章見出しを構造行として数えると、「描画された §2 には索引が無いのに検査は緑」という
 *   fail-open になる (偽の `## 3. ` で §2 を早期終了させる形も含む)。
 * ★**閉じない fence / 閉じない HTML コメントは例外**にする — 領域の終端が決まらない入力を
 *   黙って受理しない (fail-closed)。
 *
 * @param  list<string>  $lines
 * @return list<string> 各行が visible / fence / comment のいずれか
 */
function integrationGuideHiddenMask(array $lines): array
{
    /** @var list<string> $mask */
    $mask = [];
    $openChar = null;
    $openLength = 0;
    $openedAt = 0;
    $inComment = false;
    $commentOpenedAt = 0;

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        if ($openChar !== null) {
            // fence の内側。閉じ fence の条件を満たす行だけが閉じる (満たさない行はただの中身)。
            $mask[] = INTEGRATION_GUIDE_LINE_FENCE;

            if (preg_match('/^(`{3,}|~{3,})(.*)$/', $line, $closing) === 1) {
                Assert::keyExists($closing, 1, 'fence 記号の捕獲群が取れない');
                Assert::keyExists($closing, 2, 'fence の残り部分の捕獲群が取れない');
                Assert::string($closing[1], 'fence 記号が文字列ではない');
                Assert::string($closing[2], 'fence の残り部分が文字列ではない');

                if (
                    substr($closing[1], 0, 1) === $openChar
                    && strlen($closing[1]) >= $openLength
                    && trim($closing[2]) === ''
                ) {
                    $openChar = null;
                    $openLength = 0;
                }
            }

            continue;
        }

        if ($inComment) {
            $mask[] = INTEGRATION_GUIDE_LINE_COMMENT;

            if (str_contains($line, '-->')) {
                $inComment = false;
            }

            continue;
        }

        if (preg_match('/^[ \t]{0,3}<!--(.*)$/', $line, $comment) === 1) {
            Assert::keyExists($comment, 1, 'HTML コメントの残り部分の捕獲群が取れない');
            Assert::string($comment[1], 'HTML コメントの残り部分が文字列ではない');

            $mask[] = INTEGRATION_GUIDE_LINE_COMMENT;

            if (! str_contains($comment[1], '-->')) {
                $inComment = true;
                $commentOpenedAt = $lineNumber;
            }

            continue;
        }

        if (preg_match('/^[ \t]+(`{3,}|~{3,})/', $line) === 1) {
            throw new RuntimeException(
                $lineNumber.' 行目: 字下げされた code fence は受理しない '
                .'(本書の走査は列 0 の限定文法である。描画と走査が食い違う形を残さない): '.trim($line),
            );
        }

        if (preg_match('/^(`{3,}|~{3,})(.*)$/', $line, $matches) !== 1) {
            $mask[] = INTEGRATION_GUIDE_LINE_VISIBLE;

            continue;
        }

        Assert::keyExists($matches, 1, 'fence 記号の捕獲群が取れない');
        Assert::keyExists($matches, 2, 'fence の残り部分の捕獲群が取れない');
        Assert::string($matches[1], 'fence 記号が文字列ではない');
        Assert::string($matches[2], 'fence の残り部分が文字列ではない');

        $marker = $matches[1];
        $rest = $matches[2];
        $char = substr($marker, 0, 1);

        if (str_contains($rest, $char)) {
            throw new RuntimeException(
                $lineNumber.' 行目: code fence の開始行に fence 記号が混ざっている '
                .'(どこまでが記号か決まらない): '.trim($line),
            );
        }

        $openChar = $char;
        $openLength = strlen($marker);
        $openedAt = $lineNumber;
        $mask[] = INTEGRATION_GUIDE_LINE_FENCE;
    }

    if ($openChar !== null) {
        throw new RuntimeException(
            '閉じていない code fence がある ('.$openedAt.' 行目の `'.str_repeat($openChar, $openLength)
            .'`)。どこまでがコードか決まらない入力は受理しない',
        );
    }

    if ($inComment) {
        throw new RuntimeException(
            '閉じていない HTML コメントがある ('.$commentOpenedAt.' 行目の `<!--`)。'
            .'どこまでがコメントか決まらない入力は受理しない',
        );
    }

    return $mask;
}

/**
 * 見出し・アンカー・表行として見てよい行かどうか。
 *
 * ★列 0 から始まること (字下げは受理しない) と、fenced code block / HTML コメントの
 *   **外**であること (= 描画される本文であること) を要求する。
 *
 * @param  list<string>  $mask
 */
function integrationGuideIsStructural(array $mask, int $index): bool
{
    return array_key_exists($index, $mask) && $mask[$index] === INTEGRATION_GUIDE_LINE_VISIBLE;
}

/**
 * §2 の範囲だけを切り出す。
 *
 * 見出しが無いことは**空ではなく例外**にする (走査根の改名・章立ての変更で
 * 母集団が空になったまま緑になる形を作らない)。
 * ★コードブロック・HTML コメントの中の `## 2. ` は章見出しとして数えない (描画されない領域の
 *   mask を通す)。コメントの中に書いた偽の `## 3. ` で §2 を早期終了させることもできない。
 */
function integrationGuideSectionTwo(string $markdown): string
{
    $lines = integrationGuideLines($markdown);
    $mask = integrationGuideHiddenMask($lines);

    // 章の境界を決める前に、文書全体で HTML を拒否する (偽の章見出しを HTML で覆わせない)。
    integrationGuideRejectHtml($lines, $mask, '契約文書');

    /** @var list<int> $starts */
    $starts = [];

    foreach ($lines as $index => $line) {
        if (integrationGuideIsStructural($mask, $index) && str_starts_with($line, '## 2. ')) {
            $starts[] = $index;
        }
    }

    if ($starts === []) {
        throw new RuntimeException(
            '§2 の見出し (`## 2. ` で始まる行) が '.INTEGRATION_GUIDE_SOURCE_PATH.' に無い',
        );
    }

    if (count($starts) > 1) {
        throw new RuntimeException(
            '§2 の見出しが '.count($starts).' 件ある (章構造が曖昧なのでどの範囲を走査するか決まらない)',
        );
    }

    $start = $starts[0];

    foreach (array_slice($lines, $start + 1) as $offset => $line) {
        if (integrationGuideIsStructural($mask, $start + 1 + $offset) && str_starts_with($line, '## ')) {
            return implode("\n", array_slice($lines, $start, $offset + 1));
        }
    }

    return implode("\n", array_slice($lines, $start));
}

/**
 * 走査範囲に HTML が現れたら例外にする。
 *
 * ★HTML コメントも HTML block も**構文ごと拒否**する。コメントの中へ索引を退避させる形や、
 *   `<pre>` の中に表を置く形は、描画上は索引が無いのに検査だけが緑になる経路になるためである。
 *   「見なかったことにする」ではなく「その構文を書かせない」ことで、この種の食い違いを一括で閉じる。
 * ★**§2 を探す前に契約文書の全体へ適用する**。§2 を切り出してから見ると、`<pre>` の開始行を
 *   偽の `## 2. ` より前に置く形 (切り出し結果に HTML の開始行が入らない) を見逃す —
 *   章の境界を安全に決めるには、境界を決める前に文書全体で拒否しておく必要がある。
 *   切り出した §2 にも同じ検査を掛ける (合成入力を直接受け取る経路のため)。
 * ★HTML block は CommonMark に合わせて**3 スペースまでの字下げ**も対象にする
 *   (字下げしても HTML block として描画されるため)。
 *
 * @param  list<string>  $lines
 * @param  list<string>  $mask
 * @param  string  $scope  違反位置の呼び方 (契約文書 / §2 内)
 */
function integrationGuideRejectHtml(array $lines, array $mask, string $scope): void
{
    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        if (($mask[$index] ?? INTEGRATION_GUIDE_LINE_VISIBLE) === INTEGRATION_GUIDE_LINE_COMMENT) {
            throw new RuntimeException(
                $scope.' '.$lineNumber.' 行目: HTML コメントは書けない '
                .'(コメントの中の見出し・表・章見出しは描画されないため、索引の退避先にできてしまう): '
                .trim($line),
            );
        }

        if (! integrationGuideIsStructural($mask, $index)) {
            continue;
        }

        if (preg_match('/^[ \t]{0,3}</', $line) === 1) {
            throw new RuntimeException(
                $scope.' '.$lineNumber.' 行目: HTML block は書けない '
                .'(Markdown の限定文法だけで書く。章の境界を HTML で覆えないようにするため、'
                .'§2 の外も対象である): '.trim($line),
            );
        }
    }
}

/**
 * §2 の中でアンカー小見出しがちょうど 1 件あることを確かめ、その位置を返す。
 *
 * 0 件と 2 件以上でメッセージを分ける (どちらも例外)。
 * ★列 0 から始まり、fenced code block の外にある行だけをアンカーとして数える
 *   (字下げされた見出しやコードブロック内の見本は本物ではない)。
 *
 * @param  list<string>  $lines
 * @param  list<string>  $mask
 */
function integrationGuideAnchorIndex(array $lines, array $mask, string $anchor): int
{
    /** @var list<int> $found */
    $found = [];

    foreach ($lines as $index => $line) {
        if (integrationGuideIsStructural($mask, $index) && rtrim($line) === $anchor) {
            $found[] = $index;
        }
    }

    if ($found === []) {
        throw new RuntimeException('アンカー小見出し「'.$anchor.'」が §2 に無い');
    }

    if (count($found) > 1) {
        throw new RuntimeException(
            'アンカー小見出し「'.$anchor.'」が §2 に '.count($found)
            .' 件ある (ちょうど 1 件でなければどの表が正本か決まらない)',
        );
    }

    return $found[0];
}

/**
 * アンカーの領域から表の行だけを取り出す (1 始まりの行番号つき)。
 *
 * ★領域はアンカーの次の行から**次の見出し行 (列 0 の `#` で始まる行) の手前**までである。
 * ★行は**列 0 から始まり fenced code block の外にある**ものだけを構造として見る。
 * ★領域の中の `|` 行は**1 つの連続ブロック**でなければならない。ブロックが閉じた後に
 *   `|` 行が現れたら例外にする (表の切り詰め・分割をその場で落とす)。
 * ★**表が始まった後の非空行は列 0 の `|` 行でなければ例外**にする。GFM は先頭の `|` を省いた
 *   `` `X` | 説明 | 登録先 `` も同じ表の行として解釈しうるため、無言で捨てると件数 pin・実在・
 *   一意性の 3 判定をまとめて迂回できてしまう (表の**前**の説明文は自由)。
 *
 * @param  list<string>  $lines
 * @param  list<string>  $mask
 * @return list<array{0: int, 1: string}>
 */
function integrationGuideTableLines(array $lines, array $mask, int $anchorIndex, string $anchor): array
{
    /** @var list<array{0: int, 1: string}> $rows */
    $rows = [];
    $started = false;
    $closed = false;

    foreach (array_slice($lines, $anchorIndex + 1) as $offset => $line) {
        $index = $anchorIndex + 1 + $offset;
        $lineNumber = $index + 1;
        $structural = integrationGuideIsStructural($mask, $index);
        $body = rtrim($line);

        if ($structural && str_starts_with($body, '#')) {
            break;
        }

        if ($structural && str_starts_with($body, '|')) {
            if ($closed) {
                throw new RuntimeException(
                    'アンカー「'.$anchor.'」の領域で表が 2 か所に分かれている '
                    .'(§2 内 '.$lineNumber.' 行目)。表は 1 つの連続ブロックで書く',
                );
            }

            $started = true;
            $rows[] = [$lineNumber, $body];

            continue;
        }

        if (! $started) {
            continue;
        }

        if (trim($body) === '') {
            $closed = true;

            continue;
        }

        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表の後に、列 0 の `|` で始まらない非空行がある '
            .'(§2 内 '.$lineNumber.' 行目): '.$body
            .' — 表の行は列 0 の `|` から書き、コードブロックや字下げに退避させない',
        );
    }

    return $rows;
}

/**
 * 表の 1 行をセルへ割る。
 *
 * ★独立した走査契約として、区切りを**半角縦棒 `|` の 1 文字だけ**に固定する。
 *   前後の空白は落とす。両端の空セルは区切りの副産物なので捨てる。
 *
 * @return list<string>
 */
function integrationGuideCells(string $row): array
{
    $cells = array_map(static fn (string $cell): string => trim($cell), explode('|', $row));

    if ($cells !== [] && $cells[0] === '') {
        array_shift($cells);
    }
    if ($cells !== [] && end($cells) === '') {
        array_pop($cells);
    }

    return array_values($cells);
}

/**
 * アンカー小見出しの直後にある表のデータ行から、1 列目のゲート名を取り出す。
 *
 * ★**正常に全行を解決できたときだけ** `list<string>` を返す。解決できない行が 1 行でもあれば
 *   行番号と理由を持つ例外を投げる (未解決を戻り値へ混ぜない / 無言で候補から外さない)。
 * ★行番号は §2 の切り出しの中での 1 始まりの位置である (絶対行ではない)。
 *
 * @return list<string>
 */
function integrationGuideGateNames(string $section, string $anchor): array
{
    $lines = integrationGuideLines($section);
    $mask = integrationGuideHiddenMask($lines);
    integrationGuideRejectHtml($lines, $mask, '§2 内');
    $anchorIndex = integrationGuideAnchorIndex($lines, $mask, $anchor);
    $tableLines = integrationGuideTableLines($lines, $mask, $anchorIndex, $anchor);

    if (count($tableLines) < 3) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の直後に表 (ヘッダ / 区切り / データ行) が無い',
        );
    }

    [, $headerRow] = $tableLines[0];
    $headerCells = integrationGuideCells($headerRow);

    if (count($headerCells) < INTEGRATION_GUIDE_MINIMUM_COLUMNS) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表のヘッダが '.INTEGRATION_GUIDE_MINIMUM_COLUMNS
            .' 列に足りない (実測 '.count($headerCells).' 列): '.$headerRow,
        );
    }

    if ($headerCells[0] !== 'ゲート') {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表の 1 列目の見出しが「ゲート」ではない (実測: '
            .$headerCells[0].')',
        );
    }

    [, $separatorRow] = $tableLines[1];
    $separatorCells = integrationGuideCells($separatorRow);

    if (count($separatorCells) !== count($headerCells)) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表の区切り行の列数 ('.count($separatorCells)
            .') がヘッダの列数 ('.count($headerCells).') と違う: '.$separatorRow,
        );
    }

    foreach ($separatorCells as $position => $cell) {
        if (preg_match(INTEGRATION_GUIDE_SEPARATOR_CELL, $cell) !== 1) {
            throw new RuntimeException(
                'アンカー「'.$anchor.'」の表の区切り行の '.($position + 1)
                .' 列目が区切りセルの形ではない (実測: '.$cell.'): '.$separatorRow,
            );
        }
    }

    /** @var list<string> $names */
    $names = [];

    foreach (array_slice($tableLines, 2) as [$lineNumber, $row]) {
        $cells = integrationGuideCells($row);

        if (count($cells) !== count($headerCells)) {
            throw new RuntimeException(
                '§2 内 '.$lineNumber.' 行目: 表の行の列数 ('.count($cells)
                .') がヘッダの列数 ('.count($headerCells).') と一致しない '
                .'(セル内に区切りの `|` を書いていないか): '.$row,
            );
        }

        if (preg_match(INTEGRATION_GUIDE_GATE_CELL, $cells[0], $matches) !== 1) {
            throw new RuntimeException(
                '§2 内 '.$lineNumber.' 行目: 1 列目からゲート名を取り出せない '
                .'(バッククォート 1 対で囲んだ、末尾が Test の英数字だけを許す。'
                .'パス表記や .php は 1 列目に書かない)。実測: '.$cells[0],
            );
        }

        Assert::keyExists($matches, 1, '正規表現の捕獲群が取れない');
        Assert::string($matches[1], '捕獲したゲート名が文字列ではない');

        $names[] = $matches[1];
    }

    return $names;
}

/**
 * 実在するゲート名の母集団 (拡張子なし)。
 *
 * ★ディレクトリが無い・読めないことは空ではなく例外にする (fail-open を作らない)。
 * ★regular file だけを数える (`.php` で終わるディレクトリは母集団に入れない)。
 *
 * @return list<string>
 */
function integrationGuideExistingGates(): array
{
    $directory = base_path(INTEGRATION_GUIDE_GATE_DIRECTORY);
    Assert::directory($directory, INTEGRATION_GUIDE_GATE_DIRECTORY.' がディレクトリとして無い');
    Assert::readable($directory, INTEGRATION_GUIDE_GATE_DIRECTORY.' を読めない');

    $paths = glob($directory.'/*.php');
    Assert::isArray($paths, INTEGRATION_GUIDE_GATE_DIRECTORY.' を列挙できない');

    /** @var list<string> $names */
    $names = [];

    foreach ($paths as $path) {
        Assert::string($path, '列挙したパスが文字列ではない');

        if (! is_file($path)) {
            continue;
        }

        $names[] = basename($path, '.php');
    }

    sort($names);

    return $names;
}

/**
 * 抽出した名前を、件数 pin / 実在 / 一意性の 3 観点で突き合わせる (純関数)。
 *
 * ★負のコントロールは実ファイルを触らず、合成した `$tables` と `$existing` を渡して同じ関数を走らせる。
 *
 * @param  array<string, list<string>>  $tables  アンカー => 1 列目のゲート名
 * @param  array<string, int>  $expected  アンカー => 期待件数
 * @param  list<string>  $existing  実在するゲート名
 * @return list<string>
 */
function integrationGuideGateTableViolations(array $tables, array $expected, array $existing): array
{
    /** @var list<string> $violations */
    $violations = [];
    /** @var array<string, string> $seen ゲート名 => 初出のアンカー */
    $seen = [];

    foreach ($expected as $anchor => $count) {
        if (! array_key_exists($anchor, $tables)) {
            $violations[] = 'アンカー「'.$anchor.'」の表が抽出できていない';

            continue;
        }

        $names = $tables[$anchor];

        if (count($names) !== $count) {
            $violations[] = 'アンカー「'.$anchor.'」のゲート件数が '.count($names)
                .' 件で、pin した '.$count.' 件と食い違う (表を増減させたら同じ変更で pin も直す)';
        }

        foreach ($names as $name) {
            if (! in_array($name, $existing, true)) {
                $violations[] = 'ゲート `'.$name.'` が '
                    .INTEGRATION_GUIDE_GATE_DIRECTORY.' に実在しない (改名・削除で索引が腐っている)';
            }

            if (isset($seen[$name])) {
                $violations[] = 'ゲート `'.$name.'` が重複している ('
                    .$seen[$name].' と '.$anchor.')';

                continue;
            }

            $seen[$name] = $anchor;
        }
    }

    return $violations;
}

/**
 * 実ファイルから 2 表を抽出する。
 *
 * @return array<string, list<string>>
 */
function integrationGuideGateTables(): array
{
    $section = integrationGuideSectionTwo(integrationGuideMarkdown());

    /** @var array<string, list<string>> $tables */
    $tables = [];

    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
        $tables[$anchor] = integrationGuideGateNames($section, $anchor);
    }

    return $tables;
}

/**
 * 負のコントロール用に §2 相当の合成入力を組み立てる。
 *
 * 規定どおりの形を既定とし、引数で行だけを差し替える。
 */
function integrationGuideSyntheticSection(
    string $rows,
    ?string $anchor = null,
    ?string $header = null,
    ?string $separator = null,
    string $trailing = '',
): string {
    $anchor ??= '#### 新規リソースで必ず踏む Architecture ゲート';
    $header ??= '| ゲート | 何を落とすか | 何をどこへ登録するか |';
    $separator ??= '|---|---|---|';

    return implode("\n", [
        '## 2. ドメインモデルの配置',
        '',
        $anchor,
        '',
        $header,
        $separator,
        $rows,
        '',
        $trailing,
        '',
    ]);
}

test('§2 の 2 表が実在し、件数 pin / 実在 / 一意性を満たす', function (): void {
    $violations = integrationGuideGateTableViolations(
        integrationGuideGateTables(),
        INTEGRATION_GUIDE_GATE_TABLES,
        integrationGuideExistingGates(),
    );

    expect($violations)->toBe([], "§2 のゲート表の違反:\n".implode("\n", $violations));
});

test('走査が空振りしていない (走査根 / §2 / 各表の非空 / ゲート母集団)', function (): void {
    // 走査根と §2 が生きていること
    $section = integrationGuideSectionTwo(integrationGuideMarkdown());
    expect($section)->toContain('## 2. ');

    // 各表のデータ行が非空であること (母集団 0 件を緑にしない)
    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
        expect(integrationGuideGateNames($section, $anchor))->not->toBeEmpty();
    }

    // ゲート母集団が非空で、代表ゲートが在ること (全体件数の下限は持たない)
    $existing = integrationGuideExistingGates();
    expect($existing)->not->toBeEmpty();
    foreach (INTEGRATION_GUIDE_SENTINEL_GATES as $sentinel) {
        expect($existing)->toContain($sentinel);
    }
});

test('負のコントロール: §2 が 0 件でも 2 件でも例外になる', function (): void {
    // 走査根を差し替えると母集団が作れない (無言で 0 件にならない)
    expect(static function (): void {
        integrationGuideSectionTwo("# 別の文書\n\n## 3. 別の章\n");
    })->toThrow(RuntimeException::class);

    // 章見出しが 2 件あると、どの範囲を走査するか決まらない
    expect(static function (): void {
        integrationGuideSectionTwo("## 2. 章\n\n本文\n\n## 2. 章がもう 1 つ\n");
    })->toThrow(RuntimeException::class);

    // HTML コメントは文書全体で拒否する (索引や偽の章見出しの退避先を作らせない)
    expect(static function (): void {
        integrationGuideSectionTwo("## 2. 章\n\n<!--\n## 3. 偽の終端\n-->\n\n本文\n\n## 3. 次の章\n");
    })->toThrow(RuntimeException::class);

    // コードブロックの中の偽の `## 3. ` では §2 を終わらせられない
    // (章の終端も**描画される本文**だけで決める。コードブロック自体は本文中に書いてよい)
    $section = integrationGuideSectionTwo(
        "## 2. 章\n\n```markdown\n## 3. 偽の終端\n```\n\n本物の本文\n\n## 3. 次の章\n本文\n",
    );
    expect($section)->toContain('本物の本文');
    expect($section)->not->toContain('## 3. 次の章');
});

test('負例: §2 の手前に置いた HTML block で偽の章を作れない (境界を決める前に拒否する)', function (): void {
    // `<pre>` の開始行を偽の `## 2. ` より **前** に置き、偽の `## 3. ` も block の中に置く形。
    // 切り出してから HTML を見る実装ではこの開始行が切り出し結果に入らないため素通りする。
    $markdown = implode("\n", [
        '# 契約文書',
        '',
        '<pre>',
        '## 2. ドメインモデルの配置',
        '',
        '#### 新規リソースで必ず踏む Architecture ゲート',
        '',
        '| ゲート | 何を落とすか | 何をどこへ登録するか |',
        '|---|---|---|',
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        '',
        '## 3. 偽の終端',
        '</pre>',
        '',
        '## 3. 本来の章',
        '',
    ]);

    expect(static function () use ($markdown): void {
        integrationGuideSectionTwo($markdown);
    })->toThrow(RuntimeException::class);
});

test('負例: 表の形が規定を外れると例外になる', function (
    string $rows,
    ?string $header,
    ?string $separator,
    string $trailing,
): void {
    $section = integrationGuideSyntheticSection($rows, null, $header, $separator, $trailing);

    expect(static function () use ($section): void {
        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
    })->toThrow(RuntimeException::class);
})->with([
    'バッククォート欠落' => ['| MassAssignmentSafetyTest | 落とすもの | 登録先 |', null, null, ''],
    'ゲート列が空' => ['|  | 落とすもの | 登録先 |', null, null, ''],
    'パス表記' => ['| `tests/Architecture/MassAssignmentSafetyTest.php` | 落とすもの | 登録先 |', null, null, ''],
    '末尾が Test でない' => ['| `MassAssignmentSafety` | 落とすもの | 登録先 |', null, null, ''],
    'データ行がヘッダより少ない' => ['| `MassAssignmentSafetyTest` | 落とすもの |', null, null, ''],
    'データ行がヘッダより多い' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 | 備考 |',
        null,
        null,
        '',
    ],
    'ヘッダの 1 列目が「ゲート」でない' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        '| 検査 | 何を落とすか | 何をどこへ登録するか |',
        null,
        '',
    ],
    'ヘッダが 3 列に足りない' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        '| ゲート | 何を落とすか |',
        '|---|---|',
        '',
    ],
    '区切り行が見出し語' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        null,
        '| 区切りではない | 行 | である |',
        '',
    ],
    '区切り行の列数がヘッダと違う' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        null,
        '|---|---|',
        '',
    ],
    '区切り行が空セルだけ' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        null,
        '||||',
        '',
    ],
    '区切りセルの 1 つだけが不正' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        null,
        '|---|--|---|',
        '',
    ],
    '表が 2 か所に分かれている' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        null,
        null,
        '| `ControllerAuthorizationGateTest` | 落とすもの | 登録先 |',
    ],
]);

test('負例: アンカーが 1 件でないと例外になる', function (string $section): void {
    expect(static function () use ($section): void {
        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
    })->toThrow(RuntimeException::class);
})->with([
    'アンカーが 0 件' => [
        integrationGuideSyntheticSection(
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '#### 別の小見出し',
        ),
    ],
    'アンカーが 2 件' => [
        integrationGuideSyntheticSection('| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |')
        ."\n#### 新規リソースで必ず踏む Architecture ゲート\n",
    ],
]);

test('負例: Markdown の構造境界 (code fence / 字下げ / 先頭 `|` の省略) を素通りさせない', function (
    string $section,
): void {
    expect(static function () use ($section): void {
        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
    })->toThrow(RuntimeException::class);
})->with([
    // 本物の表を消し、コードブロックの中だけに見出しと表を置いても「在る」ことにはならない
    'コードブロックの中のアンカーと表' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '```markdown',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '```',
            '',
        ]),
    ],
    // 4 スペース字下げ (indented code) の見出し・表も本物ではない
    '4 スペース字下げのアンカーと表' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '    #### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '    | ゲート | 何を落とすか | 何をどこへ登録するか |',
            '    |---|---|---|',
            '    | `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '',
        ]),
    ],
    // GFM では表の行になりうる「先頭 `|` の無い行」を無言で捨てない
    '表の後に先頭 `|` の無い行が続く' => [
        integrationGuideSyntheticSection(
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            null,
            null,
            null,
            '`NoSuchGateTest` | 落とすもの | 登録先',
        ),
    ],
    // 閉じない fence はどこまでがコードか決まらないので実行不能 = 不合格
    '閉じていない code fence' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '',
            '```markdown',
            '閉じ忘れたコードブロック',
            '',
        ]),
    ],
    // CommonMark では info string 付きの行は閉じ fence ではない (中身のままである)。
    // ★この入力は CommonMark としては「1 つのコードブロック」で完結しており、
    //   素朴に「``` で始まる行で開閉する」判定だけが開閉の対を取り違えて中身を構造行に昇格させる。
    'info string 付きの行では fence が閉じない' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '```markdown',
            '```not-a-close',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '```not-a-close-either',
            '```',
            '',
        ]),
    ],
    // 4 連で開いた fence は 3 連の行では閉じない (短い記号列は閉じ fence にならない)。
    // ★これも CommonMark としては 1 つのコードブロックであり、長さを見ない判定だけが取り違える。
    '4 連で開いた fence を 3 連では閉じられない' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '````markdown',
            '```',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '```',
            '````',
            '',
        ]),
    ],
    // HTML コメントの中に索引を退避させる形 (描画された §2 には索引が無い)
    'HTML コメントの中のアンカーと表' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '<!--',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '-->',
            '',
        ]),
    ],
    // HTML block (`<pre>` 等) の中に置く形も構文ごと拒否する
    'HTML block の中のアンカーと表' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '<pre>',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '</pre>',
            '',
        ]),
    ],
    // 閉じない HTML コメントはどこまでがコメントか決まらないので実行不能 = 不合格
    '閉じていない HTML コメント' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '',
            '<!-- 閉じ忘れたコメント',
            '',
        ]),
    ],
    // CommonMark 上は有効な 1〜3 スペース字下げの fence。列 0 の限定文法なので例外にする
    '3 スペース字下げの fence の中のアンカーと表' => [
        implode("\n", [
            '## 2. ドメインモデルの配置',
            '',
            '   ```markdown',
            '#### 新規リソースで必ず踏む Architecture ゲート',
            '',
            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
            '|---|---|---|',
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '   ```',
            '',
        ]),
    ],
]);

test('負例: 不存在・重複・件数不一致は違反として報告される', function (): void {
    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';
    $other = '#### 条件付きで発火するゲート';

    // 実在しないゲート名
    expect(integrationGuideGateTableViolations(
        [$anchor => ['NoSuchGateTest']],
        [$anchor => 1],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();

    // 表をまたいだ重複
    expect(integrationGuideGateTableViolations(
        [$anchor => ['MassAssignmentSafetyTest'], $other => ['MassAssignmentSafetyTest']],
        [$anchor => 1, $other => 1],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();

    // 件数不一致 (減った側)
    expect(integrationGuideGateTableViolations(
        [$anchor => ['MassAssignmentSafetyTest']],
        [$anchor => 2],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();
});

test('正例: 規定どおりの合成入力は誤検出しない (配置指定つきの区切りも受理する)', function (): void {
    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';

    $rows = implode("\n", [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        '| `ControllerAuthorizationGateTest` | 落とすもの | `tests/Architecture` への言及は 2 列目以降なら可 |',
    ]);

    $names = integrationGuideGateNames(integrationGuideSyntheticSection($rows), $anchor);

    // 配置指定つきの区切り (`:---` / `---:` / `:---:`) も規定内である
    $aligned = integrationGuideGateNames(
        integrationGuideSyntheticSection($rows, null, null, '|:---|---:|:---:|'),
        $anchor,
    );

    expect($aligned)->toBe($names);
    expect($names)->toBe(['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest']);
    expect(integrationGuideGateTableViolations(
        [$anchor => $names],
        [$anchor => 2],
        ['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest'],
    ))->toBe([]);
});
