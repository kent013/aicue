**施策別レビュー**

- **施策1 `s3_fake` disk追加**: **APPROVE**
  - [Suggestion] `root` は `storage_path('app/s3-fake')` で実体、`FakeObjectStore` 側の一時ファイル生成パスと完全一致することをテストで固定すると安全です（将来の表記ゆれ防止）。

- **施策2 `FakeStorageGate`**: **APPROVE**
  - [Suggestion] `enabled()` は要件どおり fail-secure。加えて「`testing` かつ `runningUnitTests=false`」ケースを明示テスト化して、HTTP実行時の誤通過を固定するとより堅いです。

- **施策3 `FakeObjectStore` / `FakeObjectMeta`**: **REQUEST_CHANGES**
  - [Critical] `Storage::fake('s3_fake')` 前提テストと `absolutePath()` / `rename()` 実装が不整合です。`Storage::fake` は tmp ルートへ差し替えるため、`storage_path('app/s3_fake')` 直参照だとテスト時に別領域を書きます。  
    **修正案**: 一時ファイル作成・確定先ともに `Storage::disk(self::DISK)->path($key)` を基準にし、`dirname` を `ensureDir`。`root` をハードコードしない。
  - [Warning] `fwrite($out, $chunk)` の戻り値未検証で部分書き込みを見逃します。  
    **修正案**: ループで書込完了まで再試行し、`false` は例外化。
  - [Warning] `contentTypeOf()` は sidecar 欠損で 500 になります。GETで「objectあり/sidecarなし」は未完了扱い設計なので 404 が自然です。  
    **修正案**: コントローラで `head($key)` を使って nullなら404、値があれば `contentType` を使う。
  - [Suggestion] `decodeMeta()` で `checksum` 形式（base64 sha256長）を軽く検証すると異常検知が早まります。

- **施策4 `FakeTakeObjectStorage`**: **APPROVE**
  - [Suggestion] 既存 concrete mock との両立は概ね成立。`app()->instance()` が最終勝ちする前提を回帰テストで固定する方針は妥当です。

- **施策5 `FakeRenderObjectStorage`**: **REQUEST_CHANGES**
  - [Warning] `upload()` が sidecar を書かないため、`bughunt.storage.get` 経由DLで `contentTypeOf()` と整合しません（render成果物もGETコントローラで配信する設計）。  
    **修正案**: `FakeObjectStore` に `storeLocalFile()` 等を追加し、render upload でも meta を必ず生成（`video/mp4` など呼出側指定）。
  - [Suggestion] 親クラスの `disk()` 抽象化リファクタ案は有効。重複実装より drift リスクが下がります。

- **施策6 signed route controllers**: **APPROVE**
  - [Warning] `response()->file()` の `Content-Type` は sidecar 依存なので、上記未完了状態判定を先に行わないと 500 化します。  
    **修正案**: `head()` で存在・完了・型取得を一括判定し 404/200 を分岐。
  - [Suggestion] `key` は許可プレフィックス（`projects/`）を最小限検証しておくと、署名漏洩時の横断読取面積を縮小できます（署名前提でも多層防御）。

- **施策7 provider配線**: **APPROVE**
  - [Suggestion] queue worker 反映は「worker起動時環境でbind決定」が本質なので、`queue:listen` 起動前に `TESTING_FAKE_STORAGE=true` が入っていることを運用手順へ明記すると事故を減らせます。

- **施策8 ProductionEnvGuard固定**: **APPROVE**
  - 指摘なし（fail-fast不変条件として適切）。

- **施策9 テスト計画**: **REQUEST_CHANGES**
  - [Critical] 「RefreshDatabase グローバル適用」が計画文にあるだけで、今回の新規テスト群に `Storage::fake` と併用時の初期化順（DB/FS）や env 注入順が未定義。  
    **修正案**: `tests/Pest.php` での共通セットアップ手順（`config(['testing.fake_storage'=>true])` / app再起動要否）を具体化し、provider再解決が必要なケースを明文化。
  - [Warning] drift 契約テストは「public method override 検知」だけだと、親に新しい protected hook が増えた場合の実S3到達を取りこぼします。  
    **修正案**: 反射テストに加え、fakeモードで実際に主要ユースケース（take presign/head, render upload/download/url）を実行し「AWS region未設定でも成功」を契約として固定。

**重点論点への結論**

- サブクラス+bind方式と既存mock共存: **概ね問題なし**（`instance`最終勝ち回帰テスト必須）。
- signed route emulation安全性: **設計は良い**（signed + gate再検証 + checksum三者一致）。fail-secure も成立。
- `getContent(asResource: true)`～atomic保存: **方向性は妥当**だが、`Storage::fake` とのパス整合を直す必要あり。
- PHPStan L10: **概ね適合**。`fwrite` 戻り値検証と型注釈強化で完成度が上がる。
- render workerへのbind: **成立見込み**（起動環境依存の明文化推奨）。
- drift検知妥当性: **単独では弱い**。E2E契約を併用すべき。

**全体判定**

- **CHANGES_REQUESTED**

主な理由は 2 点です。  
1) `FakeObjectStore` のパス戦略が `Storage::fake('s3_fake')` と衝突し、テスト/実装の整合性を壊すリスク。  
2) render upload の sidecar 未生成により GET 経路との契約が崩れるリスク。  

この2点を直せば、設計全体は North Star・禁止事項・セキュリティ不変条件に整合する完成度です。