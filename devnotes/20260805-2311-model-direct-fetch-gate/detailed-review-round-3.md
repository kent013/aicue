**全体判定: APPROVED**

Round 2 の Critical は実質解消されています。v1 で provenance 第 2 段を追わず、代わりに前提を 0 件 assertion で固定する判断は、実装コストと安全側 fail のバランスとして妥当です。実装へ進めてよい設計です。

ただし、下記 Warning は実装時に反映してください。特にテスト 13 は「第 2 段と同等」ではなく「現状の前提を壊させない代償措置」なので、線引きを少しだけ締める必要があります。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1. 分類 enum | APPROVE |
| 2. inventory エントリ型 | APPROVE |
| 3. 走査器 | APPROVE |
| 4. inventory 本体 | APPROVE |
| 5. gate 本体 | APPROVE |
| 6. 走査器 Unit テスト | APPROVE |
| 7. 規約ドキュメント登録 | APPROVE |

## 指摘

[Warning] **テスト 13 は妥当。ただし `where('code', …)` の除外は Plan 限定にするべき**

`uuid` / `slug` / `public_id` / `ulid` の 0 件固定は、provenance 第 2 段を v1 で落とす代償として妥当です。ただし `where('code', …)` を列名だけで丸ごと除外すると、将来 `Invitation::where('code', $payload)` のようなテナント資源が生えても検知できません。

修正案: `where('code', …)` の除外は `Plan` / `plans` 起点に限定してください。列名だけの除外ではなく、`Plan::...where('code')` または `DB::table('plans')->where('code')` のように root とセットで扱うべきです。

[Warning] **テスト 13 の検出構文は `where(...)` だけに閉じない方がよい**

非主キー一意列の見張りが `where('uuid'...)` だけだと、`firstWhere('uuid', ...)` や magic where 形を逃します。

修正案: 少なくとも次を対象にしてください。

- `where('uuid'|'slug'|'public_id'|'ulid', …)`
- `firstWhere('uuid'|'slug'|'public_id'|'ulid', …)`
- `whereUuid(...)` / `whereSlug(...)` / `wherePublicId(...)` / `whereUlid(...)`

これは完全なデータフロー解析ではなく字句パターン追加で済むため、v1 の範囲に収まります。

[Warning] **`QueuePayloadRehydration` の enum docblock が `MultiIdentity` 許可表とずれている**

enum 側は `$this->{…Id}` と書いていますが、case × predicateKind 表では `MultiIdentity` に `$this->{…Ids}` を許可しています。

修正案: docblock に `SingleIdentity は ...Id、MultiIdentity は ...Ids` と明記してください。

[Warning] **テスト 15 は “警告” ではなく fail にする必要がある**

Pest / CI では警告だけだと運用上ほぼ見落とされます。「人間の明示確認を強制する」が目的なら、duplicate fingerprint は fail にしてください。

修正案: 同一 fingerprint group が存在したら fail し、メッセージに `chainSource` preview を出す。既存コードに重複がある場合だけ、`DirectFetchInventory` に `reviewedDuplicateFingerprints()` のような明示 inventory を持たせる形がよいです。

[Warning] **`OwnerScopedQueryConstraint + DestructiveIdentity` は表では許可だが、実際には成立しにくい**

`Model::destroy($id)` は静的削除なので同一 chain に owner scope を足せません。機械副条件で落ちるなら実害は薄いですが、許可表としては誤解を招きます。

修正案: v1 では `OwnerScopedQueryConstraint + DestructiveIdentity` を ✕ に寄せる方が設計の意味が明確です。必要な削除は `where(...)->delete()` 側で別に扱うべきです。

## 確認点への回答

1. **テスト 13 は代償措置として妥当**です。ただし第 2 段 provenance と同等の証明ではありません。「現状 0 件の前提が崩れたら設計に戻す」ための guard として扱うのが正しいです。`code` 除外は Plan 限定にしてください。

2. **case × predicateKind 表は概ね妥当**です。`QueuePayloadRehydration` の docblock 反映と、`OwnerScoped + Destructive` の ✕ 化だけ調整推奨です。

3. **routes 疑似スコープ設計で route closure は捕まります**。`__closure{n}` / `__fn{n}` は十分です。Unit fixture 18 は必須です。

4. **実装コストは現実的です**。最大の山は scanner ですが、第 2 段 provenance と route binding 突合を v1 から外したことで過大ではなくなりました。v2 送りにするなら、これ以上削るより、実装中に 50 件超の候補が出た場合だけ分類粒度を再検討する方針でよいです。