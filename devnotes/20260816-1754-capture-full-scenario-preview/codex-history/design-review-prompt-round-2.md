# Round 2: Round 1 指摘への対応 (詳細設計)

Round 1 の Critical 2 件 / Warning 8 件 / Suggestion への対応を行いました。対応マトリクスと、
書き直した施策の全文を添えます。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Critical] S2: 非 ready の採用テイクにも署名 URL / ACK を発行し続ける
- 判断: 対応する (施策 S2b として設計へ組み込む)
- 根拠: 指摘のとおり。現行 `cutWithAdoptedUrls()` は `$cut->adoptedTake` の status を見ずに
  `temporaryPlaybackUrl()` と ACK を発行しており、**`capture.takes.playback` が非 ready を
  404 にしている (状態秘匿) のと食い違う**。`adopted_ready_take_id` を足すと、この食い違いは
  「id は null なのに `playback_url` は非 null」というクライアント契約の矛盾として表面化する。
- 対応内容: `cutWithAdoptedUrls()` の先頭で `AdoptedReadyTakeCoverage::readyTakeId($cut) === null` なら
  URL / ACK を付けずに `CaptureCutData::fromCut($cut)` を返す形へ変更した
  (`RenderPipeline::clipSpecFor` と同じ書き方。非欠落側は `Assert::notNull` で型を絞る)。
  テストに「非 ready では URL / ACK が null」「`temporaryPlaybackUrl` が 1 度も呼ばれない
  (`shouldNotReceive`)」を追加。副次効果 (自動 DL が非 ready を先読みしなくなる = 処理中のテイクを
  「取得済み」と記録しない) と、その挙動変化をリスクに明記した。
  なお `video_path` は非 null カラムのため「null 参照でクラッシュ」は起きないが、
  **契約の矛盾と状態秘匿の食い違い**が本質的な問題であり、指摘の修正案どおり閉じる。

## [Warning] S2: DTO がドメイン判定 service に依存する
- 判断: 一部反論 (現設計を維持) + 指摘どおり根拠を docblock へ残す
- 根拠: 本リポジトリには既に先例が 2 件ある —
  `DataTransferObjects/Manual/TakeSelectionPageData` → `Services\Manual\CutSequencer`、
  `DataTransferObjects/Manual/ManualListItemData` → `Services\Manual\ManualRowAbilities`。
  加えて「呼び出し側が計算して DTO へ渡す」形にすると、`fromCut()` の呼び出し口
  (詳細画面 / adopt 応答) ごとに**渡し忘れうる**形になり、T148 が閉じた
  「呼び出し側が判定を組み立てる」形へ戻ってしまう。DTO 側が唯一の述語を呼ぶ方が構造的に安全である。
- 対応内容: 先例 2 件と「渡し忘れを構造で消す」理由を詳細設計へ明記し、
  同じ理由を `CaptureCutData` の docblock に残すこととした。

## [Warning] S2: array shape docblock (`has_thumbnail`) の同期
- 判断: 対応する
- 根拠: 指摘のとおり、現行 `CaptureCutData::toArray()` の入れ子 take shape は `has_thumbnail` を
  欠いており、`CaptureTakeData::toArray()` の宣言 (`has_thumbnail: bool` を含む) と食い違っている。
- 対応内容: 新キー追加の「ついで」ではなく**明示の是正項目**として波及変更に立て、
  変更後 docblock を現行実装と完全同期させた。

## [Suggestion] S3: config の正値を固定する価値
- 判断: 一部対応
- 対応内容: props の値が **1 以上の int** であることを Feature テストで固定した。
  **新しい config gate は作らない** (今必要なのは props の契約だけであり、
  設定値そのものの範囲検査を `ConfigHardeningTest` の議題にする根拠がまだ無い。思考原則 2)。

## [Warning] S4: 空リストの初期状態で `clip` が矛盾する
- 判断: 対応する
- 対応内容: 「`finished` のとき `clip` は読まない」ことを docblock に明記し、
  Vitest に「空リストでは finished かつ**どのイベントでも状態が変わらない**」を追加した
  (= `clip` の値に依存する分岐が存在しないことの固定)。

## [Warning] S4: `loading` 中の pause が扱えない / 利用者 pause とブラウザ都合 pause の区別
- 判断: 対応する (両方)
- 対応内容: (a) reducer は `paused` を `playing` **と `loading`** から受け付けるようにした
  (読み込み中に止めたのに停滞監視が動き続けて `failed` になるのを防ぐ)。
  (b) 「**`paused` は利用者操作由来のみ**」を契約として明文化し、S5 側に
  `programmaticPause` フラグ (teardown / 非表示 / スキップで自分から `pause()` する間は
  reducer へ送らない) を追加、component テストで固定することにした。
  併せて `resumed` は `playing` ではなく `loading` へ戻す (再開直後は進捗待ちのため) 形に直した。

## [Suggestion] S4: `failed` の表示待ちに `placeholderSeconds` を流用する名称
- 判断: 対応する (説明を足す。値は増やさない)
- 対応内容: 「どちらも『見せてから次へ進むまでの待ち』であり、2 つ持つと必ず食い違う」ことを
  docblock に明記した。UI 文言とテスト名では「プレースホルダ表示秒数」の意味を保つ。

## [Critical] S5: `$bindable` が実装仕様に無い
- 判断: 対応する
- 対応内容: `let { open = $bindable(false), ... }: Props = $props();` を実装仕様に明記した
  (`TakePreviewDialog` と同じ契約)。併せて `activeSlot` / `slotSrc` / `programmaticPause` の
  state 宣言も仕様へ書き下ろした。

## [Critical] S5: 2 枚の video の src 設定順序が未定義で二重取得しうる
- 判断: 対応する
- 根拠: 指摘のとおり「active entry を見て active 要素へ src を入れる `$effect`」を書くと、
  先読み済み URL を再代入して二重取得になる。設計に規則が無いと実装がその形に倒れる。
- 対応内容: `slotSrc: [string|null, string|null]` を**台帳**として持ち、
  割り当て規則を 3 つに固定した — (a) 台帳と同じ src なら**何もしない**、
  (b) `advance` は `activeSlot` を反転するだけで新 active の src に触れない、
  (c) 先読みは台帳と異なるときだけ設定する。
  「active entry を見て active 要素に src を入れる `$effect` は書かない」を明文の禁止として置き、
  component テストに「先読み済み slot が active になったあと src が再代入されない」を追加した。

## [Warning] S5: `NotAllowedError` 以外の reject で loading のまま固まりうる
- 判断: 一部反論 (現設計を維持) + 指摘された回収の固定を追加
- 根拠: 概念設計 Round 3-5 の議論で「例外名の分類に依存して失敗を確定させない」を採った
  (名前は環境差が大きく、正常なクリップを誤って欠落として見せると本機能の目的が壊れる)。
  拒否後は進捗イベントが来ないため、**停滞監視が `stallTimeoutMs` で必ず回収する**
  (`tick` は component の interval が駆動し続ける)。よって「固まる」ことはない。
- 対応内容: この依存関係を実装仕様の表へ明記し、指摘された条件
  「**stall で必ず回収できることを component test で固定**」をテスト計画に追加した
  (`NotAllowedError` 以外の拒否 → `stallTimeoutMs` 経過 → `failed` → 次へ進む)。
  併せて「回収まで最大 `stallTimeoutMs` 待たせる」ことをリスクに明記した。

## [Warning] S5: teardown / hidden の `video.pause()` も pause イベントを出す
- 判断: 対応する (S4 の Warning と同一の対応)
- 対応内容: `programmaticPause` フラグを実装仕様に追加し、
  「自分から `pause()` する間の `pause` イベントは reducer へ送らない」を契約にした。
  component テストで「programmatic pause は `paused` を作らない / 利用者 pause は作る」を固定する。

## [Warning] S6: `releaseForPreview()` の同期性が不明で資源競合が残りうる
- 判断: 反論 (根拠を設計へ明記)
- 根拠: `CameraRecorder.svelte` L487-491 の `releaseForPreview(): void` は**同期**であり、
  `starting || resuming || phase !== "idle"` のとき自分で早期 return する。
  `resumeAfterPreview(): Promise<void>` は非同期だが in-flight ガードを内部に持ち、
  既存 `TakeStrip` も戻り値を待たない。よって await も失敗ハンドリングも不要である。
- 対応内容: この事実 (行番号付き) を詳細設計へ明記し、
  「解放・復帰は page 側に 1 つの関数として切り出し、`TakeStrip` の props にも同じ関数を渡す」
  ことを併せて明文化した。

## [Warning] S6: 録画中判定が `captureActive` だけで足りるか
- 判断: 対応する (既存条件の再利用を明文化)
- 根拠: 既存の個別 preview (`TakeStrip.openPreview`) の判定も `captureActive` **のみ**であり、
  `captureActive` は recording|stopping を含む。加えて `releaseForPreview()` 自身が
  `starting || resuming || phase !== "idle"` を内部で弾くため、
  「stream は持つが captureActive=false」の窓は CameraRecorder 側で閉じている。
  ここで**別の条件を新設すると資源競合の判定が 2 種類になる**。
- 対応内容: 「判定条件・呼び出し順・文言を既存の個別 preview と同一にする」を設計に明記し、
  文言の同一性を page テストで固定することにした。

## [Suggestion] S6: 狭幅でのボタンの詰まり
- 判断: 対応する
- 対応内容: ボタン群を `flex flex-wrap items-center justify-end gap-2` で包む形へ変更した。

## [Warning] 追加: `AdoptedTakeReferenceInventory` の区分
- 判断: 対応する
- 対応内容: `CaptureManualDetailData` の区分を `DifferentCriterion` → **`DelegatedToCoverage`** へ変更し、
  根拠を「URL / ACK の発行可否を `readyTakeId()` へ委譲し、自前の ready 判定を持たない」に更新した
  (S2b により実態がそうなるため)。

## [Warning] 追加: `pnpm build` を S5/S6 の完了条件に個別に入れる
- 判断: 対応する
- 対応内容: 実装順序の各段に完了条件を書き足した
  (S5/S6 は `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build` を個別に緑にする)。


---

## 書き直した施策 (全文)

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
- [ ] **空**: entries が 0 件なら初期状態で `finished: true` になり、**どのイベントを送っても
      状態が変わらない** (= `clip` の値に依存する分岐が無いことの固定)
- [ ] **loading 中の一時停止**: `loading` → `paused` を受け付け、以後 `tick` で `failed` にならない。
      `resumed` で `loading` に戻り、進捗が来れば `playing` になる
- [ ] **`resumed` は `progressAt` を引き直す** (停止していた時間を停滞に数えない)

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
    /** teardown / 非表示で自分から pause したか (この間の pause イベントは reducer へ送らない) */
    let programmaticPause = false;
</script>
```

主要な配線 (実装時の契約):

| 要素 | 契約 |
|---|---|
| 再生リスト | `open` になった時点の `cuts` から `buildPreviewEntries()` で 1 度だけ組む (再生中に props が更新されても差し替えない = 位置が飛ばない)。閉じて開き直したら組み直す |
| メディア要素 | `videoA` / `videoB` の 2 枚。`activeSlot: 0 \| 1` を state で持ち、**現在再生 = active、先読み = inactive**。`advance` 時に `activeSlot` を反転し、先読み済み要素をそのまま再生する |
| **src 割り当ての契約 (二重取得を作らない)** | `slotSrc: [string \| null, string \| null]` を**台帳**として持ち、割り当ては次の 3 規則だけで行う。(a) **`slotSrc[slot]` が設定したい src と等しいなら何もしない** (再代入しない = 再取得しない)。(b) `advance` では **`activeSlot` を反転するだけ**で、新しい active 側の `src` には触れない (先読み済みの要素をそのまま使う)。(c) 先読みは `slotSrc[inactive]` が次の src と異なるときだけ設定する。**「active entry を見て active 要素に src を入れる」形の `$effect` は書かない** (先読み済み URL の再代入 = 二重取得になる) |
| 先読み | 現在クリップが `playing` になった時点で、**次の 1 件だけ** inactive 側へ (c) の規則で `src` を設定し `preload="auto"` にする。次が `missing` / 末尾なら何もしない (inactive の `slotSrc` は `null` に戻して teardown する) |
| イベント | `canplay` / `timeupdate` / `progress` → `progress`、`playing` → `playing`、`pause`(利用者操作) → `paused`、`play` → `resumed`、`ended` → `ended`、`error` → `error` を **その要素の世代付きで** reducer へ送る |
| `play()` | 戻り値の Promise を必ず `catch` する。**世代が一致し、かつ自動再生制限と判定できる拒否** (`err instanceof DOMException && err.name === "NotAllowedError"`) のみ `blocked` を送る。それ以外は**何も送らない** (失敗の確定は `error` と停滞監視に委ねる)。**この設計は「停滞監視が必ず回収する」ことに依存している** — 拒否後は進捗イベントが来ないため `stallTimeoutMs` 経過で `failed` → 次のカットへ進む。この回収を component テストで固定する (下記) |
| `tick` | `setInterval` (1 秒) で `tick` を送る。ダイアログを閉じるときに必ず破棄する |
| **programmatic pause** | teardown / 非表示 / スキップで自分から `pause()` するときは `programmaticPause = true` を立て、`pause` イベントハンドラは**このフラグが立っている間 reducer へ送らない** (`paused` は利用者操作由来のみ = S4 の契約)。フラグはイベント処理後に必ず戻す |
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



---

## 質問

1. 反論した 3 点 (S2 の DTO→Service 依存 / S5 の `NotAllowedError` 以外を stall 回収に委ねる /
   S6 の `releaseForPreview()` 同期性) について、提示した根拠で納得できるか。
   できないなら、既存コードの事実に即した代案を示してほしい。
2. 残る Critical / Warning があれば挙げてほしい。無ければ全体判定を APPROVED としてほしい。
