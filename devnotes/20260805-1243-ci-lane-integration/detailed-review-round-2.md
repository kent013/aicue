全体判定: **CHANGES_REQUESTED**

Round 1 の主要な穴は概ね塞がれています。ただし、施策 4A と施策 9 に偽グリーン／確実な初期失敗につながる問題が残っています。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1 | APPROVE |
| 2 | APPROVE |
| 3 | APPROVE |
| 4A | REQUEST_CHANGES |
| 4B | APPROVE |
| 5 | APPROVE（4A 修正が前提） |
| 6 | REQUEST_CHANGES |
| 7 | APPROVE |
| 8 | APPROVE |
| 9 | REQUEST_CHANGES |
| 10 | REQUEST_CHANGES |
| 11 | APPROVE |

## 指摘

### 施策 4A

[Critical] `assertAuditSourceShape()` が Composer の `advisories: []` を受理します。

提示コードは pnpm と Composerを同じ条件で検査しています。

```ts
typeof advisories === "object"
```

そのため、Composer の schema ではない次の入力が通過し、normalizer が advisory 0 件へ落とします。

```json
{"advisories":[]}
```

修正案: source ごとに条件を分けてください。

- pnpm: object または array
- Composer: `null`・array ではない object
- pip: array

Composer向けに `{"advisories":[]}` が throw する負のテストも追加してください。

[Warning] `STDERR_LOG` の生成と cleanup が設計コードにありません。

`acquire()` は `2>>"${STDERR_LOG}"` を使いますが、提示された変更後構成には `STDERR_LOG="$(mktemp)"` と trap 登録がありません。このまま実装すると `set -u` で最初の取得前に終了します。

修正案: 一時ファイル生成と trap を変更後コードへ明記してください。取得ごとにログを truncate すると、Composer失敗時に pnpm の古い stderr が混ざることも防げます。

[Warning] shape 検証の「関数単体」はテストされますが、`loadAuditJson()` への配線は保証されません。

A3 は `pnpm exec tsx` をスタブが受け止めるため、実際には JSON parse や shape 検証を実行しません。実装者が `assertAuditSourceShape()` を export しただけで呼び忘れてもテストが通る可能性があります。

修正案: 一時 JSON ファイルを使い、以下を `loadAuditJson()` 経由でテストしてください。

- 不正 JSONで失敗
- `{"error": ...}` で失敗
- 正常な空コンテナで成功
- source と normalizer の誤対応を検出

[Warning] pip 取得経路が contract test の対象外です。

「pip-audit も同じ `acquire` を通す」という新しい契約を設ける以上、`pyproject.toml` がある sandbox で空出力を拒否するシナリオが必要です。また、先行する `uv export` の空出力／失敗も advisory 0 件として扱わないことを固定してください。

A4/A5 を「止まらないこと」の対照ケースにする判断自体は妥当です。非ゼロ exit では取得失敗と脆弱性検出を区別できないため、非空出力を shell 境界、JSON・schema を TypeScript 境界にする分担も適切です。

`4A → 4B → 5` の順序も妥当です。

### 施策 6

[Warning] 負のコントロールの文字列置換が成功した保証を追加してください。

置換対象が将来少し変わると、改変されていないコピーを「broken fixture」として実行する可能性があります。

修正案:

- 置換前の対象出現数を1件と assert
- 置換後ソースが元ソースと異なることを assert
- 置換後に意図したトークンが存在することを assert

また、テスト計画の「負のコントロール4本」は本文の実走3本＋静的4本、計7本と一致していません。件数表記を修正してください。

層ごとの責務分離、`GLOBAL_TEST_LOCK_DIR` のテストハーネスからの注入、C5～C7を静的検査に置く判断は妥当です。

### 施策 9

[Critical] 再帰走査すると `scripts/README.md` 自身が未登録ファイルになります。

走査対象を「`scripts/` 配下の全ファイル」とし、exemption を空にすると、台帳自身もS1対象です。README表に `README.md` 行がなければ新規テストは初期状態から赤になります。

修正案: 「恒久スクリプト」の定義をコード化してください。少なくとも以下のいずれかが必要です。

- `README.md` を理由付き exemption に登録する
- 走査対象を実行物・ソース・テストの拡張子へ限定する
- README自身を表へ登録する

最も素直なのは `README.md` を「台帳そのものであり台帳登録対象外」と明示 exemption に入れる方法です。再帰走査への変更自体は適切です。

### 施策 10

[Warning] W9 の保証範囲を明示する必要があります。

値走査により、直接記述された以下は塞がれています。

- job/step `env`
- inline assignment
- `export`
- 複数行 `run`

一方、次は静的な scalar 検査では保証できません。

- local composite action が `$GITHUB_ENV` へ設定
- 呼び出したスクリプトが環境変数を設定
- reusable workflow 内の設定
- action内部での動的設定

現在のworkflowでは `composer test:browser` が直接実行されるため、直ちに問題ではありません。ただし「CI実行時に絶対に上書きされない」という保証ではありません。

修正案: 本バッチでは次のどちらかにしてください。

- `browser-tests.steps[*].uses` を既知の setup action allowlist に固定し、`composer test:browser` は直接runする契約を追加する
- W9の保証範囲を「ci.ymlに直接記述されたキー・scalar値」に限定し、composite/reusable workflow導入時はinventory更新が必要と明記する

前者の方が deny-by-default という設計方針と整合します。

## その他

施策8の再帰防止条件は十分明確です。module top-level／`describe` callbackと、実行時hookの違いが正しく記述されています。

施策1の `DB_DATABASE` 不在、bootstrap単一点ガード、接続情報だけをjob envへ置く構成にも後退はありません。CI secretも追加されていません。

DTO/JsonResource、Inertia Props、DESIGN.md、Atomic Designは引き続き該当なしです。