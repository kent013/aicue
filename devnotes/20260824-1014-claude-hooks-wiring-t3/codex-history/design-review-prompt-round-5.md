# Round 5: Round 4 の指摘への対応と最終判定の依頼

Warning 5 件をすべて対応しました (見送りなし)。これが最終ラウンドです (スキル規約の上限 5 ラウンド)。

## 対応マトリクス

# 対応マトリクス: design-review Round 4

## S2 [Warning] `env timeout` の保証外の説明が実装と一致しない
- 判断: 対応する
- 根拠: 候補計数は行の全トークンを見るので `env timeout -k 2` は `timeout` と `-k` を持ち**候補になる**。
  docblock が「候補にならない」と書いていたのは実装と食い違っていた。
- 対応内容: 保証外の一覧から `env timeout` を外し、「**候補になる** (行の先頭語だけを見る判定ではない)」
  と整理した。S13e に `env timeout -k 2 "${X}"` が kill 候補 1 件になる正例を追加した。

## S2 [Warning] body 語彙に「打ち消し付き」の負例が無い (規約 (e) の 3 形が揃っていない)
- 判断: 対応する
- 根拠: `timeout` / `read` は 3 形あるが `INNER_TIMEOUT_SECONDS` は接頭辞と接尾辞だけだった。
- 対応内容: S13e の負例に `!INNER_TIMEOUT_SECONDS=20` を追加し、3 語彙すべてで 3 形が揃った。

## S2 [Warning] `claudeHooksInnerLimits()` の説明が候補計数側で狭めた保証より強く読める
- 判断: 対応する
- 根拠: 「申告に無い形が 1 件でも現れたら違反」「囮の行はすべて違反」は、あらゆる非正準表記を
  検出するように読める。実際は候補走査が宣言した語彙に完全一致する行だけである。
- 対応内容: 「**候補走査が申告対象と分類した行**のうち正準形でないものが 1 件でも現れたら違反」へ限定し、
  拾わない書き方 (絶対パス・別名・変数経由) の正本が候補走査の docblock であることを参照させた。
  違反メッセージの文言も「候補語彙に一致する囮の行は違反である」へ直した。

## S6 [Warning] 実読記録の撤回規則が内部で矛盾している (3 条なのに「どちらの場合も」)
- 判断: 対応する
- 根拠: 指摘のとおり。条件 3 (隣接 feature 側の改善) は文書更新の契機であって、
  未決論点への差し戻し理由ではない。
- 対応内容: 撤回規則を**条件ごとに採る手を書いた表**へ組み替えた —
  条件 1 = 新版を再実読して保証・非保証を書き直す / 条件 2 = 家系の未決論点へ差し戻す
  (`Bash` を足す形は採らない理由もここに置いた) / 条件 3 = 改善後の実装へ追従して 3 か所を更新する
  (差し戻しではない。家系判断の再提起は正典の形と食い違うときだけ)。

## S6 [Warning] AGENTS.md の括弧書きが無条件の回収を連想させる
- 判断: 対応する
- 根拠: 直後で限定していても、括弧書きの側が先に読まれる。
- 対応内容: 「= **条件を満たす追跡下の**変更が次の `Write` / `Edit` で回収される前提」へ揃えた。

## 該当箇所の修正後の記述

### 候補計数の docblock と `claudeHooksInnerLimits()` の fail-closed の記述 / S13e

 * 内側の上限に関わる**候補行**の数を数える (純関数)。
 *
 * 正準形に一致する行だけを数えると「正準形 1 本 + 非正準の実行行」の併存を見逃す。
 * そこで**コメント行を除いた実行行**のうち、関連する語彙を持つ行を候補として別に数え、
 * 呼び出し側が「候補数 == 正準形の一致数」を要求する。
 *
 * **区切りの宣言**: 行は半角空白・タブで**トークン**へ割り、代入は最初の `=` で
 * 左辺と右辺へ割る。判定はトークンの**完全一致**である (部分文字列一致に頼らない = 共通規約 (e))。
 * 候補の語彙は次の 3 つ:
 *  - `stdin` … トークンに `read` と `-t` の両方がある行
 *  - `body`  … 代入の左辺が `INNER_TIMEOUT_SECONDS` の行
 *  - `kill`  … トークンに `timeout` と `-k` の両方がある行
 *
 * **保証しないもの (誇張しない)**: 検出できるのは**宣言した語彙にトークン完全一致する
 * 非正準行の併存**だけである。同じ操作を別の書き方で行う行 — 絶対パス (`/usr/bin/timeout`)・
 * 別名・変数経由 (`"${TIMEOUT_BIN}"`) — は**候補にならないので併存を検出しない**。
 * 逆に `env timeout -k 2 …` は `timeout` と `-k` の両トークンを持つので**候補になる**
 * (行の先頭語だけを見る判定ではない)。
 * 語彙を増やして追いかけない (書き方の全数は列挙できない)。起動子の側で余計なトークンを
 * 禁じているのと違い、スクリプト本文は隣接 feature の領分なので、ここは
 * 「正準形の行が 1 本あること + 宣言した語彙の別行が無いこと」までを見る層である。
 *
 * @return array{stdin: int, body: int, kill: int}
 */
function claudeHooksInnerLimitCandidateCounts(string $body): array
{
    $counts = ['stdin' => 0, 'body' => 0, 'kill' => 0];

    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue; // コメント行と空行は実行行ではない
        }

        $tokens = preg_split('/[ \t]+/', $trimmed) ?: [];
        if (in_array('read', $tokens, true) && in_array('-t', $tokens, true)) {
            $counts['stdin']++;
        }
        if (in_array('timeout', $tokens, true) && in_array('-k', $tokens, true)) {
            $counts['kill']++;
        }
        foreach ($tokens as $token) {
            if (str_contains($token, '=') && explode('=', $token, 2)[0] === 'INNER_TIMEOUT_SECONDS') {
                $counts['body']++;
            }
        }
    }

    return $counts;
}
 * 起動先が自分で諦める内側の上限を、スクリプト本文から**数値で**取り出す (純関数。走査器)。
 *
 * **走査対象**: 台帳の 2 スクリプトの本文。
 * **抽出する 3 値** (どれも**行全体の正準形**で当てる。行頭・行末を固定するのでコメント行
 * (`#` で始まる行) は候補にならない):
 *  - `stdin` … `IFS= read -r -N <bytes> -t <秒> input || true` の秒数 (標準入力を待つ上限)
 *  - `body`  … `readonly INNER_TIMEOUT_SECONDS=<秒>` (更新本体の上限)
 *  - `kill`  … `timeout -k <秒> "${INNER_TIMEOUT_SECONDS}" \` の猶予
 *              (TERM で終わらない相手を KILL するまで)
 *
 * **fail-closed** (見逃す方向へ倒さない = AGENTS.md 共通規約 (b)):
 *  - 申告で必要な形は**ちょうど 1 件**であること。0 件 (数値以外・単位つき・変数展開・
 *    コメントアウト) と 2 件以上 (重複・囮の実行行) はどちらも違反にする
 *  - **候補走査 (`claudeHooksInnerLimitCandidateCounts()`) が申告対象と分類した行**のうち、
 *    正準形でないものが 1 件でも現れたら違反にする (候補の語彙と、その語彙が拾わない書き方 =
 *    絶対パス・別名・変数経由 は候補走査の docblock が正本)
 *  - 抽出できた値は**正の整数**であること (`0` は `timeout` の意味論を壊すので拒否する)
 *  - 台帳に無いスクリプトを渡されたら違反として返す (未知を黙って空で通さない)
 *
 * **保証しないもの**: 見るのは**行の形と数値だけ**であり、shell の制御フロー (その行が
 * 実際に実行されるか・別の待ちが挟まっているか) は見ない。したがって
 * 「実行時の上限を証明する」とは書けない — 主張できるのは
 * 「**明示された 3 つの上限の宣言**が配線の時間切れより小さい」までである。
 *
 * @return array{limits: array{stdin: ?int, body: ?int, kill: ?int}, violations: list<string>}
test('S13e (候補計数の裏取り): 候補の語彙が区切りトークンの完全一致で判定されること', function (): void {
    // 候補計数だけを直接検査する (S13b は「併存を検出できる」ことしか示さないので、
    // **誤検出しない側**をここで固定する = AGENTS.md 共通規約 (e) の 3 形)。
    // 正例
    expect(claudeHooksInnerLimitCandidateCounts('IFS= read -r -N 10 -t 5 input || true'))
        ->toBe(['stdin' => 1, 'body' => 0, 'kill' => 0]);
    expect(claudeHooksInnerLimitCandidateCounts('readonly INNER_TIMEOUT_SECONDS=20'))
        ->toBe(['stdin' => 0, 'body' => 1, 'kill' => 0]);
    expect(claudeHooksInnerLimitCandidateCounts('timeout -k 2 "${X}" \\'))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // 宣言した区切り: タブでも割れる
    expect(claudeHooksInnerLimitCandidateCounts("timeout\t-k\t2"))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // 行の先頭語だけを見る判定ではない (トークンのどこにあっても候補になる)
    expect(claudeHooksInnerLimitCandidateCounts('env timeout -k 2 "${X}"'))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // コメント行と空行は実行行ではない
    expect(claudeHooksInnerLimitCandidateCounts("# timeout -k 2\n\n   # readonly INNER_TIMEOUT_SECONDS=20"))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0]);

    // 負例: 接頭辞つき・打ち消しつき・接尾辞つきは候補にしない
    foreach ([
        'xtimeout -k 2', '!timeout -k 2', 'timeoutx -k 2',
        'xread -r -t 5', '!read -r -t 5', 'readx -r -t 5',
        'XINNER_TIMEOUT_SECONDS=20', '!INNER_TIMEOUT_SECONDS=20', 'INNER_TIMEOUT_SECONDSX=20',
    ] as $lookalike) {
        expect(claudeHooksInnerLimitCandidateCounts($lookalike))
            ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0], "トークン完全一致でない判定になっている: {$lookalike}");
    }
});

### 実読記録の撤回規則 (更新後)

## 撤回規則の発火条件 (この記録を根拠にする)

発火条件は 3 つで、**採る手は条件ごとに違う**。

| # | 発火条件 | 採る手 |
|---|---|---|
| 1 | 索引ツールの版を上げて `update` の既定の差分基準が変わった (`--base` の既定値 / `git diff` の対象が「作業ツリー」でなくなった / 未追跡ファイルの扱いが変わった) | **新しい版を再実読**し、上の 5 点と保証・非保証を書き直す (配線台帳の docblock と AGENTS.md も同じ変更で) |
| 2 | 上の「回収されない」2 系統が**実害として観測された** (索引が古いままコード探索が誤った結果を返した実測) | **家系の未決論点へ差し戻す** — セッション開始時に索引状態を出す任意の配線 (q1) / 配線の非同期実行 (q2)。**照合条件へ `Bash` を足す形は採らない** (正典 i2 / i15 が費用構造で外しており、未追跡の穴は matcher と直交するので足しても解消しない) |
| 3 | 隣接 feature (索引更新スクリプト) 側で未追跡ファイルへの対処が入った | 改善後の実装に合わせて**本記録・配線台帳の docblock・AGENTS.md を更新する**。これは追従であって差し戻しではない (家系判断の再提起は、対処が正典の形と食い違うときだけ行う) |

**既知の 2 系統そのものは受容する** — どちらも配線層では塞げず (`--base` を変える経路も
`git add` を起こす経路も配線に無い)、実害の観測なしに索引更新スクリプトの設計を動かすのは
隣接 feature への越境であり、思考原則 2 (今必要なものだけ作る) にも反する。

### AGENTS.md の該当行 (更新後)

- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。索引更新が**差分方式**であること(= 条件を満たす**追跡下**の変更が次の
  `Write` / `Edit` で回収される前提)は**索引ツール側の実装**であり、台帳テストは機械検証しない。実読記録は
  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`。
  既定の差分基準は `HEAD~1` から作業ツリーまでで、回収されるのは**追跡下のパス**に限る —
  **未追跡の新規ファイル**(`Write` で作ったものも `git add` まで同じ)と、
  **コミット後に編集を挟まずコミットを重ねた変更**は回収されない。
  **索引ツールを更新したら、matcher の意味論と併せてこの前提も人手で再確認する**。
