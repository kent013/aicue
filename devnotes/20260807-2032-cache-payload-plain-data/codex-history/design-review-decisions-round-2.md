# 対応マトリクス: design-review Round 2

全体判定は **CHANGES_REQUESTED**（S1 に Critical 1 / Warning 2 / Suggestion 1、S2 に Warning 1 / Suggestion 1）。
全件を反映した。反論はゼロ。

## [Critical] S1-1: `\cache([...], 60)`（完全修飾ヘルパ）が素通りする

- 判断: **対応する**
- 根拠: 指摘のとおり。`\cache(...)` は `T_NAME_FULLY_QUALIFIED` で `text === '\cache'` になるため、
  `strtolower($token->text) === 'cache'` の比較に一致しない。Round 2 で塞いだはずの
  `cache($values, 60)` を `\` 一文字で回避できる = 同等機能の bypass であり Critical に同意。
- 対応内容: 呼び出し名の判定を「先頭 `\` を落とし、名前空間を含まないルート関数だけを対象にする」
  形に変更した（`$callable = strtolower(ltrim($token->text, '\\'))` / `$isRootCallable`）。
  `cache` だけでなく `app` / `resolve` / `make` にも同じ正規化が掛かる（`$lower` を共通化しているため）。
  恒久 fixture を 2 本追加:
  - 「完全修飾のヘルパ / コンテナ呼び出しも検出する」（`\cache([...])` / `\cache($values)` /
    `\app(Repository::class)->put` / `\app('cache')->forever` = writes 3 / unclassified 1）
  - 「名前空間付きの同名関数はヘルパと見なさない」（`\App\Support\cache($values, 60)` = 検出しない。
    正規化が**過剰検出**に振れていないことの固定）
  mutation は M13 を差し替えて `\cache(['k' => new stdClass], 60)` を注入する形にした。

## [Warning] S1-2: M13 の期待結果が分類ロジックと一致しない

- 判断: **対応する**（Codex の代替案のうち「M13 を削除」を採る）
- 根拠: 指摘のとおり、`getstore` を CHAIN から外すと `Cache::getStore()` は unclassified になり
  検査 1 が赤になる。「緑に戻る」は誤り。さらに、この分類の退行は
  負のコントロール fixture（期待 writes 5 件）が**恒久的に**固定しているので、
  一度きりの mutation を残す必要が無い（思考原則 2）。
- 対応内容: 旧 M13 を削除し、mutation 表の締めの文を
  「分類の退行は fixture が恒久的に固定する（例: `getstore` を CHAIN から外すと期待件数 5 が 4 になって落ちる）」
  に書き換えた。空いた M13 は上記 Critical の完全修飾ヘルパ mutation に充てた。

## [Warning] S1-3: docblock 型の受け手についての保証範囲が実装より広い

- 判断: **対応する**
- 根拠: 指摘のとおり。`/** @var \Illuminate\Contracts\Cache\Repository $c */ $c->put(...)` は
  import も型宣言も無ければ `receiverNames` にも L3 surface にも入らない。
  「これも L3 で面としては捕まる」は成立しない。**誤情報の訂正が主目的の設計で
  自分が誇張した保証を書くのは本末転倒**であり、正確に書き直す。
- 対応内容: 冒頭コメントの「保証しないもの」を
  「同じファイルに対応する型の `use` があれば L3 には現れるが、完全修飾 docblock だけの形は
  L3 でも捕まらない。docblock 解析は行わない（実測 0 件）」に訂正した。

## [Suggestion] S1-4: セクション見出しの番号が本文とずれている

- 判断: **対応する**
- 対応内容: fixture 群の見出しを実際の並び（9 本の検査のあと）に合わせて **検査 10-21** に統一し、
  冒頭の保証リストと受入条件のテスト本数（21 テスト）も同期した。

## [Warning] S2-1: `fxSnapshotPlainArray()` にコード上の戻り値 shape が無い

- 判断: **対応する**
- 根拠: PHPStan 適合チェック欄には書いたのにコード例に無く、そのまま実装されると
  level 10 で `array` の要素型が不明になり dataset 代入の推論も揺れる。
- 対応内容: コード例に
  `@return array{rate: float, pair: string, source: string, fetched_at: string}` を明記した。

## [Suggestion] S2-2: テスト計画のケース数が古い

- 判断: **対応する**
- 対応内容: 「計 13 assertion 群」→「dataset 展開後 14 ケース」に更新し、
  解釈不能な `fetched_at` のテストを内訳に加えた。

## S3 / S4 / S5: APPROVE（変更なし）
