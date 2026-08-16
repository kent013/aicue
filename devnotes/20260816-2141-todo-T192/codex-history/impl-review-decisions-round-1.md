# 対応マトリクス: impl-review Round 1

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 3 / Suggestion 2)

## [Warning] TakePreviewPanel の imageFailed リセットが take.id 変更でしかかからない

- 判断: **対応する**
- 根拠: 指摘のとおり。同じテイクのまま署名 URL だけが変わる (再取得) 場面で、前回の失敗表示が
  残り続ける。TakePreviewDialog 側は `playbackUrl` も購読しており、2 画面で挙動が食い違っていた。
- 対応内容: `resources/js/components/features/manual/TakePreviewPanel.svelte` の `$effect` で
  `playbackUrl` も購読するようにした。参照順の問題を避けるため effect の宣言位置を
  `playbackUrl` の `$derived` より後ろへ移した。

## [Warning] FfmpegProcessLaunchInventoryTest がファイル単位でしか検査していない

- 判断: **対応する**
- 根拠: 指摘のとおり。「import と呼び出し文字列がファイルにあるか」だけだと、同じファイルへ
  安全引数なしの起動を追加しても緑のままになる。PipelineSmokeCommand は起動点を 3 つ持つため、
  この弱さが実際に効く。
- 対応内容: 検査 2 を**起動点ごと**の pin へ変えた。母集団 3 ファイルについて
  プロセス起動式 (`run([`) の件数と `FfmpegSafetyArguments::all()` の出現件数が一致すること、
  かつ現行の件数 (3 / 1 / 3) を完全一致で pin する。件数が動けば必ずレビューに出る。
  docblock に**保証しないもの**を明記した (件数一致は「母集団 3 ファイルの `run([` がすべて
  ffmpeg / ffprobe 起動である」という現状に依存する / 引数の並びは Unit テストの担当)。
  behavioral な argv テストを PipelineSmokeCommand に足す案は採らなかった —
  同コマンドは bug-hunt 専用の fail-secure gate (専用 DB 接続・fake storage gate) を通らないと
  `handle()` に到達せず、テストのために gate を緩めるのは本末転倒だからである。

## [Warning] TakeFileUpload (PC 側の静止画アップロード) に component テストが無い

- 判断: **対応する**
- 根拠: 指摘のとおり。撮影 PWA 側 (CaptureFileFallback) と同じ契約 (accept の切替 /
  正規化して送る / 失敗時に原本を送らない) を持つのに未固定だった。
- 対応内容: `tests/js/components/features/manual/TakeFileUpload.test.ts` を新設 (5 件)。
  accept の切替 / 正規化 blob を `image/jpeg` かつ `durationMs: null` で enqueue すること /
  正規化失敗で enqueue しないこと / 種別違いのファイルで enqueue しないこと (両方向) を固定した。

## [Suggestion] MaterialType と CutMaterialType が 2 ファイルに重複定義されている

- 判断: **一部対応する** (統合はしない / sync テストを両方に張る)
- 根拠: 2 つの types ファイル (`types/capture.ts` / `types/manual.ts`) は
  「PC は署名 URL の口を持たない」という理由で意図的に分けてある。片方が他方を import すると
  その分離が崩れるため、写しを 1 本にする案は採らない。一方で drift のリスクは実在するので、
  **両方を enum と突き合わせる**ことで解決する。
- 対応内容: `ManualEnumTsSyncInvariantTest` に `types/capture.ts` の `MaterialType` を
  突き合わせるテストを追加し、なぜ写しを 2 つ残すのかを同ファイルのコメントに書いた。

## [Suggestion] C1 (still/still) の通しが 1 本あると接続点が明確になる

- 判断: **対応する**
- 根拠: S1 (列) / S2 (受け入れ・確定) / S3 (実効判定・尺) は個別テストで覆えているが、
  「presign が .jpg のキーを作る → 登録が still で確定する → マニフェストが TakeStill になる」の
  接続点そのものは誰も見ていなかった。
- 対応内容: `tests/Feature/Manual/StillMaterialConsistencyTest.php` へ
  「C1 通し: 静止画の presign → 登録 → 採用 → マニフェストが TakeStill になる」を追加。
  presigned PUT の実体は持てないので、HeadObject 照合だけを予約行と一致させて模している。
