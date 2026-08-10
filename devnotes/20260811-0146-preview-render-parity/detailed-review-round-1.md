全体判定: **CHANGES_REQUESTED**

概念方向は妥当です。render は 422、preview はブロックせず同じ判定基準で告知、生成後は manifest 由来の実績値で説明する、という分離は finding F-1-01 に対して過剰ではありません。ただし、テスト実行経路と事後説明の正確性に修正が必要です。

## 施策 1: 判定の単一化

判定: **APPROVE**

[Suggestion] `AdoptedReadyTakeCoverage::isMissing()` は唯一の述語として妥当です。`fromOrdered()` の `@param list<OrderedCut>` は設計に書かれている通り必須です。

[Suggestion] `TakeCoverageData::toProps()` は `missing_count` を全件、`missing_labels` を最大 10 件にする契約が明確で良いです。Feature test A-7 で固定する方針も妥当です。

## 施策 2: 事前告知

判定: **APPROVE**

[Suggestion] `coverage` を Inertia props に限定し、ポーリング API へ混ぜない判断は正しいです。これは「描画時点のスナップショット」という性質に合っています。

[Suggestion] ボタンを disabled にせず、押下時のサーバ応答に任せる設計は AGENTS.md / DESIGN.md に整合しています。

## 施策 3: 事後説明

判定: **REQUEST_CHANGES**

[Warning] `playbackJobId` が指す succeeded preview と `previewJob` が別世代の場合、実際に再生される動画に `placeholder_cut_count` があっても説明が出ません。設計上「誤表示より無表示」としていますが、事後説明の施策としては穴が残ります。  
修正案: `playbackJobId` だけでなく `playbackJob: RenderJobProps | null` を props に追加し、動画説明は `playbackJob.id === playbackId` の DTO から出してください。外部 API ではなく Inertia props の拡張なので過剰な一般化にはなりません。

[Warning] `RenderJobFactory` の既定値を `null` にするだけだと、既存または将来の `succeeded` state が `placeholder_cut_count=null` の succeeded job を自然に作れてしまいます。legacy 行の表現としては必要ですが、アプリ生成後の契約をテストが取り逃がします。  
修正案: base factory は `null` のままでよいですが、`succeeded()` / `previewSucceeded()` のような state があるなら `placeholder_cut_count` を明示してください。legacy を作る場合は `legacySucceededWithoutPlaceholderCount()` のように意図を分けるのが安全です。

## 施策 4: 再発防止 gate

判定: **APPROVE**

[Suggestion] finding の構造原因が「同じ判定式の複製」なので、deny-by-default gate はこの件では妥当です。既存の inventory 文化にも合っています。

[Suggestion] 「保証しないもの」で静的走査の限界を書いている点は良いです。`Take::query()` 経由の ready 判定までは捕まえない、と誇張していないのも妥当です。

## 施策 5: ドキュメント同期

判定: **APPROVE**

[Suggestion] `AGENTS.md` は末尾追加で renumber しない方針が正しいです。既存相互参照を壊しません。

## テスト計画

[Warning] Browser lane の E-2 は「押下可能性」を見るだけなら実際にクリックする必要がありません。クリックすると、環境次第で `RunManualRender` が同期実行され、ffmpeg / storage 副作用に進む可能性があります。  
修正案: Browser test は `disabled` 属性不在と可視性の assert に留めるか、クリックするなら queue が実行されないことを明示的に固定してください。

[Warning] B-1 の `RenderPipeline::run()` 直接実行は良いですが、`placeholder_cut_count` は manifest 由来であることが核なので、composer fake だけでなく「manifest に Placeholder が含まれる fixture」を明示してください。  
修正案: preview job + missing ready take を含む manual で run し、DB の `placeholder_cut_count` が missing 数と一致することを確認してください。

## 結論

設計の方向性は approve できますが、施策 3 の `playbackJobId` だけでは事後説明が実再生動画と結び切れていません。そこを `playbackJob` DTO にする修正と、Browser test の副作用回避を入れれば、この詳細設計は実装に進められます。