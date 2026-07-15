**施策1: `NotificationController::index` に `unreadCount` prop 追加**  
判定: **APPROVE**

- [Suggestion] 方向性は妥当です。`shared` 側の `notifications.unreadCount` とページ `notifications` 配列の衝突回避として、トップレベル `unreadCount` を追加する設計は安全です。
- [Suggestion] `unreadCountFor(User): int` の再利用も整合的です（既存サービス契約維持、DTO 契約非破壊）。
- [Suggestion] 将来の誤解防止として、Inertia の prop 名衝突理由をコントローラ側にも1行コメントで残すと保守性が上がります（任意）。

**レビュー観点メモ**
- 正確性: ページャ表示件数ではなく全体未読を使う判断は正しい。  
- 整合性: Service 経由で既存パターンに一致。  
- PHPStan: `int` 明示で問題なし。  
- DTO/JsonResource: 既存 `notifications` shape 不変で遵守。  
- Inertia/API: この用途は Inertia page prop が適切。  


**施策2: `Index.svelte` で未読0時に read-all 非表示**  
判定: **APPROVE**

- [Suggestion] 禁止事項 #8（disable で塞がない）との整合は取れています。今回は「行為自体が不要な状態」を UI から隠す設計なので妥当です。
- [Suggestion] `Props` で `unreadCount: number` を必須にする方針は良いです。型で取りこぼしを防げます。
- [Warning] `unreadCount` がサーバ不整合で負値になる想定は通常ありませんが、防御的に `unreadCount > 0` を維持する実装は必須です（修正案: 条件を緩めず現行案どおり `> 0` 固定）。
- [Suggestion] 空状態時にボタンが消えるため、E2E 的には「ヘッダ構造維持（見出しが残る）」のテスト観点を将来追加すると回帰に強いです。

**レビュー観点メモ**
- DESIGN.md: クラス変更は token/ramp 利用範囲内で問題なさそう（hex 直書き増加なし）。  
- Atomic Design: ページ責務内の条件描画で逸脱なし。  
- Lucide: 変更なし。  


**施策3: Feature テスト追加（`NotificationCenterTest.php`）**  
判定: **APPROVE**

- [Suggestion] `unreadCount` を Inertia prop で直接検証する計画は適切です。
- [Warning] 「自分宛未読 N + 既読 M」の既読生成で、他要因（既存通知や他ユーザー通知）が混ざると flaky 化します。  
  修正案: テスト内で対象ユーザー・対象通知を明示的に作成し、必要なら他ユーザー通知を混ぜて「自分宛のみカウント」を同時に検証。
- [Suggestion] 既存 index 非退行を維持する方針は良いです。`RefreshDatabase` グローバル運用前提にも整合しています。

**レビュー観点メモ**
- セキュリティ不変条件: tenant/ownership キー受け取り変更なしでリスク低。  
- 副作用: 表示ロジック向け補助 prop 追加のみで影響局所的。  


**施策4: vitest 更新（`NotificationsIndex.test.ts`）**  
判定: **REQUEST_CHANGES**

- [Critical] `Props` を必須化するなら、**既存全 render 呼び出しに `unreadCount` を追加**しないと型エラーまたは実行時不整合が出ます。  
  修正案: テスト共通 `defaultProps` を作り `unreadCount: 0` をデフォルト注入、個別ケースで上書き。
- [Warning] 既存テスト「disabled でなく押下で POST」を `notifications: []` かつ `unreadCount: 1` で実施するのは良いが、意図が読み取りづらくなりがちです。  
  修正案: テスト名に「未読あり時」を明記（例: `未読あり時、read-all は disabled でなく...`）。
- [Warning] 非表示テストは `queryByTestId` null だけだと誤判定の余地があります。  
  修正案: `expect(screen.queryByRole("button", { name: "すべて既読にする" })).toBeNull()` も併用してアクセシビリティ観点を補強。

---

**全体判定**  
**CHANGES_REQUESTED**

- 主要設計（施策1〜3）は概ね妥当で、既存規約・不変条件にも整合しています。  
- ただし施策4は、`Props` 必須化に伴う**テスト全体の追従漏れ防止**が未充足だと回帰リスクが残るため、上記 Critical/Warning の修正を反映してからマージ推奨です。  

必要なら、次に「最小差分で通るテスト修正チェックリスト（PHP/Pest + Vitest）」まで具体化します。