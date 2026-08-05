## 施策 1: REQUEST_CHANGES

[Warning] `BUGHUNT_DB_PREFIX` の検証位置と Architecture テストの契約が矛盾しています。

設計では `SHARD_DB_RE` を先に組み立て、その後 `die()` 定義直後で prefix を検証しています。一方、施策 5 は「`SHARD_DB_RE` 埋め込み前に形式検証」としています。この構造テストは変更後コードを不合格にするか、実態を検査しない弱いテストになります。

修正案: `die()` 定義後に prefix 検証と `SHARD_DB_RE` の代入を続けて配置してください。

```bash
die() { local code=$1; shift; echo "error: $*" >&2; exit "${code}"; }

[[ "${BUGHUNT_DB_PREFIX}" =~ ^[a-z][a-z0-9_]*$ ]] \
    || die 1 "BUGHUNT_DB_PREFIX が不正: '${BUGHUNT_DB_PREFIX}'"

SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
```

これなら `die` の定義順、検証前利用、Architecture テストの契約がすべて一致します。既定値と既存の英小文字・数字・underscore prefix は維持され、変更範囲も小さいです。

`valid_parallel_n` を算術判定のまま残す反論は妥当です。cap と map の同時更新を Architecture テストと self-test の二重で強制するなら、リリース前に閉じられる不整合です。

manifest parser の CAP 検証も適切です。

## 施策 2: APPROVE

Round 1 Critical の `set -e` 問題は閉じています。

```bash
stories="$(stories_for_shard ...)" || srv_rc=$?
```

は `||` リスト内なので、command substitution が非ゼロでも `set -e` による即時終了を回避し、`t_fail` まで到達できます。

不正 prefix の再帰 self-test も、不正値が `cmd_self_test` 到達前のトップレベル検証で終了するため無限再帰にはなりません。施策1の修正どおり検証後に `SHARD_DB_RE` を構築すれば、self-test 内の再導出も整合します。

## 施策 3: REQUEST_CHANGES

[Warning] 散文検出にはまだ偽陰性があります。

現在の `/\b2\/4\/6\/8\b/` は旧表記そのものしか検出しません。例えば次は cap=4 に反する割り当て記述ですが通過します。

```text
--parallel は 2/4/6
N=6 を利用する
parallel=8
```

修正案: 数字一般を無差別に検出せず、`parallel`、`shard`、`N=`などの文脈に限定して cap 超過値を検出してください。少なくとも `2/4/6` と `--parallel=8` の負のコントロールを追加します。

既知の現行 `cap=8` 残留を洗い出す目的は満たしますが、「再び腐るのを deny-by-default で止める」という恒久契約には現状不足です。

## 施策 4: APPROVE

「触れる対象は4へ狭め、守る対象は8のまま維持する」という適用は一貫しています。

`DEV_DB_DENYLIST`、`DetectsBughuntDatabase`、browser pre-flight guard を狭めない判断も正しいです。既存 `TestDatabaseEnvTest` のアサーションを維持するため、セキュリティ不変条件の後退もありません。

## 施策 5: REQUEST_CHANGES

Round 1 Critical のスクリプト自己参照による偽陽性は、散文走査から外して構造検査へ分離したことで閉じています。

[Warning] `cap-defense-ok` は現状、無制限の gate bypass です。

マーカーを付ければ、割り当ての誤記でも完全に黙らせられます。

```text
--parallel は 2/4/6/8  # cap-defense-ok
```

「レビュー時の目視対象」だけでは deny-by-default の機械保証になりません。

修正案: マーカーを許可するファイルを明示 allowlist 化し、allowlist 外のマーカーを違反にしてください。さらに次の負のコントロールを追加します。

- 許可外ファイルの `cap-defense-ok` を検出する
- `--parallel` や「割り当て」を含む行では、マーカー付きでも違反にする
- 守りを示す `denylist` / `guard` / `残留` 等の文脈がないマーカーを検出する

完全な自然言語判定は不要です。マーカーの配置面と明白な割り当て文脈だけ閉じれば、この規模に収まります。

[Warning] defense surface テストの判定内容を具体化してください。

「cap より広い」「除外集合が空でない」というテスト名だけでは、各ファイルの値が本当に8まで維持される保証が不明です。少なくとも以下を直接固定する必要があります。

- denylist が `bug_hunt_5`〜`bug_hunt_8` を保持
- seeder regex が `[1-8]` を保持
- browser guard が `8018` まで保持

既存テストで保証済みの面は、そのテストとの役割分担を明記すれば重複実装は不要です。

## 全体判定: CHANGES_REQUESTED

Round 1のCritical 2件は閉じています。残件は、prefix検証とregex構築の順序統一、`cap-defense-ok` の濫用防止、散文パターンの明確な偽陰性補完です。いずれも局所修正で、設計の中心方針やスコープを変更するものではありません。