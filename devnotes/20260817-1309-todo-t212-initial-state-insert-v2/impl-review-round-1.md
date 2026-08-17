**AGENTS.md**

[Warning] 設計では台帳 ID を `aicue:T212` 形式にする前提でしたが、追加文は `T212` になっています。`docs/architecture.md` も同じです。家系台帳との照合規律を明文化している変更なので、ここは `aicue:T212` に揃えるべきです。

**docs/architecture.md**

[Warning] 同上で、見出しが `T212` です。`aicue:T212` に修正してください。

[Suggestion] 「保証しないものは本書に写さない」は設計どおりです。ここで代表例を括弧書きしている程度なら許容範囲ですが、今後増やすと docblock と乖離しやすいので最小限のままでよいです。

**tests/Support/InitialState/NullInitialStateClass.php**

判定: 問題なし。区分 3 種、`match` 網羅、余計な default なしで設計どおりです。

**tests/Support/InitialState/NullableStateColumnEntry.php**

判定: 問題なし。`private constructor`、名前付き生成子、30 文字検査、`key()` 集約はいずれも設計どおりです。

**tests/Support/InitialState/NullableStateColumnRegistry.php**

判定: 大きな問題なし。

区分を初期案から動かした 6 列は、提示された実読根拠ベースでは妥当です。特に `users.email_verified_at` と `ticket_ledger_entries.carried_forward_through` は「生成時に非 NULL がありうる」ので `SetAtCreation` が正しいです。`plan_prices.*` / `ticket_volume_prices.*` は「作成時は NULL、後続同期や世代交代で既存行へ入る」という前提が正しければ `InitialStateMarker` で問題ありません。

**tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php**

[Suggestion] `NC-3` の代表値は設計上 `0` ですが、実装は文字列 `'0'` です。実スキーマ正規化では scalar を string 化するので実害は小さいものの、設計との完全一致を重視するなら `nullInitialStateNormalizeColumns()` 経由で integer `0` も点灯させるケースを足すとよいです。

[Suggestion] `NC-6` は「NI-3 が落ちる条件」を確認していますが、NI-3 の判定ロジック自体を純関数化して直接失敗させてはいません。現状の NI-3 本体に非空 assertion があるので穴ではありませんが、将来の弱体化検知としては少し弱いです。

[Suggestion] 作成・更新時刻の除外は、時刻型だった場合に `continue` するため、現在の意図どおり「時刻型としても enum cast としても入らない」動きです。現在スキーマ上の穴は見当たりません。ただし「lifecycle 列は無条件に母集団外」と読ませたいなら、lifecycle 判定を `$isTemporal` ブロックの前に出すと意図がより明確になります。

`nullInitialStateNormalizeColumns()` の fail-closed は、必要キー欠落と型不一致の両方を `problems` に積み、NI-3 で落とすため十分です。`name` 欠落時のメッセージも設計どおり成立しています。

NI-7 の 3 一覧 pin は、内訳 2 本を exact match し、その期待値 union と actual union も比較しているため、件数据え置きの入れ替わりは通しません。

**devnotes**

[Warning] 受け入れ条件 A14 の `devnotes/20260817-1309-todo-t212-initial-state-insert-v2/red-first.md` が、提示された `git diff HEAD` には出ていません。本文で赤の実測は提示されていますが、要件は「リポジトリ内ファイルとして存在」です。未追跡または差分漏れなら追加が必要です。

**全体判定: CHANGES_REQUESTED**

実装の中核は設計にかなり合っています。ブロック理由は主に、台帳 ID 表記の設計不一致と、red-first 記録ファイルが差分上確認できない点です。コード上の純関数・pin・AG-191 経路には致命的な穴は見当たりません。