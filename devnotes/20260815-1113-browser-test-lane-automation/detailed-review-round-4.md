## 施策別判定

### 施策 1: APPROVE

依存分類、権限観測、配列展開、異常終了時のfail-closedに問題ありません。

[Suggestion] 将来のPlaywrightが`playwright install`内部で特権取得方法を変更した場合、「`sudo -n`しか使わないので待たない」とは保証できません。これは現在の実装には影響しませんが、リスク記述は「pinされた現行版で保証する」に限定すると正確です。

### 施策 2: REQUEST_CHANGES

[Warning] `cp -R`失敗時の分岐に対応する動的契約がありません。

C14は`mkdir -p`失敗を検査しますが、次の別分岐は未実行です。

```bash
if ! cp -R ...; then
    echo "WARNING: ..."
fi
```

この分岐が将来削除・破損しても、現在のテスト計画は緑になります。「退避先作成と複製の両方が合否を上書きしない」という不変条件を完全には固定できません。

修正案: C14を2ケースに分けてください。

- C14a: `mkdir -p`失敗、pestの`exit 23`を維持
- C14b: sandboxの`PATH`に条件付き`cp`スタブを置いて退避時だけ非ゼロを返し、WARNINGと`exit 23`維持を確認

権限変更による`cp`失敗fixtureはroot環境で成立しない可能性があるため、既存のsandbox方針に合わせたスタブが安定します。

### 施策 3: APPROVE

キャッシュ、導入、Browserレーン、失敗時回収の構成は整合しています。

### 施策 4: APPROVE

空白差分とshell行継続の両方が閉じられています。正規化関数自体の負のコントロールも含まれており、W20の保証として十分です。

### 施策 5: APPROVE

再帰走査、JSON scriptsの型正規化、shell・Dockerfileの行継続、コメント除外まで母集団と検出規則が一致しています。PHPStan level 10に対するnarrow方針も明確です。

### 施策 6: REQUEST_CHANGES

[Warning] 実際に追加する`tests/js/support/shell-contract.test.ts`が変更ファイル一覧から漏れています。

本文では既存ヘルパテストをこのファイルへ移すとしていますが、以下には記載されていません。

- 施策一覧の施策6
- 施策6「変更箇所」
- 施策6「波及変更」

修正案: 施策6の変更ファイルを次の4ファイルとして明記してください。

```text
tests/js/support/shell-contract.ts
tests/js/support/shell-contract.test.ts
scripts/setup-browser-testing.contract.test.ts
scripts/run-browser-test.contract.test.ts
```

また、C14をC14a/C14bへ分ける場合は、テストID一覧と実装順序も同期してください。

### 施策 7: APPROVE

README台帳、`.gitignore`の機械検査、docsの非保証範囲が正しく整理されています。

## 全体判定

**CHANGES_REQUESTED**

実装ロジック上の重大な問題はなく、新たなCriticalもありません。残件は、`cp`失敗分岐の動的契約追加と、`shell-contract.test.ts`の波及ファイル登録です。この2点を反映すれば詳細設計は承認可能です。