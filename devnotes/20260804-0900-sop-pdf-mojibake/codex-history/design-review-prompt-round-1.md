# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

# セキュリティ不変条件

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【補足】
- 本件は AI 解析パイプライン入口のバグ修正であり、UI/frontend の変更を含みません (観点 10/11 は非該当)。
- 設計中の数値・挙動はすべて実測で裏取り済みです。実測スクリプトは
  `devnotes/20260804-0900-sop-pdf-mojibake/probe/` にあり読み込み可能です。
- 概念設計は `devnotes/20260804-0900-sop-pdf-mojibake/conceptual-design.md` (別セッションで APPROVED)。

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
| 3 | 例外の文言体系是正（空抽出 = unextractable / 新設 insufficientJapaneseText / 次アクション追記） | `app/Exceptions/Manual/AnalysisFailedException.php` / `app/Services/Manual/SopTextExtractor.php` | High |
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

        $extracted = $this->ensureUtf8($extracted); // JSON 化・UserInput 生成を不正バイトで壊さない
        $repaired = $this->repairSjisMojibake($extracted);
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
        if ($bytes === 0) {
            // 抽出結果が完全に空 = 画像/スキャン手順書。「短すぎる」と混同しない
            throw AnalysisFailedException::unextractable();
        }
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        $ratio = $this->japaneseRatio($text);
        if ($ratio < config()->float('manual.analysis_min_japanese_ratio')) {
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
    private function repairSjisMojibake(string $text): string
    {
        $repaired = preg_replace_callback(
            self::CP1252_RUN_PATTERN,
            fn (array $matches): string => $this->decodeRunAsSjis((string) $matches[0]),
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
    private function decodeRunAsSjis(string $run): string
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
        return $this->japaneseRatio($decoded) >= config()->float('manual.analysis_min_japanese_ratio')
            ? $decoded
            : $run;
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
- [x] `config()->float()` / `config()->integer()` は型付きアクセサ（`mixed` を漏らさない）
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
    // 抽出テキストの実質空判定 (これ未満は「本文が短すぎます」。0 バイトは unextractable)
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
（手順書は詳しいが、文字が画像であるだけ）。0 バイトは `unextractable()` が正しい。

### 変更後コード

```php
    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ・抽出結果が空) */
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
| T3 | `正当な欧文テキストは復元されず日本語不足で拒否される` | 英 / 独(ä ö ü ß) / 仏(é à è) の 3 データセット → `insufficientJapaneseText` 文言（`'十分な日本語の本文'`）で throw |
| T4 | `復元は混在文書の正規日本語を壊さない` | `'非鉄金属はその特性に応じた研削をする。'`（正規）+ `sjisMojibake('作業手順書 ネジを締める 安全確認')` を連結 → **両方の語**が結果に含まれる |
| T5 | `日本語比率が閾値未満のテキストは拒否される（境界: 下側）` | `config()->set('manual.analysis_min_japanese_ratio', 0.10)` + 日本語 9 文字 / ASCII 91 文字（比率 0.09）で 120 バイト以上 → throw |
| T6 | `日本語比率が閾値以上のテキストは通る（境界: 上側）` | 同条件で日本語 11 文字 / ASCII 89 文字（比率 0.11）→ 成功し `sourceKind = 'plain'` |
| T7 | `抽出結果が空の PDF は unextractable（tooShort と弁別）` | `sampleSopContents('AP_オペレーション手順書.pdf')` → `'テキストを抽出できません'` で throw（T8 に含めず、0 バイト → unextractable の弁別意図を単独で残す） |
| T8 | `同梱サンプル SOP 5 本の期待値表`（dataset） | 下表を **deny-by-default の期待値表**として固定 |

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

- [x] ヘルパ関数 `sampleSopPath()` / `sjisMojibake()` は戻り値型 `string` を明示
- [x] `file_get_contents()` の `string|false` は `is_string()` で判定し、失敗時は
      `RuntimeException` を投げる（`(string)` cast で widen しない）
- [x] `mb_convert_encoding()` の `string|false` は `Webmozart\Assert\Assert::string()` で絞る
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Pest.php` のグローバル適用に従う）
- [x] テストデータは `SourceDocument::factory()`（既存 `storedDocument()` ヘルパ）で生成

### リスク

- `doc/reference/sample-sop/` のファイルが移動・削除されるとテストが落ちる。
  これは**望ましい失敗**（回帰コーパスの喪失を検出する）。`sampleSopPath()` が
  パスを明示してメッセージ付きで失敗させる。

---

## 施策 6: ドキュメント追記

### 変更箇所

- `docs/architecture.md` L119 の `Manual/SopTextExtractor` 行

### 変更後

```
| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** + **日本語本文ゲート (analysis_min_japanese_ratio 未満は LLM に渡さない)** + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。抽出 0 バイトは unextractable、0 < bytes < min は tooShort で弁別する |
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


---

## 関連する現行コード

### `app/Services/Manual/SopTextExtractor.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * SOP (SourceDocument) からのテキスト抽出。doc/10 §10.7 (v1: テキスト抽出可能な手順書のみ)。
 *
 * - 分岐はアップロード時に内容 sniff 済みの mime を使う (クライアント拡張子は信頼しない)
 * - 抽出不能/実質空/バイト上限超過は AnalysisFailedException (ユーザー向け文言)
 * - byteLength (strlen = UTF-8 bytes) が token budget 判定値 (config manual.analysis_max_text_bytes)
 */
class SopTextExtractor
{
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
            // parser の内部例外はユーザー向け文言へ正規化 (詳細は report で内部ログのみ)
            report($exception);

            throw AnalysisFailedException::unextractable();
        }

        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
        $text = $this->normalize($text);

        $bytes = strlen($text);
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        return new ExtractedText($text, $bytes, $kind);
    }

    /**
     * mime → 抽出方式。未知 mime はアップロード時 sniff で弾かれている前提だが、
     * 防御的に unextractable で落とす (LLM に渡さない)。
     *
     * @return 'pdf'|'spreadsheet'|'plain'
     */
    private function kindFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel' => 'spreadsheet',
            'text/plain' => 'plain',
            default => throw AnalysisFailedException::unextractable(),
        };
    }

    private function fromPdf(string $contents): string
    {
        return (new PdfParser)->parseContent($contents)->getText();
    }

    /**
     * PhpSpreadsheet はファイルパス入力のため一時ファイル経由で読み込む (finally で削除)。
     * 全シートのセルをタブ/改行結合する。
     */
    private function fromSpreadsheet(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sop-xls-');
        Assert::string($path, '一時ファイルを作成できません');
        try {
            // 書き込み失敗 (ディスクフル等) を IOFactory の後段例外に依存せず明示検出する
            Assert::integer(file_put_contents($path, $contents), '一時ファイルへ書き込めません');
            $spreadsheet = IOFactory::load($path);

            $lines = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $title = $sheet->getTitle();
                if ($title !== '') {
                    $lines[] = $title;
                }
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(true);
                    /** @var Cell $cell */
                    foreach ($cellIterator as $cell) {
                        $value = $cell->getFormattedValue();
                        if (trim($value) !== '') {
                            $cells[] = $value;
                        }
                    }
                    if ($cells !== []) {
                        $lines[] = implode("\t", $cells);
                    }
                }
            }

            return implode("\n", $lines);
        } finally {
            @unlink($path);
        }
    }

    /**
     * UTF-8 妥当性の担保 (旧 XLS の SJIS 系・PDF の壊れた埋め込み対策)。
     * 推測変換で未知バイナリを「日本語らしき無意味文字列」へ化けさせない strict 手順:
     *   1. mb_check_encoding OK → そのまま
     *   2. NG → mb_detect_encoding (UTF-8/SJIS-win/EUC-JP、strict)。判定不能 → unextractable
     *   3. 判定 encoding から mb_convert_encoding → 再検証。不合格 → unextractable
     */
    private function ensureUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $detected = mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP'], true);
        if ($detected === false) {
            throw AnalysisFailedException::unextractable(); // バイナリ扱い (救済変換しない)
        }

        $converted = mb_convert_encoding($text, 'UTF-8', $detected);
        if (! is_string($converted) || ! mb_check_encoding($converted, 'UTF-8')) {
            throw AnalysisFailedException::unextractable();
        }

        return $converted;
    }

    /** 連続空白の圧縮 + trim (LLM 入力バイト数を無駄にしない) */
    private function normalize(string $text): string
    {
        // 行内の連続空白 (タブ含む) を 1 個へ、3 行以上の連続改行を 2 行へ圧縮する
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", str_replace("\r\n", "\n", $text)) ?? $text;

        return trim($text);
    }
}

```
### `app/Exceptions/Manual/AnalysisFailedException.php`

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use RuntimeException;

/**
 * AI 解析の失敗 (ユーザー向けメッセージ付き)。AnalysisPipeline が投げ、
 * catch 経路の failJob がメッセージをそのまま error 列に保存する。
 */
final class AnalysisFailedException extends RuntimeException
{
    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ) */
    public static function unextractable(): self
    {
        return new self('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
    }

    /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
    public static function tooShort(): self
    {
        return new self('手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。');
    }

    /** LLM 入力上限 (UTF-8 バイト) 超過 */
    public static function tooLarge(): self
    {
        return new self('手順書が大きすぎます。分割してアップロードしてください。');
    }

    /** パイプラインの実時間 deadline 超過 / provider の応答が client timeout を超えた */
    public static function timedOut(): self
    {
        return new self(
            '解析が時間内に終わりませんでした。手順書を分割して短くするか、'
            .'しばらく時間をおいて再実行してください。'
        );
    }

    /** provider の混雑 (429 / 529 / 500・502・503・504)。入力を変えても解決しないため待つ以外の行動がない */
    public static function providerBusy(): self
    {
        return new self('AI が混み合っています。しばらく時間をおいて再実行してください。');
    }
}

```
### `app/DataTransferObjects/Manual/Analysis/ExtractedText.php`

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

/**
 * SOP からの抽出テキスト (SopTextExtractor の出力値オブジェクト)。
 * byteLength は strlen (UTF-8 bytes) = token budget 判定値 (config manual.analysis_max_text_bytes)。
 */
final readonly class ExtractedText
{
    public function __construct(
        public string $text,
        public int $byteLength,
        public string $sourceKind, // pdf | spreadsheet | plain (診断用)
    ) {}
}

```
### `tests/Unit/Manual/SopTextExtractorTest.php`

```php
<?php

declare(strict_types=1);

use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use App\Services\Manual\SopTextExtractor;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * SOP テキスト抽出 (施策 7):
 * - plain / xlsx の抽出、UTF-8 strict 検証 (SJIS 変換 / バイナリ拒否)
 * - 実質空 (min_text_bytes 未満) / バイト上限超過の明示エラー
 */

/** 保存済み SourceDocument (Storage::fake 上) を作る */
function storedDocument(string $contents, string $mime, string $ext): SourceDocument
{
    $path = "source-documents/test.{$ext}";
    Storage::put($path, $contents);

    return SourceDocument::factory()->create([
        'file_path' => $path,
        'mime' => $mime,
    ]);
}

test('plain テキストをそのまま抽出する (byteLength = strlen)', function (): void {
    Storage::fake();
    $text = str_repeat("手順1 部品を取り付ける\n", 10);
    $document = storedDocument($text, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->sourceKind)->toBe('plain');
    expect($extracted->text)->toContain('部品を取り付ける');
    expect($extracted->byteLength)->toBe(strlen($extracted->text));
});

test('xlsx から全シートのセルを抽出する', function (): void {
    Storage::fake();
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', '手順');
    $sheet->setCellValue('B1', '急所');
    $sheet->setCellValue('A2', 'ネジを締める作業を行い、工具を正しく持って対象物に当てて回す');
    $sheet->setCellValue('B2', 'トルクは 5Nm を厳守すること (締めすぎるとネジ山が潰れる)');
    $tmp = tempnam(sys_get_temp_dir(), 'sop-xlsx-');
    (new Xlsx($spreadsheet))->save($tmp);
    $document = storedDocument((string) file_get_contents($tmp), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');
    @unlink($tmp);

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->sourceKind)->toBe('spreadsheet');
    expect($extracted->text)->toContain('ネジを締める作業');
    expect($extracted->text)->toContain('5Nm');
});

test('SJIS-win テキストは strict 検出で UTF-8 へ変換される', function (): void {
    Storage::fake();
    $utf8 = str_repeat("手順: ネジを締める。急所: トルクは五ニュートンメートル。\n", 5);
    $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
    expect(is_string($sjis))->toBeTrue();
    $document = storedDocument((string) $sjis, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect(mb_check_encoding($extracted->text, 'UTF-8'))->toBeTrue();
    expect($extracted->text)->toContain('ネジを締める');
});

test('判定不能バイナリは unextractable (推測変換で LLM に渡さない)', function (): void {
    Storage::fake();
    // UTF-8 としても SJIS/EUC としても不正な連続バイト列
    $binary = str_repeat("\xFF\xFE\x80\x81\xFD", 50);
    $document = storedDocument($binary, 'text/plain', 'txt');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
});

test('実質空 (min_text_bytes 未満) は tooShort (画像未対応と別文言)', function (): void {
    Storage::fake();
    $document = storedDocument('短い', 'text/plain', 'txt');

    // 抽出はできたが本文が短すぎるケース。画像/スキャン (unextractable) とは別文言で弁別する
    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
});

test('未知 mime は従来どおり unextractable (テキストを抽出できません)', function (): void {
    Storage::fake();
    $document = storedDocument(str_repeat('内容', 100), 'image/png', 'png');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
});

test('max_text_bytes 超過は tooLarge (分割を促す)', function (): void {
    Storage::fake();
    config()->set('manual.analysis_max_text_bytes', 500);
    $document = storedDocument(str_repeat('長い手順書テキスト。', 100), 'text/plain', 'txt');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '手順書が大きすぎます');
});

test('破損 PDF (パース不能) は unextractable に正規化される', function (): void {
    Storage::fake();
    $document = storedDocument(str_repeat('%PDF-1.4 broken content without objects', 10), 'application/pdf', 'pdf');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class);
});

```

