【アプリの使命 (North Star)】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可 (リポジトリは /workspace)。

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: `resources/js/components/` の `atoms/molecules/organisms/features/templates` の責務分離に沿った配置か。アイコンは Lucide 前提か

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: capture-full-scenario-preview (撮影 PWA の全体連結プレビュー)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは **Svelte 5 runes + DS token のみ**。component 階層は
  `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。
  アイコンは `@lucide/svelte` のみ。

### 本設計に直接効くドメイン規約 (AGENTS.md)

- **ドメイン規約 12 (T148)**: 「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
  `Services/Manual/AdoptedReadyTakeCoverage` **ただ 1 ファイル**。`adoptedTake` を参照する `app/` 配下の
  ファイルは `AdoptedTakeReferenceInventory` へ区分 + 30 文字以上の根拠で登録が必須
  (`AdoptedReadyTakeCriterionInventoryTest` が deny-by-default + exact-fit)。
- **ドメイン規約 1**: `cuts` / `scenario_version` / `status` を**書き込む**経路の共有ロック規約。
  本設計は**読み取りのみ**なので新しい書き込み経路を作らない (`ScenarioWritePathInventoryTest` へ登録不要)。
- **ドメイン規約 3**: 撮影 PWA の 3 枚セット (no-store / bfcache 秘匿 / Inertia 履歴暗号化)。
  新しい route も新しいログアウト導線も作らないため触れない。
- **セキュリティ不変条件 2/9/10**: 新しい route を 1 本も足さないため、
  `NestedRouteIdorDefenseTest` / `ControllerAuthorizationGateTest` / `ThrottleCoverageInventoryTest` の
  目録は**現状のまま**である (母集団が増えない)。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) — 方式比較 (案 A サーバ生成 / 案 B 端末側連結再生) と
  決定理由、権限の結論、再生の状態機械と契約。Codex 概念設計レビュー **Round 5 で APPROVED**。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 判定式の単一化: `AdoptedReadyTakeCoverage::readyTakeId()` の新設 | `app/Services/Manual/AdoptedReadyTakeCoverage.php` | 高 (S2 の前提) |
| S2 | 撮影 props に `adopted_ready_take_id` を供給する | `app/DataTransferObjects/Capture/CaptureCutData.php` / `CaptureManualDetailData.php` / `app/Support/Security/AdoptedTakeReferenceInventory.php` / `resources/js/types/capture.ts` | 高 |
| S3 | プレースホルダ尺をページ props で渡す | `app/Http/Controllers/Capture/CaptureManualController.php` / `resources/js/pages/Capture/Show.svelte` | 高 |
| S4 | 再生の状態機械 (純関数) | `resources/js/lib/capture/scenario-preview.ts` (新規) | 高 |
| S5 | 通し再生ダイアログ | `resources/js/components/features/capture/ScenarioPreviewDialog.svelte` (新規) | 高 |
| S6 | 撮影画面への配線 (起動導線・カメラ資源) | `resources/js/pages/Capture/Show.svelte` | 高 |
| S7 | ドキュメント更新 | `doc/05_スマホアプリ機能仕様.md` / `docs/architecture.md` | 中 |
| S8 | 権限の非回帰確認 (既存テストの緑維持) | 変更なし (確認のみ) | 中 |

---

## S1: 判定式の単一化 — `AdoptedReadyTakeCoverage::readyTakeId()` の新設

### 変更箇所

- ファイル: `app/Services/Manual/AdoptedReadyTakeCoverage.php` (L25-45 付近の `isMissing()`)

### 意図

`isMissing()` は bool しか返さないため、「どのテイクを再生するか」を知りたい側 (S2 の DTO) が
`adopted_take_id` と `TakeStatus::Ready` を**組み直す**ことになる。それは T148 が閉じた二重化の復活である
(bug-hunt F-1-01 の構造的原因そのもの)。**述語の意味は変えずに実体を 1 つにする**。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: S2 が新メソッドを使う (このファイル自体の外部契約は増えるだけで壊れない)
- テストファイル: 既存 `tests/Feature/Manual/PreviewCoverageParityTest.php` は `isMissing()` 経由の
  件数契約を見ているため**無変更で緑のまま**であること (回帰の確認点)。
- 目録: 本ファイルは既に `AdoptedTakeReferenceKind::Canonical` で登録済み。**区分・根拠とも変更不要**
  (判定式の実体が 1 ファイルに閉じている事実は変わらない)。

### 現行コード

```php
    public static function isMissing(Cut $cut): bool
    {
        $take = $cut->adoptedTake;

        return $take === null || $take->status !== TakeStatus::Ready;
    }
```

### 変更後コード

```php
    /**
     * 「使用できる採用テイク」の **id** (無ければ null)。**この式が唯一の実体**である。
     *
     * `isMissing()` は本メソッドの上に載る (bool しか返さない述語のままだと、id が要る側が
     * `adopted_take_id` と `TakeStatus::Ready` を組み直すことになり、T148 が閉じた二重化が
     * そのまま復活する)。撮影 PWA の通し再生はこの id を props 経由で受け取り、
     * TypeScript 側で述語を再実装しない。
     *
     * 前提: $cut は adoptedTake を eager load 済みで呼ぶこと (lazy load でも結果は同じだが N+1)。
     */
    public static function readyTakeId(Cut $cut): ?int
    {
        $take = $cut->adoptedTake;
        if ($take === null || $take->status !== TakeStatus::Ready) {
            return null;
        }

        return $take->id;
    }

    /**
     * 唯一の述語。**この式を他所へ写経しない**。
     *
     * TakeStatus は uploading / processing / ready / failed の 4 値を持つため、
     * 本述語が真になるのは「まだ撮っていない」だけではない
     * (採用済みだがアップロード中・処理中・失敗も含む = 「使用できる採用テイクがない」)。
     */
    public static function isMissing(Cut $cut): bool
    {
        return self::readyTakeId($cut) === null;
    }
```

> `isMissing()` の docblock にあった「前提: eager load 済みで呼ぶこと」の記述は `readyTakeId()` 側へ移す
> (実際に relation を触るのはそちらになるため)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`?int` / `bool`)
- [x] null 安全 — `$take === null` を先に落とすアーリーリターン。`$take->id` は `Take` の
      `@property int $id` により narrowing 後は `int` で確定する
- [x] DTO を返している — 本メソッドはスカラーを返す単一判定であり配列返却ではない
- [x] Generics の型パラメータ — 使用しない

### テスト計画

- [ ] 新規 `tests/Feature/Capture/ScenarioPreviewPropsTest.php` (S2 と共有) で
      `readyTakeId()` の 4 値 × 未採用の挙動を **props 経由で** 固定する
      (述語の実体はここ 1 つなので、props の値が述語の値である)
- [ ] 既存 `tests/Feature/Manual/PreviewCoverageParityTest.php` が**無変更で緑**であること
      (= `isMissing()` の意味が変わっていないことの回帰)
- [ ] 既存 `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` が緑であること
      (検出 B の期待集合は Canonical 1 ファイル + 名指し免除のまま = 本変更で母集団は動かない)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `isMissing()` の呼び出し側 (`RenderJobService` / `RenderPipeline`) の挙動は不変だが、
  **式が 1 段深くなる**ため、将来 `readyTakeId()` だけを書き換えて `isMissing()` の意味が
  意図せず変わる余地が生まれる。→ docblock に「`isMissing()` は本メソッドの上に載る」と明記し、
  `PreviewCoverageParityTest` が behavioral な回帰として残る。

---

## S2: 撮影 props に `adopted_ready_take_id` を供給する

### 変更箇所

- `app/DataTransferObjects/Capture/CaptureCutData.php` (コンストラクタ / `fromCut()` / `toArray()` の array shape)
- `app/DataTransferObjects/Capture/CaptureManualDetailData.php` (L36 付近: cuts の取得に eager load を足す)
- `app/Support/Security/AdoptedTakeReferenceInventory.php` (`CaptureManualDetailData` の根拠を実態へ合わせる)
- `resources/js/types/capture.ts` (`CaptureCut`)

### 意図

端末側が再生すべきテイクを**サーバが決めて渡す**。TypeScript 側で
「`adopted_take_id` があり、かつその take の `status === "ready"`」を組み立てさせない。

### 波及変更

- **TypeScript 型定義**: `resources/js/types/capture.ts` の `CaptureCut` に
  `adopted_ready_take_id: number | null` を追加する。
- **API Resource/DTO**: `CaptureCutResource` は `CaptureCutData::toArray()` をそのまま返すため**コード変更なし**。
  ただし **`POST .../takes/{take}/adopt` の応答 shape にも新キーが載る** (同じ DTO を通るため)。
  これは意図した挙動である (採用直後にクライアントが再生可否を知れる)。
  既存 `tests/Feature/Capture/CaptureTakeManagementTest.php` は `assertJsonPath('adopted_take_id', ...)` で
  個別キーを見ており**キー集合の完全一致は見ていない**ため、破壊はしない (確認済み)。
- **テストファイル**: `tests/Feature/Capture/CaptureManualBrowsingTest.php` の
  「show の take shape は TS CaptureTake と対のキー集合」テスト内 `$cutShape` の期待配列に
  `adopted_ready_take_id` を追加する (**キー順も完全一致**なので挿入位置に注意)。
- 目録: `CaptureCutData` は `adoptedTake` の識別子も文字列リテラルも持たない
  (`AdoptedReadyTakeCoverage::readyTakeId()` を呼ぶだけ) ため、`AdoptedTakeReferenceInventory` の
  **母集団に入らない** (検出 A の走査対象外)。**登録は不要**であり、登録すると exact-fit の
  stale entry として逆に fail する。`CaptureManualDetailData` は eager load 文字列
  `'adoptedTake'` を持つため引き続き母集団に入る (**登録済み**。根拠の文面のみ更新する)。

### 現行コード

```php
// CaptureCutData
final readonly class CaptureCutData
{
    /**
     * @param  list<CaptureTakeData>  $takes
     */
    public function __construct(
        public Cut $cut,
        public array $takes,
    ) {}

    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        $adoptedTakeId = $cut->adopted_take_id;
        $takes = $cut->takes()->orderBy('sort_order')->orderBy('id')->get()
            ->map(static function (Take $take) use ($adoptedTakeId, $adoptedPlaybackUrl, $adoptedAckToken): CaptureTakeData {
                $isAdopted = $adoptedTakeId !== null && $take->id === $adoptedTakeId;

                return CaptureTakeData::fromTake(
                    $take,
                    playbackUrl: $isAdopted ? $adoptedPlaybackUrl : null,
                    downloadAckToken: $isAdopted ? $adoptedAckToken : null,
                );
            })
            ->all();

        return new self($cut, array_values($takes));
    }

    /**
     * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, adopted_take_id: int|null,
     *   takes: list<array{...}>}
     */
    public function toArray(): array
    {
        return [
            // ...
            'adopted_take_id' => $this->cut->adopted_take_id,
            'takes' => array_map(...),
        ];
    }
}
```

```php
// CaptureManualDetailData::fromManual
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->orderBy('sort_order')->get();
```

### 変更後コード

```php
// CaptureCutData
final readonly class CaptureCutData
{
    /**
     * @param  list<CaptureTakeData>  $takes
     * @param  int|null  $adoptedReadyTakeId 使用できる採用テイクの id
     *   (`AdoptedReadyTakeCoverage::readyTakeId()` の戻り値そのもの。判定は持たない)
     */
    public function __construct(
        public Cut $cut,
        public array $takes,
        public ?int $adoptedReadyTakeId,
    ) {}

    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        $adoptedTakeId = $cut->adopted_take_id;
        $takes = $cut->takes()->orderBy('sort_order')->orderBy('id')->get()
            ->map(/* 現行のまま */)
            ->all();

        // 「使用できる採用テイクか」の判定は AdoptedReadyTakeCoverage が唯一の所在である
        // (ここで adopted_take_id と TakeStatus::Ready を組み直さない = T148)。
        return new self($cut, array_values($takes), AdoptedReadyTakeCoverage::readyTakeId($cut));
    }

    /**
     * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, adopted_take_id: int|null,
     *   adopted_ready_take_id: int|null,
     *   takes: list<array{id: int, client_take_id: string, status: string, size_bytes: int,
     *     duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
     *     downloaded: bool, has_thumbnail: bool, playback_url: string|null,
     *     download_ack_token: string|null}>}
     */
    public function toArray(): array
    {
        return [
            // ... (現行のまま)
            'adopted_take_id' => $this->cut->adopted_take_id,
            // 通し再生が再生する対象。null = そのカットはプレースホルダになる
            // (「採用されていない」と「採用済みだが ready でない」を区別しない = 述語の意味そのまま)
            'adopted_ready_take_id' => $this->adoptedReadyTakeId,
            'takes' => array_map(...),
        ];
    }
}
```

```php
// CaptureManualDetailData::fromManual — N+1 を作らない (cut ごとに adoptedTake を lazy load しない)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->get();
```

```ts
// resources/js/types/capture.ts
export interface CaptureCut {
    // ... 現行のまま
    adopted_take_id: number | null;
    /**
     * 通し再生が再生するテイクの id (サーバが `AdoptedReadyTakeCoverage` で決めた値)。
     * null = そのカットはプレースホルダになる。**クライアントでこの判定を組み立て直さない**
     * (`adopted_take_id` と take.status から導出するコードを書かない = T148)。
     */
    adopted_ready_take_id: number | null;
    takes: CaptureTake[];
}
```

```php
// AdoptedTakeReferenceInventory — 根拠の文面のみ実態へ更新 (区分は DifferentCriterion のまま)
            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => '撮影ナビの表示用に採用テイクの実体を読むだけで ready 判定はしない。'
                    .'with(adoptedTake) の eager load は、CaptureCutData が委譲する'
                    .'AdoptedReadyTakeCoverage の N+1 を防ぐための構造上の指定である。',
            ],
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`toArray()` の array shape に新キーを追加。**docblock を更新しないと
      level 10 が shape 不一致で落ちる**)
- [x] null 安全 — `?int` を promoted property でそのまま保持し、null 分岐を DTO 側に持たない
- [x] DTO を返している (`CaptureCutData` / `CaptureCutResource` 経由。`response()->json()` 直書きなし)
- [x] Generics の型パラメータ — `Collection<int, Cut>` の既存注釈を維持

### テスト計画

- [ ] 新規 `tests/Feature/Capture/ScenarioPreviewPropsTest.php`:
  - 採用済み + `ready` → `adopted_ready_take_id` = そのテイク id
  - 採用済み + `uploading` / `processing` / `failed` → `null` (**4 値すべてを個別に固定**)
  - 未採用 (テイクはあるが `adopted_take_id` が null) → `null`
  - テイクが 1 件も無いカット → `null`
  - `adopted_take_id` と `adopted_ready_take_id` が**別の意味**であること
    (採用済み非 ready のカットで前者は非 null / 後者は null になる) を 1 テストで固定する
- [ ] 既存 `tests/Feature/Capture/CaptureManualBrowsingTest.php` の cut キー集合テストを更新
      (`adopted_take_id` の直後に `adopted_ready_take_id`、`takes` はこれまでどおり末尾)
- [ ] 既存 `tests/Feature/Capture/CaptureTakeManagementTest.php` (adopt 応答) が**無変更で緑**であること
- [ ] N+1 が増えていないこと: props 生成時のクエリ本数を
      `DB::listen` ではなく **cuts 件数を増やしても採用テイク取得クエリが 1 本のまま**であることで確認する
      (`ScenarioPreviewPropsTest` に 1 ケース)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **キー集合テストは順序完全一致**なので、挿入位置を誤ると赤くなる (これは意図した検出であり、
  実装時に位置を合わせれば済む)。
- adopt 応答に新キーが載ることで、外部 (機械向け API) の契約が変わる懸念 — `capture.takes.*` は
  `/app` 配下の**同一オリジン XHR 専用**であり `api/v1` ではないため、外部契約は無い。

---

## S3: プレースホルダ尺をページ props で渡す

### 変更箇所

- `app/Http/Controllers/Capture/CaptureManualController.php` (`show()` の `Inertia::render`)
- `resources/js/pages/Capture/Show.svelte` (`Props` interface)

### 意図

端末側プレースホルダの尺を、サーバ生成プレビューと**同じ設定値**から取る
(`config('manual.preview_placeholder_seconds')`)。値をクライアントに直書きしない。

### 波及変更

- TypeScript 型定義: `Capture/Show.svelte` の `Props` に `previewPlaceholderSeconds: number` を追加
- API Resource/DTO: なし (ページ props であり資源の shape ではない)
- テストファイル: `ScenarioPreviewPropsTest` でキーと型を固定

### 現行コード

```php
        return Inertia::render('Capture/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
        ]);
```

### 変更後コード

```php
        return Inertia::render('Capture/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
            // 通し再生でプレースホルダを表示する秒数。サーバ生成プレビューの黒背景尺と
            // **同じ設定値**を使う (2 つのプレビューの構造を揃える。単位は秒・正の整数)。
            'previewPlaceholderSeconds' => config()->integer('manual.preview_placeholder_seconds'),
        ]);
```

```ts
// resources/js/pages/Capture/Show.svelte
    interface Props {
        project: { id: number; name: string };
        manual: CaptureManualDetail;
        /** プレースホルダ表示秒数 (config manual.preview_placeholder_seconds)。単位は秒 */
        previewPlaceholderSeconds: number;
    }

    let { project, manual, previewPlaceholderSeconds }: Props = $props();
```

> **命名**: 資源の shape (`CaptureCutData::toArray()`) は snake_case、Controller が組み立てる
> ページ props の複合キーは camelCase、という既存の 2 段の流儀に従う
> (`Manuals/Show` の `analysis.hasDocument` / `render.finishedJob` が先例)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`Inertia\Response`。変更なし)
- [x] null 安全 — `config()->integer()` は int を返す (`config()` の未定義キーは例外)
- [x] DTO を返している — manual は既存 DTO 経由のまま
- [x] Generics — 影響なし

### テスト計画

- [ ] `ScenarioPreviewPropsTest`: `previewPlaceholderSeconds` が props に存在し、
      `config('manual.preview_placeholder_seconds')` と一致する **int** であること
- [ ] `tests/js/pages/CaptureShow.test.ts` の既存レンダで props 追加により
      型エラー・実行時エラーが出ないこと (既存テストの props 組み立てを更新)

### リスク

- 既存の `CaptureShow.test.ts` は props を手組みしているため、**必須 prop 追加でコンパイルが赤くなる**。
  → 同じ施策の中で更新する (波及変更として明示済み)。

---

## S4: 再生の状態機械 (純関数) — `lib/capture/scenario-preview.ts`

### 変更箇所

- 新規ファイル: `resources/js/lib/capture/scenario-preview.ts`

### 意図

概念設計「再生の状態機械と契約」を**副作用を持たない形**で実装し、Vitest が fake timer で固定できるようにする。
`landscape-capture.ts` / `panel-navigation.ts` と同じ役割分担 (判断は lib、配線は component)。

### 波及変更

- TypeScript 型定義: `types/capture.ts` の `CaptureCut` を入力に取る (S2 の新キーに依存)
- API Resource/DTO: なし
- テストファイル: 新規 `tests/js/lib/capture/scenario-preview.test.ts`

### 現行コード

(新規ファイルのため現行なし。参照する既存規約は `take-endpoints.ts` の URL 導出と
`cut-labels.ts` の `buildCutLabels()`。**どちらも再実装しない**。)

### 変更後コード

```ts
import { takeUrl } from "@/lib/capture/take-endpoints";
import type { CaptureCut } from "@/types/capture";

/**
 * 撮影 PWA の通し再生 (全体連結プレビュー) の再生リストと状態機械。
 *
 * 方式の決定 (端末側連結再生 / サーバ生成プレビューを撮影者に開かない) と、
 * ここで固定する契約の根拠は devnotes/20260816-1754-capture-full-scenario-preview/。
 *
 * **この面は素材の選択判定を持たない**。どのテイクを再生するかは
 * サーバの `AdoptedReadyTakeCoverage` が決め、`cut.adopted_ready_take_id` として渡ってくる
 * (adopted_take_id と take.status からここで組み立て直さない = T148 の二重化を作らない)。
 */

/** 再生リストの 1 件 (クリップ = 再生する / 欠落 = プレースホルダを出す) */
export type PreviewEntry =
    | {
          kind: "clip";
          cutId: number;
          takeId: number;
          label: string;
          subtitlePrimary: string | null;
          subtitleSecondary: string;
          /** capture.takes.playback の URL (takeUrl が唯一の導出元) */
          src: string;
      }
    | {
          kind: "missing";
          cutId: number;
          label: string;
          subtitlePrimary: string | null;
          subtitleSecondary: string;
      };

/** 再生状態 (可視性とは**直交**する) */
export type ClipState = "loading" | "playing" | "paused" | "blocked" | "failed" | "placeholder";

export interface PreviewState {
    /** 再生リスト内の位置 (0 起点)。entries.length に達したら finished */
    index: number;
    /** 非同期結果の受付世代。index の前進・スキップ・終了のたびに +1 する */
    generation: number;
    clip: ClipState;
    /** ページが表示されているか (可視性の軸) */
    visible: boolean;
    /** 直近に「進捗があった」時刻 (ms)。停滞判定の起点 */
    progressAt: number;
    /** 全カットを見終わったか */
    finished: boolean;
}

export interface PreviewEvent {
    type:
        | "progress" // timeupdate / progress / canplay 等の前進イベント
        | "playing"
        | "paused" // 利用者の一時停止
        | "resumed" // 利用者の再生
        | "ended"
        | "error" // media error / 404
        | "blocked" // 自動再生制限と判定できる play() 拒否
        | "retry" // 「再生を続ける」
        | "skip" // 「このカットをスキップ」
        | "hidden"
        | "shown"
        | "tick"; // 時間経過の通知 (停滞監視・プレースホルダ尺)
    /** 発生元の世代。省略時は現在世代とみなす (利用者操作など同期的なもの) */
    generation?: number;
    /** イベント時刻 (ms) */
    at: number;
}

export interface PreviewOptions {
    entries: PreviewEntry[];
    /** プレースホルダの表示秒数 (サーバの preview_placeholder_seconds と同じ値) */
    placeholderSeconds: number;
    /** 停滞と判定するまでの無進捗時間 (ms) */
    stallTimeoutMs?: number;
}

/**
 * 停滞判定の既定閾値。
 *
 * **この値が「正しい」ことは主張しない**。固定するのは「監視条件を満たす限り有限時間で
 * 必ず次へ進む」ことだけで、閾値そのものは実地の観測が出るまで動かさない
 * (仕組みが機能していない段階で値を弄らない)。現場のモバイル回線で先頭バッファに
 * 時間がかかることを想定して保守的に置く。
 */
export const PREVIEW_STALL_TIMEOUT_MS = 20_000;

/**
 * 再生リストを組み立てる。並び順は props の cuts の順 (= サーバの表示順: 手順 → 配下の急所) をそのまま使う。
 * ラベルは buildCutLabels の結果を受け取る (規則をここで再実装しない)。
 */
export function buildPreviewEntries(
    cuts: CaptureCut[],
    labels: Record<number, string>,
    target: { projectId: number; manualId: number },
): PreviewEntry[] {
    return cuts.map((cut): PreviewEntry => {
        const label = labels[cut.id] ?? "カット";
        const takeId = cut.adopted_ready_take_id;
        if (takeId === null) {
            return {
                kind: "missing",
                cutId: cut.id,
                label,
                subtitlePrimary: cut.subtitle_primary,
                subtitleSecondary: cut.subtitle_secondary,
            };
        }
        return {
            kind: "clip",
            cutId: cut.id,
            takeId,
            label,
            subtitlePrimary: cut.subtitle_primary,
            subtitleSecondary: cut.subtitle_secondary,
            src: takeUrl({ projectId: target.projectId, manualId: target.manualId, cutId: cut.id }, takeId, "/playback"),
        };
    });
}

/** 使用できる採用テイクが無いカットの件数 (再生前の告知に使う。述語は持たない = null を数えるだけ) */
export function missingCount(entries: PreviewEntry[]): number {
    return entries.filter((entry) => entry.kind === "missing").length;
}

/** 初期状態 (先頭 entry の種別で clip / placeholder が決まる) */
export function initialPreviewState(options: PreviewOptions, at: number): PreviewState {
    return {
        index: 0,
        generation: 0,
        clip: stateForEntry(options.entries[0]),
        visible: true,
        progressAt: at,
        finished: options.entries.length === 0,
    };
}

/**
 * 停滞監視を動かす条件。
 * **可視性 × 再生要求 × 状態**の 3 つが揃ったときだけ監視する
 * (一時停止・非表示・blocked・failed の間は監視しない = 誤って次へ進めない)。
 */
export function shouldWatchStall(state: PreviewState): boolean {
    return state.visible && !state.finished && (state.clip === "loading" || state.clip === "playing");
}

/**
 * 状態遷移。**現在世代と一致しない非同期結果は 1 ビットも状態を変えない**
 * (要素の入れ替えで生じる古い reject / error を誤って現在のクリップの失敗にしない)。
 */
export function reducePreview(state: PreviewState, event: PreviewEvent, options: PreviewOptions): PreviewState {
    if (state.finished) return state;
    if (event.generation !== undefined && event.generation !== state.generation) return state;

    switch (event.type) {
        case "hidden":
            return { ...state, visible: false };
        case "shown":
            // 再生状態は変えない (playing なら component が再開を試み、paused/blocked は維持)。
            // 進捗の起点だけ引き直す (非表示だった時間を停滞に数えない)。
            return { ...state, visible: true, progressAt: event.at };
        case "progress":
            return { ...state, progressAt: event.at };
        case "playing":
            return { ...state, clip: "playing", progressAt: event.at };
        case "paused":
            return state.clip === "playing" ? { ...state, clip: "paused" } : state;
        case "resumed":
            return state.clip === "paused" ? { ...state, clip: "playing", progressAt: event.at } : state;
        case "blocked":
            return { ...state, clip: "blocked" };
        case "retry":
            // 「再生を続ける」= もう一度読み込みからやり直す (再拒否ならまた blocked になる)
            return { ...state, clip: "loading", progressAt: event.at };
        case "error":
            return { ...state, clip: "failed", progressAt: event.at };
        case "ended":
        case "skip":
            return advance(state, options, event.at);
        case "tick":
            return onTick(state, options, event.at);
    }
}

/** 時間経過: プレースホルダの尺満了と停滞判定の 2 つだけを見る */
function onTick(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
    if (!state.visible) return state; // 非表示の間は尺も停滞も進めない
    if (state.clip === "placeholder" || state.clip === "failed") {
        return at - state.progressAt >= options.placeholderSeconds * 1000 ? advance(state, options, at) : state;
    }
    if (!shouldWatchStall(state)) return state;
    const timeout = options.stallTimeoutMs ?? PREVIEW_STALL_TIMEOUT_MS;
    // 進捗が途切れたまま閾値を超えた → そのカットだけ失敗にする (通し再生は止めない)
    return at - state.progressAt >= timeout ? { ...state, clip: "failed", progressAt: at } : state;
}

/** 次の entry へ。**世代を必ず +1 する** (破棄したクリップの遅延イベントを無効化する) */
function advance(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
    const next = state.index + 1;
    if (next >= options.entries.length) {
        return { ...state, index: next, generation: state.generation + 1, finished: true, progressAt: at };
    }
    return {
        ...state,
        index: next,
        generation: state.generation + 1,
        clip: stateForEntry(options.entries[next]),
        progressAt: at,
    };
}

function stateForEntry(entry: PreviewEntry | undefined): ClipState {
    return entry?.kind === "clip" ? "loading" : "placeholder";
}
```

### 波及する既存規約の遵守

- URL の導出は `takeUrl()` (既存の唯一の所在) を通す。`/app/...` を文字列で組み立て直さない。
- ラベルは `buildCutLabels()` の結果を受け取る (規則を再実装しない)。
- `PreviewEntry` は `CaptureCut` を入力に取るだけで、**述語を持たない**。

### テスト計画 (`tests/js/lib/capture/scenario-preview.test.ts`)

- [ ] `buildPreviewEntries`: `adopted_ready_take_id` が非 null → `kind: "clip"` と
      `src` が `takeUrl(...,"/playback")` と一致 / null → `kind: "missing"`
- [ ] `buildPreviewEntries`: cuts の順序をそのまま保つ (手順 → 急所の並びを崩さない)
- [ ] `missingCount`: 欠落件数を数える
- [ ] **停滞**: `loading` のまま `stallTimeoutMs` を超える `tick` → `failed`、
      さらに `tick` で次の entry へ進む (**有限時間で必ず次へ進む**)
- [ ] **一時停止**: `paused` 中は `tick` をいくら送っても `failed` にならない
- [ ] **非表示**: `hidden` 中は `tick` で進まない。`shown` で `progressAt` が引き直され、
      **再生状態は変わらない** (`paused → hidden → shown → paused` を固定)
- [ ] **世代**: `advance` 後に**古い世代**の `error` / `blocked` を送っても状態が変わらない
- [ ] **blocked**: `blocked` → `retry` → `blocked` (再拒否) を繰り返しても `failed` にならない。
      `blocked` → `skip` で次へ進む
- [ ] **プレースホルダ**: `placeholder` は `placeholderSeconds` 経過の `tick` で次へ進む
- [ ] **終端**: 最後の entry の `ended` で `finished: true` になり、以後どのイベントでも状態が変わらない
- [ ] **空**: entries が 0 件なら初期状態で `finished: true`

### リスク

- 停滞閾値 20 秒は**推定値**である。現場の回線で先頭バッファに 20 秒以上かかるケースがあると、
  正常な素材を「再生できませんでした」と表示する。→ 閾値は注入可能にしてあり、
  実地の観測が出るまで動かさない方針を docblock に明記する。
- `tick` の駆動は component 側の `setInterval` に依存する。**駆動が止まれば停滞検出も止まる**
  (この非対称は「保証しないもの」に記載済み)。

---

## S5: 通し再生ダイアログ — `ScenarioPreviewDialog.svelte`

### 変更箇所

- 新規ファイル: `resources/js/components/features/capture/ScenarioPreviewDialog.svelte`

### 意図

`TakePreviewDialog` (個別再生) の構造を踏襲しつつ、**2 枚の `<video>` を交互に使って**
先読みした要素をそのまま本再生へ引き継ぐ (1 クリップ 1 回取得)。

### 波及変更

- TypeScript 型定義: `PreviewEntry` / `PreviewState` (S4) を使う
- API Resource/DTO: なし
- テストファイル: 新規 `tests/js/components/features/capture/ScenarioPreviewDialog.test.ts`

### 構造 (実装仕様)

```svelte
<script lang="ts">
    import { Captions, CaptionsOff, LoaderCircle, Play, SkipForward } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import {
        buildPreviewEntries,
        initialPreviewState,
        missingCount,
        reducePreview,
        shouldWatchStall,
        type PreviewEntry,
        type PreviewEvent,
    } from "@/lib/capture/scenario-preview";
    import type { CaptureCut } from "@/types/capture";

    /**
     * 通し再生 (全体連結プレビュー。doc/05 §5.2 [プレビュー])。
     *
     * - 素材は**採用テイク**である (先頭テイクではない)。理由は詳細設計と doc/05 の注記。
     * - 使用できる採用テイクが無いカットはプレースホルダを placeholderSeconds 秒表示して次へ進む。
     * - **1 本の失敗で通し再生を止めない**。判断は lib/capture/scenario-preview.ts が持つ
     *   (このコンポーネントは配線とメディア要素の操作だけを行う)。
     * - **2 枚の <video> を交互に使う**。次のクリップは非表示側の要素に先読みし、
     *   進むときに役割を入れ替える (同じ動画を 2 回取得しない)。
     */
    interface Props {
        open: boolean; // bindable
        projectId: number;
        manualId: number;
        cuts: CaptureCut[];
        /** buildCutLabels の結果 (規則を再実装しない) */
        labels: Record<number, string>;
        placeholderSeconds: number;
        onClose: () => void;
    }
</script>
```

主要な配線 (実装時の契約):

| 要素 | 契約 |
|---|---|
| 再生リスト | `open` になった時点の `cuts` から `buildPreviewEntries()` で 1 度だけ組む (再生中に props が更新されても差し替えない = 位置が飛ばない)。閉じて開き直したら組み直す |
| メディア要素 | `videoA` / `videoB` の 2 枚。`activeSlot: 0 | 1` を state で持ち、**現在再生 = active、先読み = inactive**。`advance` 時に `activeSlot` を反転し、先読み済み要素をそのまま再生する |
| 先読み | 現在クリップが `playing` になった時点で、**次の 1 件だけ** inactive 側に `src` を設定し `preload="auto"` にする。次が `missing` なら何もしない |
| イベント | `canplay` / `timeupdate` / `progress` → `progress`、`playing` → `playing`、`pause`(利用者操作) → `paused`、`play` → `resumed`、`ended` → `ended`、`error` → `error` を **その要素の世代付きで** reducer へ送る |
| `play()` | 戻り値の Promise を必ず `catch` する。**世代が一致し、かつ自動再生制限と判定できる拒否** (`err instanceof DOMException && err.name === "NotAllowedError"`) のみ `blocked` を送る。それ以外は**何も送らない** (失敗の確定は `error` と停滞監視に委ねる) |
| `tick` | `setInterval` (1 秒) で `tick` を送る。ダイアログを閉じるときに必ず破棄する |
| 可視性 | `visibilitychange` で `hidden` / `shown` を送る。`hidden` では**実メディアも `pause()` する** (非表示中に `ended` で勝手に次へ進まないため)。`shown` で再生状態が `playing` なら `play()` を試みる |
| 終了 | `finished` になったら「すべてのカットを再生しました」を出し、`閉じる` と `もう一度再生` を並べる (行き止まりを作らない) |
| 閉じる | `Modal` の close 契機 (背景クリック / Esc / × / 閉じるボタン) をすべて拾い、**両方の要素を teardown** (`pause()` → `removeAttribute("src")` → `load()`) し、interval を破棄し、世代を進めてから `onClose()` を呼ぶ |

表示 (DS token のみ。hex 直書きなし):

- 見出し行: `label` (手順 N / 急所 N-M) と位置 `n / M` を `text-caption text-text-secondary` で常時表示
- 事前告知 (`missingCount > 0`): `Alert type="warning"` で
  「**{missing} / {total} 件のカットに、撮影・処理が完了した採用テイクがありません。その区間はプレースホルダになります。**」
  (PC 側 RenderPanel と同じ語彙。ボタンは止めない = 禁止事項 8)
- `missing` の表示: `bg-text/5` の面に「**{label}: 撮影・処理が完了した採用テイクがありません**」
- `failed` の表示: 同じ面に「**{label}: このカットは再生できませんでした**」(原因は言わない)
- `blocked` の表示: `Alert type="info"` + 「再生を続ける」(`Play`) / 「このカットをスキップ」(`SkipForward`) /
  Modal footer の「閉じる」の **3 つの出口**
- `loading` の表示: `LoaderCircle` + 「読み込み中」
- 字幕 overlay と ON/OFF トグル (`Captions` / `CaptionsOff`) は `TakePreviewDialog` と同じ構造・初期 ON

### PHPStan適合チェック

- 該当なし (TypeScript/Svelte)。`pnpm typecheck` (svelte-check) と `pnpm lint` を通す。
  `activeSlot` は `0 | 1` のリテラル union で持ち、`HTMLVideoElement | undefined` の null 安全を
  optional chaining ではなく**早期 return** で扱う。

### テスト計画 (`tests/js/components/features/capture/ScenarioPreviewDialog.test.ts`)

- [ ] 開くと先頭 entry の `src` が active 要素に設定される (`takeUrl` の URL 形)
- [ ] `missingCount > 0` のとき事前告知 (`data-testid="scenario-preview-coverage-note"`) が出る。
      **ボタンは disabled にならない**
- [ ] `missing` entry ではプレースホルダ文言が出て `<video>` に src が設定されない
- [ ] 次のクリップが inactive 側に**先読みされる** (2 枚目の要素に src が入る)。
      進んだあと、**同じ URL を 2 回 fetch する形になっていない** (要素の役割が入れ替わるだけ)
- [ ] `play()` が `NotAllowedError` で拒否されたとき `blocked` 表示になり、
      「再生を続ける」「スキップ」「閉じる」が操作できる
- [ ] **拒否後もダイアログを閉じられる** (未処理 rejection を残さない)
- [ ] **旧クリップの遅延 `error` / 遅延 reject が、進んだ後の新クリップを壊さない**
- [ ] 閉じたときに両方の `<video>` が teardown され、interval が破棄される
- [ ] 最終 entry の `ended` で「すべてのカットを再生しました」が出る

### リスク

- jsdom は実メディア再生を行わないため、component テストで固定できるのは
  **DOM 契約とイベント配線まで**である (実際の連続再生の滑らかさは実機確認の領域)。
  → この非対称を docblock と `docs/architecture.md` に明記する (誇張しない)。
- iOS Safari は `playsinline` が無いと全画面再生に切り替わる。`TakePreviewDialog` と同じく
  **`playsinline` を必ず付ける** (付け忘れると通し再生が毎クリップ全画面になり体験が壊れる)。

---

## S6: 撮影画面への配線 — `pages/Capture/Show.svelte`

### 変更箇所

- `resources/js/pages/Capture/Show.svelte`
  - `Props` に `previewPlaceholderSeconds` (S3)
  - 左ペインのヘッダ (L470-494 付近: 「シナリオ (タップして撮影)」と「全画面で撮影」の並び) に起動ボタン
  - `ScenarioPreviewDialog` の設置とカメラ資源の解放/復帰

### 意図

導線を**カット一覧の見出し行**に置く (doc/05 のシナリオ詳細画面の [プレビュー] に対応する位置)。
横持ち全画面中は左ペインが `inert` なので**全画面からは起動しない** (撮影に専念する面)。

### 波及変更

- TypeScript 型定義: `Props` の追加 (S3 と同一施策で更新)
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/CaptureShow.test.ts` の props 更新 + 新規ケース

### 現行コード

```svelte
            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                <h2 bind:this={cutListHeadingEl} tabindex="-1" class="..." data-testid="capture-cut-list-heading">
                    シナリオ (タップして撮影)
                </h2>
                {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
                    <Button variant="neutral" size="sm" onclick={enterFullscreen} testId="enter-fullscreen-capture">
                        <Maximize class="size-4" aria-hidden="true" />
                        全画面で撮影
                    </Button>
                {/if}
            </div>
```

### 変更後コード

```svelte
            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                <h2 bind:this={cutListHeadingEl} tabindex="-1" class="..." data-testid="capture-cut-list-heading">
                    シナリオ (タップして撮影)
                </h2>
                <div class="flex items-center gap-2">
                    <!-- 通し再生 (doc/05 §5.2 [プレビュー])。**カットが 1 枚も無いときは出さない**
                         (文脈非該当の非表示であって、条件未充足の disabled ではない)。
                         撮影中の押下は開かずにエラーを出す (資源競合。TakeStrip の個別 preview と同じ規則)。 -->
                    {#if manual.cuts.length > 0}
                        <Button
                            variant="neutral"
                            size="sm"
                            onclick={openScenarioPreview}
                            testId="scenario-preview-button"
                        >
                            <ListVideo class="size-4" aria-hidden="true" />
                            通し再生
                        </Button>
                    {/if}
                    {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
                        <Button variant="neutral" size="sm" onclick={enterFullscreen} testId="enter-fullscreen-capture">
                            <Maximize class="size-4" aria-hidden="true" />
                            全画面で撮影
                        </Button>
                    {/if}
                </div>
            </div>
            {#if scenarioPreviewError !== null}
                <p class="px-3 py-2 text-caption text-danger" role="alert" data-testid="scenario-preview-error">
                    {scenarioPreviewError}
                </p>
            {/if}
```

```ts
    /* ---- 通し再生 (全体連結プレビュー) ---- */
    let scenarioPreviewOpen = $state(false);
    let scenarioPreviewError = $state<string | null>(null);

    /**
     * 撮影中はカメラ資源と競合するため開かない。**ボタンは disabled にせず、押下時に伝える**
     * (禁止事項 8)。開くときは待機中の live stream を解放する (TakeStrip の個別 preview と同じ経路)。
     */
    function openScenarioPreview(): void {
        if (captureActive) {
            scenarioPreviewError = "録画中は通し再生を開けません。録画を終了してからお試しください。";
            return;
        }
        scenarioPreviewError = null;
        onRequestCameraRelease();
        scenarioPreviewOpen = true;
    }

    function closeScenarioPreview(): void {
        scenarioPreviewOpen = false;
        void recorderRef?.resumeAfterPreview();
    }
```

```svelte
<!-- AppLayout の外側ではなく PageContainer 内の末尾に置く (Modal は Portal で描画されるため位置に依存しない) -->
<ScenarioPreviewDialog
    bind:open={scenarioPreviewOpen}
    projectId={project.id}
    manualId={manual.id}
    cuts={manual.cuts}
    labels={cutLabels}
    placeholderSeconds={previewPlaceholderSeconds}
    onClose={closeScenarioPreview}
/>
```

> `onRequestCameraRelease` は `TakeStrip` へ渡している `recorderRef?.releaseForPreview()` と同じ実体を使う
> (page 側に 1 つの関数として切り出し、`TakeStrip` の props にもそれを渡す = 2 か所に書かない)。

### PHPStan適合チェック

- 該当なし (TypeScript/Svelte)。`pnpm typecheck` / `pnpm lint` を通す。
- component 階層: `pages → features/capture` の順方向 import のみ (逆流なし)。
  `tests/js/architecture/atomic-import-graph.test.ts` が緑であること。

### テスト計画 (`tests/js/pages/CaptureShow.test.ts` に追記)

- [ ] 「通し再生」ボタンが表示され、押すとダイアログが開く
- [ ] カットが 0 件のときボタンが**表示されない** (disabled ではなく非表示)
- [ ] 録画中 (`captureActive`) に押すと `role="alert"` のエラーが出て**ダイアログは開かない**。
      **ボタンは常に押せる** (disabled 属性を持たない) ことを併せて固定する
- [ ] 開くときに `releaseForPreview` が呼ばれ、閉じるときに `resumeAfterPreview` が呼ばれる
- [ ] 既存の全画面・並べ替え・アップロード系ケースが緑のまま (props 追加による回帰なし)

### リスク

- 左ペインヘッダにボタンが 2 つ並ぶため、狭い画面で折り返す。→ `flex items-center gap-2` の
  既存レイアウトに載せ、`size="sm"` で既存ボタンと同じ大きさに揃える。
- 全画面 (横持ち) からは起動できない。**これは意図した制約**であり、
  概念設計のスコープ外に明記済み (撮影に専念する面)。

---

## S7: ドキュメント更新

### 変更箇所

- `doc/05_スマホアプリ機能仕様.md` (§5.2 の [プレビュー] 行)
- `docs/architecture.md` (§撮影 PWA (presigned アップロード + 容量 Quota) の運用契約 の末尾に小節を追加)

### 変更内容

`doc/05` — 資料の文面と実装の対応を残す (資料そのものは書き換えず注記を足す):

```markdown
- **[プレビュー]**: 各カットの先頭（左端）テイクを連結した全体映像を確認。
  - **実装注記**: 連結の素材は **採用テイク (`adopted_take_id`)** である。元資料の「一番左のテイクが
    全体プレビュー・採用候補」という記述は「左端 = 採用候補 = プレビューに使われるもの」という
    1 つの概念を指しており、本実装はそれを明示的な採用状態として持つ。完成動画・サーバ生成プレビューと
    **同じ素材**を使わないと「通しで見て問題なかったのに完成動画は別物」になるため、採用テイクを正とする。
    使用できる採用テイクが無いカットはプレースホルダになる (尺は
    `config('manual.preview_placeholder_seconds')`)。
```

`docs/architecture.md` — 契約と保証しないものを正本として置く:

```markdown
### 撮影 PWA の通し再生 (端末側連結再生)

撮影者向けの全体連結プレビューは**端末側でテイクを順に再生する**方式である
(サーバ生成プレビューを撮影者に開く方式は採らなかった。比較と決定理由は
devnotes/20260816-1754-capture-full-scenario-preview/conceptual-design.md)。

- **素材は採用テイク**である。選択は `AdoptedReadyTakeCoverage::readyTakeId()` が決め、
  `cuts.*.adopted_ready_take_id` として props に載る (クライアントは述語を持たない = T148)。
- **route も ability も増やさない**。既存の `capture.takes.playback` (`TakePolicy::preview` =
  撮影者可) をカット順に叩くだけである。**サーバ生成プレビューの起動は編集者専用のまま**で、
  `render_max_inflight_previews_per_org` (org 同時 preview 上限) を**消費しない**。
  チケットも消費しない (preview は元から非消費)。
- **1 本の失敗で止まらない**。再生できなかったカットはプレースホルダに落ちて次へ進む。
- **保証しないもの**: オフライン再生 / 完成動画と同じ見え方 (字幕は overlay、正規化なし) /
  プレースホルダ尺の厳密一致 / 1 クリップ 1 回取得 (再試行では増える) /
  停滞判定の閾値の妥当性 (固定するのは「表示中かつ再生要求中なら有限時間で必ず次へ進む」ことだけ) /
  `blocked` (自動再生制限) から自動で抜けること (出口は利用者操作のみ) /
  実機での連続再生の滑らかさ (component テストが見るのは DOM 契約とイベント配線まで)。
```

### テスト計画

- [ ] ドキュメントのみのため自動テストは無い。`docs/architecture.md` の他節と用語を揃える
      (「使用できる採用テイク」「プレースホルダ」の語を新造しない)

### リスク

- 記述が実装より先に古くなる。→ 保証しないものの列挙を **1 か所 (architecture.md)** に置き、
  概念設計側は参照にとどめる。

---

## S8: 権限の非回帰確認 (既存テストの緑維持)

### 変更箇所

- なし (確認のみ)

### 意図

本設計は「PC 側 preview / 完成動画の権限を変えない」ことを主張している。**主張は回帰テストが守る**。
既に存在するテストを名指しし、実装時に**無変更で緑**であることを確認する
(同じ契約のテストを新規に足さない = 二重管理を作らない)。

| 契約 | 既存テスト |
|---|---|
| 撮影者は `POST .../render` / `POST .../preview` とも 403 | `tests/Feature/Manual/RenderTriggerTest.php`「撮影者 (project_member) は render/preview とも 403」 |
| 撮影者は preview 成果物の `playback` が 403 | `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`「playback: 撮影者は 403」 |
| 撮影者は完成動画の `playback` / `download` が 403、props の `finishedJob` が null | `tests/Feature/Manual/FinishedVideoPlaybackTest.php` |
| 撮影者は take の playback / adopt ができる (本設計が依存する既存権限) | `tests/Feature/Capture/CapturePolicyTest.php` / `tests/Feature/Capture/TakePlaybackTest.php` |
| ポーリングは撮影者も 200 だが成果物 URL を含まない | `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php` |

### テスト計画

- [ ] 上記 5 本が**無変更で緑**であること (実装時のチェックリスト項目)
- [ ] `tests/Architecture/` の目録系 (`NestedRouteIdorDefenseTest` / `ControllerAuthorizationGateTest` /
      `ThrottleCoverageInventoryTest` / `AdoptedReadyTakeCriterionInventoryTest`) が**無変更で緑**であること
      (route が 1 本も増えないことの機械的な裏付け)

### リスク

- 「変えない」ことの確認は人手のチェックリストに依存する。→ route を 1 本も足さない設計なので、
  もし誰かが route を足せば上記 Architecture テストが deny-by-default で赤くなる (構造的な検出が効く)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | S1 → S2 → S3 が前提の鎖 (判定式 → props → 尺) で、S4 → S5 → S6 がその上に積み上がる。各段が独立してテスト可能 (S1/S2/S3 は Feature テスト、S4 は Vitest、S5/S6 は component テスト) であり、途中で止めても壊れた状態が残らない (S3 まで入れた時点では props が増えるだけで UI は無変更)。standalone にすると 8 施策が 1 コミットに固まり、レビューの粒度が落ちる |
| 競合リスク | `resources/js/pages/Capture/Show.svelte` は直近 T183/T184/T185/T186 で触られている高頻度ファイルであり、並行タスクがあると競合する。**S6 の変更範囲は左ペインヘッダとダイアログ設置の 2 箇所に限定**し、既存の全画面・D&D・サムネイル関連には触れない。`AdoptedReadyTakeCoverage` は T148 の正本ファイルであり、他タスクが同時に触る可能性は低いが、触る場合は本タスクを先に入れる |

## 実装順序 (incremental の段)

1. **S1** 判定式の単一化 (既存テストが緑のまま = 意味不変の確認)
2. **S2** props の新キー + キー集合テストの更新 + `ScenarioPreviewPropsTest` 新規
3. **S3** `previewPlaceholderSeconds` + `CaptureShow.test.ts` の props 更新
4. **S4** `scenario-preview.ts` + Vitest (UI 無変更)
5. **S5** `ScenarioPreviewDialog.svelte` + component テスト
6. **S6** `Capture/Show.svelte` 配線 + page テスト
7. **S7** ドキュメント
8. **S8** 非回帰の確認 (全レーン通し: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`)


---

## 関連する現行コード

### app/Services/Manual/AdoptedReadyTakeCoverage.php

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\DataTransferObjects\Manual\TakeCoverageData;
use App\Enums\Manual\TakeStatus;
use App\Models\Cut;
use App\Models\VideoManual;

/**
 * 「採用済みかつ ready のテイクを持つか」の**唯一の判定**。
 *
 * render (422 でブロック) と preview (ブロックせず告知) は**制裁が違うだけで基準は同じ**である。
 * 基準がファイルをまたいで複製されると再び乖離する (bug-hunt F-1-01 の構造的原因) ため、
 * 述語 isMissing() をここ 1 箇所に閉じ、`AdoptedReadyTakeCriterionInventoryTest` が
 * deny-by-default で「他ファイルが同じ判定を書き直していないこと」を機械検査する。
 *
 * 読み取り専用 (cuts / takes / status を 1 バイトも書かない)。
 */
final class AdoptedReadyTakeCoverage
{
    /**
     * 唯一の述語。**この式を他所へ写経しない**。
     *
     * TakeStatus は uploading / processing / ready / failed の 4 値を持つため、
     * 本述語が真になるのは「まだ撮っていない」だけではない
     * (採用済みだがアップロード中・処理中・失敗も含む = 「使用できる採用テイクがない」)。
     *
     * 前提: $cut は adoptedTake を eager load 済みで呼ぶこと
     * (CutSequencer::orderedWithLabels が `with('adoptedTake')` を張っている)。
     * lazy load でも結果は同じだが N+1 になる。
     */
    public static function isMissing(Cut $cut): bool
    {
        $take = $cut->adoptedTake;

        return $take === null || $take->status !== TakeStatus::Ready;
    }

    /**
     * 表示順カット列からの集計 (トリガー tx が既に持っている列を再利用する経路)。
     *
     * @param  list<OrderedCut>  $ordered
     */
    public static function fromOrdered(array $ordered): TakeCoverageData
    {
        $missing = [];
        foreach ($ordered as $entry) {
            if (self::isMissing($entry->cut)) {
                $missing[] = $entry->label;
            }
        }

        return new TakeCoverageData(totalCuts: count($ordered), missingLabels: $missing);
    }

    /** manual からの集計 (詳細画面 props の経路) */
    public static function for(VideoManual $manual): TakeCoverageData
    {
        return self::fromOrdered(CutSequencer::orderedWithLabels($manual));
    }
}

```

### app/DataTransferObjects/Capture/CaptureCutData.php

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\Take;

/**
 * 撮影 PWA へ返すカットの shape (takes 込み)。TS 側 types/capture.ts の CaptureCut と対で保守。
 * adopted_take_id の参照は読み取り直列化のみ (書き込み経路は CaptureTakeService に限定。
 * ScenarioWritePathInventoryTest 検出 4 が deny-by-default で固定する)。
 */
final readonly class CaptureCutData
{
    /**
     * @param  list<CaptureTakeData>  $takes
     */
    public function __construct(
        public Cut $cut,
        public array $takes,
    ) {}

    /**
     * takes は sort_order 順。採用テイクには playback URL / DL ACK トークンを付与できる
     * (詳細 GET のみ。null なら全テイク null = store/adopt 応答)。
     */
    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        $adoptedTakeId = $cut->adopted_take_id;
        $takes = $cut->takes()->orderBy('sort_order')->orderBy('id')->get()
            ->map(static function (Take $take) use ($adoptedTakeId, $adoptedPlaybackUrl, $adoptedAckToken): CaptureTakeData {
                $isAdopted = $adoptedTakeId !== null && $take->id === $adoptedTakeId;

                return CaptureTakeData::fromTake(
                    $take,
                    playbackUrl: $isAdopted ? $adoptedPlaybackUrl : null,
                    downloadAckToken: $isAdopted ? $adoptedAckToken : null,
                );
            })
            ->all();

        return new self($cut, array_values($takes));
    }

    /**
     * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, adopted_take_id: int|null,
     *   takes: list<array{id: int, client_take_id: string, status: string, size_bytes: int,
     *     duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
     *     downloaded: bool, playback_url: string|null, download_ack_token: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->cut->id,
            'type' => $this->cut->type->value,
            'parent_cut_id' => $this->cut->parent_cut_id,
            'scene' => $this->cut->scene,
            'shot_type' => $this->cut->shot_type->value,
            'shooting_point' => $this->cut->shooting_point,
            'narration' => $this->cut->narration,
            'subtitle_primary' => $this->cut->subtitle_primary,
            'subtitle_secondary' => $this->cut->subtitle_secondary,
            'adopted_take_id' => $this->cut->adopted_take_id,
            'takes' => array_map(
                static fn (CaptureTakeData $take): array => $take->toArray(),
                $this->takes,
            ),
        ];
    }
}

```

### app/DataTransferObjects/Capture/CaptureManualDetailData.php

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use Illuminate\Support\Collection;

/**
 * 撮影詳細 (Capture/Show) の manual + cuts + takes ツリー。
 * 採用テイクのみ署名 DL URL と DL 済み ACK トークンを付与する
 * (doc/10 §10.3 / 概念設計 D6。**本メソッドが唯一の設定経路**)。
 */
final readonly class CaptureManualDetailData
{
    /**
     * @param  list<CaptureCutData>  $cuts
     */
    public function __construct(
        public VideoManual $manual,
        public array $cuts,
    ) {}

    public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
    {
        // step 順 → 各 step 直後にその points (ScenarioDocumentData と同じ 1 パス整形)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->orderBy('sort_order')->get();
        /** @var Collection<int, Collection<int, Cut>> $grouped */
        $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
        /** @var Collection<int, Cut> $empty */
        $empty = new Collection;

        $ackExpiry = now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes'))->getTimestamp();
        $cutData = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            $cutData[] = self::cutWithAdoptedUrls($step, $user, $storage, $codec, $ackExpiry);
            foreach ($grouped->get($step->id) ?? $empty as $point) {
                $cutData[] = self::cutWithAdoptedUrls($point, $user, $storage, $codec, $ackExpiry);
            }
        }

        return new self($manual, $cutData);
    }

    /** 採用テイクがあれば署名 DL URL + ACK トークン (DL URL と同 TTL) を発行して cut を直列化 */
    private static function cutWithAdoptedUrls(Cut $cut, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec, int $ackExpiry): CaptureCutData
    {
        $adopted = $cut->adoptedTake;
        if ($adopted === null) {
            return CaptureCutData::fromCut($cut);
        }

        return CaptureCutData::fromCut(
            $cut,
            adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
            adoptedAckToken: $codec->sealAck(new DownloadAckClaims(
                takeId: $adopted->id,
                userId: $user->id,
                expiresAtTimestamp: $ackExpiry,
            )),
        );
    }

    /**
     * @return array{id: int, title: string, status: string, cuts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->manual->id,
            'title' => $this->manual->title,
            'status' => $this->manual->status->value,
            'cuts' => array_map(
                static fn (CaptureCutData $cut): array => $cut->toArray(),
                $this->cuts,
            ),
        ];
    }
}

```

### app/Http/Controllers/Capture/CaptureManualController.php (L98-122)

```php
    /** 撮影ナビ (cuts + 全 take メタ + 採用テイク署名 DL URL / ACK トークン) */
    public function show(
        Request $request,
        Project $project,
        VideoManual $manual,
        TakeObjectStorage $storage,
        UploadTicketCodec $codec,
        SeoManager $seo,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $manual); // 読み取りは撮影者含む org member

        // 撮影 PWA であることをタブ上で判別可能にする動的固有名
        $seo->setPrivateTitle($manual->title.' の撮影');

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Capture/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
        ]);
    }
}
```

### resources/js/types/capture.ts (L1-45)

```ts
/**
 * 撮影 PWA の型定義。PHP 側 App\DataTransferObjects\Capture\* と対で保守する
 * (キー集合の契約は tests/Feature/Capture/CaptureManualBrowsingTest が固定する)。
 */

export type TakeStatus = "uploading" | "processing" | "ready" | "failed";

export interface CaptureTake {
    id: number;
    client_take_id: string;
    status: TakeStatus;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
    /** サムネイルが生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
    has_thumbnail: boolean;
    /** 採用テイクのみ非 null (doc/10 §10.3) */
    playback_url: string | null;
    /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
    download_ack_token: string | null;
}

export interface CaptureCut {
    id: number;
    type: "step" | "point";
    parent_cut_id: number | null;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    adopted_take_id: number | null;
    takes: CaptureTake[];
}

export interface CaptureManualDetail {
    id: number;
    title: string;
    status: string;
    cuts: CaptureCut[];
}
```

### resources/js/lib/capture/take-endpoints.ts (L1-27)

```ts
/**
 * テイク API (capture.takes.*) の URL 導出。**規則をここ 1 箇所に置く**。
 *
 * この API 面は撮影 PWA (Capture/Show の TakeStrip) と PC 編集面
 * (Manuals/Takes) の**両方が叩く**。URL prefix が /app なのは歴史的経緯であり、
 * テイク資源の唯一の API 面である (doc/10 / docs/architecture.md §撮影 PWA の運用契約)。
 */
export interface TakeEndpointTarget {
    projectId: number;
    manualId: number;
    cutId: number;
}

/** カット配下のテイクコレクション URL (POST = 登録) */
export function cutTakesUrl({ projectId, manualId, cutId }: TakeEndpointTarget): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`;
}

/** テイク単体の URL (suffix で /adopt /playback 等を足す) */
export function takeUrl(target: TakeEndpointTarget, takeId: number, suffix = ""): string {
    return `${cutTakesUrl(target)}/${takeId}${suffix}`;
}

/** presigned upload-url 発行 URL */
export function takeUploadUrlEndpoint(target: TakeEndpointTarget): string {
    return `${cutTakesUrl(target)}/upload-url`;
}
```

### resources/js/lib/capture/cut-labels.ts (L1-26)

```ts
import type { CaptureCut } from "@/types/capture";

/**
 * カットの表示ラベル (手順 N / 急所 N-M) を cuts の並び順から導出する。
 * step は連番、point は直前 step の番号 + 枝番 (doc/10 §10.1)。
 *
 * CutNavigator の行ラベル・撮影パネルの見出し (F-1-03) ・テイクプレビューの
 * アクセシブルネーム (F-1-02) が同じ規則を共有するため、ここを唯一の導出元とする
 * (規則が 3 箇所に散るのを避ける)。
 */
export function buildCutLabels(cuts: CaptureCut[]): Record<number, string> {
    const result: Record<number, string> = {};
    let stepIndex = 0;
    let pointIndex = 0;
    for (const cut of cuts) {
        if (cut.type === "step") {
            stepIndex += 1;
            pointIndex = 0;
            result[cut.id] = `手順 ${stepIndex}`;
        } else {
            pointIndex += 1;
            result[cut.id] = `急所 ${stepIndex}-${pointIndex}`;
        }
    }
    return result;
}
```

### resources/js/pages/Capture/Show.svelte (L41-120)

```svelte
    /**
     * 撮影ナビ (doc/05 / 概念設計 D9)。cut を選び、録画 (または ファイル選択) →
     * 即時アップロード (upload-url → S3 PUT → POST takes)。失敗/オフラインは IndexedDB に
     * 一時保持し、フォアグラウンド復帰 / online / SW message で再送する。
     */
    interface Props {
        project: { id: number; name: string };
        manual: CaptureManualDetail;
    }

    let { project, manual }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /**
     * 横持ち全画面の初期判定。**テンプレートの初回描画より前**に確定させるため、
     * script のこの位置 (props 受領直後) で 1 度だけ評価する。
     * これより後ろで宣言すると selectedCutId の初期化が宣言前参照 (TDZ) になる。
     */
    const initialLandscape = matchesLandscapeCapture();

    /* 初期描画で全画面になる場合は、**同じ script 評価の中で**先頭カットも選んでおく。
     * 選ばずに全画面へ入ると、最初の 1 描画だけ「カットを選び直してください。」が出る。
     * mount 時点の値で確定させるのが意図どおりなので state_referenced_locally を明示的に無視する
     * (以降の追従は横持ち購読の $effect が担う)。 */
    // svelte-ignore state_referenced_locally
    let selectedCutId = $state<number | null>(
        initialLandscape ? (manual.cuts[0]?.id ?? null) : null,
    );
    const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
    /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
    const cutLabels = $derived(buildCutLabels(manual.cuts));
    // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)
    const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
    let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
    const showRecorder = $derived(canRecord && cameraUnavailableReason === null);
    // 撮影 active (recording|stopping) と recorder 参照 (preview の資源競合制御。T050 / S4)
    let captureActive = $state(false);
    let recorderRef = $state<CameraRecorderType | null>(null);
    // 実行時フォールバックの説明文 (reason で出し分け。静的 feature-detect 由来は
    // CaptureFileFallback 既存の説明文だけで足りるため notice なし)
    const fallbackNotice = $derived.by(() => {
        if (cameraUnavailableReason === null) return null;
        if (cameraUnavailableReason === "permission_denied") {
            return "カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザまたは端末・組織のカメラ設定を確認して再読み込みしてください。";
        }
        return "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。";
    });

    /* ---- アップロードキュー ---- */
    const store: PendingStore = createIdbPendingStore();
    const queue = new UploadQueue({ store });

    /* ---- 採用済みテイクの自動 DL (T051) ----
     * project.id / manual.id はインスタンス生存中は安定 (別 manual へ遷移すると Inertia が
     * ページを remount する。reload({only:["manual"]}) は id を変えない)。mount 時点の値で
     * 確定させるのが意図どおりなので state_referenced_locally を明示的に無視する。 */
    // svelte-ignore state_referenced_locally
    const autoDownloader = new AdoptedTakeAutoDownloader(project.id, manual.id);
    let pendingCount = $state(0);
    let pendingBytes = $state(0);
    let uploading = $state(false);
    let quotaMessage = $state<string | null>(null);

    async function refreshPending(): Promise<void> {
        const items = await store.list();
        pendingCount = items.length;
        pendingBytes = items.reduce((sum, item) => sum + item.blob.size, 0);
        quotaMessage = queue.quotaMessage;
    }

    /* ---- manual 再取得は single-flight ----
     * アップロード成功 / キュー再開 / 自動 DL / サムネイル反映の 4 経路が同じ 1 本を通る。
     * 直列化しないと、古い応答での上書きと監視集合の判定ずれが起きる。 */
    // ★ in-flight の Promise を**保持して返す**。即解決する Promise を返すと、
    //   scheduler が「再取得が終わった」と誤認して古い manual のまま次の試行を消費する。
    let inFlight: Promise<void> | null = null;
    function reloadManual(): Promise<void> {
        if (inFlight !== null) return inFlight; // 並行呼び出しには同じ Promise を返す
```

### resources/js/pages/Capture/Show.svelte (L455-500)

```svelte
             UploadQueueBar は props だけの表示 component なので、
             切替時に作り直されても失われる状態が無い。 -->
        {#if !fullscreenActive}
            <div class="mt-3">
                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
            </div>
        {/if}

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section
            bind:this={leftPaneEl}
            inert={fullscreenActive}
            class="min-w-0 rounded-md border border-border bg-surface"
            data-testid="capture-left-pane"
        >
            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
                     フォーカス可能にする (Tab 順には入れない)。 -->
                <h2
                    bind:this={cutListHeadingEl}
                    tabindex="-1"
                    class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                    data-testid="capture-cut-list-heading"
                >
                    シナリオ (タップして撮影)
                </h2>
                <!-- 横持ちなのに全画面でないとき (= 明示終了した後) の再入路。
                     文脈非該当時は非表示にする (disabled ではない)。 -->
                {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
                    <Button
                        variant="neutral"
                        size="sm"
                        onclick={enterFullscreen}
                        testId="enter-fullscreen-capture"
                    >
                        <Maximize class="size-4" aria-hidden="true" />
                        全画面で撮影
                    </Button>
                {/if}
            </div>
            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
        </section>

        <!--
          全画面は **この section の class を差し替えるだけ**で作る。
          CameraRecorder を別の {#if} ブランチへ移すと unmount され、録画中の
```

### resources/js/pages/Capture/Show.svelte (L650-671)

```svelte
                            onCaptured={(file) => handleCaptured(file, file.type, null)}
                        />
                    {/if}
                </div>

                {#if !fullscreenActive}
                    <TakeStrip
                        projectId={project.id}
                        manualId={manual.id}
                        cut={selectedCut}
                        cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
                        onChanged={reloadManual}
                        {captureActive}
                        onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
                        onCameraResume={() => void recorderRef?.resumeAfterPreview()}
                    />
                {/if}
            {/if}
        </section>
        </div>
    </PageContainer>
</AppLayout>
```

### resources/js/components/features/capture/TakePreviewDialog.svelte (L1-75)

```svelte
<script lang="ts">
    import { Captions, CaptionsOff } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { CaptureCut, CaptureTake } from "@/types/capture";

    /**
     * テイク単体のインラインプレビュー再生 (T050 / S2)。
     * 生映像を native <video controls> で再生し、cut の固定字幕を overlay で重ねる。
     * 採用ボタンを同居させ、確認しながらそのまま採用できる (doc/04・doc/05)。
     * 字幕は timed track ではなく cut 固定字幕の全編 overlay (構図確認用途)。
     */
    interface Props {
        open: boolean; // bindable
        take: CaptureTake | null; // 再生対象 (null で閉)
        cut: CaptureCut; // 字幕 (subtitle_primary/secondary) の供給元
        /** 手順 N / 急所 N-M。video のアクセシブルネームに使う (どのカットのテイクか) */
        cutLabel: string;
        playbackUrl: string | null; // takeUrl(take, "/playback")。親が組み立て
        adopting: boolean; // 採用 XHR 中
        error: string | null; // 採用失敗メッセージ (親の run() error を流用)
        onAdopt: () => void; // 親の adopt() を呼ぶ
        onClose: () => void; // 親: dialog close + 録画復帰
    }

    let {
        open = $bindable(false),
        take,
        cut,
        cutLabel,
        playbackUrl,
        adopting,
        error,
        onAdopt,
        onClose,
    }: Props = $props();

    let video: HTMLVideoElement | undefined = $state();
    let subtitlesOn = $state(true);

    // 再オープン時に字幕を初期 ON へ戻す (撮影 PWA は初期 ON。doc/05)。
    $effect(() => {
        if (open) {
            subtitlesOn = true;
        }
    });

    // video のデコード資源/ネットワーク接続を完全解放する。
    function teardownVideo(target: HTMLVideoElement): void {
        target.pause();
        target.removeAttribute("src");
        target.load();
    }

    // close / 採用成功で閉じる / take 差し替え / component 破棄を同一 cleanup で扱う。
    // effect 実行時の要素を固定し、差し替え時に新要素を誤 teardown しない。
    $effect(() => {
        if (!open || take === null || video === undefined) return;
        const target = video;
        return () => teardownVideo(target);
    });

    // Modal の bind:open が true→false に遷移した時のみ親へ通知する
    // (背景クリック / Esc / × / 閉じるボタン / 採用成功をすべて拾う)。
    // 初期 mount の false では発火させない (wasOpen ガード)。
    let wasOpen = false;
    $effect(() => {
        if (wasOpen && !open) {
            onClose();
        }
        wasOpen = open;
    });
</script>

<Modal bind:open title="テイクのプレビュー" size="lg" processing={adopting} testId="take-preview-dialog">
```

### app/Http/Controllers/Capture/CaptureTakeController.php (L140-175)

```php
        return CaptureTakeResource::make(CaptureTakeData::fromTake($acked));
    }

    /**
     * テイク単体のプレビュー再生 (302 → S3 署名 URL)。撮影者/編集者 (capture ability)。
     * doc/04 テイクプレビュー / doc/05 個別再生。採用前テイクも再生できる (adopted 限定でない)。
     *
     * nested route 整合 (認可より前に 404):
     * 1. {project} ∈ current org (project.in-route-org middleware + resolveOrganizationProject)
     * 2. {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は Route::scopeBindings()
     *
     * 302 応答は Cache-Control: no-store, private (期限付き署名 URL の再利用防止)。
     * ※ これはアプリの 302 応答のみを制御し、リダイレクト先ストレージの動画本体の
     *   cache までは保証しない (動画本体の非キャッシュは v1 要件外)。
     */
    public function playback(
        Request $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        Take $take,
        TakeObjectStorage $storage,
    ): RedirectResponse {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('preview', $take);

        // 再生可能条件: ready のみ。uploading/processing/failed は 404 とし、
        // 内部状態 (処理中/失敗) を存在有無として漏らさない (状態秘匿)
        if ($take->status !== TakeStatus::Ready) {
            abort(404);
        }

        // video_path は @property string (非 null カラム) = 型絞り込み問題なし
        return redirect()
```

### app/Support/Security/AdoptedTakeReferenceInventory.php (L26-60)

```php
    public static function entries(): array
    {
        return [
            'Services/Manual/AdoptedReadyTakeCoverage.php' => [
                'kind' => AdoptedTakeReferenceKind::Canonical,
                'rationale' => '判定式の実体。render の 422 と preview の事前告知・Placeholder 分岐が'
                    .'同じ述語 isMissing() を通るための唯一の場所 (bug-hunt F-1-01 の再発防止)。',
            ],
            'Services/Manual/CutSequencer.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => '表示順カット列の取得で with(adoptedTake) の eager load を張るだけで、'
                    .'ready 判定も採用有無の判定も持たない (N+1 回避のための構造上の参照)。',
            ],
            'Services/Manual/RenderJobService.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => '充足判定は AdoptedReadyTakeCoverage へ委譲済みで、残る参照は'
                    .'尺上限ソフトゲートが採用テイクの duration_ms を読む 1 箇所だけである。',
            ],
            'Services/Manual/RenderPipeline.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => 'clipSpecFor が isMissing() を呼んで Placeholder 分岐を決め、'
                    .'非欠落側でのみ素材パス (video_path) 取得のため take 実体を読む。',
            ],
            'Models/Cut.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => 'adoptedTake の belongsTo relation 宣言そのもの。'
                    .'判定式は一切持たず、参照の起点を提供するだけのモデル定義である。',
            ],
            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => '撮影ナビの表示用に採用テイクの実体を読むだけで ready 判定はしない。'
                    .'撮影中の端末に「今どれを採用しているか」を見せる別概念の面である。',
            ],
            'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
```

### tests/Feature/Capture/CaptureManualBrowsingTest.php (L176-197)

```php
test('show の take shape は TS CaptureTake と対のキー集合 (PHP↔TS 契約)', function (): void {
    [, $owner, $project] = browsingContext();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    Take::factory()->forCut($cut)->create();

    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");

    $take = $response->inertiaPage()['props']['manual']['cuts'][0]['takes'][0];
    expect(array_keys($take))->toBe([
        'id', 'client_take_id', 'status', 'size_bytes', 'duration_ms', 'comment',
        'captured_at', 'sort_order', 'downloaded', 'has_thumbnail', 'playback_url',
        'download_ack_token',
    ]);
    $cutShape = $response->inertiaPage()['props']['manual']['cuts'][0];
    expect(array_keys($cutShape))->toBe([
        'id', 'type', 'parent_cut_id', 'scene', 'shot_type', 'shooting_point',
        'narration', 'subtitle_primary', 'subtitle_secondary', 'adopted_take_id', 'takes',
    ]);
});

test('cross-org の project は index / show とも 404', function (): void {
```

