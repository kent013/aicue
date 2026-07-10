# 操作インベントリ (operations.md) — スケルトン

> **これはテンプレート同梱のスケルトンである。** bug-hunt カバレッジの分母となる「書き込み操作」
> (非GET × web セッション面) の一覧をアプリごとに埋めること。初回は SKILL.md「Phase 1」のコマンドで
> `php artisan route:list` から生成する。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
>
> **列フォーマット (correlate.py の fix-gate #3 が依存、厳守)**: markdown leading-pipe の **5 列**。
> `| method | route | name | story | 区分 |`。graph join の**キーは name 列 (index 2)**。
> name 列の backtick は剥がされる。API/CLI 面のみ 6 列 (`| method | route | api route name | CLI | story | 区分 |`)。

## 生成手順

```bash
php artisan route:list --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    m=r['method'].split('|')[0]
    if m in ('GET','HEAD','OPTIONS'): continue
    mw=str(r.get('middleware',[])); name=r.get('name') or '-'
    if 'web' not in mw: continue
    if name.startswith(('cashier','passport','livewire')) or 'webhook' in name: continue
    print('|', m, '|', r['uri'], '|', name, '| S? | 通常 |')" | sort -k4
```

## 操作一覧 (web セッション面)

| method | route | name | story | 区分 |
|---|---|---|---|---|

<!-- 生成後の記入例 (先頭パイプにすると行として parse される。埋めるときは下記の形で表に追記):
POST   organizations                  organizations.store    S4  通常
DELETE organizations/{organization}   organizations.destroy  S4  destructive
-->
