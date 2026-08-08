# 詳細設計レビュー Round 3

Round 2 の 2 件の Warning に対応した。1 件の Suggestion は根拠を添えて反論する。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED**。施策 1 / 4 が REQUEST_CHANGES、2 / 3 / 5 / 6 / 7 は APPROVE。

## [Warning] 施策 1: 「resolver をそのまま渡すことで前倒しが型として不可能」は不正確

- 判断: **対応する（指摘が正しい）**
- 根拠: Codex の反例
  （`$specs = $specResolver();` → `static fn (): array => $specs` を渡す）は型検査を通る。
  「型として不可能」は**誇張**であり、AGENTS.md 全体で徹底している
  「保証範囲を誇張しない」に正面から反する。本設計が潰そうとしている失敗形が
  まさに「docblock が実際より強い主張をしている」ことなので、ここで同じ罪を犯すのは論外。
- 対応内容: docblock を
  「**型で不可能なわけではない**（退行例を明記）。配線が前倒し評価へ退行していないことは
  `RouteMiddlewareBinderTest` の配線テストが**振る舞いで**固定する」に書き換えた。
  施策 1 の「リスク」節からも「構造的に起こらない」を削除した。

## [Warning] 施策 4: `attachOnBooted()` の配線を固定するテストが無い

- 判断: **対応する**
- 根拠: 指摘のとおり、`attachAll()` のテストと `attachOnBooted()` のテストは責務が違う
  （lazy resolver の契約 / 実際の起動配線がその契約を破らないこと）。
  T120 は**配線**で起きた事故なので、入口を固定しないのは片手落ち。
  当初 stub を避けたい理由で見送ったが、**stub は要らない**ことが分かった（下記）。
- 対応内容: テスト #8 / #8b を追加した。
  - **#8**: `app()->instance('routes.cached', true)` としてから
    `attachOnBooted(app(), 呼ばれたら throw する resolver)` を実行し、例外が出ないことを表明。
  - **#8b (negative control)**: `routes.cached` を `false` にすると同じ resolver で
    **例外が出る**ことを表明。#8 が「配線が死んでいるから green」になるのを防ぐ。
  - 実装メモ: `Application::routesAreCached()` は**まず container binding `routes.cached`**
    を見る（`if ($this->bound('routes.cached')) { return (bool) $this->make('routes.cached'); }`）
    ため、Application の stub / mock も route cache ファイルの生成も不要。
    `Application::booted()` は `isBooted()` なら即時発火するので同期的に検証できる。
    （vendor 実読で確認済み: `Illuminate\Foundation\Application` L1174-1181 / L1327-1334）

## [Suggestion] 二重防御として `attachOnBooted()` 自身も cached 判定で return してよい

- 判断: **反論する（採らない）**
- 根拠: skip の決定点を 2 箇所に増やすと、配線側の `$routesAreCached` 引数が実質デッドになり
  「どちらが正なのか」が読めなくなる。本設計の主題は**機序の記述を 1 つに揃えること**なので、
  決定点を増やすのは逆行する。Codex 自身も「その場合も配線テストは残すべき」と述べており、
  安全性を担っているのは二重化ではなく**テスト**である。テストを入れた以上、
  コードの二重化は思考原則 2（今必要なものだけ作る）に反する。
- 対応内容: 施策 1 の「リスク」節に、二重化しない判断とその理由を明記した。

## [APPROVE] 施策 2 / 3 / 5 / 6 / 7

- 判断: **変更なし**
- 根拠: Round 1 の対応で解消済みと確認された。
  シグネチャ不一致（`RouteThrottleBinder` = array 受け / `RouteMiddlewareBinder` = resolver 受け）
  の許容も妥当と判定された。


## 修正後の該当箇所

### 施策 1: docblock から「型として不可能」を削除（誇張しない）

```php
     *   2. **cached 起動では resolver 自体を実行しない**ため。resolver をここで呼んで
     *      配列にしてから渡す形にすると、将来 resolver が route collection を覗く実装に
     *      なったとき early return の**前**に落ちる = T120 の再導入になる。
     *      ★ただし「型で不可能」なわけではない（`$specs = $specResolver();` してから
     *      `static fn () => $specs` を渡す退行は型検査を通る）。**誇張しない**。
     *      この配線が前倒し評価へ退行していないことは
     *      `RouteMiddlewareBinderTest` の配線テスト（`routes.cached` を true に束ねて
     *      「呼ばれたら throw する resolver」を渡す）が**振る舞いで**固定する。
```

施策 1 の「リスク」節も差し替えた:

```
- cached 起動では skip するため、T120 の事故（`route:list` が落ちる）は起こらない。
  ただし「構造的に不可能」とは書かない（配線側で resolver を前倒し評価する退行は
  型検査では止まらない）。**純粋関数の契約（#1）と配線の振る舞い（#8 / #8b）を
  2 本で固定する**のが本設計の担保である。
- **`attachOnBooted()` に skip 判定を二重に置くことはしない**。skip の決定点を
  `attachAll()` の 1 箇所に保つ（二重化すると配線側の flag が実質デッドになり、
  「どちらが正なのか」が次の担当に読めなくなる）。退行の検出は #8 / #8b が担う。
```

### 施策 4: 配線テスト #8 / #8b を追加（stub 不要であることが判明）

| # | テスト名 | 検証内容 |
|---|---|---|
| 8 | **`attachOnBooted()` は cached 起動で resolver を呼ばない（配線の固定）** | `app()->instance('routes.cached', true)` としてから `attachOnBooted(app(), 呼ばれたら throw する resolver)` を実行し、**例外が出ない**ことを表明する |
| 8b | **negative control: 非 cached なら resolver は実際に呼ばれる** | `app()->instance('routes.cached', false)` で同じ resolver を渡すと**例外が出る**。#8 が「配線が死んでいるから green」になっていないことの担保 |

実装メモ（vendor 実読）:

```php
// Illuminate\Foundation\Application::routesAreCached()  L1327-1334
public function routesAreCached()
{
    if ($this->bound('routes.cached')) {
        return (bool) $this->make('routes.cached');   // ← ここを束ねれば cached 起動を再現できる
    }

    return $this->instance('routes.cached', $this['files']->exists($this->getCachedRoutesPath()));
}

// Illuminate\Foundation\Application::booted()  L1174-1181
public function booted($callback)
{
    $this->bootedCallbacks[] = $callback;

    if ($this->isBooted()) {
        $callback($this);       // ← boot 済みアプリでは即時発火するので同期的に検証できる
    }
}
```

したがって Application の stub / mock も route cache ファイルの生成も不要で、
`attachOnBooted()` の配線そのものを 2 行で固定できる。
Round 2 で stub 追加を避けたいと書いた理由は消えたため、指摘を全面的に採用した。

## 反論 1 件（Round 2 [Suggestion]）

`attachOnBooted()` 自身にも cached 判定を置く「二重防御」は**採らない**。

- skip の決定点が 2 箇所になると、`attachAll()` の `$routesAreCached` 引数が
  実配線では常に `false` になり**実質デッド**になる。「どちらが正なのか」が
  次の担当に読めなくなり、本設計の主題（機序の記述を 1 つに揃える）に逆行する。
- Codex 自身「その場合も配線テストは残すべき」と述べており、安全性を担っているのは
  二重化ではなく**テスト**である。テストを入れた以上、コードの二重化は
  思考原則 2（今必要なものだけ作る）・禁止事項 6（やたらに複雑な案）に反する。

## 再評価してほしい点

1. Round 2 の 2 件の Warning が解消されたか。
2. 反論 1 件（二重防御を採らない）が受け入れられるか。受け入れられないなら、
   テストで固定してもなお二重化が必要な**具体的な失敗シナリオ**を示すこと。
3. 残る Critical / Warning が無ければ、全体判定 **APPROVED** を出すこと。
