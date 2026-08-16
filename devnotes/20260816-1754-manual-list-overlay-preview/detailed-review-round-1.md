**全体判定: CHANGES_REQUESTED**

主な理由は、施策 1 がドメイン規約 13/T154 の「レンダ成果物の選択式は `CurrentRenderArtifact` ただ 1 箇所」に抵触しているためです。UI 方針とテストの方向性は概ね妥当ですが、props の値を作る場所が現行の `latestSucceededRender + output_path` 判定のままだと、今回の変更で「受け取れる成果物の id」を DTO 側でも選ぶことになります。

**施策 1: REQUEST_CHANGES**

[Critical] `ManualListItemData::fromManual()` が `latestSucceededRender` と `output_path` を見て `current_finished_render_job_id` を決める設計は、T154 の単一選択式に反します。bool の `downloadable` なら「受け取れるか」の副次判定に見えますが、今回の prop は id そのものなので、DTO が成果物を選択しています。  
修正案: `CurrentRenderArtifact` 経由で id を決めてください。N+1 を避けたいなら、`CurrentRenderArtifact` に一覧向けのメソッドを追加し、eager loaded な候補 relation を渡して検査する形に寄せるのがよいです。例: `CurrentRenderArtifact::currentSucceededFromLoadedCandidate($manual, RenderKind::Render)` のように、選択・検査の責務を Service 側へ移す。

[Warning] `current_finished_render_job_id` という名前は「完成動画」専用としては明確ですが、実体は `RenderKind::Render` の `RenderJob` id です。playback route は preview と render の両方を扱うため、型コメントに `kind=render` を書いている点は良い一方、将来 `preview` と混同されやすいです。  
修正案: PHP/TS のコメントだけでなく Feature テスト名にも `finished render(kind=render)` を明示し、`preview kind は一覧から返さない` ケースを維持してください。

**施策 2: APPROVE**

[Warning] `preload="metadata"` は「モーダルを開いた瞬間に playback route へ GET する」ため、一覧のプレビュー導線が軽い確認用途なら妥当ですが、署名 URL 発行回数・監査ログ・S3 側コストが増える可能性があります。  
修正案: この仕様で進めるなら、テスト名またはコメントで「開いた時点で playback を要求する」ことを契約化してください。コストを避けるなら `preload="none"` に合わせるべきです。

[Suggestion] `aria-label={`${manual?.title ?? ""} の完成動画`}` は `manual === null` かつ `playbackSrc !== null` が構造上起きないため問題はありませんが、`manual.title` を使えるよう `{#if manual !== null && playbackSrc !== null}` にすると型と意図がより揃います。

**施策 3: APPROVE**

[Warning] `onRequestPreview(manual)` は、呼び出し元で再検査せずモーダルを開く設計です。通常は行コンポーネントの `{#if finishedRenderJobId !== null}` で十分ですが、将来別経路から呼ばれた場合に `ManualPreviewModal` 側が null id を握り潰すだけになります。  
修正案: 現状のままでよいですが、`ManualPreviewModal` の「id=null なら video を描画しない」テストを必須にしてください。これは設計済みなので維持で十分です。

[Suggestion] 操作ボタンが「プレビュー / DL / 削除」の 3 つになるため、狭幅での見た目は Vitest だけでは検出できません。既存にブラウザテストのレーンがあるなら、最低 1 ケースは Playwright 側でスクリーンショット確認を足すと安心です。

**施策 4: APPROVE**

[Warning] `previewManualTarget` を閉じた後も保持する設計自体は問題ありませんが、ページ更新や props 差し替え後に古い行 id を保持し続ける余地があります。  
修正案: `ManualPreviewModal` の close 時に target を null に戻す handler を追加するか、少なくとも open=false では video が DOM から消えるテストを維持してください。現設計でもセキュリティ境界は endpoint 側なので Critical ではありません。

**施策 5: REQUEST_CHANGES**

[Critical] Feature テスト計画に「DTO が `CurrentRenderArtifact` を経由していること」を固定する観点がありません。T154 の肝は parity だけではなく、選択式の所在です。今の計画だと DTO 側に選択式を複製してもテストは通ります。  
修正案: Architecture テストまたは既存 T154 系テストへ、`CurrentRenderArtifact` 以外で `latestSucceededRender` / `output_path` / `current_finished_render_job_id` の組み合わせによる成果物選択を書けないことを追加してください。少なくとも `ManualListItemData` が `CurrentRenderArtifact` を使う実装に変更した上で、parity テストを残すべきです。

[Warning] `撮影者は一覧 id が null で playback も 403` は、playback の順序が「nested 404 → authorize 403 → published/current 404」なので妥当ですが、対象が現行世代の render job であることを明示しないと 404 と混ざります。  
修正案: 撮影者ケースでは `published + current succeeded + output_pathあり` の job id を使い、権限だけで 403 になるデータにしてください。

**施策 6: APPROVE**

[Warning] `disabled` 禁止のテストで `manual-remove-1` を常に見る場合、`deletable: true` fixture に依存します。将来 fixture の既定が変わるとテスト意図が崩れます。  
修正案: disabled テストでは `current_finished_render_job_id: 9, deletable: true` を明示してください。

**まとめ**

UI の責務分離、Inertia props の使い方、route を増やさない方針、旧 `downloadable` key の削除テストは良い設計です。修正必須なのは、完成動画 id の選択を DTO に置かず `CurrentRenderArtifact` 側へ寄せることと、その不変条件をテストで固定することです。そこを直せば全体は承認可能です。