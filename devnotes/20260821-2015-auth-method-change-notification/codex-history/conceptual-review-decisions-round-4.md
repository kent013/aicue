# 対応マトリクス: conceptual-review Round 4

## [Critical] `PostCommitCallbacks` を singleton にすると rollback 後の callback が残る
- 判断: 対応する
- 根拠: 指摘のとおり。Laravel の `singleton()` はコンテナ (アプリケーション) の寿命であり、
  php-fpm では通常リクエストごとにコンテナ自体が再生成されるため実害は小さいが、Octane 等の
  長寿命 worker では複数リクエストをまたいで同一インスタンスが再利用され得る。また
  rollback 時に collector を明示的に空にしていなかったため、同一プロセス内で後続の何らかの
  処理が誤って古い callback を実行してしまう余地があった。
- 対応内容:
  - container binding を `singleton()` から **`scoped()`** に変更する (Octane 等でも
    リクエストごとに新しいインスタンスへ入れ替わる)。
  - `discard(): void` を追加し、`EnsureLoginMethodRemains::handle()` は
    `DB::transaction()` を try/catch で包み、**例外時 (rollback 時) に `discard()` を呼んでから
    再送出する**。
  - `flush()` は実行前に保持配列を空の配列へ移し (`$pending = $this->callbacks;
    $this->callbacks = [];`)、その後に `$pending` を実行する (2 回呼んでも 2 回目は
    何もしない。callback 実行中の例外が残りの callback や次回 flush に影響しない)。

## [Warning] 汎用的な名前と、実際の flush 境界が一致していない
- 判断: 対応する
- 根拠: `push()` を呼んでよいのは `EnsureLoginMethodRemains` が包む transaction の内側だけであり、
  「アプリ全体の汎用 post-commit 基盤」という主張は実態より広い。AGENTS.md 思考原則 2
  (今必要なものだけ作る) / スキル既定の「機能の名前に立ち返れ」に従う。
- 対応内容: クラス名を `App\Support\PostCommitCallbacks` から
  **`App\Support\Auth\LoginMethodRemovalPostCommitCallbacks`** (既存の `app/Support/Auth/`
  配下に既に `EmailVerificationContinuation` 等の同種クラスがある) へ変更し、
  `EnsureLoginMethodRemains` 専用であることを名前で示す (将来 password 削除 / SSO 解除の
  removal route が同じ middleware に乗ったときは、そのまま同じ collector を使い続ける想定
  であり、「認証手段除去 transaction の post-commit callback」という意味で命名は変えない)。
