# TRUSTED_PROXIES 運用 runbook (client IP の信頼境界)

`TRUSTED_PROXIES` は「どの hop までを自分たちのインフラとして信頼し、`X-Forwarded-*` を
本物として扱うか」の宣言である。設定を誤ると **セキュリティ機構が静かに無効化される**か、
**全利用者が 1 つの rate limit バケットに落ちて自己 DoS になる**かのどちらかに倒れる。

本書は運用者が記入・維持する正本である。`<!-- OPS-FILL -->` が残っている限り
Architecture テスト (`tests/Architecture/TrustedProxiesRunbookTest.php`) が fail するので、
デプロイ前に必ず埋めること。

---

## 1. なぜ必要か (何を壊す設定なのか)

| 設定 | 起きること |
|---|---|
| `*` (全アドレス信頼) | `$request->ip()` が **XFF 最左 = クライアントが自由に書ける値**になる。IP ベースの rate limit / reCAPTCHA / 監査ログ (`security_audit_events.ip_address`) がすべて攻撃者の制御下に落ちる (audit-cycle-2 High-2) |
| 未設定 (空) | `$request->ip()` が LB の内部 IP に固定される。**全利用者が同じ rate limit バケット**に入り、少数のリクエストで全体が 429 になる (自己 DoS)。`X-Forwarded-Proto` も見えないので `$request->secure()` が false になり、HTTPS 前提の分岐が壊れる |
| hop の取りこぼし | 上と同じ。**多段構成 (CloudFront → ALB → app 等) で 1 段でも欠けると、client IP はその欠けた hop の IP になる** |

> Symfony は「信頼された hop を右から順に剥がし、最初に**信頼できない**アドレスが現れた
> ところで止める」。したがって列挙は **経路上のすべての hop** が必要で、
> 「一番外側だけ」でも「一番内側だけ」でも正しくならない。

---

## 2. 設定方法

`.env` に CSV で列挙する。

```
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
```

| 値 | 意味 |
|---|---|
| IP / CIDR の CSV | 信頼する hop。**すべての hop を列挙する** |
| `none` | 「このアプリの前段にプロキシは無い」の**明示宣言**。単独でのみ有効 |
| `REMOTE_ADDR` | 直接の接続元を信頼 (ローカル開発の Valet TLS 用)。**production では拒否される** |
| `*` / `**` | **使用不可**。起動時に fail-fast する |

未設定のまま production を起動すると `ProductionEnvGuard` が例外を投げて起動を止める
(意図的な破壊的変更)。**env を設定してからデプロイする**こと。

---

## 3. 運用者記入欄 (デプロイ前に必ず埋める)

> **この節の placeholder (`<!-- OPS-FILL -->`) が 1 つでも残っていると
> `tests/Architecture/TrustedProxiesRunbookTest.php` が fail する。**
> 「誰も読まないまま本番の信頼境界が決まる」ことを構造的に防ぐための gate。

### 3.0 現在の状態 (最終確認: 2026-08-05 / T108)

**本番環境は未構築**。本リポジトリには `deploy/` / terraform / k8s manifest / nginx conf が
存在せず、`TRUSTED_PROXIES` は既存の env にも登場していない (T108 実装時に実地確認)。
存在する唯一の実行環境は local (docker-compose) で、**前段プロキシは無い**。

したがって現時点の正しい宣言は `TRUSTED_PROXIES=none` (プロキシ無し構成の明示宣言) である。
実 CIDR は**推測で決め打ちしない** — 誤った CIDR は「hop 取りこぼしによる自己 DoS」か
「過大信頼による XFF 偽装」のどちらかに必ず倒れるため、値を書かないことが正しい。

> ⚠ **初回本番デプロイ時の必須作業**: §3.1 / §3.2 / §3.3 を実インフラに基づいて書き換え、
> §4 の手順で `production:preflight` を通すこと。本節の「未構築」記述が残ったまま
> LB / CDN 配下へデプロイすると、client IP が LB の内部 IP に固定され自己 DoS になる。

### 3.1 実 proxy hop 一覧

リクエストが app に届くまでに通過する **すべての** hop を、外側から内側の順に書く。
「アプリの直前だけ」ではなく経路全体を書くこと (§1 の hop 取りこぼし)。

| # | hop (外→内) | 種別 (CDN/WAF/LB/ingress/sidecar 等) | 送信元 IP レンジ (CIDR) | レンジの出典 (取得元 URL / IaC のリソース) |
|---|---|---|---|---|
| 1 | (無し) | — | — | 本番未構築。local は docker-compose 直結でプロキシ hop 無し |

### 3.2 CIDR の管理主体

| 項目 | 記入 |
|---|---|
| CIDR を決める責任者 / チーム | 本番インフラを構築する担当チーム (未定。初回デプロイ時に確定する) |
| レンジが変わる契機 (CDN のレンジ更新・LB 再作成・リージョン追加等) | 本番未構築のため該当なし。構築後は CDN/LB の IP レンジ更新・LB 再作成・リージョン追加が契機 |
| 変更の検知方法 (CDN の IP レンジ JSON 監視 / IaC の drift 検知など) | 本番未構築のため未設定。構築時に「CDN の IP レンジ公開 JSON の監視」または「IaC の drift 検知」を配線する |
| 変更時の連絡先 | 未定 (初回デプロイ時に確定する) |

### 3.3 環境ごとの値

| 環境 | TRUSTED_PROXIES の値 | 備考 |
|---|---|---|
| production | `none` (プロキシ無し構成として明示宣言) | **未構築**。LB / CDN を前段に置いた時点で §3.1 の実 CIDR に必ず差し替える |
| staging | (未構築) | production と同一構成で構築すること |
| local | (未設定 = 空。Valet TLS 利用時のみ `REMOTE_ADDR`) | production では `REMOTE_ADDR` 禁止 |

---

## 4. 変更手順

1. §3.1 の hop 一覧を更新する (**経路全体**。1 hop でも欠けると自己 DoS)
2. staging の `TRUSTED_PROXIES` を更新してデプロイ
3. `php artisan production:preflight` を実行し violations が空であることを確認する (必須 gate)
4. staging で実リクエストの client IP を確認する (§5 の切り分け手順)
5. production の `TRUSTED_PROXIES` を更新 → デプロイ

**env 設定 → デプロイの順序**を守ること。逆順にすると起動が fail-fast して落ちる。

---

## 5. 切り分け (fail-fast したとき / IP が変に見えるとき)

### 起動時に `TRUSTED_PROXIES is not set in production` で落ちる

期待どおりの fail-fast。`TRUSTED_PROXIES` を設定してから再デプロイする。
プロキシが本当に無い構成なら `TRUSTED_PROXIES=none` と明示する。

### `TRUSTED_PROXIES contains an invalid value "..."` で落ちる

CSV の書式ミス。各要素は単一 IP か CIDR (`10.0.0.0/8`)。
`999.999.999.999/999` のような値は通らない。

### client IP が全リクエストで同じ値になる (自己 DoS の兆候)

hop の取りこぼし。その「同じ値」が **信頼し損ねた hop の IP** なので、
その IP を含む CIDR を `TRUSTED_PROXIES` に追加する。
rate limit が異常に早く 429 になる / 監査ログの IP が 1 種類しかない、が典型の症状。

### client IP がクライアント申告どおりに何でも入る (偽装の兆候)

`*` が残っていないか、あるいは信頼範囲が広すぎないかを確認する。
`TRUSTED_PROXIES` に自分の管理下にないレンジを入れてはならない。

---

## 6. rollback 条件

**`at: '*'` へ戻すことは rollback ではない** (High-2 の状態に戻すだけ)。
正しい CIDR を確定するまでデプロイしない、が唯一の正しい判断。

暫定運用がどうしても必要な場合は、対象環境を production 以外に切り替えて
(`ProductionEnvGuard` の対象外にして) 原因を切り分けること。

---

## 7. 既知の限界

- 既存の `security_audit_events.ip_address` は `at: '*'` 時代に記録されたもので、
  **遡及訂正できない**。過去の IP は信頼できない値として扱うこと (本施策のスコープ外)。
- 本 runbook は「どの hop を信頼するか」だけを扱う。`X-Forwarded-Host` を信頼する範囲は
  `TrustHosts` (`config/trusted_hosts.php`) が別途 allowlist で閉じている。

## 関連

- `config/trustedproxy.php` — framework が読む正本 (`TRUSTED_PROXIES` の解釈)
- `app/Support/TrustedProxiesConfigValidator.php` — production 起動時検証
- `docs/auth-security-mechanisms.md` §client IP の信頼境界
- `tests/Feature/Security/TrustedProxiesTest.php` — 実挙動の固定
