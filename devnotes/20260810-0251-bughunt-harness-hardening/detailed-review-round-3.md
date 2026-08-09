## H-0: REQUEST_CHANGES

[Critical] 外部指定された `BUGHUNT_SANDBOX` の所有権と後始末契約が未定義です。

現行self-testが作成したsandboxをtrapで削除している場合、外部指定対応を単純に加えると次のどちらかになります。

- 外部指定ディレクトリもself-testが削除し、Pestが成果物を確認できない
- `BUGHUNT_SANDBOX=/` などの危険な値に対して再帰削除する可能性が生じる

修正案: self-testがsandboxを作成したかを明示的に記録し、自分で作成した場合だけ削除してください。

```bash
local sandbox_owned=0 sandbox

if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
    sandbox="${BUGHUNT_SANDBOX}"
    [[ -d "${sandbox}" ]] || die 1 "..."
else
    sandbox="$(mktemp -d)"
    sandbox_owned=1
fi

cleanup_self_test() {
    if [[ "${sandbox_owned}" == 1 ]]; then
        rm -rf -- "${sandbox}"
    fi
}
trap cleanup_self_test RETURN
```

外部指定パスをself-testが削除しないこともテストで固定してください。Pestの`finally`だけがそのディレクトリを削除する構造なら、成果物確認とも整合します。

## H-1: APPROVE

fail-closedの中核は必要な形で固定されています。

- `live > 0`、`unknown > 0`、集計不正、`kill -0`との矛盾を個別に拒否
- 連続2回の走査でzombie観測経路のrace窓を縮小
- TOCTOUを「証明」とせず「検出」と明記
- `parse_proc_stat_line`自体をfixtureで検証
- namerefと呼び出しごとの初期化でshard間の配列残留を防止
- `unknown`による可用性低下も明示

namerefについて、`local -a stopped_pgids=()`はbashでは関数スコープですが、ループごとに代入を伴って再初期化されるため問題ありません。

[Suggestion] `(y7h)` に残る「`group_live_members`相当」は、確定した関数名へ変更してください。

## H-2: APPROVE

cap導出と`SHARD_RE` / `SHARD_DB_RE`双方の実評価で十分です。

## H-3: REQUEST_CHANGES

[Warning] 「許可行は2行だけ」というinventoryは、提示された現行コードと一致しません。

少なくとも次の非コメント行にもliteralが存在します。

```bash
local op=$1 db=$2   # op ∈ {createdb, dropdb}
|| die 1 "... (createdb/dropdb には admin role 明示必須)"
```

「行頭がコメントの行だけ除外し、残った行に単語境界のliteralがあれば赤」という仕様では、inline commentやエラーメッセージも検出されます。したがって、許可2行だけでは新規Architectureテスト自身が赤になります。

修正案はどちらかです。

1. inline commentと文字列も含め、現在存在するliteral行を全件抽出し、理由付きallowlistへ登録する。
2. bashの字句解析なしに文字列除外を試みず、「literalの存在を保守的に検出する」方針を維持して、非実行literalも明示的にallowlistする。

後者が現在の設計に合っています。許可する2つの実行配列行については「必ず各1行存在する」、説明文字列等については「存在してもよい行」と役割を分けて検査してください。

保証をliteral検出に限定した点自体は適切です。

## H-4: APPROVE

`install -m 600`、source専用guard、引数化、位置引数による契約テストでRound 1の問題は解消されています。

## 実装計画: APPROVE

破壊操作について判断主体を正しく分離できています。

- エージェント自動実行はself-testと非破壊dry-runまで
- 実DBのend-to-endはユーザーの明示承認後またはユーザー実施
- 未実施の場合は実装レポートに明記

禁止事項3との整合性は取れています。

## 全体判定: CHANGES_REQUESTED

H-1のfail-closed設計、nameref、パーサ分離、H-4、破壊操作の扱いは承認できます。残る修正は2点です。

1. 外部指定sandboxをself-testが削除しない所有権契約を固定する
2. raw `dropdb` / `createdb` inventoryを、既存のinline comment・文字列を含む実際のliteral全件と整合させる

この2点を反映すれば、設計として承認可能です。