# bug-hunt report shard-3 (run 20260811-003230)

- shard: 3 / URL: http://127.0.0.1:8013 / DB: bug_hunt_3 / session: bughunt3
- 実行ストーリー: S4 (org-project-management) → S5 (billing)
- テストアカウント: owner-standard@example.com (Standard組織 owner→試験中に transfer-ownership で admin に降格) /
  admin-standard@example.com / member-standard@example.com / multi-org@example.com (S4 途中で組織から削除して検証消費)
- 走行状態: **完走** (S4/S5 の主要 screens/operations を実操作で消化。一部は環境制約により skip、理由を明記)

## 画面カバレッジ (最終)
- S4 screens: organizations.create 済, organizations.settings 済, organizations.api-keys.index 済,
  organizations.api-keys.sessions.index 済(空状態のみ), organizations.onboarding.cli 済, organizations.onboarding.mcp 済,
  manage.users.index 済(owner/member 両ロールで確認), projects.index 済, projects.create 済, projects.edit 済,
  projects.categories.index 済
- S5 screens: pricing 済, billing.index 済(owner/member 両ロールで確認), billing.plans 済, billing.tickets.show 済

## 操作カバレッジ (最終)
- **organizations.store** 済(空値バリデーション→有効値で作成→toast確認)
- **organizations.update** 済(空値バリデーション→有効値で更新→toast+再読込で反映確認)
- **organizations.switch** 済(組織切替→toast+リダイレクト+サイドバー反映確認)
- **organizations.transfer-ownership** 済(移譲先未選択で押下時エラー→有効値選択で stale invalid 解消=T044 確認→
  確認モーダル→実行→toast+ロール表示の owner⇄admin 入れ替わりをタブレット幅スクショで確認。
  「移譲先にできるメンバーがいません」ガードも別組織で確認)
- **organizations.two-factor-requirement.update** 済(自身の2FA未設定時は「必須化するには、先にご自身の
  2段階認証を有効にしてください」で正しくガード=正常系)
- **organizations.api-keys.store** 済(空値バリデーション→発行→平文キー1回表示→一覧反映)
- **organizations.api-keys.revoke** 済(確認モーダル→失効→toast確認)
- **organizations.api-keys.sessions.revoke**: skip — 接続セッションが常に空状態 (CLI/MCP からの実 OAuth
  ログインを経由しないと接続セッションが作られないため、ブラウザ操作のみでは対象データを作れない)
- **projects.store** 済(空値バリデーション→作成→toast確認)
- **projects.update** 済(名称変更→toast+タイトル反映確認)
- **projects.destroy** 済(確認モーダル→削除→toast+一覧へリダイレクト確認)
- **projects.categories.store** 済(空値/重複名/50字超の3種バリデーション押下時エラー確認→有効値で追加)
- **projects.categories.update** 済(編集モーダル→toast確認)
- **projects.categories.destroy** 済(確認モーダル→toast確認)
- **projects.categories.reorder** 済(▲▼クリック→toast+一覧の並び替わり反映確認)
- **projects.members.store** 済(追加→toast+一覧反映)
- **projects.members.destroy** 済(確認モーダル経由→toast確認。**注**: 削除ボタン直後の snapshot で一覧に
  変化が無く見えたのは、確認モーダルが別 DOM subtree に描画されるため grep 範囲が浅かっただけで、実際は
  正しくモーダルが出ていた。誤検知として記録せず訂正済み)
- **projects.items.store/.update/.destroy** 済(追加→編集(モーダル)→削除(確認モーダル)まで全て toast 確認)
- **organizations.invitations.store**(S2 所属だが manage.users.index で併走): 空値/不正メール形式/重複メール
  の3種バリデーション確認→有効値で送信→toast確認
- **organizations.invitations.revoke**: 確認モーダル経由で取消→toast確認
- **organizations.members.update**(ロール変更): 済(未割当→編集者へ変更→toast確認。組織にプロジェクトが
  存在する状態だったため 422 ガードには当たらず成功。ゼロプロジェクト組織での 422 ガードは未検証)
- **organizations.members.destroy**: 済(確認モーダル→toast確認)
- **organizations.members.two-factor.reset** (MEM-06): skip — ManualTestSeeder は全テストユーザーの2FAを
  未設定のまま投入するため、UI上にリセット対象ボタンがそもそも出現しない。2FA登録は別ストーリー相当の
  重い前準備が要るため未検証。
- **debug.login-as**: skip (低優先の開発者ツールルートのため今回は未実施)
- **billing.checkout**: 未実施(下記メモ参照。plan-to-plan の即時 swap 経路 `/billing/plan` は確認したが、
  未契約→新規契約の Stripe Checkout Session 経由の `billing.checkout` そのものは今回のアカウント (既に
  Standard 契約中) では踏めなかった。Personal ユーザーでの新規契約フローは未実施 = skip)
- **billing.portal** 済(fake_external 経由の中立帰還を確認。one-shot バナー「お支払い管理画面から戻りました」
  がリロードで消えることも確認)
- **billing.tickets.checkout** 部分済 — 枚数バリデーション(1〜1000超で押下時エラー→有効値で stale invalid
  即座に解消=T041確認、単価再計算も確認)と「進行中のお手続き」résumé/やり直し導線までは確認。実際の
  決済完了(チケット付与)は `FakeTicketCheckoutGateway` が意図的に neutral return のみ返す設計
  (ソースコメント:「決済・チケット付与・状態変更は一切行わない」)のため、fake bughunt 環境では
  構造的に検証不能 = skip (アプリ側の欠陥ではない)
- **billing.contact.update** 済(不正メールで押下時エラー→修正で即座に stale invalid 解消→保存→toast+
  再読込で反映確認。member ロールでは読み取り専用表示+直接 PATCH で 403 を確認=deviate 済)
- **billing.auto-recharge.update**(範囲検証のみ) 済(閾値≧上限で押下時エラー→修正で stale invalid
  即座に解消→カード未登録でも下書き保存が toast で成功することを確認)。**有効化(enable)・停止(stop)
  は未実施** — カード登録 (`billing.auto-recharge.setup`) が `FakeStripeGateway::swapSubscriptionPrices`
  同様に neutral return のみで実際にはカードが登録されないため、有効化に必要な前提を満たせない
  (fake 環境の構造的制約。ソースコメントに明記あり) = skip
- **billing.auto-recharge.setup** 済(クリック→fake_external 経由で中立帰還することを確認。カード登録は
  完了しない=fake環境の設計上の制約)
- member ロールでの `billing.contact.update` / `billing.auto-recharge.*` 直接 POST/PATCH: 403 を確認済み
  (deviate 済、下記メモ参照)

## メモ・ルールアウトした誤検知候補 (誤検知として記録せず訂正)
1. **projects.members.destroy の削除ボタンが無反応に見えた件**: 確認モーダルが別 DOM subtree に描画され、
   最初の grep 範囲が浅かっただけ。実際は正しく確認モーダルが出ていた。
2. **billing.portal / billing.auto-recharge.setup がクリック後に画面が変わらないように見えた件**:
   Inertia の 409+X-Inertia-Location による外部リダイレクト機構(`fake_external=stripe` marker 付き
   neutral return)が正しく動いており、request ログで 303→200 の遷移を確認済み。バグではない。
3. **プラン変更 (Starter へ swap) 後も billing.index の「現在のプラン」が Standard のまま変わらない件**:
   `FakeStripeGateway::swapSubscriptionPrices` のソースコメントに「fake 環境では webhook が発火しないため、
   画面は『反映待ち』までを観測する」と明記されており、意図的な fake 環境の制約。実際の反映は webhook
   projection 経由であり、bughunt fake 環境では構造的に検証できない。
4. **MCP 導入ガイドの「コピー」ボタンで `navigator.clipboard.writeText` が失敗する件**: 下記 F-3-01 として
   「要確認」に分類(断定はしない。severity 未確定)。

## UI/UX 検証 (H11-H14)
- H13 レスポンシブ: billing.index を 375×667 で確認(崩れなし、スクショ
  `screenshots/billing-mobile.png`)。manage/users.index を 768×1024 で owner ロール確認
  (`screenshots/manage-users-tablet-owner.png`)。長い名前でも truncate されず表示崩れなし
  (S4 story が懸念する T042 は今回のテストデータでは再現せず)。
- H7 (一過性フィードバック): 本走行で実行した書き込み操作はすべて feedback probe で toast/alert の出現を
  直接確認済み (「未検証」に倒れたものなし)。

## H7 未検証一覧
- なし (実行したすべての書き込み操作で feedback probe による直接観測が取れた)

## findings サマリ
- Critical 0 / High 0 / Medium 0 / Low 0 / 要確認 1 (F-3-01)

## インベントリ修正提案
- なし (screens.md / operations.md / stories/S4, S5 は現状の実装と整合していた)

## 環境ハザード (EH-n)
- なし (走行全体を通じて serve 停止・500 一斉応答・DB 接続断などは観測されなかった)

---

# Findings 詳細 (severity 降順、逐次追記)

## F-3-01 (要確認・severity 未確定) MCP 導入ガイドの「コピー」ボタンが常に「コピー失敗」になる
- 画面: `organizations.onboarding.mcp` (`/organizations/{org}/onboarding/mcp`)
- 手順: エンドポイント URL の「コピー」ボタン (testid `mcp-endpoint-url-copy`) をクリック。
- 観測: feedback probe で `role=status testid=mcp-endpoint-url text="コピー失敗"` を確認 (console error は 0 件)。
- 実装 (`resources/js/components/molecules/CodeSnippet.svelte`) を読むと、`navigator.clipboard.writeText` が
  例外を投げた場合に意図的に「コピー失敗」を表示するフォールバック設計になっている
  (コメント: 「非対応環境・拒否時は『コピー失敗』を表示して手動コピーを促す」)。
  よって **headless Chromium (playwright-cli) がデフォルトで clipboard-write 権限を許可していないことによる
  テスト環境側の制約である可能性が高い** (127.0.0.1 は secure context 扱いなので insecure-context 起因ではない)。
  実ブラウザ (ユーザー操作起点のクリックで通常許可される) では再現しない可能性が高いため、severity を付けず
  「要確認」に分類する。もし実ブラウザでも再現するなら CLI 導入ガイド・API キー表示など、他のコピー系 UI
  全般に波及する可能性があるため、親レポートでの扱いに注意 (実ブラウザでの再確認を推奨)。
- 再現性: 1 回のみ確認 (CLI 導入ガイド側の他コピーボタンは未検証)。
- evidence: `shard-report.md#F-3-01` (screenshot 無し。feedback probe JSON をログとして本文に記載済み)
