【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

【あなたの役割】

Laravel 12 + Inertia + Svelte 5 のアプリ AI-CUE の改善実装を、コードレビュアーとしてレビューせよ。

レビュー観点:
1. **設計との一致性**: 詳細設計書の施策 1〜5 が意図どおり実装されているか。逸脱があれば、その逸脱が正当か (前提が実測で崩れていたか) を判定する
2. **正確性**: 走査器 (Svelte AST) の判定分岐に見落ちがないか。fail-closed が破れていないか
3. **PHPStan level 10 適合性** (PHP 側) / TypeScript strict 適合性
4. **DTO / JsonResource / Inertia パターン**の遵守
5. **テスト網羅性**: テストファースト (先に赤) が守られているか。負例・正例が両方向あるか。「母集団が空でも緑」になる経路が無いか
6. **セキュリティ**: 受理判定・認可・テナント境界に変更が及んでいないか
7. **DESIGN.md 準拠**: design token 経由の参照のみ (hex 直書き `#RRGGBB` を増やしていないか)。token 値変更なら `resources/css/tokens.css` と同一 diff で同期しているか
8. **Atomic Design 準拠**: `resources/js/components/` の `atoms/molecules/organisms/features/templates` の責務分離と単方向 import (atoms → molecules → organisms → features/{domain} → templates → pages)。atom は単機能・状態を持たない。アイコンは Lucide を使い SVG 直書きを増やさない

出力形式:
- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書く

---

## 実装の要約 (レビューの前提)

TODO T235 「新規動画作成画面の SOP ファイル入力を受理形式の単一情報源 (AcceptedSourceDocumentTypes) へ揃える」。

施策 1: `AcceptedSourceDocumentTypes::formatsLabel()` を追加し、2 つの FormRequest の `messages()` が複写していた三項演算子を置換。
施策 2: `VideoManualController::create()` の Inertia props に 3 件 (`sourceDocumentAccept` / `imageSourceDocumentsEnabled` / `sourceDocumentFormatsLabel`) を追加。
施策 3: 外部送信の案内を `SourceDocumentUploadNotice.svelte` (新規 features/manual) へ集約。
施策 4: `pages/Manuals/Create.svelte` の直書き accept / help を props 由来へ。
施策 5: file input の accept 供給元目録 (deny-by-default) を新設。

### 実装中に判明した設計の前提の崩れ (重要。ここを重点的に見てほしい)

詳細設計の施策 5 は「`diagnostics` は無条件で違反 (免除の概念は無い)」と定めていた。
しかし実測すると `resources/js/components/atoms/Input.svelte` が `{type}` + `{...rest}` を持ち、
静的には file input になりうる形が **1 件正当に実在した** (走査器の診断 `spread-attribute`)。
無条件違反にすると gate が実装不能になるため、生 HTML (`{@html}`) と同じ
**名指しの免除目録 (deny-by-default + 件数の完全一致) = `UNRESOLVED_FORM_EXEMPTIONS`** で扱う形へ変更した。
未登録の未解決形は依然として違反であり fail-closed は保っている。
この逸脱の妥当性、および「無言で候補から外す経路が本当に無いか」を判定してほしい。

また判定関数の signature は設計の 5 引数 (positional) から
`(scan, policy)` の 2 引数 (policy はオブジェクト) へ変えた (引数の取り違えを型で防ぐため)。

### 検証結果 (全 green)

- `composer test`: 6153 tests / 6151 passed / 2 skipped / 5 risky / 0 failed / 29396 assertions
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: clean
- `pnpm test`: 172 files / 2338 tests passed
- `pnpm build`: OK
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 tests): OK

テストファーストの記録:
- 施策 1/2: 先に赤 (4 件: `formatsLabel()` undefined ×3 / 作成画面 props 不在 ×1) を確認してから実装
- 施策 3: 新規 component テストが import 解決失敗で赤 → 実装で緑
- 施策 4: 4 件赤 (props 由来の accept / 案内 / help 全文一致 / 配置) → 実装で緑
- 施策 5: モジュール不在で赤 → 実装後、さらに **目録を空にして gate が赤くなること** (deny-by-default の実効) を実測で確認し復元

---

## 詳細設計書

# 詳細設計: 新規動画作成画面の SOP ファイル入力を受理形式の単一情報源 (AcceptedSourceDocumentTypes) へ揃える

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計に関わる特記: **禁止事項 8** — 案内文言・accept の変更で入力を禁止しない。
> 受理外形式の拒否はサーバの 422 で行い、ボタンを disabled にしない(現状維持)。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest** テストフレームワーク(`composer test`)。JS は `pnpm test`(vitest)
- **RefreshDatabase** + `--parallel` 並列実行(`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止)
- **テストデータは必ず Factory で生成**(`Model::create()` 手組み禁止)
- 新モデルの追加は無し(Factory 追加も無し)
- **DTO + JsonResource** パターン(本設計は Inertia props のスカラー 3 件のみで、
  新規 DTO は作らない。理由は施策 2 に記述)
- `declare(strict_types=1)` + 日本語コメント
- フロントは Svelte 5 runes + DS token/ramp のみ(`DESIGN.md` が canonical、ds-purity テストが検出)
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages`
  の単方向 import のみ
- **アーリーリターン** 推奨 / **コードフォーマット**: `composer fix`(Pint)/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260820-2348-manuals-create-accepted-source-types/conceptual-design.md`
  (conceptual-review Round 3 で **APPROVED**)
- 前提となる先行設計: `devnotes/20260819-1053-sop-image-ocr-support/detailed-design.md`
  の**施策 10**(UI 文言・アップロード画面案内)。本設計はその適用漏れの回収であり、
  **同施策で確定した方針(単一の情報源 / 一般案内は常時・OCR 固有警告はフラグ true のみ /
  accept 文字列を解析して画像対応可否を判定しない)を変えない**。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 受理形式の人間向けラベルを単一の情報源へ寄せる | `app/Support/Manual/AcceptedSourceDocumentTypes.php` / `app/Http/Requests/Projects/StoreSourceDocumentRequest.php` / `app/Http/Requests/Projects/StoreVideoManualRequest.php` / `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` | 高 |
| 2 | `create()` の Inertia props を単一の情報源へ接続する | `app/Http/Controllers/Projects/VideoManualController.php` / `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` | 高 |
| 3 | 外部送信の案内を共有コンポーネントへ集約する(文言の出現箇所を 1 つにする) | `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte`(新規) / `resources/js/components/features/manual/SourceDocumentUpload.svelte` / `tests/js/components/features/manual/SourceDocumentUpload.test.ts` / `tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts`(新規) | 中 |
| 4 | 作成画面の直書き(accept / help / 案内欠落)を props 由来へ揃える | `resources/js/pages/Manuals/Create.svelte` / `tests/js/pages/ManualsCreate.test.ts` | 高 |
| 5 | 再発防止: file input の accept 供給元目録(deny-by-default) | `tests/js/support/file-input-accept-inventory.ts`(新規。`{@html}` 免除目録も含む) / `tests/js/support/file-input-scan.ts`(新規) / `tests/js/architecture/file-input-accept-source-inventory.test.ts`(新規) / `tests/js/architecture/file-input-scan.test.ts`(新規) / `AGENTS.md` | 低 |

**実装順**: 1 → 2 → 3 → 4 → 5(4 は 1〜3 の成果物に依存する。5 は独立)。

---

## 施策 1: 受理形式の人間向けラベルを単一の情報源へ寄せる

### 変更箇所

- `app/Support/Manual/AcceptedSourceDocumentTypes.php`: `formatsLabel(): string` を追加(末尾)。
- `app/Http/Requests/Projects/StoreSourceDocumentRequest.php` (L53-62 `messages()`):
  三項演算子の直書きを `formatsLabel()` 呼び出しへ置換。
- `app/Http/Requests/Projects/StoreVideoManualRequest.php` (L58-72 `messages()`): 同じ置換。
- `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`: ラベルの両状態 pin と
  **前提の pin**(基底拡張子集合・画像拡張子集合)を追加。

### 波及変更

- TypeScript 型定義: なし(この施策では props を増やさない。props 追加は施策 2)。
- API Resource/DTO: なし。
- テストファイル: `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`(拡張)。
  既存の 422 文言テスト(`tests/Feature/Projects/SourceDocumentUploadOcrTest.php` L76-93)は
  **文言が 1 バイトも変わらない**ため無変更で緑のままであることを確認する(回帰の裏取り)。

### 現行コード

```php
// app/Http/Requests/Projects/StoreSourceDocumentRequest.php
public function messages(): array
{
    $formats = AcceptedSourceDocumentTypes::imagesEnabled()
        ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
        : 'PDF・Excel・テキスト形式';

    return [
        'document.mimes' => "対応していないファイル形式です。{$formats}でアップロードし直してください。",
    ];
}
```

`StoreVideoManualRequest::messages()` も**同じ 3 行を複写**している。

### 変更後コード

```php
// app/Support/Manual/AcceptedSourceDocumentTypes.php (追加)
    /**
     * 受理形式の人間向けラベル(法務確認を経た文面。FormRequest の 422 文言・
     * 作成画面の help 文言が共有する)。
     *
     * **機械導出しない**: 拡張子リストから日本語の文を組み立てる形にすると
     * config を触った副作用で文面が変わりうるため、承認済みの 2 文をそのまま持つ。
     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin(基底拡張子集合・
     * 画像拡張子集合が現在値ちょうど)が検出する。
     */
    public static function formatsLabel(): string
    {
        return self::imagesEnabled()
            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
            : 'PDF・Excel・テキスト形式';
    }
```

```php
// 両 FormRequest の messages() (共通の置換)
public function messages(): array
{
    return [
        'document.mimes' => '対応していないファイル形式です。'
            .AcceptedSourceDocumentTypes::formatsLabel()
            .'でアップロードし直してください。',
    ];
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている(`formatsLabel(): string` / `messages(): array` は既存 phpdoc 維持)
- [x] null 安全(nullable を返さない。`Assert` 不要)
- [x] DTO を返している(該当なし。文字列 1 本で、配列を返す新 API は作らない)
- [x] Generics の型パラメータ(`messages()` の `array<string, string>` phpdoc は既存のまま)

### テスト計画

- [ ] 先に赤くする: `AcceptedSourceDocumentTypes::formatsLabel()` の両フラグ pin を書く
      (メソッド未実装なので fatal で赤)
- [ ] `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` に追加:
      - フラグ false → `'PDF・Excel・テキスト形式'`(**完全一致**)
      - フラグ true → `'PDF・Excel・テキスト形式、または JPEG・PNG の画像'`(**完全一致**)
      - **前提の pin**: `config('manual.source_document_mimes')` が
        `['pdf', 'xlsx', 'xls', 'txt']` と**順序込みの完全一致**であること /
        フラグ true の `extensions()` が
        `['pdf', 'xlsx', 'xls', 'txt', 'jpg', 'jpeg', 'png']` と**順序込みの完全一致**であること。
        **集合の差分ではなく完全一致で書く** (design-review Round 1 Suggestion 対応:
        `acceptAttribute()` は `extensions()` の**順序に依存**して文字列を組むため、
        集合比較では表示順の変更を見逃す)。
        既存テスト(`extensions()` の両フラグ完全一致)がこの pin の土台であり、
        本施策はそこへ「ラベルもこの前提に乗っている」ことを明記する形で足す。
        失敗メッセージに「ラベル(`formatsLabel`)の見直しが必要」と書く
        (何をすべきか分かる形にする)
- [ ] 既存 Feature テスト(HEIC 拒否の 422 文言完全一致)が**無変更で緑**であること
      = 文面が 1 バイトも変わっていないことの裏取り
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認(Unit テストは DB 非依存)

### リスク

- **文面の意図しない変更**: 置換時に句読点・全角半角がずれると法務確認済み文面が壊れる。
  既存の 422 完全一致テストが検出する(このテストを**書き換えない**ことが条件)。
- ラベルを 2 つの FormRequest から参照することで、**片方だけ文面を変える自由が消える**。
  これは意図した効果である(将来 SOP 経路ごとに違う文面が要るという要件が出たら、
  そのときに引数化を検討する。今は要件が無いので作らない)。

---

## 施策 2: `create()` の Inertia props を単一の情報源へ接続する

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php` `create()` (L55-70):
  Inertia props へ 3 件を追加(`sourceDocumentAccept` / `imageSourceDocumentsEnabled` /
  `sourceDocumentFormatsLabel`)。`use` は既存(`AcceptedSourceDocumentTypes` は
  `show()` のために既に import 済み)。
- `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` (L189-232 の
  「公開面の一貫性」テスト): 公開面に**作成画面**を追加。

### 波及変更

- TypeScript 型定義: `resources/js/pages/Manuals/Create.svelte` の `Props` に 3 件追加
  (施策 4 で実施。**同一 PR で必ず揃える** — props を渡して受け側の型を直さないと
  `pnpm typecheck` が落ちる)。
- Inertia Props: **変更あり**(本施策そのもの)。`show()` 側の props 形状は**変えない**
  (T234 で承認済みの契約。`sourceDocumentFormatsLabel` は `show()` には追加しない
  = 詳細画面には形式ラベルを表示する UI が無く、使わない props を配るのは
  「今必要なものだけ作る」に反する)。
- API Resource/DTO: なし。**DTO を作らない理由**: 値は独立したスカラー 3 件で、
  受け側(Svelte Props)も既に平坦な 2 件を持つ既存契約に揃える形である。
  ここで DTO/オブジェクトへ束ねると `show()` 側の承認済み props 形状を
  破壊的に変えることになる(概念設計 conceptual-review Round 2 で確認済みの判断)。
- テストファイル: 上記 Feature テスト(拡張)。`tests/Feature/Projects/VideoManualCrudTest.php`
  L111-118 の create props テストは `->has()` の件数固定を持たないため**無変更で緑**。

### 現行コード

```php
    /** 作成フォーム (カテゴリ選択肢を props で供給。撮影者は 403) */
    public function create(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        return Inertia::render('Manuals/Create', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'categories' => $this->categoryOptions($project),
        ]);
    }
```

### 変更後コード

```php
    /** 作成フォーム (カテゴリ選択肢を props で供給。撮影者は 403) */
    public function create(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        return Inertia::render('Manuals/Create', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'categories' => $this->categoryOptions($project),
            // 作成と同時の SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // StoreVideoManualRequest と同じ AcceptedSourceDocumentTypes を情報源にする
            // = ダイアログに出る形式とサーバが受理する形式が構造的に一致する。
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
            // help 文言用の受理形式ラベル (422 文言と同一の情報源。施策 1)
            'sourceDocumentFormatsLabel' => AcceptedSourceDocumentTypes::formatsLabel(),
        ]);
    }
```

### テスト計画

- [ ] 先に赤くする: 作成画面の props を検証する assert を「公開面の一貫性」テストへ足す
      (props が無いので `assertInertia` が赤になる)
- [ ] 既存テスト `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` の
      「公開面の一貫性: FormRequest / Service / Inertia Props がフラグに応じて同じ集合を表す」を拡張:
      - 両フラグ(`false` / `true`)について `projects.manuals.create` の props を検証
        (`sourceDocumentAccept` / `imageSourceDocumentsEnabled` /
        `sourceDocumentFormatsLabel` の**リテラル完全一致** pin。
        既存の `show()` 側の書き方と同じ形にする)
      - **作成画面と詳細画面の props が同値**であること。
        **対象は両面に存在する 2 件** (`sourceDocumentAccept` /
        `imageSourceDocumentsEnabled`) **だけ**である
        (design-review Round 1 Warning 対応: `sourceDocumentFormatsLabel` は
        詳細画面に存在しないため比較対象に含めない。テスト名とコメントに明記する)。
        リテラル pin だけだと「両面とも同じ間違い」は検出できるが、
        「面ごとに違う」ケースはこの assert が担う
      - 両経路の 422 文言が、現在の中央ラベル (`formatsLabel()`) と
        **同じ出力契約**を満たすこと(**完全一致**で比較する。
        design-review Round 3 Warning 対応: 「結線」という語は構造的な呼び出しの保証に
        読めるため使わない — 呼び出しの有無は保証しない)
- [ ] **`StoreVideoManualRequest` 側の 422 出力契約を独立に固定する**
      (既存の 422 文言テストは**後付けアップロード経路 (`StoreSourceDocumentRequest`) だけ**を
      通っているため、作成と同時のアップロード経路の出力は今どのテストも見ていない):

      > **このテストが保証する範囲 (誇張しない。design-review Round 2 Warning 対応)**:
      > 固定できるのは**両エンドポイントの 422 出力契約**である。
      > **「`formatsLabel()` を実際に呼んでいること」は保証しない** —
      > 置換前の三項演算子を残しても両フラグで同じ文言を返すため、このテストは緑になる。
      > 中央メソッドへの構造的な結線は**コードレビューで確認する**
      > (2 ファイルのためだけに参照の有無を見る Architecture テストは作らない。
      > 完全修飾名の解決を伴う走査器を新設するコストが、得られる保証に見合わない)。
      > 逆に**文面が経路ごとにずれたら**このテストが検出する (それが本来の目的)。
      - `POST projects.manuals.store` に**有効な `title`** と非対応形式のファイルを送り、
        `document.mimes` だけを発火させる
      - フラグ **false**: jpeg を送って `document` の 422 文言が
        `'対応していないファイル形式です。'.AcceptedSourceDocumentTypes::formatsLabel().'でアップロードし直してください。'`
        と**完全一致**すること
      - フラグ **true**: heic を送って同じ形で**完全一致**すること
      - 期待文は**リテラルを書かず `formatsLabel()` から組み立てる**
        (経路ごとの文面のずれを検出するのが目的。文面そのものの pin は施策 1 の Unit テスト)
- [ ] `assertInertia` の対象 URL は `route('projects.manuals.create', [$project])` を使う
      (既存テストの書式に合わせる)
- [ ] 撮影者(project_member)が 403 のままであること(既存
      `VideoManualCrudTest` が担保。props 追加で認可が変わらないことの確認)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている(`create(): Response` は既存のまま)
- [x] null 安全(3 件はいずれも non-nullable。`Assert` 不要)
- [x] DTO を返している(Inertia props。既存 `show()` と同じ形。上記「DTO を作らない理由」参照)
- [x] Generics: 該当なし

### リスク

- **props 追加でページの payload が増える**(3 件の短いスカラー)。history 暗号化・
  no-store baseline に影響しない(ドメイン規約 3 の 3 枚セットは props の件数に依存しない)。
- **`show()` に `sourceDocumentFormatsLabel` を足さない**非対称を残す。
  使わない props を配らない判断だが、将来詳細画面に形式ラベルを出すなら足す
  (そのときは「公開面の一貫性」テストに 1 行足す = 漏れがレビューに見える)。

---

## 施策 3: 外部送信の案内を共有コンポーネントへ集約する

### 変更箇所

- **新規** `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte`:
  外部送信の一般案内(常時)と OCR 固有警告(フラグ true のみ)を描画する。
  props は `imageSourceDocumentsEnabled: boolean` の 1 件のみ。
- `resources/js/components/features/manual/SourceDocumentUpload.svelte` (L43-54):
  直書きの `<p>` 2 つを `<SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />` へ置換。
- `tests/js/components/features/manual/SourceDocumentUpload.test.ts`: 表示順の固定を追加。
- **新規** `tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts`。

### 設計判断: なぜ文言を複写せず切り出すか

作成画面にも同じ開示が要る(概念設計 (B))。**複写すると法務確認済みの文が
片方だけ更新される事故が起きうる**ため、文言の物理的な出現箇所を 1 つに保つ。
機能追加ではなく**重複の予防**である。

- 階層: `features/manual` 内の同 domain 参照(`SourceDocumentUpload` → `SourceDocumentUploadNotice`)と
  pages → features(`Manuals/Create` → `SourceDocumentUploadNotice`)。
  どちらも `tests/js/architecture/atomic-import-graph.test.ts` の規則 4 / 6 に適合する
  (features は同 domain の features を import 可。pages からの参照は逆方向ではない)。
- **testid・文言・並び順は現状のまま**(`source-document-send-notice` /
  `source-document-image-notice`。案内 → file input の順)。
  詳細画面は T234 で承認済みの面であり、本施策は内部移動に留める。
- DS: 既存の class(`text-caption text-text-secondary`)をそのまま移す。
  新しい色・角丸・タイポを導入しないので `DESIGN.md` / `tokens.css` の変更は無い
  (ds-purity テストの対象 class は不変)。

### 波及変更

- TypeScript 型定義: 新規コンポーネントの `Props`(`imageSourceDocumentsEnabled: boolean`)。
- API Resource/DTO: なし。
- テストファイル: 上記 2 件。

### 現行コード

```svelte
<!-- SourceDocumentUpload.svelte (抜粋) -->
<form novalidate onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
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
    <FormField ...>
```

### 変更後コード

```svelte
<!-- 新規: resources/js/components/features/manual/SourceDocumentUploadNotice.svelte -->
<script lang="ts">
    /**
     * SOP アップロードの外部送信案内。**文言の唯一の出現箇所**(法務確認を経た文面。
     * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
     * component 1 つへ集約している)。
     *
     * 一般案内はフラグの真偽に関わらず常時表示する (テキスト・Excel・通常 PDF にも
     * 等しく当てはまる事実のため)。OCR 固有警告だけを imageSourceDocumentsEnabled で
     * 出し分ける (画像・スキャン SOP の OCR 対応の方針)。
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

```svelte
<!-- SourceDocumentUpload.svelte (置換後の抜粋) -->
<script lang="ts">
    import SourceDocumentUploadNotice from "@/components/features/manual/SourceDocumentUploadNotice.svelte";
    // (既存の import・props・handler は不変)
</script>

<form novalidate onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
    <SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />
    <FormField ...>
```

> **注**: 案内は `<p>` 2 つを**同じ flex 列の直下**に置く形を維持する
> (`form` の `flex flex-col gap-3` が両方に効く)。component が fragment として
> 2 要素を返すため、レイアウトは現状と同じになる(余計な wrapper `div` を作らない
> = `gap` の見た目が変わらない)。

### テスト計画

- [ ] 先に赤くする: 新規 component テスト(`SourceDocumentUploadNotice.test.ts`)を先に書く
      (ファイルが無いので import で赤)
- [ ] `SourceDocumentUploadNotice.test.ts`:
      - `imageSourceDocumentsEnabled=false`: 一般案内が表示され OCR 固有警告は無い
      - `imageSourceDocumentsEnabled=true`: 両方表示され、OCR 固有警告に
        「1 手順書につき 1 枚」を含む
      - 文言の完全一致(部分一致 assert では文面の劣化を見逃すため、
        一般案内・OCR 固有警告のいずれも**文全体**で比較する)。
        **比較前に DOM の空白を正規化する** (design-review Round 1 対応:
        Svelte ソースの改行・インデントが textContent に空白として残るため、
        連続空白を 1 つに畳んで trim した文字列同士で比較する。
        この正規化は 1 か所の helper に置き、両テストが共有する)
- [ ] `SourceDocumentUpload.test.ts`(既存を**拡張のみ**。既存 assert を消さない):
      - 既存 2 ケース(accept / 案内の出し分け)が緑のままであること
      - **表示順**: `source-document-send-notice` が `source-document-input` より
        DOM 順で前にあること(`compareDocumentPosition` か
        `container.querySelectorAll` の順序で判定)
      - **親子構造**(design-review Round 1 Warning 対応。順序だけでは wrapper の
        追加を検出できず、`gap` の適用単位が変わる後退を見逃す):
        `source-document-send-notice` と `source-document-image-notice` の
        `parentElement` が `source-document-upload`(= `form`)であること
- [ ] 個別の `DatabaseTransactions`: 該当なし(JS レーン)

### リスク

- **wrapper 要素を足すとレイアウトが変わる**(gap の効き方)。上記の注のとおり
  fragment のまま返す。Browser レーンの視覚的回帰テストは持たないので、
  DOM 構造(親が `form` 直下であること)を component テストで確認する。
- 共有化により、**詳細画面の文言変更が作成画面へも波及する**。これは意図した効果である。

---

## 施策 4: 作成画面の直書きを props 由来へ揃える

### 変更箇所

- `resources/js/pages/Manuals/Create.svelte`:
  - `Props` に 3 件追加(`sourceDocumentAccept` / `imageSourceDocumentsEnabled` /
    `sourceDocumentFormatsLabel`)
  - L98 付近の `help="PDF / Excel / テキスト。…"` を props 由来の文へ差し替え
  - L104 付近の `accept=".pdf,.xlsx,.xls,.txt"` を `accept={sourceDocumentAccept}` へ差し替え
  - `SourceDocumentUploadNotice` を **file input の前**に設置(施策 3)
- `tests/js/pages/ManualsCreate.test.ts`: 直書き pin をやめ、props の 2 状態で固定。

### 波及変更

- TypeScript 型定義: 上記 `Props` の 3 件(施策 2 の Inertia props と 1:1)。
- Inertia Props: 施策 2 で追加済み(この施策は受け側)。
- API Resource/DTO: なし。
- テストファイル: `tests/js/pages/ManualsCreate.test.ts`(既存 6 ケースの `baseProps` に
  3 件を足し、accept のケースを両状態へ分ける)。

### 現行コード

```svelte
    interface Props {
        project: { id: number; name: string };
        categories: CategoryOption[];
    }

    let { project, categories }: Props = $props();
```

```svelte
                    <FormField
                        label="手順書 (SOP・任意)"
                        id="manual-document"
                        error={form.errors.document}
                        help="PDF / Excel / テキスト。アップロードすると AI 解析でシナリオを生成できます。"
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <input
                                {id}
                                type="file"
                                accept=".pdf,.xlsx,.xls,.txt"
                                onchange={onFileChange}
                                ...
```

### 変更後コード

```svelte
    /**
     * 動画マニュアル作成 (タイトル + カテゴリ + 任意の手順書アップロード)。
     * カテゴリの入力名は保護キー category_id と別名の `category` (id 値)。
     * 空選択 = 未分類 (null で送信)。document は multipart で任意送信。
     *
     * 受理形式・画像対応の出し分けは `AcceptedSourceDocumentTypes` をサーバ側の
     * 単一の情報源として渡された Props に従う (フロント側で accept 文字列を解析して
     * 画像対応可否を判定しない)。
     */
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
                    <FormField
                        label="手順書 (SOP・任意)"
                        id="manual-document"
                        error={form.errors.document}
                        help={`${sourceDocumentFormatsLabel}。アップロードすると AI 解析でシナリオを生成できます。`}
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <input
                                {id}
                                type="file"
                                accept={sourceDocumentAccept}
                                onchange={onFileChange}
                                aria-describedby={describedBy}
                                aria-invalid={invalid}
                                class="block w-full text-body text-text file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-caption file:text-text"
                                data-testid="manual-document-input"
                            />
                        {/snippet}
                    </FormField>
```

> **新しい文言を作っていないことの確認**: help の後半
> 「アップロードすると AI 解析でシナリオを生成できます。」は**現行 Create.svelte の文をそのまま**
> 使う。前半の形式列挙は施策 1 のラベル(422 文言と同一の法務確認済み文面)に置き換わるだけで、
> 新規の文は 1 つも書かない。
> なお help の前半は「PDF / Excel / テキスト」(スラッシュ区切り)から
> 「PDF・Excel・テキスト形式」(中黒)へ**表記が変わる**。これは 422 文言との統一が目的であり、
> 意図した変更である(単一の情報源へ寄せた結果)。

> **案内の配置**: `SourceDocumentUploadNotice` は `FormField`(= file input)の**直前**に置く。
> ファイルを選ぶ前に外部送信の事実が見えている必要がある(conceptual-review Round 1 Warning)。

### テスト計画

- [ ] 先に赤くする: `tests/js/pages/ManualsCreate.test.ts` の accept ケースを
      「props で渡した値がそのまま accept になる」形へ書き換える
      (現状は直書きなので、props に画像込みの値を渡したケースが赤になる)
- [ ] `baseProps` に 3 件を追加(`sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt"` /
      `imageSourceDocumentsEnabled: false` /
      `sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式"`)
- [ ] ケース A(フラグ false 相当): accept が props と一致 /
      一般案内が表示される / OCR 固有警告が**無い** / help に
      「PDF・Excel・テキスト形式」が出る
- [ ] ケース B(フラグ true 相当): accept が `.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png` /
      OCR 固有警告が表示される / help に「JPEG・PNG の画像」が出る
- [ ] **help は全文一致で 1 ケース固定する**(design-review Round 1 Suggestion 対応。
      ラベルの部分一致だけでは後半の文「アップロードすると AI 解析でシナリオを
      生成できます。」と句点の維持を固定できない):
      `'PDF・Excel・テキスト形式。アップロードすると AI 解析でシナリオを生成できます。'`
      と空白正規化後に完全一致すること
- [ ] 表示順・親子構造: 一般案内が `manual-document-input` より DOM 順で前にあり、
      かつ作成 `form` の直下にあること(施策 3 と同じ判定方法を使う)
- [ ] 既存 5 ケース(見出し・カテゴリ選択・submit が disabled でない・カテゴリ 0 件・
      title の clearErrors 2 件)は**変更しない**(props 追加のみで緑を維持)
- [ ] `pnpm typecheck` が緑

> **保証の分担を正確に書く**(design-review Round 1 Suggestion 対応。
> 「typecheck が props 名の一致を保証する」は誤りである):
>
> | 層 | 保証する内容 |
> |---|---|
> | Feature テスト(施策 2) | Controller が**正しい名前と値**の props を返す |
> | component / page テスト(施策 3/4) | 渡された props を表示と `accept` へ**正しく使う** |
> | `pnpm typecheck` | Svelte 内とテスト呼び出し側の**型整合性**(必須 props の欠落を検出) |
>
> PHP 側の props 名と Svelte の `Props` の突き合わせを機械で行う仕組みは無い
> (だから施策 2 の Feature テストで props 名を明示的に検証する)。

### PHPStan 適合チェック

- 該当なし(フロントのみ)。PHP 側は施策 1/2 で担保。

### リスク

- **props が未指定のときの挙動**: Inertia 経由なら常に 3 件が来る(施策 2 で必ず渡す)。
  ただし component テストで props を渡し忘れると `accept` が `undefined` になり
  「全形式が選べる」状態になる。テストの `baseProps` に必ず含めることで防ぐ
  (`pnpm typecheck` も必須 props の欠落を検出する)。
- **help の表記が変わる**(スラッシュ区切り → 中黒)。UI 文言の変更だが、
  422 文言との統一が目的であり、法務確認済み文面の側へ寄せる方向である。
- accept 属性は**検証ではない**。受理判定は `StoreVideoManualRequest` +
  内容 sniff のままで、本施策はそこを 1 行も触らない。

---
## 施策 5: 再発防止 — file input の accept 供給元目録(deny-by-default)

### 目的と責務(誇張しない)

今回の漏れは「新しい面を足したときに単一の情報源へ繋ぐのを忘れても誰も赤くならない」
ために起きた。この gate が止めるのは **「accept の供給元宣言の漏れ」だけ**である。

**軸を 2 つに分ける**(design-review Round 1 Critical 対応。当初案は「実測できる構文」と
「レビューでの宣言」を 1 つの区分に混ぜており、実コードと矛盾していた):

| 軸 | 値 | 誰が決めるか |
|---|---|---|
| **実測構文** (`syntax`) | `static-text` / `expression` | **走査器が AST から実測する** |
| **供給元の宣言** (`supply`) | `server-prop` / `client-owned` | **人がレビューで宣言する**(理由必須) |

- `syntax` は機械が確かめられる事実である(値が単一の静的テキストか、式を含むか)。
- `supply` は**設計意図の宣言であって、由来の証明ではない**。
  `server-prop` と書いてあっても、gate はその識別子がサーバの
  `AcceptedSourceDocumentTypes` 由来であることを検証しない。

> **保証しないこと**: `accept={sourceDocumentAccept}` という形を見ても、その識別子の値が
> `AcceptedSourceDocumentTypes` 由来であることは**証明できない**(Inertia props は実行時に
> 注入されるため静的検査の到達範囲外。同名の別の値を入れても静的層は黙る)。
> 単一の情報源との一致は**施策 2 の Feature テストと施策 3/4 の component テスト**が担う。
> また `.svelte` 以外(TS から `document.createElement('input')` する経路)・
> Blade テンプレート・実行時に `accept` を書き換える形・`occurrence` の並べ替えの意味には
> **無言で効かない**(並べ替えは赤くなるので安全側に倒れるが、意味の追跡はしない)。
> **`{@html …}` に渡される文字列の中身は解析しない** — 免除目録の登録は
> 「そこに file input を作らない」という**人の宣言**であり、gate が中身を確かめた結果ではない。
> **静的に `input` 以外と確定できる `<svelte:element this="div">` は対象外**にする
> (確定できる非 input を診断にすると誤検出になる)。
> `.svelte` 内で native input を作りうる形のうち、**動的な要素名と生 HTML は
> 保証外へ追い出さず診断で止める**(design-review Round 3 Critical 対応。
> 保証範囲の中で解決できない形を無言で候補から外さない = 共通規約 (b))。

### 変更箇所

- **新規** `tests/js/support/file-input-scan.ts`: 走査器。
  `svelte/compiler` の `parse(source, { modern: true })` で AST を取り、
  native `input` 要素を全数集めて分類する。
- **新規** `tests/js/support/file-input-accept-inventory.ts`: 目録(deny-by-default)。
  `{@html …}` の**名指し免除目録**(`RAW_HTML_EXEMPTIONS` + 件数 pin)も同じファイルに置く。
  **判定の純関数** `evaluateFileInputInventory()` もここに置く(gate から分離して
  自己検査可能にする。design-review Round 1 Warning 対応)。
- **新規** `tests/js/architecture/file-input-accept-source-inventory.test.ts`: gate(実リポジトリを走査)。
- **新規** `tests/js/architecture/file-input-scan.test.ts`:
  走査器と判定関数の自己検査(負例・正例。合成入力のみで実ファイルに依存しない)。
- `AGENTS.md` ドメイン固有規約へ 1 項追加(新しいアップロード面を足す人が
  目録を更新する義務を書く。**保証しないものの正本は走査器の docblock**とし、
  AGENTS.md には写さない = 2 か所に書くと必ず食い違う、の既存方針に従う)。

### 母集団の取り方(fail-closed)

走査対象は `resources/js` 配下の **`.svelte` 全ファイル**で、
母集団は **native `input` を作りうる形の全数**である。
AST 上の形と扱いは次のとおり(**svelte 5.56 で実測して確認した形**を根拠にする)。

まず**要素の側**(design-review Round 3 Critical 対応。`.svelte` の中で native input を
作れるのに `RegularElement` として現れない形が 2 つあり、それを無言で候補から外すと
共通規約 (b) 違反になる):

| 要素の AST 上の形 | 実例 | 扱い |
|---|---|---|
| `RegularElement` / `name === 'input'` | `<input …>` | 母集団に入る(下表の `type` 判定へ) |
| `RegularElement` / `name !== 'input'` | `<div>` | 対象外 |
| `SvelteElement` / `tag.type === 'Literal'` かつ値が `input`(**大文字小文字を無視**) | `<svelte:element this="input" …>` | 母集団に入る(`input` と同じ判定を通す) |
| `SvelteElement` / `tag.type === 'Literal'` かつ値が `input` 以外 | `<svelte:element this="div">` | 対象外(静的に非 input と確定できる) |
| `SvelteElement` / `tag` が `Literal` 以外(識別子・式) | `<svelte:element this={tag} />` | **診断 `unresolved-native-element`**(実行時に `input` になりうる) |
| `HtmlTag`(生 HTML の描画) | `{@html markup}` | **生 HTML の実測**として `file` + `occurrence` で記録し、**名指しの免除目録**と両方向で突き合わせる(下記。診断とは別の集合にする) |
| `SvelteComponent` / 通常の component | `<svelte:component this={C} />` / `<Foo />` | 対象外(native input ではない。component 自身の `.svelte` は別途走査される) |

`{@html}` の免除目録(deny-by-default):

- 現在の実在は **1 件**(`resources/js/pages/Settings/Security.svelte` の `{@html qrSvg}`
  = 2FA のサーバ生成 QR コード SVG を描画する箇所)。
- 免除の鍵は **`file` + `occurrence`**(ファイル内の `{@html}` の 1 始まりの序数)である
  (design-review Round 4 Critical 対応: ファイル名だけを鍵にすると、
  **免除済みファイルに 2 件目の `{@html}` を足しても同じ免除に一致してしまい**、
  件数 pin も 1 のままで検出できない)。
- 免除は `RAW_HTML_EXEMPTIONS`(`file` + `occurrence` + 30 文字以上の理由)へ登録し、
  **実測件数・免除配列長・一意キー数の 3 つを件数 pin と完全一致**させる。
- **免除は「そこに file input を作らない」という人の宣言**であり、
  gate は `{@html}` に渡される文字列の中身を解析しない(保証範囲を誇張しない)。
- 目録に無い実測は違反、実測に無い登録(残置)も違反(**両方向**)。
  `occurrence` の正整数・一意性も検査する。

次に**`type` 属性の側**:

| `type` 属性の AST 上の形 | 実例 | 扱い |
|---|---|---|
| 属性が無く `SpreadAttribute` も無い | `<input />` | 対象外(HTML 既定は text) |
| `Attribute` / value = `[Text]` で file 以外 | `type="text"` | 対象外 |
| `Attribute` / value = `[Text]` が `file`(**ASCII 大文字小文字を無視**) | `type="file"` / `type="FILE"` | 母集団に入る |
| `Attribute` / value = `ExpressionTag` | `type={k}` / `type={"file"}` | **診断 `unresolved-type`**(「非 file」と決めつけない) |
| `Attribute` / value = `true`(短縮の真偽属性) | `<input type />` | **診断 `unresolved-type`** |
| `Attribute` / value = 複数パート | `type="fi{x}le"` | **診断 `unresolved-type`** |
| 同一要素に `SpreadAttribute` がある | `{...attrs}` | **診断 `spread-attribute`**(`type` / `accept` を上書きできる) |
| ファイルの parse が失敗 | 構文エラー | **診断 `parse-failed`**(ファイル単位) |

母集団に入った file input の `accept` の実測:

| `accept` 属性の AST 上の形 | 実例 | `syntax` |
|---|---|---|
| `Attribute` / value = `[Text]` | `accept="image/*"` | `static-text`(literal 値も記録する) |
| `Attribute` / value = `ExpressionTag` | `accept={sourceDocumentAccept}` / `{accept}`(短縮) / `accept={a ? "x" : "y"}` | `expression` |
| `Attribute` / value = 複数パート | `accept="a{b}c"` | `expression` |
| `Attribute` / value = `true` | `<input type="file" accept />` | **診断 `unresolved-accept`** |
| `accept` 属性が無い | — | **診断 `missing-accept`** |

> `{accept}` の短縮記法は AST 上 `Attribute` + `ExpressionTag` になる(実測済み)。
> 実コード(`CaptureFileFallback.svelte`)がこの形を使っているため、
> **`expression` として受理する正例を自己検査に必ず置く**
> (design-review Round 1 Critical 対応)。

### 走査結果の型(診断を分離する)

`occurrence`(序数)は**「静的に `file` と確定し、`accept` が実測できた file input」に対してのみ**
定義する。診断側は序数を持たず、ファイル名と AST 位置(行・列)で報告する
(design-review Round 1 Critical 対応: parse 失敗や動的 type では序数が定義できない)。

```ts
// tests/js/support/file-input-scan.ts (骨子)
/** 実測できた file input の 1 件。occurrence はファイル内の 1 始まりの序数。 */
export interface FileInputRecord {
    readonly file: string;       // resources/js からの相対パス
    readonly occurrence: number; // 1 始まり (静的に file と確定した input の出現順)
    readonly syntax: "static-text" | "expression";
    readonly literal: string | null; // static-text のときだけ値、expression は null
}

export type ScanDiagnosticReason =
    | "parse-failed"              // ファイル単位
    | "unresolved-type"           // 式・真偽短縮・複数パート
    | "spread-attribute"          // type/accept を上書きしうる
    | "missing-accept"
    | "unresolved-accept"
    | "unresolved-native-element"; // <svelte:element this={tag}> (実行時に input になりうる)

/**
 * 生 HTML の描画 (`{@html …}`) の実測 1 件。**診断とは別の集合**にする
 * (design-review Round 4 Warning 対応: 診断は無条件で違反、
 * 生 HTML は免除目録と両方向で突き合わせる = 判定の順序が説明と一致する)。
 */
export interface RawHtmlRecord {
    readonly file: string;
    readonly occurrence: number; // ファイル内の {@html} の 1 始まりの序数
    readonly at: { line: number; column: number };
}

export interface ScanDiagnostic {
    readonly file: string;
    readonly reason: ScanDiagnosticReason;
    /** parse-failed 以外は AST 位置。parse-failed は null。 */
    readonly at: { line: number; column: number } | null;
    readonly detail: string;
}

export interface FileInputScanResult {
    readonly svelteFileCount: number;   // 走査根が生きていることの確認用
    readonly nativeInputCount: number;  // 母集団非空 その 1 (input 要素の全数)
    readonly fileInputs: readonly FileInputRecord[]; // 母集団非空 その 2
    readonly diagnostics: readonly ScanDiagnostic[]; // **無条件で違反**になるもの
    readonly rawHtml: readonly RawHtmlRecord[];      // 免除目録と両方向で突き合わせるもの
}
```

### 目録と判定関数

```ts
// tests/js/support/file-input-accept-inventory.ts (骨子)
/** 供給元の宣言。**人が宣言する設計意図**であり、gate は由来を検証しない。 */
export type AcceptSupply = "server-prop" | "client-owned";

export interface FileInputAcceptEntry {
    readonly file: string;
    readonly occurrence: number;                     // 正の整数
    readonly syntax: "static-text" | "expression";   // 実測と一致していなければ赤
    readonly supply: AcceptSupply;
    readonly rationale: string;                      // **全エントリ 30 文字以上**
}

export const FILE_INPUT_ACCEPT_INVENTORY: readonly FileInputAcceptEntry[] = [ /* 下表の 4 件 */ ];

/** 件数の pin。実測件数・目録配列長・一意キー数の 3 つと一致させる。 */
export const FILE_INPUT_COUNT = 4;

/** `{@html …}` を持つことを許すファイルの名指し目録 (deny-by-default)。 */
export interface RawHtmlExemption {
    readonly file: string;
    readonly occurrence: number; // ファイル内の {@html} の 1 始まりの序数 (正の整数)
    readonly rationale: string;  // 30 文字以上
}
export const RAW_HTML_EXEMPTIONS: readonly RawHtmlExemption[] = [
    {
        file: "pages/Settings/Security.svelte",
        occurrence: 1,
        rationale: "2FA の QR コードはサーバが生成した SVG をそのまま描画する箇所で、ファイル入力を作らない",
    },
];
/** 免除の件数の pin (増減のどちらでも赤くする)。 */
export const RAW_HTML_EXEMPTION_COUNT = 1;

/**
 * gate の判定本体 (純関数。合成入力で自己検査できるよう gate から分離する)。
 *
 * **判定はすべてこの 1 関数へ集約する** (design-review Round 2 Warning 対応:
 * 母集団非空と diagnostics を gate 側の assert に散らすと、その分岐に負例が付かず、
 * 「走査器は診断を集めたのに gate が無視する」実装ミスを自己検査できない
 * = 共通規約 (d) の裏取りが弱くなる)。
 */
export function evaluateFileInputInventory(
    scan: FileInputScanResult,
    inventory: readonly FileInputAcceptEntry[],
    countPin: number,
    rawHtmlExemptions: readonly RawHtmlExemption[],
    rawHtmlExemptionCountPin: number,
): readonly string[]; // 違反の説明文の配列。空 = 適合
```

### 目録の初期状態(現在値ちょうど。実測に合わせて訂正済み)

| ファイル | 実測 `syntax` | 宣言 `supply` | 理由(30 文字以上) |
|---|---|---|---|
| `components/features/manual/SourceDocumentUpload.svelte` | `expression` | `server-prop` | SOP の受理形式はサーバの `AcceptedSourceDocumentTypes` が単一の情報源で、Inertia props 経由で受け取る |
| `pages/Manuals/Create.svelte` | `expression` | `server-prop` | 作成と同時の SOP アップロードも同じ単一の情報源から props で受け取る(本設計の施策 4) |
| `components/features/capture/CaptureFileFallback.svelte` | `expression` | `client-owned` | 撮影テイクの入力は静止画 `image/*` と動画 `video/*` の 2 択で、SOP の受理形式とは別概念のためクライアント側で決める |
| `components/features/manual/TakeFileUpload.svelte` | `expression` | `client-owned` | テイクの後付けアップロードも静止画・動画の 2 択で、サーバの SOP 受理形式とは無関係のためクライアント側で決める |

> **現在 4 件すべてが `expression`** である(`TakeFileUpload` は
> `accept={isStill ? "image/*" : "video/*"}`、`CaptureFileFallback` は `{accept}` 短縮記法。
> どちらも AST 上は `ExpressionTag` = `expression`)。
> `static-text` は現在 0 件だが、**区分値としては必要**である
> (`accept="image/*"` と直書きする面が将来増えたときに `expression` から
> `static-text` へ変わって赤くなり、供給元の宣言を見直す契機になる)。
> 現在 0 件の区分の分類が正しく動くことは自己検査の合成入力が担保する
> (「母集団が空でも緑」にはならない — gate 側の母集団は file input 全数であり、
> こちらは 4 件で非空)。

### gate の検査項目(実リポジトリ走査)

gate 本体がすることは **2 つだけ**である
(design-review Round 2 Warning 対応。判定の一本化):

1. 実リポジトリを走査する(`scanFileInputs('resources/js')`)
2. 次の**5 引数**で判定関数を呼び、戻り値が**空配列であること**を assert する
   (違反の説明文をそのまま失敗メッセージに出す。
   design-review Round 4 Warning 対応で引数を signature に揃えた):

   ```ts
   evaluateFileInputInventory(
       scan,
       FILE_INPUT_ACCEPT_INVENTORY,
       FILE_INPUT_COUNT,
       RAW_HTML_EXEMPTIONS,
       RAW_HTML_EXEMPTION_COUNT,
   )
   ```

`evaluateFileInputInventory()` が返す違反の種類(**母集団と診断もここに含める**):

- **走査が空振りしている**: `svelteFileCount` が 0
- **母集団が空 その 1**: `nativeInputCount` が 0
- **母集団が空 その 2**: `fileInputs` が 0 件(その 1 と**別の違反として**返す)
- **診断が 1 件以上ある**: `diagnostics` は**全件そのまま違反へ写す**
  (未解決を 1 つも許さない = fail-closed。理由と位置を失敗メッセージに載せる)。
  `diagnostics` に免除の概念は無い(**無条件で違反**)
- 目録に無い実測(**未登録**)/ 実測に無い目録(**残置**)
- `syntax` の不一致(宣言と実測が違う)
- **`supply` と `syntax` の整合**: `server-prop` は `syntax === "expression"` のときだけ許す
  (design-review Round 2 Suggestion。静的テキストの `accept` を
  「サーバから来る」と宣言している矛盾を潰す。**これは由来の証明ではない** —
  `expression` であることしか確かめておらず、`server-prop` の意味は宣言のままである)
- 目録キー(`file` + `occurrence`)の**重複**
- `occurrence` が正の整数でない
- `rationale` が 30 文字未満(**`supply` の値に関わらず全エントリ**。
  design-review Round 1 Warning 対応: `client-owned` だけを検査すると
  `server-prop` を空理由で通せてしまう)
- 件数 pin の不一致(実測件数 / 目録配列長 / 一意キー数の**3 つとも** `countPin` と一致)
- **生 HTML (`rawHtml`) と免除目録の突き合わせ**(design-review Round 3 Critical /
  Round 4 Critical 対応)。判定の順序を明確にする:
  1. `diagnostics` は**無条件で違反**(`opaque-html` という診断は持たない)
  2. `rawHtml` は**実測集合**として免除目録と `file` + `occurrence` で**両方向**比較する
  3. 免除に一致した実測**だけ**が違反にならない

  違反になるのは: 未登録の実測 / 実測に無い登録(残置)/
  免除の `rationale` が 30 文字未満 / 免除の `occurrence` が正整数でない /
  免除キーの重複 / **実測件数・免除配列長・一意キー数のいずれかが
  `rawHtmlExemptionCountPin` と不一致**

### 検出力の裏取り(共通規約 (c)(d)。合成入力による自己検査)

`tests/js/architecture/file-input-scan.test.ts` に**2 種類**の自己検査を置く。

**(A) 走査器の負例・正例**(`parse` へ渡す合成ソース文字列):

負例(診断になること):

1. `<input type="file" accept="x" {...attrs} />` → `spread-attribute`
2. `<input type={kind} />` → `unresolved-type`
3. `<input type />` → `unresolved-type`
4. `<input type="file" />` → `missing-accept`
5. `<input type={"file"} accept="x" />` → `unresolved-type`(式は評価しない)
6. `<input {...attrs} />` → `spread-attribute`
7. `<input type="file" accept />` → `unresolved-accept`
8. 壊れた Svelte 構文(閉じない tag) → `parse-failed`(**ファイル単位**の診断で、
   `occurrence` を持たないこと自体も assert する)

正例(誤検出しないこと):

9. `<input type="text" />` / `<input />` → 母集団に入らない(`fileInputs` は 0 件、
   `nativeInputCount` は数える)
10. `<input type="file" accept={x} />` → `expression`
11. `<input type="file" {accept} />`(短縮記法。**実コードで使用中**) → `expression`
12. `<input type="file" accept={a ? "x" : "y"} />`(**実コードで使用中**) → `expression`
13. `<input type="FILE" accept="image/*" />` → `static-text` / literal `image/*`
    (大文字も file 扱い)
14. `<input type="file" accept="a{b}c" />`(複数パート) → `expression`
15. 同一ファイルに file input が 2 つ → `occurrence` が 1, 2 の順で付くこと

**要素レベルの負例・正例**(design-review Round 3 Critical 対応。
`svelte/compiler` で実測した形: `<svelte:element>` は `SvelteElement` で
`tag.type` が `Identifier`(動的)または `Literal`(静的)、`{@html …}` は `HtmlTag`):

16. `<svelte:element this={tag} />` → 診断 `unresolved-native-element`(負例)
17. `{@html markup}` → **`rawHtml` に 1 件**記録される(`occurrence` が 1。
    診断ではないこと自体も assert する)
18. `<svelte:element this="input" type="file" accept={x} />` →
    file input として数え、`syntax` は `expression`(正例)
19. `<svelte:element this="INPUT" type="file" accept="image/*" />` →
    file input として数え、`static-text`(正例。要素名も大文字小文字を無視)
20. `<svelte:element this="div" />` → 母集団外(正例。静的に非 input と確定できる)
21. `<Foo />` / `<svelte:component this={C} />` → 母集団外(正例)
21b. 同一ファイルに `{@html}` が 2 つ → `rawHtml` の `occurrence` が 1, 2 の順で付くこと

**(B) 判定関数(gate 分岐)の負例・正例**(合成 `FileInputScanResult` + 合成目録):

22. 適合する組 → 違反 0 件(正例)
23. 目録が 1 件不足 → 未登録の違反
24. 目録に実在しない 1 件 → 残置の違反
25. `syntax` 不一致 → 違反
26. 同じ `file` + `occurrence` が 2 件 → 重複の違反
27. `rationale` が 29 文字(`supply` が `server-prop` の側で試す) → 違反
28. `occurrence` が `0` → 違反
29. 件数 pin が実測と 1 件ずれ → 違反
30. `svelteFileCount = 0` → 走査空振りの違反
31. `nativeInputCount = 0` → 母集団空 その 1 の違反
32. `fileInputs = []` → 母集団空 その 2 の違反(31 と**別の違反**として返ること)
33. `diagnostics` に 1 件ある(**`unresolved-type` など生 HTML 以外**を使う。
    他はすべて適合) → **違反になること**
    (「走査器が診断を集めたのに判定が無視する」実装ミスの負例。
    design-review Round 2 Warning 対応。生 HTML と責務を混ぜないため、
    ここでは免除の概念が無い診断を使う = Round 4 Warning 対応)
34. `supply = "server-prop"` かつ `syntax = "static-text"` → 整合違反
35. `rawHtml` 1 件があり、同じ `file` + `occurrence` が免除目録に**ある**
    → 違反にならない(正例)
36. `rawHtml` 1 件があり、免除目録に**無い** → 違反(未登録)
37. 免除目録にあるのに `rawHtml` に対応する実測が無い(残置) → 違反
38. **免除済みファイルに 2 件目の `{@html}` が増えた**
    (`rawHtml` に `occurrence: 2` が加わり、免除は `occurrence: 1` のみ)
    → **未登録として違反になること**
    (design-review Round 4 Critical の核。ファイル名だけを鍵にしていると
    ここが緑になってしまう)
39. 免除の `rationale` が 29 文字 / 免除の `occurrence` が 0 /
    免除キーの重複 / 免除の件数 pin が 1 件ずれ → それぞれ違反

> **(B) を置く理由**: (A) だけでは「実リポジトリが偶然適合しているせいで
> gate の比較分岐が壊れていても緑」という状態を検出できない
> (design-review Round 1 Warning)。
> また 30〜33 と 35〜39 は**判定関数へ集約したからこそ負例を書ける**分岐である
> (gate 側の assert に散らしていると自己検査できない。design-review Round 2 Warning)。

### テスト計画(テストファースト)

- [ ] 先に赤くする: 目録を**空**にして gate を走らせ、実測 4 件との不一致で赤くなること
      (deny-by-default が効いていることの確認)
- [ ] 走査器の負例・正例 22 ケース((A) の 1〜21b。要素レベルの 16〜21b を含む)
- [ ] 判定関数の負例・正例 18 ケース((B) の 22〜39)
- [ ] gate 本体の 2 ステップ(走査 → 判定関数の結果が空)
- [ ] docblock に**走査対象と保証しないもの**を書く(正本は docblock 側)。
      `pnpm test` が正本のレーンであることも書く(`composer test` では JS gate は走らない)
- [ ] `AGENTS.md` の追記(1 項)。**件数・保証しないものは写さない**

### リスク

- **維持コスト**: 新しいアップロード面を足すたびに目録の 1 行と件数を更新する必要がある。
  これは意図した摩擦(単一の情報源へ繋ぐ判断をレビューに見せる)。
- **`svelte/compiler` の AST 形状は major 更新で変わりうる**。
  変わったら (A) の合成入力が最初に落ちる(無言で緑にはならない)。
  本設計が根拠にしている形は **svelte 5.56 での実測**である。
- 序数(`occurrence`)は並べ替えに弱い。ずれたら赤くなるだけなので安全側。
- **`{@html}` の免除が増えると監視が緩む**。免除は `file` + `occurrence` を鍵に
  件数 pin 付きで登録するため、**同じファイルに 2 件目が増えても赤くなる**
  (現在 1 件 = 2FA の QR コード SVG)。
- **この gate は本設計の本体(施策 1〜4)とは独立**である。実装順で最後に置き、
  もし規約 5 条を満たす形に収まらないと判明したら**この施策だけを落とす判断も可**
  (安直な文字列 grep 版に劣化させない。design-review Round 1 の指摘と同じ結論)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 5 施策すべてが既存ファイルの局所変更 + **新規 6 ファイル**(共有 Svelte 1 / 共有 component テスト 1 / テスト支援 2 / architecture テスト 2。design-review Round 2 Suggestion 対応で件数を再訂正)で、migration・DTO 追加・route 追加を伴わない。施策 1→2→3→4 は同一 PR 内で順に積む前提の依存関係があり、間で緑を保てる(施策 1 は既存 422 文言テストが、施策 2 は Feature テストが、施策 3/4 は component / page テストが各段でカバーする)。施策 5 は独立で、最後に足せる |
| 競合リスク | 低。`VideoManualController::create()`・`Manuals/Create.svelte`・`AcceptedSourceDocumentTypes`・2 つの FormRequest の `messages()` はいずれも他の進行中作業と重なりにくい局所。`SourceDocumentUpload.svelte` は T234 由来のファイルだが T234 はクローズ済み。`AGENTS.md` の追記(施策 5)だけは他 TODO と衝突しうるため、末尾のドメイン規約へ 1 項追加する形に限定する |

## 最終確認(使命・禁止事項)

- **使命への寄与**: OCR を有効にした環境で、新規動画マニュアル作成という主導線から
  画像・スキャン SOP を投入できるようになる(「現場に既にある作業手順書を起点に」の入口)。
- **禁止事項 1**(テストなしの実装完了): 全施策にテスト計画があり、
  静的検査(施策 5)は負例・正例の両方向を持つ。
- **禁止事項 2**(PHPStan widen): 型を緩める箇所は無い(`string` / `bool` の追加のみ)。
- **禁止事項 3**(dev DB 破壊操作): 該当なし(migration 無し)。
- **禁止事項 4**(`response()->json()` 直書き): 該当なし(Inertia のみ)。
- **禁止事項 8**(必須未充足で disabled): 変更しない。受理外形式の拒否は 422 のまま。
- **セキュリティ不変条件**: 受理判定・容量上限・画像枚数制約・認可(`Gate::authorize`)・
  テナント境界(`resolveOrganizationProject` が認可より前に 404)は 1 行も変えない。


---

## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 8770effa..1c211a3c 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -973,3 +973,30 @@ ## ドメイン固有規約
       `composer test` だけでは値集合の同期は検証されない
     - **保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期**
       であり、本書には写さない (2 か所に書くと必ず食い違う)
+20. **file input の accept 供給元の宣言 (T235)**: `resources/js` 配下の `.svelte` に
+    file input を足したら、`tests/js/support/file-input-accept-inventory.ts` の
+    `FILE_INPUT_ACCEPT_INVENTORY` へ 1 行足し、件数の pin も 1 増やす
+    (`tests/js/architecture/file-input-accept-source-inventory.test.ts` が
+    deny-by-default + 両方向で強制する)。
+    - 宣言は **2 軸**である。実測構文 (`syntax`) は**走査器が AST から実測する**ので
+      合わせるしかない。供給元 (`supply`) は**人がレビューで宣言する設計意図**で、
+      `server-prop` (サーバの受理形式が単一の情報源) か `client-owned`
+      (その面の固有の値域) かを 30 文字以上の理由付きで書く。
+      **`server-prop` の宣言は由来の証明ではない** — 値が本当に
+      `AcceptedSourceDocumentTypes` 由来であることは Controller の Feature テストと
+      component テストが担う
+    - SOP (手順書) の受理形式を扱う面は `server-prop` にする。
+      サーバ側の単一の情報源は `App\Support\Manual\AcceptedSourceDocumentTypes` で、
+      `accept` 属性値・画像対応の真偽・人間向けの形式ラベルの 3 つを供給する
+      (フロントで accept 文字列を解析して画像対応可否を判定しない)。
+      外部送信の案内文言は
+      `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte`
+      **1 つだけが持つ** (複写すると法務確認済みの文が片方だけ更新される)
+    - 走査器が **file input かどうか / accept の値を静的に確定できない形**
+      (spread 属性・式の `type`・動的な要素名 等) と、**生 HTML の描画** (`{@html …}`) は
+      それぞれ名指しの免除目録へ理由付きで登録する。**免除は人の宣言**であって
+      走査器が中身を確かめた結果ではない
+    - **正本のレーンは `pnpm test`** である (`composer test` では JS の gate は走らない)
+    - **走査対象と保証しないものの正本は `tests/js/support/file-input-scan.ts` の
+      docblock** であり、本書には写さない (2 か所に書くと必ず食い違う)。
+      件数も写さない (正本は目録側の pin)
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index c016ce6b..27d10e7e 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -66,6 +66,13 @@ public function create(Request $request, Project $project): Response
                 'name' => $project->name,
             ],
             'categories' => $this->categoryOptions($project),
+            // 作成と同時の SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
+            // StoreVideoManualRequest と同じ AcceptedSourceDocumentTypes を情報源にする
+            // = ダイアログに出る形式とサーバが受理する形式が構造的に一致する。
+            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
+            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
+            // help 文言用の受理形式ラベル (422 文言と同一の情報源)
+            'sourceDocumentFormatsLabel' => AcceptedSourceDocumentTypes::formatsLabel(),
         ]);
     }
 
diff --git a/app/Http/Requests/Projects/StoreSourceDocumentRequest.php b/app/Http/Requests/Projects/StoreSourceDocumentRequest.php
index 3650a754..f9dcea93 100644
--- a/app/Http/Requests/Projects/StoreSourceDocumentRequest.php
+++ b/app/Http/Requests/Projects/StoreSourceDocumentRequest.php
@@ -52,12 +52,10 @@ public function rules(): array
      */
     public function messages(): array
     {
-        $formats = AcceptedSourceDocumentTypes::imagesEnabled()
-            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
-            : 'PDF・Excel・テキスト形式';
-
         return [
-            'document.mimes' => "対応していないファイル形式です。{$formats}でアップロードし直してください。",
+            'document.mimes' => '対応していないファイル形式です。'
+                .AcceptedSourceDocumentTypes::formatsLabel()
+                .'でアップロードし直してください。',
         ];
     }
 
diff --git a/app/Http/Requests/Projects/StoreVideoManualRequest.php b/app/Http/Requests/Projects/StoreVideoManualRequest.php
index a9945c8c..47bdb6fc 100644
--- a/app/Http/Requests/Projects/StoreVideoManualRequest.php
+++ b/app/Http/Requests/Projects/StoreVideoManualRequest.php
@@ -61,12 +61,10 @@ public function rules(): array
      */
     public function messages(): array
     {
-        $formats = AcceptedSourceDocumentTypes::imagesEnabled()
-            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
-            : 'PDF・Excel・テキスト形式';
-
         return [
-            'document.mimes' => "対応していないファイル形式です。{$formats}でアップロードし直してください。",
+            'document.mimes' => '対応していないファイル形式です。'
+                .AcceptedSourceDocumentTypes::formatsLabel()
+                .'でアップロードし直してください。',
         ];
     }
 }
diff --git a/app/Support/Manual/AcceptedSourceDocumentTypes.php b/app/Support/Manual/AcceptedSourceDocumentTypes.php
index 66e2211b..c504d784 100644
--- a/app/Support/Manual/AcceptedSourceDocumentTypes.php
+++ b/app/Support/Manual/AcceptedSourceDocumentTypes.php
@@ -56,4 +56,20 @@ public static function imagesEnabled(): bool
     {
         return config()->boolean('manual.ocr_analysis_enabled');
     }
+
+    /**
+     * 受理形式の人間向けラベル (法務確認を経た文面。FormRequest の 422 文言と
+     * 作成画面の help 文言が共有する)。
+     *
+     * **機械導出しない**: 拡張子リストから日本語の文を組み立てる形にすると
+     * config を触った副作用で文面が変わりうるため、承認済みの 2 文をそのまま持つ。
+     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin (基底拡張子集合・
+     * 画像拡張子集合が現在値ちょうど) が検出する。
+     */
+    public static function formatsLabel(): string
+    {
+        return self::imagesEnabled()
+            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
+            : 'PDF・Excel・テキスト形式';
+    }
 }
diff --git a/resources/js/components/features/manual/SourceDocumentUpload.svelte b/resources/js/components/features/manual/SourceDocumentUpload.svelte
index e71127d2..c93d4aef 100644
--- a/resources/js/components/features/manual/SourceDocumentUpload.svelte
+++ b/resources/js/components/features/manual/SourceDocumentUpload.svelte
@@ -2,6 +2,7 @@
     import { useForm } from "@inertiajs/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
+    import SourceDocumentUploadNotice from "@/components/features/manual/SourceDocumentUploadNotice.svelte";
 
     /**
      * SOP (手順書) の後付けアップロード (POST .../source-documents。Inertia multipart form)。
@@ -37,16 +38,7 @@
 </script>
 
 <form novalidate onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
-    <p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
-        アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
-    </p>
-    {#if imageSourceDocumentsEnabled}
-        <p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
-            画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
-            不要な個人情報や機密情報が写っていないか特に確認してください。
-            画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
-        </p>
-    {/if}
+    <SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />
     <FormField
         label={hasDocument ? "手順書を差し替える" : "手順書 (SOP) をアップロード"}
         id="source-document"
diff --git a/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte b/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
new file mode 100644
index 00000000..18e28977
--- /dev/null
+++ b/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
@@ -0,0 +1,30 @@
+<script lang="ts">
+    /**
+     * SOP アップロードの外部送信案内。**文言の唯一の出現箇所** (法務確認を経た文面。
+     * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
+     * component 1 つへ集約している)。
+     *
+     * 一般案内はフラグの真偽に関わらず常時表示する (テキスト・Excel・通常 PDF にも
+     * 等しく当てはまる事実のため)。OCR 固有警告だけを imageSourceDocumentsEnabled で
+     * 出し分ける (画像・スキャン SOP の OCR 対応の方針)。
+     *
+     * **wrapper 要素を作らない**: 呼び出し側の flex 列 (gap) が案内の各段落へ直接効く
+     * 前提で描画順・間隔が決まっているため、fragment として 2 要素を返す。
+     */
+    interface Props {
+        imageSourceDocumentsEnabled: boolean;
+    }
+
+    let { imageSourceDocumentsEnabled }: Props = $props();
+</script>
+
+<p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
+    アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
+</p>
+{#if imageSourceDocumentsEnabled}
+    <p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
+        画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
+        不要な個人情報や機密情報が写っていないか特に確認してください。
+        画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
+    </p>
+{/if}
diff --git a/resources/js/pages/Manuals/Create.svelte b/resources/js/pages/Manuals/Create.svelte
index f300e462..80296d4f 100644
--- a/resources/js/pages/Manuals/Create.svelte
+++ b/resources/js/pages/Manuals/Create.svelte
@@ -5,6 +5,7 @@
     import Input from "@/components/atoms/Input.svelte";
     import Select from "@/components/atoms/Select.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
+    import SourceDocumentUploadNotice from "@/components/features/manual/SourceDocumentUploadNotice.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
@@ -17,13 +18,29 @@
      * 動画マニュアル作成 (タイトル + カテゴリ + 任意の手順書アップロード)。
      * カテゴリの入力名は保護キー category_id と別名の `category` (id 値)。
      * 空選択 = 未分類 (null で送信)。document は multipart で任意送信。
+     *
+     * 受理形式・画像対応の出し分けは `AcceptedSourceDocumentTypes` をサーバ側の
+     * 単一の情報源として渡された Props に従う (フロント側で accept 文字列を解析して
+     * 画像対応可否を判定しない)。
      */
     interface Props {
         project: { id: number; name: string };
         categories: CategoryOption[];
+        /** SOP アップロードの `<input accept>` 属性値 (画像・スキャン SOP の OCR 対応) */
+        sourceDocumentAccept: string;
+        /** 画像・スキャン PDF の OCR 対応が有効か (フラグ連動の案内出し分け専用) */
+        imageSourceDocumentsEnabled: boolean;
+        /** 受理形式の人間向けラベル (422 文言と同一の情報源) */
+        sourceDocumentFormatsLabel: string;
     }
 
-    let { project, categories }: Props = $props();
+    let {
+        project,
+        categories,
+        sourceDocumentAccept,
+        imageSourceDocumentsEnabled,
+        sourceDocumentFormatsLabel,
+    }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -91,17 +108,19 @@
                             </Select>
                         {/snippet}
                     </FormField>
+                    <!-- ファイルを選ぶ前に外部送信の事実が見えている必要があるため file input の直前に置く -->
+                    <SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />
                     <FormField
                         label="手順書 (SOP・任意)"
                         id="manual-document"
                         error={form.errors.document}
-                        help="PDF / Excel / テキスト。アップロードすると AI 解析でシナリオを生成できます。"
+                        help={`${sourceDocumentFormatsLabel}。アップロードすると AI 解析でシナリオを生成できます。`}
                     >
                         {#snippet children({ id, describedBy, invalid })}
                             <input
                                 {id}
                                 type="file"
-                                accept=".pdf,.xlsx,.xls,.txt"
+                                accept={sourceDocumentAccept}
                                 onchange={onFileChange}
                                 aria-describedby={describedBy}
                                 aria-invalid={invalid}
diff --git a/tests/Feature/Projects/SourceDocumentUploadOcrTest.php b/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
index 1880aa77..f5b787b4 100644
--- a/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
+++ b/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
@@ -8,6 +8,7 @@
 use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Manual\SourceDocumentService;
+use App\Support\Manual\AcceptedSourceDocumentTypes;
 use Illuminate\Http\UploadedFile;
 use Illuminate\Support\Facades\Storage;
 use Illuminate\Validation\ValidationException;
@@ -221,13 +222,84 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
             ))->toThrow(ValidationException::class);
         }
 
-        // Inertia Props: sourceDocumentAccept / imageSourceDocumentsEnabled
-        $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]))
-            ->assertInertia(fn (Assert $page) => $page
-                ->where('imageSourceDocumentsEnabled', $flag)
-                ->where('sourceDocumentAccept', $flag
-                    ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
-                    : '.pdf,.xlsx,.xls,.txt'));
+        // Inertia Props (詳細画面): sourceDocumentAccept / imageSourceDocumentsEnabled
+        $showResponse = $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]));
+        $showResponse->assertInertia(fn (Assert $page) => $page
+            ->where('imageSourceDocumentsEnabled', $flag)
+            ->where('sourceDocumentAccept', $flag
+                ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
+                : '.pdf,.xlsx,.xls,.txt'));
+
+        // Inertia Props (作成画面): 同じ単一の情報源を経由する 3 件
+        $createResponse = $this->actingAs($owner)->get(route('projects.manuals.create', [$project]));
+        $createResponse->assertInertia(fn (Assert $page) => $page
+            ->where('imageSourceDocumentsEnabled', $flag)
+            ->where('sourceDocumentAccept', $flag
+                ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
+                : '.pdf,.xlsx,.xls,.txt')
+            ->where('sourceDocumentFormatsLabel', $flag
+                ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
+                : 'PDF・Excel・テキスト形式'));
+
+        // 面をまたいだ同値性。リテラル pin は「両面とも同じ間違い」を検出できるが、
+        // 「面ごとに違う」ケースはこの比較が担う。
+        // **比較対象は両面に存在する 2 件だけ**である: sourceDocumentFormatsLabel は
+        // 詳細画面に形式ラベルを表示する UI が無く props を配っていないため含めない。
+        $sharedKeys = ['sourceDocumentAccept', 'imageSourceDocumentsEnabled'];
+        $showProps = Assert::fromTestResponse($showResponse)->toArray();
+        $createProps = Assert::fromTestResponse($createResponse)->toArray();
+        foreach ($sharedKeys as $key) {
+            expect($createProps[$key] ?? null)->toBe(
+                $showProps[$key] ?? null,
+                "作成画面と詳細画面で props {$key} が食い違っている (単一の情報源を経由していない)",
+            );
+        }
+    }
+});
+
+/*
+ * StoreVideoManualRequest (作成と同時のアップロード経路) の 422 出力契約。
+ *
+ * **このテストが保証する範囲 (誇張しない)**: 固定できるのは両エンドポイントの 422 出力契約
+ * である。「formatsLabel() を実際に呼んでいること」は保証しない — 置換前の三項演算子を
+ * 残しても両フラグで同じ文言を返すため本テストは緑になる。中央メソッドへの構造的な結線は
+ * コードレビューで確認する。逆に **文面が経路ごとにずれたら** 本テストが検出する。
+ */
+test('作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す (両フラグ)', function (): void {
+    Storage::fake();
+    [, $owner, $project, $manual] = ocrUploadContext();
+
+    $cases = [
+        // フラグ false: jpeg は受理外
+        [false, fn (): UploadedFile => fakeJpegFile('rejected.jpg')],
+        // フラグ true: heic は受理外 (画像を受理してもなお外)
+        [true, fn (): UploadedFile => UploadedFile::fake()->create('rejected.heic', 10, 'image/heic')],
+    ];
+
+    foreach ($cases as [$flag, $makeFile]) {
+        config()->set('manual.ocr_analysis_enabled', $flag);
+
+        // 期待文はリテラルを書かず中央ラベルから組み立てる (文面そのものの pin は Unit テスト側)
+        $expected = '対応していないファイル形式です。'
+            .AcceptedSourceDocumentTypes::formatsLabel()
+            .'でアップロードし直してください。';
+
+        // 作成と同時 (StoreVideoManualRequest): title は有効値を渡し document.mimes だけを発火させる
+        $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
+            'title' => '422 文言の経路差テスト',
+            'category' => null,
+            'document' => $makeFile(),
+        ])->assertUnprocessable()
+            ->assertJsonValidationErrors(['document'])
+            ->assertJsonFragment(['document' => [$expected]]);
+
+        // 後付け (StoreSourceDocumentRequest): 同じ文面であること
+        $this->actingAs($owner)->postJson(
+            "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
+            ['document' => $makeFile()],
+        )->assertUnprocessable()
+            ->assertJsonValidationErrors(['document'])
+            ->assertJsonFragment(['document' => [$expected]]);
     }
 });
 
diff --git a/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php b/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php
index e1b91d2d..774d7c1b 100644
--- a/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php
+++ b/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php
@@ -40,6 +40,42 @@
     expect(AcceptedSourceDocumentTypes::imagesEnabled())->toBeTrue();
 });
 
+test('formatsLabel はフラグ false のとき画像を含まない文面を返す', function (): void {
+    config()->set('manual.ocr_analysis_enabled', false);
+
+    expect(AcceptedSourceDocumentTypes::formatsLabel())->toBe('PDF・Excel・テキスト形式');
+});
+
+test('formatsLabel はフラグ true のとき画像を含む文面を返す', function (): void {
+    config()->set('manual.ocr_analysis_enabled', true);
+
+    expect(AcceptedSourceDocumentTypes::formatsLabel())
+        ->toBe('PDF・Excel・テキスト形式、または JPEG・PNG の画像');
+});
+
+/*
+ * ラベルの前提の pin。formatsLabel() は拡張子リストから機械導出せず、法務確認を経た
+ * 2 文をそのまま持つ。したがって「拡張子集合が変わったのにラベルが据え置き」という
+ * 乖離は本テストだけが検出できる。
+ *
+ * 集合の差分ではなく **順序込みの完全一致** で書く: acceptAttribute() は extensions() の
+ * 順序に依存して文字列を組むため、集合比較では表示順の変更を見逃す。
+ */
+test('前提の pin: 基底拡張子集合と画像込み拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)', function (): void {
+    $failure = 'ラベル (AcceptedSourceDocumentTypes::formatsLabel) の見直しが必要です。'
+        .'受理拡張子の集合または順序が変わったのに、人間向けの文面は機械導出していないため追随しません。';
+
+    config()->set('manual.ocr_analysis_enabled', false);
+    expect(config()->array('manual.source_document_mimes'))
+        ->toBe(['pdf', 'xlsx', 'xls', 'txt'], $failure);
+    expect(AcceptedSourceDocumentTypes::extensions())
+        ->toBe(['pdf', 'xlsx', 'xls', 'txt'], $failure);
+
+    config()->set('manual.ocr_analysis_enabled', true);
+    expect(AcceptedSourceDocumentTypes::extensions())
+        ->toBe(['pdf', 'xlsx', 'xls', 'txt', 'jpg', 'jpeg', 'png'], $failure);
+});
+
 test('webp/gif はフラグに関わらず含まれない (スコープ外)', function (): void {
     config()->set('manual.ocr_analysis_enabled', true);
 
diff --git a/tests/js/architecture/file-input-accept-source-inventory.test.ts b/tests/js/architecture/file-input-accept-source-inventory.test.ts
new file mode 100644
index 00000000..33373e92
--- /dev/null
+++ b/tests/js/architecture/file-input-accept-source-inventory.test.ts
@@ -0,0 +1,53 @@
+import { describe, expect, it } from "vitest";
+import path from "path";
+import { scanFileInputs } from "../support/file-input-scan";
+import {
+    FILE_INPUT_ACCEPT_INVENTORY,
+    FILE_INPUT_COUNT,
+    RAW_HTML_EXEMPTION_COUNT,
+    RAW_HTML_EXEMPTIONS,
+    UNRESOLVED_FORM_EXEMPTION_COUNT,
+    UNRESOLVED_FORM_EXEMPTIONS,
+    evaluateFileInputInventory,
+} from "../support/file-input-accept-inventory";
+
+/**
+ * file input の `accept` 供給元目録 (deny-by-default)。
+ *
+ * 新しいアップロード面を足したときに「受理形式の単一の情報源へ繋ぐ」判断が
+ * レビューに見えないまま漏れるのを止める。**止めるのは供給元の宣言の漏れだけ**で、
+ * 宣言した供給元が本当にサーバ由来かは検証しない (保証範囲は走査器と目録の docblock が正本)。
+ *
+ * gate 本体がすることは 2 つだけである:
+ *   1. 実リポジトリの `resources/js` 配下の `.svelte` を走査する
+ *   2. 判定関数へ渡して戻り値が空配列であることを確かめる
+ *
+ * 判定を 1 関数へ集約しているのは、母集団非空や診断の扱いを gate 側の assert へ散らすと
+ * その分岐に負例が付かず「走査器は診断を集めたのに gate が無視する」実装ミスを
+ * 自己検査できなくなるためである (負例は `file-input-scan.test.ts` が持つ)。
+ *
+ * **正本のレーンは `pnpm test`** である (`composer test` では JS の gate は走らない)。
+ */
+
+const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
+
+describe("file input accept source inventory", () => {
+    it("resources/js 配下の file input はすべて供給元が目録に宣言されている", async () => {
+        const scan = await scanFileInputs(JS_ROOT);
+
+        const violations = evaluateFileInputInventory(scan, {
+            inventory: FILE_INPUT_ACCEPT_INVENTORY,
+            countPin: FILE_INPUT_COUNT,
+            rawHtmlExemptions: RAW_HTML_EXEMPTIONS,
+            rawHtmlExemptionCountPin: RAW_HTML_EXEMPTION_COUNT,
+            unresolvedFormExemptions: UNRESOLVED_FORM_EXEMPTIONS,
+            unresolvedFormExemptionCountPin: UNRESOLVED_FORM_EXEMPTION_COUNT,
+        });
+
+        expect(
+            violations,
+            `file input の accept 供給元目録が実測と一致しません。\n` +
+                `tests/js/support/file-input-accept-inventory.ts を更新してください:\n${violations.join("\n")}`,
+        ).toEqual([]);
+    });
+});
diff --git a/tests/js/architecture/file-input-scan.test.ts b/tests/js/architecture/file-input-scan.test.ts
new file mode 100644
index 00000000..c3973cd6
--- /dev/null
+++ b/tests/js/architecture/file-input-scan.test.ts
@@ -0,0 +1,586 @@
+import { describe, expect, it } from "vitest";
+import { scanSources } from "../support/file-input-scan";
+import type {
+    FileInputAcceptEntry,
+    FileInputPolicy,
+    RawHtmlExemption,
+    UnresolvedFormExemption,
+} from "../support/file-input-accept-inventory";
+import { evaluateFileInputInventory } from "../support/file-input-accept-inventory";
+import type { FileInputScanResult } from "../support/file-input-scan";
+
+/**
+ * `file-input-accept-source-inventory` gate の走査器と判定関数の自己検査。
+ *
+ * **合成入力のみ**で実ファイルに依存しない。(A) は走査器の検出力 (負例で診断になること /
+ * 正例で誤検出しないこと)、(B) は判定関数の分岐 (未登録・残置・件数 pin・母集団非空・
+ * 診断の取り扱い) を両方向で固定する。
+ *
+ * (B) を独立に置く理由: (A) だけでは「実リポジトリが偶然適合しているせいで判定関数の
+ * 比較分岐が壊れていても緑」という状態を検出できない。
+ */
+
+/** 1 ファイル分の合成ソースを走査する短縮形。 */
+const scanOne = (source: string, file = "pages/Synthetic.svelte") => scanSources([{ file, source }]);
+
+const reasonsOf = (result: FileInputScanResult): string[] =>
+    result.diagnostics.map((d) => d.reason);
+
+// ---------------------------------------------------------------------------
+// (A) 走査器の負例 (診断になること)
+// ---------------------------------------------------------------------------
+
+describe("file input 走査器: 負例 (未解決の形は診断になる)", () => {
+    it("1. spread 属性は type/accept を上書きしうるので診断になる", () => {
+        const result = scanOne('<input type="file" accept="x" {...attrs} />');
+
+        expect(reasonsOf(result)).toEqual(["spread-attribute"]);
+        expect(result.fileInputs).toEqual([]);
+        expect(result.nativeInputCount).toBe(1);
+        expect(result.diagnostics[0].at).not.toBeNull();
+        expect(result.diagnostics[0].detail.length).toBeGreaterThan(0);
+    });
+
+    it("2. type が式のときは「非 file」と決めつけず診断になる", () => {
+        expect(reasonsOf(scanOne("<input type={kind} />"))).toEqual(["unresolved-type"]);
+    });
+
+    it("3. type の真偽短縮も診断になる", () => {
+        expect(reasonsOf(scanOne("<input type />"))).toEqual(["unresolved-type"]);
+    });
+
+    it("4. file input に accept が無ければ診断になる", () => {
+        expect(reasonsOf(scanOne('<input type="file" />'))).toEqual(["missing-accept"]);
+    });
+
+    it("5. type={\"file\"} は式を評価しないので診断になる", () => {
+        expect(reasonsOf(scanOne('<input type={"file"} accept="x" />'))).toEqual([
+            "unresolved-type",
+        ]);
+    });
+
+    it("6. type が無くても spread があれば診断になる", () => {
+        expect(reasonsOf(scanOne("<input {...attrs} />"))).toEqual(["spread-attribute"]);
+    });
+
+    it("7. accept の真偽短縮は診断になる", () => {
+        expect(reasonsOf(scanOne('<input type="file" accept />'))).toEqual(["unresolved-accept"]);
+    });
+
+    it("8. parse 失敗はファイル単位の診断で、位置を持たない", () => {
+        const result = scanOne("<div><span/>");
+
+        expect(reasonsOf(result)).toEqual(["parse-failed"]);
+        expect(result.diagnostics[0].at).toBeNull();
+        expect(result.fileInputs).toEqual([]);
+        expect(result.rawHtml).toEqual([]);
+        // ファイル単位なので序数の概念を持たない
+        expect(result.diagnostics[0]).not.toHaveProperty("occurrence");
+    });
+
+    it("16. <svelte:element this={tag}> は実行時に input になりうるので診断になる", () => {
+        expect(reasonsOf(scanOne("<svelte:element this={tag} />"))).toEqual([
+            "unresolved-native-element",
+        ]);
+    });
+});
+
+// ---------------------------------------------------------------------------
+// (A) 走査器の正例 (誤検出しないこと)
+// ---------------------------------------------------------------------------
+
+describe("file input 走査器: 正例 (規定どおりの入力を誤検出しない)", () => {
+    it("9. 非 file の input は母集団に入らない (native input としては数える)", () => {
+        const result = scanOne('<input type="text" /><input />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs).toEqual([]);
+        expect(result.nativeInputCount).toBe(2);
+    });
+
+    it("10. accept が式なら expression", () => {
+        const result = scanOne('<input type="file" accept={x} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs).toEqual([
+            { file: "pages/Synthetic.svelte", occurrence: 1, syntax: "expression", literal: null },
+        ]);
+    });
+
+    it("11. accept の短縮記法 (実コードで使用中) も expression", () => {
+        const result = scanOne('<input type="file" {accept} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+        expect(result.fileInputs[0].literal).toBeNull();
+    });
+
+    it("12. 三項演算子 (実コードで使用中) も expression", () => {
+        const result = scanOne('<input type="file" accept={a ? "x" : "y"} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+    });
+
+    it("13. type=\"FILE\" も file 扱いで、静的テキストの accept は literal を記録する", () => {
+        const result = scanOne('<input type="FILE" accept="image/*" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("static-text");
+        expect(result.fileInputs[0].literal).toBe("image/*");
+    });
+
+    it("14. 複数パートの accept は expression", () => {
+        const result = scanOne('<input type="file" accept="a{b}c" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+        expect(result.fileInputs[0].literal).toBeNull();
+    });
+
+    it("15. 同一ファイルの file input には出現順に序数が付く", () => {
+        const result = scanOne(
+            '<input type="file" accept="a" /><div><input type="file" accept={b} /></div>',
+        );
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs.map((r) => [r.occurrence, r.syntax])).toEqual([
+            [1, "static-text"],
+            [2, "expression"],
+        ]);
+    });
+
+    it("17. {@html …} は診断ではなく生 HTML の実測として記録される", () => {
+        const result = scanOne("{@html markup}");
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.rawHtml).toHaveLength(1);
+        expect(result.rawHtml[0].occurrence).toBe(1);
+        expect(result.rawHtml[0].at).not.toBeNull();
+    });
+
+    it("18. <svelte:element this=\"input\"> は file input として数える", () => {
+        const result = scanOne('<svelte:element this="input" type="file" accept={x} />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("expression");
+        expect(result.nativeInputCount).toBe(1);
+    });
+
+    it("19. 要素名の大文字小文字は無視する (this=\"INPUT\")", () => {
+        const result = scanOne('<svelte:element this="INPUT" type="file" accept="image/*" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.fileInputs[0].syntax).toBe("static-text");
+        expect(result.fileInputs[0].literal).toBe("image/*");
+    });
+
+    it("20. 静的に非 input と確定できる <svelte:element this=\"div\"> は母集団外", () => {
+        const result = scanOne('<svelte:element this="div" />');
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.nativeInputCount).toBe(0);
+        expect(result.fileInputs).toEqual([]);
+    });
+
+    it("21. component は母集団外 (native input ではない)", () => {
+        const result = scanOne("<Foo /><svelte:component this={C} />");
+
+        expect(result.diagnostics).toEqual([]);
+        expect(result.nativeInputCount).toBe(0);
+        expect(result.fileInputs).toEqual([]);
+    });
+
+    it("21b. 同一ファイルの {@html} には出現順に序数が付く", () => {
+        const result = scanOne("{@html a}<div>{@html b}</div>");
+
+        expect(result.rawHtml.map((r) => r.occurrence)).toEqual([1, 2]);
+    });
+
+    it("走査したファイル数を返す (走査根が生きていることの確認用)", () => {
+        const result = scanSources([
+            { file: "a.svelte", source: '<input type="file" accept="x" />' },
+            { file: "b.svelte", source: "<div />" },
+        ]);
+
+        expect(result.svelteFileCount).toBe(2);
+        expect(result.fileInputs.map((r) => r.file)).toEqual(["a.svelte"]);
+    });
+});
+
+// ---------------------------------------------------------------------------
+// (B) 判定関数の負例・正例
+// ---------------------------------------------------------------------------
+
+const RATIONALE = "サーバの単一の情報源から props で受け取るため、ここでは静的な値を持たない";
+
+function entry(overrides: Partial<FileInputAcceptEntry> = {}): FileInputAcceptEntry {
+    return {
+        file: "pages/A.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "server-prop",
+        rationale: RATIONALE,
+        ...overrides,
+    };
+}
+
+function scan(overrides: Partial<FileInputScanResult> = {}): FileInputScanResult {
+    return {
+        svelteFileCount: 3,
+        nativeInputCount: 2,
+        fileInputs: [
+            { file: "pages/A.svelte", occurrence: 1, syntax: "expression", literal: null },
+        ],
+        diagnostics: [],
+        rawHtml: [],
+        ...overrides,
+    };
+}
+
+function policy(overrides: Partial<FileInputPolicy> = {}): FileInputPolicy {
+    return {
+        inventory: [entry()],
+        countPin: 1,
+        rawHtmlExemptions: [],
+        rawHtmlExemptionCountPin: 0,
+        unresolvedFormExemptions: [],
+        unresolvedFormExemptionCountPin: 0,
+        ...overrides,
+    };
+}
+
+const rawHtmlRecord = (occurrence = 1) => ({
+    file: "pages/B.svelte",
+    occurrence,
+    at: { line: 1, column: 0 },
+});
+
+const rawHtmlExemption = (overrides: Partial<RawHtmlExemption> = {}): RawHtmlExemption => ({
+    file: "pages/B.svelte",
+    occurrence: 1,
+    rationale: "サーバが生成した SVG をそのまま描画する箇所で、ファイル入力を作らないため免除する",
+    ...overrides,
+});
+
+const unresolvedExemption = (
+    overrides: Partial<UnresolvedFormExemption> = {},
+): UnresolvedFormExemption => ({
+    file: "components/atoms/Input.svelte",
+    reason: "spread-attribute",
+    count: 1,
+    rationale: "汎用入力 atom は呼び出し側の属性をそのまま転送する設計で、accept の供給元を持たない",
+    ...overrides,
+});
+
+describe("判定関数: 正例", () => {
+    it("22. 適合する組は違反 0 件", () => {
+        expect(evaluateFileInputInventory(scan(), policy())).toEqual([]);
+    });
+
+    it("35. 生 HTML の実測が免除目録にあれば違反にならない", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
+        );
+
+        expect(violations).toEqual([]);
+    });
+});
+
+describe("判定関数: 負例 (目録の突き合わせ)", () => {
+    it("23. 目録に無い実測は未登録の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [], countPin: 0 }),
+        );
+
+        expect(violations.join("\n")).toContain("未登録");
+    });
+
+    it("24. 実測に無い目録は残置の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ fileInputs: [] }),
+            policy(),
+        );
+
+        expect(violations.join("\n")).toContain("残置");
+    });
+
+    it("25. syntax の宣言が実測と違えば違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry({ syntax: "static-text", supply: "client-owned" })] }),
+        );
+
+        expect(violations.join("\n")).toContain("syntax");
+    });
+
+    it("26. 目録キーの重複は違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry(), entry()], countPin: 2 }),
+        );
+
+        expect(violations.join("\n")).toContain("重複");
+    });
+
+    it("27. rationale が 29 文字なら違反 (supply が server-prop でも検査する)", () => {
+        const short = "あ".repeat(29);
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry({ rationale: short })] }),
+        );
+
+        expect(violations.join("\n")).toContain("30 文字");
+    });
+
+    it("28. occurrence が 0 なら違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ inventory: [entry({ occurrence: 0 })] }),
+        );
+
+        expect(violations.join("\n")).toContain("occurrence");
+    });
+
+    it("29. 件数 pin が実測と 1 件ずれれば違反", () => {
+        const violations = evaluateFileInputInventory(scan(), policy({ countPin: 2 }));
+
+        expect(violations.join("\n")).toContain("件数");
+    });
+
+    it("34. server-prop と static-text の組み合わせは整合違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({
+                fileInputs: [
+                    { file: "pages/A.svelte", occurrence: 1, syntax: "static-text", literal: "x" },
+                ],
+            }),
+            policy({ inventory: [entry({ syntax: "static-text" })] }),
+        );
+
+        expect(violations.join("\n")).toContain("server-prop");
+    });
+});
+
+describe("判定関数: 負例 (母集団と診断)", () => {
+    it("30. 走査が空振りしていれば違反", () => {
+        const violations = evaluateFileInputInventory(scan({ svelteFileCount: 0 }), policy());
+
+        expect(violations.join("\n")).toContain("空振り");
+    });
+
+    it("31/32. 母集団が空の 2 条件は別の違反として返る", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ nativeInputCount: 0, fileInputs: [] }),
+            policy({ inventory: [], countPin: 0 }),
+        );
+
+        expect(violations.filter((v) => v.includes("native input"))).toHaveLength(1);
+        expect(violations.filter((v) => v.includes("file input"))).toHaveLength(1);
+        expect(violations).toHaveLength(2);
+    });
+
+    it("33. 免除目録に無い診断は違反になる (走査器が集めた診断を判定が無視しない)", () => {
+        const violations = evaluateFileInputInventory(
+            scan({
+                diagnostics: [
+                    {
+                        file: "pages/C.svelte",
+                        reason: "unresolved-type",
+                        at: { line: 3, column: 4 },
+                        detail: "type 属性が式である",
+                    },
+                ],
+            }),
+            policy(),
+        );
+
+        expect(violations.join("\n")).toContain("unresolved-type");
+    });
+});
+
+describe("判定関数: 負例 (生 HTML の免除目録)", () => {
+    it("36. 免除目録に無い生 HTML は未登録の違反", () => {
+        const violations = evaluateFileInputInventory(scan({ rawHtml: [rawHtmlRecord()] }), policy());
+
+        expect(violations.join("\n")).toContain("生 HTML");
+    });
+
+    it("37. 実測に無い免除は残置の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
+        );
+
+        expect(violations.join("\n")).toContain("残置");
+    });
+
+    it("38. 免除済みファイルに 2 件目の {@html} が増えたら未登録の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord(1), rawHtmlRecord(2)] }),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 1 }),
+        );
+
+        expect(violations.join("\n")).toContain("生 HTML");
+        expect(violations.join("\n")).toContain("occurrence=2");
+    });
+
+    it("39a. 免除の rationale が 29 文字なら違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({
+                rawHtmlExemptions: [rawHtmlExemption({ rationale: "あ".repeat(29) })],
+                rawHtmlExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("30 文字");
+    });
+
+    it("39b. 免除の occurrence が 0 なら違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({
+                rawHtmlExemptions: [rawHtmlExemption({ occurrence: 0 })],
+                rawHtmlExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("occurrence");
+    });
+
+    it("39c. 免除キーの重複は違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({
+                rawHtmlExemptions: [rawHtmlExemption(), rawHtmlExemption()],
+                rawHtmlExemptionCountPin: 2,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("重複");
+    });
+
+    it("39d. 免除の件数 pin が 1 件ずれれば違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ rawHtml: [rawHtmlRecord()] }),
+            policy({ rawHtmlExemptions: [rawHtmlExemption()], rawHtmlExemptionCountPin: 2 }),
+        );
+
+        expect(violations.join("\n")).toContain("件数");
+    });
+});
+
+/*
+ * 未解決の形の免除目録。
+ *
+ * **設計からの逸脱**: 詳細設計は「診断に免除の概念は無い (無条件で違反)」としていたが、
+ * その前提 (実リポジトリの診断が 0 件) は実測で成り立たなかった。汎用入力 atom
+ * (`components/atoms/Input.svelte`) は `{type}` と `{...rest}` を持ち、静的には file input に
+ * なりうる形が正当に実在する。無条件違反にすると gate が実装できないため、生 HTML と同じ
+ * **名指しの免除目録 (deny-by-default + 件数の完全一致)** で扱う。
+ * 未登録の未解決形は依然として違反であり、fail-closed は保たれている。
+ */
+describe("判定関数: 未解決の形の免除目録", () => {
+    const diagnostic = (file = "components/atoms/Input.svelte") =>
+        ({
+            file,
+            reason: "spread-attribute" as const,
+            at: { line: 1, column: 0 },
+            detail: "spread 属性が type/accept を上書きしうる",
+        });
+
+    it("免除目録に登録済みの未解決形は違反にならない (件数まで一致)", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ diagnostics: [diagnostic()] }),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations).toEqual([]);
+    });
+
+    it("免除済みファイルに 2 件目の未解決形が増えたら件数不一致で違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ diagnostics: [diagnostic(), diagnostic()] }),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("件数");
+    });
+
+    it("実測に無い未解決形の免除は残置の違反", () => {
+        const violations = evaluateFileInputInventory(
+            scan(),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("残置");
+    });
+
+    it("同じ reason でも別ファイルの未解決形は免除に一致しない", () => {
+        const violations = evaluateFileInputInventory(
+            scan({ diagnostics: [diagnostic("pages/Other.svelte")] }),
+            policy({
+                unresolvedFormExemptions: [unresolvedExemption()],
+                unresolvedFormExemptionCountPin: 1,
+            }),
+        );
+
+        expect(violations.join("\n")).toContain("pages/Other.svelte");
+    });
+
+    it("免除の rationale が 29 文字 / count が 0 / キー重複 / 件数 pin ずれはそれぞれ違反", () => {
+        const base = scan({ diagnostics: [diagnostic()] });
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption({ rationale: "あ".repeat(29) })],
+                    unresolvedFormExemptionCountPin: 1,
+                }),
+            ).join("\n"),
+        ).toContain("30 文字");
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption({ count: 0 })],
+                    unresolvedFormExemptionCountPin: 1,
+                }),
+            ).join("\n"),
+        ).toContain("count");
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption(), unresolvedExemption()],
+                    unresolvedFormExemptionCountPin: 2,
+                }),
+            ).join("\n"),
+        ).toContain("重複");
+
+        expect(
+            evaluateFileInputInventory(
+                base,
+                policy({
+                    unresolvedFormExemptions: [unresolvedExemption()],
+                    unresolvedFormExemptionCountPin: 2,
+                }),
+            ).join("\n"),
+        ).toContain("件数");
+    });
+});
diff --git a/tests/js/components/features/manual/SourceDocumentUpload.test.ts b/tests/js/components/features/manual/SourceDocumentUpload.test.ts
index 3fb486a1..df5d4f23 100644
--- a/tests/js/components/features/manual/SourceDocumentUpload.test.ts
+++ b/tests/js/components/features/manual/SourceDocumentUpload.test.ts
@@ -54,4 +54,45 @@ describe("SourceDocumentUpload", () => {
         expect(screen.getByTestId("source-document-image-notice")).toHaveTextContent("1 手順書につき 1 枚");
         expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent("外部の LLM provider");
     });
+
+    /*
+     * 案内を共有 component (SourceDocumentUploadNotice) へ切り出した後も、
+     * 「ファイルを選ぶ前に外部送信の事実が見えている」配置と、`form` の
+     * `flex flex-col gap-3` が案内 2 つに直接効く親子構造が保たれること。
+     * 順序だけでは wrapper の追加を検出できず gap の適用単位が変わる後退を見逃すため、
+     * 親要素も併せて固定する。
+     */
+    it("案内は file input より前にあり、form 直下に置かれる (wrapper を挟まない)", () => {
+        render(SourceDocumentUpload, {
+            props: {
+                ...baseProps,
+                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
+                imageSourceDocumentsEnabled: true,
+            },
+        });
+
+        const form = screen.getByTestId("source-document-upload");
+        const sendNotice = screen.getByTestId("source-document-send-notice");
+        const imageNotice = screen.getByTestId("source-document-image-notice");
+        const input = screen.getByTestId("source-document-input");
+
+        // DOM 順: 一般案内 → OCR 固有警告 → file input
+        const ordered = [...form.querySelectorAll("[data-testid]")].filter((el) =>
+            ["source-document-send-notice", "source-document-image-notice", "source-document-input"].includes(
+                el.getAttribute("data-testid") ?? "",
+            ),
+        );
+        expect(ordered.map((el) => el.getAttribute("data-testid"))).toEqual([
+            "source-document-send-notice",
+            "source-document-image-notice",
+            "source-document-input",
+        ]);
+        expect(
+            sendNotice.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING,
+        ).toBeTruthy();
+
+        // 親子構造: 案内 2 つは form の直下 (余計な wrapper が挟まっていない)
+        expect(sendNotice.parentElement).toBe(form);
+        expect(imageNotice.parentElement).toBe(form);
+    });
 });
diff --git a/tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts b/tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts
new file mode 100644
index 00000000..6e984092
--- /dev/null
+++ b/tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts
@@ -0,0 +1,45 @@
+import { afterEach, describe, expect, it } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import SourceDocumentUploadNotice from "@/components/features/manual/SourceDocumentUploadNotice.svelte";
+import { normalizedTextOf } from "../../../support/normalizeText";
+
+/*
+ * SOP アップロードの外部送信案内 (文言の唯一の出現箇所。作成画面と詳細画面が共有する):
+ * - 一般案内はフラグの真偽に関わらず常時表示 (テキスト・Excel・通常 PDF にも等しく当てはまる事実)
+ * - OCR 固有警告だけを imageSourceDocumentsEnabled で出し分ける
+ * - 文言は **全文一致** で固定する (部分一致では文面の劣化を見逃す)
+ */
+
+const SEND_NOTICE =
+    "アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。";
+
+const IMAGE_NOTICE =
+    "画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。" +
+    " 不要な個人情報や機密情報が写っていないか特に確認してください。" +
+    " 画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。";
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("SourceDocumentUploadNotice", () => {
+    it("imageSourceDocumentsEnabled=false では一般案内だけを全文どおり描画する", () => {
+        render(SourceDocumentUploadNotice, { props: { imageSourceDocumentsEnabled: false } });
+
+        expect(normalizedTextOf(screen.getByTestId("source-document-send-notice"))).toBe(
+            SEND_NOTICE,
+        );
+        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
+    });
+
+    it("imageSourceDocumentsEnabled=true では OCR 固有警告も全文どおり描画する", () => {
+        render(SourceDocumentUploadNotice, { props: { imageSourceDocumentsEnabled: true } });
+
+        expect(normalizedTextOf(screen.getByTestId("source-document-send-notice"))).toBe(
+            SEND_NOTICE,
+        );
+        expect(normalizedTextOf(screen.getByTestId("source-document-image-notice"))).toBe(
+            IMAGE_NOTICE,
+        );
+    });
+});
diff --git a/tests/js/pages/ManualsCreate.test.ts b/tests/js/pages/ManualsCreate.test.ts
index 08654da2..6abd03b1 100644
--- a/tests/js/pages/ManualsCreate.test.ts
+++ b/tests/js/pages/ManualsCreate.test.ts
@@ -1,6 +1,7 @@
 import { beforeEach, describe, expect, it, vi } from "vitest";
 import { fireEvent, render, screen } from "@testing-library/svelte";
 import { reactiveUseForm } from "../support/reactiveUseForm.svelte";
+import { normalizedTextOf } from "../support/normalizeText";
 
 const { formState } = vi.hoisted(() => ({ formState: { current: null as unknown } }));
 
@@ -18,8 +19,18 @@ const baseProps = {
         { id: 1, name: "準備作業" },
         { id: 2, name: "仕上げ" },
     ],
+    // 受理形式・画像対応の出し分けはサーバの AcceptedSourceDocumentTypes 由来の props に従う
+    // (フロント側で accept 文字列を解析して画像対応可否を判定しない)
+    sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt",
+    imageSourceDocumentsEnabled: false,
+    sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式",
 };
 
+/** FormField が描画する help 段落を入力要素から引く (FormField の id 規約 `{id}-help`)。 */
+function helpTextOf(input: HTMLElement): Element | null {
+    return document.getElementById(`${input.id}-help`);
+}
+
 /** 反応的フェイクフォームを毎テスト用意する (errors は $state で clearErrors 再描画を観測可能) */
 function setupForm(errors: Record<string, string> = {}): void {
     formState.current = reactiveUseForm(
@@ -61,13 +72,81 @@ describe("Manuals/Create", () => {
         expect(screen.queryByRole("option", { name: "準備作業" })).toBeNull();
     });
 
-    it("手順書 (SOP) のファイル入力を描画する (任意・accept 制限付き)", () => {
+    it("手順書 (SOP) のファイル入力は accept をサーバ props からそのまま受ける (フラグ false 相当)", () => {
         render(Create, { props: baseProps });
 
         const input = screen.getByTestId("manual-document-input");
         expect(input).toBeInTheDocument();
         expect(input.getAttribute("type")).toBe("file");
         expect(input.getAttribute("accept")).toBe(".pdf,.xlsx,.xls,.txt");
+
+        // 一般的な外部送信案内はフラグの真偽に関わらず常時表示
+        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent(
+            "外部の LLM provider",
+        );
+        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
+    });
+
+    it("フラグ true 相当の props では accept に画像拡張子を含み OCR 固有警告が出る", () => {
+        render(Create, {
+            props: {
+                ...baseProps,
+                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
+                imageSourceDocumentsEnabled: true,
+                sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式、または JPEG・PNG の画像",
+            },
+        });
+
+        expect(screen.getByTestId("manual-document-input").getAttribute("accept")).toBe(
+            ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
+        );
+        expect(screen.getByTestId("source-document-image-notice")).toHaveTextContent(
+            "1 手順書につき 1 枚",
+        );
+        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent(
+            "外部の LLM provider",
+        );
+        // help はラベル props を前半に据える (後半の文は現行のまま)
+        expect(normalizedTextOf(helpTextOf(screen.getByTestId("manual-document-input")))).toContain(
+            "JPEG・PNG の画像",
+        );
+    });
+
+    /*
+     * help の全文一致 pin。ラベルの部分一致だけでは後半の文
+     * 「アップロードすると AI 解析でシナリオを生成できます。」と句点の維持を固定できない。
+     */
+    it("help は受理形式ラベル props + 現行の後半文で構成される (全文一致)", () => {
+        render(Create, { props: baseProps });
+
+        expect(normalizedTextOf(helpTextOf(screen.getByTestId("manual-document-input")))).toBe(
+            "PDF・Excel・テキスト形式。アップロードすると AI 解析でシナリオを生成できます。",
+        );
+    });
+
+    /*
+     * 「ファイルを選ぶ前に外部送信の事実が見えている」配置と、作成 form の flex 列 (gap) が
+     * 案内へ直接効く親子構造を固定する (詳細画面側と同じ判定方法)。
+     */
+    it("案内は file input より前にあり、作成 form の直下に置かれる", () => {
+        const { container } = render(Create, {
+            props: { ...baseProps, imageSourceDocumentsEnabled: true },
+        });
+
+        const form = container.querySelector("form");
+        const sendNotice = screen.getByTestId("source-document-send-notice");
+        const imageNotice = screen.getByTestId("source-document-image-notice");
+        const input = screen.getByTestId("manual-document-input");
+
+        expect(form).not.toBeNull();
+        expect(sendNotice.parentElement).toBe(form);
+        expect(imageNotice.parentElement).toBe(form);
+        expect(
+            sendNotice.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING,
+        ).toBeTruthy();
+        expect(
+            imageNotice.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING,
+        ).toBeTruthy();
     });
 
     it("タイトル入力 (oninput) でタイトルエラーがその場でクリアされる", async () => {
diff --git a/tests/js/support/file-input-accept-inventory.ts b/tests/js/support/file-input-accept-inventory.ts
new file mode 100644
index 00000000..99be7606
--- /dev/null
+++ b/tests/js/support/file-input-accept-inventory.ts
@@ -0,0 +1,375 @@
+import type {
+    FileInputScanResult,
+    ScanDiagnosticReason,
+} from "./file-input-scan";
+
+/**
+ * file input の `accept` 供給元目録 (deny-by-default) と、その判定関数。
+ *
+ * # 軸を 2 つに分ける
+ *
+ * | 軸 | 値 | 誰が決めるか |
+ * |---|---|---|
+ * | 実測構文 (`syntax`) | `static-text` / `expression` | **走査器が AST から実測する** |
+ * | 供給元の宣言 (`supply`) | `server-prop` / `client-owned` | **人がレビューで宣言する** (理由必須) |
+ *
+ * `syntax` は機械が確かめられる事実である。`supply` は**設計意図の宣言であって由来の証明ではない**
+ * — `server-prop` と書いてあっても、この目録はその識別子がサーバの
+ * `AcceptedSourceDocumentTypes` 由来であることを検証しない。
+ *
+ * # 保証しないもの (誇張しない)
+ *
+ * - **由来の証明はしない**。`accept={sourceDocumentAccept}` の値が単一の情報源から来ている
+ *   ことは、Feature テスト (Controller の props) と component テスト (props の使い方) が担う。
+ * - **免除は人の宣言**である。生 HTML (`{@html …}`) の免除は「そこに file input を作らない」
+ *   という宣言で、中身を解析した結果ではない。未解決の形 (`diagnostics`) の免除も同じで、
+ *   「この形は accept の供給元を持たない」という宣言である。
+ * - 走査器側の限界 (`.svelte` 以外・実行時の書き換え・識別子の追跡) はそのまま引き継ぐ。
+ *   走査対象と走査器の保証範囲の正本は `./file-input-scan.ts` の docblock。
+ *
+ * 検出力の裏取りは `tests/js/architecture/file-input-scan.test.ts` (負例・正例の両方向)。
+ */
+
+/** 供給元の宣言。**人が宣言する設計意図**であり、gate は由来を検証しない。 */
+export type AcceptSupply = "server-prop" | "client-owned";
+
+export interface FileInputAcceptEntry {
+    readonly file: string;
+    /** ファイル内の 1 始まりの序数 (正の整数)。 */
+    readonly occurrence: number;
+    /** 実測と一致していなければ違反。 */
+    readonly syntax: "static-text" | "expression";
+    readonly supply: AcceptSupply;
+    /** 30 文字以上 (supply の値に関わらず全エントリ)。 */
+    readonly rationale: string;
+}
+
+/**
+ * 現在の実測ちょうど。**新しいアップロード面を足したら 1 行足し、件数 pin も 1 増やす**。
+ *
+ * 現在 4 件すべてが `expression` である。`static-text` は 0 件だが区分値としては必要で、
+ * `accept="image/*"` と直書きする面が将来増えたときに `expression` から `static-text` へ
+ * 変わって赤くなり、供給元の宣言を見直す契機になる (0 件の区分が正しく動くことは
+ * 自己検査の合成入力が担保する)。
+ */
+export const FILE_INPUT_ACCEPT_INVENTORY: readonly FileInputAcceptEntry[] = [
+    {
+        file: "components/features/manual/SourceDocumentUpload.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "server-prop",
+        rationale:
+            "SOP の受理形式はサーバの AcceptedSourceDocumentTypes が単一の情報源で、Inertia props 経由で受け取る",
+    },
+    {
+        file: "pages/Manuals/Create.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "server-prop",
+        rationale:
+            "作成と同時の SOP アップロードも同じ単一の情報源から props で受け取る (経路ごとに直書きしない)",
+    },
+    {
+        file: "components/features/capture/CaptureFileFallback.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "client-owned",
+        rationale:
+            "撮影テイクの入力は静止画 image/* と動画 video/* の 2 択で、SOP の受理形式とは別概念のためクライアント側で決める",
+    },
+    {
+        file: "components/features/manual/TakeFileUpload.svelte",
+        occurrence: 1,
+        syntax: "expression",
+        supply: "client-owned",
+        rationale:
+            "テイクの後付けアップロードも静止画・動画の 2 択で、サーバの SOP 受理形式とは無関係のためクライアント側で決める",
+    },
+] as const;
+
+/** 件数の pin。実測件数・目録配列長・一意キー数の 3 つと一致させる。 */
+export const FILE_INPUT_COUNT = 4;
+
+/** `{@html …}` を持つことを許すファイルの名指し目録 (deny-by-default)。 */
+export interface RawHtmlExemption {
+    readonly file: string;
+    /** ファイル内の `{@html}` の 1 始まりの序数 (正の整数)。 */
+    readonly occurrence: number;
+    /** 30 文字以上。 */
+    readonly rationale: string;
+}
+
+export const RAW_HTML_EXEMPTIONS: readonly RawHtmlExemption[] = [
+    {
+        file: "pages/Settings/Security.svelte",
+        occurrence: 1,
+        rationale:
+            "2FA の QR コードはサーバが生成した SVG をそのまま描画する箇所で、ファイル入力を作らない",
+    },
+] as const;
+
+/** 免除の件数の pin (増減のどちらでも赤くする)。 */
+export const RAW_HTML_EXEMPTION_COUNT = 1;
+
+/**
+ * 未解決の形 (`diagnostics`) の名指し免除目録 (deny-by-default)。
+ *
+ * **詳細設計からの逸脱**: 詳細設計は「診断に免除の概念は無い (無条件で違反)」としていたが、
+ * その前提 (実リポジトリの診断が 0 件) は実測で成り立たなかった。汎用入力 atom は
+ * `type={…}` と `{...rest}` を持ち、静的には file input になりうる形が正当に実在する。
+ * 無条件違反にすると gate そのものが実装できないため、生 HTML と同じ **名指し + 件数の
+ * 完全一致** で扱う。未登録の未解決形は依然として違反であり fail-closed は保たれている
+ * (無言で候補から外す形は作っていない)。
+ *
+ * 鍵は `file` + `reason` で、`count` は**その組の実測件数ちょうど**である
+ * (同じファイルに 2 件目が増えても件数不一致で赤くなる)。
+ */
+export interface UnresolvedFormExemption {
+    readonly file: string;
+    readonly reason: ScanDiagnosticReason;
+    /** その file + reason の実測件数ちょうど (正の整数)。 */
+    readonly count: number;
+    /** 30 文字以上。 */
+    readonly rationale: string;
+}
+
+export const UNRESOLVED_FORM_EXEMPTIONS: readonly UnresolvedFormExemption[] = [
+    {
+        file: "components/atoms/Input.svelte",
+        reason: "spread-attribute",
+        count: 1,
+        rationale:
+            "汎用入力 atom は type も残りの属性も呼び出し側から受けて転送する設計で、accept の供給元を自分では持たない",
+    },
+] as const;
+
+/** 未解決の形の免除の件数の pin (増減のどちらでも赤くする)。 */
+export const UNRESOLVED_FORM_EXEMPTION_COUNT = 1;
+
+/** 判定関数へ渡す目録一式 (引数の取り違えを型で防ぐためオブジェクトで受ける)。 */
+export interface FileInputPolicy {
+    readonly inventory: readonly FileInputAcceptEntry[];
+    readonly countPin: number;
+    readonly rawHtmlExemptions: readonly RawHtmlExemption[];
+    readonly rawHtmlExemptionCountPin: number;
+    readonly unresolvedFormExemptions: readonly UnresolvedFormExemption[];
+    readonly unresolvedFormExemptionCountPin: number;
+}
+
+const MIN_RATIONALE_LENGTH = 30;
+
+const isPositiveInteger = (value: number): boolean => Number.isInteger(value) && value > 0;
+
+const keyOf = (file: string, occurrence: number): string => `${file}#${occurrence}`;
+
+/** 重複しているキーを列挙する。 */
+function duplicatedKeys(keys: readonly string[]): string[] {
+    const seen = new Set<string>();
+    const duplicates = new Set<string>();
+    for (const key of keys) {
+        if (seen.has(key)) duplicates.add(key);
+        seen.add(key);
+    }
+
+    return [...duplicates];
+}
+
+/**
+ * gate の判定本体 (純関数)。**判定はすべてこの 1 関数へ集約する** —
+ * 母集団非空や診断の扱いを gate 側の assert へ散らすと、その分岐に負例が付かず
+ * 「走査器は診断を集めたのに gate が無視する」実装ミスを自己検査できなくなる。
+ *
+ * @returns 違反の説明文の配列 (空 = 適合)
+ */
+export function evaluateFileInputInventory(
+    scan: FileInputScanResult,
+    policy: FileInputPolicy,
+): readonly string[] {
+    const violations: string[] = [];
+
+    // --- 走査が生きているか / 母集団が空でないか ---
+    if (scan.svelteFileCount === 0) {
+        violations.push("走査が空振りしている: .svelte が 1 件も見つからない (走査根を確認)");
+    }
+    if (scan.nativeInputCount === 0) {
+        violations.push("母集団が空: native input が 0 件 (走査器の要素判定が壊れている疑い)");
+    }
+    if (scan.fileInputs.length === 0) {
+        violations.push("母集団が空: file input が 0 件 (走査器の type 判定が壊れている疑い)");
+    }
+
+    // --- 未解決の形 (診断) を免除目録と両方向で突き合わせる ---
+    const diagnosticCounts = new Map<string, number>();
+    for (const diagnostic of scan.diagnostics) {
+        const key = `${diagnostic.file}#${diagnostic.reason}`;
+        diagnosticCounts.set(key, (diagnosticCounts.get(key) ?? 0) + 1);
+    }
+    const unresolvedByKey = new Map<string, UnresolvedFormExemption>();
+    for (const exemption of policy.unresolvedFormExemptions) {
+        unresolvedByKey.set(`${exemption.file}#${exemption.reason}`, exemption);
+        if (!isPositiveInteger(exemption.count)) {
+            violations.push(
+                `未解決の形の免除の count が正の整数でない: ${exemption.file} (${exemption.reason}) count=${exemption.count}`,
+            );
+        }
+        if (exemption.rationale.length < MIN_RATIONALE_LENGTH) {
+            violations.push(
+                `未解決の形の免除の理由が 30 文字未満: ${exemption.file} (${exemption.reason})`,
+            );
+        }
+    }
+    for (const key of duplicatedKeys(
+        policy.unresolvedFormExemptions.map((e) => `${e.file}#${e.reason}`),
+    )) {
+        violations.push(`未解決の形の免除キーが重複している: ${key}`);
+    }
+    for (const [key, count] of diagnosticCounts) {
+        const exemption = unresolvedByKey.get(key);
+        const sample = scan.diagnostics.find((d) => `${d.file}#${d.reason}` === key);
+        const where = sample?.at ? ` (${sample.at.line}:${sample.at.column})` : "";
+        if (!exemption) {
+            violations.push(
+                `未登録の未解決の形: ${key}${where} — ${sample?.detail ?? ""}。` +
+                    "解消するか UNRESOLVED_FORM_EXEMPTIONS へ理由付きで登録してください",
+            );
+
+            continue;
+        }
+        if (exemption.count !== count) {
+            violations.push(
+                `未解決の形の免除の件数が実測と一致しない: ${key} 実測=${count} 免除=${exemption.count}`,
+            );
+        }
+    }
+    for (const key of unresolvedByKey.keys()) {
+        if (!diagnosticCounts.has(key)) {
+            violations.push(`未解決の形の免除が残置されている (実測に無い): ${key}`);
+        }
+    }
+    if (policy.unresolvedFormExemptions.length !== policy.unresolvedFormExemptionCountPin) {
+        violations.push(
+            `未解決の形の免除の件数 pin が配列長と一致しない: pin=${policy.unresolvedFormExemptionCountPin} 配列長=${policy.unresolvedFormExemptions.length}`,
+        );
+    }
+    if (unresolvedByKey.size !== policy.unresolvedFormExemptionCountPin) {
+        violations.push(
+            `未解決の形の免除の件数 pin が一意キー数と一致しない: pin=${policy.unresolvedFormExemptionCountPin} 一意キー数=${unresolvedByKey.size}`,
+        );
+    }
+
+    // --- file input の目録を両方向で突き合わせる ---
+    const inventoryByKey = new Map<string, FileInputAcceptEntry>();
+    for (const entry of policy.inventory) {
+        inventoryByKey.set(keyOf(entry.file, entry.occurrence), entry);
+        if (!isPositiveInteger(entry.occurrence)) {
+            violations.push(
+                `目録の occurrence が正の整数でない: ${entry.file} occurrence=${entry.occurrence}`,
+            );
+        }
+        if (entry.rationale.length < MIN_RATIONALE_LENGTH) {
+            violations.push(`目録の理由が 30 文字未満: ${keyOf(entry.file, entry.occurrence)}`);
+        }
+        if (entry.supply === "server-prop" && entry.syntax !== "expression") {
+            violations.push(
+                `server-prop の宣言は syntax=expression のときだけ許す (静的テキストをサーバ由来と宣言している): ${keyOf(entry.file, entry.occurrence)}`,
+            );
+        }
+    }
+    for (const key of duplicatedKeys(policy.inventory.map((e) => keyOf(e.file, e.occurrence)))) {
+        violations.push(`目録キーが重複している: ${key}`);
+    }
+    const measuredKeys = new Set<string>();
+    for (const record of scan.fileInputs) {
+        const key = keyOf(record.file, record.occurrence);
+        measuredKeys.add(key);
+        const entry = inventoryByKey.get(key);
+        if (!entry) {
+            violations.push(
+                `未登録の file input: ${key} (実測 syntax=${record.syntax})。` +
+                    "受理形式の供給元を判断して FILE_INPUT_ACCEPT_INVENTORY へ登録してください",
+            );
+
+            continue;
+        }
+        if (entry.syntax !== record.syntax) {
+            violations.push(
+                `syntax の宣言が実測と違う: ${key} 実測=${record.syntax} 宣言=${entry.syntax}`,
+            );
+        }
+    }
+    for (const key of inventoryByKey.keys()) {
+        if (!measuredKeys.has(key)) {
+            violations.push(`目録が残置されている (実測に無い): ${key}`);
+        }
+    }
+    if (scan.fileInputs.length !== policy.countPin) {
+        violations.push(
+            `file input の件数 pin が実測と一致しない: pin=${policy.countPin} 実測=${scan.fileInputs.length}`,
+        );
+    }
+    if (policy.inventory.length !== policy.countPin) {
+        violations.push(
+            `file input の件数 pin が目録配列長と一致しない: pin=${policy.countPin} 配列長=${policy.inventory.length}`,
+        );
+    }
+    if (inventoryByKey.size !== policy.countPin) {
+        violations.push(
+            `file input の件数 pin が一意キー数と一致しない: pin=${policy.countPin} 一意キー数=${inventoryByKey.size}`,
+        );
+    }
+
+    // --- 生 HTML を免除目録と両方向で突き合わせる ---
+    const rawHtmlExemptionByKey = new Map<string, RawHtmlExemption>();
+    for (const exemption of policy.rawHtmlExemptions) {
+        rawHtmlExemptionByKey.set(keyOf(exemption.file, exemption.occurrence), exemption);
+        if (!isPositiveInteger(exemption.occurrence)) {
+            violations.push(
+                `生 HTML の免除の occurrence が正の整数でない: ${exemption.file} occurrence=${exemption.occurrence}`,
+            );
+        }
+        if (exemption.rationale.length < MIN_RATIONALE_LENGTH) {
+            violations.push(
+                `生 HTML の免除の理由が 30 文字未満: ${keyOf(exemption.file, exemption.occurrence)}`,
+            );
+        }
+    }
+    for (const key of duplicatedKeys(
+        policy.rawHtmlExemptions.map((e) => keyOf(e.file, e.occurrence)),
+    )) {
+        violations.push(`生 HTML の免除キーが重複している: ${key}`);
+    }
+    const measuredRawHtmlKeys = new Set<string>();
+    for (const record of scan.rawHtml) {
+        const key = keyOf(record.file, record.occurrence);
+        measuredRawHtmlKeys.add(key);
+        if (!rawHtmlExemptionByKey.has(key)) {
+            violations.push(
+                `未登録の生 HTML ({@html}): ${record.file} occurrence=${record.occurrence} ` +
+                    `(${record.at.line}:${record.at.column})。` +
+                    "そこに file input を作らないことを確認して RAW_HTML_EXEMPTIONS へ登録してください",
+            );
+        }
+    }
+    for (const key of rawHtmlExemptionByKey.keys()) {
+        if (!measuredRawHtmlKeys.has(key)) {
+            violations.push(`生 HTML の免除が残置されている (実測に無い): ${key}`);
+        }
+    }
+    if (scan.rawHtml.length !== policy.rawHtmlExemptionCountPin) {
+        violations.push(
+            `生 HTML の件数 pin が実測と一致しない: pin=${policy.rawHtmlExemptionCountPin} 実測=${scan.rawHtml.length}`,
+        );
+    }
+    if (policy.rawHtmlExemptions.length !== policy.rawHtmlExemptionCountPin) {
+        violations.push(
+            `生 HTML の件数 pin が免除配列長と一致しない: pin=${policy.rawHtmlExemptionCountPin} 配列長=${policy.rawHtmlExemptions.length}`,
+        );
+    }
+    if (rawHtmlExemptionByKey.size !== policy.rawHtmlExemptionCountPin) {
+        violations.push(
+            `生 HTML の件数 pin が一意キー数と一致しない: pin=${policy.rawHtmlExemptionCountPin} 一意キー数=${rawHtmlExemptionByKey.size}`,
+        );
+    }
+
+    return violations;
+}
diff --git a/tests/js/support/file-input-scan.ts b/tests/js/support/file-input-scan.ts
new file mode 100644
index 00000000..8ee02cd6
--- /dev/null
+++ b/tests/js/support/file-input-scan.ts
@@ -0,0 +1,382 @@
+import fs from "fs/promises";
+import path from "path";
+import { parse } from "svelte/compiler";
+
+/**
+ * `.svelte` から native な file input と、その `accept` 属性の**実測**を集める走査器。
+ *
+ * # 走査対象
+ *
+ * - `scanFileInputs(root)`: `root` 配下 (再帰) の拡張子 `.svelte` のファイル全数。
+ * - `scanSources(sources)`: 与えられた合成ソース全数 (自己検査用。実ファイルに依存しない)。
+ *
+ * 母集団は「native `input` を作りうる形の全数」で、AST 上の扱いは次のとおり
+ * (svelte 5.56 で実測した形に基づく):
+ *
+ * | AST 上の形 | 扱い |
+ * |---|---|
+ * | `RegularElement` / name が `input` (大文字小文字を無視) | 母集団 (`type` 判定へ) |
+ * | `RegularElement` / name が `input` 以外 | 対象外 |
+ * | `SvelteElement` / `tag` が文字列 `Literal` で値が `input` (同上) | 母集団 |
+ * | `SvelteElement` / `tag` が文字列 `Literal` で値が `input` 以外 | 対象外 (静的に非 input と確定) |
+ * | `SvelteElement` / `tag` が `Literal` 以外、または非文字列 `Literal` | 診断 `unresolved-native-element` |
+ * | `HtmlTag` (`{@html …}`) | `rawHtml` として実測 (診断ではない。免除目録と突き合わせる) |
+ * | component (`<Foo />` / `<svelte:component>`) | 対象外 |
+ *
+ * `type` / `accept` の実測は「静的テキストだけで確定できるか」で分け、確定できない形は
+ * すべて診断にする (**未解決を無言で候補から外さない** = fail-closed)。
+ *
+ * # 保証しないもの (誇張しない)
+ *
+ * - **`.svelte` 以外**には効かない。TS から `document.createElement('input')` する経路、
+ *   Blade テンプレート、実行時に `accept` を書き換える形は見えない。
+ * - **識別子の値の由来は追跡しない**。`accept={x}` を見ても `x` がサーバ由来かは分からない
+ *   (Inertia props は実行時に注入されるため静的検査の到達範囲外)。
+ * - **`{@html …}` に渡される文字列の中身は解析しない**。生 HTML の中に file input を
+ *   書けるかどうかは分からないため、免除目録の登録は「そこに file input を作らない」という
+ *   人の宣言であり、走査器が確かめた結果ではない。
+ * - `occurrence` (序数) は**出現順**であって意味の追跡ではない。並べ替えると値がずれるが、
+ *   ずれれば赤くなる (安全側)。
+ * - `svelte/compiler` の AST 形状は major 更新で変わりうる。変われば自己検査
+ *   (`tests/js/architecture/file-input-scan.test.ts`) の合成入力が最初に落ちる
+ *   (無言で緑にはならない)。
+ *
+ * 検出力の裏取り (負例・正例の両方向) は `tests/js/architecture/file-input-scan.test.ts`。
+ */
+
+/** 走査に渡す 1 ファイル分のソース。 */
+export interface SvelteSource {
+    /** 走査根からの相対パス (POSIX 区切り)。目録の鍵になる。 */
+    readonly file: string;
+    readonly source: string;
+}
+
+/** 実測できた file input の 1 件。`occurrence` はファイル内の 1 始まりの序数。 */
+export interface FileInputRecord {
+    readonly file: string;
+    readonly occurrence: number;
+    readonly syntax: "static-text" | "expression";
+    /** `static-text` のときだけ値。`expression` は null。 */
+    readonly literal: string | null;
+}
+
+export type ScanDiagnosticReason =
+    /** ファイル単位。parse そのものが失敗した。 */
+    | "parse-failed"
+    /** `type` が式・真偽短縮・複数パートで、file かどうか確定できない。 */
+    | "unresolved-type"
+    /** 同一要素の spread 属性が `type` / `accept` を上書きしうる。 */
+    | "spread-attribute"
+    /** file input なのに `accept` が無い。 */
+    | "missing-accept"
+    /** `accept` が真偽短縮などで値を確定できない。 */
+    | "unresolved-accept"
+    /** `<svelte:element this={…}>` が実行時に input になりうる。 */
+    | "unresolved-native-element";
+
+/** ソース上の位置 (行は 1 始まり、列は 0 始まり)。 */
+export interface SourcePosition {
+    readonly line: number;
+    readonly column: number;
+}
+
+/**
+ * 生 HTML の描画 (`{@html …}`) の実測 1 件。**診断とは別の集合**である
+ * (診断は免除の概念を持たず、生 HTML は免除目録と両方向で突き合わせる)。
+ */
+export interface RawHtmlRecord {
+    readonly file: string;
+    readonly occurrence: number;
+    readonly at: SourcePosition;
+}
+
+export interface ScanDiagnostic {
+    readonly file: string;
+    readonly reason: ScanDiagnosticReason;
+    /** `parse-failed` は null (ファイル単位のため位置を持たない)。 */
+    readonly at: SourcePosition | null;
+    readonly detail: string;
+}
+
+export interface FileInputScanResult {
+    /** 走査したファイル数 (走査根が生きていることの確認用)。 */
+    readonly svelteFileCount: number;
+    /** native input 要素の全数 (母集団非空 その 1)。 */
+    readonly nativeInputCount: number;
+    /** 静的に file と確定し accept を実測できた input (母集団非空 その 2)。 */
+    readonly fileInputs: readonly FileInputRecord[];
+    /** 未解決の形。判定側で免除目録と突き合わせる。 */
+    readonly diagnostics: readonly ScanDiagnostic[];
+    /** 生 HTML の実測。判定側で免除目録と両方向で突き合わせる。 */
+    readonly rawHtml: readonly RawHtmlRecord[];
+}
+
+/** AST ノードの最低限の形 (走査器が触る範囲だけを型で表す)。 */
+interface AstNode {
+    readonly type: string;
+    readonly start?: number;
+    readonly name?: string;
+    readonly attributes?: readonly AstNode[];
+    readonly tag?: { readonly type: string; readonly value?: unknown };
+    readonly value?: unknown;
+    readonly data?: string;
+    readonly [key: string]: unknown;
+}
+
+const isAstNode = (value: unknown): value is AstNode =>
+    typeof value === "object" && value !== null && typeof (value as { type?: unknown }).type === "string";
+
+/** バイト offset を 1 始まりの行 / 0 始まりの列へ変換する。 */
+function positionAt(source: string, offset: number): SourcePosition {
+    const before = source.slice(0, offset);
+    const lineBreaks = before.split("\n");
+
+    return { line: lineBreaks.length, column: lineBreaks[lineBreaks.length - 1].length };
+}
+
+/** ノードを再帰的に列挙する (テンプレートと式の区別をせず全走査し、type で振り分ける)。 */
+function eachNode(value: unknown, visit: (node: AstNode) => void): void {
+    if (Array.isArray(value)) {
+        for (const item of value) eachNode(item, visit);
+
+        return;
+    }
+    if (typeof value !== "object" || value === null) return;
+    if (isAstNode(value)) visit(value);
+    for (const [key, child] of Object.entries(value)) {
+        // 位置情報と親参照は走査しない (循環と無駄打ちの回避)
+        if (key === "type" || key === "parent" || key === "loc" || key === "name_loc") continue;
+        eachNode(child, visit);
+    }
+}
+
+/** 属性値を「静的テキストだけで確定できるか」で分類する。 */
+type AttributeValue =
+    | { readonly kind: "static"; readonly text: string }
+    | { readonly kind: "expression" }
+    | { readonly kind: "unresolved"; readonly detail: string };
+
+function classifyAttributeValue(value: unknown): AttributeValue {
+    // 短縮の真偽属性 (`<input type />`) は値を持たない
+    if (value === true) return { kind: "unresolved", detail: "属性が真偽短縮で値を持たない" };
+
+    const parts = Array.isArray(value) ? value : [value];
+    const nodes: AstNode[] = [];
+    for (const part of parts) {
+        if (!isAstNode(part)) {
+            return { kind: "unresolved", detail: "属性値の AST を解決できない" };
+        }
+        nodes.push(part);
+    }
+    if (nodes.every((node) => node.type === "Text")) {
+        return { kind: "static", text: nodes.map((node) => node.data ?? "").join("") };
+    }
+    if (nodes.some((node) => node.type === "ExpressionTag")) return { kind: "expression" };
+
+    return { kind: "unresolved", detail: `属性値に未知のノード (${nodes.map((n) => n.type).join(",")})` };
+}
+
+/** 名前付き属性を集める (重複は呼び出し側が fail-closed で扱う)。 */
+function attributesNamed(node: AstNode, name: string): AstNode[] {
+    return (node.attributes ?? []).filter(
+        (attr) => attr.type === "Attribute" && attr.name === name,
+    ) as AstNode[];
+}
+
+const ELEMENT_NAME_INPUT = "input";
+
+/** 1 ファイルを走査した中間結果 (序数は付与前)。 */
+interface FileScan {
+    readonly nativeInputCount: number;
+    readonly fileInputs: readonly { readonly start: number; readonly syntax: "static-text" | "expression"; readonly literal: string | null }[];
+    readonly diagnostics: readonly ScanDiagnostic[];
+    readonly rawHtml: readonly { readonly start: number }[];
+}
+
+function scanOneSource({ file, source }: SvelteSource): FileScan {
+    let ast: { fragment: unknown };
+    try {
+        ast = parse(source, { modern: true });
+    } catch (error) {
+        return {
+            nativeInputCount: 0,
+            fileInputs: [],
+            diagnostics: [
+                {
+                    file,
+                    reason: "parse-failed",
+                    at: null,
+                    detail: error instanceof Error ? error.message : String(error),
+                },
+            ],
+            rawHtml: [],
+        };
+    }
+
+    let nativeInputCount = 0;
+    const fileInputs: { start: number; syntax: "static-text" | "expression"; literal: string | null }[] = [];
+    const diagnostics: ScanDiagnostic[] = [];
+    const rawHtml: { start: number }[] = [];
+
+    const diagnose = (reason: ScanDiagnosticReason, start: number, detail: string): void => {
+        diagnostics.push({ file, reason, at: positionAt(source, start), detail });
+    };
+
+    eachNode(ast.fragment, (node) => {
+        const start = node.start ?? 0;
+
+        if (node.type === "HtmlTag") {
+            rawHtml.push({ start });
+
+            return;
+        }
+
+        // --- 要素の側: native input を作りうる形を確定する ---
+        if (node.type === "RegularElement") {
+            if ((node.name ?? "").toLowerCase() !== ELEMENT_NAME_INPUT) return;
+        } else if (node.type === "SvelteElement") {
+            const tag = node.tag;
+            if (!tag || tag.type !== "Literal" || typeof tag.value !== "string") {
+                diagnose(
+                    "unresolved-native-element",
+                    start,
+                    "<svelte:element this={…}> の要素名を静的に確定できない (実行時に input になりうる)",
+                );
+
+                return;
+            }
+            if (tag.value.toLowerCase() !== ELEMENT_NAME_INPUT) return;
+        } else {
+            return;
+        }
+
+        nativeInputCount++;
+
+        // --- spread は type / accept を上書きしうるので、他の判定より先に落とす ---
+        if ((node.attributes ?? []).some((attr) => attr.type === "SpreadAttribute")) {
+            diagnose("spread-attribute", start, "spread 属性が type / accept を上書きしうる");
+
+            return;
+        }
+
+        // --- type の側 ---
+        const typeAttributes = attributesNamed(node, "type");
+        // 属性が無い = HTML 既定の text なので母集団外
+        if (typeAttributes.length === 0) return;
+        if (typeAttributes.length > 1) {
+            diagnose("unresolved-type", start, "type 属性が複数あり、どれが効くか確定できない");
+
+            return;
+        }
+        const typeValue = classifyAttributeValue(typeAttributes[0].value);
+        if (typeValue.kind !== "static") {
+            diagnose(
+                "unresolved-type",
+                start,
+                typeValue.kind === "expression"
+                    ? "type 属性が式で、file かどうか確定できない"
+                    : typeValue.detail,
+            );
+
+            return;
+        }
+        if (typeValue.text.toLowerCase() !== "file") return;
+
+        // --- accept の側 (ここに来たものだけが母集団) ---
+        const acceptAttributes = attributesNamed(node, "accept");
+        if (acceptAttributes.length === 0) {
+            diagnose("missing-accept", start, "file input に accept 属性が無い");
+
+            return;
+        }
+        if (acceptAttributes.length > 1) {
+            diagnose("unresolved-accept", start, "accept 属性が複数あり、どれが効くか確定できない");
+
+            return;
+        }
+        const acceptValue = classifyAttributeValue(acceptAttributes[0].value);
+        if (acceptValue.kind === "unresolved") {
+            diagnose("unresolved-accept", start, acceptValue.detail);
+
+            return;
+        }
+        fileInputs.push(
+            acceptValue.kind === "static"
+                ? { start, syntax: "static-text", literal: acceptValue.text }
+                : { start, syntax: "expression", literal: null },
+        );
+    });
+
+    return { nativeInputCount, fileInputs, diagnostics, rawHtml };
+}
+
+/** 合成ソース (または読み込み済みファイル) の集合を走査する。 */
+export function scanSources(sources: readonly SvelteSource[]): FileInputScanResult {
+    const fileInputs: FileInputRecord[] = [];
+    const diagnostics: ScanDiagnostic[] = [];
+    const rawHtml: RawHtmlRecord[] = [];
+    let nativeInputCount = 0;
+
+    for (const entry of sources) {
+        const scan = scanOneSource(entry);
+        nativeInputCount += scan.nativeInputCount;
+        diagnostics.push(...scan.diagnostics);
+
+        // 序数はソース上の出現順で確定する (走査順ではなく offset で並べる)
+        [...scan.fileInputs]
+            .sort((a, b) => a.start - b.start)
+            .forEach((record, index) => {
+                fileInputs.push({
+                    file: entry.file,
+                    occurrence: index + 1,
+                    syntax: record.syntax,
+                    literal: record.literal,
+                });
+            });
+        [...scan.rawHtml]
+            .sort((a, b) => a.start - b.start)
+            .forEach((record, index) => {
+                rawHtml.push({
+                    file: entry.file,
+                    occurrence: index + 1,
+                    at: positionAt(entry.source, record.start),
+                });
+            });
+    }
+
+    return {
+        svelteFileCount: sources.length,
+        nativeInputCount,
+        fileInputs,
+        diagnostics,
+        rawHtml,
+    };
+}
+
+/** `root` 配下の `.svelte` を再帰列挙する。 */
+async function listSvelteFiles(root: string): Promise<string[]> {
+    const entries = await fs.readdir(root, { recursive: true, withFileTypes: true });
+    const files: string[] = [];
+    for (const entry of entries) {
+        if (!entry.isFile()) continue;
+        if (path.extname(entry.name) !== ".svelte") continue;
+        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? root;
+        files.push(path.join(parent, entry.name));
+    }
+
+    return files.sort();
+}
+
+/** 実リポジトリの走査根を読み込んで走査する (gate 用)。 */
+export async function scanFileInputs(root: string): Promise<FileInputScanResult> {
+    const files = await listSvelteFiles(root);
+    const sources: SvelteSource[] = [];
+    for (const absolute of files) {
+        sources.push({
+            file: path.relative(root, absolute).split(path.sep).join("/"),
+            source: await fs.readFile(absolute, "utf8"),
+        });
+    }
+
+    return scanSources(sources);
+}
diff --git a/tests/js/support/normalizeText.ts b/tests/js/support/normalizeText.ts
new file mode 100644
index 00000000..ed67149c
--- /dev/null
+++ b/tests/js/support/normalizeText.ts
@@ -0,0 +1,19 @@
+/**
+ * DOM の textContent を文言比較できる形へ正規化する。
+ *
+ * Svelte ソースの改行・インデントは textContent に空白として残るため、素の比較では
+ * 「文全体の完全一致」を書けない。連続する空白 (改行・タブを含む) を 1 つの半角空白へ
+ * 畳んで前後を trim した文字列同士で比較する。
+ *
+ * **保証しないもの**: 全角空白 (U+3000) と半角空白の違いは畳み込みの対象であり区別しない
+ * (`\s` が全角空白に一致するため)。句読点・全角半角の混在は正規化しない
+ * (それらは文面の一部として比較対象に残す)。
+ */
+export function normalizeText(value: string | null | undefined): string {
+    return (value ?? "").replace(/\s+/g, " ").trim();
+}
+
+/** 要素の textContent を正規化して返す (`normalizeText` の DOM 版)。 */
+export function normalizedTextOf(element: Element | null): string {
+    return normalizeText(element?.textContent);
+}
```
