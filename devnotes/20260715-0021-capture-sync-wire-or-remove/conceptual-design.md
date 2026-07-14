# 概念設計: capture-sync-wire-or-remove

## 背景・課題

ユースケース・カバレッジ監査ギャップ #7(Medium)。

route `capture.manuals.sync` → `CaptureSyncController::store` → `CaptureSyncService::reconcile`
(読み取り専用の照合・差分算出) はバックエンド実装済み・Feature テスト完備だが、
`resources/js` 内に呼び出し経路が無い(型定義 `types/capture.ts` の `SyncResult` 参照のみで、
どの Svelte コンポーネントからも fetch されない)。事実上のデッドコードであり、
`.claude/skills/app-bug-hunt/operations.md` の操作分母を drift させている。

即時アップロード方式(`resources/js/lib/capture/upload-queue.ts` = 概念設計 D9)が
「新規のみ送信・二重防止」を **端末生成 client_take_id(ULID)を冪等キー** として実質代替しているため、
sync の当初想定した役割(一括同期時の差分算出)は既に別機構で満たされている。

本設計は brief の指示に従い **(A) 配線 / (B) 廃止 の二択を根拠付きで判断** し、
選んだ方のみを最小実装する(過剰な新機能化はしない = 思考原則 #2)。

## 仕様意図の精読(設計の最初にやること)

### doc/02 §2.3・doc/05 §5.3(同期の要点 = 重複防止ロジック)

- 詳細画面遷移時: サーバの採用済みテイクを端末へ**自動 DL**(ギャップ #6 の領域)。
- アップロード時: **その端末で新しく撮影したテイクのみ**送信。DL 済みは除外。
- → PC ↔ アプリ間でテイクが二重登録されない設計。

### doc/10 §10.3 / §10.8-8

- `POST .../sync` は「一括同期(新規テイクのみ)」。
- §10.8-8: **sync API の payload ID は照合専用**(サーバは nested relation から解決)。
- reconcile の実装: payload の (cut, client_take_id) fingerprint を manual の relation 集合と突き合わせ、
  (a) manual に属さない cut 参照 → 404、(b) サーバ未登録 fingerprint → `pending_upload` として返す、
  (c) 登録済み → 現在のサーバ状態を返す(冪等)。**書き込みゼロ**。

### 現行の即時アップロード UX(D9)が reconcile の返す情報を必要とするか

`CaptureSyncService::reconcile` が返すのは 2 つ:

1. **`pending_upload`(サーバ未登録 fingerprint の抽出)**
   - 現行 UX では、未送信テイクの**真実源はクライアント自身の IndexedDB `PendingStore`**
     (`lib/capture/idb.ts`)。しかも blob 本体を保持している。
   - `UploadQueue.resume()` が visibilitychange / online / SW message で pending を全件再送し、
     成功時のみ IndexedDB から削除する。登録は `client_take_id` 冪等(409 registration_in_flight は
     有界 backoff)なので「サーバに届いたか不明」な状態も再 POST で収束する。
   - つまり「どれが未送信か」はクライアントが blob 付きで既に把握しており、
     サーバに ID だけ問い合わせても **blob を持たないサーバは何も回復できない**。
     `pending_upload` は IndexedDB PendingStore と情報的に冗長。

2. **`manual`(全量スナップショット = `CaptureManualDetailData`)**
   - これは `Show.svelte` が既に Inertia props で受領し、アップロード成功後は
     `router.reload({ only: ["manual"] })` で最新スナップショットを再取得している。
   - reconcile が返す `manual` は同じ `CaptureManualDetailData::fromManual(...)` そのもの。
     Inertia partial reload と情報的に完全に冗長。

### 自動 DL(#6)との協調

自動 DL は「サーバの採用済みテイク(`playback_url` + `download_ack_token`)を端末へ pull」する処理で、
必要なメタは既に `manual` スナップショット(Inertia props)に含まれる。reconcile の
`pending_upload`(=アップロード方向の差分)は DL(=ダウンロード方向)に何ら寄与しない。
よって #6 のためにも reconcile は不要。

## 判断: (B) 廃止

**結論: reconcile はアーキ上不要。route / Controller / Service と専用 DTO/Request/Resource/テストを削除し、
operations.md の分母から当該操作を落として drift を解消する。**

根拠:

- **機能の名前に立ち返る**: 「sync(一括同期)」の役割 =「新規のみ送信・二重防止」は、
  即時アップロード + client_take_id 冪等 + IndexedDB PendingStore で**既に達成済み**。
  reconcile を配線しても、クライアントが既に持つ情報(未送信集合・manual スナップショット)を
  サーバ往復で再取得するだけで、新たなユーザー価値を生まない。
- **思考原則 #2(今必要なものだけ作る)/ brief「過剰な新機能化はしない」**: (A) 配線は
  「差分検出 UI」という新 UI 面を増やす過剰実装で、v1 スコープ(字幕のみ・PWA 撮影)に対し不要。
- **思考原則 #3(後方互換の並走を残さない)**: D9 へ設計が pivot した結果 reconcile は取り残された
  旧経路。「書き換えると決めたら旧実装を消す」= 廃止が規約に沿う。
- **operations.md drift の解消**: バグハント基盤の操作分母から未配線 route を除くことで、
  カバレッジ監査の分母が実配線と一致する。

reconcile を残す(何もしない)選択は、デッドコードと inventory drift を放置し監査ギャップを
閉じないため不採用。(A) 配線は上記の通り過剰実装。したがって (B) 廃止が唯一妥当。

## 失敗モード別: reconcile が回復策にならない証明

brief が名指しした 3 つの失敗モードについて、reconcile(サーバ側の read-only 照合)が
固有の回復価値を持たないことを個別に示す。

| 失敗モード | reconcile で回復できるか | 理由 / 現行機構での扱い |
|-----------|------------------------|----------------------|
| cross-device 同期(端末 A で撮影 → 端末 B で開く) | ❌ 固有価値なし | reconcile は blob を移送できない。端末 B から見て「サーバ登録済みテイク」は既に `manual` Inertia スナップショット(playback_url 等)で取得済み。端末 B のローカル未送信集合は空(別端末の IndexedDB は見えない)なので pending 照合の対象もない。 |
| IndexedDB eviction(iOS Safari が pending blob を退避) | ❌ 回復不能 | 退避で blob 本体が失われた場合、reconcile は「未登録 ID」を返せても blob を持たないため復元できない。これは reconcile でも即時アップロードでも回復不能な同質の損失(唯一の緩和は blob 保持時間の最小化 = D9 で既に実施)。 |
| 長期オフライン後の一括照合 | △ 再送コスト削減の micro-opt のみ(下記参照) | オンライン復帰で `UploadQueue.resume()` が pending を全件再送し `client_take_id` 冪等で収束する。reconcile の唯一の実効は「crash-before-ack エッジで既登録分の blob 再アップロードを事前 prune する」最適化だが、これは発生窓が限定されたエッジ(crash-before-ack。実測頻度データは未取得だが構造上まれ)の micro-opt で新 endpoint + client 照合ロジックに見合わない(下記「再送コストの正直な評価」)。 |

→ cross-device / eviction は reconcile が回復手段にならない(固有価値ゼロ)。長期オフラインは
reconcile に唯一の micro-opt 余地があるが、下記の通り受容可能な既知コストであり (A) を正当化しない。

### 再送コストの正直な評価(Codex R2 指摘への反証根拠)

`resume()` の再送は「無害な往復」ではなく実コストを伴う。正確な挙動を実コードで確認した:

- **正常系**: `enqueue` 成功時は即 IndexedDB から削除される(`upload-queue.ts` processOne)。よって
  `resume()` が再送する対象は「まだ登録に成功していない pending」に限られ、既登録分は通常再送されない。
  実測担保: `tests/js/lib/capture/upload-queue.test.ts:93`(成功で store に残らない)/ `:121`(resume 成功後に削除)。
- **crash-before-ack エッジ**: サーバ登録が commit されたが応答喪失で IndexedDB 削除前に端末が落ちた場合、
  `resume()` は当該 blob を再送する。経路は `upload-url`(**新規予約 bytes_pending += size**)→ **S3 PUT(blob 再送)**
  → `POST takes`(新 path・既存 Take と衝突)。register は冪等分岐で **200 既存返却 + その重複予約を released +
  重複オブジェクトのみ削除**(実測担保: `tests/Feature/Capture/TakeRegistrationTest.php:230`)。
- **結論**: crash-before-ack エッジでは冪等判定(register)より前に ticket 発行・quota 予約・blob 再 PUT が走る。
  よって「無害」ではなく **受容する既知コスト**である。ただしこのコストは (a) 発生窓が限定されたエッジ(crash-before-ack。実測頻度データは未取得だが構造上まれ)、
  (b) register が重複予約 released + 重複オブジェクト削除で即時自己修復、(c) さらに孤児掃除 cron
  (doc/10 §10.8-4)が backstop、で有界かつ自己回復する。reconcile を配線すれば入室時にこの再送を prune できるが、
  それは稀なエッジのための micro-optimization に新 endpoint + client 側 IndexedDB 照合ロジックを足すことになり
  過剰実装(思考原則 #2)。よって既知コストとして受容し、(B) 廃止を維持する。

## 廃止前に検証・補強する代替経路の不変条件

sync 廃止の論拠「即時アップロード経路が役割を代替済み」を担保する既存テストを、実在確認の上で
**(1)実在確認済み / (2)実在するが当該不変条件までは検証していない / (3)新規追加が必要** に分類した
(いずれも本設計で削除しない)。実在は本フェーズで `grep`/ファイル走査により確認済み。

| 不変条件 | 分類 | 担保テスト(実測) |
|---------|------|------------------|
| 即時アップロード成功で blob を端末に残さない(= 成功済みを再送しない保証) | (1)確認済み | `tests/js/lib/capture/upload-queue.test.ts:93`「即時アップロード成功: … store に残らない」 |
| pending 再送(offline→online)で成功後に削除 | (1)確認済み | `tests/js/lib/capture/upload-queue.test.ts:121`「オフライン時は store へ永続化し、resume で再送・成功後に削除」 |
| 409 registration_in_flight の有界 backoff 収束 | (1)確認済み | `upload-queue.test.ts:172`「409 … 指数 backoff で有界リトライし成功で uploaded」/ `:200`「上限超過で queued」 |
| サーバ側 (cut_id, client_take_id) 冪等(重複登録なし・既登録は 200 既存返却) | (1)確認済み | `tests/Feature/Capture/TakeRegistrationTest.php:211`(completed 再送 200 既存)/`:230`(別 path 重複 200+released+削除)/`:348`(並行二重送信 冪等分岐 200) |
| アップロード成功後の manual 最新化 = `router.reload({only:["manual"]})` を呼ぶ(JS 振る舞い) | (1)確認済み | `tests/js/pages/CaptureShow.test.ts:173`「reload が `{only:["manual"]}` で呼ばれる」(← Codex R2 指摘の JS 振る舞いテスト。CaptureManualBrowsingTest はレスポンス契約のみなので本テストで補完) |
| manual スナップショットのキー契約(reload 応答の形) | (2)実在するが別観点 | `tests/Feature/Capture/CaptureManualBrowsingTest.php`(キー集合契約を固定。reload 呼出自体は上の JS テストが担保) |

→ **(3)新規追加が必要な不変条件は無い**。既存テストで代替経路は担保済みであることを確認した。
sync 削除で失われる固有テストは `CaptureSyncTest.php` のみ(照合専用 endpoint の振る舞い)で、
これは endpoint 自体の廃止に伴い削除する(担保すべき不変条件が消えるため補強不要)。

## 削除安全性の検証(参照監査手順 — 詳細設計で施策化)

「他から未参照」は宣言でなく手順で確証する。削除実装前に以下を必須実行する。
URL 探索は文字列連結や route helper 生成で参照が隠れうるため、**route:list / code-review-graph /
シンボル文字列検索の三系統**で確認する。

1. **route 名全文監査(三系統)**:
   (a) `rg "capture\.manuals\.sync|manuals/\{manual\}/sync"` の全 hit を列挙、
   (b) `php artisan route:list` に当該 route が 1 本のみ存在することを確認(削除後は消えること)、
   (c) code-review-graph で route → Controller の到達経路を確認。
2. **PHP シンボル監査**: `rg "CaptureSyncController|CaptureSyncService|SyncCaptureTakesRequest|CaptureSyncResultResource|CaptureSyncInput|CaptureSyncResultData|ClientTakeFingerprint"` の全 hit を列挙。
3. **`ClientTakeFingerprint` sync 専用の証跡**: hit が CaptureSyncService / CaptureSyncInput / CaptureSyncResultData のみ(本 round で確認済み。実装時に再確認)。
4. **TS 監査**: `rg "SyncResult"` が types/capture.ts のみに閉じること。
5. **構造監査**: code-review-graph(MCP)で `CaptureSyncService::reconcile` の呼び出し元が Controller のみであることを確認(rg と二重確認)。

**判定基準(hit の分類)**:
- **予定済みの削除・更新対象からの参照**(route 定義・Controller・Service・Request・Resource・DTO・
  `CaptureSyncTest.php`・NestedRouteIdorDefenseTest inventory・operations.md・doc/08・doc/10・
  types/capture.ts の `SyncResult`): 設計どおり処理。
- **未記載のプロダクションコードからの参照**: **削除中止・再設計**(Critical)。当該参照の意図を精査。
- **未記載のテスト・文書・ツールからの参照**: 影響を評価し、削除/更新一覧へ追加してから実装。

## 改善アイデア(廃止の具体)

以下を削除/更新する(詳細なファイル一覧・波及は詳細設計で確定):

**削除(sync 専用・他から未参照を確認済み)**
- `routes/web.php`: `manuals.sync` Route 定義 + `use CaptureSyncController` import
- `app/Http/Controllers/Capture/CaptureSyncController.php`
- `app/Services/Capture/CaptureSyncService.php`
- `app/Http/Requests/Capture/SyncCaptureTakesRequest.php`
- `app/Http/Resources/Capture/CaptureSyncResultResource.php`
- `app/DataTransferObjects/Capture/CaptureSyncInput.php`
- `app/DataTransferObjects/Capture/CaptureSyncResultData.php`
- `app/DataTransferObjects/Capture/ClientTakeFingerprint.php`(sync trio 専用)
- `tests/Feature/Capture/CaptureSyncTest.php`
- `resources/js/types/capture.ts`: `SyncResult` interface(66-70 行)

**編集(inventory / 分母 / canonical spec)**
- `tests/Architecture/NestedRouteIdorDefenseTest.php`: `'capture.manuals.sync' => $s,`(78 行)除去
- `.claude/skills/app-bug-hunt/operations.md`: sync 行(17 行)除去
  (inventory-check は route:list と operations.md の差分検出なので、route とこの行を同時に消せば drift 0 を維持)
- `doc/10_実装仕様.md`: §10.3 route 表の sync 行(L178)除去、§10.8-8 の
  「sync API の payload ID は照合専用」bullet(L334)を除去/「D9 即時アップロードへ吸収」注記に置換
- `doc/08_システムアーキテクチャ設計.md`: route 表の sync 行(L155)除去、
  アーキ層記述(L251)の「manuals/takes/sync」→「manuals/takes」修正
  (照合専用の payload-ID 原則自体は scenario PUT §10.8-5 / upload ticket §10.8-7 に残るため
   sync 固有記述のみ落とす)

**保持(共有 = 削除しない)**
- `CaptureManualDetailData` / `CaptureTakeData` / `TakeObjectStorage` / `UploadTicketCodec`
  (`CaptureManualController` の show/index が使用)

## 期待効果

本件の価値は撮影 UX の直接改善ではなく **運用負債の除去** である。

- **実装経路の単純化**: 撮影同期を即時アップロード(D9)一本に統一し、未使用の並走経路
  (reconcile)を消すことで、将来の撮影同期改修時の「どちらが正か」の誤認を防ぐ
  (思考原則 #3 後方互換の並走を残さない)。
- **監査整合**: カバレッジ監査ギャップ #7 のクローズ。operations.md の操作分母・
  canonical spec(doc/08・doc/10)・inventory(NestedRouteIdorDefenseTest)を
  実配線に一致させ drift を解消する。
- **攻撃面の縮小**: read-only とはいえ未使用の認可 route を 1 本減らす。

## 実装方針(概要)

- 純粋な削除リファクタ。新規ロジック追加なし。
- 削除後に `composer test` / `composer phpstan` / `pnpm typecheck` / `pnpm lint` / `pnpm build`
  が全 green であること、`bug-hunt-inventory-check` が operations.md 更新後も drift 0 であることを確認。
- route 数・inventory を参照する Architecture テストが green を維持することを確認。

## 制約・前提

- v1 スコープ(doc/10)内。撮影は PWA・同一オリジン・セッション認証。
- セキュリティ不変条件は削除により**縮小のみ**(新たな攻撃面を作らない)。
  sync は読み取り専用 route だったため、削除で認可面が減る。
- reconcile 削除後も即時アップロード経路(upload-url / takes / adopt / downloaded / playback)は不変。

## スコープ外

- (A) 配線(差分検出 UI の新設)。過剰実装のため実装しない。
- ギャップ #6(自動 DL)の実装。別タスク。本設計は #6 に依存も貢献もしない
  (reconcile が #6 に不要であることを確認したのみ)。
- 即時アップロード(D9)機構そのものの変更。

## 再オープン条件

以下が実害として観測された場合に限り、(A) 配線ではなく**要件再定義から**見直す:
- 複数端末運用でローカル queue とサーバ状態の乖離が現場運用の問題として顕在化した場合。
- IndexedDB 破損/eviction によるテイク損失が頻発し、read-only な診断 probe(サーバ側の
  登録状況照会)が sup­port/observability 要件として明確に必要になった場合。
その際も「未使用 endpoint を後から配線」ではなく、観測機能として要件から設計し直す。
