# 詳細設計: OCR 機能フラグ (`manual.ocr_analysis_enabled`) の完全撤去 (常時有効化)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 (核)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び (`PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` (`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 禁止)
- **DTO + JsonResource** パターン (本設計は既存 DTO 構造を変更しない)
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260822-0146-ocr-flag-removal/conceptual-design.md` (Phase 1、Round 3 で APPROVED)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | config からフラグ削除 | `config/manual.php` | 必須 |
| 2 | 受理形式の単一情報源を固定値化 | `app/Support/Manual/AcceptedSourceDocumentTypes.php` | 必須 |
| 3 | AnalysisPipeline の route 決定を無条件化 | `app/Services/Manual/AnalysisPipeline.php` | 必須 |
| 4 | 説明 docblock のフラグ前提を除去 (コード分岐なし) | `app/Services/Manual/SopTextExtractor.php`, `app/Rules/SourceDocumentSizeLimit.php` | 必須 |
| 5 | Inertia props / Svelte props の撤去 | `VideoManualController.php`, `SourceDocumentUpload.svelte`, `SourceDocumentUploadNotice.svelte`, `Manuals/Create.svelte`, `Manuals/Show.svelte` | 必須 |
| 6 | バックエンドテストの畳み込み | `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`, `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`, `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` | 必須 |
| 7 | フロントテストの畳み込み | `tests/js/components/features/manual/SourceDocumentUpload.test.ts`, `SourceDocumentUploadNotice.test.ts`, `tests/js/pages/ManualsCreate.test.ts` | 必須 |
| 8 | rollout チェックリストの記録追記・運用手順の書き換え | `docs/rollout-checklists.md` | 必須 |
| 9 | architecture 記述の更新 | `docs/architecture.md` | 必須 |
| 10 | 残存確認 (実装完了条件) | 全体 (grep) | 必須 |

すべて **standalone 1 PR** で完結させる (施策間の依存が強く、フラグを部分的に残すと
「一部だけ常時有効・一部だけフラグ依存」という中間状態が生まれ、思考原則 3 に反する)。

---

## 施策 1: config からフラグ削除

### 変更箇所
- ファイル: `config/manual.php` (L56-61)

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 6・7 が参照する `config()->set('manual.ocr_analysis_enabled', ...)` の
  全呼び出しが対象 (施策 6・7 で扱う)
- env: `.env` / `.env.example` / `.env.local` / `.env.testing` に
  `MANUAL_OCR_ANALYSIS_ENABLED` は存在しない (確認済み。変更不要)

### 現行コード
```php
    // ── 画像・スキャン SOP の OCR 対応 ──────────────────────────────
    // 画像受理 + PDF の OCR フォールバックの単一 gate。既定 false = 施策 1〜9 のコードは
    // デプロイされていても無害 (画像は 422 のまま、PDF 品質ゲート失敗は即時失敗のまま)。
    // true にするのは法務確認・画像内 prompt injection の手動評価・責任者承認が
    // 完了した後の独立した運用操作とする (docs/rollout-checklists.md)。
    'ocr_analysis_enabled' => env('MANUAL_OCR_ANALYSIS_ENABLED', false),

    // 画像専用の容量上限 (既存の source_document_max_bytes とは別枠、より小さい値)。
```

### 変更後コード
```php
    // ── 画像・スキャン SOP の OCR 対応 ──────────────────────────────
    // 画像受理 + PDF の OCR フォールバックは無条件で有効 (旧 rollout gate
    // `manual.ocr_analysis_enabled` はオーナー決定により撤去済み。
    // 経緯は docs/rollout-checklists.md 「画像・スキャン SOP の OCR 対応」節)。

    // 画像専用の容量上限 (既存の source_document_max_bytes とは別枠、より小さい値)。
```

### PHPStan 適合チェック
- [x] 配列リテラルからキーを削るだけで戻り値型に影響しない
- [x] null 安全: 対象外
- [x] DTO を返している: 対象外 (config 配列)
- [x] Generics: 対象外

### テスト計画
- [x] 再現テスト: バグ修正ではないため対象外
- [x] 既存テストの更新: 施策 6・7 (`config()->set('manual.ocr_analysis_enabled', ...)` を
  全呼び出しから削除する。**detailed-review Round 3 の Warning を受けた訂正**:
  `config()->set()` はキー不存在でも素通りし、`config()->boolean('manual.ocr_analysis_enabled')`
  の呼び出しが万一残っていても PHPStan・実行時例外のどちらでも検出されず、黙って
  `false` 相当へ倒れる (施策 1 のリスク節と同じ理由。PHPStan/実行時が検出するという
  記述は誤りだったため削除する)。したがって死んだ `config()->set()` 呼び出し・
  `config()->boolean()` 呼び出しを取りこぼしなく消したことは、施策 10 の残存確認
  (grep) で最終確認する。施策 2・3 で `config()->boolean('manual.ocr_analysis_enabled')`
  自体を削除するため、テスト側もこの呼び出しに対応する `config()->set()` を削除する)
- [x] 新規テスト: なし (削除のみ)

### リスク
- 本施策単独では、施策 2・3 (config キーを参照するコード) が config キーの削除より前に
  適用された状態は作らない (同一 PR 内で同時適用する。standalone PR 前提であれば
  追加リスクはない)。**detailed-review Round 2 の Warning を受けた訂正**: config キーの
  削除だけを単独で先行適用した場合、`config()->boolean('manual.ocr_analysis_enabled')` は
  Laravel の config が文字列キーを動的に参照する実装であるため、**PHPStan は不存在を
  検出できず**、実行時も例外にはならずに `false` 相当へ黙って倒れる (未定義キー参照が
  静的/実行時エラーになるという説明は誤りだったため訂正する)。この「部分適用時に
  OCR が無効化されたまま気付かれない」という取りこぼしを検出する手段は
  **施策 10 の残存確認 (grep) だけ**である。したがって施策 1〜5 を 1 コミット
  (または 1 PR) 内で揃えて適用し、実装完了時に施策 10 の確認を必ず行うことを
  実装時の前提とする。デプロイ時は通常の `config:cache` 再生成が必要 (既存運用の一般論であり、
  AGENTS.md の route:cache 運用要件そのものを変更するものではない)。

---

## 施策 2: 受理形式の単一情報源を固定値化

### 変更箇所
- ファイル: `app/Support/Manual/AcceptedSourceDocumentTypes.php` (全体)

### 波及変更
- TypeScript 型定義: なし (このクラスは PHP 側のみ)
- API Resource/DTO: 対象外
- テストファイル: `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` (施策 6)

### 現行コード
```php
/**
 * 受理する SourceDocument の形式の唯一の情報源 (画像・スキャン SOP の OCR 対応)。
 * config の静的な拡張子リストと `manual.ocr_analysis_enabled` フラグを合成し、
 * FormRequest / Service / フロント Props の全てがここを経由することで、
 * 画像受理の有効・無効が 1 箇所で一貫する。
 */
final class AcceptedSourceDocumentTypes
{
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const array IMAGE_MIMES = ['image/jpeg', 'image/png'];

    /** @return list<string> 拡張子 (FormRequest の mimes: ルール・フロント accept 属性用) */
    public static function extensions(): array
    {
        /** @var list<string> $base */
        $base = config()->array('manual.source_document_mimes');

        return self::imagesEnabled() ? [...$base, ...self::IMAGE_EXTENSIONS] : $base;
    }

    /** @return list<string> 内容 sniff MIME (SourceDocumentService::allowedMimeTypes 相当) */
    public static function mimes(): array
    {
        $base = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/plain',
        ];

        return self::imagesEnabled() ? [...$base, ...self::IMAGE_MIMES] : $base;
    }

    /**
     * フロント `<input accept>` 属性用の文字列 (拡張子のみ)。
     */
    public static function acceptAttribute(): string
    {
        $parts = array_map(static fn (string $ext): string => ".{$ext}", self::extensions());

        return implode(',', $parts);
    }

    /**
     * フロントの画像対応可否表示用 (accept 属性の文字列を解析して画像対応可否を
     * 判定させないための専用の真偽値)。
     */
    public static function imagesEnabled(): bool
    {
        return config()->boolean('manual.ocr_analysis_enabled');
    }

    /**
     * 受理形式の人間向けラベル (法務確認を経た文面。FormRequest の 422 文言と
     * 作成画面の help 文言が共有する)。
     *
     * **機械導出しない**: 拡張子リストから日本語の文を組み立てる形にすると
     * config を触った副作用で文面が変わりうるため、承認済みの 2 文をそのまま持つ。
     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin (基底拡張子集合・
     * 画像拡張子集合が現在値ちょうど) が検出する。
     */
    public static function formatsLabel(): string
    {
        return self::imagesEnabled()
            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
            : 'PDF・Excel・テキスト形式';
    }
}
```

### 変更後コード
```php
/**
 * 受理する SourceDocument の形式の唯一の情報源。
 * config の静的な拡張子リストに画像拡張子 (jpg/jpeg/png) を加えた固定集合を返し、
 * FormRequest / Service / フロント Props の全てがここを経由することで、
 * 受理形式が 1 箇所で一貫する。
 *
 * 画像・スキャン SOP の OCR 対応 (旧 `manual.ocr_analysis_enabled` フラグ) は
 * オーナー決定により撤去済みで、画像受理は常時有効である
 * (経緯は docs/rollout-checklists.md 「画像・スキャン SOP の OCR 対応」節)。
 */
final class AcceptedSourceDocumentTypes
{
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const array IMAGE_MIMES = ['image/jpeg', 'image/png'];

    /** @return list<string> 拡張子 (FormRequest の mimes: ルール・フロント accept 属性用) */
    public static function extensions(): array
    {
        /** @var list<string> $base */
        $base = config()->array('manual.source_document_mimes');

        return [...$base, ...self::IMAGE_EXTENSIONS];
    }

    /** @return list<string> 内容 sniff MIME (SourceDocumentService::allowedMimeTypes 相当) */
    public static function mimes(): array
    {
        return [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/plain',
            ...self::IMAGE_MIMES,
        ];
    }

    /**
     * フロント `<input accept>` 属性用の文字列 (拡張子のみ)。
     */
    public static function acceptAttribute(): string
    {
        $parts = array_map(static fn (string $ext): string => ".{$ext}", self::extensions());

        return implode(',', $parts);
    }

    /**
     * 受理形式の人間向けラベル (法務確認を経た文面。FormRequest の 422 文言と
     * 作成画面の help 文言が共有する)。
     *
     * **機械導出しない**: 拡張子リストから日本語の文を組み立てる形にすると
     * config を触った副作用で文面が変わりうるため、承認済みの文をそのまま持つ。
     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin (拡張子集合が現在値ちょうど) が検出する。
     */
    public static function formatsLabel(): string
    {
        return 'PDF・Excel・テキスト形式、または JPEG・PNG の画像';
    }
}
```

**`imagesEnabled()` は撤去する** (呼び出し元は施策 5 で `VideoManualController` の
1 箇所だけであることを確認済み。他に呼び出しが無いことは施策 10 の残存確認でも再確認する)。

### PHPStan 適合チェック
- [x] 戻り値の型 (`list<string>` / `string`) は変更なし
- [x] null 安全: 対象外
- [x] DTO を返している: 対象外 (静的ユーティリティ、既存のまま)
- [x] Generics: 対象外

### テスト計画
- [x] 既存テスト `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` の更新 (施策 6)。
  `extensions()` / `mimes()` / `acceptAttribute()` / `formatsLabel()` の**全て**を完全一致
  (`toBe`) で固定する (拡張子だけでなく sniff MIME 集合も画像受理のセキュリティ境界であるため、
  `mimes()` の完全一致も欠かさない — 既存テストは元々この 4 メソッドを完全一致で検査しており、
  畳み込み後も同じ網羅性を維持する)
- [x] 新規テスト: 施策 6 で既存テストを書き換える形で対応 (新規ファイルは作らない)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (Unit テストなので該当なし)

### リスク
- `imagesEnabled()` を削除するため、他に呼び出し元が増えていた場合はコンパイルエラーで
  即座に判明する (PHPStan level 10 が未定義メソッド呼び出しを検出する)。実装時に
  `grep -rn imagesEnabled app resources` で再確認する。

---

## 施策 3: AnalysisPipeline の route 決定を無条件化

### 変更箇所
- ファイル: `app/Services/Manual/AnalysisPipeline.php` (L206-273: `runExtractStage()` /
  `resolveExtractInput()`)

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし (`ExtractedText`/`ImageAnalysisMediaData`/`PdfAnalysisMediaData` の
  型・生成経路は不変)
- テストファイル: `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` (施策 6)

### 現行コード
```php
    private function runExtractStage(
        AnalysisJob $job,
        SourceDocument $document,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): ExtractedSopData {
        $isImage = in_array($document->mime, ['image/jpeg', 'image/png'], true);
        $ocrEnabled = config()->boolean('manual.ocr_analysis_enabled');
        // 初期値: 画像 + フラグ有効なら最初から 'ocr'、それ以外は 'text'。
        // PDF が品質ゲート失敗から OCR フォールバックへ入る場合は、resolveExtractInput()
        // が参照渡しでこの値を 'ocr' へ更新する (media 検証を試みる直前に更新するため、
        // 検証が失敗して例外が飛んでも route は正しく 'ocr' のまま catch へ渡る)。
        $route = ($isImage && $ocrEnabled) ? 'ocr' : 'text';
        // 媒体検証が成功した後に LLM 呼び出しが失敗した場合でも、検証済みの媒体メタデータ
        // (容量・ページ数・画素数) をログへ残すため、$input をこのスコープで保持し続ける。
        $input = null;

        try {
            $input = $this->resolveExtractInput($document, $isImage, $ocrEnabled, $route);
            $extracted = $this->runExtractStep($job, $document, $input, $deadline, $context);

            $this->logExtractStageTerminal($job, $document, $route, $input, null);

            return $extracted;
        } catch (Throwable $exception) {
            $this->logExtractStageTerminal($job, $document, $route, $input, $exception);

            throw $exception;
        }
    }

    /**
     * text 抽出を試み、失敗理由が OCR 経路の対象なら media 検証へフォールバックする。
     * 対象外の理由 (tooLarge 等) や、画像/PDF 以外の mime での失敗はそのまま再送出する
     * (既存の catch → failJob 経路がそのまま処理する)。ログは出さない (呼び出し元
     * `runExtractStage()` が終端をまとめて 1 回ログする)。
     *
     * @param  string  $route  呼び出し元の route (参照渡し)。PDF が OCR フォールバックへ
     *                         入ると判断した瞬間 (media 検証を試みる前) に 'ocr' へ更新する
     *                         (戻り値の型だけで route を判定すると、media 検証自体が失敗したケースで
     *                         route を復元できないため)。
     */
    private function resolveExtractInput(
        SourceDocument $document,
        bool $isImage,
        bool $ocrEnabled,
        string &$route,
    ): ExtractedText|ImageAnalysisMediaData|PdfAnalysisMediaData {
        if ($isImage && $ocrEnabled) {
            // 画像は SopTextExtractor::kindFor() の default 分岐が unextractable を投げる
            // (テキスト抽出は元々試みない対象)。ここで直接 media 検証へ回す
            // ($route は呼び出し元で既に 'ocr' に初期化済み)。
            return $this->mediaValidator->validateImage($document);
        }

        try {
            return $this->extractor->extract($document);
        } catch (AnalysisFailedException $exception) {
            $isPdf = $document->mime === 'application/pdf';
            if ($ocrEnabled && $isPdf && $exception->reason->isOcrEligibleForPdf()) {
                $route = 'ocr'; // media 検証を試みる直前に更新 (この後の呼び出しが失敗しても正しい)

                return $this->mediaValidator->validatePdfForOcr($document);
            }

            throw $exception; // OCR 対象外、またはフラグ無効時はそのまま失敗 (既存の catch → failJob)
        }
    }
```

### 変更後コード
```php
    private function runExtractStage(
        AnalysisJob $job,
        SourceDocument $document,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): ExtractedSopData {
        $isImage = in_array($document->mime, ['image/jpeg', 'image/png'], true);
        // 初期値: 画像なら最初から 'ocr'、それ以外は 'text'。
        // PDF が品質ゲート失敗から OCR フォールバックへ入る場合は、resolveExtractInput()
        // が参照渡しでこの値を 'ocr' へ更新する (media 検証を試みる直前に更新するため、
        // 検証が失敗して例外が飛んでも route は正しく 'ocr' のまま catch へ渡る)。
        $route = $isImage ? 'ocr' : 'text';
        // 媒体検証が成功した後に LLM 呼び出しが失敗した場合でも、検証済みの媒体メタデータ
        // (容量・ページ数・画素数) をログへ残すため、$input をこのスコープで保持し続ける。
        $input = null;

        try {
            $input = $this->resolveExtractInput($document, $isImage, $route);
            $extracted = $this->runExtractStep($job, $document, $input, $deadline, $context);

            $this->logExtractStageTerminal($job, $document, $route, $input, null);

            return $extracted;
        } catch (Throwable $exception) {
            $this->logExtractStageTerminal($job, $document, $route, $input, $exception);

            throw $exception;
        }
    }

    /**
     * text 抽出を試み、失敗理由が OCR 経路の対象なら media 検証へフォールバックする。
     * 対象外の理由 (tooLarge 等) や、画像/PDF 以外の mime での失敗はそのまま再送出する
     * (既存の catch → failJob 経路がそのまま処理する)。ログは出さない (呼び出し元
     * `runExtractStage()` が終端をまとめて 1 回ログする)。
     *
     * @param  string  $route  呼び出し元の route (参照渡し)。PDF が OCR フォールバックへ
     *                         入ると判断した瞬間 (media 検証を試みる前) に 'ocr' へ更新する
     *                         (戻り値の型だけで route を判定すると、media 検証自体が失敗したケースで
     *                         route を復元できないため)。
     */
    private function resolveExtractInput(
        SourceDocument $document,
        bool $isImage,
        string &$route,
    ): ExtractedText|ImageAnalysisMediaData|PdfAnalysisMediaData {
        if ($isImage) {
            // 画像は SopTextExtractor::kindFor() の default 分岐が unextractable を投げる
            // (テキスト抽出は元々試みない対象)。ここで直接 media 検証へ回す
            // ($route は呼び出し元で既に 'ocr' に初期化済み)。
            return $this->mediaValidator->validateImage($document);
        }

        try {
            return $this->extractor->extract($document);
        } catch (AnalysisFailedException $exception) {
            $isPdf = $document->mime === 'application/pdf';
            if ($isPdf && $exception->reason->isOcrEligibleForPdf()) {
                $route = 'ocr'; // media 検証を試みる直前に更新 (この後の呼び出しが失敗しても正しい)

                return $this->mediaValidator->validatePdfForOcr($document);
            }

            throw $exception; // OCR 対象外はそのまま失敗 (既存の catch → failJob)
        }
    }
```

`runExtractStep()` / `logExtractStageTerminal()` / `observabilityCategoryFor()` 等の
他メソッドは `$ocrEnabled` を参照していないため変更しない。

### PHPStan 適合チェック
- [x] 戻り値の型は不変
- [x] null 安全: `$input` の null 許容ロジックは既存のまま
- [x] DTO を返している: `ExtractedText|ImageAnalysisMediaData|PdfAnalysisMediaData` の union 型は既存のまま
- [x] Generics: 対象外

### テスト計画
- [x] 既存テスト `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` の更新 (施策 6):
  「フラグ無効時は画像アップロードが OCR フォールバックなしで即時失敗する (回帰)」と
  「フラグ無効時はテキスト品質ゲート失敗 PDF も OCR フォールバックなしで即時失敗する (回帰)」
  の 2 テストは、検査対象の状態 (`ocr_analysis_enabled=false`) が構造的に作れなくなるため削除する
- [x] 新規テスト: なし (既存テストの畳み込みのみ)
- [x] 個別の `DatabaseTransactions` を使っていない (既存どおり `RefreshDatabase` グローバル適用)

### リスク
- `runExtractStage()` の初期 route が常に「画像なら ocr」になるため、OCR 経路の実行頻度が
  増加する (フラグが無かった環境と比べて画像アップロードそのものが常に受理されるようになる
  ため)。これは施策の意図した効果であり、コスト・失敗率への影響は概念設計の
  「リスクと運用上の帰結」4. に記載の既存観測計画 (`docs/rollout-checklists.md`
  「観測・課金の評価」節) で継続的に見る。

---

## 施策 4: 説明 docblock のフラグ前提を除去 (コード分岐なし)

### 変更箇所
- ファイル: `app/Services/Manual/SopTextExtractor.php` (L22-25 のクラス docblock)
- ファイル: `app/Rules/SourceDocumentSizeLimit.php` (L23-25 のクラス docblock)

### 波及変更
- なし (docblock のみ。コードの分岐・実行結果に変更は無い)

### 現行コード (`SopTextExtractor.php`)
```php
 * テキスト抽出できない・日本語比率が不足する PDF は `AnalysisPipeline` が
 * `AnalysisMediaValidator` 経由の OCR 経路 (画像・スキャン SOP の OCR 対応) へ回す
 * (`manual.ocr_analysis_enabled` フラグが有効な場合のみ)。本クラスの責務は
 * あくまで「テキストを抽出できるか」の判定であり、OCR 経路の判断はここでは行わない。
```

### 変更後コード (`SopTextExtractor.php`)
```php
 * テキスト抽出できない・日本語比率が不足する PDF は `AnalysisPipeline` が
 * `AnalysisMediaValidator` 経由の OCR 経路 (画像・スキャン SOP の OCR 対応) へ回す。
 * 本クラスの責務はあくまで「テキストを抽出できるか」の判定であり、OCR 経路の判断は
 * ここでは行わない。
```

### 現行コード (`SourceDocumentSizeLimit.php`)
```php
 * 「画像かどうか」はファイルの実バイトの性質であり、`ocr_analysis_enabled` フラグで
 * 変わる「現在の許可集合」(`AcceptedSourceDocumentTypes`) とは別概念。
 * ここではフラグに依存しない固定の判定を使う (許可判定と容量分類の責務を混同しない。
 * MIME の受理可否そのものは `mimes:` ルールが担当し、本 Rule は「受理された後の
 * 容量分類」だけを担当する)。
```

### 変更後コード (`SourceDocumentSizeLimit.php`)
```php
 * 「画像かどうか」はファイルの実バイトの性質であり、受理可否そのもの
 * (`AcceptedSourceDocumentTypes`) とは別概念。ここでは受理可否の判定に依存しない
 * 固定の判定を使う (許可判定と容量分類の責務を混同しない。MIME の受理可否そのものは
 * `mimes:` ルールが担当し、本 Rule は「受理された後の容量分類」だけを担当する)。
```

### PHPStan 適合チェック
- 対象外 (docblock のみ)

### テスト計画
- [x] コードの分岐が無いため新規/既存テストへの影響は無い。既存の
  `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` の容量上限テスト (施策 6 で
  `config()->set('manual.ocr_analysis_enabled', ...)` 呼び出しだけを削るテスト) が
  そのまま回帰検査を担う

### リスク
- なし

---

## 施策 5: Inertia props / Svelte props の撤去

### 変更箇所
- ファイル: `app/Http/Controllers/Projects/VideoManualController.php`
  (`create()` L70-77、`show()` L203-207)
- ファイル: `resources/js/components/features/manual/SourceDocumentUpload.svelte`
- ファイル: `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte`
- ファイル: `resources/js/pages/Manuals/Create.svelte`
- ファイル: `resources/js/pages/Manuals/Show.svelte`

### 波及変更
- TypeScript 型定義: 上記 4 Svelte ファイルの `interface Props` から
  `imageSourceDocumentsEnabled: boolean;` を削除
- API Resource/DTO: 対象外 (Inertia props は素の配列、DTO 化の対象外の既存パターンを維持)
- テストファイル: 施策 7 (`SourceDocumentUpload.test.ts` / `SourceDocumentUploadNotice.test.ts` /
  `ManualsCreate.test.ts`)。加えて `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` の
  「公開面の一貫性」テスト (施策 6) も `imageSourceDocumentsEnabled` という prop 名自体を
  参照しているため確認が要る (プロパティは撤去するため、当該テストの assertion からも除く)。

### 現行コード (`VideoManualController.php::create()`)
```php
            // 作成と同時の SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // StoreVideoManualRequest と同じ AcceptedSourceDocumentTypes を情報源にする
            // = ダイアログに出る形式とサーバが受理する形式が構造的に一致する。
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
            // help 文言用の受理形式ラベル (422 文言と同一の情報源)
            'sourceDocumentFormatsLabel' => AcceptedSourceDocumentTypes::formatsLabel(),
```

### 変更後コード (`VideoManualController.php::create()`)
```php
            // 作成と同時の SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // StoreVideoManualRequest と同じ AcceptedSourceDocumentTypes を情報源にする
            // = ダイアログに出る形式とサーバが受理する形式が構造的に一致する。
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            // help 文言用の受理形式ラベル (422 文言と同一の情報源)
            'sourceDocumentFormatsLabel' => AcceptedSourceDocumentTypes::formatsLabel(),
```

### 現行コード (`VideoManualController.php::show()`)
```php
            // SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // AcceptedSourceDocumentTypes が単一の情報源 (フラグに連動)
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
```

### 変更後コード (`VideoManualController.php::show()`)
```php
            // SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // AcceptedSourceDocumentTypes が単一の情報源
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
```

### 現行コード (`SourceDocumentUploadNotice.svelte`)
```svelte
<script lang="ts">
    /**
     * SOP アップロードの外部送信案内。**文言の唯一の出現箇所** (法務確認を経た文面。
     * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
     * component 1 つへ集約している)。
     *
     * 一般案内はフラグの真偽に関わらず常時表示する (テキスト・Excel・通常 PDF にも
     * 等しく当てはまる事実のため)。OCR 固有警告だけを imageSourceDocumentsEnabled で
     * 出し分ける (画像・スキャン SOP の OCR 対応の方針)。
     *
     * **wrapper 要素を作らない**: 呼び出し側の flex 列 (gap) が案内の各段落へ直接効く
     * 前提で描画順・間隔が決まっているため、fragment として 2 要素を返す。
     */
    interface Props {
        imageSourceDocumentsEnabled: boolean;
    }

    let { imageSourceDocumentsEnabled }: Props = $props();
</script>

<p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
    アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
</p>
{#if imageSourceDocumentsEnabled}
    <p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
        画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
        不要な個人情報や機密情報が写っていないか特に確認してください。
        画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
    </p>
{/if}
```

### 変更後コード (`SourceDocumentUploadNotice.svelte`)
```svelte
<script lang="ts">
    /**
     * SOP アップロードの外部送信案内。**文言の唯一の出現箇所** (法務確認を経た文面。
     * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
     * component 1 つへ集約している)。
     *
     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 `manual.ocr_analysis_enabled` フラグは
     * オーナー決定により撤去済み) なので、OCR 固有警告も常時表示する。props は持たない。
     *
     * **wrapper 要素を作らない**: 呼び出し側の flex 列 (gap) が案内の各段落へ直接効く
     * 前提で描画順・間隔が決まっているため、fragment として 2 要素を返す。
     */
</script>

<p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
    アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
</p>
<p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
    画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
    不要な個人情報や機密情報が写っていないか特に確認してください。
    画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
</p>
```

### 現行コード (`SourceDocumentUpload.svelte` の抜粋)
```svelte
    interface Props {
        projectId: number;
        manualId: number;
        hasDocument: boolean;
        sourceDocumentAccept: string;
        imageSourceDocumentsEnabled: boolean;
    }

    let { projectId, manualId, hasDocument, sourceDocumentAccept, imageSourceDocumentsEnabled }: Props = $props();
```
```svelte
    <SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />
```

### 変更後コード (`SourceDocumentUpload.svelte` の抜粋)
```svelte
    interface Props {
        projectId: number;
        manualId: number;
        hasDocument: boolean;
        sourceDocumentAccept: string;
    }

    let { projectId, manualId, hasDocument, sourceDocumentAccept }: Props = $props();
```
```svelte
    <SourceDocumentUploadNotice />
```

### 現行コード (`Manuals/Create.svelte` の抜粋)
```svelte
    interface Props {
        project: { id: number; name: string };
        categories: CategoryOption[];
        /** SOP アップロードの `<input accept>` 属性値 (画像・スキャン SOP の OCR 対応) */
        sourceDocumentAccept: string;
        /** 画像・スキャン PDF の OCR 対応が有効か (フラグ連動の案内出し分け専用) */
        imageSourceDocumentsEnabled: boolean;
        /** 受理形式の人間向けラベル (422 文言と同一の情報源) */
        sourceDocumentFormatsLabel: string;
    }

    let {
        project,
        categories,
        sourceDocumentAccept,
        imageSourceDocumentsEnabled,
        sourceDocumentFormatsLabel,
    }: Props = $props();
```
```svelte
                    <SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />
```

### 変更後コード (`Manuals/Create.svelte` の抜粋)
```svelte
    interface Props {
        project: { id: number; name: string };
        categories: CategoryOption[];
        /** SOP アップロードの `<input accept>` 属性値 (画像・スキャン SOP の OCR 対応) */
        sourceDocumentAccept: string;
        /** 受理形式の人間向けラベル (422 文言と同一の情報源) */
        sourceDocumentFormatsLabel: string;
    }

    let {
        project,
        categories,
        sourceDocumentAccept,
        sourceDocumentFormatsLabel,
    }: Props = $props();
```
```svelte
                    <SourceDocumentUploadNotice />
```

### 現行コード (`Manuals/Show.svelte` の抜粋)
```svelte
    interface Props {
        ...
        /** SOP アップロードの `<input accept>` 属性値 (画像・スキャン SOP の OCR 対応) */
        sourceDocumentAccept: string;
        /** 画像・スキャン PDF の OCR 対応が有効か (フラグ連動の案内出し分け専用) */
        imageSourceDocumentsEnabled: boolean;
    }

    let {
        ...
        sourceDocumentAccept,
        imageSourceDocumentsEnabled,
    }: Props = $props();
```
```svelte
                        <SourceDocumentUpload
                            projectId={project.id}
                            manualId={manual.id}
                            hasDocument={analysis.hasDocument}
                            {sourceDocumentAccept}
                            {imageSourceDocumentsEnabled}
                        />
```

### 変更後コード (`Manuals/Show.svelte` の抜粋)
```svelte
    interface Props {
        ...
        /** SOP アップロードの `<input accept>` 属性値 (画像・スキャン SOP の OCR 対応) */
        sourceDocumentAccept: string;
    }

    let {
        ...
        sourceDocumentAccept,
    }: Props = $props();
```
```svelte
                        <SourceDocumentUpload
                            projectId={project.id}
                            manualId={manual.id}
                            hasDocument={analysis.hasDocument}
                            {sourceDocumentAccept}
                        />
```

### PHPStan 適合チェック (Controller 側)
- [x] 戻り値の型 (`Inertia\Response`) は不変
- [x] null 安全: 対象外
- [x] DTO を返している: 対象外 (Inertia props は既存パターンのまま素の配列)
- [x] Generics: 対象外

### テスト計画
- [x] 既存テスト更新: 施策 6・7 (バックエンド Feature テストの `imageSourceDocumentsEnabled`
  assertion 削除、フロント 3 テストファイルの props/assertion 更新)
- [x] 新規テスト: なし
- [x] `pnpm typecheck` / `pnpm build` で Svelte 側の型不整合が無いことを確認する
  (props 型を削除した後、渡す側にまだ渡している箇所が残っていれば Svelte 5 の
  strict props 検査で検出される)

### リスク
- `imageSourceDocumentsEnabled` を渡している箇所を取り残すと、Svelte コンポーネントが
  「宣言されていない prop を受け取る」形になる (Svelte 5 では余分な prop は無視される場合が
  あるため、型検査 (`pnpm typecheck`) を実装完了条件に含めることで検出する)。

---

## 施策 6: バックエンドテストの畳み込み

### 変更箇所
- ファイル: `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`
- ファイル: `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`
- ファイル: `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php`

### 波及変更
- なし (テストファイルのみ)

### 方針

思考原則 3 (後方互換の並走を残さない) と、概念設計の「テストファーストの順序」に従い、
以下の順で進める:

1. 各テストファイルを「常時有効」を前提にした期待値へ書き換える。書き換えた時点では
   本体 (施策 1〜5) はまだ変更していないため、フラグ分岐が残る現行実装に対して **fail する**
   ことを確認する。
2. 施策 1〜5 (本体) を実装し、書き換えたテストを green にする。

### `AcceptedSourceDocumentTypesTest.php`

削除するテスト (無効状態を固定するテスト。常時有効の下では検査対象の状態が作れない):
- `'フラグ false のとき画像を含まない'`
- `'formatsLabel はフラグ false のとき画像を含まない文面を返す'`

書き換えるテスト:
- `'フラグ true のとき画像 (jpg/jpeg/png) を含む'`
  → `'画像 (jpg/jpeg/png) を含む (常時有効)'` に改名し、`config()->set(...)` の行を削除、
  `imagesEnabled()` の assertion を削除する (メソッド自体を撤去したため)。
- `'formatsLabel はフラグ true のとき画像を含む文面を返す'`
  → `'formatsLabel は画像を含む文面を返す (常時有効)'` に改名し、`config()->set(...)` を削除。
- `'前提の pin: ...'` → 基底拡張子集合 (`source_document_mimes`) と、画像込み拡張子集合が
  現在値ちょうどであることを検査する pin として残す。`config()->set('manual.ocr_analysis_enabled', ...)`
  の呼び出しを削除し、単に `AcceptedSourceDocumentTypes::extensions()` の返り値を検査する
  形にする (画像込みの 1 状態しか無いため、pin も 1 状態分になる)。
- `'webp/gif はフラグに関わらず含まれない (スコープ外)'`
  → `'webp/gif は含まれない (スコープ外)'` に改名し、`config()->set(...)` を削除。

### `SourceDocumentUploadOcrTest.php`

削除するテスト:
- `'先に赤くする: フラグ既定 (false) では jpg アップロードが 422 になる'`
  (検査対象の状態が構造的に作れなくなる)

書き換えるテスト:
- `'フラグ true では jpg/png アップロードが成功する'`
  → `'jpg/png アップロードが成功する'` に改名し、`config()->set(...)` を削除
- `'フラグ true でも HEIC は引き続き 422 で拒否される'`
  → `'HEIC は引き続き 422 で拒否される'` に改名し、`config()->set(...)` を削除
- `'新規マニュアル作成時 (StoreVideoManualRequest) もフラグに応じて jpg 受理可否が変わる'`
  → `'新規マニュアル作成時 (StoreVideoManualRequest) でも jpg アップロードが成功する'` に改名し、
  フラグ false 側の 422 期待部分 (前半の `config()->set(false)` ブロック) を削除、
  true 側の成功検査だけを残す
- `'webp/gif はフラグ true でも引き続き拒否される (回帰)'`
  → `'webp/gif は引き続き拒否される (回帰)'` に改名し、`config()->set(...)` を削除
- `'画像の容量上限超過 (source_document_image_max_bytes 基準) は 422'` /
  `'容量上限の判定材料は sniff MIME である (偽装で迂回できない)'` /
  `'容量上限の判定材料は sniff MIME である (実バイトと client 申告が食い違う場合)'` /
  `'画像の手順書は 1 枚まで (2 枚目は明示的に拒否される)'` /
  `'非画像 (PDF) の 2 枚目は画像制約の対象外で受理される'`
  → いずれも `config()->set('manual.ocr_analysis_enabled', true);` の行を削除するだけ
  (常時この状態なので明示不要)
- `'公開面の一貫性: FormRequest / Service / Inertia Props がフラグに応じて同じ集合を表す'`
  → `foreach ([false, true] as $flag)` のループを撤去し、単一状態の検査に書き換える。
  このテストが実際に検査する範囲を明示すると以下の 3 つである (テスト名は検出範囲と
  一致させ、それ以上を主張しない):
  - **FormRequest 境界**: `postJson(".../source-documents", ['document' => fakeJpegFile()])`
    が `assertRedirect()` (成功) を返すこと (jpg が `StoreSourceDocumentRequest` の
    `mimes:` ルールを通過する)
  - **Service 境界**: `app(SourceDocumentService::class)->appendDocument(...)` が
    例外を投げずに `image/jpeg` の `SourceDocument` を返すこと
  - **Inertia Props 境界**: `create()`/`show()` 両方の `sourceDocumentAccept` が
    画像込み固定値 (`.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png`) で一致すること
    (`imageSourceDocumentsEnabled` prop の assertion は施策 5 で撤去した prop なので削除する)
  テスト名は `'公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が
  同じ受理形式 (画像込み) を表す'` に改名し、上記 3 境界を検査していることをテスト内の
  コメントで明示する。**detailed-review Round 2 の Warning を受けた明記**: FormRequest 境界
  (HTTP 経由の jpg アップロード) と Service 境界 (`appendDocument()` 経由の jpg アップロード)
  は、既存実装どおり**別々の `VideoManual` fixture (`$httpManual` / `$serviceManual`)** に
  対して行う (元の両フラグ版テストが既にこの分離を採用している)。これにより「画像は
  1 手順書につき 1 枚」制約による 2 境界間の状態干渉を避ける。ループを撤去して単一状態に
  畳み込んだ後も、この 2 fixture 分離は維持する (畳み込みは `foreach ([false, true] as $flag)`
  を撤去するだけであり、fixture 構成自体は変更しない)。
- `'作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す (両フラグ)'`
  → 画像は既に常時受理されるため「フラグ false: jpeg は受理外」というケースは
  構造的に成立しない。**detailed-review Round 2 の Warning を受けた修正**:
  `$cases` 配列と `foreach` ループそのものを撤去し (1 件だけの配列とループを残すのは
  後方互換の並走を残さないという思考原則 3 に反する)、HEIC 拒否の単一ケースを
  直接検査する形に書き換える:
  ```php
  test('作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す', function (): void {
      Storage::fake();
      [, $owner, $project, $manual] = ocrUploadContext();
      $makeFile = fn (): UploadedFile => UploadedFile::fake()->create('rejected.heic', 10, 'image/heic');
      $expected = '対応していないファイル形式です。'
          .AcceptedSourceDocumentTypes::formatsLabel()
          .'でアップロードし直してください。';

      $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
          'title' => '422 文言の経路差テスト',
          'category' => null,
          'document' => $makeFile(),
      ])->assertUnprocessable()
          ->assertJsonValidationErrors(['document'])
          ->assertJsonFragment(['document' => [$expected]]);

      $this->actingAs($owner)->postJson(
          "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
          ['document' => $makeFile()],
      )->assertUnprocessable()
          ->assertJsonValidationErrors(['document'])
          ->assertJsonFragment(['document' => [$expected]]);
  });
  ```
  テスト名は `'作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す'` に改名する。

### `AnalysisPipelineOcrTest.php`

削除するテスト:
- `'フラグ無効時は画像アップロードが OCR フォールバックなしで即時失敗する (回帰)'`
- `'フラグ無効時はテキスト品質ゲート失敗 PDF も OCR フォールバックなしで即時失敗する (回帰)'`

書き換えるテスト:
- `beforeEach` の `config()->set('manual.ocr_analysis_enabled', true);` を削除する
  (常時有効になるため設定不要)。
- 他のテスト名・本体に `フラグ有効` を含む記述があれば「常時有効」の前提に合わせて
  コメント・テスト名を調整する (挙動自体は変更しない: 画像アップロード成功・
  PDF OCR フォールバック成功・OCR 対象外失敗の回帰・media 検証失敗ログ・LLM 応答失敗ログの
  5 テストはそのまま残す)。

### PHPStan 適合チェック
- 既存の `phpstan.neon` の走査対象設定に従う (`tests/` が対象に含まれる場合は level 10 を
  満たす。型注釈を追加・削除するものではなく、既存テストの記述パターンをそのまま使うため、
  対象に含まれていても不適合になる変更ではない)

### テスト計画
- [x] 施策 1〜5 実装前に本節の書き換えを行い、旧実装 (フラグ分岐がまだ残るコード) に対して
  書き換えたテストが fail することを確認する (テストファースト)
- [x] 施策 1〜5 実装後に green になることを確認する
- [x] `composer test` (RefreshDatabase + `--parallel`) をフルで実行し、削除したテストの
  カバー範囲が他のテストと重複せず失われていないことを確認する
  (「フラグ無効」という状態自体が撤去対象なので、無効状態のテストは削除してよい —
  概念設計の実装方針 6 に基づく判断)

### リスク
- テストの畳み込みで検査対象が減るのは意図的である (無効状態が存在しなくなるため)。
  ただし「画像は常時受理される」「PDF 品質ゲート失敗は常時 OCR フォールバックする」という
  **有効状態側の挙動そのもの**は 1 件も削除しない (削除するのは無効状態の固定テストだけ)。

---

## 施策 7: フロントテストの畳み込み

### 変更箇所
- ファイル: `tests/js/components/features/manual/SourceDocumentUpload.test.ts`
- ファイル: `tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts`
- ファイル: `tests/js/pages/ManualsCreate.test.ts`

### 波及変更
- なし (テストファイルのみ)

### `SourceDocumentUploadNotice.test.ts`

削除するテスト:
- `'imageSourceDocumentsEnabled=false では一般案内だけを全文どおり描画する'`

書き換えるテスト:
- `'imageSourceDocumentsEnabled=true では OCR 固有警告も全文どおり描画する'`
  → `'一般案内と OCR 固有警告を全文どおり描画する'` に改名し、`render(SourceDocumentUploadNotice, {})`
  (props なしで呼ぶ) へ変更する。

### `SourceDocumentUpload.test.ts`

削除するテスト:
- `'imageSourceDocumentsEnabled=false では accept が画像を含まず OCR 固有文言が出ない'`

書き換えるテスト:
- `'imageSourceDocumentsEnabled=true では accept に画像拡張子を含み OCR 固有文言が出る'`
  → `'accept に画像拡張子を含み OCR 固有文言が出る'` に改名し、`baseProps` から
  `imageSourceDocumentsEnabled` を除く。
- `'案内は file input より前にあり、form 直下に置かれる (wrapper を挟まない)'`
  → props から `imageSourceDocumentsEnabled` を除く (挙動検査自体は変更なし)。

### `ManualsCreate.test.ts`

`baseProps` を書き換える:
```ts
const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
    sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
    sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式、または JPEG・PNG の画像",
};
```

削除するテスト:
- `'手順書 (SOP) のファイル入力は accept をサーバ props からそのまま受ける (フラグ false 相当)'`
  (画像を含まない props 自体が撤去対象の状態のため成立しない)

書き換えるテスト:
- `'フラグ true 相当の props では accept に画像拡張子を含み OCR 固有警告が出る'`
  → `'手順書 (SOP) のファイル入力は accept をサーバ props からそのまま受ける (画像拡張子を含む)'`
  に改名し、`baseProps` をそのまま使う形にする (個別の props 上書きが不要になる)。
- `'help は受理形式ラベル props + 現行の後半文で構成される (全文一致)'`
  → 期待文字列を画像込みラベル前提へ変更:
  `"PDF・Excel・テキスト形式、または JPEG・PNG の画像。アップロードすると AI 解析でシナリオを生成できます。"`
- `'案内は file input より前にあり、作成 form の直下に置かれる'`
  → `props: { ...baseProps, imageSourceDocumentsEnabled: true }` の上書きを削除し、
  `props: baseProps` へ変更 (常時 OCR 案内が出るため上書き不要)。

他のテスト (タイトル入力・カテゴリ・ファイル選択名表示等) は `baseProps` を経由するだけで
`imageSourceDocumentsEnabled` に直接依存していないため変更不要。

### PHPStan 適合チェック
- 対象外 (Vitest テストファイル)

### テスト計画
- [x] 施策 5 実装前に本節の書き換えを行い、旧実装 (props がまだ必須のコンポーネント) に対して
  書き換えたテストが fail することを確認する (テストファースト)
- [x] 施策 5 実装後に green になることを確認する
- [x] `pnpm test` フルスイート green を実装完了条件に含める

### リスク
- なし (UI の見た目・挙動は「常時 OCR 案内が出る」状態に一本化されるだけで、
  既存の有効状態側の挙動は変更しない)

---

## 施策 8: rollout チェックリストの記録追記・運用手順の書き換え

### 変更箇所
- ファイル: `docs/rollout-checklists.md`

### 方針

既存の「承認記録」節 (項目 1・項目 2 の記録) と「観測・課金の評価」節は**削除しない**
(概念設計の制約: 既存の承認記録・将来の再評価義務の記録を消さない)。
一方で「反映の運用手順」節はフラグの存在を前提にした記述 (「フラグを `true` にする変更は
リポジトリ変更ではない」「フラグを `false` に戻す時の挙動」等) であり、フラグ撤去後は
文字どおりには成立しなくなる。

**detailed-review Round 1 の Warning を受けた修正**: 見出しを過去形へ変えるだけで本文の
現在形の手順記述 (環境変数設定・`config:cache` 再生成・synthetic 確認) をそのまま残すと、
見出しの注意を読み飛ばした運用者がそれを現行の手順として実行できてしまう。したがって
本文も含めて **全体を「実行できない過去の記録」として囲う** (実施済みの事実と、当時の
計画としてしか存在しない未実施部分を区別して書く)。また「問題発覚時の対応はコード修正+
通常デプロイの経路のみ」という記述は、本リポジトリにデプロイ定義が無いこと
(AGENTS.md 「運用要件 (route:cache)」節が明記) を踏まえると、「リリースの rollback」という
具体的な手段の有無を本設計側で断定できない。**本番環境を運用する側の手順に依存する**ことを
明示し、断定を避ける。

### 追記する内容 (節の先頭、既存の承認記録より前)

`## 画像・スキャン SOP の OCR 対応 (`MANUAL_OCR_ANALYSIS_ENABLED`)` の見出し直下、
既存の「1. 法務確認」「2. 画像内 prompt injection の手動評価」の前に、以下を追記する:

```markdown
> **フラグは撤去済み (2026-08-21〜22、JST。オーナー決定)**: 本チェックリストが定めていた
> rollout gate `config('manual.ocr_analysis_enabled')` は、本節が定める前提条件 (項目 1 の
> 完了、項目 2 の例外決定) が満たされたことを踏まえ、オーナーが「フラグを既定 `true` にして
> 緊急停止手段として残す」案を提示された上で、B 案 (フラグの完全撤去 = 常時有効化) を
> 「いらないので。」という理由で選択したため、コードから撤去した
> (`devnotes/20260822-0146-ocr-flag-removal/`)。対応する config キー・env 変数はコードに
> 存在しない。画像・スキャン SOP の OCR 対応は**現在は無条件で有効**であり、以下の
> チェックリストは**その有効化に至った経緯の記録**として残す。
>
> **フラグ撤去後の障害対応**: 環境変数の切替による即時停止はできなくなった (この手段は
> フラグ撤去前から「単独の運用操作」であり本リポジトリの変更ではなかった点は変わらないが、
> 撤去後はその操作対象自体が無い)。問題発覚時の復旧手段は、**本番環境を運用する側が持つ
> デプロイ手順に依存する** (本リポジトリには `deploy/` / terraform / k8s / CI デプロイ job の
> いずれも存在しない — AGENTS.md 「運用要件 (route:cache)」節が明記するとおり — ため、
> 本チェックリストは復旧手段そのものを保証しない)。運用手順が承認済みリリースへの
> rollback に対応している場合はそれを行い、対応していない場合、またはこの場面で
> rollback が適切でない場合は、無効化・修正する patch を通常のデプロイ手順で反映する。
> どちらを選ぶかは運用する側の判断であり、本チェックリストは選択肢を限定しない。
>
> 項目 3 (再評価対象の棚卸し) が定める再評価義務は、フラグの有無と無関係に存続する。
>
> **リポジトリ外の残置確認 (運用側の作業として引き継ぐ)**: 本番環境の環境変数・Secret 管理に
> `MANUAL_OCR_ANALYSIS_ENABLED` の設定が残っていても、コードがこのキーを参照しなくなった
> ため実害は無い。ただし整理は別途、本番環境を運用する側の作業として残す
> (管理主体・削除確認の方法は本チェックリストの対象外であり、運用側が判断する)。
```

### 書き換える節: 「反映の運用手順」

detailed-review Round 1 の Warning (「本文を過去形へ変える」方針と「本文はそのまま残す」
具体策が矛盾している) を受け、見出し・本文の**全体**を「実行できない過去の記録」として
書き換える (読み飛ばして現行手順として実行されることを防ぐ):

- 見出しを `## 旧・反映の運用手順 (廃止済み・実行不可。フラグ撤去前の記録)` に変える。
- 本文冒頭に以下を追加する:
  ```markdown
  **本節は実行できない**。`MANUAL_OCR_ANALYSIS_ENABLED` フラグと対応する config キーは
  撤去済みでコードに存在しないため、以下の手順をそのまま実行する対象は無い。
  本節はフラグが存在していた当時の計画 (実際に有効化した経緯を含む) を、
  意思決定の記録として保存するものである。
  ```
- 本文中の指示的な文 (「〜する」「〜が必要」) は、実施済みの事実として書かれている箇所
  (例: 承認記録節にある実際の有効化の経緯) と、当時の計画にすぎず実施の記録が無い箇所
  (例: synthetic 確認の具体的な実施記録) を区別できるよう、各段落の先頭に
  「(計画として記録)」または「(実施済み)」の注記を付ける。**新たに実施記録を作文しない**
  (無い情報をここで作文しないという既存の「承認記録」節の方針を維持する)。

### 波及変更
- なし (ドキュメントのみ)

### テスト計画
- [x] ドキュメント変更のため実装テスト対象外。実装完了条件として、変更後の文面が
  「現役の設定であるかのように読める記述」を含まないことをレビューで確認する
  (概念設計「波及変更・検証の受入条件」の分類基準)

### リスク
- なし

---

## 施策 9: architecture 記述の更新

### 変更箇所
- ファイル: `docs/architecture.md` (OCR 経路の設計記述。`rollout gate` の記述を含む段落)

### 現行コード (該当段落)
```markdown
- **rollout gate**: `config('manual.ocr_analysis_enabled')` (既定 `false`) が
  画像受理 (アップロード層) と OCR フォールバック (`AnalysisPipeline::resolveExtractInput()`) の
  両方を一貫してゲートする (`AcceptedSourceDocumentTypes` が単一の情報源)。
  フラグを `true` にする前提条件はチェックリスト (`docs/rollout-checklists.md`) を参照。
```

### 変更後コード
```markdown
- **rollout gate は撤去済み**: 画像受理 (アップロード層) と OCR フォールバック
  (`AnalysisPipeline::resolveExtractInput()`) は無条件で有効である (`AcceptedSourceDocumentTypes`
  が単一の情報源)。旧 rollout gate `config('manual.ocr_analysis_enabled')` はオーナー決定
  (2026-08-21〜22、JST) により撤去した。有効化に至った経緯・既存の承認記録・
  再評価義務は `docs/rollout-checklists.md` を参照。
```

### 波及変更
- なし (ドキュメントのみ)

### テスト計画
- [x] ドキュメント変更のため実装テスト対象外

### リスク
- なし

---

## 施策 10: 残存確認 (実装完了条件)

### 内容

実装 PR の完了条件として、以下を確認する (恒久的な Architecture テスト/gate は新設しない —
思考原則 2 に基づく判断。概念設計「波及変更・検証の受入条件」参照)。

**detailed-review Round 1・2 の Warning を受けた修正**: (1) `git grep` は git 追跡下の
ファイルしか対象にしないため、追跡対象外を含む `.env*` 全件は `rg --hidden --no-ignore-vcs`
で glob 指定して確認する (`.env` / `.env.local` の 2 ファイルへ固定すると、
`.env.testing.local` のような別名ファイルを見逃す。加えて「検索エラー」と「一致ゼロ」を
終了コードで区別し、エラーを完了扱いにしない)。(2) 検索語に、公開メソッド
`imagesEnabled()` やローカル変数 `$ocrEnabled` のような「フラグ撤去後に死んで残るコード片」を
検出できる語を追加する。(3) 本番環境・外部 Secret 管理は本リポジトリの検索が保証できる範囲の
外であり、施策 8 の運用側引き継ぎ (残置確認) へ分離する (本施策の対象はリポジトリ内のみ)。

```bash
# 1) git 追跡下 (本体の識別子 3 種 + 死んだコード片の 2 語)
git grep -n -E "ocr_analysis_enabled|MANUAL_OCR_ANALYSIS_ENABLED|imageSourceDocumentsEnabled|imagesEnabled|ocrEnabled" \
  -- . ':!devnotes'

# 2) git 追跡対象外を含む .env* 全件 (git grep は追跡ファイルにしか届かないため、
#    hidden/ignore 対象も含めて glob で全件確認する。rg の終了コード:
#    0 = 一致あり (要確認)、1 = 一致なし (完了)、2 = 検索自体のエラー (完了扱いにしない —
#    「一致ゼロ」と「検索が失敗した」を区別する)
rg --hidden --no-ignore-vcs -n -g '.env*' \
  "ocr_analysis_enabled|MANUAL_OCR_ANALYSIS_ENABLED" .
echo "rg exit=$?"  # 1 であることを確認する (0 や 2 なら未完了)

# 検査対象になった .env* ファイルの一覧も PR 証跡として残す
rg --hidden --no-ignore-vcs --files -g '.env*' .
```

結果の分類 (2 分類。中間の「一部だけ許容」は作らない — deny-by-default):

- **ゼロ件を要求する範囲**: `app/` `config/` `resources/js/` `routes/` `database/`
  `bootstrap/` `tests/` `tests/js/` `.env*` (追跡・非追跡の両方)。ここに 1 件でも残れば
  未完了 (「フラグの有効・無効を前提にしたテスト名やコメント」も、実装時に
  `ocr_analysis_enabled`/`imagesEnabled`/`ocrEnabled` の字面が残っていない限りは
  上記コマンドで検出できないため、施策 6・7 のテスト名・コメントの書き換え漏れは
  レビューでも確認する — 字面が残っていれば検索でも捕捉される)
- **履歴としてのみ許容する範囲**: `docs/rollout-checklists.md` / `docs/architecture.md`。
  施策 8・9 で書き換えた「過去にこの名前のフラグが存在し、この日にオーナー決定で撤去された」
  という履歴の記述だけであること (現役の設定であるかのように読める文が残っていないこと)。
  `devnotes/` 配下 (本設計ディレクトリを含む過去の設計履歴一式) はそのまま残る
  (履歴として保存対象。書き換えない)。
- 本番環境・外部 Secret 管理への残置確認は、施策 8 が明記する運用側の引き継ぎ事項として
  分離する (本施策の検索コマンドの対象外)。

### テスト計画
- [x] 上記 grep の実行結果を実装 PR の説明に記録する (「テストなしの実装完了報告」の
  禁止事項に触れないよう、この確認は下記の既存検証コマンド一式の green と併せて
  実装完了の証拠とする)
- [x] **実装完了条件として揃える検証コマンド一式** (detailed-review Round 1 の Warning を
  受けて明記。AGENTS.md 標準の検証セット):
  - `composer test`
  - `composer phpstan`
  - `vendor/bin/pint --test`
  - `pnpm lint`
  - `pnpm typecheck`
  - `pnpm build`
  - `pnpm typecheck:packages`
  - `pnpm build:packages`
  - `pnpm test`
  - `pnpm test:packages`

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** (1 PR で完結) |
| 判断根拠 | 施策間の依存が強く (config → PHP ロジック → Inertia props → フロント →
  テスト → ドキュメント の順で 1 本の変更系列)、フラグを部分的に残す中間状態は
  「一部だけ常時有効・一部だけフラグ依存」という後方互換の並走そのものになり
  思考原則 3 に反する。撤去規模も 10 施策・変更ファイル数は限定的 (バックエンド 6・
  フロント 4・テスト 6・ドキュメント 2) で、1 PR で完結させても実装コストは小さい |
| 競合リスク | 低 (対象ファイル群は他の進行中作業と重複しにくい OCR フラグ固有の領域)。
  ただし `AnalysisPipeline.php` / `AcceptedSourceDocumentTypes.php` / 各 Svelte ファイルは
  比較的更新頻度が高いため、実装着手前に最新の main を取り込むこと |
