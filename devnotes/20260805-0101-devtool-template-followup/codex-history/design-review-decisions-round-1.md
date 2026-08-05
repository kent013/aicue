# 対応マトリクス: design-review Round 1

## [Critical] 施策2: `__dirname` は Vitest + ESM で未定義になりテストが落ちる

- 判断: **反論する**（設計は変えない。根拠を設計書へ追記した）
- 根拠: 実測で否定された。
  1. 本リポジトリの既存 architecture テスト **11 本すべてが `__dirname` を使用**しており
     （`svg-inline-allowlist.test.ts:16` / `ds-purity.test.ts` / `atomic-import-graph.test.ts` ほか）、
     CI の `pnpm test` で常時 green である
  2. JST 2026-08-05 に `tests/js/architecture/` へ
     `expect(typeof __dirname).toBe("string")` を置いた一時テストを追加して
     `pnpm exec vitest run` を実行 → **1 passed**。Vitest はテストファイルへ
     `__dirname` / `__filename` を注入する
- 対応内容: 設計書の施策 2 に「`__dirname` を使う根拠」節を追加し、上記の実測と
  「既存 11 本と作法を揃える（1 本だけ `import.meta.url` にすると読み手が差分の理由を探す）」
  を明記した。**コードは変更しない**。

## [Critical] 施策3: `fileStoreOrNull()` を本番ロジックで使うのは `CredentialStore` 境界の迂回

- 判断: **対応する**
- 根拠: 完全に正当。`fileStoreOrNull()` の JSDoc は
  「Used by tests to exercise corruption paths」と明記されており、
  テスト用の露出を本番から呼ぶのは抽象の破壊である
  （概念設計 §型安全方針 の「`ProfileWriter` 抽象を迂回しない」と同じ原則が
  `CredentialStore` にも当然かかる）。
- 対応内容: **`CredentialStore.purgeProfile()` を正式 API として追加**した。
  - 「破損 index を含む best-effort な全破棄」という意図を store 側に閉じ込める
  - 戻り値 `{ indexCorrupted: boolean }` で取りこぼしの可能性を呼び出し側へ報告
    （keychain は index を失うと個々の item を列挙できないため）
  - `clearProfile()` は意味を変えずそのまま残す（`purgeProfile` はその上のラッパ）
  - `fileStoreOrNull()` は引き続きテスト専用
  - 施策一覧 / 施策 3 の変更箇所表 / 型適合チェック / リスク表を更新。
    概念設計の受け入れ条件「`store.ts` に変更が無い」も
    「`store.ts` の変更は `purgeProfile()` の追加のみ」へ同期した

## [Warning] 施策3: `api_url` が「空でないが不正形式」だと `canonicalOrigin()` の例外で削除不能

- 判断: **対応する**
- 根拠: 正当。`canonicalOrigin()` は `new URL()` 失敗と非 http(s) スキームで throw する
  （`profile/canonical-origin.ts:1-14`）。手編集で壊れた profile ほど消せないという
  逆転した挙動になり、「壊れた状態からの回復手段」という `profile:delete` の役割を潰す。
- 対応内容: `resolveOriginOrNull(apiUrl, name)` を切り出し、
  **欠落・空・不正形式のすべてで `null` を返して警告**し、config 削除を続行する形にした。
  警告文言は共通ヘルパ `warnCredentialsUnlocatable()` に集約。
  リスク表にも 1 行追加した。

## [Suggestion] 施策3: `profile:use` の説明文「default_profile を変更できる唯一のコマンド」が嘘になる

- 判断: **対応する**
- 根拠: 正当。`profile:delete --clear-default` が `default_profile` を変えるようになる。
  ヘルプ出力の嘘は「エラーメッセージの嘘を消す」という本バッチの目的
  （課題 B の `profile:add` の `profile:delete` 案内）と同じ性質の問題である。
- 対応内容: 施策 3 に **(d) `profile/use.ts` の説明文訂正**（1 行）を追加し、
  施策一覧の変更ファイル欄にも載せた。ロジックには触れない。

## [Warning] 施策4: 関数レイヤ中心で CLI 契約（exit code / stdout / stderr / `--yes`）の回帰を固定できない

- 判断: **対応する**
- 根拠: 正当。設計した exit code 対応表（10 / 11 / 13 / 1）と確認プロンプト分岐は
  ロジック層のテストでは 1 つも固定されない。
- 対応内容: `packages/cli/tests/commands/profile/delete.test.ts` を追加し、
  検証 6 本（11 / 10 / 13 / 1 / 正常削除 / default 付け替えの stdout）を定義した。
  **技法は JST 2026-08-05 に実測で検証済み**:
  - `ProfileUse.run(argv, CLI_ROOT)` は **`dist/` をビルドしていなくても** Config.load が通る
  - `HOME` を一時ディレクトリへ向けると `homedir()` がそこを返し、
    `userConfigPath()` と `FileStore` の既定 baseDir が閉じる（hermetic 化できる）
  - **重要な落とし穴**: `process.exit` のモックを throw 実装にすると、
    その throw を `BaseCommand.catch` が拾って**もう一度 exit(1) を呼ぶ**。
    素朴に `rejects.toThrow("EXIT:11")` と書くと常に 1 を見る（実測で確認）。
    **最初に記録された code** で判定する設計にした
  - この落とし穴と実測結果を設計書に明記（実装者が同じ罠を踏まないように）

## [Suggestion] 施策4: fake keychain の複合キー区切りを不可視文字直書きでなく `"\u0000"` に

- 判断: **対応する**（かつ**実害を 1 件発見した**）
- 根拠: 正当。指摘を受けて確認したところ、設計書のコードブロックに
  **リテラルの NUL バイトが 1 個混入**していた（`${this.service}<NUL>${this.username}`）。
  そのせいで `grep` が当該ファイルをバイナリ扱いし、以降の検索が無言で 0 件を返していた。
  まさに「事故耐性」の指摘どおりの事故が起きていた。
- 対応内容: NUL バイトを除去し、`const KEY_SEP = "\u0000";` を定義して
  テンプレートリテラルから参照する形に書き換えた。

---

## 判定

Critical 2 件のうち 1 件は実測に基づき反論、1 件は設計変更で対応。
Warning 2 件・Suggestion 2 件はすべて対応（うち 1 件は実バグの発見につながった）。
