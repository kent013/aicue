## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

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

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel 12 + Svelte 5 (runes) + Inertia のコードレビュアーである。
TODO T191「撮影 PWA の全体連結プレビュー」の実装差分をレビューせよ。

## レビュー観点

1. **詳細設計との一致性**: 設計書 (下記) の 8 施策 S1〜S8 の契約を実装が満たしているか。
   逸脱があるなら「意図的で妥当か」「設計書のどの記述と食い違うか」を指摘せよ。
2. **正確性**: 状態機械・世代管理・二重取得防止・pause 抑止の論理に穴がないか。
   とくに「有限時間で必ず次へ進む」「1 本の失敗で止まらない」が破れる具体的な系列を探せ。
3. **PHPStan level 10 適合性** (array shape / null 安全 / generics)。
4. **DTO / JsonResource パターン**、response()->json() 直書きの不在。
5. **テスト網羅性**: 契約に対してテストが不足している箇所。テストが実装を追認するだけで
   壊れても赤くならない書き方になっていないか。
6. **セキュリティ**: 署名 URL / ACK トークンの発行条件、権限の非回帰、IDOR。
7. **DESIGN.md 準拠**: color / radius / typography は DS token 経由か。hex 直書き (#RRGGBB) を
   増やしていないか。
8. **Atomic Design 準拠**: `atoms → molecules → organisms → features/{domain} → templates → pages`
   の単方向 import。atom は単機能・状態を持たない。アイコンは @lucide/svelte のみ (SVG 直書きを増やさない)。

## 出力形式

- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を書く

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
| S2 | 撮影 props に `adopted_ready_take_id` を供給し、採用 URL の発行条件を揃える | `app/DataTransferObjects/Capture/CaptureCutData.php` / `CaptureManualDetailData.php` / `app/Support/Security/AdoptedTakeReferenceInventory.php` / `resources/js/types/capture.ts` | 高 |
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
     * 前提 ($cut の adoptedTake の鮮度。3 段で読むこと):
     *   1. **一覧の直列化では eager load 必須** (`with('adoptedTake')`)。無いと N+1 になる。
     *   2. **単一 Cut の直列化では lazy load を許容する** — relation 未ロードで、かつ最新の
     *      `adopted_take_id` を持つインスタンスなら結果は同じである (adopt 応答の経路)。
     *   3. **古い relation cache を持つインスタンスは不可**。ロード後に `adopted_take_id` を
     *      書き換えたインスタンスをそのまま渡さないこと (呼び出し側の責務)。
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

## S2: 撮影 props に `adopted_ready_take_id` を供給し、採用 URL の発行条件を揃える

### 変更箇所

- `app/DataTransferObjects/Capture/CaptureCutData.php` (コンストラクタ / `fromCut()` / `toArray()` の array shape)
- `app/DataTransferObjects/Capture/CaptureManualDetailData.php`
  (L36 付近: cuts の eager load / L57-72 `cutWithAdoptedUrls()` の発行条件)
- `app/Support/Security/AdoptedTakeReferenceInventory.php` (`CaptureManualDetailData` の区分と根拠)
- `resources/js/types/capture.ts` (`CaptureCut`)

### 意図

端末側が再生すべきテイクを**サーバが決めて渡す**。TypeScript 側で
「`adopted_take_id` があり、かつその take の `status === "ready"`」を組み立てさせない。

**併せて採用テイクの署名 URL / ACK トークンの発行条件を同じ述語に揃える (S2b)**。
現行は `$cut->adoptedTake` が非 `ready` (uploading / processing / failed) でも
`temporaryPlaybackUrl()` と ACK トークンを発行しており、
**`capture.takes.playback` が非 ready を 404 にしている (状態秘匿) のと食い違う** —
同じ資源に対して片方の経路だけ ready ゲートを持たない状態になっている。
`adopted_ready_take_id` を足すと、この食い違いは
「`adopted_ready_take_id` は null なのに `takes.*.playback_url` は非 null」という
**クライアント契約の矛盾**として表面化する。よって本施策で発行条件を
`AdoptedReadyTakeCoverage::readyTakeId()` に揃える。

**副次的な効果**: 自動 DL (`AdoptedTakeAutoDownloader`) が非 ready の採用テイクを
先読みして `downloaded_at` を立てることが無くなる (処理中のテイクを「取得済み」と記録しない)。
ready になった後の入室 / online 復帰で改めて取得される (冪等な既存経路。取りこぼしは無い)。

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
  stale entry として逆に fail する。`CaptureManualDetailData` は eager load 文字列 `'adoptedTake'` と
  プロパティフェッチ `$cut->adoptedTake` を持つため引き続き母集団に入る。
  **区分を `DifferentCriterion` → `DelegatedToCoverage` へ変更する** (URL 発行の可否を
  `readyTakeId()` へ委譲し、自前の ready 判定を持たなくなるため。区分は「何のために触っているか」の記録である)。
- **array shape の同期**: 現行 `CaptureCutData::toArray()` の docblock は入れ子の take shape に
  `has_thumbnail` を欠いている (`CaptureTakeData::toArray()` の宣言済み shape には存在する)。
  本施策で**現行実装と完全同期**させる (新キー追加のついでではなく、明示の是正項目として扱う)。

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

// CaptureManualDetailData::cutWithAdoptedUrls — **status を見ずに URL / ACK を発行している**
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
// CaptureManualDetailData::cutWithAdoptedUrls — 発行条件を唯一の述語へ揃える (S2b)
    private static function cutWithAdoptedUrls(Cut $cut, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec, int $ackExpiry): CaptureCutData
    {
        // 「使用できる採用テイクか」の判定は AdoptedReadyTakeCoverage が唯一の所在である。
        // 非 ready の採用テイクへ署名 URL / ACK を出さない = takes.playback の 404 (状態秘匿) と
        // 同じゲートに揃える (RenderPipeline::clipSpecFor と同じ書き方)。
        if (AdoptedReadyTakeCoverage::readyTakeId($cut) === null) {
            return CaptureCutData::fromCut($cut);
        }

        // 述語が false なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
        $adopted = $cut->adoptedTake;
        Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');

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
```

```php
// AdoptedTakeReferenceInventory — 区分を委譲側へ変更し、根拠を実態へ合わせる
            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => '採用テイクの署名 URL / ACK を出すかどうかを'
                    .'AdoptedReadyTakeCoverage::readyTakeId() へ委譲し、自前の ready 判定は持たない。'
                    .'残る参照は非欠落側で素材パスと take id を読む 1 箇所と、N+1 を防ぐ eager load である。',
            ],
```

> **`fromCut()` の事前条件 (adoptedTake の鮮度)**: 本メソッドは
> **`adoptedTake` relation が最新の状態で呼ぶこと**を前提にする。現在の 2 経路はどちらも満たしている:
> - **詳細画面**: `CaptureManualDetailData::fromManual()` が `with('adoptedTake')` で
>   その時点の値を eager load する。
> - **adopt 応答**: `CaptureTakeService::adopt()` が **tx 内で `$lockedManual->cuts()->whereKey(...)`
>   から取り直した Cut を返す**ため、relation は未ロードで、`forceFill(['adopted_take_id' => ...])` の
>   後に新しい id で lazy load される (controller が bind した `$cut` インスタンスは返らない)。
>
> **`unsetRelation('adoptedTake')` を Service / Controller に足す形は採れない**:
> `CaptureTakeService` と `CaptureTakeController` はどちらも `TakeStatus::Ready` を含むため、
> `'adoptedTake'` の文字列を足すと `AdoptedReadyTakeCriterionInventoryTest` の**検出 B (判定式の同居)** に
> 該当する。名指し免除の前提 2 (`'adoptedTake'` の出現がすべて `->doesntHave('adoptedTake')` の
> 単独引数形) も満たせないため、**gate を弱めない限り登録できない**。
> 仮定の将来事故のために不変条件の gate を緩めるのは本末転倒なので、
> **鮮度は behavioral テストで守る** (下のテスト計画。誰かが eager load を足して鮮度を壊したら赤くなる)。
>
> **DTO がドメイン service を呼ぶことについて**: 本リポジトリには既に
> `DataTransferObjects/Manual/TakeSelectionPageData` → `Services\Manual\CutSequencer`、
> `DataTransferObjects/Manual/ManualListItemData` → `Services\Manual\ManualRowAbilities` の先例がある。
> 逆に「呼び出し側が計算して DTO へ渡す」形にすると、`fromCut()` の呼び出し口
> (詳細画面 / adopt 応答) ごとに**渡し忘れうる**形になり、T148 が閉じたはずの
> 「呼び出し側が判定を組み立てる」形へ戻る。**DTO 側が唯一の述語を呼ぶ**方が構造的に安全である
> (この理由は `CaptureCutData` の docblock に残す)。

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
- [ ] **S2b**: 採用済みだが非 ready (uploading / processing / failed) のカットでは
      `takes.*.playback_url` と `takes.*.download_ack_token` が **null** であること
      (= `adopted_ready_take_id` が null のとき URL も出ない、という契約の 1:1 対応)
- [ ] **S2b**: 採用済み + ready では従来どおり URL と ACK が出ること (既存
      `CaptureManualBrowsingTest`「show は cuts+takes を返し、採用テイクのみ playback_url…」が
      **無変更で緑**。Take Factory の既定 status は `ready` のため既存テストは影響を受けない)
- [ ] **S2b**: 非 ready の採用テイクで `TakeObjectStorage::temporaryPlaybackUrl` が
      **1 度も呼ばれない**こと (Mockery の `shouldNotReceive`。署名 URL を発行しないことの直接固定)
- [ ] 既存 `tests/js/lib/capture/auto-download.test.ts` が**無変更で緑**であること
      (自動 DL は `playback_url` / `download_ack_token` の非 null で対象を列挙しており、
      供給側が絞られるだけで列挙ロジックは変わらない)
- [ ] **relation 鮮度 (adopt 応答)**: ready テイクを adopt した直後の応答で
      `adopted_ready_take_id` が**採用したテイク id** になること
      (= `adoptedTake` の relation cache が古いまま直列化されていないことの behavioral 固定)
- [ ] **relation 鮮度 (採用の付け替え)**: 既に別のテイクを採用しているカットで
      2 本目を adopt したとき、応答の `adopted_ready_take_id` が**新しい方**になること
- [ ] **relation 鮮度 (非 ready への遷移は起きない)**: adopt は ready のテイクしか受け付けないため
      (`CaptureTakeService::adopt` が 422)、adopt 応答で `adopted_ready_take_id` が null になるのは
      **仕様上あり得ない**ことを、上の 2 ケースで間接的に固定する
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
- **S2b は既存挙動の変更である**。非 ready の採用テイクは自動 DL の対象から外れ、
  `downloaded_at` が立つのが ready 後にずれる。→ 取りこぼしは起きない (入室 / online 復帰の
  既存経路が冪等に再試行する) が、`downloaded_at` の立つ時点が変わることを doc の
  「`downloaded_at` は可用性指標」の記述と矛盾しない形で確認する。
- **S2b で「採用済みだが処理中」のカットの見え方が変わる** (DL 済みバッジが後から付く)。
  これは「処理が終わっていないものを取得済みと呼ばない」という是正であり、意図した変化である。
- **`adoptedTake` の鮮度は構造ではなく規律 + テストで守る** (gate の制約により
  Service / Controller 側で relation を落とす形が採れないため)。将来 `adopt()` が
  「controller が bind した Cut をそのまま返す」形に変わると壊れる。→ 上の behavioral テスト
  2 本がその瞬間に赤くなる。

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
      `config('manual.preview_placeholder_seconds')` と一致する **int** かつ **1 以上**であること
      (0 以下だとプレースホルダが一瞬で流れて確認にならないため。
      **新しい config gate は作らない** — 今必要なのはこの props の契約だけであり、
      設定値そのものの範囲検査は `ConfigHardeningTest` の議題を増やす価値がまだ無い)
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

/**
 * 初期状態 (先頭 entry の種別で clip / placeholder が決まる)。
 *
 * **entries が空のときの `clip` は意味を持たない** — `finished: true` の状態では
 * UI も reducer も `clip` を読まない (reducer は先頭で `finished` を見て素通しする)。
 * 便宜上 `"placeholder"` を入れるが、**この値に依存する分岐を書かない**
 * (この約束は Vitest の「空リストでは finished かつどのイベントでも状態が変わらない」で固定する)。
 */
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
    // **非表示中はメディア由来のイベントを受け付けない**。実メディアを pause() しても、
    // 既にキューへ入った ended / error は到着しうるため、実要素の操作だけに依存しない
    // (非表示の間に勝手に次のカットへ進むのを構造で止める)。
    // 利用者操作 (skip / retry) と可視性 (hidden / shown) と時間 (tick) は常に処理する。
    if (!state.visible && isMediaOriginEvent(event.type)) return state;

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
            // **利用者操作由来の pause だけがここへ来る** (component が programmatic pause を送らない)。
            // 読み込み中に利用者が止めることもあるため loading からも受け付ける
            // (受け付けないと「止めたのに停滞監視が動き続けて failed になる」)。
            return state.clip === "playing" || state.clip === "loading" ? { ...state, clip: "paused" } : state;
        case "resumed":
            return state.clip === "paused" ? { ...state, clip: "loading", progressAt: event.at } : state;
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

/**
 * 時間経過: プレースホルダの尺満了と停滞判定の 2 つだけを見る。
 *
 * `failed` の表示待ちにも `placeholderSeconds` を流用する (**欠落と同じ長さで通過させる**)。
 * 別の設定値を新設しないのは、どちらも「見せてから次へ進むまでの待ち」であり、
 * 2 つ持つと必ず食い違うためである (値の意味は「プレースホルダ表示秒数」のまま)。
 */
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

/**
 * メディア要素が起点のイベント (非表示中は受け付けない側)。
 * `Set<PreviewEvent["type"]>` が担保するのは**要素型の正当性**だけで、
 * **必要なイベントの登録漏れは検出しない** (漏れは下の Vitest が拾う)。
 */
const MEDIA_ORIGIN_EVENTS = new Set<PreviewEvent["type"]>([
    "progress",
    "playing",
    "paused",
    "resumed",
    "ended",
    "error",
    "blocked",
]);

function isMediaOriginEvent(type: PreviewEvent["type"]): boolean {
    return MEDIA_ORIGIN_EVENTS.has(type);
}
```

### PHPStan適合チェック

- 該当なし (TypeScript / Svelte のみ)。代わりに `pnpm typecheck` (svelte-check) と `pnpm lint` を通す。
- [x] 戻り値の型が明示されている — 公開関数はすべて明示の戻り値型を持つ
      (`PreviewEntry[]` / `PreviewState` / `boolean` / `number`)
- [x] null 安全 — `PreviewEntry` は判別可能 union、`slotGeneration` は `number | null` で、
      **`?? undefined` への変換を禁止**する (null は「世代なし」として捨てる)
- [x] 配列返却ではなく型付きの値を返す — 状態は `PreviewState` (interface) で持ち、
      緩い `Record<string, unknown>` を作らない
- [x] Generics の型パラメータ — `Set<PreviewEvent["type"]>` の要素型を明示する

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
- [ ] **空**: entries が 0 件なら初期状態で `finished: true` になり、**どのイベントを送っても
      状態が変わらない** (= `clip` の値に依存する分岐が無いことの固定)
- [ ] **loading 中の一時停止**: `loading` → `paused` を受け付け、以後 `tick` で `failed` にならない。
      `resumed` で `loading` に戻り、進捗が来れば `playing` になる
- [ ] **`resumed` は `progressAt` を引き直す** (停止していた時間を停滞に数えない)
- [ ] **非表示中のメディア由来イベントを受け付けない**: `hidden` の後に `ended` を送っても
      `index` / `generation` が変わらない。`error` / `playing` / `paused` も同様に無視される
- [ ] **非表示中でも利用者操作は効く**: `hidden` の後の `skip` は次へ進む
      (メディア由来と利用者由来を取り違えていないことの固定)

### リスク

- 停滞閾値 20 秒は**推定値**である。現場の回線で先頭バッファに 20 秒以上かかるケースがあると、
  正常な素材を「再生できませんでした」と表示する。→ 閾値は注入可能にしてあり、
  実地の観測が出るまで動かさない方針を docblock に明記する。
- `tick` の駆動は component 側の `setInterval` に依存する。**駆動が止まれば停滞検出も止まる**
  (この非対称は「保証しないもの」に記載済み)。
- `paused` を「利用者操作由来のみ」と定義したため、**component が programmatic pause を
  送らないこと**が契約の前提になる (S5 のフラグで担保し、component テストで固定する)。
  破ると「teardown の pause が最後のクリップを paused にする」といった誤りが入る。

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
        /** bindable。親 (Capture/Show) が `bind:open` で開閉する */
        open: boolean;
        projectId: number;
        manualId: number;
        cuts: CaptureCut[];
        /** buildCutLabels の結果 (規則を再実装しない) */
        labels: Record<number, string>;
        placeholderSeconds: number;
        onClose: () => void;
    }

    // `open` は必ず $bindable で受ける (TakePreviewDialog と同じ契約。
    // これが無いと親の bind:open が壊れる)
    let {
        open = $bindable(false),
        projectId,
        manualId,
        cuts,
        labels,
        placeholderSeconds,
        onClose,
    }: Props = $props();

    /** 現在再生に使っている要素 (0 = videoA / 1 = videoB)。advance のたびに反転する */
    let activeSlot = $state<0 | 1>(0);
    /** 各 slot に**現在割り当てている src** (再代入による二重取得を防ぐ台帳) */
    let slotSrc = $state<[string | null, string | null]>([null, null]);
    /**
     * 各 slot に割り当てた**世代**の台帳。
     * slot の要素から届いたイベントには**この世代**を付けて reducer へ送る
     * (slot 反転後に旧要素から遅延イベントが届いても、世代不一致で捨てられる)。
     * active 割当時は現在の `generation`、先読み時は `generation + 1` を入れる。
     */
    let slotGeneration = $state<[number | null, number | null]>([null, null]);
    /**
     * slot 別の pause 抑止。**`pause()` の直後に戻さない** — pause イベントは非同期に配送されるため、
     * 「イベントを受けた時点で消費する」形にしないと抑止が効かない。
     * 2 枚あるので単一 boolean では発生元を区別できない。
     */
    let suppressPause = $state<[boolean, boolean]>([false, false]);
    /**
     * slot 別の**割り当て世代** (assignment epoch)。`{#key}` に渡して**要素ごと作り直す**ための値で、
     * `src + generation` を**別資源へ割り当て直すときだけ**増やす。
     *
     * 世代台帳 (`slotGeneration`) だけでは、次の順序を識別できない:
     *   (1) slot に旧 src・旧世代を割り当てる → (2) 旧 src 由来のイベントがキューへ入る →
     *   (3) 同じ slot を新 src・新世代へ割り当て直す → (4) 旧イベントが配送され、
     *   ハンドラが**新しい** slotGeneration を読んでしまう。
     * 要素ごと作り直せば listener も一緒に破棄されるため、この経路が構造的に消える。
     * **先読み済み slot の active 昇格では割り当てを変えない**ので、二重取得は起きない。
     */
    let assignmentId = $state<[number, number]>([0, 0]);
</script>
```

主要な配線 (実装時の契約):

| 要素 | 契約 |
|---|---|
| 再生リスト | `open` になった時点の `cuts` から `buildPreviewEntries()` で 1 度だけ組む (再生中に props が更新されても差し替えない = 位置が飛ばない)。閉じて開き直したら組み直す |
| メディア要素 | `videoA` / `videoB` の 2 枚。`activeSlot: 0 \| 1` を state で持ち、**現在再生 = active、先読み = inactive**。`advance` 時に `activeSlot` を反転し、先読み済み要素をそのまま再生する |
| **src 割り当ての契約 (二重取得を作らない)** | `slotSrc` を**台帳**として持ち、割り当ては次の 3 規則だけで行う。(a) **`slotSrc[slot]` が設定したい src と等しく、かつ `slotGeneration[slot]` が割り当てたい世代と等しいなら何もしない** (再代入しない = 再取得しない)。**同一性は `src` だけでなく `src + generation` で判断する** (同じ URL が続けて現れても世代の割当を省略しない)。(b) `advance` では **`activeSlot` を反転するだけ**で、新しい active 側の `src` には触れない (先読み済みの要素をそのまま使う)。(c) 先読みは (a) の同一性判定で異なるときだけ設定する。**「active entry を見て active 要素に src を入れる」形の `$effect` は書かない** (先読み済み URL の再代入 = 二重取得になる) |
| **世代の台帳** | `slotGeneration: [number \| null, number \| null]`。active 割当時は現在の `generation`、先読み時は `generation + 1` を入れる。**イベントハンドラは発火した slot の `slotGeneration[slot]` を `event.generation` として渡す**。teardown では `slotSrc` / `slotGeneration` / `suppressPause` を**同時に**初期化する |
| **null 世代のメディアイベントは捨てる** | **`slotGeneration[slot]` が `null` のメディア由来イベントは dispatch しない**。`?? undefined` へ落とすと reducer が「世代省略 = 現在世代」とみなし、**teardown 後に遅延到着した `pause` / `error` / `ended` が現在のクリップへ誤適用される**。`generation` の省略を許すのは **`skip` / `retry` / `hidden` / `shown` / `tick`** (利用者操作・ページ・時間の同期イベント) だけである |
| **割り当て世代 (assignment epoch) で要素を分離** | slot へ**別資源** (`src + generation` が変わる割り当て) を入れるときは `assignmentId[slot]` を増やし、`{#key assignmentId[slot]}` で **`<video>` 要素ごと作り直す**。旧要素は listener ごと破棄されるため、**同一 slot を再利用した後に旧資源の遅延イベントが新割り当てとして受理されることが構造的に無くなる**。**先読み済み slot の active 昇格では `assignmentId` を変えない** (要素を保持 = バッファを捨てない = 再取得しない) |
| 先読み | 現在クリップが `playing` になった時点で、**次の 1 件だけ** inactive 側へ (c) の規則で `src` を設定し `preload="auto"` にする。次が `missing` / 末尾なら何もしない (inactive の `slotSrc` / `slotGeneration` を `null` に戻して teardown する) |
| **進んだ先の同期 (先読みが無い経路の補完)** | `advance` の直後に **destination entry で active slot を同期する**。これが無いと `clip → missing → clip` や**先頭が missing**・**missing 連続**の並びで、次の clip に `src` を割り当てる主体が存在せず**再生不能になる** (先読みは「現在 clip が playing になったとき」しか走らず、`missing` は `playing` にならないため)。規則は 4 つ: (i) destination が `clip` で **active slot の `src + generation` が一致**するなら**何もしない** (先読み成功経路 = 再取得しない)。(ii) destination が `clip` で一致しないときだけ active slot へ `src + generation` を割り当てる (missing 後 / 初回 / 先読み失敗のフォールバック)。(iii) destination が `missing` なら active slot を teardown する。(iv) **「active entry を見て無条件に src を再代入する `$effect`」は禁止**だが、**「台帳と一致しないときだけ補完する」ことは許可**する (この違いが二重取得の有無を分ける) |
| イベント | `canplay` / `timeupdate` / `progress` → `progress`、`playing` → `playing`、`pause`(利用者操作) → `paused`、`play` → `resumed`、`ended` → `ended`、`error` → `error` を **その要素の世代付きで** reducer へ送る |
| `play()` | 戻り値の Promise を必ず `catch` する。**呼び出し時点の `generation` を closure へ退避してから** `play()` する (`catch` の中で `slotGeneration[slot]` を読み直すと、要素再生成後の**新しい世代**を読みうる)。退避した世代が `null` なら何も送らない。**世代が一致し、かつ自動再生制限と判定できる拒否** (`err instanceof DOMException && err.name === "NotAllowedError"`) のみ `blocked` を送る。それ以外は**何も送らない** (失敗の確定は `error` と停滞監視に委ねる)。**この設計は「停滞監視が必ず回収する」ことに依存している** — 拒否後は進捗イベントが来ないため `stallTimeoutMs` 経過で `failed` → 次のカットへ進む。この回収を component テストで固定する (下記) |
| `tick` | `setInterval` (1 秒) で `tick` を送る。ダイアログを閉じるときに必ず破棄する |
| **programmatic pause** | teardown / 非表示 / スキップで自分から止めるときは、**必ず次の helper を通す**。`pause` ハンドラは**抑止が立っていたら消費して (false に戻して) reducer へ送らない** (`paused` は利用者操作由来のみ = S4 の契約)。**`pause()` の直後にフラグを戻さない** (イベントは非同期に届く)。<br>**抑止を残さないための 3 点**: (a) **既に paused の要素には抑止を立てない** (`pause` イベントが発火せず抑止が残り、その slot が後で active になったときに**本物の利用者 pause を誤って握り潰す**)。(b) slot へ新しい `src + generation` を割り当てるときにその slot の抑止をクリアする。(c) teardown でもクリアする |

```ts
/** メディア由来イベントの唯一の送出口。**世代が確定していないものは送らない** */
type MediaOriginEventType = "progress" | "playing" | "paused" | "resumed" | "ended" | "error" | "blocked";

function dispatchMediaEvent(slot: 0 | 1, type: MediaOriginEventType): void {
    const generation = slotGeneration[slot];
    if (generation === null) return; // teardown 済み / 未割当の要素からの遅延イベントは捨てる

    dispatch({ type, generation, at: Date.now() });
}

/** 自分から止めるときの唯一の入口。既に paused なら抑止を立てない (消費されない抑止を残さない) */
function pauseProgrammatically(slot: 0 | 1, video: HTMLVideoElement): void {
    if (video.paused) {
        suppressPause[slot] = false;

        return;
    }
    suppressPause[slot] = true;
    video.pause();
}

function handlePause(slot: 0 | 1): void {
    if (suppressPause[slot]) {
        suppressPause[slot] = false; // 抑止は**イベントを受けた時点で消費**する

        return;
    }
    dispatchMediaEvent(slot, "paused"); // 世代が null なら送られない (?? undefined へ落とさない)
}
```

| 可視性 | `visibilitychange` で `hidden` / `shown` を送る。`hidden` では**実メディアも `pause()` する** (programmatic pause 扱い。非表示中に `ended` で勝手に次へ進まないため)。`shown` で再生状態が `playing` なら `play()` を試みる (`paused` / `blocked` なら何もしない = 再生状態は変えない) |
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
- [ ] **`NotAllowedError` 以外の拒否**では即 `failed` にせず、`stallTimeoutMs` 経過後に
      `failed` → 次のカットへ進む (**停滞監視による回収**。loading のまま固まらないことの固定)
- [ ] **programmatic pause** (非表示 / teardown / スキップで自分から `pause()` したとき) が
      `paused` 状態を作らない。逆に**利用者操作の pause は `paused` になる**
- [ ] **抑止は slot 別**: 片方の slot を programmatic に pause した後、
      **もう片方の slot の利用者 pause は抑止されない**
- [ ] **抑止は消費されるまで残る**: `pause()` 直後に同期的にイベントが来なくても、
      後から届いた `pause` が抑止される (fake timer / microtask をまたぐケース)
- [ ] **世代の台帳**: slot 反転後に**旧 slot の要素から** `ended` / `error` が届いても、
      その slot の `slotGeneration` が現世代と異なるため状態が変わらない
- [ ] **進んだ先の同期 (再生不能を作らない)**: 次の 4 つの並びで、clip に必ず `src` が入ること
      — `missing → clip` / `clip → missing → clip` / `missing → missing → clip` /
      **先頭が missing** の並び
- [ ] **補完が二重取得を作らない**: 先読み済みの `clip → clip` では、補完処理が
      `src` を**再代入しない** (台帳一致で何もしない経路を通る)
- [ ] **抑止を残さない**: **既に paused の inactive slot** へ programmatic pause を行った後、
      その slot が active になり利用者が再生 → pause したとき、
      **利用者の pause が抑止されず `paused` になる**
- [ ] **teardown 後の遅延イベント**: teardown で `slotGeneration` を null にした後に届く
      `pause` / `error` / `ended` が**状態を変えない** (= dispatch されない)
- [ ] **null 世代は送らない**: `slotGeneration[slot] === null` のメディアイベントが
      dispatch されないこと (`?? undefined` へ落としていないことの直接固定)
- [ ] **同一 slot の再利用**: 同じ slot へ**新しい src** を割り当てた後、
      **旧要素から届く `error` / `ended` が新しいクリップを壊さない**
      (`assignmentId` により要素が作り直され、旧 listener が消えていることの固定。
      既存の「slot 反転後の旧 slot」テストでは同一 slot の再利用を検証できない)
- [ ] **昇格では要素を作り直さない**: 先読み済み slot が active になるとき `assignmentId` が
      変わらない (= バッファを捨てない = 再取得しない)
- [ ] **二重取得を作らない**: 先読み済みの slot が active になったあと、その要素の `src` が
      **再代入されない** (`setAttribute`/代入回数を数える、または `slotSrc` 台帳で固定)
- [ ] 非表示中に `ended` が起きても次へ進まない (実メディアを `pause()` しているため発火しないが、
      発火した場合も reducer の `visible=false` で進まないことを固定)
- [ ] 閉じたときに両方の `<video>` が teardown され、interval が破棄される
- [ ] 最終 entry の `ended` で「すべてのカットを再生しました」が出る

### リスク

- jsdom は実メディア再生を行わないため、component テストで固定できるのは
  **DOM 契約とイベント配線まで**である (実際の連続再生の滑らかさは実機確認の領域)。
  → この非対称を docblock と `docs/architecture.md` に明記する (誇張しない)。
- iOS Safari は `playsinline` が無いと全画面再生に切り替わる。`TakePreviewDialog` と同じく
  **`playsinline` を必ず付ける** (付け忘れると通し再生が毎クリップ全画面になり体験が壊れる)。
- `NotAllowedError` 以外の拒否を stall 回収に委ねる設計は、**回収まで最大 `stallTimeoutMs`
  待たせる**。即 `failed` にする案より遅いが、正常なクリップを誤って欠落として見せないことを
  優先した (概念設計の決定。`blocked` を素材の失敗にしない方針と同じ理由)。
- 完了条件に **`pnpm test` / `pnpm typecheck` / `pnpm build` を個別に含める**
  (Svelte 5 の `$bindable` と Modal の Portal 周りは typecheck を通っても build で落ちることがある)。

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
                <!-- 狭幅で 2 つのボタンが詰まらないよう折り返しを許す (justify-end で右寄せを保つ) -->
                <div class="flex flex-wrap items-center justify-end gap-2">
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
     * (禁止事項 8)。判定条件・呼び出し順・文言は**既存の個別 preview (TakeStrip.openPreview) と
     * 同一**にする (資源競合の条件を 2 種類持たない)。
     *
     * `captureActive` は recording|stopping を含む (CameraRecorder が
     * onCaptureActiveChange で通知する既存の値)。`releaseForPreview()` は
     * **同期の `void` 関数**で、録画中・取得中は自分で早期 return する
     * (CameraRecorder L487-491)。したがって await も失敗ハンドリングも要らない。
     */
    function openScenarioPreview(): void {
        scenarioPreviewError = null;
        if (captureActive) {
            // 文言は TakeStrip.openPreview と同じ言い回しに揃える (同じ制約を別の言葉で言わない)
            scenarioPreviewError = "撮影中は通し再生を開始できません。撮影を停止してからお試しください。";
            return;
        }
        releaseCameraForPreview(); // TakeStrip へ渡しているものと同じ関数 (page 側に 1 つだけ持つ)
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

> **カメラ資源の解放・復帰は page 側に 1 つの関数として切り出す**:
> `releaseCameraForPreview()` = `recorderRef?.releaseForPreview()`、
> `resumeCameraAfterPreview()` = `void recorderRef?.resumeAfterPreview()`。
> `TakeStrip` の `onRequestCameraRelease` / `onCameraResume` props にも**同じ関数**を渡す
> (現在は inline arrow で書かれている箇所を関数参照へ置き換えるだけ。2 か所に書かない)。
>
> **非同期性について**: `releaseForPreview(): void` は同期であり、
> 録画中・stream 取得中は自分で早期 return する (CameraRecorder L487-491)。
> `resumeAfterPreview(): Promise<void>` は非同期だが in-flight ガードを内部に持ち、
> 呼び出し側は既存 `TakeStrip` と同じく戻り値を待たない (`void`)。
> よって「release が非同期で競合が残る」ことは無い。

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
- [ ] 録画中に押したときの**文言が個別 preview と同じ言い回し**であること
      (同じ制約を別の言葉で説明しない。文言の同一性はテストで固定する)
- [ ] 既存の全画面・並べ替え・アップロード系ケースが緑のまま (props 追加による回帰なし)

### リスク

- 左ペインヘッダにボタンが 2 つ並ぶため、狭い画面で詰まる。→ ボタン群を
  `flex flex-wrap items-center justify-end gap-2` で包み、`size="sm"` で既存ボタンと大きさを揃える。
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
   (完了条件: `pnpm test` / `pnpm typecheck` / `pnpm lint` / **`pnpm build`** を個別に緑にする)
6. **S6** `Capture/Show.svelte` 配線 + page テスト
   (完了条件: 同上。`tests/js/architecture/atomic-import-graph.test.ts` を含む)
7. **S7** ドキュメント
8. **S8** 非回帰の確認 (全レーン通し: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`)

## design system 参照 (DESIGN.md 抜粋)

---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#B91C1C"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### 触れた atomic ディレクトリ構造

- 新規: resources/js/components/features/capture/ScenarioPreviewDialog.svelte
  (import: atoms/Alert, atoms/Button, organisms/Modal, lib/capture/scenario-preview, types/capture)
- 変更: resources/js/pages/Capture/Show.svelte (features/capture/ScenarioPreviewDialog を import)
- 新規: resources/js/lib/capture/scenario-preview.ts (lib は component 階層の外)

## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Capture/CaptureCutData.php b/app/DataTransferObjects/Capture/CaptureCutData.php
index 9a77e4f..a8881ac 100644
--- a/app/DataTransferObjects/Capture/CaptureCutData.php
+++ b/app/DataTransferObjects/Capture/CaptureCutData.php
@@ -6,20 +6,30 @@
 
 use App\Models\Cut;
 use App\Models\Take;
+use App\Services\Manual\AdoptedReadyTakeCoverage;
 
 /**
  * 撮影 PWA へ返すカットの shape (takes 込み)。TS 側 types/capture.ts の CaptureCut と対で保守。
  * adopted_take_id の参照は読み取り直列化のみ (書き込み経路は CaptureTakeService に限定。
  * ScenarioWritePathInventoryTest 検出 4 が deny-by-default で固定する)。
+ *
+ * **「使用できる採用テイクか」の判定は自前で持たない** — DTO 側が唯一の述語
+ * (`AdoptedReadyTakeCoverage::readyTakeId()`) を呼ぶ。呼び出し側が計算して渡す形にすると
+ * fromCut() の呼び出し口 (詳細画面 / adopt 応答) ごとに渡し忘れうる形になり、
+ * T148 が閉じた「呼び出し側が判定を組み立てる」構造へ戻るためである
+ * (先例: TakeSelectionPageData → CutSequencer / ManualListItemData → ManualRowAbilities)。
  */
 final readonly class CaptureCutData
 {
     /**
      * @param  list<CaptureTakeData>  $takes
+     * @param  int|null  $adoptedReadyTakeId  使用できる採用テイクの id
+     *                                        (`AdoptedReadyTakeCoverage::readyTakeId()` の戻り値そのもの。判定は持たない)
      */
     public function __construct(
         public Cut $cut,
         public array $takes,
+        public ?int $adoptedReadyTakeId,
     ) {}
 
     /**
@@ -41,16 +51,20 @@ public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?st
             })
             ->all();
 
-        return new self($cut, array_values($takes));
+        // 「使用できる採用テイクか」の判定は AdoptedReadyTakeCoverage が唯一の所在である
+        // (ここで adopted_take_id と TakeStatus::Ready を組み直さない = T148)。
+        return new self($cut, array_values($takes), AdoptedReadyTakeCoverage::readyTakeId($cut));
     }
 
     /**
      * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
      *   shot_type: string, shooting_point: string|null, narration: string,
      *   subtitle_primary: string|null, subtitle_secondary: string, adopted_take_id: int|null,
+     *   adopted_ready_take_id: int|null,
      *   takes: list<array{id: int, client_take_id: string, status: string, size_bytes: int,
      *     duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
-     *     downloaded: bool, playback_url: string|null, download_ack_token: string|null}>}
+     *     downloaded: bool, has_thumbnail: bool, playback_url: string|null,
+     *     download_ack_token: string|null}>}
      */
     public function toArray(): array
     {
@@ -65,6 +79,9 @@ public function toArray(): array
             'subtitle_primary' => $this->cut->subtitle_primary,
             'subtitle_secondary' => $this->cut->subtitle_secondary,
             'adopted_take_id' => $this->cut->adopted_take_id,
+            // 通し再生が再生する対象。null = そのカットはプレースホルダになる
+            // (「採用されていない」と「採用済みだが ready でない」を区別しない = 述語の意味そのまま)
+            'adopted_ready_take_id' => $this->adoptedReadyTakeId,
             'takes' => array_map(
                 static fn (CaptureTakeData $take): array => $take->toArray(),
                 $this->takes,
diff --git a/app/DataTransferObjects/Capture/CaptureManualDetailData.php b/app/DataTransferObjects/Capture/CaptureManualDetailData.php
index c92e8d7..4a79c4a 100644
--- a/app/DataTransferObjects/Capture/CaptureManualDetailData.php
+++ b/app/DataTransferObjects/Capture/CaptureManualDetailData.php
@@ -9,7 +9,9 @@
 use App\Models\VideoManual;
 use App\Services\Capture\TakeObjectStorage;
 use App\Services\Capture\UploadTicketCodec;
+use App\Services\Manual\AdoptedReadyTakeCoverage;
 use Illuminate\Support\Collection;
+use Webmozart\Assert\Assert;
 
 /**
  * 撮影詳細 (Capture/Show) の manual + cuts + takes ツリー。
@@ -29,8 +31,9 @@ public function __construct(
     public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
     {
         // step 順 → 各 step 直後にその points (ScenarioDocumentData と同じ 1 パス整形)
+        // adoptedTake は cut ごとに読むため eager load 必須 (無いと cuts 件数分の N+1 になる)
         /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
-        $cuts = $manual->cuts()->orderBy('sort_order')->get();
+        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->get();
         /** @var Collection<int, Collection<int, Cut>> $grouped */
         $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
         /** @var Collection<int, Cut> $empty */
@@ -48,14 +51,24 @@ public static function fromManual(VideoManual $manual, User $user, TakeObjectSto
         return new self($manual, $cutData);
     }
 
-    /** 採用テイクがあれば署名 DL URL + ACK トークン (DL URL と同 TTL) を発行して cut を直列化 */
+    /**
+     * 使用できる採用テイクがあれば署名 DL URL + ACK トークン (DL URL と同 TTL) を発行して cut を直列化。
+     *
+     * 発行条件は AdoptedReadyTakeCoverage が唯一の所在である。非 ready の採用テイクへ
+     * 署名 URL / ACK を出さない = `capture.takes.playback` が非 ready を 404 にしている
+     * (状態秘匿) のと同じゲートに揃える (RenderPipeline::clipSpecFor と同じ書き方)。
+     */
     private static function cutWithAdoptedUrls(Cut $cut, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec, int $ackExpiry): CaptureCutData
     {
-        $adopted = $cut->adoptedTake;
-        if ($adopted === null) {
+        if (AdoptedReadyTakeCoverage::readyTakeId($cut) === null) {
             return CaptureCutData::fromCut($cut);
         }
 
+        // 述語が非 null なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
+        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
+        $adopted = $cut->adoptedTake;
+        Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');
+
         return CaptureCutData::fromCut(
             $cut,
             adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
diff --git a/app/Http/Controllers/Capture/CaptureManualController.php b/app/Http/Controllers/Capture/CaptureManualController.php
index 0176db1..3206f1c 100644
--- a/app/Http/Controllers/Capture/CaptureManualController.php
+++ b/app/Http/Controllers/Capture/CaptureManualController.php
@@ -117,6 +117,9 @@ public function show(
         return Inertia::render('Capture/Show', [
             'project' => ['id' => $project->id, 'name' => $project->name],
             'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
+            // 通し再生でプレースホルダを表示する秒数。サーバ生成プレビューの黒背景尺と
+            // **同じ設定値**を使う (2 つのプレビューの構造を揃える。単位は秒・正の整数)。
+            'previewPlaceholderSeconds' => config()->integer('manual.preview_placeholder_seconds'),
         ]);
     }
 }
diff --git a/app/Services/Manual/AdoptedReadyTakeCoverage.php b/app/Services/Manual/AdoptedReadyTakeCoverage.php
index fe4d425..d7561e8 100644
--- a/app/Services/Manual/AdoptedReadyTakeCoverage.php
+++ b/app/Services/Manual/AdoptedReadyTakeCoverage.php
@@ -22,6 +22,32 @@
  */
 final class AdoptedReadyTakeCoverage
 {
+    /**
+     * 「使用できる採用テイク」の **id** (無ければ null)。**この式が唯一の実体**である。
+     *
+     * `isMissing()` は本メソッドの上に載る (bool しか返さない述語のままだと、id が要る側が
+     * `adopted_take_id` と `TakeStatus::Ready` を組み直すことになり、T148 が閉じた二重化が
+     * そのまま復活する)。撮影 PWA の通し再生はこの id を props 経由で受け取り、
+     * TypeScript 側で述語を再実装しない。
+     *
+     * 前提 ($cut の adoptedTake の鮮度。3 段で読むこと):
+     *   1. **一覧の直列化では eager load 必須** (`with('adoptedTake')`)。無いと N+1 になる
+     *      (CutSequencer::orderedWithLabels / CaptureManualDetailData::fromManual が張っている)。
+     *   2. **単一 Cut の直列化では lazy load を許容する** — relation 未ロードで、かつ最新の
+     *      `adopted_take_id` を持つインスタンスなら結果は同じである (adopt 応答の経路)。
+     *   3. **古い relation cache を持つインスタンスは不可**。ロード後に `adopted_take_id` を
+     *      書き換えたインスタンスをそのまま渡さないこと (呼び出し側の責務)。
+     */
+    public static function readyTakeId(Cut $cut): ?int
+    {
+        $take = $cut->adoptedTake;
+        if ($take === null || $take->status !== TakeStatus::Ready) {
+            return null;
+        }
+
+        return $take->id;
+    }
+
     /**
      * 唯一の述語。**この式を他所へ写経しない**。
      *
@@ -29,15 +55,11 @@ final class AdoptedReadyTakeCoverage
      * 本述語が真になるのは「まだ撮っていない」だけではない
      * (採用済みだがアップロード中・処理中・失敗も含む = 「使用できる採用テイクがない」)。
      *
-     * 前提: $cut は adoptedTake を eager load 済みで呼ぶこと
-     * (CutSequencer::orderedWithLabels が `with('adoptedTake')` を張っている)。
-     * lazy load でも結果は同じだが N+1 になる。
+     * 実体は readyTakeId() 側にある (述語の意味は同じ)。
      */
     public static function isMissing(Cut $cut): bool
     {
-        $take = $cut->adoptedTake;
-
-        return $take === null || $take->status !== TakeStatus::Ready;
+        return self::readyTakeId($cut) === null;
     }
 
     /**
diff --git a/app/Support/Security/AdoptedTakeReferenceInventory.php b/app/Support/Security/AdoptedTakeReferenceInventory.php
index 36824e8..d602bf9 100644
--- a/app/Support/Security/AdoptedTakeReferenceInventory.php
+++ b/app/Support/Security/AdoptedTakeReferenceInventory.php
@@ -52,9 +52,10 @@ public static function entries(): array
                     .'判定式は一切持たず、参照の起点を提供するだけのモデル定義である。',
             ],
             'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
-                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
-                'rationale' => '撮影ナビの表示用に採用テイクの実体を読むだけで ready 判定はしない。'
-                    .'撮影中の端末に「今どれを採用しているか」を見せる別概念の面である。',
+                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
+                'rationale' => '採用テイクの署名 URL / ACK を出すかどうかを'
+                    .'AdoptedReadyTakeCoverage::readyTakeId() へ委譲し、自前の ready 判定は持たない。'
+                    .'残る参照は非欠落側で素材パスと take id を読む 1 箇所と、N+1 を防ぐ eager load である。',
             ],
             'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                 'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
diff --git "a/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md" "b/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md"
index f3807d9..da50fc4 100644
--- "a/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md"
+++ "b/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md"
@@ -41,6 +41,13 @@ ### シナリオ詳細画面（アプリの中心）
 - **ナレーション試聴**: 録画ボタン左の発話アイコンで当該カットのナレーションを再生（再タップで停止）。
 - **字幕表示**: 録画ボタン右の字幕アイコンで、カメラ映像上に字幕を重畳（構図確認用。再タップで非表示）。
 - **[プレビュー]**: 各カットの先頭（左端）テイクを連結した全体映像を確認。
+  - **実装注記 (T191)**: 連結の素材は **採用テイク (`adopted_take_id`)** である。元資料の「一番左のテイクが
+    全体プレビュー・採用候補」という記述は「左端 = 採用候補 = プレビューに使われるもの」という 1 つの概念を
+    指しており、本実装はそれを明示的な採用状態として持つ。完成動画・サーバ生成プレビューと**同じ素材**を
+    使わないと「通しで見て問題なかったのに完成動画は別物」になるため、採用テイクを正とする。
+    使用できる採用テイク（採用済みかつ ready）が無いカットはプレースホルダになる
+    （尺は `config('manual.preview_placeholder_seconds')`）。契約と保証しないものは
+    `docs/architecture.md` §撮影 PWA の通し再生 (端末側連結再生) が正本。
 - **[アップロード]**: 確認ダイアログで OK → その端末で新規撮影したテイクのみをサーバー送信 → 完了で「全テイクのアップロードが完了しました」。
 
 ### 撮影 UI（縦持ち / 横持ち）
diff --git a/docs/architecture.md b/docs/architecture.md
index 21131d1..590b4ad 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1333,6 +1333,34 @@ ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
     (`SelectableTakeData`) は撮影 PWA の `CaptureTakeData` と**合流させない**
     (「今は null だから安全」を作らない)。再生は `playback` の 302 経由のみである
 
+### 撮影 PWA の通し再生 (端末側連結再生) (T191)
+
+撮影者向けの全体連結プレビューは**端末側でテイクを順に再生する**方式である
+(サーバ生成プレビューを撮影者に開く方式は採らなかった。比較と決定理由は
+`devnotes/20260816-1754-capture-full-scenario-preview/conceptual-design.md`)。
+
+- **素材は採用テイク**である。選択は `AdoptedReadyTakeCoverage::readyTakeId()` が決め、
+  `cuts.*.adopted_ready_take_id` として props に載る (クライアントは述語を持たない = ドメイン規約 12)。
+  同じ述語が**署名 playback URL / DL ACK トークンの発行条件**にもなっており、非 ready の採用テイクへは
+  URL を出さない (`capture.takes.playback` が非 ready を 404 にしているのと同じゲート)。
+  帰結として自動 DL は ready になってから走る (`downloaded_at` が立つ時点が後ろへずれる。
+  取りこぼしは入室 / online 復帰の冪等な既存経路が拾う)。
+- **route も ability も増やさない**。既存の `capture.takes.playback` (`TakePolicy::preview` =
+  撮影者可) をカット順に叩くだけである。**サーバ生成プレビューの起動は編集者専用のまま**で、
+  `render_max_inflight_previews_per_org` (org 同時 preview 上限) を**消費しない**。
+  チケットも消費しない (preview は元から非消費)。
+- **判断は `lib/capture/scenario-preview.ts` (純関数) が持ち**、component は配線とメディア要素の
+  操作だけを行う。**1 本の失敗で止まらない** — 再生できなかったカットはプレースホルダに落ちて次へ進む。
+  プレースホルダ尺はサーバ生成プレビューと同じ `config('manual.preview_placeholder_seconds')` である。
+- **カメラ資源と同居しない**: 撮影中は開かず、押下時にエラーを出す (ボタンは disabled にしない)。
+  開くときに `releaseForPreview()`、閉じるときに `resumeAfterPreview()` を通す
+  (個別テイク preview と同じ 1 組の関数)。
+- **保証しないもの**: オフライン再生 / 完成動画と同じ見え方 (字幕は overlay、正規化なし) /
+  プレースホルダ尺の厳密一致 / 1 クリップ 1 回取得 (再試行では増える) /
+  停滞判定の閾値の妥当性 (固定するのは「表示中かつ再生要求中なら有限時間で必ず次へ進む」ことだけ) /
+  `blocked` (自動再生制限) から自動で抜けること (出口は利用者操作のみ) /
+  実機での連続再生の滑らかさ (component テストが見るのは DOM 契約とイベント配線まで)。
+
 ## 退会 (アカウント削除) の課金ガード (T115)
 
 - **不変条件**: 「**唯一 Owner** かつ (**他メンバーが残る** ∨ **生きた課金責務がある**) 組織」が
diff --git a/resources/js/components/features/capture/ScenarioPreviewDialog.svelte b/resources/js/components/features/capture/ScenarioPreviewDialog.svelte
new file mode 100644
index 0000000..6920e6f
--- /dev/null
+++ b/resources/js/components/features/capture/ScenarioPreviewDialog.svelte
@@ -0,0 +1,519 @@
+<script lang="ts">
+    import { tick, untrack } from "svelte";
+    import { Captions, CaptionsOff, LoaderCircle, Play, SkipForward } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Modal from "@/components/organisms/Modal.svelte";
+    import {
+        buildPreviewEntries,
+        initialPreviewState,
+        missingCount,
+        reducePreview,
+        type PreviewEntry,
+        type PreviewEvent,
+        type PreviewOptions,
+    } from "@/lib/capture/scenario-preview";
+    import type { CaptureCut } from "@/types/capture";
+
+    /**
+     * 通し再生 (全体連結プレビュー。doc/05 §5.2 [プレビュー] / T191)。
+     *
+     * - 素材は**採用テイク**である (先頭テイクではない)。選択はサーバの
+     *   `AdoptedReadyTakeCoverage` が決め、`cut.adopted_ready_take_id` として渡ってくる。
+     * - 使用できる採用テイクが無いカットはプレースホルダを placeholderSeconds 秒表示して次へ進む。
+     * - **1 本の失敗で通し再生を止めない**。判断は lib/capture/scenario-preview.ts が持ち、
+     *   このコンポーネントは配線とメディア要素の操作だけを行う。
+     * - **2 枚の <video> を交互に使う**。次のクリップは非表示側の要素へ先読みし、
+     *   進むときに役割を入れ替える (同じ動画を 2 回取得しない)。
+     *
+     * **保証しないもの**: jsdom は実メディア再生を行わないため、component テストが固定できるのは
+     * DOM 契約とイベント配線までである (実機での連続再生の滑らかさは実機確認の領域)。
+     */
+    interface Props {
+        /** bindable。親 (Capture/Show) が `bind:open` で開閉する */
+        open: boolean;
+        projectId: number;
+        manualId: number;
+        cuts: CaptureCut[];
+        /** buildCutLabels の結果 (規則を再実装しない) */
+        labels: Record<number, string>;
+        placeholderSeconds: number;
+        onClose: () => void;
+    }
+
+    let {
+        open = $bindable(false),
+        projectId,
+        manualId,
+        cuts,
+        labels,
+        placeholderSeconds,
+        onClose,
+    }: Props = $props();
+
+    /** 再生リスト。**open になった時点の cuts から 1 度だけ組む** (再生中に位置が飛ばない) */
+    let entries = $state<PreviewEntry[]>([]);
+    /**
+     * 閉じている間の状態 (entries 0 件 = finished)。**open の時点で startPreview が必ず組み直す**ため、
+     * ここで props を読まない (初期値だけを捕まえる参照を作らない)。
+     */
+    let previewState = $state(initialPreviewState({ entries: [], placeholderSeconds: 0 }, 0));
+    let subtitlesOn = $state(true);
+
+    /** 現在再生に使っている要素 (0 = videoA / 1 = videoB)。advance のたびに反転する */
+    let activeSlot = $state<0 | 1>(0);
+    /** 各 slot に**現在割り当てている src** (再代入による二重取得を防ぐ台帳) */
+    let slotSrc = $state<[string | null, string | null]>([null, null]);
+    /**
+     * 各 slot に割り当てた**世代**の台帳。
+     * slot の要素から届いたイベントには**この世代**を付けて reducer へ送る
+     * (slot 反転後に旧要素から遅延イベントが届いても、世代不一致で捨てられる)。
+     * active 割当時は現在の `generation`、先読み時は `generation + 1` を入れる。
+     */
+    let slotGeneration = $state<[number | null, number | null]>([null, null]);
+    /**
+     * slot 別の pause 抑止。**`pause()` の直後に戻さない** — pause イベントは非同期に配送されるため、
+     * 「イベントを受けた時点で消費する」形にしないと抑止が効かない。
+     * 2 枚あるので単一 boolean では発生元を区別できない。
+     */
+    let suppressPause = $state<[boolean, boolean]>([false, false]);
+    /**
+     * slot 別の**割り当て世代** (assignment epoch)。`{#key}` に渡して**要素ごと作り直す**ための値で、
+     * `src + generation` を**別資源へ割り当て直すときだけ**増やす。
+     *
+     * 世代台帳 (`slotGeneration`) だけでは、次の順序を識別できない:
+     *   (1) slot に旧 src・旧世代を割り当てる → (2) 旧 src 由来のイベントがキューへ入る →
+     *   (3) 同じ slot を新 src・新世代へ割り当て直す → (4) 旧イベントが配送され、
+     *   ハンドラが**新しい** slotGeneration を読んでしまう。
+     * 要素ごと作り直せば listener も一緒に破棄されるため、この経路が構造的に消える。
+     * **先読み済み slot の active 昇格では割り当てを変えない**ので、二重取得は起きない。
+     */
+    let assignmentId = $state<[number, number]>([0, 0]);
+
+    // bind:this は**破棄時に null を書き戻す**ため null 許容で持つ (undefined ではない)
+    let videoA = $state<HTMLVideoElement | null>(null);
+    let videoB = $state<HTMLVideoElement | null>(null);
+    let ticker: ReturnType<typeof setInterval> | null = null;
+
+    const missing = $derived(missingCount(entries));
+    const currentEntry = $derived<PreviewEntry | undefined>(entries[previewState.index]);
+
+    function currentOptions(): PreviewOptions {
+        return { entries, placeholderSeconds };
+    }
+
+    function elementFor(slot: 0 | 1): HTMLVideoElement | null {
+        return slot === 0 ? videoA : videoB;
+    }
+
+    function otherSlot(slot: 0 | 1): 0 | 1 {
+        return slot === 0 ? 1 : 0;
+    }
+
+    /* ---- メディア要素の操作 (判断は lib 側。ここは操作だけ) ---- */
+
+    /** メディア由来イベントの唯一の送出口。**世代が確定していないものは送らない** */
+    type MediaOriginEventType = "progress" | "playing" | "paused" | "resumed" | "ended" | "error" | "blocked";
+
+    function dispatchMediaEvent(slot: 0 | 1, type: MediaOriginEventType): void {
+        const generation = slotGeneration[slot];
+        // teardown 済み / 未割当の要素からの遅延イベントは捨てる
+        // (`?? undefined` へ落とすと reducer が「世代省略 = 現在世代」とみなして誤適用する)
+        if (generation === null) return;
+
+        dispatch({ type, generation, at: Date.now() });
+    }
+
+    /** 自分から止めるときの唯一の入口。既に paused なら抑止を立てない (消費されない抑止を残さない) */
+    function pauseProgrammatically(slot: 0 | 1, video: HTMLVideoElement): void {
+        if (video.paused) {
+            suppressPause[slot] = false;
+
+            return;
+        }
+        suppressPause[slot] = true;
+        video.pause();
+    }
+
+    function handlePause(slot: 0 | 1): void {
+        if (suppressPause[slot]) {
+            suppressPause[slot] = false; // 抑止は**イベントを受けた時点で消費**する
+
+            return;
+        }
+        dispatchMediaEvent(slot, "paused");
+    }
+
+    /**
+     * slot へ資源を割り当てる。**台帳と一致するなら何もしない** (再代入 = 再取得を作らない)。
+     * 同一性は `src` だけでなく `src + generation` で判断する。
+     */
+    function assignSlot(slot: 0 | 1, entry: PreviewEntry, generation: number): void {
+        if (entry.kind !== "clip") {
+            teardownSlot(slot);
+
+            return;
+        }
+        if (slotSrc[slot] === entry.src && slotGeneration[slot] === generation) return;
+
+        // 別資源への割り当て直しなので要素ごと作り直す (旧 listener を構造的に捨てる)
+        assignmentId[slot] += 1;
+        slotSrc[slot] = entry.src;
+        slotGeneration[slot] = generation;
+        suppressPause[slot] = false;
+    }
+
+    /** slot の資源を解放する (pause → src 除去 → load)。台帳も同時に初期化する */
+    function teardownSlot(slot: 0 | 1): void {
+        const video = elementFor(slot);
+        if (video !== null) {
+            pauseProgrammatically(slot, video);
+            video.removeAttribute("src");
+            video.load();
+        }
+        slotSrc[slot] = null;
+        slotGeneration[slot] = null;
+        suppressPause[slot] = false;
+    }
+
+    /**
+     * active slot の再生を試みる。**呼び出し時点の世代を closure へ退避してから** play() する
+     * (catch の中で台帳を読み直すと、要素再生成後の新しい世代を読みうる)。
+     */
+    async function playActive(): Promise<void> {
+        await tick(); // src の反映 / 要素の再生成を待ってから再生する
+        const slot = activeSlot;
+        const generation = slotGeneration[slot];
+        const video = elementFor(slot);
+        if (video === null || generation === null) return;
+
+        const started = video.play() as Promise<void> | undefined;
+        if (started === undefined) return; // Promise を返さない実装 (古い WebKit / jsdom)
+
+        started.catch((error: unknown) => {
+            if (generation !== previewState.generation) return;
+            // **自動再生制限と判定できる拒否だけ** blocked にする。
+            // それ以外は何も送らない (失敗の確定は error と停滞監視に委ねる)。
+            if (error instanceof DOMException && error.name === "NotAllowedError") {
+                dispatch({ type: "blocked", generation, at: Date.now() });
+            }
+        });
+    }
+
+    /** 現在クリップが再生に入ったら、**次の 1 件だけ**非表示側へ先読みする */
+    function prefetchNext(): void {
+        const inactive = otherSlot(activeSlot);
+        const next = entries[previewState.index + 1];
+        if (next === undefined || next.kind !== "clip") {
+            teardownSlot(inactive);
+
+            return;
+        }
+        assignSlot(inactive, next, previewState.generation + 1);
+    }
+
+    /**
+     * 進んだ先の同期。先読みが無い経路 (先頭 / missing の後 / 先読み失敗) を補完する。
+     * **台帳と一致するときは何もしない**ので、先読み成功経路で二重取得にならない。
+     */
+    function syncDestination(): void {
+        const old = activeSlot;
+        teardownSlot(old); // 再生し終えたクリップの資源を解放する
+        const next = otherSlot(old);
+        activeSlot = next;
+
+        const entry = entries[previewState.index];
+        if (entry === undefined || entry.kind !== "clip") {
+            teardownSlot(next);
+
+            return;
+        }
+        assignSlot(next, entry, previewState.generation);
+        void playActive();
+    }
+
+    /* ---- 状態遷移の受け口 ---- */
+
+    function dispatch(event: PreviewEvent): void {
+        const before = previewState;
+        const after = reducePreview(before, event, currentOptions());
+        if (after === before) return;
+        previewState = after;
+
+        if (after.index !== before.index) {
+            if (after.finished) {
+                stopPlayback();
+
+                return;
+            }
+            syncDestination();
+
+            return;
+        }
+        if (after.clip === "playing" && before.clip !== "playing") {
+            prefetchNext();
+        }
+    }
+
+    /* ---- 開始 / 終了 ---- */
+
+    function startPreview(): void {
+        entries = buildPreviewEntries(cuts, labels, { projectId, manualId });
+        previewState = initialPreviewState(currentOptions(), Date.now());
+        subtitlesOn = true;
+        activeSlot = 0;
+        teardownSlot(0);
+        teardownSlot(1);
+
+        const first = entries[0];
+        if (first !== undefined && first.kind === "clip") {
+            assignSlot(0, first, previewState.generation);
+            void playActive();
+        }
+        if (ticker !== null) clearInterval(ticker);
+        ticker = setInterval(() => dispatch({ type: "tick", at: Date.now() }), 1_000);
+        // 「もう一度再生」でも通るため、必ず外してから付ける (二重登録を作らない)
+        document.removeEventListener("visibilitychange", handleVisibility);
+        document.addEventListener("visibilitychange", handleVisibility);
+    }
+
+    /** メディア資源と時間駆動だけを止める (状態は残す = 終端表示を出せる) */
+    function stopPlayback(): void {
+        if (ticker !== null) {
+            clearInterval(ticker);
+            ticker = null;
+        }
+        teardownSlot(0);
+        teardownSlot(1);
+    }
+
+    function stopPreview(): void {
+        stopPlayback();
+        document.removeEventListener("visibilitychange", handleVisibility);
+    }
+
+    function handleVisibility(): void {
+        if (document.visibilityState !== "visible") {
+            const video = elementFor(activeSlot);
+            // 非表示中に ended で勝手に次へ進まないよう、実メディアも自分から止める
+            if (video !== null) pauseProgrammatically(activeSlot, video);
+            dispatch({ type: "hidden", at: Date.now() });
+
+            return;
+        }
+        dispatch({ type: "shown", at: Date.now() });
+        if (previewState.clip === "playing") void playActive();
+    }
+
+    // 開閉の単一の観測点。**true→false でだけ**後始末して親へ通知する
+    // (背景クリック / Esc / × / 閉じるボタンをすべて拾う)。
+    let wasOpen = false;
+    $effect(() => {
+        if (open === wasOpen) return;
+        wasOpen = open;
+        if (open) {
+            untrack(() => startPreview());
+
+            return;
+        }
+        untrack(() => {
+            stopPreview();
+            onClose();
+        });
+    });
+
+    // component 破棄時も必ず資源を解放する (interval / listener を残さない)
+    $effect(() => () => stopPreview());
+
+    /* ---- 利用者操作 ---- */
+
+    function retry(): void {
+        dispatch({ type: "retry", at: Date.now() });
+        void playActive();
+    }
+
+    function skip(): void {
+        dispatch({ type: "skip", at: Date.now() });
+    }
+
+    function replay(): void {
+        startPreview();
+    }
+</script>
+
+<Modal bind:open title="通し再生" size="lg" testId="scenario-preview-dialog">
+    <!-- 再生の内部状態を DOM 契約として露出する (Capture/Show の data-fullscreen と同じ流儀)。
+         これが無いと「一時停止したか」「どちらの要素が再生中か」を DOM から観測できない。 -->
+    <div
+        class="flex flex-col gap-3"
+        data-testid="scenario-preview-body"
+        data-clip={previewState.clip}
+        data-index={previewState.index}
+        data-generation={previewState.generation}
+        data-active-slot={activeSlot}
+    >
+        <div class="flex items-center justify-between gap-2">
+            <p class="text-caption text-text-secondary" data-testid="scenario-preview-position">
+                {#if previewState.finished || currentEntry === undefined}
+                    {entries.length} / {entries.length}
+                {:else}
+                    {currentEntry.label} ({previewState.index + 1} / {entries.length})
+                {/if}
+            </p>
+            <Button
+                variant="ghost"
+                size="sm"
+                onclick={() => (subtitlesOn = !subtitlesOn)}
+                ariaExpanded={subtitlesOn}
+                testId="scenario-preview-subtitle-toggle"
+            >
+                {#if subtitlesOn}
+                    <Captions class="size-4" aria-hidden="true" />
+                    字幕を隠す
+                {:else}
+                    <CaptionsOff class="size-4" aria-hidden="true" />
+                    字幕を表示
+                {/if}
+            </Button>
+        </div>
+
+        {#if missing > 0}
+            <Alert type="warning" testId="scenario-preview-coverage-note">
+                {missing} / {entries.length} 件のカットに、撮影・処理が完了した採用テイクがありません。その区間はプレースホルダになります。
+            </Alert>
+        {/if}
+
+        <div class="relative w-full overflow-hidden rounded-md bg-text/5">
+            <!-- 2 枚の要素を交互に使う。**非表示側は先読み用**であり、進むときに役割が入れ替わる -->
+            {#key assignmentId[0]}
+                <!-- svelte-ignore a11y_media_has_caption -->
+                <video
+                    bind:this={videoA}
+                    controls
+                    playsinline
+                    preload="auto"
+                    src={slotSrc[0] ?? undefined}
+                    class={activeSlot === 0 ? "w-full" : "hidden"}
+                    aria-label="通し再生 (1 枚目)"
+                    data-testid="scenario-preview-video-0"
+                    data-assignment={assignmentId[0]}
+                    onplaying={() => dispatchMediaEvent(0, "playing")}
+                    onplay={() => dispatchMediaEvent(0, "resumed")}
+                    onpause={() => handlePause(0)}
+                    onended={() => dispatchMediaEvent(0, "ended")}
+                    onerror={() => dispatchMediaEvent(0, "error")}
+                    oncanplay={() => dispatchMediaEvent(0, "progress")}
+                    ontimeupdate={() => dispatchMediaEvent(0, "progress")}
+                    onprogress={() => dispatchMediaEvent(0, "progress")}
+                ></video>
+            {/key}
+            {#key assignmentId[1]}
+                <!-- svelte-ignore a11y_media_has_caption -->
+                <video
+                    bind:this={videoB}
+                    controls
+                    playsinline
+                    preload="auto"
+                    src={slotSrc[1] ?? undefined}
+                    class={activeSlot === 1 ? "w-full" : "hidden"}
+                    aria-label="通し再生 (2 枚目)"
+                    data-testid="scenario-preview-video-1"
+                    data-assignment={assignmentId[1]}
+                    onplaying={() => dispatchMediaEvent(1, "playing")}
+                    onplay={() => dispatchMediaEvent(1, "resumed")}
+                    onpause={() => handlePause(1)}
+                    onended={() => dispatchMediaEvent(1, "ended")}
+                    onerror={() => dispatchMediaEvent(1, "error")}
+                    oncanplay={() => dispatchMediaEvent(1, "progress")}
+                    ontimeupdate={() => dispatchMediaEvent(1, "progress")}
+                    onprogress={() => dispatchMediaEvent(1, "progress")}
+                ></video>
+            {/key}
+
+            {#if !previewState.finished && currentEntry !== undefined}
+                {#if currentEntry.kind === "missing"}
+                    <p
+                        class="flex min-h-32 items-center justify-center p-4 text-body text-text-secondary"
+                        data-testid="scenario-preview-placeholder"
+                    >
+                        {currentEntry.label}: 撮影・処理が完了した採用テイクがありません
+                    </p>
+                {:else if previewState.clip === "failed"}
+                    <p
+                        class="flex min-h-32 items-center justify-center p-4 text-body text-text-secondary"
+                        data-testid="scenario-preview-failed"
+                    >
+                        {currentEntry.label}: このカットは再生できませんでした
+                    </p>
+                {:else if previewState.clip === "loading"}
+                    <p
+                        class="flex items-center justify-center gap-2 p-4 text-caption text-text-secondary"
+                        data-testid="scenario-preview-loading"
+                    >
+                        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
+                        読み込み中
+                    </p>
+                {/if}
+            {/if}
+
+            {#if subtitlesOn && currentEntry !== undefined && !previewState.finished}
+                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3">
+                    {#if currentEntry.subtitlePrimary !== null && currentEntry.subtitlePrimary !== ""}
+                        <span
+                            class="self-start rounded-sm bg-surface/80 px-2 py-1 text-caption text-text-secondary"
+                            aria-live="off"
+                            data-testid="scenario-preview-subtitle-primary"
+                        >
+                            {currentEntry.subtitlePrimary}
+                        </span>
+                    {:else}
+                        <span></span>
+                    {/if}
+                    {#if currentEntry.subtitleSecondary !== ""}
+                        <span
+                            class="self-stretch rounded-sm bg-surface/80 px-2 py-1 text-body text-text"
+                            aria-live="off"
+                            data-testid="scenario-preview-subtitle-secondary"
+                        >
+                            {currentEntry.subtitleSecondary}
+                        </span>
+                    {/if}
+                </div>
+            {/if}
+        </div>
+
+        {#if previewState.clip === "blocked" && !previewState.finished}
+            <Alert type="info" testId="scenario-preview-blocked">
+                このカットの自動再生がブラウザに止められました。再生を続けるか、このカットをスキップしてください。
+            </Alert>
+            <div class="flex flex-wrap items-center gap-2">
+                <Button variant="primary" size="sm" onclick={retry} testId="scenario-preview-retry">
+                    <Play class="size-4" aria-hidden="true" />
+                    再生を続ける
+                </Button>
+                <Button variant="neutral" size="sm" onclick={skip} testId="scenario-preview-skip">
+                    <SkipForward class="size-4" aria-hidden="true" />
+                    このカットをスキップ
+                </Button>
+            </div>
+        {/if}
+
+        {#if previewState.finished}
+            <p class="text-body text-text" role="status" data-testid="scenario-preview-finished">
+                すべてのカットを再生しました。
+            </p>
+        {/if}
+    </div>
+
+    {#snippet footer()}
+        {#if previewState.finished}
+            <Button variant="neutral" size="sm" onclick={replay} testId="scenario-preview-replay">
+                <Play class="size-4" aria-hidden="true" />
+                もう一度再生
+            </Button>
+        {/if}
+        <Button variant="neutral" onclick={() => (open = false)} testId="scenario-preview-close">
+            閉じる
+        </Button>
+    {/snippet}
+</Modal>
diff --git a/resources/js/lib/capture/scenario-preview.ts b/resources/js/lib/capture/scenario-preview.ts
new file mode 100644
index 0000000..8b38b76
--- /dev/null
+++ b/resources/js/lib/capture/scenario-preview.ts
@@ -0,0 +1,280 @@
+import { takeUrl } from "@/lib/capture/take-endpoints";
+import type { CaptureCut } from "@/types/capture";
+
+/**
+ * 撮影 PWA の通し再生 (全体連結プレビュー) の再生リストと状態機械。
+ *
+ * 方式の決定 (端末側連結再生 / サーバ生成プレビューを撮影者に開かない) と、
+ * ここで固定する契約の根拠は devnotes/20260816-1754-capture-full-scenario-preview/。
+ *
+ * **この面は素材の選択判定を持たない**。どのテイクを再生するかは
+ * サーバの `AdoptedReadyTakeCoverage` が決め、`cut.adopted_ready_take_id` として渡ってくる
+ * (adopted_take_id と take.status からここで組み立て直さない = T148 の二重化を作らない)。
+ *
+ * 判断はここ (純関数)、配線とメディア要素の操作は component
+ * (landscape-capture.ts / panel-navigation.ts と同じ役割分担)。
+ */
+
+/** 再生リストの 1 件 (クリップ = 再生する / 欠落 = プレースホルダを出す) */
+export type PreviewEntry =
+    | {
+          kind: "clip";
+          cutId: number;
+          takeId: number;
+          label: string;
+          subtitlePrimary: string | null;
+          subtitleSecondary: string;
+          /** capture.takes.playback の URL (takeUrl が唯一の導出元) */
+          src: string;
+      }
+    | {
+          kind: "missing";
+          cutId: number;
+          label: string;
+          subtitlePrimary: string | null;
+          subtitleSecondary: string;
+      };
+
+/** 再生状態 (可視性とは**直交**する) */
+export type ClipState = "loading" | "playing" | "paused" | "blocked" | "failed" | "placeholder";
+
+export interface PreviewState {
+    /** 再生リスト内の位置 (0 起点)。entries.length に達したら finished */
+    index: number;
+    /** 非同期結果の受付世代。index の前進・スキップ・終了のたびに +1 する */
+    generation: number;
+    clip: ClipState;
+    /** ページが表示されているか (可視性の軸) */
+    visible: boolean;
+    /** 直近に「進捗があった」時刻 (ms)。停滞判定の起点 */
+    progressAt: number;
+    /** 全カットを見終わったか */
+    finished: boolean;
+}
+
+export interface PreviewEvent {
+    type:
+        | "progress" // timeupdate / progress / canplay 等の前進イベント
+        | "playing"
+        | "paused" // 利用者の一時停止
+        | "resumed" // 利用者の再生
+        | "ended"
+        | "error" // media error / 404
+        | "blocked" // 自動再生制限と判定できる play() 拒否
+        | "retry" // 「再生を続ける」
+        | "skip" // 「このカットをスキップ」
+        | "hidden"
+        | "shown"
+        | "tick"; // 時間経過の通知 (停滞監視・プレースホルダ尺)
+    /** 発生元の世代。省略時は現在世代とみなす (利用者操作など同期的なもの) */
+    generation?: number;
+    /** イベント時刻 (ms) */
+    at: number;
+}
+
+export interface PreviewOptions {
+    entries: PreviewEntry[];
+    /** プレースホルダの表示秒数 (サーバの preview_placeholder_seconds と同じ値) */
+    placeholderSeconds: number;
+    /** 停滞と判定するまでの無進捗時間 (ms) */
+    stallTimeoutMs?: number;
+}
+
+/**
+ * 停滞判定の既定閾値。
+ *
+ * **この値が「正しい」ことは主張しない**。固定するのは「監視条件を満たす限り有限時間で
+ * 必ず次へ進む」ことだけで、閾値そのものは実地の観測が出るまで動かさない
+ * (仕組みが機能していない段階で値を弄らない)。現場のモバイル回線で先頭バッファに
+ * 時間がかかることを想定して保守的に置く。
+ */
+export const PREVIEW_STALL_TIMEOUT_MS = 20_000;
+
+/**
+ * 再生リストを組み立てる。並び順は props の cuts の順 (= サーバの表示順: 手順 → 配下の急所) をそのまま使う。
+ * ラベルは buildCutLabels の結果を受け取る (規則をここで再実装しない)。
+ */
+export function buildPreviewEntries(
+    cuts: CaptureCut[],
+    labels: Record<number, string>,
+    target: { projectId: number; manualId: number },
+): PreviewEntry[] {
+    return cuts.map((cut): PreviewEntry => {
+        const label = labels[cut.id] ?? "カット";
+        const takeId = cut.adopted_ready_take_id;
+        if (takeId === null) {
+            return {
+                kind: "missing",
+                cutId: cut.id,
+                label,
+                subtitlePrimary: cut.subtitle_primary,
+                subtitleSecondary: cut.subtitle_secondary,
+            };
+        }
+
+        return {
+            kind: "clip",
+            cutId: cut.id,
+            takeId,
+            label,
+            subtitlePrimary: cut.subtitle_primary,
+            subtitleSecondary: cut.subtitle_secondary,
+            src: takeUrl(
+                { projectId: target.projectId, manualId: target.manualId, cutId: cut.id },
+                takeId,
+                "/playback",
+            ),
+        };
+    });
+}
+
+/** 使用できる採用テイクが無いカットの件数 (再生前の告知に使う。述語は持たない = null を数えるだけ) */
+export function missingCount(entries: PreviewEntry[]): number {
+    return entries.filter((entry) => entry.kind === "missing").length;
+}
+
+/**
+ * 初期状態 (先頭 entry の種別で clip / placeholder が決まる)。
+ *
+ * **entries が空のときの `clip` は意味を持たない** — `finished: true` の状態では
+ * UI も reducer も `clip` を読まない (reducer は先頭で `finished` を見て素通しする)。
+ * 便宜上 `"placeholder"` を入れるが、**この値に依存する分岐を書かない**
+ * (この約束は Vitest の「空リストでは finished かつどのイベントでも状態が変わらない」で固定する)。
+ */
+export function initialPreviewState(options: PreviewOptions, at: number): PreviewState {
+    return {
+        index: 0,
+        generation: 0,
+        clip: stateForEntry(options.entries[0]),
+        visible: true,
+        progressAt: at,
+        finished: options.entries.length === 0,
+    };
+}
+
+/**
+ * 停滞監視を動かす条件。
+ * **可視性 × 再生要求 × 状態**の 3 つが揃ったときだけ監視する
+ * (一時停止・非表示・blocked・failed の間は監視しない = 誤って次へ進めない)。
+ */
+export function shouldWatchStall(state: PreviewState): boolean {
+    return state.visible && !state.finished && (state.clip === "loading" || state.clip === "playing");
+}
+
+/**
+ * 状態遷移。**現在世代と一致しない非同期結果は 1 ビットも状態を変えない**
+ * (要素の入れ替えで生じる古い reject / error を誤って現在のクリップの失敗にしない)。
+ */
+export function reducePreview(
+    state: PreviewState,
+    event: PreviewEvent,
+    options: PreviewOptions,
+): PreviewState {
+    if (state.finished) return state;
+    if (event.generation !== undefined && event.generation !== state.generation) return state;
+    // **非表示中はメディア由来のイベントを受け付けない**。実メディアを pause() しても、
+    // 既にキューへ入った ended / error は到着しうるため、実要素の操作だけに依存しない
+    // (非表示の間に勝手に次のカットへ進むのを構造で止める)。
+    // 利用者操作 (skip / retry) と可視性 (hidden / shown) と時間 (tick) は常に処理する。
+    if (!state.visible && isMediaOriginEvent(event.type)) return state;
+
+    switch (event.type) {
+        case "hidden":
+            return { ...state, visible: false };
+        case "shown":
+            // 再生状態は変えない (playing なら component が再開を試み、paused/blocked は維持)。
+            // 進捗の起点だけ引き直す (非表示だった時間を停滞に数えない)。
+            return { ...state, visible: true, progressAt: event.at };
+        case "progress":
+            return { ...state, progressAt: event.at };
+        case "playing":
+            return { ...state, clip: "playing", progressAt: event.at };
+        case "paused":
+            // **利用者操作由来の pause だけがここへ来る** (component が programmatic pause を送らない)。
+            // 読み込み中に利用者が止めることもあるため loading からも受け付ける
+            // (受け付けないと「止めたのに停滞監視が動き続けて failed になる」)。
+            return state.clip === "playing" || state.clip === "loading"
+                ? { ...state, clip: "paused" }
+                : state;
+        case "resumed":
+            return state.clip === "paused" ? { ...state, clip: "loading", progressAt: event.at } : state;
+        case "blocked":
+            return { ...state, clip: "blocked" };
+        case "retry":
+            // 「再生を続ける」= もう一度読み込みからやり直す (再拒否ならまた blocked になる)
+            return { ...state, clip: "loading", progressAt: event.at };
+        case "error":
+            return { ...state, clip: "failed", progressAt: event.at };
+        case "ended":
+        case "skip":
+            return advance(state, options, event.at);
+        case "tick":
+            return onTick(state, options, event.at);
+    }
+}
+
+/**
+ * 時間経過: プレースホルダの尺満了と停滞判定の 2 つだけを見る。
+ *
+ * `failed` の表示待ちにも `placeholderSeconds` を流用する (**欠落と同じ長さで通過させる**)。
+ * 別の設定値を新設しないのは、どちらも「見せてから次へ進むまでの待ち」であり、
+ * 2 つ持つと必ず食い違うためである (値の意味は「プレースホルダ表示秒数」のまま)。
+ */
+function onTick(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
+    if (!state.visible) return state; // 非表示の間は尺も停滞も進めない
+    if (state.clip === "placeholder" || state.clip === "failed") {
+        return at - state.progressAt >= options.placeholderSeconds * 1000
+            ? advance(state, options, at)
+            : state;
+    }
+    if (!shouldWatchStall(state)) return state;
+    const timeout = options.stallTimeoutMs ?? PREVIEW_STALL_TIMEOUT_MS;
+
+    // 進捗が途切れたまま閾値を超えた → そのカットだけ失敗にする (通し再生は止めない)
+    return at - state.progressAt >= timeout ? { ...state, clip: "failed", progressAt: at } : state;
+}
+
+/** 次の entry へ。**世代を必ず +1 する** (破棄したクリップの遅延イベントを無効化する) */
+function advance(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
+    const next = state.index + 1;
+    if (next >= options.entries.length) {
+        return {
+            ...state,
+            index: next,
+            generation: state.generation + 1,
+            finished: true,
+            progressAt: at,
+        };
+    }
+
+    return {
+        ...state,
+        index: next,
+        generation: state.generation + 1,
+        clip: stateForEntry(options.entries[next]),
+        progressAt: at,
+    };
+}
+
+function stateForEntry(entry: PreviewEntry | undefined): ClipState {
+    return entry?.kind === "clip" ? "loading" : "placeholder";
+}
+
+/**
+ * メディア要素が起点のイベント (非表示中は受け付けない側)。
+ * `Set<PreviewEvent["type"]>` が担保するのは**要素型の正当性**だけで、
+ * **必要なイベントの登録漏れは検出しない** (漏れは Vitest が拾う)。
+ */
+const MEDIA_ORIGIN_EVENTS = new Set<PreviewEvent["type"]>([
+    "progress",
+    "playing",
+    "paused",
+    "resumed",
+    "ended",
+    "error",
+    "blocked",
+]);
+
+function isMediaOriginEvent(type: PreviewEvent["type"]): boolean {
+    return MEDIA_ORIGIN_EVENTS.has(type);
+}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 9d71644..2837948 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -1,7 +1,7 @@
 <script lang="ts">
     import { onMount, tick, untrack } from "svelte";
     import { page, router } from "@inertiajs/svelte";
-    import { ArrowLeft, BookOpen, Maximize, Minimize, Video } from "@lucide/svelte";
+    import { ArrowLeft, BookOpen, ListVideo, Maximize, Minimize, Video } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
@@ -11,6 +11,7 @@
     import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
     import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
     import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
+    import ScenarioPreviewDialog from "@/components/features/capture/ScenarioPreviewDialog.svelte";
     import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
     import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
@@ -46,9 +47,11 @@
     interface Props {
         project: { id: number; name: string };
         manual: CaptureManualDetail;
+        /** プレースホルダ表示秒数 (config manual.preview_placeholder_seconds)。単位は秒 */
+        previewPlaceholderSeconds: number;
     }
 
-    let { project, manual }: Props = $props();
+    let { project, manual, previewPlaceholderSeconds }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -318,6 +321,42 @@
         navigateBackToList(cutListHeadingEl, prefersReducedMotion());
     }
 
+    /* ---- 通し再生 (全体連結プレビュー / T191) ---- */
+    let scenarioPreviewOpen = $state(false);
+    let scenarioPreviewError = $state<string | null>(null);
+
+    /** カメラ資源の解放・復帰は page 側に 1 つずつ持つ (TakeStrip と同じ関数を渡す = 2 か所に書かない) */
+    function releaseCameraForPreview(): void {
+        recorderRef?.releaseForPreview();
+    }
+
+    function resumeCameraAfterPreview(): void {
+        void recorderRef?.resumeAfterPreview();
+    }
+
+    /**
+     * 撮影中はカメラ資源と競合するため開かない。**ボタンは disabled にせず、押下時に伝える**
+     * (禁止事項 8)。判定条件・呼び出し順・文言は**既存の個別 preview (TakeStrip.openPreview) と
+     * 同じ言い回し**に揃える (同じ制約を別の言葉で言わない)。
+     *
+     * `captureActive` は recording|stopping を含む。`releaseForPreview()` は同期の void 関数で、
+     * 録画中・取得中は自分で早期 return するため await も失敗ハンドリングも要らない。
+     */
+    function openScenarioPreview(): void {
+        scenarioPreviewError = null;
+        if (captureActive) {
+            scenarioPreviewError = "撮影中は通し再生を開始できません。撮影を停止してからお試しください。";
+
+            return;
+        }
+        releaseCameraForPreview();
+        scenarioPreviewOpen = true;
+    }
+
+    function closeScenarioPreview(): void {
+        resumeCameraAfterPreview();
+    }
+
     $effect(() => {
         if (leftPaneEl === null || rightPaneEl === null) return;
         // observer の初回 callback はタイミング差があるため当てにせず、登録前に必ず 1 回測る
@@ -478,20 +517,46 @@
                 >
                     シナリオ (タップして撮影)
                 </h2>
-                <!-- 横持ちなのに全画面でないとき (= 明示終了した後) の再入路。
-                     文脈非該当時は非表示にする (disabled ではない)。 -->
-                {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
-                    <Button
-                        variant="neutral"
-                        size="sm"
-                        onclick={enterFullscreen}
-                        testId="enter-fullscreen-capture"
-                    >
-                        <Maximize class="size-4" aria-hidden="true" />
-                        全画面で撮影
-                    </Button>
-                {/if}
+                <!-- 狭幅で 2 つのボタンが詰まらないよう折り返しを許す (justify-end で右寄せを保つ) -->
+                <div class="flex flex-wrap items-center justify-end gap-2">
+                    <!-- 通し再生 (doc/05 §5.2 [プレビュー])。**カットが 1 枚も無いときは出さない**
+                         (文脈非該当の非表示であって、条件未充足の disabled ではない)。
+                         撮影中の押下は開かずにエラーを出す (資源競合。TakeStrip の個別 preview と同じ規則)。 -->
+                    {#if manual.cuts.length > 0}
+                        <Button
+                            variant="neutral"
+                            size="sm"
+                            onclick={openScenarioPreview}
+                            testId="scenario-preview-button"
+                        >
+                            <ListVideo class="size-4" aria-hidden="true" />
+                            通し再生
+                        </Button>
+                    {/if}
+                    <!-- 横持ちなのに全画面でないとき (= 明示終了した後) の再入路。
+                         文脈非該当時は非表示にする (disabled ではない)。 -->
+                    {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
+                        <Button
+                            variant="neutral"
+                            size="sm"
+                            onclick={enterFullscreen}
+                            testId="enter-fullscreen-capture"
+                        >
+                            <Maximize class="size-4" aria-hidden="true" />
+                            全画面で撮影
+                        </Button>
+                    {/if}
+                </div>
             </div>
+            {#if scenarioPreviewError !== null}
+                <p
+                    class="px-3 py-2 text-caption text-danger"
+                    role="alert"
+                    data-testid="scenario-preview-error"
+                >
+                    {scenarioPreviewError}
+                </p>
+            {/if}
             <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
         </section>
 
@@ -660,12 +725,23 @@
                         cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
                         onChanged={reloadManual}
                         {captureActive}
-                        onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
-                        onCameraResume={() => void recorderRef?.resumeAfterPreview()}
+                        onRequestCameraRelease={releaseCameraForPreview}
+                        onCameraResume={resumeCameraAfterPreview}
                     />
                 {/if}
             {/if}
         </section>
         </div>
+
+        <!-- Modal は Portal で描画されるため設置位置に依存しない (全画面 section の外に置く) -->
+        <ScenarioPreviewDialog
+            bind:open={scenarioPreviewOpen}
+            projectId={project.id}
+            manualId={manual.id}
+            cuts={manual.cuts}
+            labels={cutLabels}
+            placeholderSeconds={previewPlaceholderSeconds}
+            onClose={closeScenarioPreview}
+        />
     </PageContainer>
 </AppLayout>
diff --git a/resources/js/types/capture.ts b/resources/js/types/capture.ts
index 67f7db5..f5094ae 100644
--- a/resources/js/types/capture.ts
+++ b/resources/js/types/capture.ts
@@ -34,6 +34,12 @@ export interface CaptureCut {
     subtitle_primary: string | null;
     subtitle_secondary: string;
     adopted_take_id: number | null;
+    /**
+     * 通し再生が再生するテイクの id (サーバが `AdoptedReadyTakeCoverage` で決めた値)。
+     * null = そのカットはプレースホルダになる。**クライアントでこの判定を組み立て直さない**
+     * (`adopted_take_id` と take.status から導出するコードを書かない = T148)。
+     */
+    adopted_ready_take_id: number | null;
     takes: CaptureTake[];
 }
 
diff --git a/tests/Feature/Capture/CaptureManualBrowsingTest.php b/tests/Feature/Capture/CaptureManualBrowsingTest.php
index f4b4f3b..9687c2a 100644
--- a/tests/Feature/Capture/CaptureManualBrowsingTest.php
+++ b/tests/Feature/Capture/CaptureManualBrowsingTest.php
@@ -190,7 +190,8 @@ function browsingContext(): array
     $cutShape = $response->inertiaPage()['props']['manual']['cuts'][0];
     expect(array_keys($cutShape))->toBe([
         'id', 'type', 'parent_cut_id', 'scene', 'shot_type', 'shooting_point',
-        'narration', 'subtitle_primary', 'subtitle_secondary', 'adopted_take_id', 'takes',
+        'narration', 'subtitle_primary', 'subtitle_secondary', 'adopted_take_id',
+        'adopted_ready_take_id', 'takes',
     ]);
 });
 
diff --git a/tests/Feature/Capture/ScenarioPreviewPropsTest.php b/tests/Feature/Capture/ScenarioPreviewPropsTest.php
new file mode 100644
index 0000000..709d4e3
--- /dev/null
+++ b/tests/Feature/Capture/ScenarioPreviewPropsTest.php
@@ -0,0 +1,217 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\TakeStatus;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Capture\TakeObjectStorage;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * 撮影 PWA の通し再生 (全体連結プレビュー / T191) が依存する props の契約。
+ *
+ * 固定するのは 3 点:
+ *  1. cuts.*.adopted_ready_take_id は「使用できる採用テイク」の id そのものである
+ *     (述語の実体は AdoptedReadyTakeCoverage::readyTakeId() 1 箇所。TakeStatus の 4 値すべてを個別に固定する)
+ *  2. 署名 playback URL / DL ACK トークンの発行条件が**同じ述語**に揃っている
+ *     (非 ready の採用テイクへは 1 度も署名 URL を作らない = takes.playback の 404 と同じゲート)
+ *  3. previewPlaceholderSeconds がページ props に載り、サーバ生成プレビューと同じ設定値を指す
+ */
+
+/**
+ * 撮影者 (org owner) + ready manual + step カット 1 枚。
+ *
+ * @return array{Organization, User, Project, VideoManual, Cut}
+ */
+function scenarioPreviewContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+
+    return [$organization, $owner, $project, $manual, $cut];
+}
+
+/** cut にテイクを作って採用状態にする (status を変えて述語の 4 値差を作る) */
+function scenarioPreviewAdopt(Cut $cut, TakeStatus $status = TakeStatus::Ready): Take
+{
+    $take = Take::factory()->forCut($cut)->create(['status' => $status->value]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    return $take;
+}
+
+/** 署名 URL を返す storage mock を container へ差し込む (発行回数も固定できる) */
+function scenarioPreviewFakeStorage(?string $url = 'https://s3.fake.test/signed-get-url'): void
+{
+    $storage = Mockery::mock(TakeObjectStorage::class);
+    if ($url === null) {
+        $storage->shouldNotReceive('temporaryPlaybackUrl');
+    } else {
+        $storage->shouldReceive('temporaryPlaybackUrl')->andReturn($url);
+    }
+    app()->instance(TakeObjectStorage::class, $storage);
+}
+
+/**
+ * 撮影詳細 props を取り出す。
+ *
+ * @return array<string, mixed>
+ */
+function scenarioPreviewProps(Project $project, VideoManual $manual, User $actor): array
+{
+    /** @var array<string, mixed> $props */
+    $props = test()->actingAs($actor)
+        ->get("/app/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->inertiaPage()['props'];
+
+    return $props;
+}
+
+test('採用済み + ready の cut は adopted_ready_take_id にそのテイク id を持つ', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    $take = scenarioPreviewAdopt($cut);
+    scenarioPreviewFakeStorage();
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBe($take->id);
+});
+
+test('採用済みでも ready でない cut の adopted_ready_take_id は null (uploading/processing/failed)', function (TakeStatus $status): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    scenarioPreviewAdopt($cut, $status);
+    scenarioPreviewFakeStorage(null); // 署名 URL を 1 度も作らないことを併せて固定する
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
+})->with([
+    'uploading' => TakeStatus::Uploading,
+    'processing' => TakeStatus::Processing,
+    'failed' => TakeStatus::Failed,
+]);
+
+test('未採用 (テイクはあるが adopted_take_id が null) の adopted_ready_take_id は null', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    Take::factory()->forCut($cut)->create();
+    scenarioPreviewFakeStorage(null);
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    expect($props['manual']['cuts'][0]['adopted_take_id'])->toBeNull();
+    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
+});
+
+test('テイクが 1 件も無い cut の adopted_ready_take_id は null', function (): void {
+    [, $owner, $project, $manual] = scenarioPreviewContext();
+    scenarioPreviewFakeStorage(null);
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    expect($props['manual']['cuts'][0]['takes'])->toBe([]);
+    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
+});
+
+test('adopted_take_id と adopted_ready_take_id は別の意味である (採用済み非 ready で前者だけ非 null)', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    $take = scenarioPreviewAdopt($cut, TakeStatus::Processing);
+    scenarioPreviewFakeStorage(null);
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    expect($props['manual']['cuts'][0]['adopted_take_id'])->toBe($take->id);
+    expect($props['manual']['cuts'][0]['adopted_ready_take_id'])->toBeNull();
+});
+
+test('採用済み非 ready のテイクには playback_url も download_ack_token も出さない (S2b)', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    $take = scenarioPreviewAdopt($cut, TakeStatus::Processing);
+    // 署名 URL 発行そのものが起きないことを直接固定する (呼ばれたら Mockery が落とす)
+    scenarioPreviewFakeStorage(null);
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    $takeProps = $props['manual']['cuts'][0]['takes'][0];
+    expect($takeProps['id'])->toBe($take->id);
+    expect($takeProps['playback_url'])->toBeNull();
+    expect($takeProps['download_ack_token'])->toBeNull();
+});
+
+test('採用済み + ready のテイクには従来どおり playback_url と download_ack_token が出る', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    scenarioPreviewAdopt($cut);
+    scenarioPreviewFakeStorage();
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    $takeProps = $props['manual']['cuts'][0]['takes'][0];
+    expect($takeProps['playback_url'])->toBe('https://s3.fake.test/signed-get-url');
+    expect($takeProps['download_ack_token'])->toBeString();
+});
+
+test('previewPlaceholderSeconds は config の値と一致する 1 以上の int である', function (): void {
+    [, $owner, $project, $manual] = scenarioPreviewContext();
+    scenarioPreviewFakeStorage(null);
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    expect($props['previewPlaceholderSeconds'])->toBeInt();
+    expect($props['previewPlaceholderSeconds'])->toBe(config()->integer('manual.preview_placeholder_seconds'));
+    expect($props['previewPlaceholderSeconds'])->toBeGreaterThanOrEqual(1);
+});
+
+test('adopt 応答の adopted_ready_take_id は採用したテイク id になる (relation 鮮度)', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    $take = Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($owner)->postJson(
+        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/adopt",
+    )->assertOk()
+        ->assertJsonPath('adopted_take_id', $take->id)
+        ->assertJsonPath('adopted_ready_take_id', $take->id);
+});
+
+test('採用を付け替えると adopt 応答の adopted_ready_take_id は新しい方になる (relation 鮮度)', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    $first = scenarioPreviewAdopt($cut);
+    $second = Take::factory()->forCut($cut)->create(['sort_order' => 1]);
+
+    $this->actingAs($owner)->postJson(
+        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$second->id}/adopt",
+    )->assertOk()
+        ->assertJsonPath('adopted_ready_take_id', $second->id);
+
+    expect($second->id)->not->toBe($first->id);
+});
+
+test('cuts を増やしても採用テイクの取得クエリは 1 本のまま (N+1 を作らない)', function (): void {
+    [, $owner, $project, $manual, $cut] = scenarioPreviewContext();
+    scenarioPreviewAdopt($cut);
+    foreach (range(1, 4) as $index) {
+        $extra = Cut::factory()->forManual($manual)->withSortOrder($index)->create();
+        scenarioPreviewAdopt($extra);
+    }
+    scenarioPreviewFakeStorage();
+
+    $queries = [];
+    DB::listen(function ($query) use (&$queries): void {
+        $queries[] = $query->sql;
+    });
+
+    $props = scenarioPreviewProps($project, $manual, $owner);
+
+    expect($props['manual']['cuts'])->toHaveCount(5);
+    $takeQueries = array_values(array_filter(
+        $queries,
+        static fn (string $sql): bool => str_contains($sql, 'from "takes"') && str_contains($sql, '"takes"."id" in'),
+    ));
+    expect($takeQueries)->toHaveCount(1);
+});
diff --git a/tests/js/components/features/capture/CutNavigator.test.ts b/tests/js/components/features/capture/CutNavigator.test.ts
index 28bc618..6f40a75 100644
--- a/tests/js/components/features/capture/CutNavigator.test.ts
+++ b/tests/js/components/features/capture/CutNavigator.test.ts
@@ -15,6 +15,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         subtitle_primary: null,
         subtitle_secondary: "",
         adopted_take_id: null,
+        adopted_ready_take_id: null,
         takes: [],
         ...overrides,
     };
diff --git a/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
new file mode 100644
index 0000000..f00bb17
--- /dev/null
+++ b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
@@ -0,0 +1,422 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import ScenarioPreviewDialog from "@/components/features/capture/ScenarioPreviewDialog.svelte";
+import type { CaptureCut } from "@/types/capture";
+
+/*
+ * ScenarioPreviewDialog (通し再生 / T191)。
+ *
+ * jsdom は実メディア再生を行わないため、ここで固定できるのは **DOM 契約とイベント配線**まで
+ * である (実機での連続再生の滑らかさは実機確認の領域)。逆に言えば、次の構造的な不変条件は
+ * すべてここで固定する:
+ *   - 先読み済み要素をそのまま本再生へ引き継ぐ (同じ動画を 2 回取得しない)
+ *   - missing を挟む並びでも次の clip に必ず src が入る (再生不能を作らない)
+ *   - 世代 / 割り当て世代により、旧要素・teardown 後の遅延イベントが状態を変えない
+ *   - programmatic pause と利用者 pause を slot 単位で区別する
+ *   - 1 本の失敗で通し再生が止まらない (停滞監視が有限時間で回収する)
+ */
+
+const TARGET = { projectId: 1, manualId: 5 };
+
+function cut(id: number, readyTakeId: number | null): CaptureCut {
+    return {
+        id,
+        type: "step",
+        parent_cut_id: null,
+        scene: `scene-${id}`,
+        shot_type: "hiki",
+        shooting_point: null,
+        narration: "",
+        subtitle_primary: null,
+        subtitle_secondary: `字幕 ${id}`,
+        adopted_take_id: readyTakeId,
+        adopted_ready_take_id: readyTakeId,
+        takes: [],
+    };
+}
+
+const LABELS: Record<number, string> = { 101: "手順 1", 102: "手順 2", 103: "手順 3" };
+
+function playbackUrl(cutId: number, takeId: number): string {
+    return `/app/projects/1/manuals/5/cuts/${cutId}/takes/${takeId}/playback`;
+}
+
+function renderDialog(cuts: CaptureCut[], onClose = vi.fn()): { onClose: ReturnType<typeof vi.fn> } {
+    render(ScenarioPreviewDialog, {
+        open: true,
+        projectId: TARGET.projectId,
+        manualId: TARGET.manualId,
+        cuts,
+        labels: LABELS,
+        placeholderSeconds: 3,
+        onClose,
+    });
+
+    return { onClose };
+}
+
+function video(slot: 0 | 1): HTMLVideoElement {
+    return screen.getByTestId(`scenario-preview-video-${slot}`) as HTMLVideoElement;
+}
+
+function body(): HTMLElement {
+    return screen.getByTestId("scenario-preview-body");
+}
+
+/** 要素が「再生中」であるかのように見せる (jsdom の paused は常に true のため) */
+function markPlaying(element: HTMLVideoElement): void {
+    Object.defineProperty(element, "paused", { value: false, configurable: true });
+}
+
+let playMock: ReturnType<typeof vi.fn>;
+
+beforeEach(() => {
+    playMock = vi.fn().mockResolvedValue(undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(
+        playMock as unknown as () => Promise<void>,
+    );
+    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.restoreAllMocks();
+    vi.useRealTimers();
+});
+
+describe("ScenarioPreviewDialog: 起動と告知", () => {
+    it("開くと先頭 entry の src が active 要素に入る", () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        expect(video(0)).toHaveAttribute("src", playbackUrl(101, 900));
+        expect(body()).toHaveAttribute("data-active-slot", "0");
+    });
+
+    it("使用できる採用テイクが無いカットがあると事前告知を出す (ボタンは止めない)", () => {
+        renderDialog([cut(101, 900), cut(102, null)]);
+
+        expect(screen.getByTestId("scenario-preview-coverage-note")).toHaveTextContent(
+            "1 / 2 件のカットに、撮影・処理が完了した採用テイクがありません",
+        );
+        expect(screen.getByTestId("scenario-preview-close")).not.toBeDisabled();
+    });
+
+    it("欠落が無ければ事前告知は出ない", () => {
+        renderDialog([cut(101, 900)]);
+
+        expect(screen.queryByTestId("scenario-preview-coverage-note")).not.toBeInTheDocument();
+    });
+
+    it("missing entry ではプレースホルダ文言を出し video に src を入れない", () => {
+        renderDialog([cut(101, null), cut(102, 901)]);
+
+        expect(screen.getByTestId("scenario-preview-placeholder")).toHaveTextContent(
+            "手順 1: 撮影・処理が完了した採用テイクがありません",
+        );
+        expect(video(0)).not.toHaveAttribute("src");
+        expect(video(1)).not.toHaveAttribute("src");
+    });
+
+    it("字幕は初期 ON で、トグルで隠せる", async () => {
+        renderDialog([cut(101, 900)]);
+
+        expect(screen.getByTestId("scenario-preview-subtitle-secondary")).toHaveTextContent("字幕 101");
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-subtitle-toggle"));
+
+        expect(screen.queryByTestId("scenario-preview-subtitle-secondary")).not.toBeInTheDocument();
+    });
+});
+
+describe("ScenarioPreviewDialog: 先読みと役割の入れ替え", () => {
+    it("再生に入ると次のクリップが inactive 側へ先読みされる", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        await fireEvent(video(0), new Event("playing"));
+
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+    });
+
+    it("進むと役割が入れ替わり、先読み済み要素は作り直されない (二重取得を作らない)", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing"));
+
+        const assignmentBefore = video(1).getAttribute("data-assignment");
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-active-slot", "1");
+        expect(body()).toHaveAttribute("data-index", "1");
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+        expect(video(1).getAttribute("data-assignment")).toBe(assignmentBefore);
+    });
+
+    it("次が missing なら先読みせず inactive 側を空のままにする", async () => {
+        renderDialog([cut(101, 900), cut(102, null)]);
+
+        await fireEvent(video(0), new Event("playing"));
+
+        expect(video(1)).not.toHaveAttribute("src");
+    });
+});
+
+describe("ScenarioPreviewDialog: 進んだ先の同期 (再生不能を作らない)", () => {
+    it("missing → clip で次の clip に src が入る", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, null), cut(102, 901)]);
+
+        await vi.advanceTimersByTimeAsync(4_000);
+
+        expect(body()).toHaveAttribute("data-index", "1");
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+    });
+
+    it("clip → missing → clip で最後の clip に src が入る", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900), cut(102, null), cut(103, 902)]);
+
+        await fireEvent(video(0), new Event("ended"));
+        expect(body()).toHaveAttribute("data-index", "1");
+
+        await vi.advanceTimersByTimeAsync(4_000);
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        const activeSlot = body().getAttribute("data-active-slot");
+        expect(video(activeSlot === "0" ? 0 : 1)).toHaveAttribute("src", playbackUrl(103, 902));
+    });
+
+    it("missing → missing → clip で最後の clip に src が入る", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, null), cut(102, null), cut(103, 902)]);
+
+        await vi.advanceTimersByTimeAsync(4_000);
+        await vi.advanceTimersByTimeAsync(4_000);
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        const activeSlot = body().getAttribute("data-active-slot");
+        expect(video(activeSlot === "0" ? 0 : 1)).toHaveAttribute("src", playbackUrl(103, 902));
+    });
+});
+
+describe("ScenarioPreviewDialog: 自動再生制限 (blocked)", () => {
+    it("NotAllowedError の拒否で blocked 表示になり 3 つの出口が出る", async () => {
+        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("scenario-preview-blocked")).toBeInTheDocument();
+        });
+        expect(screen.getByTestId("scenario-preview-retry")).toBeInTheDocument();
+        expect(screen.getByTestId("scenario-preview-skip")).toBeInTheDocument();
+        expect(screen.getByTestId("scenario-preview-close")).toBeInTheDocument();
+        expect(body()).toHaveAttribute("data-clip", "blocked");
+    });
+
+    it("blocked からスキップで次のカットへ進める", async () => {
+        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("scenario-preview-skip")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-skip"));
+
+        expect(body()).toHaveAttribute("data-index", "1");
+    });
+
+    it("拒否後もダイアログを閉じられる (未処理 rejection を残さない)", async () => {
+        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
+        const { onClose } = renderDialog([cut(101, 900)]);
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("scenario-preview-blocked")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
+
+        expect(onClose).toHaveBeenCalledTimes(1);
+    });
+
+    it("NotAllowedError 以外の拒否は即 failed にせず、停滞監視が回収する", async () => {
+        vi.useFakeTimers();
+        playMock.mockRejectedValue(new Error("decode failure"));
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        await vi.advanceTimersByTimeAsync(1_000);
+        expect(body()).toHaveAttribute("data-clip", "loading");
+
+        await vi.advanceTimersByTimeAsync(20_000);
+        expect(body()).toHaveAttribute("data-clip", "failed");
+        expect(screen.getByTestId("scenario-preview-failed")).toHaveTextContent(
+            "手順 1: このカットは再生できませんでした",
+        );
+
+        await vi.advanceTimersByTimeAsync(4_000);
+        expect(body()).toHaveAttribute("data-index", "1");
+    });
+});
+
+describe("ScenarioPreviewDialog: pause の抑止", () => {
+    it("利用者操作の pause は paused になる", async () => {
+        renderDialog([cut(101, 900)]);
+
+        await fireEvent(video(0), new Event("pause"));
+
+        expect(body()).toHaveAttribute("data-clip", "paused");
+    });
+
+    it("自分から止めた pause は paused を作らない (非表示での programmatic pause)", async () => {
+        renderDialog([cut(101, 900)]);
+        await fireEvent(video(0), new Event("playing"));
+        markPlaying(video(0));
+
+        // 非表示 → component が自分から pause() する (抑止が立つ)
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+        await fireEvent(video(0), new Event("pause"));
+
+        expect(body()).toHaveAttribute("data-clip", "playing");
+
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+    });
+
+    it("抑止は slot 別である (片方を止めても他方の利用者 pause は効く)", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing")); // slot1 へ先読み
+        markPlaying(video(0));
+
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+
+        // slot0 の抑止が立っている状態で slot1 (先読み側) から pause が来ても握り潰さない
+        await fireEvent(video(1), new Event("pause"));
+
+        // slot1 の世代は先読み世代 (現在世代 + 1) なので reducer が捨てる = 状態は変わらない
+        expect(body()).toHaveAttribute("data-clip", "playing");
+
+        // slot0 の抑止は残っているので、こちらの pause は 1 度だけ握り潰される
+        await fireEvent(video(0), new Event("pause"));
+        expect(body()).toHaveAttribute("data-clip", "playing");
+        // 抑止は消費済み。次の pause は利用者操作として通る
+        await fireEvent(video(0), new Event("pause"));
+        expect(body()).toHaveAttribute("data-clip", "paused");
+    });
+
+    it("既に paused の要素には抑止を立てない (後の利用者 pause を握り潰さない)", async () => {
+        renderDialog([cut(101, 900)]);
+        // jsdom の既定 paused=true のまま非表示にする (pause() は呼ばれない = 抑止も立たない)
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+
+        await fireEvent(video(0), new Event("pause"));
+
+        expect(body()).toHaveAttribute("data-clip", "paused");
+    });
+});
+
+describe("ScenarioPreviewDialog: 遅延イベントの遮断", () => {
+    it("旧 slot の遅延 error / ended が進んだ後のクリップを壊さない", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing"));
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-index", "1");
+
+        await fireEvent(video(0), new Event("error"));
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-index", "1");
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+
+    it("同一 slot を作り直した後、旧要素からのイベントは届かない", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900), cut(102, null), cut(103, 902)]);
+        const firstElement = video(0);
+
+        await fireEvent(video(0), new Event("ended")); // → missing (slot1 が active)
+        await vi.advanceTimersByTimeAsync(4_000); // → clip3 (slot0 を作り直して active)
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        expect(video(0)).not.toBe(firstElement); // 要素ごと作り直されている
+
+        await fireEvent(firstElement, new Event("ended"));
+        await fireEvent(firstElement, new Event("error"));
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+
+    it("非表示中は ended が起きても次へ進まない", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-index", "0");
+
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+    });
+});
+
+describe("ScenarioPreviewDialog: 終端と後始末", () => {
+    it("最終 entry の ended で終端表示になり、もう一度再生できる", async () => {
+        renderDialog([cut(101, 900)]);
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(screen.getByTestId("scenario-preview-finished")).toHaveTextContent(
+            "すべてのカットを再生しました。",
+        );
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-replay"));
+
+        expect(body()).toHaveAttribute("data-index", "0");
+        expect(video(0)).toHaveAttribute("src", playbackUrl(101, 900));
+    });
+
+    it("終端では両方の要素が teardown され、時間駆動も止まる", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900)]);
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(video(0)).not.toHaveAttribute("src");
+        expect(video(1)).not.toHaveAttribute("src");
+
+        // 終端後に時間が進んでも状態は動かない (interval を破棄している)
+        await vi.advanceTimersByTimeAsync(60_000);
+        expect(screen.getByTestId("scenario-preview-finished")).toBeInTheDocument();
+    });
+
+    it("閉じると両方の要素を teardown し onClose を 1 度だけ呼ぶ", async () => {
+        const { onClose } = renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing"));
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
+
+        expect(onClose).toHaveBeenCalledTimes(1);
+    });
+
+    it("teardown 後に届いた遅延イベントは状態を変えない", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        const active = video(0);
+
+        await fireEvent(active, new Event("ended")); // slot0 teardown → slot1 が active
+        const indexAfterAdvance = body().getAttribute("data-index");
+
+        await fireEvent(active, new Event("pause"));
+        await fireEvent(active, new Event("error"));
+        await fireEvent(active, new Event("ended"));
+
+        expect(body().getAttribute("data-index")).toBe(indexAfterAdvance);
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+});
diff --git a/tests/js/components/features/capture/TakePreviewDialog.test.ts b/tests/js/components/features/capture/TakePreviewDialog.test.ts
index a2e2fe8..cd66a4e 100644
--- a/tests/js/components/features/capture/TakePreviewDialog.test.ts
+++ b/tests/js/components/features/capture/TakePreviewDialog.test.ts
@@ -39,6 +39,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         subtitle_primary: "STEP 1",
         subtitle_secondary: "作業台を準備する",
         adopted_take_id: null,
+        adopted_ready_take_id: null,
         takes: [],
         ...overrides,
     };
diff --git a/tests/js/components/features/capture/TakeStrip.test.ts b/tests/js/components/features/capture/TakeStrip.test.ts
index fa20c86..9eb939e 100644
--- a/tests/js/components/features/capture/TakeStrip.test.ts
+++ b/tests/js/components/features/capture/TakeStrip.test.ts
@@ -41,6 +41,7 @@ function makeCut(takes: CaptureTake[], adopted: number | null = null): CaptureCu
         subtitle_primary: null,
         subtitle_secondary: "作業台を準備",
         adopted_take_id: adopted,
+        adopted_ready_take_id: adopted,
         takes,
     };
 }
diff --git a/tests/js/lib/capture/auto-download.test.ts b/tests/js/lib/capture/auto-download.test.ts
index 00a8548..2918de0 100644
--- a/tests/js/lib/capture/auto-download.test.ts
+++ b/tests/js/lib/capture/auto-download.test.ts
@@ -44,6 +44,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         subtitle_primary: null,
         subtitle_secondary: "",
         adopted_take_id: takes[0]?.id ?? null,
+        adopted_ready_take_id: takes[0]?.id ?? null,
         takes,
         ...overrides,
     };
diff --git a/tests/js/lib/capture/cut-labels.test.ts b/tests/js/lib/capture/cut-labels.test.ts
index ea9c87d..6c1fef9 100644
--- a/tests/js/lib/capture/cut-labels.test.ts
+++ b/tests/js/lib/capture/cut-labels.test.ts
@@ -30,6 +30,7 @@ function cut(id: number, type: "step" | "point"): CaptureCut {
         subtitle_primary: null,
         subtitle_secondary: "",
         adopted_take_id: null,
+        adopted_ready_take_id: null,
         takes: [],
     };
 }
diff --git a/tests/js/lib/capture/scenario-preview.test.ts b/tests/js/lib/capture/scenario-preview.test.ts
new file mode 100644
index 0000000..d901c1e
--- /dev/null
+++ b/tests/js/lib/capture/scenario-preview.test.ts
@@ -0,0 +1,385 @@
+/**
+ * Tests for resources/js/lib/capture/scenario-preview.ts (T191)
+ *
+ * 固定する契約:
+ * - 再生リストは「サーバが決めた adopted_ready_take_id」だけを見る (述語を持たない)
+ * - 表示中かつ再生要求中なら**有限時間で必ず次へ進む** (停滞監視による回収)
+ * - 一時停止 / 非表示 / blocked / failed の間は勝手に進まない
+ * - 世代が一致しない非同期結果は 1 ビットも状態を変えない
+ */
+import { describe, expect, it } from "vitest";
+
+import {
+    buildPreviewEntries,
+    initialPreviewState,
+    missingCount,
+    PREVIEW_STALL_TIMEOUT_MS,
+    reducePreview,
+    shouldWatchStall,
+    type PreviewEntry,
+    type PreviewEvent,
+    type PreviewOptions,
+    type PreviewState,
+} from "@/lib/capture/scenario-preview";
+import { takeUrl } from "@/lib/capture/take-endpoints";
+import type { CaptureCut } from "@/types/capture";
+
+const TARGET = { projectId: 1, manualId: 5 };
+
+function cut(id: number, readyTakeId: number | null, type: "step" | "point" = "step"): CaptureCut {
+    return {
+        id,
+        type,
+        parent_cut_id: null,
+        scene: `scene-${id}`,
+        shot_type: "hiki",
+        shooting_point: null,
+        narration: "",
+        subtitle_primary: null,
+        subtitle_secondary: `字幕 ${id}`,
+        adopted_take_id: readyTakeId,
+        adopted_ready_take_id: readyTakeId,
+        takes: [],
+    };
+}
+
+function clipEntry(cutId: number, takeId: number): PreviewEntry {
+    return {
+        kind: "clip",
+        cutId,
+        takeId,
+        label: `手順 ${cutId}`,
+        subtitlePrimary: null,
+        subtitleSecondary: "",
+        src: takeUrl({ ...TARGET, cutId }, takeId, "/playback"),
+    };
+}
+
+function missingEntry(cutId: number): PreviewEntry {
+    return {
+        kind: "missing",
+        cutId,
+        label: `手順 ${cutId}`,
+        subtitlePrimary: null,
+        subtitleSecondary: "",
+    };
+}
+
+function options(entries: PreviewEntry[], overrides: Partial<PreviewOptions> = {}): PreviewOptions {
+    return { entries, placeholderSeconds: 3, stallTimeoutMs: 1_000, ...overrides };
+}
+
+/** 連続適用のヘルパ (状態遷移の可読性のため) */
+function apply(state: PreviewState, opts: PreviewOptions, events: PreviewEvent[]): PreviewState {
+    return events.reduce((current, event) => reducePreview(current, event, opts), state);
+}
+
+describe("buildPreviewEntries", () => {
+    it("adopted_ready_take_id が非 null なら clip、null なら missing になる", () => {
+        const entries = buildPreviewEntries(
+            [cut(101, 900), cut(102, null)],
+            { 101: "手順 1", 102: "急所 1-1" },
+            TARGET,
+        );
+
+        expect(entries[0]).toMatchObject({ kind: "clip", cutId: 101, takeId: 900, label: "手順 1" });
+        expect(entries[1]).toMatchObject({ kind: "missing", cutId: 102, label: "急所 1-1" });
+    });
+
+    it("clip の src は takeUrl の /playback と一致する (URL 規則を再実装しない)", () => {
+        const entries = buildPreviewEntries([cut(101, 900)], { 101: "手順 1" }, TARGET);
+
+        expect(entries[0]).toHaveProperty(
+            "src",
+            takeUrl({ projectId: 1, manualId: 5, cutId: 101 }, 900, "/playback"),
+        );
+        expect(entries[0]).toHaveProperty("src", "/app/projects/1/manuals/5/cuts/101/takes/900/playback");
+    });
+
+    it("cuts の順序をそのまま保つ (手順 → 急所の並びを崩さない)", () => {
+        const entries = buildPreviewEntries(
+            [cut(1, 11), cut(2, null, "point"), cut(3, 33)],
+            { 1: "手順 1", 2: "急所 1-1", 3: "手順 2" },
+            TARGET,
+        );
+
+        expect(entries.map((entry) => entry.cutId)).toEqual([1, 2, 3]);
+    });
+
+    it("ラベルが無いカットは既定ラベルになる (buildCutLabels の結果をそのまま使う)", () => {
+        const entries = buildPreviewEntries([cut(1, 11)], {}, TARGET);
+
+        expect(entries[0]?.label).toBe("カット");
+    });
+
+    it("字幕は cut の値をそのまま運ぶ", () => {
+        const entries = buildPreviewEntries([cut(7, 70)], { 7: "手順 1" }, TARGET);
+
+        expect(entries[0]?.subtitleSecondary).toBe("字幕 7");
+        expect(entries[0]?.subtitlePrimary).toBeNull();
+    });
+});
+
+describe("missingCount", () => {
+    it("使用できる採用テイクが無いカットの件数を数える", () => {
+        expect(missingCount([clipEntry(1, 11), missingEntry(2), missingEntry(3)])).toBe(2);
+        expect(missingCount([clipEntry(1, 11)])).toBe(0);
+        expect(missingCount([])).toBe(0);
+    });
+});
+
+describe("initialPreviewState", () => {
+    it("先頭が clip なら loading、missing なら placeholder から始まる", () => {
+        expect(initialPreviewState(options([clipEntry(1, 11)]), 0).clip).toBe("loading");
+        expect(initialPreviewState(options([missingEntry(1)]), 0).clip).toBe("placeholder");
+    });
+
+    it("entries が空なら finished で始まる", () => {
+        expect(initialPreviewState(options([]), 0).finished).toBe(true);
+    });
+});
+
+describe("reducePreview: 停滞監視", () => {
+    it("loading のまま閾値を超える tick で failed になり、さらに tick で次へ進む", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const stalled = reducePreview(start, { type: "tick", at: 1_000 }, opts);
+        expect(stalled.clip).toBe("failed");
+        expect(stalled.index).toBe(0);
+
+        // failed の表示は placeholderSeconds だけ見せてから次へ進む (有限時間で必ず前進する)
+        const advanced = reducePreview(stalled, { type: "tick", at: 1_000 + 3_000 }, opts);
+        expect(advanced.index).toBe(1);
+        expect(advanced.clip).toBe("loading");
+        expect(advanced.generation).toBe(1);
+    });
+
+    it("既定の停滞閾値は PREVIEW_STALL_TIMEOUT_MS である", () => {
+        const opts = options([clipEntry(1, 11)], { stallTimeoutMs: undefined });
+        const start = initialPreviewState(opts, 0);
+
+        expect(reducePreview(start, { type: "tick", at: PREVIEW_STALL_TIMEOUT_MS - 1 }, opts).clip).toBe(
+            "loading",
+        );
+        expect(reducePreview(start, { type: "tick", at: PREVIEW_STALL_TIMEOUT_MS }, opts).clip).toBe(
+            "failed",
+        );
+    });
+
+    it("progress が来ている間は停滞にならない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "playing", at: 100 },
+            { type: "progress", at: 900 },
+            { type: "tick", at: 1_500 },
+        ]);
+
+        expect(state.clip).toBe("playing");
+    });
+
+    it("paused 中は tick をいくら送っても failed にならない", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "playing", at: 10 },
+            { type: "paused", at: 20 },
+            { type: "tick", at: 5_000 },
+            { type: "tick", at: 50_000 },
+        ]);
+
+        expect(state.clip).toBe("paused");
+        expect(state.index).toBe(0);
+    });
+
+    it("shouldWatchStall は表示中かつ loading/playing のときだけ真", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const base = initialPreviewState(opts, 0);
+
+        expect(shouldWatchStall(base)).toBe(true);
+        expect(shouldWatchStall({ ...base, clip: "playing" })).toBe(true);
+        expect(shouldWatchStall({ ...base, clip: "paused" })).toBe(false);
+        expect(shouldWatchStall({ ...base, clip: "blocked" })).toBe(false);
+        expect(shouldWatchStall({ ...base, clip: "failed" })).toBe(false);
+        expect(shouldWatchStall({ ...base, visible: false })).toBe(false);
+        expect(shouldWatchStall({ ...base, finished: true })).toBe(false);
+    });
+});
+
+describe("reducePreview: 一時停止と再開", () => {
+    it("loading 中の paused を受け付け、以後 tick で failed にならない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const paused = reducePreview(start, { type: "paused", at: 100 }, opts);
+        expect(paused.clip).toBe("paused");
+        expect(reducePreview(paused, { type: "tick", at: 90_000 }, opts).clip).toBe("paused");
+    });
+
+    it("resumed は loading へ戻し progressAt を引き直す (停止していた時間を停滞に数えない)", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const resumed = apply(start, opts, [
+            { type: "paused", at: 100 },
+            { type: "resumed", at: 60_000 },
+        ]);
+        expect(resumed.clip).toBe("loading");
+        expect(resumed.progressAt).toBe(60_000);
+
+        expect(reducePreview(resumed, { type: "playing", at: 60_100 }, opts).clip).toBe("playing");
+    });
+
+    it("paused でない状態の resumed は状態を変えない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        expect(reducePreview(start, { type: "resumed", at: 10 }, opts)).toEqual(start);
+    });
+});
+
+describe("reducePreview: 可視性", () => {
+    it("hidden 中は tick で進まず、shown で progressAt が引き直される", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const hidden = apply(start, opts, [
+            { type: "hidden", at: 10 },
+            { type: "tick", at: 100_000 },
+        ]);
+        expect(hidden.clip).toBe("loading");
+        expect(hidden.index).toBe(0);
+
+        const shown = reducePreview(hidden, { type: "shown", at: 100_100 }, opts);
+        expect(shown.visible).toBe(true);
+        expect(shown.progressAt).toBe(100_100);
+    });
+
+    it("paused → hidden → shown で再生状態が変わらない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "playing", at: 10 },
+            { type: "paused", at: 20 },
+            { type: "hidden", at: 30 },
+            { type: "shown", at: 40 },
+        ]);
+
+        expect(state.clip).toBe("paused");
+    });
+
+    it("非表示中はメディア由来イベントを受け付けない (ended / error / playing / paused)", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const hidden = reducePreview(initialPreviewState(opts, 0), { type: "hidden", at: 10 }, opts);
+
+        for (const type of ["ended", "error", "playing", "paused"] as const) {
+            const next = reducePreview(hidden, { type, at: 20 }, opts);
+            expect(next).toEqual(hidden);
+        }
+    });
+
+    it("非表示中でも利用者操作 (skip) は効く", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const hidden = reducePreview(initialPreviewState(opts, 0), { type: "hidden", at: 10 }, opts);
+
+        const skipped = reducePreview(hidden, { type: "skip", at: 20 }, opts);
+        expect(skipped.index).toBe(1);
+        expect(skipped.generation).toBe(1);
+    });
+});
+
+describe("reducePreview: 世代", () => {
+    it("advance 後に古い世代の error / blocked を送っても状態が変わらない", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const advanced = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);
+
+        expect(advanced.generation).toBe(1);
+        expect(reducePreview(advanced, { type: "error", generation: 0, at: 20 }, opts)).toEqual(advanced);
+        expect(reducePreview(advanced, { type: "blocked", generation: 0, at: 20 }, opts)).toEqual(
+            advanced,
+        );
+    });
+
+    it("現在世代のイベントは受理される", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const advanced = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);
+
+        expect(reducePreview(advanced, { type: "error", generation: 1, at: 20 }, opts).clip).toBe(
+            "failed",
+        );
+    });
+});
+
+describe("reducePreview: blocked (自動再生制限)", () => {
+    it("blocked → retry → blocked を繰り返しても failed にならない", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "blocked", at: 10 },
+            { type: "tick", at: 90_000 },
+            { type: "retry", at: 90_100 },
+            { type: "blocked", at: 90_200 },
+            { type: "tick", at: 180_000 },
+        ]);
+
+        expect(state.clip).toBe("blocked");
+        expect(state.index).toBe(0);
+    });
+
+    it("blocked から skip で次へ進む (出口がある)", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const blocked = reducePreview(initialPreviewState(opts, 0), { type: "blocked", at: 10 }, opts);
+
+        const skipped = reducePreview(blocked, { type: "skip", at: 20 }, opts);
+        expect(skipped.index).toBe(1);
+        expect(skipped.clip).toBe("loading");
+    });
+});
+
+describe("reducePreview: プレースホルダ", () => {
+    it("placeholder は placeholderSeconds 経過の tick で次へ進む", () => {
+        const opts = options([missingEntry(1), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        expect(reducePreview(start, { type: "tick", at: 2_999 }, opts).index).toBe(0);
+        const advanced = reducePreview(start, { type: "tick", at: 3_000 }, opts);
+        expect(advanced.index).toBe(1);
+        expect(advanced.clip).toBe("loading");
+    });
+
+    it("missing が連続しても順に進み最後は finished になる", () => {
+        const opts = options([missingEntry(1), missingEntry(2)]);
+        const start = initialPreviewState(opts, 0);
+
+        const second = reducePreview(start, { type: "tick", at: 3_000 }, opts);
+        expect(second.clip).toBe("placeholder");
+        const finished = reducePreview(second, { type: "tick", at: 6_000 }, opts);
+        expect(finished.finished).toBe(true);
+    });
+});
+
+describe("reducePreview: 終端と空リスト", () => {
+    it("最後の entry の ended で finished になり、以後どのイベントでも状態が変わらない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const finished = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);
+
+        expect(finished.finished).toBe(true);
+        for (const type of ["tick", "skip", "retry", "error", "playing", "shown"] as const) {
+            expect(reducePreview(finished, { type, at: 20 }, opts)).toEqual(finished);
+        }
+    });
+
+    it("entries が 0 件ならどのイベントを送っても状態が変わらない (clip の値に依存しない)", () => {
+        const opts = options([]);
+        const start = initialPreviewState(opts, 0);
+
+        for (const type of ["tick", "skip", "ended", "error", "hidden", "shown"] as const) {
+            expect(reducePreview(start, { type, at: 10 }, opts)).toEqual(start);
+        }
+    });
+});
diff --git a/tests/js/lib/capture/thumbnail-refresh.test.ts b/tests/js/lib/capture/thumbnail-refresh.test.ts
index 79ac5c4..dc37b67 100644
--- a/tests/js/lib/capture/thumbnail-refresh.test.ts
+++ b/tests/js/lib/capture/thumbnail-refresh.test.ts
@@ -45,6 +45,7 @@ function makeManual(takes: CaptureTake[]): CaptureManualDetail {
                 subtitle_primary: null,
                 subtitle_secondary: "準備",
                 adopted_take_id: null,
+                adopted_ready_take_id: null,
                 takes,
             },
         ],
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 1f8a149..586f0ab 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -100,6 +100,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         subtitle_primary: null,
         subtitle_secondary: "",
         adopted_take_id: null,
+        adopted_ready_take_id: null,
         takes: [],
         ...overrides,
     };
@@ -134,13 +135,14 @@ function makeAdoptedManual(): CaptureManualDetail {
         id: 5,
         title: "ネジ締め作業",
         status: "ready",
-        cuts: [makeCut({ adopted_take_id: take.id, takes: [take] })],
+        cuts: [makeCut({ adopted_take_id: take.id, adopted_ready_take_id: take.id, takes: [take] })],
     };
 }
 
 const baseProps = {
     project: { id: 1, name: "現場A" },
     manual: makeManual(),
+    previewPlaceholderSeconds: 3,
 };
 
 function stubCameraSupported(supported: boolean): void {
@@ -295,7 +297,11 @@ describe("Capture/Show カメラフォールバック", () => {
 });
 
 describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
-    const adoptedProps = { project: { id: 1, name: "現場A" }, manual: makeAdoptedManual() };
+    const adoptedProps = {
+        project: { id: 1, name: "現場A" },
+        manual: makeAdoptedManual(),
+        previewPlaceholderSeconds: 3,
+    };
 
     it("入室時に run(manual) が発火し、changed=true なら manual reload される", async () => {
         stubCameraSupported(false);
@@ -364,7 +370,9 @@ describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
         stubCameraSupported(true);
         getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
 
-        render(CaptureShow, { props: { project: { id: 1, name: "現場A" }, manual: makeManual() } });
+        render(CaptureShow, {
+            props: { project: { id: 1, name: "現場A" }, manual: makeManual(), previewPlaceholderSeconds: 3 },
+        });
         await selectCut();
         await fireEvent.click(screen.getByTestId("start-recording"));
         await vi.waitFor(() => {
@@ -607,8 +615,16 @@ function makeLandscapeManual(count: number): CaptureManualDetail {
     };
 }
 
-function landscapeProps(count = 3): { project: { id: number; name: string }; manual: CaptureManualDetail } {
-    return { project: { id: 1, name: "現場A" }, manual: makeLandscapeManual(count) };
+function landscapeProps(count = 3): {
+    project: { id: number; name: string };
+    manual: CaptureManualDetail;
+    previewPlaceholderSeconds: number;
+} {
+    return {
+        project: { id: 1, name: "現場A" },
+        manual: makeLandscapeManual(count),
+        previewPlaceholderSeconds: 3,
+    };
 }
 
 /** 実 CameraRecorder を録画状態まで駆動できる stub 一式 (component は本物のまま使う) */
@@ -931,3 +947,106 @@ describe("Capture/Show 全画面での録画中カット移動抑止 (T186)", ()
         expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
     });
 });
+
+/*
+ * 通し再生 (全体連結プレビュー / T191) のページ配線。
+ * 再生そのものの契約は ScenarioPreviewDialog.test.ts / scenario-preview.test.ts が持つ。
+ * ここで固定するのは **ページが何を渡し、いつ開き、カメラ資源をどう明け渡すか** だけ。
+ */
+describe("Capture/Show 通し再生の配線 (T191)", () => {
+    beforeEach(() => {
+        // jsdom は HTMLMediaElement の再生系を未実装 (ダイアログの teardown / 再生要求が呼ぶ)
+        vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
+        vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
+        vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
+    });
+
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("通し再生ボタンを押すとダイアログが開く", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: baseProps });
+
+        const button = screen.getByTestId("scenario-preview-button");
+        expect(button).not.toBeDisabled();
+
+        await fireEvent.click(button);
+
+        expect(screen.getByTestId("scenario-preview-dialog")).toBeInTheDocument();
+    });
+
+    it("カットが 0 件ならボタンを出さない (disabled ではなく非表示)", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, {
+            props: { ...baseProps, manual: { ...makeManual(), cuts: [] } },
+        });
+
+        expect(screen.queryByTestId("scenario-preview-button")).not.toBeInTheDocument();
+    });
+
+    it("録画中に押すとエラーを出しダイアログは開かない (ボタンは常に押せる)", async () => {
+        stubCameraRecordable();
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+
+        const button = screen.getByTestId("scenario-preview-button");
+        expect(button).not.toBeDisabled();
+        await fireEvent.click(button);
+
+        expect(screen.getByTestId("scenario-preview-error")).toHaveTextContent(
+            "撮影中は通し再生を開始できません。撮影を停止してからお試しください。",
+        );
+        expect(screen.queryByTestId("scenario-preview-dialog")).not.toBeInTheDocument();
+    });
+
+    it("録画中のエラー文言は個別 preview と同じ言い回しである (制約を別の言葉で説明しない)", async () => {
+        stubCameraRecordable();
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-button"));
+        const scenarioMessage = screen.getByTestId("scenario-preview-error").textContent ?? "";
+
+        // TakeStrip の個別 preview: 「撮影中はプレビューを再生できません。撮影を停止してからお試しください。」
+        expect(scenarioMessage).toContain("撮影を停止してからお試しください。");
+    });
+
+    it("開くとカメラを解放し、閉じると復帰する", async () => {
+        stubCameraRecordable();
+        const camera = fakeStream();
+        getUserMediaMock.mockResolvedValue(camera.stream);
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        // カメラは録画開始で初めて取得される。撮影を停止すると stream は live のまま idle へ戻る
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("start-recording")).toBeInTheDocument();
+        });
+        expect(getUserMediaMock).toHaveBeenCalledTimes(1);
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-button"));
+
+        expect(camera.stop).toHaveBeenCalled(); // releaseForPreview 経由でトラックが止まる
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
+
+        // resumeAfterPreview による再取得 (解放前に live だったときだけ走る)
+        await vi.waitFor(() => {
+            expect(getUserMediaMock).toHaveBeenCalledTimes(2);
+        });
+    });
+});
```

## テスト結果

- composer test: 5441 tests / 5439 passed / 2 skipped / 0 failed (23523 assertions)
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint: passed
- pnpm typecheck: passed
- pnpm test: 153 files / 1882 passed
- pnpm build: 成功
- pnpm typecheck:packages / build:packages / test:packages: すべて成功 (106 tests)
