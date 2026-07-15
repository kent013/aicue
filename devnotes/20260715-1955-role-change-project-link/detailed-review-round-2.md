## 再レビュー結果

### 施策1: リンク追加 — APPROVE

- 既存の `href` 直書き慣行との統一は妥当。
- Button atom、Inertia遷移、DS token、Atomic Designとも整合。
- 禁止事項#8にも抵触しない。

### 施策2: vitest — APPROVE

- 同一テストファイルの既存mock方針を維持しており妥当。
- 表示・非表示・リンク先・既存文言を十分に検証している。
- clickしないためrouter依存も生じない。

### 施策3: backend不変条件 — APPROVE

- Policy内部実装ではなく、実際のHTTP到達性を固定する形へ適切に改善された。
- Owner/Adminの200とMemberの403を分割し、権限境界と診断性を担保している。
- `current_organization_id` の明示設定もcross-org誤判定を防ぐ。
- PHPStan L10上の懸念なし。

[Suggestion] テスト名の「manageMembersを持つ」は直接検証していないため、「Owner/Adminは」へ簡略化すると、テスト実体との対応がより正確です。

課金不健全時のedgeは既存仕様に由来し、明示的にスコープ管理されているため本変更の阻害事項とはしません。

## 全体判定

**APPROVED**

Critical・Warningは解消済みです。