# 対応マトリクス: impl-review Round 3 (レビュー上限ラウンド)

## [Critical] `$this` を式へ渡して container を取り出す経路 (`get_object_vars($this)['app']->bind(…)`)

- 判断: **対応する**
- 根拠: 指摘は正しい。`$this->…` の並びに現れないため Round 2 で足した動的メンバ検査にも掛からず、
  既存 fake を concrete に使えば 3-10 も変わらないので **inventory 未登録の差し替えを無検出で追加できる**。
  mutation M10 を当てて修正前に素通りすることを確認した。
- 対応内容: Codex の提案どおり「container 取得 API を個別に列挙する」のではなく、
  **閉じた文法から外れる形を fail-closed で拒否する**方向で `disallowedIndirectAccess()` を拡張した。
  1. `$this` は **`$this-><静的なメンバ名>` の形でのみ**許す
     (Round 2 で足した動的メンバ検査を包含し、`$this` を式へ渡す経路も同時に閉じる)。
  2. **変数 callable の呼び出し** (`$factory()`) を禁止 (呼び先を静的に判定できない)。
  3. **式の即時呼び出し** (`('app')()` / `(fn () => …)()`) を禁止 (同上)。
- Unit テストに 5-23 (3 形の negative) を追加。mutation M10 を追加し **3-9 が赤くなる**ことを実走で確認した。
  現行 provider は 3 形のいずれも使っていないため 3-9 は緑のまま (誤検出なし)。

## 併せて明文化した限界 (Claude 側の判断。指摘ではない)

Round 1 → 2 → 3 の指摘は「alias → 動的プロパティ → 未分類の式」と、
**字句解析器に対する回避手段の系列**になっている。PHP は reflection / `eval` / `Closure::bind` 等で
任意に container へ到達できるため、**字句解析だけで「絶対に抜けられない」ことは原理的に示せない**。
毎ラウンド 1 件ずつ塞ぐことは可能だが、それを「完全性」と呼ぶのは誤りなので、

- `FakeWiringSourceScanner` の docblock ★限界
- `devnotes/.../mutation-evidence.md`

の 2 箇所に「**敵対的回避に対する完全性は主張しない。守る対象は通常の実装作業で起きるドリフト**。
新しい抜け道は Unit テストにケースを足して文法を狭める (allowlist を広げない)」を明記した。
指摘 3 件をすべて塞いだうえで、この線引きを文書に残すことを本 TODO の到達点とする。

## レビュー打ち切りの理由

実装レビューは**上限 3 ラウンド** (タスク指定)。Round 3 の Critical は上記のとおり全件反映済みだが、
**反映後の再判定 (Round 4) は実施していない**。

## 検証

- `composer test -- --testsuite=Unit --filter=FakeWiringSourceScanner`: 23 passed / 0 failed (22 → 23)
- `composer test -- --testsuite=Architecture`: 381 passed / 0 failed
- mutation M10: `--testsuite=Architecture` で 3-9 が赤 → revert 後は全緑
- `vendor/bin/pint --test`: passed
