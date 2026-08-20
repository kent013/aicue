# Round 2: Round 1 の指摘への対応と再レビュー依頼

Round 1 の Critical 2 件 / Warning 5 件 / Suggestion 4 件をすべて捌きました。
対応マトリクスの要点は以下で、詳細設計の該当節は全文を再掲します。

## 対応の要点

1. **[Critical] 施策 5 の分類が実コードと矛盾** → 指摘は事実でした。
   `svelte/compiler` の `parse(src, { modern: true })` で**実測**して確認しました:
   - `accept={isStill ? "image/*" : "video/*"}` → `Attribute` + `ExpressionTag`
   - `{accept}` 短縮記法 → `Attribute` + `ExpressionTag`
   - `accept="a{b}c"` → `Attribute` + `[Text, ExpressionTag, Text]`
   - `<input type />` → `Attribute` / value = `true`
   - `{...attrs}` → `SpreadAttribute`
   - 閉じない tag → `InternalCompileError` (`unexpected_eof`)
   ご提案どおり**2 軸**へ分離しました (実測構文 `syntax` / 人が宣言する `supply`)。
2. **[Critical] `parse-failed` に序数を持たせられない** → 走査結果を
   `fileInputs` (序数あり) と `diagnostics` (ファイル名 + 理由 + AST 位置 or null) に分離。
3. **[Warning] gate 判定分岐の負例が無い** → 判定を純関数
   `evaluateFileInputInventory()` へ分離し、合成入力で 9 ケース。
4. **[Warning] rationale は全エントリ必須** → 一意性・正整数・件数 3 点一致も判定関数へ。
5. **[Warning] `StoreVideoManualRequest` の結線テスト** → `projects.manuals.store` へ
   非対応形式を送る Feature テストを両フラグで追加 (期待文は `formatsLabel()` から組む)。
6. **[Warning] props 同値の対象** → 両面に存在する 2 件だけであることを明記。
7. **[Warning] 施策 3 の親子構造** → `parentElement` を検証 + 空白正規化した全文一致。
8. **[Suggestion]** 前提 pin を順序込み完全一致へ / 保証の分担表を追加 /
   help を全文一致で固定 / 新規ファイル件数を 5 件へ訂正。

なお **施策 1・4 は Round 1 で APPROVE** をいただいたため、
Suggestion の反映 (前提 pin の完全一致化・保証の分担表・help の全文一致) だけを加え、
設計そのものは変えていません。

---

## 変更後の詳細設計 (全文)

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
      - 422 文言が `AcceptedSourceDocumentTypes::formatsLabel()` から組まれた
        **完全一致**の文であること(施策 1 のラベルと 422 の結線)
- [ ] **`StoreVideoManualRequest` 側の結線を独立に固定する**
      (design-review Round 1 Warning 対応。これが無いと、施策 1 で片方の FormRequest の
      置換を忘れても緑になりうる — 既存の 422 文言テストは**後付けアップロード経路
      (`StoreSourceDocumentRequest`) だけ**を通っている):
      - `POST projects.manuals.store` に**有効な `title`** と非対応形式のファイルを送り、
        `document.mimes` だけを発火させる
      - フラグ **false**: jpeg を送って `document` の 422 文言が
        `'対応していないファイル形式です。'.AcceptedSourceDocumentTypes::formatsLabel().'でアップロードし直してください。'`
        と**完全一致**すること
      - フラグ **true**: heic を送って同じ形で**完全一致**すること
      - 期待文は**リテラルを書かず `formatsLabel()` から組み立てる**
        (ラベルと 422 の結線を確認するのが目的。文面そのものの pin は施策 1 の Unit テスト)
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

### 変更箇所

- **新規** `tests/js/support/file-input-scan.ts`: 走査器。
  `svelte/compiler` の `parse(source, { modern: true })` で AST を取り、
  native `input` 要素を全数集めて分類する。
- **新規** `tests/js/support/file-input-accept-inventory.ts`: 目録(deny-by-default)。
  **判定の純関数** `evaluateFileInputInventory()` もここに置く(gate から分離して
  自己検査可能にする。design-review Round 1 Warning 対応)。
- **新規** `tests/js/architecture/file-input-accept-source-inventory.test.ts`: gate(実リポジトリを走査)。
- **新規** `tests/js/architecture/file-input-scan.test.ts`:
  走査器と判定関数の自己検査(負例・正例。合成入力のみで実ファイルに依存しない)。
- `AGENTS.md` ドメイン固有規約へ 1 項追加(新しいアップロード面を足す人が
  目録を更新する義務を書く。**保証しないものの正本は走査器の docblock**とし、
  AGENTS.md には写さない = 2 か所に書くと必ず食い違う、の既存方針に従う)。

### 母集団の取り方(fail-closed)

走査対象は `resources/js` 配下の **`.svelte` 全ファイルの native `input` 要素の全数**。
AST 上の形と扱いは次のとおり(**svelte 5.56 で実測して確認した形**を根拠にする):

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
    | "parse-failed"      // ファイル単位
    | "unresolved-type"   // 式・真偽短縮・複数パート
    | "spread-attribute"  // type/accept を上書きしうる
    | "missing-accept"
    | "unresolved-accept";

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
    readonly diagnostics: readonly ScanDiagnostic[];
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

/** gate の判定本体 (純関数。合成入力で自己検査できるよう gate から分離する)。 */
export function evaluateFileInputInventory(
    scan: FileInputScanResult,
    inventory: readonly FileInputAcceptEntry[],
    countPin: number,
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

1. 走査根が存在し、`.svelte` ファイルが 1 件以上ある(走査が生きている)
2. `nativeInputCount >= 1`(母集団非空 その 1)
3. `fileInputs.length >= 1`(母集団非空 その 2。**2 と別々に検査する**)
4. `diagnostics` が 0 件(未解決を 1 つも許さない = fail-closed)
5. `evaluateFileInputInventory(scan, FILE_INPUT_ACCEPT_INVENTORY, FILE_INPUT_COUNT)` が空
   (判定は純関数へ委譲する = gate 側に判定ロジックを二重に書かない)

`evaluateFileInputInventory()` が返す違反の種類:

- 目録に無い実測(**未登録**)/ 実測に無い目録(**残置**)
- `syntax` の不一致(宣言と実測が違う)
- 目録キー(`file` + `occurrence`)の**重複**
- `occurrence` が正の整数でない
- `rationale` が 30 文字未満(**`supply` の値に関わらず全エントリ**。
  design-review Round 1 Warning 対応: `client-owned` だけを検査すると
  `server-prop` を空理由で通せてしまう)
- 件数 pin の不一致(実測件数 / 目録配列長 / 一意キー数の**3 つとも** `countPin` と一致)

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

**(B) 判定関数(gate 分岐)の負例・正例**(合成 `FileInputScanResult` + 合成目録):

16. 適合する組 → 違反 0 件(正例)
17. 目録が 1 件不足 → 未登録の違反
18. 目録に実在しない 1 件 → 残置の違反
19. `syntax` 不一致 → 違反
20. 同じ `file` + `occurrence` が 2 件 → 重複の違反
21. `rationale` が 29 文字(`supply` が `server-prop` の側で試す) → 違反
22. `occurrence` が `0` → 違反
23. 件数 pin が実測と 1 件ずれ → 違反
24. `nativeInputCount = 0` / `fileInputs = []` → それぞれ母集団空の違反

> **(B) を置く理由**: (A) だけでは「実リポジトリが偶然適合しているせいで
> gate の比較分岐が壊れていても緑」という状態を検出できない
> (design-review Round 1 Warning)。

### テスト計画(テストファースト)

- [ ] 先に赤くする: 目録を**空**にして gate を走らせ、実測 4 件との不一致で赤くなること
      (deny-by-default が効いていることの確認)
- [ ] 走査器の負例・正例 15 ケース((A) の 1〜15)
- [ ] 判定関数の負例・正例 9 ケース((B) の 16〜24)
- [ ] gate 本体の 5 検査
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
- **この gate は本設計の本体(施策 1〜4)とは独立**である。実装順で最後に置き、
  もし規約 5 条を満たす形に収まらないと判明したら**この施策だけを落とす判断も可**
  (安直な文字列 grep 版に劣化させない。design-review Round 1 の指摘と同じ結論)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 5 施策すべてが既存ファイルの局所変更 + **新規 5 ファイル**(共有 Svelte 1 / テスト支援 2 / architecture テスト 2。施策 3 の component テスト 1 件を加えると新規テストは 3 件。design-review Round 1 Suggestion 対応で件数を訂正)で、migration・DTO 追加・route 追加を伴わない。施策 1→2→3→4 は同一 PR 内で順に積む前提の依存関係があり、間で緑を保てる(施策 1 は既存 422 文言テストが、施策 2 は Feature テストが、施策 3/4 は component / page テストが各段でカバーする)。施策 5 は独立で、最後に足せる |
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
