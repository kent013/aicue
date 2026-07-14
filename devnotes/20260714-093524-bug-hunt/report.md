# bug-hunt 統合レポート (real-llm モード初走行) — run 20260714-093524

- 実行日時: 2026-07-14 (JST)
- モード: **real-llm (既定)** + `--all --coverage --parallel=4 --deviate`、worktree `bughunt-20260714-reallm`
- 対象: bughunt 隔離環境 shard 1..4 (`:8011`–`:8014` / DB `bug_hunt_1..4`)
- LLM: **実 Anthropic 接続** (`TESTING_FAKE_LLM=false`、serve に実キー注入。fail-fast せず provision 成功)
- 外部: Stripe/Captcha/SSO/mail は fake 維持 / S3 ストレージは fake トグル (実 S3 配線は inert=設計スコープ外)
- 位置づけ: real-llm モード(T036)の実挙動検証 + 前回修正 T030–T036 の回帰確認

## エグゼクティブサマリ

- **🎯 real-llm モードの本命検証 = PASS**。shard1 で **実 Anthropic API により AI 解析チェーンが成功** (6行の SOP から 12ステップ/14カットのシナリオを生成、再解析も成功、**401 ゼロ / console error ゼロ / 4xx-5xx ゼロ**)。前回まで Q1(LLM 401)で塞がれていた S3 中核チェーンが**実 AI で通ることを実証**。
- **前回修正 T030–T036 の回帰は全て「修正確認 (FIXED)」**。新たな回帰・デグレは検出されず。
- **新規 findings 9件** (High 1 / Medium 3 / Low 2 / 要確認 1 + 既知環境ギャップ 2)。重大なもの (Critical/認可漏れ) は無し。

## real-llm モード検証結果

| 検証項目 | 結果 |
|---|---|
| provision (real-llm 既定、実キー注入) | ✅ fail-fast せず 4 shard 起動、serve env `TESTING_FAKE_LLM=false` + `ANTHROPIC_API_KEY` 注入確認 |
| S3 AI 解析 (SOP抽出→シナリオ生成) | ✅ 実 LLM で成功。6行SOP→12step/14cut。**401 解消 (Q1 の LLM 部分クローズ)** |
| 再解析 (re-analyze) | ✅ 成功 (既存シナリオ置換) |
| Stripe/外部 fake 維持 | ✅ `TESTING_FAKE_EXTERNALS=true` (dotenv 経由) で fake 継続、実決済に飛ばない |
| S3 ストレージ | ⚠️ `TESTING_FAKE_STORAGE` は inert (実 S3 配線未実装=設計スコープ外)。take upload は region 未設定で 500 (既知 F-1-0a) |
| ffmpeg (render) | ⚠️ 未導入のまま (既知 F-1-0b)。完成動画レンダーは未検証 |

## 回帰確認結果 (前回 run 20260714-005157 の新規修正)

| 前回 finding | 対応TODO | 判定 | 確認 shard |
|---|---|---|---|
| F-01 招待経由登録の組織未所属+特典 (Critical) | T030 | ✅ FIXED (登録直後から招待先組織所属・個人組織なし・二重付与なし) | shard2 |
| F-4-01 メール変更の recent-auth 未保護 (High) | T031 | ✅ FIXED (stale session で本人確認必須。直接 fetch でも 409 で server 強制) | shard4 |
| F-1-1 実ジョブ失敗の stale alert 残留 (High) | T032 | ✅ FIXED (再解析成功後 stale alert クリア、矛盾なし) | shard1 |
| F-02 members.update 無言破棄 (High) | T033 | ✅ FIXED (422+combobox直下エラー+権威値復帰) | shard2, shard3 |
| F-4-02 notifications タブtitle (Low) | T034 | ✅ FIXED (「通知 | AI-CUE」) | shard4 |
| F-H3/H4/H5/L1 (2FA再認証/他セッション失効/唯一オーナー削除/単一トースト) | T023-25/26 | ✅ 全て回帰OK | shard4 |

> **adjudication consult**: 新規 9 findings は全件 `adjudication_status: none` = 既知 accepted 非該当、すべて未知/actionable。

---

## 新規 findings

### F-1-3: 撮影画面 (capture.manuals.show) が mobile375/tablet768 で横 overflow (High)
- severity: High / failure_class: other(H11+H13) / story: S3 / 由来: shard-1
- 再現手順: 撮影画面 (`app/projects/{p}/manuals/{m}`) を mobile 375px / tablet 768px で開く → カットのシーン/撮影ポイント説明が画面外に切れる。flex 親に `min-w-0` が無く `truncate` が効いていない。
- 阻害されたユーザージョブ: モバイル/タブレットで撮影指示を読めず、撮影作業が阻害される。
- 改善アクション候補: flex 親に `min-w-0` を付与し `truncate` を機能させる。
- 証跡: shard-1/screenshots/H13-mobile-capture-show.png, H13-mobile-capture-hscroll.png。

### F-1-1: シナリオ保存 (scenario.update) に成功トースト/flash が無い (Medium)
- severity: Medium / failure_class: claimed_success_no_change / story: S3 / 由来: shard-1
- 再現手順: マニュアル編集でシナリオを更新→ PUT 200 で保存されるが成功トーストが出ない (マニュアル作成時は出るのに非一貫)。
- 改善アクション候補: 保存成功時にトースト表示 (T026 の成功フィードバック方針を scenario.update にも適用)。
- 証跡: shard-1/screenshots/S3-scenario-save-flash-top.png。

### F-1-2: プレビュー失敗+レンダー検証失敗で2つの赤alertが帰属不明のまま並ぶ (Medium)
- severity: Medium / failure_class: ux_dead_end / story: S3-dev / 由来: shard-1
- 再現手順: プレビュー生成失敗→続けて完成動画のバリデーション失敗 (採用テイク未設定)→ 無関係な2つの赤alertが積み上がり、どの操作のものか判別不能。
- 改善アクション候補: alert を操作/パネルに紐付けて表示、または種別を明示。前回 F-1-1(T032) と同系の「複数 stale/失敗 alert 積み上がり」パターンの別変種。
- 証跡: shard-1/screenshots/S3-render-two-alerts.png。

### F-3-01: purchase-tickets の入力エラーが有効値に修正しても残る (Medium)
- severity: Medium / failure_class: other(H10+H12) / story: S5 / 由来: shard-3
- 再現手順: `/purchase-tickets` で枚数に範囲外 (1001) 入力→送信ブロック+invalidエラー表示 (正しい)→ 送信し直さず有効値 (20) に修正→ 合計金額は正しく再計算されるが **invalid とエラー文言が消えず残留**。実際は購入可能なのに「エラー中」と誤認させる。
- 改善アクション候補: `resources/js/pages/Billing/PurchaseTickets.svelte` の `clientError` を入力変更 (`isValidCount` が true 復帰) に連動してクリア (現状 submit() 内でしかリセットしない)。
- 証跡: shard-3/screenshots/F03-purchase-tickets-stale-invalid.png。
- 関連: F-02/T033 と同系パターン (サーバ/計算は正しいのに表示 state が古い) だが別コンポーネント。

### F-3-02: manage/users がタブレット768pxで名前を過剰truncate (Low)
- severity: Low / failure_class: other(H11+H13) / story: S4 / 由来: shard-3
- 再現手順: `manage/users` を 768px で開くと未割当ロール行の名前が「Unverified User」→「Un...」に過剰省略。見た目のみ・操作阻害なし。
- 改善アクション候補: `resources/js/pages/Admin/Users.svelte` の列幅/truncate 調整。

### F-4-03: settings のパスワード変更欄に「表示」トグルが無い (Low)
- severity: Low / failure_class: other(H12) / story: S6 / 由来: shard-4
- ログイン画面にはある「パスワードを表示」トグルが `/settings` のパスワード変更欄に無く非一貫。

---

## 要確認 / 既知ギャップ

- **F-2-Q03 (要確認)**: 未ログイン招待受諾→`/register` 誘導時に招待メールアドレスが登録フォームに自動入力されない (ユーザーが手入力する必要がある)。UX 改善余地だが仕様確認要 (由来 shard-2)。
- **F-1-0a (既知/未修正, needs_review)**: `capture.takes.upload-url` が S3 region 未設定で 500 (`TakeObjectStorage` が `TESTING_FAKE_STORAGE` に関わらず常に実 S3 クライアントを呼ぶ=**設計上 inert なスコープ外**)。take upload→adopt/sync/render(footage)/playback/download と S7 の take record IDOR がブロックされ route レベル検証のみ。**real-storage の実配線が別 opt-in タスクとして必要**。
- **F-1-0b (既知/未修正, needs_review)**: ffmpeg 未導入で完成動画レンダー未検証。Q1 の残件。

## 良好だった確認

- **S7 認可境界/IDOR (shard-1)**: cross-org (org B→A) の projects/manuals/jobs/render-jobs/capture-manuals/categories/takes read/write 全て 404 (存在オラクル無し)、project_member/shooter ロールは editor 操作を 403 で正しく遮断、protected-key injection は 422 拒否。**認可漏れ検出なし**。
- **S4/S5 (shard-3)**: 組織 CRUD/切替/オーナー移譲(再認証付)/APIキー発行・失効/カテゴリ/メンバー/アイテム CRUD 全走行、cross-org 直 URL は 404。

## カバレッジ

- 画面: S1 12/13 (two-factor.login skip・理由記載), S2 1/1, S3 全走行 (real LLM で解析到達), S4/S5 全走行, S6 全走行 (notifications 含む)。
- 操作: 回帰対象の書き込み操作 (invitations/members/switch/project-members/2FA/password/account-destroy/scenario/analyze) を 3点セット実走。take 系は F-1-0a で route レベルのみ。S5 checkout は fake harness で完走不可 (既知)。

## Critical/High フォロー候補 (app-design 引き渡し用)

1. **F-1-3 (High)** 撮影画面 (capture.manuals.show) の mobile/tablet 横 overflow を `min-w-0`+truncate で修正。
2. (中期) **real-storage の実配線** (F-1-0a): `TakeObjectStorage` を `TESTING_FAKE_STORAGE` で fake ディスクへ切替える実装 → take upload/render チェーンを bug-hunt で検証可能にする。ffmpeg 導入 (F-1-0b) とセットで Q1 完全クローズ。
3. (Medium 群) scenario.update トースト (F-1-1) / 複数 alert 帰属 (F-1-2) / purchase-tickets stale invalid (F-3-01) — いずれも既存修正 (T026/T032) と同系の UX 一貫性改善。
