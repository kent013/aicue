# 対応マトリクス: design-review Round 3

## 施策 3 [Critical] `joinOrganization` のメソッド宣言も呼び出しとして抽出されてしまう
- 判断: **対応する**（指摘のとおり。`private function joinOrganization(` が
  「`T_STRING` + 次が `(`」に一致し、後方が `->` `$this` でないため必ず fail する）
- 対応内容: 抽出手順に段階 2 を挿入し、
  **直前の有意トークンが `T_FUNCTION` のものはメソッド宣言として skip** する、と明記した
  （手順は 3 段 → 4 段になった）。
  あわせて空振り防止を「3 件未満なら fail」から **exact-fit（`expect($callCount)->toBe(3)`）** へ変更。
  「未満なら fail」ではセレクタ崩壊しか検出できず**呼び出し元の増加が素通り**するため。
  exact-fit なら次の 1 本が必ず数値を変える差分として現れ、
  「その経路でも `false` を正しく消費しているか」の再レビューを強制できる
  （`ThrottleCoverageInventoryTest` の exemption cap と同じ流儀）。

## 施策 3 [Warning] `DB::beforeExecuting()` の説明とサンプルコードが一致していない
- 判断: **対応する**（説明を弱めるのではなく、**bindings で対象 id を判定する実装に揃える**）
- 根拠: 指摘のとおり、サンプルはテーブル名と `for update` しか見ておらず、
  かつ id は placeholder になるため SQL 文字列だけでは対象 id を判定できない。
- 対応内容: callback の引数に `array $bindings` を追加し、
  **(a) `organization_invitations` を対象 (b) `for update` を含む (c) bindings に対象 invitation id を含む**
  の 3 条件すべてで発火する実装へサンプルを書き直した（説明文も同じ 3 条件に揃えた）。
  Codex の代案「説明を『登録後最初の招待行 FOR UPDATE に発火する』へ弱める」は採らない —
  同一テスト内に複数の招待を置くテストを将来書いたときに誤爆するため、
  条件を実装側で厳密にする方が安全。
  加えて「条件に一致するクエリが 1 度も来なければ helper は何もせず、
  結果としてテストは `false` 分岐に入らなかったことで**明示的に fail する**」ことを明記した
  （黙って green にならない）。
