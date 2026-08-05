# 対応マトリクス: conceptual-review Round 2

## [Critical] 観点3: 判断 8 (default 付け替え) と判断 6 (既存 2 メソッドの合成のみ) が両立しない / config 保存が 2 回になり中間状態が永続化する

- 判断: **対応する** (「2 メソッド再利用のみ」の制約を撤回する)
- 根拠: 完全に正当。実装を追うと指摘どおりである。
  `FileProfileWriter.deleteProfile(name, { clearDefault: true })` は
  `profiles` からの除去と `default_profile` の削除を**1 回の `save()`** で行う
  (`writer.ts:156-176`) が、そのあとに `useDefaultProfile(next)` を呼ぶと
  **2 回目の `save()`** になる。1 回目と 2 回目の間で config は
  「default_profile 不在」の状態でディスクに書かれ、2 回目が失敗すればそれが永続化する。
  「削除は原子的だが default 遷移は非原子的」という中途半端な設計になっていた。
- 対応内容: **判断 6 を書き換え**、`ProfileWriter.deleteProfile()` に
  `nextDefault?: string` を追加して**削除と default 遷移を 1 回の `save()` に畳む**。
  - `ProfileWriter` interface と `FileProfileWriter` 実装の両方を変更する (型付き API のまま)
  - 既存呼び出し元 (`profile/add.ts` のロールバック 2 箇所) は opts 省略で挙動不変 =
    後方互換の並走は生まれない (思考原則 3 に抵触しない)
  - `nextDefault` の検証 (存在する / 削除対象自身ではない) は writer 側で行い、
    コマンド側が config 内部構造に触らないようにする
  - 受け入れ条件の「`writer.ts` に変更が無い」を撤回し、
    「`store.ts` / `file-store.ts` は無変更。`writer.ts` は `deleteProfile` の
    シグネチャ拡張のみ」に改める
  - **writer 単体の原子性テスト**を施策 4 に追加
    (1 回の `deleteProfile` 呼び出し後に `profiles` 除去と `default_profile` 付け替えが
    同時に反映されている / `nextDefault` が不正なら**何も保存されない**)

## [Warning] 観点3: 「例外をそのまま伝播」と「stderr へ再実行案内」が両立しない

- 判断: **対応する**
- 根拠: 正当。何もしなければ案内は出ない。
- 対応内容: 契約を明文化した。
  「credential 破棄成功後の config 保存失敗は `catch` し、
  **stderr に再実行コマンドを実文字列で出力**したうえで**元の例外を再 throw** する」
  (新しい例外型は作らない = 型を増やさない)。
  施策 4 に「部分失敗時に再実行コマンド文字列が stderr に出る」テストを追加。

## [Warning] 観点3: 冪等性の根拠が FileStore に限定されている (keychain を含む 3 backend で保証が要る)

- 判断: **対応する** (実装を実査して根拠を補強したうえで、テストも 3 backend 共通化)
- 根拠: 指摘を受けて `KeychainStore.delete()` を実査した
  (`src/credential/keychain.ts:130-152`)。not-found は `isNotFoundError()` で
  握って `return` しており、成功扱いになっている。
  `CredentialStore.clearProfile()` (`store.ts:227-235`) も
  index 不在時は `readIndex()` が `[]` を返すため空ループになり、
  meta index の delete も backend 側で not-found 吸収される。
  つまり 3 backend すべてで冪等だが、**設計書がその根拠を FileStore だけで書いていた**のは不備。
- 対応内容: 判断 6 の冪等性節に 3 backend それぞれの根拠 (ファイル:行) を明記。
  施策 4 の冪等性テストを **3 backend 共通ケース**として回す旨を明記。

## [Warning] 観点4: 判断 2 の逆転指標が品質を測れていない (「指摘ゼロ」を劣化と誤判定する偽陽性)

- 判断: **対応する** (指標を全面的に差し替える)
- 根拠: 完全に正当。良い設計なら Warning ゼロが正常であり、
  「指摘が出ない = モデルが痩せた」は論理として成立しない。
  Round 1 の指摘 (「粗い」) に対し、粗さだけ直して**方向が間違っていた**。
- 対応内容: 指標を**逸失欠陥 (escaped defect) の追跡**に差し替えた。
  - 観測対象: 一本化後の概念設計 5 件について、
    **後続フェーズ (詳細設計レビュー / 実装レビュー / 実装中の手戻り) で
    初めて発見された「概念設計段階で気づけたはずの欠陥」**を数える
  - 分類: その欠陥が「自然言語的な設計論点 (使命整合・スコープ・リスク)」に属するか
    「コード寄りの論点」に属するかを記録する
  - 判定: 前者が 5 件中 **3 件以上**で発生したら、一本化が概念設計面を痩せさせたと判定する
  - 併記: 可能なら同一概念設計を旧モデル (`gpt-5.4`) にも通す盲検比較を行う。
    ただしこれはコストが高く、**必須にはしない** (逸失欠陥追跡を主指標とする)
  - 判定時期・戻し方は Round 1 の対応どおり (5 件到達時点 / `docs/template-divergence.md` 起票が先)

## [Warning] 観点4: 「旧世代モデルは実際に劣る」が「未計測の仮説」と矛盾する

- 判断: **対応する**
- 根拠: 正当。断定と留保が同一文書内に同居していた。
- 対応内容: 課題 A の文言を
  「一般的な世代更新による改善を期待するが、**本リポジトリのレビュー品質への効果は未検証**」
  に統一した。判断 2 の理由 2 も同じ表現に揃えた。

## [Warning] 観点5: 部分失敗時の利用者誘導が「再実行可能」だけでは不十分

- 判断: **対応する**
- 根拠: 正当。壊れた profile が残っている間、他のコマンドがそれを引くことがある。
- 対応内容: 施策 4 の部分失敗テストの検証軸を 3 点に固定した。
  1. config 側に profile が残る (= 状態が観測可能)
  2. **具体的な再実行コマンド文字列**が stderr に出る
  3. 同じコマンドの再実行で収束する (credential 不在パスを通って成功)

## [Warning] 観点2 / 観点6: AGENTS.md への登録が `test:packages` だけで `typecheck:packages` が抜けている

- 判断: **対応する**
- 根拠: 正当。ローカル受け入れ条件・CI・AGENTS.md の 3 箇所が不一致だった。
- 対応内容: 施策 5 を「packages の検証契約 = `typecheck:packages` + `test:packages` の**セット**」
  として定義し直し、3 箇所すべてに両方を登録する旨を明記した。

## [Warning] 観点7: default 付け替えの型境界が未確定 / コマンドが ProfileWriter 抽象を迂回しうる

- 判断: **対応する**
- 根拠: 正当。Critical への対応と同根。
- 対応内容: §型安全方針 に
  「**削除と default 遷移は `ProfileWriter` の型付き API 経由のみ**。
  コマンド層は `RootConfigInput` / `ProfileEntry` を直接組み立てない」を追加した。

## [Suggestion] 観点1 / 観点4 / 観点5 / 観点6 / 観点7 の肯定的評価

- 判断: 対応不要
