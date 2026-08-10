# 対応マトリクス: design-review-v2 Round 1

## [Critical] 施策 2: 0 件時に `SUM()` が NULL になり整数列の DTO が壊れる
- 判断: **対応する**
- 根拠: 指摘どおり。TOTAL は GROUP BY 無しの集計なので対象 0 件でも 1 行返り、
  `SUM(input_tokens)` 等が NULL になる。DTO は `int` を要求しており TypeError / Assert 失敗になる。
- 対応内容: **加算整数列 (トークン数・各件数) にだけ `COALESCE(SUM(...), 0)` を掛ける**と明記。
  **金額列には掛けない** (null = 未解決という情報を 0 に潰すと「タダだった」という嘘になり、
  `usdUnresolvedCalls` / `jpyUnresolvedCalls` と対になる仕様が崩れる)。
  「0 件時の TOTAL の形」を仕様として確定し、テスト計画を
  「COALESCE を外すと落ちる回帰テスト」と書き直した。

## [Warning] 施策 2: 「全軸が index に乗る」は言い過ぎ
- 判断: **対応する**
- 根拠: 列は存在するが、期間条件 + 軸の複合で常に index が効くとは言えない。誇張は設計文の毒。
- 対応内容: 主張を「既存の列だけで成立し、SQL 関数を使う GROUP BY がひとつも無い。
  既存 index と相性はよいが常に効くとまでは主張しない。本件の規模では index を追加しない」へ弱めた。

## [Critical] 施策 6: `confirmToProceed()` は既定で production でしか確認しない
- 判断: **対応する**
- 根拠: vendor 実読で確認 (`getDefaultConfirmCallback()` は
  `environment() === 'production'`)。実行環境は `bughunt.local` なので、
  引数なしで呼ぶと**確認が一度も出ないまま課金が走る**。設計の「毎回確認する」と矛盾していた。
- 対応内容: `confirmToProceed($costWarning, true)` (第 2 引数 `true` = 常に確認) を明記し、
  拒否時は `self::INVALID` を返すこと、`--force` の skip は `ConfirmableTrait` が担うこと、
  **確認の skip と fail-secure 4 条件は別物**であることを併記した。

## [Warning] 施策 6: `llm-evidence` の分類に穴 (必要 template が一部だけ欠けると `Unknown`)
- 判断: **対応する** (ただし入力は増やさない)
- 根拠: 指摘どおり穴がある。成功行が 1 つでもあれば `$hasLlmSuccessRow` が true になり
  #8 (帰属) にも #9 (Llm) にも入らず `Unknown` へ落ちる。
  かつ「analysis は成功したのに一部 template の記録が無い」は provider ではなく記録側の欠陥である。
- 対応内容: 分類器の入力 `$llmAttributionMissing` を **`$llmRecordingIncomplete` へ改称し、
  意味を「帰属欠落 **または** 必要 template の成功行欠落」へ広げた**。
  引数を 1 本足すのではなく既存の 1 本の意味を広げたので、**入力の数は増えていない**
  (オーバーエンジニアリングを避ける今回の主旨に沿う)。内訳は段の detail 文字列に出す。
  「`llm-evidence` の失敗は #8 と #9 で網羅されており `Unknown` へ落ちる経路は無い」と明記。

## [Suggestion] 施策 6: `$baselineId` の取得タイミングが未定義
- 判断: **対応する**
- 根拠: 「この実行分」の切り出し境界は smoke の証跡そのもので、曖昧だと実装がぶれる。
- 対応内容: 「fail-secure 4 条件 + preflight 通過直後、`fixture` 段の前に
  `LlmCallLog::max('id') ?? 0` を 1 回だけ取る」と確定。`--check` では取らない旨も明記。

## [Suggestion]/[Warning] 施策 1・9: 「テストレーンでは検証できない」が広すぎる
- 判断: **対応する**
- 根拠: 指摘どおり。reflection で `metadata_context` までは検証できる。
  範囲を広く書くのは「保証しないもの」の欄では**誇張の逆方向の誤り**であり、直すべき。
- 対応内容: 「検証できるのは factory が `metadata_context` を持つことまで。
  検証できないのは**イベント → listener → `llm_call_logs`** の区間」と範囲を狭めた
  (施策 1 の「テストの限界」節と docs の「保証しないもの」6 の両方)。

## [Critical] 施策 10: 確認プロンプト挙動を固定するテストが無い
- 判断: **対応する**
- 根拠: 上の Critical と対。ケース 9 が偽の前提の上に立っていた。
- 対応内容: ケース 9 を「`bughunt.local` でも確認が出ること」を固定する形へ書き換え
  (第 2 引数 `true` を外すと落ちるテストにする)。`--force` で確認が出ずに進むケース 9b を追加。

## [Warning] 施策 10: 分類器テストに template 一部欠落ケースを追加
- 判断: **対応する**
- 対応内容: 判定表を 15 行へ拡張。ケース 11 (帰属欠落 → `Wiring`) /
  ケース 12 (必要 template 一部欠落 → `Wiring`) / ケース 13 (`Wiring` 分岐が
  llm-evidence 以外へ漏れない負のコントロール) を明示した。

## 反論・見送りはこのラウンドでは無し
全指摘が「設計内の矛盾を潰す」か「主張を弱める」方向であり、機構の追加を伴わないため
すべて受け入れた。
