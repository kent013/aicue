# 対応マトリクス: design-review Round 3

すべて「対応する」。Critical 2 件は設計変更、Warning 1 件は再測定で確認した。

## [Critical] S4/S7/S8: 候補・申告・判定保留の同一性が `file + name` では衝突する
- 判断: 対応する (**設計を変更**)
- 根拠: 指摘のとおり。入れ子の宣言まで拾う設計にしたのに同一性を広げていなかった。
  同じファイルの別スコープに同名の宣言が合法的に共存でき、
  申告の巻き添え免除・登録による入れ子候補の消失・stale 判定の誤りが起きる。
- 対応内容: **locator** を導入した。
  `TsCandidateLocator = { file, shape, name, occurrence }` で、`occurrence` は
  同じ `(file, shape, name)` の中の**ソース位置順の 0 始まりの番号**である。
  - **行番号は同一性に入れない** — 無関係な行移動で申告が一斉に stale になるのを避けるため。
    行は診断にだけ使う。同名の宣言を前に足すと `occurrence` がずれて申告が stale になり
    赤くなる (人が見直す合図で fail-closed の向き)
  - 候補 / 判定保留 / 逆走査の申告 / 重複検査 / stale 検査 / 診断を**すべて locator へ統一**した
  - 前向きの目録は**最上位の宣言だけ**を指すので、候補に `topLevel` を持たせ、
    **登録済みと見なすのは `topLevel` が真で名前が一致する候補だけ**にした
    (`file + declaration` で全候補を外すと同名の入れ子候補まで消える)
  - 負の対照 (故障注入の表の #9) も足した

## [Critical] S2: 検査 B が「利用側の義務」で呼び忘れを構造的に防げない
- 判断: 対応する (**設計を変更**)
- 対応内容: `createMirrorPrograms()` が program を組んだ直後に**全仮想単位へ必ず検査 B を走らせ、
  検査を通った program だけを返す**一本道にした。低層の組み立て関数は**輸出しない**ので
  未検査の `MirrorProgram` を外から作る経路が型で消える。
  見本専用の縮めた program は**別の型 (`FixtureProgram`)** にして、
  検査済みを要求する場所へ渡せないようにした。
  故障注入 (#9') に「検査 B の呼び出しを外すと統合の検査が赤くなる」を足した。

## [Warning] S2: 検査 A の最上位束縛の列挙が不足している
- 判断: 対応する
- 対応内容: 共通の `topLevelBindingNames(statements)` を 1 本置き、
  変数宣言 (**分割代入の個々の束縛名を含む**) / 関数 / クラス / `enum` / `interface` /
  型別名 / `namespace`・`module` / 取り込みの束縛 (既定・名前つき・名前空間) /
  `import x = …` を拾うと明記した。
  負例を束縛の種類ごとに置く (**取り込み / `enum` / `namespace` / 分割代入**を含む)。

## [Warning] S2: 検査 B は別名宣言の位置も見る必要がある
- 判断: 対応する
- 対応内容: 「記号の `declarations` をまず見て、別名なら `getAliasedSymbol()` の先も見る。
  **実体側の取り込みを module 側が参照した場合、指す先は外部ファイルでも
  別名の宣言そのものは実体範囲の中にある**ので、そこで捕まえる」と書き、
  その負例をテスト計画へ足した。

## [Warning] S3b: `readConstArrayLiteralValues()` の三値の契約が矛盾している
- 判断: 対応する (「配列は構文リテラルだけ」を維持する最小案を採用)
- 対応内容: **形ごとの三値の境界を表**にした。
  定数の配列は**構文だけで判定する**ので `indeterminate` の分岐を持たない
  (識別子や呼び出し式が混ざったら型解決の成否によらず `not-a-catalogue`)。
  型解決が受理条件に要る 3 形 (計算キー / 型別名 / `case` の式) だけが `indeterminate` を持つ。

## [Warning] S3b: 5 つの抽出関数への直接の両方向試験が無い
- 判断: 対応する
- 対応内容: 「5 つの関数それぞれに該当する三値の分岐を直接置く (S4 経由の試験だけにしない)」を
  テスト計画へ書いた。

## [Warning] S4: `isUnresolvedType` の名前が `indeterminate` 契約と合わない
- 判断: 対応する
- 対応内容: **`isIndeterminateType`** へ改名した。

## [Warning] S5: `forms()` の説明例が定義と一致していない
- 判断: 対応する
- 根拠: 指摘のとおり。定義は「末尾 `s` を落とす」なので `case` / `use` / `response` の
  集合に `cas` / `us` / `respons` は入らない (入るのは複数形の側)。
- 対応内容: 例を**表**にして定義どおりに直し、
  「`cas` / `us` / `respons` は複数形の側の候補形として現れるだけで正規形として採用しない」と
  明記した。`forms()` そのものの期待値も語ごとにテストで固定する。

## [Warning] S5: 共通語数が一対一対応になっていない
- 判断: 対応する
- 根拠: 指摘のとおり。`forms(a) ∩ forms(b) ≠ ∅` は推移律を持たないので同値類に畳めず、
  単純に数えると候補側の 1 語が列挙側の複数語へ使い回される。
- 対応内容: **最大マッチング** (二部グラフ) で数える形へ変更した。語数は多くて 5 程度なので
  素直な増補路の探索で足りる。「列挙名に同じ語が 2 回出る形で一致数が 1 になる」試験を足した。

## [Warning] S5: 判定式の変更後に現物の再測定が無い
- 判断: 対応する
- 対応内容: `probe2.ts` を `forms()` + 最大マッチングへ直して**測り直した**。
  **鳴った組は 10 件のままで、規則別の内訳も変わらない** (規則 1 = 6 / 2a = 1 / 2b = 3)。
  語の対応の期待値 10 組もすべて期待どおり。診断も `語対応 2/2 語 主要語=status` の形になった。
  設計と `probe/measurements.md` の両方を更新した。

## [Warning] S6: subset 行の申告が `note` 非空だけでは強制にならない
- 判断: 対応する (推奨の判別された合併を採用)
- 対応内容: `EnumTsRelationEntry` を
  `{relation:"equal"; …} | {relation:"subset"; …; subsetReason: string}` の**判別された合併**にし、
  `subsetReason` (30 文字以上) を subset の行にだけ必須にした。

## [Warning] S7: `EnumTsMirror` という名前が subset 行を含むと不正確
- 判断: 対応する (改名する)
- 対応内容: 思考原則「機能の名前に立ち返れ」に従い、**同じ変更で機械的に改名**する —
  `mirror-inventory.ts` → `relation-inventory.ts` / `EnumTsMirror` → `EnumTsRelationEntry` /
  `ENUM_TS_MIRRORS` → `ENUM_TS_RELATIONS` / `EXPECTED_MIRROR_COUNT` → `EXPECTED_RELATION_COUNT` /
  `validateMirrors()` → `validateRelations()` /
  `registeredPhpPaths()` / `registeredTsKeys()` → `declaredPhpPaths()` / `declaredTsLocators()`。
  `AGENTS.md` 19 が旧名を名指ししているので S13 で同時に直す。旧名は別名として残さない。

## [Warning] S7: subset の非空条件が抽出器頼みになっている
- 判断: 対応する
- 対応内容: 「TS 側が空集合でないことを**関係の判定の側でも**明示的に見る」と書き、試験も足した。

## [Warning] S8: PHP 側の分類名が equal/subset の両方を包含していない
- 判断: 対応する
- 対応内容: 分類の呼び名を **「TS との関係を登録済み」** に改めた。

## [Warning] S8: 再測定前なので件数が確定値ではない
- 判断: 対応する
- 対応内容: 再測定した結果、**10 件・申告 8 件のまま変わらなかった**ので表は据え置く。
  「設計時の見積りであり実装時に数え直す」という但し書きは残してある。

## [Warning] S9: locator 衝突の負例と、検査 B の結線の故障注入が無い
- 判断: 対応する
- 対応内容: 故障注入の表に **#9 (locator の一意性)** と **#9' (検査 B の結線)** を足し、
  呼び方を「**9 カテゴリ + 境界試験 3 件**」に直した。

## [Suggestion] S11: スコープを足す順序の言い方
- 判断: 対応する
- 対応内容: 「サーバの値域へ**先に (または同じ変更で)** 足す。
  **サーバ側だけが先に増えるのは subset の契約上許される**」へ直した。

## [Suggestion] S11: `dispatchKindFromCode()` を公開 main から再輸出しない
- 判断: 対応する
- 対応内容: テスト計画にその 1 行を足した。

## [Warning] S13: locator と subset 目録の名称を文書へ反映する
- 判断: 対応する
- 対応内容: `docs/architecture.md` に書くことへ 2 項目を足した
  (入れ子候補の同一性は locator で持つ / `equal` と `subset` は同じ目録に載るが意味が違う)。
