# 対応マトリクス: conceptual-review Round 1

## [Critical] `AuthViewRenderOnly` のカテゴリ定義が緩すぎる (`social.redirect` を含めている)

- 判断: **対応する**
- 根拠: 指摘のとおり。`social.redirect` は OAuth state を session に生成して外部 IdP へ
  遷移させる**認証フローの開始**であり、「描画にすぎない」ではない。同じ箱に入れると
  「GET だが認証フローを開始する route」を将来まとめて免除する穴になる。
  enum の docblock 自身が「汎用に見えるものほど適用条件を狭く」と定めているので、
  この指摘は既存設計の思想と一致している。
- 対応内容: 新 case を 2 つに分割 (§4-3)。
  - `AuthViewRenderOnly` (13 本) — 描画のみ
  - `AuthFlowInitiationWithoutOutboundCall` (`social.redirect` 1 本のみ) —
    適用条件に「**対になる完了経路が throttle 済みであること**」を入れ、
    `social.callback` の throttle に構造的に依存させた。
    この依存は `ThrottleExemptionPremiseTest` で behavioral に固定する
    (callback の throttle を外すと exemption の前提が崩れて fail する)。

## [Warning] behavioral proof が「外部 HTTP / Mail なし」に偏っている (4 条件のうち 1 つしか固定していない)

- 判断: **対応する**
- 根拠: deny-by-default の最悪失敗は「前提が崩れたのに inventory は通り続ける」こと。
  4 条件のうち機械化できるものは機械化すべき。
- 対応内容: §4-5 を新設し、検査を 3 点追加。
  1. exemption inventory の key は **throttle を 1 本も持たない**こと
     (指摘の「throttle と exemption の二重登録」。現行は `count($entries)===1` で
      先に continue するため検出できない構造的な穴だった)
  2. 新 2 case を使う entry は **非変更系 (GET/HEAD のみ)** であること
  3. premise テストで **DB 書込 0 件** を追加 (read は許す。理由も明記)

## [Warning] `social.callback` の閾値根拠が「passkeys guest と同値だから」だけでは弱い / 監視・緊急緩和が無い

- 判断: **対応する** (ただし閾値そのものは変えない)
- 根拠: 閾値変更は `AG-096` が「プロダクト依存」と裁定しており、エージェント判断で
  動かさない。一方「巻き添えが起こりうる」ことを設計に書かずに済ませるのは
  前周回の Warning (落としたことを書かなかった) と同じ失敗になる。
- 対応内容: §10-1「未認証 IP レーン (10/min) の巻き添えリスクをどう扱うか」を新設。
  - 起こりうることを正直に認めた上で、詰みにならない根拠 (入口ページは throttle しない /
    1 分で解ける / 一回性操作) を明示
  - 監視項目 (429 発生率) を運用要件として明記
  - **初動は閾値を上げることではない**(まず `TRUSTED_PROXIES` / 実 client IP の解決を疑う)
    という順序を明記

## [Warning] `social.redirect` を exemption にする理由が楽観的 (カテゴリ名と実態のズレ)

- 判断: **対応する** (Critical と同一の対応)
- 根拠: 同上。
- 対応内容: `AuthFlowInitiationWithoutOutboundCall` へ分離。
  「throttle を貼らない」判断自体は維持する (§5 案 5 の根拠 = 外向き HTTP の総量は
  callback 側で有界化されるため redirect を絞っても減らない)。

## [Warning] `two-factor.*` GET への `10,1` が「秘密 GET の保護は済んだ」と誤読されうる

- 判断: **対応する**
- 根拠: 後続 TODO B2 (recent-auth 化) が静かに落ちる失敗モードは実在する。
- 対応内容: §4-2 に「誤読防止 (必須)」を追記。付与箇所の docblock と behavioral テスト名の
  両方に「回数上限であって認証強度ではない / 認証強度は B2」と明記することを設計要件にした。
  ※ Architecture テストや TODO 台帳への機械参照までは作らない
  (B2 は既に `aicue:T120` の後続として台帳にあり、二重管理になる = 思考原則 2)。

## [Warning] cap 14 → 26 が「25 + 1」で、cap だけでは形骸化を防げない

- 判断: **対応する**
- 根拠: 指摘のとおり全体 cap だけでは「どのカテゴリが膨らんだか」が見えない。
  新カテゴリ 13 件が将来 30 件になっても、全体 cap の一言でしか止まらない。
- 対応内容: §4-4 に **case 別上限マップ** (`throttleCoverageExemptionCapByCase()`) を追加。
  既存 6 case は現状値でほぼ固定し (署名短絡だけ +1)、
  `auth_view_render_only` = 14 / `auth_flow_initiation_without_outbound_call` = 1 とした。
  全体 cap は `array_sum()` にせず独立の 26 として両方検査する
  (全体 = セレクタの広さ、case 別 = 分類の偏り。役割が違う)。

## [Suggestion] limiter closure の戻り値型・nullability を明示する

- 判断: **対応する**
- 対応内容: §4-2 に「limiter closure の型」を追記
  (`fn (Request $request): Limit` + `$request->ip() ?? 'unknown'`。既存 limiter と同じ書き方)。

## [Suggestion] 使命への位置づけは妥当

- 判断: **見送る** (変更不要)
