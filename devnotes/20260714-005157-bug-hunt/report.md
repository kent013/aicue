# bug-hunt 統合レポート (2回目/回帰確認) — run 20260714-005157

- 実行日時: 2026-07-14 (JST)
- モード: 既定 (`--all --coverage --parallel=4 --deviate`)、worktree `bughunt-20260714` から走行
- 対象: bughunt 隔離環境 shard 1..4 (`:8011`–`:8014` / DB `bug_hunt_1..4`)
- 位置づけ: **前回 run 20260713-085818 の修正 (T019–T029) マージ後の回帰確認 + 新規探索**
- カバレッジ計装: pcov 未導入のため code-reach は no-op (operation-reach のみ)

## エグゼクティブサマリ

- **前回修正 12 件は全て回帰OK (解消確認済み)**。詰み・締め出し・再認証欠如・フィードバック欠如はいずれも解消。
- **新規 findings 9 件** (Critical 1 / High 3 / Low 3 / 要確認 2)。
- **注目**: 新規 High/Critical 4 件のうち **3 件は今回の修正の "取りこぼし" または "新規混入した回帰"**。修正のスコープが狭かった/経路を1つ見落としたことに起因する。フォロー修正を強く推奨。

## 回帰確認結果 (前回 findings)

| 前回 finding | 対応TODO | 判定 | 確認 shard |
|---|---|---|---|
| F-C1 組織ナビ導線皆無 (Critical) | T019 | ✅ 回帰OK | shard2, shard3 |
| F-C2 組織スイッチャ不在→詰み (Critical) | T019 | ✅ 回帰OK | shard3 |
| F-C3 Freeプラン締め出し (Critical) | T020 | ✅ 回帰OK | shard3 |
| F-H1 登録チケット未付与 (High) | T021 | ✅ 回帰OK (直接登録) ※招待経路で新規Critical=F-01 | shard2 |
| F-H2 manuals stale alert (High) | T022 | ✅ 回帰OK (意図した範囲) ※未カバー変種=F-1-1 | shard1 |
| F-H3 2FA無効化に再認証なし (High) | T023 | ✅ 回帰OK ※同種の未対応ルート=F-4-01 | shard4 |
| F-H4 パスワード変更で他セッション残存 (High) | T024 | ✅ 回帰OK (2セッションで実証) | shard4 |
| F-H5 唯一オーナー削除で孤児化 (High) | T025 | ✅ 回帰OK (救済導線まで実証) | shard4 |
| F-M1 保存成功フィードバック欠如 (Medium) | T026 | ✅ 回帰OK | shard4 |
| F-M3 プロジェクトメンバーUI不在 (Medium) | T028 | ✅ 回帰OK | shard3 |
| F-L1 recovery code 二重トースト (Low) | T026 | ✅ 回帰OK (1つのみ) | shard4 |
| F-L2/F-3-05 タブtitle未設定 (Low) | T027/T029 | ✅ 回帰OK (対象6ルート) ※notifications漏れ=F-4-02 | shard3 |

> **adjudication consult (親のみ)**: 新規 9 findings を validator に通した結果、**全件 `adjudication_status: none` = 既知 accepted 該当なし、すべて未知/actionable**。
> (既存 `ledger/adjudications.jsonl` A-004..A-008 の malformed 警告は前回同様の登録簿側既存不整合で無関係。)

---

## 新規 findings

### F-01: 未ログイン招待受諾→登録で新規ユーザーがどの組織にも属さず、かつ登録特典10枚が誤付与される (Critical)
- severity: **Critical** / failure_class: data_integrity / story: S2×F-H1 / 由来: shard-2
- **これは今回の修正 (T021 + T019/招待経路) が新たに壊した回帰の疑いが濃い。**
- 再現手順: (1) オーナーが `manage/users` からメール招待 (organizations.invitations.store)。(2) **未ログイン**で署名付き招待リンク `/invitations/accept?token=...` を開く→ `/register` へ誘導 (session に invitation_token 保存)。(3) 招待と同一 email で新規登録→規約同意→登録。(4) メール認証完了→ dashboard。
- 期待 (設計 `devnotes/20260713-1637-registration-ticket-grant/detailed-design.md` §施策3): 招待 token 経由の登録は個人組織を作らず signup grant も付与しない (「招待 N 人 = N×10 増幅を避ける」)。招待成立なら招待先組織に参加する。
- 実際: 登録直後の残高 = **10 (signup grant 誤付与)**。かつヘッダー組織メニューは「組織を作成」のみ = **招待先組織にも個人組織にも属さない中間不整合状態**。設計の 2 分岐 (招待成功=参加のみ / 招待失敗=個人組織+grant) の**どちらにも一致しない**。招待リンク再訪は「使用済み」表示だが `invitations.accept.store` (POST) が呼ばれた形跡なし。再ログイン後にようやく組織所属が見える。
- 阻害されたユーザージョブ: 招待された新規ユーザーが正しく組織に参加できず、かつ増幅防止インバリアントが破れている。
- 改善アクション候補: `CreateNewUser::create()` の招待経路 (acceptInvitationIfValid 成立時) が「参加のみ・grant無し・個人組織を作らない」を確実に満たすよう修正。メール認証タイミングとの整合 (認証前後で所属が見えない窓) も確認。
- 証跡: shard-2/screenshots。

### F-4-01: メールアドレス変更 (user-profile-information.update) が recent-auth 未保護 → アカウント乗っ取り経路 (High)
- severity: **High** (人間トリアージで Critical 格上げ要検討) / failure_class: authz_bypass / story: S6 / 由来: shard-4
- **F-H3 (T023) 修正の取りこぼし** — recent-auth allowlist が 2FA 関連3ルートのみで、同じ Fortify のプロフィール更新ルートを含めていない。
- 再現手順: (1) 「ログイン状態を保持」でログイン。(2) `cookie-delete ai-cue-session` でセッション cookie のみ削除 (remember 残す)。(3) `/settings` へ→ remember token 経由で自動再ログイン (`viaRemember()===true` で recent_auth_at 未 stamp = stale)。(4) メール欄を別アドレスへ変更して保存。→ **再認証・パスワード確認一切なしで受理**され `/email/verify` へ。旧アドレスへの通知も無し。
- コード根拠: `FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` は `two-factor.*` 3ルートのみ。`user-profile-information.update` (vendor/laravel/fortify/routes/routes.php:105-107、氏名・メール変更) には recent-auth も current_password 確認も無い (対照的に `user-password.update` は current_password 要求)。
- 阻害されたユーザージョブ: セッション/remember-token を窃取した攻撃者がパスワード不知のまま登録メールを差し替え→「パスワード忘れ」で新アドレスにリセットメール受信→完全乗っ取り。旧アドレス通知が無く被害者は気付けない。
- 改善アクション候補: `user-profile-information.update` (特にメール変更時) を recent-auth allowlist に追加。加えてメール変更成功時に旧アドレスへ警告通知 (変更取消リンク付きが望ましい)。
- 証跡: shard-4/screenshots/F-01-email-change-no-recent-auth.png。
- 関連: `devnotes/20260713-1653-twofa-recent-auth` の対応範囲が 2FA ルート限定だったための漏れ。

### F-1-1: 解析ジョブ失敗後にシナリオを手動完成して「準備完了」でも失敗alertが残留し状態と矛盾 (High)
- severity: High / failure_class: claimed_success_no_change (H10) / story: S3 / 由来: shard-1
- **F-H2 (T022) 修正の未カバー変種** — T022 は `missing_document` (SOP未添付422) 経路のみ解消。実ジョブが起動して失敗した `failedJob.error` 経路は対象外。
- 再現手順: (1) manuals show で SOP アップロード後「AI解析」→ ジョブ起動しテキスト抽出成功だが LLM 401 (既知 Q1) で失敗→赤字 alert「解析に失敗しました」。(2) edit で手動で Cut を追加し「シナリオを更新」保存→ status が「準備完了」に。(3) show に戻ると**「準備完了」と「解析に失敗しました」alert が同時表示**。リロードしても残る (サーバが最新 analysis job=failed を無条件返却)。
- 追加: プレビュー/完成動画パネルでも同根で、解析失敗・採用テイク未設定・書き出し失敗の**3 alert が時系列無視で積み上がる** (screenshots/F-1-triple-stacked-stale-alerts.png)。個々の検証は正しいが状態判別不能。
- 改善アクション候補: `AnalysisPanel.svelte` 293-296 の `failedJob?.error` 表示に `status !== 'ready'` 相当のガード。またはサーバ側で「scenario cuts 更新 > failed job 更新」なら stale として job を返さない。
- 証跡: shard-1/screenshots/F-1-stale-analysis-failed-alert-after-ready.png ほか。

### F-02: メンバーのロールを編集者/撮影者へ変更してもプロジェクト未作成時は無言で破棄 (High)
- severity: High (H7+H10) / failure_class: claimed_success_no_change / story: S2 / 由来: shard-2
- 再現手順: `manage/users` (組織にプロジェクト0件) でメンバーのロールを「管理者」→「編集者/撮影者」に変更→ `PATCH .../members/{user}` が 303 (エラーも成功トーストも無し、combobox は変更後の値を表示)→ リロードすると「管理者」に戻っている。owner/admin 両方で再現。
- 期待: 保存しないなら理由 (同画面に既にある「編集者・撮影者はプロジェクト作成後」注記と同趣旨) を 422+エラーで明示し combobox を戻す/invalid にする。
- 実際: 303 成功系でユーザーは変更成功と誤認。
- 改善アクション候補: サーバ側で拒否時は 422+エラーメッセージを返し、フロントは combobox 直下に表示。
- 証跡: shard-2/screenshots/F-02-member-role-update-silent-revert.png。

### F-1-2 (Low): 閾値未満の短い正当なテキストSOPに「画像・スキャンは未対応」の誤解メッセージ
- severity: Low / story: S3 / 由来: shard-1。`app/Services/Manual/SopTextExtractor.php` 49-51 が「画像/スキャン」と「テキスト抽出が100byte閾値未満」を同一メッセージに混同。短い有効テキストにも未対応表示。

### F-1-3 (Low): マニュアル作成のタイトル必須エラーが再入力時にその場でクリアされない
- severity: Low / story: S3 / 由来: shard-1。invalid フラグ/エラー文言が次の submit まで消えない (機能阻害はなし)。

### F-4-02 (Low): notifications 画面のブラウザタブ title が未設定
- severity: Low / story: S6 / 由来: shard-4。F-L2/T029 と同パターンの新規インスタンス (`notifications.index` が app_titles 未登録)。T029 の対象6ルートに含まれていなかった。

---

## 要確認 (severity 未付与)

- **Q-01** (shard-2): `legal.commerce-disclosure` (特定商取引法表記) がフッターからリンクされておらず直 URL でしか到達不能。法的表記の到達性として要確認。
- **Q-02** (shard-2): 登録時パスワードポリシー (12字以上・大小混在) が seed テストアカウントの `password123` と非整合。テスト環境ドキュメントの不整合でありアプリバグではない (要確認/test_env)。
- **Q-3-01** (shard-3, 継続): onboarding/cli の「コピー」ボタンのフィードバック (ヘッドレス環境要因の可能性、前回から継続)。
- **既知 Q1** (shard-1, 未修正): S3 中核チェーンの環境ギャップ (Anthropic 401 / ffmpeg 不在 / S3 region 未設定) は今回も想定通り発生。finding 化せず (bughunt 基盤課題)。**take レベルの record IDOR はこの storage-region ギャップで実データ検証不可のため route レベル境界のみ確認** (解消後の再検証を推奨)。

## 良好だった確認 (finding なし)

- **S7 認可境界/IDOR (shard-1)**: cross-org read/write→404、category reorder に存在オラクル無し、capture PWA cross-org→404、親子不整合→404、project_member ロール境界→403 (404と正しく区別)、protected-key injection→422、category alias もテナントスコープ。**認可漏れ検出なし** (前回「要ダブルチェック」だった点を再確認)。
- 登録直接経路のチケット付与、組織ナビ/スイッチャー、Freeプラン到達、2FA/パスワード/削除の各再認証・救済、保存トーストは全て設計通り動作。

---

## カバレッジ

- **画面**: S1(14)/S2(1)/S3(12)/S4(11)/S5(3)/S6(6+notifications) を各 shard が走行。shard-3 は API エラー中断後に再開しレポート最終化したため **projects.edit/projects.update 操作と深い H14 は今回未走行** (正直に記録、次回補完推奨)。
- **操作**: 回帰対象の書き込み操作 (invitations/members/switch/project-members/2FA/password/account-destroy 等) を 3点セットで実走。S5 checkout 系は fake harness の neutral return により完走不可 (既知)。`organizations.members.two-factor.reset` は UI 導線が見つからず skip (記録済み)。
- **未走行の主因**: (a) shard-3 中断による projects.edit/update、(b) 既知 Q1 環境ギャップによる S3 take/render 実データ、(c) fake harness による S5 checkout 完走。いずれも report に理由記載済み。

## Critical/High フォロー修正 TODO 候補 (app-design → app-todo-add 引き渡し用)

1. **F-01 (Critical)** 招待経由の未ログイン登録で組織未所属+特典誤付与を修正 (`CreateNewUser::create()` の招待分岐)。**今回修正 T021/T019 の回帰**。
2. **F-4-01 (High→Critical検討)** `user-profile-information.update` を recent-auth allowlist に追加 + 旧アドレス変更通知。**T023 の取りこぼし**。
3. **F-1-1 (High)** `AnalysisPanel.svelte` の failedJob alert を status=ready でガード (+複数 stale alert 積み上がり解消)。**T022 の未カバー変種**。
4. **F-02 (High)** `organizations.members.update` の編集者/撮影者拒否時に 422+エラー表示 (無言破棄の解消)。
