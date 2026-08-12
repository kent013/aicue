# Round 5: Round 4 指摘 (文書 2 点) への対応

2 点とも修正しました。APPROVE にできるか確認してください。

---

# 対応マトリクス: design-review Round 4

判定 REQUEST_CHANGES (文書上の 2 点のみ)。**両方対応**(反論なし)。

## [Warning] 非 HTTP 経路がまだ「既定」のまま (既定引数は無いので実装不能)

- 判断: **対応する**
- 対応内容: 呼び出し元表の 2 行を `AccountDeletionAuditContext::nonHttp()` に修正した。

## [Warning] 「順序の不変条件を早期 return が満たす」という記述が不正確

- 判断: **対応する**
- 根拠: 早期 return が保証するのは順序ではなく、**未認証時に凍結判定が作用しないこと**。
- 対応内容: 「未認証要求については user 不在により凍結判定が働かないため、
  **この要求に関して middleware 順序への依存が無い**。契約 8 が固定するのは
  『凍結判定が未認証要求を 409 で横取りしない』こと」に書き換えた。


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
