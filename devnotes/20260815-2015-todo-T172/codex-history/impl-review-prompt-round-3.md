# Round 3: Round 2 の指摘 (保証範囲の書き方) をすべて反映しました

3 件の [Warning] はいずれもご指摘のとおりでした。「台帳テストが matcher 文字列を完全一致で
固定している」ことと「Claude Code 本体の判定機序が変わったら気づける」ことは別物で、
後者は成立しません。文字列が `Write|Edit` のまま意味だけ変われば台帳テストは緑のままです。
自動検出があると読める書き方は保証範囲の誇張でした。3 か所すべて書き直しました。

## 直したもの

1. `AGENTS.md` §常設 hook 配線
   - 実測は **Claude Code 2.1.233** の挙動であると版を明示
   - **Claude Code を更新したら人手で再確認する**と運用を明示
   - 台帳テストが固定するのは**設定に書かれた matcher 文字列だけ**で、
     本体側の判定機序が変わったことは**検出しない**と明記
   - アンカーを足さない理由を「意味論の変化を防げるわけではない」に直した
     (以前は「気づけるようにしてある」と書いていた)
2. `devnotes/…/detailed-design.md`
   - E6 の帰結を同じ内容へ書き直し、「文字列のずれ」と「本体の意味論のずれ」を分けて書いた
   - 施策 3「matcher の対象」節にも同じ限界を書いた
   - [Suggestion] のとおり、訂正表の件数を 6 件に直し、E5 → E6 の番号順に並べ替えた
3. `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`
   - 結論節に「**自動検出は無い**」と明示し、再確認は運用 (Claude Code 更新時の人手) が
     担うことと、その手順が同文書の「何を見たか」であることを書いた

Round 2 と同じく、`.claude/settings.json`・2 本のスクリプト・台帳テストは 1 行も変えていません。

## 差分

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 8f010d1..6ecff45 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -229,10 +229,50 @@ ## コードベース探索
   grep / 全ファイル read より先に code-review-graph の MCP tools を試す
 - ただし機械的な文字列検索(TODO コメント抽出、特定リテラル探索など)は
   そのまま `rg` / `grep` を使う方が速い。code-review-graph はあくまで構造把握用
-- セットアップ: `uv tool install code-review-graph` → `code-review-graph build` で
-  初回ビルド(中規模アプリで ~50 秒)。以降は hook で自動更新されない場合
-  `code-review-graph update` で差分更新(~2 秒)
-- SQLite キャッシュ(`.code-review-graph/`)は `.gitignore` 済みでクローン毎に各自再生成
+- セットアップ: 開発コンテナには `docker/Dockerfile` が版を固定して導入済み
+  (`code-review-graph==2.3.7`)。コンテナを作り直していない環境だけ手で
+  `uv tool install code-review-graph==2.3.7` を 1 度実行する。索引の初回ビルドは
+  `code-review-graph build`(中規模アプリで ~15 秒)
+- 以降の差分更新は **`.claude/settings.json` の PostToolUse hook が自動で回す**
+  (§常設 hook 配線)。実行環境の前提は `flock` と `timeout` の 2 つで、
+  どちらか欠けると更新は走らず**セッションごとに 1 行だけ**告知する
+  (手で回すときは `code-review-graph update`。~0.5 秒)
+- SQLite キャッシュ(`.code-review-graph/`)は `.gitignore` 済みでクローン毎に各自再生成。
+  hook の作業ファイル置き場(`.claude/code-review-graph-update-hook/`)も同様で、
+  中身はロックと告知の目印だけなので消して構わない(消せば次のセッションで再告知される)
+
+<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
+## 常設 hook 配線
+
+`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:
+
+| イベント | 対象 | スクリプト | 役割 |
+|---|---|---|---|
+| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
+| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |
+
+- 対象は **`Write` と `Edit` の 2 つだけ**である。matcher が英数字・下線・`|` だけで
+  出来ているときは正規表現にされず、`|` で分割して**完全一致**で比べられるためで、
+  `NotebookEdit` のような派生ツールには一致しない。これは **Claude Code 2.1.233 で
+  本体を実読して確かめた挙動**であり(記録は
+  `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`)、
+  **Claude Code を更新したら人手で再確認する**。
+  台帳テスト(`ClaudeHooksWiringTest`)が固定するのは**設定に書かれた matcher 文字列だけ**で、
+  本体側の判定機序が変わったことは**検出しない**(文字列が同じまま意味だけ変われば緑のままである)。
+  `^(…)$` のようなアンカーは足さない(文字集合から外れて正規表現の経路へ移るだけで、
+  意味論の変化を防げるわけではない)。
+- 起動子は終了コードの写像器を兼ねる。**PreToolUse をブロックできるのはスクリプトが
+  意図して返す 97 だけ**で、構文エラー・ファイル不在・実行不能はすべて 0 に畳まれる
+  (hook の故障がセッションの Bash 操作を止めない)。
+- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
+  1 行だけ告知する)。
+- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
+  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
+  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
+  変更で直す。
+- 配線を変えたら**新しいセッションを開始するまで反映されない**(設定はセッション開始時に
+  1 度だけ読まれる)。
+<!-- CLAUDE_HOOKS_WIRING:END -->
 
 ## 設計・TODO・devnotes の運用
 
@@ -358,7 +398,7 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
   wrapper `tmp/bug-hunt/shard-{i}-cmd.sh` には**露出しない**。段の定義・合否条件・失敗分類の語彙・
   **保証しないもの**は `docs/architecture.md` §パイプライン通し確認 が正本。
 - **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
-  main 直叩きを早期に止める。配線は `.claude/settings.bughunt-hook.example.json` を `.claude/settings.json` にマージ)。
+  main 直叩きを早期に止める。配線は `.claude/settings.json` に常設済み。§常設 hook 配線)。
 - **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
   `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
 - **capability 語彙**: finding の `capability_tag` の正本は
diff --git a/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md b/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md
index 2a80c2a..5264670 100644
--- a/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md
+++ b/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md
@@ -53,6 +53,20 @@ ## 設計中に実測で確認した挙動 (この設計の前提)
 
 M1〜M9 の再現手順は本設計の各節に書いたコマンドそのものである。
 
+## 実装中に判明した設計の誤り (この節が本設計への訂正である)
+
+実装 (T172) の途中で本設計の記述が誤っていた箇所が 6 件見つかった。いずれも本文側を訂正済みで、
+以下はその一覧である (後から差分を追えるように、何をどう直したかを残す)。
+
+| # | 誤っていた記述 | 実測 | 訂正 |
+|---|---|---|---|
+| E1 | 施策 1 段 6 の `exec 9> "${lock_file}" 2> /dev/null` | コマンドを伴わない `exec` のリダイレクトは**シェル全体へ永続適用**され、段 7・段 8 の告知がすべて `/dev/null` へ消える (実行契約 3 が壊れる) | 波括弧のグループ `{ exec 9> "${lock_file}"; } 2> /dev/null` に直した。fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る |
+| E2 | 施策 6 の逸脱番号 `D15` | `docs/template-divergence.md` は既に D17 まで使用済み | **D18** に直した |
+| E3 | S06 の「`$CLAUDE_PROJECT_DIR` を検証する 5 条件」 | 起動子が持つ検証は 7 条件 (未設定 / 絶対パス / `..` 不在 / `scripts` が実ディレクトリ / `scripts` が symlink でない / 起動先が通常ファイル / 起動先が symlink でない) | 「7 条件」に直した。検査も 7 つ全部を見る |
+| E4 | 共有プロローグの開始マーカーが相手ファイルの名前を書く形 | 2 本でマーカー行そのものが違うと、byte 一致の比較対象を「マーカーの内側だけ」に限る必要があり、検査が 1 段複雑になる | マーカー行を 2 本で同一の中立な文言にした (`# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---`)。マーカーごと byte 比較できる |
+| E5 | 実起動層の検索パスを `$sandbox/bin:/usr/local/bin:/usr/bin:/bin` にする案 | 索引ツールが `/home/vscode/.local/bin` 以外へ導入された環境では「未導入」を再現できず、B02〜B05 が環境依存になる | sandbox 内に 3 種類の bin (`bin` / `bin-notool` / `bin-notimeout`) を作り、必要な外部コマンド (`mkdir` / `flock` / `timeout` / `sleep`) だけを symlink で持たせる。システムディレクトリは検索パスに一切入れない = 完全に決定的になる |
+| E6 | 「公式の説明はツール名の正確な文字列を書く形であり、部分一致で派生ツールも拾うという前提は置けない」という**根拠の無い**理由付け | Claude Code 本体 (2.1.233) の判定関数を実読した。matcher が `[a-zA-Z0-9_|]` だけなら正規表現にせず `|` で分割して**完全一致**で比べる。`Write|Edit` はこの経路に入るので `NotebookEdit` には一致しない | 実測を根拠として書き直した。記録は `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`。`^(Write|Edit)$` へのアンカー追加は**採らない** (文字集合から外れて正規表現の経路へ移るだけで、意味論の変化を防げない)。**台帳テストは設定の文字列しか見ないので、本体側の判定機序の変化は検出しない** — 再確認は Claude Code 更新時の人手の運用で担う |
+
 ## 施策一覧
 
 | # | 施策名 | 変更ファイル | 優先度 |
@@ -133,7 +147,7 @@ #  9. 索引の対象外の拡張子では更新を起動しない (副作用ゼ
 #
 # 索引ツール自身の install / uninstall は実行しないこと (配線の正本が二重化する。AGENTS.md)。
 
-# ---8< SHARED_PATH_PROLOGUE (bughunt-worktree-hook.sh と byte 一致。台帳テストが固定する) >8---
+# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
 # set -e は使わない: 途中の失敗で暗黙に終了すると「常に 0 で終わる」契約を守れない。
 set -uo pipefail
 export LC_ALL=C
@@ -236,7 +250,10 @@ # 帰結として、ロックファイルの差し替え (TOCTOU) までは防
     emit_warning 'no-flock' 'flock が無いため索引を更新しません (排他できない環境では更新しない契約です)'
     exit 0
 fi
-exec 9> "${lock_file}" 2> /dev/null || exit 0
+# ★ `exec 9> file 2>/dev/null` と書いてはいけない: コマンドを伴わない exec の
+#   リダイレクトは**シェル全体へ永続適用**され、以降の告知 (契約 3) が消える。
+#   波括弧のグループなら fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る。
+{ exec 9> "${lock_file}"; } 2> /dev/null || exit 0
 flock -n 9 || exit 0
 
 # --- 段 7: 前提コマンドの在否 ------------------------------------------------
@@ -324,12 +341,18 @@ ### テスト計画 (施策 4 の実起動層で固定する)
 `code-review-graph` は sandbox の `bin/` に置いた stub を PATH で見せる
 (stub は起動された事実と引数を記録するファイルを書く)。
 
-**PATH の作り方**: stub ディレクトリは**システムパスの前に足す**
-(`$sandbox/bin:/usr/local/bin:/usr/bin:/bin`)。`mkdir` / `flock` / `timeout` は本物が要るため、
-stub だけの PATH にすると段 5 で終わってしまい、検証したい経路に到達しない。
-「索引ツール未導入」を作るときは、stub ディレクトリから `code-review-graph` を**置かない**
-(PATH からシステムパスを外すのではない) — この区別をテストのヘルパ名でも明示する
-(`claudeHooksPathWithTool()` / `claudeHooksPathWithoutTool()`)。
+**PATH の作り方**: sandbox の中に bin を 3 つ作り、検索パスには**そのどれか 1 つだけ**を置く
+(システムディレクトリは 1 つも入れない = 実行環境に左右されない)。必要な外部コマンド
+(`mkdir` / `flock` / `timeout` / `sleep`) は絶対パスを解決して symlink で持たせる。
+
+| bin | 中身 | 作る状況 |
+|---|---|---|
+| `bin` | 索引ツールの stub + 4 コマンド | 正常 (`claudeHooksPathWithTool()`) |
+| `bin-notool` | 4 コマンドのみ | 索引ツール未導入 (`claudeHooksPathWithoutTool()`) |
+| `bin-notimeout` | 索引ツールの stub + `timeout` 以外の 3 コマンド | `timeout` 不在 (`claudeHooksPathWithoutTimeout()`) |
+
+「索引ツール未導入」をシステムパスの有無で作らないのが要点である
+(索引ツールの導入先は環境によって変わるため、そこに依存させると検査が環境依存になる)。
 
 ### リスク
 
@@ -380,7 +403,7 @@ ### 現行コード (判定部)
 ### 変更後コード (判定部)
 
 ```bash
-# ---8< SHARED_PATH_PROLOGUE (code-review-graph-update-hook.sh と byte 一致) >8---
+# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
 （施策 1 と完全に同じブロックをここに置く）
 # ---8< /SHARED_PATH_PROLOGUE >8---
 
@@ -551,8 +574,12 @@ ### リスク
 
 ### matcher の対象 (`Write` と `Edit` の 2 つだけ)
 
-`Write|Edit` は **`Write` と `Edit` のときだけ発火する**。公式の説明はツール名の正確な文字列を
-書く形であり、「部分一致で派生ツールも拾う」という前提は置けない。したがって:
+`Write|Edit` は **`Write` と `Edit` のときだけ発火する**。根拠は実測である (E6。
+matcher が英数字・下線・`|` だけで出来ているときは正規表現にされず、`|` で分割して
+**完全一致**で比べられる。Claude Code 2.1.233 で本体を実読した)。
+**この挙動は台帳テストでは守れない** — テストが見るのは設定の文字列だけで、本体側の
+判定機序が変わっても気づけないので、再確認は Claude Code 更新時の人手の運用で担う。
+したがって:
 
 - 台帳のコメントには「**対象はこの 2 ツールだけ**」と書く。将来の派生ツールを自動で拾うとは
   書かない (書くと嘘になる)。
@@ -603,7 +630,7 @@ ### 静的層
 | S03 | トップレベルキーが `CLAUDE_HOOKS_TOP_LEVEL_KEYS` と完全一致 (順不同・過不足なし) |
 | S04 | hooks のイベント集合が台帳と完全一致 |
 | S05 | 各イベントの matcher / command / timeout が台帳と完全一致 (1 文字でも違えば落ちる) |
-| S06 | 起動文字列が `/bin/bash -p -c ` で始まり、`$CLAUDE_PROJECT_DIR` を検証する 5 条件をすべて含み、PreToolUse は `= 97` の写像を、PostToolUse は無条件 `exit 0` を持つ |
+| S06 | 起動文字列が `/bin/bash -p -c ` で始まり、`$CLAUDE_PROJECT_DIR` を検証する 7 条件をすべて含み、PreToolUse は `= 97` の写像を、PostToolUse は無条件 `exit 0` を持つ |
 | S07 | `.claude/settings.local.json` が存在する場合、`hooks` キーを持たない |
 | S08 | `.claude/settings.bughunt-hook.example.json` が存在しない (見本方式の復活禁止) |
 | S09 | 台帳の 2 スクリプトが実在し `bash -n` を通る |
@@ -739,7 +766,7 @@ ### 変更箇所
 | `AGENTS.md` | (a) §bug-hunt の「見本をマージ」記述を「常設済み」へ差し替え (b) §コードベース探索を自動更新前提へ書き換え + 実行環境前提の明示 (c) **新設**「常設 hook 配線」節 — 2 本の一覧と、索引ツール自身に配線を書かせない明文 (マーカー付き) |
 | `README.md` | セットアップ節に索引ツールの前提を 2 行追記 |
 | `scripts/README.md` | `code-review-graph-update-hook.sh` の台帳行を追加。`bughunt-worktree-hook.sh` の行の「見本をマージ」を「常設配線」へ更新 |
-| `docs/template-divergence.md` | **D15** として起動子の逸脱を記録 |
+| `docs/template-divergence.md` | **D18** として起動子の逸脱を記録 (D17 まで使用済みのため) |
 
 ### `AGENTS.md` に置く明文 (マーカー付き)
 
@@ -770,7 +797,7 @@ ## 常設 hook 配線
 
 マーカーは S12 が存在を検査する (明文ごと消せない)。
 
-### `docs/template-divergence.md` D15 の骨子
+### `docs/template-divergence.md` D18 の骨子
 
 - **逸脱**: hook の起動子を追従元の `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` ではなく、
   起動先を検証して終了コードを写像する形にした。
diff --git a/devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md b/devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md
new file mode 100644
index 0000000..4f41986
--- /dev/null
+++ b/devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md
@@ -0,0 +1,64 @@
+# hook の matcher が「ちょうど 2 ツール」を意味することの実測
+
+Codex 実装レビュー Round 1 が `"matcher": "Write|Edit"` について
+「正規表現として評価されるならアンカーが無く `NotebookEdit` / `MultiEdit` にも一致する。
+設計と AGENTS.md の『Write と Edit の 2 つだけ』は実装で固定されていない」と指摘した。
+
+設計はこの点を**根拠を書かずに主張していた**ので、実物で確かめた。
+
+## 何を見たか
+
+対象は本マシンに導入されている Claude Code 本体
+(`anthropic.claude-code-2.1.233-linux-arm64` の `resources/native-binary/claude`)。
+埋め込まれている判定関数を取り出した (可読性のため変数名はそのまま):
+
+```js
+function sNS(e, t, r, n) {                       // e = ツール名, t = matcher
+  if (!t || t === "*") return true;
+  if ((r ? /^[a-zA-Z0-9_|, -]+$/ : /^[a-zA-Z0-9_|]+$/).test(t))
+    return t.split(r ? /[|,]/ : "|").map(s => s.trim()).filter(Boolean)
+            .flatMap(s => K0o(Q9(s), n)).includes(e);
+  try {
+    let i = new RegExp(t);
+    if (i.test(e)) return true;
+    …
+  } catch { … "Invalid regex pattern in hook matcher: " … }
+}
+```
+
+読み取れること:
+
+1. matcher が **英数字・下線・`|` だけ**で出来ているときは正規表現に**しない**。
+   `|` で分割し、**完全一致**(`Array.prototype.includes`)で判定する。
+2. 正規表現として `new RegExp(t)` に渡すのは、上の文字集合から外れた matcher だけである。
+3. 同じファイルにある警告文 —
+   「Hook matcher \`…\` matches no tool (it is compared as an exact string).」 —
+   も、単純な matcher が完全一致で比べられることを裏づけている。
+
+`Write|Edit` も `Bash` も文字集合の内側なので、**完全一致の経路に入る**。
+したがって `NotebookEdit` / `MultiEdit` には一致しない。
+
+## 結論
+
+- 設計と AGENTS.md の「対象は `Write` と `Edit` の 2 つだけ」は**正しい**。
+  ただし「公式の説明がツール名の正確な文字列を書く形だから」という設計時の理由付けは弱かったので、
+  上の機序を根拠として書き足した。
+- `^(Write|Edit)$` へ変える案は採らない。アンカーを足すと文字集合から外れて
+  **正規表現の経路へ移る**(動きはするが、判定の通り道が変わる)うえ、家系の他リポジトリ
+  4 本が採っている形からも離れる。今の形で意図どおりに動いている。
+- **保証範囲は誇張しない**: 上は **Claude Code 2.1.233 での実測**である。将来の版が
+  単純な matcher の扱いを変えたら前提は変わる。
+  - `ClaudeHooksWiringTest` が固定するのは **設定に書かれた matcher 文字列だけ**である。
+    本体側の判定機序が変わったことは**検出しない** — 文字列が `Write|Edit` のまま
+    意味だけ変われば、テストは緑のままである。**自動検出は無い**。
+  - したがって再確認は**運用**で担う。**Claude Code を更新したら人手で確かめる**
+    (この文書の「何を見たか」の手順をそのまま繰り返せばよい)。この申し送りは
+    完了報告にも載せる。
+
+## 併せて確認した Codex の [Suggestion]
+
+`docker/Dockerfile` の `RUN uv tool install …` で `uv` が解決できるかどうか。
+`uv` は mise が導入し、`ENV PATH="/home/vscode/.local/share/mise/shims:$PATH"` が
+`USER vscode` の直後に置かれている。追加した `RUN` は `mise install` より後なので
+shims 経由で解決される。現行コンテナでの実測は
+`command -v uv` → `/home/vscode/.local/share/mise/installs/uv/latest/…/uv` (0.12.1)。
```

## 申し送り事項 (完了報告に載せる予定のもの)

1. main 統合後の**新しいセッション**で、`Write` / `Edit` を 1 回行い索引が実際に前進すること、
   および無関係な `Bash` を数回叩いて遅延・警告が出ないことを確認する
2. 既存の開発コンテナは作り直すまで索引ツールが入らない (作り直さない場合は
   `uv tool install code-review-graph==2.3.7` を手で 1 度実行する)
3. `docker/Dockerfile` の実イメージビルドは本 PR の検証に含まれない (CI はビルドしない)
4. **Claude Code を更新したら matcher の判定機序を人手で再確認する**
   (自動検出は無い。手順は matcher-semantics-evidence.md の「何を見たか」)

以上で再判定をお願いします。
