レビュー仮説: この設計の成否は「hook が壊れてもセッションを止めない」ことと、「壊れている/効いていない状態を静的・実起動テストで検出できる」ことにあります。結論として方向性は妥当ですが、いくつかの保証が設計どおりに実装できていません。

全体判定: **CHANGES_REQUESTED**

Critical はありません。Warning は実装前に直した方がよいです。

## 施策 1: 索引更新 hook の実体

判定: **REQUEST_CHANGES**

[Warning] symlink/TOCTOU 保証が設計文ほど満たせていません。  
`lock_file` と `warned-*` は `[ -L ]` 後に通常のリダイレクトで開くため、チェック後の差し替えで symlink を辿れます。純 bash + `flock` の通常ファイルロックでは「ロック・告知フラグが symlink なら何も書かない」を強く保証しづらいです。

修正案: どちらかに寄せてください。

- symlink 安全を優先するなら、`warned-${reason}-${session_id}.d` のような **directory marker を `mkdir` で作る**。ロックも `update.lock.d` の directory lock にする。
- `flock` 必須を優先するなら、「lock file の TOCTOU までは防がない」と保証範囲を下げ、テストもその前提に合わせる。

[Warning] `emit_warning()` の重複抑止は「セッションごと・理由ごと」になり切っていません。  
1 ファイルに最後の `session_id` だけを書くため、複数セッションが交互に動くと、同一セッションで再告知され得ます。

修正案: `warned-${reason}-${session_id}` を marker にしてください。`session_id` は既に whitelist されているのでファイル名に使えます。

[Warning] 拡張子判定に untrusted 値を `case` pattern として埋め込んでいます。

```bash
*" ${extension,,} "*) exit 0 ;;
```

`*` や `[` を含む拡張子で意図しない skip が起きます。

修正案: 固定リストを loop し、文字列等価で比較してください。

```bash
for skip in md txt json yaml yml lock log; do
    [ "${extension,,}" = "${skip}" ] && exit 0
done
```

[Suggestion] `repo_root="$(cd ...)"` の失敗時 stderr がそのまま出る可能性があります。契約を厳密にするなら `2>/dev/null` を付けてください。

## 施策 2: bug-hunt ガード

判定: **REQUEST_CHANGES**

[Warning] 「判定条件は 1 文字も変えない」という説明と、抽出失敗時の fail-closed 設計が矛盾しています。  
現行実装は JSON 抽出に失敗すると `cmd=''` になって通ります。一方、新設計は壊れた JSON でも `bug-hunt-shard.sh provision` を含めば拒否します。これは改善として妥当ですが、「条件不変」ではありません。

修正案: 文言を次のように直してください。

> 正常に `command` を抽出できた経路の拒否対象・許可シグナルは不変。抽出失敗時のみ、概念設計の fail-closed 規則を採る。

[Suggestion] 拒否メッセージは `cat` ではなく bash builtin の `printf` に寄せる方が、設計意図と完全に揃います。現状でも判定後なので致命的ではありません。

[Suggestion] `provision-all` を明示テストに追加してください。正規表現上は `provision` prefix で通りますが、コメントが `provision / provision-all` を掲げているので、回帰防止のため B28 系に 1 ケース足すべきです。

## 施策 3: `.claude/settings.json`

判定: **REQUEST_CHANGES**

[Warning] PostToolUse の matcher が `Write|Edit` だけだと、`MultiEdit` が漏れる可能性があります。Claude Code の matcher が正規表現の部分一致なら拾えるかもしれませんが、設計上は曖昧です。

修正案: Claude Code の matcher 仕様に合わせて、明示的に `Write|Edit|MultiEdit` または `^(Write|Edit|MultiEdit)$` にしてください。あわせて台帳・テストも更新してください。

[Suggestion] 「Bash によるファイル変更は索引更新 hook の対象外」を保証しないものに明記してください。`sed -i` や生成スクリプトでコードが変わる経路は PostToolUse(Write/Edit) では拾えません。

## 施策 4: 台帳化テスト

判定: **REQUEST_CHANGES**

[Warning] S12 の「追跡ファイル内に `code-review-graph install/uninstall/init` が無い」検査は false positive になりやすいです。  
設計書・devnotes・禁止文言・template divergence に説明として書いただけでも落ちます。マーカー区間だけ除外では足りません。

修正案: 検査対象を実行可能な呼び出し面に限定してください。例:

- `scripts/**/*.sh`
- `.claude/settings*.json`
- CI 設定
- Dockerfile
- package/composer scripts

文書は「禁止文言の存在」を別テストに分け、呼び出し禁止スキャンから外すのが安全です。

[Warning] S10 の「最初の外部コマンド呼び出しより前」判定は難易度が高く、bash 構文を grep 的に見ると誤検出しやすいです。

修正案: まずは marker の位置と、marker 前に許可語以外の単純 command token が無いことに限定してください。完全な shell parser を作る方向には進まない方がよいです。

[Suggestion] B01 の stub PATH は `/tmp/stub:/usr/local/bin:/usr/bin:/bin` のように system path を残す前提を明記してください。stub だけにすると `mkdir` も見えず、段 4 で終了します。

## 施策 5: Dockerfile 導入

判定: **REQUEST_CHANGES**

[Warning] `uv tool install` の実行ユーザーと `ENV PATH="/home/vscode/.local/bin:$PATH"` が一致する保証が設計にありません。Dockerfile のその時点の `USER` が root なら、導入先は `/root/.local/bin` になり、hook は未導入扱いになります。

修正案: どちらかを固定してください。

```dockerfile
ENV UV_TOOL_BIN_DIR=/home/vscode/.local/bin
ENV UV_TOOL_DIR=/home/vscode/.local/share/uv/tools
RUN mise exec -- uv tool install code-review-graph==2.3.7
ENV PATH="/home/vscode/.local/bin:$PATH"
```

または `USER vscode` 後に実行することを Dockerfile テストで固定してください。

## 施策 6: 文書更新

判定: **REQUEST_CHANGES**

[Warning] 実装モードの `standalone` が main 直接実装を意味するなら、既存の worktree 運用ルールと衝突します。  
`.claude/settings.json` の実スモークが worktree 内でできないことと、実装を worktree で行うことは別問題です。

修正案: 「専用 worktree の単独タスクとして実装し、main 統合後の新セッションで実配線を確認する」と明記してください。

[Suggestion] 保証範囲の表現を少し絞ってください。  
「ガードは非対象コマンドで外部コマンドを 1 つも起こさない」は、mapper の `/bin/bash` 起動を含めると厳密には成り立ちません。「内側スクリプトの判定経路では外部コマンドを起こさない」が正確です。

---

まとめると、設計の主方針は良いです。特に「97 だけを 2 に写像する」「hook 本体の故障で Bash を止めない」「設定を台帳で完全一致 pin する」は妥当です。

ただし、symlink/TOCTOU の保証、PostToolUse の対象範囲、Dockerfile の導入先、S12 のスキャン範囲はこのままだと実装時に空振りまたは過検知になります。ここを直せば承認できる水準です。