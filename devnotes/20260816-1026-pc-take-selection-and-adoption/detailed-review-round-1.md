全体判定: **CHANGES_REQUESTED**

提供された詳細設計は方向性はかなり良いですが、実装前に潰すべき設計穴があります。特に「PC 側だけ編集者限定に見えるが、実際の書き込み API は project_member も叩ける」「1 分制限がクライアントだけ」「新規 props は DTO という自分の規約と施策 3 が矛盾」が主要な差し戻し理由です。

**施策 1: REQUEST_CHANGES**

[Warning] PC 画面は編集者限定だが、採用・削除・アップロード API は `capture.takes.*` のままなので `project_member` も実行可能です。  
これは PWA 要件上は妥当そうですが、「PC テイク選択・採用画面は編集者のみ」という説明だけだと、操作権限も編集者限定だと誤読されます。修正案: 設計・テストに「画面 route は `update` 権限、操作 API は既存どおり `capture` 権限」と明記し、意図するなら `project_member` が API を叩けるテストも残してください。もし PC 操作まで編集者限定にしたいなら API を分ける必要があります。

[Warning] `TakeSelectionPageData::toArray()` の `$this->cut->adoptedTake` は route-bound の `$cut` に対する lazy load になります。lazy loading 防止設定が有効な環境では落ち得ます。  
修正案: `fromCut()` 内で `loadMissing('adoptedTake')` するか、`CutSequencer::orderedWithLabels()` で取得した eager-loaded な `Cut` インスタンスを `self` に渡してください。

[Warning] `captureJson` / `extractErrorMessage` が設計上の中心なのに、追加・再利用元・CSRF/Accept ヘッダ仕様が変更ファイルに含まれていません。  
修正案: 既存 helper があるなら import 元を明記し、無いなら `credentials: "same-origin"`、CSRF、`Accept: application/json`、409/422 の文言抽出を持つ共通 helper を設計対象に追加してください。

[Warning] 新規 `app/` 側コメントに `adopted_take_id` のリテラルが複数出ています。Invariant がコメントまで見る実装なら、表示目的なのに `ScenarioWritePathInventoryTest` を壊します。  
修正案: `app/` 配下の新規コードコメントではこの識別子を直接書かず、「採用テイク外部キー」などに言い換えてください。設計書やテスト名での説明に寄せる方が安全です。

[Suggestion] `label = ''` fallback は画面タイトルが空になるため、想定外の cut 構造を静かに隠します。見つからない場合は `Assert::notEmpty()` 相当で落とすか、少なくともテストで point/step の label を固定してください。

**施策 2: APPROVE**

移設理由は妥当です。`features/capture` と `features/manual` の横参照を避けて `molecules` に上げる判断も Atomic Design 的に自然です。

[Suggestion] `TakePreviewPanel` 側では `playbackUrl === null` のときに `<video>` 自体を出さず、状態タイルや説明に切り替える設計にすると、ready 以外のテイクで不要な video 要素を持たずに済みます。

**施策 3: REQUEST_CHANGES**

[Warning] 「本タスクの新規 props は専用 DTO」と書いている一方で、`takeSummaries` は controller の private helper で生配列を組む設計になっています。これは設計内の規約違反です。  
修正案: `CutTakeSummaryData`、または `VideoManualEditPageData` 相当の DTO を追加し、TS 型と対にしてください。少なくとも「この props だけ例外」とするなら理由を明文化し、レビュー可能にしてください。

[Warning] `takeSummaries()` の並びが `orderBy('sort_order')` だけで、`CutSequencer::orderedWithLabels()` の `sort_order, id` と揃っていません。同一 `sort_order` で表示順が揺れます。  
修正案: `->orderBy('sort_order')->orderBy('id')` にしてください。

[Warning] `videoCell(cutId: number | null, label: string)` の `label` が未使用です。lint/typecheck 設定次第で落ちます。  
修正案: 引数を削除するか、`aria-label` / `data-testid` の文脈で実際に使ってください。

[Suggestion] `video-cell` が既存の行 Card 内にさらに `rounded-md border ... p-3` を作る形なら、DESIGN.md の「カード内カード」禁止に近づきます。区切り線と小さな inline action に寄せる方が安全です。

**施策 4: REQUEST_CHANGES**

[Critical] 「1 分まで」の制限がクライアントの `loadedmetadata` だけです。`durationMs` は改ざん可能で、metadata が読めない形式では通るため、業務ルールとしては強制できません。  
修正案: 本当に制限するならサーバ側でアップロード後に ffprobe 等の検査を行い、登録または処理段で拒否してください。今回サーバ強制しないなら、UI とテストを「事前警告」に下げ、「登録できません」と断定しない設計に変えるべきです。

[Warning] PC 用に memory store を使うと、オフライン時の `queued` で大きな Blob が Map に残ります。PC では保持しない方針と矛盾します。  
修正案: PC では enqueue 前に online 判定して保存しない、または `queued` が返ったら即 `store.delete(clientTakeId)` する、もしくは Blob を保持しない no-op store を別実装にしてください。

[Warning] `readDurationMs()` の詳細が未定義です。Object URL の revoke、metadata error、timeout が無いとテストやブラウザ差で詰まります。  
修正案: `URL.revokeObjectURL()` を finally 相当で必ず呼び、`loadedmetadata` / `error` / timeout の 3 経路を明記してください。

[Suggestion] 動画以外のファイルを選んだ場合も `input.value = ""` しておくと、同じファイルを再選択したときに `change` が発火しない問題を避けられます。