# 対応マトリクス: design-review Round 3

## [Warning] 母集団 B の件数 (10) と除外内訳 (6) が一致しない
- 判断: **対応する**
- 実測: 欠けていたのは **`tests/Architecture/ClaudeHooksWiringTest.php`** である。
  同テストの `ls-files` は `--error-unmatch .claude/settings.json` = **1 ファイルの追跡確認**で、
  追跡下の列挙ではない (同テストの `AGENTS.md` 参照はマーカー区間の読み取りなので母集団 A 側)。
- 対応内容: 除外理由を**表**にして 7 本を 1 本ずつ列挙した
  (`TrackedPhpSourceFiles` / `ForbiddenStatementTokenInvariantTest` / `ClaudeHooksWiringTest` /
  `codex-model-consistency.test.ts` / `pages-path-case-invariant.test.ts` /
  `validate_findings.py` / `app-update-docs/SKILL.md`)。
  母集団 A 側も件数を実測へ更新した (当たり 22 本 = 散文 13 + 実行されるコード 9)。

## [Warning] D3 の訂正を「(e) の実例」とするのは不正確
- 判断: **対応する**
- 根拠: 指摘のとおり。`TrackedPhpSourceFiles` という完全なクラス名は、実際の呼び出しにも
  「使えない理由」を書いた docblock にも同じ形で現れる。トークンの完全一致で検索しても
  docblock 側は一致し続けるので、(e) では解けない。
- 対応内容: 「(e) の実例」を削り、
  「**利用関係の棚卸しを素の文字列一致だけで行えない実例である。
  クラス名の言及と実際の呼び出しは、構文か呼び出し関係で区別する必要がある**」へ書き直した。
  ((e) では解けない別種の問題であることも括弧で明示した。)

## [Suggestion] 再現コマンドが空白入りのパスと引数長に弱い
- 判断: **対応する**
- 対応内容: 2 本とも `$(git ls-files | … | tr '\n' ' ')` の展開をやめ、
  **`git grep` の pathspec 除外 (`-- ':!devnotes'`)** へ替えた。
  「この形を使う理由」(空白を含むパスで割れる / 引数長の上限に触れる) も付録へ書いた。

## [判定] 施策 1 / 施策 2 は APPROVE
- 追加の変更なし。
