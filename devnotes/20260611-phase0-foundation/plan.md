# Phase 0: 基盤(M1)実装メモ

> **ステータス: 完了(2026-06-11)**。Laravel 13.15 + Inertia 3 + Svelte 5 + Tailwind 4 + Vite 8。
> Pest(parallel+worktree 分離 bootstrap)/ PHPStan lv10 / Pint / ESLint(better-tailwindcss) /
> tsc / Vitest / build すべて green。welcome が Inertia 経由で描画(HTTP 200)。
> 計画からの変更点:
> - scripts の sha256sum/sha1sum → shasum(macOS 互換)、flock は不在時スキップ
> - EnvExampleInvariantTest に TEMPLATE_APP_SLUG チェックを追加
> - AppNameHardcodeTest を新設(slug ハードコード検出。spirux の aigenba-test- 残骸事故の再発防止。
>   slug が既定値 'app' の間は休眠し、派生アプリが固有 slug を設定すると発動する)
> - resources/js/inertia.ts に resolvePage(未解決ページは throw)を分離
> - Dusk 配線は Phase 0 では見送り(Phase 1 で認証画面とセットで導入する方が検証対象がある)

> `devnotes/20260611-template-extraction/09-extraction-plan.md` Phase 0 の着手前設計メモ。
> DoD: 素の welcome 画面が起動 / 全 lint・test ハーネスが green / EnvExampleInvariantTest 同梱 /
> config/template.php 骨格 / CI。

## 方針

- リポジトリルート(= laravel-claude-template 自体)を Laravel アプリにする。
  既存の `scripts/claude`・`scripts/codex`・`devnotes/`・`tmp/`(調査用、git 管理外)は温存。
- `composer create-project laravel/laravel` で最新スケルトンを生成し、ドナーから
  ツールチェーン設定を移植・汎用化する。
- アプリ名等の環境座標は `config/template.php` + `.env` に集約(12 §8-3 の知見)。

## ドナーから移植するもの(Phase 0 範囲)

| ファイル | ドナー | 汎用化ポイント |
|---|---|---|
| mise.toml | aigenba | pnpm 11.3.0 pin そのまま |
| mprocs.yaml | 折衷 | ポートは spirux 系(dev 8001 / dusk 8000)。stripe pane はコメントアウト。vite は pnpm |
| composer.json scripts | aigenba | setup/dev/test/test:dusk/phpstan/fix。test:e2e・check-package-builds 系は除外 |
| scripts/run-test.sh | aigenba | flock 排他。アプリ名非依存化 |
| scripts/phpstan.sh | aigenba | そのまま |
| scripts/dusk.sh | spirux | Phase 0 では未配線(Dusk 導入時=Phase 0 内後半 or 0.5) |
| phpstan.neon | 両方 | level 10、Passport migration excludePaths は Phase 6 で追加 |
| tests/bootstrap.php | aigenba | `/tmp/{slug}-test-<hash>.sqlite`。slug は composer.json name 由来に汎用化 |
| eslint.config.js | aigenba | better-tailwindcss(entryPoint=resources/css/app.css)+ svelte |
| vite/svelte/tailwind/tsconfig/vitest 設定 | aigenba | Svelte 5 + Inertia + Tailwind v4 CSS-first |
| .editorconfig / pint(既定) | 標準 | — |
| kill-ports.sh / init.sh | aigenba | ポート変数化。init.sh は Phase 10 で本実装、いまは最小 |
| tests/Architecture/EnvExampleInvariantTest | aigenba | env() 参照と .env.example の drift 検出。汎用化 |
| .github/workflows/ci.yml | aigenba | sharding なしの単純版から開始(テスト数が少ないため) |

## Phase 0 でやらないこと

- DESIGN.md / tokens.css / コンポーネント(→ Phase 0.5)
- Fortify / Socialite / CipherSweet(→ Phase 1)
- Dusk の本格配線(env 分離 docs 含め Phase 0 内で骨格まで、fake provider は各フェーズ)
- Filament / Cashier / Laratrust(→ 各フェーズ)

## 検証

`composer test`(Pest)、`composer phpstan`、`vendor/bin/pint --test`、`pnpm lint`、`pnpm test`、
`pnpm build`、`php artisan serve` で welcome 表示。
