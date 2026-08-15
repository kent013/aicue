`.claude/settings.json`

- [Warning] `.claude/settings.json:17` の `matcher: "Write|Edit"` は、hook matcher が正規表現として評価される前提だと `MultiEdit` / `NotebookEdit` などの `Edit` を含むツール名にも一致します。設計と AGENTS.md は「Write と Edit の 2 つだけ」と明記しているため、保証が実装で固定されていません。`^(Write|Edit)$` のようにアンカー付きへ変更し、台帳テストも同じ期待値に寄せるべきです。`PreToolUse` の `Bash` も同じ理由で `^Bash$` に寄せると意図が明確です。

`tests/Architecture/ClaudeHooksWiringTest.php`

- [Warning] `CLAUDE_HOOKS_WIRING` と S05/S06 が `Write|Edit` を完全一致で pin しており、上記の matcher 過一致を「正」として固定しています。ここは実装の防波堤になっていないので、アンカー付き matcher を台帳化する必要があります。

`AGENTS.md` / `devnotes/.../detailed-design.md`

- [Warning] 「対象は Write と Edit の 2 つだけ」「将来の派生ツールを自動で拾うことはない」という保証が、現在の `Write|Edit` 文字列と一致していません。実装をアンカー付きに直すか、文書側の保証範囲を下げる必要があります。設計意図から見ると実装修正が妥当です。

その他のファイル

- [Suggestion] `Dockerfile` の `RUN uv tool install code-review-graph==2.3.7` は静的 test では固定されていますが、実際の Docker build は今回の結果に含まれていません。`uv` が非対話 `RUN` の PATH で解決できる前提は、次回のコンテナ build で確認しておくのが安全です。

全体判定: CHANGES_REQUESTED