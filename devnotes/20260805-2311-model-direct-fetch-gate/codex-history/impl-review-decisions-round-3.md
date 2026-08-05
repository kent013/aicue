# 対応マトリクス: impl-review Round 3 (最終)

Round 3 は規定の最終ラウンド (最大 3)。Codex の判定は `CHANGES_REQUESTED` で、
指摘された [Critical] 7 件はすべて本ラウンドで修正した。**修正後の再レビューは
ラウンド上限のため行っていない** (この点は最終報告に明記する)。

## [Critical] provenance 判定が代入順序と再代入を考慮していない
- 判断: 対応する
- 根拠: `$dto = $input; User::find($dto->user_id); $dto = User::firstOrFail();` で
  **後段の安全な代入が前段の危険な値を安全扱いする**。候補が inventory 登録すら
  要求されずに消えるので、これまでの指摘の中で最も重い fail-open。
- 対応内容: `provenModelVariables()` を**時系列 (`provenTimeline()` / `provenAt()`)** に作り替えた。
  スコープを先頭から走り、代入ごとに証明の付与/失効を反映したスナップショットを積む。
  候補は**自分の位置までのスナップショット**を持つ。relation 起点の証明も
  「その代入時点で基底が証明済み」かで判定する (不動点ループは順方向 1 パスに置き換わった)。

## [Critical] `queryResultVariables` の時間順序欠落
- 判断: 対応する
- 対応内容: 同じく `queryResultTimeline()` / `queryResultAt()` に作り替え、再代入で失効させる。

## [Critical] `identityAssignedFromRelationQuery()` の代入順序無視
- 判断: 対応する
- 対応内容: 副条件の判定を走査時 (絶対位置が分かる場所) へ移し、
  `lastBindingOf()` で**候補位置の直前の束縛 1 つだけ**を見るようにした。
  代入と `foreach` 束縛の両方を対象にする。結果は候補の
  `tenantScopedIdentity` / `sameMethodScanIdentity` / `parameterDerivedIdentity` に保持し、
  公開ヘルパはそれを読むだけにした (methodSource の再トークン化に依存する脆さも同時に消えた)。

## [Critical] PHPDoc 型証明がファイル全体の変数名マップ
- 判断: 対応する
- 対応内容: `@var` を**行番号付き**で収集し (`docVarDeclarationsOf()`)、
  トークンにも行番号を持たせた。適用は「宣言行が当該スコープの行範囲内」かつ
  「宣言行以降のトークン位置」に限る。Unit fixture (別メソッドの `@var` は効かない) を追加。

## [Critical] array 形が最初の主キーエントリしか返さない
- 判断: 対応する
- 対応内容: `arrayFormPredicate()` → `arrayFormPredicates()` (複数返し) にし、
  `predicateAt()` / `columnPredicate()` の戻り値も list 化した。Unit fixture を追加。

## [Critical] `findOr()` が検出集合に無い
- 判断: 対応する
- 対応内容: `find` / `findOrFail` / `findOrNew` と同じ扱いで `findOr` を追加。Unit fixture を追加。

## [Critical] 直接形 QueuePayloadRehydration が dispatch 引数の provenance を見ていない
- 判断: 対応する
- 対応内容: `enqueuedBy` の本文から `{JobClass}::dispatch(...)` の引数を取り出し、
  request 入力の直読み (`$request->` / `request(` / `->input(` / `->validated(` /
  `->query(` / `->post(` / `->json(`) を含むなら fail させる。
  (メソッドをまたぐ完全なデータフロー解析は範囲外なので「直読みでない」ところまで。
   この限界はテストのコメントに残した)

## [Critical] Unit fixture 不足
- 判断: 対応する
- 対応内容: 指摘の 6 種すべてに fixture を追加した
  (安全な代入が候補より後 / 安全な代入後に untrusted 再代入 / 別メソッドの `@var` /
   array 形の複数 id 条件 / `findOr` / 副条件側の代入順序)。
  request 値を直接 dispatch する形は**アーキテクチャテスト側の機械副条件**で塞いだ
  (走査器の責務ではなく inventory 副条件の責務であるため)。

## [Suggestion / v1 既知限界] `literalIsInsideGuardedBlock()` は条件内の `!` を一律に拒否する
- 判断: 見送る (docblock に限界として記録)
- 根拠: Codex 自身が「セキュリティ上は安全側 (fail-closed) なので docblock への記録で十分」と
  評価している。現行 routes はこの形に該当しない。
