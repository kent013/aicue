全体判定: APPROVED

### 1. 使命との整合性

[Suggestion] 効果を「確実な UI 統一」と「未検証の業務導線改善」に分離したことで、North Star への間接貢献が適切に表現されています。`/projects` から manual/capture までの導線改善は、後続の IA 検討で検証する整理が妥当です。

### 2. 禁止事項違反

[Suggestion] JS テストと Feature テストが計画され、旧 AppLayout・`headerActions`・`OrganizationSwitcher` を同一施策で退役させるため、テストなし完了および後方互換の並走には該当しません。

操作系 POST のログアウトも `/logout` への POST と明記されており、`redirect()->intended()` を導入する設計ではありません。

### 3. 実現可能性

[Suggestion] capability boolean を Inertia shared prop として提供する方式は、Laravel 12・Svelte 5・Inertia.js で実現可能です。参照アプリと同じ方式を採用する判断も、「既存解を使う」「参照側へ寄せる」という方針に整合しています。

Round 1 で提案した専用 Navigation DTO は必須ではありません。現設計の規模では、型付き capability と null guard で十分です。

### 4. 期待効果の妥当性

[Suggestion] 第1段の UI 一貫性、保守性、DRY、認可契約明示化はいずれも変更内容から合理的に期待できます。第2段を仮説として扱い、本 PR で立証しないとした点も適切です。

### 5. リスク

[Warning] 組織設定の表示条件が「`canManageMembers` 等の管理ロール時」と曖昧です。メンバー管理権限と組織設定権限が同一とは限らず、リンク先 Policy と不一致になる可能性があります。

修正提案: 詳細設計では「管理ロール」のような包括表現を使わず、組織設定・CLI・MCPそれぞれについて対応する Policy ability、`laratrust_team_id` の設定箇所、使用する capability を1対1で確定してください。不足する場合は専用 boolean を追加してください。

[Warning] capability の Feature テストが「未認証」と「権限なし」のみで、権限保有時の true および組織切替時の team context を検証していません。誤って常時 false でもテストを通過できます。

修正提案: 各 capability について少なくとも「権限ありで true」「権限なしで false」「未認証で `currentOrganization=null`」を検証し、可能なら別組織の権限が current organization に漏れないケースも追加してください。

### 6. スコープの適切さ

[Suggestion] UI shell 置換と shared prop 契約を分離して明記したことで、変更範囲が明確になりました。Quota、BrandLogo、ページ内容、全ルートの slug 化を除外した判断も適切です。

`OrganizationSwitcher` の退役は施策内に含めて問題ありません。ただし、他利用箇所の確認結果は詳細設計へ反映し、利用が残る場合は単純削除ではなく移行先まで明示する必要があります。

### 7. 型安全性

[Suggestion] `currentOrganization` の array shape と TypeScript interface を1対1で同期し、boolean を非 nullable として返す設計なら、PHPStan level 10 とフロント側の型安全性を維持できます。

shared prop は API リソース応答ではないため、JsonResource を新設しない判断も妥当です。PHPStan を通すための `mixed`・optional field・広い union への緩和は行わず、認証時の shape と未認証時の `null` を明確に分離してください。