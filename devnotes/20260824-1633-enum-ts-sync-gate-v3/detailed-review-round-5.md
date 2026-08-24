## Round 5 結論

大きな設計問題は解消されています。残っているのは局所的なWarning 3件です。概念設計の変更や再測定は不要ですが、現状の文章のままでは実装判断が一意にならない箇所があります。

---

## S1〜S3b

判定: APPROVE

母集団、Svelteのfail-closed条件、Programの一本道、共有抽出器はいずれも妥当です。

---

## S4: locatorと候補走査

判定: REQUEST_CHANGES

- [Warning] `nameResolved: false` のswitchに、locatorの `name` として何を入れるかが未定義です。

  `switchSubjectName()` は呼び出し式や添字アクセスで `null` を返しますが、`TsCandidateLocator.name` は必須です。また、採番は分類前に `(file, shape, name)` 単位で行うため、nameがなければoccurrenceを計算できません。

  修正案: locator用の構文名と、規則2用の解決名を分けてください。

  ```ts
  interface SwitchSubject {
      // locator専用。正常な構文なら必ず得られる
      readonly siteName: string; // 例: `switch:${expr.getText(source).trim()}`

      // 規則2の名前対応に使える場合だけ値を持つ
      readonly correspondenceName: string | null;
  }
  ```

  - locatorの `name` には常に `siteName` を使う
  - `nameResolved` は `correspondenceName !== null`
  - 2a/2bには `correspondenceName`だけを渡す
  - 任意の式テキストを名前対応へ使わない
  - 空の式テキストは構文診断または解析不能として落とす

  呼び出し式のswitchが2件ある場合にoccurrenceが0/1となり、一方だけがundecidableになる負例を追加してください。

- [Suggestion] 変数宣言siteの `name` は識別子bindingだけを受理するのか、分割代入にもlocatorを作るのか明記すると実装が安定します。現在の候補形では識別子bindingだけに限定するのが自然です。

---

## S5: 規則2

判定: REQUEST_CHANGES

- [Warning] 語対応の試験件数がまだ食い違っています。

  本文では対応する組として次の8組を列挙しています。

  - status/statuses
  - class/classes
  - policy/policies
  - value/values
  - kind/kinds
  - case/cases
  - response/responses
  - use/uses

  加えて対応しない2組なので、合計10組です。しかしテスト計画は「対応する6組・対応しない2組」となっています。

  修正案: 「対応する8組・対応しない2組」に統一してください。再測定結果の「期待値10組」とも一致します。

最大マッチングと増補路の試験は十分です。

---

## S6

判定: APPROVE

`subsetReason`の判別型、trim後30文字、登録例はいずれも整合しています。

---

## S7: relationとlocator解決

判定: REQUEST_CHANGES

ASTから同じ採番器でlocatorを解決する設計は妥当です。

- [Warning] subsetの限界について、まだ次の記述が残っています。

  > `note` の記述とレビューで担保する

  修正案: 「`subsetReason` の記述とレビューで担保する」へ変更してください。型定義、validation、AGENTS.mdと同じ用語へ統一します。

- [Suggestion] `ResolvedEnumTsRelation` の生成処理が、値集合の比較より先にlocatorを解決することを明記してください。値不一致でも登録済みlocatorの母集団は変わらず、前向き診断と逆走査が同じ解決結果を共有できます。

---

## S8: 逆走査gate

判定: APPROVE

locator、申告、stale、登録済み除外、判定保留が同じ採番空間へ統一されています。失敗メッセージもequal/subsetを区別できています。

- [Suggestion] 名称変更に合わせ、診断冒頭の「未登録のミラー候補」は「未登録のPHP・TS関係候補」などへ変えると一貫します。
- [Suggestion] `ApiErrorCode` exemption理由の「道具固有の符号」も「非canonicalな面固有の符号」へ揃えると、S11の用語と一致します。

---

## S9〜S10

判定: APPROVE

三値をまたぐlocator試験と検査Bの結線試験まで含まれており、共通規約(c)を満たしています。

---

## S11

判定: APPROVE

API符号の分類、公開面の確認、OAuth scopeのsubset関係はいずれも妥当です。

- [Suggestion] `API_ERROR_CODES` のdocblockに残る「ここに道具固有の符号を混ぜない」も、「ここに非canonicalな面固有の符号を混ぜない」へ統一してください。

---

## S12〜S13

判定: APPROVE

乖離台帳、文書の責務分離、locatorとsubsetReasonの記載方針は妥当です。S7本文の残存する `note` 表記だけ修正してください。

---

## 全体判定

CHANGES_REQUESTED

残る修正は小規模ですが、次の3点は実装前に確定が必要です。

1. `nameResolved: false` のswitchにも必ずlocatorを与え、locator用構文名と規則2用解決名を分離する
2. 語対応テストを「対応8組・非対応2組」へ訂正する
3. subsetの根拠欄を残存する `note` から `subsetReason` へ統一する

この3点以外に、Criticalおよび設計方向を変える問題はありません。