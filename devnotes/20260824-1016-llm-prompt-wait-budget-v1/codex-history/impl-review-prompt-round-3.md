# Round 3: Round 2 の指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Warning] `$parsed === null && $parseErrors === []` の guard に負例が無い (発火しない防御コード)

- 判断: **対応する** (提示された 3 択のうち **3 つ目**を採る)
- 根拠: 指摘は正しい。Round 1 で足した guard は公開口から到達できず、
  AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の (1) 負例と正例、
  および共通規約 (c)「検出力は負例で裏取りする」を満たせない。
  一方で「元へ戻すだけ」だと、段 2 の fail-closed が**共有ヘルパの契約に暗黙に依存する**
  状態が文書にも検査にも残らない。3 つ目の案 (共有ヘルパの契約を機械で表現・検査し、
  読み取り器側に到達不能分岐を持たせない) が両方を満たす。
  採用時債務で凍結された `tests/Support/PromptYaml.php` は**1 バイトも触らない**。
- 対応内容:
  1. `PromptWaitBudget::read()` の guard を撤去し、Round 1 提示時点の形へ戻した
     (到達不能分岐を残さない)。
  2. 自己テストへ 1 本追加:
     「共有ヘルパは解決不能形で必ず理由を積む (段 2 の fail-closed が依存する前提)」。
     `broken.yaml` / `list-top-level.yaml` について
     `PromptYaml::parseOrFail()` が `null` を返し、かつ理由列が**空でない**ことを固定する。
     母集団は既存の見本 2 本で、実際に実行される検査である (防御コードではない)。
  3. 読み取り器の docblock 段 2 に「この fail-closed は共有ヘルパの契約に依存し、
     その前提は自己テストが固定する」「到達不能な guard は積まない (裏取りできないため)」
     を明記した。
- 自己テストの本数: 5 → **6**。

## 修正後の該当箇所 (全文)

### tests/Support/PromptWaitBudget.php — 段 2 の docblock と read()
```php
 *         にするため、段 2 に混ぜると「構文が壊れている」と区別できなくなる。
 *         走査由来のパスでは起きないが `requirePositive()` は名前から組んだパスを受ける
 *         (prompt の改名で現実に起きる)。
 *   段 2: parse 不能 / 最上位が map でない → `PromptYaml::parseOrFail()` が積む既存の 2 ラベル。
 *         ★段 2 の fail-closed は**共有ヘルパの契約に依存する** — 「null を返すときは
 *         必ず理由を 1 件以上積む」ことが前提であり、これが崩れると `violations()` だけが
 *         空 (= 適合) を返して公開 2 口が非対称になる。**前提そのものを自己テストが
 *         明示的に固定する** (`PromptWaitBudgetTest` の「共有ヘルパは解決不能形で必ず理由を積む」)。
 *         読み取り器側に到達不能な guard を積む形は採らない (発火しない防御コードは
 *         裏取りできず、AGENTS.md 共通規約 (c) を満たせないため)。
 *         **本クラスは自前の `catch` を書かない** (分類は既存の共有ヘルパに従う)。
 *         ★ただし同ヘルパは `Yaml::parseFile()` の投げる `Throwable` をまとめて
 *         「parse 失敗」へ分類するため、**構文エラーと vendor 内部のエラーの区別までは
 *         保証しない** (ヘルパは採用時債務として凍結されており本 PR では変えない)。
 *   段 3: 上記 5 類型 → `evaluate()` が積む。
 *
        …
        return $timeout;
    }

    /**
     * 読み取りの 3 段 (ファイル存在 → parse → 判定)。
     *
     * @return array{timeout: int|null, violations: list<string>}
     */
    private static function read(string $absolutePath, string $label): array
    {
        if (! is_file($absolutePath)) {
            return self::rejected("{$label}: prompt YAML が無い ({$absolutePath})");
        }

        /** @var list<string> $parseErrors */
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($absolutePath, $parseErrors);
        if ($parsed === null) {
            return ['timeout' => null, 'violations' => $parseErrors];
        }

```

### tests/Unit/Architecture/PromptWaitBudgetTest.php — 追加した 6 本目
```php
test('共有ヘルパは解決不能形で必ず理由を積む (段 2 の fail-closed が依存する前提)', function (): void {
    // ★読み取り器の段 2 は `PromptYaml::parseOrFail()` が積んだ理由をそのまま違反にする。
    //   「null を返すのに理由が空」だと violations() だけが空 (= 適合) を返し、
    //   requirePositive() との間に**非対称**が生まれる (violations 側が fail-open)。
    //   到達不能な guard を読み取り器へ積む代わりに、**依存している前提そのもの**を固定する。
    foreach (['broken.yaml', 'list-top-level.yaml'] as $name) {
        /** @var list<string> $violations */
        $violations = [];
        $parsed = PromptYaml::parseOrFail(promptWaitBudgetFixtureDir().'/'.$name, $violations);

        expect($parsed)->toBeNull("{$name} は解決不能形として null を返すこと");
        expect($violations)->not->toBe([], "{$name}: 共有ヘルパが理由を積まずに null を返した");
    }
});
```

## 再検証の結果 (修正後)

- 自己テスト単独: 6 tests / 6 passed / 26 assertions
- `vendor/bin/pint --test`: passed
- `composer phpstan`: level 10 / 1114 files / No errors
- `composer test`: 7377 tests / 7375 passed / 0 failed / 2 skipped / 5 risky / 34675 assertions

## 質問

Round 2 の [Warning] への対応 (到達不能 guard の撤去 + 共有ヘルパ契約の機械的固定) で
全体判定を再評価せよ。
