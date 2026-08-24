## Round 2 結論

Round 1 の主要な問題はかなり丁寧に解消されています。ただし、まだ Critical 3件と Warning 数件が残っています。特に次の3点は実装前に設計変更が必要です。

1. Svelteのmodule/instanceを同一TSスコープへ平坦化すると、逆方向参照とshadowingを誤解決する
2. 素の `const X = ["a"]` はTypeChecker上では通常 `string[]` に widen され、S7の説明どおりには読めない
3. `CliOAuthScope` の全値と「既定で要求するスコープ」は別概念であり、完全一致mirrorにすると将来の権限追加を自動要求する危険がある

以下は提示テキストのみを対象とした再レビューです。

---

## S1: 母集団モジュール

判定: APPROVE

Round 1 の `.d.ts`、NUL区切り、0件分岐は解消されています。

- [Suggestion] `git ls-files` に shallow でないcloneは不要です。shallow cloneでもindexの追跡ファイルは列挙できます。

  修正案: リスク欄を「Git worktree/indexが利用可能であることが前提」に直してください。

---

## S2: `.svelte` の仮想TS化

判定: REQUEST_CHANGES

- [Critical] module scriptとinstance scriptを同じTSのトップレベルへ平坦化すると、Svelteの可視性を完全には再現できません。

  現設計で解消できるのは次の2点です。

  - コンポーネント間の大域汚染
  - module側の宣言をinstance側から参照すること

  一方、次の誤動作が残ります。

  - module側から、後ろに置かれたinstance側の宣言が見えてしまう
  - module/instanceの同名宣言がshadowingではなく同一symbolの重複宣言として扱われる
  - 意味診断を読まなくても、`getSymbolAtLocation()` や `getDeclaredTypeOfSymbol()` が結合済みsymbolを返し、逆走査の値集合自体が汚染され得る

  例えば、module側に `type Ref = InstanceOnly`、instance側に `type InstanceOnly = "a"` があると、平坦化したTSでは前方参照として解決されますが、Svelte本来の可視性とは逆です。

  修正案は次のいずれかです。

  1. `svelte2tsx`等のSvelte用変換とsource mapを使う
  2. 現在の平坦化を採る場合、受理範囲を狭める

     - module/instance間で同名bindingがあれば構築時に不合格
     - module側の参照がinstance側の宣言へ解決されたら不合格
     - instance→moduleだけを許可

  現物に該当が0件なら、2のfail-closed方式が最小です。「moduleからinstanceは見えない」負例を必ず追加してください。

- [Warning] `lang` の受理条件が矛盾しています。

  - 「`lang="ts"` だけを受理」
  - 「`lang="js"` は不合格にしない」

  が併記されています。

  修正案: 属性名だけでなく組み合わせと値を表にしてください。例えば以下です。

  - 属性なし: 受理しTSとして解析
  - `lang="ts"`: 受理
  - `lang="js"`: 受理してTSとして解析する、または拒否
  - bare `module`: module scriptでだけ受理
  - `module="..."`: 拒否
  - instance scriptの`module`: 拒否
  - その他: 拒否

- [Warning] 末尾の `export {};` は、元ソースが改行なし・行コメントで終わる場合も独立した文として解釈される必要があります。

  修正案: 常に `"\nexport {};\n"` を追加し、「改行なし＋末尾行コメント」の試験を追加してください。

---

## S3: programをパッケージごとに作る

判定: REQUEST_CHANGES

パッケージ固有tsconfigを使う変更は妥当です。

- [Warning] `.svelte` の所有者が常にroot programになっています。将来 `packages/*` 配下に `.svelte` が追加された場合、パッケージ固有tsconfigではなくroot設定で解析されます。

  修正案: `.ts` と `.svelte` に同じowner判定を適用してください。tsconfigを持つpackage配下の仮想Svelteは、そのpackageのProgramへ載せます。

- [Warning] 「母集団の全件がちょうど1本のprogramに載る」の意味を明確にする必要があります。推移importにより同じSourceFileが複数Programへ載ること自体はあり得ます。

  修正案: 次を区別してください。

  - 所有者への割当はちょうど1件
  - rootNamesとしての所属もちょうど1件
  - 推移依存として別Programにも現れることは、許すのか不合格にするのか

  候補走査は「所有者Program上のSourceFile」だけを使う、と固定するのが安全です。

- [Warning] case-insensitive環境では、canonical keyと生の `SourceFile.fileName` が文字列として一致するとは限りません。

  修正案: `SourceFile.fileName` 自体ではなく、`getCanonicalFileName(source.fileName)` とmap keyの一致を検査してください。

---

## S4: 候補走査・解析不能・派生除外

判定: REQUEST_CHANGES

包みの除去、三集合一致、2パス証人判定は適切に修正されています。

- [Warning] `Any | Unknown` だけでは「型解決不能」と「正しくany/unknownへ解決した型」を区別できません。

  例えば次は現在の式では解析不能扱いになります。

  ```ts
  type Dynamic = any;
  type X = Dynamic;
  ```

  しかしこれはsymbol解決に失敗したのではなく、明示的な`any`への正常な解決です。計算キーでも、明示的に`any`とした変数は同じ問題を持ちます。

  修正案は次のどちらかです。

  - 名称を `unresolvable` ではなく `indeterminate` にし、「解決不能に加えて、候補性を確定できないany/unknownも含む」と契約を変える
  - 未解決symbol、未解決import、循環型の診断・symbol provenanceを調べ、本当に解決不能な場合だけ分類する

  前者のほうがTypeScript内部のerror typeに依存せず安全です。少なくとも「別名経由の明示any/unknown」と「any型の計算キー」の試験を追加してください。

- [Warning] `EnumLiteral` の拒否を4形すべてへ適用するのか、型別名・case式だけへ適用するのかが曖昧です。

  修正案: 各shapeについて、enum memberを値として使った場合の期待結果を固定してください。

---

## S5: 規則2の論理和

判定: REQUEST_CHANGES

Round 1 の負例3形、`switch:`、空語列、`status`問題は改善されています。

- [Warning] 新しい単数化も `ses` を一律に処理するため、一般的な語を誤変換します。

  例:

  - `cases` → `cas`
  - `responses` → `respons`
  - `uses` → `us`

  `statuses → status` と `classes → class` を接尾辞だけで扱うと、`-se + s`との区別ができません。

  修正案:

  - 単一の正規化結果ではなく、曖昧な語について候補集合を作る
    - `cases` → `{case, cas}` のような方式
  - または現物で必要な限定語だけを明示する
  - 少なくとも `cases` / `responses` / `uses` を負例・正例へ追加し、意図しない語幹化を固定する

  「限定的なheuristic」とすること自体は可能ですが、現在の説明では誤った語幹を正規形として採用しています。

---

## S6: 目録の受理範囲

判定: APPROVE

字面で一致したrootをsymlink検査へ引き継ぐ設計、package rootの負例、順序固定はいずれも妥当です。

---

## S7: 前向き検査の2形対応

判定: REQUEST_CHANGES

- [Critical] 「`checker.getTypeAtLocation(name)`から要素のリテラル型として読める」は、素のconst配列では成立しません。

  ```ts
  const X = ["a", "b"];
  ```

  は通常 `string[]` にwidenされます。`as const`ならreadonly tupleになりますが、設計では素の配列も受理するとしています。

  修正案: TypeCheckerから配列要素を復元するのではなく、S4と共有する構文抽出器を使ってください。

  - wrapperを剥がす
  - initializerが配列リテラルであることを確認
  - 各要素が文字列リテラルであることを確認
  - `const` bindingを確認

  「値集合の抽出器を2本持たない」を守るため、例えば `ts-literal-values.ts` のような下位モジュールへ次を分離し、S4とS7の両方から使う必要があります。

  - `unwrapInitializer`
  - `readConstArrayLiteralValues`
  - `readResolvedStringLiteralUnion`

  変更ファイル一覧と波及変更にも、この共有モジュールを追加してください。

- [Warning] `satisfies`付き配列も、対象型によって要素がwidenされ得ます。

  修正案: 受理判断は常に配列リテラルの構文から行い、TypeCheckerの配列型に依存しないことを明記してください。

---

## S8: 逆走査gate

判定: REQUEST_CHANGES

- [Warning] 段6で追加するexemption数の説明がまだ揺れています。

  最終表では追加7件に`ApiErrorCode::ApiErrorCode`が含まれます。一方、実装順序の説明では、段9で解消する3件としてその合併型も数えています。

  修正案: 次のどちらかへ統一してください。

  - 段6で最終exemption 7件をすべて追加  
    → 残る実ドリフトは `API_ERROR_CODES` と `DEFAULT_CLI_SCOPES` の2件
  - 段6では非ドリフトの6件だけ追加  
    → 段9で合併型exemption 1件とmirror 2件を処理

  テストファーストの意味が分かりやすいのは後者です。

- [Warning] S11-bを完全一致mirrorから外す必要があるため、最終件数も変わります。

  修正案: S11の指摘に従い、mirror/exemption/PHP分類の件数を再計算してください。

---

## S9: 自己検査・故障注入

判定: REQUEST_CHANGES

不正Svelte入力をインライン化した点は解消されています。

- [Warning] 「本番APIに任意の差し替え口を増やさない」と、表にある「常に真の関数を渡す」「規則を外した述語を渡す」がまだ矛盾しています。

  修正案: exported production wrapperと、純関数coreを分けてください。

  ```ts
  // 本番入口。戦略は固定
  export const collectTsCandidates = (...) =>
      collectTsCandidatesCore(..., productionRules);

  // 自己検査対象。任意述語ではなく明示的な入力データを受ける
  export const isDerivedObjectKeys = (facts: DerivedFacts): boolean => ...
  ```

  テストでは`isDerivedObjectKeys()`や`buildWitnessIndex()`の入力factsを変えます。本番入口へ「常に真の述語」を渡せる形にはしないでください。

- [Warning] 故障注入の件数を「8件」と呼んでいますが、表には1'があり、S3には3'もあります。

  修正案: 「8カテゴリ、追加の境界試験2件」など、件数の意味を統一してください。

---

## S10: 前向きgateの負の対照

判定: APPROVE

S6実装前に負例を追加する順序と、既存ケースを保持する記述は妥当です。

---

## S11: 実ドリフト2件

判定: REQUEST_CHANGES

### S11-a APIエラー符号

API側は概ね妥当です。公開面の確認も十分具体的です。

- [Warning] 新しいcanonical codeの期待分類が「検査する」とだけ書かれ、期待値が決まっていません。

  修正案: 設計段階で少なくとも次を確定してください。

  - `insufficient_ability`
  - `actor_not_resolvable`
  - `idempotency_in_progress`
  - `idempotency_indeterminate`

  状態番号fallbackに任せるなら、その理由と期待HTTP statusを固定します。

- [Suggestion] `rate_limited + 非429` はcode優先を証明できますが、実応答としては不自然です。純関数 `dispatchKindFromCode()` を直接試験できるようにするか、前向きmirrorを先に追加して値不一致を最初の赤にする方が明快です。

### S11-b CLI OAuthスコープ

- [Critical] `CliOAuthScope` の値域と `DEFAULT_CLI_SCOPES` は別概念です。

  - `CliOAuthScope`: サーバが認識する全スコープ
  - `DEFAULT_CLI_SCOPES`: CLIが既定で要求する権限

  現時点で偶然同じ4値でも、完全一致mirrorとして登録すると、将来サーバへ新しいスコープを追加した際にCLIもその権限を要求するよう設計上促されます。これは最小権限に反し、「別物の概念を似ているから統合しない」という原則にも抵触します。

  修正案:

  1. `DEFAULT_CLI_SCOPES` を `ENUM_TS_MIRRORS` の完全一致登録から外す
  2. `DEFAULT_CLI_SCOPES ⊆ CliOAuthScope` を検査する専用のsubset不変条件を追加する
  3. 必須スコープがあるなら、それも別の明示集合として検査する
  4. 逆走査では「既定要求集合であり、許可値域の完全な写しではない」と理由付きexemptionへ登録する
  5. 現在の余分な2値はsubset違反として赤くし、削除する

  現時点で両集合が完全一致するため、修正後のreverse ruleは一旦 `"1"` になります。将来サーバ側だけ値が増えるとruleが変わってexemptionがstaleになりますが、それは再確認を促す赤として許容できます。

  この変更により、概算は次の方向へ変わります。

  - `EXPECTED_MIRROR_COUNT`: 31ではなく30
  - PHP exemption: `CliOAuthScope`をmirror分類へ移さないなら、93ではなく94
  - reverse exemption: `DEFAULT_CLI_SCOPES`用を追加するため9件

  実装時の現物再計算は必要です。

- [Warning] 「道具側の単体でサーバ登録スコープとの一致を検査する」だけでは、期待4値をCLIテストへ複製する可能性があります。

  修正案: subset検査はPHP enum catalogとTS抽出器を共有するarchitecture testとして置いてください。package unit testは「OAuth要求へ渡される集合」の振る舞いだけを担当します。

---

## S12: 乖離台帳

判定: APPROVE

D50の内容、正本レーンの明記、着手時とmerge直前の再計算は妥当です。

S11-bの変更によってenum関連のpinは変わりますが、D50とtemplate ledgerの47/147には直接影響しません。

---

## S13: 文書更新

判定: REQUEST_CHANGES

書き分け方針自体は妥当です。

- [Warning] 現状のままでは、`CliOAuthScope` と `DEFAULT_CLI_SCOPES` を完全一致mirrorとして扱う誤った設計が文書化されます。

  修正案: S11-bをsubset不変条件へ変更した後、architecture文書に「許可値域と既定要求集合は別概念」と明記してください。

- [Warning] Svelteについて「1仮想ファイルで自然にスコープを再現する」とは書けません。

  修正案: S2で採る方式に応じて、受理しないshadowing・逆方向参照を保証外ではなくfail-closed条件として記載してください。

---

## 全体判定

CHANGES_REQUESTED

残る主な修正は次のとおりです。

1. Svelteの平坦化で生じるinstance→module逆流とshadowingを塞ぐ
2. `const` 配列の値抽出をTypeCheckerのwiden後の型ではなく、S4と共有する構文抽出器へ統一する
3. `DEFAULT_CLI_SCOPES` を完全一致mirrorではなくsubset不変条件として扱う
4. `any/unknown` を「解決不能」と呼ぶ条件を精密化するか、`indeterminate`へ契約変更する
5. exemptionの追加時点と最終件数を再整理する

この5点を直せば、programの分割、母集団、派生除外、判定不能の扱い、台帳処理についてはAPPROVEDにできる状態です。