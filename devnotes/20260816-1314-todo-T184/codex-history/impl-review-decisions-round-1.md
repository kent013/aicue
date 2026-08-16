# 対応マトリクス: impl-review Round 1

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 2 / Suggestion 1)

## [Warning] 編集者が `capture.takes.store` / `playback` / `thumbnail` を実行できることが未固定

- 判断: **対応する**
- 根拠: 指摘は正しい。PC 面の設計の肝は「新しい API 面を作らず `capture.takes.*` を再利用する」ことで、
  その再利用が認可上成立することは**テストが唯一の証拠**である。とくに
  - `store` は `upload-url` の続きであり、これが通らなければ PC アップロード (施策 4) は成立しない
  - `playback` / `thumbnail` は画面の `<video src>` / `<img src>` の出所そのものである
    (`thumbnail` は逸脱 1 で今回 PC 面が使い始めたため、Round 1 の指摘どおり固定対象に入る)
- 対応内容: `tests/Feature/Manual/PcTakeOperationTest.php` に 2 本追加した。
  - 「編集者は presigned 発行の続き (POST takes = 登録) まで通せる」
    予約行 + 署名チケット + HeadObject 一致の container mock で 201 を固定し、
    併せて**応答に `playback_url` / `download_ack_token` が載らない**ことも固定した
    (PC 面が署名 URL を受け取らないという shape 契約の裏取り)。
  - 「編集者は playback / thumbnail の 302 を受け取れる」
    302 と `Cache-Control: no-store`、`thumbnail` の Location が `thumbnail_path` を指すことを固定した。

## [Warning] `analyzing` 中 adopt の 409 が未テスト

- 判断: **対応する**
- 根拠: 設計は `rendering / analyzing` の両方を 409 と明示しており、
  `TakePreviewPanel` の事前告知も 2 状態を出し分けている。片方だけの固定では
  告知と実挙動の対応が半分しか担保されない。
- 対応内容: 「analyzing 中の adopt も 409 (rendering と同じ扱い)」を追加した。

## [Suggestion] DL 済みテイクの「削除できない」理由が押下前の説明として弱い

- 判断: **対応する**
- 根拠: `SelectableTakeData` の `downloaded` は「**理由を押下前に説明するため**に出す」と
  自分でコメントしている。バッジ 1 個ではその意図を満たしていないという指摘は妥当で、
  かつ禁止事項 8 (disabled にしない) とも両立する (押下は従来どおり受け、サーバ文言を出す)。
- 対応内容: `TakePickerList.svelte` の DL 済みテイクに
  「ダウンロード済みのため削除できません。」の補足行 (`take-downloaded-note-{id}`) を追加し、
  **削除ボタンが disabled でないこと**と併せて `ManualsTakes.test.ts` で固定した。
  ツールチップではなく本文にしたのは、hover を持たない端末でも読めるようにするため。
