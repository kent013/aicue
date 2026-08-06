## 施策別判定

### 施策 1: REQUEST_CHANGES

[Warning] `ci.yml` の機械的歯止めの説明が W12/W15 のみで、別 workflow への再導入を防ぐ W17 が記載されていません。

修正案:

```yaml
# 4. 機械的な歯止め: ...
#    W12 (ci.yml のトリガー集合)、W15 (job-level if の不在)、
#    W17 (全 workflow の schedule 不在) が再導入を止める。
```

YAML本体の変更内容は妥当です。

### 施策 2: APPROVE

W17はスコープ膨張ではなく、裁定をGitHub Actions全体で成立させるための最小追加です。W12との責務分離も明確です。

`workflowsWithSchedule`についても、

- workflow 0件はW17本体の件数assertionで失敗
- YAML parse失敗は例外としてテストが失敗
- `schedule`検出はW12と同じ`triggerNames`を利用
- N4で別workflowだけの再導入を実測

となっており、主要な偽グリーン経路は閉じています。

[Suggestion] 戻り値を入力順に依存させたくなければ、ファイル名を`.sort()`して返すと診断結果が安定します。現状でも`readdirSync`の順序とfixtureが安定しているため、承認を妨げる問題ではありません。

### 施策 3: APPROVE

AGENTS.mdの変更内容とverification markerへの影響評価は妥当です。

### 施策 4: REQUEST_CHANGES

[Warning] §6の機械ゲート説明もW12/W15のみで、全workflowを対象とするW17が抜けています。この記述では「別workflowも機械的に阻止する」という実装契約が文書化されません。

修正案:

```markdown
`tests/js/architecture/ci-workflow-inventory.test.ts` の
W12 (ci.yml のトリガー集合の完全一致)、W15 (job-level `if` の不在)、
W17 (全 workflow の `schedule` 不在) が再導入を機械的に止める。
```

将来の代替方式をオーナー裁量へ戻した修正は適切です。

### 施策 5: APPROVE

免除理由の更新は既存ゲートとの整合性を保っています。

## 横断指摘

[Warning] 検証コマンド表の#6には、修正前の誤った正規表現がまだ残っています。

現在の表:

```bash
rg -n '(^\s*schedule:\|github\.event_name)' .github/workflows/ci.yml
```

修正案:

```bash
rg -n '(^\s*schedule:|github\.event_name)' .github/workflows/ci.yml
```

直後の説明文は正しいため、表だけの転記ミスです。

[Warning] #8の`git diff --name-only`はuntrackedファイルを検出しません。新規workflowを誤って残した場合も0 hitになり得ます。

修正案:

```bash
{
  git diff --name-only
  git ls-files --others --exclude-standard
} | sort -u \
  | rg -v '^(\.github/workflows/ci\.yml|AGENTS\.md|docs/supply-chain/review-checklist\.md|tests/js/architecture/(ci-workflow-inventory|verification-commands-doc-sync)\.test\.ts)$'
```

期待結果は0 hitです。

[Warning] N1〜N3での`git checkout -- <path>`は、一時改変だけでなく、未コミットの本実装もまとめて破棄します。またN4のuntracked workflowは`git checkout`では削除できません。

修正案は、一時改変前に対象ファイルを退避し、各実測後にその内容を復元して差分一致を確認することです。または本実装を一旦コミットしてから実測し、最後にamendする手順を明記してください。N4には一時ファイル専用の明示的な削除・不存在確認が必要です。

## 全体判定

**CHANGES_REQUESTED**

Criticalはありません。W17自体の設計は承認できます。残件は、W17の文書反映、#6の転記ミス、untrackedを含む変更ファイル検査、負のコントロール後の復元手順です。