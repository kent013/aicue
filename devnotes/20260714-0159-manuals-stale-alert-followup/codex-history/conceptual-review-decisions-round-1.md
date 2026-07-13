# 対応マトリクス: conceptual-review Round 1

全体判定は **APPROVED**。ただし [Warning] 4 件を精査し、設計へ反映または根拠付きで整理する。

## [Warning] 2-1 テスト先行（fail 確認）運用が設計に落ちていない
- 判断: **対応する**
- 根拠: AGENTS 思考原則5「テストファースト」。受け入れ条件に明記する。
- 対応内容: conceptual / detailed に「受け入れ条件: Feature/Unit/Vitest を先に追加し red を確認 →
  実装 → green」を追記。

## [Warning] 3-1 stale 判定を job->updated_at に載せる前提が脆い（terminal 後 touch されると崩れる）
- 判断: **一部対応 + 根拠提示**
- 根拠: 現行スキーマに `failed_at`/`finished_at` は無く（`analysis_jobs`/`render_jobs` は
  `timestamps()` のみ）、`updated_at` が terminal 時刻の唯一の代理。かつ **failed job は terminal 後に
  更新されない**ことがコードで担保済み: `failJob` は `status->isTerminal()` で早期 return（再 fail 不可）、
  stale 回復（`recoverStaleJobs`）は queued/running のみを対象とする。したがって failed job の
  `updated_at` = fail 確定時刻で安定。
- 対応内容: 設計に不変条件「failed(terminal) job は以後 updated_at を更新しない」を明記し、
  Feature テストで「failed 後に job.updated_at が変化しない」を固定する。

## [Warning] 3-2 `job: null` で failed job 全体を落とすのは alert 抑制より影響が広い
- 判断: **根拠提示（flag 追加は見送り）**
- 根拠: Panel が failed job から参照するのは **error 文言（Analysis/Render）と error_code（Preview の
  作り直し CTA）のみ**。stale = シナリオがその失敗の後に変更された状態であり、その場合 error も
  作り直し CTA も**消えるべき情報**。`scenario_version_changed` 失敗は「シナリオ変更 → 検知して失敗」
  の順で、変更は失敗の**前**に起きる（cuts.updated_at < job.updated_at）ため **not stale** となり
  CTA は保持される。よって `job: null` で失う UI は「stale な失敗の alert/CTA」だけで過不足ない。
  `displayable`/`isStale` フラグの DTO 追加は **shape 変更 + TS 型変更 + client 分岐追加** を招き、
  オーバーエンジニアリング（禁止事項）。
- 対応内容: 設計に「stale failed job で意図的に落とす UI = 当該失敗の error alert / preview CTA のみ。
  succeeded job（needsRegenerate/playback/download）は非対象」を明記。回帰テストで
  「succeeded job / not-stale 失敗は null 化されない」を固定。

## [Warning] 5-1 cuts.updated_at を唯一信号にする前提は scenario_version だけ進む経路で壊れる
- 判断: **根拠提示 + 不変条件明記**
- 根拠: `scenario_version` は成功保存で常に +1（`ScenarioService::save` の確定契約）だが、
  cuts.updated_at は **実変更（$changed）時のみ**進む。version だけ進む経路 = **no-op 保存
  （内容無変更）**。この場合シナリオ内容は失敗時と同一なので、直近の実失敗を表示し続けても矛盾しない
  （「状態と矛盾する alert」ではない）。さらに **render の scenario_version_changed CTA を保つには
  timestamp 比較が version 比較より正しい**（version 比較だと `manual.version > job.version` で当該失敗も
  stale 扱いになり CTA が消える。timestamp なら変更は失敗前なので not stale で CTA 保持）。
  つまり cuts.updated_at は「シナリオ**内容**変更」の信号として version より適切。
- 対応内容: 設計に不変条件「シナリオ内容の実変更は cuts.updated_at を進める」を明記し、
  Feature テストで「cut を実追加/更新すると updated_at がその前の失敗時刻より後になる」を固定。
  version-only（no-op 保存）は抑制対象外である旨も明記。

## [Suggestion] 7-2 display{Render,Preview}Job の返す job 種別を名前/PHPDoc で明示
- 判断: **対応する**
- 根拠: 取り違え防止。低コスト。
- 対応内容: メソッド PHPDoc に「最新 kind=render / kind=preview の表示用 job（stale failure は null）」を明記。
