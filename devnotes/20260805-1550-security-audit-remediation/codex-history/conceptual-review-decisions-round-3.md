# 対応マトリクス: conceptual-review Round 3

## [Critical] S1 と S2 で実行順が矛盾している

- 判断: 対応する (指摘どおり。v3 の S1 が改訂漏れ)
- 対応内容: 設計全体で**唯一の順序契約**に統一した:
  `Authenticate → Throttle → ResolveApiActor → SubstituteBindings
   → api.project-in-org → api-key.ability:* → idempotent → controller`
  S1 の順序表・箇条書き、S4 のテスト計画、API 影響評価をすべてこの 1 本に揃え、
  「この順序は設計全体で同一の 1 本を正本とする」と明記した。

## [Critical] API 影響評価の「唯一の変化」が成立しない

- 判断: 対応する (指摘どおり)
- 対応内容: API 影響セクションを actor 状態別に (a) 解決成功 / (b) 解決失敗 の 2 表に分割し、
  「唯一の変化」という表現を撤回。
  (b) では**不在 id の応答が 404 → 401/403 に変わる**ことを明記した。
  これはエラー優先順位の変更であり、
  「無効なトークンにはリソースの話をする前に 401/403 を返す」= `Authenticate` と同じ優先順位への是正
  と位置づけたうえで、Feature テストに
  API キー失効 / 発行者削除 / OAuth トークン失効 / CLI セッション失効 / membership 剥奪 の
  5 状態 × (自組織 / 他組織実在 / 不在) を個別登録する項目を追加した。

## [Warning] exemption 機構が不変条件を緩める入口になる。0 件なら機構自体を作らない方が規約に忠実

- 判断: 対応する (指摘に全面同意)
- 根拠: 登録件数 0 のまま機構だけ作るのは AGENTS.md 思考原則 2「今必要なものだけ作る」に反し、
  かつ「理由を書けば通る」抜け道を最初から用意することになる。
- 対応内容: S4 から exemption inventory を削除。**違反は無条件 fail**。
  将来やむを得ない例外が生じたら、その時点で設計判断としてテストを変更する
  (黙って inventory に 1 行足す運用にしない) と明記。

## [Warning] 相対挿入 API を重ねた結果を実 route で固定する必要がある

- 判断: 対応する
- 対応内容: S4-8 を「解決済み middleware 列の**完全な順序**」を固定する形に強化し、
  検証対象 route を具体名で列挙 (API write / read group、`{project}` 無しの同一 group route、
  web の `projects.update`、capture、guard を持たない web route)。
  API キー actor と OAuth actor の**両方**で列全体を検証する項目を追加した。

## [Warning] `ResolveApiActor` 前倒しの無副作用証明が不足

- 判断: 対応する
- 根拠: 実装を再確認したところ、OAuth 経路には `$session->touchLastUsedAt()`
  (`ResolveApiActor:159`) という**書き込み副作用**が実在する。
  前倒しにより「不在 id のリクエストでも last_used_at が更新される」変化が起きる。
- 対応内容: API 影響セクションに副作用の節を追加し、
  詳細設計で「DB 書き込み / イベント発火 / 監査記録 / 例外形式」を 1 件ずつ洗い出すこと、
  不在 id リクエストでテナント越えの副作用が発生しないことをテストで固定することを明記した。

## [Warning] runbook の「運用者記入」が未完のまま実装完了扱いにならないよう機械検出せよ

- 判断: 対応する
- 対応内容: runbook の運用者記入欄に固定 placeholder トークンを置き、
  Architecture テストが「placeholder が残っていたら fail」させる方式を S5 に追加した。

## [Warning] 「route parameter を 1 つ以上持つ全 route」を 1 モードに畳むのは概念が広すぎる

- 判断: 対応する (指摘どおり)
- 対応内容: inventory の値を **parameter 名 → モードの map** に変更 (route 単位から parameter 単位へ)。
  モード集合に `NonResourceParameter` (`{provider}` / `{intent}` / `{token}` / ページ番号等) と
  `PublicGlobalResource` を追加し、テナント防御モードとは別グループであることを enum の docblock に記す。

## [Warning] S7 の直接記録 case は map と型だけでは保証できない

- 判断: 対応する
- 対応内容: Architecture テストの検査項目に
  「直接記録 case の宣言クラスが実在し `SecurityEventRecorder` を参照していること」を追加し、
  さらに**全 case について Feature テストで行が増えることを固定**する方針に変更。
  既存テストで担保済みの case は担保先を map の備考欄に記載し、重複実装しない。

## [Suggestion] 使命との整合 / 型安全性

- 判断: 反映済み (変更なし)
