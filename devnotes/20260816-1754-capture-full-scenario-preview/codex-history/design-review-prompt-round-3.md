# Round 3: Round 2 指摘 (非表示中の ended / slot 別世代台帳 / suppressPause / relation 鮮度) への対応

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## [Critical] S4: 非表示中の `ended` が実際には advance する (実装と S5 のテスト計画が矛盾)
- 判断: 対応する
- 根拠: 指摘のとおり。`pause()` しても既にキューへ入った `ended` / `error` は到着しうるため、
  実メディアの操作だけに依存した「非表示中は進まない」は成立していなかった。
- 対応内容: reducer の先頭に **メディア由来イベントの拒否**を追加した。
  `MEDIA_ORIGIN_EVENTS` (progress / playing / paused / resumed / ended / error / blocked) を
  `Set<PreviewEvent["type"]>` で持ち、`!state.visible` のとき早期 return する。
  利用者操作 (skip / retry)・可視性 (hidden / shown)・時間 (tick) は**常に処理する**。
  Vitest に「`hidden` の後の `ended` で index / generation が変わらない」と
  「`hidden` の後でも `skip` は進む」を追加した (メディア由来と利用者由来の取り違えの固定)。

## [Critical] S5: slot 別の世代台帳が無く、遅延イベントに正しい世代を付けられない
- 判断: 対応する (提案どおり)
- 根拠: `slotSrc` だけでは、どの video 要素から届いたイベントにどの `generation` を付けるかを
  決められない。先読み要素は「次世代」、active 要素は「現世代」であり、slot 反転後に
  旧要素から届く遅延イベントを捨てるには世代を slot へ固定する必要がある。
- 対応内容: `slotGeneration: [number | null, number | null]` を実装仕様へ追加した。
  active 割当時は現在の `generation`、先読み時は `generation + 1`。
  イベントハンドラは発火 slot の `slotGeneration[slot]` を `event.generation` として渡す。
  teardown では `slotSrc` / `slotGeneration` / `suppressPause` を同時に初期化する。
  **台帳の同一性判定を `src` 単独から `src + generation` へ変更**した
  (同じ URL が続けて現れても割当を省略しない。ドメイン上はテイクが 2 つのカットに属さないため
  連続同一 URL は起きないが、その事実に**依存しない**形にする)。
  component テストに「slot 反転後に旧 slot から届く `ended` / `error` が状態を変えない」を追加。

## [Warning] S5: `programmaticPause` が単一 boolean で、非同期イベントと 2 要素を扱えない
- 判断: 対応する (提案どおり)
- 根拠: `pause()` の直後にフラグを戻すと、非同期に配送される `pause` イベントを抑止できない。
  また video が 2 枚あるため単一 boolean では発生元を区別できない。
- 対応内容: `suppressPause: [boolean, boolean]` の **slot 別**抑止へ変更し、
  「**`pause` イベントを受けた時点で消費する** (`pause()` 直後には戻さない)」を契約にした。
  既に paused でイベントが発火しない場合に抑止が残るため、**teardown で明示的にクリア**する。
  component テストに「抑止は slot 別」「抑止は消費されるまで残る (microtask をまたぐ)」を追加。

## [Warning] S2: `CaptureCutData::fromCut()` の `adoptedTake` 鮮度が明文化されていない
- 判断: 一部対応 (事前条件の明文化 + behavioral テスト) / 一部反論 (`unsetRelation` の追加は採れない)
- 根拠:
  - 現在の 2 経路はどちらも鮮度を満たしている。詳細画面は `with('adoptedTake')`、
    adopt 応答は `CaptureTakeService::adopt()` が **tx 内で `cuts()->whereKey(...)` から取り直した
    Cut を返す**ため relation は未ロードで、`forceFill` 後に新しい id で lazy load される
    (controller が bind した `$cut` インスタンスは返らない)。
  - **提案された `unsetRelation('adoptedTake')` の追加は gate と衝突して採れない**:
    `CaptureTakeService` と `CaptureTakeController` はどちらも `TakeStatus::Ready` を含むため、
    `'adoptedTake'` の文字列を足すと `AdoptedReadyTakeCriterionInventoryTest` の
    **検出 B (判定式の同居)** に該当する。名指し免除の前提 2
    (`'adoptedTake'` の出現がすべて `->doesntHave('adoptedTake')` の単独引数形) も満たせないため、
    **gate を弱めない限り登録できない**。仮定の将来事故のために不変条件の gate を緩めるのは
    本末転倒である。
- 対応内容: `fromCut()` の**事前条件と、それを満たす 2 経路の根拠**を設計へ明記した。
  そのうえで指摘された 2 つの Feature テスト
  (「adopt 直後に `adopted_ready_take_id` が採用 id になる」「採用の付け替えで新しい方になる」) を
  テスト計画へ追加し、**鮮度を behavioral に守る**形にした
  (将来 `adopt()` が bind 済み Cut を返す形へ変わればその瞬間に赤くなる)。
  「relation がロード済み null の状態から採用するケース」は、`adopt()` が返すのが
  常に tx 内で取り直したインスタンスであるため**外から構成できない**。この構造上の理由を明記した。

## [APPROVE] S1 / S3 / S6 / S7 / S8
- 判断: 対応不要 (合意済み)
- 反論 3 点 (DTO→Service 依存 / 非 NotAllowedError の stall 回収 / `releaseForPreview()` の同期性) は
  いずれも受け入れられた。


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

/** メディア要素が起点のイベント (非表示中は受け付けない側)。網羅は型で担保する */
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
</script>
```

主要な配線 (実装時の契約):

| 要素 | 契約 |
|---|---|
| 再生リスト | `open` になった時点の `cuts` から `buildPreviewEntries()` で 1 度だけ組む (再生中に props が更新されても差し替えない = 位置が飛ばない)。閉じて開き直したら組み直す |
| メディア要素 | `videoA` / `videoB` の 2 枚。`activeSlot: 0 \| 1` を state で持ち、**現在再生 = active、先読み = inactive**。`advance` 時に `activeSlot` を反転し、先読み済み要素をそのまま再生する |
| **src 割り当ての契約 (二重取得を作らない)** | `slotSrc` を**台帳**として持ち、割り当ては次の 3 規則だけで行う。(a) **`slotSrc[slot]` が設定したい src と等しく、かつ `slotGeneration[slot]` が割り当てたい世代と等しいなら何もしない** (再代入しない = 再取得しない)。**同一性は `src` だけでなく `src + generation` で判断する** (同じ URL が続けて現れても世代の割当を省略しない)。(b) `advance` では **`activeSlot` を反転するだけ**で、新しい active 側の `src` には触れない (先読み済みの要素をそのまま使う)。(c) 先読みは (a) の同一性判定で異なるときだけ設定する。**「active entry を見て active 要素に src を入れる」形の `$effect` は書かない** (先読み済み URL の再代入 = 二重取得になる) |
| **世代の台帳** | `slotGeneration: [number \| null, number \| null]`。active 割当時は現在の `generation`、先読み時は `generation + 1` を入れる。**イベントハンドラは発火した slot の `slotGeneration[slot]` を `event.generation` として渡す**。teardown では `slotSrc` / `slotGeneration` / `suppressPause` を**同時に**初期化する |
| 先読み | 現在クリップが `playing` になった時点で、**次の 1 件だけ** inactive 側へ (c) の規則で `src` を設定し `preload="auto"` にする。次が `missing` / 末尾なら何もしない (inactive の `slotSrc` は `null` に戻して teardown する) |
| イベント | `canplay` / `timeupdate` / `progress` → `progress`、`playing` → `playing`、`pause`(利用者操作) → `paused`、`play` → `resumed`、`ended` → `ended`、`error` → `error` を **その要素の世代付きで** reducer へ送る |
| `play()` | 戻り値の Promise を必ず `catch` する。**世代が一致し、かつ自動再生制限と判定できる拒否** (`err instanceof DOMException && err.name === "NotAllowedError"`) のみ `blocked` を送る。それ以外は**何も送らない** (失敗の確定は `error` と停滞監視に委ねる)。**この設計は「停滞監視が必ず回収する」ことに依存している** — 拒否後は進捗イベントが来ないため `stallTimeoutMs` 経過で `failed` → 次のカットへ進む。この回収を component テストで固定する (下記) |
| `tick` | `setInterval` (1 秒) で `tick` を送る。ダイアログを閉じるときに必ず破棄する |
| **programmatic pause** | teardown / 非表示 / スキップで自分から `pause()` するときは **その slot の** `suppressPause[slot] = true` を立ててから `pause()` する。`pause` ハンドラは**抑止が立っていたら消費して (false に戻して) reducer へ送らない** (`paused` は利用者操作由来のみ = S4 の契約)。**`pause()` の直後にフラグを戻さない** (イベントは非同期に届く)。既に paused で `pause` イベントが発火しない場合に抑止が残るため、**teardown で明示的にクリアする** |
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



---

## 質問

1. S2 の `unsetRelation('adoptedTake')` を採れない理由 (Architecture gate の検出 B と
   名指し免除の前提 2 に抵触し、gate を弱めない限り登録できない) と、
   代わりに behavioral テストで守る判断は妥当か。
2. 残る Critical / Warning があれば挙げてほしい。無ければ全体判定を APPROVED としてほしい。
