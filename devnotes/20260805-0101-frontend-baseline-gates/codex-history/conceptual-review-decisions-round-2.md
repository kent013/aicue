# 対応マトリクス: conceptual-review Round 2

## [Warning] テストファーストの fail 条件が具体化されていない (観点 2)
- 判断: 対応する
- 根拠: 妥当。AGENTS.md 思考原則 5「テストファースト。fail を確認してから実装に入る」に対し、
  「負のコントロールが点灯する」だけでは Red の定義になっていない。
- 対応内容: 受け入れ条件に **Red → Green の確認順序**を明記する。
  Red は 4 本: (R1) 現行 config で `svelte-no-undef-gate` が fail、
  (R2) 現行 `danger` 値 (#DC2626) で `contrast-invariant` が fail、
  (R3) 未分類トークンを混ぜた fixture で inventory 完全性検査が fail、
  (R4) `videoConstraints` の単体テストが実装前に fail。
  各 Red が示す不変条件も併記する。

## [Warning] `videoConstraints()` の引数化による挙動維持が固定されていない (観点 3)
- 判断: 対応する
- 根拠: 妥当かつ重要。現行は `facingMode` を**クロージャから読む**ため、
  カメラ切替 (flip) 後の再取得で最新モードが反映される。
  移動後に呼出時点で渡さないと「切替後も古い facing mode で getUserMedia」という
  実機でしか気づけない後退になる (撮影 PWA = 使命の中核面での後退)。
- 対応内容: シグネチャを `videoConstraints(mode: FacingMode): MediaTrackConstraints` に固定し、
  呼び出しは `acquireStream()` 内で**呼出時点の `facingMode` を渡す**形に限定する
  (constraints をモジュール初期化時や component 初期化時にキャッシュしない)。
  加えて **flip 後の `getUserMedia` が最新モードで呼ばれること**を
  component test で固定する (純関数の単体テストだけでは呼出側の後退を捕まえられない)。

## [Warning] `calculateConfigForFile()` の負のコントロールが flat config のマージ順に依存する (観点 3)
- 判断: 対応する
- 根拠: 妥当。ESLint の設定マージそのものを自作 fixture で試験対象にすると、
  検査したい不変条件ではなく ESLint の挙動を検査することになる。
- 対応内容: **検査ロジックを純関数に切り出す**
  (`assertSvelteNoUndefConfig(resolved: ResolvedConfigView): string[]` = 違反理由の配列)。
  - 正の入力: `ESLint#calculateConfigForFile()` が返した**実 config の解決結果**
  - 負の入力: 解決結果を**テスト内で加工した plain object** (no-undef を落とす /
    noInlineConfig を false にする / globals に余計な名前を足す) の 3 パターン
  ESLint のマージ規則は試験対象にしない。

## [Warning] 型専用名の混入を denylist で保証するのは不十分 (観点 5 / 7)
- 判断: 対応する
- 根拠: 妥当。denylist は未知の型名を素通しさせるうえ、
  「denylist があるから安全」という誤った保証感を作る。
- 対応内容: **allowlist 型 (完全一致) の検査**に変更する。
  svelte ブロックの `languageOptions.globals` のキー集合が
  **`globals.browser` のキー集合と完全一致すること**を固定する。
  アプリ固有の実行時グローバルが将来必要になったら、
  `eslint.config.js` に理由付きの明示 inventory
  (`APP_RUNTIME_GLOBALS`) として登録し、gate 側もその inventory を読む形にする
  (deny-by-default: 登録なき差分は fail)。

## [Suggestion] 4.5:1 一律適用は「WCAG そのもの」ではなく「プロジェクト基準」と書くべき (観点 4)
- 判断: 対応する
- 根拠: 妥当。SC 1.4.3 には大きな文字の 3:1 基準がある。
  トークン単位の gate は文字サイズを知り得ないので、**厳しい側 (4.5:1) を一律適用**するのが
  設計判断であり、それを WCAG の要求そのものと書くのは不正確。
- 対応内容: 「**WCAG 2.2 SC 1.4.3 (AA) の通常文字基準 4.5:1 を、文字サイズによらず
  一律適用するプロジェクト基準**」と明記する。大きな文字の 3:1 緩和は採らない
  (トークン単位では文字サイズが決まらず、緩和すると gate が守る範囲が曖昧になるため)。

## [Suggestion] `camera.ts` は薄いブラウザ API ドメインヘルパの責務を維持せよ (観点 5)
- 判断: 対応する（制約として明記）
- 対応内容: `videoConstraints` は既存の `nextFacingMode` / `classifyGetUserMediaError` と
  同格の「撮影ドメインのブラウザ API ヘルパ」として置く。
  汎用 utility 化・他ドメインからの流用は行わない旨を設計に書く。

## [Suggestion] その他 (使命整合・スコープ・Critical 解消確認)
- 判断: 対応不要（肯定的評価）
