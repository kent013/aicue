## アプリの使命（North Star / AGENTS.md 正本）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件（AGENTS.md 正本・抜粋）
- tenant/ownership/actor キーを payload から受け取らない
- 子は親に属する（nested route の不整合は認可より前に 404）
- cross-org read/write 不可 / 権限判定は laratrust_team_id 明示

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest/vitest、RefreshDatabase グローバル適用）
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テスト）
9. セキュリティ（認可、入力バリデーション、OWASP、セキュリティ不変条件）
10. DESIGN.md 準拠（UI/frontend 変更）
11. Atomic Design 準拠（UI/frontend 変更）

【本設計の特有事情（既に概念設計 4 ラウンドで合意済み・再燃不要）】
- サーバ変更なし（既存 POST takes.downloaded と詳細 GET を変更しない）。フロントのみ。
- `downloaded_at` はワークフロー単位のグローバル同期状態（端末単位ではない）。手動 window.open と自動 fetch は同一意味。永続保存は v1 スコープ外。
- 「自動 DL」は fetch で実バイトを完読してから ACK。上記は合意済みなので、この方針の是非ではなく**詳細設計の実装的正確性・網羅性・リスク**を見てほしい。

【重点的に見てほしい点】
- `auto-download.ts` の状態管理（fetchSucceeded / ackPending / running）の設計に穴はないか（レース、二重 ACK、リトライ有界性、reload 過多）。
- body drain / size 検査（Content-Encoding 条件付き）の実装的正確性。
- Show.svelte 結線（onMount + online 復帰、SSR 安全性、reloadManual 後の再発火なし）の妥当性。
- 型安全性（any 排除、判別可能 union）。
- テスト計画の網羅性（特に状態 2 分離・オフライン・有界リトライ・結線）。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning に修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（devnotes/20260715-0021-take-auto-download-on-entry/detailed-design.md 全文）

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
「`CaptureManualDetail` を受け取り → 未 DL 採用テイクを列挙 → 順次 `fetch`（body 完読）+ ACK → 各成功で `onDownloaded` コールバック」。`upload-queue.ts` と同じく **依存注入**（`videoFetcher` / `ackFetch` / `delay` / `isOnline`）でテスト可能にする。

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
    | { ok: false; reason: "http" | "network" | "aborted" | "size_mismatch" };
```

- `response.ok !== true`（4xx/5xx。署名 URL 期限切れ 403 含む） → `{ ok:false, reason:"http" }`
- `response.body === null` → `{ ok:false, reason:"network" }`（取得失敗扱い）
- `response.body`（`ReadableStream`）を **`reader.read()` ループで最後まで drain**（chunk を読み捨て、`arrayBuffer()` で一括保持しない＝メモリ配慮）。読取総量 `received` を積算
- **size 検査（補助・条件付き）**: `Content-Encoding` ヘッダが**無く**、かつ `Content-Length` が数値として取得できる場合のみ `received !== contentLength` を `{ ok:false, reason:"size_mismatch" }` とする（`Content-Encoding` 付きは復号後サイズと転送サイズが不一致になり得るため検査しない。CORS 非公開で参照不能時は検査せず完読成功で判定）
- 読取中の例外/中断 → `{ ok:false, reason:"network" }`（or `"aborted"`）

fetch は `credentials: "omit"`・カスタムヘッダ無し（cookie 非送信 + CORS preflight 回避）。

### ACK
`ok:true` のときのみ、既存経路と同一の `POST .../takes/{take}/downloaded`（body `{ ack_token }`）を送る。ACK は `ackFetch`（既定 `captureJson`）で行い、`response.ok` を成功とする。ACK は**サーバ冪等**（`markDownloaded` が未打刻時のみ now() 打刻）。

### 状態管理（2 分離。Round 2 反映）
オーケストレータ・インスタンスは per-take 状態を保持する:
- `fetchSucceeded: Set<number>` — fetch を完読成功した take id。同一インスタンス（＝同一セッション）で**再 fetch しない**。
- `ackPending: Set<number>` — fetch 成功済みだが ACK 未成功の take id。**再 fetch せず ACK のみ**を有界リトライ対象にする。
- fetch **失敗**の take は `fetchSucceeded` に入れない（次トリガ＝online 復帰/再入室で再取得可）。ただし 1 回の `run()` 内での再試行は有界リトライ（下記）で抑える。
- `running: boolean` — `run()` の多重起動防止（実行中は即 return）。

### リトライ規律（有界。upload-queue と同規律）
- 各対象 take について: fetch → 失敗なら指数 backoff（`delay(2**attempt * baseMs)`）で最大 `maxRetries`（既定 2）回まで。打ち切ったら次の take へ（詰ませない）。
- fetch 成功後の ACK 失敗も同様に有界リトライ。ACK 成功したら `ackPending` から除去。
- **順次（直列）**: 対象 take を 1 件ずつ処理（帯域配慮。並列 fetch しない）。

### オフライン
`run()` 冒頭で `isOnline() === false` なら**何もせず return**（fetch も ACK も呼ばない）。offline は失敗ではない。

### `onDownloaded` コールバック
ACK 成功した take が 1 件でもあれば、`run()` の**最後に 1 回だけ** `onDownloaded()` を呼ぶ（呼び出し側で `router.reload({ only:["manual"] })`）。複数採用テイクでも reload は 1 回（reload 過多防止）。

### インターフェース（案）
```ts
export interface AutoDownloadOptions {
    videoFetcher?: (url: string) => Promise<FetchOutcome>; // 既定: 本物の fetch+drain 実装
    ackFetch?: typeof captureJson;                          // 既定: captureJson
    delay?: (ms: number) => Promise<void>;
    isOnline?: () => boolean;                               // 既定: () => navigator.onLine
    maxRetries?: number;                                    // 既定: 2
    baseDelayMs?: number;                                   // 既定: 1000
}

export class AdoptedTakeAutoDownloader {
    constructor(projectId: number, manualId: number, options?: AutoDownloadOptions);
    /** 未 DL 採用テイクを順次 fetch+ACK。ACK 成功が 1 件以上なら true（呼び出し側で reload） */
    async run(manual: CaptureManualDetail): Promise<boolean>;
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
- モジュールスコープに `const autoDownloader = new AdoptedTakeAutoDownloader(project.id, manual.id);` を生成（`queue` と同様の位置。`store`/`queue` の隣）。
- `async function runAutoDownload(): Promise<void>` を追加:
  ```ts
  async function runAutoDownload(): Promise<void> {
      const changed = await autoDownloader.run(manual);
      if (changed) reloadManual();
  }
  ```
- `onMount` で `void runAutoDownload();` を追加（`refreshPending()` と並置）。
- `handleOnline` を `handleOnline(): void { void resumeUploads(); void runAutoDownload(); }` に拡張（online 復帰時にも自動 DL を 1 回試みる。多重起動は `AdoptedTakeAutoDownloader.running` が抑止）。
- **`manual` の扱い**: `run(manual)` には現在の props `manual` を渡す。`reloadManual()` 後に onMount は再実行されないが、`downloaded===true` へ更新された manual では対象が空になるため再 DL は起きない（冪等）。

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
- [ ] **有界リトライ**: fetch/ACK が連続失敗しても `maxRetries` で打ち切り、無限ループしない（`delay` を即時解決に差し替え、呼び出し回数を検証）。
- [ ] **状態 2 分離**: 同一インスタンスで `run()` を 2 回呼ぶ → fetch 成功済み take は再 fetch されない（`fetchSucceeded`）。fetch 成功後 ACK だけ失敗させ、2 回目 `run()` で**再 fetch せず ACK のみ**再送されることを検証（`ackPending`）。
- [ ] **多重起動防止**: `run()` 実行中に再度 `run()` を呼んでも二重に fetch が走らない。
- [ ] **onDownloaded/戻り値**: ACK 成功が 1 件以上で `run()` が true を返す（複数テイクでも呼び出し側 reload は 1 回想定）。
- [ ] 個別 `DatabaseTransactions` 不使用（JS テストのため非該当）。

---

## 施策 4: 結線テスト `CaptureShow.test.ts`（更新）

### 変更箇所
- ファイル: `tests/js/pages/CaptureShow.test.ts`

### テスト計画
既存の `vi.mock` パターン（`@/lib/capture/upload-queue` を stub 化しているのと同様）で `@/lib/capture/auto-download` を stub 化し、`AdoptedTakeAutoDownloader.run` を spy にする。
- [ ] **入室時に自動 DL 発火**: 採用済み・未 DL テイクを持つ manual で render → onMount で `run(manual)` が呼ばれる。`run` が true を解決したら `router.reload({ only:["manual"] })` が呼ばれる。
- [ ] **DL 済みは再 DL しない**: `downloaded=true` の採用テイクのみの manual → `run` は呼ばれるが（stub の実挙動として）ACK/reload は発生しない結線であることを確認（`run` false 解決時 reload なし）。
- [ ] **online 復帰でも発火**: `window` に `online` イベントを dispatch → `run` が再度呼ばれる。
- [ ] 既存のカメラフォールバック系ケース（a〜e）を壊さない（`auto-download` stub 追加が enqueue 経路に干渉しないこと）。

### 波及変更
- 既存テストの `makeCut`/`makeManual` に `playback_url`/`download_ack_token`/`downloaded`/`status` を持つ採用テイクを組めるようヘルパ拡張（既存ケースの挙動は不変）。

---

## 施策 5: ドキュメント整合

### 変更箇所
- `doc/05_スマホアプリ機能仕様.md` §5.3（同期の要点）
- `docs/architecture.md`（撮影 PWA 節）

### 内容
- doc/05 §5.3 の「端末へ自動 DL」に注記を追加: 「入室時に採用済みテイクを自動取得（`fetch` で実バイト取得 → ACK）。`downloaded`（取得済み）はワークフロー単位のグローバル同期状態であり端末単位ではない。**オフライン再生・端末内ファイル保存・ブラウザキャッシュ残存は保証しない**」。
- `docs/architecture.md` 撮影 PWA 節に `downloaded_at` の不変条件（概念設計の定義）を明記。将来オフライン再生等で永続保存が必要になれば `downloaded_at` を流用せず別状態を設計する旨を追記。

### 波及変更
- コード変更なし（ドキュメントのみ）。

---

## 施策 6: S3/minio CORS(GET) 受け入れ条件

### 内容
- 自動 DL は `fetch(playback_url)`（本番はクロスオリジン S3 署名 URL）。**本番 S3 バケット CORS が対象 origin からの GET を許可し、GET レスポンスに適切な `Access-Control-Allow-Origin` を返す**必要がある。既に presigned **PUT**（`upload-queue.ts`）が同バケットへクロスオリジンで成立しているため、CORS の枠組みは存在。**AllowedMethods に GET を含める**ことを確認・設定する。
- `Content-Encoding` を take オブジェクトに設定しない（撮影動画はそのまま保存）ことを前提とする。付ける運用にする場合は size 検査の `Content-Encoding` 参照を諦めるか `Access-Control-Expose-Headers` に含める。
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


## 関連する現行コード

### resources/js/pages/Capture/Show.svelte（抜粋: onMount/handleOnline/queue 生成）
```svelte
const store: PendingStore = createIdbPendingStore();
const queue = new UploadQueue({ store });
...
function reloadManual(): void { router.reload({ only: ["manual"] }); }
...
onMount(() => {
    void refreshPending();
    if ("serviceWorker" in navigator) {
        void navigator.serviceWorker.register("/capture-sw.js");
        navigator.serviceWorker.addEventListener("message", handleSwMessage);
    }
    document.addEventListener("visibilitychange", handleVisibility);
    window.addEventListener("online", handleOnline);
    return () => { ... };
});
function handleOnline(): void { void resumeUploads(); }
```

### TakeStrip.svelte の手動 DL（同一 ACK 経路の参照）
```ts
async function downloadAndAck(take: CaptureTake): Promise<void> {
    if (take.playback_url === null || take.download_ack_token === null) {
        error = "この端末からダウンロードできるのは採用テイクのみです。"; return;
    }
    window.open(take.playback_url, "_blank", "noopener");
    await run(take, () =>
        captureJson(takeUrl(take, "/downloaded"), "POST", { ack_token: take.download_ack_token }),
    );
}
// takeUrl(take, "/downloaded") = /app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}/downloaded
```

### types/capture.ts（CaptureTake / CaptureCut / CaptureManualDetail）
```ts
export interface CaptureTake {
    id: number; client_take_id: string; status: TakeStatus; size_bytes: number;
    duration_ms: number | null; comment: string | null; captured_at: string | null;
    sort_order: number; downloaded: boolean;
    playback_url: string | null;        // 採用テイクのみ非 null
    download_ack_token: string | null;  // 採用テイクのみ非 null
}
export interface CaptureCut { id: number; ...; adopted_take_id: number | null; takes: CaptureTake[]; }
export interface CaptureManualDetail { id: number; title: string; status: string; cuts: CaptureCut[]; }
```

### lib/capture/http.ts（captureFetch / captureJson。ACK に使う）
```ts
export async function captureFetch(url, init = {}, retried = false): Promise<Response> { /* X-XSRF-TOKEN 付与, 419 再取得 */ }
export async function captureJson(url, method, body?): Promise<Response> {
    return captureFetch(url, { method, headers: { "Content-Type": "application/json" }, body: body===undefined?undefined:JSON.stringify(body) });
}
```

### サーバ ACK（変更しない。参照のみ）
`POST .../takes/{take}/downloaded` → `CaptureTakeController::markDownloaded` → `Gate::authorize('markDownloaded')` → `CaptureTakeService::markDownloaded`（ack_token 検証 → downloaded_at 未打刻なら now() 打刻、再送は冪等 no-op）。`MarkTakeDownloadedRequest` は `downloaded_at` を `missing` で拒否。
