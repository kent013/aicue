# Bug-hunt Finding 台帳 (Finding Ledger)

app (AI UX 評価プラットフォーム) の探索的バグハント結果を捨てずに**資産化**するための台帳。
目的は綺麗な分類ではなく **「同じ欠陥を二度見つけたとき安く扱い、重要欠陥をテストに昇格させる」**こと。
bug-hunt の全体像・運用は `.claude/skills/app-bug-hunt/SKILL.md` と `coverage-audit.md` を参照。

## 構成
| ファイル | 役割 |
|---|---|
| `findings.schema.json` | 1 finding の JSON Schema（必須フィールド・制御語彙） |
| `validate_findings.py` | findings.jsonl を検証し success/kill KPI を出力（stdlib のみ、jsonschema 不要） |
| `test_validate_findings.py` | validator のテスト（`python3 -m unittest`） |
| `example.findings.jsonl` | デモ用サンプル（app S1..S8） |

## 正本の分離（重複させない）
- `report.md` … 人間向け本文・再現手順・証跡の正本。
- `findings.jsonl` … **分類の正本**（種別・参照のみ）。同じ説明文を両方に書かない。
- 1 finding = `report.md` の `## F-xx` と `findings.jsonl` の 1 行が `finding_id` / `evidence_ref` で対応。

## 実行環境（app bug-hunt）
LLM 探索的バグハントは dev `app` から物理隔離された専用 bughunt 環境で走る:

- **直列**: shard 0 / `:8010`
- **並列**: shard 1..8 / `:8011..8018`（各 shard 独立 DB `bug_hunt_1..8`）
- 外部依存は `TESTING_BROWSER_FAKES`（`config('testing.browser_fakes')`）で宣言的に fake 化。
- `shard_id` フィールドに 0-8 を記録する（直列=0、並列=1..8）。

## ユーザーストーリー（story_id）
`story_id` は **enum 化しない自由文字列**（逸脱ストーリーを S3-dev 等で表現できるように）。app の標準ストーリー:

| story_id | カバー範囲 |
|---|---|
| S1 | ゲスト→登録ファネル（home/pricing → register → verify → 組織作成 → dashboard） |
| S2 | 招待フロー（members.invite → invitations.accept → ロール変更） |
| S3 | サイト評価ジャーニー（コア。project→site→sitemap→page→evaluation→report/pdf） |
| S4 | 組織/プロジェクト管理（org settings/transfer/2FA-req, persona/scenario CRUD, member 権限, API キー） |
| S5 | 課金（billing checkout/plan変更/ticket購入、fake mode 必須） |
| S6 | セキュリティ/2FA/プロフィール（profile/password, 2FA, recovery codes, sessions, account 削除） |
| S7 | 結果/認可境界（他組織/他プロジェクトの evaluation/page/site への IDOR、共有リンク境界） |
| S8 | CLI 経由の評価（API トークンで `<app-cli>` から評価実行） |

## 最小フィールド（必須）
`finding_id, run_id, story_id, capability_tag, principal, tenant_relation, failure_class,
resource_type, operation, species_key, oracle_attribution, evidence_ref, triage_status`

- **species_key は自由文禁止**。`failure_class:resource_type:operation:tenant_relation` から
  **機械的に組む**（validator が導出値との一致を検証）。例 `idor:evaluation:read:cross_tenant`。
  これが dedup（same_as 判定）の基盤。LLM 要約文を key にすると揺れて種同定が壊れる（致命）。

**よくある自由文（誤用）→ 正規 failure_class 早見表**。enum 原典は `findings.schema.json`。
子 shard が直感で書きがちな自由文を正規語彙へ寄せる早見表:

| 子が書きがちな自由文 | 正しい failure_class | 補足 |
|---|---|---|
| `unresponsive_ui` / `stale_ui` | `broken_flow` | 操作後に状態が進まない/古い state 残留 |
| `ux_defect` / `ux_degradation` / `ux_regression` | `ux_dead_end` か `broken_flow` | 詰み=`ux_dead_end`、進行不能=`broken_flow` |
| `a11y` | `ux_dead_end`（操作到達不能）/ `other` | SR ラベル欠落等は `other` |
| `info_disclosure` | `error_exposure`（情報露出）/ `authz_bypass`（権限越え閲覧）/ `other` | 文脈で選ぶ |
| `external_egress` | `test_env` | fake driver 未整備の環境事象 |
| `perf_issue` / `slow` | `perf` | |

迷ったら新語を作らず `other`。failure_class は species_key の第1要素なので**揺れると dedup が壊れる**。
- `capability_tag` … capability-catalog の id。未割当は `unmapped`、tag 不能は `unknown`（隠さない）。
- `oracle_attribution` … 検出 oracle の rule id、または検出 oracle が無い場合 `oracle_gap`。
- `triage_status` … `proposed`（LLM 一次）→ 人手確認で `confirmed` / `same_as` / `split` / `fixed` /
  `test_env` / `wont_fix` / `needs_spec`。人手は new species / oracle_gap / security / high-sev のみ。

## 使い方
```bash
# 走行中: finding を 1 件見つけるたびに findings.jsonl に 1 行追記（report.md と同時、逐次）
# run 後の検証・KPI:
python3 validate_findings.py devnotes/{run-id}-bug-hunt/shard-0/findings.jsonl
python3 validate_findings.py path/to/findings.jsonl --json      # 機械集計
python3 validate_findings.py path/to/findings.jsonl --strict    # 必須欠損>10% で exit 1
# 並列: 各 shard の findings.jsonl を cat で連結して検証（union）
cat devnotes/{run-id}-bug-hunt/shard-*/findings.jsonl | python3 validate_findings.py -
```

## KPI（success / kill 判定、3 run 以内）
validator が出すもの:
- `completeness` 必須欠損なし率 … **success ≥90% / kill <80%**
- `species_consistency` species_key が導出値と一致する率 … 低い＝正規化設計が悪い
- `distinct_species` / `duplicate_rate` … 探索の鮮度（dup 上昇＝掘り尽くし接近）
- `oracle_gap` 件数 … oracle 未整備の可視化
- `high_critical_regression_coverage` … high/critical のうちテスト化された率
  … **唯一の失敗モード検知**: 2 run 後 0% なら失敗、3 run 後 <50% なら運用見直し。

## adjudication registry（誤検知/意図仕様/won't-fix の cross-session 台帳、T877）

`adjudications.jsonl` は **一度 adjudicate した finding を次の run が再 triage しない**ための cross-session
台帳。**正本の 3 分離**: `report.md`=人間本文 / `findings.jsonl`=per-run 分類 / `adjudications.jsonl`=
cross-session 判定。3 者で同じ説明文を重複させない。

**consult は Phase4 統合 (親) のみ** (shard agent は consult しない = 子は素の `proposed` のみ):
```bash
python3 validate_findings.py <findings.jsonl> --adjudications adjudications.jsonl \
    --annotate --run-id {run-id} --repo-root .
```
annotate は各 finding に `adjudication_status` (`known_accepted`/`ambiguous`/`none`) /`adjudication_id`/
`adjudication_verdict`/`adjudication_expired`/`adjudication_ambiguity_reason`/`must_remain_actionable`/
`actionable_hold_reason` を**非破壊**で付与し stdout に出す (原本不変更)。KPI: `accepted_matched` /
`accepted_high_sev_held` / `ambiguous` / `stale` / `rederive_errors` / `adjudications_invalid`。

**過剰抑制 (同 species の新規 real bug 取りこぼし) を多層で防ぐ** — 一致しても **drop せず**
known-accepted として可視・downrank するだけ。マッチは **4-gate AND**: `species_key` 完全一致 +
`scope` (route_name/screen_id exact, path_glob は literal segment≥2) + `conditions`
(finding の `observed_conditions` だけを読む) + `symptom` (`required_tokens` 肯定必須 must-hit、
`known_tokens` は novelty 基準)。さらに new_signal (新規観察語) / invalidation (`watch_globs` が
`adjudicated_at_commit` 以後変更 or 0-match/未解決 → ambiguous) / review_window (`review_after_days`、
run_id 日時から経過算出) / 再導出ゲート / high・critical は known-accepted でも `must_remain_actionable`
で actionable 保持。tie-break は **specificity-first** (最大 specificity 群に ambiguous があれば accept しない)。

**エントリ追記 = 人手 adjudicate 時のみ** (機械追記なし):
- **append-only + supersede** (既存行を編集しない。差し替えは新 `adjudication_id` + `supersedes`)。
- 必須: `adjudication_id`(`A-NNN`, 一意) / `species_key` / `scope{scope_kind,scope_value}` / `conditions` /
  `symptom{required_tokens(非空),known_tokens}` / `verdict`(false_positive|intentional|wont_fix) /
  `rationale_ref`(非空) / `source_finding_ids`(**非空** `F-*`、実 id 優先・歴史的は `F-historical-*` 可) /
  `adjudicated_at_run`(run_id) / `adjudicated_at_commit` / `watch_globs`(非空・過広禁止) / `review_after_days`(int>0)。
- 意図仕様は AGENTS.md アンカー (kebab-case, 例 `AGENTS.md#billing-409-inertia`) を `rationale_ref` で
  参照し本文は複製しない (AGENTS.md 側にも「機械正本: ledger/adjudications.jsonl A-xxx」の相互参照 1 行)。
- finding 側が carry-forward の恩恵を受けるには shard が optional `surface{route_name,screen_id,path}` /
  `observed_conditions{viewport,auth_role,...}` / `symptom_tokens[...]` を記録するとよい (無ければ安全側=
  no match/ambiguous で actionable のまま)。

## スコープ（Finding 台帳でやらないこと）
- pcov（実装到達カバレッジ）/ capture-recapture / 3軸ダッシュボード / Boundary Matrix 完備は**やらない**。
- 操作到達カバレッジ = この台帳 + `operations.md` 機構実行数 + graph TESTED_BY を同一 run_id で突合（pcov 不要）。
- adjudication registry は **抑制 (drop) しない**。annotate + downrank のみ (取りこぼし 0 は主張せず
  invalidation/review で見落としリスクを下げる)。semantic/embedding マッチもしない (決定論照合のみ)。
- **残留リスク (設計上の受容)**: 同 species・同 scope・同 conditions で finding が `required_tokens` 以外の
  distinctive な観測語を持たない (terse な) 場合、known_accepted に downrank され得る。緩和は high/critical の
  actionable hold・conditions・review_window・new_signal escalation。`required_tokens` は distinctive に、
  `known_tokens` は実語彙で書く (stopword だけにしない) こと。
