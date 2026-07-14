# 概念設計レビュー Round 2

Round 1 の CHANGES_REQUESTED を受け、概念設計を改訂しました。対応は以下です。

## 対応サマリー(Round 1 指摘 → 反映)

1. **[Critical] 削除対象の網羅性が宣言先行** → 「## 削除安全性の検証(参照監査手順)」節を新設。
   route 名全文監査・PHP シンボル監査・`ClientTakeFingerprint` sync 専用証跡・TS `SyncResult` 監査・
   code-review-graph + rg 二重確認・「sync trio 外の参照が 1 件でも出たら削除中止し再設計」の判定基準を明記。

2. **[Warning] canonical spec の更新漏れ** → 編集対象に doc/10 §10.3(L178)・§10.8-8(L334)・
   doc/08(route 表 L155・アーキ層記述 L251)を追加。「一括同期は D9 即時アップロードへ吸収」注記方針を記載。

3. **[Warning] reconcile 固有価値ゼロの説明不足** → 「## 失敗モード別: reconcile が回復策にならない証明」表を新設
   (cross-device / IndexedDB eviction / 長期オフライン の 3 モードを個別反証)。

4. **[Warning] 代替経路のテスト根拠が薄い** → 「## 代替経路の不変条件を担保する既存テスト」節を新設
   (新規のみ送信 / client_take_id 冪等 / pending 再送 / 成功後 manual 最新化 を既存テストに紐づけ、
   詳細設計で実在をコマンド確認し不足あれば補強対象化)。

5. **[Warning] 使命への貢献が強すぎる** → 期待効果を「撮影 UX 改善」から
   「運用負債の除去(実装経路の単純化・監査整合・攻撃面縮小)」へ書き換え。

6. **[Suggestion] 再オープン条件** → スコープ外に「## 再オープン条件」を追記
   (複数端末運用/eviction 実害観測時に限り要件再定義から見直す)。

改訂後の概念設計の全文を以下に添付します。特に新設した「失敗モード別証明」「参照監査手順」「代替テスト根拠」が
承認水準に達しているか、また残存する Critical/Warning があれば指摘してください。

---

## 改訂版 概念設計(全文)

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
| 長期オフライン後の一括照合 | ❌ 主に再送削減のみ | オンライン復帰で `UploadQueue.resume()` が pending を全件再送し `client_take_id` 冪等(409 registration_in_flight 有界 backoff)で収束する。reconcile が生むのは「既登録分を再送しない」最適化だが、register が冪等な現行では重複登録は起きず、削減されるのは無害な往復のみ。UX 上の実害を防がない。 |

→ 3 モードいずれでも reconcile は独自の回復手段にならない。(A) を選ぶ積極理由は無い。

## 代替経路の不変条件を担保する既存テスト

sync 廃止の論拠「即時アップロード経路が役割を代替済み」は、以下の既存テストが不変条件を担保している
(これらは本設計で削除しない)。

| 不変条件 | 担保テスト |
|---------|-----------|
| 新規のみ送信(DL 済み/登録済みは queue に入らない) | `resources/js/lib/capture/upload-queue.ts` の enqueue 経路 + vitest(`tests/js/**/upload-queue*` があれば)。詳細設計で実ファイル名を確定し紐づける。 |
| client_take_id 冪等 / 409 registration_in_flight 収束 | `tests/Feature/Capture/*`(takes.store の冪等・二重登録防止テスト)+ upload-queue の 409 backoff テスト |
| pending 再送(offline→online / visibilitychange) | upload-queue の resume テスト |
| 成功後の manual 最新化(Inertia partial reload) | `tests/Feature/Capture/CaptureManualBrowsingTest`(manual スナップショットのキー契約を固定) |

→ 詳細設計フェーズで上記テストの実在をコマンドで確認し、不足があれば「sync 削除の前提テスト」として補強対象に明示する
(不足のまま削除しない = 禁止事項 #1 テストなし完了の回避)。

## 削除安全性の検証(参照監査手順 — 詳細設計で施策化)

「他から未参照」は宣言でなく手順で確証する。削除実装前に以下を必須実行し、
**sync trio(route/Controller/Service/Request/Resource/DTO/TS type)以外からの参照が 1 件でも見つかれば削除を中止し設計を再検討**する。

1. route 名全文監査: `rg "capture\.manuals\.sync|manuals/\{manual\}/sync"` が routes/web.php・inventory・operations.md・doc 以外に hit しないこと。
2. PHP シンボル監査: `rg "CaptureSyncController|CaptureSyncService|SyncCaptureTakesRequest|CaptureSyncResultResource|CaptureSyncInput|CaptureSyncResultData|ClientTakeFingerprint"` の hit が削除対象集合に閉じること。
3. `ClientTakeFingerprint` が sync 専用である証跡: hit が CaptureSyncService / CaptureSyncInput / CaptureSyncResultData のみ(= 本 round で確認済み。詳細設計で再確認)。
4. TS 監査: `rg "SyncResult"` が types/capture.ts のみに閉じること。
5. code-review-graph(MCP)で `CaptureSyncService::reconcile` の呼び出し元が Controller のみ、Controller の到達経路が削除対象 route のみであることを構造的に確認(rg と二重確認)。
6. 判定基準: 上記いずれかで sync trio 外の参照が出た場合は Critical として削除を中止し、当該参照の意図を精査してから設計をやり直す。

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

