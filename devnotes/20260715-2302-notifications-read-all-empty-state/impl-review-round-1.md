**全体判定**  
- `CHANGES_REQUESTED`

**ファイル別レビュー**

- `app/Http/Controllers/NotificationController.php`  
  - 判定: **Warning**
  - [Warning] `unreadCount` を新規 prop で渡す方針は妥当ですが、Controller に「shared prop との衝突事情」を長く書くと責務が UI 事情へ寄ります。  
    - 修正案: コメントは短く「通知未読数をページ表示制御用に渡す」程度に留め、衝突理由は設計ドキュメントかテスト名で担保。Controller は「何を渡すか」に集中。
  - [Suggestion] DTO/Resource 観点では、`notifications` 配列 + `meta` + `unreadCount` のページ payload が増えてきているため、将来的に専用 ViewModel/Resource 化を検討すると型安全性と変更耐性が上がります（今回は必須ではない）。

- `resources/js/pages/Notifications/Index.svelte`  
  - 判定: **Critical**
  - [Critical] 未読数 0 のときボタン非表示は要件通りですが、`notifications` 表示中でも `unreadCount` が 0 になるタイミング（別画面で既読化・並行タブ更新など）で UI 上の操作導線が消えるため、操作一貫性を崩す可能性があります。仕様上許容なら問題ないが、現状コメントが「禁止事項 #8 準拠」の解釈を強く断定しており、**「非活性禁止」≠「常時非表示必須」** の余地を潰しています。  
    - 修正案: コメント文言を「本機能では未読0件時は非表示というプロダクト判断」に修正し、規約解釈の断定を避ける。加えて仕様固定の根拠（F-4-01）を短く明記。
  - [Warning] `unreadCount` を必須化したのは良いですが、ランタイム入力保証は Inertia payload 依存です。型上は `number` でも実行時不正値（`null`/負値）に脆いです。  
    - 修正案: 受け取り直後に `const safeUnreadCount = Math.max(0, Number(unreadCount ?? 0));` 相当で防御し、条件式は `safeUnreadCount > 0` を使う（最低限 `null` 安全と異常値吸収）。

- `tests/Feature/Notifications/NotificationCenterTest.php`  
  - 判定: **Approved (with suggestion)**
  - [Suggestion] 良い追加です（自分宛のみ・既読除外・全既読=0）。さらに堅牢化するなら「別組織・同一ユーザー宛の未読が count される」ケースを1本追加し、`unreadCountFor` の「全 org 横断」契約をテスト名で明示すると回帰耐性が上がります。

- `tests/js/pages/NotificationsIndex.test.ts`  
  - 判定: **Warning**
  - [Warning] ケース数の整合が取れていません。差分上は `it(...)` が 6 件（空状態、未読あり押下、未読0非表示、未読あり表示、一覧表示、通知行押下系が既存に残っている前提）に見える一方、報告は 5 passed。抜け・記載誤差・skip のいずれかが疑われます。  
    - 修正案: `NotificationsIndex.test.ts` の実行結果件数を再確認し、PR 記載を実測に合わせる。必要なら `--reporter verbose` の件数を添付。
  - [Suggestion] `unreadCount` 表示制御は role と testId の両確認があり妥当。加えて「`unreadCount` が 0 でも通知一覧自体は表示される」組合せケースを1本入れると仕様意図がより明確です。

**観点別サマリ**

- 設計一致: 概ね一致（専用 scalar prop 追加、Svelte 条件描画、Feature/vitest 拡張）。  
- 正確性/エッジケース: 主要は押さえているが、Svelte 側の実行時値防御が弱い。  
- PHPStan Lv10: 既報では問題なし。  
- DTO/JsonResource: 今回の変更範囲では違反なし（`response()->json()` 直書きなし）。  
- セキュリティ/不変条件: 認可・tenant・protected key への悪影響は見当たらず。  
- DESIGN/Atomic: token・アイコン・SVG 増加なし、層違反もなし。  
- テスト網羅: 方向性は良いが、vitest 件数整合の確認が必要。

必要なら、上の Critical/Warning を解消する最小修正パッチ案（コメント修正＋ランタイム防御＋テスト1本）を具体的に提案します。