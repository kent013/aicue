仮説: schedule 除去自体は正しいが、再導入防止 gate と検証コマンドに偽グリーン経路が残る可能性を重点確認しました。裁定の是非は蒸し返しません。

**施策別判定**

1. **施策 1: ci.yml から `on.schedule` と job 条件を除去**
   - 判定: **APPROVE**
   - YAML の変更方針は妥当です。`on:` は `push` / `pull_request` のみになり、`php` / `browser-tests` / `frontend` の `if:` 削除も意味的に正しいです。
   - `supply-chain-audit` job 本体を触らない方針も適切です。

2. **施策 2: W12 / W15 の反転**
   - 判定: **REQUEST_CHANGES**
   - [Warning] W12 は `.github/workflows/ci.yml` だけを縛るため、別 workflow に `on.schedule` を追加する経路は止まりません。  
     修正案: 裁定が「GitHub Actions 全体に schedule を置かない」なら、`.github/workflows/*.yml` / `*.yaml` を走査して `on.schedule` 不在を固定するテストを追加してください。裁定が `ci.yml` 限定なら、その限定を設計書に明記してください。
   - `triggerNames` / `jobsWithCondition` の純関数設計、負のコントロール、W15 の「値でなく有無を見る」方針は良いです。

3. **施策 3: AGENTS.md の nightly 記述除去**
   - 判定: **APPROVE**
   - マーカー外の変更で `verification-commands-doc-sync` に不要な影響を与えない点も妥当です。

4. **施策 4: review-checklist §6 の書き換え**
   - 判定: **APPROVE**
   - 「失うもの」を明文化し、`continue-on-error` や schedule 復活を埋め合わせにしないと書く方針は、今回の裁定と整合しています。
   - [Suggestion] 「artisan コマンド + scheduler も CI の外ではない」という文は少し強いです。将来のオーナー裁定まで縛る意図がないなら、「本タスクでは扱わない」程度に弱める方がスコープが膨らみません。

5. **施策 5: EXEMPT 文字列から nightly を除去**
   - 判定: **APPROVE**
   - key を変えず理由文だけ更新するため、既存 V3 / V5 との整合は問題ありません。

**横断指摘**

- [Warning] 検証コマンド #6 の regex が `rg` では偽グリーンになります。  
  現行案:
  ```bash
  rg -n "^\s*schedule:\|github\.event_name" .github/workflows/ci.yml
  ```
  `rg` の正規表現では `\|` は alternation ではなく literal pipe 扱いになるため、`schedule:` も `github.event_name` も拾えません。  
  修正案:
  ```bash
  rg -n '(^\s*schedule:|github\.event_name)' .github/workflows/ci.yml
  ```

- [Warning] 検証 #8 は「変更対象 5 ファイルのみ」を保証できません。`bootstrap/` や別の `tests/`、`composer.*` などの意図しない差分を見逃します。  
  修正案:
  ```bash
  git diff --name-only --diff-filter=ACMRTUXB \
    | rg -v '^(\.github/workflows/ci\.yml|AGENTS\.md|docs/supply-chain/review-checklist\.md|tests/js/architecture/(ci-workflow-inventory|verification-commands-doc-sync)\.test\.ts)$'
  ```
  期待結果は 0 hit にしてください。

- [Warning] 負のコントロール実測の revert 手順に「`git checkout` で revert」とありますが、作業ツリー保護の観点で粗いです。  
  修正案: 専用 worktree 内で一時改変し、対象 hunk だけを `apply_patch` 等で戻す、または少なくとも実測前後に `git diff --name-only` で一時差分だけであることを確認する手順にしてください。

**全体判定: CHANGES_REQUESTED**

大筋は正しいです。特に W12/W15 の反転設計は裁定に沿っています。ただし、検証コマンド #6 の regex は実際に偽グリーンになるため修正必須です。加えて、schedule 禁止の射程が `ci.yml` 限定なのか GitHub Actions 全体なのかを設計上明確にしてください。