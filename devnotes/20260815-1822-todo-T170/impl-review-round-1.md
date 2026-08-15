**AGENTS.md**

[Approved] 設計どおりです。秘密ファイル 4 本、0600 固定、`.env` 必須、既存 worktree に遡及しない点が明記されており、保証範囲も過大ではありません。

**docs/worktree-isolation-strategy.md**

[Approved] 実装との対応は取れています。`public/build` はコピー、秘密ファイルは供給、health check は mode を見ないという限定も正確です。

**scripts/README.md**

[Approved] 短い記述として十分です。`.env` 必須と秘密ファイル 0600 の要点が反映されています。

**scripts/setup-worktree.sh**

[Warning] `provision_secret_file` は供給先ファイル自身の symlink は拒否していますが、親ディレクトリ側の symlink、例えば `worktree/storage -> /tmp/outside` は拒否していません。固定された 4 パスを新規 worktree に供給する前提では大きな問題ではありませんが、「symlink 追従を防ぐ」という保証は最終ファイル symlink に限定されます。設計コメントも概ねその限定で書かれていますが、セキュリティ観点では残余リスクとして扱うべきです。

[Approved] 主要実装は設計と一致しています。`install -m 600 --`、required/optional の fail-closed、`.env.example` fallback 撤去、条件式に置かない呼び出し、`set -euo pipefail` 下の失敗伝播はいずれも妥当です。

**tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php**

[Suggestion] D-12 は `PROVISIONED_PATHS` の記録契約を見るなら、optional 成功時にも記録されることを 1 ケース足すとより強いです。現状でもファイル供給と mode は D-3〜D-5 で見えているため、必須の欠落ではありません。

[Approved] 18 ケースは実装の主要な退行に効いています。特に D-2〜D-6、D-10、D-13 と S-2〜S-5 は偽グリーンになりにくい作りです。PHPStan level 10 への配慮も問題ありません。

**DESIGN.md / Atomic Design**

該当なしです。今回の diff は `resources/js` / `resources/css` を含まず、UI 実装に触れていません。

**全体判定**

APPROVED