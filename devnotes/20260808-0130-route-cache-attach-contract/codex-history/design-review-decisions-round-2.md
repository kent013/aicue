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
