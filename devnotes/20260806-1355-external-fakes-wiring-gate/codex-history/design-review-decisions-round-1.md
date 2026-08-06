# 対応マトリクス: design-review Round 1

## [Critical] `sourceFiles()` のパス仕様と `FAKE_REFERENCE_ALLOWED` の仕様が食い違う
- 判断: **対応する** (実装者判断が割れる)
- 対応内容: **repo ルート相対に統一**した (`app/Providers/…` / `routes/web.php`)。
  施策 4 で scan roots を `app/` 以外へ広げるため、`app/` 相対では表現できない。
  `FakeClassCatalog::scanFiles()` は repo 相対を返し、`classFromPath()` は
  「`app/` 配下の repo 相対パスのみ受ける (PSR-4)」と明記。allowlist も repo 相対に統一。

## [Critical] 4-3 の候補集合が `implementationClasses()` だけだと placement exception を検出できない
- 判断: **対応する** (gate の偽グリーン)
- 対応内容: 4-3 の走査候補を
  `implementationClasses() ∪ array_keys(placementExceptions())` に変更。
  `FakeStorageGate` / `FakeExternalsServiceProvider` を業務コードから参照した場合も捕まる。
  参照 allowlist 側に `bootstrap/providers.php` (provider 登録) を追加。

## [Warning] scan roots が `app/` だけだと `routes/` / `config/` / `bootstrap/` の抜け道が残る
- 判断: **対応する** ("本番コード全走査" を名乗る以上、名前に負けている)
- 対応内容: scan roots を `app/` / `routes/` / `config/` / `bootstrap/` の 4 つへ拡大。
  `bootstrap/providers.php` は provider 登録として allowlist に理由付きで登録。
  母集団導出 (`implementationClasses()` / `namedClasses()`) は従来どおり `app/` のみ
  (PSR-4 のクラス定義があるのは `app/` だけ)。

## [Warning] short class name → FQCN 解決の仕様が不足 (3-8 が成立しない)
- 判断: **対応する** (現行 provider は use 済み short name を使うため、これが無いと集合一致しない)
- 対応内容: scanner に **namespace / use map の構築**を明記した。
  `A::class` の `A` を FQCN へ正規化し、`use X as Y;` の alias と group use
  (`use App\{A, B};`) も解決する。この正規化は `bindPairs()` /
  `disallowedContainerCalls()` (make の引数照合) / `referencedClasses()` が共有する。

## [Warning] docblock の型規約が弱い
- 判断: **対応する**
- 対応内容: `bindPairs(): list<array{abstract: class-string, concrete: class-string|null}>`、
  `classFromPath(): class-string`、`ALLOWED_APP_CALLS: array<string, list<class-string>|null>` を明記。

## [Warning] 3-2 は `testing` だけの実証で allowlist の他環境が固定されない / `staging` を見ていない
- 判断: **対応する**
- 対応内容: 3-2 を **binding × allowedEnvironments** の data-driven に拡張
  (`local` / `testing` / `bughunt.local` を env 差し替えで実証する)。
  3-3 (allowlist 外) も `production` に加えて **`staging`** を追加し、
  「未知環境で誤設定されても fake しない」ことを固定する。

## [Warning] M5 の期待が不正確 (既存 fake クラスを使う mutation では 3-10 は赤にならない)
- 判断: **対応する** (指摘のとおり)
- 対応内容: mutation 表を「M5 は **3-8** が担当」に修正し、
  「**未登録の fake クラスを新規に参照する** 変種では 3-10 も赤になる」を注記に分離。

## [Warning] `MUTATION_IDS` が M3〜M7 だけで名前と実体がずれる / 定数名が汎用的で衝突しうる
- 判断: **対応する**
- 対応内容: 定数名を `EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS` へ改名し、
  「M1 / M2 は data-driven 解決検査 (3-2) が担保するため本 map の対象外」と docblock に明記。
  file-level helper も `externalFakeWiringProviderSource()` へ prefix する。

## [Warning] 施策 5 に現行 provider の実パターンを固定するケースが足りない
- 判断: **対応する**
- 対応内容: 5-13 (use 済み short name の FQCN 解決) / 5-14 (alias 付き use) /
  5-15 (`make(FakeStorageGate::class)->enabled()` の chain 許可) を追加 (12 → 15 ケース)。

## [Suggestion] `(string) file_get_contents()` は読み取り失敗が空文字になり診断性が悪い
- 判断: **対応する**
- 対応内容: helper 側で `is_string($source) && $source !== ''` を assert する形に変更。

## [Warning] 施策 6 が「tests/ のみ」というスコープ説明とファイル数に矛盾
- 判断: **対応する** (docs 追記は残し、記述を正す)
- 根拠: `docs/architecture.md` は aicue の不変条件の運用契約を書く場所であり、
  「新しい差し替えは inventory 登録が必須」という運用は文書化しないと次の実装者に届かない。
  スコープから落とすのではなく、**スコープ記述の誤り**を直す。
- 対応内容: 「tests/ 配下のみ」→「**tests/ の新規 7 ファイル + `docs/architecture.md` の追記**」、
  ファイル数を「6 本」→「**8 本 (新規 7 + 追記 1)**」に訂正。
  「アプリコード (`app/` / `config/` / `bootstrap/` / `routes/`) は 1 行も変更しない」は維持。

## [Suggestion] `label()` は FQCN ベースにすると dataset 名が将来衝突しない
- 判断: **対応する** (コスト 0)
- 対応内容: `label()` を `str_replace('\\', '.', $this->abstract)` ベースに変更。
