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
