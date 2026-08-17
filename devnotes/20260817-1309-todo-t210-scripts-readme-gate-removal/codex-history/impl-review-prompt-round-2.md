# 実装レビュー Round 2 (aicue:T210)

Round 1 の指摘 (Critical 0 / Warning 3 / Suggestion 1) への対応を報告する。
対応マトリクスは `codex-history/impl-review-decisions-round-1.md` に保存済み。以下はその要約と実際の差分である。

## 対応サマリー

| 指摘 | 判断 | 対応 |
|---|---|---|
| [W] verification.md の A8 が未実測のまま全 green を主張 | 対応する | 全検証コマンド 10 本を完走させ、§5 に終了コードと実測値を記入した |
| [W] BughuntInventoryToolSelfTest のコメントが新しい母集団定義と矛盾 | 対応する | 「突合が汚れる」→「母集団には入らないが実ディレクトリと目視の一覧を汚す」へ修正 |
| [W] A2 が設計の受け入れ条件からずれている | 対応する | verification.md に「設計からの逸脱」として理由を明記し、設計どおりの形の実測 (1 件) も併記 |
| [S] 完了報告テンプレートに重複・空欄の欄が無い | 対応する | Step 5 の報告枠へ 1 行追加 |

## 全検証コマンドの実測 (すべて exit 0)

| コマンド | 実測 |
|---|---|
| `composer test` | tests=5626 / passed=5624 / skipped=2 / assertions=24733 |
| `composer phpstan` | [OK] No errors (level 10) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | 指摘なし |
| `pnpm test` | Test Files 160 passed / Tests 1967 passed |
| `pnpm build` | 成功 |
| `pnpm typecheck:packages` / `pnpm build:packages` | 成功 |
| `pnpm test:packages` | Test Files 10 passed / Tests 106 passed |

撤去前の基準測定は tests=5631 / passed=5629 / assertions=24741 だったので、
差は **テスト 5 本ちょうど** (撤去したファイルが定義していた本体 1 本 + 負のコントロール 4 本) である。
5 本以外の増減は無い。

## Round 1 以降の差分 (git diff)

```diff
diff --git a/.claude/skills/app-update-docs/SKILL.md b/.claude/skills/app-update-docs/SKILL.md
index 4ba319e..4335a47 100644
--- a/.claude/skills/app-update-docs/SKILL.md
+++ b/.claude/skills/app-update-docs/SKILL.md
@@ -68,6 +68,9 @@ ### 1-1. ドキュメント一覧の確認
 ただし `docs/TODO.md` / `docs/TODO-closed.md` は `/app-todo-add` / `/app-todo-close`
 スキルの管轄のため、本スキルの更新対象から除外する。
 
+`scripts/README.md` の台帳の整合確認 (2-1) は、**scope 引数によらず必ず実施する**
+(scope で絞っても飛ばさない)。
+
 ### 1-2. ソースコード構造の確認
 
 `scope` 引数に応じてソースコードを探索する。
@@ -102,6 +105,73 @@ ### 1-2. ソースコード構造の確認
 
 ## Step 2: 陳腐化チェック
 
+### 2-1. scripts/ 台帳の整合確認 (scope 引数によらず必須)
+
+`scripts/README.md` は `scripts/` 配下のスクリプト台帳であり、**この整合を CI で落ちる検査にしない**
+(家系の裁定 AG-076 / AG-076b / AG-133、およびその執行を命じた AG-192)。
+突き合わせは本段が人手 (エージェント) で行う。**この手順を機械検査へ昇格させないこと。**
+数え方の正本は `scripts/README.md` 冒頭の「台帳の対象範囲」である。
+
+#### 形態 A: 網羅性 (双方向の差集合) と列の空欄
+
+貼って実行する形なので、全体を `(` `)` で囲んで呼び出し元のシェルに変数も `trap` も残さない。
+
+```bash
+(
+WORK=$(mktemp -d) || exit 1     # 失敗を素通りさせない ($WORK が空だと / 直下へ書きに行く)
+trap 'rm -rf -- "$WORK"' EXIT
+
+# 台帳として扱う範囲を「## スクリプト一覧」からファイル末尾までに限定する。
+# この範囲には台帳以外の表を置かないこと (置くと台帳の行として数えられる)。
+sed -n '/^## スクリプト一覧$/,$p' scripts/README.md > "$WORK/table.md"
+
+git ls-files scripts/ | grep -v '^scripts/README\.md$' | sort > "$WORK/tracked.txt"
+sed -n 's/^| `\([^`]*\)`.*/scripts\/\1/p' "$WORK/table.md" | sort > "$WORK/listed.txt"
+
+echo "追跡下: $(wc -l < "$WORK/tracked.txt") / 表の識別子: $(wc -l < "$WORK/listed.txt")"
+echo '--- 未記載 (実体にあるが表に無い) ---'; comm -23 "$WORK/tracked.txt" "$WORK/listed.txt"
+echo '--- 残骸 (表にあるが実体に無い) ---';   comm -13 "$WORK/tracked.txt" "$WORK/listed.txt"
+echo '--- 重複した識別子 ---';                uniq -d "$WORK/listed.txt"
+
+# 用途 (第 2 列) と実行タイミング (第 3 列) が空の行、および列数が 3 でない行。
+echo '--- 空欄・書式不正 ---'
+awk -F'|' '/^\| `/ {
+  id = $2; purpose = $3; timing = $(NF - 1);
+  gsub(/`/, "", id);
+  gsub(/^[ \t]+|[ \t]+$/, "", id); gsub(/^[ \t]+|[ \t]+$/, "", purpose); gsub(/^[ \t]+|[ \t]+$/, "", timing);
+  if (NF != 5)                             printf "書式不正 (セル数 %d): %s\n", NF - 2, id;
+  else if (purpose == "" || timing == "")  printf "空欄: %s\n", id;
+}' "$WORK/table.md"
+)
+```
+
+- **両向きを必ず測る**。片側の差集合だけを見て「欠落 0 件」と判断しないこと
+  (家系の別リポジトリで実際に起きた読み違いである)。
+- 未記載が出たら表へ 1 行足す。残骸が出たら行を消す。重複が出たら 1 行に畳む。
+  **同じパスが重複と残骸の両方に出たら、まず重複を畳んでから読み直す**
+  (`comm` は重複行を差分として数えるため、重複がある間は残骸側にも同じパスが並ぶ)。
+- 空欄が出たら埋める (**用途と実行タイミングが書けないスクリプトは昇格しない**、が規約である)。
+  「書式不正」はセル内に区切り記号が入った行でも出るので、出たら目で見て判断する。
+
+#### 形態 B: 記述の実態ずれ
+
+表の「用途」「実行タイミング」が実装と食い違っていないかを実ファイルで裏取りする。
+とくに **「〜から自動呼び出し」と書かれた行は、呼び出し元を実際に grep して確かめる**
+(過去に、どこからも呼ばれていないスクリプトが「CI から自動呼び出し」と書かれたまま残っていた)。
+
+```bash
+grep -rn "<スクリプト名>" . \
+  --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=devnotes
+```
+
+- **`scripts/README.md` 自身のヒットは呼び出し元ではない** (台帳の主張そのものなので、
+  これを根拠に「呼ばれている」と判断しない)。同様に、説明コメントやテストの文字列だけの一致も
+  呼び出しではない。**実行されている記述 (`package.json` の script / CI の job / 他スクリプトの
+  実行行 / hook の配線) に当たっているかで判断する。**
+- ずれていたら **README 側を実態に合わせる** (実装を README に合わせない)。
+
+### 2-2. ドキュメント本文の陳腐化チェック
+
 各ドキュメントについて、以下の観点でソースコードとの乖離を検出する。
 
 | チェック観点 | 方法 |
@@ -212,4 +282,10 @@ ### 更新サマリー
 
 ### 陳腐化修正
 - {修正内容の箇条書き}
+
+### scripts/ 台帳の整合確認 (2-1)
+- 追跡下のファイル数: {N} / 表の識別子数: {N}
+- 未記載: {N} 件（{パス}） / 残骸: {N} 件（{パス}）
+- 重複した識別子: {N} 件（{パス}） / 空欄・書式不正: {N} 件（{パス}）
+- 記述の実態ずれ: {N} 件（{内容}）
 ```
diff --git a/tests/Architecture/BughuntInventoryToolSelfTest.php b/tests/Architecture/BughuntInventoryToolSelfTest.php
index 024b5f7..2a620e7 100644
--- a/tests/Architecture/BughuntInventoryToolSelfTest.php
+++ b/tests/Architecture/BughuntInventoryToolSelfTest.php
@@ -32,8 +32,9 @@ function bitsTestsDir(): string
  */
 function bitsRunUnittest(array $modules): array
 {
-    // PYTHONDONTWRITEBYTECODE: __pycache__ を作らせない (scripts/ 配下の台帳検査
-    // ScriptsReadmeInventoryTest の母集団を生成物で汚さないため)。
+    // PYTHONDONTWRITEBYTECODE: __pycache__ を作らせない (scripts/ 配下に git 管理外の
+    // 生成物を残さないため。scripts/README.md の台帳の突合は git 追跡下を数えるので
+    // 母集団には入らないが、実ディレクトリと目視の一覧を汚す)。
     $process = new Process(
         ['python3', '-m', 'unittest', ...$modules],
         bitsTestsDir(),
```

## verification.md (Round 1 以降に書き足した部分を含む全文)

```markdown
# 検証記録: aicue:T210 (scripts 台帳の CI 検査の撤去)

実装ブランチ: `todo/T210` / 実行場所: `.claude/worktrees/tasks/T210`

本書は詳細設計の「テストファースト計画」「受け入れ条件」を実測した記録である。

## 1. 着手前 (赤) の実測

| 順 | コマンド | 実測 |
|---|---|---|
| R1 | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | 5 件ヒット (設計時の 4 件 + 設計後に登録された `docs/TODO.md` の作業項目行 1 件) |
| R2 | `test -e tests/Architecture/ScriptsReadmeInventoryTest.php` | 真 (存在する。211 行 / テスト 5 本) |
| R3 | `grep -c 'scripts/README' .claude/skills/app-update-docs/SKILL.md` | 0 |
| R4 | `grep -c '台帳の対象範囲' scripts/README.md` | 0 |
| A3〜A5 判定スクリプト | 設計 §受け入れ条件 | exit 1 (SKILL.md / README / AGENTS.md の全語が欠落) |
| R5 | `composer test` (撤去前の基準測定) | green: tests=5631 / passed=5629 / skipped=2 / assertions=24741 |

> R5 の基準測定は**撤去前**の状態で開始した (`ScriptsReadmeInventoryTest.php` は存在したまま)。
> 実行中に文書 2 本 (`.claude/skills/app-update-docs/SKILL.md` / `scripts/README.md`) を編集しているが、
> 前者を読む PHP テストは無く、後者を読むのは撤去対象の検査自身
> (`| ` 始まりの表の行だけを解析する = 追記した `>` 引用ブロックは解析対象外) である。
> 実測でも failure 0 件だった。

> **受け入れ条件 A2 の読み替え (設計からの逸脱。理由を明記する)**:
> 設計の A2 は「履歴以外に名前の参照が残っていない」を
> `':!devnotes' ':!docs/TODO-closed.md'` の 2 つの除外で表していた。
> しかし設計を書いた後に登録された `docs/TODO.md` の作業項目行 (T210) が、
> 撤去対象の**ファイル名そのもの**を作業内容の説明として含んでいる。
> `docs/TODO.md` は履歴ではなく現行の作業一覧だが、**本ブランチでは触らない**規則であり
> (クローズ時に `app-todo-close` が `docs/TODO-closed.md` へ移す = 履歴になる)、
> 実装側で消せる参照ではない。よって A2 の判定は
> **`':!docs/TODO.md'` を足した形へ読み替える** (現行の作業項目行は `app-todo-close` 管轄として除外する)。
> 読み替えないままの設計どおりのコマンドは 1 件ヒットし exit 0 になる (下記 §4 に併記)。

## 2. 台帳の実態 (形態 A の実走)

着手前 (作業ツリー無改変) の実測:

```
追跡下: 32 / 表の識別子: 32
--- 未記載 (実体にあるが表に無い) ---
--- 残骸 (表にあるが実体に無い) ---
--- 重複した識別子 ---
--- 空欄・書式不正 ---
```

未記載 0 件 / 残骸 0 件 / 重複 0 件 / 空欄・書式不正 0 件 (受け入れ条件 A6 を満たす)。
実装後 (`scripts/README.md` へ「台帳の対象範囲」を追記した後) も同じ 32 / 32 / 0 / 0 / 0 / 0 であることを再実走で確認した
(追記は `>` 引用ブロックであり、`| ` 始まりの表の行を 1 つも増やさない)。

## 3. 負のコントロール (照合が空振りしていないことの確認)

**作業ツリーには 1 バイトも触っていない。** `mktemp -d` した作業用ディレクトリへ
`scripts/README.md` と `git ls-files scripts/` の出力を複製し、その複製の上だけで崩した。

| # | 崩し方 (複製の上) | 期待 | 実測 |
|---|---|---|---|
| 1 | 表から `phpstan.sh` の行を消す | 未記載 1 件 | `未記載: scripts/phpstan.sh` (追跡下 32 / 識別子 31) |
| 2 | 実在しない `no-such-script.sh` の行を足す | 残骸 1 件 | `残骸: scripts/no-such-script.sh` (追跡下 32 / 識別子 33) |
| 3 | `phpstan.sh` の行を複製する | 重複 1 件 | `重複: scripts/phpstan.sh` (併せて残骸側にも同じパスが出る = 設計の注記どおり) |
| 4 | `phpstan.sh` の実行タイミング列を空にする | 空欄 1 件 | `空欄: phpstan.sh` |

4 ケースとも意図どおり 1 件ずつ検出した (受け入れ条件 A7)。
ケース 3 で残骸側にも同じパスが並ぶのは `comm` が重複行を差分として数えるためで、
スキルの段にもその読み方 (まず重複を畳んでから読み直す) を書いてある。

## 4. 着手後 (緑) の実測

| # | 条件 | 実測 |
|---|---|---|
| A1 | `test ! -e tests/Architecture/ScriptsReadmeInventoryTest.php` | exit 0 (削除済み) |
| A2 (**設計から読み替え**) | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md' ':!docs/TODO.md'` | ヒット 0 件 (exit 1) |
| A2 (設計どおりの形) | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | 1 件 (`docs/TODO.md:32` の T210 作業項目行のみ。§1 の読み替えの根拠) |
| A3〜A5 | 判定スクリプト (SKILL.md 10 語 / README 3 語 / AGENTS.md 1 語) | exit 0 |
| A6 | 形態 A の実走 | 追跡下 32 / 表の識別子 32 / 未記載 0 / 残骸 0 / 重複 0 / 空欄・書式不正 0 |
| A7 | 負のコントロール 4 ケース | 上記 §3 のとおり検出 |
| A8 | 全検証コマンド | §5 のとおり全 green |

## 5. 全検証コマンドの実測

worktree `.claude/worktrees/tasks/T210` で 10 コマンドを順に実行し、**全て exit 0** を確認した。

| コマンド | 終了 | 実測 |
|---|---|---|
| `composer test` | 0 | tests=5626 / passed=5624 / skipped=2 / assertions=24733 |
| `composer phpstan` | 0 | `[OK] No errors` (level 10) |
| `vendor/bin/pint --test` | 0 | `{"tool":"pint","result":"passed"}` |
| `pnpm lint` | 0 | eslint 指摘なし |
| `pnpm typecheck` | 0 | `tsc --noEmit` 指摘なし |
| `pnpm test` | 0 | Test Files 160 passed / Tests 1967 passed |
| `pnpm build` | 0 | built |
| `pnpm typecheck:packages` | 0 | 指摘なし |
| `pnpm build:packages` | 0 | 成功 |
| `pnpm test:packages` | 0 | Test Files 10 passed / Tests 106 passed |

### 撤去前後の件数差 (受け入れ条件 R5)

| | 撤去前 (着手時に実測) | 撤去後 | 差 |
|---|---|---|---|
| tests | 5631 | 5626 | **-5** |
| passed | 5629 | 5624 | **-5** |
| skipped | 2 | 2 | 0 |
| assertions | 24741 | 24733 | -8 |

減った 5 本は撤去したファイルが定義していたテスト (本体 1 本 + 負のコントロール 4 本) と一致する。
**5 本以外の増減は無い** = 撤去が他の検査を巻き込んでいない。

## 6. 保証しないもの (この記録で主張しないこと)

- 本記録は「撤去後も台帳が実態と一致していること」を**この時点で**確認したにすぎない。
  撤去により毎 push の強制は失われ、以後のドリフトは文書更新スキルを回した時点でしか検出されない。
- 形態 B (記述の実態ずれ) は人が実ファイルで裏取りする手順であり、本記録では
  表の全 32 行を機械的に裏取りしてはいない (本作業は表の内容を 1 行も変更していない)。
- 母集団は git 追跡下である。未追跡のスクリプトは照合の母集団に入らない (現時点で未追跡 0 件)。
```

## 質問

上記の対応で Round 1 の Warning 3 件 / Suggestion 1 件は解消しているか。
残る問題があれば Critical / Warning / Suggestion に分類して指摘し、全体判定 (APPROVED / CHANGES_REQUESTED) を返してほしい。
