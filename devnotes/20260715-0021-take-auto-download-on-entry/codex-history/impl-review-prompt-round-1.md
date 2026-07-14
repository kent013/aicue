## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel + Svelte アプリの**実装レビュアー**である。TODO T051「撮影詳細入室時の採用済みテイク自動ダウンロード」の実装差分を、以下の観点でレビューせよ。

レビュー観点:
1. **設計との一致性**: 添付の詳細設計書（detailed-design.md）に忠実か。対象選別条件・取得成功判定・状態 2 分離・墓石掃除・有界リトライ・戻り値契約が設計どおりか。
2. **正確性**: ロジックの誤り・エッジケース漏れ（size 検査境界・空応答・AbortError・多重起動・ACK 冪等）。
3. **TypeScript / 型安全**: `any`/疎辞書の混入がないか、判別 union の網羅性（never チェック）が機能しているか。
4. **テスト網羅性**: 施策 3/4 のテストが対象選別・fetch/ACK 条件・リトライ・状態分離・多重起動防止・墓石掃除・戻り値・既定 fetcher の完読判定を十分カバーしているか。テストが実装を固定しているか。
5. **セキュリティ**: サーバ変更なし（既存 ACK 経路のみ）。payload から tenant/state キーを受け取らない。`credentials:"omit"` の妥当性。
6. **Atomic Design / DESIGN.md 準拠**: 本変更は `lib/capture/` のロジック + `pages/Capture/Show.svelte` の結線 + docs。新規 UI コンポーネント・token 変更・SVG 直書き・hex 直書きは無い想定。逸脱があれば指摘せよ。
7. **Svelte 5 runes**: props を top-level で読む `state_referenced_locally` を `svelte-ignore` で明示抑止している（mount 時点の id 固定が意図）。妥当か。

出力形式:
- ファイルごとに判定を述べる
- 指摘は **[Critical]（必ず修正）/ [Warning]（検討）/ [Suggestion]（任意）** に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する

---

## user: レビュー対象

### テスト結果

- `pnpm typecheck`: OK (tsc --noEmit エラーなし)
- `pnpm lint`: OK
- `pnpm build`: OK (svelte 警告なし)
- `pnpm test`: 全 677 passed（新規 auto-download.test.ts 22 + CaptureShow.test.ts 拡張含む）
- PHP 変更なし（サーバ変更なし）のため composer test / phpstan / pint は非該当

### 詳細設計書（detailed-design.md）

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


### 実装差分（git diff HEAD）

```diff
diff --git "a/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md" "b/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md"
index a408d6a..f3807d9 100644
--- "a/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md"
+++ "b/doc/05_\343\202\271\343\203\236\343\203\233\343\202\242\343\203\227\343\203\252\346\251\237\350\203\275\344\273\225\346\247\230.md"
@@ -62,8 +62,9 @@ ### アカウント画面
 
 ## 5.3 同期の要点（重複防止ロジック）
 
-- 詳細画面遷移時: サーバーの採用済みテイクを端末へ**自動 DL**（DL 済み枠は色/枠線で区別）。
+- 詳細画面遷移時: サーバーの採用済みテイクを端末へ**自動 DL**（入室時に `fetch` で実バイトを取得 → ACK `POST .../downloaded`。DL 済み枠は色/枠線で区別）。online 復帰時にも一度試みる。
 - アップロード時: **新規撮影テイクのみ**送信。DL 済みテイクは自動的に除外。
+- **`downloaded_at` は取得済み・同期済みを示す可用性指標であり、端末内保存・オフライン再生・ブラウザキャッシュ残存を保証しない**（ワークフロー単位のグローバル同期状態であり端末単位ではない。手動 DL ボタンと同一意味・同一 ACK 経路）。
 - → PC ↔ アプリ間でテイクが二重登録されない設計。詳細は [02_システム全体像.md](02_システム全体像.md#23-テイクとカットの関係連携の核)。
 
 ## 5.4 検討中の論点（資料内メモ）
diff --git a/docs/architecture.md b/docs/architecture.md
index 12b61bd..f561630 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -273,6 +273,15 @@ ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
 - **DL 済み削除不可 (D6)**: 詳細 GET が採用テイクの署名 DL URL と同時に発行する ACK トークン
   (Crypt 封緘・同 TTL) を `POST .../takes/{take}/downloaded` が検証して `takes.downloaded_at` を
   打刻する。非 null のテイクは DELETE 422
+- **入室時の採用テイク自動 DL (T051)**: `pages/Capture/Show.svelte` が mount 時 (と online 復帰時) に
+  `lib/capture/auto-download.ts` の `AdoptedTakeAutoDownloader` を起動し、採用 && ready && 未 DL の
+  テイクを順次 `fetch(playback_url, {credentials:"omit"})` で実バイト完読 → 上記 ACK 経路へ送る
+  (サーバ変更なし・既存 ACK と同一冪等打刻)。手動 DL ボタンと同一意味。**`downloaded_at` は取得済み・
+  同期済みを示す可用性指標であり、端末内保存・オフライン再生・ブラウザキャッシュ残存を保証しない**
+  (ワークフロー単位のグローバル同期状態であり端末単位ではない)。将来オフライン再生等で永続保存が
+  必要になれば `downloaded_at` を流用せず別状態を設計する。本番 S3 は署名 URL への CORS GET 許可
+  (`AllowedMethods` に GET、size 検査を使うなら `Access-Control-Expose-Headers: Content-Length,
+  Content-Encoding`) が受け入れ条件 (未公開でも size 検査を自動スキップして degrade 成立)
 - **PWA フロント**: `pages/Capture/*` + `features/capture/*` + `lib/capture/*`
   (即時アップロード優先・IndexedDB は失敗/オフライン時の一時バッファ・419 は csrf-cookie
   再取得 1 回リトライ)。SW (`public/capture-sw.js`) は同一オリジン GET `/build/*` のみ
diff --git a/resources/js/lib/capture/auto-download.ts b/resources/js/lib/capture/auto-download.ts
new file mode 100644
index 0000000..d7ddc8f
--- /dev/null
+++ b/resources/js/lib/capture/auto-download.ts
@@ -0,0 +1,275 @@
+import { captureJson } from "@/lib/capture/http";
+import type { CaptureManualDetail } from "@/types/capture";
+
+/**
+ * 採用済みテイクの入室時自動ダウンロード・オーケストレータ (T051 / 概念設計 D6)。
+ *
+ * 責務: `CaptureManualDetail` を受け取り → 未 DL の採用テイクを列挙 → 順次
+ * `videoFetcher`(実バイト完読) + ACK (`POST .../downloaded`) を行い、ACK 成功件数を
+ * 戻り値 `changed` に集約する。reload の実行判断は呼び出し側 (Show.svelte) が `changed` を
+ * 見て行う (コールバックは設けず戻り値に一本化)。
+ *
+ * サーバ変更なし: 既存 `POST takes.downloaded` (ACK・冪等) と詳細 GET payload を変更しない。
+ * `downloaded_at` はワークフロー単位のグローバル同期状態であり端末単位ではない
+ * (手動 window.open と同一意味・同一 ACK 経路)。
+ *
+ * `upload-queue.ts` と同じく依存注入 (`videoFetcher`/`ackFetch`/`delay`/`isOnline`) で
+ * テスト可能にする。
+ */
+
+/** videoFetcher の判別可能 union (取得成功/各種失敗を厳密に区別する) */
+export type FetchOutcome =
+    | { ok: true }
+    | { ok: false; reason: "http"; status: number }
+    | { ok: false; reason: "network" | "aborted" | "size_mismatch" };
+
+export interface AutoDownloadOptions {
+    /** 既定: 本物の fetch + body 完読実装 (fetchAndDrain) */
+    videoFetcher?: (url: string) => Promise<FetchOutcome>;
+    /** 既定: captureJson (X-XSRF-TOKEN 付き・419 再取得は http.ts が担う) */
+    ackFetch?: typeof captureJson;
+    /** backoff 待機 (テストで即時解決に差し替える) */
+    delay?: (ms: number) => Promise<void>;
+    /** navigator.onLine の参照 (テスト差し替え用) */
+    isOnline?: () => boolean;
+    /** 初回試行に加える再試行回数 (総試行 = 1 + maxRetries)。既定 2 → 総 3 回 */
+    maxRetries?: number;
+    /** 指数 backoff の基準ミリ秒 (delay(2**attempt * baseDelayMs)) */
+    baseDelayMs?: number;
+}
+
+export interface AutoDownloadResult {
+    /** ACK 成功が 1 件でもあれば true (呼び出し側はこの時のみ reload を 1 回行う) */
+    changed: boolean;
+    /** fetch 成功済みで ACK 未達の take が残るか (将来の軽量再試行フック用) */
+    hasPendingAck: boolean;
+}
+
+/** 列挙で確定した DL 対象 (playback_url / ack_token は非 null に絞り込み済み) */
+interface DownloadTarget {
+    cutId: number;
+    takeId: number;
+    playbackUrl: string;
+    ackToken: string;
+}
+
+/**
+ * 本物の fetch + body 完読実装 (既定 videoFetcher)。
+ * - `credentials: "omit"` + カスタムヘッダ無し (cookie 非送信 + CORS preflight 回避)。
+ * - body は ReadableStream を drain (chunk 読み捨て・一括保持しない = メモリ配慮)。
+ *   stream 非提供環境は arrayBuffer() フォールバック。
+ * - size 検査は Content-Encoding 無し + Content-Length 有効数値のときのみ (補助・条件付き)。
+ */
+async function fetchAndDrain(url: string): Promise<FetchOutcome> {
+    let response: Response;
+    try {
+        response = await fetch(url, { credentials: "omit" });
+    } catch (error) {
+        return toFailureReason(error);
+    }
+    if (!response.ok) {
+        return { ok: false, reason: "http", status: response.status };
+    }
+
+    let received: number;
+    try {
+        received = await drainBody(response);
+    } catch (error) {
+        return toFailureReason(error);
+    }
+
+    // size 検査 (補助): Content-Encoding 付きは復号後サイズと転送サイズが不一致になり得るため検査しない。
+    const encoding = response.headers.get("Content-Encoding");
+    if (encoding === null || encoding === "") {
+        const lengthHeader = response.headers.get("Content-Length");
+        // 非数値/負数 (先頭 - を含む) は /^\d+$/ で除外、非安全整数は isSafeInteger で除外
+        if (lengthHeader !== null && /^\d+$/.test(lengthHeader)) {
+            const contentLength = Number(lengthHeader);
+            if (Number.isSafeInteger(contentLength) && received !== contentLength) {
+                return { ok: false, reason: "size_mismatch" };
+            }
+        }
+    }
+    return { ok: true };
+}
+
+/** response body を最後まで読み切り、総バイト数を返す (空応答=0 も成功許容) */
+async function drainBody(response: Response): Promise<number> {
+    if (response.body === null) {
+        // jsdom/古環境等で stream 非提供: arrayBuffer() で全読 (メモリ制約あり)
+        const buffer = await response.arrayBuffer();
+        return buffer.byteLength;
+    }
+    const reader = response.body.getReader();
+    let received = 0;
+    for (;;) {
+        const { done, value } = await reader.read();
+        if (done) break;
+        if (value !== undefined) received += value.byteLength;
+    }
+    return received;
+}
+
+/** 例外/中断を判別 union へ変換 (AbortError のみ aborted、他は network) */
+function toFailureReason(error: unknown): FetchOutcome {
+    if (error instanceof DOMException && error.name === "AbortError") {
+        return { ok: false, reason: "aborted" };
+    }
+    return { ok: false, reason: "network" };
+}
+
+export class AdoptedTakeAutoDownloader {
+    private readonly projectId: number;
+    private readonly manualId: number;
+    private readonly videoFetcher: (url: string) => Promise<FetchOutcome>;
+    private readonly ackFetch: typeof captureJson;
+    private readonly delay: (ms: number) => Promise<void>;
+    private readonly isOnline: () => boolean;
+    private readonly maxRetries: number;
+    private readonly baseDelayMs: number;
+
+    /** fetch を完読成功した take id (同一セッションで再 fetch しない) */
+    private readonly fetchSucceeded = new Set<number>();
+    /** fetch 成功済みだが ACK 未成功の take id (再 fetch せず ACK のみ再試行対象) */
+    private readonly ackPending = new Set<number>();
+    /** run() の多重起動防止 (onMount と online 復帰の二重発火を単一化) */
+    private running = false;
+
+    constructor(projectId: number, manualId: number, options: AutoDownloadOptions = {}) {
+        this.projectId = projectId;
+        this.manualId = manualId;
+        this.videoFetcher = options.videoFetcher ?? fetchAndDrain;
+        this.ackFetch = options.ackFetch ?? captureJson;
+        this.delay = options.delay ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
+        this.isOnline = options.isOnline ?? (() => navigator.onLine);
+        this.maxRetries = options.maxRetries ?? 2;
+        this.baseDelayMs = options.baseDelayMs ?? 1000;
+    }
+
+    /** 未 DL 採用テイクを順次 fetch+ACK。ACK 成功が 1 件でもあれば changed=true */
+    async run(manual: CaptureManualDetail): Promise<AutoDownloadResult> {
+        // 多重起動防止: 実行中は即 return (二重に fetch を走らせない)
+        if (this.running) {
+            return { changed: false, hasPendingAck: this.ackPending.size > 0 };
+        }
+        // オフラインは失敗ではない: fetch も ACK も呼ばず return
+        if (!this.isOnline()) {
+            return { changed: false, hasPendingAck: this.ackPending.size > 0 };
+        }
+
+        this.running = true;
+        try {
+            const targets = this.collectTargets(manual);
+            this.sweepTombstones(targets);
+
+            let changed = false;
+            // 順次 (直列): 帯域配慮のため 1 件ずつ処理 (並列 fetch しない)
+            for (const target of targets) {
+                if (await this.processTarget(target)) {
+                    changed = true;
+                }
+            }
+            return { changed, hasPendingAck: this.ackPending.size > 0 };
+        } finally {
+            this.running = false;
+        }
+    }
+
+    /** 採用 && ready && 未 DL && playback_url≠null && ack_token≠null のテイクを列挙 */
+    private collectTargets(manual: CaptureManualDetail): DownloadTarget[] {
+        const targets: DownloadTarget[] = [];
+        for (const cut of manual.cuts) {
+            if (cut.adopted_take_id === null) continue;
+            for (const take of cut.takes) {
+                if (take.id !== cut.adopted_take_id) continue;
+                if (take.status !== "ready") continue;
+                if (take.downloaded) continue;
+                if (take.playback_url === null) continue;
+                if (take.download_ack_token === null) continue;
+                targets.push({
+                    cutId: cut.id,
+                    takeId: take.id,
+                    playbackUrl: take.playback_url,
+                    ackToken: take.download_ack_token,
+                });
+            }
+        }
+        return targets;
+    }
+
+    /**
+     * 墓石掃除: 現在の対象集合に無い take id を fetchSucceeded/ackPending から除去する
+     * (manual 更新で採用差し替え・削除された take の墓石が残らないようにする)。
+     */
+    private sweepTombstones(targets: DownloadTarget[]): void {
+        const targetIds = new Set(targets.map((target) => target.takeId));
+        for (const id of [...this.fetchSucceeded]) {
+            if (!targetIds.has(id)) this.fetchSucceeded.delete(id);
+        }
+        for (const id of [...this.ackPending]) {
+            if (!targetIds.has(id)) this.ackPending.delete(id);
+        }
+    }
+
+    /** 1 件の対象を fetch(未成功時) → ACK。ACK 成功で true (changed 集計用) */
+    private async processTarget(target: DownloadTarget): Promise<boolean> {
+        if (!this.fetchSucceeded.has(target.takeId)) {
+            const fetched = await this.fetchWithRetry(target.playbackUrl);
+            if (!fetched) {
+                // fetch 失敗: fetchSucceeded に入れない (次トリガ=online/再入室で再取得可)
+                return false;
+            }
+            this.fetchSucceeded.add(target.takeId);
+            this.ackPending.add(target.takeId);
+        }
+        // 既に ACK 済み (ackPending から除去済み) なら再 ACK しない
+        if (!this.ackPending.has(target.takeId)) {
+            return false;
+        }
+        if (await this.ackWithRetry(target)) {
+            this.ackPending.delete(target.takeId);
+            return true;
+        }
+        return false;
+    }
+
+    /** videoFetcher を有界リトライ (総 1 + maxRetries 回)。完読成功で true */
+    private async fetchWithRetry(url: string): Promise<boolean> {
+        for (let attempt = 0; ; attempt++) {
+            const outcome = await this.videoFetcher(url);
+            if (outcome.ok) return true;
+            // 判別 union の網羅性を switch + never チェックで担保 (any 混入予防)
+            switch (outcome.reason) {
+                case "http":
+                case "network":
+                case "aborted":
+                case "size_mismatch":
+                    break;
+                default: {
+                    // outcome は default で never に narrow 済み (判別 union 網羅性の担保)
+                    const exhaustive: never = outcome;
+                    return exhaustive;
+                }
+            }
+            if (attempt >= this.maxRetries) return false;
+            await this.delay(2 ** attempt * this.baseDelayMs);
+        }
+    }
+
+    /** ACK を有界リトライ (総 1 + maxRetries 回)。response.ok で true */
+    private async ackWithRetry(target: DownloadTarget): Promise<boolean> {
+        const url = `/app/projects/${this.projectId}/manuals/${this.manualId}/cuts/${target.cutId}/takes/${target.takeId}/downloaded`;
+        for (let attempt = 0; ; attempt++) {
+            let ok = false;
+            try {
+                const response = await this.ackFetch(url, "POST", { ack_token: target.ackToken });
+                ok = response.ok;
+            } catch {
+                ok = false;
+            }
+            if (ok) return true;
+            if (attempt >= this.maxRetries) return false;
+            await this.delay(2 ** attempt * this.baseDelayMs);
+        }
+    }
+}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 03dcc43..6b3e744 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -10,6 +10,7 @@
     import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
     import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
+    import { AdoptedTakeAutoDownloader } from "@/lib/capture/auto-download";
     import { supportsMediaRecorder } from "@/lib/capture/camera";
     import type { CameraUnavailableReason } from "@/lib/capture/camera";
     import { createIdbPendingStore } from "@/lib/capture/idb";
@@ -55,6 +56,13 @@
     /* ---- アップロードキュー ---- */
     const store: PendingStore = createIdbPendingStore();
     const queue = new UploadQueue({ store });
+
+    /* ---- 採用済みテイクの自動 DL (T051) ----
+     * project.id / manual.id はインスタンス生存中は安定 (別 manual へ遷移すると Inertia が
+     * ページを remount する。reload({only:["manual"]}) は id を変えない)。mount 時点の値で
+     * 確定させるのが意図どおりなので state_referenced_locally を明示的に無視する。 */
+    // svelte-ignore state_referenced_locally
+    const autoDownloader = new AdoptedTakeAutoDownloader(project.id, manual.id);
     let pendingCount = $state(0);
     let pendingBytes = $state(0);
     let uploading = $state(false);
@@ -94,6 +102,14 @@
         }
     }
 
+    // 入室時 / online 復帰時に採用済み未 DL テイクを自動取得する。changed のときのみ
+    // reload を 1 回行う (複数採用テイクでも reload は 1 回)。多重発火は内部 running ガードが抑止。
+    // reload 後は downloaded=true で対象が空になるため再 DL は起きない (冪等)。
+    async function runAutoDownload(): Promise<void> {
+        const { changed } = await autoDownloader.run(manual);
+        if (changed) reloadManual();
+    }
+
     async function resumeUploads(): Promise<void> {
         uploading = true;
         try {
@@ -109,6 +125,7 @@
 
     onMount(() => {
         void refreshPending();
+        void runAutoDownload();
 
         // SW 登録 (Capture ページ mount 時に限定。素の JS・/build/* のみキャッシュ)
         if ("serviceWorker" in navigator) {
@@ -133,7 +150,9 @@
     }
 
     function handleOnline(): void {
+        // resumeUploads と runAutoDownload は独立・順序非依存 (将来回帰防止のため明記)
         void resumeUploads();
+        void runAutoDownload();
     }
 
     function handleSwMessage(event: MessageEvent): void {
diff --git a/tests/js/lib/capture/auto-download.test.ts b/tests/js/lib/capture/auto-download.test.ts
new file mode 100644
index 0000000..9c3b459
--- /dev/null
+++ b/tests/js/lib/capture/auto-download.test.ts
@@ -0,0 +1,462 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import {
+    AdoptedTakeAutoDownloader,
+    type AutoDownloadOptions,
+    type FetchOutcome,
+} from "@/lib/capture/auto-download";
+import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/capture";
+
+/*
+ * 採用済みテイクの入室時自動 DL オーケストレータ (T051 / D6):
+ * - 対象選別 (採用 && ready && 未 DL && playback_url≠null && ack_token≠null のみ)
+ * - fetch 完読成功時のみ ACK / 有界リトライ / 状態 2 分離 / 多重起動防止 / 墓石掃除
+ * DI (videoFetcher/ackFetch/delay/isOnline) で HTTP を持ち込まずロジックのみ検証する。
+ */
+
+function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
+    return {
+        id: 11,
+        client_take_id: "01J0AUTODL",
+        status: "ready",
+        size_bytes: 1024,
+        duration_ms: 4200,
+        comment: null,
+        captured_at: "2026-07-11T00:00:00Z",
+        sort_order: 0,
+        downloaded: false,
+        playback_url: "https://s3.example.test/take-11.mp4?sig=1",
+        download_ack_token: "ack-token-11",
+        ...overrides,
+    };
+}
+
+function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
+    const takes = overrides.takes ?? [makeTake()];
+    return {
+        id: 101,
+        type: "step",
+        parent_cut_id: null,
+        scene: "ネジを締める",
+        shot_type: "hiki",
+        shooting_point: "手元",
+        narration: "ドライバーでネジを締めます",
+        subtitle_primary: null,
+        subtitle_secondary: "",
+        adopted_take_id: takes[0]?.id ?? null,
+        takes,
+        ...overrides,
+    };
+}
+
+function makeManual(cuts: CaptureCut[] = [makeCut()]): CaptureManualDetail {
+    return { id: 5, title: "ネジ締め作業", status: "ready", cuts };
+}
+
+function okResponse(): Response {
+    return new Response(null, { status: 200 });
+}
+
+/** delay を即時解決に差し替えた既定オプション + spy を返す */
+function makeDeps(overrides: Partial<AutoDownloadOptions> = {}) {
+    const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
+    const ackFetch = vi.fn(async () => okResponse());
+    const delay = vi.fn(async () => {});
+    const isOnline = vi.fn(() => true);
+    const options: AutoDownloadOptions = {
+        videoFetcher,
+        ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
+        delay,
+        isOnline,
+        ...overrides,
+    };
+    return { videoFetcher, ackFetch, delay, isOnline, options };
+}
+
+function makeDownloader(overrides: Partial<AutoDownloadOptions> = {}) {
+    const deps = makeDeps(overrides);
+    const downloader = new AdoptedTakeAutoDownloader(1, 5, deps.options);
+    return { downloader, ...deps };
+}
+
+describe("AdoptedTakeAutoDownloader 対象選別", () => {
+    it("採用 && ready && 未 DL && playback_url≠null && ack_token≠null のみ fetch+ACK する", async () => {
+        const { downloader, videoFetcher, ackFetch } = makeDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(videoFetcher).toHaveBeenCalledTimes(1);
+        expect(videoFetcher).toHaveBeenCalledWith("https://s3.example.test/take-11.mp4?sig=1");
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+        expect(ackFetch).toHaveBeenCalledWith(
+            "/app/projects/1/manuals/5/cuts/101/takes/11/downloaded",
+            "POST",
+            { ack_token: "ack-token-11" },
+        );
+        expect(result).toEqual({ changed: true, hasPendingAck: false });
+    });
+
+    it("非採用・DL 済み・非 ready・ack_token=null は対象外 (fetch も ACK もされない)", async () => {
+        const cut = makeCut({
+            adopted_take_id: 11,
+            takes: [
+                makeTake({ id: 11, downloaded: true }), // 採用だが DL 済み
+                makeTake({ id: 12 }), // 非採用
+                makeTake({ id: 13, status: "processing" }), // 非採用かつ非 ready
+            ],
+        });
+        const nonReadyAdopted = makeCut({
+            id: 102,
+            adopted_take_id: 21,
+            takes: [makeTake({ id: 21, status: "processing" })],
+        });
+        const nullToken = makeCut({
+            id: 103,
+            adopted_take_id: 31,
+            takes: [makeTake({ id: 31, download_ack_token: null })],
+        });
+        const nullPlayback = makeCut({
+            id: 104,
+            adopted_take_id: 41,
+            takes: [makeTake({ id: 41, playback_url: null })],
+        });
+        const { downloader, videoFetcher, ackFetch } = makeDownloader();
+
+        const result = await downloader.run(makeManual([cut, nonReadyAdopted, nullToken, nullPlayback]));
+
+        expect(videoFetcher).not.toHaveBeenCalled();
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result).toEqual({ changed: false, hasPendingAck: false });
+    });
+});
+
+describe("AdoptedTakeAutoDownloader fetch/ACK 条件", () => {
+    it("fetch 失敗 (http 403) では ACK を送らず changed=false", async () => {
+        const { downloader, ackFetch } = makeDownloader({
+            videoFetcher: vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
+                ok: false,
+                reason: "http",
+                status: 403,
+            })),
+        });
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result).toEqual({ changed: false, hasPendingAck: false });
+    });
+
+    it("fetch 失敗 (network) でも ACK を送らない", async () => {
+        const { downloader, ackFetch } = makeDownloader({
+            videoFetcher: vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
+                ok: false,
+                reason: "network",
+            })),
+        });
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result.changed).toBe(false);
+    });
+});
+
+describe("AdoptedTakeAutoDownloader オフライン", () => {
+    it("isOnline=false は videoFetcher も ackFetch も呼ばず changed=false", async () => {
+        const { downloader, videoFetcher, ackFetch } = makeDownloader({ isOnline: () => false });
+
+        const result = await downloader.run(makeManual());
+
+        expect(videoFetcher).not.toHaveBeenCalled();
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result).toEqual({ changed: false, hasPendingAck: false });
+    });
+});
+
+describe("AdoptedTakeAutoDownloader 有界リトライ", () => {
+    it("fetch 連続失敗は総 1 + maxRetries 回で打ち切る (既定 3 回)", async () => {
+        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
+            ok: false,
+            reason: "network",
+        }));
+        const { downloader, delay } = makeDownloader({ videoFetcher });
+
+        const result = await downloader.run(makeManual());
+
+        expect(videoFetcher).toHaveBeenCalledTimes(3);
+        expect(delay).toHaveBeenCalledTimes(2); // 試行間の待機は 2 回
+        expect(result.changed).toBe(false);
+    });
+
+    it("maxRetries=0 は総 1 回で打ち切る", async () => {
+        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
+            ok: false,
+            reason: "network",
+        }));
+        const { downloader } = makeDownloader({ videoFetcher, maxRetries: 0 });
+
+        await downloader.run(makeManual());
+
+        expect(videoFetcher).toHaveBeenCalledTimes(1);
+    });
+
+    it("ACK 連続失敗も総 1 + maxRetries 回で打ち切り、無限ループしない", async () => {
+        const ackFetch = vi.fn(async () => new Response(null, { status: 500 }));
+        const { downloader, delay } = makeDownloader({
+            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
+        });
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).toHaveBeenCalledTimes(3);
+        expect(delay).toHaveBeenCalledTimes(2);
+        expect(result).toEqual({ changed: false, hasPendingAck: true });
+    });
+});
+
+describe("AdoptedTakeAutoDownloader 状態 2 分離", () => {
+    it("fetch 成功済み take は 2 回目 run で再 fetch しない (fetchSucceeded)", async () => {
+        const { downloader, videoFetcher, ackFetch } = makeDownloader();
+
+        await downloader.run(makeManual());
+        // reload されないケースを模し、同一 (未 DL) manual で再度 run しても再 fetch されない
+        await downloader.run(makeManual());
+
+        expect(videoFetcher).toHaveBeenCalledTimes(1);
+        // 1 回目で ACK 成功済み → ackPending から除去済みなので再 ACK もしない
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+    });
+
+    it("fetch 成功後 ACK だけ失敗 → 2 回目 run は再 fetch せず ACK のみ再送する (ackPending)", async () => {
+        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
+        let ackShouldFail = true;
+        const ackFetch = vi.fn(async () =>
+            ackShouldFail ? new Response(null, { status: 500 }) : okResponse(),
+        );
+        const { downloader } = makeDownloader({
+            videoFetcher,
+            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
+        });
+
+        const first = await downloader.run(makeManual());
+        expect(first).toEqual({ changed: false, hasPendingAck: true });
+        expect(videoFetcher).toHaveBeenCalledTimes(1);
+        const ackCallsAfterFirst = ackFetch.mock.calls.length; // 有界リトライ分
+
+        ackShouldFail = false;
+        const second = await downloader.run(makeManual());
+
+        expect(videoFetcher).toHaveBeenCalledTimes(1); // 再 fetch しない
+        expect(ackFetch.mock.calls.length).toBe(ackCallsAfterFirst + 1); // ACK のみ 1 回で成功
+        expect(second).toEqual({ changed: true, hasPendingAck: false });
+    });
+});
+
+describe("AdoptedTakeAutoDownloader 多重起動防止", () => {
+    it("run 実行中に再度 run を呼んでも二重 fetch せず、再入は changed=false", async () => {
+        let resolveFetch: (o: FetchOutcome) => void = () => {};
+        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(
+            () => new Promise<FetchOutcome>((resolve) => (resolveFetch = resolve)),
+        );
+        const { downloader, ackFetch } = makeDownloader({ videoFetcher });
+
+        const firstPromise = downloader.run(makeManual());
+        const reentrant = await downloader.run(makeManual()); // 実行中の再入
+
+        expect(reentrant).toEqual({ changed: false, hasPendingAck: false });
+        expect(videoFetcher).toHaveBeenCalledTimes(1);
+
+        resolveFetch({ ok: true });
+        const first = await firstPromise;
+        expect(first.changed).toBe(true);
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+    });
+});
+
+describe("AdoptedTakeAutoDownloader 墓石掃除", () => {
+    it("2 回目 manual で対象外化した take id は状態から除去され、再採用時に誤って skip しない", async () => {
+        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
+        // 1 回目: ACK は成功させ fetchSucceeded に残す
+        const { downloader } = makeDownloader({ videoFetcher });
+
+        await downloader.run(makeManual()); // take 11 を fetch 成功
+        expect(videoFetcher).toHaveBeenCalledTimes(1);
+
+        // 2 回目: take 11 が採用差し替え (別 take 採用) → 11 は対象外 = 墓石掃除
+        const swapped = makeManual([
+            makeCut({ adopted_take_id: 99, takes: [makeTake({ id: 99 })] }),
+        ]);
+        await downloader.run(swapped);
+        expect(videoFetcher).toHaveBeenCalledTimes(2); // take 99 を新規 fetch
+
+        // 3 回目: take 11 が再び採用・未 DL に戻る → 墓石掃除済みなので再 fetch される
+        await downloader.run(makeManual());
+        expect(videoFetcher).toHaveBeenCalledTimes(3);
+    });
+});
+
+describe("AdoptedTakeAutoDownloader 戻り値", () => {
+    it("複数採用テイクで 1 件 ACK 成功・1 件 ACK 失敗でも changed=true / hasPendingAck=true", async () => {
+        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
+        const ackFetch = vi.fn(async (url: string) =>
+            url.includes("/takes/11/") ? okResponse() : new Response(null, { status: 500 }),
+        );
+        const manual = makeManual([
+            makeCut({ id: 101, adopted_take_id: 11, takes: [makeTake({ id: 11 })] }),
+            makeCut({ id: 102, adopted_take_id: 12, takes: [makeTake({ id: 12 })] }),
+        ]);
+        const { downloader } = makeDownloader({
+            videoFetcher,
+            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
+        });
+
+        const result = await downloader.run(manual);
+
+        expect(result).toEqual({ changed: true, hasPendingAck: true });
+    });
+});
+
+describe("AdoptedTakeAutoDownloader 既定 videoFetcher (fetch + 完読)", () => {
+    const fetchMock = vi.fn();
+
+    beforeEach(() => {
+        vi.stubGlobal("fetch", fetchMock);
+        fetchMock.mockReset();
+    });
+
+    afterEach(() => {
+        vi.unstubAllGlobals();
+    });
+
+    /** credentials:omit で fetch し、body を完読・ACK する既定経路を通す downloader */
+    function realFetchDownloader(ackFetch = vi.fn(async () => okResponse())) {
+        const downloader = new AdoptedTakeAutoDownloader(1, 5, {
+            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
+            delay: async () => {},
+            isOnline: () => true,
+        });
+        return { downloader, ackFetch };
+    }
+
+    it("body を最後まで drain して完読成功したら ACK する (credentials:omit)", async () => {
+        fetchMock.mockResolvedValue(
+            new Response(new Blob(["video-bytes"]), { status: 200 }),
+        );
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(fetchMock).toHaveBeenCalledWith(
+            "https://s3.example.test/take-11.mp4?sig=1",
+            { credentials: "omit" },
+        );
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+        expect(result.changed).toBe(true);
+    });
+
+    it("response.ok=false (404) は http 失敗として ACK しない", async () => {
+        fetchMock.mockResolvedValue(new Response(null, { status: 404 }));
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result.changed).toBe(false);
+    });
+
+    it("読取中に error stream が投げると network 失敗として ACK しない", async () => {
+        const body = new ReadableStream({
+            start(controller) {
+                controller.error(new Error("stream broke"));
+            },
+        });
+        fetchMock.mockResolvedValue(new Response(body, { status: 200 }));
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result.changed).toBe(false);
+    });
+
+    it("Content-Encoding 無し + Content-Length 不一致は size_mismatch で ACK しない", async () => {
+        fetchMock.mockResolvedValue(
+            new Response(new Blob(["short"]), {
+                status: 200,
+                headers: { "Content-Length": "9999" },
+            }),
+        );
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result.changed).toBe(false);
+    });
+
+    it("Content-Encoding: gzip 付きは size 検査せず完読で ACK する", async () => {
+        fetchMock.mockResolvedValue(
+            new Response(new Blob(["short"]), {
+                status: 200,
+                headers: { "Content-Length": "9999", "Content-Encoding": "gzip" },
+            }),
+        );
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+        expect(result.changed).toBe(true);
+    });
+
+    it("Content-Length 非数値は size 検査をスキップし完読で ACK する", async () => {
+        fetchMock.mockResolvedValue(
+            new Response(new Blob(["short"]), {
+                status: 200,
+                headers: { "Content-Length": "not-a-number" },
+            }),
+        );
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+        expect(result.changed).toBe(true);
+    });
+
+    it("Content-Length が実読取量に一致すれば完読成功で ACK する", async () => {
+        const bytes = new Uint8Array([1, 2, 3, 4, 5]);
+        fetchMock.mockResolvedValue(
+            new Response(bytes, { status: 200, headers: { "Content-Length": "5" } }),
+        );
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+        expect(result.changed).toBe(true);
+    });
+
+    it("body=null + 空応答 (Content-Length: 0) は arrayBuffer フォールバックで完読成功し ACK する", async () => {
+        // body=null (stream 非提供環境相当) → arrayBuffer() フォールバックで received=0。
+        // received===0 && Content-Length===0 を network 失敗と誤判定しない。
+        fetchMock.mockResolvedValue(
+            new Response(null, { status: 200, headers: { "Content-Length": "0" } }),
+        );
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).toHaveBeenCalledTimes(1);
+        expect(result.changed).toBe(true);
+    });
+
+    it("fetch が AbortError を投げると aborted (= network 系失敗) として ACK しない", async () => {
+        fetchMock.mockRejectedValue(new DOMException("aborted", "AbortError"));
+        const { downloader, ackFetch } = realFetchDownloader();
+
+        const result = await downloader.run(makeManual());
+
+        expect(ackFetch).not.toHaveBeenCalled();
+        expect(result.changed).toBe(false);
+    });
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 595298b..cc3a2f1 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -1,7 +1,7 @@
 import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
 import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
 import CaptureShow from "@/pages/Capture/Show.svelte";
-import type { CaptureCut, CaptureManualDetail } from "@/types/capture";
+import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/capture";
 
 /*
  * 撮影ページ Capture/Show: F-03 実行時カメラフォールバック。
@@ -11,9 +11,10 @@ import type { CaptureCut, CaptureManualDetail } from "@/types/capture";
  * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
  */
 
-const { routerReloadMock, enqueueMock } = vi.hoisted(() => ({
+const { routerReloadMock, enqueueMock, autoDownloadRunMock } = vi.hoisted(() => ({
     routerReloadMock: vi.fn(),
     enqueueMock: vi.fn(),
+    autoDownloadRunMock: vi.fn(),
 }));
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
@@ -49,6 +50,15 @@ vi.mock("@/lib/capture/upload-queue", async (importOriginal) => ({
     },
 }));
 
+// AdoptedTakeAutoDownloader は run spy 付き stub に差し替え。状態機械の厳密検証は
+// auto-download.test.ts が担うため、本テストは Show 側の結線 (発火/reload) のみ検証する。
+// stub には running ガードが無いので多重実行抑止は検証しない (二重ガード回避方針)。
+vi.mock("@/lib/capture/auto-download", () => ({
+    AdoptedTakeAutoDownloader: class {
+        run = autoDownloadRunMock;
+    },
+}));
+
 function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
     return {
         id: 101,
@@ -75,6 +85,29 @@ function makeManual(): CaptureManualDetail {
     };
 }
 
+/** 採用済み・未 DL テイク (playback_url/ack_token 保持) を持つ manual */
+function makeAdoptedManual(): CaptureManualDetail {
+    const take: CaptureTake = {
+        id: 900,
+        client_take_id: "01J0ADOPT",
+        status: "ready",
+        size_bytes: 2048,
+        duration_ms: 3000,
+        comment: null,
+        captured_at: "2026-07-11T00:00:00Z",
+        sort_order: 0,
+        downloaded: false,
+        playback_url: "https://s3.example.test/take-900.mp4?sig=1",
+        download_ack_token: "ack-900",
+    };
+    return {
+        id: 5,
+        title: "ネジ締め作業",
+        status: "ready",
+        cuts: [makeCut({ adopted_take_id: take.id, takes: [take] })],
+    };
+}
+
 const baseProps = {
     project: { id: 1, name: "現場A" },
     manual: makeManual(),
@@ -102,6 +135,9 @@ beforeEach(() => {
     enqueueMock.mockImplementation((item: { clientTakeId: string }) =>
         Promise.resolve({ status: "uploaded", clientTakeId: item.clientTakeId }),
     );
+    autoDownloadRunMock.mockReset();
+    // 既定: 対象なし (changed=false)。個別ケースで override する
+    autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });
     getUserMediaMock.mockReset();
 });
 
@@ -215,6 +251,91 @@ describe("Capture/Show カメラフォールバック", () => {
     });
 });
 
+describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
+    const adoptedProps = { project: { id: 1, name: "現場A" }, manual: makeAdoptedManual() };
+
+    it("入室時に run(manual) が発火し、changed=true なら manual reload される", async () => {
+        stubCameraSupported(false);
+        autoDownloadRunMock.mockResolvedValue({ changed: true, hasPendingAck: false });
+
+        render(CaptureShow, { props: adoptedProps });
+
+        await vi.waitFor(() => {
+            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
+        });
+        expect(autoDownloadRunMock).toHaveBeenCalledWith(adoptedProps.manual);
+        await vi.waitFor(() => {
+            expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
+        });
+    });
+
+    it("changed=false のときは reload しない (DL 済み対象空 = 再発火抑止)", async () => {
+        stubCameraSupported(false);
+        autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });
+
+        render(CaptureShow, { props: adoptedProps });
+
+        await vi.waitFor(() => {
+            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
+        });
+        expect(routerReloadMock).not.toHaveBeenCalled();
+    });
+
+    it("online 復帰でも run が再度呼ばれる", async () => {
+        stubCameraSupported(false);
+        autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });
+
+        render(CaptureShow, { props: adoptedProps });
+        await vi.waitFor(() => {
+            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
+        });
+
+        await fireEvent(window, new Event("online"));
+
+        await vi.waitFor(() => {
+            expect(autoDownloadRunMock).toHaveBeenCalledTimes(2);
+        });
+    });
+
+    it("online を連続 dispatch すると各回で run 起動要求が出る (結線責務のみ)", async () => {
+        stubCameraSupported(false);
+        autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });
+
+        render(CaptureShow, { props: adoptedProps });
+        await vi.waitFor(() => {
+            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
+        });
+
+        await fireEvent(window, new Event("online"));
+        await fireEvent(window, new Event("online"));
+
+        await vi.waitFor(() => {
+            expect(autoDownloadRunMock).toHaveBeenCalledTimes(3);
+        });
+    });
+
+    it("自動 DL stub は録画フォールバックの enqueue 経路に干渉しない (a〜e 系の非回帰)", async () => {
+        stubCameraSupported(true);
+        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
+
+        render(CaptureShow, { props: { project: { id: 1, name: "現場A" }, manual: makeManual() } });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
+        });
+
+        const file = new File(["data"], "take.mp4", { type: "video/mp4" });
+        await fireEvent.change(screen.getByTestId("capture-file-input"), {
+            target: { files: [file] },
+        });
+
+        await vi.waitFor(() => {
+            expect(enqueueMock).toHaveBeenCalledTimes(1);
+        });
+    });
+});
+
 describe("Capture/Show レイアウト overflow ガード (H13/F-1-3)", () => {
     it("グリッドは mobile 単一列 (grid-cols-1)、左右 pane が min-w-0 を持つ", () => {
         stubCameraSupported(false);

```
