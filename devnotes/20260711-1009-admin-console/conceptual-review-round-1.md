全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] D1 と D3 の方向性自体は妥当です。管理者のオンボーディング導線とカテゴリ運用を分離するのは、North Star の「現場で迷わず回る運用」を支える改善として筋が通っています。
- [Suggestion] doc/04 の「管理者が ID/PW を直接発行する」モックを招待一本化へ reconcile する判断も妥当です。現行のセキュリティ不変条件と明確に衝突するため、この逸脱はむしろ必要です。

**2. 禁止事項違反**
- [Warning] 「Settings からユーザー管理 UI を移す」「Projects/Show からカテゴリ CRUD を移す」は設計上は明記されていますが、同一 PR で旧 UI を完全撤去する前提を実装計画にもっと明示した方が良いです。ここが曖昧だと AGENTS.md の「後方互換の並走を残さない」に抵触します。  
  修正提案: 実装方針に「旧 UI の削除を同一 PR の完了条件とする」「重複導線を残さない回帰テストを追加する」を明記してください。

**3. 実現可能性**
- [Critical] D2 の「project 不在なら org 参加のみ = fail-soft」は、Laravel/Svelte で実装はできますが、設計として破綻しています。`編集者/撮影者` を選んだのに実際には project 権限が付かない状態を許すため、UI 表示と実効権限が乖離します。  
  修正提案: `editor/shooter` の招待・変更時は Default Project 存在を必須条件にするか、少なくとも `未割当` の明示状態を別概念として導入してください。黙って degrade する案は避けるべきです。
- [Warning] `orderBy('projects.id')->first()` を Default Project 解決規約に使うのは、実装は容易でもドメイン不変条件として弱いです。複数 project が混入した瞬間に意図しない project へ role 合成されます。  
  修正提案: `default_project_id` の明示保持、または少なくとも専用 resolver に一本化した上で「v1 では 1 org = 1 project」を検証するテストを追加してください。

**4. 期待効果の妥当性**
- [Critical] 「3 値 1 セレクトで 1 画面 1 操作に集約される」という期待効果は、既存データの正規化戦略がない現状では成立しません。既存の org Member / project_admin / project_member の組み合わせ、旧 pending invitation（org role しか持たない招待）、stale pivot をどう表示・移行するかが未定義です。  
  修正提案: 既存メンバーと pending invitation に対する canonical mapping を先に定義し、必要なら backfill/migration を設計に入れてください。少なくとも「admin を優先表示する」「旧 member 招待は再招待が必要」などの扱いを明文化すべきです。
- [Suggestion] D1 の reconcile は期待効果よりも「危険な旧モックを排除する」意味合いが強いので、その点を効果欄でも明記すると設計意図がより一貫します。

**5. リスク**
- [Critical] D2 は 2 つの既存プリミティブ（org role と project pivot）を 1 UI 概念に圧縮していますが、非正規状態の扱いがありません。ここを詰めないまま出すと、表示上は「撮影者」なのに pivot 未付与、または「管理者」降格時に stale pivot が残る、といった権限ドリフトを起こします。  
  修正提案: `AdminConsoleRole` を保存概念ではなく「正規状態へのコマンド」と捉え、各遷移で最終状態を厳密に定義してください。あわせて stale pivot の掃除規則を決め、遷移テストに含めてください。
- [Warning] 既存 `organizations.members.update` の契約を書き換えると、Users 画面だけでなく既存 Settings やテスト資産にも影響します。  
  修正提案: endpoint の責務変更を明記し、既存 caller の棚卸しと回帰テストを実装方針に追加してください。

**6. スコープの適切さ**
- [Warning] 1 スライスで「新画面 2 枚」「既存 UI の移設」「招待スキーマ変更」「role 合成」「受諾フロー変更」を同時に入れるので、概念設計としてはやや広めです。  
  修正提案: フェーズを切ってください。最低でも「role 正規化と招待/受諾の整備」と「管理画面への UI 移設」は分けて考えた方が安全です。
- [Suggestion] カテゴリ管理側は既存 backend 再利用が中心なので、Users 側より明らかに低リスクです。優先順位を分けるとレビューもしやすくなります。

**7. 型安全性**
- [Warning] 「Inertia props は typed array + TS interface」だけでは不十分です。これは TypeScript 側の見かけの型でしかなく、PHP 側の契約を固定しません。PHPStan level 10 を意識するなら、DTO/Resource/ViewModel を明示すべきです。  
  修正提案: `Admin/Users` と `Admin/Categories` の props は PHP 側でも専用 DTO か Resource に切り出し、request も `FormRequest + Enum` で受けてください。`organization_invitations.project_role` も enum cast まで含めて設計に入れるべきです。
- [Suggestion] `AdminConsoleRole` は表示用と更新用で責務が異なります。`assignable` と `displayed` を分けると owner や legacy invitation の扱いが型で表現しやすくなります。

この設計は方向性自体は良いですが、`Default Project` 前提の扱いと、既存 role/invitation データの canonicalization が未定義のままでは出せません。そこを埋めれば、doc/04 の reconcile・セキュリティ不変条件・Laravel/Svelte 実装性はかなりきれいに揃います。