# 対応マトリクス: design-review Round 2

Codex 全体判定: CHANGES_REQUESTED
（施策 1・3・4・5 = APPROVE / 施策 2 = REQUEST_CHANGES。Critical 0 / Warning 1 / Suggestion 2 +
禁止事項の番号参照の誤り 1 件）

## [Warning] 施策 2: `Storage::fake()` は引数なしだと `ArgumentCountError` になる恐れ

- 判断: **反論する**（ただし**根拠を設計に明記する**ことで実装時の迷いを消す）
- 根拠: 事実誤認である。Laravel の `Storage::fake()` のシグネチャは
  `fake($disk = null, array $config = [])` で、**引数なし呼び出しは正当**であり
  「既定ディスクを fake する」意味になる。`ArgumentCountError` は発生しない。
  さらに本リポジトリの実コードが裏づけている:
  - `SourceDocumentService::appendDocument()` は `Storage::putFileAs(...)` を
    **ディスク指定なし**で呼ぶ（= 既定ディスク）。`config/filesystems.php` の
    `'default' => env('FILESYSTEM_DISK', 'local')`、`.env.example` は `FILESYSTEM_DISK=local`
  - **`appendDocument` を実際に通している既存テスト**
    `tests/Feature/Projects/SourceDocumentUploadTest.php` が
    **`Storage::fake()`（引数なし）** を各テストで使っており（L52 / L69 / L81 / L101 / L114 …）、
    現に緑で回っている。指摘どおりなら既存テストが全部落ちているはずである
  - リポジトリ全体でも引数なし `Storage::fake()` は複数箇所で常用されている
    （`ScenarioBookendMaterializeTest` / `CannedAnalysisPipelineTest` /
    `TakeDeletionQueueAtomicityTest` 等）。ディスク名を明示しているのは
    `Storage::fake('s3')` のように**既定でない特定ディスク**を狙う場合だけである
- 対応内容: `Storage::fake()`（引数なし）を維持する。ただし Suggestion に沿って
  **選定根拠を設計に明記**する（`appendDocument` はディスク未指定 = 既定ディスク /
  既存の `SourceDocumentUploadTest` と同じセットアップ）。取り違え防止という指摘の
  **意図は正しい**ので、そこは受け入れる。

## [Suggestion] 施策 2: ディスク選定の根拠を設計に書く

- 判断: **対応する**
- 根拠: 上記のとおり指摘の意図は妥当。根拠が書いてあれば実装者が迷わない。
- 対応内容: テスト 1 の手順に「なぜ引数なしでよいか」の根拠 3 点を注記する。

## [誤り指摘] 施策 3 の「禁止事項 3」という番号参照が AGENTS.md と一致しない

- 判断: **対応する**（Codex が正しい）
- 根拠: `AGENTS.md` の禁止事項 3 は「dev DB への破壊操作」である。
  「既存テストの削除・上書き」は `app-design` スキル側の禁止事項リストの番号であり、
  **正本の番号と取り違えていた**。AGENTS.md は「番号ではなく項目名で指せ」と明示している
  （§セキュリティ不変条件の採番の注意）。番号参照は削る。
- 対応内容: 施策 3 の該当文から番号参照を削除し、
  「**検査内容を不必要に変更しないため**（既存テストの削除・上書きを避ける）」と書き換える。

## [Suggestion] 検証コマンドは「全 9 本」ではなく 10 本

- 判断: **対応する**
- 根拠: 数え間違い。composer/pint 3 本 + pnpm 7 本 = **10 本**。
  列挙自体は正しかったが、見出しの数が合っていなかった。
- 対応内容: 「AGENTS.md と同期した**全 10 本**」に訂正する。
