# 対応マトリクス: impl-review Round 1

## [Critical] 検出 B の名指し免除が DB クエリ形の判定を素通しする

- 判断: **対応する**
- 根拠: 指摘は正しい。免除の前提は「`->adoptedTake` のプロパティフェッチを持たない」だけだったため、
  免除ファイル内に `whereHas('adoptedTake', fn ($q) => $q->where('status', TakeStatus::Ready->value))` と
  書けば gate を素通りできた。これは動的アクセスのような特殊形ではなく Eloquent で最も普通に書ける形で、
  「判定式を書いてよいのは Canonical 1 ファイル」という不変条件を実質的に弱めていた。
  Codex 提示の選択肢 1 (PipelineSmokeCommand の分割) は、bug-hunt 用の開発コマンドを gate の都合で
  分解することになり本末転倒 (思考原則 2「今必要なものだけ作る」に反する) なので、選択肢 2 を採った。
- 対応内容:
  - 免除の前提を **2 層**にした (`criterionExemptionPremiseHolds()`)。
    - 前提 1 (in-memory 形): 検出 A' (`->adoptedTake` / `?->adoptedTake`) を持たない
    - 前提 2 (DB クエリ形): `'adoptedTake'` を引数に取る**呼び出しの引数リストの中**に
      `TakeStatus::Ready` も `'status'` も現れない
      (`hasCriterionInRelationArgument()` が括弧の対応を取って引数リストを切り出して判定する。
      `whereHas` のクロージャ形も `whereRelation('adoptedTake', 'status', 'ready')` も捕まる)
  - scanner 自己検証テストを 1 件追加 (クロージャ形 / whereRelation 形を true、
    素の `doesntHave('adoptedTake')->count()` と「relation 引数の外にある ready 参照」を false)
  - ケース 8 のテスト名と失敗メッセージを 2 層前提に合わせて更新
  - **mutation で実証**: M10 (免除ファイルの `doesntHave` を DB クエリ形の判定に変える) を実施し、
    前提 2 追加前は green、追加後は ケース 8 が fail することを確認した (mutation-evidence.md M10)
  - 「保証しないもの」も更新 (前提 2 が見るのは `'adoptedTake'` を含む呼び出しの引数リストだけで、
    relation の id を別クエリで取り出して後段で status を判定する形には沈黙する)

## [Warning] `AdoptedTakeReferenceInventory` の PipelineSmokeCommand の根拠が誤解を招く

- 判断: **対応する**
- 根拠: 指摘のとおり。「ready 状態は見ず」は同ファイルに `TakeStatus::Ready` が実在する事実と
  読み手の中で衝突し、免除判断の材料として誤解を招く。
- 対応内容: 根拠を「**adoptedTake 参照側は** ready を見ない (別の `TakeStatus::Ready` 参照は
  登録直後のテイク自身の確認であって採用テイクの充足判定ではない)」へ書き換えた。
  併せて `COOCCURRENCE_EXEMPT` 側の根拠も「in-memory 形も DB クエリ形も持たないことを
  `criterionExemptionPremiseHolds()` が機械検査する」と、何が機械保証なのかを明示する文面にした。

## その他

Codex は他の全ファイルを OK と判定し、`playbackJobId` → `playbackJob` の追随、
`placeholder_cut_count` の manifest 由来記録、preview を 422 にしない非対称、ボタン非 disabled 方針、
`null` と `0` の扱い、`coverage` を project_member に返す点についても設計と一致していると確認した。
これらは変更していない。
