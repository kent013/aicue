## 全体判定: CHANGES_REQUESTED

Round 1 の主要指摘は概ね適切に反映されています。ただし C2 の修正方針は、**unlabeled の一括削除リスクは解消するものの、細工された provenance を持つ foreign DB の保護には不足**しています。また A1/A2/C1 に設計記述と実挙動の不一致があります。

### A1: REQUEST_CHANGES

[Warning] P13 の PHP 文字列評価が誤っています。

PHP の単一引用符では、次の評価になります。

```php
'/\\R/'    // 評価後は /\R/   → PCRE の改行クラス
'/\\\\R/'  // 評価後は /\\R/  → リテラルの \ + R
```

詳細設計の P13 は `'/\\R/'` を非検出としていますが、これは検出対象です。

修正案:

- P13 を PHP ソース上の `'/\\\\R/'` に修正する
- `'/\\R/'` を正コントロールとして追加する
- double-quoted 文字列も含め、抽出器が「PHP評価後の値」をどう復元するか明記する
- テスト計画の「P1〜P11」を「P1〜P13以降」に更新する

### A2: REQUEST_CHANGES

[Warning] `ps` 不在時の説明が現行コードと一致しません。

提示された `_gtl_probe_process_group()` は `pgid` が3回とも空ならループ後に `_gtl_die` します。したがって、`|| pgid=""` を加えても「ps 不在なら通す」挙動にはなりません。

修正案:

- `ps` 不在を許容するなら、関数冒頭で `command -v ps` を検査して明示的に return する
- 許容しないなら、「ps 不在では fail」と設計・C25・skip 方針を統一する
- `ps` 不在を模した contract test を追加する

### B1: APPROVE

Round 1 の指摘は解消されています。GET×web への見出し変更と `runLines()` の採用により、台帳の意味と W16 の検出力が一致しています。

### B2: APPROVE

マーカー範囲限定、V0/V7、AGENTS.md の圧縮はいずれも妥当です。

[Suggestion] テスト計画の記載を `V1〜V6` から `V0〜V7` に更新してください。

### C1: REQUEST_CHANGES

[Warning] 適用直後の `git status` の期待値が矛盾しています。

`git rm --cached` 後の staged deletion は porcelain では通常 `D ` と表示されます。「Dでもなく、staged な削除58件のみ」は同時に成立しません。

修正案:

- 「staged deletionを示す `D ` が58件のみ」
- 「unstaged変更（列2）と `??` が0件」

という機械判定に置き換えてください。

R2、`core.precomposeunicode`、NFC path→blob map の修正は妥当です。

### C2: REQUEST_CHANGES

[Critical] `--include-hash` への変更だけでは、「commentを細工しても生存DBを落とせない」を完全には保証しません。

保護されるのは、現在のクローンから列挙できる `Live` hash です。別クローンの生存DBについて provenance を存在しないパスへ書き換える、またはそのパスが現在のコンテナから見えない場合、分類は `Foreign` ではなく `Orphan` になります。現在の設計では `Orphan → shouldDrop=true` なので、`--include-hash` なしでもDROP対象です。

つまり今回の変更は unlabeled の巻き添えを閉じていますが、**細工された labeled foreign DB の経路は残っています**。

修正案:

```text
Orphan → shouldDrop = hash ∈ --include-hash
Unlabeled → shouldDrop = hash ∈ --include-hash
```

すなわち、現在のクローンで生存を否定できない全hash群を明示指定制にします。分類は説明用として維持し、削除可否を分類だけで自動決定しない形です。

追加テスト:

- foreign DB の provenance を不存在パスへ細工しても、`--include-hash` なしではDROPされない
- Orphan は対応hash指定時だけDROPされる
- `Protected` / `Live` は同時に `--include-hash` 指定されてもDROPされない
- provenance path が別namespaceから見えないケースを Orphan として保護する

[Warning] T-C2-17/18 の「生成SQLの検証」だけでは、既存DB・新規DBの両分岐が実際に stamp 関数を呼ぶことや例外時のexit codeを証明できません。

修正案: PDO境界を注入可能な関数へ分離するか、スクリプトのcontract testで両分岐と例外経路を実行してください。

なお、COMMENT失敗時にDBをDROPしない判断自体は妥当です。問題はその代替guardを unlabeled だけでなく Orphan にも適用する必要がある点です。

### D1: APPROVE

5ファイルを分割基準にした判断は明確です。`overrides` を避ける方針、既存レーンによる回帰確認も妥当です。