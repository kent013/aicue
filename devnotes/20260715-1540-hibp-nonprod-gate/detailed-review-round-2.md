## 施策1: APPROVE

責務境界の Critical は解消しています。

- production の先行判定により、denylist の編集ミスでも常時 ON。
- 未知 env は既定 ON。
- `fake_externals` と分離したことで、staging の stray flag による fail-open も排除。
- denylist と fake allowlist が同一である限り、要求も推移的に満たします。

## 施策2: REQUEST_CHANGES

- [Warning] `withAppEnv()` が元の値ではなく常に `'testing'` へ戻しています。現在の実行前提では動きますが、別 env から呼ばれた場合のテスト分離が不完全です。  
  修正案: 差し替え前の env を保存して復元してください。

```php
$originalEnv = app()->environment();

try {
    app()->instance('env', $env);
    $assertion();
} finally {
    app()->instance('env', $originalEnv);
}
```

- [Warning] `fake_externals` も常に `false` ではなく元の config 値へ戻すべきです。  
  修正案: `$original = config('testing.fake_externals')` を保存し、`finally` で復元してください。
- [Suggestion] グローバル関数 `withAppEnv()` は他テストファイルとの名前衝突余地があります。固有名にするか、ファイルローカルな `Closure` 変数として定義すると安全です。

## 施策3: REQUEST_CHANGES

- [Critical] `Http::preventStrayRequests()` が残っているため、Round 1 の過検出問題は実質的に未解消です。`assertNotSent()` を限定しても、合法な別 HTTP はその前に例外となります。  
  修正案: `preventStrayRequests()` を外し、HIBP URLだけを fake して記録・検査してください。

```php
Http::fake([
    'api.pwnedpasswords.com/*' => Http::response('', 200),
]);

// POST と成功導線の検証

Http::assertNotSent(
    fn (Request $request): bool =>
        str_contains($request->url(), 'api.pwnedpasswords.com')
);
```

これなら実ネットワークを確実に遮断しつつ、他の合法な HTTP を本テストの責務外にできます。成功導線の追加は妥当です。

## 全体判定: CHANGES_REQUESTED

fail-secure 本体は承認可能です。残件はテスト隔離の復元方法と、施策3の `preventStrayRequests()` 撤去です。