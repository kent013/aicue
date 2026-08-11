# Round 3: Round 2 指摘への対応

3 件中 **2 件は対応**、**1 件（`Storage::fake()`）は根拠を添えて反論**します。

---

## 1. [Warning] 施策 2: `Storage::fake()` は引数なしだと `ArgumentCountError` になる恐れ → **反論**

これは事実誤認だと考えます。Laravel の `Storage::fake()` のシグネチャは

```php
public static function fake($disk = null, array $config = [])
```

であり、**引数なし呼び出しは正当**で「既定ディスクを fake する」意味になります。
`ArgumentCountError` は発生しません。

本リポジトリの実コードが裏づけています:

1. `SourceDocumentService::appendDocument()` は `Storage::putFileAs(dirname($path), $file, basename($path))`
   を**ディスク指定なし**で呼びます（= 既定ディスク）。
   `config/filesystems.php` は `'default' => env('FILESYSTEM_DISK', 'local')`、
   `.env.example` は `FILESYSTEM_DISK=local` です
2. **`appendDocument` を実際に通している既存テスト**
   `tests/Feature/Projects/SourceDocumentUploadTest.php` が
   **引数なし `Storage::fake()`** を各テストで使っており（L52 / L69 / L81 / L101 / L114 …）、
   **現に緑で回っています**。ご指摘のとおりなら既存テストが全部落ちているはずです
3. リポジトリ全体でも引数なし `Storage::fake()` は常用されています
   （`ScenarioBookendMaterializeTest` / `CannedAnalysisPipelineTest` /
   `TakeDeletionQueueAtomicityTest` / `ManualAnalysisNotificationTest` 等）。
   ディスク名を明示しているのは `Storage::fake('s3')`（`ManualRenderNotificationTest`）や
   `Storage::fake(FakeObjectStore::DISK)`（`tests/Pest.php`）のように
   **既定でない特定ディスク**を狙う場合だけです

したがって `Storage::fake()`（引数なし）を維持します。

**ただしご指摘の意図（取り違えを防ぐ）は正しい**ので、[Suggestion] に沿って
**選定根拠を設計本文に明記**しました（上記 3 点をテスト 1 の手順欄に注記）。
これで実装者が「どのディスクを fake すべきか」で迷うことはありません。

---

## 2. [誤り指摘] 施策 3 の「禁止事項 3」という番号参照 → **対応**

ご指摘のとおりです。`AGENTS.md` の禁止事項 3 は「dev DB への破壊操作」であり、
「既存テストの削除・上書き」は `app-design` スキル側のリストの番号でした。**取り違えです。**

しかも `AGENTS.md` 自身が「相互参照するときは**番号ではなく項目名**で指すこと」と
明示しています（§セキュリティ不変条件の採番の注意）。番号参照そのものを削りました:

> **assertion は 1 行も変えない**（`containsStatusWrite()` / `containsScenarioVersionWrite()` の
> 2 本のまま）。**検査内容を不必要に変更しない**ため（既存テストの削除・上書きを避ける）、
> 名称とコメントだけを実態に合わせる

---

## 3. [Suggestion] 検証コマンドは 9 本ではなく 10 本 → **対応**

数え間違いでした。composer/pint 3 本 + pnpm 7 本 = **10 本**です。
見出しを「AGENTS.md の `VERIFICATION_COMMANDS` と**同期した全 10 本**
（composer/pint 3 本 + pnpm 7 本）」に訂正しました。コマンドの列挙自体は変更していません。

---

## 変更していない点（確認）

- 施策 1 / 3 / 4 / 5 は Round 2 で APPROVE をいただいた内容から変更なし
- 生成経路の免除範囲を「その tx が生成した新規行の初期値 INSERT のみ」に封じる節は維持
  （`duplicate()` の cuts は更新経路として `lockForUpdate()` 再取得が必要）
- 保証しないもの（検出される / 検出されない (a)(b) の 3 分岐、
  `take_upload_reservations` の根拠、pipeline-smoke 全体は保証しない）は維持
- fail-first 手順と mutation ①/②-a/②-b は維持

以上を踏まえ、最終判定をお願いします。
`Storage::fake()` の反論にご納得いただけない場合は、根拠を示して再度ご指摘ください。
