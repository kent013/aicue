## 全体判定: CHANGES_REQUESTED

Round 1 の主要な矛盾は解消されています。特に `ReferenceScanResult::$imports`、facade の canonical 化、mutation の分離は妥当です。

ただし、既定拒否 gate の分類単位と委譲先テストの同定に、まだ保証をすり抜けられる箇所があります。

### S1: REQUEST_CHANGES

[Warning] `ExternalClientBoundaryScanner` の public API 維持が不明確です。

`phpFiles()` を `PhpReferenceScanner` へ移設するとしつつ、S3 の委譲では引き続き次を呼んでいます。

```php
ExternalClientBoundaryScanner::phpFiles(...)
```

「public API は不変」を成立させるには、旧クラスに委譲ラッパーを残す必要があります。

修正案:

```php
public static function phpFiles(string $absoluteRoot, string $relativeRoot): array
{
    return PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot);
}
```

`boundarySites()` を含め、既存 public method の維持一覧もS1に明記してください。

それ以外の `ReferenceScanResult` 導入、emission 契約、部分修飾名を今回直さない判断は妥当です。

### S2: APPROVE

`use` を site にせず metadata として保持する設計、facade を `NameReference` のみに正規化する設計は整合しています。

[Suggestion] リスク節に残っている「facade は `StaticCall` の receiver 経由でも拾う」を削除してください。現在の canonical 契約と逆であり、実装者を誤誘導します。

### S3: APPROVE

語彙を本番側、目録をテスト側に置く分担は既存規約と整合しています。`Exempt` を現時点で使用禁止にする判断も、空虚な gate を作らないという目的に合っています。

### S4: APPROVE

移設理由、相対パス、既存テストを回帰証拠にする方針に問題はありません。

### S5: REQUEST_CHANGES

[Critical] 対称差の比較単位が「クラス」だけでは分類の完全性を保証できません。

現在のテスト1はクラス集合だけを比較します。そのため、同一クラスに複数種類の外部到達がある場合、次が曖昧になります。

- 同じクラスに `Payment` と `Mail` の両方がある
- 同じクラスの entry を複数 kind で登録する
- 同じ `(class, kind)` entry を重複登録する
- 走査と対応しない追加 entry が、既存クラスと同名なので stale 判定をすり抜ける

テスト4も「そのクラスの登録 kind」を単数として扱っており、複数 entry がある場合の選択規則がありません。

修正案:

- 目録の識別単位を `(class, kind)` にする
- 各 site に対し、同じ class かつ許可された kind の entry がちょうど1件あることを検査する
- 各 entry に対応する site が1件以上あることを逆方向に検査する
- `(class, kind)` の重複を禁止する
- 同一クラスへの複数 kind は、別の到達事実であれば許可する

これにより、現在の「クラス集合の対称差」を「分類済み到達集合の双方向照合」へ引き上げられます。

[Critical] 委譲先 test 名の「文字列が含まれる」検査は、test の実在を保証しません。

test を改名しても、旧名称がコメントや別の文字列リテラルに残ればテスト12は緑になります。「test 名が実在する」という保証より弱い状態です。

修正案:

- PHP token から `test(...)` / `it(...)` の第1引数である文字列を抽出する小さな scanner を用意する
- 抽出した test 名集合に `gateTestName` が完全一致することを検査する
- 最低限、コメントを除去した token 列上で `T_STRING(test|it)`、`(`、`T_CONSTANT_ENCAPSED_STRING` の並びを検査する
- 合成ソースで「コメントにだけ名前がある場合は失敗する」負のコントロールを追加する

[Warning] M3 の期待結果にテスト10が含まれるのは誤りです。

`FACADE_RULES=[]` にしても `entries()` は残るため、テスト10の「目録か委譲で覆われる」は引き続き成立します。赤になるのはテスト1とテスト7です。

修正案: M3 の期待する赤からテスト10を削除してください。

[Warning] mutation の採番と分類が不整合です。

- 実装順序は「M1〜M13」ですが、現在はM1〜M12です
- P3は「赤にならないことを確認する mutation」節にありますが、期待結果はS6 #6が赤です

修正案:

- 実装順序をM1〜M12へ直す
- P1/P2を「等価変形」
- P3を通常の赤化 mutation、または「規則強化の負のコントロール」として別分類する

### S6: REQUEST_CHANGES

S5の分類単位修正に対応するテストが必要です。

[Warning] 次のケースを追加してください。

- 同一クラスに `Http::...` と `Mail::...` がある場合、2種類のsiteが得られる
- 同一 `(class, kind)` entry の重複をgateが拒否する
- 同一クラスに異なるkindを登録した場合、それぞれ対応するsiteが必要になる

既存の19ケースはscanner自体の検証として十分に具体的です。

### S7: APPROVE

fake有効時、flag off時、secret未設定時の3方向が揃っており、負のコントロールも実際にHTTP経路が成立する条件を検査しています。環境復元も既存作法に沿っています。

### S8: REQUEST_CHANGES

[Warning] 「保証しないものを3箇所に同じ内容を書く」という方針と、提示されたAGENTS.md追記案が一致していません。

AGENTS.md案では、以下が省略されています。

- `.env.bughunt.local`
- 決済の別API表面
- 部分修飾名
- 他種別の宛先集合の詳細

修正案: 完全な同文複製はドリフトしやすいため、`docs/architecture.md`を詳細の正本にし、gateとAGENTS.mdには要約と正本への参照を書く契約へ変更してください。「3箇所に同じ内容」とは記載しない方が誠実です。

DTO/JsonResource、Inertia Props、TypeScript、DESIGN.md、Atomic Designについては、本設計に該当するHTTP/UI変更がないため問題ありません。