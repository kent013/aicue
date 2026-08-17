# 対応マトリクス: impl-review Round 1

## [Warning] remember me の仕様固定が弱い (tests/Feature/Admin/UserManagementPageTest.php)

- 判断: **対応する**
- 根拠: 指摘のとおりである。修正前のテストは「行が 2 件になること」と「props が non-null であること」
  しか見ておらず、**1 回目 (資格情報提示) と 2 回目 (recaller 復元) の時刻が同一**だったため、
  `LastLoginLookup` が recaller 行を除外する実装へ退行しても 1 回目の値が残って緑になった。
  詳細設計の要求は「`lastLoginAt` が 2 回目の時刻になること」であり、修正前は要求を満たしていない。
- 対応内容:
  1. recaller リクエストの前に `$this->travel(30)->minutes()` を入れ、2 回目の `occurred_at` を
     1 回目と**必ず別時刻**にした (直後に `travelBack()`)。
  2. `login` 行 2 件を id 順で取り、`occurred_at` が実際に異なることを先に固定した
     (時刻が動かない環境でこの検査自体が空振りしないようにする)。
  3. props の `lastLoginAt` を **recaller 行の `occurred_at`** と完全一致で比較する形にした
     (`not->toBeNull()` を捨てた)。これで「recaller 行を除外する」退行は必ず赤くなる。

## 検証

- `composer test -- tests/Feature/Admin/`: 34 passed / 0 failed (assertions 216 → 217)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
