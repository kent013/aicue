# bug-hunt 統合レポート (real-llm 2nd / Q1完全クローズ検証) — run 20260714-154640

- 実行日時: 2026-07-14 (JST)
- モード: **real-llm (既定)** + `--all --coverage --parallel=4 --deviate`、worktree `bughunt-20260714-reallm2`
- 環境: 実 Anthropic 接続 / **fake storage 稼働 (T038)** / **ffmpeg 導入済み (T039)** / Stripe 等は fake 維持
- 位置づけ: **Q1 (LLM/storage/ffmpeg 環境ギャップ) 完全クローズの検証** + 直近修正 (T037/T040/T041/T042) の回帰確認

## 🎯 エグゼクティブサマリ — Q1 = CONFIRMED CLOSED

- **S3 中核ジャーニーがエンドツーエンドで完走（500 ゼロ）**: SOP → 実LLM解析 → take upload → adopt → preview/render(ffmpeg) → download。
  - 実 Anthropic で 5項目 SOP から **11カットのシナリオ生成**
  - **T038 検証**: 全11カットの take upload(upload-url→PUT→fake storage→store→adopt)が **500 にならず成功**（前回は S3 region 500 で全滅）
  - **T039 検証**: preview + full render が **ffmpeg で成功 → 実再生可能な 22秒/1.4MB mp4 を生成し download**（preview/render/download 間で byte 一致）
- **T037 回帰**: 撮影画面が mobile375/tablet768 で **横 overflow 解消**（カット説明が正しく truncate）
- **S7 IDOR**: 実 take データで **take レベルの cross-org IDOR も実検証可能に** → 全 cross-org 404、role 境界 403、protected-key 422、署名URL改竄 403。**Critical/High ゼロ、防御堅牢**
- **新規 findings は 3件(Medium2/Low1)+要確認1** のみ。重大なものは無し。

## 回帰確認結果 (直近修正)

| 前回 finding | TODO | 判定 | shard |
|---|---|---|---|
| F-1-3 撮影画面 横overflow | T037 | ✅ FIXED (375/768 で overflow なし) | shard1 |
| F-1-1 scenario保存トースト / F-1-2 alert帰属 | T040 | ✅ (S3 走行で保存・alert 挙動正常) | shard1 |
| F-3-01 purchase-tickets stale invalid | T041 | ✅ FIXED (有効値修正でエラー消失) | shard3 |
| F-3-02 manage/users タブレット名切れ | T042 | ✅ FIXED (768/375 でフル表示) | shard3 |
| F-4-03 settings パスワード表示トグル | T042 | ✅ FIXED (recent-auth 画面にも横展開) | shard4 |
| F-01 招待所属 / F-H1 登録チケット | T030/T021 | ✅ デグレなし | shard2 |
| F-4-01 メール変更 recent-auth | T031 | ✅ (stale で 409、直接 fetch も遮断) | shard4 |
| F-H3/H4/H5 / F-4-02 / F-L1 / F-M1 | T023-26/34 | ✅ 全て回帰OK (F-M1 も今回確定) | shard4 |
| 前回 Critical/High (組織ナビ/Free締め出し/メンバーUI/title) | T019/20/28/29 | ✅ 全て解消維持 | shard3 |

> **adjudication consult**: 新規 4 findings は全件 `adjudication_status: none` = 未知/actionable。

---

## 新規 findings

### F-1-2 (Medium): capture.takes.destroy に確認ダイアログが無い
- severity: Medium / failure_class: other(H7) / story: S3 / 由来: shard-1
- 症状: テイク削除(capture.takes.destroy)が、アプリの他の破壊的操作(マニュアル削除等)と違い**確認ダイアログ無しで即削除**される。撮影現場での誤タップによるテイク喪失リスク。
- 改善アクション候補: 削除前に確認ダイアログ(他の destructive 操作と同じ molecule)を挟む。
- 由来: 実 take データが作れるようになって初めて到達できた操作。

### F-3-01 (Medium): オーナー移譲フォームの select で stale invalid が残る (T041 の横展開漏れ)
- severity: Medium / failure_class: validation_gap(H12) / story: S4 / 由来: shard-3
- 症状: オーナー移譲(organizations.transfer-ownership)の「移譲先メンバー」select で、空値送信後に有効な選択肢を選んでも invalid/エラー文言が消えない。機能はブロックされないが視覚的に矛盾。
- 改善アクション候補: T041(purchase-tickets)と同じく、有効選択で clientError を連動クリア。**同種 stale-validation パターンの横展開漏れ**なので、他フォームの一括点検も推奨。
- 証跡: shard-3/screenshots/F-3-03-transfer-ownership-stale-invalid-clean.png。

### F-2-01 (Low): 特定商取引法ページ(commerce-disclosure)がサイト内から未リンク
- severity: Low / story: S1 / 由来: shard-2
- 症状: legal.commerce-disclosure は URL 直打ちでは表示されるが、home/pricing/footer 等どこからもリンクされていない孤立ページ。法的表記の到達性の観点で要改善(前回 run の Q-01 と同観察)。
- 改善アクション候補: フッター等に特定商取引法表記へのリンクを追加。

---

## 要確認

- **F-1-1 (needs_review)**: capture.manuals.sync(operations.md 割当の S3 操作)はバックエンド実装済みだが、フロントに呼び出す経路が無い。意図的(将来の SW background-sync 用)か実装ギャップか仕様確認が必要 (由来 shard-1)。
- **F-2-01 の誤検知却下 (参考)**: 招待受諾直後に組織スイッチャーが自動切替しないのは T030 の意図的設計(既知)。forgot-password トースト未表示は snapshot タイミングの早計(実際は表示)で regression なし。

## カバレッジ

- 画面: S1 12/13, S2 1/1, **S3 全走行(take/render 実データ含め完走)**, S4 11/11, S5 3/3, S6 全走行。
- 操作: **S3 の take/render 系(upload-url/store/adopt/update/destroy/downloaded/sync, preview, render, playback, download)を実データで実走**(Q1 クローズにより初めて可能に)。S5 checkout は fake harness で仕様内確認。organizations.members.two-factor.reset は UI 導線不在で skip(既知)。
- **未走行の主因は解消**: 前回まで S3 中核を塞いでいた Q1(LLM/storage/ffmpeg)が全てクローズし、S3 の網羅が達成された。

## 結論

**Q1(LLM 401 / S3 storage region 500 / ffmpeg 不在)は完全にクローズ**し、real-llm モード + fake storage + ffmpeg により **S3 中核チェーン(AI解析→撮影→レンダー→ダウンロード)が実 AI・実 ffmpeg で完走**することを実証。直近の全修正(T019–T042)は回帰なく維持されている。残る新規は Medium2/Low1/要確認1 と軽微。

## Critical/High フォロー候補
- (新規に Critical/High は無し) Medium: F-1-2 (take 削除確認ダイアログ) / F-3-01 (移譲フォーム stale + 同種横展開点検) は次サイクルで対応可能。
