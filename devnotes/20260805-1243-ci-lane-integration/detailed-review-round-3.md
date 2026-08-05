全体判定: **CHANGES_REQUESTED**

Round 2 の指摘は解消されていますが、施策4Aに取得失敗を通す経路が1つ、施策10にW14の保証と実装方式の不一致が残っています。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1 | APPROVE |
| 2 | APPROVE |
| 3 | APPROVE |
| 4A | REQUEST_CHANGES |
| 4B | APPROVE |
| 5 | APPROVE（4A修正が前提） |
| 6 | APPROVE |
| 7 | APPROVE |
| 8 | APPROVE |
| 9 | REQUEST_CHANGES |
| 10 | REQUEST_CHANGES |
| 11 | APPROVE |

## 指摘

### 施策4A

[Critical] `uv export` の非ゼロ終了を無視してはいけません。

auditコマンドの非ゼロには「脆弱性検出」という正常系がありますが、`uv export` の非ゼロは取得失敗です。共通の`acquire()`を使うと、`uv export`が部分的またはコメントだけの非空出力を残して失敗した場合、そのままpip-auditへ進みます。

修正案: 取得関数を契約別に分けてください。

- `acquireAudit`: 非空出力を要求し、非ゼロexitを許容
- `acquireRequired`: 非空出力かつexit 0を要求
- `uv export`は`acquireRequired`
- pnpm/composer/pip-auditは`acquireAudit`

A7に「非空出力＋exit 1」も追加してください。現在のA7「空出力」だけではこの経路を検出できません。

[Warning] top-levelコンテナだけの検査では、内部schema不整合が0件へ落ちる経路が残ります。

例:

```json
{"advisories":{"vendor/package":{"error":"unavailable"}}}
```

Composer normalizerは値がarrayでなければ黙って無視します。pipでも`{"dependencies":[{}]}`、pnpmでもprimitiveのentryなどが同種の問題になります。

修正案: 未知フィールドは許容しつつ、normalizerが走査に必要とする最低限の構造を検証してください。

- Composer: 各package値がarray
- pnpm: 各advisory entryが非null object
- pip: 各dependencyがobjectで、`name`がstring、`vulns`がarray

空のコンテナや空の`vulns`は正当な0件として許可できます。

[Warning] `source`と`normalizer`を別引数にすると誤配線が型上可能です。

pnpmとComposerはどちらもobject形式の`advisories`を持ち得るため、shape検査だけではnormalizerの取り違えを常に検出できません。

修正案: `loadAuditJson(path, source)`とし、sourceからnormalizerを内部選択してください。これで誤対応そのものを表現不能にできます。

[Warning] A7/A8には`bin/uv`スタブが必要ですが、sandbox構成に記載されていません。

修正案: sandbox一覧とスタブの責務に`uv export`／`uv tool run ... pip-audit`の分岐を明記してください。

### 施策9

[Warning] 設計文に矛盾が残っています。

定数には`README.md`が1件ありますが、直後に「明示 exemption は初期値ゼロ」とあります。またS1の表も「直下＋`scripts/ci/`」のままで、本文の再帰走査と一致しません。

修正案:

- S1を「`scripts/`配下の全ファイル（明示exemptionを除く）」へ変更
- 「初期値ゼロ」を「初期状態では`README.md`の1件のみ」へ変更
- exemptionのパス実在に加えて、理由文字列が非空であることも検査

実装方針自体は妥当で、初期赤と死んだexemptionは防げています。

### 施策10

[Warning] W14はlocal/composite actionを塞ぎますが、通常の`run`経由を塞いでいません。

例えば次はW9/W14を通過できます。

```yaml
- run: bash scripts/prepare-browser-ci.sh
- run: composer test:browser
```

`prepare-browser-ci.sh`が`$GITHUB_ENV`へレーン変数を書けば、W9の射程外です。したがって「browser-testsではci.ymlの範囲がjob全体と一致する」という主張は成立しません。

修正案: 次のどちらかが必要です。

- `browser-tests`の`run` stepも許可コマンドの構造的inventoryで固定する
- W14の保証を「composite/reusable action経由を防ぐ」に限定し、local script経由は保証外と明記する

deny-by-default方針なら前者が適切です。

[Warning] `runScript(job).includes("composer test:browser")`では「直接実行」を保証できません。

以下も通過します。

```yaml
run: echo "composer test:browser"
```

修正案: W14では対象stepの`run.trim()`が`composer test:browser`と完全一致することを要求してください。少なくとも独立した実行行として解析し、`echo`、シェル演算子、環境変数付与を拒否する必要があります。

4 actionのallowlist自体は現在のjob構成に対して過不足ありません。ただし、それらaction内部まで静的に保証するものではないため、「既知の信頼済みsetup action」として境界を明記するのが正確です。

## 確認結果

施策6の`mutate()`は、対象件数・変更発生・期待トークンを検証しており、負のコントロールの空振りを防げています。

施策1/2/3/4B/5/7/8/11には今回の修正による新たな後退はありません。dev DB保護、T099、CI secret不在も維持されています。