# 実装ノート: T116 (詳細設計からの逸脱と、その理由)

詳細設計 (`detailed-design.md`) に対する実装時の差分を記録する。
逸脱はすべて「走査器を実コードに流した結果」または「Codex 実装レビューの指摘」に基づく。

## 1. 分類 case を 7 → 9 に増やした

設計の 7 case では実在候補 8 件を分類できなかったため、`DirectFetchJustification` に 2 case 追加した
(**ファイルは設計どおり enum 1 本のみ**。既存アプリコードは 1 行も変更していない)。

| 追加 case | 実在件数 | 実体 |
|---|---|---|
| `IdDerivedFromSameMethodQuery` | 6 | stale 回収 / 整合回復の保守走査 (`$ids = RenderJob::query()->…->pluck('id')` の各要素を引き直す形) |
| `IdSuppliedByInternalCaller` | 2 | private ヘルパが呼び出し元から id 配列を受け取る (`OrganizationMembershipService::lockForMembershipWrite`) |

どちらも機械副条件を付けてある (前者はクラス起点クエリ結果由来 + request accessor 無し、
後者は private + 引数由来 + request accessor 無し + `calledBy` の実在呼び出し)。

## 2. 債務 case の件数上限を 2 → 3 にした

設計は「実在 2 件」を前提にしていたが、実測すると同じ形が 3 件あった
(`McpConsentOrganizationBinder::handle` の consent payload `organization_id`)。
上限 = 現在値なので「4 件目で fail」という増殖防止の効果は変わらない。

## 3. `todoRef` に TODO ID を書けなかった

本実装セッションは `docs/TODO.md` の変更を禁止されている (TODO クローズを別担当が直列で行うため)。
そのため後続 TODO を起票できず、代わりに専用の追跡ファイル
`devnotes/20260805-2311-model-direct-fetch-gate/follow-up-todo.md` を新設して指している。

gate は `todoRef` の実在を機械検証する。受理形は
**`aicue:T<番号>` (docs/TODO.md か docs/TODO-closed.md に実在)** または
**`devnotes/{dir}/follow-up-todo.md` (実在)** の 2 つだけで、任意のファイルは通らない。

> **main 取り込み担当への申し送り**: `follow-up-todo.md` の内容で `/app-todo-add` を実行し、
> 採番された ID で `DirectFetchInventory` の `todoRef:` 3 箇所を置き換えること。

## 4. 走査器の判定を「時系列」にした (Codex Round 3)

provenance 証明とクエリ結果変数の集合を**候補位置までの状態**で評価する。
スコープ全体から先に集めると
`$dto = $input; User::find($dto->user_id); $dto = User::firstOrFail();` のように
後段の安全な代入が前段の危険な値を安全扱いし、候補が inventory 登録すら要求されずに消える。

同じ理由で、副条件 (`identityAssignedFromRelationQuery` /
`identityDerivedFromSameMethodQuery` / `identityDerivedFromMethodParameters`) の判定も
走査時に確定させ、候補位置の直前の束縛 1 つだけを見る。

## 5. provenance 証明 (第 1 段) を 3 → 4 手段にした

設計の 3 手段 (型付き引数 / PHPDoc / relation 起点代入) に加えて:

- **モデル起点クエリの実行結果からの代入** (`$job = RenderJob::query()->find($this->id);`)
  — 代入式そのものが候補として分類を要求されるので循環しない
- **同一クラスのメソッド呼び出しで戻り値型宣言が `App\Models\*`**
  (`$organization = $this->resolveOrganization($project);`) — 宣言型は実行時に強制される

relation 起点は「基底変数が既にモデルと証明済み」の場合しか受理しない (Codex Round 2)。

## 6. 動的列名は「0 件固定」ではなく「明示 inventory」にした

設計は `whereRaw` のみ 0 件固定としていたが、動的列名 (`where($column, $x)`) も
`$column = 'id';` で gate を黙らせられる回避手段である (Codex Round 1)。
実測 3 件と 0 件ではないため、`DirectFetchInventory::reviewedDynamicColumnPredicates()` へ
理由付きで登録させ、双方向整合で見張る形にした。

## 7. request accessor 判定を「入力を読む呼び出し」に限定した

`$request` を素通しで別メソッドへ渡すだけの使用と `request()->attributes`
(middleware がサーバ側で確定させるバッグ) は accessor に数えない。
設計の fixture 19 が挙げる呼び出し形 (`->input()` / `->query()` / `->validated()` / `request(…)`) が正本。

## 8. その他 (Codex 指摘由来の検出強化)

- `orWhere` / `orWhereIn` / `whereNotIn` / `orWhereNotIn` / `orWhereKey(Not)` / `findOr` を検出集合に追加
- 3 引数形の `!=` / `<>` / `not in` を `IdentityExclusion` として分類
- array 形 where は**全**主キーエントリを候補にする (最初の 1 件で打ち切らない)
- chain が `delete` / `forceDelete` / `restore` / `truncate` で終わるなら `DestructiveIdentity` へ昇格
  (`update` は含めない。CAS 更新は識別子による削除と危険度が違う)
- group use / 複数 use を展開する (無視するとモデル解決に失敗して候補が消える)
- raw guard は quoted identifier (`` `id` `` / `"id"` / `[id]`) と大文字 (`ID`) も検出する
- `LocalOnlyDiagnostics` は route 名リテラルが `isLocal` / `runningUnitTests` を条件に持つ
  `if` ブロックの中にあることまで確認する (否定条件は受理しない)
- `QueuePayloadRehydration` は dispatch 元が当該 job を実際に dispatch していること、
  その引数が request 入力の直読みでないことまで確認する

## 実測値 (実装完了時点)

| 項目 | 件数 |
|---|---|
| 候補 (要分類) | 34 |
| 動的列名 (別 inventory) | 3 |
| raw 主キー述語 | 0 |
| 非主キー一意列によるモデル解決 | 0 |
| 同一 fingerprint の重複 | 0 |
| 債務 case | 3 |
