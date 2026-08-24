## Round 3 結論

Round 2の3件のCriticalに対する設計変更は、いずれも方向として妥当です。特に `relation: "subset"` と共有抽出器S3bは良い着地です。

ただし、まだCritical 2件が残っています。

1. 逆走査対象を入れ子まで広げたのに、候補・申告・判定保留の同一性が `file + name` のままで衝突する
2. Svelteの検査Bが「利用側の義務」であり、共有program作成経路からの実行が機械的に保証されていない

加えて、Round 3で判定式を変更した後の再測定がまだありません。

---

## S1: 母集団モジュール

判定: APPROVE

前回指摘は解消されています。

---

## S2: `.svelte` の仮想TS化

判定: REQUEST_CHANGES

- [Critical] `assertNoModuleToInstanceReference()` が「利用側の義務」になっており、呼び忘れを構造的に防げません。前向き・逆走査・fixture用Programの一部だけが呼ばない状態でも型は通ります。

  修正案: 本番経路では `createMirrorPrograms()` の内部で、Program構築直後に全仮想unitへ必ず検査Bを実行してください。検査済みProgramだけを返す一本道にします。

  さらにarchitecture testで、次を固定してください。

  - `createMirrorPrograms()` が検査Bを必ず呼ぶ
  - 前向き・逆走査の呼び出し側が未検査の `MirrorProgram` を直接構築しない
  - fixture用の縮小Programは明示的に検査を呼ぶ、または「未検査fixture用」という別型にする

- [Warning] 検査Aの「最上位束縛」の列挙が不足しています。型別名・interface・変数・関数・classだけでは、次が漏れます。

  - importのdefault/name/namespace binding
  - `import x = ...`
  - enum
  - namespace/module declaration
  - 分割代入の個々のbinding name

  修正案: TypeScript AST上のbinding-name抽出を共通化し、最上位で束縛を作る構文を網羅してください。少なくともimport、enum、namespace、分割代入についてmodule/instance衝突の負例が必要です。

- [Warning] 検査Bは識別子のsymbolだけでなくalias宣言の位置も見る必要があります。instance側importをmodule側が誤って参照した場合、aliased targetは外部ファイルでも、alias declaration自体はinstance範囲です。

  修正案: `checker.getSymbolAtLocation()` が返すsymbolの `declarations` をまず検査し、必要なら `getAliasedSymbol()` の先も確認してください。

fail-closedで平坦化の差を塞ぐ方針自体は妥当です。

---

## S3: 複数Program

判定: APPROVE

所有者・rootNames・推移依存を分離したことで、前回の曖昧さは解消されています。

S2の検査Bは、この施策のProgram構築内部へ組み込んでください。

---

## S3b: 共有値抽出器

判定: REQUEST_CHANGES

- [Warning] `readConstArrayLiteralValues()` の契約が一部矛盾しています。

  受理条件は「全要素が構文上の文字列リテラル」なので、識別子や呼び出し式が混ざれば、型解決の成否に関係なく `not-a-catalogue` のはずです。一方、テスト計画では「型を解決できない要素があれば `indeterminate`」としています。

  修正案: shapeごとに三値の境界を明記してください。

  - const-arrayの非文字列構文要素: `not-a-catalogue`
  - objectの計算キー、switch case、解決型unionなど、型解決が受理条件に必要な箇所でany/unknown: `indeterminate`
  - 配列で識別子まで型解決して受理するなら、受理条件表を変更する

  現在の「配列は構文リテラルだけ」を維持するのが最小です。

- [Warning] 新設した5抽出関数に対する直接の両方向試験が明記されていません。

  修正案: S3b自身のテストとして、各関数に `values / not-a-catalogue / indeterminate` の該当する分岐を置いてください。S4経由だけにすると、共有抽出器のどの分岐が壊れたか不明瞭になります。

---

## S4: 候補走査・派生除外・判定保留

判定: REQUEST_CHANGES

- [Critical] 候補の同一性が `file + name` では不足します。

  S4では入れ子の型別名、関数内変数、複数のswitchを拾います。そのため、同じファイルに次が合法的に存在できます。

  ```ts
  function a() {
      type Status = "a";
  }

  function b() {
      type Status = "b";
  }
  ```

  現在の設計では両方が同じ `file::Status` になります。この衝突により次が起きます。

  - 一方のexemptionでもう一方まで免除される
  - top-levelの登録済み宣言と同名のnested候補が逆走査から消える
  - `KNOWN_INDETERMINATE_TS_DECLARATIONS` の一行で複数宣言が免除される
  - 同名switchやobject候補のstale判定が正しくできない

  修正案: 逆走査候補へ一意なlocatorを追加してください。最小案は次です。

  ```ts
  interface TsCandidateLocator {
      file: string;
      shape: TsCandidateShape;
      line: number;
      name: string;
  }
  ```

  行移動で申告がstaleになることを許容できない場合は、囲んでいるnamed declarationと同一名内ordinalから構造的locatorを作ります。ただし複雑になるため、静的gateでは行番号を同一性へ入れる方が明快です。

  次をすべてlocatorへ統一する必要があります。

  - `registeredTsKeys`
  - `reverseSweepKey`
  - `REVERSE_SWEEP_EXEMPTIONS`
  - `KNOWN_INDETERMINATE_TS_DECLARATIONS`
  - 重複検査
  - stale検査
  - 診断

  前向き目録はtop-level宣言だけを受理するなら、その事実もlocator照合へ反映し、同名nested候補を登録済み扱いしないでください。

- [Warning] `isUnresolvedType` という関数名が新しい `indeterminate` 契約と一致していません。

  修正案: `isIndeterminateType` または `classifyResolvedType` へ改名してください。

---

## S5: 規則2の論理和

判定: REQUEST_CHANGES

- [Warning] `forms()` の説明例が定義と一致していません。

  定義どおりなら次です。

  - `forms("case") = {"case"}` であり `{"case", "cas"}` ではない
  - `forms("use") = {"use"}` であり `{"use", "us"}` ではない
  - `forms("response") = {"response"}` であり `{"response", "respons"}` ではない

  複数形側の集合に元の単数形候補が入るため、対応関係自体は成立します。

  修正案: 例だけを実際の式へ合わせてください。実装テストでは各語の`forms()`そのものも固定します。

- [Warning] 共通語数の数え方が一対一対応になっていません。

  「列挙側の各語について、候補語袋のどれかと対応するか」を単純に数えると、候補側の一語が列挙側の複数語へ重複利用されます。例えば形態的に対応する語が列挙名内に重複している場合、候補側一語だけで一致数2になり得ます。

  修正案: 次のどちらかにしてください。

  - 語の対応について最大二部matchingを取り、一つの候補語を一度だけ使う
  - 対応形のequivalence classを作れる範囲へ規則を限定し、class集合の積を数える

  現在の `forms(a) ∩ forms(b)` は推移律を保証しないため、最大matchingの方が正確です。

- [Warning] Round 3で単数化判定を候補集合方式へ変更した後の現物再測定がありません。probe2は前の正規化方式による値です。

  修正案: 最終の `forms()` と一致数判定で再測定し、hit件数・規則別件数・申告予定8件が変わらないことを確認してください。

---

## S6: 目録の受理範囲・relation

判定: REQUEST_CHANGES

- [Warning] `subset` 行に必要な説明を、全行共通の `note` 非空だけでは実質的に強制できません。既存のequal行もすべてnoteを持つため、subset特有の負担が機械上ありません。

  修正案: 次のいずれかを採用してください。

  - `subsetReason` をsubsetだけの必須fieldにするdiscriminated union
  - subsetのnoteだけ30文字以上かつ「値域の写しではない理由」を要求する

  推奨形はdiscriminated unionです。

  ```ts
  type EnumTsRelation =
      | { relation: "equal"; note: string }
      | { relation: "subset"; note: string; subsetReason: string };
  ```

  これにより、subsetへの変更がレビュー上も明確になります。

---

## S7: 前向き検査・relation

判定: REQUEST_CHANGES

subsetという関係を既存gateへ統合する判断は妥当で、専用テストファイルは不要です。

ただし次を修正してください。

- [Critical] S4のlocator問題により、「relationを問わず登録済みなら候補から外す」が同名nested候補まで消す可能性があります。

  修正案: 前向き登録が指すtop-level宣言の正確なlocatorだけを登録済みにしてください。`file + declaration`だけで全candidateを除外しないことが必要です。

- [Warning] `EnumTsMirror` という型名はsubset行を含むようになるため、必ずしもmirrorではありません。

  修正案: `EnumTsRelationEntry`、`ENUM_TS_RELATIONS`などへ改名するか、「inventory全体の歴史的名称であり、行はequal/subset関係を表す」と明記してください。思考原則の「機能の名前に立ち返る」を考えると改名が望ましいです。

- [Warning] `subset` の非空条件は抽出器が空配列を拒否することに依存しています。

  修正案: relation判定側でもTS集合の非空を明示検査してください。将来別の受理形が増えても不変条件が維持されます。

---

## S8: 逆走査gate

判定: REQUEST_CHANGES

- [Critical] S4と同じく、exemptionと判定保留の同一性にlocatorが必要です。特に「行番号は不安定なのでfile/nameだけ」という判断は、入れ子走査との両立上採用できません。

  修正案: 行番号または構造locatorを同一性へ含め、件数pin・重複・staleをすべてそのlocatorで判定してください。

- [Warning] `CliOAuthScope` をPHP側の「登録済み」分類へ移す説明では、「mirror登録済み」ではなく「TSとの関係が登録済み」と呼ぶ方が正確です。

  修正案: PHP側三分類の名称またはdocblockを、equal/subsetの両方を包含する言葉へ更新してください。

- [Warning] S5の再測定前なので、hit 10件・最終exemption 8件はまだ確定値ではありません。

  修正案: 最終判定式による再測定後に表を更新してください。

---

## S9: 負の対照と故障注入

判定: REQUEST_CHANGES

- [Warning] locator衝突を検出する負例がありません。

  修正案: 同一ファイルの異なるscopeに同名候補を2件置き、次を固定してください。

  - 2件とも別candidateとして残る
  - 一方のexemptionは他方へ効かない
  - top-level登録はnested同名candidateを消さない
  - indeterminate申告も一方だけへ作用する

- [Warning] S2の検査BがProgram構築へ必ず結線されることの故障注入が必要です。

  修正案: 検査B呼び出しを一時的に外すと、module→instance参照の統合テストが緑ではなく赤になることを確認してください。

8カテゴリ＋境界2件という呼び方自体は整理されています。

---

## S10: 前向きgate

判定: APPROVE

---

## S11: 実ドリフト是正

判定: APPROVE

API errorの分類は具体化され、OAuth scopeもsubset関係として正しく分離されています。

- [Suggestion] リスク欄の「スコープを足すときはサーバの列挙と同時に足す」はsubset方針と少しずれます。

  推奨表現は「CLIへ足す前に、または同じ変更でサーバ値域へ足す」です。サーバ側だけ先行して増えることはsubset契約上許されています。

- [Suggestion] `dispatchKindFromCode()` を直接検査するためexportを追加する場合、packageの公開mainからは再exportしないことを維持してください。

---

## S12: 乖離台帳

判定: APPROVE

---

## S13: 文書更新

判定: REQUEST_CHANGES

- [Warning] locatorの一意性とsubset inventoryの名称を、最終設計へ反映する必要があります。

  修正案: `docs/architecture.md` に次を加えてください。

  - 入れ子候補の同一性は宣言名だけでなくlocatorで持つ
  - equalとsubsetは同じ目録に載るが、意味は異なる
  - subsetは値域の写しではなく、許可値域に対する選択集合である

---

## 全体判定

CHANGES_REQUESTED

残る必須修正は次のとおりです。

1. 入れ子候補を一意に識別するlocatorを導入し、登録・免除・判定保留・stale判定へ一貫適用する
2. Svelteの検査Bを「利用側の義務」ではなく、Program構築の一本道へ組み込む
3. 検査Aのbinding抽出をimport・enum・namespace・分割代入まで広げる
4. `forms()`方式による最終判定式で現物を再測定する
5. subset行をdiscriminated union等でequal行より強く申告させる

これらを修正すれば、全体APPROVEDに到達できる状態です。