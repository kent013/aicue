全体判定: **CHANGES_REQUESTED**

Round 2 の Critical はすべて解消されています。母集合の集計根拠、空名・重複名、複合 method、型境界まで具体化され、実装へ進める水準に近づきました。残る問題は主に文書内の契約矛盾です。

## 1. 使命との整合性

[Suggestion] 問題ありません。

「探索的バグハントの分母の信頼性」を直接効果とし、North Star への寄与を間接・補助的と限定しています。保証範囲も web 面に絞られており、使命への貢献を誇張していません。

## 2. 禁止事項違反

[Suggestion] 明確な違反はありません。

テストファースト、Architecture/Feature テストへの登録、PHPStan level 10、stdlib のみという制約が実装方針に反映されています。2ファイル間の原子性を実装しない判断も、保証を正しく限定したため妥当です。

## 3. 実現可能性

[Warning] 「分母と観測面が一致する」という記述は、現在定義した predicate と一致しません。

T164 の middleware は web group 全体を観測しますが、目録側はさらに URI prefix を除外します。したがって集合関係は、少なくとも設計記述上は次の形です。

```text
目録の母集合 ⊆ T164 が観測し得る web route
```

両者は同一ではありません。

修正提案:

- 制約・前提の「分母と観測面が一致する」を、「分母は T164 の観測可能範囲内に限定される」へ変更する。
- `correlate.py` が目録に存在しない実行済み route を無視する契約も、詳細設計または自己テストで固定する。
- 本当に一致を要件とするなら、URI prefix 除外した route を記録集約側でも同じ predicate で除外する必要がありますが、責務の重複になるため前者が妥当です。

[Warning] prefix ごとの除外根拠が、まだ設計本文に実体として記載されていません。

「設計に各 prefix の根拠を書く」とありますが、現在の表は「機械向け API / 管理画面 / MCP…」という分類だけで、`_`、`.well-known`、`storage`、`sanctum` など各値との対応が確定していません。

修正提案:

各 prefix について、除外する surface と根拠を1対1で記載してください。例えば以下の粒度です。

| 先頭セグメント | 除外する面 |
|---|---|
| `admin` | Filament 管理画面 |
| `api` | API surface |
| `mcp` | MCP transport |
| `livewire` | Livewire 内部通信 |

特に `_` は範囲が広いため、「先頭セグメントが `_` そのもの」なのか「`_` で始まる全セグメント」なのかも固定が必要です。

[Suggestion] Laravel 側で使う取得 API の表記は統一してください。冒頭では Router の `getRoutes()`、本文では `Route::getRoutes()` と読める記述になっています。詳細設計では注入した `Illuminate\Routing\Router` から `getRoutes()` を取得する、と固定すれば十分です。

## 4. 期待効果の妥当性

[Suggestion] 妥当です。

147件への再集計によって、主張の根拠が明確になりました。`webhooks.ses` の発見は、既存の名前ベース除外が実際に分母を欠落させていた証拠であり、本方式の期待効果を直接支持しています。

偽緑の解消範囲も「定義した web 面の判定が抽出した母集合」に限定されており、適切です。

## 5. リスク

[Warning] 複合 method route の扱いについて、本文内で契約が矛盾しています。

分類節では次の契約です。

```text
GET/HEAD と非GETを併せ持つ route
→ 段2で exit 3
→ 生成物を成立させない
```

一方、「保証しないもの」では次のように記載されています。

```text
その route は操作表にだけ載る
```

fail-closed なら操作表にも載りません。

修正提案:

「保証しないもの」の該当行を次へ変更してください。

> GET/HEAD と非GETを併せ持つ route は現在の注釈モデルでは表現せず、段2で drift として拒否する。必要になった時点で分類モデルを再設計する。

[Suggestion] 題名欠落を許容する判断は、33件対17件という実測と設定本来の責務に基づいており妥当です。ここで `seo.app_titles` を目録都合で完全化しない判断は、スコープ抑制にも合っています。

## 6. スコープの適切さ

[Suggestion] 適切です。

中間 JSON、到達可能性検査、未実行算出、カタログ生成を含めず、既存責務との重複を避けています。段4も逸脱登録と同じ変更単位で不変条件を固定するという理由があり、同一PRに含める範囲として妥当です。

初期注釈生成用スクリプトを `devnotes/` に限定する点も、恒久スクリプトを増やさない原則に沿っています。

## 7. 型安全性

[Suggestion] DTOを2つに限定する判断で問題ありません。ただし詳細設計では「array shape」という表現を、実際の型に合わせて分けてください。

想定される契約は、例えば次のようになります。

```php
methods: list<non-empty-string>
middleware: list<non-empty-string>
title: string|null

/** @return array{
 *   extraction_condition: non-empty-string,
 *   routes: list<array{...}>
 * }
 */
public function toArray(): array
```

`methods` と `middleware` は連想的な array shape ではなく、要素型を持つ `list` です。`seo.app_titles` は検証後に `array<string, string>` として扱うのが自然です。

`JSON_THROW_ON_ERROR`、config 境界での `mixed` 排除、`toArray()` の Feature テスト固定まで含まれているため、PHPStan level 10 を通せる設計です。

承認を妨げているのは、複合 method route の記述矛盾と、T164との「観測面一致」という過大な主張です。この2点と prefix 根拠を文書上で確定すれば、概念設計として承認可能です。