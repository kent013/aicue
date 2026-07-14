# 概念設計: take-auto-download-on-entry（撮影詳細入室時の採用済みテイク自動ダウンロード）

> **改訂履歴**: Round 1（gpt-5.4）レビューを反映し、`downloaded_at` のドメイン意味を明文化（§`downloaded_at` の意味）／S3 CORS(GET) を受け入れ条件化／帯域抑制策とテスト要件を追記。

## 背景・課題

ユースケース・カバレッジ監査ギャップ #6（Medium）。

`doc/05 §5.3`・`doc/02 §2.3` は撮影 PWA の**核となる同期ルール**として次を求める:

> シナリオ詳細を開いたとき、既にサーバーで採用済みのテイクがあれば端末へ**自動ダウンロード**される（DL 済み枠は色/枠線で区別）。→ PC ↔ アプリ間でテイクが二重登録されない設計。

現状 `resources/js/pages/Capture/Show.svelte` は**入室時の自動 DL を持たず**、`TakeStrip.svelte` の手動 DL ボタン（`downloadAndAck`）のみが存在する。テイクの二重登録自体は同期冪等キー（`client_take_id`）で既に防止済みで**詰みは無い**が、仕様どおりの UX ではない（採用済みテイクを操作者が手で 1 件ずつ DL しない限り「DL 済み」バッジが付かない）。

つまり本件は**correctness gap ではなく v1 仕様（doc/05 §5.3）への UX 忠実性ギャップ**である。

## 現状の整理（重要な前提）

- 詳細 GET（`CaptureManualDetailData` → `CaptureCutData::fromCut`）は**採用テイクのみ** `playback_url` と `download_ack_token` を非 null で返す。非採用テイクは常に両方 null。
- 「DL 済み」は**サーバ側 `takes.downloaded_at`** が唯一の真実。`downloaded: boolean`（`downloaded_at !== null`）として詳細 GET に載る。
- 手動 DL（`TakeStrip.downloadAndAck`）は次の 2 段:
  1. `window.open(playback_url, "_blank", "noopener")`（ブラウザの DL/表示）
  2. `POST .../takes/{take}/downloaded`（`{ ack_token }` を送る署名 ACK。`CaptureTakeService::markDownloaded` が token 検証 → 未打刻なら `downloaded_at = now()` を打刻。再送は冪等 no-op）
- **現行 v1 アーキテクチャに「DL した動画バイトを端末 IDB へ永続保存する」仕組みは存在しない**。`lib/capture/idb.ts` は「アップロード pending バッファ」専用。手動 DL の `window.open(playback_url)` も**アプリ管理ストレージへは保存せず**、新規タブでの表示/ブラウザ DL に委ねるだけである。「DL 済み」の効果は (a) バッジ表示、(b) サーバ側で当該テイクの削除を 422 で拒否（`CaptureTakeService::delete`）、(c) 再アップロード対象からの除外（＝ローカル pending キュー由来のみ送信するため元々対象外）に限られる。

## `downloaded_at` の意味（本設計で明文化する不変条件）

Round 1・2 レビューの指摘（「fetch+破棄+ACK を "端末保存" と呼ぶのは無理」「downloaded_at に端末識別子が無いので "この端末" は表現できない」）を受け、`downloaded_at` / `downloaded` のドメイン意味を次のとおり**明文化**する（doc/05 §5.3 付近と `docs/architecture.md` の撮影 PWA 節へ追記）:

> `takes.downloaded_at` は「**いずれかの認可済みクライアントで当該採用テイクの取得処理（HTTP 成功 + body 読取完了）が成功し、ACK された時刻**」を表す。**端末単位の状態ではない**（端末識別子を持たず、別端末/別セッションからも ACK され得る）。手動 DL（`window.open`）・自動 DL（`fetch`）の**いずれも同一の意味**であり、両経路で同じ ACK 経路（`POST takes.downloaded`）を通す。
>
> 明示的に**保証しないこと**: (a) オフライン再生・端末内ファイルの存在、(b) ブラウザキャッシュへの残存。`downloaded` は「取得処理と ACK が成功した状態」に過ぎない。将来オフライン再生等で永続保存が必要になった場合は、**`downloaded_at` を流用せず別状態として設計する**。

### プロダクト不変条件（v1・端末単位ではなくワークフロー単位）

Round 3 の指摘（global な `downloaded_at` を `downloaded===false` で絞ると、別クライアントで ACK 済みのテイクは新端末に自動取得されない）を受け、次を **v1 プロダクト不変条件**として確定する:

> v1 では撮影の download/sync 状態（`downloaded_at`）は**ワークフロー全体（take 単位）の「取得済み・同期済み」状態**であり、**端末単位ではない**。**v1 制約: 複数撮影クライアント間の端末別同期状態は保証しない**（規範的制約。「単一 Default Project」はこの制約の根拠ではなく独立した v1 スコープ制約として扱う）。doc/05 §5.3 の「端末へ」は達成メカニズムの記述であり、**強制されるべき不変条件は doc/02 §2.3 の「PC↔アプリ二重登録防止」**（ワークフロー全体でグローバルな性質）である。これはグローバル状態で満たされる。

根拠（コード事実）: `downloaded_at` の**唯一の書き込み経路は撮影 PWA の署名 ACK（`CaptureTakeService::markDownloaded`）**であり、PC 側（doc/04）の「ダウンロード」は完成 mp4 マニュアルの DL で `downloaded_at` を触らない。加えて v1 はバイトの端末永続保存を行わない（下記スコープ外）ため、per-device 再取得は端末固有の durable な便益を持たない一方、端末別状態の導入は端末識別 + 端末別 ACK レコードを要しスコープ爆発を招く。**複数端末で 1 manual を同時撮影し入室毎に per-device 再取得する要求は v1 スコープ外**（将来必要になれば端末別 ACK を別設計）。監査ギャップ #6 は「入室時に採用済みテイクを自動取得し同期状態を UX に反映」＝このグローバル同期モデルで解消する。

この定義により (a) 手動と自動で実体（window.open / fetch）が違っても意味は揃う、(b) 自動 `fetch` はネットワーク越しに実バイトを端末へ**転送する**ため `window.open`（inline 再生で終わる場合がある）より「取得」として忠実、(c) 「DL 済み」バッジと削除ガードの根拠が一貫する。**フィールド名・route 名の rename は行わない**（機能的必要がなく、doc/05 §5.3 の「自動ダウンロード」という要求語ともバイト転送の実体は一致する。確立済み API の破壊的 rename はオーバーエンジニアリング）。バッジ文言「DL 済み」は既存 UX・要求語に合わせ**維持**するが、上記 caveat（オフライン再生・永続保存を保証しない）を doc に明記して誤解を防ぐ。

## 改善アイデア

`Capture/Show.svelte` のマウント時（入室時）に、**未 DL の採用済みテイクを順次自動ダウンロード + ACK** する仕組みを追加する。

- 対象: 各カットの採用テイク（`cut.adopted_take_id === take.id`）のうち
  `take.status === "ready"` かつ `take.downloaded === false` かつ
  `playback_url !== null` かつ `download_ack_token !== null` のもの。
- 手順（1 件ずつ = 順次。帯域・容量配慮）:
  1. `fetch(playback_url, { credentials: "omit" })` で**実際にバイトを取得**（＝仕様の「端末へ DL」を忠実に満たす。署名 S3 URL に cookie を送らない／カスタムヘッダを付けず CORS preflight を避ける）。
  2. **取得成功の判定（厳密）**: `response.ok === true`（4xx/5xx は失敗。特に署名 URL 期限切れ 403 を含む）**かつ** `response.body` の `ReadableStream` を**最後まで順次 drain して読み切る**（`arrayBuffer()` で大容量を一括保持せず、chunk を読み捨てる＝メモリ配慮）。サイズ一致判定は **`Content-Encoding` ヘッダが無く、かつ `response.body` が非 null の場合のみ** `Content-Length` と読取総量の一致を検査する（`Content-Encoding` 付きは復号後サイズと転送時サイズが不一致になり得るため検査しない）。`response.body === null` は取得失敗扱い。読取途中の失敗/中断（abort）では ACK しない。取得結果は判別可能 union（`{ ok: true } | { ok: false; reason: "http" | "network" | "aborted" | "size_mismatch" }`）で返す。
  3. `ok:true` のときのみ `POST .../takes/{take}/downloaded`（`{ ack_token }`）で ACK。手動 DL と**同一の ACK 経路**を共有する。
  4. ACK 成功後に `router.reload({ only: ["manual"] })` を（複数採用テイクでも最小回数で）行い、`downloaded=true` を UI に反映（「DL 済み」バッジ・枠線）。
- 規律:
  - **オフライン時はスキップ**（`navigator.onLine === false` なら何もしない。offline は失敗ではない）。次回入室 or online 復帰で再試行。
  - **失敗時は有界リトライ**（指数 backoff、既存 upload-queue と同じ規律）→ 最終失敗はスキップ（詰ませない。手動 DL ボタンは従来どおり残る）。
  - **多重起動防止 + 帯域抑制（状態を 2 分離）**: 実行中フラグで onMount/online の自動 DL が二重に走らないようにする。状態は次の 2 つに分離する（Round 2 指摘）:
    - **`fetchSucceeded`（per-take）**: fetch を成功した take は同一セッションで**再 fetch しない**（フル動画の無駄再取得を防ぐ）。
    - **`ackPending`（per-take）**: fetch 成功済みだが ACK 未成功の take は、**再 fetch せず ACK のみを有界リトライ**できる。
    fetch **失敗**は `fetchSucceeded` に入れない（次トリガで再取得可）が、1 トリガ内の再試行は有界リトライで抑える。手動 `downloadAndAck` が既に打った分は `downloaded===true` で自然に除外され、二重 ACK は起きない（かつ ACK 自体サーバ冪等）。
  - **online 復帰時**の再実行は既存の `handleOnline`（`resumeUploads`）と別関数として最小限に留める（自動 DL は入室時の 1 パスを基本とし、online 復帰時にも 1 回だけ試みる。per-take `attempted` セットにより既試行分は再取得しない）。
  - **帯域コストの受容**: 「ダウンロード」は本質的にバイト転送を伴うため一度きりの取得コストは受け入れる。抑制は「未 DL のみ・入室時＋online 復帰のみ・直列・per-take attempted」で担保する。

### 実装構造（概要）

- 新規 `resources/js/lib/capture/auto-download.ts`（テスト可能な純関数/クラス）を追加し、
  「manual を受け取り → 未 DL 採用テイクを列挙 → 順次 fetch+ACK → 各成功で `onDownloaded` コールバック」を担わせる。
  upload-queue.ts と同じく `fetcher` / `delay` / `isOnline` を注入可能にしてテストする。
- `Capture/Show.svelte` は onMount（および online 復帰）でこのモジュールを呼ぶだけの薄い結線に留める。ACK 経路（URL 生成・`captureJson`）は既存 `lib/capture/http.ts` を再利用する。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」の撮影体験。操作者が採用済みテイクを手で取得する手間をゼロにし、**入室時の同期状態を自動で揃える**（doc/05 §5.3 の同期モデルの UX 一貫性）。「DL 済み（取得済み）」バッジが自動で正しく付くことで「どのカットが確定済みか」が一目で分かり、無駄な再撮影・混乱を減らす。
- **UX 忠実性**: 監査ギャップ #6 を解消（仕様準拠）。
- **副作用最小**: 二重登録は元々冪等キーで防止済みのため機能追加によるリスクは低い。手動 DL/DL 済みバッジ/upload-queue と完全整合。

> 期待効果の表現について（Round 1 反映）: 本件の価値は「端末永続保存」ではなく「**入室時同期の自動化 = UX 一貫性の改善**」に置く。フル動画取得の帯域/電池コストは受容条件（上記抑制策で最小化）。

## 制約・前提

- v1 スコープ（doc/10 §5 冒頭・AGENTS.md）: 撮影は PWA・同一オリジン・セッション認証。合成/TTS 等は無関係。
- セキュリティ不変条件（AGENTS.md §セキュリティ不変条件）:
  - ACK は既存の署名 `download_ack_token` 経由のみ（payload から `downloaded_at` 等の tenant/state キーを受け取らない。`MarkTakeDownloadedRequest` が `downloaded_at` を `missing` で拒否済み）。**新規エンドポイント・新規サーバ変更は不要**（既存 `takes.downloaded` を再利用）。
  - `playback_url` は take スコープの署名 S3 URL。cross-org 越境は発生しない。
- **S3/minio CORS（受け入れ条件）**: 自動 DL は `fetch(playback_url)`（クロスオリジン GET）を用いる。バケット CORS が対象 origin からの **GET** を許可し、**GET レスポンスに適切な `Access-Control-Allow-Origin` ヘッダ**が付く必要がある（preflight 有無だけでなく実レスポンスの ACAO が必須）。既に `upload-queue.ts` が同バケットへ**クロスオリジン PUT** しており、アプリ origin に対する CORS は PUT で成立済み。実装時に **GET を AllowedMethods に含める**（dev=minio / 本番）ことを確認・設定する。fetch は `credentials: "omit"`・カスタムヘッダ無し（cookie 非送信 + CORS preflight 回避）。取得失敗（CORS 不備・署名 URL 期限切れ 403 含む）は有界リトライ後スキップし、手動ボタンへ fallback（詰ませない）。
- **`Content-Encoding` の扱い（受け入れ条件）**: `Content-Encoding` は CORS で公開されない限り JS から参照できない。v1 は **take オブジェクトに `Content-Encoding` を設定しない**（撮影動画はそのまま保存）ことを前提とし、サイズ一致検査を適用する。将来 `Content-Encoding` を付ける運用にする場合は、参照を諦めるか `Access-Control-Expose-Headers` に含める。size 検査はあくまで補助（本質は `response.ok` + body 完読）であり、参照不能時は size 検査をスキップして完読成功のみで判定する。
- 既存アーキテクチャ整合: Svelte 5 runes、`lib/capture/http.ts` の `captureFetch`/`captureJson`、`atoms→…→pages` の単方向 import。新規 UI コンポーネントは追加しない（バッジ等は既存 `TakeStrip` のまま）。

## テスト計画（概要・詳細設計で確定）

`resources/js/lib/capture/auto-download.ts` を vitest で単体テスト（fetcher/ackFetch/delay/isOnline を注入）:
- 対象選別: 採用 && status="ready" && downloaded=false && playback_url≠null && ack_token≠null のみ取得。非採用・DL 済み・非 ready は対象外。
- **取得成功判定**: `response.ok===true` かつ body drain 完了時のみ ACK。HTTP 403/404/500・body 読取中断・`Content-Length` 不一致では ACK を送らない（署名 URL 期限切れ 403 ケース含む）。
- ACK 成功後に `onDownloaded`（reload）が呼ばれる。複数採用テイクでも reload は最小回数。
- オフライン（isOnline=false）は何もしない（fetch も ACK も呼ばれない）。
- 失敗時の有界リトライ → 打ち切り（無限ループしない）。
- **状態 2 分離**: fetch 成功済み take は再 fetch しない（`fetchSucceeded`）。fetch 成功後 ACK 失敗した take は再 fetch せず ACK のみ有界リトライ（`ackPending`）。
- `Capture/Show.svelte`（CaptureShow.test.ts）: onMount で未 DL 採用テイクの自動 DL が発火し、DL 済みは再 DL しない結線を確認。
- 実装完了条件: test-first（fail 確認）+ `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` green。

## スコープ外（過剰実装しない）

- **DL した動画バイトの端末 IDB 永続保存（offline 再生用キャッシュ）**: 現行 v1 に永続化機構は無く、二重登録は冪等キーで既に防止済み。iOS Safari の eviction リスク・容量圧迫を考えると v1 で恒久保存機構を新設するのはオーバーエンジニアリング。本件は「fetch でバイトをネットワーク取得（＝doc/05 §5.3「端末へ DL」を満たす）→ ACK でサーバに取得済みを記録」に留め、バイトの**永続キャッシュ（オフライン再生）は v1 スコープ外**とする。将来 SW / Cache Storage での正式キャッシュが必要になった時点で別途設計する（`downloaded_at` の意味は上記のとおり「取得済み」で、その時点でも整合する）。
- **セッション中に採用したテイクの即時自動 DL**: 本件は仕様の「詳細画面**遷移時**（入室時）」に忠実に onMount（+ online 復帰）へ限定する。ページ滞在中の adopt 操作直後の自動 DL は今回対象外（手動 DL ボタンで対応可能・詰みなし）。
- **サーバ側 API 変更**: 既存 `POST takes.downloaded` と詳細 GET の payload を一切変更しない。
- **手動 DL ボタンの削除**: 残す（オフライン/失敗時の fallback、および再表示用途）。
