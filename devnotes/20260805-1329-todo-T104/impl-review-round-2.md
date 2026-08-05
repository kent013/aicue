## Critical

### `scripts/audit-gate.ts`

Top-level `error` / `errors` は塞がれましたが、入れ子の取得失敗表現がまだ通ります。

例:

```json
{"advisories":{"vendor/pkg":[{"error":"registry unavailable"}]}}
```

Composer 側は配列であることしか検証せず、advisory 要素の必須フィールドを検証していません。pnpm の `{advisories:[{error:...}]}`、pip の `vulns:[{error:...}]` も同様です。normalizer が欠落フィールドを空値へ落とすなら、未受容 high として検出されず偽グリーンになります。

`assertAuditSourceShape()` は各 normalizer が読む advisory/vulnerability 要素について、ID・package・severity 等の最低限の型を検証するか、少なくとも入れ子の非空 `error` / `errors` を拒否してください。

### `scripts/audit-gate.test.ts`

上記経路の負のコントロールがありません。少なくとも次を throw として追加する必要があります。

- Composer: package 配列内の `{error: ...}`
- pnpm: advisory entry 内の `{error: ...}`
- pip-audit: vulnerability entry 内の `{error: ...}` または必須フィールド欠落

一時ディレクトリの回収は適切に修正されています。

## Warning

なし。

## Suggestion

### `scripts/run-browser-test.contract.test.ts`

環境変数の除去と PPID 反転の負のコントロールは妥当です。`sleep 0.1` も T104 のテストハーネスとしては許容できます。

ただし、確認済みの `global-test-lock.sh` の race は TODO 化して追跡可能にするべきです。最終報告だけでは恒久的に埋もれる可能性があります。

### `.github/workflows/ci.yml`

schedule 時に supply-chain job だけを走らせる修正は設計・文書と一致しています。`continue-on-error` や CI 環境変数によるバイパスもありません。

### `tests/js/architecture/ci-workflow-inventory.test.ts`

W15 は正負両方向を検査しており妥当です。既存の W9/W13/W14 とも競合していません。

### `docs/supply-chain/review-checklist.md`

workflow の実体と一致しています。問題ありません。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の指摘は概ね正しく解消されていますが、supply-chain gate に入れ子の malformed/error-bearing advisory をゼロ件相当へ落とせる経路が残っています。fail-closed の中核なので Critical とします。