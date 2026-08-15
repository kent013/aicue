## 施策別判定

### 施策 1: APPROVE

`exit 1`だけを正常な依存不足として扱い、その他を`undeterminable`へ倒す修正は適切です。権限観測も依存不足時だけに限定され、特権経路の条件が明確になりました。

### 施策 2: REQUEST_CHANGES

[Warning] C14の失敗条件が成立しません。

実行前に`storage/browser-test-artifacts`と同名のファイルを置いても、レーン開始前の次の処理で削除されます。

```bash
rm -rf "${ARTIFACT_DIR}"
```

その後の`mkdir -p`は成功するため、WARNINGを検証できません。

修正案: pestスタブがスクリーンショットを書き込むタイミングで、初期化後に同名ファイルを作成してください。

```text
1. run-browser-test.sh が ARTIFACT_DIR を初期化
2. pestスタブが screenshot を作成
3. pestスタブが storage/browser-test-artifacts を通常ファイルとして作成
4. collect_lane_artifacts() の mkdir -p が失敗
5. WARNINGを出し、元のレーン結果を維持
```

成功レーンだけでなく、pestが任意の非ゼロ、例えば`exit 23`を返したケースでも最終終了コードが`23`のままであることを確認すると、「結果を上書きしない」という契約を直接検証できます。

### 施策 3: APPROVE

CIの実行順序、キャッシュ条件、失敗時のみの証跡回収に問題はありません。

### 施策 4: REQUEST_CHANGES

[Warning] W20はshellの行継続で迂回できます。

現在の検査は`runLines()`で各行を個別に照合するため、次の実行を検出できません。

```yaml
run: |
  pnpm exec playwright \
    install chromium webkit
```

修正案: workflowの各`run`を検査する前に、shellのバックスラッシュ改行を空白へ正規化してください。

```typescript
function normalizeShellContinuations(source: string): string {
    return source.replace(/\\\r?\n\s*/g, " ");
}
```

そのうえで正規化した実行内容へ`PLAYWRIGHT_INSTALL_PATTERN`を適用し、行継続形式の負のコントロールを追加してください。

### 施策 5: REQUEST_CHANGES

[Warning] `scripts/`の走査が`glob()`では再帰的になりません。

設計は「`scripts/`配下のshellスクリプト」と「将来追加されたスクリプトも自動で母集団に入る」としていますが、通常の`scripts/*.sh`相当の`glob()`では、次を検出できません。

```text
scripts/tools/install-browser.sh
```

修正案: `RecursiveDirectoryIterator`と`RecursiveIteratorIterator`で再帰走査し、拡張子`.sh`の通常ファイルを収集してください。ネストしたshellスクリプトの負のコントロールも必要です。

[Warning] shellおよびDockerfileの行継続も現在の行単位検査を迂回します。

```bash
pnpm exec playwright \
    install chromium
```

```dockerfile
RUN pnpm exec playwright \
    install chromium
```

修正案: コメント除去後、`\\`と改行の組を空白へ正規化してからパターンを適用してください。shellとDockerfileそれぞれに負のコントロールを追加します。

### 施策 6: REQUEST_CHANGES

[Warning] 設計書内のテスト件数と対象IDが更新しきれていません。

以下がRound 3の内容と不一致です。

- 層1が「ケース数の下限 (17)」のまま。正しくは`19`
- 層3が「S1〜S7」のまま。S4b/S4cを含む表現へ更新が必要
- テスト計画が「C9〜C13」のままで、C14/C15を含まない
- 実装順序3も「C9〜C13」のままで、C14/C15を含まない

修正案: 範囲表現を避けて「C9〜C15（欠番なし）」とするか、対象IDを明示列挙してください。S系も「S1〜S7 + S4b/S4c」など、実際の採番と一致させます。

C14のfixtureは施策2の指摘どおり、初期化後に失敗条件を作る必要があります。

### 施策 7: APPROVE

`.gitignore`をC15で機械検査し、docsは保証対象外と明記する整理は妥当です。保証範囲も正確です。

## 全体判定

**CHANGES_REQUESTED**

Round 2のCritical事項は解消されており、新たなCriticalはありません。残る修正点は、C14のfixture成立条件、実行コマンドの行継続対策、`scripts/`の再帰走査、テストID記述の同期です。これらを直せば詳細設計として承認可能です。