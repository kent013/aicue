# Round 4: Round 3 指摘 (missing 後の src 割り当て / 抑止の残存 / docblock 2 件) への対応

## 対応マトリクス

# 対応マトリクス: design-review Round 3

## [Critical] S5: `clip → missing → clip` で次の clip に src を割り当てる主体が居ない (再生不能)
- 判断: 対応する (提案どおり)
- 根拠: 指摘のとおりの穴だった。先読みは「現在 clip が `playing` になったとき」しか走らず、
  `missing` は `playing` にならないため、`missing` を挟むと次の clip へ `src` が入らない。
  **先頭が missing の並び**と **missing 連続**も同じ理由で再生不能になる (機能が成立しない)。
- 対応内容: 実装仕様に「**進んだ先の同期 (先読みが無い経路の補完)**」の行を追加し、
  `advance` 直後の規則を 4 つに固定した —
  (i) destination が `clip` で active slot の `src + generation` が**一致すれば何もしない**
  (先読み成功経路 = 再取得しない)、
  (ii) 一致しないときだけ active slot へ `src + generation` を割り当てる
  (missing 後 / 初回 / 先読み失敗のフォールバック)、
  (iii) destination が `missing` なら active slot を teardown、
  (iv) 「無条件に再代入する `$effect`」は**禁止**のまま、「**台帳と一致しないときだけ補完する**」ことは
  **許可**する (この違いが二重取得の有無を分ける)。
  component テストに `missing → clip` / `clip → missing → clip` / `missing → missing → clip` /
  **先頭が missing** の 4 並びと、「先読み済み `clip → clip` では補完が再代入しない」を追加した。

## [Warning] S5: `pause` イベントが発生しない `pause()` で抑止が残存する
- 判断: 対応する (提案どおり)
- 根拠: 非表示処理で inactive の先読み video にも `pause()` すると、その要素は元から paused なので
  イベントが発火せず `suppressPause[inactive]` が残る。その slot が後で active になったとき、
  **本物の利用者 pause を誤って握り潰す**。
- 対応内容: 自分から止める唯一の入口として `pauseProgrammatically(slot, video)` を実装仕様に書き下ろし、
  **既に paused なら抑止を立てずに抜ける**形にした。併せて
  (b) slot へ新しい `src + generation` を割り当てるとき、(c) teardown のときにも抑止をクリアする、
  の 2 点を契約に加えた。component テストに
  「既に paused の inactive slot へ programmatic pause した後、その slot が active になってからの
  利用者 pause が抑止されない」を追加した。

## [Suggestion] S2: `readyTakeId()` の docblock が「eager load 済み」と adopt 経路の説明で矛盾する
- 判断: 対応する
- 根拠: 指摘のとおり、「eager load 済みで呼ぶこと」と「adopt 応答は未ロードから lazy load」は
  文言として矛盾していた。
- 対応内容: docblock の前提を 3 段に書き直した —
  (1) 一覧の直列化では N+1 防止のため eager load 必須、
  (2) 単一 Cut の直列化では**未ロードかつ最新の `adopted_take_id` を持つインスタンス**なら lazy load を許容、
  (3) **古い relation cache を持つインスタンスは不可** (呼び出し側の責務)。

## [Suggestion] S4: 「網羅は型で担保する」は不正確
- 判断: 対応する
- 根拠: `Set<PreviewEvent["type"]>` が担保するのは要素型の正当性だけで、登録漏れは検出しない。
- 対応内容: コメントを「**要素型の正当性だけを型が担保し、登録漏れは検出しない** (漏れは Vitest が拾う)」
  へ弱めた (保証範囲を誇張しない)。

## [APPROVE] S1 / S2 / S3 / S4 / S6 / S7 / S8
- 判断: 対応不要 (合意済み)
- S2 の `unsetRelation` 非採用 (gate との衝突) と behavioral テストでの担保は承認された。


---

## 書き直した S5 (全文)

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
| 先読み | 現在クリップが `playing` になった時点で、**次の 1 件だけ** inactive 側へ (c) の規則で `src` を設定し `preload="auto"` にする。次が `missing` / 末尾なら何もしない (inactive の `slotSrc` / `slotGeneration` を `null` に戻して teardown する) |
| **進んだ先の同期 (先読みが無い経路の補完)** | `advance` の直後に **destination entry で active slot を同期する**。これが無いと `clip → missing → clip` や**先頭が missing**・**missing 連続**の並びで、次の clip に `src` を割り当てる主体が存在せず**再生不能になる** (先読みは「現在 clip が playing になったとき」しか走らず、`missing` は `playing` にならないため)。規則は 4 つ: (i) destination が `clip` で **active slot の `src + generation` が一致**するなら**何もしない** (先読み成功経路 = 再取得しない)。(ii) destination が `clip` で一致しないときだけ active slot へ `src + generation` を割り当てる (missing 後 / 初回 / 先読み失敗のフォールバック)。(iii) destination が `missing` なら active slot を teardown する。(iv) **「active entry を見て無条件に src を再代入する `$effect`」は禁止**だが、**「台帳と一致しないときだけ補完する」ことは許可**する (この違いが二重取得の有無を分ける) |
| イベント | `canplay` / `timeupdate` / `progress` → `progress`、`playing` → `playing`、`pause`(利用者操作) → `paused`、`play` → `resumed`、`ended` → `ended`、`error` → `error` を **その要素の世代付きで** reducer へ送る |
| `play()` | 戻り値の Promise を必ず `catch` する。**世代が一致し、かつ自動再生制限と判定できる拒否** (`err instanceof DOMException && err.name === "NotAllowedError"`) のみ `blocked` を送る。それ以外は**何も送らない** (失敗の確定は `error` と停滞監視に委ねる)。**この設計は「停滞監視が必ず回収する」ことに依存している** — 拒否後は進捗イベントが来ないため `stallTimeoutMs` 経過で `failed` → 次のカットへ進む。この回収を component テストで固定する (下記) |
| `tick` | `setInterval` (1 秒) で `tick` を送る。ダイアログを閉じるときに必ず破棄する |
| **programmatic pause** | teardown / 非表示 / スキップで自分から止めるときは、**必ず次の helper を通す**。`pause` ハンドラは**抑止が立っていたら消費して (false に戻して) reducer へ送らない** (`paused` は利用者操作由来のみ = S4 の契約)。**`pause()` の直後にフラグを戻さない** (イベントは非同期に届く)。<br>**抑止を残さないための 3 点**: (a) **既に paused の要素には抑止を立てない** (`pause` イベントが発火せず抑止が残り、その slot が後で active になったときに**本物の利用者 pause を誤って握り潰す**)。(b) slot へ新しい `src + generation` を割り当てるときにその slot の抑止をクリアする。(c) teardown でもクリアする |

```ts
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
    dispatch({ type: "paused", generation: slotGeneration[slot] ?? undefined });
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

## その他の修正 (原文)

### S1 の docblock (前提を 3 段に)

```php
     * 前提 ($cut の adoptedTake の鮮度。3 段で読むこと):
     *   1. **一覧の直列化では eager load 必須** (`with('adoptedTake')`)。無いと N+1 になる。
     *   2. **単一 Cut の直列化では lazy load を許容する** — relation 未ロードで、かつ最新の
     *      `adopted_take_id` を持つインスタンスなら結果は同じである (adopt 応答の経路)。
     *   3. **古い relation cache を持つインスタンスは不可**。ロード後に `adopted_take_id` を
     *      書き換えたインスタンスをそのまま渡さないこと (呼び出し側の責務)。
```

### S4 の `MEDIA_ORIGIN_EVENTS` のコメント

```ts
/**
 * メディア要素が起点のイベント (非表示中は受け付けない側)。
 * `Set<PreviewEvent["type"]>` が担保するのは**要素型の正当性**だけで、
 * **必要なイベントの登録漏れは検出しない** (漏れは下の Vitest が拾う)。
 */
```

---

## 質問

残る Critical / Warning があれば挙げてほしい。無ければ全体判定を APPROVED としてほしい
(本ラウンドが詳細設計の最終ラウンド)。
