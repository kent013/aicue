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
- **並列**: shard 1..4 / `:8011..8014`（各 shard 独立 DB `bug_hunt_1..4`）
- 外部依存は `TESTING_BROWSER_FAKES`（`config('testing.browser_fakes')`）で宣言的に fake 化。
- `shard_id` フィールドに 0-4 を記録する（直列=0、並列=1..4）。
- 過去 run の findings には 0-4 の範囲外の `shard_id` が入りうる（履歴は書き換えない）。

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

### 運用ガード (a) `species_key` の 4 セグメント規約

`species_key` は **必ず 4 セグメント**（`failure_class:resource_type:operation:tenant_relation`）。
finding 側と同じ規約で、adjudication 側も validator が `_SPECIES_RE` で強制する。

```
misleading_copy:billing:starter-consent            # ← invalid (3 セグメント)
misleading_copy:billing:read:self                  # ← valid
```

実例根拠: 削除した旧 seed の A-004〜A-007 は 3 セグメントで書かれており
`bad species_key` で invalid だった（`data_loss:api-rest:put-full-replace` など）。
**registry が 1 件でも invalid なら fail-closed で registry 全体が無効になる**ため、
3 セグメントの 1 行が抑制機構を全面停止させる。書くときは 4 要素を機械的に組むこと。

### 運用ガード (b) governed `COND_KEYS` と `mode` / `env` を含める理由

`conditions` に書けるキーは validator の `COND_KEYS` に限定される（未知キーは
`bad condition key` で invalid = fail-closed）:

| key | 意味 |
|---|---|
| `viewport` | 観測ビューポート（例 `<=389px`, `768`） |
| `auth_role` | 認証ロール（例 `member`, `guest`） |
| `browser` | ブラウザ（例 `chromium`, `webkit`） |
| `feature_flag` | フラグ状態 |
| `precondition` | 上記に当てはまらない前提条件（自由文） |
| `mode` | bug-hunt harness のモード（`fake` / `real`。manifest の real_mode） |
| `env` | 走行環境（例 `bughunt`, `dev`） |

`mode` / `env` は **generic な `precondition` に潰さない**。bug-hunt harness の第一級ディメンション
であり、「fake mode 限定の偽陽性」を real モードの実退行に誤適用しないための **load-bearing な条件**
だからである（`precondition` の自由文に落とすと文字列一致でしか効かず、条件として機能しない）。
根拠となった事故: spirux HARNESS-01 — 旧 `COND_KEYS` に `mode` / `env` が無く schema drift が起き、
`conditions.mode` を持つエントリが `bad condition key` で invalid → fail-closed で抑制が全面停止した。
AI-CUE でも同じ状態で、2026-08-02 の監査時に旧 A-008 が `bad condition key: 'mode'` で fail していた。

### 運用ガード (c) 新規 adjudication の登録手順

1. **どの run で** — `adjudicated_at_run` に実 run_id（`YYYYMMDD-HHMMSS`）、
   `adjudicated_at_commit` にその run の commit を書く。`source_finding_ids` は
   その run の実 finding id（歴史的な事象のみ `F-historical-*` を許す）。
2. **何を根拠に** — `rationale_ref` は非空。**実コードの file:line か AGENTS.md アンカーか
   テスト名**を指す（本文は複製しない）。裏取りは設計文書 / 実コード / テストの三点で行い、
   取れないものは登録しない（「要確認」のまま残す方が安全）。
3. **`watch_globs` に何を書くか** — その判定を無効化しうる**実在ファイル**のパス。
   - 実在しないパスは書かない（invalidation が永久に発火せず、判定が腐ったまま抑制し続ける）。
   - AI-CUE の実 path 規約に従う（Svelte ページは **小文字** `resources/js/pages/`。
     大文字 `Pages/` は case-sensitive CI で解決不能かつ AI-CUE に存在しない）。
   - 過広禁止（`app/**` 等は validator が拒否）。判定の根拠になったファイルだけを列挙する。
4. `species_key` は (a)、`conditions` は (b) の規約に従う。`symptom.required_tokens` は
   distinctive に、`known_tokens` は実語彙で書く。
5. 追記後は必ず検証する（invalid が 1 件でもあれば registry 全体が無効になる）:
   ```bash
   python3 validate_findings.py ledger/example.findings.jsonl --adjudications ledger/adjudications.jsonl
   python3 -m unittest discover -s ledger -p 'test_*.py'
   ```
6. 人間可読の申し送り（「過去 run で SPEC / DOC と確定した事象を再起票しない」）は
   機械 registry の対として `.claude/skills/app-bug-hunt/spec-ledger.md` に書く。

### 運用ガード (d) spirux 由来 18 件 (A-001〜A-018) を削除した理由

2026-08-02 に旧 seed 18 件を**全削除して seed を空にした**。

- 18 件は **AI-CUE に実在しない資産**を指していた:
  `.claude/skills/spirux-bug-hunt/operations.md`（A-012）/ `/api/v1/personas/*`・
  `app/Http/Controllers/Api/V1/PersonaController.php`（A-005 / A-006）/
  大文字 `resources/js/Pages/Billing/Index.svelte`（A-004 / A-008〜A-011。AI-CUE は小文字 `pages/`）/
  `app/Filament/Resources/OrganizationResource.php`（A-018。AI-CUE に Filament admin は無い）。
- `watch_globs` が実在しないパスを指すため **invalidation が永久に発火しない** =
  他アプリの偽陽性判定を AI-CUE の実退行に誤適用し続けるリスクだけが残る。
- **実効的な抑制件数は 0 → 0 で不変**。削除時点で validator は 5 件の error
  （A-004〜A-007 の 3 セグメント `species_key`、A-008 の `bad condition key: 'mode'`）を出しており、
  fail-closed により 18 件すべてが無効だった。
- したがってこの変更は**機能削除ではなく、機構を使える状態に戻す**もの。
  今後は上記 (c) の手順で AI-CUE の実 run から積み上げる。

## スコープ（Finding 台帳でやらないこと）
- pcov（実装到達カバレッジ）/ capture-recapture / 3軸ダッシュボード / Boundary Matrix 完備は**やらない**。
- 操作到達カバレッジ = この台帳 + `operations.md` 機構実行数 + graph TESTED_BY を同一 run_id で突合（pcov 不要）。
- adjudication registry は **抑制 (drop) しない**。annotate + downrank のみ (取りこぼし 0 は主張せず
  invalidation/review で見落としリスクを下げる)。semantic/embedding マッチもしない (決定論照合のみ)。
- **残留リスク (設計上の受容)**: 同 species・同 scope・同 conditions で finding が `required_tokens` 以外の
  distinctive な観測語を持たない (terse な) 場合、known_accepted に downrank され得る。緩和は high/critical の
  actionable hold・conditions・review_window・new_signal escalation。`required_tokens` は distinctive に、
  `known_tokens` は実語彙で書く (stopword だけにしない) こと。
