# 対応マトリクス: impl-review Round 1

## [Critical] `0.0.0.0/0` / `::/0` が production でも通過する (TrustedProxyToken / TrustedProxiesConfigValidator / TrustedProxyTokenTest)

- 判断: **対応する**
- 根拠: 完全に正しい指摘。prefix 長 0 の CIDR は全アドレスを含むため `*` と意味的に同値であり、
  「`*` を禁止する」という High-2 の是正が迂回できる。しかも書式としては正当な CIDR なので
  `filter_var` + prefix 範囲チェックだけの書式検査では素通りする。
  Unit テストで `0.0.0.0/0` を **valid として固定していた**ため、偽グリーンでもあった
  (「テストが不変条件を落とせるか」の観点で最も悪い形)。
- 対応内容:
  - `TrustedProxyToken::isAllAddresses(string): bool` を新設。
    `*` / `**` に加え、**valid CIDR かつ prefix が 0** のものを全アドレス等価と判定する
    (`0.0.0.0/0` / `::/0` / 完全展開表記の `0000:...:0000/0` を含む)。
  - `isTrustableAddress()` の先頭で `isAllAddresses()` を弾く
    → **どの環境でも** framework に渡らない (fail-secure)。local/dev でも `0.0.0.0/0` は無効。
  - `TrustedProxiesConfigValidator` の検査 1 を `['*','**']` の in_array から
    `isAllAddresses()` 走査へ置換 → production では専用メッセージ
    ("Trusting every address lets clients forge X-Forwarded-For…") で reject。
    判定を `TrustedProxyToken` に一本化したので config 段と validator 段のズレも起きない。
  - テスト:
    - `TrustedProxyTokenTest`: `0.0.0.0/0` を valid リストから削除し **invalid リストへ移動**。
      `::/0` / 完全展開表記も追加。`isAllAddresses` の正/負データセットを新設。
    - `TrustedProxiesConfigValidatorTest`: 検査 1 のデータセットに `0.0.0.0/0` / `::/0` を追加。
      さらに「実 hop と併記していても reject」ケースを追加 (最優先で落ちることの固定)。

## [Warning] ResponseSignature が ETag / Last-Modified まで volatile 除外している

- 判断: **対応する**
- 根拠: 妥当。設計で除外を意図していたのは「連続リクエストで必ず差分が出る」ヘッダだけで、
  `ETag` / `Last-Modified` は**リソース内容・更新時刻に由来する安定した差分**になりうる。
  存在オラクル検査の本体は「2 応答が観測上同一か」なので、安定差分を除外すると検査が空洞化する。
- 対応内容: `VOLATILE_EXACT` から `etag` / `last-modified` を削除し比較対象に戻した。
  `expires` / `age` は「現在時刻から導出される値」で連続リクエストでは必ずズレるため除外を維持し、
  その区別の理由をコメントに明記した。除外を戻しても全テスト green (偽陽性は出ていない)。

## [Warning] pre-binding 静的検査が `$request->route($param)` (変数引数) を見逃す

- 判断: **対応する**
- 根拠: 妥当。deny-by-default テストとしては「文字列リテラルで書いた場合だけ落ちる」のは弱い。
  現在の登録対象には該当が無いが、このテストの目的は**将来の追加**を落とすことなので、
  検出パターンが書き方に依存するのは設計意図に反する。
- 対応内容: 禁止パターンに `->route($` を追加。
  `ThrottleRequests` の `$route = $request->route()` (引数なし = Route オブジェクト取得)
  は引き続き通る = 意図した区別を保っている (Route の `getDomain()` は URL 上の id と無関係)。

## 検証

- `composer test`: **2992 passed / 0 failed / 2 skipped** (2994 tests, 11766 assertions)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
