## 施策別判定

### 施策 1: REQUEST_CHANGES

[Critical] `classify_deps()` が missing と認める終了コードの範囲が広すぎます。

現在は次の条件です。

```bash
if [ "${code}" != "0" ] && ...
```

Playwright 1.61.1 の正常な不足検出は `process.exitCode = 1` です。終了コード `2`、`126`、`137` などでも、途中まで出力された missing marker が残っていれば `missing` と分類され、`--with-deps` の特権経路へ進みます。「終了コードと文言の一致時だけ確定」「判定不能は拒否側」という設計に反します。

修正案:

```bash
if [ "${code}" = "1" ] && [ "${out#*"${DEPS_MISSING_MARKER}"}" != "${out}" ]; then
    printf 'missing\n'
    return 0
fi
```

あわせて以下を自己テストへ追加してください。

- `classify_deps 2 "<missing 文言>"` → `undeterminable`
- `classify_deps 137 "<missing 文言>"` → `undeterminable`

対象ブラウザの配列化と、必要時だけ `detect_privilege()` を呼ぶ修正は適切です。

### 施策 2: REQUEST_CHANGES

[Warning] `cp` だけでなく、直前の `mkdir -p` も証跡退避の失敗として扱う必要があります。

現在の設計では、権限不足や容量不足で `mkdir -p` が失敗すると、`set -e` によりスクリプトが終了し、Browser テスト本体の終了コードを失います。

修正案:

```bash
if ! mkdir -p "${ARTIFACT_DIR}/${lane}"; then
    echo "WARNING: ${lane} レーンの証跡退避先を作成できませんでした (${ARTIFACT_DIR}/${lane})" >&2
    return 0
fi

if ! cp -R "${SCREENSHOT_DIR}/." "${ARTIFACT_DIR}/${lane}/"; then
    echo "WARNING: ${lane} レーンの証跡を退避できませんでした (${SCREENSHOT_DIR})" >&2
fi
```

契約テストにも、`mkdir` または退避先作成を失敗させても元のテスト終了コードが維持され、警告が出るケースを追加してください。

C11を2レーンの実際の消去順序に合わせた点は適切です。

### 施策 3: APPROVE

キャッシュ、単一の導入スクリプト、失敗時の証跡回収という順序は整合しています。`continue-on-error` を使わず、証跡が存在しない失敗を許容する判断も妥当です。

実装時のAction major確認を必須手順にした点も十分です。

### 施策 4: REQUEST_CHANGES

[Warning] W20が空白差分を見逃します。

施策5では `/\bplaywright\s+install\b/` に強化していますが、workflowは施策5の走査対象外であり、W20は依然として次の部分一致です。

```typescript
l.includes("playwright install")
```

したがって、以下がCI workflow内で検出されません。

```yaml
run: pnpm exec playwright   install chromium
```

修正案:

```typescript
const PLAYWRIGHT_INSTALL_PATTERN = /\bplaywright\s+install\b/;

const hits = runLines(job(workflow, name)).filter((line) =>
    PLAYWRIGHT_INSTALL_PATTERN.test(line),
);
```

空白を増やした負のコントロールも追加してください。

[Warning] W19が設計上の契約であるartifact名と保持日数を固定していません。

現状の検査では、次の変更が緑のままです。

- `name` の変更・欠落
- `retention-days` の変更・欠落

修正案:

```typescript
expect(String(last?.with?.name ?? "")).toBe("browser-test-artifacts");
expect(Number(last?.with?.["retention-days"])).toBe(7);
```

それぞれ少なくとも1件の負のコントロールを追加してください。

### 施策 5: APPROVE

Round 1の指摘は解消されています。

- `string | list<string>` の正規化
- 想定外JSON型のfail-closed
- 空白差分を許容する正規表現
- executable bitの明示的な契約

実装時には、`json_decode()` 由来の `mixed` をそのまま反復せず、`Assert::isArray()`、`Assert::string()`などで段階的にnarrowしてください。

### 施策 6: REQUEST_CHANGES

[Critical] 施策1の終了コード分類問題が、sandbox契約で検出されません。

S4は「想定外の出力」だけです。missing markerを出した後に異常終了したケースがないため、現在の `code != 0` 実装を許してしまいます。

修正案として、次のsandboxケースを追加してください。

- missing marker + exit 2 → install系0回、exit 1、`undeterminable-deps`
- missing marker + exit 137 → install系0回、exit 1、`undeterminable-deps`
- 両ケースとも `sudo` 未起動

[Warning] 証跡退避失敗時の契約に、`mkdir -p` 失敗を含めてください。`cp` だけを守っても「診断補助の失敗は合否を上書きしない」という不変条件を完全には保証できません。

実CLI smokeの対象限定と`status === null`の明示失敗は適切です。

### 施策 7: REQUEST_CHANGES

[Warning] 「docs / .gitignore に対する不変条件は施策5のgateが担う」という記述は事実と一致しません。

施策5が走査するのは、実行経路となるshell、JSON scripts、Dockerfileです。以下は検査しません。

- `docs/testing-browser.md`
- `.gitignore`
- artifact退避先のignore登録

修正案は次のいずれかです。

1. `.gitignore`に`/storage/browser-test-artifacts/`が存在することをArchitectureテストへ追加する。
2. docsは説明資料であり機械的不変条件にはしない、と保証範囲を正確に記載する。

少なくとも`.gitignore`は、登録漏れによりBrowserテスト実行後のworktreeが恒常的にdirtyになるため、機械検査を推奨します。

## 全体判定

**CHANGES_REQUESTED**

Round 1の主要な矛盾は解消されています。残る重要点は、異常終了を`deps=missing`として特権経路へ進めないことです。加えて、証跡退避失敗の扱いを`mkdir`まで広げ、workflow側の一元化gateを空白差分で迂回できないようにすれば、実装へ進める設計になります。