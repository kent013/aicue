**全体判定: CHANGES_REQUESTED**

設計の方向性自体は妥当です。復路を往路の `isCaptureNavigable` と共有しない判断、`TextLink` + Lucide + 既存 Props だけで閉じる判断、Browser lane を足さない判断はいずれも筋が通っています。

ただし、テストと文書の契約がまだ設計主張の強さに追いついていません。

**施策 1: APPROVE**

[Suggestion] Svelte 内コメントがやや重いです。判断理由は docs とテスト名で固定できるので、コンポーネント内コメントは「復路は状態で出し分けない」程度に絞る方が保守しやすいです。

[Suggestion] 実装内容は DESIGN / Atomic Design 的に問題ありません。`TextLink` atom、`BookOpen` from `@lucide/svelte`、hex 追加なし、Props 追加なしで整合しています。

**施策 2: REQUEST_CHANGES**

[Warning] 現在の Vitest は「DOM 順」を固定していません。  
設計では「既存を先、新規を後」「タブ順を変えない」と判断していますが、テストは両リンクの存在しか見ていないため、後日リンク順が入れ替わっても緑のままです。

修正案:

```ts
const back = screen.getByRole("link", { name: /一覧へ戻る/ });
const detail = screen.getByRole("link", { name: /マニュアル詳細へ/ });

expect(
    back.compareDocumentPosition(detail) & Node.DOCUMENT_POSITION_FOLLOWING,
).toBeTruthy();
```

[Warning] 1本目は `getByTestId` + `textContent` ではなく、アクセシブルネームで取るべきです。  
文言・a11y を契約にするなら、ユーザーが認識する名前で固定してください。

修正案:

```ts
const link = screen.getByRole("link", { name: /マニュアル詳細へ/ });
expect(link).toHaveAttribute("href", "/projects/1/manuals/5");
```

[Warning] status dataset が文字列配列のままだと、TypeScript strict 下で型が広がる可能性があります。また、将来 status が増えてもテストが自動で気づけません。  
後日「往路の述語で包む」退行は現状でも `draft/analyzing/rendering` で赤くできますが、status 網羅性は弱いです。

修正案: `as const` ではなく、可能なら既存の `Manual['status']` 型または status 定義に `satisfies` させる。

```ts
const statuses = [
    "draft",
    "analyzing",
    "ready",
    "rendering",
    "published",
] as const satisfies readonly ManualStatus[];
```

もし PHP 側 enum に他の状態があるなら、ここにも必ず足してください。

**施策 3: REQUEST_CHANGES**

[Critical] `assertOk()` だけでは「到達条件が同じ」を十分に固定できません。  
将来 middleware や controller が 200 の別 Inertia 画面へ逃がす実装になった場合、テストは通ります。復路の行き先として成立していることを固定するなら、200 だけでなく Inertia component まで assert してください。

修正案:

```php
$this->actingAs($member)
    ->get(route('capture.manuals.show', [$project, $manual]))
    ->assertOk()
    ->assertInertia(fn ($page) => $page->component('Capture/Show'));

$this->actingAs($member)
    ->get(route('projects.manuals.show', [$project, $manual]))
    ->assertOk()
    ->assertInertia(fn ($page) => $page->component('Manuals/Show'));
```

[Warning] `ready` と `rendering` の2状態だけでは、`Capture/Show` に表示され得る全 status の復路保証としては不足です。  
復路は「status に依らず常に出す」という設計なので、Feature 側も `VideoManualStatus::cases()` ベースの dataset にして、全状態で両 route が同じく到達可能であることを固定するべきです。

修正案: `VideoManualStatus::cases()` を dataset 化し、各 status で `capture.manuals.show` と `projects.manuals.show` の両方を assert する。

[Warning] 「同じ middleware 2 本」という説明は不正確です。実際には外側の `auth` / `verified` / `not-pending-deletion`、内側の `require-active-subscription` / `project.in-current-org`、`scopeBindings()`、controller 内の `resolveOrganizationProject()`、`Gate::authorize('view', $manual)` が組み合わさっています。  
修正案: 文書・テストコメントでは「同じ middleware 2本」ではなく、この構成を具体名で書いてください。

**施策 4: REQUEST_CHANGES**

[Warning] 文書追記が現状の到達条件をやや単純化しすぎています。  
「同じ middleware 2 本 + 同じ Gate」ではなく、共通の親 middleware と scope binding、tenant boundary、Gate まで含めて書くべきです。セキュリティ不変条件の文脈では、この省略は危険です。

修正案:

```markdown
復路を無条件にできる根拠は、両 route が共通の auth / verified /
not-pending-deletion 親グループ、require-active-subscription、
project.in-current-org、scopeBindings、controller 内の
resolveOrganizationProject、Gate::authorize('view', $manual) を通ることである。
```

[Warning] 「Vitest で mobile 幅の overflow guard を再確認する」という趣旨の記述は避けてください。jsdom ベースの Vitest は実レイアウト、flex wrap、truncate、実際の overflow を保証できません。  
Browser lane を追加しない判断自体は妥当ですが、レイアウト保証を Vitest に背負わせるのは不正確です。

修正案: 「Browser lane は追加しない。実レイアウトの保証は対象外」と明記するか、既存 Browser lane に自然に相乗りできる場合だけ軽い表示確認を足してください。

**補足判定**

Browser lane を追加しない判断は、リンク1本の DOM 契約 + route 到達性の変更としては妥当です。手抜きではありません。ただし、PWA standalone の挙動や狭幅ヘッダーの実レイアウトを保証しない、という線引きを文書で明確にしてください。

「サーバ側 0 行」は、route / controller / DTO / policy を触らないという意味なら嘘ではありません。ただし Feature test は PHP 側に追加されるため、「アプリサーバ実装コード 0 行」と表現する方が正確です。