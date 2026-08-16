全体判定: **CHANGES_REQUESTED**

主因は、S2/S5/S6 に実装時クラッシュや Svelte 契約不整合になりそうな点が残っているためです。設計の方向性自体は妥当ですが、このまま実装に入ると回帰を埋め込みやすいです。

## S1: 判定式の単一化

判定: **APPROVE**

[Suggestion] `readyTakeId()` の追加は T148 の趣旨に合っています。`isMissing()` をその上に載せる設計もよいです。

[Suggestion] テスト計画では `PreviewCoverageParityTest` だけでなく、`readyTakeId()` 自体の境界を props 経由で固定する方針なので十分です。

## S2: 撮影 props に `adopted_ready_take_id` を供給

判定: **REQUEST_CHANGES**

[Critical] `CaptureManualDetailData::cutWithAdoptedUrls()` が非 ready の採用テイクにも `temporaryPlaybackUrl($adopted->video_path)` と ACK token を発行し続ける設計になっています。S2 のテスト計画では「採用済み + uploading/processing/failed」を明示的に許容しているため、`video_path` が null または未使用状態の take で URL 発行に進む危険があります。  
修正案: `AdoptedReadyTakeCoverage::readyTakeId($cut) === null` の場合は採用 URL/ACK を付けない、または少なくとも `TakeStatus::Ready` 以外では `CaptureCutData::fromCut($cut)` に落とす設計へ変更してください。`adopted_ready_take_id` だけ null にして `takes.*.playback_url` は非 null という状態は、クライアント契約としても混乱します。

[Warning] `CaptureCutData` が `AdoptedReadyTakeCoverage` を直接使うため、DTO がドメイン判定 service に依存します。既存パターンとして許容できる可能性はありますが、DTO を単純直列化層に寄せているなら責務が少し重いです。  
修正案: `CaptureCutData::fromCut()` の引数に `?int $adoptedReadyTakeId` を渡す形にし、`CaptureManualDetailData` 側で coverage service を呼ぶ案も検討してください。現在の設計を採るなら「DTO が正本判定を呼ぶ例外理由」を architecture か docblock に残すべきです。

[Warning] `CaptureTakeData::toArray()` の現行 shape には `has_thumbnail` が含まれている一方、提示された `CaptureCutData::toArray()` docblock 変更後 shape には含まれていますが、現行 docblock には無いように見えます。  
修正案: S2 の修正時に `CaptureTakeData` と `CaptureCutData` の array shape を現行実装と完全同期してください。PHPStan level 10 ではここが赤くなりやすいです。

## S3: プレースホルダ尺 props

判定: **APPROVE**

[Suggestion] `config()->integer('manual.preview_placeholder_seconds')` を使うのは妥当です。

[Suggestion] 設計上「正の整数」と書いているので、既存 config gate が無ければ feature test だけでなく config hardening 系のテストで `>= 1` を固定する価値があります。

## S4: 再生の状態機械

判定: **REQUEST_CHANGES**

[Warning] `initialPreviewState()` は `entries.length === 0` でも `clip: "placeholder"` になります。`finished: true` なので実害は限定的ですが、状態としては矛盾しています。  
修正案: 空リスト専用の初期状態を明示し、`clip` を `"placeholder"` にするなら「finished 時の clip は参照しない」とコメント・テストで固定してください。

[Warning] `paused` イベントが `playing` のときしか効かないため、`loading` 中に実メディアが pause された場合は状態が進み続け、stall で failed になります。ユーザー操作の pause とブラウザ都合の pause を区別する設計ならよいですが、契約に書かれていません。  
修正案: component 側で「利用者操作由来の pause のみ送る」と明記するか、reducer 側で `loading` からの pause を扱う設計にしてください。

[Suggestion] `failed` を placeholder と同じ `placeholderSeconds` で自動 advance する仕様は良いですが、名称上は「失敗表示待ち時間」です。テスト名と UI 文言で誤解しないようにしてください。

## S5: 通し再生ダイアログ

判定: **REQUEST_CHANGES**

[Critical] `open: boolean // bindable` と書いていますが、Svelte 側の実装仕様に `$bindable(false)` がありません。S6 では `bind:open={scenarioPreviewOpen}` を使うため、このままだと bind 契約が壊れます。  
修正案: `let { open = $bindable(false), ... }: Props = $props();` を明記してください。

[Critical] 「1 クリップ 1 回取得」を目標にしつつ、`advance` 時の `activeSlot` 反転と `src` 設定の副作用順序が未定義です。Svelte の `$effect` で active entry を見て active 要素に `src` を設定する実装にすると、先読み済み URL を再代入して二重取得する可能性があります。  
修正案: 状態に `slotAssignments` または `loadedBySlot` を持たせる、少なくとも「既に該当 slot に同じ src が入っていれば再設定しない」「advance 直後は inactive だった要素を active として使い、src 再設定 effect を走らせない」という実装契約を追加してください。

[Warning] `play()` 拒否で `NotAllowedError` 以外は何も送らない設計は、恒久的な reject が `error` イベントにも stall にも繋がらないケースで loading のままになります。  
修正案: NotAllowedError は `blocked`、それ以外の reject は世代一致を確認して `failed` にする方が安全です。もし現設計を維持するなら「stall で必ず回収できる」ことを component test で固定してください。

[Warning] `pause` イベントを「利用者操作」として扱うとありますが、`video.pause()` を teardown や hidden で呼んだ場合にも pause event が出ます。  
修正案: programmatic pause 中のフラグを持ち、その間の `pause` event は reducer に送らない契約を追加してください。

## S6: 撮影画面への配線

判定: **REQUEST_CHANGES**

[Warning] `onRequestCameraRelease()` を page 側に切り出す方針は良いですが、`openScenarioPreview()` で `onRequestCameraRelease(); scenarioPreviewOpen = true;` としており、release が非同期・失敗可能なら資源競合が残ります。提示コードからは `releaseForPreview()` の戻り値が不明です。  
修正案: 既存 `TakeStrip` と同じ契約に合わせ、同期ならその旨を明記してください。Promise なら `await` して失敗時に alert を出し、dialog を開かない設計にしてください。

[Warning] 録画中判定が `captureActive` のみです。録画停止中や camera 初期化中など、`recorderRef` が stream を保持しているが `captureActive === false` の状態があるなら競合します。  
修正案: 既存の個別 preview と同じ条件を再利用し、状態名を「録画中」ではなく「preview に資源を渡せない状態」として切り出してください。

[Suggestion] 左ペインヘッダは狭幅でボタンが詰まりやすいです。`flex-wrap` またはボタン群を折り返す responsive class を設計に入れておくと安全です。

## S7: ドキュメント更新

判定: **APPROVE**

[Suggestion] `doc/05` の「先頭テイク」表現と実装注記の差分説明は妥当です。完成動画と同じ素材を使う理由も North Star に合っています。

## S8: 権限の非回帰確認

判定: **APPROVE**

[Suggestion] 既存 route/ability を増やさない判断は妥当です。`capture.takes.playback` に依存する設計なので、撮影者が ready take の playback を取得できる既存テストを名指ししている点もよいです。

## 追加で必要な修正

[Warning] `AdoptedTakeReferenceInventory` の `CaptureManualDetailData` を `DifferentCriterion` のままにする説明は少し弱いです。実際には `readyTakeId()` を呼ぶなら「判定は委譲済み」に近い分類です。  
修正案: inventory の enum 定義に合わせ、`DelegatedToCoverage` が適切なら変更してください。`DifferentCriterion` のままなら「採用テイク実体の読み取りは表示/URL 発行用途で、ready 判定は coverage に委譲」と根拠をより明確にしてください。

[Warning] テスト計画に `pnpm build` は最後にありますが、Svelte 5 + bindable + Modal/Portal 周りは `pnpm typecheck` だけでなく実 build で落ちることがあります。  
修正案: S5/S6 の完了条件に `pnpm test`、`pnpm typecheck`、`pnpm build` を個別に入れてください。

設計の核である「採用済みかつ ready の判定をサーバ正本に閉じる」「撮影者向け preview は既存 take playback を連続再生する」は承認できます。修正すべき中心は、非 ready 採用テイクへの URL 発行、Svelte bind 契約、2 video 先読みの副作用順序です。