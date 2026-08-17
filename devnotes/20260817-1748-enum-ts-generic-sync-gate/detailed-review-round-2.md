# 全体判定: CHANGES_REQUESTED

Round 1 の Critical はすべて適切に解消されています。方向性も妥当です。ただし、まだ実装仕様または回帰テストに残る Warning が4点あります。

## 施策 A: 型情報で読む抽出基盤

判定: REQUEST_CHANGES

### [Warning] PHP の引用符終了判定がバックスラッシュの偶奇を扱っていない

状態表の「直前が `\` でない引用符を終端とする」では不十分です。引用符がエスケープされるかは、直前に連続するバックスラッシュが奇数か偶数かで決まります。

例えば、引用符直前に `\\` がある場合、その引用符は終端ですが、現仕様では終端ではないと誤認します。位置走査が崩れ、後続 case を誤って文字列内として扱う可能性があります。

修正案:

- 引用符直前に連続する `\` の数を数え、偶数なら終端、奇数なら文字列内とする
- 単一引用符・二重引用符の双方で固定する
- 次を別々のテストケースとして追加する

  - `\'` / `\"` の奇数本
  - `\\'` / `\\"` の偶数本
  - 3本以上の連続したバックスラッシュ

受理正規表現はバックスラッシュを拒否するままで構いません。字句走査が正しく宣言範囲を確定してから、限定文法として例外にする必要があります。

### [Warning] `?>` は行注釈内でも PHP の閉じタグとして働く

PHP では `//` または `#` の行注釈中にある `?>` が PHP モードを終了させます。現設計は「コード状態の `?>` だけを例外」としているため、次の形を見逃します。

```php
// ?> HTML が続く
```

修正案:

- 単一・二重引用符とブロック注釈内の `?>` は無視する
- コード状態に加え、行注釈状態の `?>` も閉じタグとして検出し例外にする
- `// ?>` と `# ?>` の負例を追加する
- EOF の行注釈は有効な終端として扱うか、意図的に拒否するかを明記する。未終端エラーは少なくとも文字列とブロック注釈について個別に定義する

末尾のリスク記述にある「受理範囲外は例外なので静かに間違えない」も、次の限定表現へ揃えてください。

> 抽出対象として認識した enum 宣言・case 宣言・禁止した字句状態については、受理するか理由付き例外になる。

ファイル全体についての主張にはしない方が正確です。

## 施策 B: 汎用 gate 本体

判定: REQUEST_CHANGES

### [Warning] inventory path を検証する前に Program がファイルを使用している

パス検査は `it("目録の行の体裁…")` で行われますが、`beforeAll` の `createMirrorProgram()` が先に inventory path を解決して Program へ渡します。

したがって traversal や symlink containment を「使用前の安全境界」としては保証できません。最終的にテストは赤くなっても、検査対象外のファイルを既に読んだ後です。

修正案:

- `validateMirrors()` を純粋な共通関数として切り出す
- `beforeAll` の冒頭で `validateMirrors(ENUM_TS_MIRRORS)` を実行してから Program を作る
- 体裁を示す個別テストも同じ関数を呼び、実装を二重化しない
- containment は単純な `startsWith(root)` ではなく、`root + path.sep` との境界を検査する
- `realpath(ts) + declaration` でも一意性を検査し、symlink 経由で同じ宣言を二重登録できないようにする

## 施策 C: 抽出器の自己検査

判定: REQUEST_CHANGES

### [Warning] T25 が縮小 Program への回帰を本当に検出できる配置になっていない

fixture ディレクトリ全体を `tsconfig.json` の `exclude` に入れるため、T25 の augmentation/helper も `parsed.fileNames` に入りません。

一方、helper を `createMirrorProgram` の `tsFiles` へ明示的に渡すと、縮小 Program に戻しても helper が root に残ります。これでは「`parsed.fileNames` を捨てると値が減る」という回帰を検出できません。

修正案:

- T25 専用の有効な ambient/augmentation fixture を、`tsconfig` に含まれる別ディレクトリへ置く
- target はその helper を import しない
- full Program では helper が `parsed.fileNames` 経由で参加し、値が増えることを確認する
- inventory roots だけの Program では helper が載らず、値が不足することも対照として確認する

例としては次の構成が適しています。

```text
program-fixtures/
  registry-base.ts
  registry-augmentation.ts   # tsconfig に含まれるが target から import されない
fixtures/
  t25-target.ts              # 明示 root。Registry[keyof Registry] を読む
```

`registry-augmentation.ts` は正常な TS とし、`pnpm typecheck` に含まれても問題ない内容にします。

また、T10 の `@/*` は現在 `resources/js/*` を指すため、fixture ディレクトリ内の helper には解決されません。次のどちらかへ分けてください。

- fixture 間参照は相対 import で検査する
- `@/*` は実在する `resources/js` の型を使った別の統合テストで固定する

fixture 専用に `paths` を差し替えるだけでは、リポジトリ本来の alias 解決を検証したことになりません。

### [Warning] P25 の複合ケースは個別の字句状態を固定できない

「未終端の文字列 / 未終端のブロック注釈」を1行として pin すると、一方しか実装されていなくてもテスト構成次第で見逃します。同一 source 内に両方置くと、先に開始した未終端状態が後者を覆い隠します。

修正案:

- 未終端の単一引用符
- 未終端の二重引用符
- 未終端のブロック注釈

を独立した test case に分け、pin は概念行数ではなく実際の parameter case 数に合わせてください。前述のバックスラッシュ偶奇と行注釈中の `?>` も同じ粒度で追加します。

## 施策 D: 旧実装の撤去と参照の是正

判定: APPROVE

値集合の移設と、PHP の構文・autoload 等を別レーンへ明示的に残す整理は妥当です。14件の比較と4件の自己検査を分けた対応表、重複 backing value の保証維持、参照残骸の探索範囲も十分です。

## 施策 E: 母集団の拡張

判定: APPROVE

27組への拡張、部分集合を登録しない判断、未登録理由を「免除 inventory」と呼ばない整理はいずれも妥当です。

## 施策 F: 規約・文書・テンプレート差分

判定: APPROVE

D27 を `監視中` として登録し、後半実装を再判定条件にする判断は台帳の原則に合っています。正本を `docs/architecture.md` に一本化した点も妥当です。

実装時には「実装日」「実装日 + 180日」を式のまま書かず、台帳が要求する具体的な日付形式へ確定してください。

Critical は残っていません。上記 Warning、特に PHP の引用符走査と T25 の配置を修正すれば、設計として APPROVED にできます。