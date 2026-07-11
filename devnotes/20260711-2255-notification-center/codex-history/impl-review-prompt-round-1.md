# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# セキュリティ不変条件(アプリ都合で緩めない)

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は認可より前に 404
3. **cross-org 不可**: 組織を跨ぐ read/write をしない
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

# 役割・タスク

あなたはシニア Laravel 12 / Svelte 5 (Inertia) エンジニアとして、TODO T008「アプリ内通知センター (ジョブ完了/招待/残高)」の**最終実装レビュー**を行う。

対象は main と todo/T008 ブランチの差分全体（下記 user 部に diff 全文を添付。未コミットの直近修正を含む作業ツリー状態）。設計ドキュメントは `/workspace/devnotes/20260711-2255-notification-center/detailed-design.md` を参照してよい。実装ファイル本体は `/workspace/.claude/worktrees/tasks/T008/` 配下にある（読み込み可）。

前段レビューで指摘された Warning「/notifications/{notification}/* に UUID 制約が無く、非UUID id が pgsql uuid 比較で 22P02 → 500 になる」は、`->whereUuid('notification')`(routes/web.php) と 非UUID id 404 の Feature テスト追加(tests/Feature/Notifications/NotificationCenterTest.php) で対応済み。この対応の妥当性確認も含めてレビューせよ。

観点:
1. **正当性バグ**: ロジック誤り、境界条件、null/空配列、通知 payload スキーマ不整合、既読/開封の状態遷移
2. **セキュリティ不変条件違反**: 上記 8 項目、特に cross-user 通知アクセス(存在オラクル封じ = 404)、tenant キー不信、認可
3. **規約違反**: 禁止事項 1〜8、DTO/Inertia 経由、Svelte 5 runes、atomic import 階層、DS token 純度
4. **テスト網羅**: 重要パス・異常系のテスト漏れ
5. **設計一致**: detailed-design.md との乖離

出力形式: 指摘を [Critical] / [Warning] / [Suggestion] に分類し、各指摘に該当ファイル・行・根拠・修正案を付す。問題が無ければ「Critical なし」と明言する。

---

# user 部: main..todo/T008 diff 全文

```diff
diff --git a/.env.example b/.env.example
index 1f2f951..bdac9fe 100644
--- a/.env.example
+++ b/.env.example
@@ -89,6 +89,8 @@ STRIPE_PORTAL_CONFIGURATION_ID=
 #BILLING_SIGNUP_GRANT_TICKETS=10
 #BILLING_SIGNUP_GRANT_EXPIRY_DAYS=30
 #BILLING_TICKET_UNIT_PRICE_FLOOR=50
+# チケット残高低下のアプリ内通知閾値 (config/billing.php。未設定時の既定: 5 枚)
+#BILLING_TICKET_LOW_BALANCE_THRESHOLD=5
 
 # --- render (config/manual.php。レンダ worker ホストの ffmpeg/ffprobe 要件) ---
 # ffmpeg / ffprobe のバイナリパス (未設定なら PATH 上の ffmpeg / ffprobe)
diff --git a/app/DataTransferObjects/Notification/InvitationReceivedPayload.php b/app/DataTransferObjects/Notification/InvitationReceivedPayload.php
new file mode 100644
index 0000000..7a2a8a3
--- /dev/null
+++ b/app/DataTransferObjects/Notification/InvitationReceivedPayload.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Notification;
+
+/**
+ * 組織招待の気づき通知の表示用 payload。
+ *
+ * 平文 token は含めない (token 平文非保存の既存不変条件。受諾はメールのリンクから行う)。
+ * organizationName は発火時点のスナップショット。
+ */
+final readonly class InvitationReceivedPayload
+{
+    public function __construct(
+        public string $organizationName,
+    ) {}
+
+    /**
+     * @return array{organization_name: string}
+     */
+    public function toArray(): array
+    {
+        return ['organization_name' => $this->organizationName];
+    }
+
+    /**
+     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
+     *
+     * @param  array<array-key, mixed>  $data
+     */
+    public static function tryFromArray(array $data): ?self
+    {
+        $organizationName = $data['organization_name'] ?? null;
+        if (! is_string($organizationName)) {
+            return null;
+        }
+
+        return new self($organizationName);
+    }
+}
diff --git a/app/DataTransferObjects/Notification/ManualJobPayload.php b/app/DataTransferObjects/Notification/ManualJobPayload.php
new file mode 100644
index 0000000..75a42d8
--- /dev/null
+++ b/app/DataTransferObjects/Notification/ManualJobPayload.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Notification;
+
+/**
+ * 解析/レンダ完了通知の表示用 payload (manual_analyzed / manual_rendered 共用)。
+ *
+ * manualTitle / organizationName は発火時点のスナップショット
+ * (manual 削除・org 改名・退会後も当時の名前で本文表示できる。join 不要)。
+ * org 判定には使わない (org 文脈は notifications.organization_id 列が正)。
+ */
+final readonly class ManualJobPayload
+{
+    public function __construct(
+        public int $projectId,
+        public int $manualId,
+        public string $manualTitle,
+        public string $organizationName,
+        public bool $succeeded,
+        public ?string $error,
+    ) {}
+
+    /**
+     * @return array{project_id: int, manual_id: int, manual_title: string,
+     *   organization_name: string, succeeded: bool, error: string|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'project_id' => $this->projectId,
+            'manual_id' => $this->manualId,
+            'manual_title' => $this->manualTitle,
+            'organization_name' => $this->organizationName,
+            'succeeded' => $this->succeeded,
+            'error' => $this->error,
+        ];
+    }
+
+    /**
+     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
+     *
+     * @param  array<array-key, mixed>  $data
+     */
+    public static function tryFromArray(array $data): ?self
+    {
+        $projectId = $data['project_id'] ?? null;
+        $manualId = $data['manual_id'] ?? null;
+        $manualTitle = $data['manual_title'] ?? null;
+        $organizationName = $data['organization_name'] ?? null;
+        $succeeded = $data['succeeded'] ?? null;
+        $error = $data['error'] ?? null;
+
+        if (! is_int($projectId) || ! is_int($manualId)
+            || ! is_string($manualTitle) || ! is_string($organizationName)
+            || ! is_bool($succeeded)
+            || ($error !== null && ! is_string($error))) {
+            return null;
+        }
+
+        return new self($projectId, $manualId, $manualTitle, $organizationName, $succeeded, $error);
+    }
+}
diff --git a/app/DataTransferObjects/Notification/NotificationListItemData.php b/app/DataTransferObjects/Notification/NotificationListItemData.php
new file mode 100644
index 0000000..da9802e
--- /dev/null
+++ b/app/DataTransferObjects/Notification/NotificationListItemData.php
@@ -0,0 +1,114 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Notification;
+
+use App\Enums\Notification\NotificationType;
+use Carbon\CarbonInterface;
+use Illuminate\Notifications\DatabaseNotification;
+use Webmozart\Assert\Assert;
+
+/**
+ * 通知一覧の読み出し境界 DTO (DatabaseNotification の生配列をページへ渡さない)。
+ *
+ * - type は NotificationType::tryFrom(rawType)。未知 type (enum⇔DB ドリフト時) は null
+ * - rawType は DB の type 文字列そのまま (常に保持 = fallback 表示・toArray の正)
+ * - payload は種別ごとの DTO 検証復元 (tryFromArray)。復元失敗は null
+ *   (フロントは rawType で fallback 描画)
+ */
+final readonly class NotificationListItemData
+{
+    public function __construct(
+        public string $id,
+        public ?NotificationType $type,
+        public string $rawType,
+        public ?int $organizationId,
+        public ?string $readAt,
+        public string $createdAt,
+        public ManualJobPayload|InvitationReceivedPayload|TicketBalanceLowPayload|null $payload,
+    ) {}
+
+    public static function fromNotification(DatabaseNotification $notification): self
+    {
+        $rawType = $notification->getAttribute('type');
+        Assert::string($rawType);
+        $type = NotificationType::tryFrom($rawType);
+
+        // organization_id は morph 生モデルの attribute 取得のため is_int で検証する
+        $organizationId = $notification->getAttribute('organization_id');
+        $organizationId = is_int($organizationId) ? $organizationId : null;
+
+        $data = $notification->getAttribute('data');
+        $data = is_array($data) ? $data : [];
+
+        $payload = match ($type) {
+            NotificationType::ManualAnalyzed,
+            NotificationType::ManualRendered => ManualJobPayload::tryFromArray($data),
+            NotificationType::InvitationReceived => InvitationReceivedPayload::tryFromArray($data),
+            NotificationType::TicketBalanceLow => TicketBalanceLowPayload::tryFromArray($data),
+            null => null,
+        };
+
+        $readAt = $notification->getAttribute('read_at');
+        $createdAt = $notification->getAttribute('created_at');
+
+        $id = $notification->getKey();
+        Assert::string($id); // notifications.id は uuid (string PK)
+
+        return new self(
+            id: $id,
+            type: $type,
+            rawType: $rawType,
+            organizationId: $organizationId,
+            readAt: $readAt instanceof CarbonInterface ? $readAt->toIso8601String() : null,
+            createdAt: $createdAt instanceof CarbonInterface ? $createdAt->toIso8601String() : '',
+            payload: $payload,
+        );
+    }
+
+    /**
+     * manual 系 (解析/レンダ) の通知として遷移解決できるか
+     * (type と payload 形状の両方が揃っているときのみ true)。
+     */
+    public function isManualJob(): bool
+    {
+        return in_array($this->type, [NotificationType::ManualAnalyzed, NotificationType::ManualRendered], true)
+            && $this->payload instanceof ManualJobPayload;
+    }
+
+    /** isManualJob() === true の場合のみ呼び出せる */
+    public function projectId(): int
+    {
+        Assert::isInstanceOf($this->payload, ManualJobPayload::class);
+
+        return $this->payload->projectId;
+    }
+
+    /** isManualJob() === true の場合のみ呼び出せる */
+    public function manualId(): int
+    {
+        Assert::isInstanceOf($this->payload, ManualJobPayload::class);
+
+        return $this->payload->manualId;
+    }
+
+    /**
+     * Inertia typed array (TS 側 NotificationItem と対)。
+     * type は常に rawType を返す (TS 側 discriminant。未知値は fallback 描画)。
+     *
+     * @return array{id: string, type: string, organization_id: int|null,
+     *   read_at: string|null, created_at: string, payload: array<string, int|string|bool|null>|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->id,
+            'type' => $this->rawType,
+            'organization_id' => $this->organizationId,
+            'read_at' => $this->readAt,
+            'created_at' => $this->createdAt,
+            'payload' => $this->payload?->toArray(),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Notification/TicketBalanceLowPayload.php b/app/DataTransferObjects/Notification/TicketBalanceLowPayload.php
new file mode 100644
index 0000000..38d64d6
--- /dev/null
+++ b/app/DataTransferObjects/Notification/TicketBalanceLowPayload.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Notification;
+
+/**
+ * チケット残高低下通知の表示用 payload。
+ *
+ * balance は Reserved 拘束を含む「実効残高」(ユーザーが今トリガーできるかに一致する値。
+ * クロス判定のセマンティクスは TicketLedgerService::reserve のコメント参照)。
+ */
+final readonly class TicketBalanceLowPayload
+{
+    public function __construct(
+        public string $organizationName,
+        public int $balance,
+        public int $threshold,
+    ) {}
+
+    /**
+     * @return array{organization_name: string, balance: int, threshold: int}
+     */
+    public function toArray(): array
+    {
+        return [
+            'organization_name' => $this->organizationName,
+            'balance' => $this->balance,
+            'threshold' => $this->threshold,
+        ];
+    }
+
+    /**
+     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
+     *
+     * @param  array<array-key, mixed>  $data
+     */
+    public static function tryFromArray(array $data): ?self
+    {
+        $organizationName = $data['organization_name'] ?? null;
+        $balance = $data['balance'] ?? null;
+        $threshold = $data['threshold'] ?? null;
+
+        if (! is_string($organizationName) || ! is_int($balance) || ! is_int($threshold)) {
+            return null;
+        }
+
+        return new self($organizationName, $balance, $threshold);
+    }
+}
diff --git a/app/Enums/Notification/NotificationType.php b/app/Enums/Notification/NotificationType.php
new file mode 100644
index 0000000..b970f41
--- /dev/null
+++ b/app/Enums/Notification/NotificationType.php
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Notification;
+
+/**
+ * アプリ内通知の type (単一の正)。
+ *
+ * - DB (notifications.type) には本 enum の value を格納する (クラス名を DB に置かない。
+ *   AppNotification::databaseType() 経由。InAppNotificationTypeInvariantTest が強制)
+ * - TS 側 resources/js/types/notification.ts の literal union と値集合を一致させる
+ *   (NotificationTypeTsSyncInvariantTest が固定)
+ */
+enum NotificationType: string
+{
+    case ManualAnalyzed = 'manual_analyzed';
+    case ManualRendered = 'manual_rendered';
+    case InvitationReceived = 'invitation_received';
+    case TicketBalanceLow = 'ticket_balance_low';
+}
diff --git a/app/Http/Controllers/NotificationController.php b/app/Http/Controllers/NotificationController.php
new file mode 100644
index 0000000..d95f800
--- /dev/null
+++ b/app/Http/Controllers/NotificationController.php
@@ -0,0 +1,142 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers;
+
+use App\DataTransferObjects\Notification\NotificationListItemData;
+use App\Enums\Notification\NotificationType;
+use App\Models\User;
+use App\Services\Notification\NotificationCenterService;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Notifications\DatabaseNotification;
+use Inertia\Inertia;
+use Inertia\Response;
+use Webmozart\Assert\Assert;
+
+/**
+ * 通知センター (一覧 / open 遷移 / 既読化)。薄い Controller + Service 委譲。
+ *
+ * - {notification} は implicit binding を使わず Service が $user->notifications() 経由で
+ *   解決する (cross-user は構造的に 404 = 存在オラクル封じ。403 で存在を漏らさない。
+ *   1 param ルートのため NestedRouteIdorDefenseTest の inventory 対象外)
+ * - open は POST + 303 (GET にしない = prefetch/リンクプレビューによる意図しない既読化防止)
+ * - open は認可判断 (Gate) を一切複製しない。行うのは (a) 自通知の organization_id と
+ *   current org の突合 (自分のデータ同士のルーティング判断) と (b) org→project→manual の
+ *   relation 連鎖による存在解決のみ (「認可より前の 404」層の再利用。Gate::authorize は
+ *   遷移先 projects.manuals.show が唯一の判断点)。(b) と遷移の間の TOCTOU
+ *   (redirect 直後の削除) は遷移先の標準 404 が受ける (残余は許容)
+ */
+class NotificationController extends Controller
+{
+    public function __construct(private readonly NotificationCenterService $notifications) {}
+
+    /** 通知一覧 (全 org 横断 = 自分宛のみで構造的に閉じる) */
+    public function index(Request $request): Response
+    {
+        $user = $this->authedUser($request);
+        $paginator = $this->notifications->paginateFor($user);
+
+        $items = [];
+        foreach ($paginator->items() as $notification) {
+            Assert::isInstanceOf($notification, DatabaseNotification::class);
+            $items[] = NotificationListItemData::fromNotification($notification)->toArray();
+        }
+
+        return Inertia::render('Notifications/Index', [
+            'notifications' => $items,
+            // 既存 ManualListItem のページャ shape (ProjectController::manualRows) と同形
+            'meta' => [
+                'current_page' => $paginator->currentPage(),
+                'last_page' => $paginator->lastPage(),
+                'per_page' => $paginator->perPage(),
+                'total' => $paginator->total(),
+            ],
+        ]);
+    }
+
+    /** 既読化 + 遷移先のサーバ解決 (POST + 303。開けない場合は一覧へ明示 redirect) */
+    public function open(Request $request, string $notification): RedirectResponse
+    {
+        $user = $this->authedUser($request);
+        $found = $this->notifications->findOwnOrFail($user, $notification); // cross-user 404
+        $this->notifications->markRead($found);
+
+        $item = NotificationListItemData::fromNotification($found);
+
+        // 遷移はすべて 303 (POST → GET の意味論を明示。Inertia の POST visit とも整合)
+        return match (true) {
+            // manual 系: 通知 org ≠ current org → 案内して一覧へ (自動 org 切替はしない = 驚き最小)
+            $item->isManualJob() && ! $this->belongsToCurrentOrg($user, $item) => redirect()
+                ->route('notifications.index', [], 303)
+                ->with('info', 'この通知は別の組織のものです。組織を切り替えてから開いてください。'),
+            // manual 系: current org → project → manual の relation 連鎖で現存する → manual 画面へ
+            $item->isManualJob() && $this->manualStillExists($user, $item) => redirect()
+                ->route('projects.manuals.show', [$item->projectId(), $item->manualId()], 303),
+            $item->isManualJob() => redirect()
+                ->route('notifications.index', [], 303)
+                ->with('info', '対象の動画マニュアルは削除されています。'),
+            $item->type === NotificationType::TicketBalanceLow => redirect()
+                ->route('billing.tickets.show', [], 303),
+            $item->type === NotificationType::InvitationReceived => redirect()
+                ->route('notifications.index', [], 303)
+                ->with('info', '招待はメールの受諾リンクから参加してください。'),
+            // 未知 type (enum⇔DB ドリフト時の防御): 既読化のみ・汎用文言
+            default => redirect()
+                ->route('notifications.index', [], 303)
+                ->with('info', 'この通知には開ける対象がありません。'),
+        };
+    }
+
+    /** 1 件既読化 (back() 完結) */
+    public function read(Request $request, string $notification): RedirectResponse
+    {
+        $user = $this->authedUser($request);
+        $this->notifications->markRead($this->notifications->findOwnOrFail($user, $notification));
+
+        return back();
+    }
+
+    /** 一括既読化 (back() 完結) */
+    public function readAll(Request $request): RedirectResponse
+    {
+        $this->notifications->markAllRead($this->authedUser($request));
+
+        return back()->with('success', 'すべての通知を既読にしました');
+    }
+
+    /** admin guard 追加で user() は union になるため User へ narrowing する */
+    private function authedUser(Request $request): User
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        return $user;
+    }
+
+    /** 通知の org 文脈 (organization_id 列) が current org と一致するか (認可判断ではない) */
+    private function belongsToCurrentOrg(User $user, NotificationListItemData $item): bool
+    {
+        return $item->organizationId !== null
+            && $item->organizationId === $user->current_organization_id;
+    }
+
+    /**
+     * current org → projects() → manuals の relation 連鎖による存在解決 (exists() 1 クエリ。
+     * 認可判断なし = 「認可より前の 404」層の再利用)。
+     */
+    private function manualStillExists(User $user, NotificationListItemData $item): bool
+    {
+        $organization = $user->currentOrganization;
+        if ($organization === null) {
+            return false;
+        }
+
+        return $organization->projects()
+            ->whereKey($item->projectId())
+            ->whereHas('manuals', fn (Builder $query): Builder => $query->whereKey($item->manualId()))
+            ->exists();
+    }
+}
diff --git a/app/Http/Controllers/Projects/ManualAnalysisController.php b/app/Http/Controllers/Projects/ManualAnalysisController.php
index 0e2163f..c538d82 100644
--- a/app/Http/Controllers/Projects/ManualAnalysisController.php
+++ b/app/Http/Controllers/Projects/ManualAnalysisController.php
@@ -11,6 +11,7 @@
 use App\Http\Resources\Manual\AnalysisJobResource;
 use App\Models\AnalysisJob;
 use App\Models\Project;
+use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Manual\AnalysisJobService;
 use Illuminate\Http\JsonResponse;
@@ -37,7 +38,9 @@ public function store(AnalyzeManualRequest $request, Project $project, VideoManu
         $this->resolveOrganizationProject($organization, $project);
         Gate::authorize('analyze', $manual);
 
-        $job = $analysis->trigger($project, $manual);
+        // actor = ジョブ実行者 (通知宛先の導出用。Auth から明示的に渡す = payload 不信任)
+        $actor = $request->user();
+        $job = $analysis->trigger($project, $manual, $actor instanceof User ? $actor : null);
         $manual->refresh(); // trigger で analyzing へ遷移済み
 
         return AnalysisJobResource::make(AnalysisJobData::fromJob($job, $manual))
diff --git a/app/Http/Controllers/Projects/ManualRenderController.php b/app/Http/Controllers/Projects/ManualRenderController.php
index f605d7a..03bc57d 100644
--- a/app/Http/Controllers/Projects/ManualRenderController.php
+++ b/app/Http/Controllers/Projects/ManualRenderController.php
@@ -13,6 +13,7 @@
 use App\Http\Resources\Manual\RenderJobResource;
 use App\Models\Project;
 use App\Models\RenderJob;
+use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Manual\RenderJobService;
 use App\Services\Render\RenderObjectStorage;
@@ -45,7 +46,9 @@ public function store(TriggerRenderRequest $request, Project $project, VideoManu
         $this->resolveOrganizationProject($organization, $project);
         Gate::authorize('render', $manual);
 
-        $job = $render->trigger($project, $manual);
+        // actor = ジョブ実行者 (通知宛先の導出用。Auth から明示的に渡す = payload 不信任)
+        $actor = $request->user();
+        $job = $render->trigger($project, $manual, $actor instanceof User ? $actor : null);
         $manual->refresh(); // trigger で rendering へ遷移済み
 
         return RenderJobResource::make(RenderJobData::fromJob($job, $manual))
@@ -61,7 +64,8 @@ public function preview(TriggerRenderRequest $request, Project $project, VideoMa
         $this->resolveOrganizationProject($organization, $project);
         Gate::authorize('render', $manual); // preview も編集者専用 (§10.5)
 
-        $job = $render->triggerPreview($project, $manual);
+        $actor = $request->user();
+        $job = $render->triggerPreview($project, $manual, $actor instanceof User ? $actor : null);
         $manual->refresh();
 
         return RenderJobResource::make(RenderJobData::fromJob($job, $manual))
diff --git a/app/Http/Middleware/HandleInertiaRequests.php b/app/Http/Middleware/HandleInertiaRequests.php
index 7021663..5f0c378 100644
--- a/app/Http/Middleware/HandleInertiaRequests.php
+++ b/app/Http/Middleware/HandleInertiaRequests.php
@@ -59,6 +59,11 @@ public function share(Request $request): array
             ],
             'organizations' => $this->organizationsProp($user),
             'currentOrganization' => $this->currentOrganizationProp($user),
+            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
+            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
+            'notifications' => [
+                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
+            ],
             'flash' => [
                 'success' => $request->session()->get('success'),
                 'error' => $request->session()->get('error'),
diff --git a/app/Models/AnalysisJob.php b/app/Models/AnalysisJob.php
index b2cff5f..3a814a8 100644
--- a/app/Models/AnalysisJob.php
+++ b/app/Models/AnalysisJob.php
@@ -27,6 +27,7 @@
  * @property AnalysisStep|null $step
  * @property int|null $progress
  * @property int|null $ticket_reservation_id
+ * @property int|null $triggered_by
  * @property array<array-key, mixed>|null $result_json
  * @property string|null $error
  * @property Carbon|null $created_at
@@ -73,4 +74,14 @@ public function ticketReservation(): BelongsTo
     {
         return $this->belongsTo(TicketReservation::class);
     }
+
+    /**
+     * ジョブ実行者 (通知宛先の導出用。Auth からの明示代入のみ = 保護キー)。
+     *
+     * @return BelongsTo<User, $this>
+     */
+    public function triggeredBy(): BelongsTo
+    {
+        return $this->belongsTo(User::class, 'triggered_by');
+    }
 }
diff --git a/app/Models/RenderJob.php b/app/Models/RenderJob.php
index b2f309e..fa996bd 100644
--- a/app/Models/RenderJob.php
+++ b/app/Models/RenderJob.php
@@ -31,6 +31,7 @@
  * @property int|null $progress
  * @property int $scenario_version
  * @property int|null $ticket_reservation_id
+ * @property int|null $triggered_by
  * @property string|null $output_path
  * @property string|null $error
  * @property RenderErrorCode|null $error_code
@@ -72,4 +73,14 @@ public function ticketReservation(): BelongsTo
     {
         return $this->belongsTo(TicketReservation::class);
     }
+
+    /**
+     * ジョブ実行者 (通知宛先の導出用。Auth からの明示代入のみ = 保護キー)。
+     *
+     * @return BelongsTo<User, $this>
+     */
+    public function triggeredBy(): BelongsTo
+    {
+        return $this->belongsTo(User::class, 'triggered_by');
+    }
 }
diff --git a/app/Notifications/Channels/OrganizationScopedDatabaseChannel.php b/app/Notifications/Channels/OrganizationScopedDatabaseChannel.php
new file mode 100644
index 0000000..ebf621d
--- /dev/null
+++ b/app/Notifications/Channels/OrganizationScopedDatabaseChannel.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Channels;
+
+use App\Notifications\InApp\AppNotification;
+use Illuminate\Notifications\Channels\DatabaseChannel;
+use Illuminate\Notifications\Notification;
+
+/**
+ * 標準 DatabaseChannel の公式拡張点 (buildPayload) に organization_id 列をマージする薄い層。
+ * AppServiceProvider で DatabaseChannel::class に container binding して差し替える
+ * (ChannelManager::createDatabaseDriver は container 経由で解決するため binding が効く)。
+ * AppNotification 以外の通知は素通し (後方互換)。
+ */
+class OrganizationScopedDatabaseChannel extends DatabaseChannel
+{
+    /**
+     * @param  mixed  $notifiable
+     * @return array<mixed> (親シグネチャ互換。実体は DatabaseNotification の列名 => 値)
+     */
+    protected function buildPayload($notifiable, Notification $notification): array
+    {
+        $payload = parent::buildPayload($notifiable, $notification);
+
+        if ($notification instanceof AppNotification) {
+            $payload['organization_id'] = $notification->organizationId();
+        }
+
+        return $payload;
+    }
+}
diff --git a/app/Notifications/InApp/AppNotification.php b/app/Notifications/InApp/AppNotification.php
new file mode 100644
index 0000000..71ec5aa
--- /dev/null
+++ b/app/Notifications/InApp/AppNotification.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\InApp;
+
+use App\Enums\Notification\NotificationType;
+use Illuminate\Notifications\Notification;
+
+/**
+ * アプリ内 (database channel) 通知の共通基底。
+ *
+ * - via() は database のみ (メール系既存 Notification とはクラス階層ごと分離)
+ * - databaseType() は NotificationType enum の value を返す (クラス名を DB に漏らさない。
+ *   規約は InAppNotificationTypeInvariantTest が全派生クラスに deny-by-default で強制)
+ * - organizationId() は notifications.organization_id 列の値
+ *   (OrganizationScopedDatabaseChannel が読む)。v1 の全通知種別は org 文脈必須のため
+ *   non-nullable (DB 列は将来の org 非依存通知に備え nullable のままだが、
+ *   「null を書く通知種別は現状存在しない」を NotificationSchemaTest が固定する)
+ */
+abstract class AppNotification extends Notification
+{
+    /**
+     * @return list<string>
+     */
+    public function via(object $notifiable): array
+    {
+        return ['database'];
+    }
+
+    public function databaseType(object $notifiable): string
+    {
+        return $this->type()->value;
+    }
+
+    abstract public function type(): NotificationType;
+
+    abstract public function organizationId(): int;
+
+    /**
+     * 実装は payload DTO の toArray() を返すのみ (array<string, mixed> を裸で流さない)。
+     *
+     * @return array<string, int|string|bool|null>
+     */
+    abstract public function toDatabase(object $notifiable): array;
+}
diff --git a/app/Notifications/InApp/InvitationReceivedNotification.php b/app/Notifications/InApp/InvitationReceivedNotification.php
new file mode 100644
index 0000000..4fb4afa
--- /dev/null
+++ b/app/Notifications/InApp/InvitationReceivedNotification.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\InApp;
+
+use App\DataTransferObjects\Notification\InvitationReceivedPayload;
+use App\Enums\Notification\NotificationType;
+
+/**
+ * 組織招待の気づき通知 (既存ユーザー宛のみ)。メール招待の補完であり置換ではない
+ * (受諾はメールの受諾リンクから。平文 token は payload に含めない)。
+ */
+final class InvitationReceivedNotification extends AppNotification
+{
+    public function __construct(
+        private readonly int $organizationId,
+        private readonly InvitationReceivedPayload $payload,
+    ) {}
+
+    public function type(): NotificationType
+    {
+        return NotificationType::InvitationReceived;
+    }
+
+    public function organizationId(): int
+    {
+        return $this->organizationId;
+    }
+
+    /**
+     * @return array<string, int|string|bool|null>
+     */
+    public function toDatabase(object $notifiable): array
+    {
+        return $this->payload->toArray();
+    }
+}
diff --git a/app/Notifications/InApp/ManualAnalyzedNotification.php b/app/Notifications/InApp/ManualAnalyzedNotification.php
new file mode 100644
index 0000000..56b77dc
--- /dev/null
+++ b/app/Notifications/InApp/ManualAnalyzedNotification.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\InApp;
+
+use App\DataTransferObjects\Notification\ManualJobPayload;
+use App\Enums\Notification\NotificationType;
+
+/**
+ * AI 解析ジョブの terminal (succeeded/failed) 通知。宛先は creator ∪ triggeredBy
+ * (NotificationCenterService::notifyAnalysisFinished が組み立てる)。
+ */
+final class ManualAnalyzedNotification extends AppNotification
+{
+    public function __construct(
+        private readonly int $organizationId,
+        private readonly ManualJobPayload $payload,
+    ) {}
+
+    public function type(): NotificationType
+    {
+        return NotificationType::ManualAnalyzed;
+    }
+
+    public function organizationId(): int
+    {
+        return $this->organizationId;
+    }
+
+    /**
+     * @return array<string, int|string|bool|null>
+     */
+    public function toDatabase(object $notifiable): array
+    {
+        return $this->payload->toArray();
+    }
+}
diff --git a/app/Notifications/InApp/ManualRenderedNotification.php b/app/Notifications/InApp/ManualRenderedNotification.php
new file mode 100644
index 0000000..8588e26
--- /dev/null
+++ b/app/Notifications/InApp/ManualRenderedNotification.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\InApp;
+
+use App\DataTransferObjects\Notification\ManualJobPayload;
+use App\Enums\Notification\NotificationType;
+
+/**
+ * レンダジョブ (kind=render のみ) の terminal (succeeded/failed) 通知。
+ * preview は通知しない (NotificationCenterService::notifyRenderFinished が guard)。
+ */
+final class ManualRenderedNotification extends AppNotification
+{
+    public function __construct(
+        private readonly int $organizationId,
+        private readonly ManualJobPayload $payload,
+    ) {}
+
+    public function type(): NotificationType
+    {
+        return NotificationType::ManualRendered;
+    }
+
+    public function organizationId(): int
+    {
+        return $this->organizationId;
+    }
+
+    /**
+     * @return array<string, int|string|bool|null>
+     */
+    public function toDatabase(object $notifiable): array
+    {
+        return $this->payload->toArray();
+    }
+}
diff --git a/app/Notifications/InApp/TicketBalanceLowNotification.php b/app/Notifications/InApp/TicketBalanceLowNotification.php
new file mode 100644
index 0000000..662a0c4
--- /dev/null
+++ b/app/Notifications/InApp/TicketBalanceLowNotification.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\InApp;
+
+use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
+use App\Enums\Notification\NotificationType;
+
+/**
+ * チケット残高低下通知 (org の owner/admin 宛)。reserve の閾値クロス検知でのみ発火する
+ * (TicketLedgerService::reserve)。billing_notifications (メール送達台帳) には行を作らない。
+ */
+final class TicketBalanceLowNotification extends AppNotification
+{
+    public function __construct(
+        private readonly int $organizationId,
+        private readonly TicketBalanceLowPayload $payload,
+    ) {}
+
+    public function type(): NotificationType
+    {
+        return NotificationType::TicketBalanceLow;
+    }
+
+    public function organizationId(): int
+    {
+        return $this->organizationId;
+    }
+
+    /**
+     * @return array<string, int|string|bool|null>
+     */
+    public function toDatabase(object $notifiable): array
+    {
+        return $this->payload->toArray();
+    }
+}
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 28244e5..8baf31f 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -18,6 +18,7 @@
 use App\Models\Billing\Subscription;
 use App\Models\Organization;
 use App\Models\User;
+use App\Notifications\Channels\OrganizationScopedDatabaseChannel;
 use App\Services\Billing\CashierTicketCheckoutGateway;
 use App\Services\Billing\StripeWebhookProcessor;
 use App\Services\Billing\TicketCheckoutGateway;
@@ -39,6 +40,7 @@
 use Illuminate\Foundation\Support\Providers\EventServiceProvider;
 use Illuminate\Http\Request;
 use Illuminate\Mail\Events\MessageSending;
+use Illuminate\Notifications\Channels\DatabaseChannel;
 use Illuminate\Notifications\Events\NotificationSent;
 use Illuminate\Support\Facades\Auth;
 use Illuminate\Support\Facades\Event;
@@ -100,6 +102,12 @@ public function register(): void
 
         // チケットスポット購入の Stripe Checkout 抽象 (T007)。テストは fake を bind する
         $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);
+
+        // アプリ内通知 (T008): database channel を薄い拡張へ差し替え、AppNotification の
+        // organization_id を notifications テーブルの first-class 列として書き込む
+        // (ChannelManager::createDatabaseDriver は container 解決のため binding が効く。
+        // AppNotification 以外の通知は素通し = 後方互換)
+        $this->app->bind(DatabaseChannel::class, OrganizationScopedDatabaseChannel::class);
     }
 
     public function boot(): void
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index 7d4f314..86b6b6d 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -11,6 +11,7 @@
 use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Billing\TicketReservation;
 use App\Models\Organization;
+use App\Services\Notification\NotificationCenterService;
 use Carbon\CarbonImmutable;
 use Illuminate\Support\Facades\DB;
 use LogicException;
@@ -33,6 +34,10 @@ class TicketLedgerService
     /** reserve の TTL (分)。入口の二重起動・放置予約による残高死蔵を防ぐ */
     private const int RESERVATION_TTL_MINUTES = 30;
 
+    public function __construct(
+        private readonly NotificationCenterService $notifications,
+    ) {}
+
     /** チケットを付与する (運用調整の正エントリ。冪等付与は grantMonthly / grantPurchased を使う) */
     public function grant(Organization $organization, int $amount, string $description): TicketLedgerEntry
     {
@@ -255,6 +260,19 @@ public function reserve(Organization $organization, int $amount): TicketReservat
             $reservation->expires_at = CarbonImmutable::now()->addMinutes(self::RESERVATION_TTL_MINUTES);
             $reservation->save();
 
+            // 残高低下の閾値クロス検知。クロス判定を reserve に置く理由: balance() は
+            // 「有効台帳合計 − Reserved 拘束」であり、実効残高が減る唯一の消費イベントは reserve
+            // (Reserved→Committed の commit は拘束 -amount と台帳 -amount が相殺し balance() 不変)。
+            // reserve は org 行ロック下で直列化済みのため、並行 reserve でもクロスを観測するのは
+            // ちょうど 1 回 (release/grant で回復して再度跨げば再通知される = 仕様)
+            $threshold = config()->integer('billing.ticket_low_balance_threshold');
+            $after = $balance - $amount;
+            if ($balance >= $threshold && $after < $threshold) {
+                // afterCommit: reserve は pipeline の startJob tx 内から savepoint で呼ばれ得るため、
+                // 最外層 commit 成立後にのみ通知する (rollback 時は発火しない)
+                DB::afterCommit(fn () => $this->notifications->notifyTicketBalanceLow($organization, $after, $threshold));
+            }
+
             return $reservation;
         });
     }
diff --git a/app/Services/Manual/AnalysisJobService.php b/app/Services/Manual/AnalysisJobService.php
index 9fd9d95..e3d048c 100644
--- a/app/Services/Manual/AnalysisJobService.php
+++ b/app/Services/Manual/AnalysisJobService.php
@@ -14,8 +14,10 @@
 use App\Models\AnalysisJob;
 use App\Models\Organization;
 use App\Models\Project;
+use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Notification\NotificationCenterService;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Database\Query\Builder;
 use Illuminate\Support\Facades\DB;
@@ -38,6 +40,7 @@ class AnalysisJobService
 {
     public function __construct(
         private readonly TicketLedgerService $tickets,
+        private readonly NotificationCenterService $notifications,
     ) {}
 
     /**
@@ -46,10 +49,12 @@ public function __construct(
      * - 実行可能状態: status ∈ {draft, ready} のみ (ready→analyzing = 再解析は正式遷移)
      * - analyze 冪等: 同一 manual の in-flight (queued/running) は 1 つ → 409
      * - 残高事前チェックは fail-fast の入口ゲート (真の残高保証は pipeline の reserve)
+     * - $actor はジョブ実行者 (通知宛先の導出用)。web 経路では必ず存在するが、
+     *   将来の CLI 経路に備え nullable (未指定時は triggered_by NULL = creator のみ宛先)
      */
-    public function trigger(Project $project, VideoManual $manual): AnalysisJob
+    public function trigger(Project $project, VideoManual $manual, ?User $actor = null): AnalysisJob
     {
-        $job = DB::transaction(function () use ($project, $manual): AnalysisJob {
+        $job = DB::transaction(function () use ($project, $manual, $actor): AnalysisJob {
             // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
             /** @var VideoManual $locked */
             $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
@@ -81,6 +86,9 @@ public function trigger(Project $project, VideoManual $manual): AnalysisJob
             $job = $locked->analysisJobs()->make();
             $job->status = JobStatus::Queued;
             $job->sourceDocument()->associate($document);
+            if ($actor !== null) {
+                $job->triggeredBy()->associate($actor); // Auth 導出のみ (保護キー。payload 直送は 422)
+            }
             $job->save();
 
             $locked->forceFill(['status' => VideoManualStatus::Analyzing])->save();
@@ -105,7 +113,7 @@ public function trigger(Project $project, VideoManual $manual): AnalysisJob
      */
     public function failJob(AnalysisJob $job, string $error): bool
     {
-        return DB::transaction(function () use ($job, $error): bool {
+        $failed = DB::transaction(function () use ($job, $error): bool {
             /** @var AnalysisJob $locked */
             $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
             if ($locked->status->isTerminal()) {
@@ -137,6 +145,15 @@ public function failJob(AnalysisJob $job, string $error): bool
 
             return true;
         });
+
+        // terminal 遷移が実際に起きたときだけ・commit 後に通知する (at-most-once。詳細設計
+        // 「配信保証仕様」)。通知例外は NotificationCenterService 内 catch + report で
+        // ジョブ本流を壊さない。二重 fail は上の terminal guard (false) が通知ごと握る
+        if ($failed) {
+            $this->notifications->notifyAnalysisFinished($job->refresh());
+        }
+
+        return $failed;
     }
 
     /**
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index 26cfe9d..0397927 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -23,6 +23,7 @@
 use App\Prompts\SopExtractPrompt;
 use App\Prompts\WorkDecompositionPrompt;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Notification\NotificationCenterService;
 use Illuminate\Support\Facades\DB;
 use LogicException;
 use Throwable;
@@ -45,6 +46,7 @@ public function __construct(
         private readonly ScenarioService $scenarios,
         private readonly SopTextExtractor $extractor,
         private readonly TicketLedgerService $tickets,
+        private readonly NotificationCenterService $notifications,
     ) {}
 
     public function run(int $analysisJobId): void
@@ -61,7 +63,10 @@ public function run(int $analysisJobId): void
             $extracted = $this->runExtractStep($job, $document, $text);
             $decomposition = $this->runDecomposeStep($job, $extracted);
             $generated = $this->runGenerateStep($job, $decomposition);
-            $this->finalize($job, $generated);
+            if ($this->finalize($job, $generated)) {
+                // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
+                $this->notifications->notifyAnalysisFinished($job->refresh());
+            }
         } catch (Throwable $exception) {
             report($exception);
             $this->jobs->failJob($job, $this->userMessageFor($exception));
@@ -182,15 +187,18 @@ private function runGenerateStep(AnalysisJob $job, WorkDecompositionData $decomp
      *   - releaseStale (billing cron): ticket_reservations → organizations (前方リソースを保持しない)
      *   - ScenarioService::save: video_manuals のみ
      * いずれもグローバル順の部分列であり循環待ちは構成できない。
+     *
+     * @return bool succeeded に到達したか (stale 回復先勝ちなら false = 通知しない。
+     *              RenderPipeline::finalize と同型の bool 返却)
      */
-    private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): void
+    private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): bool
     {
-        DB::transaction(function () use ($job, $generated): void {
+        return DB::transaction(function () use ($job, $generated): bool {
             // ロック 1: job 行 (stale 回復 cron との直列化点)
             /** @var AnalysisJob $locked */
             $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
             if ($locked->status !== JobStatus::Running) {
-                return; // stale 回復 cron が先勝ち → materialize も commit もしない (無課金 succeeded 排除)
+                return false; // stale 回復 cron が先勝ち → materialize も commit もしない (無課金 succeeded 排除)
             }
 
             // ロック 2: manual 行 (共有ロック規約。親 relation 経由再解決 = 子∈親も担保)
@@ -212,6 +220,8 @@ private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): v
             $locked->status = JobStatus::Succeeded;
             $locked->progress = 100;
             $locked->save();
+
+            return true;
         });
     }
 
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index 5c97d38..c071fd4 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -21,8 +21,10 @@
 use App\Models\Organization;
 use App\Models\Project;
 use App\Models\RenderJob;
+use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Notification\NotificationCenterService;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Database\Query\Builder;
 use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
@@ -52,6 +54,7 @@ class RenderJobService
 {
     public function __construct(
         private readonly TicketLedgerService $tickets,
+        private readonly NotificationCenterService $notifications,
     ) {}
 
     /**
@@ -61,10 +64,12 @@ public function __construct(
      * - render 冪等: 同一 manual の in-flight kind=render は 1 つ → 409 (preview は妨げない)
      * - 採用テイク欠落は 422 (スキップしない: 標準化された成果物の完全性)
      * - 尺上限ソフトゲート 422 (§10.8-1: TTL 内 commit)・残高事前チェック 402
+     * - $actor はジョブ実行者 (通知宛先の導出用)。web 経路では必ず存在するが、
+     *   将来の CLI 経路に備え nullable (未指定時は triggered_by NULL = creator のみ宛先)
      */
-    public function trigger(Project $project, VideoManual $manual): RenderJob
+    public function trigger(Project $project, VideoManual $manual, ?User $actor = null): RenderJob
     {
-        $job = DB::transaction(function () use ($project, $manual): RenderJob {
+        $job = DB::transaction(function () use ($project, $manual, $actor): RenderJob {
             // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
             /** @var VideoManual $locked */
             $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
@@ -92,6 +97,9 @@ public function trigger(Project $project, VideoManual $manual): RenderJob
             $job->kind = RenderKind::Render;
             $job->status = JobStatus::Queued;
             $job->scenario_version = $locked->scenario_version; // §10.8-6 スナップショット
+            if ($actor !== null) {
+                $job->triggeredBy()->associate($actor); // Auth 導出のみ (保護キー。payload 直送は 422)
+            }
             $job->save();
 
             $locked->forceFill(['status' => VideoManualStatus::Rendering])->save();
@@ -111,9 +119,9 @@ public function trigger(Project $project, VideoManual $manual): RenderJob
      * org 同時 preview 上限は Organization 行ロックで直列化する (reserve と同じ手法。
      * ロック順 video_manuals → organizations はグローバル順の部分列)。
      */
-    public function triggerPreview(Project $project, VideoManual $manual): RenderJob
+    public function triggerPreview(Project $project, VideoManual $manual, ?User $actor = null): RenderJob
     {
-        $job = DB::transaction(function () use ($project, $manual): RenderJob {
+        $job = DB::transaction(function () use ($project, $manual, $actor): RenderJob {
             /** @var VideoManual $locked */
             $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
 
@@ -141,6 +149,9 @@ public function triggerPreview(Project $project, VideoManual $manual): RenderJob
             $job->kind = RenderKind::Preview;
             $job->status = JobStatus::Queued;
             $job->scenario_version = $locked->scenario_version;
+            if ($actor !== null) {
+                $job->triggeredBy()->associate($actor); // Auth 導出のみ (preview は通知対象外だが監査用に記録)
+            }
             $job->save();
 
             return $job; // manual status は変更しない (編集と並走)
@@ -163,7 +174,7 @@ public function triggerPreview(Project $project, VideoManual $manual): RenderJob
      */
     public function failJob(RenderJob $job, RenderErrorCode $code, string $error): bool
     {
-        return DB::transaction(function () use ($job, $code, $error): bool {
+        $failed = DB::transaction(function () use ($job, $code, $error): bool {
             /** @var RenderJob $locked */
             $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
             if ($locked->status->isTerminal()) {
@@ -196,6 +207,18 @@ public function failJob(RenderJob $job, RenderErrorCode $code, string $error): b
 
             return true;
         });
+
+        // terminal 遷移が実際に起きたときだけ・commit 後に通知する (kind=render のみ。
+        // preview はノイズ・status 遷移も無いため通知しない。at-most-once = 詳細設計「配信保証仕様」。
+        // 通知例外は NotificationCenterService 内 catch + report でジョブ本流を壊さない)
+        if ($failed) {
+            $job->refresh();
+            if ($job->kind === RenderKind::Render) {
+                $this->notifications->notifyRenderFinished($job);
+            }
+        }
+
+        return $failed;
     }
 
     /**
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index 808ebac..606c648 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -25,6 +25,7 @@
 use App\Models\RenderJob;
 use App\Models\VideoManual;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Notification\NotificationCenterService;
 use App\Services\Render\RenderObjectStorage;
 use App\Services\Render\VideoComposer;
 use Illuminate\Support\Facades\DB;
@@ -65,6 +66,7 @@ public function __construct(
         private readonly VideoComposer $composer,
         private readonly RenderObjectStorage $storage,
         private readonly TicketLedgerService $tickets,
+        private readonly NotificationCenterService $notifications,
     ) {}
 
     public function run(int $renderJobId): void
@@ -102,6 +104,11 @@ public function run(int $renderJobId): void
             );
             if ($this->finalize($job, $result)) {
                 $uploadedKey = null; // succeeded に到達した出力は正 (後始末しない)
+                // succeeded 到達時のみ・terminal tx の commit 後に通知 (kind=render のみ。
+                // finalize が $job->refresh() 済み。preview は通知しない)
+                if ($job->kind === RenderKind::Render) {
+                    $this->notifications->notifyRenderFinished($job);
+                }
             }
         } catch (Throwable $exception) {
             report($exception);
diff --git a/app/Services/Notification/NotificationCenterService.php b/app/Services/Notification/NotificationCenterService.php
new file mode 100644
index 0000000..0312769
--- /dev/null
+++ b/app/Services/Notification/NotificationCenterService.php
@@ -0,0 +1,212 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Notification;
+
+use App\DataTransferObjects\Notification\InvitationReceivedPayload;
+use App\DataTransferObjects\Notification\ManualJobPayload;
+use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderKind;
+use App\Models\AnalysisJob;
+use App\Models\Organization;
+use App\Models\OrganizationInvitation;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Notifications\InApp\InvitationReceivedNotification;
+use App\Notifications\InApp\ManualAnalyzedNotification;
+use App\Notifications\InApp\ManualRenderedNotification;
+use App\Notifications\InApp\TicketBalanceLowNotification;
+use Illuminate\Notifications\DatabaseNotification;
+use Illuminate\Pagination\LengthAwarePaginator;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * アプリ内通知センターの唯一の窓口 (発火・読み出し・既読化)。概念設計 20260711-2255。
+ *
+ * 発火の設計上の位置づけ:
+ * - すべて既存 exactly-once 遷移 (terminal tx / org 行ロック) の **commit 後** に呼ばれる
+ *   (terminal tx 内に通知 insert を入れない = 通知失敗がジョブ結果を rollback しない)
+ * - 配信保証は at-most-once (重複なし・欠落あり得る)。正はジョブ status + 既存ポーリング UI
+ *   であり通知は補助チャネル (outbox 台帳は作らない。詳細設計「配信保証仕様」)
+ * - 宛先・内容・organization_id は DB relation からの再解決のみ (payload 不信任)
+ * - 通知内の例外は safely() で catch + report し、ジョブ本流を絶対に壊さない
+ */
+class NotificationCenterService
+{
+    // ── 発火 (すべて terminal 遷移 commit 後に呼ばれる) ──────────────────────
+
+    /**
+     * 解析 terminal 通知。宛先 = creator ∪ triggeredBy (org 所属を再確認して dedup)。
+     * terminal 遷移と通知の間に manual/project が削除された競合は「通知スキップ」が仕様
+     * (例外にしない。ManualAnalysisNotificationTest で固定)。
+     */
+    public function notifyAnalysisFinished(AnalysisJob $job): void
+    {
+        $this->safely(function () use ($job): void {
+            $manual = $job->videoManual;
+            if ($manual === null) {
+                return; // manual 削除競合 → 通知スキップ
+            }
+            $project = $manual->project;
+            Assert::isInstanceOf($project, Project::class);
+            $organization = $project->organization;
+            Assert::isInstanceOf($organization, Organization::class);
+
+            $payload = new ManualJobPayload(
+                projectId: $project->id,
+                manualId: $manual->id,
+                manualTitle: $manual->title,
+                organizationName: $organization->name,
+                succeeded: $job->status === JobStatus::Succeeded,
+                error: $job->error,
+            );
+
+            foreach ($this->resolveRecipientsForManualJob($manual, $job->triggered_by, $organization) as $user) {
+                $user->notify(new ManualAnalyzedNotification($organization->id, $payload));
+            }
+        });
+    }
+
+    /**
+     * レンダ terminal 通知 (kind=render のみ。preview はノイズ・status 遷移も無いため通知 0)。
+     */
+    public function notifyRenderFinished(RenderJob $job): void
+    {
+        $this->safely(function () use ($job): void {
+            if ($job->kind !== RenderKind::Render) {
+                return; // preview は通知しない
+            }
+            $manual = $job->videoManual;
+            if ($manual === null) {
+                return; // manual 削除競合 → 通知スキップ
+            }
+            $project = $manual->project;
+            Assert::isInstanceOf($project, Project::class);
+            $organization = $project->organization;
+            Assert::isInstanceOf($organization, Organization::class);
+
+            $payload = new ManualJobPayload(
+                projectId: $project->id,
+                manualId: $manual->id,
+                manualTitle: $manual->title,
+                organizationName: $organization->name,
+                succeeded: $job->status === JobStatus::Succeeded,
+                error: $job->error,
+            );
+
+            foreach ($this->resolveRecipientsForManualJob($manual, $job->triggered_by, $organization) as $user) {
+                $user->notify(new ManualRenderedNotification($organization->id, $payload));
+            }
+        });
+    }
+
+    /**
+     * 招待の気づき通知 (既存ユーザー宛のみ)。whereBlind = CipherSweet 不変条件 6。
+     * 受信者は当然まだ招待元 org に未所属のため所属確認はしない。
+     */
+    public function notifyInvitationReceived(OrganizationInvitation $invitation): void
+    {
+        $this->safely(function () use ($invitation): void {
+            $organization = $invitation->organization;
+            Assert::isInstanceOf($organization, Organization::class);
+
+            /** @var User|null $user */
+            $user = User::whereBlind('email', 'email_index', $invitation->email)->first();
+            if ($user === null) {
+                return; // 未登録 email はメールのみ (アプリ内通知なし)
+            }
+
+            $user->notify(new InvitationReceivedNotification(
+                $organization->id,
+                new InvitationReceivedPayload($organization->name),
+            ));
+        });
+    }
+
+    /**
+     * 残高低下通知。宛先 = org の owner/admin (organizationRole = laratrust_team_id 明示判定)。
+     * $balance は Reserved 拘束を含む実効残高 (クロス検知は TicketLedgerService::reserve)。
+     */
+    public function notifyTicketBalanceLow(Organization $organization, int $balance, int $threshold): void
+    {
+        $this->safely(function () use ($organization, $balance, $threshold): void {
+            $payload = new TicketBalanceLowPayload($organization->name, $balance, $threshold);
+            foreach ($organization->users()->get() as $user) {
+                if ($user->organizationRole($organization)?->canManage() !== true) {
+                    continue;
+                }
+                $user->notify(new TicketBalanceLowNotification($organization->id, $payload));
+            }
+        });
+    }
+
+    // ── 読み出し・既読化 (NotificationController から委譲) ────────────────────
+
+    /**
+     * 自分宛のみの一覧 (notifiable = 自分で構造的に閉じる。全 org 横断)。
+     *
+     * @return LengthAwarePaginator<int, DatabaseNotification>
+     */
+    public function paginateFor(User $user, int $perPage = 20): LengthAwarePaginator
+    {
+        // Notifiable::notifications() は latest() 適用済みの morphMany (自分宛のみ)
+        return $user->notifications()->paginate($perPage);
+    }
+
+    public function unreadCountFor(User $user): int
+    {
+        return $user->unreadNotifications()->count();
+    }
+
+    /**
+     * 自分宛以外は 404 (存在オラクル封じ。403 で存在を漏らさない)。relation 経由で解決する。
+     */
+    public function findOwnOrFail(User $user, string $id): DatabaseNotification
+    {
+        return $user->notifications()->whereKey($id)->firstOrFail();
+    }
+
+    public function markRead(DatabaseNotification $notification): void
+    {
+        $notification->markAsRead();
+    }
+
+    public function markAllRead(User $user): void
+    {
+        $user->unreadNotifications()->update(['read_at' => now()]);
+    }
+
+    /**
+     * ジョブ通知の宛先集合: creator ∪ triggeredBy を id で dedup し、org 所属を relation 経由で
+     * 再確認する (退会済みユーザーへは送らない・cross-org を構造的に作らない)。
+     *
+     * @return list<User>
+     */
+    private function resolveRecipientsForManualJob(VideoManual $manual, ?int $triggeredById, Organization $organization): array
+    {
+        // creator (created_by は非 null) ∪ triggeredBy を id で dedup
+        $ids = array_values(array_unique(array_filter(
+            [$manual->created_by, $triggeredById],
+            static fn (?int $id): bool => $id !== null,
+        )));
+
+        return array_values($organization->users()->whereIn('users.id', $ids)->get()->all());
+    }
+
+    /**
+     * 通知失敗はジョブ本流を絶対に壊さない (catch + report。terminal tx の外でのみ呼ばれる前提)。
+     */
+    private function safely(callable $callback): void
+    {
+        try {
+            $callback();
+        } catch (Throwable $exception) {
+            report($exception);
+        }
+    }
+}
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 9fd647f..16920a8 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -12,6 +12,7 @@
 use App\Models\OrganizationInvitation;
 use App\Models\User;
 use App\Notifications\OrganizationInvitationNotification;
+use App\Services\Notification\NotificationCenterService;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
 use Illuminate\Support\Facades\DB;
@@ -34,6 +35,7 @@ class OrganizationMembershipService
     public function __construct(
         private readonly SecurityEventRecorder $recorder,
         private readonly DefaultProjectResolver $defaultProjects,
+        private readonly NotificationCenterService $notifications,
     ) {}
 
     /**
@@ -79,6 +81,9 @@ public function inviteMember(Organization $organization, User $invitedBy, string
             acceptUrl: url('/invitations/accept?token='.$plainToken),
         ));
 
+        // 既存ユーザーが宛先ならアプリ内でも気づけるようにする (メールの補完。平文 token は含めない)
+        $this->notifications->notifyInvitationReceived($invitation);
+
         return $invitation;
     }
 
diff --git a/app/Support/Security/MassAssignmentProtectedKeys.php b/app/Support/Security/MassAssignmentProtectedKeys.php
index 6d6b838..31dc564 100644
--- a/app/Support/Security/MassAssignmentProtectedKeys.php
+++ b/app/Support/Security/MassAssignmentProtectedKeys.php
@@ -27,6 +27,7 @@ public static function all(): array
             'user_id',
             'created_by_user_id',
             'created_by', // AI-CUE ドメイン (video_manuals) の actor キー (doc/10 §10.1 準拠の命名)
+            'triggered_by', // AI-CUE: analysis_jobs / render_jobs のジョブ実行者 (通知宛先導出。Auth 導出のみ)
             'invited_by_user_id',
             // tenant / ownership (route・コンテキストから導出する)
             'organization_id',
diff --git a/config/billing.php b/config/billing.php
index 0224fa9..6f3756c 100644
--- a/config/billing.php
+++ b/config/billing.php
@@ -34,4 +34,11 @@
     */
     'ticket_unit_price_floor' => (int) env('BILLING_TICKET_UNIT_PRICE_FLOOR', 50),
 
+    /*
+    | チケット残高低下のアプリ内通知閾値。reserve (実効残高が実際に減る唯一の消費起点) で
+    | 「閾値以上 → 閾値未満」を跨いだときのみ owner/admin に 1 回通知する (クロス検知。
+    | TicketLedgerService::reserve)。
+    */
+    'ticket_low_balance_threshold' => (int) env('BILLING_TICKET_LOW_BALANCE_THRESHOLD', 5),
+
 ];
diff --git a/database/migrations/2026_07_12_000000_create_notifications_table.php b/database/migrations/2026_07_12_000000_create_notifications_table.php
new file mode 100644
index 0000000..a0e4f74
--- /dev/null
+++ b/database/migrations/2026_07_12_000000_create_notifications_table.php
@@ -0,0 +1,40 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * notifications: Laravel 標準 database 通知スキーマ + organization_id first-class 列。
+     *
+     * - type には NotificationType enum の value を格納する (クラス名を DB に置かない。
+     *   規約は InAppNotificationTypeInvariantTest が全 AppNotification 派生に強制する)
+     * - organization_id は org 文脈のサーバ導出列 (OrganizationScopedDatabaseChannel が埋める)。
+     *   org 削除で通知ごと消える (cascade)。org 判定・クエリには data (jsonb) を使わない
+     *   (data は表示用 payload 限定)
+     * - 複合 index (notifiable_type, notifiable_id, read_at) は未読数 1 クエリの担保
+     *   (標準 morphs index は read_at を含まない)
+     */
+    public function up(): void
+    {
+        Schema::create('notifications', function (Blueprint $table): void {
+            $table->uuid('id')->primary();
+            $table->string('type');
+            $table->morphs('notifiable');
+            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
+            $table->jsonb('data');
+            $table->timestamp('read_at')->nullable();
+            $table->timestamps();
+            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('notifications');
+    }
+};
diff --git a/database/migrations/2026_07_12_000100_add_triggered_by_to_job_tables.php b/database/migrations/2026_07_12_000100_add_triggered_by_to_job_tables.php
new file mode 100644
index 0000000..3e14940
--- /dev/null
+++ b/database/migrations/2026_07_12_000100_add_triggered_by_to_job_tables.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * analysis_jobs / render_jobs にジョブ実行者 (triggered_by) を追加する。
+     *
+     * 通知宛先 (creator ∪ triggeredBy) の導出用。Auth からの明示代入のみ
+     * (MassAssignmentProtectedKeys 登録済み = payload 直送は 422 / $fillable 不含)。
+     * ユーザー削除時は nullOnDelete (ジョブ行は監査のため残す)。
+     */
+    public function up(): void
+    {
+        Schema::table('analysis_jobs', function (Blueprint $table): void {
+            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
+        });
+        Schema::table('render_jobs', function (Blueprint $table): void {
+            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('analysis_jobs', function (Blueprint $table): void {
+            $table->dropConstrainedForeignId('triggered_by');
+        });
+        Schema::table('render_jobs', function (Blueprint $table): void {
+            $table->dropConstrainedForeignId('triggered_by');
+        });
+    }
+};
diff --git a/docs/architecture.md b/docs/architecture.md
index b9a5c5b..0eaac2d 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -212,6 +212,40 @@ ## チケットスポット購入 (T007) の運用契約
   DB 行は checkout 開始時の期限切れ回収 (`status=pending AND expires_at <= now` → expired) で
   局所回収する (専用 cron は作らない)
 
+## アプリ内通知センター (T008) の運用契約
+
+- **格納**: Laravel 標準 `notifications` テーブル (Eloquent 標準 `DatabaseNotification` を使う。
+  新規モデル / Factory は作らない。テストは `$user->notify(...)` 実発火で行を作る)。
+  `organization_id` は first-class 列 (nullable FK, cascadeOnDelete)。
+  `OrganizationScopedDatabaseChannel` (標準 DatabaseChannel の `buildPayload` 拡張 +
+  container binding 差し替え) がサーバ導出で埋める。`data` (jsonb) は表示用 payload 限定
+  (org 判定・クエリには使わない)
+- **type 規約**: `notifications.type` には `NotificationType` enum の value を格納する
+  (クラス名を DB に置かない。`InAppNotificationTypeInvariantTest` が
+  `app/Notifications/InApp/*` の全派生に deny-by-default で強制。
+  TS 側 `types/notification.ts` との値集合同期は `NotificationTypeTsSyncInvariantTest`)
+- **発火**: すべて `NotificationCenterService` 経由・既存 exactly-once 遷移の **commit 後**
+  (解析/レンダ terminal 遷移の bool ゲート / 招待作成後 / reserve の残高閾値クロス検知)。
+  terminal tx 内に通知 insert を入れない。通知例外は catch + report でジョブ本流を壊さない
+- **配信保証は at-most-once** (重複なし・欠落あり得る)。正はジョブ status + 既存ポーリング UI で、
+  通知は補助チャネル。terminal commit 直後〜通知 insert 間のプロセス停止の欠落窓 (数 ms) は許容し、
+  outbox 台帳は作らない (送達保証が要件化したときに outbox へ移行する)。worker のジョブ実行中
+  停止は `recoverStale` → `failJob` 経由で失敗通知が発火する
+- **宛先導出**: ジョブ通知 = `manual.created_by` ∪ `triggered_by` (jobs 列。Auth からの明示代入のみ =
+  `MassAssignmentProtectedKeys` 登録済み) を org 所属再確認 + dedup / 招待 = `whereBlind` 一致の
+  既存ユーザーのみ (平文 token 非含有) / 残高低下 = org の owner/admin
+  (`organizationRole` = laratrust_team_id 明示判定)
+- **残高低下のクロス検知**: `TicketLedgerService::reserve` の org 行ロック内で
+  「実効残高 (Reserved 拘束込み) が `billing.ticket_low_balance_threshold` を跨いだ」ときのみ
+  `DB::afterCommit` で 1 回通知 (commit は拘束と台帳が相殺し balance 不変 = クロスを発生させない。
+  release/grant で回復して再度跨げば再通知)。`billing_notifications` (メール送達台帳) には行を作らない
+- **読み出し**: 自分宛 (notifiable = 自分) で構造的に閉じる (org フィルタなし = 全 org 横断)。
+  `{notification}` は implicit binding を使わず relation 経由解決 (cross-user は 404 = 存在秘匿)。
+  `open` は POST + 303 のサーバ解決遷移 (認可判断は複製せず遷移先の Gate が唯一の判断点)。
+  未読数は `HandleInertiaRequests` の shared props `notifications.unreadCount` (closure 共有のため
+  `router.reload({ only: ['notifications'] })` の partial reload キーとしてそのまま使える。
+  将来の SPA 内ポーリングはこのキーで実現する。v1 はページ遷移時更新のみ)
+
 ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
 
 doc/10 §10.3 / §10.8-4/-7 の実装 (T004)。routes は `/app/projects/{project}/...`
diff --git a/docs/factories.md b/docs/factories.md
index 3d22ed6..2451235 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -42,6 +42,10 @@ ## Factory 一覧 (テンプレート同梱)
 
 Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
 または Service (`OrganizationProvisioningService` 等) 経由で作る。
+アプリ内通知 (`notifications` テーブル) は Eloquent 標準 `DatabaseNotification` を使うため
+新規モデル / Factory は作らない (テストでは `$user->notify(new ManualAnalyzedNotification(...))`
+の実発火で行を作る。`AnalysisJob` / `RenderJob` の `triggered_by` は nullable のため
+Factory は既存のまま。テストで必要なときは create 属性 `['triggered_by' => $user->id]` を渡す)。
 
 ## 使い方
 
diff --git a/resources/js/components/features/notifications/NotificationListItem.svelte b/resources/js/components/features/notifications/NotificationListItem.svelte
new file mode 100644
index 0000000..5889039
--- /dev/null
+++ b/resources/js/components/features/notifications/NotificationListItem.svelte
@@ -0,0 +1,171 @@
+<script lang="ts">
+    import type { Component } from "svelte";
+    import { router } from "@inertiajs/svelte";
+    import { Bell, FileSearch, Film, Mail, TicketMinus } from "@lucide/svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import type {
+        InvitationReceivedPayload,
+        ManualJobPayload,
+        NotificationItem,
+        TicketBalanceLowPayload,
+    } from "@/types/notification";
+
+    /**
+     * 通知一覧の 1 行。type ごとにアイコン・文言を組み立てる。
+     * 行クリック = POST /notifications/{id}/open (サーバが既読化 + 遷移先を解決する 303。
+     * GET にしない = prefetch による意図しない既読化防止)。
+     * 未知 type (enum⇔TS の一時的ドリフト) は汎用アイコン + rawType 表示の fallback。
+     */
+    interface Props {
+        notification: NotificationItem;
+    }
+
+    let { notification }: Props = $props();
+
+    let opening = $state(false);
+
+    const unread = $derived(notification.read_at === null);
+
+    // payload の判別は type discriminant + null 検査 (サーバ側で検証復元済み)
+    const manualPayload = $derived(
+        (notification.type === "manual_analyzed" || notification.type === "manual_rendered") &&
+            notification.payload !== null
+            ? (notification.payload as ManualJobPayload)
+            : null,
+    );
+    const invitationPayload = $derived(
+        notification.type === "invitation_received" && notification.payload !== null
+            ? (notification.payload as InvitationReceivedPayload)
+            : null,
+    );
+    const balancePayload = $derived(
+        notification.type === "ticket_balance_low" && notification.payload !== null
+            ? (notification.payload as TicketBalanceLowPayload)
+            : null,
+    );
+
+    const icon = $derived.by<Component>(() => {
+        switch (notification.type) {
+            case "manual_analyzed":
+                return FileSearch;
+            case "manual_rendered":
+                return Film;
+            case "invitation_received":
+                return Mail;
+            case "ticket_balance_low":
+                return TicketMinus;
+            default:
+                return Bell;
+        }
+    });
+
+    const title = $derived.by<string>(() => {
+        if (manualPayload) {
+            const kind = notification.type === "manual_analyzed" ? "AI 解析" : "動画の書き出し";
+            return manualPayload.succeeded
+                ? `${kind}が完了しました`
+                : `${kind}に失敗しました`;
+        }
+        if (invitationPayload) {
+            return `${invitationPayload.organization_name} に招待されています`;
+        }
+        if (balancePayload) {
+            return `チケット残高が残り ${balancePayload.balance} 枚になりました`;
+        }
+        // 未知 type / payload 復元失敗の fallback (rawType をそのまま出す)
+        return notification.type;
+    });
+
+    const body = $derived.by<string | null>(() => {
+        if (manualPayload) {
+            return manualPayload.succeeded
+                ? manualPayload.manual_title
+                : `${manualPayload.manual_title}: ${manualPayload.error ?? "エラーが発生しました"}`;
+        }
+        if (invitationPayload) {
+            return "メールの受諾リンクから参加してください";
+        }
+        if (balancePayload) {
+            return `通知の目安 (${balancePayload.threshold} 枚) を下回りました。チケットを追加購入できます`;
+        }
+        return null;
+    });
+
+    const organizationName = $derived.by<string | null>(() => {
+        if (manualPayload) return manualPayload.organization_name;
+        if (invitationPayload) return invitationPayload.organization_name;
+        if (balancePayload) return balancePayload.organization_name;
+        return null;
+    });
+
+    /** 相対時刻 (分/時間/日)。7 日超は日付表示 */
+    function relativeTime(iso: string): string {
+        const date = new Date(iso);
+        if (Number.isNaN(date.getTime())) return "";
+        const diffMs = Date.now() - date.getTime();
+        const minutes = Math.floor(diffMs / 60_000);
+        if (minutes < 1) return "たった今";
+        if (minutes < 60) return `${minutes}分前`;
+        const hours = Math.floor(minutes / 60);
+        if (hours < 24) return `${hours}時間前`;
+        const days = Math.floor(hours / 24);
+        if (days <= 7) return `${days}日前`;
+        return date.toLocaleDateString("ja-JP");
+    }
+
+    function open(): void {
+        if (opening) return; // 連打ガード (disabled 属性ではなく送信ガード)
+        router.post(
+            `/notifications/${notification.id}/open`,
+            {},
+            {
+                onStart: () => {
+                    opening = true;
+                },
+                onFinish: () => {
+                    opening = false;
+                },
+            },
+        );
+    }
+
+    const Icon = $derived(icon);
+</script>
+
+<button
+    type="button"
+    onclick={open}
+    class="flex w-full items-start gap-3 border-b border-border px-4 py-3 text-left
+        hover:bg-neutral {unread ? 'bg-primary-soft/40' : 'bg-surface'}"
+    data-testid="notification-item"
+    data-unread={unread}
+>
+    <span
+        class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md
+            {unread ? 'bg-primary-soft text-primary' : 'bg-neutral text-text-secondary'}"
+        aria-hidden="true"
+    >
+        <Icon class="size-4" />
+    </span>
+    <span class="min-w-0 flex-1">
+        <span class="block text-body {unread ? 'font-medium' : ''} text-text">{title}</span>
+        {#if body !== null}
+            <span class="mt-0.5 block truncate text-caption text-text-secondary">{body}</span>
+        {/if}
+        <span class="mt-1 flex items-center gap-2">
+            {#if organizationName !== null}
+                <Badge tone="neutral" size="sm">{organizationName}</Badge>
+            {/if}
+            <span class="text-caption text-text-secondary">
+                {relativeTime(notification.created_at)}
+            </span>
+        </span>
+    </span>
+    {#if unread}
+        <span
+            class="mt-2 inline-block size-2 shrink-0 rounded-sm bg-primary"
+            aria-label="未読"
+            data-testid="unread-dot"
+        ></span>
+    {/if}
+</button>
diff --git a/resources/js/components/molecules/NotificationBell.svelte b/resources/js/components/molecules/NotificationBell.svelte
new file mode 100644
index 0000000..2f27cf3
--- /dev/null
+++ b/resources/js/components/molecules/NotificationBell.svelte
@@ -0,0 +1,37 @@
+<script lang="ts">
+    import { Bell } from "@lucide/svelte";
+    import { Link } from "@inertiajs/svelte";
+
+    /**
+     * 通知ベル (未読数バッジ付き)。/notifications への Inertia link。
+     * 未読数は shared props notifications.unreadCount (親が渡す)。
+     * v1 はドロップダウンなし = フォーカス管理/状態を持たない最小構成。
+     */
+    interface Props {
+        unreadCount: number;
+        testId?: string;
+    }
+
+    let { unreadCount, testId = "notification-bell" }: Props = $props();
+
+    const badge = $derived(unreadCount > 99 ? "99+" : String(unreadCount));
+</script>
+
+<Link
+    href="/notifications"
+    class="relative inline-flex size-9 items-center justify-center rounded-md text-text-secondary
+        hover:bg-neutral hover:text-text"
+    aria-label="通知"
+    data-testid={testId}
+>
+    <Bell class="size-5" aria-hidden="true" />
+    {#if unreadCount > 0}
+        <span
+            class="absolute -top-1 -right-1 inline-flex min-w-4 items-center justify-center
+                rounded-sm bg-danger px-1 text-caption text-neutral"
+            data-testid="unread-badge"
+        >
+            {badge}
+        </span>
+    {/if}
+</Link>
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index be63a3b..f593565 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -3,7 +3,9 @@
     import { page } from "@inertiajs/svelte";
     import ToastContainer from "@/components/organisms/ToastContainer.svelte";
     import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
+    import NotificationBell from "@/components/molecules/NotificationBell.svelte";
     import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
+    import type { NotificationSharedProps } from "@/types/notification";
 
     /**
      * 認証済み画面用レイアウト (最小骨格)。
@@ -27,6 +29,12 @@
     // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
     const auth = $derived(page.props.auth as { user?: { emailVerified?: boolean } | null } | undefined);
     const showEmailBanner = $derived(auth?.user != null && auth.user.emailVerified === false);
+
+    // 通知センターの未読数 (shared props)。ログイン時のみベルを常設する
+    const notifications = $derived(
+        page.props.notifications as NotificationSharedProps | undefined,
+    );
+    const showBell = $derived(auth?.user != null);
 </script>
 
 <ToastContainer />
@@ -35,11 +43,14 @@
     <header class="border-b border-border bg-surface">
         <div class="mx-auto flex max-w-6xl items-center justify-between px-8 py-3">
             <a href="/dashboard" class="text-h3 text-primary">{appName}</a>
-            {#if headerActions}
-                <div class="flex items-center gap-3">
+            <div class="flex items-center gap-3">
+                {#if showBell}
+                    <NotificationBell unreadCount={notifications?.unreadCount ?? 0} />
+                {/if}
+                {#if headerActions}
                     {@render headerActions()}
-                </div>
-            {/if}
+                {/if}
+            </div>
         </div>
     </header>
     <main class="mx-auto w-full max-w-6xl flex-1 px-8 py-8">
diff --git a/resources/js/lib/shared-props.ts b/resources/js/lib/shared-props.ts
index c71b4f7..88e59bd 100644
--- a/resources/js/lib/shared-props.ts
+++ b/resources/js/lib/shared-props.ts
@@ -1,4 +1,5 @@
 import type { FlashPayload } from "@/lib/stores/flash-to-toast";
+import type { NotificationSharedProps } from "@/types/notification";
 
 /**
  * HandleInertiaRequests が共有する props の型 (backend が真実)。
@@ -32,6 +33,8 @@ export interface SharedProps {
     organizations: OrganizationSummary[];
     currentOrganization: CurrentOrganization | null;
     flash: FlashPayload;
+    /** 通知センターの未読数 (全 org 横断・自分宛のみ。未ログイン時は 0) */
+    notifications: NotificationSharedProps;
     /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
     title: string;
 }
diff --git a/resources/js/pages/Notifications/Index.svelte b/resources/js/pages/Notifications/Index.svelte
new file mode 100644
index 0000000..197e99e
--- /dev/null
+++ b/resources/js/pages/Notifications/Index.svelte
@@ -0,0 +1,96 @@
+<script lang="ts">
+    import { page, router } from "@inertiajs/svelte";
+    import { Bell } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import EmptyState from "@/components/molecules/EmptyState.svelte";
+    import Pagination from "@/components/molecules/Pagination.svelte";
+    import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type { PaginationMeta } from "@/types/manual";
+    import type { NotificationItem } from "@/types/notification";
+
+    /**
+     * 通知一覧 (全 org 横断 = 自分宛のみ)。行クリックはサーバ解決の open (POST + 303)。
+     * 「すべて既読にする」は未読 0 でも disabled にしない (押下時は成功 flash のみ。
+     * 連打ノイズは in-flight 送信ガードで抑止する = disabled 属性ではなくハンドラ内 guard)。
+     */
+    interface Props {
+        notifications: NotificationItem[];
+        meta: PaginationMeta;
+    }
+
+    let { notifications, meta }: Props = $props();
+
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    let markingAll = $state(false);
+
+    function markAllRead(): void {
+        if (markingAll) return; // 連打ガード (disabled 属性ではなく送信ガード)
+        router.post(
+            "/notifications/read-all",
+            {},
+            {
+                onStart: () => {
+                    markingAll = true;
+                },
+                onFinish: () => {
+                    markingAll = false;
+                },
+            },
+        );
+    }
+
+    function goToPage(pageNumber: number): void {
+        router.get("/notifications", { page: pageNumber });
+    }
+</script>
+
+<AppLayout {appName}>
+    <div class="flex items-start justify-between gap-4">
+        <div>
+            <h1 class="text-h2">通知</h1>
+            <p class="mt-1 text-caption text-text-secondary">
+                すべての組織の通知が表示されます。
+            </p>
+        </div>
+        <Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">
+            すべて既読にする
+        </Button>
+    </div>
+
+    {#if notifications.length === 0}
+        <div class="mt-6">
+            <EmptyState
+                title="通知はありません"
+                description="ジョブの完了・招待・チケット残高の通知がここに表示されます。"
+                icon={Bell}
+                bordered
+                testId="notifications-empty"
+            />
+        </div>
+    {:else}
+        <Card padding="none" class="mt-6 overflow-hidden">
+            <ul data-testid="notification-list">
+                {#each notifications as notification (notification.id)}
+                    <li>
+                        <NotificationListItem {notification} />
+                    </li>
+                {/each}
+            </ul>
+        </Card>
+        {#if meta.last_page > 1}
+            <div class="mt-6">
+                <Pagination
+                    currentPage={meta.current_page}
+                    totalPages={meta.last_page}
+                    onChange={goToPage}
+                    testId="notifications-pagination"
+                />
+            </div>
+        {/if}
+    {/if}
+</AppLayout>
diff --git a/resources/js/types/notification.ts b/resources/js/types/notification.ts
new file mode 100644
index 0000000..0c6cf54
--- /dev/null
+++ b/resources/js/types/notification.ts
@@ -0,0 +1,54 @@
+/**
+ * アプリ内通知 (通知センター) の Inertia props 型。
+ * PHP 側 App\Enums\Notification\NotificationType /
+ * App\DataTransferObjects\Notification\NotificationListItemData::toArray() と対で保守する
+ * (値集合の一致は tests/Architecture/NotificationTypeTsSyncInvariantTest が固定する)。
+ */
+
+/** PHP: App\Enums\Notification\NotificationType と対 (値集合を一致させる) */
+export type NotificationType =
+    | "manual_analyzed"
+    | "manual_rendered"
+    | "invitation_received"
+    | "ticket_balance_low";
+
+/** 解析/レンダ完了通知の payload (manual_analyzed / manual_rendered 共用) */
+export interface ManualJobPayload {
+    project_id: number;
+    manual_id: number;
+    manual_title: string;
+    organization_name: string;
+    succeeded: boolean;
+    error: string | null;
+}
+
+export interface InvitationReceivedPayload {
+    organization_name: string;
+}
+
+export interface TicketBalanceLowPayload {
+    organization_name: string;
+    balance: number;
+    threshold: number;
+}
+
+/**
+ * 通知一覧の 1 行。type を discriminant にした union。
+ * 未知 type (enum⇔TS の一時的ドリフト) は string として受け、fallback 描画する。
+ */
+export interface NotificationItem {
+    id: string;
+    type: NotificationType | (string & {});
+    organization_id: number | null;
+    /** ISO8601。null = 未読 */
+    read_at: string | null;
+    /** ISO8601 */
+    created_at: string;
+    /** サーバ側の検証復元に失敗した場合は null (fallback 描画) */
+    payload: ManualJobPayload | InvitationReceivedPayload | TicketBalanceLowPayload | null;
+}
+
+/** HandleInertiaRequests が共有する notifications props */
+export interface NotificationSharedProps {
+    unreadCount: number;
+}
diff --git a/routes/web.php b/routes/web.php
index f47a275..cd1e29c 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -15,6 +15,7 @@
 use App\Http\Controllers\DebugLoginController;
 use App\Http\Controllers\HomeController;
 use App\Http\Controllers\Marketing\PricingController;
+use App\Http\Controllers\NotificationController;
 use App\Http\Controllers\Organizations\InvitationAcceptanceController;
 use App\Http\Controllers\Organizations\OrganizationApiKeyController;
 use App\Http\Controllers\Organizations\OrganizationController;
@@ -320,6 +321,25 @@
     Route::post('/purchase-tickets/checkout', [TicketPurchaseController::class, 'checkout'])
         ->name('billing.tickets.checkout');
 
+    /*
+    | 通知センター (課金ゲート外 = サブスク失効中でもベルは機能させる。残高/課金系通知の
+    | 受け皿として必要)。{notification} は implicit binding を使わず controller が
+    | $request->user()->notifications() 経由で解決する (cross-user は構造的に 404 =
+    | 存在オラクル封じ。1 param のため NestedRouteIdorDefenseTest の inventory 対象外)。
+    | whereUuid は不正形式 id を route 不一致 = 404 に落とす (pgsql uuid 比較の 22P02 防止)。
+    | open は POST + 303 (GET にしない = prefetch による意図しない既読化防止)。
+    */
+    Route::get('/notifications', [NotificationController::class, 'index'])
+        ->name('notifications.index');
+    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
+        ->name('notifications.read-all');
+    Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])
+        ->whereUuid('notification')
+        ->name('notifications.open');
+    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
+        ->whereUuid('notification')
+        ->name('notifications.read');
+
     /*
     | 組織配下の業務 route (課金ゲート対象)。有効な subscription (BillingAccess 判定)
     | を持たない組織は billing へ redirect される (JSON は 402)。
diff --git a/tests/Architecture/InAppNotificationTypeInvariantTest.php b/tests/Architecture/InAppNotificationTypeInvariantTest.php
new file mode 100644
index 0000000..fca6cfb
--- /dev/null
+++ b/tests/Architecture/InAppNotificationTypeInvariantTest.php
@@ -0,0 +1,81 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Notification\NotificationType;
+use App\Notifications\InApp\AppNotification;
+
+/*
+ * 「このアプリの database 通知は type = NotificationType enum 値」規約の
+ * deny-by-default 固定 (詳細設計 施策8)。
+ *
+ * app/Notifications/InApp 配下の全 Notification クラス (基底 AppNotification を除く) を
+ * ファイル走査で列挙し、以下を強制する:
+ * 1. AppNotification 派生であること (via = database のみ / organizationId 契約を継承)
+ * 2. type() が NotificationType を返し、databaseType() = type()->value であること
+ *    (クラス名を DB に置かない。クラス名前提の読取ロジックをアプリ内に作らせない)
+ * 3. type() 値がクラス間で重複しないこと (読み出し側の payload 復元分岐が一意)
+ *
+ * DB 実発火の round-trip (organization_id 列・type 列) は Feature の
+ * NotificationSchemaTest が担保する (Architecture lane は DB を使わない)。
+ */
+
+/**
+ * app/Notifications/InApp 配下の具象 Notification クラスを列挙する (deny-by-default 走査)。
+ *
+ * @return list<class-string>
+ */
+function inAppNotificationConcreteClasses(): array
+{
+    $files = glob(app_path('Notifications/InApp/*.php'));
+    expect($files)->not->toBeFalse();
+    assert(is_array($files));
+
+    $classes = [];
+    foreach ($files as $file) {
+        $class = 'App\\Notifications\\InApp\\'.basename($file, '.php');
+        expect(class_exists($class))->toBeTrue("クラスが存在しません: {$class}");
+        $reflection = new ReflectionClass($class);
+        if ($reflection->isAbstract()) {
+            continue; // 基底 (AppNotification)
+        }
+        $classes[] = $class;
+    }
+
+    return $classes;
+}
+
+test('InApp 配下の全具象クラスは AppNotification 派生である (deny-by-default)', function (): void {
+    $classes = inAppNotificationConcreteClasses();
+    expect($classes)->not->toBeEmpty();
+
+    foreach ($classes as $class) {
+        expect(is_subclass_of($class, AppNotification::class))
+            ->toBeTrue("{$class} は AppNotification を継承していません");
+    }
+});
+
+test('全具象クラスの type() は NotificationType を返し databaseType() = enum 値である', function (): void {
+    foreach (inAppNotificationConcreteClasses() as $class) {
+        $reflection = new ReflectionClass($class);
+        $instance = $reflection->newInstanceWithoutConstructor();
+        assert($instance instanceof AppNotification);
+
+        $type = $instance->type();
+        expect($type)->toBeInstanceOf(NotificationType::class);
+        expect($instance->databaseType(new stdClass))->toBe($type->value);
+        expect($instance->via(new stdClass))->toBe(['database']);
+    }
+});
+
+test('type() 値はクラス間で重複しない (payload 復元分岐の一意性)', function (): void {
+    $values = [];
+    foreach (inAppNotificationConcreteClasses() as $class) {
+        $reflection = new ReflectionClass($class);
+        $instance = $reflection->newInstanceWithoutConstructor();
+        assert($instance instanceof AppNotification);
+        $values[] = $instance->type()->value;
+    }
+
+    expect($values)->toBe(array_values(array_unique($values)));
+});
diff --git a/tests/Architecture/ManualEnumTsSyncInvariantTest.php b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
index acdbc01..6d79bd6 100644
--- a/tests/Architecture/ManualEnumTsSyncInvariantTest.php
+++ b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
@@ -7,6 +7,7 @@
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\RenderStep;
+use Tests\Support\TsUnionValues;
 
 /*
  * PHP enum ⇔ TS literal union の値集合同期 invariant (概念設計 Round 3)。
@@ -14,6 +15,8 @@
  * resources/js/types/manual.ts の literal union を正規表現で抽出し、PHP enum の
  * 値集合と完全一致することを固定する (フロントの CTA 分岐・型分岐が enum 追加で
  * silent に壊れるのを防ぐ)。抽出不能 (degenerate PASS) は fail させる。
+ * 抽出ロジックは共有 helper (Tests\Support\TsUnionValues) に置き、
+ * NotificationTypeTsSyncInvariantTest と共用する。
  */
 
 /**
@@ -23,63 +26,27 @@
  */
 function extractTsUnionValues(string $typeName): array
 {
-    $path = base_path('resources/js/types/manual.ts');
-    $contents = file_get_contents($path);
-    if ($contents === false) {
-        throw new RuntimeException("types/manual.ts を読めません: {$path}");
-    }
-
-    // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
-    $matched = preg_match(
-        '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
-        $contents,
-        $matches,
-    );
-    if ($matched !== 1) {
-        throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
-    }
-
-    $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
-    if ($literalCount === false || $literalCount === 0) {
-        throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
-    }
-
-    $values = $literals[1];
-    sort($values);
-
-    return $values;
-}
-
-/**
- * @param  list<BackedEnum>  $cases
- * @return list<string>
- */
-function enumStringValues(array $cases): array
-{
-    $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
-    sort($values);
-
-    return $values;
+    return TsUnionValues::extract('resources/js/types/manual.ts', $typeName);
 }
 
 test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderKind'))->toBe(enumStringValues(RenderKind::cases()));
+    expect(extractTsUnionValues('RenderKind'))->toBe(TsUnionValues::enumStringValues(RenderKind::cases()));
 });
 
 test('RenderStep の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderStep'))->toBe(enumStringValues(RenderStep::cases()));
+    expect(extractTsUnionValues('RenderStep'))->toBe(TsUnionValues::enumStringValues(RenderStep::cases()));
 });
 
 test('RenderErrorCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderErrorCode'))->toBe(enumStringValues(RenderErrorCode::cases()));
+    expect(extractTsUnionValues('RenderErrorCode'))->toBe(TsUnionValues::enumStringValues(RenderErrorCode::cases()));
 });
 
 test('RenderConflictType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('RenderConflictType'))->toBe(enumStringValues(RenderConflictType::cases()));
+    expect(extractTsUnionValues('RenderConflictType'))->toBe(TsUnionValues::enumStringValues(RenderConflictType::cases()));
 });
 
 test('AnalysisJobStatus (JobStatus 共用) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
-    expect(extractTsUnionValues('AnalysisJobStatus'))->toBe(enumStringValues(JobStatus::cases()));
+    expect(extractTsUnionValues('AnalysisJobStatus'))->toBe(TsUnionValues::enumStringValues(JobStatus::cases()));
 });
 
 test('抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
diff --git a/tests/Architecture/NotificationTypeTsSyncInvariantTest.php b/tests/Architecture/NotificationTypeTsSyncInvariantTest.php
new file mode 100644
index 0000000..b884bcd
--- /dev/null
+++ b/tests/Architecture/NotificationTypeTsSyncInvariantTest.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Notification\NotificationType;
+use Tests\Support\TsUnionValues;
+
+/*
+ * NotificationType (PHP enum) ⇔ resources/js/types/notification.ts (TS literal union) の
+ * 値集合同期 invariant。フロントの type 駆動描画 (アイコン/文言分岐) が enum 追加で
+ * silent に壊れるのを防ぐ (抽出は共有 helper TsUnionValues。抽出不能 = fail)。
+ */
+
+test('NotificationType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
+    expect(TsUnionValues::extract('resources/js/types/notification.ts', 'NotificationType'))
+        ->toBe(TsUnionValues::enumStringValues(NotificationType::cases()));
+});
+
+test('notification.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
+    expect(fn (): array => TsUnionValues::extract('resources/js/types/notification.ts', 'NoSuchUnionName'))
+        ->toThrow(RuntimeException::class, 'degenerate PASS');
+});
diff --git a/tests/Feature/Notifications/InvitationNotificationTest.php b/tests/Feature/Notifications/InvitationNotificationTest.php
new file mode 100644
index 0000000..3534655
--- /dev/null
+++ b/tests/Feature/Notifications/InvitationNotificationTest.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\AdminConsoleRole;
+use App\Models\User;
+use App\Notifications\InApp\InvitationReceivedNotification;
+use App\Notifications\OrganizationInvitationNotification;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Notification;
+
+/*
+ * 組織招待のアプリ内通知配線 (施策5):
+ * - 既存ユーザーの email へ招待 → その User に 1 件 (whereBlind 一致。payload に token を含まない)
+ * - 未登録 email → 通知 0 (メールのみ)
+ * - 既存の招待メール (OrganizationInvitationNotification) は従来どおり送信される
+ */
+
+test('既存ユーザーの email へ招待 → その User に 1 件 (org 名スナップショット・token 非含有)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('招待テスト組織');
+    $existing = User::factory()->create(['email' => 'invited@example.com']);
+
+    app(OrganizationMembershipService::class)->inviteMember(
+        $organization, $owner, 'invited@example.com', AdminConsoleRole::Admin,
+    );
+
+    $rows = DB::table('notifications')->where('notifiable_id', $existing->id)->get();
+    expect($rows)->toHaveCount(1);
+    expect($rows[0]->type)->toBe('invitation_received');
+    expect((int) $rows[0]->organization_id)->toBe($organization->id);
+
+    $data = json_decode((string) $rows[0]->data, true);
+    expect($data)->toBe(['organization_name' => '招待テスト組織']);
+    // 平文 token を payload に含めない (token 平文非保存の不変条件)
+    expect((string) $rows[0]->data)->not->toContain('token');
+});
+
+test('未登録 email へ招待 → アプリ内通知 0 (メールのみ)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    app(OrganizationMembershipService::class)->inviteMember(
+        $organization, $owner, 'nobody@example.com', AdminConsoleRole::Admin,
+    );
+
+    expect(DB::table('notifications')->count())->toBe(0);
+});
+
+test('招待メールは従来どおり送信され、既存ユーザーにはアプリ内通知も送られる', function (): void {
+    Notification::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $existing = User::factory()->create(['email' => 'both@example.com']);
+
+    app(OrganizationMembershipService::class)->inviteMember(
+        $organization, $owner, 'both@example.com', AdminConsoleRole::Admin,
+    );
+
+    // メール (on-demand route) は従来どおり
+    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
+    // アプリ内通知 (database channel) も既存ユーザー宛に送られる
+    Notification::assertSentTo($existing, InvitationReceivedNotification::class);
+});
diff --git a/tests/Feature/Notifications/ManualAnalysisNotificationTest.php b/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
new file mode 100644
index 0000000..516e91f
--- /dev/null
+++ b/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
@@ -0,0 +1,195 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\JobStatus;
+use App\Models\AnalysisJob;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\AnalysisJobService;
+use App\Services\Manual\AnalysisPipeline;
+use App\Services\Notification\NotificationCenterService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Storage;
+use Kent013\PrismPrompt\Prompt;
+use Kent013\PrismPrompt\Testing\TextResponseFake;
+
+/*
+ * 解析ジョブ terminal 遷移の通知配線 (施策3/4):
+ * - 成功 (pipeline finalize true) → creator ∪ triggeredBy に各 1 件 (succeeded=true)
+ * - creator = triggeredBy は dedup で 1 件のみ
+ * - 失敗 (failJob true) → 1 件 (succeeded=false)。failJob 2 回目 no-op で二重発火しない
+ * - recoverStale 経由の失敗も通知される
+ * - 退会済み (org 非所属) creator へは送らない / manual 削除競合は通知スキップ (例外なし)
+ */
+
+beforeEach(function (): void {
+    // executeSync は fake 中も PromptExecutionCompleted を発火し listener が FX 解決 (HTTP) を試みる
+    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
+});
+
+/**
+ * queued 解析 job 一式 (creator = org member。triggered_by は $triggeredBy)。
+ *
+ * @return array{Organization, User, Project, VideoManual, AnalysisJob}
+ */
+function analysisNotificationContext(?User $creator = null, ?User $triggeredBy = null): array
+{
+    Storage::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $creator ??= $owner;
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->createdBy($creator)->create([
+        'status' => 'analyzing',
+        'title' => '通知テスト手順書',
+    ]);
+    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
+    Storage::put($path, str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5));
+    $document = SourceDocument::factory()->forManual($manual)->create([
+        'file_path' => $path,
+        'mime' => 'text/plain',
+    ]);
+    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create([
+        'triggered_by' => $triggeredBy?->id,
+    ]);
+    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');
+
+    return [$organization, $owner, $project, $manual, $job];
+}
+
+/** 成功 3 段の Prompt fake */
+function fakeAnalysisLlmSuccess(): void
+{
+    Prompt::fake([
+        TextResponseFake::make()->withText(json_encode([
+            'header' => ['title' => 'SOP', 'department' => null, 'revision' => null],
+            'sections' => [[
+                'title' => null,
+                'steps' => [[
+                    'no' => 1, 'work_process' => 'ネジを締める', 'work_points' => [],
+                    'safety_points' => [], 'quality_points' => [], 'pm_points' => [],
+                ]],
+            ]],
+        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
+        TextResponseFake::make()->withText(json_encode([
+            'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => []]],
+        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
+        TextResponseFake::make()->withText(json_encode([
+            'cuts' => [[
+                'no' => 1, 'type' => 'step', 'parent_no' => null,
+                'scene' => 'ネジ締め', 'shot_type' => 'hiki', 'shooting_point' => null,
+                'narration' => 'ネジを締めます', 'subtitle_primary' => null, 'subtitle_secondary' => null,
+            ]],
+        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
+    ]);
+}
+
+test('解析成功 → creator と triggeredBy に各 1 件 (succeeded=true・org/manual スナップショット)', function (): void {
+    [$organization, $owner, $project, $manual, $job] = analysisNotificationContext();
+    $editor = attachOrganizationMember($organization);
+    $job->forceFill(['triggered_by' => $editor->id])->save();
+
+    fakeAnalysisLlmSuccess();
+    app(AnalysisPipeline::class)->run($job->id);
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    // creator (owner) と triggeredBy (editor) に各 1 件
+    foreach ([$owner, $editor] as $recipient) {
+        $rows = DB::table('notifications')
+            ->where('notifiable_id', $recipient->id)
+            ->where('type', 'manual_analyzed')
+            ->get();
+        expect($rows)->toHaveCount(1);
+        $data = json_decode((string) $rows[0]->data, true);
+        expect($data['succeeded'])->toBeTrue();
+        expect($data['manual_title'])->toBe('通知テスト手順書');
+        expect($data['project_id'])->toBe($project->id);
+        expect($data['manual_id'])->toBe($manual->id);
+        expect((int) $rows[0]->organization_id)->toBe($organization->id);
+    }
+    expect(DB::table('notifications')->count())->toBe(2);
+});
+
+test('creator = triggeredBy のとき通知は 1 件のみ (dedup)', function (): void {
+    [, $owner, , , $job] = analysisNotificationContext();
+    $job->forceFill(['triggered_by' => $owner->id])->save();
+
+    fakeAnalysisLlmSuccess();
+    app(AnalysisPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+    expect(DB::table('notifications')->count())->toBe(1);
+    expect((int) DB::table('notifications')->firstOrFail()->notifiable_id)->toBe($owner->id);
+});
+
+test('解析失敗 (failJob) → 1 件 (succeeded=false + error 文言)。2 回目 no-op で二重発火しない', function (): void {
+    [, $owner, , , $job] = analysisNotificationContext();
+    $job->forceFill(['status' => JobStatus::Running->value])->save();
+
+    $failed = app(AnalysisJobService::class)->failJob($job, '解析に失敗しました。時間をおいて再実行してください。');
+    expect($failed)->toBeTrue();
+
+    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
+    expect($rows)->toHaveCount(1);
+    $data = json_decode((string) $rows[0]->data, true);
+    expect($data['succeeded'])->toBeFalse();
+    expect($data['error'])->toBe('解析に失敗しました。時間をおいて再実行してください。');
+
+    // terminal 済み no-op (false) は通知しない = 二重発火なし
+    expect(app(AnalysisJobService::class)->failJob($job->refresh(), '二重'))->toBeFalse();
+    expect(DB::table('notifications')->count())->toBe(1);
+});
+
+test('recoverStale 経由の失敗も通知が 1 件発火する', function (): void {
+    [, $owner, , , $job] = analysisNotificationContext();
+    $job->forceFill(['status' => JobStatus::Running->value])->save();
+    // stale 閾値超過に細工 (updated_at を過去へ)
+    DB::table('analysis_jobs')->where('id', $job->id)
+        ->update(['updated_at' => now()->subHours(2)]);
+
+    expect(app(AnalysisJobService::class)->recoverStale())->toBe(1);
+
+    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
+    expect($rows)->toHaveCount(1);
+    expect(json_decode((string) $rows[0]->data, true)['succeeded'])->toBeFalse();
+});
+
+test('退会済み (org 非所属) creator へは通知しない', function (): void {
+    $outsider = User::factory()->create(); // org に attach しない = 退会相当
+    [, , , , $job] = analysisNotificationContext(creator: $outsider);
+    $job->forceFill(['status' => JobStatus::Running->value])->save();
+
+    app(AnalysisJobService::class)->failJob($job, '失敗');
+
+    expect(DB::table('notifications')->count())->toBe(0);
+});
+
+test('manual 削除競合は通知スキップ (例外にしない)', function (): void {
+    [, , , $manual, $job] = analysisNotificationContext();
+    $job->forceFill(['status' => JobStatus::Failed->value, 'error' => '失敗'])->save();
+    $manual->delete(); // terminal 遷移と通知の間に manual が消えた競合
+
+    app(NotificationCenterService::class)->notifyAnalysisFinished($job);
+
+    expect(DB::table('notifications')->count())->toBe(0);
+});
+
+test('trigger に actor を渡すと triggered_by が記録される (web 経路の配線)', function (): void {
+    Queue::fake();
+    Storage::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->createdBy($owner)->create(['status' => 'draft']);
+    SourceDocument::factory()->forManual($manual)->create();
+    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');
+
+    $job = app(AnalysisJobService::class)->trigger($project, $manual, $owner);
+
+    expect($job->triggered_by)->toBe($owner->id);
+});
diff --git a/tests/Feature/Notifications/ManualRenderNotificationTest.php b/tests/Feature/Notifications/ManualRenderNotificationTest.php
new file mode 100644
index 0000000..f8e5bc1
--- /dev/null
+++ b/tests/Feature/Notifications/ManualRenderNotificationTest.php
@@ -0,0 +1,163 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
+use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderErrorCode;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\RenderJobService;
+use App\Services\Manual\RenderPipeline;
+use App\Services\Render\VideoComposer;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Storage;
+
+/*
+ * レンダジョブ terminal 遷移の通知配線 (施策3/4):
+ * - render 成功 (pipeline finalize true) / 失敗 (failJob true) → 1 件
+ * - preview は成功/失敗とも通知 0
+ * - failJob 2 回目 no-op で二重発火しない / recoverStale 経由の失敗通知
+ */
+
+/** テスト用 fake composer (実 ffmpeg に触れない) */
+final class NotificationFakeRenderComposer implements VideoComposer
+{
+    public ?Throwable $throws = null;
+
+    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
+    {
+        if ($this->throws !== null) {
+            throw $this->throws;
+        }
+        $durations = [];
+        foreach ($manifest->clips as $index => $clip) {
+            $durations[$clip->cutId] = 1_000 * ($index + 1);
+            $onClipComposed($index + 1, count($manifest->clips));
+        }
+        $localPath = "{$workDir}/output.mp4";
+        file_put_contents($localPath, 'fake-mp4');
+
+        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
+    }
+}
+
+/**
+ * ready manual (採用済みテイク) + 残高 + fake composer 一式。creator = owner。
+ *
+ * @return array{Organization, User, Project, VideoManual, NotificationFakeRenderComposer}
+ */
+function renderNotificationContext(): array
+{
+    Queue::fake();
+    Storage::fake('s3');
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->createdBy($owner)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+        'title' => '通知テスト動画',
+    ]);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
+    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');
+
+    $fake = new NotificationFakeRenderComposer;
+    app()->instance(VideoComposer::class, $fake);
+
+    return [$organization, $owner, $project, $manual, $fake];
+}
+
+test('render 成功 → creator と triggeredBy に各 1 件 (succeeded=true)', function (): void {
+    [$organization, $owner, $project, $manual] = renderNotificationContext();
+    $editor = attachOrganizationMember($organization);
+
+    $job = app(RenderJobService::class)->trigger($project, $manual, $editor);
+    app(RenderPipeline::class)->run($job->id);
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    foreach ([$owner, $editor] as $recipient) {
+        $rows = DB::table('notifications')
+            ->where('notifiable_id', $recipient->id)
+            ->where('type', 'manual_rendered')
+            ->get();
+        expect($rows)->toHaveCount(1);
+        $data = json_decode((string) $rows[0]->data, true);
+        expect($data['succeeded'])->toBeTrue();
+        expect($data['manual_title'])->toBe('通知テスト動画');
+        expect((int) $rows[0]->organization_id)->toBe($organization->id);
+    }
+    expect(DB::table('notifications')->count())->toBe(2);
+});
+
+test('render 失敗 (failJob) → 1 件 (succeeded=false)。2 回目 no-op で二重発火しない', function (): void {
+    [, $owner, $project, $manual] = renderNotificationContext();
+    $job = app(RenderJobService::class)->trigger($project, $manual, $owner);
+
+    $failed = app(RenderJobService::class)->failJob($job, RenderErrorCode::Internal, '書き出しに失敗しました。');
+    expect($failed)->toBeTrue();
+
+    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
+    expect($rows)->toHaveCount(1);
+    $data = json_decode((string) $rows[0]->data, true);
+    expect($data['succeeded'])->toBeFalse();
+    expect($data['error'])->toBe('書き出しに失敗しました。');
+
+    expect(app(RenderJobService::class)->failJob($job->refresh(), RenderErrorCode::Internal, '二重'))->toBeFalse();
+    expect(DB::table('notifications')->count())->toBe(1);
+});
+
+test('preview は成功/失敗とも通知 0', function (): void {
+    [, $owner, $project, $manual, $fake] = renderNotificationContext();
+
+    // 成功 preview
+    $preview = app(RenderJobService::class)->triggerPreview($project, $manual, $owner);
+    app(RenderPipeline::class)->run($preview->id);
+    expect($preview->refresh()->status)->toBe(JobStatus::Succeeded);
+    expect(DB::table('notifications')->count())->toBe(0);
+
+    // 失敗 preview (failJob 直呼び)
+    $failing = app(RenderJobService::class)->triggerPreview($project, $manual, $owner);
+    expect(app(RenderJobService::class)->failJob($failing, RenderErrorCode::Internal, '失敗'))->toBeTrue();
+    expect(DB::table('notifications')->count())->toBe(0);
+});
+
+test('recoverStale 経由の render 失敗も通知される', function (): void {
+    [, $owner, $project, $manual] = renderNotificationContext();
+    $job = app(RenderJobService::class)->trigger($project, $manual, $owner);
+    DB::table('render_jobs')->where('id', $job->id)->update([
+        'status' => JobStatus::Running->value,
+        'updated_at' => now()->subHours(2),
+    ]);
+
+    expect(app(RenderJobService::class)->recoverStale())->toBe(1);
+
+    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
+    expect($rows)->toHaveCount(1);
+    expect(json_decode((string) $rows[0]->data, true)['succeeded'])->toBeFalse();
+});
+
+test('stale 先勝ちで finalize が false のとき成功通知は発火しない (失敗通知のみ)', function (): void {
+    [, $owner, $project, $manual, $fake] = renderNotificationContext();
+    $job = app(RenderJobService::class)->trigger($project, $manual, $owner);
+    // compose 中に stale 回復 cron が先勝ちした状況を細工 (failJob が先に terminal 化)
+    $fake->throws = null;
+    app(RenderJobService::class)->failJob($job, RenderErrorCode::Timeout, 'タイムアウト');
+    expect(DB::table('notifications')->count())->toBe(1); // 失敗通知
+
+    // 遅延実行された pipeline は queued guard で no-op → 通知は増えない
+    app(RenderPipeline::class)->run($job->id);
+    expect(DB::table('notifications')->count())->toBe(1);
+    expect(RenderJob::query()->findOrFail($job->id)->status)->toBe(JobStatus::Failed);
+});
diff --git a/tests/Feature/Notifications/NotificationCenterTest.php b/tests/Feature/Notifications/NotificationCenterTest.php
new file mode 100644
index 0000000..2c4f223
--- /dev/null
+++ b/tests/Feature/Notifications/NotificationCenterTest.php
@@ -0,0 +1,239 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Notification\InvitationReceivedPayload;
+use App\DataTransferObjects\Notification\ManualJobPayload;
+use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Notifications\InApp\InvitationReceivedNotification;
+use App\Notifications\InApp\ManualAnalyzedNotification;
+use App\Notifications\InApp\TicketBalanceLowNotification;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * 通知センター route (施策6): index / open / read / read-all の全分岐。
+ * - 自分宛のみ表示 (全 org 横断)・ページネーション
+ * - cross-user は 404 (403 でない = 存在秘匿)・GET open は 405
+ * - open の遷移解決 (manual 現存 / 削除済み / org 不一致 / 残高 / 招待 / 未知 type)
+ * - 未認証は login へ / unverified は verified ガード
+ */
+
+/**
+ * 通知一覧テスト用: owner の org + project + manual (creator=owner)。
+ *
+ * @return array{Organization, User, Project, VideoManual}
+ */
+function notificationCenterContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->createdBy($owner)->create([
+        'title' => '通知対象マニュアル',
+    ]);
+
+    return [$organization, $owner, $project, $manual];
+}
+
+/** manual 系通知を owner へ発火し、その通知 uuid を返す */
+function notifyManualAnalyzed(Organization $organization, User $user, Project $project, VideoManual $manual): string
+{
+    $user->notify(new ManualAnalyzedNotification($organization->id, new ManualJobPayload(
+        projectId: $project->id,
+        manualId: $manual->id,
+        manualTitle: $manual->title,
+        organizationName: $organization->name,
+        succeeded: true,
+        error: null,
+    )));
+
+    $id = $user->notifications()->latest()->firstOrFail()->getKey();
+    assert(is_string($id));
+
+    return $id;
+}
+
+test('index: 自分宛のみ表示 (他人の通知が混ざらない)・未読/既読・全 org 横断', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $other = attachOrganizationMember($organization);
+    notifyManualAnalyzed($organization, $owner, $project, $manual);
+    notifyManualAnalyzed($organization, $other, $project, $manual);
+
+    // 別 org の通知も自分宛なら見える (全 org 横断)
+    [$organization2] = createOrganizationWithOwner('第二組織');
+    $organization2->users()->attach($owner);
+    $owner->notify(new TicketBalanceLowNotification(
+        $organization2->id, new TicketBalanceLowPayload('第二組織', 3, 5),
+    ));
+
+    $this->actingAs($owner)->get('/notifications')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Notifications/Index')
+            ->has('notifications', 2)
+            ->where('meta.total', 2));
+});
+
+test('index: ページネーション (20 件/頁)', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    for ($i = 0; $i < 25; $i++) {
+        notifyManualAnalyzed($organization, $owner, $project, $manual);
+    }
+
+    $this->actingAs($owner)->get('/notifications')
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('notifications', 20)
+            ->where('meta.last_page', 2)
+            ->where('meta.total', 25));
+
+    $this->actingAs($owner)->get('/notifications?page=2')
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('notifications', 5)
+            ->where('meta.current_page', 2));
+});
+
+test('read: 自分の通知は既読化され back で戻る', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);
+
+    $this->actingAs($owner)->from('/notifications')
+        ->post("/notifications/{$id}/read")
+        ->assertRedirect('/notifications');
+
+    expect($owner->unreadNotifications()->count())->toBe(0);
+});
+
+test('read/open: 他人の通知 uuid は 404 (403 でない = 存在秘匿)。存在しない uuid も 404', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $other = attachOrganizationMember($organization);
+    $othersId = notifyManualAnalyzed($organization, $other, $project, $manual);
+
+    $this->actingAs($owner)->post("/notifications/{$othersId}/read")->assertNotFound();
+    $this->actingAs($owner)->post("/notifications/{$othersId}/open")->assertNotFound();
+    $this->actingAs($owner)->post('/notifications/'.Str::uuid()->toString().'/read')->assertNotFound();
+
+    // 他人の通知は未読のまま (影響しない)
+    expect($other->unreadNotifications()->count())->toBe(1);
+});
+
+test('read/open: 不正形式 (非UUID) の id は route 不一致で 404 (pgsql uuid 比較の 22P02 = 500 を出さない)', function (): void {
+    [, $owner] = notificationCenterContext();
+
+    $this->actingAs($owner)->post('/notifications/not-a-uuid/read')->assertNotFound();
+    $this->actingAs($owner)->post('/notifications/not-a-uuid/open')->assertNotFound();
+});
+
+test('read-all: 自分の未読のみ全既読 (他人の行に影響しない)', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $other = attachOrganizationMember($organization);
+    notifyManualAnalyzed($organization, $owner, $project, $manual);
+    notifyManualAnalyzed($organization, $owner, $project, $manual);
+    notifyManualAnalyzed($organization, $other, $project, $manual);
+
+    $this->actingAs($owner)->from('/notifications')
+        ->post('/notifications/read-all')
+        ->assertRedirect('/notifications')
+        ->assertSessionHas('success');
+
+    expect($owner->unreadNotifications()->count())->toBe(0);
+    expect($other->unreadNotifications()->count())->toBe(1);
+});
+
+test('open: manual 現存 + 同一 org → manuals.show へ 303 + 既読化', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);
+
+    $response = $this->actingAs($owner)->post("/notifications/{$id}/open");
+
+    $response->assertStatus(303)
+        ->assertRedirect("/projects/{$project->id}/manuals/{$manual->id}");
+    expect($owner->unreadNotifications()->count())->toBe(0);
+});
+
+test('open: manual 削除済み → 一覧へ 303 + info (既読化はされる)', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);
+    $manual->delete();
+
+    $this->actingAs($owner)->post("/notifications/{$id}/open")
+        ->assertStatus(303)
+        ->assertRedirect('/notifications')
+        ->assertSessionHas('info', '対象の動画マニュアルは削除されています。');
+    expect($owner->unreadNotifications()->count())->toBe(0);
+});
+
+test('open: 通知 org ≠ current org → 一覧へ 303 + 組織切替の案内 (自動切替しない)', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);
+
+    // current org を別組織へ切り替えた状態にする
+    [$organization2] = createOrganizationWithOwner('別組織');
+    $organization2->users()->attach($owner);
+    $owner->forceFill(['current_organization_id' => $organization2->id])->save();
+
+    $this->actingAs($owner)->post("/notifications/{$id}/open")
+        ->assertStatus(303)
+        ->assertRedirect('/notifications')
+        ->assertSessionHas('info', 'この通知は別の組織のものです。組織を切り替えてから開いてください。');
+});
+
+test('open: ticket_balance_low → billing.tickets.show へ 303', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $owner->notify(new TicketBalanceLowNotification(
+        $organization->id, new TicketBalanceLowPayload($organization->name, 3, 5),
+    ));
+    $id = $owner->notifications()->firstOrFail()->getKey();
+
+    $this->actingAs($owner)->post("/notifications/{$id}/open")
+        ->assertStatus(303)
+        ->assertRedirect('/purchase-tickets');
+});
+
+test('open: invitation_received → 一覧へ 303 + 招待案内 info', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $owner->notify(new InvitationReceivedNotification(
+        $organization->id, new InvitationReceivedPayload($organization->name),
+    ));
+    $id = $owner->notifications()->firstOrFail()->getKey();
+
+    $this->actingAs($owner)->post("/notifications/{$id}/open")
+        ->assertStatus(303)
+        ->assertRedirect('/notifications')
+        ->assertSessionHas('info', '招待はメールの受諾リンクから参加してください。');
+});
+
+test('open: 未知 type → 一覧へ 303 + 汎用 info (招待文言と混同しない)・既読化のみ', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $owner->notifications()->create([
+        'id' => Str::uuid()->toString(),
+        'type' => 'legacy_unknown_type', // enum⇔DB ドリフトの防御分岐
+        'data' => [],
+    ]);
+    $id = $owner->notifications()->firstOrFail()->getKey();
+
+    $this->actingAs($owner)->post("/notifications/{$id}/open")
+        ->assertStatus(303)
+        ->assertRedirect('/notifications')
+        ->assertSessionHas('info', 'この通知には開ける対象がありません。');
+    expect($owner->unreadNotifications()->count())->toBe(0);
+});
+
+test('GET /notifications/{id}/open は 405 (POST 限定 = prefetch 既読化防止)', function (): void {
+    [$organization, $owner, $project, $manual] = notificationCenterContext();
+    $id = notifyManualAnalyzed($organization, $owner, $project, $manual);
+
+    $this->actingAs($owner)->get("/notifications/{$id}/open")->assertStatus(405);
+    expect($owner->unreadNotifications()->count())->toBe(1); // 既読化されない
+});
+
+test('未認証は login へ redirect / unverified は verified ガード', function (): void {
+    $this->get('/notifications')->assertRedirect('/login');
+
+    $unverified = User::factory()->unverified()->create();
+    $this->actingAs($unverified)->get('/notifications')->assertRedirect('/email/verify');
+});
diff --git a/tests/Feature/Notifications/NotificationSchemaTest.php b/tests/Feature/Notifications/NotificationSchemaTest.php
new file mode 100644
index 0000000..5cf7fa5
--- /dev/null
+++ b/tests/Feature/Notifications/NotificationSchemaTest.php
@@ -0,0 +1,127 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Notification\InvitationReceivedPayload;
+use App\DataTransferObjects\Notification\ManualJobPayload;
+use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
+use App\Notifications\InApp\InvitationReceivedNotification;
+use App\Notifications\InApp\ManualAnalyzedNotification;
+use App\Notifications\InApp\ManualRenderedNotification;
+use App\Notifications\InApp\TicketBalanceLowNotification;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * notifications スキーマ + OrganizationScopedDatabaseChannel の round-trip (施策1/2):
+ * - notify() 実発火 → type = NotificationType enum 値 (クラス名でない)・organization_id 列・data 形状
+ * - v1 の全通知種別で organization_id が非 null で書き込まれる (DB 列は nullable だが
+ *   null を書く種別は存在しない、の固定)
+ * - 未読 count クエリ (複合 index 前提の機能面)
+ * - payload DTO の tryFromArray 検証復元 (不正形状 → null)
+ */
+
+function manualJobPayloadFixture(bool $succeeded = true): ManualJobPayload
+{
+    return new ManualJobPayload(
+        projectId: 1,
+        manualId: 2,
+        manualTitle: 'ネジ締め手順',
+        organizationName: 'テスト組織',
+        succeeded: $succeeded,
+        error: $succeeded ? null : '解析に失敗しました。',
+    );
+}
+
+test('notify 実発火で type=enum 値・organization_id 列・data 形状が書き込まれる (channel round-trip)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
+
+    $row = DB::table('notifications')->first();
+    expect($row)->not->toBeNull();
+    expect($row->type)->toBe('manual_analyzed'); // クラス名を DB に置かない
+    expect($row->notifiable_type)->toBe($owner->getMorphClass());
+    expect((int) $row->notifiable_id)->toBe($owner->id);
+    expect((int) $row->organization_id)->toBe($organization->id);
+
+    $data = json_decode((string) $row->data, true);
+    // pgsql jsonb はキー順を保存しないため順序非依存で比較する
+    expect($data)->toEqual([
+        'project_id' => 1,
+        'manual_id' => 2,
+        'manual_title' => 'ネジ締め手順',
+        'organization_name' => 'テスト組織',
+        'succeeded' => true,
+        'error' => null,
+    ]);
+    expect($row->read_at)->toBeNull();
+});
+
+test('v1 の全通知種別で organization_id が非 null で書き込まれる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
+    $owner->notify(new ManualRenderedNotification($organization->id, manualJobPayloadFixture(succeeded: false)));
+    $owner->notify(new InvitationReceivedNotification($organization->id, new InvitationReceivedPayload('テスト組織')));
+    $owner->notify(new TicketBalanceLowNotification($organization->id, new TicketBalanceLowPayload('テスト組織', 3, 5)));
+
+    expect(DB::table('notifications')->count())->toBe(4);
+    expect(DB::table('notifications')->whereNull('organization_id')->count())->toBe(0);
+    expect(DB::table('notifications')->pluck('type')->sort()->values()->all())->toBe([
+        'invitation_received',
+        'manual_analyzed',
+        'manual_rendered',
+        'ticket_balance_low',
+    ]);
+});
+
+test('未読 count は自分宛の未読のみを数える (既読化で減る)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $other = attachOrganizationMember($organization);
+
+    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
+    $owner->notify(new ManualRenderedNotification($organization->id, manualJobPayloadFixture()));
+    $other->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
+
+    expect($owner->unreadNotifications()->count())->toBe(2);
+    expect($other->unreadNotifications()->count())->toBe(1);
+
+    $first = $owner->notifications()->firstOrFail();
+    $first->markAsRead();
+    expect($owner->unreadNotifications()->count())->toBe(1);
+});
+
+test('org 物理削除で通知は cascade 削除される (organization_id FK)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $owner->notify(new ManualAnalyzedNotification($organization->id, manualJobPayloadFixture()));
+    expect(DB::table('notifications')->count())->toBe(1);
+
+    // Organization は SoftDeletes のため FK cascade は物理削除 (forceDelete) で発火する
+    $organization->forceDelete();
+
+    expect(DB::table('notifications')->count())->toBe(0);
+});
+
+test('ManualJobPayload::tryFromArray は不正形状で null を返す', function (): void {
+    $valid = manualJobPayloadFixture()->toArray();
+    expect(ManualJobPayload::tryFromArray($valid))->not->toBeNull();
+
+    expect(ManualJobPayload::tryFromArray([]))->toBeNull();
+    expect(ManualJobPayload::tryFromArray([...$valid, 'project_id' => '1']))->toBeNull();
+    expect(ManualJobPayload::tryFromArray([...$valid, 'succeeded' => 'yes']))->toBeNull();
+    expect(ManualJobPayload::tryFromArray([...$valid, 'error' => 123]))->toBeNull();
+});
+
+test('InvitationReceivedPayload / TicketBalanceLowPayload の tryFromArray も不正形状で null', function (): void {
+    expect(InvitationReceivedPayload::tryFromArray(['organization_name' => 'X']))->not->toBeNull();
+    expect(InvitationReceivedPayload::tryFromArray(['organization_name' => 1]))->toBeNull();
+    expect(InvitationReceivedPayload::tryFromArray([]))->toBeNull();
+
+    expect(TicketBalanceLowPayload::tryFromArray([
+        'organization_name' => 'X', 'balance' => 3, 'threshold' => 5,
+    ]))->not->toBeNull();
+    expect(TicketBalanceLowPayload::tryFromArray([
+        'organization_name' => 'X', 'balance' => '3', 'threshold' => 5,
+    ]))->toBeNull();
+    expect(TicketBalanceLowPayload::tryFromArray([]))->toBeNull();
+});
diff --git a/tests/Feature/Notifications/NotificationSharedPropsTest.php b/tests/Feature/Notifications/NotificationSharedPropsTest.php
new file mode 100644
index 0000000..e86a59b
--- /dev/null
+++ b/tests/Feature/Notifications/NotificationSharedPropsTest.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Notification\ManualJobPayload;
+use App\Notifications\InApp\ManualAnalyzedNotification;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * shared props notifications.unreadCount (施策6):
+ * - ログイン時: 全 org 横断の未読数が全 Inertia 応答へ共有される
+ * - 未ログイン画面: 0 で共有される (欠落しない)
+ */
+
+test('ログイン時: unreadCount が共有される (既読分は数えない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $payload = new ManualJobPayload(1, 2, 'M', 'Org', true, null);
+    $owner->notify(new ManualAnalyzedNotification($organization->id, $payload));
+    $owner->notify(new ManualAnalyzedNotification($organization->id, $payload));
+    $owner->notifications()->firstOrFail()->markAsRead();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('notifications.unreadCount', 1));
+});
+
+test('未ログイン画面でも unreadCount は 0 で共有される (欠落しない)', function (): void {
+    $this->get('/')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('notifications.unreadCount', 0));
+});
diff --git a/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php b/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
new file mode 100644
index 0000000..9bc2016
--- /dev/null
+++ b/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
@@ -0,0 +1,130 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * チケット残高低下通知 (施策5)。クロス判定は reserve に置く:
+ * 通知が示す残高は Reserved 拘束を含む「実効残高」(= balance())。
+ * - 実効残高が閾値を跨ぐ reserve → owner/admin に各 1 件、member には作られない
+ * - 既に閾値未満でさらに reserve → 通知されない (クロスのみ)
+ * - 複数 pending 予約: 跨いだ 2 件目の reserve でのみ発火。その後の commit (順序不問) は追加なし
+ * - release で閾値以上へ回復 → 再度跨ぐ reserve で再通知される
+ * - rollback される外側 tx 内の reserve → 通知されない (afterCommit)
+ */
+
+beforeEach(function (): void {
+    config()->set('billing.ticket_low_balance_threshold', 5);
+});
+
+/**
+ * owner + admin + member の組織 (台帳 $tickets 枚)。
+ *
+ * @return array{Organization, User, User, User}
+ */
+function balanceLowContext(int $tickets = 10): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    if ($tickets > 0) {
+        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
+    }
+
+    return [$organization, $owner, $admin, $member];
+}
+
+function lowBalanceNotificationCountFor(User $user): int
+{
+    return DB::table('notifications')
+        ->where('notifiable_id', $user->id)
+        ->where('type', 'ticket_balance_low')
+        ->count();
+}
+
+test('実効残高が閾値を跨ぐ reserve → owner/admin に各 1 件・member には作られない (payload は実効残高)', function (): void {
+    [$organization, $owner, $admin, $member] = balanceLowContext(tickets: 10);
+
+    app(TicketLedgerService::class)->reserve($organization, 6); // 実効残高 10 → 4 (閾値 5 を跨ぐ)
+
+    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
+    expect(lowBalanceNotificationCountFor($admin))->toBe(1);
+    expect(lowBalanceNotificationCountFor($member))->toBe(0);
+
+    $row = DB::table('notifications')->where('notifiable_id', $owner->id)->firstOrFail();
+    expect((int) $row->organization_id)->toBe($organization->id);
+    $data = json_decode((string) $row->data, true);
+    expect($data['balance'])->toBe(4);      // Reserved 拘束を含む実効残高
+    expect($data['threshold'])->toBe(5);
+});
+
+test('既に閾値未満の状態でさらに reserve → 通知されない (クロスのみ)', function (): void {
+    [$organization, $owner] = balanceLowContext(tickets: 10);
+
+    app(TicketLedgerService::class)->reserve($organization, 6); // 跨ぐ → 1 件
+    app(TicketLedgerService::class)->reserve($organization, 2); // 4 → 2 (跨がない)
+
+    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
+});
+
+test('複数 pending 予約: 跨いだ 2 件目の reserve でのみ発火。commit は順序を入れ替えても追加なし', function (): void {
+    [$organization, $owner] = balanceLowContext(tickets: 10);
+    $ledger = app(TicketLedgerService::class);
+
+    $first = $ledger->reserve($organization, 4);  // 10 → 6 (跨がない)
+    expect(lowBalanceNotificationCountFor($owner))->toBe(0);
+    $second = $ledger->reserve($organization, 4); // 6 → 2 (跨ぐ)
+    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
+
+    // commit は拘束と台帳が相殺し balance() 不変 → クロスを発生させない (順序を入れ替えて確認)
+    $ledger->commit($second);
+    $ledger->commit($first);
+    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
+    expect($ledger->balance($organization))->toBe(2);
+});
+
+test('release で閾値以上へ回復 → 再度跨ぐ reserve で再通知される', function (): void {
+    [$organization, $owner] = balanceLowContext(tickets: 10);
+    $ledger = app(TicketLedgerService::class);
+
+    $ledger->reserve($organization, 4);
+    $crossing = $ledger->reserve($organization, 4); // 6 → 2 (跨ぐ) → 1 件
+    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
+
+    $ledger->release($crossing); // 実効残高 2 → 6 (回復)
+    $ledger->reserve($organization, 4); // 6 → 2 (再度跨ぐ) → 再通知
+    expect(lowBalanceNotificationCountFor($owner))->toBe(2);
+});
+
+test('rollback される外側 tx 内の reserve は通知されない (afterCommit)', function (): void {
+    [$organization, $owner] = balanceLowContext(tickets: 10);
+
+    try {
+        DB::transaction(function () use ($organization): void {
+            app(TicketLedgerService::class)->reserve($organization, 6); // savepoint 内で跨ぐ
+            throw new RuntimeException('外側 tx を rollback させる');
+        });
+    } catch (RuntimeException) {
+        // 想定どおり
+    }
+
+    expect(lowBalanceNotificationCountFor($owner))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(10); // reserve ごと巻き戻る
+});
+
+test('grant で回復して再度跨ぐ場合も再通知される', function (): void {
+    [$organization, $owner] = balanceLowContext(tickets: 6);
+    $ledger = app(TicketLedgerService::class);
+
+    $ledger->reserve($organization, 2); // 6 → 4 (跨ぐ) → 1 件
+    expect(lowBalanceNotificationCountFor($owner))->toBe(1);
+
+    $ledger->grant($organization, 5, '追加購入'); // 実効残高 4 → 9 (回復)
+    $ledger->reserve($organization, 5); // 9 → 4 (再度跨ぐ)
+    expect(lowBalanceNotificationCountFor($owner))->toBe(2);
+});
diff --git a/tests/Support/TsUnionValues.php b/tests/Support/TsUnionValues.php
new file mode 100644
index 0000000..adbcbc2
--- /dev/null
+++ b/tests/Support/TsUnionValues.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use BackedEnum;
+use RuntimeException;
+
+/**
+ * PHP enum ⇔ TS literal union の値集合同期 invariant 用の抽出ヘルパ。
+ * ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest が共有する
+ * (T008 で ManualEnumTsSyncInvariantTest 内のローカル関数から昇格)。
+ */
+final class TsUnionValues
+{
+    /**
+     * TS ファイルから `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
+     * 抽出不能 (degenerate PASS) は fail させる (RuntimeException)。
+     *
+     * @param  string  $relativePath  base_path からの相対パス (例: resources/js/types/manual.ts)
+     * @return list<string>
+     */
+    public static function extract(string $relativePath, string $typeName): array
+    {
+        $path = base_path($relativePath);
+        $contents = file_get_contents($path);
+        if ($contents === false) {
+            throw new RuntimeException("TS ファイルを読めません: {$path}");
+        }
+
+        // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
+        $matched = preg_match(
+            '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
+            $contents,
+            $matches,
+        );
+        if ($matched !== 1) {
+            throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
+        }
+
+        $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
+        if ($literalCount === false || $literalCount === 0) {
+            throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
+        }
+
+        $values = $literals[1];
+        sort($values);
+
+        return $values;
+    }
+
+    /**
+     * @param  list<BackedEnum>  $cases
+     * @return list<string>
+     */
+    public static function enumStringValues(array $cases): array
+    {
+        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
+        sort($values);
+
+        return $values;
+    }
+}
diff --git a/tests/js/components/features/NotificationListItem.test.ts b/tests/js/components/features/NotificationListItem.test.ts
new file mode 100644
index 0000000..610e7f5
--- /dev/null
+++ b/tests/js/components/features/NotificationListItem.test.ts
@@ -0,0 +1,141 @@
+import { beforeEach, describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen } from "@testing-library/svelte";
+import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
+import type { NotificationItem } from "@/types/notification";
+
+// 行クリックの POST (open) は router をモックして検証する
+const { routerPostMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: {
+        post: routerPostMock,
+    },
+}));
+
+function manualAnalyzedItem(overrides: Partial<NotificationItem> = {}): NotificationItem {
+    return {
+        id: "11111111-1111-1111-1111-111111111111",
+        type: "manual_analyzed",
+        organization_id: 1,
+        read_at: null,
+        created_at: new Date().toISOString(),
+        payload: {
+            project_id: 1,
+            manual_id: 2,
+            manual_title: "ネジ締め手順",
+            organization_name: "テスト組織",
+            succeeded: true,
+            error: null,
+        },
+        ...overrides,
+    };
+}
+
+beforeEach(() => {
+    routerPostMock.mockReset();
+});
+
+describe("NotificationListItem", () => {
+    it("manual_analyzed 成功: 完了文言 + manual タイトル + org バッジ", () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        expect(screen.getByText("AI 解析が完了しました")).toBeInTheDocument();
+        expect(screen.getByText("ネジ締め手順")).toBeInTheDocument();
+        expect(screen.getByText("テスト組織")).toBeInTheDocument();
+    });
+
+    it("manual_analyzed 失敗: 失敗文言 + error 本文", () => {
+        const item = manualAnalyzedItem();
+        render(NotificationListItem, {
+            props: {
+                notification: {
+                    ...item,
+                    payload: {
+                        ...(item.payload as object),
+                        succeeded: false,
+                        error: "解析に失敗しました。",
+                    } as NotificationItem["payload"],
+                },
+            },
+        });
+
+        expect(screen.getByText("AI 解析に失敗しました")).toBeInTheDocument();
+        expect(screen.getByText(/解析に失敗しました。/)).toBeInTheDocument();
+    });
+
+    it("未読はハイライト (data-unread=true + 未読ドット)、既読はドットなし", () => {
+        const { unmount } = render(NotificationListItem, {
+            props: { notification: manualAnalyzedItem() },
+        });
+        expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "true");
+        expect(screen.getByTestId("unread-dot")).toBeInTheDocument();
+        unmount();
+
+        render(NotificationListItem, {
+            props: {
+                notification: manualAnalyzedItem({ read_at: new Date().toISOString() }),
+            },
+        });
+        expect(screen.getByTestId("notification-item")).toHaveAttribute("data-unread", "false");
+        expect(screen.queryByTestId("unread-dot")).toBeNull();
+    });
+
+    it("行クリックで POST /notifications/{id}/open (サーバ解決の遷移)", async () => {
+        render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
+
+        await fireEvent.click(screen.getByTestId("notification-item"));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        expect(routerPostMock.mock.calls[0][0]).toBe(
+            "/notifications/11111111-1111-1111-1111-111111111111/open",
+        );
+    });
+
+    it("未知 type は rawType 表示の fallback で描画され、クリックも可能 (disabled にしない)", async () => {
+        render(NotificationListItem, {
+            props: {
+                notification: manualAnalyzedItem({
+                    type: "future_unknown_type",
+                    payload: null,
+                }),
+            },
+        });
+
+        expect(screen.getByText("future_unknown_type")).toBeInTheDocument();
+        const row = screen.getByTestId("notification-item");
+        expect(row).not.toHaveAttribute("disabled");
+        await fireEvent.click(row);
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("ticket_balance_low: 残高と閾値を表示", () => {
+        render(NotificationListItem, {
+            props: {
+                notification: manualAnalyzedItem({
+                    type: "ticket_balance_low",
+                    payload: { organization_name: "テスト組織", balance: 3, threshold: 5 },
+                }),
+            },
+        });
+
+        expect(screen.getByText("チケット残高が残り 3 枚になりました")).toBeInTheDocument();
+        expect(screen.getByText(/5 枚/)).toBeInTheDocument();
+    });
+
+    it("invitation_received: 招待文言とメール案内", () => {
+        render(NotificationListItem, {
+            props: {
+                notification: manualAnalyzedItem({
+                    type: "invitation_received",
+                    payload: { organization_name: "招待元組織" },
+                }),
+            },
+        });
+
+        expect(screen.getByText("招待元組織 に招待されています")).toBeInTheDocument();
+        expect(screen.getByText("メールの受諾リンクから参加してください")).toBeInTheDocument();
+    });
+});
diff --git a/tests/js/components/molecules/NotificationBell.test.ts b/tests/js/components/molecules/NotificationBell.test.ts
new file mode 100644
index 0000000..49e6561
--- /dev/null
+++ b/tests/js/components/molecules/NotificationBell.test.ts
@@ -0,0 +1,40 @@
+import { describe, expect, it } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import NotificationBell from "@/components/molecules/NotificationBell.svelte";
+
+describe("NotificationBell", () => {
+    it("/notifications への link (a 要素) を描画する", () => {
+        render(NotificationBell, { props: { unreadCount: 0 } });
+
+        const link = screen.getByTestId("notification-bell");
+        expect(link.tagName).toBe("A");
+        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
+            "/notifications",
+        );
+        expect(link).toHaveAccessibleName("通知");
+    });
+
+    it("unreadCount=0 でバッジ非表示", () => {
+        render(NotificationBell, { props: { unreadCount: 0 } });
+
+        expect(screen.queryByTestId("unread-badge")).toBeNull();
+    });
+
+    it("unreadCount=5 でバッジに 5 を表示", () => {
+        render(NotificationBell, { props: { unreadCount: 5 } });
+
+        expect(screen.getByTestId("unread-badge")).toHaveTextContent("5");
+    });
+
+    it("unreadCount=100 で 99+ に打ち切る", () => {
+        render(NotificationBell, { props: { unreadCount: 100 } });
+
+        expect(screen.getByTestId("unread-badge")).toHaveTextContent("99+");
+    });
+
+    it("disabled 属性を一切持たない (必須未充足 disabled UI 禁止)", () => {
+        render(NotificationBell, { props: { unreadCount: 0 } });
+
+        expect(screen.getByTestId("notification-bell")).not.toHaveAttribute("disabled");
+    });
+});
diff --git a/tests/js/pages/NotificationsIndex.test.ts b/tests/js/pages/NotificationsIndex.test.ts
new file mode 100644
index 0000000..861863f
--- /dev/null
+++ b/tests/js/pages/NotificationsIndex.test.ts
@@ -0,0 +1,72 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import NotificationsIndex from "@/pages/Notifications/Index.svelte";
+import type { NotificationItem } from "@/types/notification";
+
+// router をモックし page state は実物を使う (props 未設定の空オブジェクト)
+const { routerMock } = vi.hoisted(() => ({
+    routerMock: { post: vi.fn(), get: vi.fn() },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: routerMock,
+}));
+
+const meta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
+
+function item(id: string): NotificationItem {
+    return {
+        id,
+        type: "manual_analyzed",
+        organization_id: 1,
+        read_at: null,
+        created_at: new Date().toISOString(),
+        payload: {
+            project_id: 1,
+            manual_id: 2,
+            manual_title: "手順書",
+            organization_name: "組織",
+            succeeded: true,
+            error: null,
+        },
+    };
+}
+
+afterEach(() => {
+    cleanup();
+    routerMock.post.mockReset();
+    routerMock.get.mockReset();
+});
+
+describe("Notifications/Index", () => {
+    it("0 件時は EmptyState を表示する", () => {
+        render(NotificationsIndex, { props: { notifications: [], meta } });
+
+        expect(screen.getByTestId("notifications-empty")).toBeInTheDocument();
+        expect(screen.queryByTestId("notification-list")).toBeNull();
+    });
+
+    it("read-all ボタンは disabled でなく、押下で POST /notifications/read-all", async () => {
+        render(NotificationsIndex, { props: { notifications: [], meta } });
+
+        const button = screen.getByTestId("read-all-button");
+        expect(button).not.toHaveAttribute("disabled");
+        await fireEvent.click(button);
+
+        expect(routerMock.post).toHaveBeenCalledTimes(1);
+        expect(routerMock.post.mock.calls[0][0]).toBe("/notifications/read-all");
+    });
+
+    it("通知がある場合は一覧を描画する", () => {
+        render(NotificationsIndex, {
+            props: {
+                notifications: [item("a"), item("b")],
+                meta: { ...meta, total: 2 },
+            },
+        });
+
+        expect(screen.getByTestId("notification-list")).toBeInTheDocument();
+        expect(screen.getAllByTestId("notification-item")).toHaveLength(2);
+    });
+});
```
