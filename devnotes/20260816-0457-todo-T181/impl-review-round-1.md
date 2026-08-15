**レビュー仮説:** 変更は設計通り小さく閉じており、主なリスクは shell 探索の空振り、テストの偽グリーン、保証範囲の誇張にあるとして確認しました。

**.gitignore**

指摘なし。`upgrade-stripe` の個別除外は設計通りで、既存 glob を広げすぎて意図しない `.claude/skills/*` 全体を隠す形にもなっていません。

**tests/Architecture/SkillsLockIgnoreCoverageTest.php**

指摘なし。`--no-index`、exit code `0/1` の正常扱い、それ以外の fail、正負コントロール、空キー検出が揃っています。PHPStan level 10 向けの型注記と `Assert` による絞り込みも概ね十分です。

**scripts/claude**

[Suggestion] fallback の `ls -d "$HOME/.../anthropic.claude-code-"*` はディレクトリ以外の同名パターンにも一致します。後段で `[ -d ]` を確認しているため採用パス自体は実在ディレクトリに限定されていますが、もし「より新しい名前のファイル」が混ざると `tail -1` に選ばれて既存の古い実ディレクトリを見落とす可能性があります。通常の VSCode extension 配下では現実的な問題ではありませんが、テストの偽 HOME ではこの性質を固定していません。

それ以外は設計通りです。`local` 無し関数をコマンド置換内だけで呼ぶ前提、`sort -V` 依存、代替経路が別 platform バイナリを掴みうる点もコメントで保証範囲を過大化していません。

**scripts/claude-wrapper.test.ts**

[Warning] W2 で `result.status` を明示確認していません。偽バイナリ到達は `recordedInvocation()` で実質確認できていますが、設計は「終了コードが 0 になるのは偽バイナリが 0 で終わるから」と固定する方針なので、`expect(result.status).toBe(0)` を置くのが設計との一致としてより正確です。

[Suggestion] W7 の壊れやすい引数転送は良いですが、改行入り引数は対象外です。設計にも必須とは書かれていないためブロッカーではありません。

後始末、実 HOME 非破壊、`uname` ベースの platform 算出、statusline 有無、`--no-bypass` / `--no-ctx` は設計通りです。

**scripts/README.md**

指摘なし。`scripts/` 台帳追加は設計通りで、用途説明も実装範囲を超えていません。

**DESIGN.md / Atomic Design**

該当なし。`resources/js` / `resources/css` には触れていません。

CHANGES_REQUESTED