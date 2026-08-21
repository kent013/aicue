# 対応マトリクス: design-review Round 3

## 施策 1 [Warning] テスト計画に残った旧説明 (「PHPStan/実行時に未定義キー参照が起きる」)
- 判断: 対応する
- 根拠: 指摘のとおり、リスク節では訂正済みだったがテスト計画節に矛盾する旧説明が
  残っていた。`config()->array()` もこの旧フラグの参照経路ではないため除いた。
- 対応内容: テスト計画の記述をリスク節と同じ正確な説明 (黙って false へ倒れる/
  検出は施策 10 の grep のみ) へ揃え、`config()->array()` の言及を削除した。

## 施策 6 [Critical] `Storage::fake()` が disk 名必須引数でエラーになるという指摘
- 判断: 反論する (エビデンスにより指摘は誤りと判断)
- 根拠: `vendor/laravel/framework/.../Facades/Storage.php` の実シグネチャは
  `public static function fake($disk = null, array $config = [])` であり、`$disk` は
  デフォルト値 `null` を持つ**必須引数ではない**。加えて、変更対象の既存ファイル
  `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` は**既に** `Storage::fake();`
  (引数なし) を 12 箇所で使用しており、これは現在 `composer test` で green のテストである。
  提示したテストコードはこの既存ファイルの直近の並びに追加する 1 テストであり、
  同じ書き方をそのまま使っている。したがって「実行時エラーになる」という指摘は
  このリポジトリの実際の Laravel バージョン・既存テストの実測と矛盾するため、
  コード例は変更しない。
- 対応内容: 詳細設計は変更なし。本対応マトリクスに反論の根拠を記録する。

## 施策 6 [Warning] 文字列連結の Pint 空白指摘
- 判断: 反論する (エビデンスにより指摘は誤りと判断)
- 根拠: 本リポジトリには `pint.json` が存在せず (確認済み)、Laravel Pint の既定設定を
  そのまま使っている。既定設定は `concat_space` フィクサーを有効にしないため、
  `.` の前後に空白を入れない現在の書き方はそのまま Pint 準拠である。実際、
  変更対象ファイル自身 (`tests/Feature/Projects/SourceDocumentUploadOcrTest.php` の
  既存 L282-285) が全く同じ「`.` の前に空白、後ろに空白なし」という書き方を既に持ち、
  これは `vendor/bin/pint --test` を通過している既存コードである。したがって提示した
  コード例の書き方 (既存ファイルと同一) を Pint 非準拠へ変える必要はない。
- 対応内容: 詳細設計は変更なし。本対応マトリクスに反論の根拠を記録する。
