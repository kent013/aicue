# 対応マトリクス: design-review Round 1

## [Critical] S2: 非 ready の採用テイクにも署名 URL / ACK を発行し続ける
- 判断: 対応する (施策 S2b として設計へ組み込む)
- 根拠: 指摘のとおり。現行 `cutWithAdoptedUrls()` は `$cut->adoptedTake` の status を見ずに
  `temporaryPlaybackUrl()` と ACK を発行しており、**`capture.takes.playback` が非 ready を
  404 にしている (状態秘匿) のと食い違う**。`adopted_ready_take_id` を足すと、この食い違いは
  「id は null なのに `playback_url` は非 null」というクライアント契約の矛盾として表面化する。
- 対応内容: `cutWithAdoptedUrls()` の先頭で `AdoptedReadyTakeCoverage::readyTakeId($cut) === null` なら
  URL / ACK を付けずに `CaptureCutData::fromCut($cut)` を返す形へ変更した
  (`RenderPipeline::clipSpecFor` と同じ書き方。非欠落側は `Assert::notNull` で型を絞る)。
  テストに「非 ready では URL / ACK が null」「`temporaryPlaybackUrl` が 1 度も呼ばれない
  (`shouldNotReceive`)」を追加。副次効果 (自動 DL が非 ready を先読みしなくなる = 処理中のテイクを
  「取得済み」と記録しない) と、その挙動変化をリスクに明記した。
  なお `video_path` は非 null カラムのため「null 参照でクラッシュ」は起きないが、
  **契約の矛盾と状態秘匿の食い違い**が本質的な問題であり、指摘の修正案どおり閉じる。

## [Warning] S2: DTO がドメイン判定 service に依存する
- 判断: 一部反論 (現設計を維持) + 指摘どおり根拠を docblock へ残す
- 根拠: 本リポジトリには既に先例が 2 件ある —
  `DataTransferObjects/Manual/TakeSelectionPageData` → `Services\Manual\CutSequencer`、
  `DataTransferObjects/Manual/ManualListItemData` → `Services\Manual\ManualRowAbilities`。
  加えて「呼び出し側が計算して DTO へ渡す」形にすると、`fromCut()` の呼び出し口
  (詳細画面 / adopt 応答) ごとに**渡し忘れうる**形になり、T148 が閉じた
  「呼び出し側が判定を組み立てる」形へ戻ってしまう。DTO 側が唯一の述語を呼ぶ方が構造的に安全である。
- 対応内容: 先例 2 件と「渡し忘れを構造で消す」理由を詳細設計へ明記し、
  同じ理由を `CaptureCutData` の docblock に残すこととした。

## [Warning] S2: array shape docblock (`has_thumbnail`) の同期
- 判断: 対応する
- 根拠: 指摘のとおり、現行 `CaptureCutData::toArray()` の入れ子 take shape は `has_thumbnail` を
  欠いており、`CaptureTakeData::toArray()` の宣言 (`has_thumbnail: bool` を含む) と食い違っている。
- 対応内容: 新キー追加の「ついで」ではなく**明示の是正項目**として波及変更に立て、
  変更後 docblock を現行実装と完全同期させた。

## [Suggestion] S3: config の正値を固定する価値
- 判断: 一部対応
- 対応内容: props の値が **1 以上の int** であることを Feature テストで固定した。
  **新しい config gate は作らない** (今必要なのは props の契約だけであり、
  設定値そのものの範囲検査を `ConfigHardeningTest` の議題にする根拠がまだ無い。思考原則 2)。

## [Warning] S4: 空リストの初期状態で `clip` が矛盾する
- 判断: 対応する
- 対応内容: 「`finished` のとき `clip` は読まない」ことを docblock に明記し、
  Vitest に「空リストでは finished かつ**どのイベントでも状態が変わらない**」を追加した
  (= `clip` の値に依存する分岐が存在しないことの固定)。

## [Warning] S4: `loading` 中の pause が扱えない / 利用者 pause とブラウザ都合 pause の区別
- 判断: 対応する (両方)
- 対応内容: (a) reducer は `paused` を `playing` **と `loading`** から受け付けるようにした
  (読み込み中に止めたのに停滞監視が動き続けて `failed` になるのを防ぐ)。
  (b) 「**`paused` は利用者操作由来のみ**」を契約として明文化し、S5 側に
  `programmaticPause` フラグ (teardown / 非表示 / スキップで自分から `pause()` する間は
  reducer へ送らない) を追加、component テストで固定することにした。
  併せて `resumed` は `playing` ではなく `loading` へ戻す (再開直後は進捗待ちのため) 形に直した。

## [Suggestion] S4: `failed` の表示待ちに `placeholderSeconds` を流用する名称
- 判断: 対応する (説明を足す。値は増やさない)
- 対応内容: 「どちらも『見せてから次へ進むまでの待ち』であり、2 つ持つと必ず食い違う」ことを
  docblock に明記した。UI 文言とテスト名では「プレースホルダ表示秒数」の意味を保つ。

## [Critical] S5: `$bindable` が実装仕様に無い
- 判断: 対応する
- 対応内容: `let { open = $bindable(false), ... }: Props = $props();` を実装仕様に明記した
  (`TakePreviewDialog` と同じ契約)。併せて `activeSlot` / `slotSrc` / `programmaticPause` の
  state 宣言も仕様へ書き下ろした。

## [Critical] S5: 2 枚の video の src 設定順序が未定義で二重取得しうる
- 判断: 対応する
- 根拠: 指摘のとおり「active entry を見て active 要素へ src を入れる `$effect`」を書くと、
  先読み済み URL を再代入して二重取得になる。設計に規則が無いと実装がその形に倒れる。
- 対応内容: `slotSrc: [string|null, string|null]` を**台帳**として持ち、
  割り当て規則を 3 つに固定した — (a) 台帳と同じ src なら**何もしない**、
  (b) `advance` は `activeSlot` を反転するだけで新 active の src に触れない、
  (c) 先読みは台帳と異なるときだけ設定する。
  「active entry を見て active 要素に src を入れる `$effect` は書かない」を明文の禁止として置き、
  component テストに「先読み済み slot が active になったあと src が再代入されない」を追加した。

## [Warning] S5: `NotAllowedError` 以外の reject で loading のまま固まりうる
- 判断: 一部反論 (現設計を維持) + 指摘された回収の固定を追加
- 根拠: 概念設計 Round 3-5 の議論で「例外名の分類に依存して失敗を確定させない」を採った
  (名前は環境差が大きく、正常なクリップを誤って欠落として見せると本機能の目的が壊れる)。
  拒否後は進捗イベントが来ないため、**停滞監視が `stallTimeoutMs` で必ず回収する**
  (`tick` は component の interval が駆動し続ける)。よって「固まる」ことはない。
- 対応内容: この依存関係を実装仕様の表へ明記し、指摘された条件
  「**stall で必ず回収できることを component test で固定**」をテスト計画に追加した
  (`NotAllowedError` 以外の拒否 → `stallTimeoutMs` 経過 → `failed` → 次へ進む)。
  併せて「回収まで最大 `stallTimeoutMs` 待たせる」ことをリスクに明記した。

## [Warning] S5: teardown / hidden の `video.pause()` も pause イベントを出す
- 判断: 対応する (S4 の Warning と同一の対応)
- 対応内容: `programmaticPause` フラグを実装仕様に追加し、
  「自分から `pause()` する間の `pause` イベントは reducer へ送らない」を契約にした。
  component テストで「programmatic pause は `paused` を作らない / 利用者 pause は作る」を固定する。

## [Warning] S6: `releaseForPreview()` の同期性が不明で資源競合が残りうる
- 判断: 反論 (根拠を設計へ明記)
- 根拠: `CameraRecorder.svelte` L487-491 の `releaseForPreview(): void` は**同期**であり、
  `starting || resuming || phase !== "idle"` のとき自分で早期 return する。
  `resumeAfterPreview(): Promise<void>` は非同期だが in-flight ガードを内部に持ち、
  既存 `TakeStrip` も戻り値を待たない。よって await も失敗ハンドリングも不要である。
- 対応内容: この事実 (行番号付き) を詳細設計へ明記し、
  「解放・復帰は page 側に 1 つの関数として切り出し、`TakeStrip` の props にも同じ関数を渡す」
  ことを併せて明文化した。

## [Warning] S6: 録画中判定が `captureActive` だけで足りるか
- 判断: 対応する (既存条件の再利用を明文化)
- 根拠: 既存の個別 preview (`TakeStrip.openPreview`) の判定も `captureActive` **のみ**であり、
  `captureActive` は recording|stopping を含む。加えて `releaseForPreview()` 自身が
  `starting || resuming || phase !== "idle"` を内部で弾くため、
  「stream は持つが captureActive=false」の窓は CameraRecorder 側で閉じている。
  ここで**別の条件を新設すると資源競合の判定が 2 種類になる**。
- 対応内容: 「判定条件・呼び出し順・文言を既存の個別 preview と同一にする」を設計に明記し、
  文言の同一性を page テストで固定することにした。

## [Suggestion] S6: 狭幅でのボタンの詰まり
- 判断: 対応する
- 対応内容: ボタン群を `flex flex-wrap items-center justify-end gap-2` で包む形へ変更した。

## [Warning] 追加: `AdoptedTakeReferenceInventory` の区分
- 判断: 対応する
- 対応内容: `CaptureManualDetailData` の区分を `DifferentCriterion` → **`DelegatedToCoverage`** へ変更し、
  根拠を「URL / ACK の発行可否を `readyTakeId()` へ委譲し、自前の ready 判定を持たない」に更新した
  (S2b により実態がそうなるため)。

## [Warning] 追加: `pnpm build` を S5/S6 の完了条件に個別に入れる
- 判断: 対応する
- 対応内容: 実装順序の各段に完了条件を書き足した
  (S5/S6 は `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build` を個別に緑にする)。
