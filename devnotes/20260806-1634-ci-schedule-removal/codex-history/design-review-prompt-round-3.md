Round 2 の指摘への対応マトリクスと、修正後の詳細設計書 (全文) です。最終確認をお願いします。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 5 / Suggestion 1)。
施策 2 / 3 / 5 は APPROVE、施策 1 / 4 は W17 の文書反映漏れで REQUEST_CHANGES。
**全件採用**した (いずれも記述漏れと手順の精度で、設計方針の変更を伴わない)。

## [Warning] 施策 1: ci.yml の歯止め説明に W17 が抜けている

- 判断: **対応する**
- 根拠: W17 を足したのに ci.yml のコメントが W12/W15 しか挙げていないと、
  「別 workflow に置けば通る」と読める。歯止めの一覧が不完全なコメントは
  それ自体が抜け道の案内になる。
- 対応内容: ci.yml の再導入禁止コメント 4. に W17 を追記。

## [Warning] 施策 4: review-checklist §6 の機械ゲート説明にも W17 が抜けている

- 判断: **対応する**
- 根拠: 同上。§6 は「実装契約の文書側の正本」なので、機械ゲートの一覧が実体と一致しないと
  次にここを読む人が W17 の存在を知らないまま緩める。
- 対応内容: §6 の該当文を W12 / W15 / W17 の 3 本に更新。

## [Warning] 検証コマンド表 #6 に誤った正規表現が残っている

- 判断: **対応する**
- 根拠: 表のセル内で `|` を `\|` とエスケープしているのは Markdown テーブルの都合だが、
  **そのままコピペすると literal pipe になって偽グリーンになる**。
  「コピペされる場所に間違ったコマンドを置く」のは検証手順として不合格。
- 対応内容: #6 を表から外し、**コードブロック**として提示する
  (Markdown のエスケープが要らない形にして転記ミスの余地を消す)。

## [Warning] #8 が untracked ファイルを検出しない

- 判断: **対応する**
- 根拠: 指摘のとおり。特に本タスクは「新しい workflow ファイルを作らない」ことが
  スコープ境界そのものなので、**untracked の新規 workflow を見逃す検査**では意味がない。
- 対応内容: `git diff --name-only` に `git ls-files --others --exclude-standard` を
  合わせて `sort -u` してから allowlist と突き合わせる形に修正。

## [Warning] 負のコントロール後の復元手順が本実装まで破棄する / N4 は git checkout で消えない

- 判断: **対応する**
- 根拠: `git checkout -- <path>` は「HEAD の内容に戻す」ので、
  未コミットの本実装ごと消える。N4 で作る untracked ファイルは checkout では消えない。
  実測手順が実装を壊すのは本末転倒。
- 対応内容: **本実装を先にコミットしてから実測する**手順に変更
  (N1〜N3 は `git checkout -- <path>` が安全に効く / N4 は `rm` + 不存在確認)。
  各実測後に `git status --porcelain` が**空**であることを確認する、と明記。

## [Suggestion] `workflowsWithSchedule` の戻り値を `.sort()` して安定させる

- 判断: **対応する** (コストゼロ)
- 根拠: `readdirSync` の順序は環境依存でありうる。診断メッセージが環境で揺れる理由が無い。
- 対応内容: 実装に `.sort()` を追加。N4 の fixture 期待値も昇順で書く。

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
#    W12 (本ファイルのトリガー集合の完全一致) / W15 (job-level if の不在) /
#    W17 (.github/workflows 配下の全 workflow に schedule が無いこと) が再導入を止める。
#    別ファイルに新しい workflow を作って定期実行を復活させる経路も W17 が塞ぐ。
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
        .map(([name]) => name)
        .sort(); // 診断メッセージを readdirSync の順序に依存させない (design-review R2 [Suggestion])
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
  `tests/js/architecture/ci-workflow-inventory.test.ts` の
  W12 (ci.yml のトリガー集合の完全一致) / W15 (job-level `if` の不在) /
  **W17 (`.github/workflows/` 配下の全 workflow に `schedule` が無いこと)** が
  再導入を機械的に止める。別 workflow を新設して定期実行を戻す経路も W17 が塞ぐ。

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
| 6 | 下記コードブロック #6 (ci.yml の schedule 由来キー・式の残置検査) | **0 hit** |
| 7 | 下記コードブロック #7 (`nightly` の語彙残置検査) | **0 hit** |
| 8 | 下記コードブロック #8 (変更ファイルの allowlist 検査) | **0 hit** |

> **#6 / #7 / #8 は表のセルに書かない** (design-review R2 [Warning])。
> Markdown テーブルではパイプを `\|` とエスケープする必要があり、
> **そのままコピペすると `rg` (Rust regex) が literal pipe として扱って
> 何も拾わず 0 hit = 偽グリーンになる**。コピペされる形で置くこと。

```bash
# 6: ci.yml に schedule 節・トリガー依存条件が残っていないか (0 hit が期待値)
rg -n '(^\s*schedule:|github\.event_name)' .github/workflows/ci.yml

# 7: 除去済み機構の語彙が残っていないか (0 hit が期待値)
#    TODO-closed.md は過去の作業記録なので除外する
rg -n -i 'nightly' AGENTS.md docs/ tests/ scripts/ .github/ --glob '!TODO-closed.md'

# 8: 許可した 5 ファイル以外を触っていないか (0 hit が期待値)
#    untracked も含めること — 新しい workflow ファイルを誤って残す事故は
#    `git diff` だけでは検出できない (design-review R2 [Warning])
{
  git diff --name-only
  git ls-files --others --exclude-standard
} | sort -u \
  | rg -v '^(\.github/workflows/ci\.yml|AGENTS\.md|docs/supply-chain/review-checklist\.md|tests/js/architecture/(ci-workflow-inventory|verification-commands-doc-sync)\.test\.ts)$'
```

#8 の allowlist を「触っていないディレクトリの列挙」にしないのは、列挙漏れ
(`bootstrap/` / `composer.json` / 別の `tests/`) を見逃すため (deny-by-default にする)。
設計文書 (`devnotes/20260806-1634-ci-schedule-removal/`) を同一コミットに含める場合のみ
allowlist に足す。

**#6 と #8 は導入時に「わざと 1 hit する状態」で試し、検出できることを確認する**
(検証コマンド自身が空振りしていないことの確認)。

`composer test` は**流さない** (PHP 差分ゼロ / グローバルテストロックを無駄に占有しない)。
PHP を 1 行も触らないことを #8 で機械的に確認することが、`composer test` を省く根拠になる。

> **判定の正本は #1 の構造テスト、#6 / #7 の `rg` は補助**である
> (conceptual-review R1 [Warning])。`if: contains(github.event…)` のような言い換えは
> grep では拾えない。rg が見ているのは「読み手向けの語彙が残っていないか」であって
> 「機構が残っていないか」ではない。

### 負のコントロールの実測 (必須。報告に結果を書く)

fixture だけでなく**実ファイルを一時改変して gate が落ちること**を確認する (T113 の作法)。

**作業ツリーを壊さない手順** (design-review R1 / R2 [Warning]):

1. 実測は**実装 worktree の中**で行う (AGENTS.md worktree 運用ルール)。
2. **本実装を先にコミットしてから実測する**。`git checkout -- <path>` は
   「HEAD の内容に戻す」ので、未コミットの本実装ごと消える。
   コミット済みなら復元が安全に効く (実測後に amend したければそこで行う)。
3. 実測開始前に `git status --porcelain` が**空**であることを確認する。
4. **1 改変ずつ**行う (まとめて改変するとどの改変がどの gate を落としたか分からなくなる)。
   - N1〜N3 (tracked ファイルの改変): 改変 → gate 実行 → fail を記録 →
     `git checkout -- <path>` → `git status --porcelain` が**空**に戻ったことを確認。
   - N4 (untracked ファイルの新設): `git checkout` では消えない。
     `rm .github/workflows/<一時ファイル名>` で明示削除し、
     `git status --porcelain` が**空**であること (= untracked が残っていないこと) を確認する。

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

残件が無ければ全体判定 APPROVED を明示してください。
残件がある場合は [Critical] / [Warning] を明示し、修正案を添えてください。
