# bug-hunt shard-3 report (run 20260714-154640, 4th run = real-llm 2nd)

- shard: 3 / URL: http://127.0.0.1:8013 / DB: bug_hunt_3 (users=8 at start)
- 割り当てストーリー: S4 (組織/プロジェクト/カテゴリ/ユーザー管理), S5 (課金・チケット)
- 主眼: 前回 findings の回帰確認 (T041=purchase-tickets stale invalid修正, T042=manage/users tablet truncate修正)
- 実行ストーリー: S4, S5 (両方フル走行)
- skip したステップ: organizations.api-keys.sessions.revoke (該当セッション0件のため実行不可。理由: OAuth接続セッションがbughunt環境で発生しないため対象が存在しない)、debug.login-as (bughunt環境ではAPP_ENV=bughunt.localでapp()->isLocal()がfalseのためroute自体が404。前回runと同じ意図的fail-safe gateと判断、非バグ)

## 画面カバレッジ
- S4 screens: organizations.create○, organizations.settings○, organizations.api-keys.index○, organizations.api-keys.sessions.index○, organizations.onboarding.cli○, organizations.onboarding.mcp○, manage.users.index○, projects.index○, projects.create○, projects.edit○, projects.categories.index○ — 11/11 走行済み
- S5 screens: pricing○, billing.index○, billing.tickets.show(=purchase-tickets)○ — 3/3 走行済み

## 操作カバレッジ
- S4 operations: organizations.store○, organizations.update○, organizations.switch○(F-3-02回帰: ドロップダウンから正常動作確認), organizations.transfer-ownership○(実行し完了確認。副作用としてF-3-01発見), organizations.two-factor-requirement.update○(業務ルール拒否パス: 自身の2FA未設定のため必須化不可のアラート確認), organizations.api-keys.store○, organizations.api-keys.revoke○, organizations.api-keys.sessions.revoke skip(理由: 該当OAuthセッション0件), projects.store○, projects.update○, projects.destroy○, projects.categories.store○, projects.categories.update○, projects.categories.destroy○, projects.categories.reorder○, projects.members.store○(F-3-04回帰: 前回UI無しから実装済みへ), projects.members.destroy○, projects.items.store○, projects.items.update○, projects.items.destroy○, debug.login-as skip(理由: bughunt環境で意図的404) — 18/20 実行、2 skip(理由明記)
- S5 operations: billing.checkout○(Standard組織のプラン変更 + Free組織のアップグレードで実行。fake gatewayのneutral returnまで確認), billing.portal○(実行しfake gateway到達を確認) — 2/2 実行 (残高/プラン反映はfake harness仕様上未確認。前回run Q-3-02と同じ既知制約)

## 回帰確認 (最優先)
- **T041 regression (purchase-tickets stale invalid): FIXED 確認済み。** /purchase-tickets で枚数に 1001 入力→送信→`[invalid]`属性+「購入枚数は 1〜1000 の整数で入力してください」エラー表示。その後**送信し直さず**枚数欄を 20 に修正 → `[invalid]` 属性・エラー文が即座に消え、「単価 ¥80 × 20 枚 = 合計 ¥1,600」に正しく再計算された。stale な invalid 状態は残留しなかった。証跡: screenshots/regression-T041-purchase-tickets-fixed.png (アカウント: owner-standard@example.com)
- **T042 regression (manage/users tablet truncate): FIXED 確認済み。** tablet 768×1024 で /manage/users を表示。「Standard Owner」「Standard Admin」「Standard Member」「Multi Org User」全メンバー名が省略記号なしで全文表示。mobile 375×667 でも同様に問題なし。証跡: screenshots/regression-T042-manage-users-tablet768.png, screenshots/manage-users-mobile375.png

## 走行中の副次確認 (前回 F-3-01/F-3-02 の状況、参考)
- 前回(0713)run の F-3-01(組織設定等への恒常ナビ導線欠如)/F-3-02(組織切替UI欠如・詰み) は、今回 dashboard ヘッダーの「Standardプラン組織」ボタンをクリックすると組織設定/メンバー管理/APIキー/請求/料金へのドロップダウンメニューが実装されており解消済みと確認。**organizations.switch もこのドロップダウン内「組織を切り替え」から実行可能で、実際に新規作成した組織 → 元の Standard 組織へ切替を実行し、フラッシュ「「Standardプラン組織」に切り替えました」+ダッシュボードへの正しい遷移を確認した (詰みは再現せず)。**
- 前回(0713)run の F-3-04 (`projects.members.store`/`destroy` の UI が存在しない) も、今回 /projects/{id} (projects.show) 画面内に「プロジェクトメンバー」セクションとして実装されており解消済み。追加→一覧反映→確認ダイアログ付き削除→一覧反映まで実行し正常動作を確認。
- 前回(0713)run の F-3-05 (onboarding/cli 等の title 未設定) も、今回は `<title>CLI 導入ガイド | AI-CUE</title>` 等正しく設定されており解消済み。

## インベントリ修正提案
- S4 stories 手順3「カテゴリ管理: 追加(名20字・同名不可・空値不可 → 押下時エラー)」の「20字」は実装 (`StoreCategoryRequest`/`UpdateCategoryRequest` の `max:50`) と不一致。実際は 50 字まで許可される。カード記述の更新を提案 (バグではなくカード側の記述ドリフト)。

## S5 (課金・チケット) 走行結果
- pricing: 未ログインでも閲覧可 (owner-standard→admin-standard, owner-free で確認)。FAQ アコーディオン開閉、チケット料金表 (¥100/¥80/¥70/¥65/¥60/¥55/¥50 の7段階) が purchase-tickets 画面と完全一致 (表示と実際の課金の乖離なし)。mobile 375 で確認、overflow なし。
- billing.index: owner/admin は「お支払い方法を管理 (Stripe)」ボタン (billing.portal) と Free→Standard の「このプランにする」(billing.checkout) ボタンが見える。member は同じ画面で「プランの変更には組織の管理者権限が必要です」に差し替わり操作ボタンが一切出ない (適切なロールベース制御)。
- purchase-tickets: owner/admin はチケット購入フォームが見える。member は「チケットの購入は組織のオーナーまたは管理者が行えます」に差し替わりフォームなし。route doc comment 通り「閲覧は組織メンバー全員、Checkout 開始は owner/admin のみ」の設計と一致 (要確認ではなく仕様通り)。
- billing.checkout / billing.portal / billing.tickets.checkout: いずれも実行すると `POST .../checkout` (または `/portal`) が **409 Conflict** を返し、Inertia の `X-Inertia-Location` ヘッダで `?fake_external=stripe` 付きの同URLへ遷移する。これは前回(0713)run 同様、bug-hunt の Fake決済ゲートウェイ (`FakeTicketCheckoutGateway`/`FakeSubscriptionCheckoutGateway`) が「決済・チケット付与・状態変更は一切行わない (neutral return)」設計であるための既知の制約であり、アプリバグではない (Inertia の 409+X-Inertia-Location は外部リダイレクトの正規プロトコル)。残高/プラン反映の実確認は本 fake harness では不可 (前回run Q-3-02と同じ制約、regression ではない)。
- H6 (二重送信) 確認: purchase-tickets の「購入手続きへ (Stripe)」ボタンを1クリック操作内で3連打 (JS click×3) しても `POST /purchase-tickets/checkout` は1回のみ発火。二重送信保護は機能している (Critical 級の課金二重発火は未検出)。
- billing.checkout (Free→Standard アップグレード) も owner-free アカウントで実行し同様に 409+redirect を確認 (neutral return)。

## H9 (IDOR/認可境界) 確認
- Standard組織のowner/admin/memberアカウントとFree組織のowner (owner-free) の間で組織切替アカウント (multi-org@example.com は保有だが今回は個別アカウントで検証)。
- member-standard@example.com (project_member) で `/manage/users` に直アクセス → 403 (「アクセスできません」+ホームに戻る導線あり、H2詰みなし)。
- owner-free@example.com (別組織) でログイン中に、Standard組織の `/organizations/standard-ahnmit/settings` および `/organizations/standard-ahnmit/api-keys` に直 URL アクセス → いずれも **404** (存在オラクル封じ。scopeBindings によるIDOR防御が設計通り機能)。IDOR 漏れは検出されなかった。
- member(project_member)アカウントで billing.index/purchase-tickets を閲覧すると、doc comment 通り「閲覧は全メンバー可・操作ボタンはowner/adminのみ表示」に正しく縮退 (member はチェックアウトフォーム自体が表示されない)。

## UI/UX 検証
- 視覚破綻(H11): S4/S5 で確認した全画面 (organizations.settings, manage/users, projects/categories, purchase-tickets, pricing, billing.index) でレイアウト崩れ・overflow なし。
- アフォーダンス・状態(H12): カテゴリ/アイテム/APIキー/メンバー/プロジェクトの追加・編集・削除ボタンは明確なラベルと確認ダイアログを持つ。**例外: F-3-01 (オーナー移譲 select の stale invalid 表示)**。
- レスポンシブ(H13): manage/users を tablet 768×1024 (T042回帰の主眼) と mobile 375×667 で確認 → 崩れなし・truncateなし。pricing を mobile 375×667 で確認 → 崩れなし。検証後デスクトップ(1280×900)に復帰済み。
- a11y基礎(H14): 確認した範囲でフォームフィールドはlabel/aria結線あり、確認ダイアログはheading構造を持つ。異常なし。

## findings サマリ
Medium 1

(以下 finding 詳細を随時追記)

## F-3-01: オーナー移譲フォームの「移譲先のメンバー」select で、空値送信後に有効な選択肢を選んでも stale invalid/エラー文が消えない (T041 と同種の未修正箇所)
- severity: Medium
- story/step: S4-1 (organizations.transfer-ownership)
- 再現手順:
  1. owner-standard@example.com / password123 でログイン (Standardプラン組織)
  2. `/organizations/standard-ahnmit/settings` を開く
  3. 「オーナー移譲」セクションの「移譲先のメンバー」を未選択のまま「オーナーを移譲」を押す → `combobox [invalid]` + 「移譲先のメンバーを選択してください。」が表示される (ここまでは正しいバリデーション)
  4. **送信し直さず** select で「Standard Admin」など有効な選択肢を選ぶ
  5. select の `[invalid]` 属性とエラー文「移譲先のメンバーを選択してください。」が選択後も画面に残り続ける (実際に「オーナーを移譲」ボタンは有効に動作し確認ダイアログ・本人確認ダイアログへ進めるため機能はブロックされないが、視覚的には選択済みなのにエラー表示が残ったまま操作が進む)
- 期待: 有効な値に修正した時点で invalid 状態・エラー文が消える (T041 で purchase-tickets の枚数入力に対して行われた修正と同じ挙動を、select 系フィールドにも適用)
- 実際: select の値を有効なものに変えてもエラー文・invalid 装飾が残留する (screenshots 参照。オーナー移譲は実際に成功しページが admin 表示に正しく切り替わることを確認済みなので機能面の欠陥ではなく表示の不整合)
- 阻害されたユーザージョブ: オーナー移譲操作自体は完了できるが、ユーザーは「まだ選択が無効なのでは」と誤解し、二度見・不要な再選択・問い合わせに繋がりうる (H12 状態表現の判別不能に該当)
- 改善アクション候補: T041 で purchase-tickets に適用したのと同じ修正 (有効値入力/選択で client 側 error 状態をクリアする) を、この select コンポーネント (および同様の他の select/combobox 系フォーム) にも横展開する
- 証跡: screenshots/F-3-03-transfer-ownership-stale-invalid-clean.png (select 後・提出前の stale invalid state)、screenshots/transfer-ownership-double-dialog.png (実際に確認ダイアログ→本人確認ダイアログまで進み機能自体は動作することの参考)
- 推定原因: 未調査 (T041 の修正が PurchaseTickets コンポーネント固有で、他フォームの select/combobox 系フィールドには適用されていない可能性)
- 関連既知情報: docs/TODO-closed.md T041 (purchase-tickets の同種問題を修正済み)。本 finding はその横展開漏れの可能性がある別発生箇所

---

## Critical/High TODO候補
- なし (今回検出した finding は F-3-01 Medium のみ)。

## 要確認リスト
- なし (billing.checkout/portal の fake harness neutral return は前回run Q-3-02で既に「要確認」として記録済みのため再登録せず。今回は同一挙動の再確認に留める)

## 総括
- T041/T042 の回帰は両方とも **修正確認 (regression なし)**。
- 前回(0713) run の Critical/High findings (F-3-01/F-3-02 ナビ導線欠如、F-3-03 Free組織ブロック、F-3-04 プロジェクトメンバーUI欠如、F-3-05 title欠落) は**すべて解消確認**。
- 新規に発見した finding は F-3-01 (Medium、オーナー移譲 select の stale invalid 表示、T041と同種の横展開漏れ) の1件のみ。
- S4/S5 の screens/operations はすべて走行完了 (skip 2件はいずれも環境上の意図的制約で理由明記済み)。
- IDOR (H9) は今回検出したscopeBindings保護範囲内で漏れなし。二重送信 (H6、課金系Critical想定) も検出なし。
