# Round 2: Round 1 の指摘への対応

以下が Round 1 の [Warning] 4 件に対する対応です。

# 対応マトリクス: impl-review Round 1

## [Warning] `actions/cache@v6` / `actions/upload-artifact@v7` の版はローカル gate で担保されない

- 判断: 反論する (実装は変えない)
- 根拠: gate の `actionName()` が `@version` を落とすのは**意図した設計**である。版まで固定すると、
  無害な版上げのたびに `pnpm test` が偽赤になり、gate の信頼が落ちる。実装時 (2026-08-15) に
  GitHub の releases を実際に確認し、現行 major が `actions/cache@v6` /
  `actions/upload-artifact@v7` であることを確かめた。存在しない版なら CI が即 fail するので
  「無音で壊れる」経路ではない。
- 対応内容: 詳細設計書 (施策 3) に「実装時に確認した現行 major」を追記済み。コードは変更しない。

## [Warning] C14b の `cp` スタブが無条件で、設計記述 (条件付き) より広い

- 判断: 対応する
- 根拠: 設計は「条件付き `cp` スタブを置き、**退避の複製だけ**非ゼロを返させる」と書いており、
  無条件スタブは検査の意味を「cp を使う操作すべて」へ広げてしまう。指摘のとおり設計と実装のずれ。
- 対応内容: 宛先が `storage/browser-test-artifacts/` 配下のときだけ非ゼロを返し、
  それ以外は `/bin/cp` へ委譲する条件付きスタブに変更した (理由をコメントで明記)。

## [Warning] `browserProvisioningJsonScriptCommands()` が `Assert::isArray()` / `Assert::string()` を使っていない

- 判断: 反論する (振る舞いは変えず、理由をコードに残す)
- 根拠: 設計のコーディングルールにある段階的 narrow の目的は「`mixed` をそのまま反復しない」ことで、
  実装は `is_array()` → `is_string()` の順で同じく満たしている (PHPStan level 10 も緑)。
  一方、施策 5 の設計は「**想定外の型は違反として列挙する**(静かに素通りさせない)」と明記している。
  `Assert` は例外を投げるため、1 ファイルの型崩れでテストが止まり、
  **同じ実行で見つかるはずだった他ファイルの違反が失われる**。ここでの想定外の型は
  「前提の破れ」ではなく「報告すべき違反」なので Assert は使わない。
- 対応内容: 上記の理由を関数の docblock に追記した。

## [Warning] W18 / W19 の負のコントロールが検出関数を通っていない

- 判断: 対応する
- 根拠: 指摘のとおり。fixture に対して `expect(step.with?.["restore-keys"]).toBeDefined()` を
  書いても「検出器が空振りしていないこと」の証明にならない (検出器を一度も呼んでいない)。
- 対応内容: W18 / W19 の検査を純関数 `browserCacheViolations()` / `artifactCollectionViolations()` へ
  切り出し、**実 workflow の検査と負のコントロールが同じ関数を通る**ようにした。
  正のコントロール (合格 fixture で違反 0 件) も各 1 件足した。


---

## 変更後コード

### 1. `tests/js/architecture/ci-workflow-inventory.test.ts` — W18 / W19 を純関数へ切り出した

```typescript
/**
 * W18: ブラウザ実体キャッシュ段の違反を列挙する純関数。
 * 実 workflow と負のコントロールの **両方が同じ関数を通る** ようにして、
 * 検出器が空振りしていないことを fixture で示せるようにする。
 */
export function browserCacheViolations(steps: readonly WorkflowStep[]): string[] {
    const violations: string[] = [];
    const cache = steps.filter((s) => s.uses !== undefined && actionName(s.uses) === "actions/cache");
    if (cache.length !== 1) {
        return [`W18: actions/cache step がちょうど 1 つでない (${cache.length} 個)`];
    }

    const step = cache[0];
    if (String(step.with?.path ?? "") !== "~/.cache/ms-playwright") {
        violations.push("W18: cache path が ~/.cache/ms-playwright でない");
    }
    // key の 3 要素を個別に見る (設計意図との対応を明示する)
    const key = String(step.with?.key ?? "");
    for (const token of ["runner.os", "runner.arch", "hashFiles('pnpm-lock.yaml')"]) {
        if (!key.includes(token)) violations.push(`W18: cache key に ${token} が無い`);
    }
    // 部分一致復元は古い版のブラウザを溜め込む
    if (step.with?.["restore-keys"] !== undefined) {
        violations.push("W18: restore-keys を持っている (古い版のブラウザを溜め込む)");
    }

    return violations;
}

/**
 * W19: 失敗時の証跡回収段の違反を列挙する純関数。
 *
 * 「最後の step であること」を要求するのは意図的である
 * (回収より後ろに step を足すと、その step の失敗で証跡が回収されない窓ができる)。
 */
export function artifactCollectionViolations(steps: readonly WorkflowStep[]): string[] {
    const last = steps[steps.length - 1];
    if (last === undefined || last.uses === undefined || actionName(last.uses) !== "actions/upload-artifact") {
        return ["W19: 最後の step が actions/upload-artifact でない"];
    }

    const violations: string[] = [];
    // step-level の if。W15 が禁じているのは **job-level** の if であって別物である。
    if (last.if !== "failure()") {
        violations.push("W19: 失敗時だけ回収していない (if: failure() が無い)");
    }
    if (String(last.with?.path ?? "") !== "storage/browser-test-artifacts/") {
        violations.push("W19: 回収対象が storage/browser-test-artifacts/ でない");
    }
    // 名前と保持日数も契約である (変更・欠落を素通りさせない)
    if (String(last.with?.name ?? "") !== "browser-test-artifacts") {
        violations.push("W19: artifact 名が browser-test-artifacts でない");
    }
    if (Number(last.with?.["retention-days"]) !== 7) {
        violations.push("W19: retention-days が 7 でない");
    }

    return violations;
}

```

W18 / W19 の本体テスト (実 workflow を同じ関数へ通す):

```typescript
    it("W18: browser-tests が ~/.cache/ms-playwright をキャッシュし、restore-keys を持たないこと", () => {
        expect(browserCacheViolations(job(workflow, "browser-tests").steps ?? [])).toEqual([]);
    });

    it("W19: browser-tests の最後の step が失敗時の証跡回収であること", () => {
        expect(artifactCollectionViolations(job(workflow, "browser-tests").steps ?? [])).toEqual([]);
    });

```

負のコントロール (正のコントロールを含め、すべて同じ関数を通る):

```typescript
    /** W18 の正のコントロールに使う、合格する cache step。 */
    const validCacheStep: WorkflowStep = {
        uses: "actions/cache@v6",
        with: {
            path: "~/.cache/ms-playwright",
            key: "${{ runner.os }}-${{ runner.arch }}-ms-playwright-${{ hashFiles('pnpm-lock.yaml') }}",
        },
    };

    /** W19 の正のコントロールに使う、合格する回収 step。 */
    const validArtifactStep: WorkflowStep = {
        uses: "actions/upload-artifact@v7",
        if: "failure()",
        with: { name: "browser-test-artifacts", path: "storage/browser-test-artifacts/", "retention-days": 7 },
    };

    it("W18: 正常な fixture では違反 0 件 (負のコントロールの土台)", () => {
        expect(browserCacheViolations([validCacheStep])).toEqual([]);
    });

    it("W18: cache step が無い構成を検出する", () => {
        expect(browserCacheViolations([{ run: "composer test:browser" }])).toEqual([
            "W18: actions/cache step がちょうど 1 つでない (0 個)",
        ]);
    });

    it("W18: restore-keys を足した構成を検出する", () => {
        const step: WorkflowStep = {
            ...validCacheStep,
            with: { ...validCacheStep.with, "restore-keys": "os-ms-playwright-" },
        };
        expect(browserCacheViolations([step])).toContain(
            "W18: restore-keys を持っている (古い版のブラウザを溜め込む)",
        );
    });

    it("W18: key から runner.arch を落とした構成を検出する", () => {
        const step: WorkflowStep = {
            ...validCacheStep,
            with: { ...validCacheStep.with, key: "${{ runner.os }}-ms-playwright-${{ hashFiles('pnpm-lock.yaml') }}" },
        };
        expect(browserCacheViolations([step])).toContain("W18: cache key に runner.arch が無い");
    });

    it("W19: 正常な fixture では違反 0 件 (負のコントロールの土台)", () => {
        expect(artifactCollectionViolations([{ run: "composer test:browser" }, validArtifactStep])).toEqual([]);
    });

    it("W19: 常時アップロード (if 無し) を検出する", () => {
        const step: WorkflowStep = { ...validArtifactStep, if: undefined };
        expect(artifactCollectionViolations([step])).toContain(
            "W19: 失敗時だけ回収していない (if: failure() が無い)",
        );
    });

    it("W19: upload-artifact が最後の step でない構成を検出する", () => {
        expect(artifactCollectionViolations([validArtifactStep, { run: "echo done" }])).toEqual([
            "W19: 最後の step が actions/upload-artifact でない",
        ]);
    });

    it("W19: name / retention-days の欠落を検出する", () => {
        const step: WorkflowStep = { ...validArtifactStep, with: { path: "storage/browser-test-artifacts/" } };
        const violations = artifactCollectionViolations([step]);
        expect(violations).toContain("W19: artifact 名が browser-test-artifacts でない");
        expect(violations).toContain("W19: retention-days が 7 でない");
    });

```

### 2. `scripts/run-browser-test.contract.test.ts` — C14b の cp スタブを条件付きにした

```typescript
    it("C14b: 複製に失敗してもレーンの終了コードを上書きしない", { timeout: 30_000 }, () => {
        // mkdir -p 側と cp 側は別の分岐なので、片方だけでは不変条件を固定できない。
        //
        // スタブは **条件付き** にする: 退避先 (storage/browser-test-artifacts/) を宛先に持つ
        // 複製だけ非ゼロを返し、それ以外は実 cp へ委譲する。無条件スタブにすると
        // 「退避以外で cp を使う将来の変更」にも反応してしまい、検査の意味が広がりすぎる。
        const run = runInSandbox(realSource(), {
            env: { BROWSER_TEST_LANES: "chromium" },
            failingLanes: [1],
            failExitCode: 23,
            pestWritesScreenshots: true,
            extraStubs: {
                cp: [
                    "#!/usr/bin/env bash",
                    "set -u",
                    'for a in "$@"; do',
                    '  case "$a" in',
                    "    storage/browser-test-artifacts/*|*/storage/browser-test-artifacts/*)",
                    '      echo "cp stub: 退避の複製だけ失敗させる" >&2',
                    "      exit 1 ;;",
                    "  esac",
                    "done",
                    'exec /bin/cp "$@"',
                    "",
                ].join("\n"),
            },
        });

        expect(run.stderr).toContain("WARNING");
        expect(run.status).toBe(23);
    });
});

```

### 3. `tests/Architecture/BrowserProvisioningEntrypointTest.php` — Assert を使わない理由を docblock に明記

```php
/**
 * composer.json / package.json の `scripts` から実行コマンド文字列を取り出す (純関数)。
 *
 * `composer.json` の値は **文字列と配列の両方**を取るので両方を受ける。
 * **想定外の型は違反として列挙する** (静かに素通りさせない)。
 *
 * ここで `Assert::isArray()` / `Assert::string()` を使わないのは、Assert が投げる例外で
 * テストが即座に止まり、**同じ実行で見つかるはずだった他ファイルの違反が失われる**ためである
 * (想定外の型はここでは「報告すべき違反」であって「前提の破れ」ではない)。
 * 段階的な narrow という意図は `is_array()` → `is_string()` の順で同じく満たしている。
 *
 * @return array{commands: list<string>, errors: list<string>}
 */
function browserProvisioningJsonScriptCommands(string $relative, string $contents): array
{
    $decoded = json_decode($contents, true);
    if (! is_array($decoded)) {
        return ['commands' => [], 'errors' => ["{$relative}: JSON として読めない"]];
    }

    $scripts = $decoded['scripts'] ?? [];
    if (! is_array($scripts)) {
        return ['commands' => [], 'errors' => ["{$relative}: scripts が想定外の型 (配列でない)"]];
    }

    $commands = [];
    $errors = [];
    foreach ($scripts as $name => $value) {
        $key = (string) $name;
        if (is_string($value)) {
            $commands[] = $value;

            continue;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    $commands[] = $item;

                    continue;
                }
                $errors[] = "{$relative}: scripts.{$key} の要素が想定外の型 (文字列でない)";
            }

            continue;
        }
        $errors[] = "{$relative}: scripts.{$key} が想定外の型 (文字列でも配列でもない)";
    }

    return ['commands' => $commands, 'errors' => $errors];
}

```

---

## 再実行したテスト結果

- `composer phpstan`: No errors (level 10, 915 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm exec vitest run scripts/run-browser-test.contract.test.ts tests/js/architecture/ci-workflow-inventory.test.ts`: 75 passed
- `vendor/bin/pest tests/Architecture/BrowserProvisioningEntrypointTest.php`: 9 passed

残り 2 件の [Warning] については上の対応マトリクスに反論理由を書きました。
これらの判断が妥当かを含めて再レビューし、最後に全体判定を **APPROVED** または
**CHANGES_REQUESTED** の 1 語で明記してください。
