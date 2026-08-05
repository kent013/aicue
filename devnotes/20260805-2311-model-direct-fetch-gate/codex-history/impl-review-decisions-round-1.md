# 対応マトリクス: impl-review Round 1

## [Critical] `orWhere` / `orWhereIn` / `whereNotIn` / `where('id','!=',…)` が素通りする
- 判断: 対応する
- 根拠: 検出漏れ (fail-open) であり本 gate の最悪の失敗モード。`where` だけ見て `orWhere` を
  見ないのは走査器の欠陥で、逃げ道として最も安易。
- 対応内容: 列名を第 1 引数に取る述語を `COLUMN_PREDICATES` 定数
  (`where` / `orWhere` / `firstWhere` / `whereIn` / `orWhereIn` / `whereNotIn` / `orWhereNotIn`) に集約。
  `orWhereKey` / `orWhereKeyNot` も key 述語へ追加。3 引数形の演算子に `!=` / `<>` / `not in` を足し、
  これらは `IdentityExclusion` として分類する。Unit fixture を追加。

## [Critical] 動的列名 (`where($column, $payloadId)`) が候補にも guard にも出ない
- 判断: 対応する (ただし設計の「0 件固定」ではなく明示 inventory にした)
- 根拠: `$column = 'id';` で gate を黙らせられるのは実在する回避手段。ただし実測すると
  0 件ではなく 3 件 (`MembershipScopedOrganizationBinder` の binding field / 通知の dedup 列)
  あるため、0 件 assertion では成立しない。
- 対応内容: `PrimaryKeyStaticQueryScanner::dynamicColumnPredicates()` を追加し、
  `DirectFetchInventory::reviewedDynamicColumnPredicates()` (記述子 => 理由) と
  **双方向整合**するテストを追加。理由は 30 文字以上を要求する。

## [Critical] group use / 複数 use を無視するためモデル解決に失敗し候補が消える
- 判断: 対応する
- 根拠: 「書き方を変えると候補が消える」は fail-open そのもの。
- 対応内容: `importsOf()` を書き直し、`use A\{B, C as D};` と `use A, B;` を展開する。
  Unit fixture (group use / group use + alias) を追加。

## [Critical] raw guard が quoted identifier と raw variant を漏らす
- 判断: 対応する
- 根拠: 同上 (書き方で guard を回避できる)。
- 対応内容: `RAW_PREDICATES` を `whereRaw` / `orWhereRaw` / `havingRaw` / `orHavingRaw` /
  `whereIntegerInRaw` / `orWhereIntegerInRaw` / `whereIntegerNotInRaw` / `orWhereIntegerNotInRaw` に拡張。
  SQL 側は `` ` `` / `"` / `[` / `]` を空白へ潰してから `id` を照合する。Unit fixture を追加。

## [Critical] `queryResultVariables()` が `$obj->method()` を受理し sameMethodQuery が形骸化する
- 判断: 対応する
- 根拠: 指摘のとおり「任意 object のメソッド結果を foreach しただけ」で副条件が通る。
  分類語彙が形骸化すれば deny-by-default の意味が消える。
- 対応内容: `queryResultVariables()` を**クラス起点 (`Model::` / `DB::`) の代入のみ**に絞った。
  relation 起点でテナントに閉じている形は `IdDerivedFromTenantScopedQuery` の担当なので
  責務が割れることもない。Unit fixture (`$input->ids()` は false / class-rooted pluck は true) を追加。

## [Critical] `whereKey($id)->delete()` が `DestructiveIdentity` にならない
- 判断: 対応する
- 根拠: `QueuePayloadRehydration + DestructiveIdentity` を禁止した設計意図が、
  書き方 1 つで無効化されるのは許可表の意味を失わせる。
- 対応内容: chain の最終 depth-0 呼び出しが `delete` / `forceDelete` / `restore` / `truncate` なら
  predicateKind を `DestructiveIdentity` へ昇格する。`update` は含めない
  (CAS 更新は識別子による削除と危険度が違い、含めると既存の正当な CAS 経路まで巻き込むため。
  理由は定数の docblock に明記)。Unit fixture を追加。

## [Critical] 抜け道 fixture が不足している
- 判断: 対応する
- 対応内容: 上記 6 件それぞれに Unit fixture を追加 (合計 +7 テスト)。

## [Warning] LocalOnly の登録条件確認がファイル全体の文字列一致で弱い
- 判断: 対応する
- 対応内容: `literalIsInsideGuardedBlock()` を追加し、**route 名リテラルが
  `isLocal` / `runningUnitTests` を含む条件式のブロック内にある**ことを波括弧の対応で確認する。
  併せて route の action が候補のコントローラを指していることも確認する
  (無関係な local route を借りて通せないようにする)。

## [Warning] delegated QueuePayloadRehydration の検証が弱い
- 判断: 対応する
- 対応内容: `enqueuedBy` の**メソッド本文が実在する**ことを必須にし、その本文が
  `->{候補メソッド}($this->` を呼んでいることまで確認する
  (メソッド名の一致だけだと job のどこかに同名呼び出しがあれば通ってしまう)。

## [Warning] 債務 case の `todoRef` が TODO ID でなく概念設計ファイル
- 判断: 一部対応する (TODO ID にはできない)
- 根拠: 本実装セッションは `docs/TODO.md` の変更を明示的に禁止されている
  (TODO のクローズを別担当が直列で行うため、同一ファイルを触ると必ず競合する)。
  したがって後続 TODO を起票できず ID を採番できない。
- 対応内容: 概念設計ではなく**専用の追跡ファイル**
  `devnotes/20260805-2311-model-direct-fetch-gate/follow-up-todo.md` を新設し、
  起票内容 (タイトル / テーマ / 優先度 / 対象 3 件 / 是正方針 / 完了時に何を消すか) を書いた。
  main 取り込み担当が `/app-todo-add` を実行して ID を採番し、`todoRef` を
  `aicue:T<番号>` へ置き換える手順もファイル内に明記した (gate は両形式を受理し実在を検証する)。

## [Warning] 追加 2 case は機械証明が弱い
- 判断: 一部対応する
- 根拠: 実コードに実在する 8 件を分類する語彙が設計の 7 case に無かったため追加は必要。
  ただし「弱い」という指摘は正しい。
- 対応内容: `IdDerivedFromSameMethodQuery` の副条件をクラス起点クエリ結果に限定して締めた (上記)。
  `IdSuppliedByInternalCaller` は private + 引数由来 + request accessor 無し + calledBy の
  実在呼び出しの 4 条件を維持し、「呼び出し元の provenance は機械証明できない」ことを
  enum の docblock に明記済み (public メソッドには使えない)。
