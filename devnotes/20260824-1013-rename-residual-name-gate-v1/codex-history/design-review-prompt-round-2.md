# design-review Round 2

Round 1 の [Warning] 3 件はすべて対応しました。対応マトリクスは
`devnotes/20260824-1013-rename-residual-name-gate-v1/codex-history/design-review-decisions-round-1.md` です。
以下に修正内容と、修正を実測で裏取りした結果を示します。**再レビューをお願いします。**

## 修正 1: N-4 (l) を追加（位置集合強化の価値を証明する負例）

ご提案どおり「実出現 2 件・申告 2 件だが 2 本の一意な周辺文字列が同じ出現を指す」ケースを追加しました。

```php
    // (l) ★件数は一致するが 2 つの申告が同じ出現を指し、別の 1 件が未申告になる入力。
    //     件数の比較だけなら緑になるため、**出現位置の集合一致でなければ捕まらない**。
    //     この 1 ケースが「位置集合まで強める価値」の実測である。
    $twoOccurrences = "行 1: T001 で {$seeder} を作った\n行 2: T002 で {$seeder} を消した\n";
    $sameSpotTwice = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder}", 'reason' => $reason],
                ['needle' => "で {$seeder} を作った", 'reason' => $reason],
            ],
        ],
    ];
    // 申告 2 件・実出現 2 件 = 件数は一致する (前提の確認)。
    expect(count($sameSpotTwice['docs/record.md'][$seeder]))
        ->toBe(count(bughuntNamingOffsetsOf($twoOccurrences, $seeder)));

    $sameSpotViolations = bughuntNamingViolationsIn('docs/record.md', $twoOccurrences, $sameSpotTwice);
    expect($sameSpotViolations)->toHaveCount(2);
    expect(implode("\n", $sameSpotViolations))->toContain('申告外の出現がある');
    expect(implode("\n", $sameSpotViolations))->toContain('二重に指している');
```

素の PHP で実リポジトリに対して実走させた結果 (述語は詳細設計のコードそのまま):

```
  (l) count=2
   * 申告外の出現がある: docs/record.md / 旧名 BughuntBillingSeeder (家系名 BughuntStripeSyncSeeder) / 実出現 2 件・申告 2 件 / 未申告の位置 66 — 改名の取りこぼしなら家系名へ直すこと。記録として残すなら、記録を書き換えるのではなく、申告を足す・移す・外すこと
   * 申告が同じ出現を二重に指している: docs/record.md / 旧名 BughuntBillingSeeder / 実出現 2 件・申告 2 件 — 記録を書き換えるのではなく、申告を足す・移す・外すこと
ok: N-4l 件数一致・同一位置の二重申告 → 赤 2 件
ok: N-4l 件数比較だけなら緑 (穴の実証)
```

あわせて詳細設計に「### 判定の含意（正典の 3 方向が漏れないことの根拠）」を新設し、
(1) 有効な申告は必ず実出現位置を指す（`$declared ⊆ $actual`）/ (2) 未申告差分と重複の 2 つで方向 1・3 が閉じ、
周辺文字列 0 回で方向 2 が閉じる / (3) 逆向き差分 `$declared − $actual` は常に空なので**持たない**
（走査器共通規約 (d) 集めた結果を判定に使わない形を作らない）/ (4) 件数不一致は 3 方向が含意する、を明記しました。

## 修正 2: テストファースト手順 2 を事実へ修正

```
2. **突き合わせを一時的に「申告の本数と実出現数の比較だけ」に落として実行する**
   → このとき**緑になってしまうのは `(b)` と `(l)` の 2 ケースだけ**である（= 現行の穴 1 の再現）。
   その後、出現位置の集合一致を入れて赤にする。
   - `(b)` すり替え: 申告 1 件・実出現 1 件で件数が一致するため、件数比較では緑
   - `(l)` 同一位置の二重申告: 申告 2 件・実出現 2 件で件数が一致するため、件数比較では緑。
     **基底実装 (aigenba) の「件数一致 + needle 一意」でも同じ入力は通り得る**ので、
     位置集合まで強める価値をここで固定する
   - `(j)` 二重申告 (実出現 1 件・申告 2 件) と `(k)` 周辺文字列が旧名を 2 回含む
     (実出現 2 件・申告 1 件) は**件数比較でも赤になる**。したがってこの 2 つは
     「位置集合の必要性」の証明ではなく、**申告の入力契約**（周辺文字列は実物にちょうど 1 回・
     旧名をちょうど 1 回含む）の負例として位置づける。手順 2 の記録に混ぜない。
```

## 修正 3: 添字参照を implode + toContain へ統一し、件数も固定

```php
    $duplicateViolations = bughuntNamingViolationsIn('docs/record.md', $body, $duplicated);
    expect($duplicateViolations)->toHaveCount(1);
    expect(implode("\n", $duplicateViolations))->toContain('二重に指している');
```

```php
    $ambiguousViolations = bughuntNamingViolationsIn('docs/record.md', "T001 で {$seeder} と {$seeder}\n", $ambiguous);
    expect($ambiguousViolations)->toHaveCount(2);
    expect(implode("\n", $ambiguousViolations))->toContain('ちょうど 1 回含まない');
```

`(k)` の違反が 2 件であることは実測で確認しました（周辺文字列の契約違反 1 件 + 申告外の出現 1 件。
後者は「実出現 2 件・申告 0 件 / 未申告の位置 9, 34」と出ます）。

## 併せて直した細部

失敗メッセージの日本語が「記録として残すなら 記録を書き換えるのではなく…」と二重になっていたため、
「— 改名の取りこぼしなら家系名へ直すこと。記録として残すなら、記録を書き換えるのではなく、申告を足す・移す・外すこと」
に整えました（`申告を足す・移す・外す` の文言は N-4 (b) が実測する対象なので保持しています）。

## 実測の再走

修正後の述語 + N-1〜N-4 相当を素の PHP で実リポジトリに対して再走し、**51 assert すべて緑**、
N-1 は 9925 ファイル / 違反 0 件 / 0.42 秒でした。

## 残っている論点（ご意見があれば）

- `(k)` の期待件数 2 は「周辺文字列の契約違反」と「申告外の出現」が同時に出ることに依存します。
  実装の分岐順序を変えると件数が変わるため、契約として固定してよいか（固定するのが退化検出に有利と判断しました）。
- 逆向き差分を持たない判断（証明 3）を docblock にも書くべきか、詳細設計だけで足りるか。
