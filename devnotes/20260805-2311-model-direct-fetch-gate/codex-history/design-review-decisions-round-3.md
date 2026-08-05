# 対応マトリクス: design-review Round 3 (最終)

**全体判定 APPROVED**。施策 1〜7 すべて APPROVE。
残った [Warning] 5 件はすべて設計へ反映済み (下記)。**未解決の Critical / Warning は無い**。

## [Warning] `where('code', …)` の除外を Plan 限定にする

- 判断: **対応する**
- 根拠: 正しい。列名だけで除外すると将来 `OrganizationInvitation::where('code', $payload)` の
  ような**テナント資源**が生えても検知できない。
- 対応内容: 除外条件を「root が `Plan` / `DB::table('plans')` のときのみ」に限定し、
  「列名だけで除外しない」理由を設計に明記。

## [Warning] テスト 13 の検出構文を `where(...)` だけに閉じない

- 判断: **対応する**
- 対応内容: 対象構文に `firstWhere('uuid'|'slug'|'public_id'|'ulid', …)` と
  magic where 形 (`whereUuid(` / `whereSlug(` / `wherePublicId(` / `whereUlid(`) を追加。
  字句パターンの追加のみで v1 の範囲に収まる。

## [Warning] `QueuePayloadRehydration` の enum docblock が許可表とずれている

- 判断: **対応する**
- 対応内容: docblock に predicateKind 別の形を明記
  (`SingleIdentity` = `$this->{…Id}` / `MultiIdentity` = `$this->{…Ids}` /
  `IdentityExclusion`・`DestructiveIdentity` = v1 禁止)。

## [Warning] テスト 15 は警告でなく fail にする

- 判断: **対応する**
- 根拠: 正しい。CI の警告は運用上ほぼ見落とされ、「人間の明示確認を強制する」目的を果たさない。
- 対応内容: duplicate fingerprint group が存在したら **fail**。
  `DirectFetchInventory::reviewedDuplicateFingerprints()` に group 単位で明示登録したものだけ許可し、
  登録時に `chainSource` preview の確認を強制する。

## [Warning] `OwnerScopedQueryConstraint + DestructiveIdentity` は成立しにくい

- 判断: **対応する (✕ に寄せる)**
- 根拠: `Model::destroy($id)` は静的削除で**同一 chain に owner scope を足せない**。
  ○ のままだと case の定義 (「同一クエリ内で閉じている」) と矛盾した許可表になる。
- 対応内容: 許可表を ✕ に変更し、理由 (スコープ付き削除は relation 起点 =
  そもそも候補外の `$organization->users()->whereKey($id)->delete()` を使う) を併記。

---

## 実装コストについての Codex 回答 (申し送り)

> 実装コストは現実的。最大の山は scanner だが、第 2 段 provenance と route binding 突合を
> v1 から外したことで過大ではなくなった。v2 送りにするなら、これ以上削るより、
> **実装中に 50 件超の候補が出た場合だけ分類粒度を再検討する**方針でよい。

この方針を詳細設計「リスク」表の申し送りと一致させて維持する。
