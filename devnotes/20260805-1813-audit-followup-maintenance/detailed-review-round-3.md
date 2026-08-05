## 全体判定: CHANGES_REQUESTED

Round 2 の主要な安全性問題、特に C2 の自動DROP経路は解消されています。ただし、実装不能なSQL生成契約とテスト期待値の残存不整合があります。

### A1: REQUEST_CHANGES

[Warning] 「PHP評価後の値」を復元する規則として、`\\ → \` の1パスは完全ではありません。

double-quoted PHP文字列では `\x5cR` や `\u{5c}R` も評価後に `\R` になりますが、現在の復元規則では検出できません。

修正案:

- 射程を「`\R` を直接記述したリテラル」に明確に限定する、または
- double-quoted PHP文字列のエスケープを正しく復元する
- 少なくとも `\x5cR` を意図的な射程外としてテスト・docblockに固定する

P13/P14の訂正自体は正しいです。

### A2: REQUEST_CHANGES

[Warning] C26は、単にPATHから`ps`を外すだけでは偽グリーンになり得ます。`flock`、`sleep`、`tr`など別コマンドの不在で先に失敗しても、期待する「非ゼロ」を満たします。

修正案:

- 一時PATHへ必要コマンドだけをshimまたはsymlinkし、`ps`だけを置かない
- exit codeだけでなく、`_gtl_probe_process_group`固有のエラーメッセージを検証する
- probe到達を示すマーカーも検査する

`ps`必須という契約への訂正は妥当です。

### B1: APPROVE

Round 1以降の修正を含め、台帳、ストーリー、CI配線、W16の実行行検査は整合しています。

### B2: APPROVE

マーカー範囲、双方向検査、V0〜V7、AGENTS.mdの記述密度はいずれも妥当です。

### C1: REQUEST_CHANGES

[Warning] V-C4はpath→blob map比較へ変更されていますが、手順4は依然としてblobだけを保存しています。

```bash
git ls-files -s doc/reference | awk '{print $2}' | sort > after-blobs.txt
```

これではpathとの対応を復元できず、V-C4を実行できません。

修正案: NUL安全な方法で、施策前後とも次のmapを生成・比較してください。

```text
NFC(path) → mode + blob + stage
```

同一NFC keyに異なる値が出た時点で中止し、施策後mapとの完全一致を検証します。

statusの3条件への訂正は妥当です。

### C2: REQUEST_CHANGES

[Warning] `testDatabaseEnsurePlan()`の契約では、安全なCOMMENT SQLを生成できません。

既存の`pgsqlCommentDatabaseSql()`は`PDO::quote()`を必要としますが、新しい純関数はPDOを受け取りません。provenance pathには`'`などが含まれ得るため、独自連結は許容できません。

修正案: SQL文字列ではなくaction列を返してください。

```php
enum TestDatabaseEnsureAction
{
    case Create;
    case StampProvenance;
}
```

本体側でactionに応じて、既存の`pgsqlCreateDatabaseSql()`と`pgsqlCommentDatabaseSql($pdo, ...)`を呼びます。これなら計画は純粋で、クォート責務も維持できます。

[Warning] T-C2-2が旧契約のままです。

```text
orphan: ラベルあり・path不在 → shouldDrop = true
```

修正後の契約では、include-hashなしなら`false`です。T-C2-19/20と矛盾しています。

修正案:

- T-C2-2を`Orphan / shouldDrop=false`へ変更
- include-hash指定時の`true`はT-C2-20で検証
- apply契約2-bを「Orphan / Unlabeledの両方」に修正
- PHPDocの`$includeHashes`説明から「unlabeledのみ」を削除
- token説明の「どのunlabeled群」を「どのOrphan/Unlabeled群」へ変更

Orphanも明示指定制にした中心方針は妥当で、Round 2のCritical自体は解消しています。

### D1: APPROVE

依存更新、分割基準、検証レーンの設計に追加の問題はありません。