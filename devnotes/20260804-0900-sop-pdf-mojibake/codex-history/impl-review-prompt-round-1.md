## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## あなたの役割

あなたは Laravel + Svelte アプリのコードレビュアーである。TODO T096 の実装差分をレビューせよ。

### レビュー観点

1. **設計との一致性** — 詳細設計書のとおりに実装されているか。逸脱があれば妥当か
2. **正確性** — アルゴリズムの正しさ、境界条件、例外経路、副作用
3. **PHPStan level 10 適合性** — mixed の漏れ、型の widen、@phpstan-ignore の混入がないか
4. **DTO / JsonResource パターン** — response()->json() 直書きがないか
5. **テスト網羅性** — 不変条件がテストで固定されているか。テストが実装の写像になっていないか
6. **セキュリティ** — untrusted な顧客手順書テキストの扱い、ログへの本文漏洩、ReDoS 等
7. **DESIGN.md 準拠 / Atomic Design 準拠** — 本差分は resources/js・resources/css を含まないため該当なし

### 出力形式

- ファイルごとに判定 (APPROVE / CHANGES_REQUESTED) と指摘
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

---

## 詳細設計書

# 詳細設計: SOP PDF の文字化けが全ガードを素通りして LLM に渡る問題の是正

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント

## 概念設計リファレンス

`devnotes/20260804-0900-sop-pdf-mojibake/conceptual-design.md`（Codex 概念設計レビュー Round 3 で APPROVED）

実測スクリプト: `devnotes/20260804-0900-sop-pdf-mojibake/probe/`（`probe-extract.php` /
`probe-fonts.php` / `probe-aw*.php` / `probe-metrics.php` / `probe-repair.php` /
`probe-cp1252-table.php` / `probe-final.php`）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | SJIS 誤解釈テキストの区間単位復元 | `app/Services/Manual/SopTextExtractor.php` | Critical |
| 2 | 日本語本文ゲート + 閾値 config | `app/Services/Manual/SopTextExtractor.php` / `config/manual.php` | Critical |
| 3 | 例外の文言体系是正（PDF の空抽出 = unextractable / 新設 insufficientJapaneseText / 次アクション追記） | `app/Exceptions/Manual/AnalysisFailedException.php` / `app/Services/Manual/SopTextExtractor.php` | High |
| 4 | 観測ログ（復元発火・ゲート棄却） | `app/Services/Manual/SopTextExtractor.php` | Medium |
| 5 | テスト（合成 fixture・境界 fixture・同梱実 PDF 5 本の期待値表） | `tests/Unit/Manual/SopTextExtractorTest.php` | Critical |
| 6 | ドキュメント追記 | `docs/architecture.md` | Low |

---

## 施策 1: SJIS 誤解釈テキストの区間単位復元

### 変更箇所

- ファイル: `app/Services/Manual/SopTextExtractor.php`
  - `extract()` (L26-57) に復元段を挿入
  - 定数 `CP1252_RUN_PATTERN` / `JAPANESE_PATTERN` / `NON_SPACE_PATTERN` を新設
  - private メソッド `repairSjisMojibake()` / `decodeRunAsSjis()` / `japaneseCount()` /
    `japaneseRatio()` を新設

### 波及変更

- TypeScript型定義: **なし**（DTO の形は変えない。`ExtractedText` に項目を足さない）
- API Resource/DTO: **なし**
- テストファイル: `tests/Unit/Manual/SopTextExtractorTest.php`（施策 5）

### 現行コード

```php
    public function extract(SourceDocument $document): ExtractedText
    {
        $contents = Storage::get($document->file_path);
        Assert::string($contents, "SOP ファイルが見つかりません: {$document->file_path}");

        $kind = $this->kindFor($document->mime);
        try {
            $text = match ($kind) {
                'pdf' => $this->fromPdf($contents),
                'spreadsheet' => $this->fromSpreadsheet($contents),
                'plain' => $contents,
            };
        } catch (Throwable $exception) {
            report($exception);

            throw AnalysisFailedException::unextractable();
        }

        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
        $text = $this->normalize($text);

        $bytes = strlen($text);
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::tooShort();
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        return new ExtractedText($text, $bytes, $kind);
    }
```

### 変更後コード

```php
    /**
     * CP1252 の 256 バイトと 1:1 対応する文字だけからなる極大連続区間。
     *
     * pdfparser は定義済み CJK CMap (90ms-RKSJ-H 等) を知らないため、CP932 バイト列を
     * Windows-1252 として decode してしまう (Font::decodeContentByAutodetectIfNecessary)。
     * その化けを元バイト列へ戻せる文字集合が、この 256 文字の全単射である。
     * U+0081/008D/008F/0090/009D は CP1252 未定義バイトだが mbstring が素通しし、かつ
     * Shift_JIS の主要 lead byte (0x81 = JIS 記号行 / 0x8D / 0x8F / 0x90) なので必須。
     * BMP 全走査で「mbstring の CP1252 往復が同一になる集合」と完全一致を検証済み
     * (devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-cp1252-table.php)。
     */
    private const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
        .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
        .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
        .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';

    /** 日本語文字 (かな / 漢字 / 全角句読点 / 全角英数記号 / 半角カナ) */
    private const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
        .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';

    /** 比率の分母 = 空白を除いた文字数 (レイアウト由来の空白量に判定を引きずられない) */
    private const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';

    public function extract(SourceDocument $document): ExtractedText
    {
        $contents = Storage::get($document->file_path);
        Assert::string($contents, "SOP ファイルが見つかりません: {$document->file_path}");

        $kind = $this->kindFor($document->mime);
        try {
            $extracted = match ($kind) {
                'pdf' => $this->fromPdf($contents),
                'spreadsheet' => $this->fromSpreadsheet($contents),
                'plain' => $contents,
            };
        } catch (Throwable $exception) {
            // parser の内部例外はユーザー向け文言へ正規化 (詳細は report で内部ログのみ)
            report($exception);

            throw AnalysisFailedException::unextractable();
        }

        // 「区間の採否」と「文書ゲート」は同じ閾値で判断する = ここで 1 回だけ読む
        $minJapaneseRatio = config()->float('manual.analysis_min_japanese_ratio');

        $extracted = $this->ensureUtf8($extracted); // JSON 化・UserInput 生成を不正バイトで壊さない
        $repaired = $this->repairSjisMojibake($extracted, $minJapaneseRatio);
        if ($repaired !== $extracted) {
            // 現場でこの化けがどれだけ起きているかを後から測れるようにする (本文は出さない)
            Log::info('SOP テキストの SJIS 誤解釈を復元しました', [
                'reason' => 'sjis_mojibake_repaired',
                'source_document_id' => $document->id,
                'source_kind' => $kind,
                'japanese_ratio_before' => round($this->japaneseRatio($extracted), 4),
                'japanese_ratio_after' => round($this->japaneseRatio($repaired), 4),
            ]);
        }

        $text = $this->normalize($repaired);
        $bytes = strlen($text);
        if ($bytes === 0 && $kind === 'pdf') {
            // PDF から 1 バイトも取れない = 文字が画像 (スキャン手順書)。
            // plain / spreadsheet の空ファイルは原因が違うので tooShort のままにする
            throw AnalysisFailedException::unextractable();
        }
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        $ratio = $this->japaneseRatio($text);
        if ($ratio < $minJapaneseRatio) {
            Log::info('SOP テキストの日本語本文が不足しています', [
                'reason' => 'insufficient_japanese_text',
                'source_document_id' => $document->id,
                'source_kind' => $kind,
                'japanese_ratio' => round($ratio, 4),
                'byte_length' => $bytes,
            ]);

            throw AnalysisFailedException::insufficientJapaneseText();
        }

        return new ExtractedText($text, $bytes, $kind);
    }

    /**
     * CP932 バイト列を Windows-1252 として解釈された文字列 (mojibake) の復元。
     *
     * CP1252 レパートリ内の**極大連続区間**だけを単位に読み直す。区間外の文字
     * (= 正しく decode された日本語。AS_作業手順書.pdf では隠し OCR 層由来の 63 文字)
     * には一切触れないため、混在文書でも既存の正しいテキストを壊さない。
     */
    private function repairSjisMojibake(string $text, float $minJapaneseRatio): string
    {
        $repaired = preg_replace_callback(
            self::CP1252_RUN_PATTERN,
            fn (array $matches): string => $this->decodeRunAsSjis((string) $matches[0], $minJapaneseRatio),
            $text,
        );

        return is_string($repaired) ? $repaired : $text;
    }

    /**
     * 1 区間を SJIS-win として読み直す。3 段の検証をすべて満たしたときだけ置換し、
     * 1 つでも欠けたら原文をそのまま返す (推測変換をしない)。
     *   1. CP1252 へ可逆に戻せる (= この区間が CP1252 誤解釈由来である)
     *   2. 得たバイト列が SJIS-win として妥当である
     *   3. 復号で日本語が増え、かつ日本語比率が閾値以上である
     */
    private function decodeRunAsSjis(string $run, float $minJapaneseRatio): string
    {
        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
        if (! is_string($bytes) || mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
            return $run;
        }
        if (! mb_check_encoding($bytes, 'SJIS-win')) {
            return $run;
        }

        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        if (! is_string($decoded) || ! mb_check_encoding($decoded, 'UTF-8')) {
            return $run;
        }
        if ($this->japaneseCount($decoded) <= $this->japaneseCount($run)) {
            return $run;
        }

        // 区間の採否も文書ゲートと同じ閾値で判断する (「日本語として読めるか」は 1 つの基準)
        return $this->japaneseRatio($decoded) >= $minJapaneseRatio ? $decoded : $run;
    }

    /** 日本語文字数 */
    private function japaneseCount(string $text): int
    {
        $count = preg_match_all(self::JAPANESE_PATTERN, $text);

        return is_int($count) ? $count : 0;
    }

    /** 空白を除いた文字数に占める日本語文字の比率 (0.0〜1.0) */
    private function japaneseRatio(string $text): float
    {
        $assessable = preg_match_all(self::NON_SPACE_PATTERN, $text);
        if (! is_int($assessable) || $assessable === 0) {
            return 0.0;
        }

        return $this->japaneseCount($text) / $assessable;
    }
```

追加 import: `use Illuminate\Support\Facades\Log;`

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`string` / `int` / `float` / `void`）
- [x] `mb_convert_encoding` の `string|false` を `is_string()` で絞る（widen しない）
- [x] `preg_replace_callback` の `string|null` を `is_string()` で絞る
- [x] `preg_match_all` の `int|false` を `is_int()` で絞る
- [x] `config()->float()` / `config()->integer()` は型付きアクセサ（`mixed` を漏らさない）。閾値は `extract()` で 1 回だけ読み、`float` として下位メソッドへ渡す
- [x] クロージャ引数 `array $matches` は `(string) $matches[0]` で string 化（`array<int,string>` を前提にしない）
- [x] DTO（`ExtractedText`）を返している（配列返却なし）

### テスト計画

施策 5 に集約。

### リスク

- **正当なテキストの誤変換**: 3 段検証すべてを満たさない区間は原文のまま。
  対照コーパス（日本語 / 英 / 独 / 仏）で**変化ゼロ**を実測済み。
- **byte 長の増加で tooLarge が新たに発火する**: 起きない。SJIS 2 バイトは
  mojibake では 3〜4 UTF-8 バイト、復元後は 3 バイトであり、**復元は byte 長を増やさない**
  （実測 AS: 6451 → 5006）。
- **性能**: 129KB を 0.014 秒（実測）。`analysis_max_text_bytes` = 150,000 でも問題ない。

---

## 施策 2: 日本語本文ゲート + 閾値 config

### 変更箇所

- `app/Services/Manual/SopTextExtractor.php`（施策 1 のコード内に含む）
- `config/manual.php`: `analysis_min_japanese_ratio` を追加

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Unit/Manual/SopTextExtractorTest.php`（施策 5）

### 変更後コード（`config/manual.php`、`analysis_min_text_bytes` の直後）

```php
    // 抽出テキストの実質空判定 (これ未満は「本文が短すぎます」。PDF の 0 バイトのみ unextractable)
    'analysis_min_text_bytes' => 100,

    // 抽出テキストが「日本語の手順書本文」と言えるかの下限 (空白を除く文字数に占める
    // かな/漢字/全角記号/半角カナの比率)。これ未満は LLM に渡さず insufficientJapaneseText。
    // v1 の原稿は日本語 (doc/08 §182 / config/app.php の locale=ja) であることが前提。
    // 導出 (devnotes/20260804-0900-sop-pdf-mojibake): 破損クラスの実測は 0.000 (glyph ノイズ /
    // 欧文) 〜 0.020 (SJIS 化け未修復) で誤受理側に 5 倍、正当な日本語 SOP は復元後 0.661 /
    // 型番を極端に詰めた対照でも 0.196 で誤拒否側に約 2 倍のマージンがある。
    // 誤拒否は運用ログ (reason=insufficient_japanese_text) で観測できるようにしてあり、
    // field データが出るまでこの値は動かさない。
    'analysis_min_japanese_ratio' => 0.10,
```

### PHPStan適合チェック

- [x] `config()->float()` は `is_float()` でなければ `InvalidArgumentException` を投げてから
  `float` を返す（`Illuminate\Config\Repository::float()` L134-146）。
  設定値は必ず**float リテラル**（`0.10`。`0` や `'0.10'` にしない）で書くこと

### テスト計画

施策 5 に集約。

### リスク

- **誤拒否**: 型番・設備コード主体の帳票系 SOP。境界テストで挙動を固定し、
  ログで観測する。値は field データが出るまで動かさない。

---

## 施策 3: 例外の文言体系是正

### 変更箇所

- `app/Exceptions/Manual/AnalysisFailedException.php`
  - `unextractable()` に次アクションを追記
  - `insufficientJapaneseText()` を新設
- `app/Services/Manual/SopTextExtractor.php`: 0 バイトを `unextractable()` へ（施策 1 に含む）

### 現行の帰結（コードから確定した事実）

同梱スキャン PDF 3 本（AP / AT / 作業要領書）は抽出 0 バイトであり、
`strlen('') = 0 < analysis_min_text_bytes (100)` により **`tooShort()`** が投げられている。
ユーザーが受け取る文言は「手順書の本文が短すぎます。**もう少し詳しい手順書を**
アップロードしてください。」で、**スキャン PDF に対して事実と異なる指示**になっている
（手順書は詳しいが、文字が画像であるだけ）。

ただし 0 バイトの意味は**媒体で異なる**:

| kind | 0 バイトの意味 | 正しい文言 |
|---|---|---|
| `pdf` | テキスト層が無い = 文字が画像（スキャン手順書） | `unextractable()` |
| `plain` / `spreadsheet` | 単に中身が空のファイル | `tooShort()`（「本文が短すぎます」の極端ケース） |

よって **`$kind === 'pdf'` のときだけ** 0 バイトを `unextractable()` に寄せる。
媒体非依存の新例外は作らない（原因が違うものを 1 文言に畳むとどちらにも不正確になる）。

### 変更後コード

```php
    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ・PDF から 1 バイトも取れない) */
    public static function unextractable(): self
    {
        return new self(
            'テキストを抽出できません。画像・スキャンの手順書は現在未対応です。'
            .'Excel・テキスト形式か、文字が選択できる PDF をアップロードしてください。'
        );
    }

    /**
     * 抽出はできたが日本語の本文が閾値に満たない
     * (文字化け / テキスト埋め込みの破損 / 日本語以外の手順書)。
     * 3 つの原因をアプリ側で識別する手段は無いため、どの原因でも実行できる次アクションを示す。
     */
    public static function insufficientJapaneseText(): self
    {
        return new self(
            '手順書から十分な日本語の本文を読み取れませんでした。'
            .'文字が画像になっている / PDF のテキスト埋め込みが壊れている / '
            .'日本語以外の手順書、のいずれかの可能性があります。'
            .'日本語の手順書を、Excel・テキスト形式か文字を選択できる PDF でアップロードしてください。'
        );
    }
```

### 波及変更

- TypeScript型定義: **なし**（`analysis_jobs.error` は既に `string|null` として
  `AnalysisJobData` / `AnalysisJobResource` を通っており、文言は透過的に表示される）
- API Resource/DTO: **なし**
- テストファイル:
  - `tests/Feature/Projects/AnalysisPipelineTest.php` — 既存テストは
    `'短すぎ'`(9 バイト) / 日本語 fixture を使っており**影響なし**（確認済み）
  - `tests/Unit/Manual/SopTextExtractorTest.php` — `unextractable` を
    部分一致（`'テキストを抽出できません'`）で検証している既存 2 件は**そのまま通る**

### PHPStan適合チェック

- [x] `static` メソッドの戻り値型 `self` が明示されている
- [x] 新規の型引数・generics なし

### リスク

- 文言変更により、文言完全一致でアサートしているテストがあれば落ちる。
  現行で完全一致アサートしているのは `tooShort` / `tooLarge` の 2 件のみで、
  どちらも本施策では変更しない（`rg` で確認済み）。

---

## 施策 4: 観測ログ

### 変更箇所

`app/Services/Manual/SopTextExtractor.php`（施策 1 のコードに含む）

### 設計意図

- 「この化けが現場でどれだけ起きているか」「ゲートの誤拒否が起きていないか」を測れるようにする。
- **本文は絶対に出さない**（untrusted な顧客手順書。ログ経由の情報露出を作らない）。
  出すのは `source_document_id` / `source_kind` / `reason` / 比率 / byte 長のみ。
- level は `info`（失敗ではあるがユーザー入力起因であり、運用者のアラート対象ではない）。
  既存の `AnalysisPipeline` も再試行を `Log::warning` で残しており、粒度の流儀は一致する。
- 識別は `reason`（`sjis_mojibake_repaired` / `insufficient_japanese_text`）が一意に担う。
  既存ログ規約に無い新語彙（`manual_stage` 等）は消費者がいないため追加しない。

### 波及変更

なし。

### テスト計画

ログ内容自体はテストしない（値の二重管理になる）。
`Log::spy()` での検証は行わず、**例外・返り値の不変条件をテストする**（施策 5）。

### リスク

なし（副作用のない info ログ）。

---

## 施策 5: テスト

### 変更箇所

- `tests/Unit/Manual/SopTextExtractorTest.php`（既存 8 件は変更しない。以下を追加）

### fixture 方針

| 種類 | 使うもの | 理由 |
|---|---|---|
| アルゴリズムの境界・誤変換ゼロ | **合成文字列**（`text/plain` の `storedDocument()`） | 閾値の境界を意図どおりに作れる。バイナリを増やさない |
| 実世界の回帰 | **同梱の実 PDF 5 本**（`doc/reference/sample-sop/`） | 本バグの発生源そのもの。パースは実測 0.05 秒 / peak 20MB で単体テストに耐える。同じ PDF をテストへ複製すると 720KB の重複バイナリになるため**参照する**。存在しなければ**明示的に失敗**させる（黙ってスキップしない） |

### 追加テスト

```php
/** 同梱サンプル SOP の中身 (存在しなければ黙ってスキップせず失敗させる) */
function sampleSopContents(string $name): string
{
    $path = base_path("doc/reference/sample-sop/{$name}");
    $contents = file_exists($path) ? file_get_contents($path) : false;
    if (! is_string($contents)) {
        throw new RuntimeException("回帰コーパスのサンプル SOP を読めません: {$path}");
    }

    return $contents;
}

/** CP932 バイト列を Windows-1252 として読んだときの化け (pdfparser が返すもの) を合成する */
function sjisMojibake(string $japanese): string
{
    $sjis = mb_convert_encoding($japanese, 'CP932', 'UTF-8');
    Assert::string($sjis);
    $mojibake = mb_convert_encoding($sjis, 'UTF-8', 'CP1252');
    Assert::string($mojibake);

    return $mojibake;
}
```

| # | テスト名 | 検証内容 |
|---|---------|---------|
| T1 | `CP1252 として読まれた SJIS テキストは日本語へ復元される` | `sjisMojibake(str_repeat('作業手順書 ネジを締める 安全確認 保護メガネ着用。', 5))` を `text/plain` で投入 → `text` に `'ネジを締める'` を含み、`byteLength = strlen(text)` |
| T2 | `正当な日本語テキストは 1 文字も変化しない` | **正規化済み**（連続空白・連続改行・前後空白を含まない）日本語 SOP を投入 → `text` が**入力と完全一致**（誤変換ゼロの回帰） |
| T3 | `正当な欧文テキストは復元されず日本語不足で拒否される` | 英 / 独(ä ö ü ß) / 仏(é à è) の 3 データセット（**いずれも `analysis_min_text_bytes` (100) 以上**にして `tooShort` と競合させない）→ `insufficientJapaneseText` 文言（`'十分な日本語の本文'`）で throw |
| T4 | `復元は混在文書の正規日本語を壊さない` | `'非鉄金属はその特性に応じた研削をする。'`（正規）+ `sjisMojibake('作業手順書 ネジを締める 安全確認')` を連結 → **両方の語**が結果に含まれる |
| T5 | `日本語比率が閾値未満のテキストは拒否される（境界: 下側）` | `config()->set('manual.analysis_min_japanese_ratio', 0.10)` + 日本語 9 文字 / ASCII 91 文字（比率 0.09）で 120 バイト以上 → throw |
| T6 | `日本語比率が閾値以上のテキストは通る（境界: 上側）` | 同条件で日本語 11 文字 / ASCII 89 文字（比率 0.11）→ 成功し `sourceKind = 'plain'` |
| T7 | `抽出結果が空の PDF は unextractable（tooShort と弁別）` | `sampleSopContents('AP_オペレーション手順書.pdf')` → `'テキストを抽出できません'` で throw（T8 に含めず、0 バイト → unextractable の弁別意図を単独で残す） |
| T8 | `同梱サンプル SOP 5 本の期待値表`（dataset） | 下表を **deny-by-default の期待値表**として固定 |
| T9 | `空の text/plain は tooShort（画像未対応と弁別）` | 空文字列を `text/plain` で投入 → `'本文が短すぎます'` で throw |
| T10 | `空の Spreadsheet は tooShort` | セルなしの xlsx → `'本文が短すぎます'` で throw。※ `fromSpreadsheet()` はシート名を 1 行目に出すため厳密には 0 バイトではないが、いずれにせよ `unextractable` ではなく `tooShort` であることを固定する |

T7 / T9 / T10 の 3 本で「**媒体ごとの空入力の文言体系**」が固定される
（pdf = `unextractable` / plain・spreadsheet = `tooShort`）。

#### T8 の期待値表（pdfparser の挙動が変わったら CI が落ちる）

| ファイル | 期待 | 追加アサート |
|---|---|---|
| `AP_オペレーション手順書.pdf` | throw `'テキストを抽出できません'` | — |
| `AT_作業手順書.pdf` | throw `'テキストを抽出できません'` | — |
| `作業要領書.pdf` | throw `'テキストを抽出できません'` | — |
| `AW_作業手順書 (1).pdf` | throw `'十分な日本語の本文'` | — |
| `AS_作業手順書.pdf` | **成功** | `text` に `'グラインダー研削作業'` と `'保護メガネ'` を含む / `sourceKind === 'pdf'` |

```php
// 例: T8 の骨子 (Pest の dataset で 5 本を回す)
test('同梱サンプル SOP 5 本の抽出結果は期待値表どおりである', function (string $file, ?string $expectedError): void {
    Storage::fake();
    $document = storedDocument(sampleSopContents($file), 'application/pdf', 'pdf');

    if ($expectedError !== null) {
        expect(fn () => app(SopTextExtractor::class)->extract($document))
            ->toThrow(AnalysisFailedException::class, $expectedError);

        return;
    }

    $extracted = app(SopTextExtractor::class)->extract($document);
    expect($extracted->sourceKind)->toBe('pdf');
    expect($extracted->text)->toContain('グラインダー研削作業');
    expect($extracted->text)->toContain('保護メガネ');
})->with([
    ['AP_オペレーション手順書.pdf', 'テキストを抽出できません'],
    ['AT_作業手順書.pdf', 'テキストを抽出できません'],
    ['作業要領書.pdf', 'テキストを抽出できません'],
    ['AW_作業手順書 (1).pdf', '十分な日本語の本文'],
    ['AS_作業手順書.pdf', null],
]);
```

### 既存テストへの影響（全件確認済み）

| テスト | 影響 |
|---|---|
| `tests/Unit/Manual/SopTextExtractorTest.php` 全 8 件 | fixture はすべて日本語 or 例外期待。**影響なし** |
| 同 `破損 PDF (パース不能) は unextractable に正規化される` | pdfparser が `Exception: Unable to find startxref` を投げる（実測）→ 従来どおり `unextractable`。**影響なし** |
| `tests/Feature/Projects/AnalysisPipelineTest.php` の `pipelineContext()` fixture | `str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5)` = 日本語比率高。**影響なし** |
| 同 `実質空の SOP は failed + tooShort 文言` | `'短すぎ'` = 9 バイト（0 ではない）→ 従来どおり `tooShort`。**影響なし** |
| 同 `バイト上限超過の SOP は failed` | 日本語 fixture + `max_text_bytes = 200` → 従来どおり `tooLarge`（ゲートより前で throw）。**影響なし** |
| `AnalysisTokenBudgetInvariantTest` / `AnalysisTimeBudgetInvariantTest` | 触る値が無い。**影響なし** |

### PHPStan適合チェック

- [x] ヘルパ関数 `sampleSopContents()` / `sjisMojibake()` は戻り値型 `string` を明示
- [x] `file_get_contents()` の `string|false` は `is_string()` で判定し、失敗時は
      `RuntimeException` を投げる（`(string)` cast で widen しない）
- [x] `mb_convert_encoding()` の `string|false` は `Webmozart\Assert\Assert::string()` で絞る
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Pest.php` のグローバル適用に従う）
- [x] テストデータは `SourceDocument::factory()`（既存 `storedDocument()` ヘルパ）で生成

### リスク

- `doc/reference/sample-sop/` のファイルが移動・削除されるとテストが落ちる。
  これは**望ましい失敗**（回帰コーパスの喪失を検出する）。`sampleSopContents()` が
  パスを明示した `RuntimeException` で失敗させる（黙ってスキップしない）。

---

## 施策 6: ドキュメント追記

### 変更箇所

- `docs/architecture.md` L119 の `Manual/SopTextExtractor` 行

### 変更後

```
| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** + **日本語本文ゲート** (`manual.analysis_min_japanese_ratio` 未満は LLM に渡さず insufficientJapaneseText。評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率。**閾値の変更は TODO 起票 + 実測の再提出を必須とする**) + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。0 バイトは媒体で弁別する (pdf = unextractable / plain・spreadsheet = tooShort) |
```

### 波及変更

なし。

### リスク

なし。

---

## T091（時間 budget 是正）との関係

bug-hunt F-1-01 で 2/2 タイムアウトした SOP は本件の `AS_作業手順書.pdf` であり、
「サイズが原因」という観測は**サイズと文字化けが交絡**していた。ただし
T091 の 360s ceiling は `max_tokens=16000` の飽和生成実測（273.9s / 58.4 token/s）から
導かれており、**AS に依存していない**。よって**交絡の判明ではその根拠は毀損されない**。

- **本設計では T091 が触れた値（`resources/prompts/*.yaml` の `client_options.timeout` /
  `config/manual.php` の `analysis_deadline_seconds`）に一切手を入れない。**
- 実装完了後に AS を再走行し、(a) 解析が成功すること (b) 実所要時間を測ること。
  復元後の AS は 5006 バイト（元 6451）と小さく、ceiling に対して大きな余裕が
  確認できた場合の**引き下げ再評価は別 TODO** とする（本タスクでは起票提案のみ）。

## 別 TODO の起票提案（本タスクでは実装しない）

1. **スキャン PDF の実態調査** — 同梱サンプル 5 本中 3 本が抽出 0 バイトの
   スキャン PDF である。実運用 SOP に占める割合を確かめ、OCR / マルチモーダル取り込み
   （`doc/10 §10.7` オープン項目 1）の優先度を判断する。
2. **T091 の 360s ceiling 再評価** — 上記の再走行結果を根拠に。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は `SopTextExtractor` / `AnalysisFailedException` / `config/manual.php` / 単体テスト / `docs/architecture.md` の 5 ファイルに閉じており、他タスクとの依存が無い。一方で AI 解析パイプラインの入口の挙動を変えるため、他の変更と混ぜずに単独で検証・レビューできる形が望ましい |
| 競合リスク | 低。`config/manual.php` は行追加のみ。`SopTextExtractor` は他タスクで触っている形跡なし（直近の T091 は prompt YAML と `AnalysisPipeline` を触っており、本ファイルは非対象） |


## 実装差分 (git diff)

```diff
diff --git a/app/Exceptions/Manual/AnalysisFailedException.php b/app/Exceptions/Manual/AnalysisFailedException.php
index e6791df..e73983d 100644
--- a/app/Exceptions/Manual/AnalysisFailedException.php
+++ b/app/Exceptions/Manual/AnalysisFailedException.php
@@ -12,10 +12,28 @@
  */
 final class AnalysisFailedException extends RuntimeException
 {
-    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ) */
+    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ・PDF から 1 バイトも取れない) */
     public static function unextractable(): self
     {
-        return new self('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
+        return new self(
+            'テキストを抽出できません。画像・スキャンの手順書は現在未対応です。'
+            .'Excel・テキスト形式か、文字が選択できる PDF をアップロードしてください。'
+        );
+    }
+
+    /**
+     * 抽出はできたが日本語の本文が閾値に満たない
+     * (文字化け / テキスト埋め込みの破損 / 日本語以外の手順書)。
+     * 3 つの原因をアプリ側で識別する手段は無いため、どの原因でも実行できる次アクションを示す。
+     */
+    public static function insufficientJapaneseText(): self
+    {
+        return new self(
+            '手順書から十分な日本語の本文を読み取れませんでした。'
+            .'文字が画像になっている / PDF のテキスト埋め込みが壊れている / '
+            .'日本語以外の手順書、のいずれかの可能性があります。'
+            .'日本語の手順書を、Excel・テキスト形式か文字を選択できる PDF でアップロードしてください。'
+        );
     }
 
     /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
diff --git a/app/Services/Manual/SopTextExtractor.php b/app/Services/Manual/SopTextExtractor.php
index 7e2062c..c860451 100644
--- a/app/Services/Manual/SopTextExtractor.php
+++ b/app/Services/Manual/SopTextExtractor.php
@@ -7,6 +7,7 @@
 use App\DataTransferObjects\Manual\Analysis\ExtractedText;
 use App\Exceptions\Manual\AnalysisFailedException;
 use App\Models\SourceDocument;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Storage;
 use PhpOffice\PhpSpreadsheet\Cell\Cell;
 use PhpOffice\PhpSpreadsheet\IOFactory;
@@ -20,9 +21,34 @@
  * - 分岐はアップロード時に内容 sniff 済みの mime を使う (クライアント拡張子は信頼しない)
  * - 抽出不能/実質空/バイト上限超過は AnalysisFailedException (ユーザー向け文言)
  * - byteLength (strlen = UTF-8 bytes) が token budget 判定値 (config manual.analysis_max_text_bytes)
+ * - SJIS 誤解釈 (pdfparser の定義済み CJK CMap 非対応) を区間単位で復元し、
+ *   日本語本文が閾値未満のテキストは LLM に渡さない (manual.analysis_min_japanese_ratio)
  */
 class SopTextExtractor
 {
+    /**
+     * CP1252 の 256 バイトと 1:1 対応する文字だけからなる極大連続区間。
+     *
+     * pdfparser は定義済み CJK CMap (90ms-RKSJ-H 等) を知らないため、CP932 バイト列を
+     * Windows-1252 として decode してしまう (Font::decodeContentByAutodetectIfNecessary)。
+     * その化けを元バイト列へ戻せる文字集合が、この 256 文字の全単射である。
+     * U+0081/008D/008F/0090/009D は CP1252 未定義バイトだが mbstring が素通しし、かつ
+     * Shift_JIS の主要 lead byte (0x81 = JIS 記号行 / 0x8D / 0x8F / 0x90) なので必須。
+     * BMP 全走査で「mbstring の CP1252 往復が同一になる集合」と完全一致を検証済み
+     * (devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-cp1252-table.php)。
+     */
+    private const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
+        .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
+        .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
+        .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';
+
+    /** 日本語文字 (かな / 漢字 / 全角句読点 / 全角英数記号 / 半角カナ) */
+    private const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
+        .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';
+
+    /** 比率の分母 = 空白を除いた文字数 (レイアウト由来の空白量に判定を引きずられない) */
+    private const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';
+
     public function extract(SourceDocument $document): ExtractedText
     {
         $contents = Storage::get($document->file_path);
@@ -30,7 +56,7 @@ public function extract(SourceDocument $document): ExtractedText
 
         $kind = $this->kindFor($document->mime);
         try {
-            $text = match ($kind) {
+            $extracted = match ($kind) {
                 'pdf' => $this->fromPdf($contents),
                 'spreadsheet' => $this->fromSpreadsheet($contents),
                 'plain' => $contents,
@@ -42,10 +68,29 @@ public function extract(SourceDocument $document): ExtractedText
             throw AnalysisFailedException::unextractable();
         }
 
-        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
-        $text = $this->normalize($text);
+        // 「区間の採否」と「文書ゲート」は同じ閾値で判断する = ここで 1 回だけ読む
+        $minJapaneseRatio = config()->float('manual.analysis_min_japanese_ratio');
+
+        $extracted = $this->ensureUtf8($extracted); // JSON 化・UserInput 生成を不正バイトで壊さない
+        $repaired = $this->repairSjisMojibake($extracted, $minJapaneseRatio);
+        if ($repaired !== $extracted) {
+            // 現場でこの化けがどれだけ起きているかを後から測れるようにする (本文は出さない)
+            Log::info('SOP テキストの SJIS 誤解釈を復元しました', [
+                'reason' => 'sjis_mojibake_repaired',
+                'source_document_id' => $document->id,
+                'source_kind' => $kind,
+                'japanese_ratio_before' => round($this->japaneseRatio($extracted), 4),
+                'japanese_ratio_after' => round($this->japaneseRatio($repaired), 4),
+            ]);
+        }
 
+        $text = $this->normalize($repaired);
         $bytes = strlen($text);
+        if ($bytes === 0 && $kind === 'pdf') {
+            // PDF から 1 バイトも取れない = 文字が画像 (スキャン手順書)。
+            // plain / spreadsheet の空ファイルは原因が違うので tooShort のままにする
+            throw AnalysisFailedException::unextractable();
+        }
         if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
             throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
         }
@@ -53,9 +98,89 @@ public function extract(SourceDocument $document): ExtractedText
             throw AnalysisFailedException::tooLarge();
         }
 
+        $ratio = $this->japaneseRatio($text);
+        if ($ratio < $minJapaneseRatio) {
+            Log::info('SOP テキストの日本語本文が不足しています', [
+                'reason' => 'insufficient_japanese_text',
+                'source_document_id' => $document->id,
+                'source_kind' => $kind,
+                'japanese_ratio' => round($ratio, 4),
+                'byte_length' => $bytes,
+            ]);
+
+            throw AnalysisFailedException::insufficientJapaneseText();
+        }
+
         return new ExtractedText($text, $bytes, $kind);
     }
 
+    /**
+     * CP932 バイト列を Windows-1252 として解釈された文字列 (mojibake) の復元。
+     *
+     * CP1252 レパートリ内の**極大連続区間**だけを単位に読み直す。区間外の文字
+     * (= 正しく decode された日本語。AS_作業手順書.pdf では隠し OCR 層由来の 63 文字)
+     * には一切触れないため、混在文書でも既存の正しいテキストを壊さない。
+     */
+    private function repairSjisMojibake(string $text, float $minJapaneseRatio): string
+    {
+        $repaired = preg_replace_callback(
+            self::CP1252_RUN_PATTERN,
+            fn (array $matches): string => $this->decodeRunAsSjis((string) $matches[0], $minJapaneseRatio),
+            $text,
+        );
+
+        return is_string($repaired) ? $repaired : $text;
+    }
+
+    /**
+     * 1 区間を SJIS-win として読み直す。3 段の検証をすべて満たしたときだけ置換し、
+     * 1 つでも欠けたら原文をそのまま返す (推測変換をしない)。
+     *   1. CP1252 へ可逆に戻せる (= この区間が CP1252 誤解釈由来である)
+     *   2. 得たバイト列が SJIS-win として妥当である
+     *   3. 復号で日本語が増え、かつ日本語比率が閾値以上である
+     */
+    private function decodeRunAsSjis(string $run, float $minJapaneseRatio): string
+    {
+        // encoding 名がリテラルのため mb_convert_encoding は string を返す (不正名は ValueError)
+        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
+        if (mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
+            return $run;
+        }
+        if (! mb_check_encoding($bytes, 'SJIS-win')) {
+            return $run;
+        }
+
+        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
+        if (! mb_check_encoding($decoded, 'UTF-8')) {
+            return $run;
+        }
+        if ($this->japaneseCount($decoded) <= $this->japaneseCount($run)) {
+            return $run;
+        }
+
+        // 区間の採否も文書ゲートと同じ閾値で判断する (「日本語として読めるか」は 1 つの基準)
+        return $this->japaneseRatio($decoded) >= $minJapaneseRatio ? $decoded : $run;
+    }
+
+    /** 日本語文字数 */
+    private function japaneseCount(string $text): int
+    {
+        $count = preg_match_all(self::JAPANESE_PATTERN, $text);
+
+        return is_int($count) ? $count : 0;
+    }
+
+    /** 空白を除いた文字数に占める日本語文字の比率 (0.0〜1.0) */
+    private function japaneseRatio(string $text): float
+    {
+        $assessable = preg_match_all(self::NON_SPACE_PATTERN, $text);
+        if (! is_int($assessable) || $assessable === 0) {
+            return 0.0;
+        }
+
+        return $this->japaneseCount($text) / $assessable;
+    }
+
     /**
      * mime → 抽出方式。未知 mime はアップロード時 sniff で弾かれている前提だが、
      * 防御的に unextractable で落とす (LLM に渡さない)。
diff --git a/config/manual.php b/config/manual.php
index b899381..cda3f8c 100644
--- a/config/manual.php
+++ b/config/manual.php
@@ -27,9 +27,19 @@
     // 安全側上界のため strlen で保証する (AnalysisTokenBudgetInvariantTest が算術を固定)
     'analysis_max_text_bytes' => 150_000,
 
-    // 抽出テキストの実質空判定 (これ未満は「テキストを抽出できません」)
+    // 抽出テキストの実質空判定 (これ未満は「本文が短すぎます」。PDF の 0 バイトのみ unextractable)
     'analysis_min_text_bytes' => 100,
 
+    // 抽出テキストが「日本語の手順書本文」と言えるかの下限 (空白を除く文字数に占める
+    // かな/漢字/全角記号/半角カナの比率)。これ未満は LLM に渡さず insufficientJapaneseText。
+    // v1 の原稿は日本語 (doc/08 §182 / config/app.php の locale=ja) であることが前提。
+    // 導出 (devnotes/20260804-0900-sop-pdf-mojibake): 破損クラスの実測は 0.000 (glyph ノイズ /
+    // 欧文) 〜 0.020 (SJIS 化け未修復) で誤受理側に 5 倍、正当な日本語 SOP は復元後 0.661 /
+    // 型番を極端に詰めた対照でも 0.196 で誤拒否側に約 2 倍のマージンがある。
+    // 誤拒否は運用ログ (reason=insufficient_japanese_text) で観測できるようにしてあり、
+    // field データが出るまでこの値は動かさない。
+    'analysis_min_japanese_ratio' => 0.10,
+
     // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
     'analysis_stale_after_minutes' => 30,
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 7a7a534..4dc6e68 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -116,7 +116,7 @@ ## 主要 Service (テンプレート同梱)
 | `Manual/SourceDocumentService` | AI-CUE: SOP (SourceDocument) の保存。追記型 immutable (差し替え = 新規行)。専用 route 経路は VideoManual 行ロック + draft/ready guard、MIME は内容 sniff で再判定 (polyglot 対策) |
 | `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / recoverStale = stale 回復 cron 本体) |
 | `Manual/AnalysisPipeline` | AI-CUE: 解析パイプライン本体 (extract→decompose→generate→terminal tx)。チケット 2 フェーズ (予約冪等キー = analysis_jobs.ticket_reservation_id、materialize + commit + succeeded を単一 tx で原子化)。LLM 出力の有界リトライ (JSON 検証失敗のみ最大 2 回) |
-| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定) |
+| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** + **日本語本文ゲート** (`manual.analysis_min_japanese_ratio` 未満は LLM に渡さず insufficientJapaneseText。評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率。**閾値の変更は TODO 起票 + 実測の再提出を必須とする**) + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。0 バイトは媒体で弁別する (pdf = unextractable / plain・spreadsheet = tooShort) |
 | `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / recoverStale・reconcileOutputs = cron 本体) |
 | `Manual/RenderPipeline` | AI-CUE: レンダパイプライン本体 (startJob→buildManifest→compose→upload→finalize)。チケット 2 フェーズ (予約冪等キー = render_jobs.ticket_reservation_id、complete + commit + succeeded を terminal tx で原子化)。version スナップショット固定 (§10.8-6) |
 | `Manual/CutSequencer` | AI-CUE: カット表示順 (step→配下 point) と表示ラベル (手順N/急所N-M) の導出 (読み取り専用) |
diff --git a/tests/Unit/Manual/SopTextExtractorTest.php b/tests/Unit/Manual/SopTextExtractorTest.php
index 05e33eb..89e8a3c 100644
--- a/tests/Unit/Manual/SopTextExtractorTest.php
+++ b/tests/Unit/Manual/SopTextExtractorTest.php
@@ -8,11 +8,13 @@
 use Illuminate\Support\Facades\Storage;
 use PhpOffice\PhpSpreadsheet\Spreadsheet;
 use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
+use Webmozart\Assert\Assert;
 
 /*
  * SOP テキスト抽出 (施策 7):
  * - plain / xlsx の抽出、UTF-8 strict 検証 (SJIS 変換 / バイナリ拒否)
  * - 実質空 (min_text_bytes 未満) / バイト上限超過の明示エラー
+ * - SJIS 誤解釈 (pdfparser の CJK CMap 非対応) の区間単位復元 / 日本語本文ゲート (T096)
  */
 
 /** 保存済み SourceDocument (Storage::fake 上) を作る */
@@ -27,6 +29,32 @@ function storedDocument(string $contents, string $mime, string $ext): SourceDocu
     ]);
 }
 
+/**
+ * 同梱サンプル SOP の中身 (回帰コーパス)。
+ * fixture を複製せず参照するため、欠落は黙ってスキップせず明示的に失敗させる。
+ */
+function sampleSopContents(string $name): string
+{
+    $path = base_path("doc/reference/sample-sop/{$name}");
+    $contents = file_exists($path) ? file_get_contents($path) : false;
+    if (! is_string($contents)) {
+        throw new RuntimeException("回帰コーパスのサンプル SOP を読めません: {$path}");
+    }
+
+    return $contents;
+}
+
+/** CP932 バイト列を Windows-1252 として読んだときの化け (pdfparser が返すもの) を合成する */
+function sjisMojibake(string $japanese): string
+{
+    $sjis = mb_convert_encoding($japanese, 'CP932', 'UTF-8');
+    Assert::string($sjis);
+    $mojibake = mb_convert_encoding($sjis, 'UTF-8', 'CP1252');
+    Assert::string($mojibake);
+
+    return $mojibake;
+}
+
 test('plain テキストをそのまま抽出する (byteLength = strlen)', function (): void {
     Storage::fake();
     $text = str_repeat("手順1 部品を取り付ける\n", 10);
@@ -115,3 +143,140 @@ function storedDocument(string $contents, string $mime, string $ext): SourceDocu
     expect(fn () => app(SopTextExtractor::class)->extract($document))
         ->toThrow(AnalysisFailedException::class);
 });
+
+/*
+ * T096: SJIS 誤解釈の区間単位復元 + 日本語本文ゲート
+ */
+
+test('CP1252 として読まれた SJIS テキストは日本語へ復元される', function (): void {
+    Storage::fake();
+    $document = storedDocument(
+        sjisMojibake(str_repeat('作業手順書 ネジを締める 安全確認 保護メガネ着用。', 5)),
+        'text/plain',
+        'txt',
+    );
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toContain('ネジを締める');
+    expect($extracted->text)->toContain('保護メガネ');
+    expect($extracted->byteLength)->toBe(strlen($extracted->text));
+});
+
+test('正当な日本語テキストは 1 文字も変化しない', function (): void {
+    Storage::fake();
+    // normalize() で変化しない形 (連続空白・連続改行・前後空白なし) にして「復元による誤変換ゼロ」を固定する
+    $text = "作業手順書\n1. ネジを締める (トルク 5Nm)\n2. カバーを取り付ける\n"
+        ."3. 動作確認を行う\n安全: 保護メガネと手袋を着用する";
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toBe($text);
+});
+
+test('正当な欧文テキストは復元されず日本語不足で拒否される', function (string $text): void {
+    Storage::fake();
+    // いずれも analysis_min_text_bytes (100) 以上にして tooShort と競合させない
+    expect(strlen($text))->toBeGreaterThanOrEqual(100);
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
+})->with([
+    'en' => ['Work Instruction: 1. Tighten the screw to 5Nm. 2. Attach the cover plate. 3. Check the operation before use.'],
+    'de' => ['Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für die Straße. Öl nachfüllen. Weiß markieren.'],
+    'fr' => ['Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté gauche. Contrôler après usage.'],
+]);
+
+test('復元は混在文書の正規日本語を壊さない', function (): void {
+    Storage::fake();
+    // 正しく decode された日本語 (AS の隠し OCR 層に相当) と mojibake の混在
+    $document = storedDocument(
+        '非鉄金属はその特性に応じた研削をする。'.sjisMojibake('作業手順書 ネジを締める 安全確認 保護メガネ着用'),
+        'text/plain',
+        'txt',
+    );
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toContain('非鉄金属');
+    expect($extracted->text)->toContain('ネジを締める');
+});
+
+test('日本語比率が閾値未満のテキストは拒否される (境界: 下側)', function (): void {
+    Storage::fake();
+    config()->set('manual.analysis_min_japanese_ratio', 0.10);
+    // 空白を除く 100 文字中 日本語 9 文字 = 0.09
+    $document = storedDocument(str_repeat('A', 91).'安全確認手順書作業', 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
+});
+
+test('日本語比率が閾値以上のテキストは通る (境界: 上側)', function (): void {
+    Storage::fake();
+    config()->set('manual.analysis_min_japanese_ratio', 0.10);
+    // 空白を除く 100 文字中 日本語 11 文字 = 0.11
+    $document = storedDocument(str_repeat('A', 89).'安全確認手順書作業前点', 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->sourceKind)->toBe('plain');
+});
+
+test('抽出結果が空の PDF は unextractable (tooShort と弁別)', function (): void {
+    Storage::fake();
+    $document = storedDocument(sampleSopContents('AP_オペレーション手順書.pdf'), 'application/pdf', 'pdf');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
+});
+
+test('空の text/plain は tooShort (画像未対応と弁別)', function (): void {
+    Storage::fake();
+    $document = storedDocument('', 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
+});
+
+test('空の Spreadsheet は tooShort', function (): void {
+    Storage::fake();
+    $spreadsheet = new Spreadsheet;
+    $tmp = tempnam(sys_get_temp_dir(), 'sop-xlsx-');
+    Assert::string($tmp);
+    (new Xlsx($spreadsheet))->save($tmp);
+    $document = storedDocument(
+        (string) file_get_contents($tmp),
+        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
+        'xlsx',
+    );
+    @unlink($tmp);
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
+});
+
+test('同梱サンプル SOP 5 本の抽出結果は期待値表どおりである', function (string $file, ?string $expectedError): void {
+    Storage::fake();
+    $document = storedDocument(sampleSopContents($file), 'application/pdf', 'pdf');
+
+    if ($expectedError !== null) {
+        expect(fn () => app(SopTextExtractor::class)->extract($document))
+            ->toThrow(AnalysisFailedException::class, $expectedError);
+
+        return;
+    }
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+    expect($extracted->sourceKind)->toBe('pdf');
+    expect($extracted->text)->toContain('グラインダー研削作業');
+    expect($extracted->text)->toContain('保護メガネ');
+})->with([
+    ['AP_オペレーション手順書.pdf', 'テキストを抽出できません'],
+    ['AT_作業手順書.pdf', 'テキストを抽出できません'],
+    ['作業要領書.pdf', 'テキストを抽出できません'],
+    ['AW_作業手順書 (1).pdf', '十分な日本語の本文'],
+    ['AS_作業手順書.pdf', null],
+]);

```

## テスト結果

- `vendor/bin/pest tests/Unit/Manual/SopTextExtractorTest.php`: 24 passed / 0 failed (56 assertions)
  - 新規 12 件は実装前に red を確認済み (12 failed → 24 passed)
- `composer phpstan` (level 10, 747 files): No errors
- `vendor/bin/pint --test`: passed
- `composer test` (全体) / `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm test`: 実行中

## 設計からの逸脱 (実装時に判断したもの)

1. 詳細設計のコード例では `decodeRunAsSjis()` 内で `mb_convert_encoding()` の戻り値を
   `is_string()` で絞っていたが、encoding 名がリテラルのため PHPStan は戻り型を `string` と
   判定し `function.alreadyNarrowedType` エラーになった (PHP 8 では不正 encoding 名は
   ValueError であり false は返らない)。よって冗長な `is_string()` を削除した
   (型の widen ではなく到達不能コードの削除)。
2. `devnotes/20260804-0900-sop-pdf-mojibake/probe/*.php` (設計時の実測スクリプト、main に
   コミット済み) が `vendor/bin/pint --test` を fail させていたため pint で整形した
   (本実装とは無関係の pre-existing failure の解消)。差分には含めていない。
