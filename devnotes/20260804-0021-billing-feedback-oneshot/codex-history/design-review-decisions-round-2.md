# 対応マトリクス: design-review Round 2

## [Warning] (施策3) `?portal` はキー存在だけで `PortalReturned` になる (値検証がない)
- 判断: **対応する**
- 根拠: 指摘のとおり非対称だった。Round 1 で `session_id` には
  「canonical 判定 (キー存在) と kind 判定 (値の妥当性) の分離」を入れたのに、
  `portal` は分離していなかった。`portal()` が Stripe に渡す return_url は
  `route('billing.index', ['portal' => 1])` の **1 形だけ**なので、
  それ以外の値で状態 (「お支払い管理画面から戻りました」) を主張する理由がない。
  fail-closed の一貫性を優先する。
- 対応内容: kind 解決を
  `$isPortalReturn => $request->query('portal') === '1' ? BillingFeedbackKind::PortalReturned : null`
  に変更。キーが存在すれば 303 で畳む点は不変 (query を残さない)。
  既存テストは `?portal=1` を使っているため回帰しない。

## [Warning] (施策7) T6 に portal の不正値ケースを追加
- 判断: **対応する**
- 対応内容: T6 の dataset に `?portal`(値なし) / `?portal=forged` / `?portal[]=x` を追加し、
  303 + `assertRedirect('/billing')` + `assertSessionMissing(FLASH_KEY)` を固定。
  正常系 `?portal=1` は T4 が担当することを明記 (T4 の見出しも `?portal=1` に限定した)。

## その他 (施策 1 / 2 / 4 / 5 / 6): APPROVE
- 現状維持。
