全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当です。route を増やさず、`RenderJobData` を再利用し、成果物選択式を保持ポリシーへ揃える判断もよいです。ただし、認可写像のテスト不能を受け入れている点と、UI 表示条件の責務説明に矛盾がある点は修正が必要です。

## 施策別判定

### 施策 1: 成果物選択式の集約
判定: **APPROVE**

`CurrentRenderArtifact::currentSucceeded()` の責務は狭く、published / ability を持たせない設計も正しいです。`latest succeeded` を先に選び、`output_path === null` なら旧世代へフォールバックしない点も保持ポリシーと整合しています。

[Suggestion] Unit test に「同 manual だが別 kind の最新 succeeded に引っ張られない」だけでなく、「別 manual の最新 succeeded に引っ張られない」も入れると、選択式の境界がより明確になります。

### 施策 2: playback を kind=render へ拡張
判定: **REQUEST_CHANGES**

[Warning] kind→ability 写像が実質未テストです。  
設計書自身が M7 で「`playback()` の ability 写像を `'render'` 固定にしても赤にならない」と認めています。これは `kind=render` は `download` ability で守る、という本施策の中核契約が regression test で固定されていない状態です。

修正案: `RenderPlaybackAbilityParityTest` ではなく、写像そのものを観測するテストを追加してください。例えばテスト内で Gate を spy/mock し、render job の playback で `Gate::authorize('download', $manual)`、preview job の playback で `Gate::authorize('render', $manual)` が呼ばれることを固定する。もしくはテスト専用 policy で `render=false / download=true` の差を作り、render playback が通り preview playback が落ちることを確認してください。M7 は「赤になる」mutation に変えるべきです。

### 施策 3: download を集約式へ載せ替え
判定: **APPROVE**

既存 download の条件を `CurrentRenderArtifact` へ寄せるだけで、DTO / Resource / route 形を変えないため影響範囲は適切です。`latest succeeded` の `output_path NULL` で旧世代に戻らない Feature test も必要十分です。

### 施策 4: props に `finishedJob` を追加
判定: **APPROVE**

`finishedJob` を `published + download ability + current artifact` の結果としてサーバ側で組み立てる設計は正しいです。`RenderJobData` を再利用し、`output_path` や署名 URL を props に載せない点も既存の権限分離と整合しています。

[Suggestion] props test では `finishedJob.id` だけでなく、`output_path` / URL 形式のキーが含まれないことを exact に確認してください。

### 施策 5: 完成動画プレイヤー UI
判定: **REQUEST_CHANGES**

[Warning] `finishedJob !== null && canManage` は、設計文の「表示可否はサーバが決めた finishedJob だけで判断する」と矛盾しています。現状は `download` ability と `canManage/update` が同値なので動きますが、将来 `download` が分岐した瞬間に、サーバは `finishedJob` を渡しているのに UI が隠す状態になります。

修正案はどちらかに寄せてください。

- 推奨: 完成動画ブロックは `{#if finishedJob !== null}` にする。`canManage` は生成・プレビュー操作ボタンだけに使う。
- `canManage` を残すなら、「完成動画表示条件は finishedJob と canManage の積である」と明記し、`canManage` と `download` ability が同値であることを trip-wire に含める。

今のままだと「判断は props で 1 回」という保証が過大です。

### 施策 6: 不変条件の機械化
判定: **REQUEST_CHANGES**

[Warning] gate の不変条件表現が強すぎます。  
「選択式を書いてよいのは `CurrentRenderArtifact` ただ 1 ファイル」と書いていますが、検出条件は `JobStatus::Succeeded` と `renderJobs(`/`RenderJob::query(` の同居だけです。`'succeeded'` 文字列、別 helper 経由、既に inventory 登録済みファイル内の追加メソッドは検出しません。

修正案: 次のどちらかにしてください。

- gate を強化する: `'succeeded'` / `"succeeded"` を含む status 条件も母集団に入れ、M1 に文字列版の mutation も追加する。
- ドキュメントを弱める: 「enum 参照を伴う app/内の直接 query を deny-by-default で固定する。文字列・動的呼び出し・同一 allowlist ファイル内の追加式は保証しない」と明記する。

現状の「守る不変条件」と「保証しないもの」の粒度がずれています。

### 施策 7: ドキュメント
判定: **REQUEST_CHANGES**

施策 2 / 5 / 6 の修正後に、`docs/architecture.md` と `AGENTS.md` の保証文を合わせてください。特に「ability 写像を behavioral に固定できない」という記述は、修正後は削除または「Gate spy / divergent policy test で固定する」に変更すべきです。

## 最終コメント

実装方針自体は良いです。主な修正点は、設計が主張している保証をテストで本当に赤化できる形に寄せることです。特に ability 写像は成果物アクセスの認可境界なので、「今は同値だから観測できない」で済ませず、テスト側で差を作って固定してください。