## Round 4 結論

ほぼ収束していますが、まだCritical 1件とWarning数件が残っています。主な問題は、導入したlocatorを「目録の登録済み宣言」までどう解決するかが未確定な点です。

---

## S1: 母集団

判定: APPROVE

---

## S2: `.svelte` 仮想化

判定: REQUEST_CHANGES

- [Warning] 本文では検査Bを `createMirrorPrograms()` 内部の一本道に移していますが、コード骨子のdocblockにはまだ次が残っています。

  > 利用側の義務: programを組んだあと `assertNoModuleToInstanceReference()` を呼ぶ

  修正案: 「`createMirrorPrograms()` が内部で必ず実行する。利用側に呼び出し義務はない」へ直してください。

- [Warning] `getAliasedSymbol()` はalias symbolにだけ呼べます。

  修正案: `(symbol.flags & ts.SymbolFlags.Alias) !== 0` を確認してから呼ぶことを骨子へ明記してください。

それ以外の検査A/B、binding種類、Program構築への結線は妥当です。

---

## S3: 複数Program

判定: APPROVE

---

## S3b: 共有抽出器

判定: APPROVE

形ごとの三値境界が整理され、const配列の構文抽出も明確になりました。

---

## S4: locatorと候補走査

判定: REQUEST_CHANGES

- [Critical] `occurrence` を割り当てる母集団がまだ曖昧です。

  現在の説明は「同じ `(file, shape, name)` を持つ候補を並べる」ですが、candidateとindeterminateを別々に採番すると、次がどちらも `occurrence: 0` になり得ます。

  ```ts
  function a() {
      type Status = MissingType; // indeterminate
  }

  function b() {
      type Status = "ready"; // candidate
  }
  ```

  また、`not-a-catalogue`を採番前に除くと、宣言の分類が変わっただけで後続のoccurrenceが意図せず動きます。

  修正案: occurrenceは三値判定より前に、構文上の宣言site全体へ割り当ててください。

  - literal-union: 全type alias宣言
  - const-array/object-keys: wrapperを剥がした結果、対応するinitializer形を持つ全変数宣言
  - switch-cases: 全switch文
  - 同じ `(file, shape, name)` のsource position順

  その後に各siteを `candidate / not-a-catalogue / indeterminate` へ分類します。candidateとindeterminateは同じ採番空間を共有します。

  次の負例が必要です。

  - 同名でcandidateとindeterminateが一件ずつ
  - 同名でnot-a-catalogueが先、candidateが後
  - 前者だけを申告しても後者へ効かない

---

## S5: 規則2

判定: REQUEST_CHANGES

最終式での再測定は十分です。

- [Warning] テスト計画の件数が本文と一致していません。本文では「対応する8組・対応しない2組」ですが、テスト計画では「対応する6組・対応しない2組」です。

  修正案: 8組/2組へ統一してください。

- [Warning] 最大マッチングの試験が「同じ語の重複」だけでは、増補路の実装を裏取りできません。単純なgreedyでも通る可能性があります。

  修正案: 一度割り当てた辺を付け替えなければ最大値へ到達しない二部グラフを直接テストしてください。例えば隣接関係が次になる入力です。

  ```text
  L1 → R1, R2
  L2 → R1
  ```

  `L1→R1`を先に選んでも、増補路で`L1→R2`へ付け替え、matching size 2になることを固定します。

---

## S6: relation目録

判定: REQUEST_CHANGES

- [Warning] 判別された合併ではsubsetに `subsetReason` が必須ですが、S11の実際の登録例には `subsetReason` がなく、`note`だけです。このままでは型検査が落ちます。

  修正案:

  ```ts
  {
      relation: "subset",
      note: "CLIログイン時の既定要求スコープ",
      subsetReason: "値域そのものの写しではなく、サーバが認識する値域からCLIが既定で要求する権限だけを選ぶため",
  }
  ```

  のように役割を分けてください。

- [Warning] `subsetReason` のtrim後30文字以上を `validateRelations()` で検査することを明記してください。空白だけで30文字を満たす形を通さない必要があります。

---

## S7: 前向き検査・登録済みlocator

判定: REQUEST_CHANGES

- [Critical] `declaredTsLocators()` をどのように作るかが未定義です。

  relation行が持つのは `ts + declaration` だけで、locatorに必要な次がありません。

  - shape
  - occurrence

  特に、同名のnested宣言がtop-level宣言より前にある場合、top-levelでもoccurrenceは0とは限りません。

  修正案: 前向き抽出時にrelationを解決し、正確なAST nodeからlocatorを返してください。

  ```ts
  interface ResolvedEnumTsRelation {
      entry: EnumTsRelationEntry;
      tsLocator: TsCandidateLocator;
      phpValues: ReadonlySet<string>;
      tsValues: ReadonlySet<string>;
  }
  ```

  処理順は次です。

  1. 対象SourceFileのtop-levelから、指定名の受理可能宣言をちょうど一つ解決
  2. S4と同じlocator factoryで、そのnodeのshape/name/occurrenceを計算
  3. `declaredTsLocators()` は解決済みrelationから作る
  4. 逆走査はlocator完全一致のcandidateだけを登録済みとして外す

  locatorの採番処理をS4とS7で別実装にしてはいけません。共有の `locatorOf(node, scanIndex)` 等へ集約してください。

  必須負例:

  - nested `Status` が先、top-level `Status` が後
  - relationはtop-levelだけを登録済みとする
  - nested候補は逆走査に残る

- [Warning] `subset`の限界を担保する記述は `note` ではなく、新設した `subsetReason` に統一してください。

---

## S8: 逆走査gate

判定: REQUEST_CHANGES

- [Critical] S4/S7と同じく、locatorの採番空間とrelationからのlocator解決が確定するまで、exemption・stale・登録済み除外の正しさを保証できません。

  修正案: 以下を同じlocator factoryへ統一してください。

  - candidate
  - indeterminate
  - resolved relation
  - reverse exemption
  - stale判定
  - 重複検査
  - 診断

- [Warning] 失敗メッセージがまだ「写しなら登録」とだけ案内しています。現在はequalとsubsetの2関係があります。

  修正案:

  ```text
  TSがPHP値域そのものの写しなら relation:"equal"、
  PHP値域から選んだ非空集合なら relation:"subset" と subsetReason を登録する。
  どちらでもない候補だけをREVERSE_SWEEP_EXEMPTIONSへ登録する。
  ```

  のように案内を分けてください。

---

## S9: 負の対照・故障注入

判定: REQUEST_CHANGES

- [Warning] locator試験へ、candidate同士だけでなく三値をまたぐ衝突を追加してください。

  修正案:

  - candidate + candidate
  - indeterminate + candidate
  - not-a-catalogue + candidate
  - nested先行 + top-level relation

  の4形を固定します。

- [Warning] 故障注入の呼び方は概ね整理されていますが、表には `1'`、`4'`、`8'`、`9'` があります。「境界試験3件」とするなら、`8'`はカテゴリ8の通常ケースであることを明記してください。そうでなければ「境界4件」です。

---

## S10: 前向きgate

判定: APPROVE

---

## S11: 実ドリフト是正

判定: REQUEST_CHANGES

実装判断自体は妥当です。

- [Warning] subset登録例に必須の `subsetReason` がありません。

  修正案: S6記載のとおり追加してください。

- [Suggestion] APIエラーのdocblockにある「道具固有の符号」は、直後に「道具内部で作らない」と説明しているため、「非canonicalなendpoint固有符号」などへ統一すると誤読が減ります。

---

## S12: 乖離台帳

判定: APPROVE

---

## S13: 文書更新

判定: REQUEST_CHANGES

- [Warning] AGENTS.mdのsubset説明がまだ「noteに理由を書く」になっています。

  修正案: `subsetReason`に値域の写しでない理由を30文字以上で書く、へ統一してください。

- [Warning] `docs/architecture.md` に、relation行のlocatorはASTから解決し、逆走査のcandidate locatorと同じfactoryで生成することを追加してください。

---

## 全体判定

CHANGES_REQUESTED

残る必須修正は主に2点です。

1. `occurrence`をcandidateだけでなく、三値分類前の構文site全体で採番する
2. relation行からtop-level宣言の正確なlocatorを解決し、S4と同じlocator factoryで登録済み判定へ渡す

あわせて、`subsetReason`の登録例・文書・validationを統一し、最大マッチングの増補路試験を追加すれば、設計はAPPROVEDに到達できます。