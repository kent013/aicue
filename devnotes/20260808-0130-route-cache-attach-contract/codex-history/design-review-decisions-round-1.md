# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED**。施策 1 / 3 / 4 が REQUEST_CHANGES、2 / 5 / 6 / 7 は APPROVE。

## [Warning] 施策 1: `attachOnBooted()` が cached 起動でも `$specResolver()` を呼ぶ

- 判断: **対応する（ただし Codex 案より強い形にする）**
- 根拠: 指摘は正しい。「後付け入口を binder に集約して T120 を構造的に防ぐ」と主張するなら、
  cached 起動で **resolver 実行にも到達しない**のが契約でなければならない。
  ただし Codex の修正案（booted closure の中で早期 return し、`attachAll(..., false)` を
  リテラルで渡す）は、**保証を配線側 closure に置く**ため、純粋関数だけを見ても
  「resolver が呼ばれない」ことを検証できない（Application stub が要る = 施策 4 の指摘に繋がる）。
- 対応内容: `attachAll()` の第 2 引数を **`callable(): array` に変える**。
  `attachAll(Router $router, callable $specResolver, bool $routesAreCached)` が
  `$routesAreCached` のとき **resolver を呼ぶ前に** early return する。
  こうすると:
  1. `attachOnBooted()` は resolver をそのまま渡すだけになり、**構造的に前倒し評価できない**。
  2. 「cached では resolver が呼ばれない」ことが**純粋関数のテストで直接固定できる**
     （Application stub 不要。施策 4 の Warning も同時に解消する）。
  `RouteThrottleBinder::attachAll()` は array のままで揃わなくなるが、あちらは
  spec に副作用がなく（定数表）resolver を持たないため、無理に合わせない
  （思考原則 4: 似ているからで統合しない）。docblock に差分の理由を書く。

## [Warning] 施策 3: `RouteMiddlewareBinder` の import 追加が明記されていない

- 判断: **対応する**
- 根拠: 実装時に未解決クラスで落ちる。設計書の記述漏れ。
- 対応内容: 施策 3 に `use App\Support\Http\RouteMiddlewareBinder;` /
  `use Laravel\Fortify\Features;` の追加を明記した。施策 2 側も同様に明記した。

## [Warning] 施策 4: T120 恒久回帰テストが `attachAll()` 直叩きに寄っている

- 判断: **対応する（方式は変更）**
- 根拠: 指摘の本質は「実リスクは配線側にある」。上記の型変更（resolver を `attachAll` が受ける）
  により、**実リスクそのものが純粋関数の中へ移る**ため、Application stub を作らずに
  同じ保証が取れる。stub を増やさない分だけ単純になる（禁止事項 6）。
- 対応内容: 施策 4 のテスト #1 を
  「`routesAreCached: true` のとき、**呼ばれたら throw する resolver** を渡しても
  例外が出ず middleware も増えない」に差し替えた。あわせて #1b として
  「`attachOnBooted()` は resolver を**そのまま**渡す（配線点で先に呼ばない）」ことを
  型シグネチャで担保している旨を設計に明記した。

## [Suggestion] 施策 2 / 3: `static fn (): array` より first-class callable を使え

- 判断: **対応する**
- 根拠: `self::recentAuthRouteSpecs(...)` は元メソッドの `@return array<string, list<string>>`
  をそのまま持ち込むため、level 10 で iterable value type 不足を指摘される余地が消える。
  記述も短い。
- 対応内容: 施策 2 / 3 の呼び出しを first-class callable 記法へ変更した。

## [Suggestion] 施策 5: false positive が出たら `token_get_all()` でコメント除外に寄せればよい

- 判断: **見送る**
- 根拠: Codex 自身が「現時点で先回りする必要はない」と書いている。思考原則 2。
  ただし**現時点で false positive が出ないこと**は確認済み
  （`getByName(` / `refreshNameLookups(` の出現は `app/` 全体で 3 ファイル・7 箇所のみで、
  すべて実コード）。設計にその実測を残して、将来の判断材料にする。
- 対応内容: 施策 5 に実測（7 箇所・3 ファイル）を追記した。

## [Suggestion] 施策 6: 「cached 起動では named route を 1 本も解決できない」に “binder callback が走る時点では” を補え

- 判断: **対応する**
- 根拠: 正確性の指摘として妥当。compiled routes は**後で**読まれるので、
  「絶対に存在しない」と読ませると次の誤読を生む（本設計が潰したい failure mode そのもの）。
- 対応内容: §7c の文面と `RouteMiddlewareBinder` の docblock の両方を
  「**この callback が走る時点では**」を含む表現に修正した。

## [Suggestion] 施策 2 の feature flag 対応付けは正しい / 施策 5・6・7 は妥当

- 判断: **見送る（変更なし）**
- 根拠: 元設計の結論と一致。
