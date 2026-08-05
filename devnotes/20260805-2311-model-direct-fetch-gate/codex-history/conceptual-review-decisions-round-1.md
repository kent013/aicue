# 対応マトリクス: conceptual-review Round 1

## [Critical] 検出規則が狭すぎる (`where('id', …)` で抜ける)

- 判断: **対応する**
- 根拠: 指摘のとおり。`User::query()->where('id', $request->input('user_id'))->firstOrFail()` は
  「payload 由来 id を tenant scope 外でモデル化する」ものそのものであり、旧案の
  「key **終端**」規則では 1 件も検出しない。§1 の成功条件を自分で満たしていなかった。
- 対応内容: 検出のアンカーを**終端メソッドから主キー同一性述語 (PK-identity predicate) へ移す**。
  - 述語: `find` / `findOrFail` / `findOrNew` / `findMany` / `whereKey` / `whereKeyNot` /
    `where('id', …)` / `whereIn('id', …)` / `firstWhere('id', …)` / `where($m->getKeyName(), …)`
  - これにより終端 (`first` / `sole` / `get` / `exists` / `delete` / `update`) を列挙する必要が
    無くなり、規則が**単純化しつつ広がる** (終端の網羅漏れという失敗モードが構造的に消える)。
  - 実測し直した結果、entrypoint 層の検出数は 12 → **13** (+1)。運用コストはほぼ変わらない。

## [Warning] `DB::table()` 経由の抜け道 (Round 1 では Codex 未指摘。自己検出)

- 判断: **対応する**
- 根拠: 述語アンカー化にあたり実測したところ `ResolveApiActor.php:146` に
  `DB::table('oauth_access_tokens')->where('id', $tokenId)` があった。root が静的である以上
  `Model::` と同じ抜け道になる (`DB::table('users')->where('id', $payloadId)` が素通りする)。
- 対応内容: 静的 root に `DB::table(…)` を含める。該当は 1 件のみで `AuthenticatedActorScope` に分類。

## [Warning] #1 (`OrganizationOwnershipController`) の扱いが「初期 green」と矛盾

- 判断: **対応する** (指摘どおり論理矛盾していた)
- 根拠: 「どの case にも当てはまらない」と書きながら「初期 inventory 全件 green」を成功条件に
  置いており、両立しない。
- 対応内容: case `PayloadIdVerifiedInLockedServiceTransaction` を**新設**する。ただし
  `PayloadIdWithCompensatingCheck` を広げる形は採らない (それは case を歪める)。新 case の
  適用条件は「検証が**行ロック下の named Service メソッド**で行われる」と狭く定義し、
  根拠文に `Class::method` を書かせ、その**クラスファイルが実在し `lockForUpdate` を含むこと**を
  機械検証する。case を増やして逃がすのではなく、**より強い条件を機械で確認する**方向で解く。

## [Warning] entrypoint 限定だと Service に id を渡す経路が抜ける

- 判断: **対応する** (設計を 1 段強くする)
- 根拠: 指摘は正しい。Controller が scalar id を Service に渡し Service 側で global fetch すると
  検出 A (sink 側) は沈黙する。「将来再検討」で流すのは gate として弱い。
- 対応内容: **検出 B (source 側) を追加**する。「entrypoint 層で request 由来の resource id scalar を
  読む箇所」を deny-by-default で inventory 登録させる。実測すると母集団は**わずか 5 件**で、
  追加コストが極小のわりに「id が entrypoint に入った瞬間」を押さえるため、
  fetch がどの層で起きても取り逃がさない。sink だけでなく source を押さえるほうが本質的だった。

## [Warning] `exists:users,id` の存在オラクルが残る

- 判断: **一部対応する** (gate の責務は広げない / 可視化はする)
- 根拠: 本 gate は「モデル取得の経路」を守るものであり、validation rule の存在漏れは別の攻撃面。
  ただし指摘どおり #1 #2 と**同じ 2 箇所**に集中しているため、切り離すと片手落ちに見える。
- 対応内容: 検出 B の根拠文に「その id に掛けている validation rule」を書かせる。これにより
  `exists:users,id` が inventory 上に**必ず現れる**。ルール自体の是正は後続 TODO のまま
  (振る舞い変更を伴うため。§7-1)。

## [Warning] 根拠文 30 文字だけでは case が形骸化する

- 判断: **対応する**
- 根拠: 妥当。`ControllerAuthorizationExemption` も文字数だけで守られているわけではない。
- 対応内容: case ごとに**機械副条件**を課す (完全解析はしない。安価に効く分だけ):
  | case | 機械副条件 |
  |---|---|
  | `OwnerScopedQueryConstraint` | 同一 chain 内に identity 述語**以外の** `where(` / `whereHas(` / `whereBelongsTo(` がある |
  | `PayloadIdWithCompensatingCheck` | 同一メソッド本体に既知 marker (`organizationRole(` / `organizations()` / `users()` / `whereHas(`) がある |
  | `PayloadIdVerifiedInLockedServiceTransaction` | 根拠文が `Class::method` を含み、そのクラスファイルが実在し `lockForUpdate` を含む |
  | `LocalOnlyDiagnostics` | 同一ファイルに `LocalOnly` / `isLocal` がある |
  | `AuthenticatedActorScope` | 機械条件なし (id の出所が actor であることは静的に決められない) — **この case のみ人手根拠に依存**する旨を明記 |

## [Suggestion] 使命との整合

- 判断: 対応不要 (肯定的評価)

## [Warning] `app/Http/Requests/**` を母集団に入れるなら validation も

- 判断: **反論する (母集団には残す)**
- 根拠: `app/Http/Requests/**` に fetch は 1 件も無い (実測 0 件) が、母集団から外すと
  「FormRequest に fetch を書けば通る」という抜け道になる。**0 件のまま母集団に置く**のが正しい
  (deny-by-default の空 inventory は最も安いガード)。validation rule 自体は上記のとおり検出 B で可視化する。
