# 対応マトリクス: impl-review Round 1

## [Warning] `matcher: "Write|Edit"` はアンカーが無く `NotebookEdit` / `MultiEdit` にも一致する

- 判断: **反論する** (ただし指摘が突いた「根拠が書かれていない」点は受け入れ、根拠を実測で補った)
- 根拠: Claude Code 本体 (`anthropic.claude-code-2.1.233`) の判定関数を実読した。
  matcher が英数字・下線・`|` だけで出来ているときは **正規表現にされず**、`|` で分割して
  **完全一致** (`Array.prototype.includes`) で比べられる。正規表現 (`new RegExp`) に渡すのは
  その文字集合から外れた matcher だけである。同じ本体に
  「Hook matcher \`…\` matches no tool (it is compared as an exact string).」という警告文もある。
  `Write|Edit` も `Bash` も完全一致の経路に入るので、`NotebookEdit` には一致しない。
  実読の全文と読み取りは `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`。
- 対応内容:
  - matcher は変えない。`^(Write|Edit)$` はアンカーを足すと文字集合から外れて
    **正規表現の経路へ移る** (動きはするが判定の通り道が変わる) うえ、家系の他リポジトリ 4 本が
    採っている形からも離れる。今の形で意図どおり動いている。
  - `AGENTS.md` の「2 つだけ」に**機序と実測の参照**を書き足した (根拠のない断言をやめた)。
    併せて「この機序は将来変わりうるので matcher 文字列を台帳テストで完全一致に固定して
    気づけるようにしてある」と、保証の限界も明記した。
  - 詳細設計にも訂正 E6 として同じ内容を残した (設計時の理由付けが弱かったことを記録)。

## [Warning] 台帳テストが `Write|Edit` を「正」として固定している

- 判断: **反論する** (上と同一の論点)
- 根拠: 完全一致の経路に入る以上、`Write|Edit` は「2 つだけ」の正しい表現である。
  台帳が完全一致で pin していることは、むしろ**本体の判定機序が将来変わったときに
  設定を見直す手掛かり**になる (matcher を書き換えれば必ず赤くなる)。
- 対応内容: 変更なし。

## [Warning] AGENTS.md / 設計の保証が実装と一致していない

- 判断: **一部対応する**
- 根拠: 「一致していない」は事実でないが、「保証の根拠が書かれていない」は事実だった。
  保証範囲を誇張しない規約に照らすと、根拠と限界を書くべき箇所だった。
- 対応内容: `AGENTS.md` §常設 hook 配線 と詳細設計の該当節に、機序・実測の参照・
  限界 (版が変われば前提が変わる) を書き足した。

## [Suggestion] `RUN uv tool install` の `uv` が非対話 `RUN` の検索パスで解決できるか

- 判断: **対応する** (確認して記録に残す)
- 根拠: `uv` は mise が導入し、`ENV PATH="/home/vscode/.local/share/mise/shims:$PATH"` は
  `USER vscode` の直後 (追加した `RUN` より前) にある。追加した `RUN` は `mise install` より
  後なので shims 経由で解決される。現行コンテナでの実測は
  `command -v uv` → `/home/vscode/.local/share/mise/installs/uv/latest/…/uv` (0.12.1)。
- 対応内容: 確認結果を `matcher-semantics-evidence.md` の末尾に記録した。
  Dockerfile の実ビルドは本 PR の検証に含まれない (CI はイメージをビルドしない) ため、
  **実ビルドでの確認は申し送り事項として残す**。
