# 対応マトリクス: design-review Round 3

## [Critical] M1: `const int MAX_PAGE = intdiv(...)` はコンパイルできない
- 判断: **対応する (実測で確認)**
- 根拠: 指摘のとおり。開発コンテナの PHP 8.4.24 で実測した:

  ```
  $ php -r 'class A { const int X = intdiv(PHP_INT_MAX, 10); } var_dump(A::X);'
  Fatal error: Constant expression contains invalid operations
  ```

  (純粋な算術式なら定数にできる — `(PHP_INT_MAX - (PHP_INT_MAX % self::PER_PAGE)) / self::PER_PAGE`
  は `int(922337203685477580)` を返すことも確認したが、読み手に意図が伝わらないので採らない。)
- 対応内容: `public static function maxPage(): int { return intdiv(PHP_INT_MAX, self::PER_PAGE); }`
  へ変更。呼び出し側 (`min(max(1, (int) $pageRaw), self::maxPage())`)、docblock、
  テスト計画の期待値 (`ManualListQuery::maxPage()`) をすべて合わせた。
  「定数ではなくメソッドである理由」を docblock に残し、後から定数へ戻されないようにした。

## [Warning] M9: Factory state が null ケースを表現できない
- 判断: **対応する**
- 根拠: テスト計画に `published + total_length_ms=null` があるのに、state の署名が
  `published(int $totalLengthMs)` では表現できず、計画と実装が食い違っていた。
- 対応内容: `published(?int $totalLengthMs = null)` にし、実際の state 実装を設計へ明記した。

## [Warning] M9: 検証コマンドが AGENTS.md の VERIFICATION_COMMANDS と一致していない
- 判断: **対応する**
- 対応内容: 実装モード表の検証コマンドを 10 本すべてに揃えた
  (`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
  `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages` / `pnpm test:packages`)。

## M2〜M8 (APPROVE 部分)
- 判断: 指摘なし。変更しない。
