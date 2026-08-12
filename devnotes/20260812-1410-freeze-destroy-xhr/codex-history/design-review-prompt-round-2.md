# Round 2: Round 1 指摘への対応

Critical 2 件・Warning 4 件・Suggestion 1 件を**すべて対応**しました。反論はありません。
中心は 契約 7 の 7a/7b 分割 (M5 を殺せる形へ)、`request()` を service から追い出す DTO 化、契約 8 の追加です。

APPROVED にできるか確認してください。

---

# 対応マトリクス: design-review Round 1

判定 REQUEST_CHANGES。Critical 2 / Warning 4 / Suggestion 1。**すべて対応**(反論なし)。

## [Critical] 契約 7 は M5 を殺せない (通常削除では期待値が false)

- 判断: **対応する**
- 根拠: 完全に正しい。凍結していない削除で検査すると期待値は `false` なので、
  実装を「常に false」に壊しても緑のまま。
- 対応内容: 契約 7 を **7a (Feature / 通常削除で false + route + method)** と
  **7b (Service レベル / 凍結中 user を直接渡して `true`)** に**分割**した。
  **M5 を殺すのは 7b** であることも mutation 表に明記した
  (凍結中は HTTP 経由では削除されないので、この経路でしか観測できない)。

## [Critical] M5 の予測が誤り

- 判断: **対応する** (上記と同じ修正)
- 対応内容: M5 の対象を **契約 7b のみ**に修正し、7a では殺せない理由も併記した。

## [Warning] `request()` を service 内で直接呼ぶな

- 判断: **対応する**
- 根拠: 妥当。削除 service が HTTP 経路に依存すると、CLI / job / テストからの呼び出しで
  観測値の意味が曖昧になる。
- 対応内容: **`AccountDeletionAuditContext` (readonly DTO)** を新設し、
  **呼び出し元から渡す**形にした。HTTP 外 (日次執行・コンソール) は既定の `null` を使う。
  呼び出し元 3 箇所の対応表も設計に載せた (既定引数なので既存 2 箇所は無変更)。

## [Warning] 「赤くなるのは契約 7 だけ」は強すぎる

- 判断: **対応する**
- 対応内容: 「想定」を書くのをやめ、**fail-first で実測して実装メモへ残す**形にした
  (仮説は仮説として併記)。

## [Warning] 凍結判定が認証・認可境界より前に走らないことを確認対象に含めよ

- 判断: **対応する**
- 対応内容: **契約 8 (未認証の JSON DELETE は 409 ではなく 401)** を追加し、
  対応する **M6 (凍結判定を認証より前に動かす)** も mutation 表へ足した。

## [Warning] M2 の期待範囲が狭い

- 判断: **対応する**
- 対応内容: 「**2 / 6 も赤くなりうる**。最低限 1・3」と書き換えた。

## [Suggestion] M1 / M3 / M4 は妥当 (肯定)

- 判断: 対応不要。


---

## 改訂後の詳細設計 (全文)

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
| 1 | 監査 metadata に削除時点の凍結状態・route・method を残す | `app/Services/Organization/OrganizationMembershipService.php` | High |
| 2 | 契約 6 件をテストで固定 | `tests/Feature/Auth/AccountDeletionFreezeTest.php` (既存へ追記) | High |
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
    public function __construct(
        public ?string $route = null,
        public ?string $method = null,
    ) {}
}
```

`deleteAccount()` は 3 番目の引数として受け取り (既定は空 context)、監査記録に載せる:

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
| `Settings\AccountController::destroy` | `new AccountDeletionAuditContext($request->route()?->getName(), $request->method())` |
| `PurgeDeletionRequestsCommand` 経由 (猶予期間の日次執行) | 既定 (route / method とも `null`) |
| 内部の予約執行 (`OrganizationMembershipService` 自身) | 既定 |

- **PII は載せない** (bool と route 名と HTTP メソッドのみ)。

### PHPStan 適合

- context は `readonly` DTO で `?string` 2 本。metadata は `array<string, mixed>` を受ける。
- 既定引数を持たせるので既存呼び出し元 (2 箇所) は無変更で通る。

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
| 8 | **未認証**の JSON DELETE は 409 ではなく 401 | 凍結判定が**認証境界より前に走らない**ことの固定 (Round 1 [Warning]) |

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
| M6 | 凍結判定を認証より前に動かす (middleware 順の入れ替え) | 契約 8 |

## 実装モード

incremental (1 サービス 1 箇所 + テスト + docs)。競合リスクなし。

## 保証しないもの (誇張しない)

- **観測された 1 件の原因は特定していない**。本 TODO は契約テストと監査 metadata を足すだけで、
  原因特定や防御追加は行わない。
- **並行実行 (ブラウザ遷移と fetch の競合) は再現しない**。Feature テストは 1 リクエストずつ
  順に実行するため、探索エージェントが疑った競合そのものは検査できない。
  その代わりが監査 metadata である。
- **防御は増えない**。`deletion_requested` の値で分岐する処理は作らない。
