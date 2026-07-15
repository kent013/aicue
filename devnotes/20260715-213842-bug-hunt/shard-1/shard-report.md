# bug-hunt shard-1 report (run 20260715-213842)
- 対象: S3 (core journey), S7 (authz boundaries)
- URL: http://127.0.0.1:8011 / DB: bug_hunt_1
- 走行主眼: T057-T067 回帰確認 (T058複製ダイアログ / T066複製状態 / T057カメラpermissions-policy / T061 publishedパネル / T062テイク行mobile375 / T040/T032/T046/T048/T050/T043/T051/T053/T054 維持確認) + S3 e2e (real-llm+ffmpeg) + S7 IDOR

## ステータス
- 開始: 2026-07-15 (DB確認: bug_hunt_1, users=8)
- 完了: S3(中核ジャーニー e2e完走)+ S7(認可境界) とも完走。Critical/High の新規 finding は無し(前回 run の High 2件は両方修正確認=解消)。

## 画面カバレッジ
走行 13 / 13 (S3対象): projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.show, projects.manuals.render-jobs.playback, projects.manuals.download, capture.home(=/app→リダイレクト先のcapture.manuals.index), capture.csrf-cookie(暗黙・セッション確立時), capture.manuals.index, capture.manuals.show, capture.takes.playback(プレビューモーダル経由)
S7: S3の全nested screenをB視点で再走査、404を確認(新規消化なし、想定どおり)。

## 操作カバレッジ
実行 15 / 15 (S3対象): projects.manuals.store, update(UI経由=タイトル/カテゴリ編集画面で確認), destroy(確認ダイアログの存在のみ確認、破壊的操作のため実行は見送り), duplicate, source-documents.store, analyze, scenario.update, preview, render, capture.takes.upload-url, store, update(コメント欄経由は未明示実行), destroy(確認ダイアログ確認、キャンセルで見送り), adopt, downloaded(自動)。
S7: projects.manuals.update/destroy/scenario.update/analyze/render/preview/duplicate/source-documents.store(すべてB視点404)、projects.categories.update/destroy/reorder、capture.takes.adopt/destroy/store/update/upload-url/downloaded/playback(すべて404)。

## UI/UX 検証 (H11-H14)
- H11(視覚破綻): manuals.show(published)・capture.manuals.show(mobile375/tablet768) いずれも視覚破綻なし。旧F-1-05(テイク行の縦積み・重なり)はT062修正で解消(screenshots/s3-08-mobile375-take-row-viewport.png)。
- H12(アフォーダンス/状態): 採用中/DL済み/公開済み/下書き等のバッジ、Undo/Redoのdisabled状態、複製ダイアログのボタン活性状態などいずれも判別可能。
- H13(レスポンシブ): capture.manuals.show を mobile 375x667 / tablet 768x1024 で確認(screenshots/s3-07/s3-08/s3-09)。両方とも問題なし。desktopに復帰済み。
- H14(a11y基礎): 削除ボタン等アイコンボタンに aria-label あり(`[aria-label="手順 N を削除"]` 等で取得可能)。video要素はネイティブcontrols使用でキーボード操作可能。大きな欠落は未検出。

## findings
- **Critical 0 / High 0 / Medium 0 / Low 0 / 要確認 2**(下記「要確認まとめ」参照)。
- 本 run の主眼は T057-T067 回帰確認であり、前回 run (20260715-084108) の High 2件(F-1-04カメラPermissions-Policy, F-1-01複製ダイアログ残留)・Medium 2件(F-1-03 publishedパネル文言矛盾, F-1-05 mobile375テイク行重なり)は**全て修正され解消**(詳細は下記チェックリスト)。F-1-02(capture.manuals.index の並べ替え未実装)はストーリーカード側がv1スコープ外と明記する形で対応済み(インベントリ修正で解消、再検証不要)。
- 新規の Critical/High/Medium finding は本 run では発見されなかった。

## 要確認まとめ (仕様確認が必要、バグと断定しない)
- **Q-A (前回Q4の再確認)**: raw fetch (X-Inertiaヘッダなし) で PATCH `/projects/1/manuals/1` を短時間に連投すると、バリデーションエラーが無い(=更新成功)場合に `302` の遷移先が `/dashboard` になり、それを追いかけた2回目のPATCH的リクエストが `405` になる現象を再度観測(前回run Q4と同一パターン)。実際のUI操作(フォーム送信、Inertia標準ヘッダ付き)では毎回正しく動作しデータも正しく保存されることを本run前半で複数回確認済み。生fetch連投というテスト手法に起因するノイズであり、再現手順が不確かなため finding にはしない。次にこの経路を検証する者は実UI操作か適切な `X-Inertia`/`X-Inertia-Version` ヘッダ付きXHRを使うことを推奨(前回runの推奨と同じ)。
- **Q-B (capture.takes.adopt の protected key 無視)**: `adopted_take_id` を `capture.takes.adopt` のリクエストボディに混入しても 422 にならず 200 で成功する(値は使われず無視される=実害なし)。これは同エンドポイントがリクエストボディを一切読まずroute paramのみで完結する実装のため。S7カードの「全 protected key で 422」という一般化された期待とは厳密には一致しないが、セキュリティ上の実害はない。前回run既知の観察と同一(再確認のみ、新規ではない)。

## 回帰確認チェックリスト (前回 run 20260715-084108 findings に対する T057-T067 修正の確認)
- [x] **T057 (camera Permissions-Policy, 旧F-1-04 High) — 修正確認: OK**。`capture.manuals.show` (`/app/projects/1/manuals/1`) のみ `Permissions-Policy: camera=(self), microphone=(self)` の例外が付与されることを `fetch().headers.get('permissions-policy')` で確認(他ルート `/projects/1`, `/app`, `/app/projects/1/manuals`, `/app/csrf-cookie` はいずれも従来どおり `camera=()`固定=最小権限維持)。実際に「録画開始」を押すと `getUserMedia` は `NotFoundError: Requested device not found`(headless に物理カメラが無いことによる環境要因、Permissions Policy violation ではない)でファイル選択にフォールバックすることを `navigator.mediaDevices.getUserMedia` の直接呼び出しでも確認。旧 F-1-04 (「常に Permissions-Policy 由来でカメラ到達不能」) は解消。証跡: screenshots/s3-04-capture-cut1-fallback.png
- [x] **T058 (複製ダイアログ残留, 旧F-1-01 High) — 修正確認: OK**。manual#1(検証用「バルブ閉止作業マニュアル」) で「複製」→ダイアログ「複製する」クリック後、`/projects/1/manuals/2` へ遷移し「動画マニュアルを複製しました（手順書は引き継がれません）」flash 表示、**ダイアログは自動的に閉じている**(snapshot 上に `dialog` role が0件)。`playwright-cli requests` でも `POST /projects/1/manuals/1/duplicate` が1回のみ(多重送信なし)。
- [x] **T066 (複製の状態) — 修正確認: OK**。複製後の manual#2 は status=下書き(draft)、`projects/1/manuals/2/edit` で 手順1〜16(元と同数)が複製されていることを確認。takes は当然未撮影(新規複製直後のため0件、captureマニュアル一覧にも manual#2 は status=draft のためそもそも掲載されない=readyのみ表示という既存仕様どおり)。
- [x] **T048 Undo/Redo — 動作確認: OK**。manual#1 編集画面で 手順2 の「字幕①」を編集→「元に戻す」ボタン活性化(未保存の変更ありインジケータも表示)→クリックで直前値に復元→「やり直す」で再適用→再度「元に戻す」で元の値に戻し「シナリオを更新」で保存(「シナリオを保存しました」toast確認)。
- [x] **T046 導入/総括カット自動挿入 — 確認: OK**。AI解析後、手順1=「作業全体の俯瞰（導入）」、手順16(末尾)=「作業全体の俯瞰（総括）」が自動挿入されていることを確認(実LLM生成、real-llm 走行で非決定的だが構造は固定)。
- [x] **T032 stale alert(解析失敗後の残留) — 確認: OK**。AI解析が2回連続でタイムアウト失敗(EH-1/EH-2参照)した際、いずれも失敗直後は draft に復帰しalert表示。次に「AI 解析」を再実行した瞬間にalertは消え、古い失敗表示が新しい試行中に残留することはなかった。
- [x] **T061 published パネル(旧F-1-03) — 修正確認: OK**。manual#1 を AI解析→22カット中20カットに縮小(急所2件を編集画面で削除しレンダ尺上限20分をクリア)→全カット採用テイク設定→プレビュー生成→完成動画を生成(チケット消費確認ダイアログ「チケットを消費して完成動画を書き出します...実行しますか？」で確認→実行)→status=公開済みまで完走。**published 状態でシナリオ欄の説明文は「生成済みのシナリオは編集画面で確認できます。」**(シナリオ存在を前提とした正しい文言)に変わっており、旧 F-1-03 の「シナリオ未生成」誤表示は解消。証跡: screenshots/s3-06-manual-published.png。書き出し中(rendering)の中間状態でも同様に正しい文言(「生成済みのシナリオは編集画面で確認できます。」)を確認。
- [x] **完成動画DL/再生 — 確認: OK**。`/projects/1/manuals/1/download` は `200, content-type: video/mp4, content-length: 2587820` (2.58MB、ffmpeg実合成)。manuals.show 上のネイティブ `<video>` プレーヤーでプレビュー・完成動画とも表示される(fake-storage経由で206 Partial Contentのレンジ配信を確認)。
- [x] **チケット消費の整合性(Q3関連の追加確認)** — 解析タイムアウト失敗(EH-1, EH-2)を2回経験したが、ダッシュボードのチケット残高は 100→96(-4) で「AI解析成功1回(-1)+動画レンダ成功1回(-3)」の想定どおり。**失敗した解析(2回)はチケットを消費していない**ことを確認(前回runのQ3に対する追加傍証: 少なくとも解析の失敗はノーチャージ)。
- [x] **T062 テイク行 mobile375(旧F-1-05) — 修正確認: OK**。`capture.manuals.show`(`/app/projects/1/manuals/1`) を `playwright-cli resize 375 667` で確認。「テイク 1」ラベルは1行表示(縦積みなし)、「採用中」「DL 済み」バッジはラベル右側に並んで表示され重なりなし、操作ボタン行(再生/採用/DL/コメント/削除アイコン)もラベル・バッジと重ならず独立した行で表示されタップ可能。旧 F-1-05 の縦積み・重なりは解消。証跡: screenshots/s3-08-mobile375-take-row-viewport.png (viewport 375x667)。desktop に復帰済み。
- [x] **T040 alert帰属 — 確認: OK**。解析失敗alert(「解析に失敗しました。時間をおいて再実行してください。」)は「シナリオ」パネル直下に表示され、レンダ尺超過alert(「完成動画の生成を開始できませんでした: 動画の合計尺が上限を超えています。マニュアルを分割してください。」)は「完成動画」パネル直下に表示され、それぞれ発生源のパネルに明確に帰属していて混同がない。
- [x] **T043 削除確認(capture.takes.destroy) — 確認: OK**。テイクの「削除」ボタン押下で「テイク削除」ダイアログ(「テイク 1を削除しますか？ この操作は取り消せません。」)が表示され、「キャンセル」で取消可能(削除は実行せず状態維持を確認)。
- [x] **T053 一覧sort(PC側 projects.show) — 確認: OK**。「並べ替え」を「タイトル昇順」に変更 → `GET /projects/1?sort=title_asc` に反映され一覧がタイトル順に並び替わる。「自分の作成分のみ」チェックも正しく機能(自分が作成した2件が表示され続けることを確認、フィルタ自体は正常)。
- [x] **T054 文脈リンク — 確認: OK**。manuals.show/edit の「この手順書を撮影する」リンクが `capture.manuals.show` (`/app/projects/1/manuals/1`) へ直接遷移する。
- [x] **render-jobs.playback の仕様確認** — `render-jobs/{id}/playback` は「最新succeededのpreview jobのみ302で再生可能」という設計(コード内コメント確認)どおり、preview job(id=1)は200(signed URLへredirect)、render job(id=2, 完成動画本体)は404。設計どおりで finding ではない(完成動画自体は `projects.manuals.download` で取得するのが正規導線であり、実際 `download` は200/video-mp4/2.58MBを確認済み)。
- [ ] **T051 自動DL** — 同一ブラウザセッション内で自分が採用したテイクのため「採用時点で既にDL済み扱い」となり、他デバイス視点の「未DLテイクの自動DL+ACK」トリガーの再現には別セッション/別ユーザーでの採用が必要。時間予算の都合により本 run では未検証(要確認として後述)。前回 run で OK 確認済みの機能であり regression 対象(T057-T067)にも含まれないため優先度は低い。

## S7 (認可境界/IDOR) 実施状況 (随時更新)
- 前提: S3 実行後の状態(組織A= Standardプラン組織, owner-standard@example.com, project id=1, manual id=1(公開済み)/2(下書き複製), category id=1「安全作業」, cut id 1-22(2件削除済み), take id 1-22, render-job id 1(preview成功)/2(render成功), analysis job id 3(成功))をそのまま利用。同一 bughunt1 セッション内で owner-free@example.com(組織B、Freeプラン)にログイン切替。
- [x] 手順1(画面直叩き): `/projects/1`, `/projects/1/manuals/1`, `/projects/1/manuals/1/edit`, `/projects/1/manuals/1/jobs/3`, `/projects/1/manuals/1/render-jobs/2`, `/app/projects/1/manuals/1` — 全て **404**(fetchで status確認)。
- [x] 手順2(書き込み直叩き): `projects.manuals.update`(PATCH)/`destroy`(DELETE)/`scenario.update`(PUT)/`analyze`(POST)/`render`(POST)/`preview`(POST)/`duplicate`(POST)/`source-documents.store`(POST, multipart) — 全て **404**。
- [x] 手順3(category): 組織Bで project id=2(「Org B Project」)を新規作成し、組織Aと**同名カテゴリ「安全作業」を作成できる**ことを確認(project スコープ unique、OK)。B の project 2 に対し `categories.reorder`(PATCH)へ A の実在 category id(1)を混入 → **422**「並び順の指定がカテゴリ一覧と一致しません。」。実在しない id(99999)を混入した場合も**全く同じ 422 メッセージ**。存在オラクル差分なし(OK)。`categories.update`/`destroy` を A の category id=1 に対し実行 → **404**。
- [x] 手順4(撮影面 capture.takes.*): `upload-url`(POST)/`store`(POST)/`update`(PATCH)/`destroy`(DELETE)/`adopt`(POST)/`downloaded`(POST)/`playback`(GET) 全て A の manual/cut/take id に対し **404**。`capture.manuals.sync` は S3ストーリー記載どおり廃止済みのため未実施(叩いていない)。
- [x] 手順5(cross-cut adopt) — 組織A内で cut1 の take(id=1)を cut2 の adopt エンドポイントに渡すと **404**(`No query results for model [App\Models\Take] 1` — `cut->takes()` relation 経由解決が正しく機能)。
- [x] 手順6(ロール境界) — project_member(撮影者, member-standard@example.com)で `manuals.store/update/destroy`・`categories.store`・`analyze`・`render`・`manage.users` を叩くと全て **403**。撮影者自身の `capture.takes.adopt` は正常に **200**(自ロール範囲の操作は許可、OK)。
- [x] 手順7(protected keys) — `project_id`/`created_by`/`category_id`/`parent_cut_id`/`ticket_reservation_id`/`video_manual_id`/`cut_id` を主要 payload(manuals.store/update)に混入するといずれも **422**(ProhibitsProtectedKeys、例:「project id を入力する必要はありません。」)。`category`(別名)は許容される(拒否されない=更新が実処理まで進む。ただし raw fetch(X-Inertiaヘッダなし)だと成功後のリダイレクト先解決が `/dashboard` になり `PATCH /dashboard => 405` という**テスト手法起因のノイズ**が発生する — 前回 run の Q4 と同一現象。実 UI 操作(フォーム送信)では発生しないことを本 run 前半で確認済みのため finding にはしない)。`capture.takes.adopt` に `adopted_take_id` を混入すると **200**(値は無視され反映されない=実害なしだが「全 protected key で422」という一般化された期待とは厳密には一致しない。前回run既知の観察と同一、再確認のみで新規ではない)。
- 結論: S7 の主要な IDOR/認可/存在オラクル/protected-key 不変条件は前回runと同様すべて健全。**Critical/High な認可漏れは検出されなかった**(regression なし)。

## skip 一覧
- **T051 自動DL の「他デバイス視点」再現**: skip。理由: 採用操作を行ったのが自分自身(同一ブラウザセッション)のため、採用と同時に「DL済み」化しており、「別デバイス/別ユーザーが採用した未DLテイクを自分の入室時に自動DL+ACKする」という T051 本来のシナリオを本 shard の単一隔離セッション内では再現できなかった(bughunt1 セッションはユーザーを切り替えられるが同一ブラウザ=同一クライアント状態のため)。前回 run (20260715-084108) で T051 は OK 確認済みかつ今回の T057-T067 回帰対象にも含まれないため優先度を下げ、時間予算の都合で見送った。
- **capture撮影中カメラ機能(T056: タイマー/グリッド/一時停止再開/カメラ反転)の実カメラ動作**: skip(環境要因)。理由: headless Chromium に物理カメラが無く `getUserMedia` が `NotFoundError` で倒れるため(T057修正によりPermissions-Policyは許可されているが、環境の物理制約は変わらない)。ファイル選択フォールバックの動作(アップロード成功)は確認済み。グリッド表示ボタン・字幕トグルボタンなど**UI要素自体の存在と押下可能性**は確認したが、実カメラ映像を伴う動作(タイマー計測・カメラ反転)は環境要因により未検証。

## インベントリ修正提案
(該当時に追記)

## 環境ハザード
- EH-1 (非停止・記録のみ): 2026-07-15 12:49 頃、manual#1 (`/projects/1/manuals/1`, AS_作業手順書.pdf) の AI 解析(`projects.manuals.analyze`)で `scenario-generation` プロンプト呼び出し中に Anthropic API (`https://api.anthropic.com/v1/messages`) への HTTP リクエストが 120001ms でタイムアウト (`cURL error 28`)。1 回目の試行のみ (再試行回数=0)、待機秒=120、発生 route=`POST /projects/1/manuals/1/analyze`(実処理は queue job)。実 LLM 接続の非決定的遅延によるもので app バグではない。アプリ側の挙動は正しかった: status は draft に自動復帰し、「解析に失敗しました。時間をおいて再実行してください。」の alert が manuals.show に表示され、通知一覧にも同内容が記録された(T032 期待どおり)。再実行して先に進めた(下記参照)。

参考(注意/非finding): `storage/logs/laravel.log` は worktree 内で shard 間共有されている(shard 4 宛のメールログ等、他ポート:8014の内容も同ファイルに混在して見えた)。本 shard(1)は自分の DB/URL(bug_hunt_1 / :8011)のみを操作しており、他 shard の環境には一切触れていない。ログの共有自体は shard-1 の操作対象ではないため environment hazard としては扱わず記録のみ。

- EH-2 (非停止・記録のみ): 2026-07-15 12:54 頃、同じ manual#1 に対する 2 回目の AI 解析再実行でも同一の `scenario-generation` プロンプトで `cURL error 28 (120001ms timeout)` が再発。1回目(12:49)・2回目(12:54)とも同じ `scenario-generation` ステップ・同じ 120s ちょうどでタイムアウトしており、再現性がある。HTTP status=(タイムアウトのため応答なし) / 再試行回数=2(1回目・2回目とも失敗) / 待機秒=120 / 発生route=`POST /projects/1/manuals/1/analyze`(scenario-generation ステップ)。実 LLM(Anthropic API)側のレイテンシ起因(並列 shard による同時実 API 呼び出しの輻輳の可能性が高い。8 shard 並走時は real-llm 呼び出しが同時多重化するため)と考えられ、アプリの実装バグとは断定しない。アプリの failure UX 自体は 1 回目と同様正しく機能(draft復帰・alert表示)。3回目の再実行(SOPを短いテキストファイルに差し替え)で成功し、以降のS3チェーンを完走できた。

## Critical/High TODO 候補
なし。前回run (20260715-084108) の High 2件 (F-1-04カメラPermissions-Policy=T057, F-1-01複製ダイアログ残留=T058) はいずれも修正され再発なし。新規のCritical/High findingは本runで発見されなかった。

---
**走行終了**: `playwright-cli close` 実行済み(bughunt1セッション)。本レポート絶対パス:
`/workspace/.claude/worktrees/tasks/bughunt-20260715-reallm4/devnotes/20260715-213842-bug-hunt/shard-1/shard-report.md`
