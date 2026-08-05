# 対応マトリクス: impl-review Round 1

## [Critical] `Gate::forUser(...)->authorize` の検出で `authorize` 直後の `(` を確認していない

- 判断: **対応する**
- 根拠: 指摘のとおり `Gate::forUser($user)->authorize;` が合格する。
  deny-by-default gate における誤合格は最悪の失敗モードであり、実際に再現した。
  (d-1) の `Gate::authorize(` 側は元から `(` を要求していたが、(d-2) だけ抜けていた。
- 対応内容: `AuthorizationMarkerScanner::authorizationMarkerOffset()` の (d-2) 判定に
  `($tokens[$close + 3] ?? '') === '('` を追加。

## [Critical] `guardMarkerOffset()` が最初の guard 位置しか返さず、2 段 guard の部分的な後置を見逃す

- 判断: **対応する**
- 根拠: `ItemController::update/destroy` は `resolveOrganizationProject` +
  `resolveProjectItem` の 2 段 guard であり、2 段目だけが `Gate` の後ろに移動した壊れ方が
  合格していた。これは本設計の中心不変条件 (層 2 → 層 3 の順序) の直接的な穴。
- 対応内容: API を `guardMarkerOffsets(): list<int>` (全件返却) に変更し、
  `ControllerAuthorizationGateTest` 側で `max($guardOffsets) > $authOffset` を違反とした。
  単数版は残さない (後方互換の並走を作らない)。
  実証: `ItemController::destroy` の 2 段目 guard だけを `Gate` の後ろへ移動したところ
  修正後の gate が fail することを実測 (修正前は合格していたパターン)。

## [Critical] 上記 2 点を固定する negative test が不足

- 判断: **対応する**
- 対応内容: `AuthorizationMarkerScannerTest` に 2 本追加 (合計 21 tests)。
  - 「authorize を呼んでいない (末尾の括弧が無い) 記述は認可マーカーにならない」
    (`->authorize;` / `Gate::authorize;` / `->authorize::class`)
  - 「guard が 2 段ある場合は全件を返す (片方だけ認可より後ろでも検出できる)」

## [Warning] middleware 順序テストが `api-key.ability:* < api.project-in-org` を検証していない

- 判断: **対応する**
- 根拠: 設計書の順序契約は 4 項 (`resolve.api-actor < api-key.ability:* <
  api.project-in-org < idempotent`) だが、テストは 3 項しか固定していなかった。
  ability 判定より先にテナント境界の 404 が返ると `insufficient_ability` の
  エラー契約が route ごとにぶれる。
- 対応内容: `api-key.ability:` を prefix 一致で探して index 比較する判定を追加。
  実証: `api.project-in-org` を ability より前へ動かして fail することを実測。

## [Suggestion] 順序契約コメントの表現が逆

- 判断: **対応する**
- 対応内容: 「破られる契約 / 起きること」の見出しに整理し、
  「idempotent が api.project-in-org **より前**」と条件側を正しく書き直した。

## [Suggestion] `{item}` の 404 body 同一性テストを足す

- 判断: **対応する**
- 根拠: `scopeBindings()` 化で `{item}` の解決経路が変わったため、
  「この project には無いが item 自体は存在する」という新しい識別差分が
  生まれていないことを直接証明する価値がある。
- 対応内容: 「{item} も cross-project / cross-org / 不在 で完全に同一の 404 応答」を追加。
  cross-project item / 不在 item id / cross-org project+item の 3 応答が
  status と body の両方で一致することを assert する。

## [Suggestion] `viewerApiKey()` / `apiBearer()` の名前が汎用的

- 判断: **対応する**
- 根拠: Pest の global 関数は再宣言できず、他ファイルとの衝突は fatal error になる。
- 対応内容: `itemAuthorizationViewerApiKey()` / `itemAuthorizationBearer()` に改名。
