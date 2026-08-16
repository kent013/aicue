# 対応マトリクス: conceptual-review Round 1

全体判定は Round 1 で **APPROVED**。Critical は 0 件。Warning 4 件はすべて概念設計へ反映した
(合議は終了だが、指摘を無反映のまま Phase 2 へ持ち越さない)。

## [Warning] `whereIn('status', $progress->statuses())` の BackedEnum → DB 値の境界が曖昧
- 判断: **対応する**
- 根拠: Laravel の query binding は BackedEnum を value 化するが、それに依存すると
  「型と SQL の境界がどこで閉じるか」がコードから読めない。PHPStan level 10 でも
  `list<VideoManualStatus>` を `whereIn` に渡す形は意図が伝わらない。
- 対応内容: `ManualProgress::statusValues(): list<string>` を追加し、クエリ側はこちらを使う。
  `statuses(): list<VideoManualStatus>` は写像テストと enum 同期テスト用に残し、
  `statusValues()` は `statuses()` から導出する (写像規則は依然 `forStatus()` の match 1 か所)。

## [Warning] 「一覧のバッジが陳腐化しない」は過大な表現
- 判断: **対応する**
- 根拠: 指摘のとおり。`rendering → published` の遷移も再読込まで反映されない以上、
  陳腐化が消えるわけではない。誇張は本リポジトリの規約 (保証範囲を誇張しない) にも反する。
- 対応内容: 期待効果を「**数十秒で消える遷移状態を一覧に出さないため、陳腐化の幅が
  『解析中/書き出し中』の分だけ小さくなる**」へ弱め、「一覧は依然としてポーリングしないので
  再読込までは古い」ことを明記した。

## [Warning] `ready` (シナリオ済・動画未完成) を「作成中」に畳むと次の一手が見えなくなる懸念
- 判断: **対応する** (ただし一覧行への CTA 追加はしない)
- 根拠: 一覧行の操作 (プレビュー / DL / 削除) は現行でも `status` ではなく
  `current_finished_render_job_id` と `deletable` だけで決まっており、
  `ready` と `rendering` の差で出し分けている導線は**一覧に 1 つも無い** (現行コード実読)。
  次の一手 (AI 解析 / 書き出し / 撮影ナビ) の CTA は詳細画面が唯一持つ、というのが T148/T154 の設計である。
  よって一覧に CTA を足すのはスコープ拡大 (思考原則 2) であり、必要なのは
  「畳んでも失われていないこと」をテストで固定することである。
- 対応内容: 「一覧が失う情報の受け皿」を制約節に明記し、詳細画面の CTA (RenderPanel /
  AnalysisPanel) が `ready` / `rendering` を区別し続けることを**既存テストの維持**として
  テスト計画に明示する (新規 CTA は作らない)。

## [Warning] `status → progress` は破壊的変更なので波及 (TS 型 / Svelte / Feature テスト) を同じ変更で
- 判断: **対応する**
- 根拠: 妥当。行 props の shape 変更は Inertia props 契約の破壊的変更である。
- 対応内容: 実装方針に「`ManualListItemData::toArray()` の shape を固定する Feature テスト」を追加し、
  `ManualEnumTsSyncInvariantTest` へ `ManualProgress` と `VideoManualStatus` の**両方**を
  登録することを必須項目として明記した。

## [Suggestion] 使命整合 / スコープ / PWA 非統合の評価
- 判断: 対応不要 (肯定的評価のため)
