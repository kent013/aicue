# 概念設計レビュー Round 2

Round 1 の指摘に対する対応マトリクスと、修正後の概念設計 (全文) を送ります。
Critical 1 については**前提が事実と異なる**ため、実際の route シグネチャを添えて反論します。
残りはすべて設計へ反映しました。

再度、全体判定 (APPROVED / CHANGES_REQUESTED) と、残る [Critical] [Warning] [Suggestion] を
出してください。既に解消したと判断できる項目は「解消」と明記してください。

---

## 対応マトリクス (Round 1)

# 対応マトリクス: conceptual-review Round 1

## [Critical] 既存 `capture.takes.*` の route parameter / capture session の整合が不足

- 判断: **反論する (前提が事実と異なる) + 設計へ明示追記する**
- 根拠: 本リポジトリに **capture session という概念は存在しない**。`routes/web.php` L604-621 の
  `capture.takes.*` は 7 本すべてが `/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes[/{take}]`
  の形で、**project / manual / cut / take だけ**を parameter に取る。認証はセッション (web guard)、
  テナント境界は `project.in-current-org` middleware + `scopeBindings` である。
  PC 画面は同じ project / manual / cut を持っているので、追加の解決処理は 1 つも要らない。
- 対応内容: D2 に**既存 route の実シグネチャ表**を貼り、「capture session は存在しない /
  PC が追加で解決するものは無い」ことを明記した。あわせて Codex の懸念どおり
  **URL 組み立て規則が 2 箇所に散る**問題は実在するので、
  `lib/capture/take-endpoints.ts` を唯一の導出元として切り出し、既存 `TakeStrip` も
  そこへ寄せる (新規複製を作らない) ことを施策に追加した。

## [Critical] `/app/*` API を PC から使う場合のセキュリティ UX と Feature テスト

- 判断: **対応する**
- 根拠: 「暗黙の前提にしない」は本リポジトリの規約そのものであり、指摘は正しい。
  no-store / bfcache / Inertia 履歴暗号化 (ドメイン固有規約 3) は**Inertia が描画する
  認証済み画面すべて**に既に効いており PC 面も対象だが、テイク API を PC から叩けることは
  新しい事実なのでテストで固定すべきである。
- 対応内容: 「必須成果物 (Feature テスト)」節を新設し、Codex が挙げた 3 本を含む
  テスト一覧 (cross-org / cross-project / cross-manual / cross-cut 404、保護キー 422、
  権限境界、rendering/analyzing 409、not-ready 422、DL 済み削除 422) を概念設計に固定した。

## [Warning] 「採否の判断が現場から戻る」という効果表現が言い過ぎ

- 判断: **対応する**
- 根拠: `TakePolicy` は撮影者にも adopt を開いたままであり、権限は変えない。
  「戻る」と書くと実装しない権限分離を約束することになる。
- 対応内容: 期待効果を「PC 編集者が**同じ判断を PC 上でも行えるようになる**」に修正し、
  権限分離は今回やらないことをスコープ外に明記した。

## [Warning] doc/10 側の記述との衝突 (PC 専用 BFF route 案との比較を明記せよ)

- 判断: **対応する**
- 根拠: 契約の変更であって単なるコメント変更ではない、という指摘は正しい。
- 対応内容: D2 に**案 A (既存再利用) / 案 B (PC 専用 route から同一 Service を呼ぶ)** の
  比較表を追加し、更新対象ドキュメントに `doc/10` を追加した。

## [Warning] UploadQueue 再利用時の PendingStore contract / 完了後処理 / 失敗表示が未設計

- 判断: **対応する**
- 対応内容: D4 にメモリ `PendingStore` の contract、完了後の `router.reload({only:[...]})`、
  失敗時の表示 (422 quota / 409 in-flight / ネットワーク断) と
  **予約の後始末は既存 sweeper が担う**ことを追記した。

## [Warning] 「レンダ 422 をその場で解消」の効果に条件が要る

- 判断: **対応する**
- 対応内容: 「ready なテイクが存在する場合に限り」と条件付けし、
  `uploading` / `processing` / `failed` / 409 の UI 状態を D7・必須成果物に明記した。

## [Warning] `takeSummaries` の N+1 / props 肥大

- 判断: **対応する**
- 対応内容: 施策 3 に「`withCount` + 採用テイクの eager load による 1 クエリ集約」
  「1 cut あたり 4 フィールドに限定」を明記した。

## [Warning] スコープ: アップロードが P2 だと「PC で業務完了」が成立しない

- 判断: **対応する**
- 根拠: 指摘のとおり。PC ローカル動画取り込みは doc/04 のテイク選択画面の要件本体であり、
  これを外すと「PC のみの利用者が業務を完了できる」という効果が嘘になる。
- 対応内容: 4 施策すべてを P1 (= 完了条件) とし、優先度は**実装順序**として表現し直した。

## [Warning] ページ props 全体の型境界が曖昧

- 判断: **対応する**
- 対応内容: `App\DataTransferObjects\Manual\TakeSelectionPageData` を置き、
  `toArray()` の array shape を phpdoc で固定する方針を D2 / 施策 1 に追記した。
  内部で既存 `CaptureCutData` を合成する (shape の二重管理を作らない)。

## [Suggestion] `thumbnail_url` を先回りで足さない判断は妥当

- 判断: **維持** (変更なし)

---

## 反論の根拠 (実コードからの引用)

`routes/web.php` L594-621 (撮影 PWA group) — capture session の parameter は存在しない:

```php
Route::middleware(['require-active-subscription', 'project.in-current-org'])
    ->prefix('app')->as('capture.')->group(function (): void {
        Route::get('/', [CaptureManualController::class, 'home'])->name('home');
        Route::get('/csrf-cookie', fn (): Response => response()->noContent())->name('csrf-cookie');
        Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
            ->name('manuals.index');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])
                ->name('manuals.show');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url', [TakeUploadUrlController::class, 'store'])
                ->name('takes.upload-url');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CaptureTakeController::class, 'store'])
                ->name('takes.store');
            Route::patch('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'update'])
                ->name('takes.update');
            Route::delete('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'destroy'])
                ->name('takes.destroy');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt', [CaptureTakeController::class, 'adopt'])
                ->name('takes.adopt');
            Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded', [CaptureTakeController::class, 'markDownloaded'])
                ->name('takes.downloaded');
            Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])
                ->name('takes.playback');
        });
    });
```

業務 route group (PC 面) の middleware は **完全に同一**である:

```php
Route::middleware(['require-active-subscription', 'project.in-current-org'])->group(function (): void {
```

`TakePolicy` は全 ability を `ProjectPolicy::capture()` へ委譲し、`capture()` は
`canManageProject()` (= org owner/admin または project_admin) を最初に true 判定するため、
**編集者は既に通る**:

```php
public function adopt(User $user, Take $take): bool { return $this->captureVia($user, $take); }
private function captureVia(User $user, Take $take): bool
{
    $project = $take->cut?->videoManual?->project;
    return $project !== null && $this->projectPolicy->capture($user, $project);
}
```

---

## 修正後の概念設計 (全文)

# 概念設計: pc-take-selection-and-adoption (PC 側のテイク選択・採用画面)

## 背景・課題

doc/04 §PC サイト機能仕様が定める **「テイクのプレビュー / 選択画面」** と
**「動画シナリオ画面の動画列」** は、PC 編集者にとっての中核機能でありながら**まったく存在しない**。

現状 (2026-08-16 時点の実装を実読して確認):

| 事実 | 根拠 |
|---|---|
| テイクの採用 / 並べ替え / コメント / 削除 / プレビュー再生の UI は撮影 PWA にしか無い | `resources/js/pages/Capture/Show.svelte` → `components/features/capture/TakeStrip.svelte` |
| シナリオ編集画面にはテイクを見る手段も採用する手段も無い | `components/features/manual/ScenarioEditor.svelte` (1049 行) にテイクの語が 1 つも無い |
| PC ブラウザからローカル動画を登録する経路が事実上無い | `CaptureFileFallback.svelte` は `supportsMediaRecorder()` が false のときだけ描画され、PC Chrome/Firefox/Safari では出ない (`Capture/Show.svelte` L51-53) |
| API 側 (採用・削除・presigned アップロード・プレビュー 302) は既に完成している | `routes/web.php` L594-622 の `capture.takes.*` 7 本 |
| 認可も既に編集者を通す | `TakePolicy` → `ProjectPolicy::capture()` は org owner/admin と project_admin を先に true にする |

つまり**足りないのは PC 側の画面と導線だけ**であり、サーバ側の業務ロジックはほぼ揃っている。
にもかかわらず編集者は「AI が設計したシナリオ」を見ながら「撮れた素材」を選ぶという
編集作業を PC 上でまったく行えず、採用作業を現場のスマホ側に押し付けている。

これは使命 (「編集ゼロ」= 台本作成・撮影判断・編集の 3 ハードルを肩代わりする) の
**編集ハードルの部分が PC で未着地**であることを意味する。素材の採否は現場作業者ではなく
標準を定める側 (編集者) の判断であり、そこを PC で完結できないことが最大の欠落である。

## 改善アイデア

**シナリオ編集画面に「動画」列を足し、そこから独立した「テイク選択・採用画面」へ遷移する。**

1. **動画列** (`ScenarioEditor` 内): 各手順 / 急所の行に、そのカットの登録済みテイク状況
   (件数・採用有無・採用テイクの状態) を表示し、「テイクを選択」で選択画面へ遷移する。
2. **テイク選択・採用画面** (独立した Inertia ページ): 左にテイク一覧、中央に大きな
   プレビュー再生、右 (または下) に採用・削除・アップロードの操作。
   - 「このテイクを採用する」で `cuts.adopted_take_id` を確定する
   - 採用テイクは視覚的に区別する (青枠 = DS token の primary 系 ring)
   - プレビュー上の**字幕表示 ON/OFF**、**ナレーション原稿表示 ON/OFF** (初期は両方オフ)
   - **PC ローカル動画の追加アップロード** (既存 presigned PUT フローの再利用)
   - 各テイクの削除 (確認ダイアログ。復元不可を明記)
3. **サーバ側の新設は GET 1 本だけ**。採用・削除・アップロード・プレビュー再生は
   既存 `capture.takes.*` を編集者からもそのまま使う (新しい書き込み経路を作らない)。

### 設計上の確定判断

#### D1. 画面の形態 = 独立した Inertia ページ (モーダルにしない)

- doc/04 の記述が「テイク選択画面へ**遷移**する」であり、中央に大きなプレビューを置く
  レイアウト要件 (一覧 + 中央プレビュー + 操作) はモーダルでは窮屈になる。
- `ScenarioEditor` は**クライアント側の作業コピー (`DraftStep[]`) を保持し、
  「シナリオを更新」で document 全体を 1 回の PUT で送る**設計である。
  モーダル内から `router.reload({only:[...]})` を撃つと、`ScenarioEditor` が登録している
  `router.on("before")` の dirty 離脱確認 (L652-660) が**巻き添えで発火する**。
  この guard の抑止フラグ (`reloading`) はコンポーネント内部の private state であり、
  外から握れない。独立ページなら再取得は自ページ内で完結し、この干渉が構造的に消える。
- 置き場所は規約どおり `resources/js/pages/Manuals/Takes.svelte` +
  `resources/js/components/features/manual/*` (features の domain 間横参照はしない)。

#### D2. 使う endpoint = 既存 `capture.takes.*` を再利用し、新設は GET 1 本のみ

**前提の確認 (重要): 本リポジトリに「capture session」に類する概念は存在しない。**
`capture.takes.*` は route parameter として **project / manual / cut / take しか取らない**。
認証はセッション (web guard + CSRF)、テナント境界は `project.in-current-org` middleware と
`Route::scopeBindings()` で閉じている。したがって PC 画面が追加で解決すべき状態は 1 つも無い。

| 既存 route 名 | メソッドと URI | 役割 |
|---|---|---|
| `capture.takes.upload-url` | POST `/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url` | Quota 予約 + presigned PUT 発行 |
| `capture.takes.store` | POST `/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes` | HeadObject 照合 → Take 登録 (冪等) |
| `capture.takes.adopt` | POST `.../takes/{take}/adopt` | 採用 (`cuts.adopted_take_id`) |
| `capture.takes.destroy` | DELETE `.../takes/{take}` | 削除 (DL 済みは 422) |
| `capture.takes.playback` | GET `.../takes/{take}/playback` | 302 → S3 署名 URL (`ready` のみ) |
| `capture.takes.update` / `capture.takes.downloaded` | PATCH / POST | **今回 PC からは使わない** (並べ替え・コメント・DL ACK は PWA の要件) |

- 新設: `GET /projects/{project}/manuals/{manual}/cuts/{cut}/takes`
  (`projects.manuals.cuts.takes.index`) — 画面の Inertia props を返すだけ。
- 再利用: 上表の 5 本を XHR / `<video src>` としてそのまま叩く。

##### 案の比較 (なぜ PC 専用 route を新設しないか)

| | 案 A: 既存 `capture.takes.*` 再利用 (採用) | 案 B: PC 専用 route を新設し同一 Service を呼ぶ |
|---|---|---|
| 新規 route | GET 1 本 | GET 1 本 + 変更系 4 本 + playback 1 本 = 6 本 |
| `NestedRouteDefenseInventory` 登録 | 1 route × 3 param | 6 route × 3〜4 param |
| `ControllerAuthorizationGateTest` 対象 | 増えない (GET のみ) | 変更系 4 本ぶん増える |
| 認可 | 変更なし (`TakePolicy` が既に編集者を通す) | 同左 (Policy は共用) |
| `ScenarioWritePathInventoryTest` | 変更なし | Controller が増えるだけなら変更なし |
| doc/10 の「/app は PWA 専用」 | **読み替えが要る** | 記述どおり |
| 得られるもの | — | URL の見た目の一貫性のみ |
| 失うもの | URL 空間の説明が 1 行増える | 同じ Service を呼ぶだけの Controller が 5 本増える |

案 B の追加コストに見合う利得が「URL の見た目」しか無いため案 A を採る (思考原則 2)。
**代わりに読み替えを明文化する義務を負う** — `doc/10` と
`docs/architecture.md` §撮影 PWA の運用契約、および `routes/web.php` の group コメントに
「`capture.takes.*` はテイク資源の唯一の API 面であり、撮影 PWA と PC 編集面の両方が叩く」と
書き、Feature テストで固定する (下記「必須成果物」)。

- **再利用の根拠**:
  - 両 group の middleware は**完全に同一** (`['require-active-subscription', 'project.in-current-org']`)。
    課金ゲートの内側という要件も自動的に満たす。
  - `TakePolicy` は全 ability を `ProjectPolicy::capture()` に委譲しており、
    編集者 (org owner/admin・project_admin) は既に通る。**認可の変更が 1 行も要らない。**
  - `cuts.adopted_take_id` を書くのは `Capture/CaptureTakeService::adopt()` のみで、
    ここは `ScenarioWritePathInventoryTest` 検出 4 の allowlist に登録済み。
    **PC 用に別の書き込み経路を作れば inventory 登録が要るだけでなく、
    AGENTS.md ドメイン固有規約 1 の共有ロック規約を守る実装が 2 本になる**
    (思考原則 3・4 に反する)。
  - PC 用に同等の 5 本を複製すると、同じ Service を呼ぶだけの Controller と
    `NestedRouteDefenseInventory` 登録が 5 route 分増える。得られるものは URL の見た目だけである。
- **代償として引き受けること**: `/app/*` は doc/10 §10.8-3 で「撮影 PWA 専用の URL 空間」と
  書かれている。本設計はこれを**「テイク資源の唯一の API 面であり、PC 面もここを叩く」**と
  読み替える。読み替えは `doc/10`・`docs/architecture.md` §撮影 PWA の運用契約・
  `routes/web.php` の group コメントの 3 箇所に明記し、Feature テストで
  「編集者が `/app` の take API を使える」ことを固定する (暗黙の前提にしない)。
- **URL 組み立て規則の単一化**: 現在 `/app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}` の
  文字列組み立ては `TakeStrip.svelte` の `takeUrl()` にしか無い。PC 側で 2 本目を書くと
  規則が 2 箇所に散る。`resources/js/lib/capture/take-endpoints.ts` を**唯一の導出元**として
  切り出し、`TakeStrip` もそこへ寄せる (複製を作らない)。
- **props に署名 URL を載せない**: 再生は `capture.takes.playback` (302 + `no-store, private`)
  へ `<video src>` を向けるだけにする。一覧の props に S3 署名 URL を先出ししない
  (既存 `CaptureManualDetailData` が採用テイクにだけ URL を付ける秘匿方針と揃える)。
- **ページ props の型境界**: `App\DataTransferObjects\Manual\TakeSelectionPageData` を置き、
  `toArray()` の array shape を phpdoc で固定する (PHPStan level 10 で Svelte 側 props との
  ずれを早期に落とす)。内部では既存 `CaptureCutData` を合成し、テイクの shape は二重管理しない。

#### D3. 権限境界 (編集者 project_admin / 撮影者 project_member)

| 対象 | 認可 | 撮影者 (project_member) | 編集者 (project_admin / org admin) |
|---|---|---|---|
| PC テイク選択画面 (新 GET) | `Gate::authorize('update', $manual)` (`VideoManualPolicy::update` → `ProjectPolicy::update`) | **403** | 可 |
| 採用 / 削除 / アップロード / プレビュー (既存) | `TakePolicy` → `ProjectPolicy::capture` | 可 (PWA から) | 可 |

- **非対称は意図的**。PC 編集面は編集者の面 (doc/10 §10.5 の権限表と一致。`analyze` /
  `render` / `download` と同じ扱い)、テイク資源そのものの操作は撮影者にも開いている
  (撮影者が撮った直後に PWA で採用できる既存仕様を壊さない)。
- 撮影者が PC 画面に入れなくても**詰まない**: 撮影 PWA に採用導線があり、
  `Capture/Show` にはマニュアル詳細への復路もある (T155)。

#### D4. PC ローカル動画のアップロード = 既存 presigned フローの再利用

`upload-url` (Quota 予約) → S3 presigned PUT → `POST takes` (HeadObject 三点照合 → 予約 completed)
の 3 段は `resources/js/lib/capture/upload-queue.ts` の `UploadQueue` に実装済みで、
SHA-256 算出・422 quota・409 in-flight backoff まで含む。**PC 側はこれをそのまま使う**。

- PC には IndexedDB による再送キューは要らない (オフライン撮影の要件が無い)。
  `UploadQueue` は `PendingStore` を注入で受けるので、**メモリ実装を渡すだけ**でよい
  (新しいアップロード実装を書かない)。
- ファイル選択は `MediaRecorder` の有無に依存しない `<input type="file" accept="video/*">`
  (`capture` 属性は付けない = PC ではファイルダイアログが開く)。
- 尺 1 分の上限 (doc/04) は**クライアント側の事前案内**として実装する
  (`<video>` の `loadedmetadata` で duration を読み、超過なら押下時にエラー表示)。
  サーバ側の強制は行わない — `duration_ms` はクライアント申告値であって信用できず、
  真の尺はエンコード段 (別タスク) でしか確定しないためである。
  **「1 分を超える動画は登録できない」とは書かない** (保証範囲を誇張しない)。
- **disabled にしない**: 処理中・quota 超過・尺超過はいずれも押下を受けてエラー表示する
  (AGENTS.md 禁止事項 8)。
- **メモリ `PendingStore` の contract**: `put` / `delete` / `list` を持つ既存 interface を
  そのまま満たす、インスタンス生存中だけ保持する Map 実装。PC はページ遷移で失われてよい
  (オフライン再送の要件が無い) ため永続化しない。`UploadQueue` の `resume()` は
  PC からは呼ばない (呼ばないので pending は溜まらない)。
- **完了後**: `POST takes` が 201/200 を返したら `router.reload({ only: ['cut'] })` で
  テイク一覧を取り直す (この画面には dirty guard が無いので確認ダイアログは出ない)。
- **失敗時の表示**: 422 `quota_exceeded` は容量不足の文言 + 契約プランの案内、
  409 `registration_in_flight` は「処理中です。少し待って再試行してください」、
  ネットワーク断は再試行案内。**失敗した予約行の後始末は既存の掃除 cron
  (`stale_verifying_minutes` / 期限切れ pending の release) が担う**ので、
  UI からの release 操作は作らない。

#### D5. 採用の書き込み経路 = 既存 `CaptureTakeService::adopt()` をそのまま使う

`adopt()` は対象 VideoManual を `lockForUpdate()` した同一トランザクション内で
`adopted_take_id` を書き、`rendering` / `analyzing` 中は 409、`ready` 以外は 422 を返す
(AGENTS.md ドメイン固有規約 1 (i) 準拠)。**新しい書き込み経路を作らないので
`ScenarioWritePathInventoryTest` への追加登録は発生しない。**
PC 側 UI の責務は 409 / 422 を利用者に伝えることだけである。

#### D6. ナレーション ON/OFF の読み替え (理由付き)

doc/04 は「プレビューにナレーション/字幕を ON/OFF」と書くが、**v1 は字幕のみで TTS を
実装しない確定スコープ** (doc/10 / AGENTS.md 使命の v1 スコープ注記) である。
ナレーション音声そのものが存在しないため、「ナレーション ON/OFF」を音声再生の切替として
実装すると**存在しない機能のスイッチ**になる。

したがって本設計では **「ナレーション原稿 (cuts.narration) のテキスト表示 ON/OFF」**
に読み替える。編集者がテイクを見ながら「この原稿の画がこれでよいか」を判断する用途は満たし、
TTS が入った時点で同じスイッチに音声を後付けできる。
**UI の文言も「ナレーション原稿」と書き、音声が出ると誤解させない。**

#### D7. サムネイルのフォールバック (thumbnail_path は当面 null)

`takes.thumbnail_path` は schema に存在するが**現在どこからも書かれていない**
(生成は別タスク)。書かれるまでの表示を先に決める:

- テイクのタイル (動画列 / 選択画面の一覧) は、サムネイル画像ではなく
  **状態タイル** を描く: Lucide の動画アイコン + テイク番号 + 状態バッジ
  (`uploading` / `processing` / `ready` / `failed`) + 尺 (`duration_ms` があれば)。
- **今回は `thumbnail_url` フィールドを DTO に足さない** (常に null の項目を先回りで
  作らない = 思考原則 2)。サムネイル生成タスクが `thumbnail_url` を DTO と
  `TakeThumbnail` コンポーネントの prop として足し、タイルの中身だけを差し替える。
  この差し替え点 (コンポーネント 1 つ) が今回作る**唯一の受け口**である。
- doc/04 の「ホバーで自動再生」は今回のスコープ外。一覧の全テイクに署名 URL を
  先出しすることになり、秘匿と負荷の両面で v1 に必要ない。

#### D8. 動画列と未保存行の扱い

`ScenarioEditor` の作業コピーでは、追加直後の行は `id === null` (= cut がまだ無い)。
テイクは cut に紐づくため、**未保存行の動画セルは遷移リンクを出さず
「シナリオを更新すると動画を登録できます」と案内する** (押せるのに詰むボタンを作らない)。

保存済み行から選択画面へ遷移するとき、未保存の編集があれば `ScenarioEditor` 既存の
dirty 離脱確認が発火する。これは**正しい保護**であり抑止しない。

## 期待効果

- **使命への貢献**: 「編集ゼロ」の最後の 1 ピース。編集者が PC 上で
  「シナリオ (台本) を見る → 撮れた素材を見る → 採用する」を 1 本の導線で完結できる。
  **採否の判断を PC 編集者も行えるようになる** (撮影者から権限を取り上げるわけではない。
  `TakePolicy` は変更せず、撮影者の PWA からの採用は従来どおり残る)。
- **具体的な改善見込み**:
  - **`ready` なテイクが既に存在するカットについては**、採用テイク未設定が原因の
    レンダ 422 (`AdoptedReadyTakeCoverage`) を PC 上でその場で解消できる。
    テイクが `uploading` / `processing` / `failed` のとき、および manual が
    `rendering` / `analyzing` のとき (409) は解消できず、その理由を画面に出す。
  - PC 手元にある既存動画 (過去に撮った mp4 等) をマニュアルへ取り込めるようになる。
  - 撮影 PWA を使わない編集者 (PC のみの利用者) が、素材の取り込みから採用まで
    PC だけで完了できるようになる。

## 実装方針 (概要)

**4 施策すべてが完了条件である** (優先度ではなく**実装順序**を示す)。
アップロードを落とすと「PC だけで業務を完了できる」という効果が成立しないため、
今回 P2 に落とす施策は置かない。

| 順 | 施策 | 主な変更 |
|---|---|---|
| 1 | テイク選択・採用画面の新設 | 新 GET route + `Projects\CutTakeController` + `TakeSelectionPageData` + `pages/Manuals/Takes.svelte` + `features/manual/{TakePickerList,TakePreviewPanel,TakeThumbnail}.svelte` + `lib/capture/take-endpoints.ts` 切り出し + `NestedRouteDefenseInventory` 登録 |
| 2 | 字幕 overlay の共有化と表示 ON/OFF | `features/capture/SubtitleOverlay.svelte` → `molecules/SubtitleOverlay.svelte` へ昇格 (`CameraRecorder` の import とテストも移す)、ナレーション原稿トグル |
| 3 | シナリオ編集画面の「動画」列 | `VideoManualController::edit` に `takeSummaries` props 追加 + `ScenarioEditor` に動画セル |
| 4 | PC ローカル動画のアップロード | `UploadQueue` + メモリ `PendingStore` の再利用、`features/manual/TakeFileUpload.svelte`、尺の事前案内 |

- 施策 2 で `SubtitleOverlay` を molecules へ上げるのは、**features/manual から
  features/capture を横参照できない**規約 (atomic-import-graph) を、複製ではなく
  共有化で満たすためである。`SubtitleOverlay` は props だけで描画する無状態の
  表示部品であり molecules の要件を満たす。移設と同時に旧位置は消す (思考原則 3)。
- 施策 3 の `takeSummaries` は **1 cut あたり 4 フィールドに限る**
  (`cut_id` / `takes_count` / `adopted_take_id` / `adopted_take_status`)。
  取得は `$manual->cuts()->withCount('takes')->with('adoptedTake:id,status')` の
  **1 クエリ集約**で行い、cut ごとの追加クエリ (N+1) を作らない。

### 必須成果物 (テスト・目録・ドキュメント)

実装完了と呼ぶために以下を含める (AGENTS.md 禁止事項 1)。

**Feature テスト (Pest)**
1. 編集者が新 GET 画面に到達できる / **撮影者 (project_member) は 403**
2. 編集者が `/app` の take API から **upload-url / store / adopt / destroy / playback を実行できる**
   (PC 導線でも認可が通ることの固定 = D2 の読み替えの機械化)
3. cross-org / cross-project / cross-manual / cross-cut は**認可より前に 404**
   (新 GET と、PC から叩く既存 route の両方。既存側は回帰確認)
4. 保護キー (`cut_id` / `organization_id` / `video_path` 等) の直送は 422
5. `rendering` / `analyzing` 中の adopt は 409、`ready` 以外の adopt は 422、
   DL 済みテイクの削除は 422 (いずれも既存挙動の PC 経路からの確認)
6. `require-active-subscription` 未充足の組織は新 GET が onboarding へ遮断される

**Architecture テスト (目録)**
7. `NestedRouteDefenseInventory` に新 route の 3 parameter を登録
   (未登録なら `NestedRouteIdorDefenseTest` が deny-by-default で落ちる)
8. `ScenarioWritePathInventoryTest` は**変更なし**であることを確認
   (新しい書き込み経路を作っていないことの裏取り)

**Vitest**
9. 動画列: 保存済み行はリンクを出す / 未保存行 (`id === null`) は案内文を出す
10. 選択画面: 採用テイクの視覚的区別、字幕 ON/OFF (初期オフ)、ナレーション原稿 ON/OFF (初期オフ)、
    `processing` / `failed` テイクは採用押下でエラー表示 (**disabled にしない**)、
    削除確認ダイアログ (復元不可の明示)、サムネイル未生成時のフォールバックタイル
11. `take-endpoints.ts` の URL 組み立て (既存 `TakeStrip` の回帰を含む)

**目録・ドキュメント**
12. `.claude/skills/app-bug-hunt/inventory/annotations.toml` へ新 route の注釈を 1 行足し目録を再生成
13. `doc/10` / `docs/architecture.md` §撮影 PWA の運用契約 / `routes/web.php` group コメントに
    「`capture.takes.*` は PWA と PC 編集面の共用 API 面」を明記

## 制約・前提

- **課金ゲートの内側**: 新 route は `routes/web.php` の
  `require-active-subscription` group 内 (業務 route の group) に置く (ドメイン固有規約 4)。
- **nested route の 3 層**: `{project} ∈ current org` (middleware + inline guard) /
  `{manual} ∈ {project}` / `{cut} ∈ {manual}` (`Route::scopeBindings()`)。
  不整合は**認可より前に 404**。`NestedRouteDefenseInventory` へ 3 parameter を登録する
  (登録しないと `NestedRouteIdorDefenseTest` が deny-by-default で落ちる)。
- **`response()->json()` 直書き禁止**: 画面は Inertia props、書き込み応答は既存の
  `CaptureTakeResource` / `CaptureCutResource` をそのまま使う (新規 Resource は作らない)。
- **PHPStan level 10**: props 組み立ては既存 `CaptureCutData` / `CaptureTakeData` の
  `toArray()` (配列 shape が phpdoc で固定済み) を再利用する。
- **DS token / Atomic Design**: 青枠は `ring-primary` 等の token 経由 (hex 直書き禁止)。
  アイコンは `@lucide/svelte` のみ。
- **bug-hunt 目録**: web route を 1 本足すので
  `.claude/skills/app-bug-hunt/inventory/annotations.toml` に注釈を 1 行足して
  目録を再生成する (AGENTS.md bug-hunt 節)。
- **ドキュメント**: `docs/architecture.md` §撮影 PWA の運用契約 に
  「PC 面も `capture.takes.*` を叩く」ことと保証範囲を追記する。

## スコープ外

- サムネイル画像の生成と表示 (別タスク。今回は状態タイルのフォールバックのみ)
- ホバー自動再生
- PC 側からのテイク並べ替え・コメント編集 (doc/04 のテイク選択画面の要件に無い。PWA 側に既存)
- ナレーション音声の再生 (v1 は TTS 非実装。D6)
- サーバ側での尺 1 分の強制 (D4)
- 多言語 (字幕の言語切替)。doc/04 にはあるが v1 スコープ外
- 撮影者 (project_member) 向けの PC 編集面
- テイク資源の PC 専用 route の新設 (D2 で既存再利用と決定)
- **採用権限の編集者への限定** (`TakePolicy::adopt` は撮影者に開いたまま。doc/10 §10.5 の
  確定仕様であり、変えるなら別タスクの議題)
- PC 側でのオフライン再送 / IndexedDB キュー (PWA 固有の要件)
