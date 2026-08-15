全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**

[Suggestion] 本件は North Star への直接機能ではなく、開発基盤改善としての間接貢献です。  
ただし、bug-hunt の誤実行防止と code-review-graph の鮮度維持は、品質低下や改修漏れを減らす基盤なので、使命との整合性はあります。概念設計内でも「間接」と明示されており、過剰な主張にはなっていません。

**2. 禁止事項違反**

[Warning] 「テストなしの実装完了報告」を避ける設計にはなっていますが、実起動層のテストケースが詳細設計送りになっています。  
修正提案: 概念設計段階でも最低限、以下の実プロセス起動ケースを受け入れ条件として明記してください。

- PostToolUse hook は壊れた JSON / PATH 空 / code-review-graph 未導入 / lock 競合 / timeout でも exit 0
- PostToolUse hook は成功時 stdout/stderr 無出力
- PreToolUse hook は拒否対象で exit 2
- PreToolUse hook は非対象コマンドで外部コマンドなし exit 0
- `.claude/settings.local.json` の hooks 禁止が検出される

**3. 実現可能性**

[Warning] `scripts/bughunt-worktree-hook.sh` の JSON 解析を「bash 組み込みだけ」で行う方針は危険です。Claude hook の stdin JSON は将来フィールド順・空白・エスケープを変え得ます。bash だけの抽出は、実質的に脆い簡易パーサになりやすいです。  
修正提案: fail-open を避けたい PreToolUse では、JSON 解析をしない設計に寄せるか、固定された小さい入力形式に対して厳格に失敗を扱う必要があります。たとえば「`tool_input.command` が安全に抽出できない場合、bug-hunt-shard.sh を含む可能性が否定できない入力では拒否」など、壊れた入力時の判断を明文化してください。

[Warning] `/bin/bash -p` の採用は合理的ですが、Claude Code の hook 実行環境で `CLAUDE_PROJECT_DIR` が常に期待通り設定される前提に依存しています。  
修正提案: Architecture テストで `.claude/settings.json` の起動文字列だけでなく、`CLAUDE_PROJECT_DIR` 未設定時の挙動も固定してください。少なくとも PostToolUse は exit 0、PreToolUse は無関係コマンドを止めないことが必要です。

**4. 期待効果の妥当性**

[Warning] 「索引ツールが無い環境ではセッションごとに 1 行だけ告知」と「PostToolUse は成功時 stdout/stderr 無出力」は両立しますが、Claude hook 仕様上、標準出力がセッションに現れうる点を考えると、告知が頻発・混入するリスクがあります。  
修正提案: 告知先を stdout ではなく stderr に限定するのか、または `.claude/tmp` 等の状態ファイルだけに記録するのかを明確にしてください。「1 行だけ告知」はよいですが、どのチャネルに出すかが設計上の重要点です。

[Suggestion] code-review-graph の自動更新による改修漏れ低減は合理的ですが、効果は補助的です。「見落としが減る可能性が高い」程度に留めるのが妥当です。

**5. リスク**

[Critical] 「hook がセッション全体を壊す」最大リスクは PostToolUse より PreToolUse 側です。設計では bug-hunt ガードが拒否対象を狭めるとしていますが、JSON 抽出失敗時・stdin 読み取り失敗時・`CLAUDE_PROJECT_DIR` 不正時の扱いがまだ曖昧です。ここが誤ると Bash ツール全体を止めるか、逆に拒否対象を通します。  
修正提案: PreToolUse の失敗モードを表で固定してください。特に以下は deny-by-default / allow-by-default のどちらかを明文化すべきです。

- stdin が空
- stdin が JSON でない
- command フィールドが抽出不能
- command が巨大
- command に改行・エスケープ・Unicode が含まれる
- `CLAUDE_PROJECT_DIR` が空、相対パス、存在しない

[Warning] symlink 拒否の対象が「作業ファイル置き場・ロック・告知フラグ」とありますが、親ディレクトリの symlink / path traversal / TOCTOU への扱いが不足しています。  
修正提案: hook 専用の状態ディレクトリを repo 配下の固定パスにし、親ディレクトリを含めて `[[ -L ... ]]` を確認する、または安全に作れない場合は完全 no-op にする条件を Architecture テストで固定してください。

**6. スコープの適切さ**

[Warning] Dockerfile への `uv tool install code-review-graph==2.3.7` 追加は本件の目的に合いますが、「常設 hook 配線」と「開発コンテナ依存導入」は変更の性質が違います。1 タスクに束ねる理由は理解できますが、Dockerfile 変更はビルド時間・キャッシュ・ネットワーク依存の副作用を持ちます。  
修正提案: Dockerfile 導入は同一 PR でよいものの、受け入れ条件を分けてください。hook はツール未導入でも壊れない、Dockerfile は再ビルド後に告知が消える、という独立した合否にするのが安全です。

[Suggestion] SessionStart hook や permissions をスコープ外にした判断は適切です。現状の穴は `.claude/settings.json` の常設配線なので、そこに絞るのは妥当です。

**7. 型安全性 / 機械統制**

[Warning] deny-by-default の方向性はよいですが、「実起動層」のケースが詳細設計送りのままだと、台帳テストが静的な文字列固定に偏る恐れがあります。  
修正提案: `ClaudeHooksWiringTest.php` の責務を次のように明文化してください。

- `.claude/settings.json` のトップレベルキー全数申告
- hook event / matcher / command / timeout の完全一致
- 未申告 hook の禁止
- `.claude/settings.local.json` の hooks 禁止
- example settings 復活禁止
- hook script の安全化前置き byte 一致
- PostToolUse の exit 0 invariant
- PreToolUse の拒否対象 exit 2 invariant
- code-review-graph `install` / `uninstall` 呼び出し禁止の静的検査

[Suggestion] `code-review-graph install/uninstall` 禁止を AGENTS.md とテストの両方で固定するのはよい設計です。配線の正本を `.claude/settings.json` に一本化できます。

結論として、方向性は妥当ですが、PreToolUse の失敗モードと JSON 抽出方針がまだ危険です。ここを明文化し、実プロセス起動テストの受け入れ条件まで概念設計に入れれば、承認可能な水準になります。