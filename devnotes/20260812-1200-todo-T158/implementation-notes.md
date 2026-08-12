# 実装メモ: T158 XHR の 404 に内部クラス名が漏れる (bug-hunt F-1-03)

設計: `devnotes/20260812-1200-xhr-404-message/` (概念 Round 3 APPROVED / 詳細 Round 3 APPROVE)。
実装レビュー: `impl-review-round-2.md` (APPROVE)。

## 原因 (実コードで特定)

例外応答を整形するクラスは 2 つあるが、**担当領域が隙間なく敷き詰められていなかった**。

- `ApiExceptionRenderer` … `shouldHandle()` が `$request->is('api/*')` のみ
- `InertiaExceptionRenderer` … `is('api/*') || expectsJson()` を「機械可読な封筒が正しい」として素通し

**`expectsJson() かつ !is('api/*')` の領域には封筒を作る者が居ない**ため、Laravel 既定の JSON 化
(= `NotFoundHttpException` の message をそのまま) に落ち、`ModelNotFoundException` 由来の
`No query results for model [App\Models\Take] 1` が出ていた。`APP_DEBUG` は無関係。

## 実装したもの

| # | 施策 |
|---|---|
| 1 | `NotFoundMessage` (DTO) + `NotFoundMessageResource` (JsonResource)。**`response()->json()` 直書きをしない** (禁止事項 4) |
| 2 | `bootstrap/app.php` に render callback を 1 本追加。`expectsJson()` かつ `HttpExceptionInterface` かつ status 404 のときだけ非 null |
| 3 | `InertiaErrorScreenPassthrough::MachineReadableEnvelope` の docblock 是正 (「封筒が返る」と読める記述の修正) |
| 4 | Feature テスト 15 件 (契約 1..8) |
| 5 | Architecture テスト 14 件 (文言つき 404 が 0 件 + 記法ごとの自己検査 13) |

**collapse は `api/*` 以外へ全面適用し、prefix は「文言」しか決めない** —
機械向け経路 (`oauth` / `.well-known` とその直下・配下) は英語 `Not Found`、それ以外は日本語。
分類から漏れても起きるのは「機械向けに日本語が返る」見た目の問題だけで、**情報露出は起きない**。

## 合議で棄却した案 (記録)

- **除外 prefix 方式** (機械向け経路を collapse 対象から外す) は棄却。
  「除外はセキュリティ目的と逆向き」で、その領域に同種の露出が残るため。
- **`ApiExceptionRenderer` の担当領域拡大**も棄却。撮影 PWA のクライアントが
  `record.message` / `body.code` を読むため、封筒形にすると**クライアントが壊れる**。

## mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | `expectsJson()` 判定を外す | 契約 3 | 一致 |
| M2 | status 404 の判定を外す | 契約 4 | **当初赤化せず → 契約 4 を拡張して一致** |
| M3 | callback を `ApiExceptionRenderer` より前へ | 契約 1 | 一致 (2 dataset) |
| M4 | 例外型を `ModelNotFoundException` に狭める | 契約 2 | 一致 |
| M5 | 文言選択を常に日本語に | 契約 7 | 一致 (機械向け 4 dataset 全部) |
| M6 | `$exception->getMessage()` を載せる | 契約 8 | 一致 |

### M2 が最初赤くならなかった原因

契約 4 の dataset が **401 だけ**だった。401 (`AuthenticationException`) は
`HttpExceptionInterface` を実装しないため、「status 404 の判定を外す」mutation が素通りする。
**403 (`AccessDeniedHttpException`) と 409 (`abort`)** を足して赤化を実測し、
実装レビューの指摘で **422 (validation 応答の形の固定)** も追加した。
**402 は入れていない** — 課金ゲート固有の組織状態セットアップが要り、本 TODO との距離が遠いため。

## 設計からの乖離 2 点

1. 契約 4 の dataset 設計 (上記 M2 の件)。
2. `bootstrap/app.php` から `use Throwable;` を削除。名前空間を持たないファイルで
   非複合名の use は無効で、PHP 警告が出ていた (このリポジトリには同種の gate もある)。

## 検証コマンド (worktree 内)

`composer test` 4527 passed / 2 skipped (4529) / `pnpm test` 1357 passed /
`composer phpstan` No errors / `vendor/bin/pint --test` passed / `pnpm lint` / `pnpm build`: 全緑。

## 保証しないもの (誇張しない)

- **404 以外の status は変えない**。403 / 422 / 409 の body は現行のまま。
  棚卸しで「クラス名を出す箇所 0 件」は確認したが、**独自例外の message までは追っていない**。
- **封筒形にはしない**。`api/*` の統一封筒とは別の形のままで、「JSON 応答が統一された」とは書かない。
- **認可・存在秘匿の挙動は変えない**。変わるのは message 文字列だけ。
- **Architecture テストは列挙した直接記法の変更検知**であり、変数経由・動的生成・helper 経由は
  捕捉しない。collapse の安全性は Feature テストが担う。
