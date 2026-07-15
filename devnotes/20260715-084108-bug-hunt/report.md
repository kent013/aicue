# bug-hunt 統合レポート (新機能 T046-T056 反映後) — run 20260715-084108

- 実行日時: 2026-07-15 (JST)
- モード: real-llm (既定) + fake storage + ffmpeg + `--all --coverage --parallel=4 --deviate`、worktree `bughunt-20260715-reallm3`
- 位置づけ: ストーリー/機能一覧を新機能(T046-T056)反映後に更新し、その実挙動を検証する初回走行

## エグゼクティブサマリ

- **新機能 T046-T056 の中核は動作確認**: shard1 で S3 中核チェーン(作成→AI解析→編集/Undo-Redo→複製→撮影/upload/adopt→preview→render→download)が実 AI + ffmpeg で e2e 完走。T046(導入/総括カット)/T048(Undo/Redo)/T049(複製コア)/T050(テイクプレビュー)/T043(削除確認)/T051(自動DL)/T040(alert帰属)/T032(stale alert)/T054(文脈リンク)/T055(招待メール自動入力)/T045(特商法リンク)/T044・T041(stale解消)/T042(名前切れ/表示トグル)/T034(通知title)/T031(メール変更recent-auth)・sync廃止・S7 IDOR は**すべて確認OK**。
- ただし新機能・既存を通じ **新規 High 4 / Medium 5 / 要確認 1** を検出。うち 2 件は今回追加機能(T049 複製ダイアログ・撮影行のモバイル崩れ)由来、1 件は**撮影PWA のカメラが全環境で起動できない重大既存問題**。

## 新機能の確認結果（動作OK）

| 機能 | 確認 |
|---|---|
| T046 導入/総括カット | ✅ AI生成シナリオに冒頭/末尾カット挿入 |
| T048 Undo/Redo | ✅ 取消/やり直し動作 |
| T049 複製(コア) | ✅ cuts複製・takes空・draft ※ダイアログ不具合=F-1-01 |
| T050 テイクプレビュー / T043 削除確認 | ✅ |
| T051 自動DL / T032 stale alert / T040 alert帰属 | ✅ |
| T054 文脈リンク / sync廃止 | ✅ |
| T055 招待メール自動入力 / T045 特商法リンク / T030 招待所属 | ✅ (shard2) |
| T044/T041 stale解消 / T042 名前切れ・表示トグル | ✅ (shard3,4) |
| T031 メール変更recent-auth / T034 通知title / F-H3/H4/H5 | ✅ (shard4) |
| S7 IDOR/認可 (実take+複製込み) | ✅ 漏れなし |

---

## 新規 findings

### F-1-04 (High): 撮影PWAのカメラが全環境で起動できない (Permissions-Policy で camera 無効)
- severity: **High**(実質 Critical: 撮影=中核機能が native カメラで一切動かない) / failure_class: broken_flow / story: S3 / 由来: shard-1
- 症状: サイト全体の `Permissions-Policy: camera=(), microphone=()`(config/security.php + SecurityHeaders middleware)が撮影PWA(`/app/*`)にも例外なく適用され、ブラウザ native のカメラ録画が**どのデバイス/ブラウザでも起動できず**、常にファイル選択フォールバックに「この端末では…」の誤解メッセージで倒れる。**T047(字幕オーバーレイ)・T056(タイマー/グリッド/一時停止/カメラ反転)は native カメラ前提のため、そもそも到達不能**。
- 改善アクション候補: `/app/*`(capture) のレスポンスで `Permissions-Policy` に `camera=(self)` 等の例外を付与(同一オリジン PWA のみ許可)。SecurityHeaders middleware に capture ルート例外を実装。
- 補足: 既存問題(gap-fill 以前から)だが、撮影機能の根幹を無効化しており最優先。bug-hunt はこれまで file-fallback で S3 を通していたため露見が遅れた。

### F-1-01 (High): マニュアル複製の確認ダイアログが成功後も開いたままで、再クリックで意図せず再複製
- severity: High / failure_class: broken_flow(H6/H7) / story: S3 / 由来: shard-1 / **T049 の不具合**
- 症状: `projects.manuals.duplicate` 成功後、Inertia は新マニュアルへ遷移するが確認モーダルが閉じず「複製する」ボタンが有効なまま残る。再クリックすると**今度は新マニュアル(現在のマニュアル)を無言で再複製**し、意図しないリソースが増える。
- 改善アクション候補: 複製成功(onSuccess)でダイアログを閉じる/ボタンを無効化する。二重送信ガード。

### F-2-02 (High): 2FA セットアップ確認で誤コードを送ってもエラーが一切出ない (無言失敗)
- severity: High / failure_class: validation_gap(H4/H1) / story: S6 / 由来: shard-2
- 症状: 2FA 有効化確認(`two-factor.confirm` / `/user/confirmed-two-factor-authentication`)で誤った TOTP を送信すると**エラー表示ゼロ**で無言失敗。ユーザーはなぜ有効化できないか分からない。(対照的にログインチャレンジは正しくエラー表示する)。
- 改善アクション候補: 確認失敗時にエラーメッセージを表示。

### F-4-01 (High): パスワード変更が HIBP チェックで10〜14秒無応答、進行中/失敗の判別不能
- severity: High / failure_class: broken_flow(H3) / story: S6 / 由来: shard-4
- 症状: パスワード変更が漏洩チェック(HIBP)の実 HTTP 呼び出しで 10〜14秒かかる間、pending 表示が無くボタンが disabled になるだけで**直前の失敗エラー文言が残ったまま**。サーバ側は成功しているが、ユーザーは「処理中か失敗か」を判別できず失敗と誤認しうる。
- 改善アクション候補: 送信中の loading/pending 表示、送信時に前回エラーをクリア。
- **併記(要確認/環境)**: HIBP が**実外部 API を叩いている**。fake-externals 方針(禁止事項4)との整合を要確認(パスワード関連の uncompromised チェックが bughunt でも実 API を叩く=Q2 再掲)。

### F-1-03 (Medium): published 後にシナリオパネルが「シナリオ未作成」表示に戻る
- severity: Medium / failure_class: claimed_success_no_change(H10) / story: S3 / 由来: shard-1
- 症状: マニュアルが published になると `AnalysisPanel.svelte` の分岐が `status==='ready'` のみ扱うため、既存の 16 カットシナリオがあるのに「まだシナリオがありません」文言へ戻り矛盾。
- 改善アクション候補: published も ready と同様にシナリオ有り表示にする。

### F-1-05 (Medium): 撮影テイク行が mobile 375px でレイアウト崩れ
- severity: Medium / failure_class: other(H11/H13) / story: S3 / 由来: shard-1 / **T050/T056 追加UI由来の可能性**
- 症状: mobile 375px で撮影テイク行のラベルが縦に折返し、再生ボタンが「採用中」「DL済み」バッジと重なる(tablet 768 は正常)。インラインプレビュー(T050)/撮影UX(T056)で要素が増えた影響とみられる。
- 改善アクション候補: テイク行の flex/min-w-0 調整でモバイル幅の重なり解消。

### F-2-03 (Medium): 未確定2FAメンバーに「2FA解除」ボタンが表示され security state を誤認させる
- severity: Medium / failure_class: other(H10) / story: S2 / 由来: shard-2
- 症状: 組織メンバー管理で、QR secret を生成しただけ(未確認)のメンバーに「2FA 解除」ボタンが出て、オーナーに「2FA 有効」と誤認させる(本人の設定画面は「無効」表示で正しい)。
- 改善アクション候補: 2FA 確定(confirmed)状態でのみ解除ボタンを表示。

### F-2-01 (Medium): パスワードリセット申請後に成功フィードバックが無い
- severity: Medium / failure_class: ux_dead_end(H7) / story: S1 / 由来: shard-2
- 症状: `/forgot-password` 送信後に成功メッセージが出ず、送信されたか判別できない。※前回 run では snapshot タイミングの早計として却下されたが、今回は再確認で finding 化。要 再現確認。

---

## 要確認 / スコープ・ストーリー記述の是正

- **F-3-01 (要確認)**: `billing.index` に容量 Quota 表示が無い(S5 ストーリーカードの記述と乖離)。dashboard/billing のロール分担の可能性、仕様確認要。
- **F-1-02 (Medium→ストーリー是正)**: `capture.manuals.index`(撮影PWA一覧)に並べ替えが無い。**T053 設計で「PWA の並べ替え UI は doc/05 が要求せず out-of-scope」と明示済み**。→ **アプリバグでなく S3 ストーリーの過剰記述**。ストーリーから「capture.manuals.index の並べ替え」を除く(自作フィルタ/メタ表示は実装済み)。
- **ストーリー記述ズレ (shard3 指摘)**: カテゴリ名上限は 20字でなく**50字**、`billing.index` の容量表示記述 → S4/S5 ストーリーを実装に合わせ是正する。
- **要確認 (shard4)**: HIBP の実外部呼び出し(F-4-01 併記)。fake-externals 方針の確認。

## カバレッジ
- 画面: S1 14/14, S2, S3 全走行(撮影/レンダー実データ含む), S4 14/14, S5, S6 6/6(notifications 含む)。
- 操作: 新機能(duplicate/takes.playback/notifications.*)含め実走。`capture.manuals.sync` は廃止済みで対象外。`debug.login-as` は UI 導線無しで skip(既知)。
- **新機能 T046-T056 はストーリー更新により初めて体系的に検証**され、動作確認 + 上記 finding を得た。

## Critical/High フォロー候補
1. **F-1-04 (High/実質Critical)** capture PWA の Permissions-Policy に camera 例外を付与(撮影機能の根幹回復)。※これが直らないと T047/T056 のカメラ系機能が実機で動かない。
2. **F-1-01 (High)** 複製ダイアログを成功後に閉じる/二重送信ガード。
3. **F-2-02 (High)** 2FA セットアップ確認失敗時のエラー表示。
4. **F-4-01 (High)** パスワード変更の pending 表示 + 前回エラークリア(+ HIBP 外部呼び出しの方針確認)。
5. Medium: published シナリオ表示(F-1-03) / 撮影行モバイル崩れ(F-1-05) / 未確定2FA解除ボタン(F-2-03) / forgot-password FB(F-2-01)。
