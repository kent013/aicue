# S3: アプリ中核ジャーニー — SOP から完成マニュアル動画まで

- 前提状態: 編集者(project_admin)でログイン済み、Default Project あり、チケット残高あり(なければ S5 でチャージ)。reseed 推奨。
- 目的: AI-CUE の North Star フロー全体が破綻なく通るか。手順書(SOP)を起点に AI がカット設計 → 撮影 → 完成動画まで、ユーザーが「次に何をすべきか」を見失わないか。

## 手順
1. `projects.show`(動画一覧)を開く → カテゴリ/状態/検索の絞り込みが効き、空状態でも「動画を追加」導線が見える。
2. `projects.manuals.create` → タイトル・カテゴリを入力し手順書(PDF/Excel)をアップロード → `projects.manuals.store` → 作成され `projects.manuals.show` へ遷移(status=draft)。
3. 手順書追加 `projects.manuals.source-documents.store` → アップロード完了が明示される。
4. `projects.manuals.analyze`(AI 解析トリガー) → チケット残高が事前チェックされ、不足なら押下時にエラー(disabled でなく)。実行で status=analyzing。
5. `projects.manuals.jobs.show` をポーリング → 進捗(extract/decompose/generate)が表示され、完了で status=ready、失敗なら draft に戻り理由が見える。
6. `projects.manuals.edit`(シナリオ編集) → 生成された Cut(手順=step/急所=point のツリー)が表示。本文・字幕を編集し保存 `projects.manuals.scenario.update` → 楽観ロックで version が進む。別タブで先に保存すると 409 で差分再取得を促される。
7. 撮影(PWA面): `capture.home` → `capture.manuals.index` → `capture.manuals.show` でシナリオを見ながら、各 Cut にテイクをアップロード(`capture.takes.upload-url` → `capture.takes.store`)。カメラ不可環境ではファイル選択にフォールバック。テイクの並べ替え/コメント(`capture.takes.update`)、採用(`capture.takes.adopt`)、一括同期(`capture.manuals.sync`)。
8. `projects.manuals.preview`(チケット非消費)で確認 → `projects.manuals.render`(video_render チケット消費) → status=rendering → `projects.manuals.render-jobs.show` ポーリング → 完了で published。
9. `projects.manuals.render-jobs.playback` / `projects.manuals.download` で完成 mp4 を再生・DL。

## このストーリーで消化する screens / operations
- screens: projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.show, projects.manuals.render-jobs.playback, projects.manuals.download, capture.home, capture.csrf-cookie, capture.manuals.index, capture.manuals.show
- operations: projects.manuals.store, projects.manuals.update, projects.manuals.destroy, projects.manuals.source-documents.store, projects.manuals.analyze, projects.manuals.scenario.update, projects.manuals.preview, projects.manuals.render, capture.takes.upload-url, capture.takes.store, capture.takes.update, capture.takes.destroy, capture.takes.adopt, capture.takes.downloaded, capture.manuals.sync

## 逸脱アイデア (--deviate 時)
- analyze/render を二重送信 → 同時 in-flight が 1 本に抑えられるか(冪等)。失敗後のみ再実行できるか。
- 解析中/レンダ中に scenario 保存 → 禁止(409/403)されるか。published 後に編集して published→ready に戻るか。
- 残高 0 で analyze/render → 押下時エラーで詰まないか(disabled で無反応にならないか)。
- ポーリング中にブラウザ戻る/リロード → 状態が壊れないか、二重で job が増えないか。
- upload-url の署名チケットで size/content_type を偽装 → 拒否されるか(§10.8-7)。採用テイクのない Cut でレンダするとどうなるか。
