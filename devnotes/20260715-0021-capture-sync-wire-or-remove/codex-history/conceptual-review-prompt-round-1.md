【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

【あなたの役割】
あなたは Web アプリケーション(Laravel 12 + Svelte 5 + Inertia.js + TypeScript, PHPStan level 10, Pest)の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善は使命(North Star)に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか(特に「削除して良いはずのコードが実は使われている」見落とし)
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターン・PHPStan level 10 を通せるか

【この設計の特殊性】
これは「デッドコードの sync endpoint(照合専用・書き込みゼロ)を、フロントに配線するか(A)、廃止するか(B)」の二択判断を含む設計です。設計者は (B) 廃止を選択しました。特に次を批判的に検証してください:
- (B) 廃止の判断根拠(即時アップロード方式 + client_take_id 冪等 + IndexedDB PendingStore が reconcile の役割を既に代替している、という主張)は妥当か。見落としている reconcile の固有価値(cross-device 同期・IndexedDB eviction からの回復・オフライン後の一括照合など)はないか。
- 削除対象の網羅性・巻き込み事故(共有 DTO の誤削除、他経路からの参照見落とし)。
- (A) 配線を選ぶべき積極的理由が本当に無いか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260715-0021-capture-sync-wire-or-remove/conceptual-design.md の全文）

（レビュアーは必要に応じて次の実ファイルを読んで裏取りしてよい:
resources/js/lib/capture/upload-queue.ts, resources/js/lib/capture/idb.ts,
resources/js/pages/Capture/Show.svelte, app/Services/Capture/CaptureSyncService.php,
app/Http/Controllers/Capture/CaptureSyncController.php, routes/web.php,
tests/Feature/Capture/CaptureSyncTest.php, tests/Architecture/NestedRouteIdorDefenseTest.php,
doc/02_システム全体像.md §2.3, doc/05_スマホアプリ機能仕様.md §5.3, doc/10_実装仕様.md §10.3/§10.8-8）

---

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

**編集(inventory / 分母)**
- `tests/Architecture/NestedRouteIdorDefenseTest.php`: `'capture.manuals.sync' => $s,`(78 行)除去
- `.claude/skills/app-bug-hunt/operations.md`: sync 行(17 行)除去

**保持(共有 = 削除しない)**
- `CaptureManualDetailData` / `CaptureTakeData` / `TakeObjectStorage` / `UploadTicketCodec`
  (`CaptureManualController` の show/index が使用)

## 期待効果

- **使命への貢献**: 直接の機能追加ではないが、デッドコード除去でコードベースの
  「思考ゼロ」設計の一貫性(即時アップロード D9 に一本化)を守り、将来の撮影同期改修の混乱を防ぐ。
- カバレッジ監査ギャップ #7 のクローズ(operations.md 分母 = 実配線に整合)。
- inventory(NestedRouteIdorDefenseTest)と実 route の drift 解消。

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

