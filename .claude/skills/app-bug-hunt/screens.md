# 画面インベントリ (screens.md) — スケルトン

> **これはテンプレート同梱のスケルトンである。** bug-hunt カバレッジの分母となる「画面」(GET × inertia × web)
> の一覧をアプリごとに埋めること。初回は SKILL.md「Phase 1」のコマンドで `php artisan route:list` から生成する。
> ドリフト検知は `scripts/bug-hunt-inventory-check.sh` が本ファイルと route:list の差分を出す。

## 生成手順

```bash
php artisan route:list --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    if 'GET' not in r['method']: continue
    uri=r['uri']; mw=str(r.get('middleware',[]))
    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
    if 'web' not in mw: continue
    print('|', uri, '|', r.get('name') or '-', '| S? |')" | sort
```

## 画面一覧

| route (URL) | name | 割当ストーリー |
|---|---|---|

<!-- 生成後の記入例 (埋めるときは上の表に | 区切りで追記する):
login       login       S1
dashboard   dashboard   S4
-->
