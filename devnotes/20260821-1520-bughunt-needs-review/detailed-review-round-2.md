# 施策 D: REQUEST_CHANGES

前回の6件は実質的に解消されています。分岐設計、状態境界、入口の責務分離、回帰範囲、検証計画は妥当です。ただし、テストファーストの記録に事実と矛盾する箇所があり、文書内にも1か所不整合が残っています。

[Warning] テスト #6 は実装前に赤になりません。

現行コードはすでに「未契約 + manageBilling 非保持 → onboarding.billing-required」を実装しています。したがって、新規テスト #6 は変更前から緑になる characterization/regression test です。

現在の以下の記述は成立しません。

- 「#6 も実装前に赤を確認する」
- 「#2/#4/#6/continuation が……赤になったことを記録する」

修正案:

- 実装前に赤を確認する対象を `#2 / #4 / continuation` に限定する。
- #6 は「変更前から緑であることを確認し、実装後も緑を維持する境界回帰テスト」と位置づける。
- テストファーストの証跡には、赤になる仕様変更テストと、最初から緑になる characterization test を分けて記録する。

[Warning] `screens.md` の扱いが乖離台帳節だけ以前の記述のままです。

波及変更と完了条件では「同一 PR で更新」と正しく修正されていますが、乖離台帳節には次の記述が残っています。

> app-update-docs で扱う（実装 TODO のクローズ条件に追跡を明記）

これは「別途追跡する」とも読め、同一 PR 更新方針と不整合です。

修正案:

> `.claude/skills/app-bug-hunt/screens.md` は同一 PR で更新する。テンプレート乖離台帳の登録対象ではない。

のように一本化してください。

[Warning] Inertia assertion のコールバック型を明記してください。

PHPStan level 10 を前提とする詳細設計として、次の無型引数は避けるのが安全です。

```php
fn ($page) => $page->component('Dashboard')
```

修正案として、既存テストの記法に合わせて型を付けます。

```php
use Inertia\Testing\AssertableInertia as Assert;

->assertInertia(
    fn (Assert $page): Assert => $page->component('Dashboard'),
);
```

[Suggestion] continuation テストは、中間リダイレクトも保証するなら段階的に確認すると意図が明瞭です。

- signed verification → `onboarding.checkout`
- `onboarding.checkout` → `dashboard`
- `dashboard` → 200 + Inertia component `Dashboard`

`followingRedirects()` で最終画面だけ確認する場合、「onboarding.checkout 経由」自体は直接保証されません。ただし、既存テストが第一段を固定しているため、新規テストを最終接続だけに限定する設計も許容できます。

# 全体判定: CHANGES_REQUESTED

上記3件を文言・テスト記法へ反映すれば、施策 D は承認可能です。特に #6 の「実装前に赤」は完了不能な条件なので、実装着手前に必ず修正してください。