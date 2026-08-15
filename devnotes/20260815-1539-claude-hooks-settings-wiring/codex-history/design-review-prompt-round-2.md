# Round 2: 詳細設計の修正

Round 1 の Warning 8 件・Suggestion 5 件すべてに対応した。対応マトリクスと変更後の該当箇所を示す。

## 施策 1

### [Warning] lock / warn flag の symlink 保証が TOCTOU で成立しない → 一部対応 + 保証範囲を下げた

2 案のうち、**告知フラグは前者 (原子的な作成)、ロックは後者 (保証範囲を下げる)** に分けた。

- **告知フラグ**: `( set -C; : > "$flag" )` に変えた。`O_CREAT|O_EXCL` になるので、既にあっても symlink でも作成が失敗する = 検査と作成が原子的になり、TOCTOU が無い。追加コストゼロ。
- **ロック**: ディレクトリロックは**プロセスが落ちたときに解放されず、索引更新が恒久的に止まる** (flock は解放される)。可用性のほうが重いので `flock` を残し、「**ロックファイルの差し替え (TOCTOU) までは防がない**」を保証しないものへ明記した。テストも「事前に symlink になっていれば更新しない」までに揃えた。
- 根拠の併記: 置き場の中に symlink を差し込める者は、hook スクリプト自体を書き換えられる。ここは意味のある安全境界ではない。

### [Warning] 重複抑止が「セッションごと・理由ごと」になり切っていない → 対応した

フラグ名を `warned-${reason}-${session_id}` にした (上の変更と同時に解決)。`session_id` は `^[A-Za-z0-9._-]{1,64}$` で検証済みなのでファイル名に使える。フラグは 0 バイトで置き場ごと消してよい旨を AGENTS.md に 1 行書く。

```bash
emit_warning() {
    local reason="$1" message="$2" flag
    flag="${state_dir}/warned-${reason}-${session_id}"
    ( set -C; : > "${flag}" ) 2> /dev/null || return 0
    printf 'code-review-graph: %s\n' "${message}" >&2
    return 0
}
```

あわせて処理順序を入れ替え、セッション識別子の抽出 (副作用なし) を段 3 に前倒しした。ロック取得前に出しうる告知は `no-flock` の 1 件だけで、その重複抑止も上の原子的作成が担う。

### [Warning] 拡張子を case パターンに埋めている → 対応した

```bash
case "${file_path}" in
    *.*)
        extension="${file_path##*.}"
        extension="${extension,,}"
        for skip in ${SKIP_EXTENSIONS}; do
            [ "${extension}" = "${skip}" ] && exit 0
        done
        ;;
esac
```

### [Suggestion] `cd` の stderr → 対応した (`> /dev/null 2>&1` を付けた)

## 施策 2

### [Warning] 「1 文字も変えない」と抽出失敗時の fail-closed が矛盾 → 対応した

概念設計・詳細設計の両方で次に直した。

> **正常に `command` を抽出できた経路の拒否対象・許可シグナルは不変**である。抽出に失敗した経路だけ、現行の「素通り」から失敗モード表の規則 (明示解除だけを見て、無ければ拒否) へ変える。これは意図した改善であって副作用ではない。

### [Suggestion] 拒否メッセージを printf に → 対応した

`cat` + ヒアドキュメントをやめ、`printf '%s\n' …` に変えた。これで**このスクリプトは外部コマンドを 1 つも使わない**ことになり、保証の説明が単純になった。あわせて概念設計の「非対象コマンドで外部コマンドを 1 つも起こさない」という表現も、「内側のガードスクリプトは外部コマンドを 1 つも使わない (起動子が `/bin/bash` を起こすのは別の話)」へ正確化した。

### [Suggestion] `provision-all` のテスト → B40b として追加した

## 施策 3

### [Warning] matcher `Write|Edit` だと MultiEdit が漏れうる → 値は据え置き、根拠を明記した

Claude Code の matcher は**ツール名に対するアンカーなしの正規表現照合**であり、`Edit` は `MultiEdit` / `NotebookEdit` にも一致する。`^(Write|Edit|MultiEdit)$` へ変えると (a) この harness に存在しないツール名を台帳へ書くことになり、(b) アンカーで将来の派生ツールを取りこぼす。**意図的にアンカーを付けない**ことを台帳のコメントとテスト計画に残し、後から「不正確」と誤読されて直されるのを防ぐ。

もし「アンカーなし照合」という前提そのものが誤りであれば指摘してほしい (その場合は `Write|Edit|MultiEdit|NotebookEdit` の列挙へ倒す)。

### [Suggestion] Bash によるファイル変更は対象外 → 保証しないものへ明記した

緩和策も併記した — 索引ツールの差分更新は「直前のコミット以降の差分」を再解析するため、`sed -i` 等で変わったファイルも次の Write / Edit のときにまとめて取り込まれる (取りこぼしは恒久化しない)。

## 施策 4

### [Warning] S12 の走査範囲が広すぎる → 対応した

S12 を 2 つに割った。

- **S12a**: `AGENTS.md` に禁止の明文がマーカー付きで存在する
- **S12b**: **実行面のファイルだけ**を走査する — `scripts/**/*.sh` / `.claude/settings*.json` / `docker/Dockerfile` / `composer.json` / `package.json` / `.github/workflows/*`。文書 (`AGENTS.md` / `README.md` / `docs/**` / `devnotes/**`) は走査しない (禁止を説明する文章にコマンド名が出るのは正常であり、走査すると設計書・逸脱台帳・devnotes で必ず落ちる)

### [Warning] S10 の判定が難しい → shell parser を作らない形に限定した

S10 は次の 3 点だけを見る。

1. 2 本それぞれに開始・終了マーカーが 1 組ずつある
2. マーカー間の内容が byte 一致する
3. 開始マーカー**より前**の行が、shebang・コメント・空行だけである

3 により「プロローグはファイル先頭にある」= 「最初の外部コマンド呼び出しより前にある」が自動的に成立する。行の中身は解釈しない。

### [Suggestion] stub PATH に system path を残す前提 → 明記した

stub ディレクトリは**システムパスの前に足す** (`$sandbox/bin:/usr/local/bin:/usr/bin:/bin`)。`mkdir` / `flock` / `timeout` は本物が要るため、stub だけの PATH にすると段 5 で終わって検証したい経路に到達しない。「索引ツール未導入」を作るときは stub ディレクトリに `code-review-graph` を**置かない**方法で作る (PATH からシステムパスを外すのではない)。ヘルパ名でも区別する (`claudeHooksPathWithTool()` / `claudeHooksPathWithoutTool()`)。

## 施策 5

### [Warning] `uv tool install` の導入先と ENV PATH の一致 → 対応した

`USER` の位置に依存させない形にした。

```dockerfile
ENV UV_TOOL_DIR=/home/vscode/.local/share/uv/tools
ENV UV_TOOL_BIN_DIR=/home/vscode/.local/bin
RUN uv tool install code-review-graph==2.3.7
ENV PATH="/home/vscode/.local/bin:$PATH"
```

Dockerfile テストは (1) 版固定の導入行 (2) `UV_TOOL_BIN_DIR` の宣言 (3) `ENV PATH` への同じディレクトリの追加、の 3 点を固定する。実測で `uv tool dir --bin` = `/home/vscode/.local/bin` であることも確認済み。

## 施策 6

### [Warning] standalone が main 直接実装に読める → 対応した

> 推奨モード: **standalone** (= 専用 worktree の単独タスク。**main 直接実装ではない**。AGENTS.md §worktree 運用ルールに従う)。`.claude/settings.json` はセッション開始時にしか読まれないので、実配線の確認だけは main 統合後の新しいセッションで行う (これは実装場所の話ではなく確認時点の話である)。

### [Suggestion] 保証範囲の表現 → 対応した (施策 2 の項に記載)

---

残る [Critical] / [Warning] があれば指摘してほしい。無ければ各施策と全体の判定を APPROVED としてほしい。
