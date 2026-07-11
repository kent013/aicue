# Round 3: Round 2 指摘への対応報告

Round 2 の Critical 1 件・Warning 2 件に対応した。再レビューし、全体判定を出してほしい。

## 対応マトリクス

### [Critical] 自己修復と membership 削除の競合 → 対応済み（防御線を「読み出し時所属再確認 + 原子的条件付き UPDATE」に設計変更）

ロック直列化を採らない根拠: `removeMember` の current null 化は PHP 側の**メモリ上 stale 値比較**（`$target->current_organization_id === $organization->id`）で行われるため、healer 側が User 行ロックを取得しても removeMember 側の条件評価が古い値で走る限り dangling window はゼロにできない。removeMember の改修は本設計のスコープ外であり、既存 `OrganizationSwitchController`（membership-scoped binding で解決 → forceFill）にも同型の window が既存する。

代わりに **cross-org 不変条件を「読み出しの安全性」で構造的に保証**する。新規 `App\Services\Organization\CurrentOrganizationResolver` の解決契約:

1. `current_organization_id` 非 null → `$user->organizations()->whereKey($id)->first()`（pivot relation 経由）で**所属を再確認してから**返す。所属が確認できない dangling id は「未設定」として 2 へ倒す = **非所属 org のデータを描画する経路を構造的に持たない**
2. 未設定/dangling かつ所属あり → `organizations.id` 昇順で決定的に候補を選び、**単一の条件付き UPDATE** `WHERE id = :user AND (current_organization_id IS NULL OR current_organization_id = :観測した dangling 値) AND EXISTS(所属 pivot)` を満たすときのみ設定（原子的・冪等。除名 tx が先に commit していれば EXISTS 偽で不発）。修復後、1 と同じ所属再確認つき読み出しで返す
3. 所属 0 件（または競合で候補消滅）→ null（setup 表示）

競合 3 順序の帰結: (a) 除名先行 → EXISTS 偽で修復せず setup/別 org 表示、(b) 修復先行 → removeMember が同一 tx で detach + current null 化して回収、(c) removeMember の stale 比較スキップによる残余 window は dangling id になり得るが、1 の所属再確認により**描画には決して現れず**、次回 resolve が dangling を修復する。

テスト固定: 「org あり current null → 自己修復 + 200 + 当該 org データ表示」「current が非所属 org を指す dangling を手動作成 → 当該 org のデータを描画しない」の Feature テストを必須化。

### [Warning] 「読み取り専用」記述との矛盾 → 対応済み
制約・前提を「**業務データは読み取り専用**。唯一の例外は current organization の冪等な整合修復（CurrentOrganizationResolver 経由の条件付き UPDATE のみ）。この書き込み経路と読み出し安全性は Feature テストに登録する」に修正。

### [Warning] 自己修復の Dashboard 固有化 → 対応済み
`App\Services\Organization\CurrentOrganizationResolver`（新規・再利用可能な配置）に集約。**v1 の呼び出し元は dashboard のみ**という制約を設計に明記（他画面への展開は後続。将来 `ResolvesCurrentOrganization` trait からの利用を想定した配置）。
