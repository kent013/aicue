# 対応マトリクス: design-review Round 1

## [Critical] 施策3: `storage_path('app/s3-fake')` ハードコードが `Storage::fake('s3_fake')` と衝突
- 判断: 対応する
- 根拠: `Storage::fake` は disk root を tmp へ差し替えるため、root 直参照だとテスト時に別領域を書き実装/テスト不整合。
- 対応内容: 一時ファイル・確定先ともに `Storage::disk(self::DISK)->path($key)` を基準にする。temp は同ディレクトリに作り（同一 filesystem）、`dirname()` を `ensureDir`。root ハードコード撤廃。パス整合を Unit テストで固定。

## [Critical] 施策9: `Storage::fake` 併用時の初期化順・env 注入順・provider 再解決が未定義
- 判断: 対応する
- 根拠: provider の register/boot は bootstrap 時に config を読むため、テスト body で config を変えても route/bind は遡って登録されない。
- 対応内容: テスト設定パターンを明文化。(a) Unit（FakeObjectStore/FakeTakeObjectStorage 等）は route/provider 不要 = 直接 new + `Storage::fake('s3_fake')`。(b) signed route を要する Feature は `withFakeStorage()` ヘルパで「config(['testing.fake_storage'=>true]) → fake を bind → provider の route 登録ロジックを再実行」を beforeEach で行う（testing かつ runningUnitTests=true で gate 成立）。app 全体再起動は不要（route/ bind の明示再登録で足りる）ことを明記。

## [Warning] 施策3: `fwrite` 戻り値未検証で部分書き込みを見逃す
- 判断: 対応する
- 対応内容: 書込完了までループ再試行し、`false` は例外化するヘルパ `writeAll()` を追加。

## [Warning] 施策3/6: sidecar 欠損時 `contentTypeOf()` が 500 になる
- 判断: 対応する
- 根拠: 「object あり sidecar 無し」は未完了扱い = 404 が自然。
- 対応内容: GET コントローラは `contentTypeOf()` を使わず `head($key)` を呼び、null→404、値あり→`contentType` 使用に統一。`contentTypeOf()` は廃止（head へ一本化）。

## [Warning] 施策5: render `upload()` が sidecar を書かず GET DL と契約が崩れる
- 判断: 対応する
- 根拠: render 成果物も `bughunt.storage.get` で配信するため sidecar（content_type）が必要。
- 対応内容: `FakeObjectStore` に `putStreamWithMeta(string $key, resource $in, string $contentType): void`（checksum 照合なしでストリーム保存 + sidecar 生成、容量上限は適用）を追加。render `upload()` はこれ経由で `video/mp4` の sidecar を必ず生成。

## [Warning] 施策9: drift 契約テストが reflection だけだと protected hook 追加時の実 S3 到達を取りこぼす
- 判断: 対応する
- 対応内容: reflection（public surface 網羅）に加え、**fake モードで主要ユースケース（take presign/head, render upload/download/url）を実行し「AWS region 未設定でも成功（実 S3 非到達）」を契約として固定する E2E 契約テスト**を追加。

## [Suggestion] key プレフィックス allowlist / checksum 形式検証 / パス整合テスト / testing∧¬unit テスト
- 判断: 対応する（多層防御・堅牢化）
- 対応内容: PUT/GET コントローラで `key` が `projects/` プレフィックス（+ `..` 不含）であることを最小検証（署名漏洩時の横断読取縮小）。`decodeMeta` で checksum が base64 sha256 長（44 文字・末尾 `=`）を軽く検証。FakeObjectStore の path 整合と gate の testing∧runningUnitTests=false ケースを明示テスト化。

## [Suggestion] 施策5: 親 `disk()` 抽象化 / 施策7: worker 起動前 env 明記
- 判断: 対応する
- 対応内容: `RenderObjectStorage` を `protected function disk(): Filesystem { return Storage::disk('s3'); }` に薄くリファクタし fake は disk 名のみ override（重複削減・drift 低減、既存テスト不変厳守）。運用手順に「`queue:listen` 起動前に `TESTING_FAKE_STORAGE=true` が worker env に入っていること（bughunt は `scripts/bug-hunt-shard.sh` が担保）」を明記。
