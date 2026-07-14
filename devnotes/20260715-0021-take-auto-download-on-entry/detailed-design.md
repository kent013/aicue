# 詳細設計: take-auto-download-on-entry（撮影詳細入室時の採用済みテイク自動ダウンロード）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

### セキュリティ不変条件（関連）

- tenant/ownership/actor キーを payload から受け取らない
- 子は親に属する（nested route の不整合は認可より前に 404）
- cross-org read/write 不可
- 権限判定は `laratrust_team_id` 明示

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）／**Pest**（`composer test`、`RefreshDatabase` + `--parallel` グローバル適用）
- フロントは **Svelte 5 runes** + DS token/ramp のみ。component 階層は単方向 import。アイコンは `@lucide/svelte`
- 検証: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（全 green）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260715-0021-take-auto-download-on-entry/conceptual-design.md`（概念設計 APPROVED, conceptual-review Round 4）

### 本設計で確定した不変条件（概念設計より）

1. **`downloaded_at` の意味**: 「いずれかの認可済みクライアントで当該採用テイクの取得処理（HTTP 成功 + body 読取完了）が成功し ACK された時刻」。**端末単位ではない**（端末識別子を持たない）。手動（`window.open`）・自動（`fetch`）とも同一意味・同一 ACK 経路。オフライン再生・端末内ファイル存在・ブラウザキャッシュ残存は**保証しない**。
2. **v1 制約**: 複数撮影クライアント間の端末別同期状態は保証しない（ワークフロー単位のグローバル状態）。1 manual の撮影クライアントは実質単一を想定。
3. **サーバ変更なし**: 既存 `POST takes.downloaded`（ACK）と詳細 GET payload を変更しない。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 自動 DL オーケストレータ新規追加 | `resources/js/lib/capture/auto-download.ts`（新規） | High |
| 2 | Capture/Show への結線（onMount + online） | `resources/js/pages/Capture/Show.svelte` | High |
| 3 | 単体テスト（オーケストレータ） | `tests/js/lib/capture/auto-download.test.ts`（新規） | High |
| 4 | 結線テスト（Show） | `tests/js/pages/CaptureShow.test.ts` | High |
| 5 | ドキュメント整合（意味の明文化） | `doc/05_スマホアプリ機能仕様.md` / `docs/architecture.md` | Medium |
| 6 | S3/minio CORS(GET) 受け入れ条件の確認 | インフラ設定（本番 S3 バケット CORS。dev は fake のため対象外） | Medium |

---

## 施策 1: 自動 DL オーケストレータ `auto-download.ts`（新規）

### 変更箇所
- ファイル: `resources/js/lib/capture/auto-download.ts`（新規）

### 責務
「`CaptureManualDetail` を受け取り → 未 DL 採用テイクを列挙 → 順次 `fetch`（body 完読）+ ACK → **ACK 成功件数を集計し戻り値で返す**」。`upload-queue.ts` と同じく **依存注入**（`videoFetcher` / `ackFetch` / `delay` / `isOnline`）でテスト可能にする。reload の実行判断は呼び出し側（Show.svelte）が戻り値 `changed` を見て行う（コールバックは設けない＝契約一本化）。

### 対象選別（列挙条件）
各カットについて、`cut.adopted_take_id === take.id` のテイクのうち次を**すべて**満たすもの:
- `take.status === "ready"`
- `take.downloaded === false`
- `take.playback_url !== null`
- `take.download_ack_token !== null`

（採用は各カット高々 1 テイク。全カットを走査し対象リストを作る。）

### 取得成功判定（厳密。Round 2/3 反映）
`videoFetcher(playback_url)` の結果を判別可能 union で返す:

```ts
type FetchOutcome =
    | { ok: true }
    | { ok: false; reason: "http"; status: number }
    | { ok: false; reason: "network" | "aborted" | "size_mismatch" };
```

- `response.ok !== true`（4xx/5xx。署名 URL 期限切れ 403 含む） → `{ ok:false, reason:"http", status: response.status }`
- **body 取得の段階化（Round 1 反映）**:
  1. `response.body` が `ReadableStream` の場合 → **`reader.read()` ループで最後まで drain**（chunk を読み捨て、`arrayBuffer()` で一括保持しない＝メモリ配慮）。読取総量 `received` を積算。
  2. `response.body === null` の場合（jsdom/古環境等で stream 非提供） → **`response.arrayBuffer()` フォールバック**で全読し `byteLength` を `received` とする（メモリ制約はコメントで明示）。
  3. `response.ok` かつ `received === 0`（`Content-Length===0` 含む）は成功許容（空応答を network 失敗と誤判定しない）。
- **size 検査（補助・条件付き）**: `Content-Encoding` ヘッダが**無く**、かつ `Content-Length` が**有効な数値**（`/^\d+$/` かつ `Number.isSafeInteger(n)`。非数値/負数/非安全整数はスキップ）として取得できる場合のみ `received !== contentLength` を `{ ok:false, reason:"size_mismatch" }` とする（`Content-Encoding` 付きは復号後サイズと転送サイズが不一致になり得るため検査しない。CORS で `Content-Length`/`Content-Encoding` が公開されず参照不能な場合は検査せず完読成功で判定）。
- **例外/中断の判別（Round 1 反映）**: `catch (e)` で `e instanceof DOMException && e.name === "AbortError"` → `{ ok:false, reason:"aborted" }`、それ以外 → `{ ok:false, reason:"network" }`。

fetch は `credentials: "omit"`・カスタムヘッダ無し（cookie 非送信 + CORS preflight 回避）。

### ACK
`ok:true` のときのみ、既存経路と同一の `POST .../takes/{take}/downloaded`（body `{ ack_token }`）を送る。ACK は `ackFetch`（既定 `captureJson`）で行い、`response.ok` を成功とする。ACK は**サーバ冪等**（`markDownloaded` が未打刻時のみ now() 打刻）。

### 状態管理（2 分離。Round 2 反映 + 墓石掃除 Round 1 反映）
オーケストレータ・インスタンスは per-take 状態を保持する:
- `fetchSucceeded: Set<number>` — fetch を完読成功した take id。同一インスタンス（＝同一セッション）で**再 fetch しない**。
- `ackPending: Set<number>` — fetch 成功済みだが ACK 未成功の take id。**再 fetch せず ACK のみ**を有界リトライ対象にする。
- fetch **失敗**の take は `fetchSucceeded` に入れない（次トリガ＝online 復帰/再入室で再取得可）。ただし 1 回の `run()` 内での再試行は有界リトライ（下記）で抑える。
- `running: boolean` — `run()` の多重起動防止（実行中は即 return し、戻り値は `{ changed:false, hasPendingAck:<現状> }`）。
- **墓石掃除（Round 1 反映）**: `run(manual)` の冒頭で「現在の対象 take ID 集合」を算出し、`fetchSucceeded`/`ackPending` から**対象集合に無い ID を除去**する（manual 更新で採用差し替え・削除された take の墓石が残らないようにする）。

### リトライ規律（有界。upload-queue と同規律）
- `maxRetries` は「**初回試行に加える再試行回数**」（総試行 = 1 + maxRetries、既定 2 → 総 3 回）。施策3 で呼び出し回数テストに固定。
- 各対象 take について: fetch → 失敗なら指数 backoff（`delay(2**attempt * baseMs)`）で総 `1 + maxRetries` 回まで。打ち切ったら次の take へ（詰ませない）。
- fetch 成功後の ACK 失敗も同様に有界リトライ。ACK 成功したら `ackPending` から除去。
- **順次（直列）**: 対象 take を 1 件ずつ処理（帯域配慮。並列 fetch しない）。

### オフライン
`run()` 冒頭で `isOnline() === false` なら**何もせず return**（fetch も ACK も呼ばない）。offline は失敗ではない。

### 戻り値（コールバックは設けず戻り値に一本化。Round 2 反映）
`run()` は `{ changed: boolean; hasPendingAck: boolean }` を返す:
- `changed` — ACK 成功が 1 件でもあれば true。呼び出し側は true のとき**のみ** `router.reload({ only:["manual"] })` を 1 回だけ行う（複数採用テイクでも reload は 1 回＝reload 過多防止）。
- `hasPendingAck` — fetch 成功済みで ACK 未達の take が残るか。将来の軽量再試行フック用（v1 は online/再入室トリガで足りるため呼び出し側は未使用でよい。短時間タイマ導入は任意・既定無効＝過剰実装回避）。

### インターフェース（案）
```ts
export type FetchOutcome =
    | { ok: true }
    | { ok: false; reason: "http"; status: number }
    | { ok: false; reason: "network" | "aborted" | "size_mismatch" };

export interface AutoDownloadOptions {
    videoFetcher?: (url: string) => Promise<FetchOutcome>; // 既定: 本物の fetch+drain 実装
    ackFetch?: typeof captureJson;                          // 既定: captureJson
    delay?: (ms: number) => Promise<void>;
    isOnline?: () => boolean;                               // 既定: () => navigator.onLine
    maxRetries?: number;                                    // 既定: 2
    baseDelayMs?: number;                                   // 既定: 1000
}

export interface AutoDownloadResult { changed: boolean; hasPendingAck: boolean; }

export class AdoptedTakeAutoDownloader {
    constructor(projectId: number, manualId: number, options?: AutoDownloadOptions);
    /** 未 DL 採用テイクを順次 fetch+ACK。判別 union の網羅性は switch + never チェックで担保 */
    async run(manual: CaptureManualDetail): Promise<AutoDownloadResult>;
}
```
（ACK の URL 生成は `TakeStrip` と同形: `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes/${takeId}/downloaded`。cut/take id は列挙時に保持。）

### 波及変更
- TypeScript 型定義: 既存 `types/capture.ts` の `CaptureManualDetail`/`CaptureCut`/`CaptureTake` を厳密に import して使用。**新規 export 型は本ファイル内 `FetchOutcome`/`AutoDownloadOptions` のみ**。`any`/疎辞書は使わない。
- API Resource/DTO: **なし**（サーバ変更なし。フィールド意味も変えない）。
- テストファイル: 施策 3（新規 `auto-download.test.ts`）。

### リスク
- フル動画 fetch の帯域コスト → 未 DL のみ・入室時＋online のみ・直列・`fetchSucceeded` で最小化。
- CORS 不備（prod S3）で全件 fetch 失敗 → 有界リトライ後スキップ、手動ボタン fallback（詰まない）。施策 6 で受け入れ条件化。

---

## 施策 2: `Capture/Show.svelte` への結線

### 変更箇所
- ファイル: `resources/js/pages/Capture/Show.svelte`（L110-141 の onMount / handleOnline 付近）

### 現行コード（抜粋）
```svelte
onMount(() => {
    void refreshPending();
    if ("serviceWorker" in navigator) { ... }
    document.addEventListener("visibilitychange", handleVisibility);
    window.addEventListener("online", handleOnline);
    return () => { ... };
});

function handleOnline(): void {
    void resumeUploads();
}
```

### 変更後（差分）
- スクリプトトップ（`<script>` はコンポーネント・インスタンス毎に 1 回実行）に `const autoDownloader = new AdoptedTakeAutoDownloader(project.id, manual.id);` を生成（既存 `const queue = new UploadQueue({store})` と同一配置・同一方針）。`project.id`/`manual.id` は**インスタンス生存中は安定**（別 manual へ遷移すると Inertia がページを remount する。`reload({only:["manual"]})` は id を変えない）旨をコメントで明記。
- `async function runAutoDownload(): Promise<void>` を追加:
  ```ts
  async function runAutoDownload(): Promise<void> {
      const { changed } = await autoDownloader.run(manual);
      if (changed) reloadManual();
  }
  ```
- `onMount` で `void runAutoDownload();` を追加（`refreshPending()` と並置）。
- `handleOnline` を `handleOnline(): void { void resumeUploads(); void runAutoDownload(); }` に拡張（online 復帰時にも自動 DL を 1 回試みる。多重起動は `AdoptedTakeAutoDownloader` の `running` ガードが抑止）。`resumeUploads` と `runAutoDownload` は**独立・順序非依存**（コメント明記。将来回帰防止）。
- **`manual` の扱い**: `run(manual)` には現在の props `manual` を渡す。`reloadManual()` 後に onMount は再実行されないが、`downloaded===true` へ更新された manual では対象が空になるため再 DL は起きない（冪等）。この再発火抑止は施策4 テストで固定する。

### 波及変更
- TypeScript 型定義: import 追加のみ（`AdoptedTakeAutoDownloader`）。Props 変更なし。
- API Resource/DTO: なし。
- テストファイル: 施策 4（`CaptureShow.test.ts` に自動 DL 結線ケース追加）。

### PHPStan 適合チェック
- 該当なし（フロントのみ。サーバ変更なし）。

### リスク
- onMount と online の二重発火 → `running` ガードで単一化。
- SSR: `AdoptedTakeAutoDownloader` インスタンス生成は副作用なし。`run()` は onMount（クライアント）でのみ呼ぶ。`navigator.onLine` 参照は `run()` 内（クライアント）に閉じる。

---

## 施策 3: 単体テスト `auto-download.test.ts`（新規）

### 変更箇所
- ファイル: `tests/js/lib/capture/auto-download.test.ts`（新規）

### テスト計画（vitest。DI で `videoFetcher`/`ackFetch`/`delay`/`isOnline` を注入）
- [ ] **対象選別**: 採用 && ready && downloaded=false && playback_url≠null && ack_token≠null のみ fetch。非採用テイク・DL 済み・非 ready・採用だが ack_token=null は対象外（fetch/ACK 呼ばれない）。
- [ ] **fetch 成功時のみ ACK**: `videoFetcher` が `{ok:true}` のとき ACK が送られ、`{ok:false, reason:"http"}`（403/404/500）や `{ok:false, reason:"network"}`（body=null / 読取中断）では ACK が送られない。
- [ ] **body 完読条件**: 実 fetch 実装のテストとして、`Response` の `body` を最後まで読み切ったときのみ成功、途中 error stream では失敗。
- [ ] **size_mismatch**: `Content-Encoding` 無し + `Content-Length` あり + 実読取量不一致 → ACK しない。`Content-Encoding: gzip` 付きは size 検査を行わず完読成功で ACK。
- [ ] **オフライン**: `isOnline=()=>false` は `videoFetcher` も `ackFetch` も呼ばず、`run()` は false を返す。
- [ ] **有界リトライ**: fetch/ACK が連続失敗しても総 `1 + maxRetries` 回で打ち切り、無限ループしない（`delay` を即時解決に差し替え、fetch/ACK 呼び出し回数が `1 + maxRetries` であることを検証）。
- [ ] **状態 2 分離**: 同一インスタンスで `run()` を 2 回呼ぶ → fetch 成功済み take は再 fetch されない（`fetchSucceeded`）。fetch 成功後 ACK だけ失敗させ、2 回目 `run()` で**再 fetch せず ACK のみ**再送されることを検証（`ackPending`）。
- [ ] **多重起動防止**: `run()` 実行中に再度 `run()` を呼んでも二重に fetch が走らない。再入した 2 回目の戻り値は `changed:false`。
- [ ] **墓石掃除**: 1 回目で対象だった take が 2 回目の manual で採用差し替え/削除され対象外化 → `fetchSucceeded`/`ackPending` から除去される（後で同 id が再採用されても誤って skip しない）。
- [ ] **戻り値**: ACK 成功が 1 件以上で `run()` の `changed` が true（1 件目 ACK 成功・2 件目失敗でも `changed:true`）。fetch 成功だが ACK 未達が残ると `hasPendingAck:true`。
- [ ] **size 検査境界**: `Content-Length` 非数値/負数は検査スキップ（`ok:true`）。`Content-Encoding: gzip` 付きは size 検査せず完読で `ok:true`。`response.body===null` + ok は `arrayBuffer()` フォールバックで成功。
- [ ] **判別 union 網羅**: `switch(reason)` の default で `never` チェック（`any` 混入予防・exhaustiveness）。
- [ ] 個別 `DatabaseTransactions` 不使用（JS テストのため非該当）。

---

## 施策 4: 結線テスト `CaptureShow.test.ts`（更新）

### 変更箇所
- ファイル: `tests/js/pages/CaptureShow.test.ts`

### テスト計画（結線責務に限定。状態機械の厳密検証は施策3 が担う旨をコメント明示）
既存の `vi.mock` パターン（`@/lib/capture/upload-queue` を stub 化しているのと同様）で `@/lib/capture/auto-download` を stub 化し、`AdoptedTakeAutoDownloader.run` を spy にする。`run` の戻り値は `{ changed, hasPendingAck }` 形。
- [ ] **入室時に自動 DL 発火**: 採用済み・未 DL テイクを持つ manual で render → onMount で `run(manual)` が呼ばれる。`run` が `{changed:true}` を解決したら `router.reload({ only:["manual"] })` が呼ばれる。
- [ ] **DL 済みは再 DL しない（再発火抑止）**: `changed:false` 解決時は reload が呼ばれないこと（reload 後 downloaded=true で対象空 → 再 DL なし、を結線として固定）。
- [ ] **online 復帰でも発火**: `window` に `online` イベントを dispatch → `run` が再度呼ばれる。
- [ ] **online ごとに起動要求**: `online` を連続 dispatch すると各回で `run` 起動要求が出る（結線責務のみを検証）。**多重実行抑止（running ガード）は施策3 の実クラス単体テストで保証**する分担とし、結線テストでは検証しない（auto-download を stub 化しており stub に running ガードは無いため）。Show 側に独立ガードは置かない（二重ガード回避）。
- [ ] 既存のカメラフォールバック系ケース（a〜e）を壊さない（`auto-download` stub 追加が enqueue 経路に干渉しないこと）。

### 波及変更
- 既存テストの `makeCut`/`makeManual` に `playback_url`/`download_ack_token`/`downloaded`/`status` を持つ採用テイクを組めるようヘルパ拡張（既存ケースの挙動は不変）。

---

## 施策 5: ドキュメント整合

### 変更箇所
- `doc/05_スマホアプリ機能仕様.md` §5.3（同期の要点）
- `docs/architecture.md`（撮影 PWA 節）

### 内容
- doc/05 §5.3 の「端末へ自動 DL」に注記を追加: 「入室時に採用済みテイクを自動取得（`fetch` で実バイト取得 → ACK）。`downloaded`（取得済み）はワークフロー単位のグローバル同期状態であり端末単位ではない」。
- `docs/architecture.md` 撮影 PWA 節に `downloaded_at` の不変条件（概念設計の定義）を明記。将来オフライン再生等で永続保存が必要になれば `downloaded_at` を流用せず別状態を設計する旨を追記。
- **両文書で同一の太字文言を統一記載**（Round 1 反映）: 「**`downloaded_at` は取得済み・同期済みを示す可用性指標であり、端末内保存・オフライン再生・ブラウザキャッシュ残存を保証しない**」。

### 波及変更
- コード変更なし（ドキュメントのみ）。

---

## 施策 6: S3/minio CORS(GET) 受け入れ条件

### 内容
- 自動 DL は `fetch(playback_url)`（本番はクロスオリジン S3 署名 URL）。**本番 S3 バケット CORS が対象 origin からの GET を許可し、GET レスポンスに適切な `Access-Control-Allow-Origin` を返す**必要がある。既に presigned **PUT**（`upload-queue.ts`）が同バケットへクロスオリジンで成立しているため、CORS の枠組みは存在。**AllowedMethods に GET を含める**ことを確認・設定する。
- **`Access-Control-Expose-Headers` の要件（Round 1 反映）**: size 検査（`Content-Length`/`Content-Encoding` 参照）を機能させるには、CORS レスポンスで **`Access-Control-Expose-Headers: Content-Length, Content-Encoding`（必要なら `ETag`）** を公開する必要がある。**未公開でも設計は degrade して成立**する（size 検査を自動スキップし `response.ok` + 完読で判定）が、size 検査を有効にしたいなら expose 設定が必須。
- `Content-Encoding` を take オブジェクトに設定しない（撮影動画はそのまま保存）ことを前提とする。付ける運用にする場合は size 検査の `Content-Encoding` 参照を諦めるか上記 expose に含める。
- 脚注（任意）: 将来 `HEAD` 診断（メタ確認）を入れる余地として **AllowedMethods に `HEAD` も許可推奨**（v1 必須ではない）。
- **dev/test は `FakeTakeObjectStorage` にバインド**（`FakeExternalsServiceProvider`）されており、vitest は `videoFetcher` を mock するため CORS は開発では非該当。本番/ステージングでの受け入れ確認項目。

### 波及変更
- リポジトリ内コード変更なし（インフラ設定 + 受け入れ確認）。設計上の受け入れ条件として明記。

---

## 使命・禁止事項チェック

- **使命寄与**: 入室時に採用済みテイクの同期状態を自動で揃え、操作者の手作業をゼロにする（思考ゼロ・編集ゼロ）。仕様（doc/05 §5.3・doc/02 §2.3）忠実性を回復し監査ギャップ #6 を解消。
- **禁止事項**: 抵触なし。サーバ変更なし（`response()->json()` 直書き・Prism・prompt 直書き・redirect intended 無関係）。テストなし完了報告をしない（施策 3/4 でテスト必須）。ボタン disabled による UX 制約もしない（自動処理・手動ボタンは残置）。
- **セキュリティ**: ACK は既存署名 `download_ack_token` 経由のみ。payload から tenant/state キーを受け取らない（`MarkTakeDownloadedRequest` が `downloaded_at` を `missing` 拒否）。`playback_url` は take スコープ署名 URL、cross-org 越境なし。新規エンドポイント・認可経路の追加なし。
- **コーディングルール**: Svelte 5 runes / 単方向 import / `@lucide/svelte`（新規アイコン追加なし）。`pnpm lint`/`typecheck`/`test`/`build` green を完了条件に含む。test-first（fail 確認）で着手。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 `Capture/Show.svelte` / `lib/capture/*` / `CaptureShow.test.ts` に対する追加・小変更で、サーバ・DB・API 契約に触れない。新規ファイル（`auto-download.ts` + そのテスト）が中心で影響範囲は撮影 PWA フロントに限定。 |
| 競合リスク | 低。撮影 PWA フロント（`resources/js/lib/capture/`・`pages/Capture/`）に閉じる。他施策との干渉可能性は小。doc 更新は独立。 |
