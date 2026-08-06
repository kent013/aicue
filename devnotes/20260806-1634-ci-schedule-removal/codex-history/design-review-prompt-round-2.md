Round 1 の指摘への対応マトリクスと、修正後の該当箇所です。再レビューしてください。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 4 / Suggestion 1)。
施策 1 / 3 / 4 / 5 は APPROVE、施策 2 が REQUEST_CHANGES。
**Warning 4 件・Suggestion 1 件をすべて採用**した。

## [Warning] 施策 2: W12 は ci.yml だけを縛るため、別 workflow への schedule 追加を止められない

- 判断: **対応する** (射程を GitHub Actions 全体へ広げる)
- 根拠: 裁定文は「CI の定期実行トリガ (schedule) を除去する」であって
  「ci.yml というファイルの schedule を除去する」ではない。「定期実行は CI の責務ではない」
  という理由づけは workflow ファイル名に依存しない。
  かつ実査で `.github/workflows/` は `ci.yml` と `secret-scan.yml` の 2 本しかなく
  (`secret-scan.yml` は `pull_request` のみ)、全数走査のコストは無視できる。
  ここを塞がないと「ci.yml から消したので裁定に従った」と言いながら
  `nightly-audit.yml` を新設する経路が残り、**除去したことにならない**。
- 対応内容: **W17 を新設**する
  (`.github/workflows/*.yml|*.yaml` を全数 parse し、どの workflow の `on:` にも
  `schedule` キーが無いことを固定。負のコントロール 1 本つき)。
  W12 は従来どおり ci.yml のトリガー集合の完全一致を担当する (責務分離)。
  スコープが膨らむ指摘だが、**W12 だけでは目的 (再導入防止) を達成しないため必要**と判断した。

## [Warning] 検証 #6 の rg 正規表現が偽グリーンになる (`\|` が literal pipe)

- 判断: **対応する**
- 根拠: 指摘のとおり。rg (Rust regex) では `\|` はエスケープされた literal `|` であり
  alternation にならない。**「0 hit だから消えている」と誤読する検証**になっていた。
  検証コマンド自身が偽グリーンを作るのは本設計が最も嫌う型の欠陥である。
- 対応内容: `rg -n '(^\s*schedule:|github\.event_name)' .github/workflows/ci.yml` に修正。
  同じ理由で #7 の対象に `.github/` を含めていることも維持する。

## [Warning] 検証 #8 が「変更対象 5 ファイルのみ」を保証できない

- 判断: **対応する**
- 根拠: `git diff --stat -- app/ ...` はディレクトリを列挙する negative check であり、
  列挙漏れ (`bootstrap/` / `composer.json` / 別の `tests/`) を見逃す。
  allowlist (許可ファイル以外が 0 hit) の方が deny-by-default で強い。
- 対応内容: 変更許可ファイル 5 本の allowlist に対する `git diff --name-only` の
  差集合が 0 hit であることを検証条件にする。

## [Warning] 負のコントロール実測の revert 手順が粗い

- 判断: **対応する**
- 根拠: 実測は「実ファイルを壊して gate が落ちるのを見る」作業であり、
  戻し損ねると壊れた ci.yml をコミットしうる。AGENTS.md の worktree 運用ルール
  (実装は worktree で行う) と合わせて手順を明示すべき。
- 対応内容: 実測手順に「worktree 内で行う」「実測前に `git status --porcelain` が
  clean であることを確認」「1 改変ごとに `git checkout -- <path>` で戻し、
  戻した直後に再度 clean を確認」を明記した。

## [Suggestion] §4 の「artisan コマンド + scheduler も CI の外ではない」は将来の裁定まで縛る

- 判断: **対応する** (弱める)
- 根拠: 指摘のとおり、これは本タスクの裁定が言っていないことまで文書に固定してしまう。
  必要なのは「本タスクでは作らない」であって「未来永劫その形を禁じる」ではない。
- 対応内容: 「**本タスクでは代替の定期実行を作らない**。どういう形で用意するかは
  オーナーの裁量」という表現に弱めた。

## 修正後の詳細設計書 (全文)

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
| 2 | inventory gate の W12 / W15 を「存在強制」から「不在強制」へ反転 + **W17 (全 workflow の schedule 不在) を新設** | `tests/js/architecture/ci-workflow-inventory.test.ts` | 必須 |
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
- **W17 を新設** (`ci.yml` 以外の workflow ファイルからの schedule 再導入を塞ぐ)
- 純関数 3 本を新規 export (既存 helper 群の直後、L108 付近)
- 負のコントロール describe (L278 以降) にケース追加

**既存の W1〜W11 / W13 / W14 / W16 は 1 文字も変えない。**

> **W17 を足す理由 (design-review R1 [Warning])**: W12 は `ci.yml` **1 ファイルだけ**を縛る。
> 裁定は「CI の定期実行トリガを除去する」であってファイル名の話ではないので、
> `ci.yml` から消したうえで `nightly-audit.yml` を新設する経路が残っていては
> **除去したことにならない**。`.github/workflows/` は現状 `ci.yml` と
> `secret-scan.yml` (`pull_request` のみ) の 2 本しかなく、全数走査のコストは無視できる。

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

/**
 * workflow ファイル群のうち `on:` に `schedule` を持つものの**ファイル名**を返す純関数 (W17 用)。
 * 入力は「ファイル名 → YAML テキスト」の対にして、FS 走査と検査を分離する
 * (負のコントロールから FS に触れずに呼べるようにするため)。
 */
export function workflowsWithSchedule(files: ReadonlyArray<[name: string, yaml: string]>): string[];
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

export function workflowsWithSchedule(files: ReadonlyArray<[string, string]>): string[] {
    return files
        .filter(([, yaml]) => triggerNames(parseYaml(yaml) as Workflow).includes("schedule"))
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

    it("W17: .github/workflows のどの workflow も schedule トリガーを持たないこと", () => {
        // W12 は ci.yml 1 ファイルしか縛らない。裁定は「CI の定期実行トリガを持たない」で
        // あってファイル名の話ではないので、別 workflow を新設して定期実行を復活させる
        // 経路もここで塞ぐ (design-review R1 [Warning])。
        const dir = resolve(process.cwd(), ".github/workflows");
        const files = readdirSync(dir)
            .filter((name) => name.endsWith(".yml") || name.endsWith(".yaml"))
            .map((name) => [name, readFileSync(resolve(dir, name), "utf-8")] as [string, string]);

        // 空振り防止: workflow ファイルが 1 本も無い状態で green にならないこと
        expect(files.length, "workflow ファイルが 1 本も見つからない (走査パスの誤り)").toBeGreaterThanOrEqual(1);
        expect(workflowsWithSchedule(files), "schedule トリガーを持つ workflow").toEqual([]);
    });
```

> `readdirSync` は `node:fs` から追加 import する (既存の `readFileSync` と同じ import 文に足す)。
> `parseYaml` は既に import 済み。

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

    it("W17: 別 workflow ファイルに新設された schedule を検出する", () => {
        const files: Array<[string, string]> = [
            ["ci.yml", "on:\n  push:\n    branches: [main]\n  pull_request:\n"],
            ["secret-scan.yml", "on:\n  pull_request:\n    branches:\n      - main\n"],
            ["nightly-audit.yml", 'on:\n  schedule:\n    - cron: "0 20 * * *"\n'],
        ];
        expect(workflowsWithSchedule(files)).toEqual(["nightly-audit.yml"]);
    });

    it("正常な fixture では W12 / W15 / W17 とも違反 0 件", () => {
        const fixture = {
            on: { push: null, pull_request: null },
            jobs: { php: {}, "supply-chain-audit": {} },
        } as Workflow;
        expect(triggerNames(fixture)).toEqual(["pull_request", "push"]);
        expect(jobsWithCondition(fixture)).toEqual([]);
        expect(workflowsWithSchedule([["ci.yml", "on:\n  push:\n  pull_request:\n"]])).toEqual([]);
    });
```

> `as Workflow` は既存ファイルの fixture が使っている書き方
> (`const fixture: WorkflowJob = { steps: [...] }`) に合わせる。
> `on` の値が `null` になるのは `pull_request:` のように値を書かない YAML の parse 結果と同じ。

### TypeScript 適合チェック

- [ ] `triggerNames` / `jobsWithCondition` / `workflowsWithSchedule` に戻り値型 `string[]` を明示
- [ ] `workflow.on ?? {}` / `workflow.jobs ?? {}` で null 安全 (どちらも optional プロパティ)
- [ ] fixture の型注釈で `any` を使わない (`as Workflow` / `Array<[string, string]>`)
- [ ] `readdirSync` の import 追加漏れがないこと
- [ ] `pnpm lint` (ESLint) / `pnpm typecheck` が通ること

### テスト計画

- [ ] **fail を先に見る**: 施策 2 (テスト) を先にコミットせずとも、
      **施策 2 を書いた時点で W12 / W15 が赤くなる**ことを実測する (ci.yml がまだ schedule を持つため)。
      その後に施策 1 を適用して緑になることを確認する = テストファースト (思考原則 5)。
- [ ] W17 は**書いた時点で green** になる (`ci.yml` に schedule が残っている段階では赤 →
      施策 1 適用後に緑)。空振りしていないことは負のコントロールと
      「workflow ファイルが 1 本以上ある」assertion で担保する。
- [ ] 既存 W1〜W11 / W13 / W14a-c / W16 が引き続き green
- [ ] 追加した負のコントロール 5 本が green
- [ ] `pnpm test` 全体が green (`ci-workflow-inventory.test.ts` は root project の対象)
- [ ] 個別の `DatabaseTransactions` 等は無関係 (PHP テストを触らない)

### リスク

| リスク | 手当て |
|---|---|
| W12 の完全一致が将来のトリガー追加で偽赤になる | それが意図 (登録させる)。コメントに明記済み |
| W15 の全面禁止が正当な条件付き job をブロックする | deny-by-default。allowlist 化の手順をコメントに明記済み |
| `on` が boolean `true` として parse される parser 変更 | `triggerNames` が `[]` を返し **W12 が落ちる** = 静かに素通りしない。W17 も同じ経路で「schedule なし」と誤判定しうるが、W12 が先に落ちるので気付ける |
| W17 の FS 走査パスが誤っていて常に空 = 偽グリーン | 「workflow ファイルが 1 本以上ある」assertion を同じ it に入れて空振りを排除する |

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
定期的な検知が必要になったときの枠組みは **CI の外**に用意する。
どういう形で用意するかはオーナーの裁量であり、**本タスクでは代替を作らない**。
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
| 1 | `pnpm vitest run tests/js/architecture/ci-workflow-inventory.test.ts` | **全 green** (W12 / W15 / W17 が新しい契約で通る + 負のコントロール 5 本) |
| 2 | `pnpm vitest run tests/js/architecture/verification-commands-doc-sync.test.ts` | 全 green (V0〜V7) |
| 3 | `pnpm test` | 全 green (既存件数から**減っていない**こと。W12/W15 は差し替えなので総数は W17 + 負のコントロール分だけ増える) |
| 4 | `pnpm lint` / `pnpm typecheck` | クリーン |
| 5 | `pnpm run audit:gate` | 従来どおり PASS (判定ロジック無変更なので**ベースラインと同一の集計**になること) |
| 6 | `rg -n '(^\s*schedule:\|github\.event_name)' .github/workflows/ci.yml` | **0 hit** |
| 7 | `rg -n -i "nightly" AGENTS.md docs/ tests/ scripts/ .github/ --glob '!TODO-closed.md'` | **0 hit** |
| 8 | 変更ファイル allowlist (下記) の差集合 | **0 hit** (許可した 5 ファイル以外を触っていない) |

> **#6 の regex に注意** (design-review R1 [Warning]): `rg` (Rust regex) では `\|` は
> **alternation ではなく literal pipe** になる。`"^\s*schedule:\|github\.event_name"` と
> 書くと**両方とも拾えず 0 hit になり、消せていなくても green に見える**。
> 必ず括弧つきの alternation `'(^\s*schedule:|github\.event_name)'` を使い、
> 導入時に**わざと 1 hit する状態で試して検出できること**を確認すること。

#8 は「触っていないディレクトリを列挙する」形にすると列挙漏れ (`bootstrap/` /
`composer.json` / 別の `tests/`) を見逃すため、**許可ファイルの allowlist** で見る
(design-review R1 [Warning]):

```bash
git diff --name-only \
  | rg -v '^(\.github/workflows/ci\.yml|AGENTS\.md|docs/supply-chain/review-checklist\.md|tests/js/architecture/(ci-workflow-inventory|verification-commands-doc-sync)\.test\.ts)$'
```

期待結果は **0 hit** (devnotes 配下の設計文書は別コミットで入るため、
同一コミットに含める場合は allowlist に `devnotes/20260806-1634-ci-schedule-removal/` を足す)。

`composer test` は**流さない** (PHP 差分ゼロ / グローバルテストロックを無駄に占有しない)。
PHP を 1 行も触らないことを #8 で機械的に確認することが、`composer test` を省く根拠になる。

> **判定の正本は #1 の構造テスト、#6 / #7 の `rg` は補助**である
> (conceptual-review R1 [Warning])。`if: contains(github.event…)` のような言い換えは
> grep では拾えない。rg が見ているのは「読み手向けの語彙が残っていないか」であって
> 「機構が残っていないか」ではない。

### 負のコントロールの実測 (必須。報告に結果を書く)

fixture だけでなく**実ファイルを一時改変して gate が落ちること**を確認する (T113 の作法)。

**作業ツリーを壊さない手順** (design-review R1 [Warning]):
実測は**実装 worktree の中**で行う (AGENTS.md worktree 運用ルール)。
1 改変ごとに次を守る — 改変前に `git status --porcelain` を撮って**一時差分だけ**であることを
確認 → gate を走らせて fail を記録 → `git checkout -- <path>` で戻す →
**戻した直後に再度 `git status --porcelain` が改変前と一致することを確認**。
3 件をまとめて改変・まとめて revert しない (どの改変がどの gate を落としたかが不明になる)。

| # | 一時改変 | 期待して落ちる gate |
|---|---|---|
| N1 | `ci.yml` の `on:` に `schedule: [{cron: "0 20 * * *"}]` を戻す | **W12** (トリガー集合不一致) と **W17** (schedule を持つ workflow) の 2 本 |
| N2 | `php` job に `if: github.event_name != 'schedule'` を戻す | **W15** |
| N3 | `php` job に `if: "!contains(github.event_name, 'schedule')"` (言い換え) を入れる | **W15** (値ではなく有無を見ているため言い換えでも落ちる) |
| N4 | `.github/workflows/` に `schedule` だけを持つ workflow を 1 本一時新設 | **W17** のみ (W12 は ci.yml しか見ないので落ちない = W17 が必要だった証明) |

実装完了報告には「**どの gate が、どの改変で fail したか**」を N1〜N4 の粒度で明記する
(conceptual-review R1 [Suggestion])。特に N4 は W17 の存在意義そのものなので省略しない。

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

## 確認してほしい点

1. W17 の追加はスコープの膨張ではなく「目的達成に必要な最小追加」と言えるか。
   (本タスクは「小さいタスク。膨らませない」が明示的な制約である)
2. `workflowsWithSchedule` の純関数分離 (FS 走査と検査の分離) に偽グリーン経路は残っていないか。
   特に「workflow が 0 本のとき green」「parse 失敗を握りつぶす」経路。
3. 修正後の検証コマンド #6 / #8 と、負のコントロール N1〜N4 で
   「実装したが実は効いていない」を検出しきれるか。
4. 他に回収漏れ (schedule 前提の記述・条件・gate) が残っていないか。

全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
