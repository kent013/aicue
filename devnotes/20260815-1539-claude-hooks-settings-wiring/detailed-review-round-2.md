仮説「修正後の保証とテスト境界が一致し、hook 故障がセッションを止めない」を基準に再レビューした。Round 1 の指摘は、1 件を除いて適切に解消されている。

## 施策別判定

### 施策 1: 索引更新 hook

**APPROVE**

告知フラグを `warned-${reason}-${session_id}` とし、noclobber による排他的作成へ変更したことで、重複抑止と symlink 追従の問題は解消されている。

ロックファイルについても、`flock` のプロセス終了時解放を優先し、TOCTOU を保証対象外へ明示的に下げる判断は妥当。脅威モデルとの釣り合いも取れている。

固定リストとの `=` 比較に変えた拡張子判定も問題ない。

### 施策 2: bug-hunt ガード

**APPROVE**

正常抽出経路と抽出失敗経路の仕様差が明文化され、以前の矛盾は解消された。

外部コマンドを完全に排除したことで、検索パス異常時にも判定結果が変わらないという保証と実装が一致している。`provision-all` のテスト追加も十分。

### 施策 3: `.claude/settings.json`

**REQUEST_CHANGES**

[Warning] `Write|Edit` が `MultiEdit` / `NotebookEdit` に部分一致するという前提は、現行の公式説明と一致しない。

公式ドキュメントは、`"Edit|Write"` について「`Edit` または `Write` のときだけ発火し、その他のツールでは発火しない」と明記している。また、ツール名は hook matcher で使う正確な文字列と説明されている。[Hooks guide](https://code.claude.com/docs/en/hooks-guide)、[Tools reference](https://code.claude.com/docs/en/tools-reference)

したがって、「アンカーなしなので将来の派生ツールにも一致する」という保証は置けない。

修正案:

- v1 の対象が `Write` / `Edit` だけなら、値は `Write|Edit` のままとし、「この2ツールだけが対象」と記述する。
- `NotebookEdit` も必要なら `Write|Edit|NotebookEdit` と明示列挙する。
- 存在しない `MultiEdit` は現在の台帳へ先回りして追加しない。
- 「将来の派生ツールを自動的に拾う」という説明とテスト前提を削除する。

Bash による変更を保証対象外とした点と、次回更新で差分に含まれるという説明は妥当。

### 施策 4: Architecture テスト

**APPROVE**

S12 の文書面と実行面の分離は適切。禁止事項の説明そのものによる誤検出を避けつつ、実行可能な経路を固定できている。

S10 も、先頭行の構造を検査する方式なら shell parser を作らずに目的を達成できる。stub PATH の前提も明確になった。

施策3の修正後は、matcher のコメントと期待値も同時に更新すること。

### 施策 5: Dockerfile

**APPROVE**

`UV_TOOL_DIR`、`UV_TOOL_BIN_DIR`、`PATH` の三者を固定し、Architecture テストで対応関係を検査する設計で、実行ユーザーへの暗黙依存は除去されている。

### 施策 6: 文書更新

**APPROVE**

`standalone` が専用 worktree を意味することと、main 統合後に必要なのは実装ではなく新セッションでの確認であることが明確になった。保証範囲の表現も実装と一致している。

## 全体判定

**CHANGES_REQUESTED**

残る Warning は施策3の matcher の保証説明だけ。`Write|Edit` 自体は維持可能だが、部分一致によって `MultiEdit` / `NotebookEdit` まで捕捉するという説明は削除または明示列挙へ変更する必要がある。それを直せば、全施策を **APPROVED** と判定できる。