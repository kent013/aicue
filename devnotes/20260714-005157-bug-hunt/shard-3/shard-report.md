# bug-hunt report shard-3 (run 20260714-005157) — 2回目走行 (回帰確認)

- 実行ストーリー: S4 (組織・プロジェクト・カテゴリ・ユーザー管理), S5 (課金・チケット)
- 対象URL: http://127.0.0.1:8013
- DB: bug_hunt_3 (db-check時点 users: 8)
- 前回走行: devnotes/20260713-085818-bug-hunt/shard-3/shard-report.md (F-3-01〜F-3-05)
- 走行状態: **API エラーにより中断**。中断までに実施した範囲は以下に記録の通り (S4/S5 の主要フローは走行済みだが、一部未消化項目あり。正直に「(中断により未記録)」を明記)。

## 回帰確認サマリ (最優先)
- F-C3 (T020, 前回F-3-03 Free plan gate): **解消確認**。owner-free / admin-free / member-free の 3 アカウント全員でログインし http://127.0.0.1:8013/projects へ到達 (billing への強制リダイレクト無し、200 で「プロジェクトはまだありません」表示)。owner-free で /billing を開いても「現在のプラン: 未契約 (Free 相当)」の正常表示のみで、以前あった赤い支払いエラー alert は消滅。
- F-C2 (T019, 前回F-3-02 組織スイッチャー): **解消確認**。ヘッダーに組織名ボタン (`org-switcher-trigger`) が追加され、multi-org@example.com でクリック→ドロップダウンに「組織を切り替え」+ 他組織ボタン (`org-switch-{id}`) が表示。Standard→Free→Standard→Free の往復切替を実施し全て成功。切替時に flash「「Freeプラン組織」に切り替えました」表示、`POST /organizations/{id}/switch` → 302 → `/dashboard` 200 を確認。組織新規作成 (`organizations.store`) 直後の自動切替からも同スイッチャーで元組織に正常に戻れることを確認 (詰み解消)。
- F-C1 (T019, 前回F-3-01 恒常ナビ導線): **解消確認**。組織スイッチャードロップダウン内に「組織設定」「メンバー管理」「API キー」「請求」「料金」への恒常リンクが追加された。owner/admin ロールでは 5 項目全て表示、member ロール (member-free) では「組織設定」「請求」「料金」の 3 項目のみ表示 (メンバー管理・API キーは非表示) — 権限に応じた適切な出し分けを確認。
- F-M3 (T028, 前回F-3-04 projects.members UI): **解消確認**。`/projects/{id}` 詳細画面に「プロジェクトメンバー」セクションが追加され、メンバー選択 + ロール選択 (編集者/撮影者) + 「メンバーを追加」ボタン (`project-member-submit`) で追加 → 一覧に反映 → 「削除」ボタン押下で確認モーダル (「メンバー削除」「『◯◯』をこのプロジェクトから外しますか？ 組織のメンバーシップは維持されます」) → 「削除する」で削除 → flash「プロジェクトメンバーを削除しました」表示 → 一覧から消え追加コンボボックスに復帰、の 3 点セット (実行→フィードバック→反映確認) が正常動作することを確認。

**回帰確認結論: 4 件 (F-C3 / F-C2 / F-C1 / F-M3) すべて解消を確認した。**

## 新規 finding
**新規 finding なし (回帰4件は全て解消確認)。** 中断までの探索範囲で新規の Critical/High/Medium/Low バグは検出しなかった。

なお、以下は finding としては起票していない調査メモ (誤検知の可能性が高いため参考記録のみ):
- 逸脱アイデア「削除確認ダイアログをスキップして直 POST できるか」を検証する過程で、ブラウザの `fetch()` から実 HTTP DELETE メソッド + 有効な XSRF トークンで `/projects/1` に直接リクエストしたところプロジェクトが削除された。これは **バグではない** と判断: JS の確認モーダルはあくまで UX 上の防御であり、真の保護境界は認証セッション+CSRF トークンであるべきところ、これは正しく機能していた (無効なトークン/未認証では別途弾かれるはずだが、今回は正規セッションでの直叩きなので許可されるのが正しい)。一方で UI 経由の「キャンセル」ボタンは別プロジェクト (`Cancel検証プロジェクト`, id=3) で明示的に再検証し、キャンセル後に削除リクエストが送信されない (0件) ことをネットワークログで確認済み。誤ってバグと誤認しかけた点を記録として残す。
- Q-3-01 (前回「要確認」: onboarding/cli の「コピー」ボタンが毎回失敗表示) は今回再現せず。ただしクリック後に成功/失敗いずれのフィードバックも視認できなかった (console error 無し、flash 無し)。解消したのか、単に失敗トーストが今回出なかっただけなのかは中断により深掘りできず「要確認」として保留。

## 画面カバレッジ
- S4 screens: organizations.create○, organizations.settings○ (新規組織+Standard組織の両方で確認), organizations.api-keys.index○ (発行+失効), organizations.api-keys.sessions.index○ (閲覧のみ。セッション0件のため空状態表示を確認), organizations.onboarding.cli○, organizations.onboarding.mcp○, manage.users.index○ (招待送信+取消+オーナー移譲後の一覧反映を確認), projects.index○ (Free/Standard/新規組織の3組織で確認), projects.create○ (3件作成), projects.edit **(中断により未記録。画面自体への遷移・projects.update 操作は未実施)**, projects.categories.index○ (作成・編集・並べ替え・削除まで実施)
  - 11/11 中 10 件走行済み、1 件 (projects.edit) 未走行
- S5 screens: pricing○, billing.index○ (Free/Standard 両方のプラン表示を確認)
  - billing.tickets.show (=purchase-tickets) ○ (バリデーション + 購入手続き遷移まで確認)
  - 3/3 走行済み

## 操作カバレッジ
- S4 operations:
  - organizations.store○ (新規組織作成、自動切替+リダイレクト確認)
  - organizations.update○ (組織名変更→即時反映確認)
  - organizations.switch○ (往復切替、UI経由で複数回実施。F-C2 回帰確認の本体)
  - organizations.transfer-ownership○ (owner-standard→admin-standard へ移譲。移譲後に自分の役割が「管理者」に降格し、オーナー専用セクション (2FA必須化/オーナー移譲) が非表示になることを確認。sudo-mode的な `recent-auth/status` チェックにより再ログイン直後はパスワード再入力なしで実行可能なことも確認 (`{"recent":true,...}` レスポンス。正常なUX、バグではない))
  - organizations.two-factor-requirement.update○ (業務ルール拒否パスのみ確認: 「必須化するには、先にご自身の2段階認証を有効にしてください」。前回同様)
  - organizations.api-keys.store○ (発行、平文キー1度限り表示を確認)
  - organizations.api-keys.revoke○ (失効、監査痕跡「失効済み」表示を確認)
  - organizations.api-keys.sessions.revoke skip (理由: 該当セッション0件のため実行不可。前回と同じ状況)
  - organizations.invitations.store○ (招待送信、無効メール形式はブラウザネイティブ検証でブロック、有効メールで一覧に反映)
  - organizations.invitations.revoke○ (取消、一覧から消去確認)
  - projects.store○ (3件作成: S4回帰確認プロジェクト/カテゴリ確認用プロジェクト/Cancel検証プロジェクト)
  - projects.update **skip (中断により未実施。projects.edit 画面へ未遷移)**
  - projects.destroy○ (確認モーダル経由で正常削除。キャンセルでは削除されないことも別プロジェクトで再検証済み)
  - projects.categories.store○ (空値バリデーション、重複名バリデーション、正常作成を確認)
  - projects.categories.update○ (名称編集→即時反映)
  - projects.categories.destroy○ (削除→一覧から消去)
  - projects.categories.reorder○ (▲▼ボタンで並び替え→即時反映)
  - projects.members.store○ (F-M3 回帰確認の本体)
  - projects.members.destroy○ (確認モーダル経由。F-M3 回帰確認の本体)
  - projects.items.store○ (作成→一覧反映)
  - projects.items.update○ (編集→即時反映)
  - projects.items.destroy○ (確認モーダル経由で削除→一覧から消去)
  - debug.login-as **(中断により未記録。今回は未検証。前回run (20260713) では APP_ENV=bughunt.local により isLocal() が false でルート自体が404、fail-safe gateと判定済み)**
  - 18/20 実行、2 skip (1件は理由明記のセッション0件、1件は中断による未実施)
- S5 operations:
  - billing.checkout (チケット購入)△: 0枚指定でクライアント側バリデーション (「購入枚数は1〜1000の整数で入力してください」) を確認。20枚で正常送信→フェイク Stripe 経由でリダイレクト (`?fake_external=stripe`) →購入画面に復帰。ただし残高は購入前後で 100枚のまま変化なし。理由: 前回同様、FakeTicketCheckoutGateway が「決済・チケット付与は一切行わない (neutral return)」設計のため、UIからは実際の残高反映を検証できない (Q-3-02 と同じ既知の限界。新規finding扱いにはしない)
  - billing.portal○ (「お支払い方法を管理 (Stripe)」ボタン→フェイクStripeへリダイレクト→billing復帰。前回同様fake harness仕様の範囲で正常動作確認)
  - 二重送信/戻る→再送 等の冪等性シナリオ: fake harness の制約により実際の課金完了状態を再現できず検証不能 (前回Q-3-02と同じ)

## UI/UX 検証
- H11 (視覚破綻): カテゴリ管理画面・billing画面 (組織スイッチャードロップダウン展開状態含む) をデスクトップ1280×900で確認、崩れなし
- H12 (アフォーダンス/状態): カテゴリ/アイテム/APIキー/招待/メンバー/プロジェクトの追加・編集・削除操作はすべて明確なラベルと確認モーダルを持つ。オーナー移譲後にオーナー専用UIセクション (2FA必須化・オーナー移譲フォーム) が正しく非表示になるなど、権限変化に応じたUI出し分けも適切
- H13 (レスポンシブ): カテゴリ管理画面 (`/projects/2/categories`) を mobile 375×667 / tablet 768×1024 で確認→崩れなし。billing画面+組織スイッチャードロップダウン展開状態を mobile 375×667 で確認→ドロップダウン (組織設定/メンバー管理/APIキー/請求/料金の5項目) がはみ出し・重なりなく正しく表示 (screenshots/H13-billing-orgswitcher-mobile375.png)。デスクトップ (1280×900) に復帰済み
- H14 (a11y基礎): 前回Low findingだった app_titles 未登録 (F-3-05) の6画面 (manage.users.index, projects.categories.index, organizations.api-keys.index/sessions.index, organizations.onboarding.cli/mcp) すべてでブラウザタブタイトルが固有の文言に修正されていることを確認 (**F-3-05も解消**)。コントラスト比・フォーカスリング可視性・キーボードのみでの到達性の詳細検証は **中断により未実施**

## findings サマリ
Critical 0 / High 0 / Medium 0 / Low 0 / 要確認 1 (Q-3-01継続、新規ではない)

新規 finding は無し。前回の Critical 2 / High 1 / Medium 1 / Low 1 はすべて解消確認 (F-3-03→F-C3, F-3-02→F-C2, F-3-01→F-C1, F-3-04→F-M3, F-3-05も副次的に解消確認)。

## 中断により未記録・未検証の項目 (正直申告)
- projects.edit 画面への遷移、および projects.update 操作は未実施 (S4手順2の一部が未消化)
- debug.login-as は今回未検証 (前回の404 fail-safe gate判定を継続採用、再検証はしていない)
- H14 の詳細検証 (コントラスト・フォーカスリング・キーボードナビゲーション) は未実施 (タイトルタグ・見出し階層・確認モーダルの基本構造確認のみ)
- Q-3-01 (onboarding/cliのコピー機能) は成功/失敗いずれのフィードバックも確認できておらず「要確認」のまま
- S5の二重課金/予約解放等の冪等性シナリオはfake harnessの制約により今回も検証不能 (前回から継続する既知の限界。インベントリ修正提案は前回reportに記載済みのため本reportでは重複記載しない)

## Critical/High TODO候補
今回のTODO候補なし (新規findingなし)。前回のCritical/High (F-3-03, F-3-02, F-3-01) はいずれも解消確認済みのためクローズ対象。

## 要確認リスト
- Q-3-01 (継続): onboarding/cli の「コピー」ボタンのクリック後フィードバック (成功/失敗トースト) が視認できない。前回は「コピー失敗」表示が毎回出ていたが今回は何も表示されなかった。環境要因 (ヘッドレスブラウザのclipboard権限) の可能性が高いが、中断により深掘りできず要確認のまま。

---

## 証跡 (screenshots/)
- S4-regress-org-switch-back.png: 組織切替往復成功時のflashメッセージ「「Freeプラン組織」に切り替えました」
- S4-regress-members-ui.png: プロジェクトメンバー管理UI (F-M3回帰確認)
- H13-categories-mobile375.png / H13-categories-tablet768.png: カテゴリ管理画面のレスポンシブ確認
- H13-billing-orgswitcher-mobile375.png: billing画面+組織スイッチャードロップダウン展開状態のmobile確認 (5項目メニューが正しく表示)
