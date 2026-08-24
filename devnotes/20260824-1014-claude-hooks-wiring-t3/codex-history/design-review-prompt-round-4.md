# Round 4: Round 3 の指摘への対応と再レビュー依頼

Critical 1 件・Warning 6 件をすべて対応しました (見送りなし)。これが 4 ラウンド目です
(スキル規約の上限は 5 ラウンド)。

主要な変更点:
1. **未追跡ファイルの穴を実測で確認し、i15 の文面を全面的に狭めた** — 一時 git リポジトリで
   `git diff --name-only HEAD~1 --` が未追跡ファイルを列挙しないこと (add 後には出ること) を実測。
   回収の条件を「追跡下のパスで、作業ツリーの内容が HEAD~1 と違うこと」に限定し、
   回収されない 2 系統 (未追跡の新規ファイル / 差分基準から外れた過去のコミット) を明記。
   未追跡の穴は **Write で作った新規ファイルも同じ = matcher の選択と直交する**ので、
   `Bash` を足しても塞がらないことも書いた (正典の判断を補強する材料になる)
2. 撤回規則を 3 条へ整理し、既知の 2 系統を**受容する判断理由**を書いた
3. 合成本文ヘルパを `substr_count(...) === 1` へ (stdin の変異元は先頭改行で一意化)
4. **S13e を新設**して候補計数の 3 形 (接頭辞・打ち消し・接尾辞) を裏取り。
   併せて候補計数の docblock に保証しないもの (絶対パス・別名・変数経由・`env timeout` は候補外) を明記
5. S13 / S13c のコメントの誇張を修正 (「共通関数の中の比較を消したら」)

## 対応マトリクス

# 対応マトリクス: design-review Round 3

## S6 [Critical] 未追跡の新規ファイルが i15 の回収説明から漏れている
- 判断: 対応する (指摘は正しい。実測でも確認した)
- 根拠: `git diff --name-only HEAD~1 --` は**未追跡ファイルを列挙しない**。一時 git リポジトリでの
  実測: 新規ファイルは `git add` するまで一覧に出ない (add 後は出る)。
  したがって「シェルで変えたファイルも次の Write / Edit で回収される」は無条件では成立しない。
- 対応内容:
  1. 実読記録に「未追跡ファイル」の行と実測結果を追加し、確認項目を 5 点にした
  2. 回収の条件を「**追跡下のパス**であり、作業ツリーの内容が `HEAD~1` と違うこと」に限定した
  3. 回収されない経路を **2 系統**として明記した — (1) 未追跡の新規ファイル
     (**`Write` で作ったものも `git add` まで同じ = matcher に `Bash` を足しても塞がらない**。
     穴は matcher の選択と直交する) / (2) 差分基準から外れた過去のコミットの変更
  4. docblock と AGENTS.md から無条件の「回収される」を削除し、条件つきの書き方へ改めた
  5. **既知の 2 系統は受容する**とし、その判断理由を書いた (どちらも配線層では塞げない
     = `--base` を変える経路も `git add` を起こす経路も配線に無い。塞ぐなら索引更新スクリプト側 =
     隣接 feature の領分であり、実害の観測なしに越境しない)
  6. 撤回規則を 3 条へ整理した — (a) **2 系統以外**で回収されない実測、(b) 版を上げて差分基準や
     未追跡ファイルの扱いが変わった、(c) 2 系統が**実害**として観測された
  7. 家系への申し送りを追加した (正典 i15 の根拠の言い方「直前の索引時点からの差分」は不正確。
     結論は変わらないので**記述の是正だけ**を提案する)

## S6 [Warning] 「それでも索引が置いていかれない」の断定と非回収ケースが矛盾する
- 判断: 対応する
- 根拠: 例外を後置きで処理する書き方は保証の誇張になる。
- 対応内容: 冒頭から条件つきの記述 (「条件を満たす変更は次の編集時に回収される」+ 条件の明示) に変えた。

## S2 [Warning] 「1 か所だけ変異」をヘルパが保証していない
- 判断: 対応する
- 根拠: `Assert::contains()` は 1 件以上の存在しか見ず、`str_replace()` は全出現を置換する。
  基準本文は囮のコメント行に stdin の正準文字列を含むので、stdin の変異は 2 か所を書き換えていた。
- 対応内容: `Assert::same(substr_count($body, $mutate), 1)` に変え、stdin の変異元は
  **先頭に改行を付けて実行行だけに一意化**した (囮のコメント行は `# ` が前に付くので当たらない)。

## S2 [Warning] 候補語彙の走査に共通規約 (e) の 3 形の裏取りが無い
- 判断: 対応する
- 根拠: S13b は「併存を検出できる」ことしか示しておらず、誤検出しない側が固定されていない。
- 対応内容: **S13e を新設**し、候補計数だけを直接検査する — 正例 (`read`+`-t` / `INNER_TIMEOUT_SECONDS=` /
  `timeout`+`-k`)、宣言した区切り (タブでも割れる)、コメント行と空行を数えないこと、
  負例 8 形 (`xtimeout` / `!timeout` / `timeoutx` / `xread` / `!read` / `readx` /
  `XINNER_TIMEOUT_SECONDS=` / `INNER_TIMEOUT_SECONDSX=`)。

## S2 [Warning] 「非正準の実行行を検出する」の主張が候補語彙の定義より広い
- 判断: 対応する (指摘の 2 番目の案 = 保証を狭める側を採る)
- 根拠: `/usr/bin/timeout` のような絶対パス形式や変数経由の起動は候補語彙にトークン完全一致しない。
  語彙を増やして追いかけると書き方の全数を列挙する羽目になる (思考原則 2 に反する)。
- 対応内容: 候補計数の docblock に**保証しないもの**を明記した — 検出できるのは
  「宣言した語彙にトークン完全一致する非正準行の併存」だけで、絶対パス・別名・変数経由・`env timeout`
  は候補にならないので併存を検出しない。スクリプト本文は隣接 feature の領分であることも書いた。

## S2 [Warning] S13 / S13c のコメントが検出力を誇張している
- 判断: 対応する
- 根拠: S13 から呼び出しごと削除された場合は S13c では分からない。検出できるのは
  「共通関数の中の比較を消した / 向きを逆にした」場合である。
- 対応内容: 両方のコメントを「**共通関数の中の**比較を消したり向きを逆にしたら」へ直し、
  呼び出しの削除は S13 の本文を読むレビューの担当であることも書いた。

## S7 [Warning] D50 が参照する i15 の非保証内容を最終文面と整合させること
- 判断: 対応する
- 根拠: 指摘のとおり (S6 の文面変更に追従が必要)。
- 対応内容: D50 の記述は「i14 / i15 を冒頭に書く」という**所在の指定**に留めており、
  非保証の内容そのものは検査の docblock 1 か所を正本にしている (2 か所に書くと必ず食い違う)。
  今回の文面変更は docblock 側で完結するため D50 の追従は不要であることを確認した。

## 更新した実読記録 (全文)

# i15 の前提の実読記録 — 索引更新 (`code-review-graph update`) が何を差分と見るか

**確認項目**: 差分の基準 / 未索引の変更の検出方法 / 除外規則 / 未追跡ファイルの扱い / 状態更新のタイミング

**目的**: 正典 t3 の i15 は「シェル経由の編集が**次の `Write` / `Edit` の起動で回収される**根拠
(索引更新が差分方式であること) を配線台帳の傍らに書く」ことを要求する。その根拠を
「仕様だから」で済ませず、**どこを読んで何を確かめたか**を残す。

- 読んだ版: `code-review-graph==2.3.7` (`docker/Dockerfile` が pin。実体は
  `~/.local/share/uv/tools/code-review-graph/lib/python3.13/site-packages/code_review_graph/`)
- 読んだ日: 2026-08-24
- 読んだ箇所: `cli.py` L670 (`update --base` の既定値) /
  `incremental.py` `get_changed_files()` (L559-) / `incremental.py` `incremental_update()` (L1003-)
- コマンドの自己申告: `code-review-graph update --help` の
  `--base BASE  Git diff base (default: HEAD~1)`

## 確かめた 5 点

| 確認項目 | 実読の結果 |
|---|---|
| **差分の基準** | `update` の既定は `--base HEAD~1`。実行するのは `git diff --name-only -z HEAD~1 --` である (`get_changed_files()`)。**「直前に索引した時点」ではなく「1 つ前のコミット」が基準**であり、比較先は**作業ツリー** (コミット前の変更を含む) である |
| **未索引の変更の検出方法** | 上の一覧に、そこから `find_dependents()` で辿った依存ファイルを足した集合を再解析する。さらに**ファイル内容の sha256 が索引済みの値と同じならスキップ**する (`incremental_update()` の quick hash check) ので、同じ内容の再実行は無害である |
| **除外規則** | `_load_ignore_patterns()` の無視パターンと、`parser.detect_language()` が言語を判定できない拡張子は解析しない。存在しなくなったファイルは索引から削除する |
| **未追跡ファイル** | `git diff` は**未追跡ファイルを列挙しない**。実測 (一時 git リポジトリで確認): 新規ファイルを作っただけでは `git diff --name-only HEAD~1 --` に出ず、`git add` した後には出る。したがって**索引に入る条件は「index に入っている (= 追跡下の) パスであること」**である |
| **状態更新のタイミング** | 解析の後に `git_head_sha` 等のメタデータを書く。**索引の側に「最後に索引した時刻」を持って次の差分基準にする作りではない** |

## 帰結 (i15 の主張をどこまで狭めるか)

**回収される (条件つき)**: **追跡下のパス**の変更が「作業ツリーで `HEAD~1` と内容が違う」状態に
ある限り、次の `Write` / `Edit` が起こす `update` の対象に入る (コミット前でも、直前のコミットに
入っていても入る)。シェルで既存ファイルを書き換える日常の編集はこの範囲に収まる。

**回収されない (受容する穴。2 系統)**

1. **未追跡の新規ファイル**。`git diff` は未追跡ファイルを列挙しないので、作っただけの
   新規ファイルは索引に入らない。**これは作った道具に依らない** — `Write` で作った新規ファイルも
   `git add` されるまで同じである。したがって**照合条件に `Bash` を足しても塞がらない**
   (この穴は matcher の選択と直交する)。
2. **差分基準から外れた過去のコミットの変更**。シェルで変更したファイルを**コミットし、そのあと
   `Write` / `Edit` を 1 度も起こさずにさらにコミットを重ねた**場合、そのファイルは `HEAD~1` からの
   差分に現れなくなる (`build` か、そのファイルを再び触る編集が要る)。

`--base` を変える経路も `git add` を起こす経路も配線には無いので、**どちらの穴も配線層では塞げない**
(塞ぐなら索引更新スクリプト側 = 隣接 feature の領分である)。

したがって配線台帳に書く根拠は
「**索引更新は 1 つ前のコミットから作業ツリーまでの差分を見るので、シェルで変えた
「追跡下の」ファイルは次の `Write` / `Edit` の起動で回収される。未追跡の新規ファイルと、
差分基準から外れた過去のコミットの変更は回収されない**」とし、
**無条件の「回収される」とは書かない**。

## 家系への申し送り (正典 i15 の根拠の言い換え)

正典 i15 と spirux の台帳は根拠を「**直前の索引時点からの差分**を見るので回収される」と書いているが、
実読の結果、実装は「**1 つ前のコミットから作業ツリーまでの差分**」であり、
**未追跡の新規ファイルは対象外**である。結論 (「照合条件に `Bash` を足さない」) は変わらない
— むしろ穴の 1 つ目は matcher と直交するので、`Bash` を足しても解消しない = 正典の判断を補強する。
ただし**根拠の言い方は不正確**なので、lctl への報告に含めて家系側の記述の是正を促す。

## 撤回規則の発火条件 (この記録を根拠にする)

1. 索引ツールの版を上げたときに `update` の既定の差分基準が変わった
   (`--base` の既定値 / `git diff` の対象が「作業ツリー」でなくなった / 未追跡ファイルの扱いが変わった)
2. 上の「回収されない」2 系統が**実害として観測された** (索引が古いまま作業が進み、
   コード探索が誤った結果を返した実測)。**既知の 2 系統そのものは受容する** — どちらも配線層では
   塞げず、実害の観測なしに索引更新スクリプトの設計 (差分基準 / `build` への切り替え) を
   動かすのは隣接 feature への越境であり、思考原則 2 (今必要なものだけ作る) にも反する
3. 上記の「未追跡の新規ファイル」の穴について、隣接 feature (索引更新スクリプト) 側で
   対処が入った (そのときは本記録と配線台帳の記述を同じ変更で更新する)

どちらの場合も、**照合条件へ `Bash` を足す形は採らない** (正典 i2 / i15 が費用構造で外している)。
家系の未決論点 — セッション開始時に索引状態を出す任意の配線 (q1) / 配線の非同期実行 (q2) —
へ差し戻す。

## 修正後の詳細設計 — 変更のあった節 (S1 冒頭の施策一覧 / S2 全文 / S6 全文 / 申し送り)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 配線台帳検査を正典 t3 の向きへ反転する (i5/i7 の静的層 + pass-through の実起動層) | `tests/Architecture/ClaudeHooksWiringTest.php` | 最高 |
| S2 | 内側の上限と配線の時間切れの数値比較を新設する (i8) | `tests/Architecture/ClaudeHooksWiringTest.php` / `scripts/code-review-graph-update-hook.sh` | 高 |
| S3 | ローカル層のトップレベルを全数申告制にする (i10) | `tests/Architecture/ClaudeHooksWiringTest.php` | 高 |
| S4 | 起動子を直呼び 1 行へ戻す (i5/i6/i7) | `.claude/settings.json` | 最高 |
| S5 | bug-hunt ガードの拒否コードを 97 → 2 にする (i7 の従属変更) | `scripts/bughunt-worktree-hook.sh` / `scripts/README.md` | 最高 |
| S6 | 塞がない脅威 3 点と、覆わない編集経路の回収根拠・撤回規則を書く (i14/i15) | `tests/Architecture/ClaudeHooksWiringTest.php` / `AGENTS.md` (根拠は `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`) | 高 |
| S7 | 乖離台帳の移送 (D18 縮小 + D50 新設 + 採用時債務の 1 行削除 + 件数 pin) | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` / `tests/Support/TemplateDivergence/adoption-debt.tsv` | 高 |

**実装順序 (テストファースト)**: S1 → S2 → S3 → (ここまでで赤を確認) → S4 → S5 → S6 → S7。
S1〜S3 の検査を先に書くと**現行の設定・スクリプトで必ず落ちる** (下記「テスト計画」の赤の条件)。

### 波及変更 (全施策共通の棚卸し)

- TypeScript 型定義: **なし** (frontend を 1 行も触らない)
- Inertia Props / API Resource / DTO: **なし**
- route / migration: **なし**
- テストファイル: `tests/Architecture/ClaudeHooksWiringTest.php` のみ。
  他に本件の 4 ファイルを参照する検査は無いことを確認済み
  (`grep -rl "DENY_EXIT_CODE\|bughunt-worktree-hook"` の結果は追跡下では
  `.claude/settings.json` / `AGENTS.md` / `docs/template-divergence.md` /
  `docs/template-fingerprints.json` / `docs/worktree-isolation-strategy.md` /
  `scripts/README.md` / `scripts/bughunt-worktree-hook.sh` / 本検査の 8 件。
  `docs/worktree-isolation-strategy.md` は拒否コードに触れていないので変更不要)
- 文書: `AGENTS.md` のマーカー区間と `scripts/README.md` の 1 行 (下記 S5/S6)
- 乖離台帳: S7 (必須。`docs/template-fingerprints.json` の母集合に 5 パスが在る)

---

## S1: 配線台帳検査を正典 t3 の向きへ反転する
## S2: 内側の上限と配線の時間切れの数値比較 (i8)

### 変更箇所

- `tests/Architecture/ClaudeHooksWiringTest.php` (新設: 内側上限の申告 const / 抽出の純関数 / 候補行の計数 / 関係の純関数 / 合成本文のヘルパ / S13・S13b・S13c・S13d・S13e)
- `tests/Architecture/ClaudeHooksWiringTest.php` L921-948 (B17 の `30.0` 直書き) / L950-966 (B18 の `'20 秒'` 直書き)
- `scripts/code-review-graph-update-hook.sh` L9 (実行契約の記述) / L138 (`timeout -k 5` → `-k 2`)

### 現行コード

```bash
# scripts/code-review-graph-update-hook.sh
#  5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
...
timeout -k 5 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
```

```php
// B17 / B18 (抜粋)
expect($elapsed)->toBeLessThan(30.0, '呼び出し側 timeout (30 秒) を超えた');
expect($result['errorOutput'])->toContain('20 秒');
expect($result['elapsed'])->toBeLessThan(45.0, '内側の時間切れ (20 秒) が効いていない');
```

### 変更後コード

```bash
#  5. **明示している 3 つの上限の和**が呼び出し側の時間切れより小さい:
#     標準入力待ち 5 秒 + 更新本体 20 秒 + KILL までの猶予 2 秒 = 27 秒 < 30 秒。
#     台帳テストがこの 3 値と `.claude/settings.json` の timeout を数値で取り出して比較する。
#     **和は「明示した待ちの合計」であって全体の最悪時間ではない** (前処理とプロセス起動の
#     時間は含まない。含める設計 = 前処理ごと内側 timeout で囲む形は採っていない)
...
timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
```

```php
/**
 * 内側の上限の申告 (i8)。値そのものはスクリプト本文から取り出すので**ここには書かない** —
 * 書くのは「どの数値を持つ契約か」だけである (数値を 2 か所に書くと必ず食い違う)。
 *
 * `body` / `kill` が false なのは、そのスクリプトが外部プロセスを 1 つも起こさないため
 * (bug-hunt ガードの判定は bash の組み込みだけで完結する)。
 *
 * @var array<string, array{stdin: bool, body: bool, kill: bool}>
 */
const CLAUDE_HOOKS_INNER_LIMIT_SHAPE = [
    'scripts/bughunt-worktree-hook.sh' => ['stdin' => true, 'body' => false, 'kill' => false],
    'scripts/code-review-graph-update-hook.sh' => ['stdin' => true, 'body' => true, 'kill' => true],
];

/**
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
 *  - 申告に無い形が 1 件でも現れたら違反にする
 *  - 抽出できた値は**正の整数**であること (`0` は `timeout` の意味論を壊すので拒否する)
 *  - 台帳に無いスクリプトを渡されたら違反として返す (未知を黙って空で通さない)
 *
 * **保証しないもの**: 見るのは**行の形と数値だけ**であり、shell の制御フロー (その行が
 * 実際に実行されるか・別の待ちが挟まっているか) は見ない。したがって
 * 「実行時の上限を証明する」とは書けない — 主張できるのは
 * 「**明示された 3 つの上限の宣言**が配線の時間切れより小さい」までである。
 *
 * @return array{limits: array{stdin: ?int, body: ?int, kill: ?int}, violations: list<string>}
 */
function claudeHooksInnerLimits(string $body, string $script): array
{
    if (! array_key_exists($script, CLAUDE_HOOKS_INNER_LIMIT_SHAPE)) {
        return [
            'limits' => ['stdin' => null, 'body' => null, 'kill' => null],
            'violations' => ["{$script}: 内側の上限の申告が無い (台帳と申告を同じ変更で更新すること)"],
        ];
    }

    /** @var array{stdin: bool, body: bool, kill: bool} $shape */
    $shape = CLAUDE_HOOKS_INNER_LIMIT_SHAPE[$script];

    // 行全体の正準形。`^` と `$` を複数行モードで固定するので `# read …` は当たらない。
    // `kill` は**次の行の更新本体まで**含めて当てる (猶予が更新の起動に接続していることを見る)。
    $patterns = [
        'stdin' => '/^IFS= read -r -N \d+ -t (\d+) input \|\| true$/m',
        'body' => '/^readonly INNER_TIMEOUT_SECONDS=(\d+)$/m',
        // PHP の単一引用符では `\\\\` が正規表現の `\\` (= リテラルのバックスラッシュ 1 文字) になる
        'kill' => '/^timeout -k (\d+) "\$\{INNER_TIMEOUT_SECONDS\}" \\\\\n +code-review-graph update /m',
    ];

    $limits = ['stdin' => null, 'body' => null, 'kill' => null];
    $violations = [];
    $candidates = claudeHooksInnerLimitCandidateCounts($body);

    foreach ($patterns as $key => $pattern) {
        $count = preg_match_all($pattern, $body, $matches);
        Assert::integer($count, "{$script}: 内側の上限 [{$key}] の走査が失敗した");

        // **候補母集団と正準形の一致数が同じであること**。これが無いと、正準形の行 1 本と
        // 非正準の実行行 (別の変数で上限を渡す行など) が**併存**していても検出できない。
        if ($candidates[$key] !== $count) {
            $violations[] = "{$script}: 内側の上限 [{$key}] に正準形でない実行行がある"
                ." (候補 {$candidates[$key]} 件 / 正準形 {$count} 件)";

            continue;
        }

        if (! $shape[$key]) {
            if ($count > 0) {
                $violations[] = "{$script}: 申告に無い内側の上限 [{$key}] が {$count} 件現れた"
                    .' (申告を同じ変更で更新すること)';
            }

            continue;
        }
        if ($count !== 1) {
            $violations[] = "{$script}: 内側の上限 [{$key}] の宣言が 1 件でない (実測 {$count} 件)"
                .' — 数値として取り出せない形・重複・囮の行はすべて違反である';

            continue;
        }

        $value = (int) $matches[1][0];
        if ($value <= 0) {
            $violations[] = "{$script}: 内側の上限 [{$key}] が正の整数でない (実測 {$value})";

            continue;
        }

        $limits[$key] = $value;
    }

    return ['limits' => $limits, 'violations' => $violations];
}

/**
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
 * 別名・変数経由 (`"${TIMEOUT_BIN}"`)・`env timeout` — は**候補にならないので併存を検出しない**。
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

/**
 * 合成した hook 本文 (S13b / S13d 用)。**基準は実ファイルと同じ正準形**で、
 * 各データセットは `str_replace()` で**1 か所だけ**変異させる
 * (複数箇所が同時に壊れていると、狙った分岐を消しても別の理由で赤いままになる)。
 *
 * nowdoc (`<<<'BASH'`) を使うのでバックスラッシュはそのまま 1 文字として入る
 * (二重引用符のエスケープの曖昧さを持ち込まない)。基準本文には**囮のコメント行**を
 * 1 本入れてあり、コメントが候補にならないことが同時に固定される。
 */
function claudeHooksSyntheticUpdateHookBody(string $mutate = '', string $replacement = ''): string
{
    $body = <<<'BASH'
        #!/usr/bin/env bash
        # 囮: IFS= read -r -N 1048576 -t 5 input || true
        IFS= read -r -N 1048576 -t 5 input || true
        readonly INNER_TIMEOUT_SECONDS=20
        timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
            code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
        BASH;

    if ($mutate === '') {
        return $body;
    }

    // **変異元は本文にちょうど 1 か所**であること。`str_replace()` は全出現を置換するので、
    // 存在検査だけだと「1 か所だけ変異させる」が壊れる (基準本文には囮のコメント行があり、
    // 実行行と同じ文字列を含む。stdin の変異元は先頭に改行を付けて一意にする)。
    Assert::same(
        substr_count($body, $mutate),
        1,
        "合成本文の変異元が 1 か所でない: {$mutate}",
    );

    return str_replace($mutate, $replacement, $body);
}

/**
 * 内側の上限と配線の時間切れの**関係**を判定する (純関数)。
 *
 * S13 (実ファイル) と S13c (変異させた入力) の**両方がこの関数を呼ぶ**。
 * 比較を検査の中に直接書くと、比較を消しても変異テストが緑のままになる。
 *
 * 判定するのは「**明示された 3 上限の宣言の和** < 配線の時間切れ」であり、
 * 前処理・プロセス起動の時間は含まない (含められないので主張もしない)。
 *
 * @param  array{stdin: ?int, body: ?int, kill: ?int}  $limits
 * @return list<string>
 */
function claudeHooksInnerLimitRelationViolations(array $limits, int $harness, string $label): array
{
    $declared = array_filter($limits, static fn (?int $value): bool => $value !== null);
    if ($declared === []) {
        return ["{$label}: 内側の上限が 1 つも取れていない (関係を判定できない)"];
    }

    $sum = array_sum($declared);
    if ($sum >= $harness) {
        return [sprintf(
            '%s: 明示された内側の上限の和 %d 秒が配線の時間切れ %d 秒より内側でない',
            $label,
            $sum,
            $harness,
        )];
    }

    return [];
}
```

```php
test('S13: 明示された内側の上限の和が配線の時間切れより小さいこと (数値を両方から取って比較する)', function (): void {
    // 申告の母集団が台帳とちょうど一致すること (申告の余剰・不足を黙って通さない)
    $ledgerScripts = [];
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $ledgerScripts[] = $entry['script'];
        }
    }
    sort($ledgerScripts);
    $declaredScripts = array_keys(CLAUDE_HOOKS_INNER_LIMIT_SHAPE);
    sort($declaredScripts);
    expect($declaredScripts)->toBe($ledgerScripts, '内側の上限の申告が台帳のスクリプト集合と一致しない');

    $checked = 0;

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            $extracted = claudeHooksInnerLimits(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
            expect($extracted['violations'])->toBe([], implode("\n", $extracted['violations']));

            // 設定ファイル側の timeout も**設定から**取る (台帳の写しではなく実値を見る)
            $harness = claudeHooksHookTimeout($event);
            expect($harness)->toBe($entry['timeout'], "{$event}: 設定の timeout が台帳と違う");

            // 関係の判定は純関数へ (S13c が同じ関数を呼ぶ = **共通関数の中の**比較を
            // 消したり向きを逆にしたら負例が赤くなる)
            $violations = claudeHooksInnerLimitRelationViolations($extracted['limits'], $harness, $event);
            expect($violations)->toBe([], implode("\n", $violations));
            $checked++;
        }
    }

    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
});

test('S13b (負のコントロール): 内側の上限の走査が違反を実際に検出すること', function (string $body, string $script): void {
    // **基準の合成本文から 1 か所だけ変異させる** (複数箇所が同時に壊れていると、
    // 狙った分岐を消しても別の理由で赤いままになり、分岐の裏取りにならない)。
    $extracted = claudeHooksInnerLimits($body, $script);
    expect($extracted['violations'])->not->toBe([]);
})->with([
    '必要な正準形が 0 件 (変数展開)' => [
        claudeHooksSyntheticUpdateHookBody(
            'readonly INNER_TIMEOUT_SECONDS=20',
            'readonly INNER_TIMEOUT_SECONDS=$FOO',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '必要な正準形が 2 件 (重複宣言)' => [
        claudeHooksSyntheticUpdateHookBody(
            'readonly INNER_TIMEOUT_SECONDS=20',
            "readonly INNER_TIMEOUT_SECONDS=20\nreadonly INNER_TIMEOUT_SECONDS=99",
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '正準形と非正準の実行行が併存する' => [
        claudeHooksSyntheticUpdateHookBody(
            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
            "code-review-graph update -q --repo \"\${repo_root}\" > /dev/null 2>&1\n"
                .'timeout -k "${OTHER}" 99 code-review-graph update -q',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '標準入力待ちが数値でない' => [
        claudeHooksSyntheticUpdateHookBody(
            // 先頭の改行で**実行行だけ**に一意化する (囮のコメント行は `# ` が前に付くので当たらない)
            "\nIFS= read -r -N 1048576 -t 5 input || true",
            "\nIFS= read -r -N 1048576 -t \"\${UNBOUNDED}\" input || true",
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '値が 0 (timeout の意味論が壊れる)' => [
        claudeHooksSyntheticUpdateHookBody('timeout -k 2 ', 'timeout -k 0 '),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '猶予が更新本体へ接続していない' => [
        claudeHooksSyntheticUpdateHookBody(
            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
            '    true',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '申告に無い上限が現れた (検問側に本体の宣言がある)' => [
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/bughunt-worktree-hook.sh',
    ],
    '台帳に無いスクリプト' => [
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/unknown-hook.sh',
    ],
]);

test('S13d (正のコントロール): 実ファイルと合成の基準本文から 3 値がちょうど取れること', function (): void {
    // 実ファイル
    $real = claudeHooksInnerLimits(
        claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
        'scripts/code-review-graph-update-hook.sh',
    );
    expect($real['violations'])->toBe([]);
    expect($real['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);

    // 合成の基準本文 (変異していない = 違反ゼロ)。囮のコメント行があっても件数は増えない
    $synthetic = claudeHooksInnerLimits(
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/code-review-graph-update-hook.sh',
    );
    expect($synthetic['violations'])->toBe([]);
    expect($synthetic['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);

    // 検問側 (本体と猶予を持たない申告)
    $guard = claudeHooksInnerLimits(
        claudeHooksReadFile(base_path('scripts/bughunt-worktree-hook.sh')),
        'scripts/bughunt-worktree-hook.sh',
    );
    expect($guard['violations'])->toBe([]);
    expect($guard['limits'])->toBe(['stdin' => 5, 'body' => null, 'kill' => null]);
});

test('S13c (負のコントロール): 関係の判定が崩れた数値を落とすこと', function (?int $stdin, ?int $body, ?int $kill, int $harness, bool $shouldFail): void {
    // **S13 と同じ関数**を呼ぶので、**共通関数の中の**比較を消したり向きを逆にしたらここが赤くなる
    // (S13 から呼び出しごと削除された場合はここでは分からない — それは S13 の本文を読むレビューの担当)。
    // dataset を `?int` の 3 引数に分けるのは、closure の `array` に要素型を書けないためである
    // (PHPStan level 10 は iterable value type の欠落を落とす)。
    $violations = claudeHooksInnerLimitRelationViolations(
        ['stdin' => $stdin, 'body' => $body, 'kill' => $kill],
        $harness,
        'テスト入力',
    );

    expect($violations === [])->toBe(! $shouldFail);
})->with([
    '索引更新の現行値 (27 < 30)' => [5, 20, 2, 30, false],
    '等しい (30 は内側でない)' => [5, 20, 5, 30, true],
    '超える (32 > 30)' => [5, 25, 2, 30, true],
    '検問の現行値 (5 < 10)' => [5, null, null, 10, false],
    '1 つも取れていない' => [null, null, null, 30, true],
]);
```

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

    // コメント行と空行は実行行ではない
    expect(claudeHooksInnerLimitCandidateCounts("# timeout -k 2\n\n   # readonly INNER_TIMEOUT_SECONDS=20"))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0]);

    // 負例: 接頭辞つき・打ち消しつき・接尾辞つきは候補にしない
    foreach ([
        'xtimeout -k 2', '!timeout -k 2', 'timeoutx -k 2',
        'xread -r -t 5', '!read -r -t 5', 'readx -r -t 5',
        'XINNER_TIMEOUT_SECONDS=20', 'INNER_TIMEOUT_SECONDSX=20',
    ] as $lookalike) {
        expect(claudeHooksInnerLimitCandidateCounts($lookalike))
            ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0], "トークン完全一致でない判定になっている: {$lookalike}");
    }
});
```

設定から timeout を取るヘルパ (既存の `claudeHooksLauncherCommand()` と同じ形):

```php
/** 設定ファイルから hook の時間切れを取り出す。 */
function claudeHooksHookTimeout(string $event): int
{
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    Assert::keyExists($settings['hooks'], $event);
    $group = $settings['hooks'][$event];
    Assert::isArray($group);
    Assert::isArray($group[0]);
    Assert::isArray($group[0]['hooks']);
    Assert::isArray($group[0]['hooks'][0]);
    $timeout = $group[0]['hooks'][0]['timeout'];
    Assert::integer($timeout);

    return $timeout;
}
```

B17 / B18 の直書きの除去:

```php
// B17
expect($elapsed)->toBeLessThan(
    (float) claudeHooksHookTimeout('PostToolUse'),
    '呼び出し側 timeout を超えた',
);

// B18
$inner = claudeHooksInnerLimits(
    claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
    'scripts/code-review-graph-update-hook.sh',
)['limits']['body'];
Assert::integer($inner);
expect($result['errorOutput'])->toContain("{$inner} 秒");
// 実測の上限は**設定由来の値** (配線の時間切れ) を使う。根拠の無い余裕の数値を持ち込まない
// (この stub は 120 秒眠るので、内側の時間切れが効いていなければ必ず超える)。
// 数値の関係そのものは静的層 (S13) が見るので、ここは「内側が実際に発火する」ことだけを見る。
expect($result['elapsed'])->toBeLessThan(
    (float) claudeHooksHookTimeout('PostToolUse'),
    '内側の時間切れが効いていない (配線の時間切れまで走ってしまっている)',
);
```

### PHPStan適合チェック

- [x] `claudeHooksInnerLimits()` の戻り値は shape 付き配列 (`?int` を明示)
- [x] `null` 安全: 合算は `array_filter()` で `null` を落としてから行い、**0 を混ぜない**
      (申告で true の値は抽出できなければ違反として先に返るので、残る `null` は
      「その配線が持たない上限」だけである)
- [x] `Assert::integer()` で `mixed` を narrow してから比較へ渡す
- [x] Generics: `CLAUDE_HOOKS_INNER_LIMIT_SHAPE` に `@var array<string, array{stdin: bool, body: bool, kill: bool}>`

### テスト計画

- [x] **先に赤くする**: S13 を入れると現行の `timeout -k 5` で 5+20+5=30 ≥ 30 となり落ちる
      (**この赤が `-k 2` へ変える唯一の理由**である)
- [x] 新規テスト: S13 (実ファイル + 申告の母集団一致) / S13b (抽出の負例 8 形。**基準の合成本文から
      1 か所だけ変異**させる) / S13c (関係の負例。**S13 と同じ純関数**を呼ぶ) /
      S13d (実ファイル + 合成の基準本文 + 検問側の正のコントロール) /
      **S13e (候補計数の 3 形の裏取り + 区切りの宣言 + コメント行の除外)**
- [x] 既存テスト更新: B17 / B18 の直書きを設定・スクリプト由来へ
- [x] `bash -n` (S09) と B18 の実挙動で `-k 2` が壊れていないことを確認

### リスク

- `-k 2` は「TERM を無視する相手を KILL するまでの猶予」が 3 秒短くなる。索引ツールが
  TERM を無視して 2 秒以内に終わらない場合、KILL される (現行も 5 秒後に KILL される
  = 差は待ち時間だけで、結果は同じ)。**KILL 猶予そのものが効くことの実測 (家系の motivation が持つ)
  は本件では持たない** — 前提は GNU coreutils の仕様であり、i8 が要求するのは数値の関係だけである。
- **保証範囲を誇張しない**: 検査が見るのは行の形と数値だけで、shell の制御フローは見ない。
  したがって「実行時に必ず 27 秒以内で終わる」とは書かない (書けない)。主張は
  「明示された 3 上限の宣言の和 < 配線の時間切れ」までであり、この文言をスクリプトのコメント・
  `AGENTS.md`・検査の docblock の 3 か所で**同じ言い方に揃える**。

---

## S3: ローカル層のトップレベルを全数申告制にする (i10)
## S6: 塞がない脅威と、覆わない編集経路の始末を書く (i14/i15)

### 変更箇所

- `tests/Architecture/ClaudeHooksWiringTest.php` L9-23 (冒頭 docblock) と `CLAUDE_HOOKS_WIRING` の docblock
- `AGENTS.md` L388-419 (`CLAUDE_HOOKS_WIRING:BEGIN` … `:END` の区間)

### 変更後コード (検査ファイルの冒頭 docblock)

```php
/*
 * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
 *
 * 本テストは 2 層で構成する:
 *  - 静的層 (S01〜S13e): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
 *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
 *    ローカル層 (`.claude/settings.local.json`) のトップレベルも全数申告制で、申告に `hooks` は
 *    足せない。内側の上限と配線の時間切れは**数値を両方から取って比較**する。
 *  - 実起動層 (B01〜B46): hook スクリプトと起動子を**別プロセスで本当に起動**して、
 *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
 *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
 *
 * -------------------------------------------------------------------------
 * **配線層が塞がないもの** (家系の正典 t3 の i14。緑であることを実際より強く読ませないために書く):
 *  1. **起動子 `/bin/bash` 自体を差し替えられる攻撃者**。起動子を絶対パスで書くのは検索パス経由の
 *     すり替えを防ぐためで、`/bin/bash` そのものを置き換えられる相手には何も効かない
 *  2. **`$CLAUDE_PROJECT_DIR` を含む環境変数を仕込める攻撃者**。起動先のパスはこの変数から
 *     組まれる。t3 の起動子は値を検証しない (B45 がその挙動を実挙動で見えるようにしている)。
 *     `-p` が塞ぐのは継承したシェル関数と `BASH_ENV` / `ENV` **だけ**である
 *  3. **リポジトリの外に置かれた設定層**。hook の設定は利用者層・管理者層にも置け、管理者は
 *     プロジェクト層の hook をまとめて無効化できる。リポジトリ内の検査からは原理的に見えない
 *
 * **索引更新の配線が覆わない編集経路** (i15):
 *  `matcher` は `Write|Edit` なので、**シェル経由の変更 (Bash ツール) は索引更新を起こさない**。
 *  **条件を満たす変更は次の編集時に回収される**。条件は「**追跡下のパス**であり、作業ツリーの内容が
 *  `HEAD~1` と違うこと」で、これを満たす限りシェルで変えたファイルも次の `Write` / `Edit` が
 *  起こす更新でまとめて索引へ入る。その間だけ索引が古いことは受容する。
 *  **根拠は外部ツールの実装である** — `code-review-graph==2.3.7` (`docker/Dockerfile` が版を固定)
 *  の `update` は既定で `git diff --name-only HEAD~1 --`、つまり**1 つ前のコミットから
 *  作業ツリーまで**の差分を対象にする (実読記録:
 *  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`)。
 *  **回収されない経路が 2 系統ある (受容する)**:
 *   (1) **未追跡の新規ファイル**。`git diff` は未追跡ファイルを列挙しない。これは作った道具に依らず、
 *       `Write` で作った新規ファイルも `git add` されるまで同じである
 *       = **照合条件に `Bash` を足しても塞がらない** (穴は matcher の選択と直交する)
 *   (2) **差分基準から外れた過去のコミットの変更**。コミットしたあと `Write` / `Edit` を挟まずに
 *       さらにコミットを重ねると `HEAD~1` からの差分に現れない
 *  どちらも配線層では塞げない (`--base` を変える経路も `git add` を起こす経路も配線には無い)。
 *  **無条件の「回収される」とは書かない**。
 *  **本テストはこの前提を機械検証しない** (差分の基準・除外規則・索引状態の更新はツール側の実装)。
 *  したがって**索引ツールを更新したら、matcher の意味論と併せてこの差分回収の前提も
 *  人手で再確認する** (確認項目は上記の実読記録の 5 点)。
 *  **撤回規則**: (a) **上の 2 系統以外**で索引へ入らない実測が出た、(b) 索引ツールの版を上げて
 *  差分基準や未追跡ファイルの扱いが変わった、(c) 上の 2 系統が**実害**として観測された
 *  (索引が古いままコード探索が誤った結果を返した) — このいずれかが起きたら、
 *  **`matcher` へ `Bash` を足すのではなく**、家系の未決論点へ差し戻す
 *  (`Bash` の hook 入力には編集対象のパスが無く対象外拡張子での早期打ち切りが原理的に効かないため、
 *  最頻ツールの呼び出しごとに索引更新の実プロセスが起きる = 正典が費用構造で外している)。
 *  差し戻す先は「セッション開始時に索引状態を出す任意の配線」と「配線の非同期実行」の 2 案である。
 * -------------------------------------------------------------------------
 *
 * 本テストは DB を触らない (ファイル読み取りと別プロセス起動のみ)。
 * 関数名を `claudeHooks` 接頭辞で始めるのは、Pest が全テストを 1 プロセスへ読み込むため
 * 素の名前が他の Architecture テストと衝突するからである。
 */
```

### 変更後コード (`AGENTS.md` のマーカー区間)

```markdown
<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
## 常設 hook 配線

`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:

| イベント | 対象 | スクリプト | 役割 |
|---|---|---|---|
| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |

- 対象は **`Write` と `Edit` の 2 つだけ**である。matcher が英数字・下線・`|` だけで
  出来ているときは正規表現にされず、`|` で分割して**完全一致**で比べられるためで、
  `NotebookEdit` のような派生ツールには一致しない。これは **Claude Code 2.1.233 で
  本体を実読して確かめた挙動**であり(記録は
  `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`)、
  **Claude Code を更新したら人手で再確認する**。
  台帳テスト(`ClaudeHooksWiringTest`)が固定するのは**設定に書かれた matcher 文字列だけ**で、
  本体側の判定機序が変わったことは**検出しない**(文字列が同じまま意味だけ変われば緑のままである)。
  `^(…)$` のようなアンカーは足さない(文字集合から外れて正規表現の経路へ移るだけで、
  意味論の変化を防げるわけではない)。
- **起動子はスクリプトを起こすだけ**である
  (`/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/<name>"`)。`-p`(特権モード)は継承した
  シェル関数と `BASH_ENV` / `ENV` を無効化するために付ける(スクリプトの 1 行目より前に効く層で、
  スクリプト内のどの防御でも代替できない)。**終了コードの写像・起動前の条件分岐・
  インラインのシェル片は置かない** — hook の終了コードは harness の唯一の制御信号
  (`PreToolUse` の **2 はブロック**、それ以外の非 0 はブロックしない異常として面に出る)で、
  畳むと配線ミスと実行時の異常を harness も人も区別できなくなる(家系の正典 t3)。
  bug-hunt ガードの拒否も **2** である。
  **帰結として、意図した拒否以外の理由で hook が 2 を返しても Bash 操作はブロックされる**
  (構文エラーで bash が返す 2 はその一例)。これは意図した交換であり、着地前に台帳テストの
  `bash -n` 検査が構文エラーを止める。
- **明示された内側の上限の和が配線の時間切れより小さい**(検問 10 秒 / 索引更新 30 秒)。
  台帳テストは 3 値(標準入力待ち / 更新本体の上限 / KILL までの猶予)を**スクリプト本文と
  設定の両方から数値で取り出して比較**する(文字列一致では数値の関係が崩れたことを検出できない)。
  **和は明示した待ちの合計であって全体の最悪時間ではない**(前処理とプロセス起動の時間は含まない)。
- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。索引更新が**差分方式**であること(= シェル経由の変更が次の `Write` / `Edit`
  で回収される前提)は**索引ツール側の実装**であり、台帳テストは機械検証しない。実読記録は
  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`。
  既定の差分基準は `HEAD~1` から作業ツリーまでで、回収されるのは**追跡下のパス**に限る —
  **未追跡の新規ファイル**(`Write` で作ったものも `git add` まで同じ)と、
  **コミット後に編集を挟まずコミットを重ねた変更**は回収されない。
  **索引ツールを更新したら、matcher の意味論と併せてこの前提も人手で再確認する**。
- `.claude/settings.local.json` は**トップレベル項目を 1 つも持てない**(全数申告が空)。
  常設配線をローカル層から無効化する経路を作らないためで、項目を置きたくなったら
  台帳テストの申告を同じ変更で更新する(`hooks` は申告に足せない)。
- **配線層が塞がない範囲**(起動子自体の差し替え / 環境変数を仕込める攻撃者 /
  リポジトリ外の設定層)と、**索引更新が覆わない編集経路の始末**(シェル経由の変更が次の
  `Write` / `Edit` で回収される根拠と撤回規則)の**正本は
  `tests/Architecture/ClaudeHooksWiringTest.php` の冒頭**にある。本書には写さない
  (2 か所に書くと必ず食い違う)。
- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
  変更で直す。
- 配線を変えたら**新しいセッションを開始するまで反映されない**(設定はセッション開始時に
  1 度だけ読まれる)。
<!-- CLAUDE_HOOKS_WIRING:END -->
```

### 波及変更

- S12a が区間内に要求する語 (`code-review-graph install` / `uninstall` / `.claude/settings.json` /
  2 本のスクリプト名) は**すべて残す**。区間ごと消さない。
- `AGENTS.md` は `docs/template-fingerprints.json` の母集合に**無い** (実測) ので、
  乖離台帳への影響は無い。

### テスト計画

- [x] S12a が緑であること (マーカーと必要な語の実在)
- [x] docblock の記述そのものは機械検査を置かない (概念設計の「スコープ外」に明記)

### リスク

- 文書が実装より強い保証を主張しないことは人手レビューで見る。i14 の 3 点を
  検査ファイル側に置くのは、家系の先行実装 (テンプレート / spirux) と同じ形である。

---

## S7: 乖離台帳の移送 (D18 縮小 + D50 新設 + 採用時債務の削除)
## 実装後の申し送り (lctl への報告に含める)

1. aicue セルの `status` / `version` を `implemented` / `t3` へ (満たした条文と、採らなかった
   任意配線 i4 を明記)。
2. `scripts/bughunt-worktree-hook.sh` の拒否コード変更は**隣接 feature
   (`bug-hunt-exec-infra`) の領分への従属変更**である旨を明記する。
3. 反映はセッションを開き直してから (設定はセッション開始時に 1 度だけ読まれる)。
   次セッションで「bug-hunt provision の main 直叩きが実際にブロックされる」ことを人手で確認する。
4. **正典 i15 の根拠の言い方の是正を提案する**。正典 (と spirux の台帳) は「直前の索引時点からの差分を
   見るので回収される」と書いているが、実装は「1 つ前のコミットから作業ツリーまでの差分」であり
   **未追跡の新規ファイルは対象外**である (実読記録
   `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`)。
   結論 (`Bash` を照合条件に足さない) は変わらない — 未追跡の穴は matcher と直交するので
   `Bash` を足しても解消せず、むしろ正典の判断を補強する。**根拠の記述だけが不正確**なので報告する。
