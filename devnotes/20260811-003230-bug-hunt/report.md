# bug-hunt 統合レポート — run-id 20260811-003230

- 実行日時: 2026-08-11 00:32 JST 開始 / 01:5x JST 完了 (JST)
- モード: `--all --coverage --parallel=4 --deviate --real-llm` (worktree `bughunt-20260810` 走行)
- 対象: 隔離 bughunt 環境 shard 1..4 (`:8011`..`:8014` / DB `bug_hunt_1`..`_4`)
- `verify-run`: **exit 0 (全シャード完遂・欠落なし)**

## 総括

**Critical 0 / High 2 / Medium 1 / Low 1 / 要確認 2 (計 6 件)。**

- **IDOR は検出されなかった** (S7 の cross-org 検査で GET/書き込みとも一律 404、存在オラクルになる差分なし、
  保護キー注入は 422、ロール境界の 403 も正常)。
- High 2 件はいずれも **「操作の結果がユーザーに伝わらない」種の UX 破綻**であり、データ破壊や認可漏れではない。
- 6 件すべて adjudication registry に**既存の該当なし** (`adjudication_status: none`) = 新規。
  High 2 件は `must_remain_actionable: true` (`actionable_hold_reason: high_severity`) で保持。

### カバレッジ (シャードの和集合)

| shard | ストーリー | 状態 |
|---|---|---|
| 1 | S3 core-journey → S7 authz-boundaries | 両方完走 (skip 2 = `capture.takes.update` / `capture.takes.downloaded`、時間予算・理由記載あり) |
| 2 | S1 guest-registration-funnel / S2 invitation-flow | 画面 16/16・操作 11/11 (S1) + 画面 2/2・操作 6/6 (S2)。skip 1 = `debug.login-as` (設計上 local 限定) |
| 3 | S4 org-project-management → S5 billing | 画面・操作をほぼ全て実操作で消化。skip は fake gateway 由来の構造的検証不能分のみ (理由記載あり) |
| 4 | S6 security-2fa-profile | 1 枚を深掘り。skip 3 (passkey 実機認証器不在 / SSO-only の実 IdP 遷移は egress 禁止 / bfcache は Playwright が既定無効) |

- **H7 未検証: 0 件** (少なくとも shard-2 は全書き込み操作で feedback probe の肯定/陰性を確定。
  他シャードも H7 起票は probe 陰性を伴っている)。
- **H13 (レスポンシブ)** は各シャードが代表画面で mobile 375×667 / tablet 768×1024 を確認済み。

### 環境の制約 (この run で取れなかったもの)

- **pcov 未導入のためコード到達カバレッジ (C3) は取れていない**。`--coverage` を指定したが
  middleware が no-op で続行した (provision が warning を出している)。操作到達カバレッジは取得可能。
- 決済・SSO・S3・captcha は fake。**実 Stripe / 実 IdP へは 1 度も出ていない** (禁止事項 4 遵守)。
- LLM のみ実 Anthropic 接続 (既定 real-llm)。

---

## findings (severity 降順)

### F-1-01 (High) — プレビューは未撮影カットを検証せず黒画面で完了する。完成動画生成は同条件をブロックする

- **species**: `validation_gap:manual_preview:create:self` / oracle: `consistency_with_sibling_render_validation`
- **story/step**: S3-8 (`projects.manuals.preview` と `projects.manuals.render` の比較)
- **症状**: 67 カット中 1 カットだけテイクを採用した状態で「プレビュー生成」すると、
  **約 201 秒の全編黒画面の動画が警告なしで生成完了**する (ナレーション・字幕は乗る)。
  一方 `render` は同じ状態を **422 で明示ブロック**し未採用カットを列挙する。
- **阻害されたユーザージョブ**: 制作途中で仕上がりを確認する。黒画面を見たユーザーは
  「アプリか AI が壊れている」と受け取るのが自然で、中核体験の信頼を損なう。
- **証跡**: サーバ生成 mp4 を **ffprobe + フレーム抽出**で確認済み。
  ブラウザ側のコーデック問題ではない (Chromium の H.264 再生制限は別事象として finding から除外している)。
  `screenshots/F-1-01-render-blocks-preview-does-not.png` / `F-1-01-preview-blackframe-t30s.png`
- **改善アクション候補**: preview 側にも未採用カットの検証を入れる。ブロックまでしないなら
  「n カットが未撮影のため黒画面になります」の事前警告を出す。**render と preview で判断基準を揃える**のが要点。

### F-4-01 (High) — 2FA 必須組織で退会取消が黙って弾かれ、結果が伝わらない

- **species**: `ux_dead_end:account_deletion_request:delete:self` / oracle: `H1` (説明なしリダイレクト)
- **story/step**: S6 (退会予約 → 取消)
- **症状**: 2FA 必須組織の**未準拠**メンバーが猶予期間中に「退会を取り消す」を押すと、
  `DELETE /settings/account/deletion-request` が `RequireTwoFactorForEnforcedOrganizations` に捕まり
  (`ALLOWED_ROUTE_NAMES` に無い)、`/settings/security` へ**汎用の「2FA が必要」メッセージだけ**で
  リダイレクトされる。**退会・取消に一言も触れない**ため、取り消せたのか分からない。実際には**取り消せていない**。
- **永久の詰みではない**: 2FA を完了すれば取消できることを実機で確認済み。
- **阻害されたユーザージョブ**: 誤って予約した退会を取り消す。猶予期間の目的そのもの。
- **推定原因**: `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php`。
  凍結許可リスト (`AccountDeletionFreezeAllowance`) 側は通しているが、**優先度が先に走る 2FA ゲートが弾く**。
- **既知情報との関係**: `docs/architecture.md` §退会の猶予期間つき削除「2FA 必須組織との相互作用」が
  この遮断自体は設計として記述している。ただし aicue:T142 で修正されたのは
  「`settings.security` 自体への到達性」であり、**本件はその一段先の「取消操作の結果文言」**で別論点。
- **改善アクション候補**: ゲートのリダイレクト時に元操作の文脈を持たせる
  (「退会の取り消しには 2 要素認証の設定が必要です」)。あるいは取消 DELETE を allowlist へ入れる
  (ただし 2FA 必須の趣旨との整合を要判断)。

### F-2-01 (Medium) — 未契約ユーザーに「支払いが確認できない」と表示される

- **species**: `ux_dead_end:organization:read:self` / oracle: `H10` (直前の操作結果と矛盾)
- **症状**: **一度も契約していない**ユーザー (NoSubscription) のダッシュボードに
  「サブスクリプションのお支払いが確認できないため…」が出る。支払い失敗 (PastDue) と同じ文言。
  `hasBillingAccess` が両状態を 1 つの真偽値に潰しているため `Dashboard.svelte` が区別できない。
- **影響範囲**: **S1 登録ファネルを通る全新規ユーザー**が最初にこれを見る。
- **改善アクション候補**: 未契約と支払い失敗を props で区別し、未契約には「プランを選ぶ」導線を出す。

### F-2-02 (Low) — passkey の 429 が他の失敗と区別されない

- **species**: `ux_dead_end:passkey:read:guest` / oracle: `H4`
- **症状**: `/passkeys/login/options` の `throttle:passkeys` 429 で、
  「パスキー認証を開始できませんでした」の汎用 alert のみ。**流量制限だと分からない**。
- **改善アクション候補**: 429 を判別して「しばらく待ってから再試行してください」を出す。

### F-2-03 (要確認) — 別メール宛の招待トークンで組織に参加できる

- **species**: `other:organization_invitation:accept:cross_tenant` / triage: `needs_spec`
- **確認したこと**: ログイン中のユーザーが**全く別のメールアドレス宛**の招待トークンで、
  招待されていない組織に参加できることを**実際に再現**した。
- **バグと断定しなかった根拠**: `InvitationAcceptanceController` の docblock と
  `docs/architecture.md` §招待受諾の 2 経路が、**メールリンク経路の意図的な bearer token 意味論**として
  明示している (アプリ内受諾の経路だけが email 一致を要求する)。
- **残る論点**: **管理者ロールの招待も同じ性質を持つ**点は文書が明示的に論じていない
  (コードで確認、ブラウザ未再現)。招待リンクの漏洩で管理者権限が渡りうるかは仕様確認の価値がある。

### F-3-01 (要確認) — MCP 導入ガイドのコピーボタンが常に失敗表示

- **species**: `other:onboarding:read:self` / triage: `needs_spec`
- **症状**: `organizations.onboarding.mcp` のコピーボタンが `navigator.clipboard.writeText` 失敗で
  常に「コピー失敗」を表示。
- **バグと断定しなかった根拠**: `CodeSnippet.svelte` を読み、**意図的なフォールバック設計**であり、
  **headless Chromium が clipboard-write 権限を既定で許可していない検査環境側の制約**の可能性が高いと判断。
  実ブラウザでの再確認が要る。

---

## Critical/High の TODO 候補 (app-design → app-todo-add へ渡せる粒度)

1. **プレビューと完成動画生成で未撮影カットの扱いを揃える** (F-1-01 / High)
   - 再現: shard-1 レポート F-1-01 の手順 1..3
   - 阻害されるジョブ: 制作途中の仕上がり確認
   - 候補: preview 側に検証を入れる or 事前警告を出す。**判断基準を render と揃える**
   - 関連: `app/Services/Manual/RenderPipeline.php` / `projects.manuals.preview` の Controller
2. **2FA ゲートのリダイレクトに元操作の文脈を持たせる** (F-4-01 / High)
   - 再現: shard-4 レポート F-4-01
   - 阻害されるジョブ: 誤操作した退会の取り消し (猶予期間の目的そのもの)
   - 候補: 遮断時のメッセージに元操作を含める or 取消 DELETE を allowlist へ (要判断)
   - 関連: `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php`

## 要確認 (仕様確認の質問リスト。バグとは分けている)

1. **招待の bearer token 意味論を管理者ロールにも適用してよいか** (F-2-03)。
   メールリンク経路が email 一致を要求しないのは意図的と文書化されているが、
   管理者招待のリンク漏洩で管理者権限が渡ることの是非は明示されていない。
2. **MCP 導入ガイドのコピー失敗は環境要因か** (F-3-01)。実ブラウザでの再確認が要る。

## インベントリの drift (この run で判明。バグではない)

1. **走行前に 1 件修正済み**: `operations.md` に aicue:T142 の
   `settings.account.deletion-request.store` / `.destroy` が未登録だった (`inventory-check` で検出、追記済み)。
2. ストーリーカード S1 の `?plan=` handoff の記述が実装と不一致
   (実際は `/onboarding/checkout?plan=` で `/pricing?plan=` ではない)。
3. 招待無効ページが HTTP 200 を返す (404 ではない)。存在オラクルの目的は満たしているが記述と差がある。

## 誤検知としてルールアウトしたもの (記録)

- **チケット購入完了 / オートリチャージ有効化 / プラン変更の画面反映** (shard-3):
  `FakeStripeGateway` / `FakeTicketCheckoutGateway` が「決済・状態変更を一切行わない neutral return」を返す
  設計のため bughunt fake 環境では**構造的に検証不能**。ソースコメントに明記されている。
- **Chromium の H.264 再生制限** (shard-1): F-1-01 とは別事象として明示的に除外。
  F-1-01 はサーバ生成 mp4 を ffprobe/フレーム抽出で確認しており、この制限とは独立。

## 肯定的に確認できたもの (finding なし)

- **S7 認可境界**: cross-org の GET/書き込みとも一律 404、存在オラクルになる status/timing 差分なし、
  ロール境界の 403 正常、保護キー注入 (`project_id` / `created_by` / `category_id`) は 422、別カットへの採用は 404。
- **再認証 (recent-auth) の鮮度切れ**: 実 15 分の `AUTH_RECENT_AUTH_TIMEOUT` を待ち切って検証。
  2FA 秘密の開示操作で**その場に本人確認モーダルが出て `/settings/security` から離脱せず完了**し、
  再クリックも不要。文書化された設計契約どおり。
- **6 レーンの throttle 分離** (aicue:T125): 巻き添え 429 は観測されず。
- **回復コードの単回使用での失効** / `settings.password.store` の fail-closed バイパス防御 /
  `passkey.destroy` の IDOR・型混同に対する一律 404 / **猶予期間中の即時削除が 409 でブロック**される点。
- **組織の所有権移譲・2FA 必須化ガード**、プロジェクト/カテゴリ/メンバー/アイテムの全 CRUD、
  招待送信のバリデーション、API キー発行・失効 (shard-3)。

## Phase 4 後のカバレッジ突合 (実行できなかったもの)

- **コード到達カバレッジ (code-reach)**: **未取得**。pcov 拡張が環境に無く、`--coverage` を指定しても
  `BughuntCoverageMiddleware` が no-op で続行した (provision が warning を出している)。
- **操作到達カバレッジ (operation-reach)**: **未取得**。`coverage/correlate.py` は
  `--graph-db` (code-review-graph の `.code-review-graph/graph.db`) を必須とするが、本環境では未ビルド。
  取得するには `code-review-graph build` を先に走らせる必要がある (中規模アプリで ~50 秒)。

いずれも**この run の findings の妥当性には影響しない**が、「どの機構に到達しなかったか」の
機械的な worklist は得られていない。次回 run で取得するなら、provision 前に上記 2 つを用意すること。
