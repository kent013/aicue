## 全体判定: CHANGES_REQUESTED

### 1. 使命との整合性

[Suggestion] 「共用端末で誤組織の手順書を撮る」リスクを、URL 単一方式で解消する方向は North Star と強く整合しています。とくに撮影 PWA の `/app` を状態を持たない組織選択・分岐入口にする設計は、「作業者に判断させない」要件にかなっています。

[Warning] 「URL を共有すれば受け手の状態に依存しない」は、所属・認可の前提を省くとやや強すぎます。非所属者は正しく 404 になるべきです。

修正提案: 期待効果を「同じ組織へのアクセス権を持つ利用者について、表示対象は保持状態ではなく URL と route binding により一意に決まる」と表現してください。

### 2. 禁止事項違反

[Suggestion] `current_organization_id`、切替 route、resolver を同一変更で撤去し、旧 URL の並走・転送を置かない方針は、後方互換の並走を残さない規約に適合しています。

[Suggestion] 改名の対象組織を URL binding から決め、新 slug のみを入力とするため、tenant キー不信にも整合しています。

### 3. 実現可能性

[Warning] 「DB の小文字式 unique index」は、利用 DB と Laravel migration の表現を確定しないままでは移植性・テスト可能性が不明です。特に SQLite、MySQL、PostgreSQL では式 index・照合順序・migration の扱いが異なります。

修正提案: 詳細設計で対象 DB を明記し、採る方式を固定してください。例えば、`slug` を値オブジェクトで必ず lowercase に正規化したうえで通常の unique index を張る方式、または DB 固有の `LOWER(slug)` index／生成列方式のいずれかを選び、並行改名時の競合を Feature テストで確認してください。

[Warning] `Organization::getRouteKeyName()` の扱いが未決定です。明示的な `{organization:slug}` binding に全て移行するなら実現可能ですが、field 無指定 binding、通知 URL、Inertia の route helper、テスト factory などが残ると `id` 解決が混在します。

修正提案: 詳細設計の完了条件に「field 無指定の `Organization` route binding の全数棚卸しとゼロ化、または用途を限定した inventory」を加えてください。

### 4. 期待効果の妥当性

[Suggestion] 保持列の自己修復を撤去すれば、所属 ID 順への暗黙の組織切替が消える、という因果は妥当です。

[Suggestion] `/app` を選択・分岐入口として残すため、PWA manifest を組織別にする必要がないという判断も、v1 のスコープに対して適切です。

### 5. リスク

[Critical] AG-047 の保証が不足しています。「機械経路が slug を使っていない」だけでは、「不変の内部識別子で組織を指す」ことを保証できません。組織を文字列名、表示名、あるいは改名可能な別フィールドで解決する経路は slug 不使用の検査をすり抜けます。また、組織を URL 引数に持たないことと、機械経路が安全な組織帰属をしていることも別問題です。

修正提案: AG-047 の検査を deny-by-default の識別子契約にしてください。少なくとも api / ai / console / Filament / MCP ごとに、組織を扱う入口を inventory 化し、許可する解決経路を「内部主キーまたは不変 UUID による relation / org-scoped resolver」に限定します。組織を扱わない経路も明示 exempt とし、slug・name・表示用識別子による検索や URL parameter 使用を負例で検出してください。

[Warning] 旧 URL を即時 404 にするのは正典と既存規約には合いますが、PWA のキャッシュ済み画面、通知メール、ブックマーク、外部連携が古い URL を参照する可能性があります。詳細設計で移行コストを評価すると明記しているだけで、現時点では判断材料が不足しています。

修正提案: 実装前に、旧 URL を生成する箇所（通知、メール、PWA/SW キャッシュ、画面リンク、テスト、外部 API 説明）を棚卸しし、「旧 URL が残らない」ことを成功条件へ追加してください。転送を置かない方針自体は維持してください。

### 6. スコープの適切さ

[Suggestion] 割当済み未追従項目 AG-037 / AG-038 / AG-039 系 / AG-046 / AG-047 に限定し、充足済み・未確定・他 feature 所有を明示的に除外しているため、スコープ設定は適切です。

[Suggestion] AG-036 を新規スコープに含めず、既存 binder の適用範囲拡大として扱う整理も妥当です。ただし route 移設後の全 nested route を IDOR inventory に登録することは必須です。

### 7. 型安全性

[Warning] 新設する `OrganizationSlug`、予約語設定、改名履歴、Inertia shared prop の境界型が概要だけでは不十分です。PHPStan level 10 では、設定配列の曖昧な shape、nullable な route parameter、`mixed` な Inertia shared data が弱点になり得ます。

修正提案: 詳細設計で以下を固定してください。

- `OrganizationSlug` は生成・正規化・検証に成功した値だけを持つ不変 DTO/value object とする。
- 予約語設定は理由分類を backed enum と array shape で表し、設定読込直後に検証済み DTO へ変換する。
- 改名履歴は Eloquent モデル任せの曖昧な配列ではなく、制限判定に必要な値を型付き DTO / query result として扱う。
- `currentOrganization` shared prop は `OrganizationResource|null` 相当の明示型にし、組織外 route では必ず `null` とする。
- 改名 endpoint は FormRequest → 型付き slug 値 → Service → Resource / Inertia response の境界を明示する。

上記、とくに AG-047 の内部識別子契約と DB 一意性方式を詳細設計で確定できれば、裁定への最小追従として承認可能です。