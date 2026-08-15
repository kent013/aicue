# hook の matcher が「ちょうど 2 ツール」を意味することの実測

Codex 実装レビュー Round 1 が `"matcher": "Write|Edit"` について
「正規表現として評価されるならアンカーが無く `NotebookEdit` / `MultiEdit` にも一致する。
設計と AGENTS.md の『Write と Edit の 2 つだけ』は実装で固定されていない」と指摘した。

設計はこの点を**根拠を書かずに主張していた**ので、実物で確かめた。

## 何を見たか (この手順を Claude Code 更新のたびに繰り返す)

対象は本マシンに導入されている Claude Code 本体
(`anthropic.claude-code-2.1.233-linux-arm64` の `resources/native-binary/claude`)。
次の 2 コマンドで取り出した。

```bash
# 1. 本体の場所を探す (版の番号は導入済みのものに読み替える)
ls -d ~/.vscode-server/extensions/anthropic.claude-code-*/resources/native-binary/claude

# 2. matcher の判定関数を含む区間を取り出す
#    ("Invalid regex pattern in hook matcher" は判定関数の catch 節にある文言で、
#     難読化されていないため目印に使える)
strings -n 6 <上で見つけた claude のパス> \
  | grep -n "Invalid regex pattern in hook matcher"
```

2 の出力には 2 行出る。1 行目は文言そのもの、**2 行目が判定関数を含むコード行**である
(1 行が非常に長いので、`sNS(` あるいは `it is compared as an exact string` を目印に読む)。
取り出した判定関数は次のとおり (可読性のため変数名はそのまま):

```js
function sNS(e, t, r, n) {                       // e = ツール名, t = matcher
  if (!t || t === "*") return true;
  if ((r ? /^[a-zA-Z0-9_|, -]+$/ : /^[a-zA-Z0-9_|]+$/).test(t))
    return t.split(r ? /[|,]/ : "|").map(s => s.trim()).filter(Boolean)
            .flatMap(s => K0o(Q9(s), n)).includes(e);
  try {
    let i = new RegExp(t);
    if (i.test(e)) return true;
    …
  } catch { … "Invalid regex pattern in hook matcher: " … }
}
```

読み取れること:

1. matcher が **英数字・下線・`|` だけ**で出来ているときは正規表現に**しない**。
   `|` で分割し、**完全一致**(`Array.prototype.includes`)で判定する。
2. 正規表現として `new RegExp(t)` に渡すのは、上の文字集合から外れた matcher だけである。
3. 同じファイルにある警告文 —
   「Hook matcher \`…\` matches no tool (it is compared as an exact string).」 —
   も、単純な matcher が完全一致で比べられることを裏づけている。

`Write|Edit` も `Bash` も文字集合の内側なので、**完全一致の経路に入る**。
したがって `NotebookEdit` / `MultiEdit` には一致しない。

## 結論

- 設計と AGENTS.md の「対象は `Write` と `Edit` の 2 つだけ」は**正しい**。
  ただし「公式の説明がツール名の正確な文字列を書く形だから」という設計時の理由付けは弱かったので、
  上の機序を根拠として書き足した。
- `^(Write|Edit)$` へ変える案は採らない。アンカーを足すと文字集合から外れて
  **正規表現の経路へ移る**(動きはするが、判定の通り道が変わる)うえ、家系の他リポジトリ
  4 本が採っている形からも離れる。今の形で意図どおりに動いている。
- **保証範囲は誇張しない**: 上は **Claude Code 2.1.233 での実測**である。将来の版が
  単純な matcher の扱いを変えたら前提は変わる。
  - `ClaudeHooksWiringTest` が固定するのは **設定に書かれた matcher 文字列だけ**である。
    本体側の判定機序が変わったことは**検出しない** — 文字列が `Write|Edit` のまま
    意味だけ変われば、テストは緑のままである。**自動検出は無い**。
  - したがって再確認は**運用**で担う。**Claude Code を更新したら人手で確かめる**
    (この文書の「何を見たか」の手順をそのまま繰り返せばよい)。この申し送りは
    完了報告にも載せる。

## 併せて確認した Codex の [Suggestion]

`docker/Dockerfile` の `RUN uv tool install …` で `uv` が解決できるかどうか。
`uv` は mise が導入し、`ENV PATH="/home/vscode/.local/share/mise/shims:$PATH"` が
`USER vscode` の直後に置かれている。追加した `RUN` は `mise install` より後なので
shims 経由で解決される。現行コンテナでの実測は
`command -v uv` → `/home/vscode/.local/share/mise/installs/uv/latest/…/uv` (0.12.1)。
