# 実装レビュー Round 2

Round 1 の指摘への対応を報告する。**対応マトリクスは以下のとおり**。

# 対応マトリクス: impl-review Round 1

## [Critical] `docs/architecture.md` (S11) が差分に無い
- 判断: **反論する** (指摘は事実誤認。ただし原因は Claude 側の差分抽出範囲)
- 根拠: S11 は実装済みである。Round 1 に渡した `git diff` を
  `app/ resources/ tests/ routes/ database/ config/` に限定していたため、`docs/` が
  差分に現れなかった。`docs/architecture.md` の §撮影 PWA には
  「サムネイル生成 (media queue。T183)」「サムネイルは容量 Quota に計上する (事後計上)」
  「サムネイルについて保証しないもの」の 3 項目を追記済みで、worker 契約
  (`queue:work database-media --timeout=240`) と時間予算の連鎖
  (ffmpeg 60 < job 180 < worker 240 < retry_after 300) も明記している。
- 対応内容: Round 2 で `docs/` と `.claude/` を含む差分を渡し、実物を確認してもらう。

## [Critical] bug-hunt 注釈 (S6) と生成物が差分に無い
- 判断: **反論する** (同上。差分抽出範囲の問題)
- 根拠: `.claude/skills/app-bug-hunt/inventory/annotations.toml` へ
  `[routes."capture.takes.thumbnail"]` (kind=画面 / story=S3 / kubun=通常) を追記し、
  `python3 scripts/bug-hunt-inventory.py generate` で `screens.md` / `operations.md` を
  再生成済み (手書きしていない)。`scripts/bug-hunt-inventory-check.sh` は exit 0
  (画面 69 件 / 操作 79 件で一致)。
- 対応内容: Round 2 の差分に `.claude/skills/app-bug-hunt/` を含める。

## [Warning] `TakeObjectStorageTest` の ContentType 検証が保証範囲を誇張している
- 判断: **対応する**
- 根拠: 指摘のとおり `Storage::fake('s3')` はローカル disk で、`mimeType()` は
  **拡張子から導出**される。`.jpg` を書けば option を渡さなくても `image/jpeg` になるため、
  この assert には**負のコントロールが無い** (実装から option を消しても緑のまま)。
  「ContentType 付きで書かれる」というテスト名は嘘になる。
- 対応内容: テスト名を「往復が同一バイト列になる」へ変更し、`mimeType()` の assert を削除。
  代わりに**何をどこで保証しているか**をコメントで明示した
  (ContentType の実記録は fake adapter の sidecar を見る `FakeStorageRouteTest` が担う /
  実 S3 の応答ヘッダは本タスクでは保証しない)。

## [Warning] `CaptureShow.test.ts` の期待値ブロックのインデント崩れ
- 判断: **対応する**
- 根拠: 一括置換でネストの深い呼び出し (`vi.waitFor` の中) だけインデントが浅くなっていた。
  `pnpm lint` の走査根は `resources/js` のみなので機械では落ちないが、読みにくい。
- 対応内容: 該当 2 箇所のうち崩れていた 1 箇所を修正した。


---

## 1. Critical 2 件について (差分抽出範囲の問題であり、実装は存在する)

Round 1 の diff は `app/ resources/ tests/ routes/ database/ config/` に限定していたため、
`docs/` と `.claude/` が現れていなかった。実物を以下に添付する。

### 追加差分 A: `docs/architecture.md` (S11) と bug-hunt 目録 (S6)

```diff
diff --git a/.claude/skills/app-bug-hunt/inventory/annotations.toml b/.claude/skills/app-bug-hunt/inventory/annotations.toml
index 0fa000b..bdde95e 100644
--- a/.claude/skills/app-bug-hunt/inventory/annotations.toml
+++ b/.claude/skills/app-bug-hunt/inventory/annotations.toml
@@ -96,6 +96,11 @@ kubun = "通常"
 story = "S3"
 kubun = "通常"
 
+[routes."capture.takes.thumbnail"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
 [routes."capture.takes.update"]
 story = "S3"
 kubun = "通常"
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index 38e6758..142554e 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -6,7 +6,7 @@ # 画面インベントリ (screens.md) — AI-CUE
 > 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
 > ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
 
-bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。全 68 件 (うち対象外 13 件)。
+bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。全 69 件 (うち対象外 13 件)。
 
 ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 
@@ -20,6 +20,7 @@ ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 | app/projects/{project}/manuals | capture.manuals.index | 画面 | 撮影するマニュアルを選ぶ | S3 | 通常 |
 | app/projects/{project}/manuals/{manual} | capture.manuals.show | 画面 | - | S3 | 通常 |
 | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | 画面 | - | S3 | 通常 |
+| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail | capture.takes.thumbnail | 画面 | - | S3 | 通常 |
 | contact | contact | 画面 | お問い合わせ | S1 | 通常 |
 | contact/thanks | contact.thanks | 画面 | お問い合わせ完了 | S1 | 通常 |
 | dashboard | dashboard | 画面 | ダッシュボード | S1 | 通常 |
diff --git a/docs/architecture.md b/docs/architecture.md
index d32194e..538be90 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1233,8 +1233,34 @@ ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
   `Capture/TakeRegistrationService` がチケット検証 + 予約 claim (pending→verifying の原子的
   UPDATE) + HeadObject 三点照合 (size/content_type/checksum) + `(cut_id, client_take_id)` 冪等
   登録を行う (確定は verifying→completed の CAS = sweeper と競合しない)
-- **使用量の真実源は集計クエリ** (`Capture/StorageUsageService`。bytes_used = takes.size_bytes の
-  org 合計 / bytes_pending = pending 未失効 + verifying 全件。カウンタキャッシュは持たない)
+- **使用量の真実源は集計クエリ** (`Capture/StorageUsageService`。bytes_used = takes.size_bytes と
+  takes.thumbnail_size_bytes の org 合計 / bytes_pending = pending 未失効 + verifying 全件。
+  カウンタキャッシュは持たない)
+- **サムネイル生成 (media queue。T183)**: テイク登録の確定 tx が
+  `Jobs/Capture/GenerateTakeThumbnailJob` を投入し (同一 tx 内 = ドメイン固有規約 11)、
+  `Services/Capture/TakeThumbnailPipeline` が S3 GET → ffmpeg 1 フレーム抽出
+  (`Capture/TakeThumbnailExtractor` の抽象。v1 実装は `FfmpegTakeThumbnailExtractor`) →
+  **S3 PUT の直前に所有権再検証 (preflight)** → 条件付き UPDATE
+  (`where status=ready and thumbnail_path is null`) で `takes.thumbnail_path` /
+  `takes.thumbnail_size_bytes` を確定する。S3 キーは take の主キーから決定的に組むため、
+  重複配送は同じキーへ同じ意味の PUT に収束する (**0 行更新でもオブジェクトを削除しない** —
+  消すと勝者の実体を壊す)。worker は削除ジョブと同じ `queue:work database-media --timeout=240`
+  を共用する。時間予算は ffmpeg 60 < job 180 < worker 240 < retry_after 300。
+  配信は `GET .../takes/{take}/thumbnail` (302 → 署名 URL。ready かつ生成済みのときだけ 302 で、
+  それ以外は 404)。props の `has_thumbnail` はこの 302 条件と 1 対 1 である
+- **サムネイルは容量 Quota に計上する (事後計上)**: `takes.thumbnail_size_bytes` を
+  `StorageUsageService::bytesUsed()` が加算する。**予約 (bytes_pending) は経ない**ため、
+  生成が上限を跨ぐことはありうる (上限の強制点は presigned URL 発行時のまま。
+  超過は QuotaStatusDto の既存表示が受ける)。`takes.size_bytes` の意味 (三点照合の確定値) は不変
+- **サムネイルについて保証しないもの**: 生成の成功 (失敗しても take は `ready` のままで、
+  UI はプレースホルダへ degrade する) / 過去分の一括バックフィル (行わない) /
+  孤児オブジェクトと work dir 残骸の自動回収 (行わない) / 重複配送時に DB の記録バイト数と
+  S3 の実体が完全一致すること / 撮影画面での反映が有界であること
+  (**最後に監視集合へ追加されたテイクを起点に最大 4 回・~29 秒**の再取得。
+  既に監視中の ID の再追加では予算は戻らず、キュー再開で複数件を追加した場合は最後の 1 件が起点になる。
+  撮影を続ける限り予算は更新され、撮影を止めれば必ず停止する) / 実 S3 の応答ヘッダに
+  `Content-Type: image/jpeg` が載ること (`writeStream` の option 名の読解までが根拠で、
+  テストが固定できるのは fake adapter の sidecar までである)
 - **media queue**: S3 オブジェクト削除 (`Jobs/Capture/DeleteTakeObjectsJob`) は専用 connection
   **`database-media`** (queue=media、retry_after=300) で流れる。**本番/ステージングの worker
   プロセス定義・デプロイ手順・監視対象に `php artisan queue:work database-media --timeout=240`

```

補足: `screens.md` / `operations.md` は生成物のため**手で書いていない**
(`python3 scripts/bug-hunt-inventory.py generate` で再生成)。
差分が大きくノイズになるため本文には貼らないが、
ドリフト検査 `scripts/bug-hunt-inventory-check.sh` は **exit 0**
(画面 69 件 / 操作 79 件で一致) である。生成物の手編集は同スクリプトの段 3 (byte 比較) が
検出する仕組みなので、手編集していないことは機械で担保されている。

## 2. Warning 2 件の修正差分

```diff
diff --git a/tests/Feature/Capture/TakeObjectStorageTest.php b/tests/Feature/Capture/TakeObjectStorageTest.php
index b5b549a..5678024 100644
--- a/tests/Feature/Capture/TakeObjectStorageTest.php
+++ b/tests/Feature/Capture/TakeObjectStorageTest.php
@@ -194,6 +194,58 @@ protected function client(): S3Client
     expect($url)->toContain('X-Amz-Signature=');
 });
 
+test('temporaryThumbnailUrl は config TTL の署名 GET URL を返す (playback と同じ TTL)', function (): void {
+    fakeS3DiskConfig();
+
+    $key = 'projects/1/manuals/2/cuts/3/takes/thumbnails/9.jpg';
+    $url = app(TakeObjectStorage::class)->temporaryThumbnailUrl($key);
+
+    expect($url)->toContain($key);
+    expect($url)->toContain('X-Amz-Signature=');
+    // TTL は動画再生と同じ config キーから引く (2 つの TTL を持たない)
+    expect($url)->toContain('X-Amz-Expires='.(config()->integer('capture.playback_url_ttl_minutes') * 60));
+});
+
+test('upload → downloadToLocal の往復が同一バイト列になる', function (): void {
+    Storage::fake('s3');
+    $storage = app(TakeObjectStorage::class);
+
+    $local = tempnam(sys_get_temp_dir(), 'thumb');
+    expect($local)->toBeString();
+    assert(is_string($local));
+    file_put_contents($local, 'jpeg-bytes-of-a-thumbnail');
+
+    $key = 'projects/1/manuals/2/cuts/3/takes/thumbnails/9.jpg';
+    $storage->upload($local, $key, 'image/jpeg');
+    expect(Storage::disk('s3')->exists($key))->toBeTrue();
+
+    $roundTrip = tempnam(sys_get_temp_dir(), 'thumb-back');
+    expect($roundTrip)->toBeString();
+    assert(is_string($roundTrip));
+    $storage->downloadToLocal($key, $roundTrip);
+
+    expect(file_get_contents($roundTrip))->toBe('jpeg-bytes-of-a-thumbnail');
+    // ★ **ContentType はここでは検査しない**。`Storage::fake('s3')` はローカル disk であり
+    //   `writeStream()` の option を metadata として保持しない (`mimeType()` は拡張子から
+    //   導出されるので、option を渡さなくても image/jpeg になってしまう = 負のコントロールが無い)。
+    //   ContentType が実際に記録されることの検査は fake adapter の sidecar を見る
+    //   FakeStorageRouteTest 側が担う。実 S3 の応答ヘッダに載ることは本タスクでは保証しない。
+
+    unlink($local);
+    unlink($roundTrip);
+});
+
+test('downloadToLocal は存在しないキーで例外を投げる (無音で 0 バイトを作らない)', function (): void {
+    Storage::fake('s3');
+    $target = tempnam(sys_get_temp_dir(), 'thumb-missing');
+    assert(is_string($target));
+
+    expect(fn () => app(TakeObjectStorage::class)->downloadToLocal('missing/key.mp4', $target))
+        ->toThrow(RuntimeException::class);
+
+    unlink($target);
+});
+
 test('config capture の値が typed accessor で読める', function (): void {
     expect(config()->integer('capture.upload_ticket_ttl_minutes'))->toBe(30);
     expect(config()->integer('capture.max_take_bytes'))->toBe(500 * 1024 * 1024);
@@ -201,4 +253,9 @@ protected function client(): S3Client
     expect(config()->integer('capture.playback_url_ttl_minutes'))->toBe(60);
     expect(config()->integer('capture.released_reservation_retention_days'))->toBe(30);
     expect(config()->integer('capture.stale_verifying_minutes'))->toBe(15);
+    expect(config()->integer('capture.thumbnail_seek_ms'))->toBe(1000);
+    expect(config()->integer('capture.thumbnail_max_edge'))->toBe(640);
+    expect(config()->integer('capture.thumbnail_jpeg_quality'))->toBe(5);
+    // 時間予算の連鎖: ffmpeg 60 < job timeout 180 < worker 240 < retry_after 300
+    expect(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))->toBe(60);
 });
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 89bd554..70d9b88 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -12,14 +12,14 @@ import { VIDEO_MANUAL_STATUS_LABELS, type VideoManualStatus } from "@/types/manu
  * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
  */
 
-const { routerReloadMock, enqueueMock, autoDownloadRunMock, navigateToPanelMock } = vi.hoisted(
-    () => ({
+const { routerReloadMock, enqueueMock, resumeMock, autoDownloadRunMock, navigateToPanelMock } =
+    vi.hoisted(() => ({
         routerReloadMock: vi.fn(),
         enqueueMock: vi.fn(),
+        resumeMock: vi.fn(),
         autoDownloadRunMock: vi.fn(),
         navigateToPanelMock: vi.fn(),
-    }),
-);
+    }));
 
 // 撮影パネルへのナビゲーション (F-1-03) は panel-navigation.ts が副作用ごと担い、
 // その抑止契約は panel-navigation.test.ts が固定する。ここで固定するのは
@@ -58,9 +58,7 @@ vi.mock("@/lib/capture/upload-queue", async (importOriginal) => ({
     UploadQueue: class {
         quotaMessage: string | null = null;
         enqueue = enqueueMock;
-        async resume(): Promise<unknown[]> {
-            return [];
-        }
+        resume = resumeMock;
     },
 }));
 
@@ -111,6 +109,7 @@ function makeAdoptedManual(): CaptureManualDetail {
         captured_at: "2026-07-11T00:00:00Z",
         sort_order: 0,
         downloaded: false,
+        has_thumbnail: false,
         playback_url: "https://s3.example.test/take-900.mp4?sig=1",
         download_ack_token: "ack-900",
     };
@@ -145,7 +144,14 @@ const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
 
 beforeEach(() => {
     routerReloadMock.mockReset();
+    // reload は Inertia の onFinish で解決する契約。既定では即座に完了させる
+    // (single-flight の in-flight が張り付いたままにならないようにする)
+    routerReloadMock.mockImplementation((options: { onFinish?: () => void }) => {
+        options.onFinish?.();
+    });
     enqueueMock.mockReset();
+    resumeMock.mockReset();
+    resumeMock.mockResolvedValue([]);
     enqueueMock.mockImplementation((item: { clientTakeId: string }) =>
         Promise.resolve({ status: "uploaded", clientTakeId: item.clientTakeId }),
     );
@@ -222,7 +228,10 @@ describe("Capture/Show カメラフォールバック", () => {
         expect(arg.blob).toBe(file);
         expect(arg.contentType).toBe("video/mp4");
         expect(arg.durationMs).toBeNull();
-        expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
+        expect(routerReloadMock).toHaveBeenCalledWith({
+            only: ["manual"],
+            onFinish: expect.any(Function),
+        });
     });
 
     it("(e) permission_denied 以外 (device_missing) は汎用の切替 notice を出す", async () => {
@@ -281,7 +290,10 @@ describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
         });
         expect(autoDownloadRunMock).toHaveBeenCalledWith(adoptedProps.manual);
         await vi.waitFor(() => {
-            expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
+            expect(routerReloadMock).toHaveBeenCalledWith({
+                only: ["manual"],
+                onFinish: expect.any(Function),
+            });
         });
     });
 
@@ -471,3 +483,47 @@ describe("Capture/Show マニュアル詳細への復路 (T155)", () => {
         },
     );
 });
+
+/*
+ * サムネイル反映の**ページ配線** (T183 / S10)。
+ *
+ * 有界性・停止条件そのものは thumbnail-refresh.test.ts が固定する。
+ * ここで見るのは「Show がどの outcome を watch へ渡し、reload を何回通したか」だけである
+ * (helper だけでは、将来 Show がキュー再開経路の watch を落としても緑のままになる)。
+ */
+describe("Capture/Show サムネイル反映の配線 (T183)", () => {
+    it("キュー再開で uploaded が複数でも reload は 1 回だけ通る (single-flight)", async () => {
+        stubCameraSupported(false);
+        resumeMock.mockResolvedValue([
+            { status: "uploaded", clientTakeId: "q1" },
+            { status: "uploaded", clientTakeId: "q2" },
+            { status: "queued", clientTakeId: "q3", reason: "offline" },
+        ]);
+
+        render(CaptureShow, { props: baseProps });
+        await fireEvent(window, new Event("online"));
+
+        await vi.waitFor(() => {
+            expect(resumeMock).toHaveBeenCalled();
+        });
+        await vi.waitFor(() => {
+            expect(routerReloadMock).toHaveBeenCalledTimes(1);
+        });
+    });
+
+    it("uploaded が 1 件も無いキュー再開では reload しない", async () => {
+        stubCameraSupported(false);
+        resumeMock.mockResolvedValue([
+            { status: "queued", clientTakeId: "q1", reason: "offline" },
+            { status: "quota_exceeded", clientTakeId: "q2", message: "上限です" },
+        ]);
+
+        render(CaptureShow, { props: baseProps });
+        await fireEvent(window, new Event("online"));
+
+        await vi.waitFor(() => {
+            expect(resumeMock).toHaveBeenCalled();
+        });
+        expect(routerReloadMock).not.toHaveBeenCalled();
+    });
+});

```

## 3. 修正後の検証結果

- `vendor/bin/pest tests/Feature/Capture/TakeObjectStorageTest.php`: 11 passed / 45 assertions
- `npx vitest run tests/js/pages/CaptureShow.test.ts`: 22 passed
- 全レーン (composer test / composer phpstan / pint / pnpm lint / typecheck / test / build /
  packages 3 種) は Round 1 時点で green であり、今回の修正はテストの assert 削除と
  インデント修正のみのため、後述の最終確認で再実行する

---

上記を踏まえて再レビューし、**全体判定 (APPROVED / CHANGES_REQUESTED)** を出してほしい。
残る指摘があれば [Critical] / [Warning] / [Suggestion] の分類で、ファイル名と根拠を添えて示すこと。
