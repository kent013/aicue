# bug-hunt 統合レポート (最新修正 T057-T067 反映後) — run 20260715-213842

- 実行日時: 2026-07-15 (JST)
- モード: real-llm (既定) + fake storage + ffmpeg + `--all --coverage --parallel=4 --deviate`、worktree `bughunt-20260715-reallm4`
- 位置づけ: 前回 run(20260715-084108)の findings 修正(T057-T063)+ 監査ギャップ充填(T065-T067)+ HIBP 非本番無効化(T064)反映後の検証

## エグゼクティブサマリ

- **最新修正 T057-T067 はすべて実挙動で回帰確認 OK**。前回 run の High2/Medium2(F-1-01/03/04/05)は全て解消確認。**新規 Critical/High 0件**。
- S3 中核チェーンが実 AI + ffmpeg で **e2e 完走**(SOP→解析→編集/Undo-Redo→複製→撮影20カット全採用→プレビュー→レンダー 2.58MB mp4→DL)。S7 IDOR/認可漏れ **なし**。
- **新規 findings は 2 件のみ(Medium 1 / Low 1)**。過去 run で最もクリーン。
- **HIBP 無効化(T064)の効果を実測**: パスワード変更 PUT = **3.1秒**(旧 10-14秒から明確に改善)、登録 ~2.8秒。実 HIBP 通信が止まった。

## 最新修正の回帰確認結果（全て OK）

| finding/機能 | TODO | 実挙動での確認 | shard |
|---|---|---|---|
| camera Permissions-Policy 例外 | T057 | camera=(self) が capture.manuals.show のみにスコープ。getUserMedia の失敗は device不在(環境要因)で Permissions違反ではない | shard1 |
| 複製ダイアログ閉じ+再複製防止 | T058 | 成功後クローズ・多重複製なし | shard1 |
| 複製 status=draft 明示 | T066 | cuts複製・takes空・draft | shard1 |
| published パネル表示 | T061 | published/rendering で「未作成」に戻らない | shard1 |
| テイク行 mobile375 | T062 | レイアウト崩れ解消 | shard1 |
| 通知 個別「既読」ボタン | T065 | 遷移せず1件既読・バッジ即更新・二重送信ガード | shard4 |
| ロール422→プロジェクト作成リンク | T067 | 2組織で 422+直リンクから1ホップで projects.create | shard3 |
| 2FA確認エラー / 未確定2FA解除ボタン | T059/T063 | 誤コードにエラー表示 / 未確定は解除ボタン非表示 | shard4 |
| メール変更recent-auth / パスワード表示トグル / トースト / 通知title | T031/T042/T026/T034 | すべて OK | shard2,4 |
| 招待メール自動入力 / 招待先所属 / 特商法リンク | T055/T030/T045 | すべて OK | shard2 |
| 移譲stale / チケット購入stale / ロール変更UI反映 | T044/T041/T033 | すべて OK | shard3 |
| 導入総括カット / Undo-Redo / alert帰属 / stale alert / 削除確認 / 自動DL / 一覧sort / 文脈リンク | T046/T048/T040/T032/T043/T051/T053/T054 | 維持確認 OK | shard1 |
| **HIBP 非本番無効化** | T064 | パスワード変更 3.1秒 / 登録 2.8秒(旧10-14秒から改善) | shard2,4 |
| 唯一オーナー削除ガード / 他セッション失効 | T025/T024 | サーバ側 422 ブロック(raw DELETE でも)/ 配線確認 | shard3,4 |

**F-2-01(前回 forgot-password FB)**: 誤検知確定 — 1.5秒待てば緑トースト表示。設計での「実装済み」判定と一致。

## 新規 findings（2件のみ）

### F-2-02 (Medium): パスワードリセット完了後、/login に成功フィードバックが無い
- severity: Medium / failure_class: ux_dead_end(H7) / story: S1 / 由来: shard-2
- 症状: `password.update`(reset→/login)成功後、ログイン画面に成功トースト/メッセージが 1.5秒以上待っても出ない(機能は成功し新パスワードでログイン可)。forgot-password/verification.send の既存トーストパターンとの一貫性欠如。
- 改善アクション候補: reset 成功→/login リダイレクト時に成功 flash/トーストを表示(既存の flash-to-toast 機構を利用)。※ run 1(20260713) の F-02 と同系で、T026(settings のパスワード/プロフィール保存)の対象外だった reset フロー固有の残ギャップ。
- 証跡: shard-2/screenshots/F-2-01new-reset-password-no-toast.png。

### F-4-01 (Low): 通知0件時も「すべて既読にする」ボタンが常時有効
- severity: Low / failure_class: other(H12) / story: S6 / 由来: shard-4
- 症状: `/notifications` が0件のときも read-all ボタンが活性のままで、無意味な read-all リクエストを発火できる(無害)。
- 改善アクション候補: 未読0件時は read-all を非表示/無効化(禁止事項#8 に留意し、意味の無い操作は非表示が自然)。

## 要確認（バグ断定せず・多くは既知/意図的）
- 招待受諾がログインユーザーのメールと招待先メールを照合しない(コード上**意図的仕様**と確認済み、shard3)。
- `capture.takes.adopt` の protected key 無視(実害なし、前回 run 既知、shard1)。
- raw fetch 連投時のリダイレクトノイズ(テスト方法論由来、前回 Q4 と同一既知、shard1)。

## カバレッジ
- 画面: S1 14/14, S2, S3 全走行(撮影/レンダー実データ), S4 11/11, S5 3/3, S6 6/6(通知含む)。
- 操作: 新機能(duplicate/takes.playback/notifications.read 個別既読)含め実走。`capture.manuals.sync` は廃止済で対象外。`debug.login-as` は bughunt で isLocal ガードにより非該当 skip(既知)。
- インベントリ(screens/operations)・ストーリー(S7/S5 是正済)と乖離なし。

## 結論
最新の全修正(T057-T067)+HIBP 無効化(T064)は実挙動で回帰なく機能し、S3 中核チェーンも実 AI+ffmpeg で健全に完走。**ユースケース破綻は無し**。残る新規は Medium1(パスワードリセットの成功FB)/Low1(read-all ボタン状態)の軽微 2 件のみ。

## Critical/High フォロー候補
- (Critical/High は無し) Medium: F-2-02(パスワードリセットの成功FB追加)。Low: F-4-01(0件時の read-all 非表示)。
