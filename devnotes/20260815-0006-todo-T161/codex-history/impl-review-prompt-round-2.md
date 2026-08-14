# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の指摘は全件受け入れました（反論なし）。うち 2 件は実バグでした。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

全件受け入れ（反論なし）。うち 2 件は実バグだった。

## [Warning] `away-navigation-failed` の順序が固定されていない (bfcache-trial.ts)
- 判断: **対応する**
- 根拠: 詳細設計の「実装時の確認事項」に
  `trial-started < away-navigation-started < away-navigation-failed` の順序固定を
  挙げておきながら、実装は `.some(type === "away-navigation-failed")` で済ませていた。
  「離脱を試していないのに離脱失敗が記録されている」列を根拠にしてしまう。
- 対応内容: `hasOrderedAwayFailure()` を追加し、
  `trial-started` → `away-navigation-started` → `away-navigation-failed` の
  sequence 順が成立する場合のみ `invalid-wrong-route` を導出するようにした。
  テストに #9-b（failed が away より前）/ #9-c（away 無しの failed）を追加。

## [Warning] 軸2終端後の `guard-state-changed` で `failed-transition` に崩れる (bfcache-trial.ts)
- 判断: **対応する（実バグ）**
- 根拠: `states.length > 3 → failed-transition` としていたため、
  再ログイン後に A を開き直した fresh load の guard 遷移が積まれると
  終端済みの判定が崩れる。**失効セッション経路では手順上ほぼ必ず起きる**。
  設計の「軸 2 終端後に fresh load のイベントが追記されても崩れない」に違反していた。
  既存テストは終端後に `page-show` しか足しておらず、この経路を踏んでいなかった。
- 対応内容: 軸2の走査を**最初の終端で閉じる**形に変更した
  （guard 状態を 3 つ集めた時点、または秘匿維持のまま `page-hide` した時点で打ち切る）。
  テストに #9-b / #9-c / #9-d / #14 を追加し、
  `authenticated-unhidden` / `hidden-then-left` / `retry-hidden` の各終端後に
  guard イベントが積まれても崩れないことを固定した。

## [Warning] `value in ALLOWED_KEYS` が prototype 由来キーで例外化しうる (bfcache-trial.ts)
- 判断: **対応する（実バグ）**
- 根拠: `"toString" in ALLOWED_KEYS` は真になり、`ALLOWED_KEYS["toString"]` が
  関数になるため後段の spread が例外を投げる。validator は
  「壊れた入力を安全に弾く」のが仕事なので、例外化は契約違反。
- 対応内容: `Object.hasOwn(ALLOWED_KEYS, value)` に変更。
  `toString` / `constructor` / `hasOwnProperty` を type に入れても
  例外化せず `null` を返すテストを追加した。

## [Warning] `hasStoredPayload()` が sessionStorage.length を見ている (BfcacheTrial.svelte)
- 判断: **対応する**
- 根拠: Inertia など別キーがあるだけで「証跡が壊れていた」と誤表示する。
- 対応内容: `getItem(TRIAL_STORAGE_KEY) !== null` に変更。

## [Warning] 離脱リンクで保存失敗しても遷移が進む (BfcacheTrial.svelte)
- 判断: **対応する**
- 根拠: 証跡ツールとして正しくない。記録できないまま離脱すると
  証跡に穴が空いたまま A が bfcache に入る。
- 対応内容: `record()` を `boolean` 返却にし、`leaveToAway()` で失敗時に
  `preventDefault()` して遷移を止めるようにした。

## [Suggestion] `navigator.clipboard.writeText` の存在確認 (BfcacheTrial.svelte)
- 判断: **対応する**
- 対応内容: `typeof navigator.clipboard?.writeText !== "function"` を先に確認し、
  使えない環境では「画面を撮影してください」と案内するようにした。

## [Warning] 真理値表に対するテスト不足 (bfcache-trial.test.ts)
- 判断: **対応する**
- 対応内容: 上記に加えて `verifiedOsVersion` の負のコントロール
  （最大長超過 / 許可外文字）を追加した。テストは 75 → 84 件。
- 補足: Codex が「軸2 #14 `pending → null` が未固定」と指摘したが、
  これは既存の #7「verifying を経ずに null」として存在していた（番号のずれ）。
  紛らわしいので終端後ケースを #14 として追加し、番号の重複を解消した。

## [Warning] 「実ユーザー情報を渡さない」という表現が不正確 (route gate test / controller)
- 判断: **対応する**
- 根拠: 指摘が正しい。Inertia 共有 props の `auth.user` は**載る**し、
  載らなければ guard が作動せず検証が成立しない。テスト名が誤読を招いていた。
- 対応内容:
  - テスト名を「controller 固有 props を渡さない」に限定
  - **`auth.user` が載ることを正のコントロールとして固定するテストを追加**
    （guard の作動条件そのものなので、欠けたら検証ページが観測対象を失う）
  - controller の docblock も同様に表現を狭めた

---

## 確認してほしいこと

1. 軸2の走査を「最初の終端で閉じる」形に変えた実装が正しいか。
   とくに hidden-then-left の判定 (states.length === 2 の時点で page-hide を見る) が
   往路 hide を拾わないこと、終端後の guard イベントを無視できていることを確認してください。
2. hasOrderedAwayFailure() の順序判定に穴がないか。
3. 追加したテストで Round 1 の指摘が実際に固定されているか。
4. 残る指摘が無ければ APPROVED としてください。

## テスト結果 (修正後)

- PHPStan level 10: No errors
- Pest (対象テスト): 14 passed / 50 assertions
- vitest (対象テスト): 84 passed (Round 1 時点は 75)
- tsc --noEmit / eslint: green

---

## 修正差分 (git diff)

```diff
diff --git a/app/Http/Controllers/DebugBfcacheTrialController.php b/app/Http/Controllers/DebugBfcacheTrialController.php
new file mode 100644
index 0000000..ea782c0
--- /dev/null
+++ b/app/Http/Controllers/DebugBfcacheTrialController.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers;
+
+use Inertia\Inertia;
+use Inertia\Response;
+
+/**
+ * bfcache 実機受入確認 (T085) の検証ページ。LocalOnly + auth の背後でのみ動く。
+ *
+ * 目的: T085 の実機手順には**負のコントロールが無く**、「guard が働いた」と
+ * 「そもそも bfcache 復元が起きなかった」を目視で区別できない。どちらも
+ * 「PII が出ない」に見えるため空振りを合格として記録しうる。本ページは
+ * pageshow.persisted・JS 実行コンテキスト生存トークン・guard の状態遷移を
+ * 観測して、その区別を機械化する。
+ *
+ * 観測値はすべてクライアント側で生成されるため **controller 固有の props を渡さない**。
+ * (HandleInertiaRequests の共有 props は別で、そちらは載る。とくに `auth.user` は
+ * bfcache guard の作動条件そのものなので、無いと検証が成立しない。)
+ * サーバの責務は「認証済みページとして描画されること」だけで、それにより
+ * web グループの NoStoreCacheHeadersForAuthenticatedPages が no-store を付け、
+ * app.ts が登録した**本物の** bfcache guard がそのまま作動する
+ * (検証対象を検証の都合で変えたら、確認しているものが production と別物になる)。
+ *
+ * ページが 2 枚あるのは、A から **full document navigation** で離脱しないと
+ * A が bfcache に入らないためである。Inertia visit は同一 Document のままなので
+ * pagehide が起きず、戻る操作は経路 C (Inertia の history 復元) になってしまう。
+ */
+class DebugBfcacheTrialController extends Controller
+{
+    /** 検証ページ A (観測・判定・証跡表示)。 */
+    public function trial(): Response
+    {
+        return Inertia::render('Debug/BfcacheTrial');
+    }
+
+    /** 相方ページ B。A を bfcache に入れるための full document navigation の着地点。 */
+    public function away(): Response
+    {
+        return Inertia::render('Debug/BfcacheTrialAway');
+    }
+}
diff --git a/resources/js/lib/debug/bfcache-trial.ts b/resources/js/lib/debug/bfcache-trial.ts
new file mode 100644
index 0000000..0bb0cac
--- /dev/null
+++ b/resources/js/lib/debug/bfcache-trial.ts
@@ -0,0 +1,750 @@
+/**
+ * bfcache 実機受入確認 (T085) の観測ライブラリ。
+ *
+ * 目的: T085 の実機手順には**負のコントロールが無く**、「guard が働いた」と
+ * 「そもそも bfcache 復元が起きなかった」を目視で区別できない。どちらも
+ * 「PII が出ない」に見えるため、空振りを合格として記録しうる。
+ * 同じ欠陥は Playwright レーンについては徹底的に潰されている
+ * (詳細設計 施策 8「空振りを green と偽らない」)。その規律を実機レーンへ揃える。
+ *
+ * 設計方針:
+ * - **検証対象 (bfcache-guard.ts / 秘匿 CSS / /session/status) には一切手を入れない**。
+ *   本ライブラリは guard を**外から観測するだけ**である
+ * - 判定は **二軸 + 総合**。「bfcache が成立したか」(軸 1) と
+ *   「guard が合格したか」(軸 2) は別の問いで、混ぜると受入失敗を PASS と読む
+ * - event log には**観測事実のみ**を保存し、verdict は保存しない
+ *   (後から `redirect-observed` が追記されるため、保存すると必ず stale になる)
+ * - **観測できないことを推論しない**。離脱先が /login だったか、離脱が
+ *   intercept されたかは A から観測できないため、利用者の手動記録に倒す
+ *
+ * 全体設計は devnotes/20260812-1931-bfcache-device-verification-page/detailed-design.md。
+ */
+
+/** schema 変更時に必ず上げる。復元時に不一致なら破棄する。 */
+export const TRIAL_SCHEMA_VERSION = 1;
+
+/** sessionStorage のキー。 */
+export const TRIAL_STORAGE_KEY = "bfcache-trial:v1";
+
+/** 検証シナリオ。利用者が試行開始時に宣言する。 */
+export type TrialScenario = "expired-session" | "active-session";
+
+/** guard の秘匿属性がとりうる値 (属性削除は null で表す)。 */
+export type GuardState = "pending" | "verifying" | "retry" | null;
+
+/** 利用者申告フィールドの制約 (自由記述の抜け道にしない)。 */
+export const DEVICE_MODEL_MAX_LENGTH = 40;
+export const VERIFIED_OS_VERSION_MAX_LENGTH = 20;
+export const STORAGE_FAILURE_REASON_MAX_LENGTH = 200;
+
+const DEVICE_MODEL_PATTERN = /^[A-Za-z0-9 \-,().]*$/;
+const VERIFIED_OS_VERSION_PATTERN = /^[A-Za-z0-9 .]*$/;
+
+/** 前後の空白を落とし、連続空白を 1 個に畳む。 */
+export function normalizeUserReported(value: string): string {
+    return value.trim().replace(/\s+/g, " ");
+}
+
+/**
+ * 利用者申告値を検証する。**許可外文字を除去して通さない**
+ * (除去すると利用者が意図しない値が証跡に残る)。拒否して入力し直させる。
+ */
+export function isValidDeviceModel(value: string): boolean {
+    return (
+        value.length <= DEVICE_MODEL_MAX_LENGTH &&
+        DEVICE_MODEL_PATTERN.test(value)
+    );
+}
+
+export function isValidVerifiedOsVersion(value: string): boolean {
+    return (
+        value.length <= VERIFIED_OS_VERSION_MAX_LENGTH &&
+        VERIFIED_OS_VERSION_PATTERN.test(value)
+    );
+}
+
+interface EventBase {
+    schemaVersion: number;
+    trialId: string;
+    sequence: number;
+    /** ISO 8601。 */
+    timestamp: string;
+}
+
+export interface TrialStartedEvent extends EventBase {
+    type: "trial-started";
+    scenario: TrialScenario;
+    contextToken: string;
+    userAgent: string;
+    uaReportedOs: string;
+    displayMode: string;
+    navigatorStandalone: boolean | null;
+    /** 利用者申告。長さ・文字種を制限する。 */
+    deviceModel: string;
+    /** 利用者申告。長さ・文字種を制限する。 */
+    verifiedOsVersion: string;
+}
+
+/**
+ * 離脱リンクが押された**操作事実**を同期記録する。
+ * `page-hide` の不在だけから離脱失敗を推論しない (正常な時間差と区別できないため)。
+ * 離脱失敗の判定は `AwayNavigationFailedEvent` (手動記録) のみが担う。
+ */
+export interface AwayNavigationStartedEvent extends EventBase {
+    type: "away-navigation-started";
+}
+
+/**
+ * 離脱が始まらなかったことを**利用者が明示的に記録する**手動イベント。
+ *
+ * タイマーで自動判定しない: 次タスク時点で `visibilityState !== "hidden"` でも
+ * その後に正常な full navigation が進みうる (誤検出) 一方、intercept 後に
+ * 別処理がページを hidden にすれば見逃す。どちらの向きにも外すので、
+ * 「観測できないことを推論しない」という本設計の原則に反する。
+ */
+export interface AwayNavigationFailedEvent extends EventBase {
+    type: "away-navigation-failed";
+    observationMethod: "manual";
+}
+
+export interface PageHideEvent extends EventBase {
+    type: "page-hide";
+    persisted: boolean;
+    guardState: GuardState;
+}
+
+export interface PageShowEvent extends EventBase {
+    type: "page-show";
+    persisted: boolean;
+    contextToken: string;
+    displayMode: string;
+}
+
+export interface GuardStateChangedEvent extends EventBase {
+    type: "guard-state-changed";
+    state: GuardState;
+}
+
+/**
+ * 利用者が /login 到達を確認して記録する手入力イベント。
+ *
+ * guard は `unauthenticated` のとき属性を `verifying` のまま
+ * `location.replace('/login')` を呼ぶため、A から観測できるのは
+ * 「秘匿を維持したまま離脱した」までである。離脱先は観測できない。
+ */
+export interface RedirectObservedEvent extends EventBase {
+    type: "redirect-observed";
+    observationMethod: "manual";
+}
+
+/**
+ * 保存できれば記録する診断イベント。**保存不能の永続証拠ではない**
+ * (storage が壊れていれば本イベント自身も残らない)。
+ */
+export interface StorageFailedEvent extends EventBase {
+    type: "storage-failed";
+    reason: string;
+}
+
+export interface TrialAbortedEvent extends EventBase {
+    type: "trial-aborted";
+}
+
+export type TrialEvent =
+    | TrialStartedEvent
+    | AwayNavigationStartedEvent
+    | AwayNavigationFailedEvent
+    | PageHideEvent
+    | PageShowEvent
+    | GuardStateChangedEvent
+    | RedirectObservedEvent
+    | StorageFailedEvent
+    | TrialAbortedEvent;
+
+export type TrialEventType = TrialEvent["type"];
+
+/** 軸 1: 試行が成立したか (bfcache 復元が本当に起きたか)。 */
+export type TrialVerdict =
+    | "valid-bfcache"
+    | "invalid-not-bfcache"
+    | "invalid-wrong-route"
+    | "inconsistent"
+    | "incomplete";
+
+/** 軸 2: guard がどう振る舞ったか。`in-progress` は正常遷移の途中 (終端していない)。 */
+export type GuardVerdict =
+    | "in-progress"
+    | "authenticated-unhidden"
+    | "unauthenticated-redirected"
+    | "hidden-then-left"
+    | "retry-hidden"
+    | "failed-transition"
+    | "not-observed";
+
+/** 軸 3: 総合。保存せず軸 1・軸 2 から導出する。 */
+export type OverallVerdict =
+    | "pass"
+    | "fail"
+    | "expectation-mismatch"
+    | "undetermined";
+
+/** 試行の進行状態。保存せず導出する (保存すると stale 化する)。 */
+export type TrialPhase =
+    | "invalid"
+    | "collecting-axis1"
+    | "collecting-axis2"
+    | "awaiting-manual-confirmation"
+    | "complete"
+    | "aborted";
+
+// ---------------------------------------------------------------------------
+// validator
+// ---------------------------------------------------------------------------
+
+/**
+ * 各 event type に許可されるキー。**ここに無いキーを 1 つでも持つイベントは拒否する**
+ * (余分キーの混入を黙って通さない)。
+ */
+const ALLOWED_KEYS: Record<TrialEventType, readonly string[]> = {
+    "trial-started": [
+        "scenario",
+        "contextToken",
+        "userAgent",
+        "uaReportedOs",
+        "displayMode",
+        "navigatorStandalone",
+        "deviceModel",
+        "verifiedOsVersion",
+    ],
+    "away-navigation-started": [],
+    "away-navigation-failed": ["observationMethod"],
+    "page-hide": ["persisted", "guardState"],
+    "page-show": ["persisted", "contextToken", "displayMode"],
+    "guard-state-changed": ["state"],
+    "redirect-observed": ["observationMethod"],
+    "storage-failed": ["reason"],
+    "trial-aborted": [],
+} as const;
+
+const BASE_KEYS: readonly string[] = [
+    "schemaVersion",
+    "trialId",
+    "sequence",
+    "timestamp",
+    "type",
+] as const;
+
+const GUARD_STATES: readonly GuardState[] = [
+    "pending",
+    "verifying",
+    "retry",
+    null,
+] as const;
+
+function isPlainObject(value: unknown): value is Record<string, unknown> {
+    return (
+        typeof value === "object" && value !== null && !Array.isArray(value)
+    );
+}
+
+function isNonEmptyString(value: unknown): value is string {
+    return typeof value === "string" && value.length > 0;
+}
+
+function isConstrainedString(
+    value: unknown,
+    maxLength: number,
+    pattern: RegExp,
+): value is string {
+    return (
+        typeof value === "string" &&
+        value.length <= maxLength &&
+        pattern.test(value)
+    );
+}
+
+function isEventType(value: unknown): value is TrialEventType {
+    // `in` は prototype 由来のキー ("toString" 等) にも真を返し、後段の
+    // ALLOWED_KEYS[value] が関数になって spread で例外化する。自身のキーだけを見る。
+    return typeof value === "string" && Object.hasOwn(ALLOWED_KEYS, value);
+}
+
+/**
+ * 1 イベントを厳密に検証する。shape が少しでも崩れていたら `null` を返す。
+ *
+ * `bfcache-guard.ts` の `readAuthenticatedFlag()` と同じ
+ * 「shape を厳密判定し、崩れていたら採用しない」idiom に揃えている。
+ */
+export function parseTrialEvent(value: unknown): TrialEvent | null {
+    if (!isPlainObject(value)) return null;
+    if (value.schemaVersion !== TRIAL_SCHEMA_VERSION) return null;
+    if (!isEventType(value.type)) return null;
+    if (!isNonEmptyString(value.trialId)) return null;
+    if (typeof value.sequence !== "number" || !Number.isInteger(value.sequence)) {
+        return null;
+    }
+    if (value.sequence < 0) return null;
+    if (!isNonEmptyString(value.timestamp)) return null;
+
+    const allowed = new Set<string>([...BASE_KEYS, ...ALLOWED_KEYS[value.type]]);
+    for (const key of Object.keys(value)) {
+        if (!allowed.has(key)) return null;
+    }
+    for (const key of ALLOWED_KEYS[value.type]) {
+        if (!(key in value)) return null;
+    }
+
+    if (!parsePayload(value.type, value)) return null;
+
+    return value as unknown as TrialEvent;
+}
+
+/** type 固有フィールドの型・制約を検証する。 */
+function parsePayload(
+    type: TrialEventType,
+    value: Record<string, unknown>,
+): boolean {
+    switch (type) {
+        case "trial-started":
+            return (
+                (value.scenario === "expired-session" ||
+                    value.scenario === "active-session") &&
+                isNonEmptyString(value.contextToken) &&
+                typeof value.userAgent === "string" &&
+                typeof value.uaReportedOs === "string" &&
+                typeof value.displayMode === "string" &&
+                (typeof value.navigatorStandalone === "boolean" ||
+                    value.navigatorStandalone === null) &&
+                isConstrainedString(
+                    value.deviceModel,
+                    DEVICE_MODEL_MAX_LENGTH,
+                    DEVICE_MODEL_PATTERN,
+                ) &&
+                isConstrainedString(
+                    value.verifiedOsVersion,
+                    VERIFIED_OS_VERSION_MAX_LENGTH,
+                    VERIFIED_OS_VERSION_PATTERN,
+                )
+            );
+        case "page-hide":
+            return (
+                typeof value.persisted === "boolean" &&
+                GUARD_STATES.includes(value.guardState as GuardState)
+            );
+        case "page-show":
+            return (
+                typeof value.persisted === "boolean" &&
+                isNonEmptyString(value.contextToken) &&
+                typeof value.displayMode === "string"
+            );
+        case "guard-state-changed":
+            return GUARD_STATES.includes(value.state as GuardState);
+        case "away-navigation-failed":
+        case "redirect-observed":
+            return value.observationMethod === "manual";
+        case "storage-failed":
+            return (
+                typeof value.reason === "string" &&
+                value.reason.length <= STORAGE_FAILURE_REASON_MAX_LENGTH
+            );
+        case "away-navigation-started":
+        case "trial-aborted":
+            return true;
+    }
+}
+
+/**
+ * 保存済みログ全体をパースする。
+ *
+ * **1 件でも壊れていたらログ全体を破棄する** (部分採用しない)。
+ * 欠落した証跡を完全な証跡と誤読させないため。
+ */
+export function parseTrialLog(raw: string | null): TrialEvent[] | null {
+    if (raw === null || raw === "") return null;
+
+    let decoded: unknown;
+    try {
+        decoded = JSON.parse(raw);
+    } catch {
+        return null;
+    }
+    if (!Array.isArray(decoded)) return null;
+
+    const events: TrialEvent[] = [];
+    for (const entry of decoded) {
+        const parsed = parseTrialEvent(entry);
+        if (parsed === null) return null;
+        events.push(parsed);
+    }
+    return events;
+}
+
+// ---------------------------------------------------------------------------
+// 採番 / 前提条件
+// ---------------------------------------------------------------------------
+
+/**
+ * 常に `max(sequence) + 1` を返す。sessionStorage から復元した進行中試行へ
+ * 追記する場合も採番が壊れない (欠番・重複があっても max を基準にする)。
+ * 空配列では 1 を返す (先頭イベントの sequence は 1)。
+ */
+export function nextSequence(events: TrialEvent[], trialId: string): number {
+    const target = events.filter((event) => event.trialId === trialId);
+    if (target.length === 0) return 1;
+    return Math.max(...target.map((event) => event.sequence)) + 1;
+}
+
+/** 3 導出関数の共通事前条件。イベントが 1 つの trialId だけに属するか。 */
+export function hasSingleTrialId(events: TrialEvent[]): boolean {
+    if (events.length === 0) return true;
+    const first = events[0].trialId;
+    return events.every((event) => event.trialId === first);
+}
+
+// ---------------------------------------------------------------------------
+// 軸 1: 試行成立判定
+// ---------------------------------------------------------------------------
+
+interface Axis1Window {
+    started: TrialStartedEvent;
+    away: AwayNavigationStartedEvent;
+    hide: PageHideEvent;
+    show: PageShowEvent;
+}
+
+function bySequence(events: TrialEvent[]): TrialEvent[] {
+    return [...events].sort((a, b) => a.sequence - b.sequence);
+}
+
+/**
+ * 軸 1 window を探す。
+ *
+ * 最初に成立した `trial-started < away-navigation-started < page-hide < page-show` を
+ * **軸 1 が参照する唯一の範囲**として確定させる。窓の外は軸 1 の判定に用いない
+ * (失効セッション経路では再ログイン後に必ず追加観測が発生するため、
+ * これが無いと判定が後から崩れる)。
+ */
+export function findAxis1Window(events: TrialEvent[]): Axis1Window | null {
+    const ordered = bySequence(events);
+
+    const started = ordered.find(
+        (event): event is TrialStartedEvent => event.type === "trial-started",
+    );
+    if (started === undefined) return null;
+
+    const away = ordered.find(
+        (event): event is AwayNavigationStartedEvent =>
+            event.type === "away-navigation-started" &&
+            event.sequence > started.sequence,
+    );
+    if (away === undefined) return null;
+
+    const hide = ordered.find(
+        (event): event is PageHideEvent =>
+            event.type === "page-hide" && event.sequence > away.sequence,
+    );
+    if (hide === undefined) return null;
+
+    const show = ordered.find(
+        (event): event is PageShowEvent =>
+            event.type === "page-show" && event.sequence > hide.sequence,
+    );
+    if (show === undefined) return null;
+
+    return { started, away, hide, show };
+}
+
+export function deriveTrialVerdict(events: TrialEvent[]): TrialVerdict {
+    if (!hasSingleTrialId(events)) return "inconsistent";
+
+    const window = findAxis1Window(events);
+    if (window !== null) {
+        const tokenMatches =
+            window.show.contextToken === window.started.contextToken;
+
+        if (window.hide.persisted && window.show.persisted && tokenMatches) {
+            return "valid-bfcache";
+        }
+        if (!window.show.persisted && !tokenMatches) {
+            return "invalid-not-bfcache";
+        }
+        return "inconsistent";
+    }
+
+    const hasHide = events.some((event) => event.type === "page-hide");
+    const hasShow = events.some((event) => event.type === "page-show");
+    // hide と show が揃っているのに窓を成せない = away 欠落 or 順序異常
+    if (hasHide && hasShow) return "inconsistent";
+
+    if (hasOrderedAwayFailure(events)) return "invalid-wrong-route";
+
+    return "incomplete";
+}
+
+/**
+ * 離脱失敗の手動記録を採用してよいか。
+ *
+ * 順序 `trial-started < away-navigation-started < away-navigation-failed` を要求する。
+ * 「離脱を試していないのに離脱失敗が記録されている」列を根拠にしない
+ * (単独の failed は状態として意味を成さない)。
+ */
+function hasOrderedAwayFailure(events: TrialEvent[]): boolean {
+    const ordered = bySequence(events);
+
+    const started = ordered.find((event) => event.type === "trial-started");
+    if (started === undefined) return false;
+
+    const away = ordered.find(
+        (event) =>
+            event.type === "away-navigation-started" &&
+            event.sequence > started.sequence,
+    );
+    if (away === undefined) return false;
+
+    return ordered.some(
+        (event) =>
+            event.type === "away-navigation-failed" &&
+            event.sequence > away.sequence,
+    );
+}
+
+// ---------------------------------------------------------------------------
+// 軸 2: guard 結果判定
+// ---------------------------------------------------------------------------
+
+/**
+ * 軸 2 は**軸 1 window の `page-show` より後**のイベントだけを見る。
+ * 往路 (A → B) の `page-hide` をリダイレクト離脱として拾ってはならない。
+ */
+export function deriveGuardVerdict(events: TrialEvent[]): GuardVerdict {
+    if (!hasSingleTrialId(events)) return "failed-transition";
+
+    const window = findAxis1Window(events);
+    const boundary = window?.show.sequence ?? Number.POSITIVE_INFINITY;
+    const after = bySequence(events).filter(
+        (event) => event.sequence > boundary,
+    );
+
+    // **最初の終端まででフィルタを閉じる**。終端後に fresh load の guard イベントが
+    // 追記されても判定が崩れないようにするため (失効セッション経路では再ログイン後に
+    // A を開き直すので、これが無いと確実に崩れる)。
+    const states: GuardState[] = [];
+    let hiddenThenLeft = false;
+
+    for (const event of after) {
+        if (event.type === "guard-state-changed") {
+            states.push(event.state);
+            if (states.length === 3) break; // 3 つ目で終端か異常かが決まる
+            continue;
+        }
+        if (
+            event.type === "page-hide" &&
+            states.length === 2 &&
+            states[0] === "pending" &&
+            states[1] === "verifying"
+        ) {
+            // 秘匿を維持したまま A から離脱した
+            hiddenThenLeft = true;
+            break;
+        }
+    }
+
+    const aborted = events.some((event) => event.type === "trial-aborted");
+
+    if (states.length === 0) return aborted ? "not-observed" : "in-progress";
+
+    // 正常遷移は pending → verifying → (null | retry)。prefix を異常扱いしない
+    if (states[0] !== "pending") return "failed-transition";
+    if (states.length === 1) return "in-progress";
+    if (states[1] !== "verifying") return "failed-transition";
+
+    if (states.length === 2) {
+        if (!hiddenThenLeft) return "in-progress";
+        return events.some((event) => event.type === "redirect-observed")
+            ? "unauthenticated-redirected"
+            : "hidden-then-left";
+    }
+
+    if (states[2] === null) return "authenticated-unhidden";
+    if (states[2] === "retry") return "retry-hidden";
+    return "failed-transition";
+}
+
+// ---------------------------------------------------------------------------
+// 軸 3: 総合判定 / 進行状態
+// ---------------------------------------------------------------------------
+
+/** シナリオごとに期待される guard 結果。 */
+export function expectedGuardVerdict(scenario: TrialScenario): GuardVerdict {
+    return scenario === "expired-session"
+        ? "unauthenticated-redirected"
+        : "authenticated-unhidden";
+}
+
+/**
+ * 総合判定。**軸 1 と軸 2 から導出するだけで、保存しない**。
+ *
+ * `in-progress` / `not-observed` / `hidden-then-left` を `undetermined` に落とすのが要点。
+ * - `in-progress`: 観測途中。ここを fail にすると復元直後の正常な状態が FAIL 表示になる
+ * - `not-observed`: guard が発火しなかったのか利用者が早く中止したのか**区別できない**
+ * - `hidden-then-left`: `redirect-observed` が入るまで終端していない
+ */
+export function deriveOverallVerdict(
+    scenario: TrialScenario,
+    trial: TrialVerdict,
+    guard: GuardVerdict,
+): OverallVerdict {
+    if (trial !== "valid-bfcache") return "undetermined";
+    if (
+        guard === "in-progress" ||
+        guard === "not-observed" ||
+        guard === "hidden-then-left"
+    ) {
+        return "undetermined";
+    }
+    if (guard === expectedGuardVerdict(scenario)) return "pass";
+    if (guard === "failed-transition") return "fail";
+    return "expectation-mismatch";
+}
+
+/**
+ * 試行の進行状態。listener の追記可否をこの結果で決める。
+ *
+ * `in-progress` が `collecting-axis2` に写ることが要点である。これが無いと
+ * 正常な `pending` / `pending → verifying` の途中で `complete` に落ちて
+ * 自動追記が止まり、`null` / `retry` / 復元後 `page-hide` を記録できなくなる。
+ */
+export function deriveTrialPhase(events: TrialEvent[]): TrialPhase {
+    if (!hasSingleTrialId(events)) return "invalid";
+    if (events.some((event) => event.type === "trial-aborted")) return "aborted";
+
+    const trial = deriveTrialVerdict(events);
+    if (trial === "incomplete") return "collecting-axis1";
+    if (trial !== "valid-bfcache") return "complete";
+
+    const guard = deriveGuardVerdict(events);
+    if (guard === "in-progress") return "collecting-axis2";
+    if (guard === "hidden-then-left") return "awaiting-manual-confirmation";
+    return "complete";
+}
+
+/** phase ごとに追記を許可するイベント種別。 */
+const ALLOWED_APPENDS: Record<TrialPhase, readonly TrialEventType[]> = {
+    invalid: [],
+    "collecting-axis1": [
+        "away-navigation-started",
+        "away-navigation-failed",
+        "page-hide",
+        "page-show",
+        "guard-state-changed",
+        "storage-failed",
+        "trial-aborted",
+    ],
+    "collecting-axis2": [
+        "page-hide",
+        "page-show",
+        "guard-state-changed",
+        "storage-failed",
+        "trial-aborted",
+    ],
+    "awaiting-manual-confirmation": ["redirect-observed", "trial-aborted"],
+    complete: [],
+    aborted: [],
+} as const;
+
+/**
+ * その phase でそのイベントを追記してよいか。
+ *
+ * `awaiting-manual-confirmation` で自動イベントを止めることが、
+ * **再ログイン後の fresh load による証跡汚染を防ぐ実装上の要**である。
+ */
+export function canAppend(phase: TrialPhase, type: TrialEventType): boolean {
+    return ALLOWED_APPENDS[phase].includes(type);
+}
+
+// ---------------------------------------------------------------------------
+// storage
+// ---------------------------------------------------------------------------
+
+function storage(): Storage | null {
+    try {
+        return globalThis.sessionStorage;
+    } catch {
+        return null;
+    }
+}
+
+/** 試行開始前の保存テスト。書けない環境では試行を始めさせない。 */
+export function probeStorageWritable(): boolean {
+    const store = storage();
+    if (store === null) return false;
+
+    const probeKey = `${TRIAL_STORAGE_KEY}:probe`;
+    try {
+        store.setItem(probeKey, "1");
+        const readBack = store.getItem(probeKey);
+        store.removeItem(probeKey);
+        return readBack === "1";
+    } catch {
+        return false;
+    }
+}
+
+/** 保存済みログを読む。壊れていたら `null` (呼び出し側が破棄を表示する)。 */
+export function readTrialLog(): TrialEvent[] | null {
+    const store = storage();
+    if (store === null) return null;
+    try {
+        return parseTrialLog(store.getItem(TRIAL_STORAGE_KEY));
+    } catch {
+        return null;
+    }
+}
+
+/**
+ * イベントを追記する。**例外を投げず、失敗を `false` で返す**
+ * (黙って成功扱いにしない)。
+ *
+ * 書き込み後に read-back validation を行い、追記したイベントが末尾に
+ * 同値で存在することまで確認する (JSON parse 成功だけでは破損を見逃す)。
+ */
+export function appendEvent(event: TrialEvent): boolean {
+    const store = storage();
+    if (store === null) return false;
+
+    try {
+        const existing = parseTrialLog(store.getItem(TRIAL_STORAGE_KEY)) ?? [];
+        const next = [...existing, event];
+        store.setItem(TRIAL_STORAGE_KEY, JSON.stringify(next));
+
+        const readBack = parseTrialLog(store.getItem(TRIAL_STORAGE_KEY));
+        if (readBack === null) return false;
+        if (readBack.length !== next.length) return false;
+
+        const tail = readBack[readBack.length - 1];
+        return JSON.stringify(tail) === JSON.stringify(event);
+    } catch {
+        return false;
+    }
+}
+
+/** 保存済みイベントを trialId ごとに分離して返す (混ぜて誤判定させない)。 */
+export function loadTrials(): Map<string, TrialEvent[]> {
+    return groupByTrialId(readTrialLog() ?? []);
+}
+
+/** イベント列を trialId ごとに分離する (純粋関数。テスト用に公開する)。 */
+export function groupByTrialId(
+    events: TrialEvent[],
+): Map<string, TrialEvent[]> {
+    const grouped = new Map<string, TrialEvent[]>();
+    for (const event of bySequence(events)) {
+        const bucket = grouped.get(event.trialId);
+        if (bucket === undefined) {
+            grouped.set(event.trialId, [event]);
+            continue;
+        }
+        bucket.push(event);
+    }
+    return grouped;
+}
diff --git a/resources/js/pages/Debug/BfcacheTrial.svelte b/resources/js/pages/Debug/BfcacheTrial.svelte
new file mode 100644
index 0000000..311136b
--- /dev/null
+++ b/resources/js/pages/Debug/BfcacheTrial.svelte
@@ -0,0 +1,708 @@
+<script lang="ts">
+    import { onMount } from "svelte";
+    import { page } from "@inertiajs/svelte";
+    import { ShieldQuestion } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import Select from "@/components/atoms/Select.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import { BFCACHE_HIDDEN_ATTRIBUTE } from "@/lib/bfcache-guard";
+    import {
+        DEVICE_MODEL_MAX_LENGTH,
+        TRIAL_SCHEMA_VERSION,
+        TRIAL_STORAGE_KEY,
+        VERIFIED_OS_VERSION_MAX_LENGTH,
+        appendEvent,
+        canAppend,
+        deriveGuardVerdict,
+        deriveOverallVerdict,
+        deriveTrialPhase,
+        deriveTrialVerdict,
+        expectedGuardVerdict,
+        isValidDeviceModel,
+        isValidVerifiedOsVersion,
+        loadTrials,
+        nextSequence,
+        normalizeUserReported,
+        probeStorageWritable,
+        readTrialLog,
+        type GuardState,
+        type TrialEvent,
+        type TrialEventType,
+        type TrialPhase,
+        type TrialScenario,
+    } from "@/lib/debug/bfcache-trial";
+    import type { SharedProps } from "@/lib/shared-props";
+
+    /**
+     * bfcache 実機受入確認 (T085) の検証ページ A。local / debug 限定。
+     *
+     * **なぜ要るのか**: T085 の実機手順は素の目視確認であり、「guard が働いた」と
+     * 「そもそも bfcache 復元が起きなかった」を区別できない。どちらも「PII が出ない」に
+     * 見えるため、空振りを合格として記録しうる。同じ欠陥は Playwright レーンについては
+     * 潰されている (「空振りを green と偽らない」)。その規律を実機レーンへ揃える。
+     *
+     * **観測するだけで、検証対象は一切変更しない**。guard は app.ts が登録した本物が
+     * そのまま動く。ここでは documentElement の秘匿属性を MutationObserver で見るだけ。
+     *
+     * 判定は二軸 + 総合 (詳細は lib/debug/bfcache-trial.ts):
+     *   軸 1 = bfcache 復元が本当に起きたか / 軸 2 = guard がどう振る舞ったか
+     * 混ぜると受入失敗を PASS と読むので分けてある。
+     *
+     * **軸 2 の unauthenticated-redirected だけは自動判定できない。** guard は
+     * location.replace('/login') を呼ぶだけで、A からは離脱先が観測できないため、
+     * 利用者の手動記録 (manual confirmation) を必須にしている。
+     */
+
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    /**
+     * JS 実行コンテキスト生存トークン。**onMount で 1 回だけ生成する**。
+     * bfcache 復元では component が再生成されないため onMount は再実行されず値が残る =
+     * 「Document が再実行されていない」ことの証拠になる。fresh load でのみ変わる。
+     * (module scope で評価すると SSR / テスト import 時に壊れる)
+     */
+    let contextToken = $state<string | null>(null);
+    let secureContextReady = $state(true);
+    let storageWritable = $state(true);
+    let logDiscarded = $state(false);
+
+    let events = $state<TrialEvent[]>([]);
+    let notice = $state<string | null>(null);
+
+    let scenario = $state<TrialScenario>("expired-session");
+    let deviceModel = $state("");
+    let verifiedOsVersion = $state("");
+
+    /** 進行中の試行 (phase が終端していないもの)。無ければ stored report モード。 */
+    const activeTrialId = $derived.by(() => {
+        let candidate: string | null = null;
+        let bestSequence = -1;
+        for (const [trialId, trialEvents] of groupEvents(events)) {
+            const phase = deriveTrialPhase(trialEvents);
+            if (phase === "complete" || phase === "aborted" || phase === "invalid") {
+                continue;
+            }
+            const first = Math.min(...trialEvents.map((event) => event.sequence));
+            if (first > bestSequence) {
+                bestSequence = first;
+                candidate = trialId;
+            }
+        }
+        return candidate;
+    });
+
+    const mode = $derived(activeTrialId === null ? "stored report" : "live observation");
+    const trials = $derived([...groupEvents(events)].reverse());
+
+    /**
+     * trialId ごとに分離する。**Map を返さない** — reactive な文脈で組み込み Map を
+     * 持つと svelte/prefer-svelte-reactivity に触れる。順序が要るだけなので tuple 配列で足りる。
+     */
+    function groupEvents(all: TrialEvent[]): Array<[string, TrialEvent[]]> {
+        const grouped: Array<[string, TrialEvent[]]> = [];
+        for (const event of [...all].sort((a, b) => a.sequence - b.sequence)) {
+            const bucket = grouped.find(([trialId]) => trialId === event.trialId);
+            if (bucket === undefined) {
+                grouped.push([event.trialId, [event]]);
+                continue;
+            }
+            bucket[1].push(event);
+        }
+        return grouped;
+    }
+
+    function refresh(): void {
+        const stored = readTrialLog();
+        logDiscarded = stored === null && hasStoredPayload();
+        events = [...loadTrials().values()].flat();
+    }
+
+    /**
+     * 証跡キーが存在するか。**sessionStorage.length を見ない**
+     * (Inertia など別キーがあるだけで「証跡が壊れていた」と誤表示してしまう)。
+     */
+    function hasStoredPayload(): boolean {
+        try {
+            return globalThis.sessionStorage.getItem(TRIAL_STORAGE_KEY) !== null;
+        } catch {
+            return false;
+        }
+    }
+
+    function displayMode(): string {
+        if (typeof globalThis.matchMedia !== "function") return "unknown";
+        for (const candidate of ["standalone", "fullscreen", "minimal-ui", "browser"]) {
+            if (globalThis.matchMedia(`(display-mode: ${candidate})`).matches) {
+                return candidate;
+            }
+        }
+        return "unknown";
+    }
+
+    /** iOS Safari の非標準 API。any に逃がさず型を切る。 */
+    interface NavigatorWithStandalone extends Navigator {
+        standalone?: boolean;
+    }
+
+    function navigatorStandalone(): boolean | null {
+        const value = (navigator as NavigatorWithStandalone).standalone;
+        return typeof value === "boolean" ? value : null;
+    }
+
+    /**
+     * UA から読み取れる OS。**確定した OS バージョンとして扱わない**
+     * (UA reduction / iPadOS の desktop-class UA / standalone と Safari の差がある)。
+     * 確定値は利用者申告の verifiedOsVersion 側が持つ。
+     */
+    function uaReportedOs(): string {
+        const match = navigator.userAgent.match(
+            /(iPhone OS|CPU OS|Mac OS X|Android)\s+([0-9_.]+)/,
+        );
+        return match === null ? "unknown" : `${match[1]} ${match[2].replace(/_/g, ".")}`;
+    }
+
+    /** 現在の試行に 1 イベント追記する。phase で許可されない場合は理由を表示する。 */
+    function record(
+        trialId: string,
+        build: (base: {
+            schemaVersion: number;
+            trialId: string;
+            sequence: number;
+            timestamp: string;
+        }) => TrialEvent,
+        type: TrialEventType,
+        options: { silent?: boolean } = {},
+    ): boolean {
+        const stored = readTrialLog() ?? [];
+        const trialEvents = stored.filter((event) => event.trialId === trialId);
+        const phase = deriveTrialPhase(trialEvents);
+
+        if (!canAppend(phase, type)) {
+            if (options.silent !== true) {
+                notice = `この試行では「${type}」を記録できません (状態: ${phaseLabel(phase)})。`;
+            }
+            return false;
+        }
+
+        const event = build({
+            schemaVersion: TRIAL_SCHEMA_VERSION,
+            trialId,
+            sequence: nextSequence(stored, trialId),
+            timestamp: new Date().toISOString(),
+        });
+
+        const saved = appendEvent(event);
+        if (!saved) {
+            notice = "証跡の保存に失敗しました。この試行は証跡を回収できません (unrecordable)。";
+        }
+        refresh();
+        return saved;
+    }
+
+    function startTrial(): void {
+        notice = null;
+
+        if (!secureContextReady) {
+            notice = "secure context が必要です。この環境では検証できません。";
+            return;
+        }
+
+        const model = normalizeUserReported(deviceModel);
+        const version = normalizeUserReported(verifiedOsVersion);
+
+        if (model === "" || !isValidDeviceModel(model)) {
+            notice = `端末モデルを英数字と - , ( ) . の範囲・${DEVICE_MODEL_MAX_LENGTH} 文字以内で入力してください。`;
+            return;
+        }
+        if (version === "" || !isValidVerifiedOsVersion(version)) {
+            notice = `OS バージョンを英数字と . の範囲・${VERIFIED_OS_VERSION_MAX_LENGTH} 文字以内で入力してください。`;
+            return;
+        }
+        if (activeTrialId !== null) {
+            notice = "進行中の試行があります。中止してから新しい試行を開始してください。";
+            return;
+        }
+        if (!probeStorageWritable()) {
+            storageWritable = false;
+            notice =
+                "sessionStorage に書き込めません (unrecordable)。この状態では試行を開始しません。";
+            return;
+        }
+
+        const token = contextToken;
+        if (token === null) return;
+
+        const stored = readTrialLog() ?? [];
+        const trialId = globalThis.crypto.randomUUID();
+        const event: TrialEvent = {
+            schemaVersion: TRIAL_SCHEMA_VERSION,
+            trialId,
+            sequence: nextSequence(stored, trialId),
+            timestamp: new Date().toISOString(),
+            type: "trial-started",
+            scenario,
+            contextToken: token,
+            userAgent: navigator.userAgent,
+            uaReportedOs: uaReportedOs(),
+            displayMode: displayMode(),
+            navigatorStandalone: navigatorStandalone(),
+            deviceModel: model,
+            verifiedOsVersion: version,
+        };
+
+        if (!appendEvent(event)) {
+            notice = "証跡の保存に失敗しました (unrecordable)。試行を開始しません。";
+            return;
+        }
+        refresh();
+    }
+
+    function leaveToAway(event: MouseEvent): void {
+        const trialId = activeTrialId;
+        if (trialId === null) {
+            event.preventDefault();
+            notice = "進行中の試行がありません。先に試行を開始してください。";
+            return;
+        }
+        // 操作事実のみを同期記録する。page-hide の不在から離脱失敗を推論しない
+        const saved = record(
+            trialId,
+            (base) => ({ ...base, type: "away-navigation-started" }),
+            "away-navigation-started",
+        );
+        // 記録できないまま離脱すると証跡に穴が空いたまま A が bfcache に入る。
+        // 証跡ツールとしては、そこで進ませない方が正しい
+        if (!saved) event.preventDefault();
+    }
+
+    function recordManual(type: "redirect-observed" | "away-navigation-failed"): void {
+        notice = null;
+        const trialId = activeTrialId;
+        if (trialId === null) {
+            notice = "進行中の試行がありません。";
+            return;
+        }
+        record(trialId, (base) => ({ ...base, type, observationMethod: "manual" }), type);
+    }
+
+    function abortTrial(): void {
+        notice = null;
+        const trialId = activeTrialId;
+        if (trialId === null) {
+            notice = "進行中の試行がありません。";
+            return;
+        }
+        record(trialId, (base) => ({ ...base, type: "trial-aborted" }), "trial-aborted");
+    }
+
+    function copyReport(): void {
+        notice = null;
+        // 未提供環境では同期例外になりうるため、呼ぶ前に存在を確かめる
+        if (typeof navigator.clipboard?.writeText !== "function") {
+            notice = "この環境ではクリップボードを使えません。画面の内容を撮影してください。";
+            return;
+        }
+        void navigator.clipboard
+            .writeText(reportText())
+            .then(() => {
+                notice = "証跡テキストをコピーしました。";
+            })
+            .catch(() => {
+                notice = "クリップボードにコピーできませんでした。";
+            });
+    }
+
+    function reportText(): string {
+        const lines: string[] = [`# bfcache 実機受入確認の証跡 (${mode})`, ""];
+        for (const [trialId, trialEvents] of trials) {
+            const started = trialEvents.find((event) => event.type === "trial-started");
+            if (started === undefined || started.type !== "trial-started") continue;
+            lines.push(`## trial ${trialId}`);
+            lines.push(`- シナリオ: ${scenarioLabel(started.scenario)}`);
+            lines.push(`- 自動観測 UA: ${started.userAgent}`);
+            lines.push(`- 自動観測 UA reported OS: ${started.uaReportedOs}`);
+            lines.push(`- 自動観測 display-mode: ${started.displayMode}`);
+            lines.push(`- 自動観測 navigator.standalone: ${started.navigatorStandalone}`);
+            lines.push(`- 利用者申告 端末モデル: ${started.deviceModel}`);
+            lines.push(`- 利用者申告 OS バージョン: ${started.verifiedOsVersion}`);
+            lines.push(`- 軸1 試行成立: ${deriveTrialVerdict(trialEvents)}`);
+            lines.push(`- 軸2 guard 結果: ${deriveGuardVerdict(trialEvents)}`);
+            lines.push(
+                `- 総合: ${deriveOverallVerdict(started.scenario, deriveTrialVerdict(trialEvents), deriveGuardVerdict(trialEvents))}`,
+            );
+            lines.push(`- 期待 guard 結果: ${expectedGuardVerdict(started.scenario)}`);
+            lines.push("- イベント:");
+            for (const event of trialEvents) {
+                lines.push(`  - [${event.sequence}] ${event.timestamp} ${event.type}`);
+            }
+            lines.push("");
+        }
+        return lines.join("\n");
+    }
+
+    function phaseLabel(phase: TrialPhase): string {
+        const labels: Record<TrialPhase, string> = {
+            invalid: "不正 (複数試行の混入)",
+            "collecting-axis1": "軸1 観測中",
+            "collecting-axis2": "軸2 観測中",
+            "awaiting-manual-confirmation": "手動確認待ち",
+            complete: "完了",
+            aborted: "中止",
+        };
+        return labels[phase];
+    }
+
+    function scenarioLabel(value: TrialScenario): string {
+        return value === "expired-session"
+            ? "失効セッション経路 (本試行)"
+            : "有効セッション経路 (正のコントロール)";
+    }
+
+    function guardStateOf(): GuardState {
+        const value = document.documentElement.getAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
+        if (value === "pending" || value === "verifying" || value === "retry") return value;
+        return null;
+    }
+
+    onMount(() => {
+        if (typeof globalThis.crypto?.randomUUID !== "function") {
+            secureContextReady = false;
+            return;
+        }
+        contextToken = globalThis.crypto.randomUUID();
+        storageWritable = probeStorageWritable();
+        refresh();
+
+        const onPageHide = (event: Event): void => {
+            const trialId = activeTrialId;
+            if (trialId === null) return;
+            record(
+                trialId,
+                (base) => ({
+                    ...base,
+                    type: "page-hide",
+                    persisted: (event as PageTransitionEvent).persisted,
+                    guardState: guardStateOf(),
+                }),
+                "page-hide",
+                { silent: true },
+            );
+        };
+
+        const onPageShow = (event: Event): void => {
+            const trialId = activeTrialId;
+            const token = contextToken;
+            if (trialId === null || token === null) return;
+            record(
+                trialId,
+                (base) => ({
+                    ...base,
+                    type: "page-show",
+                    persisted: (event as PageTransitionEvent).persisted,
+                    contextToken: token,
+                    displayMode: displayMode(),
+                }),
+                "page-show",
+                { silent: true },
+            );
+        };
+
+        // 秘匿属性の変化を外から観測する (guard には手を入れない)
+        const observer = new MutationObserver(() => {
+            const trialId = activeTrialId;
+            if (trialId === null) return;
+            record(
+                trialId,
+                (base) => ({ ...base, type: "guard-state-changed", state: guardStateOf() }),
+                "guard-state-changed",
+                { silent: true },
+            );
+        });
+        observer.observe(document.documentElement, {
+            attributes: true,
+            attributeFilter: [BFCACHE_HIDDEN_ATTRIBUTE],
+        });
+
+        // unload / beforeunload は使わない (bfcache の適格性を壊す。architecture テストが固定)
+        window.addEventListener("pagehide", onPageHide);
+        window.addEventListener("pageshow", onPageShow);
+
+        return () => {
+            observer.disconnect();
+            window.removeEventListener("pagehide", onPageHide);
+            window.removeEventListener("pageshow", onPageShow);
+        };
+    });
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title="bfcache 実機受入確認"
+            description="T085 の実機確認を空振りと区別するための観測ページ (local / debug 限定)"
+            icon={ShieldQuestion}
+        />
+        <PageContent>
+            {#if !secureContextReady}
+                <Alert variant="danger" testId="bfcache-trial-insecure">
+                    secure context が必要です。この環境では検証できません。HTTPS で開き直してください
+                    (平文 http で試すと本番と違う条件を見て「確認済み」と記録する事故になります)。
+                </Alert>
+            {:else}
+                <div class="space-y-6">
+                    <Alert variant="info" testId="bfcache-trial-mode">
+                        現在のモード: <strong>{mode}</strong>
+                        {#if activeTrialId !== null}
+                            / 進行中の試行: <code>{activeTrialId.slice(0, 8)}</code>
+                        {/if}
+                    </Alert>
+
+                    {#if !storageWritable}
+                        <Alert variant="danger" testId="bfcache-trial-unrecordable">
+                            sessionStorage に書き込めません (unrecordable)。証跡を回収できないため
+                            試行を開始しません。
+                        </Alert>
+                    {/if}
+
+                    {#if logDiscarded}
+                        <Alert variant="warning" testId="bfcache-trial-log-discarded">
+                            保存済み証跡の形式が壊れていたため破棄しました (部分採用はしません)。
+                        </Alert>
+                    {/if}
+
+                    {#if notice !== null}
+                        <Alert variant="warning" testId="bfcache-trial-notice">{notice}</Alert>
+                    {/if}
+
+                    <Card padding="lg">
+                        <h2 class="text-h2">新しい試行を開始する</h2>
+                        <p class="mt-2 text-caption text-text-secondary">
+                            端末モデルと OS バージョンは UA から確定できないため手入力します
+                            (UA reduction / iPadOS の desktop-class UA があるため)。
+                            <strong>氏名などの個人情報は入力しないでください。</strong>
+                        </p>
+
+                        <div class="mt-4 space-y-4">
+                            <FormField label="検証シナリオ" htmlFor="bfcache-trial-scenario">
+                                <Select id="bfcache-trial-scenario" bind:value={scenario}>
+                                    <option value="expired-session"
+                                        >失効セッション経路 (本試行)</option
+                                    >
+                                    <option value="active-session"
+                                        >有効セッション経路 (正のコントロール)</option
+                                    >
+                                </Select>
+                            </FormField>
+
+                            <FormField label="端末モデル (利用者申告)" htmlFor="bfcache-trial-device">
+                                <Input
+                                    id="bfcache-trial-device"
+                                    bind:value={deviceModel}
+                                    placeholder="iPhone 15 Pro"
+                                    testId="bfcache-trial-device"
+                                />
+                            </FormField>
+
+                            <FormField
+                                label="確認済み OS バージョン (利用者申告)"
+                                htmlFor="bfcache-trial-os"
+                            >
+                                <Input
+                                    id="bfcache-trial-os"
+                                    bind:value={verifiedOsVersion}
+                                    placeholder="18.2"
+                                    testId="bfcache-trial-os"
+                                />
+                            </FormField>
+
+                            <Button onclick={startTrial} testId="bfcache-trial-start">
+                                試行を開始する
+                            </Button>
+                        </div>
+                    </Card>
+
+                    <Card padding="lg">
+                        <h2 class="text-h2">操作</h2>
+                        <p class="mt-2 text-caption text-text-secondary">
+                            下のリンクは <strong>plain な a 要素</strong>です (Inertia の Link
+                            ではありません)。full document navigation でないと A が bfcache に入らないためです。
+                        </p>
+                        <p class="mt-2 text-caption text-text-secondary">
+                            戻るときは<strong>履歴から A を選んで復帰</strong>してください。
+                            相方ページでログアウトすると Inertia が履歴を積むため、戻る 1 回では A に戻りません。
+                        </p>
+
+                        <div class="mt-4 flex flex-wrap gap-3">
+                            <a
+                                href="/debug/bfcache-trial/away"
+                                class="text-body text-primary underline"
+                                data-testid="bfcache-trial-away-link"
+                                onclick={leaveToAway}
+                            >
+                                相方ページへ移動する (full reload)
+                            </a>
+                        </div>
+
+                        <div class="mt-4 flex flex-wrap gap-3">
+                            <Button
+                                variant="ghost"
+                                onclick={() => recordManual("redirect-observed")}
+                                testId="bfcache-trial-record-redirect"
+                            >
+                                /login 到達を記録する (手動確認)
+                            </Button>
+                            <Button
+                                variant="ghost"
+                                onclick={() => recordManual("away-navigation-failed")}
+                                testId="bfcache-trial-record-away-failed"
+                            >
+                                離脱失敗を記録する (手動確認)
+                            </Button>
+                            <Button
+                                variant="ghost"
+                                onclick={abortTrial}
+                                testId="bfcache-trial-abort"
+                            >
+                                試行を中止する
+                            </Button>
+                            <Button
+                                variant="neutral"
+                                onclick={copyReport}
+                                testId="bfcache-trial-copy"
+                            >
+                                証跡テキストをコピー
+                            </Button>
+                        </div>
+                    </Card>
+
+                    <!--
+                        オーバーレイが覆う対象。明らかに偽物と分かる固定文字列にしてある
+                        (証跡を devnotes に貼るため、本物めいた個人情報を写り込ませない)。
+                        この文字列自体は sessionStorage に保存しない (allowlist 外)。
+                    -->
+                    <Card padding="lg" testId="bfcache-trial-fake-pii">
+                        <h2 class="text-h2">ダミー PII (架空データ)</h2>
+                        <dl class="mt-3 space-y-1 text-body">
+                            <div><dt class="inline">氏名:</dt> <dd class="inline">架空 太郎</dd></div>
+                            <div>
+                                <dt class="inline">メール:</dt>
+                                <dd class="inline">example-not-real@invalid.test</dd>
+                            </div>
+                            <div><dt class="inline">電話:</dt> <dd class="inline">000-0000-0000</dd></div>
+                        </dl>
+                    </Card>
+
+                    {#each trials as [trialId, trialEvents] (trialId)}
+                        {@const started = trialEvents.find((e) => e.type === "trial-started")}
+                        {#if started !== undefined && started.type === "trial-started"}
+                            {@const trialVerdict = deriveTrialVerdict(trialEvents)}
+                            {@const guardVerdict = deriveGuardVerdict(trialEvents)}
+                            <Card padding="lg">
+                                <h2 class="text-h2">
+                                    trial <code>{trialId.slice(0, 8)}</code>
+                                    <span class="text-caption text-text-secondary">
+                                        ({trialId === activeTrialId
+                                            ? "live observation"
+                                            : "stored report"})
+                                    </span>
+                                </h2>
+
+                                <dl class="mt-3 grid gap-1 text-body">
+                                    <div>
+                                        <dt class="inline">シナリオ:</dt>
+                                        <dd class="inline">{scenarioLabel(started.scenario)}</dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">状態:</dt>
+                                        <dd class="inline">
+                                            {phaseLabel(deriveTrialPhase(trialEvents))}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">軸1 試行成立:</dt>
+                                        <dd class="inline" data-testid="bfcache-trial-verdict">
+                                            {trialVerdict}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">軸2 guard 結果:</dt>
+                                        <dd class="inline" data-testid="bfcache-guard-verdict">
+                                            {guardVerdict}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">総合:</dt>
+                                        <dd class="inline" data-testid="bfcache-overall-verdict">
+                                            {deriveOverallVerdict(
+                                                started.scenario,
+                                                trialVerdict,
+                                                guardVerdict,
+                                            )}
+                                        </dd>
+                                    </div>
+                                    <div>
+                                        <dt class="inline">期待 guard 結果:</dt>
+                                        <dd class="inline">
+                                            {expectedGuardVerdict(started.scenario)}
+                                        </dd>
+                                    </div>
+                                </dl>
+
+                                {#if guardVerdict === "unauthenticated-redirected"}
+                                    <p class="mt-3 text-caption text-text-secondary">
+                                        この判定は <strong>manual confirmation</strong> を含みます
+                                        (guard の離脱先は A から観測できないため、/login 到達は利用者の確認記録によります)。
+                                    </p>
+                                {/if}
+                                {#if guardVerdict === "hidden-then-left"}
+                                    <p class="mt-3 text-caption text-text-secondary">
+                                        秘匿を維持したまま A から離脱しました。<strong
+                                            >/login に着地したことを確認して記録</strong
+                                        >すると判定が確定します。
+                                    </p>
+                                {/if}
+
+                                <h3 class="mt-4 text-h3">自動観測</h3>
+                                <ul class="mt-1 text-caption text-text-secondary">
+                                    <li>UA: {started.userAgent}</li>
+                                    <li>UA reported OS: {started.uaReportedOs}</li>
+                                    <li>display-mode: {started.displayMode}</li>
+                                    <li>navigator.standalone: {String(started.navigatorStandalone)}</li>
+                                </ul>
+
+                                <h3 class="mt-4 text-h3">利用者申告</h3>
+                                <ul class="mt-1 text-caption text-text-secondary">
+                                    <li>端末モデル: {started.deviceModel}</li>
+                                    <li>確認済み OS バージョン: {started.verifiedOsVersion}</li>
+                                </ul>
+
+                                <h3 class="mt-4 text-h3">観測イベント</h3>
+                                <ol class="mt-1 space-y-1 text-caption text-text-secondary">
+                                    {#each trialEvents as event (event.sequence)}
+                                        <li>
+                                            [{event.sequence}] {event.timestamp} — {event.type}
+                                            {#if event.type === "page-hide" || event.type === "page-show"}
+                                                (persisted: {String(event.persisted)})
+                                            {/if}
+                                            {#if event.type === "guard-state-changed"}
+                                                (state: {String(event.state)})
+                                            {/if}
+                                        </li>
+                                    {/each}
+                                </ol>
+                            </Card>
+                        {/if}
+                    {/each}
+                </div>
+            {/if}
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/tests/Feature/DebugBfcacheTrialRouteGateTest.php b/tests/Feature/DebugBfcacheTrialRouteGateTest.php
new file mode 100644
index 0000000..ba508de
--- /dev/null
+++ b/tests/Feature/DebugBfcacheTrialRouteGateTest.php
@@ -0,0 +1,143 @@
+<?php
+
+declare(strict_types=1);
+
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * bfcache 検証ページ (/debug/bfcache-trial) の防御層と前提条件のテスト。
+ *
+ * 本ページ専用の env フラグは追加しない判断をしている (概念設計)。根拠は
+ * 既存の三層防御 (route 登録ゲート / LocalOnly の env 判定 + 資格情報未設定 404 /
+ * production での ProductionEnvGuard fail-fast) が既にあり、しかも本ページは
+ * 同一ゲート上の /debug/login より権限が低いためである。
+ * **その前提が構造的に維持されていることを、ここで実効条件として機械固定する。**
+ *
+ * とくに `Cache-Control: no-store` は正のコントロールである。これが付かなくなると
+ * 「Safari は no-store でも bfcache に格納する」という検証したい条件そのものが崩れ、
+ * **本番と違う条件を見て「確認済み」と記録する**事故になる。
+ *
+ * **middleware 実行順の実測 (実装時に判明)**: 本 route は `LocalOnly` グループの内側に
+ * `auth` を重ねているが、解決後の実行順は **`auth` が先**である。`Authenticate` は
+ * Laravel 既定の priority list に載っており、載っていない `LocalOnly` より前へソートされる
+ * (bootstrap/app.php の注記どおり、priority list は「載っている middleware 同士の相対順序」
+ * しか強制しない)。auth を持たない `/debug/login` とはここが非対称になる。
+ *
+ * 帰結として **guest は 404 ではなく /login へ 302 する**。この差は許容する:
+ *   - staging / production では route 登録ゲート自体が働き **route が存在しない**ため、
+ *     存在オラクルにならない
+ *   - local でのみ「登録済み route に guest が触れた」ことが 302 で分かるが、
+ *     これは開発者自身の環境であり、実際に到達しうる相手 (認証済みユーザー) に対しては
+ *     `LocalOnly` の env / 資格情報ゲートが正しく 404 / 401 を返す
+ *
+ * したがって本テストは **認証済みユーザーに対する LocalOnly の実効性**を主に固定し、
+ * guest に対しては 302 (= auth が効いていること) を負のコントロールとして固定する。
+ * `bootstrap/app.php` の priority list は TenantBoundaryOrderingTest が固定している
+ * load-bearing な宣言であり、debug ページのために順序を動かすことはしない。
+ */
+
+beforeEach(function (): void {
+    config(['app.env' => 'local']);
+    config(['debug.login.user' => 'testuser']);
+    config(['debug.login.password' => 'testpass123']);
+});
+
+/** @return array{string, string} */
+function bfcacheTrialBasicAuthHeaders(): array
+{
+    return [
+        'PHP_AUTH_USER' => 'testuser',
+        'PHP_AUTH_PW' => 'testpass123',
+    ];
+}
+
+dataset('bfcache trial routes', [
+    'trial (A)' => ['/debug/bfcache-trial', 'Debug/BfcacheTrial'],
+    'away (B)' => ['/debug/bfcache-trial/away', 'Debug/BfcacheTrialAway'],
+]);
+
+test('認証済みでも production 環境なら 404 (LocalOnly の env ゲート)', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+    config(['app.env' => 'production']);
+
+    $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path)
+        ->assertNotFound();
+})->with('bfcache trial routes');
+
+test('認証済みでも DEBUG_LOGIN_* 未設定なら 404 (fail-secure。明示的な env opt-in が必須)', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+    config(['debug.login.user' => '']);
+    config(['debug.login.password' => '']);
+
+    $this->actingAs($user)
+        ->get($path)
+        ->assertNotFound();
+})->with('bfcache trial routes');
+
+test('認証済みでも Basic 認証なしなら 401', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($user)->get($path);
+
+    $response->assertStatus(401);
+    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('Basic');
+})->with('bfcache trial routes');
+
+test('guest は /login へリダイレクト (auth が効いていることの負のコントロール)', function (string $path): void {
+    // auth が LocalOnly より先に走るため 404 ではなく 302 になる (docblock の実行順の項)
+    $this->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path)
+        ->assertRedirect('/login');
+})->with('bfcache trial routes');
+
+test('認証済み + Basic 認証で 200。Inertia component が取り違えられていない', function (string $path, string $component): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->component($component));
+})->with('bfcache trial routes');
+
+test('認証済み応答に Cache-Control: no-store が付く (検証条件の正のコントロール)', function (string $path): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get($path);
+
+    $response->assertOk();
+    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
+})->with('bfcache trial routes');
+
+test('controller 固有 props を渡さない (観測値はすべてクライアント側で生成する)', function (): void {
+    [, $user] = createOrganizationWithOwner();
+
+    $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get('/debug/bfcache-trial')
+        ->assertOk()
+        ->assertInertia(function (Assert $page): void {
+            // controller は Inertia::render にデータを渡していない。
+            // **共有 props (HandleInertiaRequests) は別の話**で、そちらは載る。
+            $page->component('Debug/BfcacheTrial')
+                ->missing('users')
+                ->missing('trial');
+        });
+});
+
+test('共有 props の auth.user は載る (guard の作動条件そのもの)', function (): void {
+    [, $user] = createOrganizationWithOwner();
+
+    // bfcache-guard は Inertia 共有 props の auth.user を見て
+    // 「認証済みページか」を判定する (resources/js/app.ts)。ここが欠けると
+    // guard が一切作動せず、検証ページが観測対象を失う。正のコントロールとして固定する。
+    $this->actingAs($user)
+        ->withHeaders(bfcacheTrialBasicAuthHeaders())
+        ->get('/debug/bfcache-trial')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->has('auth.user'));
+});
diff --git a/tests/js/lib/debug/bfcache-trial.test.ts b/tests/js/lib/debug/bfcache-trial.test.ts
new file mode 100644
index 0000000..5c744c5
--- /dev/null
+++ b/tests/js/lib/debug/bfcache-trial.test.ts
@@ -0,0 +1,845 @@
+import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
+import {
+    TRIAL_SCHEMA_VERSION,
+    TRIAL_STORAGE_KEY,
+    DEVICE_MODEL_MAX_LENGTH,
+    STORAGE_FAILURE_REASON_MAX_LENGTH,
+    VERIFIED_OS_VERSION_MAX_LENGTH,
+    appendEvent,
+    canAppend,
+    deriveGuardVerdict,
+    deriveOverallVerdict,
+    deriveTrialPhase,
+    deriveTrialVerdict,
+    expectedGuardVerdict,
+    groupByTrialId,
+    hasSingleTrialId,
+    loadTrials,
+    nextSequence,
+    parseTrialEvent,
+    parseTrialLog,
+    probeStorageWritable,
+    type GuardState,
+    type TrialEvent,
+} from "@/lib/debug/bfcache-trial";
+
+/**
+ * 観測ライブラリの真理値表テスト (詳細設計 施策 5)。
+ *
+ * **最終形だけでなく逐次適用も検証する**。listener の追記可否は
+ * deriveTrialPhase() の結果で決まるため、正常な遷移 prefix で phase が
+ * complete に落ちると実機で観測が途中停止する。最終形のテストだけでは
+ * この回帰を検出できない。
+ */
+
+const TRIAL = "trial-a";
+const TOKEN = "token-a";
+
+let sequence = 0;
+
+function base(trialId = TRIAL): {
+    schemaVersion: number;
+    trialId: string;
+    sequence: number;
+    timestamp: string;
+} {
+    sequence += 1;
+    return {
+        schemaVersion: TRIAL_SCHEMA_VERSION,
+        trialId,
+        sequence,
+        timestamp: `2026-08-14T00:00:${String(sequence).padStart(2, "0")}.000Z`,
+    };
+}
+
+function started(trialId = TRIAL, contextToken = TOKEN): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "trial-started",
+        scenario: "expired-session",
+        contextToken,
+        userAgent: "test-agent",
+        uaReportedOs: "iOS",
+        displayMode: "standalone",
+        navigatorStandalone: true,
+        deviceModel: "iPhone 15 Pro",
+        verifiedOsVersion: "18.2",
+    };
+}
+
+function away(trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "away-navigation-started" };
+}
+
+function awayFailed(trialId = TRIAL): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "away-navigation-failed",
+        observationMethod: "manual",
+    };
+}
+
+function hide(persisted: boolean, trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "page-hide", persisted, guardState: null };
+}
+
+function show(
+    persisted: boolean,
+    contextToken = TOKEN,
+    trialId = TRIAL,
+): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "page-show",
+        persisted,
+        contextToken,
+        displayMode: "standalone",
+    };
+}
+
+function guard(state: GuardState, trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "guard-state-changed", state };
+}
+
+function redirect(trialId = TRIAL): TrialEvent {
+    return {
+        ...base(trialId),
+        type: "redirect-observed",
+        observationMethod: "manual",
+    };
+}
+
+function aborted(trialId = TRIAL): TrialEvent {
+    return { ...base(trialId), type: "trial-aborted" };
+}
+
+beforeEach(() => {
+    sequence = 0;
+    sessionStorage.clear();
+});
+
+// ---------------------------------------------------------------------------
+
+describe("軸 1: 試行成立判定", () => {
+    it("#1 started → away → hide(true) → show(true, token 一致) は valid-bfcache", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(true), show(true)]),
+        ).toBe("valid-bfcache");
+    });
+
+    it("#2 show(false) かつ token 不一致は invalid-not-bfcache (空振り)", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(false, "other-token"),
+            ]),
+        ).toBe("invalid-not-bfcache");
+    });
+
+    it("#3 hide(false) と show(true) の不一致は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(false), show(true)]),
+        ).toBe("inconsistent");
+    });
+
+    it("#4 show(false) だが token 一致は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(true), show(false)]),
+        ).toBe("inconsistent");
+    });
+
+    it("#5 show(true) だが token 不一致は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true, "other-token"),
+            ]),
+        ).toBe("inconsistent");
+    });
+
+    it("#6 show が無ければ incomplete", () => {
+        expect(deriveTrialVerdict([started(), away(), hide(true)])).toBe(
+            "incomplete",
+        );
+    });
+
+    it("#7 hide 後に aborted は incomplete", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), hide(true), aborted()]),
+        ).toBe("incomplete");
+    });
+
+    it("#8 away 後に hide が無いだけでは incomplete (時間差を失敗と見なさない)", () => {
+        expect(deriveTrialVerdict([started(), away()])).toBe("incomplete");
+    });
+
+    it("#9 away-navigation-failed (手動記録) があれば invalid-wrong-route", () => {
+        expect(deriveTrialVerdict([started(), away(), awayFailed()])).toBe(
+            "invalid-wrong-route",
+        );
+    });
+
+    it("#9-b away-navigation-started より前の failed は採用しない (順序を要求する)", () => {
+        // 離脱を試していないのに離脱失敗が記録されている列は根拠にしない
+        const s = started();
+        const f = awayFailed();
+        const a = away();
+        expect(deriveTrialVerdict([s, f, a])).toBe("incomplete");
+    });
+
+    it("#9-c away-navigation-started が無い failed も採用しない", () => {
+        expect(deriveTrialVerdict([started(), awayFailed()])).toBe("incomplete");
+    });
+
+    it("#10 started のみは incomplete", () => {
+        expect(deriveTrialVerdict([started()])).toBe("incomplete");
+    });
+
+    it("#11 sequence 逆順 (show が hide より前) は inconsistent", () => {
+        const s = started();
+        const a = away();
+        const sh = show(true);
+        const h = hide(true);
+        expect(deriveTrialVerdict([s, a, sh, h])).toBe("inconsistent");
+    });
+
+    it("#12 guard-state-changed のみは incomplete (invalid-wrong-route にしない)", () => {
+        expect(deriveTrialVerdict([started(), guard("pending")])).toBe(
+            "incomplete",
+        );
+    });
+
+    it("#13 複数 trialId の混入は inconsistent", () => {
+        expect(
+            deriveTrialVerdict([started(), away(), away("trial-b")]),
+        ).toBe("inconsistent");
+    });
+
+    it("#14 away 欠落 (started → hide → show) は inconsistent", () => {
+        expect(deriveTrialVerdict([started(), hide(true), show(true)])).toBe(
+            "inconsistent",
+        );
+    });
+
+    it("#15 窓確定後に show(false, token 不一致) が追記されても valid-bfcache を維持", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                show(false, "fresh-token"),
+            ]),
+        ).toBe("valid-bfcache");
+    });
+
+    it("#16 窓確定後に redirect-observed が追記されても valid-bfcache を維持", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                redirect(),
+            ]),
+        ).toBe("valid-bfcache");
+    });
+
+    it("#17 窓確定後の復元後 page-hide は軸 1 に影響しない", () => {
+        expect(
+            deriveTrialVerdict([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                guard("pending"),
+                guard("verifying"),
+                hide(true),
+            ]),
+        ).toBe("valid-bfcache");
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("軸 2: guard 結果判定", () => {
+    /**
+     * 軸 1 window を成立させたうえで、復元後のイベントを足す。
+     *
+     * **thunk で受ける**のが要点。イベントを値で受けると JS の引数評価順により
+     * 復元後イベントの sequence が window の page-show より小さくなり、
+     * 軸 2 の境界フィルタで除外されてしまう (テストが意図しない列を検証することになる)。
+     */
+    function withWindow(...makeAfter: Array<() => TrialEvent>): TrialEvent[] {
+        const events: TrialEvent[] = [
+            started(),
+            away(),
+            hide(true),
+            show(true),
+        ];
+        for (const make of makeAfter) events.push(make());
+        return events;
+    }
+
+    it("#1 pending → verifying → null は authenticated-unhidden", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => guard(null)),
+            ),
+        ).toBe("authenticated-unhidden");
+    });
+
+    it("#2 秘匿維持のまま復元後 hide + redirect-observed は unauthenticated-redirected", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => hide(true), () => redirect()),
+            ),
+        ).toBe("unauthenticated-redirected");
+    });
+
+    it("#3 同じ列で redirect-observed が無ければ hidden-then-left", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => hide(true)),
+            ),
+        ).toBe("hidden-then-left");
+    });
+
+    it("#4 pending → verifying → retry は retry-hidden", () => {
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => guard("retry")),
+            ),
+        ).toBe("retry-hidden");
+    });
+
+    it("#7 verifying を経ずに null は failed-transition (秘匿解除が早すぎる)", () => {
+        expect(
+            deriveGuardVerdict(withWindow(() => guard("pending"), () => guard(null))),
+        ).toBe("failed-transition");
+    });
+
+    it("#8 往路 hide のみでは unauthenticated-redirected にしない", () => {
+        // 復元後の hide が無い (往路 hide は軸 1 window の内側)
+        expect(
+            deriveGuardVerdict(
+                withWindow(() => guard("pending"), () => guard("verifying"), () => redirect()),
+            ),
+        ).toBe("in-progress");
+    });
+
+    it("#9 軸 2 終端後に fresh load のイベントが追記されても判定が崩れない", () => {
+        const events = withWindow(() => guard("pending"), () => guard("verifying"), () => guard(null), () => show(false, "fresh-token"));
+        expect(deriveTrialVerdict(events)).toBe("valid-bfcache");
+        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
+    });
+
+    it("#9-b 終端後に guard-state-changed が追記されても崩れない", () => {
+        // 再ログイン後に A を開き直すと fresh load の guard 遷移が積まれる。
+        // 終端でフィルタを閉じていないとここで failed-transition に崩れる
+        const events = withWindow(
+            () => guard("pending"),
+            () => guard("verifying"),
+            () => guard(null),
+            () => show(false, "fresh-token"),
+            () => guard("pending"),
+            () => guard("verifying"),
+            () => guard(null),
+        );
+        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
+        expect(deriveTrialPhase(events)).toBe("complete");
+    });
+
+    it("#9-c hidden-then-left の後に guard イベントが追記されても崩れない", () => {
+        const events = withWindow(
+            () => guard("pending"),
+            () => guard("verifying"),
+            () => hide(true),
+            () => show(false, "fresh-token"),
+            () => guard("pending"),
+        );
+        expect(deriveGuardVerdict(events)).toBe("hidden-then-left");
+        expect(deriveTrialPhase(events)).toBe("awaiting-manual-confirmation");
+    });
+
+    it("#9-d 上記に redirect-observed を足すと unauthenticated-redirected", () => {
+        const events = withWindow(
+            () => guard("pending"),
+            () => guard("verifying"),
+            () => hide(true),
+            () => show(false, "fresh-token"),
+            () => guard("pending"),
+            () => redirect(),
+        );
+        expect(deriveGuardVerdict(events)).toBe("unauthenticated-redirected");
+    });
+
+    it("#14 retry 終端後に guard イベントが追記されても崩れない", () => {
+        const events = withWindow(
+            () => guard("pending"),
+            () => guard("verifying"),
+            () => guard("retry"),
+            () => guard("pending"),
+        );
+        expect(deriveGuardVerdict(events)).toBe("retry-hidden");
+    });
+
+    it("#10 復元直後で guard イベント無しは in-progress", () => {
+        expect(deriveGuardVerdict(withWindow())).toBe("in-progress");
+    });
+
+    it("#11 pending のみは in-progress (停止をイベント列から判定しない)", () => {
+        expect(deriveGuardVerdict(withWindow(() => guard("pending")))).toBe(
+            "in-progress",
+        );
+    });
+
+    it("#12 pending → verifying は in-progress", () => {
+        expect(
+            deriveGuardVerdict(withWindow(() => guard("pending"), () => guard("verifying"))),
+        ).toBe("in-progress");
+    });
+
+    it("#13 verifying から始まる列は failed-transition", () => {
+        expect(deriveGuardVerdict(withWindow(() => guard("verifying")))).toBe(
+            "failed-transition",
+        );
+    });
+
+    it("#15 guard イベント無しのまま aborted は not-observed", () => {
+        expect(deriveGuardVerdict(withWindow(() => aborted()))).toBe("not-observed");
+    });
+
+    it("複数 trialId の混入は failed-transition", () => {
+        expect(deriveGuardVerdict([started(), guard("pending", "trial-b")])).toBe(
+            "failed-transition",
+        );
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("軸 3: 総合判定", () => {
+    it("expired-session × valid-bfcache × unauthenticated-redirected は pass", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "unauthenticated-redirected",
+            ),
+        ).toBe("pass");
+    });
+
+    it("active-session × valid-bfcache × authenticated-unhidden は pass", () => {
+        expect(
+            deriveOverallVerdict(
+                "active-session",
+                "valid-bfcache",
+                "authenticated-unhidden",
+            ),
+        ).toBe("pass");
+    });
+
+    it("expired-session で authenticated-unhidden は expectation-mismatch", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "authenticated-unhidden",
+            ),
+        ).toBe("expectation-mismatch");
+    });
+
+    it("hidden-then-left は undetermined (redirect-observed 待ち)", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "hidden-then-left",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("in-progress は undetermined (観測途中を fail にしない)", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "in-progress",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("not-observed は undetermined (guard 故障と中止を区別できない)", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "not-observed",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("failed-transition は fail", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "valid-bfcache",
+                "failed-transition",
+            ),
+        ).toBe("fail");
+    });
+
+    it("空振り (invalid-not-bfcache) は pass にも fail にもしない", () => {
+        expect(
+            deriveOverallVerdict(
+                "expired-session",
+                "invalid-not-bfcache",
+                "authenticated-unhidden",
+            ),
+        ).toBe("undetermined");
+    });
+
+    it("incomplete は undetermined", () => {
+        expect(
+            deriveOverallVerdict("expired-session", "incomplete", "in-progress"),
+        ).toBe("undetermined");
+    });
+
+    it("expectedGuardVerdict がシナリオごとの期待値を返す", () => {
+        expect(expectedGuardVerdict("expired-session")).toBe(
+            "unauthenticated-redirected",
+        );
+        expect(expectedGuardVerdict("active-session")).toBe(
+            "authenticated-unhidden",
+        );
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("逐次適用: 各追記直後の verdict と phase", () => {
+    it("正常な遷移 prefix で観測が停止しない", () => {
+        const events: TrialEvent[] = [started(), away(), hide(true), show(true)];
+
+        // 軸 1 window 確定直後
+        expect(deriveGuardVerdict(events)).toBe("in-progress");
+        expect(deriveTrialPhase(events)).toBe("collecting-axis2");
+
+        events.push(guard("pending"));
+        expect(deriveGuardVerdict(events)).toBe("in-progress");
+        expect(deriveTrialPhase(events)).toBe("collecting-axis2");
+
+        events.push(guard("verifying"));
+        expect(deriveGuardVerdict(events)).toBe("in-progress");
+        expect(deriveTrialPhase(events)).toBe("collecting-axis2");
+
+        events.push(guard(null));
+        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
+        expect(deriveTrialPhase(events)).toBe("complete");
+    });
+
+    it("retry 終端は complete", () => {
+        const events: TrialEvent[] = [
+            started(),
+            away(),
+            hide(true),
+            show(true),
+            guard("pending"),
+            guard("verifying"),
+            guard("retry"),
+        ];
+        expect(deriveGuardVerdict(events)).toBe("retry-hidden");
+        expect(deriveTrialPhase(events)).toBe("complete");
+    });
+
+    it("復元後 hide は awaiting-manual-confirmation、redirect 追記で complete", () => {
+        const events: TrialEvent[] = [
+            started(),
+            away(),
+            hide(true),
+            show(true),
+            guard("pending"),
+            guard("verifying"),
+            hide(true),
+        ];
+        expect(deriveGuardVerdict(events)).toBe("hidden-then-left");
+        expect(deriveTrialPhase(events)).toBe("awaiting-manual-confirmation");
+
+        events.push(redirect());
+        expect(deriveGuardVerdict(events)).toBe("unauthenticated-redirected");
+        expect(deriveTrialPhase(events)).toBe("complete");
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("deriveTrialPhase の状態機械", () => {
+    it("軸 1 未終端は collecting-axis1", () => {
+        expect(deriveTrialPhase([started(), away()])).toBe("collecting-axis1");
+    });
+
+    it("軸 1 が invalid-not-bfcache で終端すると complete", () => {
+        expect(
+            deriveTrialPhase([
+                started(),
+                away(),
+                hide(true),
+                show(false, "other-token"),
+            ]),
+        ).toBe("complete");
+    });
+
+    it("trial-aborted は他の終端イベントと併存しても aborted が優先", () => {
+        expect(
+            deriveTrialPhase([
+                started(),
+                away(),
+                hide(true),
+                show(true),
+                guard("pending"),
+                guard("verifying"),
+                guard(null),
+                aborted(),
+            ]),
+        ).toBe("aborted");
+    });
+
+    it("複数 trialId の混入は invalid", () => {
+        expect(deriveTrialPhase([started(), away("trial-b")])).toBe("invalid");
+    });
+
+    it("awaiting-manual-confirmation では自動イベントを追記できない", () => {
+        expect(canAppend("awaiting-manual-confirmation", "page-show")).toBe(
+            false,
+        );
+        expect(canAppend("awaiting-manual-confirmation", "guard-state-changed")).toBe(
+            false,
+        );
+        expect(canAppend("awaiting-manual-confirmation", "redirect-observed")).toBe(
+            true,
+        );
+        expect(canAppend("awaiting-manual-confirmation", "trial-aborted")).toBe(
+            true,
+        );
+    });
+
+    it("complete / aborted / invalid では一切追記できない", () => {
+        for (const phase of ["complete", "aborted", "invalid"] as const) {
+            expect(canAppend(phase, "page-show")).toBe(false);
+            expect(canAppend(phase, "redirect-observed")).toBe(false);
+        }
+    });
+
+    it("collecting-axis1 では離脱失敗の手動記録を許可する", () => {
+        expect(canAppend("collecting-axis1", "away-navigation-failed")).toBe(
+            true,
+        );
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("validator の負のコントロール", () => {
+    it("schemaVersion 不一致は破棄", () => {
+        const event = { ...started(), schemaVersion: 99 };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("未知の type は破棄", () => {
+        const event = { ...started(), type: "unknown-type" };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("許可外の余分なキーを持つイベントは破棄", () => {
+        const event = { ...started(), extraKey: "x" };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("必須キーの欠落は破棄", () => {
+        const event: Record<string, unknown> = { ...started() };
+        delete event.contextToken;
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("deviceModel が最大長超過なら破棄", () => {
+        const event = {
+            ...started(),
+            deviceModel: "a".repeat(DEVICE_MODEL_MAX_LENGTH + 1),
+        };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("deviceModel に許可外文字があれば破棄", () => {
+        expect(parseTrialEvent({ ...started(), deviceModel: "山田太郎" })).toBeNull();
+        expect(parseTrialEvent({ ...started(), deviceModel: "a@b.com" })).toBeNull();
+    });
+
+    it("verifiedOsVersion が最大長超過なら破棄", () => {
+        const event = {
+            ...started(),
+            verifiedOsVersion: "1".repeat(VERIFIED_OS_VERSION_MAX_LENGTH + 1),
+        };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("verifiedOsVersion に許可外文字があれば破棄", () => {
+        expect(
+            parseTrialEvent({ ...started(), verifiedOsVersion: "18.2 (実機)" }),
+        ).toBeNull();
+        expect(
+            parseTrialEvent({ ...started(), verifiedOsVersion: "user@example.com" }),
+        ).toBeNull();
+    });
+
+    it("prototype 由来のキーを type にしても例外化せず破棄する", () => {
+        // `value in ALLOWED_KEYS` だと "toString" が真になり後段で例外化する
+        for (const poisoned of ["toString", "constructor", "hasOwnProperty"]) {
+            expect(() =>
+                parseTrialEvent({ ...started(), type: poisoned }),
+            ).not.toThrow();
+            expect(parseTrialEvent({ ...started(), type: poisoned })).toBeNull();
+        }
+    });
+
+    it("storage-failed の reason が最大長超過なら破棄", () => {
+        const event = {
+            ...base(),
+            type: "storage-failed",
+            reason: "x".repeat(STORAGE_FAILURE_REASON_MAX_LENGTH + 1),
+        };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("observationMethod が manual 以外なら破棄", () => {
+        const event = { ...redirect(), observationMethod: "auto" };
+        expect(parseTrialEvent(event)).toBeNull();
+    });
+
+    it("JSON として壊れていれば null", () => {
+        expect(parseTrialLog("{not json")).toBeNull();
+    });
+
+    it("配列でなければ null", () => {
+        expect(parseTrialLog('{"a":1}')).toBeNull();
+    });
+
+    it("1 件だけ壊れていてもログ全体を破棄する (部分採用しない)", () => {
+        const raw = JSON.stringify([started(), { broken: true }, away()]);
+        expect(parseTrialLog(raw)).toBeNull();
+    });
+
+    it("正常なログはイベント数どおりパースされる", () => {
+        const events = [started(), away(), hide(true)];
+        const parsed = parseTrialLog(JSON.stringify(events));
+        expect(parsed).not.toBeNull();
+        expect(parsed).toHaveLength(3);
+    });
+
+    it("null / 空文字は null", () => {
+        expect(parseTrialLog(null)).toBeNull();
+        expect(parseTrialLog("")).toBeNull();
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("採番と trial 分離", () => {
+    it("空配列では 1 を返す", () => {
+        expect(nextSequence([], TRIAL)).toBe(1);
+    });
+
+    it("復元した進行中 trial に対して max+1 を返す", () => {
+        const events = [started(), away(), hide(true)];
+        expect(nextSequence(events, TRIAL)).toBe(4);
+    });
+
+    it("欠番・重複があっても max+1 を返す", () => {
+        const events: TrialEvent[] = [
+            { ...started(), sequence: 1 },
+            { ...away(), sequence: 7 },
+            { ...hide(true), sequence: 7 },
+        ];
+        expect(nextSequence(events, TRIAL)).toBe(8);
+    });
+
+    it("他 trial の sequence を混ぜない", () => {
+        const events: TrialEvent[] = [
+            { ...started(), sequence: 1 },
+            { ...started("trial-b"), sequence: 99 },
+        ];
+        expect(nextSequence(events, TRIAL)).toBe(2);
+    });
+
+    it("hasSingleTrialId が単一で true、混入で false", () => {
+        expect(hasSingleTrialId([started(), away()])).toBe(true);
+        expect(hasSingleTrialId([started(), away("trial-b")])).toBe(false);
+        expect(hasSingleTrialId([])).toBe(true);
+    });
+
+    it("groupByTrialId が trialId ごとに分離する", () => {
+        const grouped = groupByTrialId([
+            started(),
+            away(),
+            started("trial-b"),
+        ]);
+        expect(grouped.size).toBe(2);
+        expect(grouped.get(TRIAL)).toHaveLength(2);
+        expect(grouped.get("trial-b")).toHaveLength(1);
+    });
+});
+
+// ---------------------------------------------------------------------------
+
+describe("storage", () => {
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("probeStorageWritable が書き込み可能環境で true", () => {
+        expect(probeStorageWritable()).toBe(true);
+    });
+
+    it("probeStorageWritable が setItem 例外環境で false", () => {
+        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
+            throw new Error("QuotaExceededError");
+        });
+        expect(probeStorageWritable()).toBe(false);
+    });
+
+    it("appendEvent が例外を伝播せず false を返す", () => {
+        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
+            throw new Error("QuotaExceededError");
+        });
+        expect(() => appendEvent(started())).not.toThrow();
+        expect(appendEvent(started())).toBe(false);
+    });
+
+    it("appendEvent の read-back が書き戻し内容の不一致を検出する", () => {
+        // setItem は成功するが保存内容が別物になる環境を模す
+        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
+            // 何も保存しない (getItem は null のまま)
+        });
+        expect(appendEvent(started())).toBe(false);
+    });
+
+    it("appendEvent が追記し、loadTrials が trialId ごとに返す", () => {
+        expect(appendEvent(started())).toBe(true);
+        expect(appendEvent(away())).toBe(true);
+        expect(appendEvent(started("trial-b"))).toBe(true);
+
+        const trials = loadTrials();
+        expect(trials.size).toBe(2);
+        expect(trials.get(TRIAL)).toHaveLength(2);
+        expect(trials.get("trial-b")).toHaveLength(1);
+    });
+
+    it("保存済みログが壊れていれば loadTrials は空を返す", () => {
+        sessionStorage.setItem(TRIAL_STORAGE_KEY, "{broken");
+        expect(loadTrials().size).toBe(0);
+    });
+});
```
