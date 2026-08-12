【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富な Laravel コードレビュアーです。実装をレビューしてください。

【レビュー観点】
1. 詳細設計との一致性 2. 正確性 3. PHPStan level 10 4. テストが退行を検出できるか (mutation 実測を添付)
5. 副作用・後退リスク 6. セキュリティ 7. 禁止事項

【特に見てほしい点】
- 設計からの乖離 2 点 (下記) の判断は妥当か
- mutation M4 が予測より広く赤くなった件の説明は正確か
- 「観測であって防御ではない」が実装で守られているか (値で分岐していないか)

【出力形式】ファイルごとに APPROVE / REQUEST_CHANGES、[Critical][Warning][Suggestion]、全体判定、日本語

---

## 詳細設計書 (APPROVE 済み)

# 詳細設計: freeze-destroy-xhr

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 2. PHPStan の widen 3. dev DB への破壊操作
4. `response()->json()` の直書き 5. Prism 直呼び 6. prompt 直書き
7. `redirect()->intended()` 8. 必須条件未充足での disabled 9. Artifact の使用

### コーディングルール

`declare(strict_types=1)` + 日本語コメント / PHPStan level 10 / Pest (RefreshDatabase グローバル) /
テストデータは Factory 経由 / 既存テストの削除禁止。

## 概念設計リファレンス

- `devnotes/20260812-1410-freeze-destroy-xhr/conceptual-design.md` (Round 4 APPROVED)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 監査 metadata に削除時点の凍結状態・route・method を残す (context DTO 経由) | `app/DataTransferObjects/Account/AccountDeletionAuditContext.php` (新規) / `app/Services/Organization/OrganizationMembershipService.php` / `app/Http/Controllers/Settings/AccountController.php` | High |
| 2 | 契約 **9 件** (1〜6 + 7a + 7b + 8) をテストで固定 | `tests/Feature/Auth/AccountDeletionFreezeTest.php` (既存へ追記) | High |
| 3 | 運用契約の記載 | `docs/architecture.md` §退会の猶予期間つき削除 | Medium |

**防御は増やさない。** 凍結判定の二重化・削除直前の再チェックは作らない。

---

## 施策 1: 監査 metadata

### 現行コード

```php
// 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
$this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
$freshUser->delete();
```

### 変更後コード

**`request()` を service 内で呼ばない** (Round 1 [Warning])。削除 service が HTTP 経路に依存すると、
CLI / job / テストからの呼び出しで観測値の意味が曖昧になる。**呼び出し元から context を渡す**。

```php
<?php // app/DataTransferObjects/Account/AccountDeletionAuditContext.php

/**
 * 削除の到達経路 (監査 metadata 用)。**HTTP 外 (日次執行・コンソール) では null を渡す**。
 * ★観測専用。この値で分岐する処理は 1 つも作らない。
 */
final readonly class AccountDeletionAuditContext
{
    private function __construct(
        public ?string $route,
        public ?string $method,
    ) {}

    /** HTTP 経由の削除 (route / method を残す) */
    public static function http(?string $route, string $method): self
    {
        return new self($route, $method);
    }

    /** HTTP 外 (猶予期間の日次執行・コンソール)。**渡し忘れと区別するため明示的に呼ばせる** */
    public static function nonHttp(): self
    {
        return new self(null, null);
    }
}
```

`deleteAccount()` は context を **必須引数**で受け取る (Round 2 [Warning])。
既定引数にすると **HTTP 呼び出し元が渡し忘れても検出できず**、
「非 HTTP なので null」と「渡し忘れの null」が区別できなくなる。
必須にすれば新しい呼び出し元は**判断を強制**され、PHPStan level 10 が漏れを検出する。

```php
// 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)。
//    **削除実行時点の凍結状態と到達経路を残す** (bug-hunt F-4-Q1)。
//    再現しなかった「凍結中なのに削除された」観測に対し、**再発時に原因へ到達できる**ようにする。
//    ★これは観測であって防御ではない — この値で分岐する処理は 1 つも無い。
$this->recorder->record(SecurityEventType::AccountDeleted, $freshUser, [
    // 行ロック下で読み直した $freshUser から取る (削除と同一トランザクション内)
    'deletion_requested' => $freshUser->deletion_requested_at !== null,
    // 呼び出し元が渡す。HTTP 外は null が正常値
    'route' => $context->route,
    'method' => $context->method,
]);
$freshUser->delete();
```

呼び出し元は 3 箇所:

| 呼び出し元 | 渡す context |
|---|---|
| `Settings\AccountController::destroy` | `AccountDeletionAuditContext::http($request->route()?->getName(), $request->method())` |
| `PurgeDeletionRequestsCommand` 経由 (猶予期間の日次執行) | `AccountDeletionAuditContext::nonHttp()` |
| 内部の予約執行 (`OrganizationMembershipService` 自身) | `AccountDeletionAuditContext::nonHttp()` |

- **PII は載せない** (bool と route 名と HTTP メソッドのみ)。

### PHPStan 適合

- context は `readonly` DTO で `?string` 2 本。metadata は `array<string, mixed>` を受ける。
- **既定引数は持たせない**。既存 2 箇所も明示的に `nonHttp()` を渡すよう変更する
  (監査情報の deny-by-default 性を保つ)。

---

## 施策 2: 契約 (既存 `AccountDeletionFreezeTest` へ追記)

| # | 契約 | 検査 |
|---|---|---|
| 1 | **XHR/JSON の DELETE で 409**、かつ**その user が消えていない** | `deleteJson('/settings/account')` → 409 / `User::whereKey($id)->exists()` が true |
| 2 | **recent-auth を満たしていても 409** (step-up を通過しても凍結が優先) | `withSession(freshRecentAuthSession())` つきで 409 |
| 3 | **recent-auth を満たしていなくても 409** (順序の決定。step-up challenge を先に返さない) | session 無しで 409 (302/401 ではない) |
| 4 | 凍結中に即時削除を試みた後、**取消 → 削除ができる** | 409 → `deletion-request` の DELETE → 即時削除が通る |
| 5 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` が**入っていない** | enum の全 case を集めて名指しで不在を assert (allowlist へ足した瞬間に赤くなる) |
| 6 | **2FA 必須組織 × 凍結中**でも即時削除は 409 | 2FA 必須組織の未準拠メンバーで JSON DELETE → 409 |
| 7a | **通常削除 (凍結なし)** の監査 metadata に `deletion_requested=false` / `route` / `method` が載る | Feature テスト。HTTP 経由なので route / method が入る |
| 7b | **凍結中の user を service へ直接渡した**とき `deletion_requested=true` が記録される | Service レベルのテスト。**凍結中は HTTP 経由では削除されない**ため、この経路でしか観測できない。**M5 (値を常に false にする) を殺すのはこの契約**である |
| 8 | **未認証**の JSON DELETE は 409 ではなく 401 | 凍結の遮断が未認証要求を横取りしないことの固定。**ただし下記のとおり「順序」の証明ではない** |

**契約 3 が「順序の決定」を固定する**。実行順が変わっても 409 が正であり、
middleware priority の偶然を追認しない (概念設計の決定)。

### fail 先行

**「どれが赤くなるか」は想定を書かず、fail-first で実測して記録する** (Round 1 [Warning])。
仮説としては契約 7a / 7b が赤 (metadata 未実装のため)、契約 1..4 / 6 / 8 は緑 (実装は既に正しい) だが、
recent-auth / 2FA middleware / route priority の既存状態次第で赤くなりうる。
**実測値を実装メモへ残す**。

### mutation 計画

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` を足す | 契約 1・2・3・5・6 |
| M2 | middleware の `expectsJson()` 分岐を消し常に redirect にする | 契約 1・3 (**2 / 6 も赤くなりうる**。最低限 1・3) |
| M3 | metadata の `deletion_requested` を落とす | 契約 7a・7b |
| M4 | metadata の `route` / `method` を落とす | 契約 7a |
| M5 | `deletion_requested` の値を常に `false` にする | **契約 7b のみ** (7a は期待値が false なので殺せない = Round 1 [Critical]) |

**M6 (凍結判定を認証より前へ動かす) は mutation として成立しないため計画から外す** (Round 2 [Critical])。
`EnsureAccountNotPendingDeletion` は `$request->user()` が `User` でなければ**何もせず次へ渡す**ので、
認証より前に置いても未認証要求は素通りし、その後の `Authenticate` が同じ 401 を返す = **観測できない**。
**早期 return が保証するのは「順序」ではなく「未認証時に凍結判定が作用しないこと」である。**
未認証要求については user 不在により凍結判定が働かないため、**この要求に関して
middleware 順序への依存が無い**。契約 8 が固定するのは
「凍結判定が未認証要求を 409 で横取りしない」ことである。

## 実装モード

incremental。変更は **DTO 新規 1 + service 1 箇所 + controller 1 箇所 + コマンド/内部呼び出し 2 箇所 +
テスト + docs**。`OrganizationMembershipService` は退会系の他 TODO と同じファイルだが、
現在並走している TODO は無い。

## 保証しないもの (誇張しない)

- **観測された 1 件の原因は特定していない**。本 TODO は契約テストと監査 metadata を足すだけで、
  原因特定や防御追加は行わない。
- **並行実行 (ブラウザ遷移と fetch の競合) は再現しない**。Feature テストは 1 リクエストずつ
  順に実行するため、探索エージェントが疑った競合そのものは検査できない。
  その代わりが監査 metadata である。
- **防御は増えない**。`deletion_requested` の値で分岐する処理は作らない。


---

## テスト結果 (worktree 内)

`composer test` **4549 passed / 2 skipped (4551)** / `composer phpstan` No errors /
`vendor/bin/pint --test` passed / `pnpm lint` / `pnpm typecheck`: 緑。

### fail 先行 (予測と実測)

仮説「7a / 7b が赤、1..6 / 8 は緑」→ **実測一致** (27 件中 2 件赤)。
実装が既に正しく、**足りなかったのは観測**だったことが裏づけられた。

### 設計からの乖離 2 点

1. **契約 6 の 2FA 必須組織のフラグ名が違った。** 設計時は `requires_two_factor` と書いていたが、
   実際の列は **`two_factor_required`** (migration で確認)。テストを実列名に修正した。
2. **`AccountDeletionPathGateTest` の `DELETION_PATH_CLOSURE` へ新 DTO の登録が必要だった。**
   退会経路の依存閉包 gate (T141) が exact-fit で赤くなった = **想定どおりの deny-by-default**。
   「観測専用 DTO で決済事業者 SDK への到達辺を持たない」旨のコメント付きで登録した。

### mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | allowlist に `settings.account.destroy` を足す | 契約 1・2・3・5・6 | **一致** (5 件赤) |
| M2 | middleware の `expectsJson()` 分岐を消す | 最低 1・3 (2・6 も赤くなりうる) | **一致** (6 件赤) |
| M3 | metadata の `deletion_requested` を落とす | 契約 7a・7b | **一致** (2 件) |
| M4 | metadata の `route` / `method` を落とす | 契約 7a | **予測より広く 2 件** (7a・7b)。7b も `route`/`method` が `null` であることを `toMatchArray` で見ているため、キーごと落ちると 7b も落ちる。**予測の書き方が狭かった** |
| M5 | `deletion_requested` を常に `false` にする | **契約 7b のみ** | **一致** (1 件だけ赤) |

**M5 が 7b だけを赤くした**ことで、設計 Round 1 の Critical
(「7a では M5 を殺せないので service レベルの 7b が要る」) が実測で裏づけられた。

---

## 実装差分 (git diff)

```diff
diff --git a/app/Http/Controllers/Settings/AccountController.php b/app/Http/Controllers/Settings/AccountController.php
index 11709f1..8aeffc1 100644
--- a/app/Http/Controllers/Settings/AccountController.php
+++ b/app/Http/Controllers/Settings/AccountController.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Controllers\Settings;
 
+use App\DataTransferObjects\Account\AccountDeletionAuditContext;
 use App\Http\Controllers\Controller;
 use App\Models\User;
 use App\Services\Organization\OrganizationMembershipService;
@@ -27,7 +28,13 @@ public function destroy(Request $request, OrganizationMembershipService $members
         // 記録 (AccountDeleted) と削除は service の単一トランザクション内・行ロック下で直列化される。
         // Auth::logout はガード通過後・削除直前のフックで呼ぶ (logout 監査イベントを user 行が
         // 存在するうちに記録するため。ブロック時はフックが実行されずログアウトされない)。
-        $membership->deleteAccount($user, static fn () => Auth::logout());
+        $membership->deleteAccount(
+            $user,
+            static fn () => Auth::logout(),
+            null,
+            // 監査 metadata へ到達経路を残す (T160 / bug-hunt F-4-Q1。観測専用で分岐しない)
+            AccountDeletionAuditContext::http($request->route()?->getName(), $request->method()),
+        );
 
         // 削除成功後のみ後処理 (ブロック時は上で例外伝播し到達しない)。
         $request->session()->invalidate();
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 5576327..c1d9d1d 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -4,6 +4,7 @@
 
 namespace App\Services\Organization;
 
+use App\DataTransferObjects\Account\AccountDeletionAuditContext;
 use App\DataTransferObjects\Account\AccountDeletionStateDto;
 use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
 use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
@@ -749,7 +750,7 @@ public function executeAccountDeletionRequest(User $user): bool
             $executed = AccountDeletionStateDto::fromUser($locked)->isDue(CarbonImmutable::now());
 
             return $executed;
-        });
+        }, AccountDeletionAuditContext::nonHttp());
 
         return $executed;
     }
@@ -902,9 +903,9 @@ private function keyOf(Model $model): int
      *
      * @throws ValidationException 唯一 Owner かつ (他メンバーが残る ∨ 生きた課金責務がある) 組織がある
      */
-    public function deleteAccount(User $user, ?\Closure $beforeDelete = null, ?\Closure $precondition = null): void
+    public function deleteAccount(User $user, ?\Closure $beforeDelete, ?\Closure $precondition, AccountDeletionAuditContext $auditContext): void
     {
-        DB::transaction(function () use ($user, $beforeDelete, $precondition): void {
+        DB::transaction(function () use ($user, $beforeDelete, $precondition, $auditContext): void {
             // 1. 対象 User 行を最初にロック (この後の所属列挙を安定させる。列挙前に user を
             //    ロックしないと、列挙〜user ロック取得の間に別 txn が新組織 B の Owner を user へ
             //    移譲し、B を未検査のまま削除する race が残る)。
@@ -956,8 +957,17 @@ public function deleteAccount(User $user, ?\Closure $beforeDelete = null, ?\Clos
                 $beforeDelete();
             }
 
-            // 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
-            $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
+            // 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)。
+            //    **削除実行時点の凍結状態と到達経路を残す** (T160 / bug-hunt F-4-Q1)。
+            //    再現しなかった「凍結中なのに削除された」観測に対し、再発時に原因へ到達できるようにする。
+            //    ★観測であって防御ではない — この値で分岐する処理は 1 つも無い。
+            $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser, [
+                // 行ロック下で読み直した $freshUser から取る (削除と同一トランザクション内)
+                'deletion_requested' => $freshUser->deletion_requested_at !== null,
+                // 呼び出し元が渡す。HTTP 外 (日次執行・コンソール) は null が正常値
+                'route' => $auditContext->route,
+                'method' => $auditContext->method,
+            ]);
             $freshUser->delete();
         });
     }
diff --git a/docs/architecture.md b/docs/architecture.md
index 5ac630f..0857439 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1632,6 +1632,21 @@ ## 退会の猶予期間つき削除 (凍結方式・30 日)
   (`ValidationException` を素で `report()` しても Laravel の既定 dontReport が握り潰すため。
   T142 で実測)。保留が滞留すると 30 日を過ぎた予約が消えないままになるので、
   `blocked` の継続・増加を正常成功として扱わない。
+- **即時削除 (`settings.account.destroy`) の遮断は HTML と XHR の両方で固定済み (T160)**。
+  凍結中の即時削除は **recent-auth の有無にかかわらず 409** を返す (凍結が step-up より先)。
+  理由は (a) 凍結状態を知るのは**本人**で `/settings` に既に表示しており秘匿すべき相手がいない、
+  (b) 再認証させてから断るのは体験として悪い。**実行順が変わっても 409 が正**であり、
+  `AccountDeletionFreezeTest` の契約がそれを固定する。
+  **未認証要求は 409 ではなく 401** — 未認証時は user 不在で凍結判定が作用しないため、
+  この要求について middleware 順序への依存は無い。
+- **削除の監査 metadata (T160)**: `AccountDeleted` イベントへ
+  `deletion_requested` (削除実行時点で凍結中だったか。**行ロック下で読み直した行**から取る) /
+  `route` / `method` を残す。route・method は呼び出し元が
+  `AccountDeletionAuditContext::http()` / `::nonHttp()` で**明示的に**渡す
+  (既定引数を持たせない = HTTP 呼び出し元の渡し忘れと「HTTP 外なので null」を区別する)。
+  **これは観測であって防御ではない** — この値で分岐する処理は 1 つも無い。
+  背景は bug-hunt run 20260812-100645 の F-4-Q1 (凍結中の即時削除で 1 件だけ実データが消えた
+  観測。**2 回のクリーン再現では遮断され、原因は未特定**)。
 - **2FA 必須組織との相互作用**: 2FA 強制ゲートは priority list で凍結より**前**に走る。
   取消は**業務の利用ではなく誤操作の救済**なので、**両ゲートの allowlist に入っている**
   (凍結側 = `AccountDeletionFreezeAllowance::DeletionRequestDestroy`、2FA 側 =
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
index 1928cff..54726f8 100644
--- a/tests/Architecture/AccountDeletionPathGateTest.php
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -102,6 +102,10 @@
     //   「予約列の読み書き」「猶予日数の解決」「予約したことの通知」だけを行い、
     //   決済事業者 SDK への到達辺を持たない (検査 2 が機械的に固定する)。
     'App\Console\Commands\Account\PurgeDeletionRequestsCommand',
+    // ↓ T160 (bug-hunt F-4-Q1) で閉包に入った 1 クラス。削除の到達経路 (route / method) を
+    //   監査 metadata へ運ぶだけの readonly DTO で、**観測専用** (値で分岐しない)。
+    //   決済事業者 SDK への到達辺は持たない (検査 2 が機械的に固定する)。
+    'App\DataTransferObjects\Account\AccountDeletionAuditContext',
     'App\DataTransferObjects\Account\AccountDeletionStateDto',
     'App\DataTransferObjects\Notification\AccountDeletionRequestedPayload',
     'App\DataTransferObjects\Invitations\PendingInvitationForUserDto',
diff --git a/tests/Feature/Auth/AccountDeletionFreezeTest.php b/tests/Feature/Auth/AccountDeletionFreezeTest.php
index 31834f4..3c7bff0 100644
--- a/tests/Feature/Auth/AccountDeletionFreezeTest.php
+++ b/tests/Feature/Auth/AccountDeletionFreezeTest.php
@@ -2,12 +2,15 @@
 
 declare(strict_types=1);
 
+use App\DataTransferObjects\Account\AccountDeletionAuditContext;
 use App\Enums\Account\AccountDeletionFreezeAllowance;
 use App\Enums\OrganizationRole;
+use App\Enums\SecurityEventType;
 use App\Http\Middleware\EnsureAccountNotPendingDeletion;
 use App\Jobs\Billing\AutoRechargeTriggerJob;
 use App\Models\AnalysisJob;
 use App\Models\Project;
+use App\Models\SecurityAuditEvent;
 use App\Models\SourceDocument;
 use App\Models\User;
 use App\Models\VideoManual;
@@ -374,3 +377,127 @@ function twoFactorPendingFrozenUser(): User
     $this->actingAs($owner)->get("/projects/{$foreign->id}")->assertNotFound();
     $this->actingAs($owner)->get('/projects/999999999')->assertNotFound();
 });
+
+/*
+ * 凍結中の即時削除 — XHR 経路の観測ギャップを閉じる (T160 / bug-hunt F-4-Q1)。
+ *
+ * 既存テストは HTML 経路 (302 リダイレクト) しか叩いておらず、探索エージェントが通った
+ * **XHR/JSON の DELETE には遮断を固定するテストが 1 本も無かった**。再現しなかったことは
+ * 無罪証明ではない (実データが消えた観測が 1 件ある) ため、経路を機械で固定する。
+ *
+ * 順序の決定 (概念設計): **凍結は recent-auth より先** = step-up の有無にかかわらず 409。
+ * 理由は (a) 凍結状態を知るのは本人で /settings に既に表示しており秘匿すべき相手がいない、
+ * (b) 再認証させてから断るのは体験として悪い。実行順が変わっても 409 が正である。
+ */
+
+test('T160 契約 1: 凍結中の XHR DELETE は 409 で、その user は消えていない', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->deleteJson('/settings/account')
+        ->assertStatus(409);
+
+    // 件数ではなく**その user** の実在で見る
+    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
+});
+
+test('T160 契約 2: recent-auth を満たしていても 409 (step-up を通過しても凍結が優先)', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->deleteJson('/settings/account')
+        ->assertStatus(409);
+});
+
+test('T160 契約 3: recent-auth を満たしていなくても 409 (step-up challenge を先に返さない)', function (): void {
+    [, $owner] = frozenUser();
+
+    // recent_auth_at を積まない = step-up 未充足
+    $this->actingAs($owner)
+        ->deleteJson('/settings/account')
+        ->assertStatus(409);
+});
+
+test('T160 契約 4: 凍結中に即時削除を試みた後、取消してからなら削除できる', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->withSession(freshRecentAuthSession())
+        ->deleteJson('/settings/account')->assertStatus(409);
+
+    $this->actingAs($owner)->delete('/settings/account/deletion-request');
+    $owner->refresh();
+
+    $this->actingAs($owner)->withSession(freshRecentAuthSession())
+        ->delete('/settings/account')->assertRedirect('/');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
+});
+
+test('T160 契約 5: 即時削除は凍結 allowlist に入っていない (足した瞬間に赤くなる)', function (): void {
+    $allowed = array_map(
+        static fn (AccountDeletionFreezeAllowance $case): string => $case->value,
+        AccountDeletionFreezeAllowance::cases(),
+    );
+
+    expect($allowed)->not->toContain('settings.account.destroy');
+});
+
+test('T160 契約 6: 2FA 必須組織でも凍結中の即時削除は 409', function (): void {
+    [$organization, $owner] = frozenUser();
+    $organization->forceFill(['two_factor_required' => true])->save();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->deleteJson('/settings/account')
+        ->assertStatus(409);
+
+    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
+});
+
+test('T160 契約 7a: 通常削除の監査 metadata に凍結状態と到達経路が載る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/settings/account')
+        ->assertRedirect('/');
+
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::AccountDeleted->value)
+        ->latest('id')
+        ->first();
+
+    expect($event)->not->toBeNull();
+    expect($event?->metadata)->toMatchArray([
+        'deletion_requested' => false,
+        'route' => 'settings.account.destroy',
+        'method' => 'DELETE',
+    ]);
+});
+
+test('T160 契約 7b: 凍結中の user を service へ直接渡すと deletion_requested=true が残る', function (): void {
+    // 凍結中は HTTP 経由では削除されないため、**この経路でしか観測できない**。
+    // 「値を常に false にする」mutation を殺すのはこの契約である。
+    [, $owner] = frozenUser();
+
+    app(OrganizationMembershipService::class)
+        ->deleteAccount($owner, null, null, AccountDeletionAuditContext::nonHttp());
+
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::AccountDeleted->value)
+        ->latest('id')
+        ->first();
+
+    expect($event?->metadata)->toMatchArray([
+        'deletion_requested' => true,
+        'route' => null,
+        'method' => null,
+    ]);
+});
+
+test('T160 契約 8: 未認証の XHR DELETE は 409 ではなく 401', function (): void {
+    // 凍結の遮断が未認証要求を横取りしないことの固定。
+    // (未認証時は user 不在で凍結判定が作用しないため、この要求について middleware 順序への依存は無い)
+    $this->deleteJson('/settings/account')->assertStatus(401);
+});
```
