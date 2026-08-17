# 赤の実測証跡 (aicue:T213 — 環境見本の検査を AG-007 の統合形へ追従)

詳細設計「テストファースト計画」の 2 段構えを実装セッションで実測した記録である。
実行はすべて worktree `.claude/worktrees/tasks/T213` (ブランチ `todo/T213`) の中で、
`composer test -- --filter=EnvExampleInvariant` (グローバルテストロック配下) で行った。

**この証跡が保証する範囲**: ここに書いてあるのは各時点の実測結果である。
受け入れ条件 AC5 が機械で数えるのは「11 個の行 ID が追跡下のファイルに 1 件ずつあること」までで、
**書かれた内容が正しいことは機械では保証されない**。

## 段 1a: 反証の検査を先に書く (実行経路に乗っていることの確認)

`envExampleParseContents()` を**まだ書かず**、反証の表 16 件だけを既存ファイルへ足して実行した。

| ID | 実施内容 | 実測結果 |
|---|---|---|
| R1A | 解析器を未実装のまま反証の表 16 件を追加して実行 | `tests=22 / passed=6 / errors=16`。16 件すべてが `Call to undefined function envExampleParseContents()` で赤。緑の 6 件は当時の t0 の 5 本と `${VAR}` 検査 1 本 |

これは「テストが実行経路に乗っている」ことの確認にすぎない (全件が同じ理由で赤くなるだけで、
個々の反証がバグを捕まえる証明にはならない)。よって段 1b を続けた。

## 段 1b: わざと穴のある解析器で、反証が個別に赤くなることを見る

解析器を 3 通りの「穴あき版」で一時的に実装して実測した (記録後に破棄。**コミットしていない**)。

| ID | 穴あき版 | 設計が予想した赤 | 実測で赤になった行 | 一致 |
|---|---|---|---|---|
| R1B-1 | コメント行を飛ばさない (`^\s*#` の分岐を消す) | R1 / R2 | `tests=22 / failed=2` → R1, R2 (どちらも `malformedLineNumbers` が `[1]` になった) | ○ |
| R1B-2 | 重複を無視して後勝ちで上書きする (`array_key_exists` の分岐を消す) | R4 / R5 / R6 | `tests=22 / failed=3` → R4, R5, R6 | ○ |
| R1B-3 | 形式違反を返さない (`malformedLineNumbers` を常に空にする) | R7〜R12 / R15 | `tests=22 / failed=7` → R7, R8, R9, R10, R11, R12, R15 | ○ |

3 通りとも「対応する行だけ」が赤くなった (他の行は緑のまま)。
その後、正しい解析器を実装して 16 件を緑にし、台帳駆動の 5 本を足した。

## 段 2 (主証跡): 見本・台帳を壊して赤を実測する

`.env.example` (B6 だけは台帳側) を 7 通りに壊し、対応する検査が実際に赤くなることを実測した。
**同じ実行の中に t0 (置換前) の 5 本を一時ファイルとして複製して同居させ**、
「現行 t0 では緑のまま通る」ことを同じ壊し方に対して同時に観測した
(比較用の一時ファイル `tests/Architecture/EnvExampleInvariantT0ProbeTest.php` は
記録後に削除しており、**コミットしていない**)。
各実行の母数は 27 件 = 新しい検査 22 件 + t0 の複製 5 件である。

| ID | 壊し方 | 新しい検査の実測 | t0 の複製 5 本の実測 | 復元確認 |
|---|---|---|---|---|
| B1 | `SESSION_SECURE_COOKIE=true` を `# SESSION_SECURE_COOKIE=true` に変える | `passed=26 / failed=1` → **a (値の固定) だけが赤** | **5 本とも緑 = 偽グリーン** (文字列自体はファイルに残るため `toContain` が当たる) | `git diff --exit-code -- .env.example` が exit 0 |
| B2 | 末尾に `SESSION_ENCRYPT=false` を**足す** | `passed=26 / failed=1` → **c-2 (重複) だけが赤。a は緑のまま** | **5 本とも緑 = 偽グリーン** (元の `SESSION_ENCRYPT=true` の行が残るため) | 同上 exit 0 |
| B2b | 元の `SESSION_ENCRYPT=true` を `SESSION_ENCRYPT=false` に**書き換える** | `passed=25 / failed=2` → **a (値の固定) が赤** | t0 の `SESSION_ENCRYPT` の 1 本が赤 (**検出のしかたが違う**: t0 は「その文字列がどこかにある」、新実装は「解析結果の値が `true` である」) | 同上 exit 0 |
| B3 | `TRUSTED_PROXIES=` の行を消す | `passed=25 / failed=2` → **b (キー網羅) が赤** | t0 の `TRUSTED_PROXIES` の 1 本が赤 | 同上 exit 0 |
| B4 | `AWS_BUCKET=` を `export AWS_BUCKET=` に変える | `passed=25 / failed=2` → **c-1 (行の形式) と b (キー網羅) が赤** | **5 本とも緑** (t0 に対応する検査が無い) | 同上 exit 0 |
| B5 | `MCP_STRICT_TRANSPORT=true` を `MCP_STRICT_TRANSPORT=false` に変える | `passed=26 / failed=1` → **a (値の固定) が赤** | **5 本とも緑** (t0 に対応する検査が無い) | 同上 exit 0 |
| B6 | 台帳側で `TRUSTED_PROXIES` を値の固定とキー網羅の**両方**に登録する (`.env.example` は触らない) | `passed=26 / failed=1` → **台帳の誠実性だけが赤** (実値が空文字なので a は緑のまま) | **5 本とも緑** (t0 に対応する検査が無い) | `.env.example` は無改変 (exit 0)。台帳は元へ戻した |

### 塞いだ穴の実体 (B1 / B2)

- **B1 と B2 は t0 では緑のまま通る**。t0 の `toContain` は「その文字列がファイルのどこかにある」
  しか見ないため、コメント偽装 (B1) でも重複代入 (B2) でも当たってしまう。
  どちらも実効値は失われている / 覆されているのに緑になる = **偽グリーン**であり、
  これが本 TODO で塞いだ穴の実体である。
- **B2 で a (値の固定) が緑のままなのは仕様である**。解析器は重複キーを**先勝ち**で記録するので
  `values['SESSION_ENCRYPT']` は `'true'` のままになる。一方 dotenv は同一ファイル内の重複を
  **後勝ち**で解決するので**実効値は `false`** である。この食い違いこそが
  「重複を 1 件も許さない」理由であり、重複を許すと値の固定は実効値ではない値を見ることになる。
  だから B2 は c-2 (重複) が受け持つ。

## 受け入れ条件 AC4 の判定方法 (設計からの差し替えと、その理由)

詳細設計の AC4 は次の形だった。

```bash
composer test -- --filter=EnvExampleInvariant --log-junit="$JUNIT_FILE" &&
python3 -c "... 件数が 22 なら exit 0 ..." "$JUNIT_FILE"
```

設計はこの `--log-junit` が `composer test` の経路で通るかを**実装セッションで確認する**と定めていた。
**実測の結果、通り方が片側だけだった**:

- junit ファイルは**正しく書かれる** (`<testsuite … tests="22" assertions="51" errors="0" failures="0">`、
  `testcase` 要素は 22 件)。
- ところが **22 件すべて緑でも `composer test` の終了コードが 1 になる**
  (`{"tool":"pest","result":"passed","tests":22,"passed":22}` が出た直後に
  `Script bash scripts/run-test.sh handling the test event returned with error code 1`)。
  `--log-junit` を外すと同じ実行が exit 0 になる。

したがって `&&` で繋ぐ設計どおりの式は、**中身が全件緑でも python の判定へ到達しない**。
そこで判定を **XML の中身だけで閉じる形**へ差し替えた
(ロックの外で `vendor/bin/pest` を直接叩く逃げ道は設計どおり採らない)。

```bash
JUNIT_FILE=$(mktemp "${TMPDIR:-/tmp}/env-example-invariant-junit.XXXXXX.xml")
trap 'rm -f "$JUNIT_FILE"' EXIT

composer test -- --filter=EnvExampleInvariant --log-junit="$JUNIT_FILE" || true
python3 -c "import sys, xml.etree.ElementTree as E; \
p=sys.argv[1]; r=E.parse(p); \
n=sum(1 for _ in r.iter('testcase')); \
bad=sum(1 for _ in r.iter('failure')) + sum(1 for _ in r.iter('error')); \
print(n, bad); sys.exit(0 if n == 22 and bad == 0 else 1)" "$JUNIT_FILE"
```

- **偽グリーンにならない理由**: `mktemp` が実行ごとに**空のファイル**を作るので、走行が
  junit を書かなければ XML の解析が例外で落ちて非 0 になる (前回の 22 件の XML を読む余地が無い)。
  合否は終了コードではなく **XML に記録された `failure` / `error` の件数**で見る。
- **AC3 (対象テストが全件緑 = exit 0)** は `--log-junit` を**付けない**実行で確認する
  (付けない実行の終了コードは正常に 0 / 1 を返す)。

### 復元の確認

全 7 件について、壊した直後に `git diff --stat` で「実際に壊れたこと」を確認し、
実行後に元の内容へ書き戻して `git diff --exit-code -- .env.example` が exit 0 になることを
機械で確認した (`env_restored_exit0: true`)。最終状態でも
`git diff --exit-code main -- .env.example .env.testing .env.bughunt.local.example` は exit 0 である
(見本ファイルは 1 バイトも変えていない = AC1)。
