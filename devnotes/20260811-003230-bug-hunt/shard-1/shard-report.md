# bug-hunt report shard-1 (run 20260811-003230) 2026-08-11 00:32 JST 開始

- shard: 1 / URL: http://127.0.0.1:8011 / DB: bug_hunt_1 / session: bughunt1
- 実行ストーリー: S3 (core-journey) → S7 (authz-boundaries)。両方完走。
- skip したステップ: capture.takes.update (コメント編集/並べ替え) / capture.takes.downloaded (自動DL+ACK, T051) — 時間予算の都合。理由は「操作カバレッジ (最終)」節参照。

## 進捗メモ (走行中)
- ログイン: owner-personal@example.com (org owner。project_admin pivot 不要で編集者相当のフル操作が可能なことを確認)
- project 作成 (projects.store) 済 (Default Project, id=1)。空タイトルで422バリデーション確認・即時クリア確認 OK
- manual 作成 (projects.manuals.store) 済 (id=1, タイトル「玉掛用具割れ確認」)。空タイトル→エラー表示→入力で即時クリア OK
- 画像スキャンPDF (玉掛用具割れ確認.pdf) で AI 解析 → 「テキストを抽出できません」alert 即時表示 (同期エラー、チケット未消費) OK
- テキスト選択可能PDF (AS_作業手順書.pdf) に差し替え (source-documents.store) → toast 「手順書をアップロードしました」OK。旧エラーalertは新規analyze実行時にクリアされた (T032寄りの観察: 再アップロード直後はまだ残っていたが、次の意味ある操作で解消 → finding化せず)
- AI解析実行 (real-llm) → 解析を待機中→手順書を読み取り中→シナリオを生成中→準備完了 (約3分)。ready後「この手順書を撮影する」リンク出現 (T054) OK

- Undo/Redo (T048) 確認 OK。字幕編集→Undoで復元→Redoで再適用→シナリオ更新保存 (楽観ロック version 進行, toast「シナリオを保存しました」)
- 複製 (T049, projects.manuals.duplicate) OK。新規 manual id=2 が draft・手順書は引き継がれない旨のtoast文言も明示
- プレビュー生成 (projects.manuals.preview) をテイク未採用の状態 (67カット / 採用済0) で実行 → 201秒のmp4が生成されサーバは200/206で正しく配信 (ffprobeでH264/AAC有効ストリームを確認)。ただしplaywright-cli の chromium (オープンソースビルド、H.264非搭載) では再生できず「DEMUXER_ERROR_NO_SUPPORTED_STREAMS」。**これはテスト環境のブラウザコーデック制約であり、アプリ側の不具合ではないと判断**(要確認処理はせず、報告のみ)
- 撮影PWA (`/app`) : capture.manuals.index (フィルタ・カット数/採用済数表示 OK) → capture.manuals.show → カメラ利用不可環境でファイル選択にフォールバックする旨の status メッセージが明示 (T056関連: カメラ非対応時のフォールバックOK)。テイクアップロード (capture.takes.store) → 一覧に即反映 (22KB) → プレビュー再生+字幕トグル (T050 OK) → 採用 (capture.takes.adopt) → 「採用中」バッジ反映 (H10 OK) → 削除確認ダイアログ (T043 OK、キャンセルで温存)

- カテゴリ管理 (projects.categories.store): 「安全確認」作成 OK (toast)。同名重複 → 「指定の名前は既に使用されています。」で即時422バリデーション表示 OK
- プロジェクトメンバー追加: メンバー未選択のまま追加ボタン押下 → **リクエストが飛ばず (network 0件)、エラー表示も無く画面も変化しない** (H7寄りだが disabled 相当の抑止と見られ、visible な disabled 属性がsnapshot上は確認できず要確認扱いとし finding化はしない)。メンバー選択 (Personal Member / 編集者) → 追加 OK (toast「プロジェクトメンバーを追加しました」、一覧に反映)
- render (projects.manuals.render) を採用テイク不足の状態で実行 → 422 + alert で「採用テイクが未設定のカットがあります」を全カット列挙、明確にブロック (F-1-01 参照。preview との非対称性が finding)
- H13 レスポンシブ: mobile 375x667 (projects.show, capture.manuals.show) / tablet 768x1024 (manuals.edit) を確認、レイアウト崩れなし。desktop 1280x800 に復帰済み

### S7 (認可境界) 実施結果
- 前提: S3 で org A (Personalプラン組織, owner-personal@example.com) に project 1 (Default Project) / manual 1 (準備完了, 67カット, 1テイク採用) / manual 2 (複製draft) / category 1 (安全確認) を作成済み。org B (Starterプラン組織, owner-starter@example.com) は別ユーザーで新規に project 2 (B Project) / manual 3 (B Manual, draft) を作成し、member-starter@example.com を project 2 に撮影者(project_member)として追加。
- **B (owner-starter) から A の URL 直叩き** (`goto`): `/projects/1`, `/projects/1/manuals/1`, `/projects/1/manuals/1/edit`, `/projects/1/manuals/1/jobs/1`, `/projects/1/manuals/1/render-jobs/1`, `/projects/1/categories`, `/app/projects/1/manuals/1` → **すべて 404** (ページが見つかりません、Blade生エラーでも403でもない) OK
- **B から A への書き込み** (fetch + X-XSRF-TOKEN, 同一ブラウザ内): `PATCH/DELETE /projects/1/manuals/1`, `POST duplicate`, `PUT scenario`, `POST analyze/render/preview/source-documents`, `PATCH/DELETE /projects/1/categories/1`, `PATCH /projects/1/categories/reorder`, capture.takes.* (store/update/destroy/adopt/downloaded/upload-url, cut=1/take=1 決め打ち) → **全て 404** OK
- **存在オラクル**: `/projects/1` (実在・他組織) と `/projects/99999` (非実在) を B から fetch → 両方 404、応答時間も有意差なし (26-65ms、通常のジッタ範囲) OK。差分オラクルなし
- **tenant/protected key 混入** (B 自身の project 2 への manual作成): `project_id`/`created_by`/`category_id` を payload に混入 → 全て 422 (「project id を入力する必要はありません。」等の明確な拒否メッセージ) OK。`category` 別名は許容される設計 (別テストで型不一致 422 は確認、値の実挙動は未確認 = 要検討noteに記載)
- **ロール境界 (project_member/撮影者 → 編集者専用操作)**: member-starter (project 2 の撮影者) で `POST /projects/2/manuals`, `PATCH/DELETE manuals/3`, `POST categories`, `POST analyze/render`, `GET/POST /manage/users` を fetch → **全て 403** OK。capture PWA (`/app`) 自体へのアクセスは許可され一覧画面が正しく表示される (許可された操作は塞がれていない) OK
- **cross-cut adopt**: A (owner-personal) 自身の manual 1 内で、cut=1 に属する take=1 を **cut=2 の adopt エンドポイント**に渡す (`POST /app/projects/1/manuals/1/cuts/2/takes/1/adopt`) → **404** (`No query results for model [App\Models\Take] 1`、cut->takes() relation経由解決により拒否) OK
- 上記よりS7の中核不変条件 (子は親に属する / cross-org不可 / tenantキー不信 / ロール境界) はいずれも finding なし。設計通り機能している

## 画面カバレッジ (最終)
- projects.show ✓ / projects.manuals.create ✓ / projects.manuals.show ✓ / projects.manuals.edit ✓ /
  projects.manuals.render-jobs.show ✓ (render/preview ポーリングで実アクセス確認済み) /
  projects.manuals.render-jobs.playback ✓ (video src 経由) / capture.home ✓ (`/app` → manuals.index にリダイレクト) /
  capture.manuals.index ✓ / capture.manuals.show ✓ / capture.takes.playback ✓ (テイクプレビューダイアログ)
- **未確認 (推定は付いたが直接ページ遷移では確認していない)**: projects.manuals.jobs.show (解析中は show 画面内の
  埋め込みポーリング UI のみ観測、専用ページへの直接遷移は未実施) / projects.manuals.download (mp4 ダウンロード自体は
  preview の配信確認で代替、専用の download リンク操作は未実施) / capture.csrf-cookie (SPA 初期化時の自動 XHR と
  推測されるが個別に確認していない)
- S7: `/projects/1`, `/projects/1/manuals/1`, `/projects/1/manuals/1/edit`, `/projects/1/manuals/1/jobs/1`,
  `/projects/1/manuals/1/render-jobs/1`, `/projects/1/categories`, `/app/projects/1/manuals/1` を B から直叩き ✓ 全404

## 操作カバレッジ (最終)
- S3 operations: projects.manuals.store ✓ / .update ✓ / .destroy ✓ (確認ダイアログ+toast+一覧反映まで) /
  .duplicate ✓ / .source-documents.store ✓ / .analyze ✓ (real-llm 完走) / .scenario.update ✓ (楽観ロック更新+Undo/Redo) /
  .preview ✓ (F-1-01 finding あり) / .render ✓ (バリデーションブロックのされ方を確認) /
  capture.takes.store ✓ / capture.takes.adopt ✓ / capture.takes.destroy: 確認ダイアログ表示は確認、実削除はキャンセルし温存 (△部分実施) /
  capture.takes.upload-url: UI操作 (ファイル選択→アップロード) 経由で間接的に発火していると推測、個別のnetwork確認はしていない (△) /
  capture.takes.update ✓ 未実施 (コメント/並べ替え、時間都合でskip) / capture.takes.downloaded 未実施 (自動DL+ACKシナリオは別マニュアル/別環境が必要、時間都合でskip)
- S7 operations (越境で404を確認): projects.manuals.update/destroy/duplicate/scenario.update/analyze/render/preview/source-documents.store ✓ 全404 /
  projects.categories.update/destroy/reorder ✓ 全404 / capture.takes.store/update/destroy/adopt/downloaded/upload-url ✓ 全404 (cut=1/take=1決め打ちだが一貫して404) /
  ロール境界 (project_member→編集者専用操作): manuals.store/update/destroy, categories.store, analyze, render, manage.users ✓ 全403 /
  tenant/protected key混入 (project_id/created_by/category_id) ✓ 全422 / cross-cut adopt (cut2のadoptにcut1のtakeを渡す) ✓ 404
- skip 理由まとめ: capture.takes.update (コメント編集) と capture.takes.downloaded (自動DL+ACK, T051) は時間予算の都合でスキップ。
  いずれも致命的操作ではなく (update=UIの軽微な編集、downloaded=内部ACKでユーザー可視の影響が薄い)、次回 run での優先実施を推奨。

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): 走行した全画面 (projects.show / manuals.create・show・edit / capture 各画面 / categories) で
  レイアウト崩れ・要素重なり・横スクロールは観測されず。
- H12 (アフォーダンス/状態表現): 削除系ボタンは危険色 (赤系) で表現され、確認ダイアログの主操作/副操作の階層も明確。
  Undo/Redo ボタンは disabled 状態が snapshot 上で `[disabled]` として明確に判別可能だった。
  プロジェクトメンバー追加フォームでメンバー未選択のまま送信した際、クリックしても何も起きない
  (エラー表示も無い) 挙動を観測 (見た目上disabledかは screenshot 未確認のため要確認扱いとし finding化せず)。
- H13 (レスポンシブ): mobile 375x667 で projects.show / capture.manuals.show、tablet 768x1024 で
  projects.manuals.edit を確認。いずれも崩れなし。desktop 1280x800 に復帰済み。
- H14 (a11y基礎): 主要ボタン・フォーム要素は snapshot 上で role/name が取得でき、aria-label 欠落は観測されなかった
  (詳細なコントラスト測定は未実施)。

## H7 未検証一覧
- 0 件。書き込み操作は全て probe で `installed_now:false` かつ `pending:0` かつ `errors:0` の状態で判定できた
  (toast可視 or 一覧即時反映による肯定証拠)。probe 呼び出し忘れ・pending継続・errors発生はなし。

## findings サマリ
- Critical 0 / High 1 (F-1-01) / Medium 0 / Low 0 / 要確認 0
- F-1-01 は Critical/High として TODO 候補: 一行サマリ「プレビュー生成は未撮影カットを無警告で黒画面のまま完了する
  (完成動画生成は同じ状態を明示ブロックするのに非対称)」/ 再現手順は F-1-01 本文参照 / 阻害されたユーザージョブ =
  SOP→カット設計→撮影→プレビュー確認という North Star フローの「プレビューで進捗確認」ステップ /
  改善アクション候補 = プレビューにも render 同等の未採用テイク検出・警告表示を追加、または黒画面区間に
  プレースホルダテロップを焼き込む / 関連ファイル: 未調査 (preview 生成パイプライン, render のバリデーション実装箇所)

## インベントリ修正提案
- なし (screens.md / operations.md は現状の実装と齟齬なく一致していた)

---

# Findings 詳細 (severity 降順、逐次追記)

## F-1-01: プレビュー生成は未撮影カットを検証せず黒画面のまま完了する。完成動画生成(render)は同じ状態を検出して明示ブロックするため挙動が矛盾している
- severity: High
- story/step: S3-8 (`projects.manuals.preview` / `projects.manuals.render` の比較)
- 再現手順:
  1. owner-personal@example.com でログイン (http://127.0.0.1:8011)。Default Project (id=1) に手順書 (テキスト抽出可能なPDF) から AI 解析でシナリオ (67カット / 手順24) を生成した動画マニュアル (id=1) を用意する。
  2. カットのうち 1 件 (手順1) だけにテイクをアップロード・採用する。残り 66 カットは未採用のまま。
  3. `projects.manuals.show` (`/projects/1/manuals/1`) で「プレビュー生成」ボタン (`preview-button`) をクリックする。
  4. プレビューは正常に完了する (201 Created, `projects.manuals.preview`)。生成された mp4 は `<video>` で表示され、サーバは 200/206 で正しく配信する (ffprobe で H264+AAC の有効な 201 秒の映像であることを確認済み)。
  5. 同じ mp4 の任意フレーム (例: t=5s, t=30s) を抽出すると、字幕テキストとナレーション音声はあるが**映像は完全な黒画面** (テイク未採用のカット区間はプレースホルダ映像が無く黒塗りになる)。かつ画面上にはこの状態を示す注記が一切ない。
  6. 続けて同じ画面で「完成動画を生成」ボタン (`render-button`) をクリックし確認ダイアログで「生成する」を押すと、`projects.manuals.render` は 422 を返し、alert (`render-start-error`) で「完成動画の生成を開始できませんでした 採用テイクが未設定のカットがあります: 手順2、急所2-1、…(66件列挙)」と明確にブロックされる。
- 期待: プレビュー (チケット非消費・ラフ確認用) と完成動画生成 (チケット消費・最終成果物) は「採用テイク未設定カットがある」という同一の不整合状態に対して一貫した扱いをすべき。少なくとも、プレビューが黒画面のまま「成功」する場合は画面上に「◯件のカットは未撮影のためプレースホルダです」等の明示が必要。
- 実際: render は明確なエラーで防いでいるのに対し、preview は同じ状態を無警告で受理し、結果は 3 分強の黒画面+ナレーションのみの動画になる。ユーザーはこれを「アプリが壊れている」「AI解析が失敗した」と誤解しかねず、"未撮影カットがある" という本当の原因に気づく手がかりが画面上にない。
- 阻害されたユーザージョブ: SOP からカット設計 → 撮影 → プレビューで確認、という North Star フローの「プレビューで進捗を確認する」ステップ。テイクが揃っていない段階でプレビューを見ても意味のある確認ができず、原因不明のまま離脱するリスクがある。
- 改善アクション候補: (a) プレビュー生成時にも render と同様に「未採用テイクのカットが n 件あります」という警告 (ブロックではなく注意喚起として) を表示する、または (b) プレビュー動画内で未採用カットの区間に「未撮影」等のプレースホルダテロップを焼き込む、のいずれか。
- 証跡: screenshots/F-1-01-render-blocks-preview-does-not.png (render の 422 ブロック alert),
  screenshots/F-1-01-preview-blackframe-t30s.png (preview mp4 を ffprobe/ffmpeg でフレーム抽出した黒画面。テスト用に scratchpad へ一時DLして検証。実ブラウザでの目視ではなくサーバ生成物を直接検証した),
  network: `POST /projects/1/manuals/1/preview => 201 Created` / `POST /projects/1/manuals/1/render => 422`,
  console: render 422 は `[ERROR] Failed to load resource: ... 422` の 1 件のみ (想定内、UI側は alert で正しくハンドリング)
- 推定原因: 未調査 (preview 生成パイプライン側に render と同等の take 充足チェックが無い、または警告注記の描画が欠落していると推測される。5分調査では特定箇所まで辿れず)
- 関連既知情報: なし (S3 カード自身が「採用テイクのないCutでレンダするとどうなるか」を deviate アイデアとして明示しており、想定された調査観点)
- 備考: 生成された mp4 自体は H.264/AAC の有効なストリームで、サーバの Range 配信も正しい (206 Partial Content)。ブラウザ側 (playwright-cli 同梱の Chromium オープンソースビルド) では `DEMUXER_ERROR_NO_SUPPORTED_STREAMS` (H.264非搭載ビルドのため) で再生できないが、これはテスト環境のブラウザコーデック制約でありアプリ側の不具合ではないと判断し、本 finding には含めていない (黒画面の事実は ffprobe/ffmpeg によるサーバ生成物の直接検証で確認)。
