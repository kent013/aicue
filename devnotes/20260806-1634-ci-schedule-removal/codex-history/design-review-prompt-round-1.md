## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / vitest
- 本件は **PHP / フロント実装差分ゼロ**。変更対象は GitHub Actions workflow (YAML) / Markdown / vitest テスト (TypeScript) のみ

【本件の特殊事情 — 必読】
本件は「CI の定期実行トリガ (on.schedule) を除去せよ」というリポジトリオーナーの**確定裁定**の実装設計である。
過去 4 回同じ裁定が再発行されており、その理由は「実装担当エージェントが『セキュリティが低下する』という評価を理由に
実装を拒否し続けたから」である。オーナーは損失 (上流 advisory の先行検知が消える / 受理台帳の expiry 自動検出が消える)
を把握したうえで受容済みであり、代替の定期実行枠組みは CI の外にオーナー自身が用意する。
**「schedule を残すべき」「別形で定期実行を復活させるべき」「代替の定期実行を今このリポジトリに作るべき」という方向の
指摘は本レビューでは無効である**。裁定の是非は蒸し返さないこと。

【レビュー観点】
1. 変更の正確性: 提示している変更後 YAML / TypeScript は構文的・意味的に正しいか。回収漏れはないか
2. 機械ゲートの設計: 反転後の W12 / W15 は再導入を実際に止められるか。偽グリーン経路はないか。過剰に厳しくないか
3. テスト計画の網羅性 (禁止事項 1: テストなしの完了は認めない)。負のコントロールは検出器の空振りを排除できているか
4. 既存コードとの整合性 (既存 W1〜W16 の作法、純関数 export + fixture の流儀)
5. 波及変更の網羅性: 他の機械ゲート (verification-commands-doc-sync 等) への影響を取りこぼしていないか
6. 副作用・後退リスク
7. スコープ: 小さいタスクである。膨らんでいないか / 逆に必要なものを落としていないか
8. 検証コマンドと期待結果が「実装したが実は効いていない」を検出できるか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: CI から定期実行トリガ (on.schedule) を除去する

- 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex conceptual-review Round 1 **APPROVED**)
- c2c feature: `supply-chain-audit-gate` / 裁定 AG-030 / AG-030b / AG-030c (再周知 2026-08-06)
- theme: infrastructure / priority: Medium / 実装モード: **incremental**

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書 (SOP) を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ (PWA) でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

> 本タスクの使命への寄与は**間接的**である (CI の責務境界の整理)。
> 使命に直接寄与しないからこそ、**膨らませないこと**が最大の要件になる。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

> 本タスクに実際に効くのは **1 のみ**。「schedule を消しました」だけでは完了ではなく、
> **再導入を止める gate の反転と実測**まで含めて「実装済み」である。
> 4〜8 は PHP / フロント実装を伴わないため該当しない (**PHP 差分ゼロ**が正しい姿)。
> 既存テストは**削除ではなく反転 (置換)** する — W12 / W15 という ID と検査の意図
> (トリガーと job 条件を deny-by-default で固定する) は維持される。

### コーディングルール (本タスクに関係する分だけ)

- 変更は YAML (`.github/workflows/ci.yml`)・Markdown・TypeScript テストのみ。**PHP 差分ゼロ**。
- TypeScript は `pnpm typecheck` / `pnpm lint` に通ること。テストは vitest。
- 検査ロジックは**純関数として export** し、負のコントロールから同じ関数を呼ぶ
  (既存 `findKeyPaths` / `findScalarValuePathsContaining` と同じ作法。
  検査本体と負のコントロールで別の実装を書くと「片方だけ正しい」偽グリーンを作る)。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | ci.yml から `on.schedule` と job 条件を除去し、再導入禁止の理由をコメントに残す | `.github/workflows/ci.yml` | 必須 |
| 2 | inventory gate の W12 / W15 を「存在強制」から「不在強制」へ反転 | `tests/js/architecture/ci-workflow-inventory.test.ts` | 必須 |
| 3 | AGENTS.md の nightly 記述の除去 | `AGENTS.md` | 必須 |
| 4 | supply-chain review-checklist §6 を「受容の記録」へ書き換え | `docs/supply-chain/review-checklist.md` | 必須 |
| 5 | 免除理由文字列から `nightly` を除去 | `tests/js/architecture/verification-commands-doc-sync.test.ts` | 必須 |

変更ファイルは **5 件、すべて既存ファイルの変更**。新規ファイルは無い。

---

## 施策 1: ci.yml から定期実行トリガを除去する

### 変更箇所

`.github/workflows/ci.yml`

- L7-L10 (`on:` 配下の schedule コメント 2 行 + `schedule:` 2 行)
- L16-L20 (`php` job の除外コメント 4 行 + `if:` 1 行)
- L107-L111 (`browser-tests` job の同上)
- L179-L183 (`frontend` job の同上)
- `supply-chain-audit` job (L214 以降) は **一切触らない**

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: `ci-workflow-inventory.test.ts` (施策 2 で扱う。**先に施策 2 を書くと赤 → 施策 1 で緑**
  になるのでテストファーストの順序が自然に取れる)

### 現行コード

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
  # 上流で新しい advisory が公開された事実を、無関係な PR のクリティカルパス外で先に検知する。
  # nightly は PR job の **代替ではなく追加** (PR job を降格させない)。
  schedule:
    - cron: "0 20 * * *"   # 05:00 JST

jobs:
  php:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
    # 本 job は schedule では走らせない (impl-review R1 [Warning]:
    # on.schedule は workflow 全体を起動するため、明示除外しないと
    # docs の「nightly は supply-chain gate の先行検知」という記述と実体が食い違う)。
    if: github.event_name != 'schedule'
    # pgsql 一本化 (phpunit.xml が DB_CONNECTION=pgsql を <server force> する) の実体。
```

(`browser-tests` L107-L111 / `frontend` L179-L183 も同一の 4 行コメント + `if:` を持つ)

### 変更後コード

```yaml
name: CI

# 定期実行 (on.schedule) は持たない — オーナー裁定 (2026-08-05 / 再周知 2026-08-06)。
# 1. 裁定: CI の責務は push / pull_request の同期検査に限る。定期実行を CI に置くこと自体が
#    責務の置き違いという判断であり、「うまく作れば残せる」道は無い
#    (技術的に妥当な nightly が一度入り、巻き戻された経緯がある)。
# 2. 受容済みの損失: 依存を変えない限り、上流で新しく公開された advisory の検知と
#    docs/supply-chain/accepted-advisories.yaml の expiry 切れの検出は**次の push まで起きない**。
# 3. 代替: 定期実行の枠組みは CI の外に用意する。**CI に戻さない**。
# 4. 機械的な歯止め: tests/js/architecture/ci-workflow-inventory.test.ts の
#    W12 (トリガー集合の完全一致) と W15 (job-level if の不在) が再導入を止める。
# 詳細は docs/supply-chain/review-checklist.md §6。
on:
  push:
    branches: [main]
  pull_request:

jobs:
  php:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    # pgsql 一本化 (phpunit.xml が DB_CONNECTION=pgsql を <server force> する) の実体。
```

`browser-tests` / `frontend` も同様に、4 行コメント + `if: github.event_name != 'schedule'` を
削除する (それ以外の行は 1 文字も変えない)。

### 実装上の注意

- **`supply-chain-audit` job のコメント (L214-L221) は触らない**。
  「`continue-on-error` を付けない」「逃げ道は期限付き accept-risk のみ」は schedule と無関係で、
  裁定が維持を明示している内容である。
- `workflow_dispatch` は**追加しない** (§スコープ外。後続 TODO 候補)。
- インデントを崩さないこと。`if:` の削除で後続行の相対位置が変わるが、
  YAML のキー階層は変わらない (job 直下のキーを 1 つ消すだけ)。

### リスク

| リスク | 影響 | 手当て |
|---|---|---|
| `if:` 削除でジョブが「常に走る」ようになる | 実質変化なし。schedule が無ければ元々常に true | — |
| PR / push の CI 実行時間が増える | **増えない**。schedule 実行が消えるだけで push / PR の実行内容は不変 | — |
| コメント削除で「なぜ 4 job 構成なのか」の説明が失われる | 失われない。消すのは schedule 由来の除外理由だけ | — |

---

## 施策 2: inventory gate の W12 / W15 を反転する

### 変更箇所

`tests/js/architecture/ci-workflow-inventory.test.ts`

- L222-224 (W12) — 差し替え
- L226-237 (W15) — 差し替え
- 純関数 2 本を新規 export (既存 helper 群の直後、L108 付近)
- 負のコントロール describe (L278 以降) に 2 ケース追加

**既存の W1〜W11 / W13 / W14 / W16 は 1 文字も変えない。**

### 波及変更

- TypeScript 型定義: 既存の `Workflow` / `WorkflowJob` interface をそのまま使う (変更なし)
- API Resource / DTO: なし
- テストファイル: 本ファイルのみ

### 追加する純関数 (シグネチャ)

```ts
/**
 * workflow の `on:` に宣言されたトリガー名を昇順で返す純関数 (W12 用)。
 *
 * `on` が未定義なら空配列を返す。YAML 1.1 互換の parser が `on` を boolean `true` として
 * 解釈した場合もここが空配列になり **W12 が落ちる** (静かに素通りしない fail-safe な向き)。
 */
export function triggerNames(workflow: Workflow): string[];

/**
 * job-level `if` を持つ job 名を宣言順で返す純関数 (W15 用)。
 * 値ではなく **`if` の有無**を見るので、条件式の言い換えでは逃げられない。
 */
export function jobsWithCondition(workflow: Workflow): string[];
```

実装:

```ts
export function triggerNames(workflow: Workflow): string[] {
    return Object.keys(workflow.on ?? {}).sort();
}

export function jobsWithCondition(workflow: Workflow): string[] {
    return Object.entries(workflow.jobs ?? {})
        .filter(([, target]) => target.if !== undefined)
        .map(([name]) => name);
}
```

### 現行コード

```ts
    it("W12: on.schedule (nightly) が存在すること", () => {
        expect(workflow.on?.schedule).toBeDefined();
    });

    it("W15: nightly (schedule) では supply-chain-audit だけが走ること", () => {
        // on.schedule は workflow 全体を起動する。docs (review-checklist §6) が
        // 「nightly は supply-chain gate の先行検知」と書いている以上、
        // 他 job は schedule から明示除外され、**gate 自身は除外されない**ことを固定する。
        for (const name of ["php", "frontend", "browser-tests"]) {
            expect(job(workflow, name).if, `${name} が schedule から除外されていない`).toBe(
                "github.event_name != 'schedule'",
            );
        }
        // gate を nightly から外す (= 先行検知を殺す) 退行を止める
        expect(job(workflow, "supply-chain-audit").if).toBeUndefined();
    });
```

### 変更後コード

```ts
    it("W12: on のトリガー集合が push / pull_request と完全一致すること", () => {
        // **定期実行 (schedule) は持たない** — CI の責務は push / pull_request の同期検査に
        // 限るという裁定による (理由と受容した損失は ci.yml の on: 直上のコメントと
        // docs/supply-chain/review-checklist.md §6)。
        //
        // 「schedule が無いこと」ではなく **集合の完全一致** で固定するのは、
        // repository_dispatch を外部 cron から叩く等、別名で定期実行を復活させる経路を
        // 塞ぐため。トリガーを増やすときはこの配列に登録させる (W1 と同じ作法)。
        expect(triggerNames(workflow)).toEqual(["pull_request", "push"]);
    });

    it("W15: どの job も job-level if を持たないこと", () => {
        // 定期実行トリガーの除去に伴い `if: github.event_name != 'schedule'` を全廃した。
        // 値ではなく **`if` の有無** を見るのは、`!contains(github.event_name, 'schedule')`
        // のような言い換えを一網打尽にするため。
        //
        // job-level `if` は **deny-by-default**。条件付き job が必要になったら、
        // W14a / W14b と同じく「理由付きの allowlist」としてここへ登録すること
        // (黙って足すと、条件の形を変えた定期実行の復活を見逃す)。
        expect(jobsWithCondition(workflow), "job-level if は deny-by-default (登録が必要)").toEqual([]);
    });
```

### 追加する負のコントロール

既存の `describe("走査関数の負のコントロール (検出器が空振りしていないこと)")` の中に追加する:

```ts
    it("W12: 復活した schedule トリガーを検出する", () => {
        const fixture = {
            on: { push: null, pull_request: null, schedule: [{ cron: "0 20 * * *" }] },
        } as Workflow;
        expect(triggerNames(fixture)).not.toEqual(["pull_request", "push"]);
        expect(triggerNames(fixture)).toContain("schedule");
    });

    it("W12: 別名トリガー (repository_dispatch) の追加も検出する", () => {
        const fixture = { on: { push: null, pull_request: null, repository_dispatch: null } } as Workflow;
        expect(triggerNames(fixture)).not.toEqual(["pull_request", "push"]);
    });

    it("W15: 復活した job-level if を条件式の形によらず検出する", () => {
        const fixture = {
            jobs: {
                php: { if: "github.event_name != 'schedule'" },
                frontend: { if: "!contains(github.event_name, 'schedule')" },
                "supply-chain-audit": {},
            },
        } as Workflow;
        // 言い換えた条件式も同じく検出される (値ではなく有無を見ているため)
        expect(jobsWithCondition(fixture)).toEqual(["php", "frontend"]);
    });

    it("正常な fixture では W12 / W15 とも違反 0 件", () => {
        const fixture = {
            on: { push: null, pull_request: null },
            jobs: { php: {}, "supply-chain-audit": {} },
        } as Workflow;
        expect(triggerNames(fixture)).toEqual(["pull_request", "push"]);
        expect(jobsWithCondition(fixture)).toEqual([]);
    });
```

> `as Workflow` は既存ファイルの fixture が使っている書き方
> (`const fixture: WorkflowJob = { steps: [...] }`) に合わせる。
> `on` の値が `null` になるのは `pull_request:` のように値を書かない YAML の parse 結果と同じ。

### TypeScript 適合チェック

- [ ] `triggerNames` / `jobsWithCondition` に戻り値型 `string[]` を明示
- [ ] `workflow.on ?? {}` / `workflow.jobs ?? {}` で null 安全 (どちらも optional プロパティ)
- [ ] fixture の型注釈で `any` を使わない (`as Workflow`)
- [ ] `pnpm lint` (ESLint) / `pnpm typecheck` が通ること

### テスト計画

- [ ] **fail を先に見る**: 施策 2 (テスト) を先にコミットせずとも、
      **施策 2 を書いた時点で W12 / W15 が赤くなる**ことを実測する (ci.yml がまだ schedule を持つため)。
      その後に施策 1 を適用して緑になることを確認する = テストファースト (思考原則 5)。
- [ ] 既存 W1〜W11 / W13 / W14a-c / W16 が引き続き green
- [ ] 追加した負のコントロール 4 本が green
- [ ] `pnpm test` 全体が green (`ci-workflow-inventory.test.ts` は root project の対象)
- [ ] 個別の `DatabaseTransactions` 等は無関係 (PHP テストを触らない)

### リスク

| リスク | 手当て |
|---|---|
| W12 の完全一致が将来のトリガー追加で偽赤になる | それが意図 (登録させる)。コメントに明記済み |
| W15 の全面禁止が正当な条件付き job をブロックする | deny-by-default。allowlist 化の手順をコメントに明記済み |
| `on` が boolean `true` として parse される parser 変更 | `triggerNames` が `[]` を返し **W12 が落ちる** = 静かに素通りしない |

---

## 施策 3: AGENTS.md の nightly 記述の除去

### 変更箇所

`AGENTS.md` L148-L152 (§依存脆弱性 (supply-chain) の運用 の 3 つ目の bullet)

**マーカー `<!-- VERIFICATION_COMMANDS:BEGIN/END -->` の外側**なので、
`verification-commands-doc-sync.test.ts` の V0〜V5 には影響しない (照合範囲はマーカー内側のみ)。

### 現行コード

```markdown
- gate は CI (`supply-chain-audit` job) で **blocking** 実行され、加えて nightly (05:00 JST) でも回る。
  `continue-on-error` は付けない (soft-fail = 偽グリーン)。取得失敗は advisory 0 件扱いにせず
  fail-closed で止まる。運用責任 (owner / 初動 SLA) は `docs/supply-chain/review-checklist.md` §6
```

### 変更後コード

```markdown
- gate は CI (`supply-chain-audit` job) の **push / pull_request** で **blocking** 実行される。
  `continue-on-error` は付けない (soft-fail = 偽グリーン)。取得失敗は advisory 0 件扱いにせず
  fail-closed で止まる。**定期実行 (schedule) は持たない** — CI の責務を同期検査に限る裁定で、
  帰結として新しい advisory の検知と accept-risk の expiry 切れは**次の push まで起きない**
  (受容済み。埋め合わせに schedule を戻さない)。運用責任 (owner / 初動 SLA) と
  受容の詳細は `docs/supply-chain/review-checklist.md` §6
```

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- テストファイル: なし (マーカー範囲外のため同期ゲートの対象外。**実測で確認すること**)

---

## 施策 4: `docs/supply-chain/review-checklist.md` §6 の書き換え

### 変更箇所

- L53-L68 (§6 の本文)
- L74 (一次対応表の owner 行)

### 現行コード (該当部分)

```markdown
- **PR / push (main)**: blocking。`continue-on-error` は付けない
  (soft-fail は「赤いのに緑に見える」= baseline 化と同型のため採らない)。
- **nightly (05:00 JST)**: 同じ job を `schedule` でも回す。上流で新しい advisory が
  公開された事実を、**無関係な PR のクリティカルパス外**で先に検知するため。
  nightly は PR blocking の代替ではない。
  `on.schedule` は workflow 全体を起動するため、`php` / `frontend` / `browser-tests` には
  `if: github.event_name != 'schedule'` を付けて **nightly では supply-chain-audit だけが走る**
  ようにしている (`tests/js/architecture/ci-workflow-inventory.test.ts` W15 が固定)。
```

```markdown
| 一次対応 owner | リポジトリオーナー (`ishitoya`)。nightly / PR いずれの赤化でも同一 |
```

### 変更後コード

```markdown
- **PR / push (main)**: blocking。`continue-on-error` は付けない
  (soft-fail は「赤いのに緑に見える」= baseline 化と同型のため採らない)。
- **定期実行 (schedule) は持たない**: CI の責務は push / pull_request の同期検査に限る、
  というオーナー裁定 (2026-08-05 / 再周知 2026-08-06)。
  **実装の巧拙の問題ではない** — 「workflow 起動と job 実行を分けて供給網監査だけを
  定期実行する」技術的に妥当な nightly が一度入り、それでも巻き戻された経緯がある。
  「もっとうまく作れば残せる」道は無い。
  `.github/workflows/ci.yml` の `on:` は `push` / `pull_request` の 2 つで、
  `tests/js/architecture/ci-workflow-inventory.test.ts` の W12 (トリガー集合の完全一致) と
  W15 (job-level `if` の不在) が再導入を機械的に止める。

### 定期実行を持たないことで失うもの (受容済み)

| 失うもの | 帰結 |
|---|---|
| 上流で新しい advisory が公開された事実の先行検知 | 依存を変えない限り、**次の push / PR まで検出されない**。検知の間隔は push / PR の頻度に依存する |
| `accepted-advisories.yaml` の expiry 切れの自動検出 | 同じく次の push / PR まで検出されない。期限を過ぎた entry が気付かれないまま残る期間が生じうる |

これは把握したうえでの受容であり、**埋め合わせに `continue-on-error` を足す /
gate を除外リスト化する / schedule を戻す、のいずれもしない**。
定期的な検知が必要になったときの枠組みは **CI の外**に用意する
(リポジトリ側で artisan コマンド + scheduler として作り直すのも「CI の外」の意味ではない)。
```

```markdown
| 一次対応 owner | リポジトリオーナー (`ishitoya`)。push / PR いずれの赤化でも同一 |
```

### 波及変更

- テストファイル: なし。ただし §6 は `ci-workflow-inventory.test.ts` の W12/W15 を
  **参照している**ため、施策 2 の ID と説明が本文と一致することを目視確認する
  (機械的な同期ゲートは無い = ドリフトしうる箇所なので、実装時にペアで直す)。

---

## 施策 5: 免除理由文字列から `nightly` を除去する

### 変更箇所

`tests/js/architecture/verification-commands-doc-sync.test.ts` L41

### 現行コード

```ts
    "audit:gate": "supply-chain gate は CI/nightly の blocking 実行が正本 (AGENTS.md §依存脆弱性に別記)",
```

### 変更後コード

```ts
    "audit:gate": "supply-chain gate は CI (push / pull_request) の blocking 実行が正本 (AGENTS.md §依存脆弱性に別記)",
```

### 波及変更・リスク

- V5 (免除理由 10 文字以上) は満たす。V3 (key が package.json に実在) は key を変えないので不変。
- 文字列長が 120 文字を超える場合は Prettier / ESLint の行長設定に合わせて折り返す
  (`pnpm lint` で確認)。

---

## 検証コマンドと期待結果

| # | コマンド | 期待 |
|---|---|---|
| 1 | `pnpm vitest run tests/js/architecture/ci-workflow-inventory.test.ts` | **全 green** (W12 / W15 が新しい契約で通る + 負のコントロール 4 本) |
| 2 | `pnpm vitest run tests/js/architecture/verification-commands-doc-sync.test.ts` | 全 green (V0〜V7) |
| 3 | `pnpm test` | 全 green (既存件数から**減っていない**こと。W12/W15 は差し替えなので総数は負のコントロール分だけ増える) |
| 4 | `pnpm lint` / `pnpm typecheck` | クリーン |
| 5 | `pnpm run audit:gate` | 従来どおり PASS (判定ロジック無変更なので**ベースラインと同一の集計**になること) |
| 6 | `rg -n "^\s*schedule:\|github\.event_name" .github/workflows/ci.yml` | **0 hit** |
| 7 | `rg -n -i "nightly" AGENTS.md docs/ tests/ scripts/ .github/ --glob '!TODO-closed.md'` | **0 hit** |
| 8 | `git diff --stat -- app/ database/ routes/ config/ resources/` | **空** (PHP / フロント実装差分ゼロ) |

`composer test` は**流さない** (PHP 差分ゼロ / グローバルテストロックを無駄に占有しない)。
PHP を 1 行も触らないことを #8 で機械的に確認することが、`composer test` を省く根拠になる。

> **判定の正本は #1 の構造テスト、#6 / #7 の `rg` は補助**である
> (conceptual-review R1 [Warning])。`if: contains(github.event…)` のような言い換えは
> grep では拾えない。rg が見ているのは「読み手向けの語彙が残っていないか」であって
> 「機構が残っていないか」ではない。

### 負のコントロールの実測 (必須。報告に結果を書く)

fixture だけでなく**実ファイルを一時改変して gate が落ちること**を確認する (T113 の作法):

1. `.github/workflows/ci.yml` の `on:` に `schedule: [{cron: "0 20 * * *"}]` を戻す
   → #1 で **W12 が fail** することを実測 → `git checkout` で revert。
2. `php` job に `if: github.event_name != 'schedule'` を戻す
   → #1 で **W15 が fail** することを実測 → revert。
3. `php` job に `if: "!contains(github.event_name, 'schedule')"` (言い換え) を入れる
   → #1 で **W15 が fail** することを実測 → revert。

実装完了報告には「どの gate が、どの改変で fail したか」を明記する
(conceptual-review R1 [Suggestion])。

---

## 段階分け

### このタスクでやる

施策 1〜5 (上記)。**1 コミットで完結する規模**。
実施順序は **施策 2 (テスト) → 赤を確認 → 施策 1 (ci.yml) → 緑を確認 → 施策 3〜5 (docs)**。

### 後続 TODO 候補 (本タスクでは起票しない)

| 候補 | 起票条件 |
|---|---|
| `workflow_dispatch` の追加 | オーナーが用意する「CI 外の定期実行枠組み」が **CI を叩く形**に決まったとき。裁定は「残してよい」= 任意であり、叩き方が未定の段階で口を開けるのはオーバーエンジニアリング (思考原則 2)。追加時は **W12 のトリガー集合への登録が必須**になる (gate が強制する) |
| `accepted-advisories.yaml` の expiry 切れの CI 外監視 | 「オーナーが別途用意する」と裁定が明記。リポジトリ側の実装課題ではないため、**こちらから起票しない**。必要になったらオーナー起点で議題化される |
| c2c 台帳への `status_reported` の追記 | 実装が main にマージされ push 済みになった後 (refs は `aicue@<commit>` 形式)。**設計フェーズでは書かない** |

### スコープ外 (概念設計 §6 の再掲)

`scripts/audit-gate.*` の判定ロジック / `accepted-advisories.yaml` の中身 /
`supply-chain-audit` job 本体 / 独立 workflow の新設 / `docs/TODO-closed.md` の過去記録 /
`secret-scan.yml` (schedule を持たない) / `docs/testing-browser.md` (schedule に言及しない)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 5 ファイルの部分変更のみ。新規ファイル・新規抽象なし。1 コミットで完結し、施策間の依存は「テストを先に赤くする」という順序だけ |
| 競合リスク | `.github/workflows/ci.yml` と `ci-workflow-inventory.test.ts` を触る他タスクがあれば競合する。現時点で並走中の CI 関連タスクは無い。`AGENTS.md` は行単位の局所変更なので rebase で解ける |

---

## 関連する現行コード

### `.github/workflows/ci.yml` (全文)

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
  # 上流で新しい advisory が公開された事実を、無関係な PR のクリティカルパス外で先に検知する。
  # nightly は PR job の **代替ではなく追加** (PR job を降格させない)。
  schedule:
    - cron: "0 20 * * *"   # 05:00 JST

jobs:
  php:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
    # 本 job は schedule では走らせない (impl-review R1 [Warning]:
    # on.schedule は workflow 全体を起動するため、明示除外しないと
    # docs の「nightly は supply-chain gate の先行検知」という記述と実体が食い違う)。
    if: github.event_name != 'schedule'
    # pgsql 一本化 (phpunit.xml が DB_CONNECTION=pgsql を <server force> する) の実体。
    # image は docker-compose と同一 major に揃える: ローカルの実測 (2704 passed) を
    # CI の期待値としてそのまま使えるようにするため (major 差は collation / SQL 差で
    # 「CI だけ赤 / CI だけ緑」を生む)。
    services:
      postgres:
        image: postgres:18-alpine
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
          # scripts/ci/pgsql_test_conn.php が maintenance DB として固定で使うため明示する
          POSTGRES_DB: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    # DB_DATABASE は置かない: tests/bootstrap.php が `<slug>_test_<worktree-hash>` を
    # 後勝ちで注入し assertPgsqlTestDatabaseSafe() が fail-closed 検証する単一点ガードを
    # 曖昧にしないため。接続先だけを渡す (pgsql_test_conn.php は shell env を最優先で読む)。
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_USERNAME: postgres
      DB_PASSWORD: postgres
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          # pdo_pgsql は既定で入る保証がない。未導入だと ensure-test-db.php が
          # 「could not find driver」で落ちる (pgsql lane が丸ごと動かない)
          extensions: pdo_pgsql, pgsql
          coverage: none
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Prepare environment
        # passport:keys: OAuth/MCP テストは Passport 鍵 (storage/oauth-*.key) を要する。
        # CI には鍵が無いため生成する (未生成だと "Invalid key supplied" で fail)
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      # bug-hunt インベントリ (.claude/skills/app-bug-hunt/{screens,operations}.md) と
      # route:list のドリフト検知。T106 (passkey 7 route) / T107 (settings.password.store) で
      # 2 サイクル連続してドリフトし、「認証系が bug-hunt のカバレッジから丸ごと落ちる」
      # という実害が出た。台帳が正本である以上 soft-fail にしない (exit 3 で job を落とす)。
      # 判定ロジックは既存スクリプトのままで PHP 側に再実装しない (自前解析器を増やさない)。
      # route:list は APP_KEY を要するが DB は不要なので、Pest より前で fail-fast できる。
      - name: Bug-hunt inventory drift
        run: bash scripts/bug-hunt-inventory-check.sh
      # レンダー smoke テスト (施策 4) の前提。Dockerfile (dev/bughunt) と別に CI runner にも
      # ffmpeg/ffprobe と字幕フォントを導入し、存在・フォント解決を fail-fast 検証する (層 1)。
      - name: Provision ffmpeg for render smoke
        run: |
          sudo apt-get update
          # fontconfig を明示 (fc-match の依存。ランナー差異で未導入の可能性をゼロにする。design-review R1)
          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
          ffmpeg -version
          ffprobe -version
          # fc-match の終了コードだけでなく、解決 family が Noto CJK であることを機械的に判定
          # (代替フォントへのフォールバックを検出する。-f '%{family}' で family のみ抽出しノイズ耐性を上げる)
          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
      - name: Pint (code style)
        run: vendor/bin/pint --test
      - name: PHPStan
        run: composer phpstan
      # グローバルテストロックの並行挙動ゲート (層 1)。
      # 実ロックには触れず、mktemp -d の scratch 上で待機・シグナル収束・fd 非継承などを検証する。
      - name: Verify global test lock
        run: bash scripts/verify-global-test-lock.sh
      # composer test = scripts/run-test.sh (グローバルロック → ensure-test-db → artisan test --parallel)。
      # CI 専用の起動経路は作らない (T099: CI が検証するものと開発者が走らせるものを同一に保つ)。
      # 1 job = 1 runner なので他 job と競合せず、ロックは無競合で即時取得される。
      - name: Pest
        run: composer test

  # Browser lane (pest-plugin-browser)。Chromium + WebKit の 2 レーンが契約であり
  # (AGENTS.md ドメイン規約 3 / docs/supported-browsers.md / T082)、CI でもレーンを絞らない。
  # WebKit は撮影 PWA の主戦場 iOS Safari に最も近い engine で、ログアウト後の
  # Inertia 履歴からの PII 復元を止める唯一の自動回帰である。
  browser-tests:
    runs-on: ubuntu-latest
    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
    # 本 job は schedule では走らせない (impl-review R1 [Warning]:
    # on.schedule は workflow 全体を起動するため、明示除外しないと
    # docs の「nightly は supply-chain gate の先行検知」という記述と実体が食い違う)。
    if: github.event_name != 'schedule'
    # 実ブラウザがハングしたときに既定 6 時間を燃やさないための上限。
    # 現状 14 テスト × 2 レーン (直列) なので十分な余裕がある。
    timeout-minutes: 45
    services:
      postgres:
        image: postgres:18-alpine
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_USERNAME: postgres
      DB_PASSWORD: postgres
      # BROWSER_TEST_LANES / BROWSER_TEST_PROCESSES は **意図的に設定しない**。
      # 既定 (chromium webkit / 直列 1) が契約であり、CI で上書きするとレーンを
      # 骨抜きにできてしまう (tests/js/architecture/ci-workflow-inventory.test.ts が
      # この不在を deny-by-default で固定する)。
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          extensions: pdo_pgsql, pgsql
          coverage: none
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Install pnpm dependencies
        run: pnpm install --frozen-lockfile
      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      # 実ブラウザは public/build のビルド済アセットを読む (withoutVite() は Browser lane に効かない)。
      - name: Build frontend assets
        run: pnpm build
      # ブラウザ実体は Playwright が別途 DL する。**pnpm exec** を使うこと:
      # pest-plugin-browser が起動する run-server は root devDependency の playwright と
      # 同一実体である必要があり、npx だと別バージョンを引きうる。
      # 未導入だと PlaywrightOutdatedException で 2 レーンとも全 fail する (ローカル実測)。
      # --with-deps は WebKit が Linux で要求する共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を入れる。
      - name: Install Playwright browsers (chromium + webkit)
        run: pnpm exec playwright install --with-deps chromium webkit
      # composer test:browser = scripts/run-browser-test.sh
      # (グローバルロック → config:clear → ensure-test-db → chromium レーン → webkit レーン)。
      # レーン引数は渡さない (§既定が契約)。
      - name: Pest (browser lanes)
        run: composer test:browser

  frontend:
    runs-on: ubuntu-latest
    # nightly (schedule) は supply-chain-audit だけを回すためのトリガーなので、
    # 本 job は schedule では走らせない (impl-review R1 [Warning]:
    # on.schedule は workflow 全体を起動するため、明示除外しないと
    # docs の「nightly は supply-chain gate の先行検知」という記述と実体が食い違う)。
    if: github.event_name != 'schedule'
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
      - name: Install dependencies
        run: pnpm install --frozen-lockfile
      - name: ESLint
        run: pnpm lint
      - name: TypeScript
        run: pnpm typecheck
      - name: Vitest
        run: pnpm test
      - name: TypeScript (workspace packages)
        run: pnpm typecheck:packages
      # emit 経路 (packages/cli/tsconfig.json) の検証。
      # typecheck:packages が使う tsconfig.test.json は noUnusedLocals/noUnusedParameters を
      # 明示的に false にしているため、**build を通さないと検出できないエラーが存在する**。
      # 「typecheck があるから build は不要」は成立しない (実測: main で TS6133/TS6192 7 件)。
      - name: Build (workspace packages)
        run: pnpm build:packages
      - name: Vitest (workspace packages)
        run: pnpm test:packages
      - name: Build
        run: pnpm build

  # supply-chain 依存脆弱性 gate (AGENTS.md §依存脆弱性の運用)。
  #
  # **continue-on-error を付けない**。soft-fail は「赤いのに緑に見える」= 偽グリーンであり、
  # PHPStan の baseline 化 (禁止事項 2) と同型の逃げになる。
  # 未受容 high/critical で fail、moderate は warn (audit-gate.ts の判定)。
  # 逃げ道は docs/supply-chain/accepted-advisories.yaml の **期限付き** accept-risk のみ
  # (expiry・cleanup・severity 別上限を audit-gate.ts が機械強制するため、
  #  「黙らせて永続化する」ベースラインとは性質が異なる)。
  supply-chain-audit:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - uses: actions/checkout@v4
      # composer audit / pnpm audit の両方を回すため PHP と Node の両方が要る
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Install pnpm dependencies
        run: pnpm install --frozen-lockfile
      # scripts/audit-gate.sh → scripts/audit-gate.ts (tsx 経由)。
      # ローカルの `pnpm run audit:gate` と同一経路 (CI 専用の判定を作らない)。
      - name: Supply-chain audit gate
        run: pnpm run audit:gate
```

### `tests/js/architecture/ci-workflow-inventory.test.ts` (全文)

```ts
/**
 * CI workflow inventory gate — `.github/workflows/ci.yml` の構成を deny-by-default で固定する。
 *
 * なぜ必要か: scripts/run-browser-test.contract.test.ts は**スクリプトの契約**を守るが、
 * workflow 側で
 *   - `browser-tests` の env に `BROWSER_TEST_LANES: chromium` を足す
 *   - どこかの step に `continue-on-error: true` を足す
 *   - `pnpm test:packages` / `pnpm build:packages` の step を消す
 * といった退行は**スクリプトを一切壊さずに**実行できる。
 * 「レーンが CI で実際に走っている」を守るには workflow 自体を inventory 化する必要がある。
 *
 * W9 / W13 は「値が正しいこと」ではなく「**現れないこと**」を検査する。
 * 文字列 grep ではコメント内の言及で偽赤になるため、**YAML を parse した後の構造を歩く**
 * (コメントは parse 時に落ちるので、`BROWSER_TEST_LANES` を**コメントで説明する**ことは許される)。
 */
import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { parse as parseYaml } from "yaml";

/** ci.yml の最小構造 (検査に必要な範囲のみ)。 */
interface WorkflowStep {
    name?: string;
    uses?: string;
    with?: Record<string, unknown>;
    run?: string;
    env?: Record<string, unknown>;
}
interface WorkflowJob {
    "runs-on"?: string;
    if?: string;
    services?: Record<string, { image?: string }>;
    env?: Record<string, unknown>;
    steps?: WorkflowStep[];
}
interface Workflow {
    on?: Record<string, unknown>;
    jobs?: Record<string, WorkflowJob>;
}

const WORKFLOW_PATH = resolve(process.cwd(), ".github/workflows/ci.yml");

function loadWorkflow(): Workflow {
    return parseYaml(readFileSync(WORKFLOW_PATH, "utf-8")) as Workflow;
}

function job(workflow: Workflow, name: string): WorkflowJob {
    const found = workflow.jobs?.[name];
    if (!found) throw new Error(`job "${name}" が ci.yml に無い`);
    return found;
}

/** 全 run 文字列を job 単位で連結する (step の分割に依存せず「実行しているか」を見るため)。 */
function runScript(target: WorkflowJob): string {
    return (target.steps ?? []).map((s) => s.run ?? "").join("\n");
}

/** `run` 文字列を「空行とコメント行を除いた実行行」へ分解する。 */
function runLines(target: WorkflowJob): string[] {
    return (target.steps ?? [])
        .flatMap((s) => (s.run ?? "").split("\n"))
        .map((l) => l.trim())
        .filter((l) => l !== "" && !l.startsWith("#"));
}

/** 任意の深さのオブジェクト木に指定 **キー名** が現れる位置を返す純関数 (W9 / W13 用)。 */
export function findKeyPaths(node: unknown, key: string, path = "$"): string[] {
    const hits: string[] = [];
    if (Array.isArray(node)) {
        node.forEach((child, i) => hits.push(...findKeyPaths(child, key, `${path}[${i}]`)));
        return hits;
    }
    if (node && typeof node === "object") {
        for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
            if (k === key) hits.push(`${path}.${k}`);
            hits.push(...findKeyPaths(v, key, `${path}.${k}`));
        }
    }
    return hits;
}

/**
 * 任意の深さの木を歩き、**scalar 文字列の中身**に needle を含む位置を返す純関数 (W9 用)。
 * `run: BROWSER_TEST_LANES=chromium composer test:browser` のような
 * 「キーではなく値として仕込む」骨抜きを検出するために必要 (キー走査だけでは素通りする)。
 */
export function findScalarValuePathsContaining(node: unknown, needle: string, path = "$"): string[] {
    const hits: string[] = [];
    if (typeof node === "string") {
        if (node.includes(needle)) hits.push(path);
        return hits;
    }
    if (Array.isArray(node)) {
        node.forEach((child, i) => hits.push(...findScalarValuePathsContaining(child, needle, `${path}[${i}]`)));
        return hits;
    }
    if (node && typeof node === "object") {
        for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
            hits.push(...findScalarValuePathsContaining(v, needle, `${path}.${k}`));
        }
    }
    return hits;
}

/** action 名から `@version` を落とす (version 上げで偽赤にしない)。 */
function actionName(uses: string): string {
    return uses.split("@")[0];
}

/**
 * browser-tests job で使ってよい setup action (allowlist)。
 * ここに足すことは「その action が BROWSER_TEST_* を $GITHUB_ENV へ書かない」ことの表明である。
 */
const BROWSER_JOB_ALLOWED_USES = [
    "actions/checkout",
    "shivammathur/setup-php",
    "pnpm/action-setup",
    "actions/setup-node",
] as const;

/** browser-tests job で実行してよいコマンド行 (完全一致)。
 *  追加するときは「その行が BROWSER_TEST_* を設定しうるか」を必ず確認すること。 */
const BROWSER_JOB_ALLOWED_RUN_LINES = [
    "composer install --prefer-dist --no-progress --no-interaction",
    "pnpm install --frozen-lockfile",
    "cp .env.example .env",
    "php artisan key:generate",
    "php artisan passport:keys --force",
    "pnpm build",
    "pnpm exec playwright install --with-deps chromium webkit",
    "composer test:browser",
] as const;

const LANE_ENV_VARS = ["BROWSER_TEST_LANES", "BROWSER_TEST_PROCESSES"] as const;

describe("ci.yml inventory gate", () => {
    const workflow = loadWorkflow();

    it("W1: job 集合が完全一致すること (job を増やしたらここに登録させる)", () => {
        expect(Object.keys(workflow.jobs ?? {}).sort()).toEqual(
            ["browser-tests", "frontend", "php", "supply-chain-audit"].sort(),
        );
    });

    it("W2: php / browser-tests が postgres:18-alpine service を持つこと", () => {
        for (const name of ["php", "browser-tests"]) {
            expect(job(workflow, name).services?.postgres?.image).toBe("postgres:18-alpine");
        }
    });

    it("W3: php / browser-tests の setup-php が pdo_pgsql を含むこと", () => {
        for (const name of ["php", "browser-tests"]) {
            const setup = (job(workflow, name).steps ?? []).find(
                (s) => s.uses !== undefined && actionName(s.uses) === "shivammathur/setup-php",
            );
            expect(setup, `${name} に setup-php step が無い`).toBeDefined();
            expect(String(setup?.with?.extensions ?? "")).toContain("pdo_pgsql");
        }
    });

    it("W4: php が composer test と verify-global-test-lock.sh を実行すること", () => {
        const script = runScript(job(workflow, "php"));
        expect(script).toContain("composer test");
        expect(script).toContain("bash scripts/verify-global-test-lock.sh");
    });

    it("W5: php の ffmpeg provision と fc-match fail-fast が残っていること", () => {
        const script = runScript(job(workflow, "php"));
        for (const token of ["ffmpeg", "fonts-noto-cjk", "fontconfig"]) {
            expect(script).toContain(token);
        }
        expect(script).toContain("fc-match");
        // 解決 family が Noto CJK であることの機械判定 (代替フォントへのフォールバック検出)
        expect(script).toContain("Noto Sans CJK");
    });

    it("W6/W14c: browser-tests に composer test:browser 完全一致の run step がちょうど 1 つあること", () => {
        // `includes` 判定にしないのは `run: echo "composer test:browser"` が素通りするため。
        const exact = (job(workflow, "browser-tests").steps ?? []).filter(
            (s) => (s.run ?? "").trim() === "composer test:browser",
        );
        expect(exact).toHaveLength(1);
    });

    it("W7: browser-tests が playwright install --with-deps chromium webkit を実行すること", () => {
        expect(runScript(job(workflow, "browser-tests"))).toContain(
            "pnpm exec playwright install --with-deps chromium webkit",
        );
    });

    it("W8: browser-tests が pnpm build を実行すること (実ブラウザが public/build を読む)", () => {
        expect(runLines(job(workflow, "browser-tests"))).toContain("pnpm build");
    });

    it("W9: BROWSER_TEST_LANES / BROWSER_TEST_PROCESSES が workflow のどこにも現れないこと", () => {
        for (const name of LANE_ENV_VARS) {
            // キー名としても、あらゆる scalar 値の中身としても現れてはならない
            expect(findKeyPaths(workflow, name)).toEqual([]);
            expect(findScalarValuePathsContaining(workflow, name)).toEqual([]);
        }
    });

    it("W10: frontend が全レーンを実行すること", () => {
        const lines = runLines(job(workflow, "frontend"));
        for (const command of [
            "pnpm test",
            "pnpm test:packages",
            "pnpm typecheck:packages",
            "pnpm build:packages",
            "pnpm build",
            "pnpm lint",
            "pnpm typecheck",
        ]) {
            expect(lines, `frontend に "${command}" が無い`).toContain(command);
        }
    });

    it("W11: supply-chain-audit が pnpm run audit:gate を実行すること", () => {
        expect(runScript(job(workflow, "supply-chain-audit"))).toContain("pnpm run audit:gate");
    });

    it("W12: on.schedule (nightly) が存在すること", () => {
        expect(workflow.on?.schedule).toBeDefined();
    });

    it("W15: nightly (schedule) では supply-chain-audit だけが走ること", () => {
        // on.schedule は workflow 全体を起動する。docs (review-checklist §6) が
        // 「nightly は supply-chain gate の先行検知」と書いている以上、
        // 他 job は schedule から明示除外され、**gate 自身は除外されない**ことを固定する。
        for (const name of ["php", "frontend", "browser-tests"]) {
            expect(job(workflow, name).if, `${name} が schedule から除外されていない`).toBe(
                "github.event_name != 'schedule'",
            );
        }
        // gate を nightly から外す (= 先行検知を殺す) 退行を止める
        expect(job(workflow, "supply-chain-audit").if).toBeUndefined();
    });

    it("W16: php が bug-hunt インベントリの drift 検知を **実行行として** 持つこと", () => {
        // T106 (passkey 7 route) / T107 (settings.password.store) で 2 サイクル連続して
        // .claude/skills/app-bug-hunt/{screens,operations}.md がドリフトし、
        // 「認証系が bug-hunt のカバレッジから丸ごと落ちる」実害が出た。
        //
        // runScript ではなく runLines を使う: runScript はコメント行も連結するため
        // 「# bug-hunt-inventory-check.sh は将来入れる」というコメントで green になる
        // (既存 W14b/W14c と同じ「実行行だけを見る」方針)。
        const lines = runLines(job(workflow, "php"));
        const mentions = lines.filter((l) => l.includes("scripts/bug-hunt-inventory-check.sh"));
        expect(mentions, "php job に bug-hunt インベントリ drift 検知の実行行が無い").not.toEqual([]);

        // `includes` だけでは `... || true` / `echo "bash scripts/..."` の soft-fail 偽装が素通りする
        // (W6/W14c と同じ理由で完全一致を要求する)。continue-on-error 自体は W13 が別途禁じている。
        expect(mentions, "drift 検知が完全一致の実行行になっていない (soft-fail 偽装の疑い)").toEqual([
            "bash scripts/bug-hunt-inventory-check.sh",
        ]);
    });

    it("W13: continue-on-error が workflow のどこにも現れないこと (soft-fail 禁止)", () => {
        expect(findKeyPaths(workflow, "continue-on-error")).toEqual([]);
    });

    it("W14a: browser-tests の uses が信頼済み setup action の allowlist に限定されること", () => {
        const used = (job(workflow, "browser-tests").steps ?? [])
            .filter((s) => s.uses !== undefined)
            .map((s) => actionName(s.uses as string));
        for (const name of used) {
            expect(BROWSER_JOB_ALLOWED_USES, `allowlist 外の action: ${name}`).toContain(name);
        }
    });

    it("W14b: browser-tests の run 実行行が allowlist に完全一致すること", () => {
        for (const line of runLines(job(workflow, "browser-tests"))) {
            expect(BROWSER_JOB_ALLOWED_RUN_LINES, `allowlist 外の実行行: ${line}`).toContain(line);
        }
    });
});

describe("走査関数の負のコントロール (検出器が空振りしていないこと)", () => {
    it("continue-on-error を持つ step を検出する", () => {
        const fixture = { jobs: { php: { steps: [{ run: "x", "continue-on-error": true }] } } };
        expect(findKeyPaths(fixture, "continue-on-error")).toHaveLength(1);
    });

    it("env キーとしての BROWSER_TEST_LANES を検出する", () => {
        const fixture = { jobs: { "browser-tests": { env: { BROWSER_TEST_LANES: "chromium" } } } };
        expect(findKeyPaths(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
    });

    it("run 値に埋めた BROWSER_TEST_LANES を検出する (キー走査は 0 件 = 値走査が必要な証明)", () => {
        const fixture = {
            jobs: { "browser-tests": { steps: [{ run: "BROWSER_TEST_LANES=chromium composer test:browser" }] } },
        };
        expect(findKeyPaths(fixture, "BROWSER_TEST_LANES")).toEqual([]);
        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
    });

    it("複数行 scalar に埋めた BROWSER_TEST_PROCESSES を検出する", () => {
        const fixture = {
            jobs: {
                "browser-tests": { steps: [{ run: "export BROWSER_TEST_PROCESSES=4\ncomposer test:browser" }] },
            },
        };
        expect(findKeyPaths(fixture, "BROWSER_TEST_PROCESSES")).toEqual([]);
        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_PROCESSES")).toHaveLength(1);
    });

    it("正常な fixture では両関数とも 0 件", () => {
        const fixture = { jobs: { "browser-tests": { steps: [{ run: "composer test:browser" }] } } };
        for (const name of LANE_ENV_VARS) {
            expect(findKeyPaths(fixture, name)).toEqual([]);
            expect(findScalarValuePathsContaining(fixture, name)).toEqual([]);
        }
        expect(findKeyPaths(fixture, "continue-on-error")).toEqual([]);
    });

    it("W14a: allowlist 外の composite action を検出する", () => {
        const steps: WorkflowStep[] = [
            { uses: "actions/checkout@v4" },
            { uses: "./.github/actions/setup-browser" },
        ];
        const outside = steps
            .map((s) => actionName(s.uses as string))
            .filter((n) => !(BROWSER_JOB_ALLOWED_USES as readonly string[]).includes(n));
        expect(outside).toEqual(["./.github/actions/setup-browser"]);
    });

    it("W14b: allowlist 外のローカルスクリプト実行行を検出する", () => {
        const fixture: WorkflowJob = {
            steps: [{ run: "bash scripts/prepare-browser-ci.sh" }, { run: "composer test:browser" }],
        };
        const outside = runLines(fixture).filter(
            (l) => !(BROWSER_JOB_ALLOWED_RUN_LINES as readonly string[]).includes(l),
        );
        expect(outside).toEqual(["bash scripts/prepare-browser-ci.sh"]);
    });

    it("W14c: echo で偽装した composer test:browser を検出する", () => {
        const fixture: WorkflowJob = { steps: [{ run: 'echo "composer test:browser"' }] };
        const exact = (fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser");
        // includes 判定なら素通りするが、完全一致では 0 件になる
        expect(runScript(fixture)).toContain("composer test:browser");
        expect(exact).toHaveLength(0);
    });

    it("W9 + W14b/W14c: 環境変数付与つき起動は 3 検査すべてが違反を返す", () => {
        const fixture: WorkflowJob = { steps: [{ run: "BROWSER_TEST_LANES=chromium composer test:browser" }] };
        expect(findScalarValuePathsContaining(fixture, "BROWSER_TEST_LANES")).toHaveLength(1);
        expect(
            runLines(fixture).filter((l) => !(BROWSER_JOB_ALLOWED_RUN_LINES as readonly string[]).includes(l)),
        ).toHaveLength(1);
        expect((fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser")).toHaveLength(0);
    });

    it("composite action へ移送すると W14a と W14c の両方が違反を返す", () => {
        const fixture: WorkflowJob = { steps: [{ uses: "./.github/actions/run-browser-lanes@v1" }] };
        const outside = (fixture.steps ?? [])
            .filter((s) => s.uses !== undefined)
            .map((s) => actionName(s.uses as string))
            .filter((n) => !(BROWSER_JOB_ALLOWED_USES as readonly string[]).includes(n));
        expect(outside).toHaveLength(1);
        expect((fixture.steps ?? []).filter((s) => (s.run ?? "").trim() === "composer test:browser")).toHaveLength(0);
    });
});
```

### `docs/supply-chain/review-checklist.md` §6 (該当部分)

```markdown
## 6. CI での実行と運用責任

`pnpm run audit:gate` は GitHub Actions の `supply-chain-audit` job で実行される。

- **PR / push (main)**: blocking。`continue-on-error` は付けない
  (soft-fail は「赤いのに緑に見える」= baseline 化と同型のため採らない)。
- **nightly (05:00 JST)**: 同じ job を `schedule` でも回す。上流で新しい advisory が
  公開された事実を、**無関係な PR のクリティカルパス外**で先に検知するため。
  nightly は PR blocking の代替ではない。
  `on.schedule` は workflow 全体を起動するため、`php` / `frontend` / `browser-tests` には
  `if: github.event_name != 'schedule'` を付けて **nightly では supply-chain-audit だけが走る**
  ようにしている (`tests/js/architecture/ci-workflow-inventory.test.ts` W15 が固定)。

取得失敗 (network 不通・レジストリ障害) は **advisory 0 件として扱わない**。
`scripts/audit-gate.sh` が空出力・前処理失敗をそこで止め、`assertAuditSourceShape` が
「valid JSON だが期待 schema でない」出力を弾く (fail-closed)。一過性の赤は re-run で回復する。

### 一次対応

| 項目 | 決め |
|---|---|
| 一次対応 owner | リポジトリオーナー (`ishitoya`)。nightly / PR いずれの赤化でも同一 |
| 初動 SLA | critical: 当日中に判断 / high: 2 営業日以内に判断 / moderate: warn のみ (SLA なし) |
| 「判断」の中身 | upgrade で解消する、または §3 の上限内で accept-risk を登録する、のいずれか |
| accept-risk の承認者 | 単独開発体制のため `approved_by` = owner。代替統制として `expiry` 上限 (high 30 日) と `tracking_issue` 必須で外部から追跡可能にする (`audit-gate.ts` が両方を機械強制) |
| 自動 upgrade PR (Dependabot / Renovate) | **現時点では導入しない**。gate 単体で運用し「upgrade 追従が人手で回らない」ことが観測されてから検討する |

### 上流由来で全 PR が赤くなったとき
```

### `AGENTS.md` §依存脆弱性 (supply-chain) の運用 (該当部分)

```markdown
## 依存脆弱性 (supply-chain) の運用

- `pnpm run audit:gate`(`scripts/audit-gate.sh` → `scripts/audit-gate.ts`)が
  composer / pnpm(pyproject.toml があるリポジトリでは PyPI も)の audit を統合判定する。
  未受容の high/critical で fail、moderate は warn
- advisory 検出時は **upgrade で解消が原則**。accept-risk は最終手段で、
  `docs/supply-chain/accepted-advisories.yaml` に owner / approved_at / expiry /
  rationale 付きで登録する(high/critical は approved_by / compensating_controls /
  tracking_issue も必須)。severity 別の expiry 上限(low/moderate 90 日・high 30 日・
  critical 14 日)、期限切れ・解消済み entry の残置は gate が機械的に fail させる
- gate は CI (`supply-chain-audit` job) で **blocking** 実行され、加えて nightly (05:00 JST) でも回る。
  `continue-on-error` は付けない (soft-fail = 偽グリーン)。取得失敗は advisory 0 件扱いにせず
  fail-closed で止まる。運用責任 (owner / 初動 SLA) は `docs/supply-chain/review-checklist.md` §6
- 判断基準・0day 緊急時フロー・新規 npm 依存の審査観点は
  `docs/supply-chain/review-checklist.md` を参照

```

### `tests/js/architecture/verification-commands-doc-sync.test.ts` の EXEMPT (該当部分)

```ts
const EXEMPT: Record<string, string> = {
    dev: "開発サーバ起動。検証コマンドではない",
    "lint:fix": "lint の自動修正。検証は lint 側が担う",
    "test:ui": "vitest UI の対話起動。CI/検証で回すものではない",
    "test:watch": "watch 実行。単発検証ではない",
    "test:coverage": "カバレッジ計測。検証ゲートではない (test が正本)",
    "audit:gate": "supply-chain gate は CI/nightly の blocking 実行が正本 (AGENTS.md §依存脆弱性に別記)",
};
```
（このファイルの照合範囲は AGENTS.md / app-implement SKILL.md の
`<!-- VERIFICATION_COMMANDS:BEGIN -->` 〜 `END` マーカーの内側のみ。
V5 は免除理由が 10 文字以上であることを要求する。）
