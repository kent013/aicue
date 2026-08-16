# Round 2: Round 1 指摘への対応

Round 1 の指摘 (Warning 7 / Suggestion 4) をすべて捌きました。対応マトリクスと、
概念設計の変更点を示します。1 件だけ**反論** (厳格必須の維持) がありますので、根拠をご確認ください。

## 対応マトリクス

| # | 指摘 | 判断 | 対応内容 |
|---|---|---|---|
| W1 | 「費用増はゼロ」は言い過ぎ | 対応 | 「追加 LLM 呼び出し 0 回 / チケット据え置き / provider 実費は微増 (上界を数値で明記)」の 3 段に書き分け、"ゼロ" の語を削除 |
| W2 | `validation` 追加で `WorkDecompositionData::fromLlmText()` の契約が壊れうる | 対応 | 応答 DTO `WorkDecompositionResponseData` を新設。**decode は 1 回だけ** |
| W3 | 二重パース設計 | 対応 | `fromPayload(array)` / `fromStorage(?array)` に分離。`WorkDecompositionData::fromLlmText()` は削除 (並走を残さない) |
| W4 | validation 不正で解析本体を失敗させるのは強すぎないか | **反論 (厳格必須を維持)** | 下記 §反論 |
| S1 | `narration_not_polite` の仕様を明文化せよ | 対応 | 許容終端集合を定義 + 字幕①規則も明文化 |
| W5 | `fromStorage()` の無音 null フォールバック | 対応 | 固定文言の `Log::warning` + テストで固定 |
| W6 | 最新 succeeded job 取得の境界 / source_document 比較の表示仕様 | 対応 | relation 起点に固定 + `is_current_document` の定義を明記 |
| W7 | 「追加クエリ 2 本」は nested cuts を読むと崩れる | 対応 | 1 クエリ + 1 パス groupBy に確定。クエリ数テストを追加 |
| W8 | props / storage / TS の shape と同期責務が抽象的 | 対応 | DTO 階層を確定 (下記) |

## §反論: `validation` は厳格必須のままにする

- 既存 3 段すべてが「DTO 検証 → 不正なら `LlmOutputInvalidException` → 有界リトライ」の 1 本道であり、
  ここだけ寛容にすると「一部だけ不正な応答」という概念が増える (経路が 2 本になる)。
- **失敗しても課金されない**。`AnalysisJobService::failLockedJob()` がチケット予約を release するため、
  利用者が失うのは時間だけで金銭ではない。しかも失敗は画面のエラー表示に出る (無音の欠落ではない)。
- スキーマが小さい (4 フィールド / enum 3 値 / タイトル 10 件上限)。同じモデルは 3 段目で
  これよりはるかに大きいスキーマ (8 フィールド × 最大 100 カット) を満たしている。試行は計 3 回ある。
- 寛容にすると「所見が出ないまま誰も気づかない」= 機能が静かに死ぬ (W5 のご指摘と同じ性質の危険)。

リスクとしては設計のリスク表に「report のスキーマ違反だけで解析が失敗しうる (上限 3 試行 / 課金なし)」を明記しました。

## 概念設計の変更点 (差分の全文)

### 追加: `validation` の必須度 (§改善アイデア (A) の末尾)

```
#### `validation` の必須度 — 厳格必須 (不正なら有界リトライ、最終的に解析失敗)

「補助情報だから不正でも null にして通す」案を検討したうえで、**厳格必須**を選ぶ:
1. 既存 3 段すべてが「DTO 検証 → 不正なら LlmOutputInvalidException → 有界リトライ」の 1 本道。
2. 失敗しても課金されない (failLockedJob が予約を release)。失うのは時間だけ。失敗は画面に出る。
3. スキーマが小さい (4 フィールド / enum 3 値 / タイトル 10 件上限)。リトライは計 3 試行。
4. 寛容にすると「所見が出ないまま誰も気づかない」= 機能が静かに死ぬ。
この判断のリスク: report のスキーマ違反だけを理由に解析全体が失敗しうる。上限はリトライ 3 試行で、
失敗時はチケットが戻る。
```

### 差し替え: 規約検査の規則の明文化 (§改善アイデア (B))

```
| code | 検査 | 規約の出所 |
| narration_missing | ナレーションが空 | ナレーションは全カットに要る |
| narration_not_polite | 丁寧体で終わっていない | 「語尾は〜します に統一」 |
| narration_directive | 「ください」を含む | 「指示的な〜してくださいは禁止」 |
| subtitle_primary_sentence | 字幕①が文になっている | 「字幕①は固有名詞・数値のみ」 |
| subtitle_secondary_missing | 字幕②が空 | 「音声なしで 100% 伝わる情報量」 |

規則の明文化 (偽陽性を出す検査は読み飛ばす習慣を作るので、境界を先に決める):
- narration_not_polite: 末尾の空白と句点 (。 . ! ！) を除いた文字列が
  {ます, ません, ました, ましょう, です, でした} のいずれでも終わらないとき。
  「〜してはいけません」「〜が必要です」を偽陽性にしないための集合であり、
  体言止め・「〜する」「〜せよ」を拾うのが目的。
- narration_directive: 「ください」を含むとき。narration_not_polite とは独立に数える
  (「〜してください」は両方に載りうる。パネルは code ごとの件数を出すので二重計上にはならない)。
- subtitle_primary_sentence: 字幕①が 。 を含む、または「ます」「です」を含むとき。
- 閾値 (文字数上限等) は 1 つも置かない (根拠となる実データが無い段階で値を作らない)。
```

### 追記: 置き場所 (§改善アイデア (C))

```
- 読み出しは必ず DTO の fromStorage(?array): ?self で組み立て直す。壊れていれば null を返して
  「所見なし」として描画する (JSON カラムの中身を信用しない。詳細画面が 500 にならないことを優先)。
- null に畳むだけにせず必ず記録する: 復元失敗時は固定文言の Log::warning
  (「解析ジョブの妥当性所見の復元に失敗しました」+ job id) を出す。無音の縮退は保存契約の破損を
  長期間隠すため、テストで「壊れた保存値でも Show が 200」と「警告が記録される」の両方を固定する。
```

### 差し替え: 所見の取得と鮮度 (§改善アイデア (D))

```
- LLM の所見 (最新の succeeded な解析ジョブから。無ければ非表示)
  取得は $manual->analysisJobs() relation 起点に固定する
  (クラス起点の主キー取得を作らない = cross-org 不可の不変条件と DirectFetchInventory に触れない)。
  鮮度の表示仕様: is_current_document = (job.source_document_id !== null &&
  job.source_document_id === 最新 SOP の id)。SOP が差し替え済み / 削除済み (FK は nullOnDelete で
  NULL になる) / 1 件も無い、のいずれでも false になり、false のときだけ
  「この所見は解析時の手順書に対するものです」の注記と再解析導線を添える (所見自体は隠さない)。
```

### 差し替え: 費用 (§期待効果)

```
- 費用: 3 段に分けて誇張せずに書く。
  1. 追加の LLM 呼び出しは 0 回 (3 回のまま)。段を増やさないので時間 budget も不変。
  2. チケット消費は据え置き (COST_ANALYSIS = 1)。
  3. provider 実費は微増する。上界は出力 +700 token 程度 (10 タイトル × 60 字 + 理由 200 字。
     出力予約 16,000 の 5% 未満)、入力 +250 token 程度 (固定プロンプト余裕 4,000 token の内側)。
     1 解析あたり 3 段合計の token に対しては 1% 未満の増分である。
```

### 差し替え: 実装方針 (DTO 階層の確定 + クエリ)

```
2. 応答 DTO の一本化: WorkDecompositionResponseData::fromLlmText(string) が decode を 1 回だけ行い、
   WorkDecompositionData::fromPayload(array) と SopValidationData::fromPayload(array) を組み立てる。
   WorkDecompositionData::fromLlmText() は削除する (後方互換の並走を残さない)。
5. ScenarioRuleCheck — cuts を orderBy('sort_order') の 1 クエリで全件取得し、parent_cut_id で
   1 パス groupBy して位置 (手順 N / 急所 N-M) を組む (ScenarioDocumentData::fromManual と同じ手口)。
6. DTO 階層 (props は必ずこれ経由。Controller で生 array を組まない):
   - ScenarioReportData { verdict: ScenarioVerdictData|null, counts: ScenarioCountsData,
     findings: list<ScenarioRuleFindingData> }
   - ScenarioVerdictData { verdict: ScenarioVerdict, reason: string, works: list<string>,
     workCount: int, splitRecommended: bool, isCurrentDocument: bool }
   - ScenarioCountsData { steps: int, points: int, total: int }
   - ScenarioRuleFindingData { code: ScenarioRuleCode, count: int,
     positions: list<ScenarioCutPositionData{step:int, point:?int}> } (先頭 5 件まで)
11. テスト: DTO 検証 (正常/不正/リトライ)、パイプライン保存、壊れた保存値での復元 (null + 警告)、
    規約検査の各 code、クエリ数がカット件数に依存しないこと、props、UI、enum⇔TS 同期。
```

### 追加: リスク表 (§制約・前提の直後)

```
| validation のスキーマ違反だけで解析が失敗しうる | 時間を失う (チケットは release) | スキーマを 4 フィールドに絞る / 3 試行 / 失敗は画面に出る |
| 2 段目の出力が +700 token 増える | 出力予約の 5% 未満 | 3 段目 (張り付きうる段) には足さない |
| 規約検査の偽陽性 | 検査を読み飛ばす習慣を作る | 許容終端集合を広めに / 導入・総括が必ず該当する検査を入れない / 閾値なし |
| 所見が古い手順書に対するものになる | 誤った判断材料 | is_current_document の注記 + 再解析導線 |
| 保存 JSON の破損が無音化する | 機能が静かに死ぬ | 復元失敗を Log::warning で記録し、テストで固定 |
```

以上を踏まえて再判定をお願いします。
