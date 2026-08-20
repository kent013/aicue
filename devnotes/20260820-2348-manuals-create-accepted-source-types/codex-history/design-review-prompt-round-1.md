【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本設計の前史(重要)】
本設計は先行実装 T234「画像・スキャン SOP の OCR 対応」の**適用漏れの回収**である。
T234 の詳細設計 施策 10 が UI 変更対象を詳細画面の 2 ファイルへ限定していたため、
新規動画作成画面 `Manuals/Create.svelte` が漏れた。概念設計は同ディレクトリで
Codex レビュー Round 3 で APPROVED 済みで、そこで確定した判断は以下:
- 静的検査 (施策 5) は「accept 供給元区分の宣言漏れ」しか保証しない (由来は検証できない)。
  単一情報源との一致は Feature / component テストの 2 段の契約が担う。
- 受理形式の人間向けラベルは機械導出しない (法務確認済み文面をコードが生成しない)。
  乖離は前提の pin で検出する。
- gate の母集団は native input 全数から取り、動的 type / spread / parse 失敗は fail-closed。

---

## 詳細設計書

<!-- devnotes/20260820-2348-manuals-create-accepted-source-types/detailed-design.md の全文 -->
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
| 5 | 再発防止: file input の accept 供給元目録(deny-by-default) | `tests/js/support/file-input-accept-inventory.ts`(新規) / `tests/js/support/file-input-scan.ts`(新規) / `tests/js/architecture/file-input-accept-source-inventory.test.ts`(新規) / `tests/js/architecture/file-input-scan.test.ts`(新規) / `AGENTS.md` | 低 |

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
        `['pdf', 'xlsx', 'xls', 'txt']` ちょうどであること /
        フラグ true の `extensions()` からフラグ false の `extensions()` を差し引いた集合が
        `['jpg', 'jpeg', 'png']` ちょうどであること。
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
      - **作成画面と詳細画面の props が同値**であること(2 面が違う値を返す形を禁じる。
        リテラル pin だけだと「両方とも同じ間違い」を検出できるが、
        「面ごとに違う」ケースはこの assert が担う)
      - 422 文言が `AcceptedSourceDocumentTypes::formatsLabel()` から組まれた
        **完全一致**の文であること(施策 1 のラベルと 422 の結線)
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
        一般案内は**文全体**で比較する)
- [ ] `SourceDocumentUpload.test.ts`(既存を**拡張のみ**。既存 assert を消さない):
      - 既存 2 ケース(accept / 案内の出し分け)が緑のままであること
      - **表示順**: `source-document-send-notice` が `source-document-input` より
        DOM 順で前にあること(`compareDocumentPosition` か
        `container.querySelectorAll` の順序で判定)
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
- [ ] 表示順: 一般案内が `manual-document-input` より DOM 順で前にあること
- [ ] 既存 5 ケース(見出し・カテゴリ選択・submit が disabled でない・カテゴリ 0 件・
      title の clearErrors 2 件)は**変更しない**(props 追加のみで緑を維持)
- [ ] `pnpm typecheck` が緑(Props の 3 件が Inertia props と一致していること)

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
ために起きた。この gate が止めるのは **「accept の供給元区分の宣言漏れ」だけ**である。

> **保証しないこと**: `accept={sourceDocumentAccept}` という形を見ても、その識別子の値が
> `AcceptedSourceDocumentTypes` 由来であることは**証明できない**(Inertia props は実行時に
> 注入されるため静的検査の到達範囲外。同名の別の値を入れても静的層は黙る)。
> 単一の情報源との一致は**施策 2 の Feature テストと施策 3/4 の component テスト**が担う。
> また `.svelte` 以外(TS から `document.createElement('input')` する経路)・
> Blade テンプレート・実行時に `accept` を書き換える形には**無言で効かない**。

### 変更箇所

- **新規** `tests/js/support/file-input-scan.ts`: 走査器。
  `svelte/compiler` の `parse(source, { modern: true })` で AST を取り、
  native `input` 要素を全数集めて分類する。
- **新規** `tests/js/support/file-input-accept-inventory.ts`: 目録(deny-by-default)。
- **新規** `tests/js/architecture/file-input-accept-source-inventory.test.ts`: gate。
- **新規** `tests/js/architecture/file-input-scan.test.ts`: 走査器の自己検査(負例・正例)。
- `AGENTS.md` ドメイン固有規約へ 1 項追加(新しいアップロード面を足す人が
  目録を更新する義務を書く。**保証しないものの正本は走査器の docblock**とし、
  AGENTS.md には写さない = 2 か所に書くと必ず食い違う、の既存方針に従う)。

### 母集団の取り方(fail-closed。conceptual-review Round 2 Critical 対応)

走査対象は `resources/js` 配下の **`.svelte` 全ファイルの native `input` 要素の全数**。

| `type` 属性の形 | 扱い |
|---|---|
| 属性が無く spread も無い | 対象外(HTML 既定は text) |
| 静的に file 以外(`type="text"` 等) | 対象外 |
| 静的に `file`(**ASCII 大文字小文字を区別しない**。`type="FILE"` も file) | 目録の対象 |
| 式・短縮記法(`type` 単独)・複数パート連結で確定できない(`type={kind}` / `type={"file"}`) | **未解決 → gate 失敗**(「非 file」と決めつけない) |
| spread 属性(`{...attrs}`)が同一要素に存在 | **失敗**(`type` / `accept` を上書きできる) |
| file input に `accept` 属性が無い | **失敗** |
| ファイルの parse に失敗 | **失敗** |

`accept` の分類:

- 値が単一の静的テキスト → `client-literal`(目録に 30 文字以上の理由が必須)
- 値が式・複数パート → `dynamic`(値は実行時に決まる。**由来は検証しない**)
- 値が無い / 短縮記法で確定できない → **失敗**

### 目録の初期状態(現在値ちょうど)

| ファイル | 区分 | 理由 |
|---|---|---|
| `resources/js/components/features/manual/SourceDocumentUpload.svelte` | `dynamic` | SOP の受理形式はサーバの `AcceptedSourceDocumentTypes` が単一の情報源で props 経由で来る |
| `resources/js/pages/Manuals/Create.svelte` | `dynamic` | 同上(作成と同時の SOP アップロード) |
| `resources/js/components/features/capture/CaptureFileFallback.svelte` | `client-literal` | 撮影テイクの入力は静止画 `image/*` / 動画 `video/*` の 2 択で、SOP の受理形式とは別概念のため固定値が正しい |
| `resources/js/components/features/manual/TakeFileUpload.svelte` | `client-literal` | 同上(テイクの後付けアップロード) |

**件数を完全一致で pin する**(4 件。増えても減っても赤)。
エントリの鍵は `file` + `occurrence`(ファイル内の file input の 1 始まりの序数)。
今はどのファイルも 1 件ずつだが、同一ファイルに 2 つ置ける形を最初から表現しておく
(序数は並べ替えに弱いが、ずれたら赤くなるので fail-closed 側に倒れる。
この限界は docblock に書く)。

### 変更後コード(骨子)

```ts
// tests/js/support/file-input-accept-inventory.ts
/** accept の供給元区分。dynamic は「静的に確定できない値」の意味で、由来は検証しない。 */
export type AcceptSourceKind = "dynamic" | "client-literal";

export interface FileInputAcceptEntry {
    readonly file: string; // resources/js からの相対パス
    readonly occurrence: number; // ファイル内の file input の序数 (1 始まり)
    readonly kind: AcceptSourceKind;
    readonly rationale: string; // client-literal は 30 文字以上
}

export const FILE_INPUT_ACCEPT_INVENTORY: readonly FileInputAcceptEntry[] = [ /* 上表の 4 件 */ ];

/** 母集団の件数の pin (増減のどちらでも赤くする)。 */
export const FILE_INPUT_COUNT = 4;
```

```ts
// tests/js/support/file-input-scan.ts (骨子)
/**
 * `resources/js` 配下の .svelte から native `input` 要素を全数集め、
 * file input の accept 供給元を分類する走査器。
 *
 * 走査対象: git 追跡下かどうかは見ない (resources/js 配下の *.svelte 全件)。
 * 解決に `svelte/compiler` の parse(modern) を使う。
 *
 * 保証しないもの:
 * - accept の値の**由来**(props がサーバの単一情報源から来ているか)は判定できない
 * - .svelte 以外 (TS の createElement)・Blade・実行時の属性書き換えは見えない
 * - occurrence はファイル内の出現順なので、並べ替えでも差分が出る (fail-closed 側)
 */
export type FileInputClassification =
    | { kind: "dynamic"; file: string; occurrence: number }
    | { kind: "client-literal"; file: string; occurrence: number; value: string }
    | { kind: "unresolved"; file: string; occurrence: number; reason: UnresolvedReason };

export type UnresolvedReason =
    | "spread-attribute"
    | "dynamic-type"
    | "shorthand-type"
    | "missing-accept"
    | "dynamic-accept-shorthand"
    | "parse-failed";

export interface FileInputScanResult {
    readonly nativeInputCount: number; // 母集団非空の検査用 (input 要素の全数)
    readonly classifications: readonly FileInputClassification[];
}
```

```ts
// tests/js/architecture/file-input-accept-source-inventory.test.ts (検査の骨子)
// 1. 走査根が存在し、native input が 1 件以上ある (母集団非空 その 1)
// 2. file input が 1 件以上ある (母集団非空 その 2 — 別々に検査する)
// 3. 未解決 (unresolved) が 0 件
// 4. 検出集合と目録が完全一致 (両方向。file+occurrence を鍵に)
// 5. 各 entry の kind が実測の分類と一致する
// 6. client-literal の rationale が 30 文字以上
// 7. 件数が FILE_INPUT_COUNT ちょうど
```

### 検出力の裏取り(共通規約 (c)。走査器の自己検査)

`tests/js/architecture/file-input-scan.test.ts` に**合成入力**を置く(実ファイルを汚さない)。

負例(いずれも `unresolved` になること):

1. `<input type="file" accept="x" {...attrs} />` → `spread-attribute`
2. `<input type={kind} />` → `dynamic-type`
3. `<input type />` → `shorthand-type`
4. `<input type="file" />` → `missing-accept`
5. `<input type={"file"}
   accept="x" />` → `dynamic-type`(式は評価しない方針)
6. `<input {...attrs} />` → `spread-attribute`
7. 壊れた Svelte 構文 → `parse-failed`

正例(誤検出しないこと):

8. `<input type="text" />` / 属性なしの `<input />` → 母集団に入らない
9. `<input type="file" accept={x} />` → `dynamic`
10. `<input type="FILE" accept="image/*" />` → `client-literal`(大文字も file 扱い)
11. `<input type="file" accept="image/*,video/*" />` → `client-literal`

### テスト計画

- [ ] 先に赤くする: 目録を**空**にして gate を走らせ、実測 4 件との不一致で赤くなることを確認
      (deny-by-default が効いていることの確認)。次に走査器の負例テストを先に書く
- [ ] 上記 11 ケースの自己検査(負例 7 / 正例 4)
- [ ] gate 本体の 7 検査
- [ ] `pnpm test` が正本のレーンであることを docblock に書く
      (`composer test` では JS gate は走らない)
- [ ] `AGENTS.md` の追記(1 項)。**件数・保証しないものは写さない**

### リスク

- **維持コスト**: 新しいアップロード面を足すたびに目録の 1 行と件数を更新する必要がある。
  これは意図した摩擦(単一の情報源へ繋ぐ判断をレビューに見せる)。
- **`svelte/compiler` の AST 形状は major 更新で変わりうる**。
  変わったら parse 結果の型不一致でテストが落ちる(無言で緑にはならない)。
  自己検査の合成入力が最初に落ちるため、故障は検出可能。
- 序数(occurrence)は並べ替えに弱い。ずれたら赤くなるだけなので安全側。
- **この gate は本設計の本体(施策 1〜4)とは独立**である。実装順で最後に置き、
  もし規約 5 条を満たす形に収まらないと判明したら**作らない判断も可**
  (安直な文字列 grep 版に劣化させない)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 5 施策すべてが既存ファイルの局所変更 + 新規 3 ファイル(Svelte 1 / テスト支援 2)で、migration・DTO 追加・route 追加を伴わない。施策 1→2→3→4 は同一 PR 内で順に積む前提の依存関係があり、間で緑を保てる(施策 1 は既存 422 文言テストが、施策 2 は Feature テストが、施策 3/4 は component / page テストが各段でカバーする)。施策 5 は独立で、最後に足せる |
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

## 関連する現行コード

### app/Http/Controllers/Projects/VideoManualController.php (create() と show() の props 部分)
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

// … show() の末尾 (既存の props)
            'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
            // SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // AcceptedSourceDocumentTypes が単一の情報源 (フラグに連動)
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
        ]);
    }

```

### app/Support/Manual/AcceptedSourceDocumentTypes.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Support\Manual;

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
}
```

### app/Http/Requests/Projects/StoreVideoManualRequest.php (rules / messages)
```php

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return array_merge([
            'title' => ['required', 'string', 'max:200'],
            'category' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('project_id', $projectId),
            ],
            // SOP 同時アップロード (任意。multipart)。保存時は Service が内容 sniff で再判定する
            'document' => [
                'nullable',
                'file',
                'mimes:'.implode(',', AcceptedSourceDocumentTypes::extensions()),
                new SourceDocumentSizeLimit,
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * mimes ルールの汎用文言を、現在受理している形式の案内へ差し替える
     * (画像・スキャン SOP の OCR 対応。`StoreSourceDocumentRequest` と同じ方針)。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $formats = AcceptedSourceDocumentTypes::imagesEnabled()
            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
            : 'PDF・Excel・テキスト形式';

        return [
            'document.mimes' => "対応していないファイル形式です。{$formats}でアップロードし直してください。",
        ];
    }
}
```

### app/Http/Requests/Projects/StoreSourceDocumentRequest.php (messages)
```php

    /**
     * mimes ルールの汎用文言を、現在受理している形式の案内へ差し替える
     * (画像・スキャン SOP の OCR 対応。HEIC 等の非対応形式で「JPEG / PNG で保存し直す」
     * という次アクションを示す。受理形式はフラグに連動するため固定文言にしない)。
     *
     * @return array<string, string>
     */
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

### resources/js/pages/Manuals/Create.svelte (全文)
```svelte
<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import { BookOpen } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CategoryOption } from "@/types/manual";

    /**
     * 動画マニュアル作成 (タイトル + カテゴリ + 任意の手順書アップロード)。
     * カテゴリの入力名は保護キー category_id と別名の `category` (id 値)。
     * 空選択 = 未分類 (null で送信)。document は multipart で任意送信。
     */
    interface Props {
        project: { id: number; name: string };
        categories: CategoryOption[];
    }

    let { project, categories }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const form = useForm<{ title: string; category: string; document: File | null }>({
        title: "",
        category: "",
        document: null,
    });

    function onFileChange(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        form.document = input.files?.[0] ?? null;
    }

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.transform((data) => ({
            title: data.title,
            category: data.category === "" ? null : Number(data.category),
            document: data.document,
        })).post(`/projects/${project.id}/manuals`);
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="動画マニュアルの作成"
            description={`${project.name} に新しい動画マニュアルを作成します。`}
            icon={BookOpen}
            testId="manual-create-heading"
        />
        <PageContent>
            <Card padding="lg">
                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                    <FormField label="タイトル" id="manual-title" error={form.errors.title} required>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={form.title}
                                error={invalid}
                                aria-describedby={describedBy}
                                oninput={() => {
                                    // 入力し始めたらその場でタイトルエラーをクリア (次 submit を待たない)
                                    if (form.errors.title) form.clearErrors("title");
                                }}
                            />
                        {/snippet}
                    </FormField>
                    <FormField label="カテゴリ" id="manual-category" error={form.errors.category}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Select
                                {id}
                                bind:value={form.category}
                                error={invalid}
                                aria-describedby={describedBy}
                                testId="manual-category-select"
                            >
                                <option value="">未分類</option>
                                {#each categories as category (category.id)}
                                    <option value={String(category.id)}>{category.name}</option>
                                {/each}
                            </Select>
                        {/snippet}
                    </FormField>
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
                                aria-describedby={describedBy}
                                aria-invalid={invalid}
                                class="block w-full text-body text-text file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-caption file:text-text"
                                data-testid="manual-document-input"
                            />
                        {/snippet}
                    </FormField>
                    <div class="flex items-center gap-2">
                        <Button type="submit" loading={form.processing} testId="manual-submit">
                            作成
                        </Button>
                        <Button variant="ghost" href={`/projects/${project.id}`} inertia>
                            キャンセル
                        </Button>
                    </div>
                </form>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
```

### resources/js/components/features/manual/SourceDocumentUpload.svelte (全文)
```svelte
<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormField from "@/components/molecules/FormField.svelte";

    /**
     * SOP (手順書) の後付けアップロード (POST .../source-documents。Inertia multipart form)。
     * 追記型 immutable: アップロードは常に新しい行を追加する (差し替え = 最新が解析対象)。
     *
     * 受理形式・画像対応の出し分けは `AcceptedSourceDocumentTypes` (画像・スキャン SOP の
     * OCR 対応) をサーバ側の単一の情報源として渡された Props に従う
     * (フロント側で文字列を解析して画像対応可否を判定しない)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        hasDocument: boolean;
        sourceDocumentAccept: string;
        imageSourceDocumentsEnabled: boolean;
    }

    let { projectId, manualId, hasDocument, sourceDocumentAccept, imageSourceDocumentsEnabled }: Props = $props();

    const form = useForm<{ document: File | null }>({ document: null });

    function onFileChange(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        form.document = input.files?.[0] ?? null;
    }

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post(`/projects/${projectId}/manuals/${manualId}/source-documents`, {
            onSuccess: () => form.reset(),
        });
    }
</script>

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
    <FormField
        label={hasDocument ? "手順書を差し替える" : "手順書 (SOP) をアップロード"}
        id="source-document"
        error={form.errors.document}
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
                data-testid="source-document-input"
            />
        {/snippet}
    </FormField>
    <div>
        <Button type="submit" variant="secondary" loading={form.processing} testId="source-document-submit">
            アップロード
        </Button>
    </div>
</form>
```

### resources/js/components/molecules/FormField.svelte (全文)
```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import FormError from "@/components/atoms/FormError.svelte";

    /**
     * ラベル + 入力 + エラー + ヘルプの複合 molecule。
     *
     * 入力 atom (Input/Textarea/Select) は最小責務に保ち、ラベル・エラー文言・
     * aria-describedby の配線は本 molecule が担う (関心分離)。
     * children snippet に { id, describedBy, invalid } を渡すので、呼び出し側は
     * それを入力 atom へ流し込む。
     *
     * 使用例:
     *   <FormField label="名前" id="name" required error={form.errors.name}>
     *       {#snippet children({ id, describedBy, invalid })}
     *           <Input {id} bind:value={form.name} error={invalid} aria-describedby={describedBy} />
     *       {/snippet}
     *   </FormField>
     */
    interface Props {
        label: string;
        id: string;
        error?: string | null;
        help?: string;
        required?: boolean;
        children: Snippet<[{ id: string; describedBy: string | undefined; invalid: boolean }]>;
    }

    let { label, id, error, help, required = false, children }: Props = $props();

    const errorId = $derived(error ? `${id}-error` : undefined);
    const helpId = $derived(help ? `${id}-help` : undefined);
    const describedBy = $derived(
        [errorId, helpId].filter(Boolean).join(" ") || undefined,
    );
</script>

<div class="flex flex-col gap-1.5">
    <label for={id} class="text-caption font-medium text-text">
        {label}
        {#if required}<span class="text-danger" aria-hidden="true">*</span>{/if}
    </label>
    {@render children({ id, describedBy, invalid: Boolean(error) })}
    {#if help}
        <p id={helpId} class="text-caption text-text-secondary">{help}</p>
    {/if}
    <FormError id={errorId} message={error} />
</div>
```

### tests/js/pages/ManualsCreate.test.ts (accept を pin している箇所)
```ts
const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
};

/** 反応的フェイクフォームを毎テスト用意する (errors は $state で clearErrors 再描画を観測可能) */
function setupForm(errors: Record<string, string> = {}): void {
    formState.current = reactiveUseForm(
        { title: "", category: "", document: null as File | null },
        errors,
    );
}

    it("手順書 (SOP) のファイル入力を描画する (任意・accept 制限付き)", () => {
        render(Create, { props: baseProps });

        const input = screen.getByTestId("manual-document-input");
        expect(input).toBeInTheDocument();
        expect(input.getAttribute("type")).toBe("file");
        expect(input.getAttribute("accept")).toBe(".pdf,.xlsx,.xls,.txt");
    });

    it("タイトル入力 (oninput) でタイトルエラーがその場でクリアされる", async () => {
```

### tests/js/components/features/manual/SourceDocumentUpload.test.ts (全文)
```ts
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import SourceDocumentUpload from "@/components/features/manual/SourceDocumentUpload.svelte";

/*
 * SOP アップロード (画像・スキャン SOP の OCR 対応。施策 1/10):
 * - accept 属性はサーバ Props (sourceDocumentAccept) をそのまま使う (フロントで解析しない)
 * - 送信案内 (外部 LLM provider への送信) は imageSourceDocumentsEnabled の真偽に関わらず常時表示
 * - OCR 固有の警告・1 枚制約の明示は imageSourceDocumentsEnabled=true のときだけ表示
 */

vi.mock("@inertiajs/svelte", () => ({
    useForm: () => ({ document: null, errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
}));

afterEach(() => {
    cleanup();
});

const baseProps = {
    projectId: 1,
    manualId: 5,
    hasDocument: false,
};

describe("SourceDocumentUpload", () => {
    it("imageSourceDocumentsEnabled=false では accept が画像を含まず OCR 固有文言が出ない", () => {
        render(SourceDocumentUpload, {
            props: {
                ...baseProps,
                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt",
                imageSourceDocumentsEnabled: false,
            },
        });

        const input = screen.getByTestId("source-document-input") as HTMLInputElement;
        expect(input.accept).toBe(".pdf,.xlsx,.xls,.txt");
        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
        // 一般的な外部送信案内は false のときも表示され続ける
        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent("外部の LLM provider");
    });

    it("imageSourceDocumentsEnabled=true では accept に画像拡張子を含み OCR 固有文言が出る", () => {
        render(SourceDocumentUpload, {
            props: {
                ...baseProps,
                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
                imageSourceDocumentsEnabled: true,
            },
        });

        const input = screen.getByTestId("source-document-input") as HTMLInputElement;
        expect(input.accept).toBe(".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png");
        expect(screen.getByTestId("source-document-image-notice")).toHaveTextContent("1 手順書につき 1 枚");
        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent("外部の LLM provider");
    });
});
```

### tests/Feature/Projects/SourceDocumentUploadOcrTest.php (公開面の一貫性テスト)
```php
test('公開面の一貫性: FormRequest / Service / Inertia Props がフラグに応じて同じ集合を表す', function (): void {
    Storage::fake();
    [, $owner, $project] = ocrUploadContext();

    foreach ([false, true] as $flag) {
        config()->set('manual.ocr_analysis_enabled', $flag);
        // 各分岐を独立したマニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
        $httpManual = VideoManual::factory()->forProject($project)->create();
        $serviceManual = VideoManual::factory()->forProject($project)->create();

        // FormRequest: jpg 受理可否
        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/manuals/{$httpManual->id}/source-documents",
            ['document' => fakeJpegFile("sop-{$flag}.jpg")],
        );
        if ($flag) {
            $response->assertRedirect();
        } else {
            $response->assertUnprocessable();
        }

        // Service: allowedMimeTypes に image/jpeg が含まれるか (appendDocument の成否で確認)
        if ($flag) {
            $doc = app(SourceDocumentService::class)->appendDocument(
                $serviceManual,
                fakeJpegFile("service-{$flag}.jpg"),
            );
            expect($doc->mime)->toBe('image/jpeg');
        } else {
            expect(fn () => app(SourceDocumentService::class)->appendDocument(
                $serviceManual,
                fakeJpegFile("service-{$flag}.jpg"),
            ))->toThrow(ValidationException::class);
        }

        // Inertia Props: sourceDocumentAccept / imageSourceDocumentsEnabled
        $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('imageSourceDocumentsEnabled', $flag)
                ->where('sourceDocumentAccept', $flag
                    ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
                    : '.pdf,.xlsx,.xls,.txt'));
    }
});
```

### tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php (全文)
```php
<?php

declare(strict_types=1);

use App\Support\Manual\AcceptedSourceDocumentTypes;

/*
 * AcceptedSourceDocumentTypes (画像・スキャン SOP の OCR 対応): 受理する SourceDocument
 * 形式の唯一の情報源。フラグ true/false それぞれの extensions()/mimes()/
 * acceptAttribute()/imagesEnabled() を固定する。
 */

test('フラグ false のとき画像を含まない', function (): void {
    config()->set('manual.ocr_analysis_enabled', false);

    expect(AcceptedSourceDocumentTypes::extensions())->toBe(['pdf', 'xlsx', 'xls', 'txt']);
    expect(AcceptedSourceDocumentTypes::mimes())->toBe([
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/plain',
    ]);
    expect(AcceptedSourceDocumentTypes::acceptAttribute())->toBe('.pdf,.xlsx,.xls,.txt');
    expect(AcceptedSourceDocumentTypes::imagesEnabled())->toBeFalse();
});

test('フラグ true のとき画像 (jpg/jpeg/png) を含む', function (): void {
    config()->set('manual.ocr_analysis_enabled', true);

    expect(AcceptedSourceDocumentTypes::extensions())->toBe(['pdf', 'xlsx', 'xls', 'txt', 'jpg', 'jpeg', 'png']);
    expect(AcceptedSourceDocumentTypes::mimes())->toBe([
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/plain',
        'image/jpeg',
        'image/png',
    ]);
    expect(AcceptedSourceDocumentTypes::acceptAttribute())->toBe('.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png');
    expect(AcceptedSourceDocumentTypes::imagesEnabled())->toBeTrue();
});

test('webp/gif はフラグに関わらず含まれない (スコープ外)', function (): void {
    config()->set('manual.ocr_analysis_enabled', true);

    expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('webp');
    expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('gif');
    expect(AcceptedSourceDocumentTypes::mimes())->not->toContain('image/webp');
    expect(AcceptedSourceDocumentTypes::mimes())->not->toContain('image/gif');
});
```

### 他の file input 2 件 (撮影側)
```svelte
<!-- features/capture/CaptureFileFallback.svelte -->
    let input: HTMLInputElement | null = $state(null);
    let error = $state<string | null>(null);

    const isStill = $derived(material === "still");
    const accept = $derived(isStill ? "image/*" : "video/*");

    async function handleChange(): Promise<void> {
        error = null;
        const file = input?.files?.[0];
<div class="flex flex-col items-center gap-3 py-6">
    <input
        bind:this={input}
        type="file"
        {accept}
        capture="environment"
        class="hidden"
        onchange={handleChange}
        data-testid="capture-file-input"
<!-- features/manual/TakeFileUpload.svelte -->
    -->
    <input
        bind:this={input}
        type="file"
        accept={isStill ? "image/*" : "video/*"}
        class="hidden"
        onchange={handleChange}
        data-testid="take-file-input"
    />
    <div class="mt-3">
        <Button
```
