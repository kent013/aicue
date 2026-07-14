# S3: アプリ中核ジャーニー — SOP から完成マニュアル動画まで

- 前提状態: 編集者(project_admin)でログイン済み、Default Project あり、チケット残高あり(なければ S5 でチャージ)。reseed 推奨。
- 目的: AI-CUE の North Star フロー全体が破綻なく通るか。手順書(SOP)を起点に AI がカット設計 → 撮影 → 完成動画まで、ユーザーが「次に何をすべきか」を見失わないか。
- 環境: real-llm 既定(実 Anthropic 接続)+ fake storage(take upload はローカル emulate)+ ffmpeg 導入済み。中核チェーンはエンドツーエンドで通る前提。

## 手順
1. `projects.show`(動画一覧)を開く → カテゴリ/状態/検索の絞り込みが効き、空状態でも「動画を追加」導線が見える。**並べ替え(更新日/タイトル × 昇順/降順)**・**「自分の作成分のみ」フィルタ**・行の**作成者/更新日メタ表示**が機能する(T053)。並べ替え/フィルタ切替が一覧に正しく反映されるか(H10)。
2. `projects.manuals.create` → タイトル・カテゴリを入力し手順書(PDF/Excel)をアップロード → `projects.manuals.store` → 作成され `projects.manuals.show` へ遷移(status=draft)。**タイトル必須エラー表示後に入力すると即座にエラーがクリアされる**か。
3. 手順書追加 `projects.manuals.source-documents.store` → アップロード完了が明示される。閾値未満の短いテキストと画像/スキャン未対応で**別々のメッセージ**になるか。
4. `projects.manuals.analyze`(AI 解析トリガー) → チケット残高が事前チェックされ、不足なら押下時にエラー(disabled でなく)。実行で status=analyzing。
5. `projects.manuals.jobs.show` をポーリング → 進捗(extract/decompose/generate)が表示され、完了で status=ready、失敗なら draft に戻り理由が見える。**real-llm 走行のため生成内容・所要時間は run ごとに変動する。固定文言を期待しない**。待機中の無反応・タイムアウト UX(H3)、失敗時の draft 復帰と理由提示(H4)を重点観察。解析ジョブ失敗後に手動でシナリオを完成させ ready にした場合、**失敗 alert が残留せず状態と矛盾しない**か(T032)。
6. `projects.manuals.edit`(シナリオ編集) → 生成された Cut(手順=step/急所=point のツリー)が表示。**生成 Cut ツリーの内容は非決定的**だが、**冒頭に導入カット・末尾に総括カットが自動挿入**されているか(T046)。件数 0 や不整合(H10)に注意。本文・字幕を編集し保存 `projects.manuals.scenario.update` → 楽観ロックで version が進み、**保存成功のフィードバック**が出る。別タブで先に保存すると 409 で差分再取得を促される。
   - **Undo/Redo(T048)**: 行の追加/削除/並べ替え/セル編集を「元に戻す/やり直す」(ボタン + Ctrl/Cmd+Z / Shift+Z)で取消・再適用できるか。保存前のローカル編集のみが対象で、サーバ状態と矛盾しないか。
   - **別名保存/複製 `projects.manuals.duplicate`(T049)**: 既存シナリオを雛形に新タイトル・カテゴリで複製 → cuts が複製され takes は空・status=draft の新マニュアルに遷移するか。他組織のマニュアル複製が 404 か(S7 連動)。
   - **撮影ナビへの文脈リンク(T054)**: 詳細/編集画面から「この手順書を撮影する」で該当マニュアルの `capture.manuals.show` へ直接遷移できるか。
7. 撮影(PWA面): `capture.home` → `capture.manuals.index`(**並べ替え/自作フィルタ/進捗バッジ/作成者メタ**が効く, T053) → `capture.manuals.show`。詳細入室時に**採用済み未DLテイクが自動ダウンロード+ACK**され DL済みバッジが反映されるか(T051)。シナリオを見ながら各 Cut にテイクをアップロード(`capture.takes.upload-url` → `capture.takes.store`)。fake storage で upload→store→adopt が 500 なく通るか。
   - **撮影中カメラプレビュー(`CameraRecorder`)**: 当該 Cut の**字幕オーバーレイ(T047)**が重畳表示され ON/OFF できるか(焼込ではないガイド)。**録画タイマー・グリッド表示・一時停止/再開(同一テイク継続)・カメラ反転(前後)(T056)**が動作するか。カメラ不可環境ではファイル選択にフォールバック。
   - テイクの並べ替え/コメント(`capture.takes.update`)、**インラインプレビュー再生 + 字幕トグル(T050、`capture.takes.playback`)**で採用前に確認、採用(`capture.takes.adopt`)、削除(`capture.takes.destroy`、**確認ダイアログ**があるか T043)。
8. `projects.manuals.preview`(チケット非消費)で確認 → `projects.manuals.render`(video_render チケット消費) → status=rendering → `projects.manuals.render-jobs.show` ポーリング → 完了で published。ffmpeg で実際に合成されるか。複数の失敗 alert(プレビュー失敗/採用テイク未設定/レンダ失敗)が**帰属明示**されるか(T040)。
9. `projects.manuals.render-jobs.playback` / `projects.manuals.download` で完成 mp4 を再生・DL(byte 一致)。

## このストーリーで消化する screens / operations
- screens: projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.show, projects.manuals.render-jobs.playback, projects.manuals.download, capture.home, capture.csrf-cookie, capture.manuals.index, capture.manuals.show, capture.takes.playback
- operations: projects.manuals.store, projects.manuals.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.source-documents.store, projects.manuals.analyze, projects.manuals.scenario.update, projects.manuals.preview, projects.manuals.render, capture.takes.upload-url, capture.takes.store, capture.takes.update, capture.takes.destroy, capture.takes.adopt, capture.takes.downloaded

## 逸脱アイデア (--deviate 時)
- 解析失敗(実 AI/レート制限由来)を UX バグと環境ハザードで区別して記録する(Anthropic 429/5xx)。環境ハザードは比較可能性のため `HTTP status / 再試行回数 / 待機秒 / 発生 route` の 1 行フォーマットで残す。
- analyze/render を二重送信 → 同時 in-flight が 1 本に抑えられるか(冪等)。失敗後のみ再実行できるか。
- 解析中/レンダ中に scenario 保存 → 禁止(409/403)されるか。published 後に編集して published→ready に戻るか。
- 残高 0 で analyze/render → 押下時エラーで詰まないか(disabled で無反応にならないか)。
- ポーリング中にブラウザ戻る/リロード → 状態が壊れないか、二重で job が増えないか。
- upload-url の署名チケットで size/content_type を偽装 → 拒否されるか(§10.8-7)。採用テイクのない Cut でレンダするとどうなるか。
- Undo を限界まで/やり直し後に新規編集 → redo スタックが正しくクリアされ状態が壊れないか。複製直後の新マニュアルで撮影→レンダーまで通るか。
- 撮影中に一時停止→再開→停止したテイクの尺・再生が正しいか。カメラ反転失敗(制約非対応)時にフォールバックへ倒れず現状維持できるか。
