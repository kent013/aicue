# 対応マトリクス: design-review Round 2

## [Critical] `bindPairs()` の第 1 引数が `::class` でない場合の仕様が無い (偽グリーン)
- 判断: **対応する** (指摘のとおり。
  `$this->app->bind($abstract, FakeRenderObjectStorage::class)` は
  bind 自体が許可され、既存 fake を使えば 3-10 の参照集合も変わらないため、
  bindPairs が読み飛ばすと 3-8 にも 3-9 にも現れない)
- 採用した修正案: Codex が提示した後者 (**責務が明確**な方)。
  `disallowedContainerCalls()` の検出対象に
  「**`bind()` の第 1 / 第 2 引数のどちらかが `::class` 定数でない呼び出し**」を追加。
  `bindPairs()` の型は `abstract: class-string` のまま (読めない形は bind 側で禁止済みになる)。
- 追加テスト: **5-16** (`bind($abstract, ExistingFake::class)` が 1 件検出される)。

## [Warning] `referencedClasses()` が同一 namespace の short name 参照を拾えない
- 判断: **対応する** (配置例外クラスは通常ディレクトリに置かれるので現実的な抜け道)
- 対応内容: 収集元を 3 系統 → **4 系統**へ拡張。
  4 系統目 = 「**candidate の class basename に一致する `T_STRING`** を namespace / use map で
  解決する」。これで `FakeStorageGate::class` / `new FakeStorageGate` /
  `FakeStorageGate::enabled()` / 型宣言・戻り値型・プロパティ型の `FakeStorageGate` を拾う。
  実装範囲を抑える方式 (basename 起点) を採用し、docblock に明記。
- 追加テスト: **5-17** (`namespace App\Support;` 直下・use 無しの参照を検出する)。

## [Suggestion] `scanFiles()` は「全ファイル」ではなく「`.php` のみ」と明記せよ
- 判断: **対応する**
- 対応内容: docblock に「対象は `.php` 拡張子のファイルのみ」と明記。

## 施策 1 / 3 / 6: APPROVE (指摘なし)
- 判断: **現状維持**
- 特に 3-2 の binding × allowedEnvironments 展開について、Codex が
  「payment = local / testing / bughunt.local、storage = testing / bughunt.local で成立。
   storage の `testing` は Laravel TestCase 配下なので `runningUnitTests()` が true。
   `bughunt.local` は `runningUnitTests()` を要求しない。storage に `local` が無いのも正しい。
   env を boot 後に差し替える方式も、register 実行時に `FakeStorageGate` を新たに `make()` する
   現行実装では問題ない」と成立を確認した。
