## Round 1 対応マトリクス

`devnotes/20260822-0146-ocr-flag-removal/codex-history/impl-review-decisions-round-1.md`
の内容 (以下に貼付):

### [Critical] 全体 — 修正後コードの検証が未完了
- 判断: 対応する
- 対応内容: 以下の検証コマンドをすべて実行し green を確認した。
  - `composer test`: 6422 tests / 6420 passed / 0 failed / 2 skipped / 5 risky
  - `pnpm test`: 173 files / 2366 tests passed
  - `pnpm test:packages`: 10 files / 106 tests passed
  - `composer phpstan`: level 10, No errors
  - `vendor/bin/pint --test`: passed
  - `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` /
    `pnpm build:packages`: すべて clean
  - 下記 Suggestion 2 件を反映した後、`composer test` フルスイートと `pnpm test` を再実行し、
    全 green であることを再確認済み (詳細は本メッセージ末尾)。

### [Suggestion] `imageSourceDocumentsEnabled` の `missing()` 検査
- 判断: 対応する
- 対応内容: 「公開面の一貫性」テストの show/create 両方の `assertInertia` チェーンへ
  `->missing('imageSourceDocumentsEnabled')` を追加した。

### [Suggestion] 「jpg/png アップロードが成功する」のテスト名と実体の不一致
- 判断: 対応する
- 対応内容: テストを 2 つの `VideoManual` に分け、jpg・png それぞれの HTTP 経由
  アップロード成功と `mime` を明示的に検証するよう拡張した。

## 追加差分 (Round 1 以降の変更のみ)

Round 1 の diff に対する追加差分は以下 (`tests/Feature/Projects/SourceDocumentUploadOcrTest.php` のみ)。

```diff
diff --git a/tests/Feature/Projects/SourceDocumentUploadOcrTest.php b/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
index f5b787b4..93165579 100644
--- a/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
+++ b/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
@@ -11,13 +11,13 @@
 use App\Support\Manual\AcceptedSourceDocumentTypes;
 use Illuminate\Http\UploadedFile;
 use Illuminate\Support\Facades\Storage;
-use Illuminate\Validation\ValidationException;
 use Inertia\Testing\AssertableInertia as Assert;
 use Tests\Support\Manual\MinimalImageFixture;
 
 /*
- * 画像・スキャン SOP の OCR 対応 (施策 1/11):
- * - フラグ true 時のみ jpg/png アップロードが成功する (先に赤くする: フラグ既定 false で 422)
+ * 画像・スキャン SOP の OCR 対応 (常時有効。旧 `manual.ocr_analysis_enabled` フラグは
+ * オーナー決定により撤去済み):
+ * - jpg/png アップロードが成功する
  * - HEIC は引き続き拒否され、文言に「JPEG / PNG で保存し直す」と出る
  * - 画像専用の容量上限は sniff MIME だけで判定される (偽装で迂回できない)
  * - webp/gif は引き続き拒否される (回帰)
@@ -47,36 +47,31 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     return UploadedFile::fake()->createWithContent($name, MinimalImageFixture::png(10, 10));
 }
 
-test('先に赤くする: フラグ既定 (false) では jpg アップロードが 422 になる', function (): void {
+test('jpg/png アップロードが成功する', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', false);
-    [, $owner, $project, $manual] = ocrUploadContext();
-
-    $this->actingAs($owner)->postJson(
-        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
-        ['document' => fakeJpegFile()],
-    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
-
-    expect(SourceDocument::query()->count())->toBe(0);
-});
+    [, $owner, $project] = ocrUploadContext();
 
-test('フラグ true では jpg/png アップロードが成功する', function (): void {
-    Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
-    [, $owner, $project, $manual] = ocrUploadContext();
+    // jpg/png それぞれ別マニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
+    $jpgManual = VideoManual::factory()->forProject($project)->create();
+    $pngManual = VideoManual::factory()->forProject($project)->create();
 
     $this->actingAs($owner)->post(
-        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
+        "/projects/{$project->id}/manuals/{$jpgManual->id}/source-documents",
         ['document' => fakeJpegFile()],
     )->assertRedirect();
+    $jpgDocument = $jpgManual->sourceDocuments()->firstOrFail();
+    expect($jpgDocument->mime)->toBe('image/jpeg');
 
-    $document = SourceDocument::query()->firstOrFail();
-    expect($document->mime)->toBe('image/jpeg');
+    $this->actingAs($owner)->post(
+        "/projects/{$project->id}/manuals/{$pngManual->id}/source-documents",
+        ['document' => fakePngFile()],
+    )->assertRedirect();
+    $pngDocument = $pngManual->sourceDocuments()->firstOrFail();
+    expect($pngDocument->mime)->toBe('image/png');
 });
 
-test('フラグ true でも HEIC は引き続き 422 で拒否される', function (): void {
+test('HEIC は引き続き 422 で拒否される', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     // finfo は HEIC を image/heic (または application/octet-stream) と判定する。
@@ -93,20 +88,12 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
         .'PDF・Excel・テキスト形式、または JPEG・PNG の画像でアップロードし直してください。']]);
 });
 
-test('新規マニュアル作成時 (StoreVideoManualRequest) もフラグに応じて jpg 受理可否が変わる', function (): void {
+test('新規マニュアル作成時 (StoreVideoManualRequest) でも jpg アップロードが成功する', function (): void {
     Storage::fake();
     [, $owner, $project] = ocrUploadContext();
 
-    config()->set('manual.ocr_analysis_enabled', false);
-    $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
-        'title' => 'フラグ無効テスト',
-        'category' => null,
-        'document' => fakeJpegFile('create-false.jpg'),
-    ])->assertUnprocessable()->assertJsonValidationErrors(['document']);
-
-    config()->set('manual.ocr_analysis_enabled', true);
     $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
-        'title' => 'フラグ有効テスト',
+        'title' => '画像アップロードテスト',
         'category' => null,
         'document' => fakeJpegFile('create-true.jpg'),
     ])->assertRedirect();
@@ -114,9 +101,8 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     expect(SourceDocument::query()->where('mime', 'image/jpeg')->count())->toBe(1);
 });
 
-test('webp/gif はフラグ true でも引き続き拒否される (回帰)', function (): void {
+test('webp/gif は引き続き拒否される (回帰)', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     foreach (['image/webp' => 'sop.webp', 'image/gif' => 'sop.gif'] as $mime => $name) {
@@ -131,7 +117,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
 
 test('画像の容量上限超過 (source_document_image_max_bytes 基準) は 422', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     config()->set('manual.source_document_image_max_bytes', 1024); // 1KB に絞る
     [, $owner, $project, $manual] = ocrUploadContext();
 
@@ -145,7 +130,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
 
 test('容量上限の判定材料は sniff MIME である (偽装で迂回できない)', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
     config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
     [, $owner, $project, $manual] = ocrUploadContext();
@@ -166,7 +150,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     // 'image/jpeg' を返し、getClientMimeType()/getClientOriginalExtension() は 'application/pdf'
     // 側を返す (この乖離を実ファイルで固定する)。
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
     config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
     [, $owner, $project, $manual] = ocrUploadContext();
@@ -187,125 +170,83 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     @unlink($tmpPath);
 });
 
-test('公開面の一貫性: FormRequest / Service / Inertia Props がフラグに応じて同じ集合を表す', function (): void {
+test('公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す', function (): void {
     Storage::fake();
     [, $owner, $project] = ocrUploadContext();
 
-    foreach ([false, true] as $flag) {
-        config()->set('manual.ocr_analysis_enabled', $flag);
-        // 各分岐を独立したマニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
-        $httpManual = VideoManual::factory()->forProject($project)->create();
-        $serviceManual = VideoManual::factory()->forProject($project)->create();
-
-        // FormRequest: jpg 受理可否
-        $response = $this->actingAs($owner)->postJson(
-            "/projects/{$project->id}/manuals/{$httpManual->id}/source-documents",
-            ['document' => fakeJpegFile("sop-{$flag}.jpg")],
-        );
-        if ($flag) {
-            $response->assertRedirect();
-        } else {
-            $response->assertUnprocessable();
-        }
-
-        // Service: allowedMimeTypes に image/jpeg が含まれるか (appendDocument の成否で確認)
-        if ($flag) {
-            $doc = app(SourceDocumentService::class)->appendDocument(
-                $serviceManual,
-                fakeJpegFile("service-{$flag}.jpg"),
-            );
-            expect($doc->mime)->toBe('image/jpeg');
-        } else {
-            expect(fn () => app(SourceDocumentService::class)->appendDocument(
-                $serviceManual,
-                fakeJpegFile("service-{$flag}.jpg"),
-            ))->toThrow(ValidationException::class);
-        }
-
-        // Inertia Props (詳細画面): sourceDocumentAccept / imageSourceDocumentsEnabled
-        $showResponse = $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]));
-        $showResponse->assertInertia(fn (Assert $page) => $page
-            ->where('imageSourceDocumentsEnabled', $flag)
-            ->where('sourceDocumentAccept', $flag
-                ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
-                : '.pdf,.xlsx,.xls,.txt'));
-
-        // Inertia Props (作成画面): 同じ単一の情報源を経由する 3 件
-        $createResponse = $this->actingAs($owner)->get(route('projects.manuals.create', [$project]));
-        $createResponse->assertInertia(fn (Assert $page) => $page
-            ->where('imageSourceDocumentsEnabled', $flag)
-            ->where('sourceDocumentAccept', $flag
-                ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
-                : '.pdf,.xlsx,.xls,.txt')
-            ->where('sourceDocumentFormatsLabel', $flag
-                ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
-                : 'PDF・Excel・テキスト形式'));
-
-        // 面をまたいだ同値性。リテラル pin は「両面とも同じ間違い」を検出できるが、
-        // 「面ごとに違う」ケースはこの比較が担う。
-        // **比較対象は両面に存在する 2 件だけ**である: sourceDocumentFormatsLabel は
-        // 詳細画面に形式ラベルを表示する UI が無く props を配っていないため含めない。
-        $sharedKeys = ['sourceDocumentAccept', 'imageSourceDocumentsEnabled'];
-        $showProps = Assert::fromTestResponse($showResponse)->toArray();
-        $createProps = Assert::fromTestResponse($createResponse)->toArray();
-        foreach ($sharedKeys as $key) {
-            expect($createProps[$key] ?? null)->toBe(
-                $showProps[$key] ?? null,
-                "作成画面と詳細画面で props {$key} が食い違っている (単一の情報源を経由していない)",
-            );
-        }
-    }
+    // 各分岐を独立したマニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
+    $httpManual = VideoManual::factory()->forProject($project)->create();
+    $serviceManual = VideoManual::factory()->forProject($project)->create();
+
+    // FormRequest 境界: jpg が StoreSourceDocumentRequest の mimes: ルールを通過する
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$httpManual->id}/source-documents",
+        ['document' => fakeJpegFile('sop.jpg')],
+    )->assertRedirect();
+
+    // Service 境界: appendDocument() が例外を投げずに image/jpeg の SourceDocument を返す
+    $doc = app(SourceDocumentService::class)->appendDocument(
+        $serviceManual,
+        fakeJpegFile('service.jpg'),
+    );
+    expect($doc->mime)->toBe('image/jpeg');
+
+    // Inertia Props 境界: create()/show() 両方の sourceDocumentAccept が画像込み固定値で一致する。
+    // 旧 imageSourceDocumentsEnabled prop が再追加されないことも固定する (完全撤去の回帰検出)
+    $showResponse = $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]));
+    $showResponse->assertInertia(fn (Assert $page) => $page
+        ->where('sourceDocumentAccept', '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png')
+        ->missing('imageSourceDocumentsEnabled'));
+
+    $createResponse = $this->actingAs($owner)->get(route('projects.manuals.create', [$project]));
+    $createResponse->assertInertia(fn (Assert $page) => $page
+        ->where('sourceDocumentAccept', '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png')
+        ->where('sourceDocumentFormatsLabel', 'PDF・Excel・テキスト形式、または JPEG・PNG の画像')
+        ->missing('imageSourceDocumentsEnabled'));
+
+    // 面をまたいだ同値性 (作成画面と詳細画面が同じ情報源を経由していること)
+    $showProps = Assert::fromTestResponse($showResponse)->toArray();
+    $createProps = Assert::fromTestResponse($createResponse)->toArray();
+    expect($createProps['sourceDocumentAccept'] ?? null)->toBe(
+        $showProps['sourceDocumentAccept'] ?? null,
+        '作成画面と詳細画面で props sourceDocumentAccept が食い違っている (単一の情報源を経由していない)',
+    );
 });
 
 /*
  * StoreVideoManualRequest (作成と同時のアップロード経路) の 422 出力契約。
  *
  * **このテストが保証する範囲 (誇張しない)**: 固定できるのは両エンドポイントの 422 出力契約
- * である。「formatsLabel() を実際に呼んでいること」は保証しない — 置換前の三項演算子を
- * 残しても両フラグで同じ文言を返すため本テストは緑になる。中央メソッドへの構造的な結線は
+ * である。「formatsLabel() を実際に呼んでいること」は保証しない — 置換前の文字列を
+ * 残しても同じ文言を返すため本テストは緑になる。中央メソッドへの構造的な結線は
  * コードレビューで確認する。逆に **文面が経路ごとにずれたら** 本テストが検出する。
  */
-test('作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す (両フラグ)', function (): void {
+test('作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す', function (): void {
     Storage::fake();
     [, $owner, $project, $manual] = ocrUploadContext();
+    $makeFile = fn (): UploadedFile => UploadedFile::fake()->create('rejected.heic', 10, 'image/heic');
+    $expected = '対応していないファイル形式です。'
+        .AcceptedSourceDocumentTypes::formatsLabel()
+        .'でアップロードし直してください。';
 
-    $cases = [
-        // フラグ false: jpeg は受理外
-        [false, fn (): UploadedFile => fakeJpegFile('rejected.jpg')],
-        // フラグ true: heic は受理外 (画像を受理してもなお外)
-        [true, fn (): UploadedFile => UploadedFile::fake()->create('rejected.heic', 10, 'image/heic')],
-    ];
-
-    foreach ($cases as [$flag, $makeFile]) {
-        config()->set('manual.ocr_analysis_enabled', $flag);
-
-        // 期待文はリテラルを書かず中央ラベルから組み立てる (文面そのものの pin は Unit テスト側)
-        $expected = '対応していないファイル形式です。'
-            .AcceptedSourceDocumentTypes::formatsLabel()
-            .'でアップロードし直してください。';
-
-        // 作成と同時 (StoreVideoManualRequest): title は有効値を渡し document.mimes だけを発火させる
-        $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
-            'title' => '422 文言の経路差テスト',
-            'category' => null,
-            'document' => $makeFile(),
-        ])->assertUnprocessable()
-            ->assertJsonValidationErrors(['document'])
-            ->assertJsonFragment(['document' => [$expected]]);
-
-        // 後付け (StoreSourceDocumentRequest): 同じ文面であること
-        $this->actingAs($owner)->postJson(
-            "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
-            ['document' => $makeFile()],
-        )->assertUnprocessable()
-            ->assertJsonValidationErrors(['document'])
-            ->assertJsonFragment(['document' => [$expected]]);
-    }
+    $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
+        'title' => '422 文言の経路差テスト',
+        'category' => null,
+        'document' => $makeFile(),
+    ])->assertUnprocessable()
+        ->assertJsonValidationErrors(['document'])
+        ->assertJsonFragment(['document' => [$expected]]);
+
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
+        ['document' => $makeFile()],
+    )->assertUnprocessable()
+        ->assertJsonValidationErrors(['document'])
+        ->assertJsonFragment(['document' => [$expected]]);
 });
 
 test('画像の手順書は 1 枚まで (2 枚目は明示的に拒否される)', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     $this->actingAs($owner)->post(
@@ -323,7 +264,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
 
 test('非画像 (PDF) の 2 枚目は画像制約の対象外で受理される', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     $this->actingAs($owner)->post(

```

(上記は Round 1 プロンプト時点との差分ではなく、HEAD (未コミット) との全差分。Round 1 で送付済みの箇所も含むため、Suggestion 対応箇所 (`missing('imageSourceDocumentsEnabled')` の追加、'jpg/png アップロードが成功する' テストの拡張) に注目してレビューしてほしい。)

## Suggestion 反映後の再検証結果

- `composer test`: 6422 tests / 6420 passed / 0 failed / 2 skipped / 5 risky (green)
- `composer phpstan`: level 10, No errors
- `vendor/bin/pint --test`: passed
- `pnpm test` (フロントフルスイート): 173 files / 2366 tests passed (green)

上記 2 件の Suggestion 対応 (missing() 検査の追加、jpg/png 両方の明示検証) を反映したうえで、
CIレビューの観点から再度判定してほしい。
