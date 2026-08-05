## 再レビュー結果

### 施策 1: 課金責務 guard

**判定: APPROVE**

Round 1 の import 漏れと N+1 の判断記録は適切に解消されています。

[Suggestion] organization ID は `(int) $org->getKey()` で黙って変換するより、既存の `keyOf()` と同様に `Assert::integer()` で不正な型を検出する方が型方針と整合します。

---

### 施策 2: 理由・action 語彙と DTO

**判定: REQUEST_CHANGES**

[Warning] action 導出規則は十分具体化されましたが、その重要な契約である「両理由の場合の順序」と「重複なし」を固定するテストがありません。

修正案: DTO の単体テスト、または Props テストへ以下を追加してください。

- 両理由なら `['transfer_ownership', 'open_billing']` の順
- 非 current org なら billing action が `switch_organization_then_open_billing`
- 重複した理由を渡しても action が重複しない

---

### 施策 3: OrganizationMembershipService

**判定: APPROVE**

通常経路の権威判定と webhook race の限界が明確に分離されました。Laratrust の列名についても、提示された migration・設定・既存実装から次の対応で正しいです。

- `role_user.team_id`
- `organizations.laratrust_team_id`

明示的な nullable filter も PHPStan level 10 を考慮した妥当な設計です。

---

### 施策 4: Inertia Props

**判定: APPROVE**

DTO を wire shape へ変換し、旧 prop を同一変更で削除する方針は適切です。未使用 import の削除も反映されています。

---

### 施策 5: Settings UI

**判定: REQUEST_CHANGES**

[Critical] 「Inertia は `errors.account` を配列で渡す」という前提を確定できていません。標準の Inertia Laravel middleware は、ValidationException の同一フィールドに複数メッセージがあっても、通常は各フィールドの先頭メッセージだけを shared errors に載せます。その場合、フロントを `string[]` 対応しても複数組織のメッセージは届きません。

修正案:

- 現行 `HandleInertiaRequests::resolveValidationErrors()` が配列を保持する独自実装か確認し、設計へ根拠を記載する。
- 標準実装なら、複数 blocker を専用 error bag / flash DTO / Inertia prop として運ぶ設計へ変更する。
- Feature テストで、実際の Inertia response/session を通して複数メッセージがブラウザへ届くことを固定する。

[Suggestion] `onError` が 404 を捕捉しない点は認識済みですが、所属変更による stale action は現実に起こり得ます。例外ページ遷移を許容する UX 契約をテストまたは設計に明記すると明確です。

---

### 施策 6: 孤児組織の検知バッチ

**判定: APPROVE**

反論 2 点はいずれも妔当です。

- closure DI: 既存コマンドと同じ `Artisan::command` +型 hint DI であり、既存踏襲として適切です。
- `RuntimeException`: namespace のないファイルでは global 解決されるため import 不要です。さらに既存 Architecture テストが非複合 `use` を禁止しているため、追加しない判断が正しいです。

---

### 施策 7: テスト

**判定: REQUEST_CHANGES**

[Critical] 施策 5 の問題に対応し、複数 blocker のメッセージが最終的な Inertia props まで全件届く統合テストが必要です。session の MessageBag だけを確認しても、Inertia middleware で先頭要素へ縮退する可能性を検出できません。

修正案: 2 組織以上で退会をブロックし、`GET /settings` または削除後の Inertia response を通じて、両方のメッセージがクライアント入力へ到達するテストを追加してください。

[Warning] `orphanBillingOrganizationIds()` 自体は Owner 不在を判定せず、入力契約を信用します。テスト #8 の名称・期待値は「渡された ownerless collection のうち課金中の ID」とし、Owner 不在判定は `organizationsWithoutOwner()` またはコマンドテストの責務として分離してください。

修正案: テスト名と対象責務を分け、誤って guard 単体が Owner 判定まで行うように見せないこと。

---

### 施策 8: ドキュメント

**判定: APPROVE**

webhook race の限界、検知との二層構成、N+1 の判断、外部仕様の参照元と確認日の記録が追加され、過剰な保証表現は解消されています。

テスト参照は `#15` のような番号より、テスト名で記載した方が追加・並べ替えに耐えます。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の Critical は適切に解消され、反論 2 点も妥当です。残る実質的な問題は、複数の `ValidationException` メッセージが Inertia 経由で本当に配列として届くかという transport 契約です。ここを現行 middleware の実査と統合テストで確定すれば、実装着手可能な粒度になります。