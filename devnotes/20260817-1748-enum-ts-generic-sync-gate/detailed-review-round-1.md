# 全体判定: CHANGES_REQUESTED

型情報による抽出、単一目録への集約、14 組から 27 組への拡張という方向性は妥当です。ただし現設計には、値域の不一致を静かに見逃し得る穴が少なくとも 2 つあります。

- TypeScript の `Program` が実際の `tsconfig` と異なる型世界を構築する
- PHP の大文字小文字を区別しないキーワードや字句状態を十分に扱えていない

このまま旧 PHP テストを削除すると、「より強い gate への同値な移設」とはまだ言えません。

## 施策 A: 型情報で読む抽出基盤

判定: REQUEST_CHANGES

### [Critical] 目録ファイルだけを root にした `Program` は、実際の型世界と一致しない

import 先は再帰的に読み込まれますが、import されていない次のファイルは載りません。

- `tsconfig.json` の `include` だけで参加する ambient `.d.ts`
- `declare global`
- module augmentation
- registry interface の追加宣言
- project reference 経由の宣言

例えば別ファイルの declaration merging により `Registry` に `"b"` が追加され、本番の型が `"a" | "b"` になる一方、縮小 Program では `"a"` とだけ解決されると、PHP 側も `"a"` なら gate が緑になります。これは fail-closed ではなく静かな偽陰性です。T10 の通常 import だけでは防げません。

修正案:

- 本番 gate は `parsed.fileNames` と目録ファイルの和集合を `rootNames` にする
- `parsed.projectReferences` も `createProgram` に渡す
- fixture 用には、意図的に縮小した別の `createFixtureProgram` を用意する
- 全 Program が重いという仮説は実測してから最適化する。縮小する場合も、full Program との同値性テストが必要

```ts
const rootNames = [...new Set([...parsed.fileNames, ...inventoryRootNames])];

const program = ts.createProgram({
    rootNames,
    options: { ...parsed.options, noEmit: true },
    projectReferences: parsed.projectReferences,
    configFileParsingDiagnostics: parsed.errors,
});
```

### [Warning] `parsed.errors` を見ていない

`onUnRecoverableConfigFileDiagnostic` は全 config 診断を捕捉しません。回復可能な `extends`、option、include 関係の診断が `parsed.errors` に残っていても Program を作ってしまいます。

修正案:

- `parsed.errors.length > 0` を検査し、全診断を整形して `EnumTsSyncError` にする
- 少なくとも `program.getOptionsDiagnostics()` と inventory source の syntactic diagnostics を検査する
- semantic diagnostics を見ないなら、「TS ソース全体の妥当性は保証せず、別レーンに依存する」と明記する

### [Warning] `getDeclaredTypeOfSymbol` 自体は妥当だが、型の受理方針が未確定

この API は型別名を遅延解決するため、単純な別名参照について問題ありません。ただし「解決後の型だけを見る」場合、次の挙動を明示する必要があります。

- `Lowercase<"A" | "B">`: 通常は `"a" | "b"` に評価され、受理される
- concrete な分配条件型: 有限リテラル union に評価されれば受理される
- 未具体化の generic 条件型: `Conditional` のままなので拒否される
- finite template literal type: リテラル union に展開されれば受理される
- `` `x${string}` ``: `TemplateLiteral` のため拒否される
- `unique symbol`: 拒否される
- 循環別名: error/any 相当になり通常は拒否されるが、診断無視のまま挙動を保証してはいけない
- `never` や重複 union は正規化で消えるため、元の構文要素としては検出できない

特に文字列 enum member type は `StringLiteral` と `EnumLiteral` の flag を併有し得ます。`part.isStringLiteral()` だけでは、通常の文字列リテラルと enum member を区別できない可能性があります。TS の string enum member は生の `"value"` と同じ型契約ではありません。

修正案:

- enum literal を受理するか拒否するかを決める
- 拒否するなら `ts.TypeFlags.EnumLiteral` を明示的に除外する
- T12 以降として `Lowercase`、条件型、有限/無限 template literal、string enum、numeric enum、`unique symbol`、循環参照を固定する
- 「文字列リテラルだけ」は「正規化・解決後の型について」と文書化する

### [Warning] TS の重複値検査は機能しない

`type X = "a" | "a"` は checker により `"a"` に正規化されます。そのため、

```ts
values.size !== parts.length
```

では元ソースの重複を検出できません。

修正案:

- 値集合だけが責務なら、この検査と「同じ値が 2 回なら例外」という主張を削除する
- 構文上の重複も禁止するなら、型ノードを別途検査する。ただし別名間の重複まで扱うかを定義する

### [Critical] PHP キーワードの大文字小文字を区別すると case を静かに落とせる

PHP の `enum`、`case`、`string` などのキーワードは大文字小文字を区別しません。提示された正規表現は小文字固定です。

```php
enum X: string {
    case A = 'a';
    CASE B = 'b';
}
```

で大文字の `CASE` を候補として認識しなければ、TS が `"a"` だけでも一致してしまいます。

修正案:

- enum 宣言検出と case token 検出を ASCII case-insensitive にする
- 深さ 1 のすべての `T_CASE` 相当を先に列挙し、その後で受理正規表現へ通す
- 受理正規表現も `i` flag を付ける
- lower/upper/mixed case の正例を追加する

### [Critical] PHP 字句状態の仕様が不足している

少なくとも以下を仕様化しないと、正しい PHP を誤って読むか、位置対応が崩れます。

- `#` コメントと `#[Attribute]` の区別
- backtick 文字列
- `?>` 以後の inline HTML と、再度の `<?php`
- `//` コメント中の `?>`
- 未終端の文字列・コメント
- `__halt_compiler()`
- astral Unicode 文字による UTF-16 offset のずれ
- `<<<` がコメントや文字列内にあるだけの場合
- CRLF の位置保存

特に「無害化した写し」と元文字列を同じ offset で slice する設計では、`for...of` によるコードポイント単位の置換を使うと絵文字などで位置がずれます。

修正案:

- PHP code / single quote / double quote / backtick / line comment / block comment / inline HTML の状態遷移を明文化する
- mask は UTF-16 code unit 単位で元文字列と同じ `length` を必ず維持する
- `#[` は `#` コメントに入れない
- backtick、close tag、`__halt_compiler()`を実装しないなら、code state で検出して理由付き例外にする
- 未終端状態は必ず例外にする
- コメントや文字列内の `<<<` は heredoc と誤認しない
- 絵文字・CRLF・`#[...]`・close tag の fixture を追加する

### [Warning] 「範囲外はすべて例外」は保証できない

この抽出器は PHP ファイル全体の文法検証器ではありません。例えば case 抽出に関係しない不正なトークンや、壊れたメソッド本体を無視して値集合だけ返す可能性があります。

修正案:

保証を次のように狭めてください。

> enum 本体直下で認識した case token は、限定文法に一致するか例外になる。PHP ファイル全体の構文、namespace、autoload、メソッド本体の妥当性は検証せず、PHP レーンに依存する。

### [Warning] PHP の複数行に関する仕様と正規表現が矛盾している

`\s*` と `[^'\\]*` は改行を含むため、提示された正規表現は複数行の case や文字列値を受理します。「複数行は例外」という説明と一致しません。

修正案:

- 改行を許可するなら受理文法を書き換える
- 禁止するなら `[ \t]*` を使い、対象 range に `\r` / `\n` がないことを検査する

### [Warning] PHP 側の重複値が Set で消える

旧テストは list 同士を比較していたため、重複した backing value を検出できました。新設計は `Set` にするため、次が同じ `{a}` になります。

```php
case A = 'a';
case B = 'a';
```

修正案:

- case 件数と Set 件数を比較し、重複 backing value を例外にする
- case 名の重複も検出する
- 対応する負例を追加する

## 施策 B: 汎用 gate 本体

判定: REQUEST_CHANGES

### [Warning] inventory path の検査が traversal を防いでいない

単純な `app/` prefix 検査では `app/../tests/...` が通ります。TS 側も fixture や任意の外部パスを指せる設計です。

修正案:

- absolute path、`\`、`.`、`..` segment を拒否する
- `path.resolve()` 後に PHP は `app/`、TS は少なくともリポジトリ内、可能なら `resources/js/` 内にあることを確認する
- symlink を考慮するなら `realpath` 後にも containment を確認する
- パスが通常ファイルであることも検査する

### [Warning] `mirrorProgram` の初期化が strict 上不明瞭

`beforeAll` 内で代入した変数を別 callback で読む構造は、制御フロー上の初期化保証が表現されません。

修正案:

```ts
let mirrorProgram: MirrorProgram | undefined;

const requireMirrorProgram = (): MirrorProgram => {
    if (mirrorProgram === undefined) {
        throw new EnumTsSyncError("mirror program", "初期化されていません");
    }

    return mirrorProgram;
};
```

`!` による definite assignment より、実行時にも fail-closed になる形が適切です。

### [Suggestion] 27 組すべてへの手動 mutation は過剰

各行は同じ comparator を通るため、54 回の一時編集は持続的な保証になりません。代表的な fail-first と抽出器の負例行列を残し、各 inventory 行については通常の parameterized test が存在することを保証すれば十分です。

件数 pin 自体は妥当です。増減の両方を赤くすることで、inventory の変更を明示的な判断にできます。ただし、27 という数は網羅性を証明しないことを記載してください。

## 施策 C: 抽出器の自己検査

判定: REQUEST_CHANGES

### [Warning] `.php.txt` とファイル名語幹検査が衝突する

`X.php.txt` に通常の `path.parse(...).name` を適用すると語幹は `X.php` です。enum 名 `X` と一致しません。

修正案:

- production は `.php`、fixture は `.php.txt` を明示的な許可 suffix として剥がす
- または fixture を `X.txt` にする
- 未知の suffix は例外にする

`.php.txt` という配置判断自体は妥当です。壊れた PHP を PHP 側の全数 gate に入れず、内容が PHP だと分かる点で `X.txt` より明瞭です。inline fixture よりもレビューしやすいため、現案を推奨します。

### [Warning] 負例行列が固有論点を十分に固定していない

追加が必要な主要ケース:

TS:

- `keyof typeof` の正例
- `typeof O[keyof typeof O]` の正例
- `Lowercase<"A" | "B">`
- concrete / generic の分配条件型
- finite / open template literal type
- string enum member / numeric enum member
- `unique symbol`
- 循環別名
- unresolved import / 存在しない export
- source 上の重複 union
- import されない global declaration / module augmentation
- `@/*` paths 経由の import

PHP:

- `match` 式を持つ実例
- 匿名クラス
- `enum X: string implements Foo, Bar`
- enum の `const` 宣言
- multi-line case
- `#[...]` と `# comment`
- upper/mixed-case keyword
- `?>` 後の HTML
- backtick
- 未終端コメント・文字列
- コメント・文字列中の `<<<`
- 重複 case 名・重複 backing value
- astral Unicode と CRLF
- interface/trait adaptation の波括弧

修正案:

上記を追加し、「受理」「明示的に拒否」のどちらかを各行で決めてください。特に module augmentation のケースは full Program を使う設計の回帰テストになります。

### [Warning] fixture 件数 pin だけでは degenerate PASS を完全には防げない

T10 は import 先の補助ファイルを必要とするため、「25 行」と「fixture ファイル数」は一致しません。また行列を減らしながら pin も減らす変更は普通に通ります。

修正案:

- matrix row 数を固定する
- 参照する fixture path の集合と実在ファイル集合を完全一致で検査する
- T10 の helper file は補助ファイルとして別 inventory に分類する
- orphan fixture と missing fixture の両方を赤くする

## 施策 D: 旧実装の撤去と参照の是正

判定: REQUEST_CHANGES

### [Warning] 旧テストと新 gate は完全には同じ保証ではない

旧 PHP テストは実際に enum class をロードして `::cases()` を呼んでいました。新 gate はテキスト抽出なので、次の保証は直接には引き継ぎません。

- namespace / autoload / FQCN の正しさ
- PHP としてロードできること
- backing value の重複
- 元 TS ソースに同じ literal が重複していないこと

最後の TS 重複は値集合の意味上、意図的に保証から外しても問題ありません。しかし PHP backing value の重複は保持すべきです。

修正案:

- PHP 重複値を抽出器で拒否する
- PHP 構文・namespace・autoload は `composer test` / PHPStan の責務で、新 gate 単体では保証しないと明記する
- 「同じ保証を移設」ではなく「値集合の不変条件を移設し、構文実行保証は既存 PHP レーンに依存する」と書き換える
- 削除する18 test の対応表では、14 件の比較と4件の degenerate-PASS 自己検査を分ける

PHP 側に同じ値比較テストを残さない判断自体は妥当です。上記の保証境界を明確にすれば、並走を残す必要はありません。

### [Warning] 参照切れ検査が `TsUnionValues` だけでは不足する

旧テストクラス名、ローカル関数名、ドキュメント内の説明が残り得ます。

修正案:

次を git 追跡下全体で検査対象にしてください。

- `TsUnionValues`
- `extractTsUnionValues`
- 削除する4テストのクラス名
- 旧テストパス
- 「PHP enum ⇔ TS union」の旧案内文

`app tests docs resources` だけでなく、`AGENTS.md` や設定・スクリプトも対象に含めるべきです。歴史記録を残す `devnotes` は、残置を許すか明示的に分類してください。

## 施策 E: 母集団の拡張

判定: APPROVE

14 組から 27 組への拡張、同じ PHP enum に複数の正当な TS mirror を登録できる構造、部分集合を完全一致 gate に入れない判断はいずれも妥当です。

### [Suggestion] 「登録しない理由」の置き場所を明確にする

未登録項目には inventory row がないため、「目録の `note` に書く」ことはできません。検査ファイル近傍のコメントか `docs/architecture.md` に置いてください。

現段階では機械的な発見処理がないため、これを「免除 inventory」と呼ぶのは避けた方が正確です。後半実装時に初めて、対象外理由を機械的 inventory に昇格させるのがよいです。

## 施策 F: 規約・文書

判定: REQUEST_CHANGES

### [Warning] `docs/template-divergence.md` に登録しない根拠が不足している

AG-099 の正典が「前半 + 発見の段 + 逆走査」であり、前半だけを本番の規約として導入するなら、少なくとも一時的には正典と異なる保護境界です。「段階的な取り込み」という意図だけでは、後半が恒久的に未着手になることを防げません。

修正案は次のいずれかです。

- temporary divergence として登録し、後続 TODO ID と解消条件を記載する
- このリポジトリの governance 上「未完了の段階導入は divergence に登録せず、機能台帳と TODO で管理する」という正本の規則を引用し、実際の TODO IDを同じ変更で付ける

提供された設計だけを根拠にすると、現状の「登録しない」は妥当とは判断できません。

### [Warning] 「保証しないもの」の正本が複数ある

設計では検査 docblock、`docs/architecture.md`、AGENTS.md の複数箇所に同じ限界を書く一方、「2か所に書くと食い違う」とも述べています。

修正案:

- 詳細な保証境界の正本を `docs/architecture.md` に一本化する
- gate の docblock は短い要約と正本への参照にする
- AGENTS.md は登録手順とレーンだけを書き、詳細を複製しない

また、PHP 側については次も「保証しないもの」に追加してください。

- PHP ファイル全体の構文妥当性は検証しない
- namespace / autoload / FQCN は検証しない
- close tag 等を拒否する場合、PHP が受理する全構文を受理するわけではない
- PHP の妥当性は backend job、同期値集合は frontend job という非対称な分担である

## 固有論点への最終判断

| 論点 | 判断 |
|---|---|
| (a) `getDeclaredTypeOfSymbol` | API 選択は妥当。ただし enum literal の扱い、正規化で消える `never`/重複、条件型・intrinsic の受理方針を固定する必要がある。最大の穴は API ではなく不完全な Program |
| (b) PHP 受理文法 | 現状は不十分。大文字 `CASE`、属性の `#[`、close tag、UTF-16 offset、未終端状態、重複値などを閉じる必要がある |
| (c) `.php.txt` | 妥当で推奨。ただし `X.php.txt` の語幹処理を明示的に実装する必要がある |
| (d) 件数完全一致 pin | 妥当。増減双方をレビュー対象にできる。ただし網羅性の証明ではない |
| (e) divergence 非登録 | 現状の根拠では不適切。一時 divergence 登録、または非登録を許す governance 正本と実 TODO IDが必要 |
| (f) inventory TS だけを root にする | 静かな誤判定経路がある。ambient/global/module augmentation を欠くため、full tsconfig Program を基準にすべき |

Critical を解消し、負例行列と保証境界を上記のとおり補強すれば、旧実装を撤去して単一の vitest gate に移す構想は承認可能です。