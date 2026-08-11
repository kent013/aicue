全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**

[Warning] 完成動画をアプリ内で観られるようにする方向性は North Star に強く整合しています。  
ただし、設計上「撮った人が結果に到達する」と言いながら、スコープ外で **撮影者 `project_member` への視聴開放はしない** としているため、期待効果の記述がやや過大です。現状の効果は「編集権限を持つユーザーが完成動画をアプリ内で視聴できる」までです。

修正提案: 期待効果を次のように限定してください。

- 「編集者が完成動画をアプリ内で確認できる」
- 「撮影者本人への視聴開放は別 TODO」
- 「撮った人が結果に到達する」は今回の完了条件に含めない

**2. 禁止事項違反**

[Warning] DL ボタンの表示条件を `status === "published"` から `finishedJob !== null` に変える方針は妥当ですが、`canManage` 条件を外すようにも読めます。もし `finishedJob !== null` だけで表示すると、UI が認可条件とズレる可能性があります。

修正提案: 表示条件は少なくとも `finishedJob !== null && canManage`、またはより正確に `canDownloadFinishedVideo` のような props を DTO 経由で渡す設計にしてください。押下時の認可はサーバ側 `Gate::authorize('download', $manual)` が正本ですが、UI 表示もそれに合わせるべきです。

**3. 実現可能性**

[Suggestion] Laravel 12 + Svelte 5 + Inertia.js で十分実現可能です。既存 route を使い、`RenderJobData` を再利用する方針も過剰ではありません。

[Warning] `CurrentRenderArtifact` の責務名は少し曖昧です。「成果物選択式の集約」なら、preview/render の両方を扱う read model 的 service になります。T148 の `AdoptedReadyTakeCoverage` と同様に単一判定ファイルとして扱うなら、メソッド境界を明確にした方がよいです。

修正提案: 例えば以下の責務に限定してください。

- `currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob`
- 定義は「同 kind の最新 succeeded を 1 件取得し、`output_path === null` なら null」
- published 判定や ability 判定はこの service に入れない

**4. 期待効果の妥当性**

[Warning] 「props と route が別世代を指す穴が構造的に消える」は概ね妥当ですが、`CurrentRenderArtifact` を導入するだけでは不十分です。playback route 側でも「渡された job が current job と同一である」ことを確認する必要があります。

修正提案: playback では次のような判定にしてください。

- `renderJob->video_manual_id !== manual->id` は認可前 404 のまま
- kind ごとの ability を通す
- `CurrentRenderArtifact::currentSucceeded($manual, $renderJob->kind)` を取得
- `null` または `current->isNot($renderJob)` なら 404

これにより「旧世代 job id を直接叩く」経路も閉じられます。

**5. リスク**

[Critical] kind=render の playback に対して、最初に一律 `Gate::authorize('render', $manual)` を呼ぶ既存構造のままだと、設計が掲げる「kind=render は download ability」にできません。さらに認可前後の 404/403 の意味も曖昧になります。

修正提案: `renderJob->video_manual_id` の 404 確認後、`kind` を分岐してから ability を呼ぶ設計を明記してください。

```php
if ($renderJob->kind === RenderKind::Preview) {
    Gate::authorize('render', $manual);
} elseif ($renderJob->kind === RenderKind::Render) {
    Gate::authorize('download', $manual);
    if ($manual->status !== VideoManualStatus::Published) {
        abort(404);
    }
} else {
    abort(404);
}
```

そのうえで current artifact 判定を行う、という順序がよいです。

[Warning] `published` でない完成動画を 404 にする方針は download と揃っていて妥当です。ただし、`Gate::authorize('download')` の前に `status !== Published` を見るか後に見るかで、存在秘匿と認可の観測差が変わります。既存 download は authorize 後に status 404 です。playback も同じ順序に揃えるべきです。

**6. スコープの適切さ**

[Suggestion] 撮影 PWA からの戻り導線を別 TODO にする判断は妥当です。今回の変更は「完成成果物の受け取り口」に絞れており、過剰ではありません。

[Warning] Architecture test `CurrentRenderArtifactInventoryTest` の新設は方向性としてよいですが、何を deny-by-default にするかが未定義です。曖昧な inventory は後から運用負荷だけ増えます。

修正提案: 機械化対象を限定してください。例えば「`renderJobs()->where(kind/status/output_path)->latest()` による current artifact 選択を controller から禁止する」程度に絞るのが適切です。

**7. 型安全性**

[Suggestion] 既存 `RenderJobData` を `playbackJob` / `finishedJob` に共用する方針は DTO パターンに合っています。独自 shape を増やさない判断もよいです。

[Warning] `finishedJob: RenderJobProps | null` を追加する場合、`types/manual.ts`、Inertia props、Svelte component props の nullability を揃える必要があります。PHPStan level 10 観点では、`CurrentRenderArtifact` が返す model の `output_path` 非 null 性も呼び出し側で再確認されるべきです。

修正提案: service は `?RenderJob` を返し、URL 発行直前に `output_path === null` を再チェックするか、非 null を保証する専用 DTO/value object にしてください。過剰化を避けるなら前者で十分です。

**8. セキュリティ**

[Critical] 設計は「層 2 は authorize より前」を維持すると書いていますが、manual と project の所属確認について明示が不足しています。抜粋では `renderJob->video_manual_id !== manual->id` は確認されていますが、`manual` が `project` に属する確認は route scope binding または controller 側に依存しています。レビュー対象設計としては、ここを曖昧にしない方がよいです。

修正提案: playback / download / show の前提として、以下を明記してください。

- `Project` は current organization に属することを認可前 404
- `VideoManual` は `Project` に属することを認可前 404
- `RenderJob` は `VideoManual` に属することを認可前 404
- その後に kind 別 ability を評価する

[Warning] kind=render の playback を開くことで、完成動画の署名 URL が `temporaryPlaybackUrl` で inline 表示されます。これは download と異なる disposition になるため、同じ S3 object でもブラウザ表示可能な経路が増えます。認可が download ability と同じなら許容できますが、テストで固定すべきです。

修正提案: Feature test に少なくとも以下を追加してください。

- 他 org / 他 project / 他 manual の render job は 404
- preview は従来どおり `render` ability
- render は `download` ability
- unpublished manual の render playback は 404
- 最新 succeeded render のみ 302、旧世代や `output_path = null` は 404

結論として、方向性は正しいですが、**認可分岐の順序と効果の過大表現**を直す必要があります。そこを修正すれば APPROVED に近い設計です。