**レビュー前提**
仮説: 変更の核は「割り当て可能 shard は 4 に狭めるが、防御・残留検出は 8 まで維持する」こと。成功条件は、`scripts/bug-hunt-shard.sh` の実行経路が cap=4 で矛盾なく動き、散文 gate が防御面を誤って 4 に狭める圧力を作らないことです。

## 施策別判定

### 施策 1: cap の SSOT 化と割り当て範囲の 4 化
**REQUEST_CHANGES**

[Warning] `CAP` を Python heredoc に渡した manifest key regex が未検証です。  
`CAP="${BUGHUNT_SHARD_CAP}"` 自体は渡せていますが、`rf"[0-{os.environ['CAP']}]"` は cap が 2..9 の 1 桁である前提に依存します。self-test では bash 側だけ assert していますが、manifest parser 単体では守られていません。

修正案:
```python
cap = os.environ["CAP"]
if not re.fullmatch(r"[2-9]", cap):
    raise SystemExit(f"invalid BUGHUNT_SHARD_CAP for manifest parser: {cap!r}")
if re.fullmatch(rf"[0-{cap}]", key):
    print(key)
```

[Warning] `valid_parallel_n` を算術判定にした結果、将来 cap=6 に変更した瞬間 `6` が受理されます。self-test [r] で検出する方針はよいですが、実行時の error path が `valid_parallel_n` ではなく `stories_for_shard` の die 1 になります。これは「入力不正 = exit 2」という現行の期待からずれます。

修正案: 今回 cap=4 固定なら `valid_parallel_n` は算術判定でもよいですが、コメントを「cap を上げる場合は stories_for_shard 追加が同一変更必須」と明記し、Architecture test で受理集合と map 完全性を必ず固定してください。より堅くするなら `valid_parallel_n` は `2|4` のままにし、cap SSOT はテストで担保する方が実行時の曖昧さは減ります。

### 施策 2: self-test の cap=4 化
**REQUEST_CHANGES**

[Critical] `stories_for_shard` の完全性チェックが `set -e` 下で落ちる可能性があります。  
提案コードのここです。

```bash
[[ -n "$(stories_for_shard "${i}" "${n}")" ]] || t_fail ...
```

`stories_for_shard` が未定義なら `die 1` で subshell 内の command substitution が非ゼロ終了し、`set -e` により `t_fail` に到達せず self-test 全体が即死する可能性があります。失敗を「テスト失敗」として扱う設計になっていません。

修正案:
```bash
local stories rc
rc=0
stories="$(stories_for_shard "${i}" "${n}")" || rc=$?
[[ "${rc}" == 0 && -n "${stories}" ]] || t_fail "stories_for_shard 未定義: N=${n} shard=${i}"
```

[Warning] `BUGHUNT_DB_PREFIX` を self-test で既定値前提にしています。  
既存設計上 env override は残るため、外部環境に `BUGHUNT_DB_PREFIX` が入っていると `shard_db 0 == bug_hunt` が赤くなります。self-test が実資源に触れない安全テストである以上、環境依存は避けた方がよいです。

修正案: `cmd_self_test` の sandbox 初期化時に `BUGHUNT_DB_PREFIX=bug_hunt` を固定するか、期待値を `"${BUGHUNT_DB_PREFIX}"` から導出してください。

### 施策 3: 割り当て散文の 4 化
**APPROVE**

[Suggestion] `AGENTS.md` から regex 写経をやめて `DetectsBughuntDatabase` を正本として参照する判断は妥当です。ここを `1-4` に変えると守りの面を狭めるため、設計原則と整合しています。

[Suggestion] `findings.schema.json` の description のみ変更し、値制約を追加しない判断も妥当です。履歴 artifact の再検証を壊さないためです。

### 施策 4: 守りの面の据え置き + 理由の明文化
**APPROVE**

[Suggestion] 方針は一貫しています。`DEV_DB_DENYLIST` と `DetectsBughuntDatabase::BUGHUNT_DB_REGEX` を 8 のまま維持するのは、「過去残留 DB を守る / bughunt と検出する」という方向なので、割り当て allowlist と同期させない判断が正しいです。

[Suggestion] Browser lane guard の `8010..8018` 据え置きも妥当です。偽陽性に倒れる guard なので、cap より広く見ることはセキュリティ・非干渉の後退ではありません。

### 施策 5: 散文同期 gate の新設
**REQUEST_CHANGES**

[Critical] `CAP_ALLOCATION_DOCS` に `scripts/bug-hunt-shard.sh` を含めると、設計中のコメント自身が偽陽性になります。  
施策 1 のスクリプトコメントには意図的に以下が入ります。

- `tests/Support/Ci/TestDatabaseEnv::DEV_DB_DENYLIST`
- `DetectsBughuntDatabase`
- `cap <= 9`
- `2..9`

提案パターンの `/cap\s*=\s*[5-9]/` や `0-[5-9]` 系は、スクリプト内の説明・self-test 文言に当たり得ます。特に「cap <= 9」は `cap=9` ではないものの、近い表現が増えると壊れやすいです。

修正案: `scripts/bug-hunt-shard.sh` は散文 literal scan ではなく、専用構造テストだけに分けてください。例:

- `BUGHUNT_SHARD_CAP=4` が 1 箇所
- `SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"`
- `SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"`
- manifest parser が `CAP` を参照
- `6-*` / `8-*` case が存在しない

[Warning] `AGENTS.md` を `CAP_ALLOCATION_DOCS` に含めると、守りの理由説明が書きにくくなります。  
施策 3 で regex 写経を避ける方針はよいですが、AGENTS.md は「割り当て」と「守り」の両方を説明する規約文書です。今後「残留 `bug_hunt_5..8` を守る」のような正しい説明を書いただけで gate が赤くなる設計になります。

修正案: `AGENTS.md` 全体を scan するのではなく、bug-hunt 節のうち割り当てを説明する文だけを対象にするか、違反検出を「明示 allow comment 付きの defense 文脈は除外」にしてください。単純には AGENTS.md から cap 超過 literal を消す方針でもよいですが、その場合は「守りが広い理由」を別ファイルに逃がす設計にしてください。

[Warning] `BUGHUNT_DB_PREFIX` の regex injection 可能性は既存由来ですが、今回のコメントで「外から広げられない」趣旨を強めるなら不整合が残ります。  
`BUGHUNT_DB_PREFIX="${BUGHUNT_DB_PREFIX:-bug_hunt}"` を regex escape せず `SHARD_DB_RE` に埋め込んでいるため、環境変数に regex メタ文字が入ると allowlist が壊れます。

修正案: 今回スコープに入れないなら、コメントを「cap は env 上書き不可」に限定してください。守りを強めるなら `BUGHUNT_DB_PREFIX` を `^[a-z_][a-z0-9_]*$` で検証してから `SHARD_DB_RE` を組み立てるのが最小です。

## 既存テスト・PHPStan・副作用

既存テストへの波及判断は概ね正しいです。`BughuntEnvExampleContractTest` はコメント変更では赤くならず、`BughuntOrchestratorGateInvariantTest` も提示 needle 的には影響しません。`TestDatabaseEnvTest` の assert を維持する判断も正しいです。

PHPStan level 10 は、新規 Architecture テストの純関数に型を明示する方針なら問題ありません。ただし `file_get_contents()` narrowing と `preg_match()` の `false` 扱いは設計通り厳密に書く必要があります。

セキュリティ不変条件の後退は、施策 4 の据え置き方針により基本的にありません。ただし `BUGHUNT_DB_PREFIX` の扱いだけは、今回の説明文と実装がずれないように注意が必要です。

## 全体判定

**CHANGES_REQUESTED**

設計の方向性は正しいです。特に「割り当ては 4、防御は 8 維持」は一貫しています。  
ただし、self-test の `set -e` 下の失敗捕捉、Architecture gate の偽陽性設計、manifest parser 側の cap 検証は実装前に直した方がよいです。これらを修正すれば APPROVED に近い内容です。