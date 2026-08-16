# 対応マトリクス: design-review Round 1

## [Critical] S8: 「`image/jpeg` 申告 + 動画バイト列 → レンダ失敗」というテスト期待が本文と矛盾
- 判断: **対応する (指摘が正しい)**
- 根拠: 実際にそうなる。`planTakeStill()` の 1 段目 `-frames:v 1` は**動画入力でも 1 枚出す**ので、
  「still と申告して動画バイト列」は **C2 (still カット + 動画テイク) とまったく同じ経路**を通って**成功する**。
  失敗テストの題材として不適切だった。
  実際に壊れるのは**逆向き**である: `video/mp4` と申告して画像バイト列を置くと
  `material_type=video` → `planTakeVideo()` → `probeDurationMs()` の ffprobe が
  `format=duration` を数値で返せず `RenderCompositionException` になる。
- 対応内容:
  - S8 のテストを **「ffprobe が尺を取れない / ffmpeg がデコードできない素材は failed job になる」**
    に書き換える (題材は `video/mp4` 申告 + 画像バイト列)。
  - `docs/architecture.md` に **「still と申告して動画を置いた場合は先頭フレーム抽出で成功しうる
    (C2 と同じ経路)。これは害ではない」** を明記する。
  - 「壊れた mp4 を出さない / running のまま残らない / 後続ジョブが処理可能 /
    アップロード済み孤児の後始末」まで Feature で固定する (Warning 側もまとめて対応)。

## [Critical] S5: `normalizeStillFile()` が `encodeStillJpeg` の reject を拾わず未処理 Promise になる
- 判断: **対応する (指摘が正しい)**
- 根拠: `drawImage()` は tainted canvas 等で throw しうる。`.then(finish)` だけでは未処理 rejection になる。
- 対応内容: **`encodeStillJpeg()` の内側を try/catch で囲み、例外時は `null` を返す**契約にする
  (呼び出し側に catch を配って回るより、契約を 1 か所で閉じる方が漏れない)。
  加えて `normalizeStillFile` 側にも `.catch(() => finish(null))` を付け二重に閉じる。
  「失敗は `null`。呼び出し側は**原本を送らずエラー表示**する」を型で強制する。

## [Warning] S3: `downloadSources()` の更新が設計に無い / `src{$index}.mp4` 固定
- 判断: **対応する (指摘が正しい。ただし原因の理解を 1 段深くする)**
- 根拠: 現行コードは `$clip->takeVideoPath` を参照しており改名の波及先である。
  さらに `src{$index}.mp4` の拡張子は**現時点で既に嘘**である —
  `video/webm` / `video/quicktime` のテイクも `.mp4` という名前でローカルに落ちている。
  つまり合成は最初から**ffmpeg の内容プローブ**に依存しており、画像を入れても事情は変わらない。
  ただし「嘘の拡張子を増やす」のは名前が役割を表していない状態なので直す。
- 対応内容: `downloadSources()` を `takeSourcePath` へ更新し、ローカル名を
  **`src{$index}` (拡張子なし)** にする。前例は `TakeThumbnailPipeline` の `"{$workDir}/source"`
  (同じく拡張子なしで ffmpeg に渡している)。S3 の変更ファイルに `downloadSources()` を明記する。

## [Warning] S3: テスト計画の `cut=null/take=video` がシグネチャと矛盾
- 判断: **対応する** (表記ミス)
- 対応内容: `cut.material_type = null / take.material_type = video` と書き直す。

## [Warning] S5: `shootStill()` で `starting = true` を立てると stream 取得が塞がる可能性
- 判断: **反論する (現行コードを読んで確認済み)**
- 根拠: `acquirePreviewStream()` → `acquireStream()` には `starting` の再入ガードが**無い**
  (`stream ??= await navigator.mediaDevices.getUserMedia(...)` だけ)。`starting` を見ているのは
  `startRecording()` / `flipCamera()` / `releaseForPreview()` / `resumeAfterPreview()` の**入口**である。
  現行 `startRecording()` 自身が **`starting = true` → `syncActive()` → `acquirePreviewStream()`** の順で
  呼んでおり、`shootStill()` はその形をそのままなぞっている。塞がらない。
- 対応内容 (指摘は空振りだが、設計を簡単にする改善は取り込む):
  独自の `shooting` フラグを**やめ**、`startRecording()` と**完全に同じ**
  `if (starting || resuming || phase !== "idle") return;` の入口ガード + `starting` の
  try/finally で閉じる形に統一する (状態変数を 1 本減らす)。

## [Warning] S6: 申告と実体が食い違うと `<img>` プレビューが壊れ得る
- 判断: **対応する (UI の fallback を足す。実体検証は入れない)**
- 根拠: 実体検証を入れない方針は概念設計 Round 2〜3 で確定済み。
  一方「壊れたときに何も出ない」のは詰みなので、**読み込み失敗の受け皿**は要る。
- 対応内容: `TakePreviewDialog` / `TakePreviewPanel` の `<img>` に `onerror` を付け、
  失敗したら「このテイクはプレビューできません。」の告知に差し替える
  (`<video>` 側にも同じ受け皿を置くかは既存挙動を変えないため**触らない**)。
  「申告と実体の不一致ではプレビューが壊れ得る」を `docs/architecture.md` の非保証に追記する。

## [Warning] S7: `adoptedTake` の eager load が列制限されていると `material_type` が取れない
- 判断: **反論する (現行コードを全数確認済み)**
- 根拠: `adoptedTake` を eager load / 遅延読み込みしている箇所は 3 つで、**どれも列制限をしていない**:
  - `app/Services/Manual/CutSequencer.php:26` — `->with('adoptedTake')`
  - `app/Http/Controllers/Projects/VideoManualController.php:238` — `->with('adoptedTake')`
  - `app/DataTransferObjects/Manual/TakeSelectionPageData.php:38` — `->loadMissing('adoptedTake')`
  `adoptedTake:id,status` のような select 制限は 1 件も無いため、
  `material_type` は自動的に載る。追加変更は不要である。
- 対応内容: 反論の根拠 (3 箇所と列制限が無いこと) を S7 の「波及変更」に**明記**して、
  実装者が同じ確認を再度しなくて済むようにする。

## [Warning] S1: `Assert::true(false, ...)` は PHPStan が `never` と推論できない可能性
- 判断: **対応する**
- 対応内容: `throw new InvalidArgumentException(...)` に置き換える
  (戻り値欠落を構造的に消す)。`extensionFor()` 側は `match` + `Assert::notNull` のままでよいが、
  こちらも `default => throw new InvalidArgumentException(...)` に揃えて 2 メソッドの形を一致させる。

## [Suggestion] S1: `MaterialType` は「新設」ではなく「既存 enum を takes にも適用」
- 判断: **対応する** (誤実装を防ぐ表記の是正)

## [Suggestion] S2: `after()` に `@return array<int, callable>` と `Validator` import を明記
- 判断: **対応する**

## [Suggestion] S4: 字句 pin と argv アサーションの役割分担を維持
- 判断: 反映不要 (設計どおり維持する)
