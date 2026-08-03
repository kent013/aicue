# 対応マトリクス: conceptual-review Round 3

Round 3 は **Critical なし / Warning 6**。全て対応する。

## [Warning] 2-1 / 4-1: B-1 の fail は H-a 固有の証拠ではない (テスト条件由来の fail と区別せよ)

- 判断: **対応する**
- 根拠: 妥当。判定表が「fail → H-a」と短絡していた。
- 対応内容: 判定表を 3 値に分ける。
  - 「制御条件を満たしたうえで一度も可視にならない」= H-a を支持 → B-2 適用。
    制御条件 = (i) レスポンスに flash が存在、(ii) 着地ページが mount 済み、
    (iii) リダイレクト完了 → assertion 開始が 4 秒未満。
  - 「その他の fail」= 原因判定不能 → **B-2 を適用せず、テスト条件を調査する**。
  - 「pass」= 自動テスト条件では未再現。

## [Warning] 3-1: A-2 の clear が SSR で走らないことを明記せよ

- 判断: **事実確認のうえ対応 (SSR は本アプリに存在しない)**
- 根拠: 本リポジトリに Inertia SSR は導入されていない
  (`resources/js/ssr.*` が無く、`vite.config.*` / `package.json` に ssr エントリも無い。
  `config/inertia.php` 自体が存在しない)。したがって module singleton が
  サーバサイドで共有される経路は無い。
- 対応内容: 概念設計に「本アプリは Inertia SSR 未使用」を前提として明記。
  そのうえで clear は**ブラウザでの component 初期化 1 回のみ**である旨と、
  テストで「初回 mount / 再レンダー (props 更新) / partial reload」を区別することを明記する。

## [Warning] 5-1: A-2 は別タブの既表示 toast を即時消去しない

- 判断: **対応する (保証範囲を明文化)**
- 根拠: 事実。かつ Codex 自身が指摘するとおり現行 `onDestroy(clearToasts)` も
  別タブの即時無効化は提供していないため、本変更による後退ではない。
- 対応内容: 保証範囲を
  「認証失効後、**次のサーバ遷移で未認証 layout に着地した時点**で認証文脈の toast を持ち越さない。
  別タブの既表示 UI の即時消去は保証しない」と限定して記述。
  BroadcastChannel 等の即時同期はスコープ外 (今必要ない = 思考原則 2)。

## [Warning] 5-2: C は confirm 成功時にしか secret を破棄していない (離脱経路が未定義)

- 判断: **対応する**
- 根拠: 妥当。enrollment 中に別画面へ遷移・再試行した場合の残置は避けるべき。
- 対応内容: `qrSvg` / `secretKey` / 各取得エラーを以下で一括リセットする契約にする。
  - enrollment 開始時 (`enableTwoFactor` の送信前)
  - confirm 成功時 (既存の `qrSvg = null` に `secretKey` とエラーを追加)
  - 2FA 無効化成功時 (既存 `disableTwoFactor` の onSuccess にも追加)
  - component 破棄時は Svelte の state 破棄で自然に消えるため追加処理は不要
    (`Security.svelte` の state は component ローカル。module singleton ではない)
  さらに **QR / secret の両方が取得失敗した場合は「続行可能」と見せず、
  再試行可能な enrollment 取得失敗として提示する**契約を追加する。

## [Warning] 7-1: `fetchJson<unknown>` + type guard で受けよ

- 判断: **対応する**
- 対応内容: 詳細設計で `fetchJson` の戻りを `unknown` として受け、
  局所 type guard (非空 string の確認) を通してから型付き値へ代入する形にする。
  HTTP 失敗 / 不正 shape / 通信失敗は表示経路が同じでも内部では識別できる形にする。

## [Suggestion] 1-1 / 3-2 / 6-1

- 判断: **見送る (現状維持でよいという趣旨)**
