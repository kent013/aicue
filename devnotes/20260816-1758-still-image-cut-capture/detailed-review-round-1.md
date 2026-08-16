**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、実体検証をしない前提とテスト期待の矛盾、フロントの画像正規化失敗時の扱い、DTO追加に伴う取得クエリ更新漏れがあるため、このまま実装に入るのは危険です。

**S1: REQUEST_CHANGES**

[Warning] `TakeMaterialClassifier::fromContentType()` の末尾が `Assert::true(false, ...)` だけだと、PHPStan が `never` と推論できない可能性があります。  
修正案: `throw new InvalidArgumentException(...)` または `return match (...) { ..., default => throw ... }` にして、戻り値欠落を構造的に消してください。

[Suggestion] `app/Enums/Manual/MaterialType.php` は既存で使われているため、「新設」ではなく「既存 enum を takes にも適用」と書く方が誤実装を防げます。

**S2: APPROVE**

設計は妥当です。`material_type` を payload から受けず、予約行の `content_type` から確定する方針も安全です。

[Suggestion] `after(): array` には `@return array<int, callable>` を明記し、`Validator` import も設計に含めると PHPStan level 10 の実装漏れを減らせます。

**S3: REQUEST_CHANGES**

[Warning] `RenderClipSpec` を `takeSourcePath` に改名していますが、現行 `downloadSources()` 側の更新が設計に明示されていません。現行コードは `$clip->takeVideoPath` と `src{$index}.mp4` 固定です。画像素材を `.mp4` ローカル名で保存すると ffmpeg の推測に依存します。  
修正案: `downloadSources()` も `takeSourcePath` に更新し、拡張子は元 S3 キーから引き継ぐ、または `src{$index}` のように拡張子前提を消してください。

[Warning] テスト計画の `cut=null/take=video` は `EffectiveMaterialType::of(Cut $cut, Take $take)` のシグネチャと矛盾します。  
修正案: `cut.material_type=null/take=video` のケースとして書き直してください。

**S4: APPROVE**

`-max_alloc` を共通 helper でバイナリ直後に入れる設計は妥当です。ffprobe の位置引数形式まで考慮している点もよいです。

[Suggestion] `FfmpegProcessLaunchInventoryTest` は字句 pin なので、最終保証は `Process::fake()` の argv アサーションに寄せる、という整理は設計どおり維持してください。

**S5: REQUEST_CHANGES**

[Critical] `normalizeStillFile()` が `encodeStillJpeg(...).then(finish)` だけで、`drawImage()` / `toBlob()` 例外時に reject されると未処理 Promise になります。タイマーで最終的に null になる可能性はありますが、テストや UI に未処理 rejection を残します。  
修正案: `encodeStillJpeg()` 内を `try/catch` で囲んで例外時 `null` を返すか、呼び出し側で `.catch(() => finish(null))` を必ず付けてください。

[Warning] `shootStill()` で `starting = true` を立ててから `acquirePreviewStream()` を呼んでいます。既存の取得関数が `starting` を再入ガードに使っている場合、静止画撮影だけ stream 取得できない可能性があります。  
修正案: 既存 `acquirePreviewStream()` の guard を確認し、必要なら `captureActive` 用の状態と stream 取得中 guard を分ける、または取得後に `starting` を立てる設計にしてください。

**S6: REQUEST_CHANGES**

[Warning] `<img>` / `<video>` の出し分けを `take.material_type` だけに依存していますが、S8 で「実体検証しない」としているため、申告 `image/jpeg` で実体が動画のテイクは `<img>` プレビューが壊れ得ます。  
修正案: S8 の方針と合わせて、実体不一致を検証して拒否するか、「申告と実体の不一致ではプレビューが壊れ得る」を仕様として明記し、UI には画像読み込み失敗時の fallback 表示を追加してください。

**S7: REQUEST_CHANGES**

[Warning] `CutTakeSummaryData` に `adopted.material_type` を足していますが、`fromCut()` が依存する取得クエリの更新が施策に含まれていません。既存で `adoptedTake:id,status` のような列制限があると、`material_type` が未取得になります。  
修正案: `with('adoptedTake')` / select 制限箇所を全洗い出しし、`id,status,material_type` を必ず含める変更を S7 の変更ファイル・テスト対象に追加してください。

**S8: REQUEST_CHANGES**

[Critical] 「`image/jpeg` と申告して動画バイト列を置いたテイクのレンダが失敗ジョブになる」というテスト期待は、設計本文と矛盾しています。S3 の `planTakeStill()` は「1 枚目のフレームを取り出せる入力なら動画でも画像でもよい」としているため、動画バイト列は成功し得ます。  
修正案: どちらかに寄せてください。実体不一致を拒否したいなら登録時または非同期検証で MIME/ffprobe 実体検査を追加する。実体検証しない方針を維持するなら、テストは「ffmpeg がデコード不能な素材は failed job になる」に変更し、動画バイト列の成功可能性を docs に明記してください。

[Warning] 「壊れた mp4 を出さない / running のまま残らない」は重要なので、ffmpeg 非 0 終了時の `failJob` 到達、後続ジョブ処理可能性、アップロード済み孤児削除の有無まで Feature で固定してください。

**まとめ**

主要な修正点は 3 つです。

1. 実体検証しない前提と S8 の誤申告テストを整合させる。
2. `material_type` 追加に伴う eager load / select / DTO / TS の波及を明示する。
3. 静止画正規化の例外処理を null 契約に閉じる。

この 3 点を直せば、全体の設計はかなり実装可能な形になります。