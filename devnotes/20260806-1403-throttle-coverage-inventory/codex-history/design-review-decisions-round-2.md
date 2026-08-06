# 対応マトリクス: design-review Round 2

Critical 0 件 / Warning 3 件。**全件対応**。施策 2・3・4・5・6・8・10 は Round 2 で APPROVE。

## [Warning] 施策 1: 期待値検証が実装骨子に反映されていない
- 判断: **対応する**
- 根拠: 正しい。本文には「期待値が named/inline のどちらにも一致しなければ例外」と書いたが、
  実装骨子は `$entries === []` の分岐で検証せずに `$route->middleware('throttle:'.$limiter)` していた。
  **初回呼び出しでは `6,1,9` のような不正形式を素通しする**非対称な穴になる。
- 対応内容:
  - `private static function assertValidLimiter(string $limiter): void` を追加し、
    **`attachByName()` の冒頭で無条件に呼ぶ** (route 解決・既存 entry の有無より前)。
  - テスト計画に
    「throttle が 1 本も無い route に対しても不正期待値で例外になる」
    「既存 entry の params が `6,1,9` / `Foo Bar` の route へ呼ぶと例外」を追加。

## [Warning] 施策 7: `T_NAME_QUALIFIED` の無条件受理は別クラスを誤合格させる
- 判断: **対応する**
- 根拠: 正しい。名前空間内の `Illuminate\Support\Facades\RateLimiter::for(...)` は
  PHP の解決規則では `App\Foo\Illuminate\Support\Facades\RateLimiter` を指し、Laravel の Facade ではない。
  無条件受理は deny-by-default の**偽グリーン**を作る (最悪の失敗モード)。
- 対応内容: `T_NAME_QUALIFIED` は**受理せず `unresolved` に入れる**。
  受理するのは (a) 完全修飾 `\Illuminate\Support\Facades\RateLimiter`、
  (b) `use Illuminate\Support\Facades\RateLimiter;` 済みの短縮名 `RateLimiter` の 2 通りのみ。
  「グローバル名前空間のときだけ受理」という緩和案も検討したが、
  規約を「完全修飾か `use` 済み短縮名のどちらかで書く」に倒すほうが実装も規約も単純なため採らない。
  単体テストの該当ケースも「名前空間内の qualified name は unresolved」に変更。

## [Warning] 施策 9: 異常入力の「同一 IP bucket」要件が実装と矛盾する
- 判断: **対応する**
- 根拠: 正しい。極端に長い文字列も有効な `string` なので `EmailHash::compute()` が計算され、
  配列 / 空文字の `anon` bucket とは**別の bucket**になる。
  「配列 / 空文字 / 長大文字列がすべて同一 IP bucket」という記述は実装と矛盾していた。
- 対応内容: 契約を 3 つに分ける。
  1. 配列 / 空文字 → `anon` fallback として同じ bucket を消費する
  2. 極端に長い文字列 → 500 にならず、同一値の反復では同じ bucket を消費する
  3. 認証フォーム 2 段 limiter → 異なる異常文字列でも **IP レーンは共有**する
     (IP-email レーンが値ごとに分かれるのは正しい挙動)
