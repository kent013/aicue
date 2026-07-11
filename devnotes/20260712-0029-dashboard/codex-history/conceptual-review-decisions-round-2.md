# 対応マトリクス: conceptual-review Round 2

## [Critical] 自己修復と membership 削除の競合（cross-org 不変条件）
- 判断: 対応する（ただしロック直列化ではなく「読み出し時所属再確認 + 原子的条件付き UPDATE」で対処。理由を添えて設計変更）
- 根拠: `removeMember` の current null 化は **PHP 側のメモリ上 stale 値比較**（`$target->current_organization_id === $organization->id`）で行われるため、healer 側が行ロックを取っても removeMember 側の条件評価が古い値で走る限り dangling window はゼロにできない（removeMember の改修はスコープ外・既存 OrganizationSwitchController にも同型の window が既存）。したがって「書き込みの完全直列化」ではなく「**読み出しの安全性**」を防御線にする: current が指す org は常に pivot relation で所属再確認してから描画に使う = 非所属 org のデータが描画される経路を構造的に排除。書き込みは `WHERE current IS NULL OR current = 観測 dangling 値 AND EXISTS(pivot)` の単一条件付き UPDATE（原子的・冪等・除名先行なら EXISTS 偽で不発）
- 対応内容: 「表示組織の解決規則」を全面改稿。競合 3 順序（除名先行/修復先行/stale スキップ残余）の帰結を明記し、「非所属 org を current に持つユーザーに当該 org のデータを描画しない」を Feature テストで固定（dangling を手動作成するテストを追加）

## [Warning] 「読み取り専用」記述と GET での User 更新の矛盾
- 判断: 対応する
- 対応内容: 制約・前提を「業務データは読み取り専用。唯一の例外は current organization の冪等な整合修復（CurrentOrganizationResolver 経由の条件付き UPDATE のみ）」に修正し、書き込み経路の Feature テスト登録を明記

## [Warning] 自己修復の Dashboard 固有化
- 判断: 対応する
- 対応内容: `App\Services\Organization\CurrentOrganizationResolver`（新規・再利用可能な配置）に集約。v1 の呼び出し元は dashboard のみ、という制約を設計に明記（他画面展開は後続。ResolvesCurrentOrganization trait からの将来利用を想定した配置）

## [Suggestion] 各所（自己修復の方向性・効果表現・分割コミット・型合成）
- 判断: 現設計のまま（肯定的評価）
