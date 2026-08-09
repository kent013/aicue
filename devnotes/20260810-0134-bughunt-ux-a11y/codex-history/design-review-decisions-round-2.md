# 対応マトリクス: design-review Round 2

Critical はゼロ。Warning 6 件はすべて対応した（反論なし・全件受け入れ）。

## [Warning/A] `navigateToPanelIfNeeded` の単体テストだけでは受入条件 4 のページ配線を保証できない
- 判断: **対応する**
- 根拠: 指摘のとおり。将来 `Show.svelte` が誤って `captureActive: false` を渡しても helper の
  テストは緑のままで、回帰を検出できない。helper を切り出したこと自体は正しいが、
  「配線が正しい」ことは別に固定しないと受入条件 4 を満たしたとは言えない。
- 対応内容: 受入条件 4 を **2 段構え**にした ——
  (i) helper の抑止契約（`panel-navigation.test.ts`）、
  (ii) **ページ配線**（`tests/js/pages/Capture/Show.test.ts` の component test で
  `CameraRecorder` の `onCaptureActiveChange(true)` を発火させてからカット行をクリックし、
  `focus` / `scrollIntoView` が呼ばれないことを固定）。
  さらに **(ii) が技術的に成立しない場合は「helper の抑止契約のみ検証（ページ配線は未固定）」と
  保証範囲を下方修正して実装レポートに明記する**という逃げ道の書き方まで指定した
  （「完全に検証した」と書かないため）。受入条件マップにも反映。

## [Warning/A] 「cuts 14 件以上」はテストが失敗経路を通る保証にならない
- 判断: **対応する**
- 根拠: 指摘のとおり。行高・折返し・mobile 実寸が変われば 14 件でも撮影パネルが
  初期 viewport に収まりうる。そうなるとテストは実装前から緑で、何も証明しない。
- 対応内容: **件数を条件から外した**。Browser テストが**クリック前に前提そのものを assert** する形に変更:
  `capture-right-pane` の `getBoundingClientRect().top >= window.innerHeight`。
  件数は 14 件から始めるが「viewport 外にするための手段」と位置づけ、
  この前提 assert が落ちたらテストデータを増やす、と明記した。

## [Warning/B] コメントと代入キー名だけでは `playbackJobId` の preview 限定を認定できない
- 判断: **対応する**
- 根拠: 妥当。コメントは不変条件の根拠にならない。実 query 条件を示すべき。
- 対応内容: `VideoManualController.php` L142-148 の**実 query を全文引用**した:
  `->where('kind', RenderKind::Preview->value)` / `->where('status', JobStatus::Succeeded->value)` /
  `->whereNotNull('output_path')` / `->latest('id')` / `->value('id')`。
  `kind` の enum 比較で render job が構造的に混ざらないことを示した。
  実行中の更新側（`RenderPanel.svelte` L118-131 で render 分岐が `playbackId` を触らないこと）も
  行番号付きで明示した。これで固定文言「プレビュー動画」の根拠が実コードのみになった。

## [Warning/C] 「初期選択されています」は依然として「選択済み」を意味し CTA と食い違う
- 判断: **対応する**
- 根拠: 指摘のとおり。「初期選択されて**います**」は完了相であり、
  CTA の「選択」（= これから操作が必要）と意味が一致しない。
  基準を分離しても、語が選択済みを含意していたら誤認は消えない。
- 対応内容: 未押下時の文言を **「{plan.name} プランが初期候補として表示されています」** に変更した。
  責務表・受入条件 9 も更新し、受入条件 9 に **「『選択中』を含まないこと」** の否定条件も足した。
  押下後の「選択中です」はそのまま（Codex も妥当と評価）。

## [Warning/C] 異なるカード同士の比較はレイアウト不変性の検査として成立しない
- 判断: **対応する**
- 根拠: 指摘のとおり。プランごとに名前・価格・機能数・CTA が違うので、
  選択状態と無関係に高さや相対位置が異なる。grid stretch でカード全体の高さだけ揃い、
  内部の折返しが隠れる可能性もある。
- 対応内容: 受入条件 11 を **同一カードの状態変更「前後」比較**に置き換えた ——
  (1) 初期状態で Starter / Standard の `h3` / 価格 / CTA の矩形を保存、
  (2) Standard を選択（Starter は note 有→無、Standard は無→有）、
  (3) **同一カードの前後**を比較、(4) 相対 `top` と `height` が許容差 1px 以内、
  (5) note 自身が可視領域を持たないことを別途検査。
  カード全体の `height` 一致は**補助検査**に格下げした（grid stretch に吸収されるため）。

## [Warning/C] fixture が「未契約」を保証していない / Seeder の実行も未確定
- 判断: **対応する（最も実害の大きい指摘。設計のままだとテストが到達すらしない）**
- 根拠: コードを追った結果、**指摘のとおり設計が間違っていた**:
  - `createOrganizationWithOwner()` の既定 `grandfatherFreePlan = true` は
    `free_plan_code = PersonalPlanService::FREE_PLAN_CODE` を立てる
  - `BillingAccess::state()` L74-76 はそれを **`ActiveFreePlan`** と判定する
  - `OnboardingBillingState::grantsAccess()` L25-28 は **`ActiveFreePlan` でも true** を返す
  - `OnboardingController::show()` L61-63 は `hasActiveAccess` なら **`billing.index` へリダイレクト**
  → **既定の fixture では Checkout に到達できない**。
  さらに同 Controller L72-76 は `?plan=` を session へ積んでから
  **query 無しの canonical URL へ 303** するため、`?plan=starter` のまま URL に残る前提も誤りだった。
- 対応内容: fixture を **`createOrganizationWithOwner(grandfatherFreePlan: false)`** に確定し、
  上記 4 ファイルの行番号付き根拠を設計書に書いた。
  `?plan=` の 303 canonical redirect を明記し、`assertPathIs('/onboarding/checkout')`（query 無し）で
  着地を固定する形にした。前提の事前 assert（未契約オーナーで Checkout が表示できること）も追加した。
  Seeder は `tests/TestCase.php` L14 の **`protected bool $seed = true;`** を確認し、
  `DatabaseSeeder` が自動で走るため**明示 seed は不要**と確定した（「走るなら」を消した）。
  リスク欄にも「fixture が `grandfatherFreePlan: false` であること」を明記した
  （既定のままだと「note が無い」ではなく「画面が違う」で落ち、原因が分かりにくいため）。

## [Suggestion] `navigateToPanelIfNeeded` の引数に名前付き型
- 判断: **対応する**
- 対応内容: `export interface PanelNavigationInput` を定義し、それを引数型にした。
