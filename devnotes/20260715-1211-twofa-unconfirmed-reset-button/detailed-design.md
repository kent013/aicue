# 詳細設計: twofa-unconfirmed-reset-button

bug-hunt run 20260715-084108 F-2-03 (Medium, H10)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg /
単一 Default Project。

本改善は本質機能ではなく、**運用者がメンバーのセキュリティ状態を誤認しない管理 UX の
整合性回復**として使命の周辺品質に寄与する。

### 禁止事項

1. テストなしの実装完了報告(不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`(`back()->with(...)` で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### セキュリティ不変条件（関連）

- tenant キー不信 / 子は親に属する(不整合は認可より前に 404) / cross-org 不可
- PII(email/name)は CipherSweet、検索は `whereBlind()`
- 権限判定は常に `laratrust_team_id` を明示(strict_check=true)
- 本画面は `manageMembers` 権限者のみ到達 (403 境界)。今回の変更で新規 PII 露出・
  cross-org read/write は増やさない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは Svelte 5 runes + DS token のみ。アイコンは `@lucide/svelte`。

## 概念設計リファレンス

[conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` レビュー APPROVED / Round 1）

## 調査サマリー（現行実装の要点）

- `App\Enums\TwoFactorStatus`: `disabled` / `pending` / `enabled` の 3 値状態機械。
- `App\Models\User::twoFactorStatus()` (L133-142): `two_factor_confirmed_at !== null` →
  `Enabled`、secret なし → `Disabled`、secret ありで未確認 → `Pending`。
- `App\DataTransferObjects\Admin\MemberRowData` (L26,40): `twoFactorStatus` として 3 値を
  props に露出済み。**backend の追加露出は不要。**
- TS `resources/js/types/admin.ts` L18: `twoFactorStatus: "disabled" | "pending" | "enabled"`。
- `resources/js/pages/Admin/Users.svelte`:
  - L276: 2FA バッジは `member.twoFactorStatus === "enabled"` でのみ表示（**正しい**）。
  - L196-207: `canResetTwoFactor()` が `=== "disabled"` のみ false（**バグ**。pending が通る）。
- `App\Http\Controllers\Organizations\OrganizationMemberController::resetTwoFactor()`
  L104-109: `=== TwoFactorStatus::Disabled` のみ拒否。pending は素通しで解除実行（**副次バグ**）。
- 既存テスト:
  - `tests/Feature/Organizations/TwoFactorEnforcementTest.php` L159-172: props が
    `pending` を返す確認が**既存**（→ 概念レビュー Warning 通り、props テストの追加は不要）。
  - 同 L454-462: `disabled` メンバーの reset 明示拒否テストが既存（pending 版を追加する型）。
  - 同 L329-355: 解除成功テストは `enabled` メンバーで実施（pending 拒否化で破綻しない）。
  - `tests/js/pages/AdminUsers.test.ts` L163-170: `enabled` 解除ボタン表示テストが既存
    （pending 非表示テストを追加する型）。ヘルパ `tfeSetTwoFactorState` は既に `pending`
    (secret あり・confirmed_at なし) を生成可能 → **UserFactory の変更は不要**。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | フロント: 解除ボタンを enabled のみに表示 | `resources/js/pages/Admin/Users.svelte` | 必須 |
| 2 | サーバ: reset guard を「enabled 以外は拒否」に一貫化 | `app/Http/Controllers/Organizations/OrganizationMemberController.php` | 必須 |
| T1 | vitest: pending 非表示 / enabled 表示 | `tests/js/pages/AdminUsers.test.ts` | 必須 |
| T2 | Feature: pending reset 拒否 | `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | 必須 |

> backend の Resource/DTO/Controller への confirmed フラグ追加、UserFactory の pending state
> 追加、props=pending の Feature テスト追加は**いずれも不要**（既存で充足）。

---

## 施策 1: フロント — 解除ボタンを enabled のメンバーにのみ表示

### 変更箇所
- ファイル: `resources/js/pages/Admin/Users.svelte` (L195-207 `canResetTwoFactor`)

### 波及変更
- TypeScript型定義: なし（`MemberRow.twoFactorStatus` の型は変更不要）
- API Resource/DTO: なし（`MemberRowData` は既に 3 値を露出）
- テストファイル: `tests/js/pages/AdminUsers.test.ts`（施策 T1 で pending ケース追加）

### 現行コード
```svelte
/** 2FA リセットを提示できる対象か (自分以外 + 設定済み + Admin は org Member 系のみ対象) */
function canResetTwoFactor(member: MemberRow): boolean {
    if (member.isSelf || member.twoFactorStatus === "disabled") {
        return false;
    }
    // Owner は誰でも。Admin は org Member (editor/shooter/unassigned) のみ (同格以上は不可)
    return (
        viewerIsOwner ||
        member.roleState === "editor" ||
        member.roleState === "shooter" ||
        member.roleState === "unassigned"
    );
}
```

### 変更後コード
```svelte
/**
 * 2FA リセットを提示できる対象か (自分以外 + 2FA 確定済み + Admin は org Member 系のみ対象)。
 * pending (secret 生成済・TOTP 未確認) は 2FA 無効として扱い、解除ボタンを出さない
 * (本人の設定画面・2FA バッジと表示意味論を揃える。F-2-03)。
 */
function canResetTwoFactor(member: MemberRow): boolean {
    if (member.isSelf || member.twoFactorStatus !== "enabled") {
        return false;
    }
    // Owner は誰でも。Admin は org Member (editor/shooter/unassigned) のみ (同格以上は不可)
    return (
        viewerIsOwner ||
        member.roleState === "editor" ||
        member.roleState === "shooter" ||
        member.roleState === "unassigned"
    );
}
```

### 型安全 / DS 適合チェック
- [x] `member.twoFactorStatus` は union `"disabled" | "pending" | "enabled"`。`!== "enabled"` は
      型的に安全（narrowing で pending/disabled を弾く）。
- [x] `pnpm typecheck` / `pnpm lint` に影響なし（DOM・DS token 追加なし、既存条件式の変更のみ）。
- [x] 2FA バッジ (L276 `=== "enabled"`) と解除ボタンの表示条件が一致する。

### テスト計画
- [x] T1 (vitest) で pending 非表示 / enabled 表示を検証（再現→修正の順）。
- [x] 既存 vitest（enabled のボタン表示・admin 同格ガード）は変更後も緑（enabled 挙動は不変）。

### リスク
- pending メンバーに対して運用上「未確認」であることが UI から読み取れなくなる
  （バッジも解除ボタンも出ない = disabled と同一表示）。ただしドメイン 3 値は維持し、
  将来 pending 可視化を別施策で足せる。今回は「無効として扱う」に留める（スコープ外）。

---

## 施策 2: サーバ — reset guard を「enabled 以外は拒否」に一貫化（defense-in-depth）

### 変更箇所
- ファイル: `app/Http/Controllers/Organizations/OrganizationMemberController.php`
  (`resetTwoFactor` L104-109 の状態判定)

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Organizations/TwoFactorEnforcementTest.php`（施策 T2）

### 現行コード
```php
// disabled は明示拒否 (冪等成功にすると監査ノイズ・誤認が増える)
if ($lockedUser->twoFactorStatus() === TwoFactorStatus::Disabled) {
    throw ValidationException::withMessages([
        'two_factor' => ['このメンバーは 2 段階認証を設定していません。'],
    ]);
}

$disableTwoFactorAuthentication($lockedUser);
```

### 変更後コード
```php
// 2FA が確定 (Enabled) しているメンバーのみ解除対象。未設定 (Disabled) と
// 設定途中 (Pending) はともに「2FA 無効」として明示拒否する。UI (解除ボタンは
// enabled のみ表示) と API の意味論を揃え、未確認 secret のクリアに対して
// 誤解を招く監査記録・本人へのセキュリティ通知が発生するのを防ぐ (F-2-03)。
if ($lockedUser->twoFactorStatus() !== TwoFactorStatus::Enabled) {
    throw ValidationException::withMessages([
        'two_factor' => ['このメンバーは 2 段階認証を設定していません。'],
    ]);
}

$disableTwoFactorAuthentication($lockedUser);
```

### PHPStan適合チェック
- [x] 戻り値型・引数型は不変（`RedirectResponse`）。
- [x] `TwoFactorStatus` enum 比較のみで null 危険なし（`twoFactorStatus()` は非 null enum を返す）。
- [x] `ValidationException::withMessages` は既存パターン踏襲。DTO/JsonResource 非該当（拒否は
      例外経路で `back()->withErrors` に落ちる = 禁止事項 4・7 に抵触しない）。
- [x] トランザクション（`lockForUpdate` 済）内で判定・拒否する現行 TOCTOU 対策の構造を維持。

### 判定文言について
- エラー key・文言は現行の `two_factor` / 「このメンバーは 2 段階認証を設定していません。」を
  **据え置く**。pending は 2FA が有効化されていない状態であり、この文言で状態不正が伝わる
  （概念レビュー Suggestion: 権限不足 403 とは別経路のため運用上も区別が付く）。専用文言の
  新設はオーバーエンジニアリングのため見送り。

### テスト計画
- [x] T2 (Feature) で pending メンバーの reset が `two_factor` エラーで拒否され、secret が
      残る（解除されない）ことを検証。
- [x] 既存の disabled 拒否テスト（L454-462）・enabled 成功テスト（L329-355）は不変で緑。

### リスク / 運用ノート
- 「未確認 secret を管理者が能動的にクリアしたい」ニーズがあれば拒否になる。ただし本人が
  設定画面から再生成でき、未確認 secret は認証に使えないため実害は低い。誤解を招く監査
  記録・通知を避ける便益が上回る。
- **運用周知（実装時 TODO / リリースノート反映）**: 「pending（設定途中）メンバーの 2FA は
  管理画面から解除できない。本人が設定画面から再生成することで解消する」旨を 1 行案内する
  （Round 1 Warning 反映）。

---

## 施策 T1: vitest — pending 非表示 / enabled 表示

### 変更箇所
- ファイル: `tests/js/pages/AdminUsers.test.ts`（`membersFixture` に pending 行追加 + テスト追加）

### 変更方針（Round 1 Critical 反映）
- **共有 `membersFixture` は変更しない。** pending テストは既存「admin 閲覧者」テストと同じく
  props に**自己完結のローカル members 配列**を渡して描画する（他テストへの波及・件数
  アサーション破壊を避ける）。
- 検証は reset ボタンの **id 付き testid**（`reset-two-factor-{id}` は行スコープ相当）で
  presence/absence を確認。バッジ非表示は対象行の `<li>` を `closest("li")` で取得して
  `within(row)` にスコープし、脆い件数アサーションを使わない。
- viewer=owner・対象 role=editor を arrange で明示し、role 由来失敗と 2FA 状態由来失敗を分離。

### 変更内容（追加する test）
```ts
it("2FA 未確認 (pending) メンバーには解除ボタン・2FA バッジを出さない (owner 閲覧)", () => {
    // viewer=owner (id=1, isSelf) を明示。対象は role=editor に固定し role 条件を満たさせる。
    render(Users, {
        props: {
            ...baseProps,
            members: [
                {
                    id: 1, name: "オーナー 太郎", email: "owner@example.com",
                    roleState: "owner", roleLabel: "管理者（オーナー）",
                    twoFactorStatus: "enabled", isSelf: true,
                },
                {
                    id: 2, name: "確定 花子", email: "enabled@example.com",
                    roleState: "editor", roleLabel: "編集者",
                    twoFactorStatus: "enabled", isSelf: false,
                },
                {
                    id: 5, name: "設定中 五郎", email: "pending@example.com",
                    roleState: "editor", roleLabel: "編集者",
                    twoFactorStatus: "pending", isSelf: false,
                },
            ] satisfies MemberRow[],
        },
    });

    // enabled (id=2): 従来どおり解除ボタン表示（回帰しないことの対照）
    expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
    // pending (id=5): 解除ボタン非表示（本バグの修正点）
    expect(screen.queryByTestId("reset-two-factor-5")).toBeNull();

    // pending 行には 2FA バッジも出ない（バッジと解除ボタンの意味論一致）。
    // 行スコープ: 対象メンバー固有の email から closest('li') を辿る（件数アサーションを避ける）
    const pendingRow = screen.getByText("pending@example.com").closest("li");
    expect(pendingRow).not.toBeNull();
    expect(within(pendingRow as HTMLElement).queryByText("2FA")).toBeNull();
    // enabled 行には 2FA バッジが出る（対照）
    const enabledRow = screen.getByText("enabled@example.com").closest("li");
    expect(within(enabledRow as HTMLElement).getByText("2FA")).toBeInTheDocument();
});
```

> `within` は既存 import 済み（テスト冒頭 L2）。`baseProps` は const オブジェクト（本テストは
> `members` のみ上書き）。実装時に既存の型 import（`MemberRow`）に合わせる。
> 注記（Round 2 Suggestion）: `closest("li")` は行 DOM 構造に依存する。将来 `<li>` 構造を
> 変える場合は行 testid（例: `member-row-{id}`）を新設して `within(row)` に切り替える。
> 現状は reset ボタンの id-scoped testid を主検証とし、バッジ検証を補助とする現案で堅牢。

### テスト計画
- [x] 再現: 修正前（`=== "disabled"`）では pending 行に解除ボタンが出て
      `queryByTestId("reset-two-factor-5")` が非 null になり fail することを先に確認。
- [x] 施策 1 適用後に緑。
- [x] 共有 fixture 不変のため既存テストへの波及なし。

### リスク
- なし（ローカル fixture で自己完結、id-scoped testid + 行スコープ検証で堅牢）。

---

## 施策 T2: Feature — pending reset 拒否

### 変更箇所
- ファイル: `tests/Feature/Organizations/TwoFactorEnforcementTest.php`（disabled 拒否テストの隣に追加）

### 変更内容（追加する test、Round 1 Warning 反映で副作用抑止も固定）
```php
test('2FA 未確認 (pending) のメンバーへのリセットも明示拒否 (validation error / 通知・監査なし)', function (): void {
    Notification::fake();
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'pending');

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $member), ['reason' => '未確認 secret への誤操作'])
        ->assertSessionHasErrors(['two_factor']);

    // 未確認 secret は解除されず残る (冪等成功にしない)
    // 未確認 secret は解除されず残る (冪等成功にしない)。fresh は一度だけ取得。
    $fresh = $member->fresh();
    expect($fresh->two_factor_secret)->not->toBeNull();
    expect($fresh->two_factor_confirmed_at)->toBeNull();

    // 拒否時は本人通知・監査イベントを発火しない (誤解を招く通知/監査の抑止を仕様固定)。
    // event_type は enum value を使い、対象ユーザーでも絞る (enum 変更・別 fixture に強い)。
    Notification::assertNothingSentTo($member);
    expect(
        SecurityAuditEvent::query()
            ->where('event_type', SecurityEventType::OrgMemberTwoFactorReset->value)
            ->where('user_id', $member->id)
            ->count(),
    )->toBe(0);
});
```

> `Notification::fake()` / `SecurityAuditEvent` は同ファイルの成功テスト（L329-355）で既に
> import・使用済み。`SecurityEventType` の use 追加のみ確認する（成功テストは文字列
> `'org_member_two_factor_reset'` を使っているため未 import の可能性 → 実装時に use 追加）。
> テスト名は既存 disabled 拒否テスト（L454）と対になるよう「pending も明示拒否」で統一。

### PHPStan / 規約適合チェック
- [x] `tfeAddMember($organization, 'pending')` は既存ヘルパ（`tfeSetTwoFactorState` が pending
      = secret あり・confirmed_at なしを生成）を使用。手組み `create()` なし。
- [x] `RefreshDatabase` グローバル適用に依存。個別 `DatabaseTransactions` 不使用。
- [x] 既存の disabled 拒否テスト（L454）と同じ assert 構造で、`--parallel` セーフ。

### テスト計画
- [x] 再現: 修正前（guard が `=== Disabled` のみ）では pending reset が成功し、
      `assertSessionHasErrors(['two_factor'])` が fail することを先に確認。
- [x] 施策 2 適用後に緑。
- [x] UserFactory の pending state 追加は不要（ヘルパで充足）。

### リスク
- なし（既存テストパターンの複製 + 状態違いのみ）。

---

## 波及変更の総括

| 対象 | 変更要否 | 備考 |
|------|---------|------|
| TS 型 (`types/admin.ts`) | 不要 | union は既に pending を含む |
| DTO (`MemberRowData`) | 不要 | 3 値を既に露出 |
| Controller の props 構築 (`UserManagementController`) | 不要 | 既存 props を再解釈するだけ |
| ルート / API 仕様 | 不要 | endpoint 追加なし |
| UserFactory | 不要 | テストヘルパ `tfeSetTwoFactorState` が pending 生成可 |
| props=pending の Feature テスト | 不要 | 既存 (TwoFactorEnforcementTest L159-172) |

## 検証コマンド

- `pnpm test`（vitest: 施策 T1）
- `pnpm typecheck` / `pnpm lint`（施策 1 の型・lint）
- `composer test`（Pest: 施策 T2、既存 reset テスト回帰）
- `composer phpstan`（施策 2 の型）
- `vendor/bin/pint --test`（施策 2 のフォーマット）

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存ファイル 2 本（Svelte 1 箇所・Controller 1 箇所）の局所修正 + 既存テスト 2 本への追記のみ。新規モジュール・スキーマ・ルートを増やさず、mainの現行構造に増分適用するのが自然。 |
| 競合リスク | 低。`Users.svelte` の `canResetTwoFactor` と `OrganizationMemberController::resetTwoFactor` はいずれも他施策と共有されない局所。テストは末尾追記で衝突しにくい。 |

## 使命・禁止事項チェック（最終）

- 使命寄与: 管理 UX の整合性回復（間接的だが妥当。過大評価しない旨を明記済み）。
- 禁止事項: 4（`response()->json()` 直書き）非該当。7（`redirect()->intended()`）非該当
  （拒否は `ValidationException` → `back()->withErrors`）。1（テストなし完了）は T1/T2 で回避。
- コーディングルール: PHPStan L10 維持（enum 比較のみ）、Factory 経由テスト、DTO パターン非破壊、
  DS token 追加なし、Atomic Design 逆流なし。
