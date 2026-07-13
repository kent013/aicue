**`app/Services/Organization/OrganizationMembershipService.php`**
- 判定: **[Warning]**
- `acceptInvitationIfValid()` の `join` 直後で `current_organization_id` を `forceFill` する配置は、設計意図（register 専用ユースケースに閉じる）と整合しています。
- `forceFill(['current_organization_id' => $organization->id])` は tenant キー不信・保護キー運用の観点で適切です（クライアント入力を使わずサーバ導出値のみ）。
- ただし「register 専用前提」に強く依存するため、将来このメソッドが再利用された場合に current 組織を意図せず上書きしうる点はリスクです。  
  - 予防としては、メソッドPHPDocに「register 専用」を明記、または命名で専用性を強化（例: `acceptInvitationForRegistrationIfValid`）が有効です。  
  - 実装そのものは現要件に対して妥当で、即修正必須の欠陥ではありません。

**トランザクション境界 / 原子性**
- 判定: **[Suggestion]**
- `CreateNewUser::create()` の外側 `DB::transaction` 内で、`joinOrganization()`（内側 tx/savepoint）後に `forceFill()->save()` しているため、全体としては同一登録トランザクションで原子的に扱われます。設計上問題ありません。
- savepoint 後の `save()` 失敗時も外側 tx がロールバックされる前提なので、整合性（join済みだがcurrent未設定）がコミットされる懸念は低いです。

**テスト差分（InvitationTest / RegistrationTest）**
- 判定: **[Suggestion]**
- 2-1/2-2（招待登録時 current 確定）と 2-4/2-5/2-6（通常登録・無効token・POST受諾の非切替）が揃っており、A/B分岐の排他性と非波及を十分に押さえています。
- 2-2 の観測点を `verification.notice` に置いた判断は妥当です。`dashboard` の自己修復バイアスを避けるテスト設計として正しいです。
- `response()->json()` 直書き禁止、DTO/JsonResource 方針との衝突も本差分には見当たりません。

**セキュリティ観点**
- 判定: **[Suggestion]**
- 保護キーを `forceFill` で明示代入しており、mass-assignment 保護・tenant キー不信に適合。
- cross-org 分離についても、`$organization` が招待モデル由来であり、入力 payload 起点ではないため妥当です。

**見落としうるエッジ**
- 判定: **[Warning]**
- `既メンバーなら null return` 経路で current を触らない仕様は、今回要件（新規登録直後の不整合解消）に照らすと実害は小さいですが、将来「招待再送＋再登録相当」の特殊運用が入る場合に挙動差の論点になります。  
  - 既存テストで POST受諾の current 非変更を固定しているため、現時点では意図通りです。  
  - 将来再利用時の事故防止として、専用前提のドキュメント化/命名強化を推奨します。

**全体判定**
- **CHANGES_REQUESTED**

理由: 実装・テストは概ね妥当で重大欠陥は見えませんが、`acceptInvitationIfValid()` の register 専用前提がコード上で暗黙であり、将来再利用時の上書きリスクを抑える明示化（命名またはDocBlock等）を入れてからのマージが安全です。