**全体判定: CHANGES_REQUESTED**

仮説としては、この設計の狙いは「組織操作の到達不能を恒常ナビで解消し、運用上の詰みをなくすこと」です。成功条件は、`currentOrganization` 共有情報と常設スイッチャーだけで、組織切替・組織設定・招待/メンバー管理・API キー・請求へ、権限に応じて一貫して到達できることです。現状案は方向性は妥当ですが、少なくとも 2 点、仕様のままだと UX/認可整合を壊す可能性があります。

**1. 使命との整合性**
- [Suggestion] F-C1/F-C2 は「思考ゼロ・編集ゼロ」の前段である運用導線の欠落なので、North Star への寄与は明確です。特に「組織を跨いだ運用」「招待による現場展開」の復旧として筋が良いです。
- [Suggestion] 「SOP 起点の動画運用」そのものを増やす機能ではなく、運用阻害の解消に効く基盤改善として位置づけると期待値が適切です。

**2. 禁止事項違反**
- [Warning] 設計文ではテスト追加が書かれており禁止事項 1 には沿っていますが、`shared-props.ts` の更新だけだと型ドリフトを完全には防げません。PHP 側でも shared prop の返却 shape を明示してください。
修正提案: `currentOrganizationProp()` の戻り値に PHPStan array-shape か専用 DTO を導入し、Feature テストと両輪で固定する。
- [Suggestion] `response()->json()` 直書きや Prism 直呼びには触れておらず、この設計自体は非抵触です。
- [Suggestion] disabled ではなく「非表示 + 最終認可はサーバ」で整理している点は禁止事項 8 と整合しています。

**3. 実現可能性**
- [Critical] `organizations.switch` をそのまま使う前提が危険です。切替元が slug ベース画面 (`organizations.settings` / `organizations.api-keys.index`) の場合、既存 switch の遷移先が `back()` 系だと、URL/表示内容は旧組織のまま・ヘッダーだけ新 current org になる不整合が起こり得ます。
修正提案: 概念設計に「switch 後の遷移先契約」を追加してください。安全策は、切替後は current-org スコープの中立ページへ必ず遷移することです。少なくとも slug 依存ページからの switch をどう整合させるかを設計に含めるべきです。
- [Warning] `HandleInertiaRequests` で毎回 Gate 評価を増やすこと自体は Laravel 12 で実現可能ですが、認可文脈が current org と一致することを明文化しないと、実装者が role 直見や曖昧な Gate 呼び出しに流れる余地があります。
修正提案: `OrganizationPolicy` を唯一の真実源とし、評価対象 org を明示して `canManageMembers/canManageApiKeys/canManageBilling` を算出することを設計に明記する。

**4. 期待効果の妥当性**
- [Critical] 「請求 (`billing.index`) をメンバー全員に表示」は、補足コンテキストの `OrganizationPolicy::manageBilling` が owner/admin である点と整合していません。ここが誤っていると、恒常導線ではなく恒常 403 導線になります。
修正提案: `billing.index` の実際の認可契約に合わせてください。現状前提なら `canManageBilling` 保持者のみに表示し、全員向けは `pricing` のみに分離するのが妥当です。
- [Warning] `organizations.settings` を「メンバー全員」としている根拠が本文中にありません。もし settings 画面側が admin/owner 限定なら同様に 403 導線になります。
修正提案: settings の認可仕様を先に確定し、必要なら `canViewSettings` のような明示フラグを追加してください。

**5. リスク**
- [Critical] switch 後の遷移不整合は、組織の切替に成功しても利用者には「切り替わっていない」「画面とヘッダーが食い違う」と見える重大 UX 後退です。
修正提案: 上記の通り、post-switch redirect を設計対象に含め、Feature テストで slug 画面からの切替後遷移を固定してください。
- [Warning] `currentOrganization` に権限フラグを寄せると、将来リンク追加のたびに middleware の prop が肥大化するリスクがあります。
修正提案: 今回は 3 フラグに限定し、「ナビ表示に必要な最小権限のみ shared prop に載せる」という境界を設計に明記してください。
- [Suggestion] click-outside / Escape / focus 管理まで含めるのは良いですが、まずは到達不能解消が主目的なので、アクセシビリティ要件は MVP として必要最小限を定義しておくと実装がぶれません。

**6. スコープの適切さ**
- [Suggestion] 新規ルートや専用一覧画面を増やさず、既存 endpoint を活かす方針は適切です。bug-hunt の Critical を潰すスコープとして過大ではありません。
- [Warning] ただし「switch 後遷移契約」をスコープ外に置くと、肝心の F-C2 解消が不完全になります。
修正提案: スコープ内に「switch 後の安全な遷移先設計とテスト」を追加してください。

**7. 型安全性**
- [Warning] TS 側の `CurrentOrganization` 更新だけでは不十分です。PHP 側で `slug` と各 `canManage*` の存在/nullable 条件を固定しないと、PHPStan level 10 と将来保守の両面で弱いです。
修正提案: `HandleInertiaRequests::currentOrganizationProp()` に厳密な array-shape もしくは専用 DTO を与え、`currentOrganization|null` の境界も明記してください。
- [Suggestion] `organizations` 一覧側は今回は `id/name/isPersonal` のままで足ります。slug を載せない判断は cross-org 露出を広げない意味でも妥当です。

**要修正ポイントの要約**
1. `billing.index` と `organizations.settings` の表示条件を、実際の認可契約に合わせて再定義する。  
2. `organizations.switch` の post-switch redirect 契約を設計に含める。特に slug 画面からの切替整合を必須要件にする。  
3. `currentOrganization` shared prop の PHP 側型定義を強化し、Feature テストで固定する。  

この 3 点が入れば、概念設計としてはかなり堅くなります。