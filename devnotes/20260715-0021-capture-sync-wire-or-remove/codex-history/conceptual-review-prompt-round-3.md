# 概念設計レビュー Round 3

Round 2 の 2 つの Warning + 参照監査の判定基準を反映しました。実コードを確認して裏取りしています。

## 対応サマリー

1. **[Warning] 既存テスト担保未確認** → 実ファイルを走査し「## 廃止前に検証・補強する代替経路の不変条件」表を
   (1)実在確認済み/(2)実在するが別観点/(3)新規必要 に再分類。全て (1) で担保、(3) は無し、を実測(ファイル:行)で確定:
   - `tests/js/lib/capture/upload-queue.test.ts:93/121/172/200`(即時成功で store 残らない / resume 再送削除 / 409 backoff)
   - `tests/Feature/Capture/TakeRegistrationTest.php:211/230/348`(サーバ冪等・重複 200 既存)
   - `tests/js/pages/CaptureShow.test.ts:173`(**Show.svelte が `router.reload({only:["manual"]})` を呼ぶ JS 振る舞い** = R2 指摘に対応)

2. **[Warning] 「無害な往復」断定の根拠不足** → 断定を撤回。実コード確認により crash-before-ack エッジでは
   register 冪等判定の前に upload-url(quota 予約)+ S3 PUT(blob 再送)が走ることを確認
   (`TakeRegistrationTest.php:230` が「別 path 重複 → 200 既存 + 予約 released + 重複オブジェクト削除」を担保)。
   「### 再送コストの正直な評価」節を新設し、**受容する既知コスト**(稀なエッジ限定・register 自己修復・
   孤児掃除 cron backstop で有界)と評価。reconcile 配線は稀なエッジ用 micro-opt に新 endpoint+client 照合を足す
   過剰実装として退け、廃止判断を維持。

3. **[Warning] 参照監査の判定基準が既知参照と矛盾** → 判定を 3 分類に再構成
   (予定済み削除更新対象=設計どおり / 未記載プロダクションコード=削除中止再設計 / 未記載テスト文書ツール=評価して追加)。
   URL 監査を route:list + code-review-graph + シンボル検索の三系統に。

該当節の改訂全文を以下に添付します。承認水準に達しているか判定してください。

---

## 改訂節: 失敗モード別証明 + 再送コストの正直な評価

## 失敗モード別: reconcile が回復策にならない証明

brief が名指しした 3 つの失敗モードについて、reconcile(サーバ側の read-only 照合)が
固有の回復価値を持たないことを個別に示す。

| 失敗モード | reconcile で回復できるか | 理由 / 現行機構での扱い |
|-----------|------------------------|----------------------|
| cross-device 同期(端末 A で撮影 → 端末 B で開く) | ❌ 固有価値なし | reconcile は blob を移送できない。端末 B から見て「サーバ登録済みテイク」は既に `manual` Inertia スナップショット(playback_url 等)で取得済み。端末 B のローカル未送信集合は空(別端末の IndexedDB は見えない)なので pending 照合の対象もない。 |
| IndexedDB eviction(iOS Safari が pending blob を退避) | ❌ 回復不能 | 退避で blob 本体が失われた場合、reconcile は「未登録 ID」を返せても blob を持たないため復元できない。これは reconcile でも即時アップロードでも回復不能な同質の損失(唯一の緩和は blob 保持時間の最小化 = D9 で既に実施)。 |
| 長期オフライン後の一括照合 | △ 再送コスト削減の micro-opt のみ(下記参照) | オンライン復帰で `UploadQueue.resume()` が pending を全件再送し `client_take_id` 冪等で収束する。reconcile の唯一の実効は「crash-before-ack エッジで既登録分の blob 再アップロードを事前 prune する」最適化だが、これは稀なエッジ限定の micro-opt で新 endpoint + client 照合ロジックに見合わない(下記「再送コストの正直な評価」)。 |

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
  よって「無害」ではなく **受容する既知コスト**である。ただしこのコストは (a) 稀なエッジ限定、
  (b) register が重複予約 released + 重複オブジェクト削除で即時自己修復、(c) さらに孤児掃除 cron
  (doc/10 §10.8-4)が backstop、で有界かつ自己回復する。reconcile を配線すれば入室時にこの再送を prune できるが、
  それは稀なエッジのための micro-optimization に新 endpoint + client 側 IndexedDB 照合ロジックを足すことになり
  過剰実装(思考原則 #2)。よって既知コストとして受容し、(B) 廃止を維持する。

---

## 改訂節: 廃止前に検証・補強する代替経路の不変条件

## 廃止前に検証・補強する代替経路の不変条件

sync 廃止の論拠「即時アップロード経路が役割を代替済み」を担保する既存テストを、実在確認の上で
**(1)実在確認済み / (2)実在するが当該不変条件までは検証していない / (3)新規追加が必要** に分類した
(いずれも本設計で削除しない)。実在は本フェーズで `grep`/ファイル走査により確認済み。

| 不変条件 | 分類 | 担保テスト(実測) |
|---------|------|------------------|
| 即時アップロード成功で blob を端末に残さない(= 新規のみ送信の裏返し) | (1)確認済み | `tests/js/lib/capture/upload-queue.test.ts:93`「即時アップロード成功: … store に残らない」 |
| pending 再送(offline→online)で成功後に削除 | (1)確認済み | `tests/js/lib/capture/upload-queue.test.ts:121`「オフライン時は store へ永続化し、resume で再送・成功後に削除」 |
| 409 registration_in_flight の有界 backoff 収束 | (1)確認済み | `upload-queue.test.ts:172`「409 … 指数 backoff で有界リトライし成功で uploaded」/ `:200`「上限超過で queued」 |
| サーバ側 (cut_id, client_take_id) 冪等(重複登録なし・既登録は 200 既存返却) | (1)確認済み | `tests/Feature/Capture/TakeRegistrationTest.php:211`(completed 再送 200 既存)/`:230`(別 path 重複 200+released+削除)/`:348`(並行二重送信 冪等分岐 200) |
| アップロード成功後の manual 最新化 = `router.reload({only:["manual"]})` を呼ぶ(JS 振る舞い) | (1)確認済み | `tests/js/pages/CaptureShow.test.ts:173`「reload が `{only:["manual"]}` で呼ばれる」(← Codex R2 指摘の JS 振る舞いテスト。CaptureManualBrowsingTest はレスポンス契約のみなので本テストで補完) |
| manual スナップショットのキー契約(reload 応答の形) | (2)実在するが別観点 | `tests/Feature/Capture/CaptureManualBrowsingTest.php`(キー集合契約を固定。reload 呼出自体は上の JS テストが担保) |

→ **(3)新規追加が必要な不変条件は無い**。既存テストで代替経路は担保済みであることを確認した。
sync 削除で失われる固有テストは `CaptureSyncTest.php` のみ(照合専用 endpoint の振る舞い)で、
これは endpoint 自体の廃止に伴い削除する(担保すべき不変条件が消えるため補強不要)。

---

## 改訂節: 削除安全性の検証(参照監査手順)

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
