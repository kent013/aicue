# 対応マトリクス: conceptual-review Round 3

## [Critical] `make()` の無制限許可で配線を別クラスへ逃がせる
- 判断: **対応する** (指摘は正しい。`CannedPromptFakeRegistrar` が現に委譲パターンなので
  「非現実的な抜け道」ではない)
- 対応内容: 条件 1 を「method 名 allowlist」から「**呼び出し形 (method 名 + 引数) の allowlist**」へ
  強化。表で以下 3 形のみ許可:
  - `$this->app->bind(A::class, B::class)` (条件 2 で集合一致)
  - `$this->app->make(X::class)` — **X は `FakeStorageGate` / `CannedPromptFakeRegistrar` のみ**
    (どちらも container 配線を行わないことを理由付きで分類済み)
  - `$this->app->environment()` — 引数なしの直接呼び出しのみ
  対象を増やす場合は「container 配線を行わない」ことを理由付きで分類してから足す、と明記。

## [Critical] fake 配置規約が現行クラスと矛盾する (実装直後の main が赤になる)
- 判断: **対応する** (実査で裏取り: `Fake` 命名で `Fakes/`・`Testing/` の外にあるのは
  `FakeExternalsServiceProvider` と `FakeStorageGate` の**ちょうど 2 件**)
- 対応内容: 施策 2 を定義から書き直した。
  - **fake 実装クラス** = `app/**/Fakes/` + `app/**/Testing/` 配下の全クラス
  - **fake 命名クラス** = `app/` 配下でクラス名が `Fake` 始まり / `Fake` 終わり
  - 検査 2-a (配置規約): 「fake 命名クラス」⊆「fake 実装クラス」∪ **理由付き例外 2 件**
    (`FakeExternalsServiceProvider` = 唯一の配線 provider /
     `FakeStorageGate` = gate predicate)
  - 施策 1 条件 3 の明示例外も 2 件 → **4 件**へ整合 (上記に加え
    `Put/GetFakeStorageObjectController`。実査で provider の参照は inventory 5 + 例外 4 =
    ちょうど 9 件と確認済み)

## [Warning] `mutationIds` の集合一致は形骸化防止として弱い
- 判断: **対応する**
- 根拠: M3〜M7 は entry ではなく gate 全体に属する mutation。entry に書かせても意味的対応にならない。
- 対応内容: mutation 被覆を **2 層**へ分離。
  - entry mutation (M1 / M2): inventory を回す **data-driven** な real/fake 厳密一致検査が
    全 entry を自動被覆する (entry を足せば検査も自動で増える)
  - gate mutation (M3〜M7): gate ファイルの `MUTATION_COVERAGE` map で管理し、
    **キー集合が `{M3..M7}` と完全一致すること**を 1 test で機械照合
  - inventory からは `mutationIds` を削除し、`risk` (レビュー用説明) のみ残す

## [Suggestion] それ以外のスコープは着手可能
- 判断: **見送る** (現状維持)
