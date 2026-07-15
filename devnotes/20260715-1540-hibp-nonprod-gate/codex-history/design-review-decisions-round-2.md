# 対応マトリクス: design-review Round 2

## 施策1: APPROVE(対応不要)
- 責務境界 Critical 解消・fail-secure 承認。維持。

## [Warning] 施策2: `withAppEnv()` が元の値でなく常に `'testing'` へ戻す
- 判断: 対応する
- 根拠: 妥当。別 env から呼ばれた場合に分離が崩れる。
- 対応内容: 差し替え前 env を `$original = app()->environment()` で保存し `finally` で復元。

## [Warning] 施策2: `fake_externals` も元 config 値へ復元すべき
- 判断: 対応する
- 対応内容: `$original = config('testing.fake_externals')` を保存し `finally` で復元(ハードコード false を廃止)。

## [Suggestion] 施策2: グローバル関数 `withAppEnv()` の名前衝突余地
- 判断: 対応する
- 対応内容: ファイル固有名 `withPasswordPolicyAppEnv()` へ改名(他テストファイルとの衝突回避)。

## [Critical] 施策3: `Http::preventStrayRequests()` 残置で過検出未解消
- 判断: 対応する
- 根拠: 妥当。合法な別 HTTP が `assertNotSent` 到達前に例外化する。
- 対応内容: `preventStrayRequests()` を撤去し、`Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)])` で HIBP URL のみ intercept(実ネットワーク遮断)。POST + 成功導線検証後に `Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.pwnedpasswords.com'))`。他の合法 HTTP は本テストの責務外にする。`uncompromised` は `NotPwnedVerifier`(Http client factory 経由)のため Http::fake で intercept 可能。
