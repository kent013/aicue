# 対応マトリクス: conceptual-review Round 1

全体判定: **APPROVED** (Round 1)。Critical 0 件。Warning 3 件 / Suggestion 6 件。
Warning はいずれも「概念を差し戻すものではなく詳細設計で詰める点」なので、
**詳細設計へ持ち越して確定させる**方針を採る (概念設計の本文も必要な範囲で追記)。

## [Warning] 実現可能性: `"legal.consent_version"` (二重引用符) も検出できるようにせよ

- 判断: **対応する**
- 根拠: 妥当。`token_get_all` の `T_CONSTANT_ENCAPSED_STRING` は引用符を含んだ生表記
  (`'legal.consent_version'` / `"legal.consent_version"`) を返すため、素の `===` 比較だと
  二重引用符形がすり抜ける。gate の回避口になる。
- 対応内容: 既存の `tests/Architecture/ScenarioWritePathInventoryTest.php:451`
  (`stringLiteralEquals()` = `trim($literal, "'\"") === $expected`) と**同じ流儀**の
  正規化ヘルパを本 gate にも置く。先人の知恵を探せ (既存の見本を踏襲) に沿う。
  さらに**負のコントロールに二重引用符形の fixture を必ず含める**ことで、この正規化が
  効いていることを behavioral に固定する (詳細設計 §gate の負のコントロール T-A2)。
  なお Codex の提案した `stripcslashes` 相当までは行わない: 対象キーは
  `legal.consent_version` / `LEGAL_CONSENT_VERSION` の 2 つだけで**エスケープ文字を含まず**、
  `"legal.\x63onsent_version"` のような難読化は現実の脅威ではない (思考原則 2)。
  この限界は「保証しないもの」に明記する。

## [Warning] リスク: `InquiryFactory` が Laravel config 初期化に依存するようになる

- 判断: **対応する (検証条件の明記のみ。新規テストは追加しない)**
- 根拠: 指摘どおり literal → `LegalConsent::version()` で Factory が config 解決を要求する。
  ただし本リポジトリの Factory は必ず `RefreshDatabase` グローバル適用の
  Feature/Unit lane (Laravel アプリ起動済み) からのみ使われ、Architecture lane は
  `tests/Pest.php:98` で `TestCase` のみ・DB 非使用のため Factory を触らない。
  よって実害は無いが、「落ち方が変わらない」ことは**実証すべき**である。
- 対応内容: 詳細設計のテスト計画に、`Inquiry::factory()` を使う既存 4 ファイル
  (`tests/Feature/Inquiry/InquiryModelTest.php` / `PurgeInquiriesCommandTest.php` /
  `tests/Feature/Filament/InquiryResourceTest.php` / `tests/Feature/Inquiry/ContactSubmissionTest.php`)
  が **1 行も変更せず green** であることを合格条件として明記する。新規テストは足さない
  (思考原則 2)。値が同一であることの根拠も明記する:
  `.env.testing:77` が `LEGAL_CONSENT_VERSION=draft-1` なので、
  literal `'draft-1'` と `LegalConsent::version()` はテストレーンで**同値**である (実読確認済み)。

## [Warning] 型安全性: `non-empty-string` の narrowing を単純形で固定せよ

- 判断: **対応する (かつ実測で検証した)**
- 根拠: 妥当。ただし「narrowing しない場合のみ `@phpstan-return` を足す」という条件付き提案は
  実装時の判断に委ねる形になるので、設計段階で**実測して確定**させた。
- 対応内容: probe ファイルを一時作成して `bash scripts/phpstan.sh analyse --level=10` を実測:
  - `$version = config()->string(...); Assert::stringNotEmpty($version); return $version;` +
    `/** @return non-empty-string */` → **No errors** (narrowing が効く)
  - `Assert` 行を削ったもの → `Method ...::version() should return non-empty-string but
    returns string. (return.type)` で **error**
  つまり本リポジトリの larastan 3.10 + webmozart/assert 2.4 の組み合わせで narrowing は効き、
  かつ**その narrowing は load-bearing** (Assert を消すと PHPStan が落ちる = 型注釈が
  fail-fast の存在を機械的に守る第 2 の gate になる)。詳細設計にこの実測を根拠として記載する。
  probe ファイルは検証後に削除済み (アプリコードは 1 行も変更していない)。

## [Suggestion] 空文字環境で 500 になる挙動変更を実装 PR の説明にも残せ

- 判断: **対応する**
- 根拠: 妥当。概念設計 §振る舞いの変化に既に記載済みだが、実装 PR まで運ぶ導線が無かった。
- 対応内容: 詳細設計の §リスク と §実装モード に「PR 説明へ転記する」ことを明記する。

## [Suggestion] 使命との整合性 / スコープ / billing 非統合 / 二段 fail-fast / 効果の妥当性

- 判断: **見送る (肯定的評価であり対応不要)**
- 根拠: いずれも設計の追認。変更を要しない。
