# 概念設計レビュー Round 2 (対応反映後の再レビュー依頼)

Round 1 の指摘 (Warning 4 / Suggestion 多数) に対する対応マトリクスと、修正後の概念設計 (該当差分) を提示します。全体判定を再度お願いします。

## 対応マトリクス

| 指摘 | 判断 | 対応 |
|------|------|------|
| [W] 禁止事項 4 との関係未明記 (expectsJson 分岐) | 対応 | 施策 A に「禁止事項 4 との関係」節を追加。Fortify 固定契約の互換維持という例外に該当・`app/Http/Responses/Fortify/` に閉じる・通常 endpoint へ波及させない・docblock に明記、を規定 |
| [W] モバイルヘッダ収まりの設計不足 | 対応 | 施策 B に 375px 方針を固定: ロゴ `shrink-0` + 右側アクション群 `flex flex-wrap items-center justify-end gap-x-3 gap-y-1` の行内折り返し (2 段化)。メニュー化は Phase 2 サイドバー拡張と競合するため不採用。適用後 headerActions 併用ページは 0 件 (Dashboard の snippet は削除) だが、併用が増えても折り返しで構造的に破綻しないことを保証 |
| [W] status→success の明文化不足 (再発防止) | 対応 | 「flash キー統一ポリシー」を明文化: web 向け操作成功 flash は `success` キーに統一 (`status` は flash-to-toast が意図的に gating)。`FortifyResponseTest` を bind 済み Response 群 (password reset link / verification resend / two-factor disabled / recovery codes) の応答契約の正本テストに拡張し、`success` flash を回帰テスト登録。ポリシーはテスト冒頭コメント + 各 Response docblock に記録 |
| [W] Svelte 側の型方針未記載 | 対応 | 「型安全性の方針」節を追加: AppLayout の auth 参照は既存 `SharedProps`/`AuthUser` 型 (`lib/shared-props.ts`、backend が真実) を使用しインラインキャストを置換。`headerActions` は既存 `Snippet` optional 契約を維持。PHP 新 Response は `toResponse(Request): JsonResponse\|RedirectResponse` + strict_types + final で既存パターンに閉じる |
| [S] logout の共通化 | 対応 | AppLayout 内単一ハンドラ (router.post('/logout') + 二重送信ガード) に一本化、ページ側に実装を残さない |
| [S] 「二重送信を抑止」表現 | 対応 | 「成功が見えないことによる不要な再試行を低減 (送信制御は既存 loading ガード/throttle の責務)」に修正 |
| [S] F-14 長文/多言語耐性 | 対応 | `min-w-0` + `truncate` 維持・固定幅を新設しないことを追記 |
| [S] Vitest は proxy → bug-hunt 再確認 | 対応 | 出口条件に実ブラウザ観察 (375px scrollWidth) + bug-hunt 再走行での F-14 消込を追加 |
| [S] A/B/C 独立検証粒度 | 対応 | 詳細設計の施策一覧を A/B/C の独立検証可能な粒度で記載する旨を反映 |

## 修正後の概念設計 (追加・変更部分の全文)

### 施策 A への追加

> **禁止事項 4 (raw JSON) との関係の明記**: `new JsonResponse(...)` 分岐は「Fortify 固定契約 (パッケージが応答形式を規定する仕様固定 endpoint) の互換維持」という禁止事項 4 の例外に該当する。既存の `TwoFactorDisabledResponse` / `EnumerationSafePasswordResetLinkResponse` と同じ位置づけであり、`app/Http/Responses/Fortify/` 配下に閉じる。通常のアプリ endpoint へこのパターンを波及させない (この位置づけを新 Response class の docblock に明記する)。
>
> **flash キー統一ポリシーの明文化 (再発防止)**: 本修正の設計判断を「web 向け操作成功の flash は `success` キーに統一する (`status` は flash-to-toast が意図的に gating しており toast にならない)」というポリシーとして固定する。
> - `tests/Feature/Auth/FortifyResponseTest.php` を「Fortify Response contract bind の応答契約」の正本テストとして拡張し、自前 bind 済み Response 群 (password reset link / verification resend / two-factor disabled / recovery codes) が web 応答で `success` flash を持つこと (= `status` キーに依存しないこと) を回帰テストとして登録する。
> - 同ポリシーは `FortifyResponseTest` の冒頭コメントと各 Response class の docblock に記録し、今後 Fortify 応答を bind する際の参照点とする。

### 施策 B への追加

> **モバイル幅 (375px) のヘッダー収まり方針 (先に固定する)**:
> - ヘッダー行は「ロゴ = `shrink-0`」+「右側アクション群 = `flex flex-wrap items-center justify-end gap-x-3 gap-y-1`」とし、収まらない場合は右側アクション群が行内折り返し (2 段化) する。メニュー化 (ハンバーガー) は Phase 2 のサイドバー拡張と競合するため今回は採らない。
> - 常設要素は「ベル (アイコンのみ) + 設定 (テキストリンク) + ログアウト (ghost/sm ボタン)」の 3 点で、375px でも 1 行に収まる想定だが、`headerActions` を併用するページが増えても折り返しで破綻しないことを上記方針で構造的に保証する。
> - 現在 `headerActions` を渡しているのは Dashboard のみ (本施策で削除) のため、適用後の併用ページは 0 件。snippet 契約は optional のまま維持する。
>
> **ログアウト処理の共通化**: logout POST は Dashboard からの移植ではなく、`AppLayout` 内の単一ハンドラ (Inertia `router.post('/logout')` + 実行中フラグの二重送信ガード) に一本化する。ページ側に logout 実装を残さない (再重複の防止)。

### 施策 C への追加

> - 長い可変テキスト (メールアドレス・名前) は既存の `min-w-0` + `truncate` を維持し、将来の多言語ラベル・長文でも折り返し/省略で吸収できる構造にする (固定幅を新設しない)

### 実装方針への追加 (型安全性・テスト・出口条件)

> **型安全性の方針**:
> - `AppLayout.svelte` の `auth` 参照は既存の `SharedProps` / `AuthUser` 型 (`resources/js/lib/shared-props.ts`、backend の `HandleInertiaRequests` が真実) を使い、現行の場当たり的なインラインキャストを `page.props as unknown as SharedProps` (既存ページと同じ流儀) に置き換える。`any` は使わない。
> - `headerActions` は既存の `Snippet` 型 optional prop を維持 (契約変更なし)。
> - PHP 側の新 Response class は既存 Fortify Response パターンに閉じる (`toResponse(Request): JsonResponse|RedirectResponse`、`declare(strict_types=1)`、final class)。
>
> テスト:
> - **Feature (Pest)**: 認証メール再送で `success` flash が載ること / forgot-password が user 在/不在とも同一の `success` flash であること (既存 `FortifyResponseTest` の `status` アサーションを更新) / bind 済み Fortify Response 群の `success` flash 契約 (flash キー統一ポリシーの回帰防止)
> - **Vitest**: AppLayout がログイン中に設定リンク (/settings) とログアウトボタンを描画すること (auth 無しでは出ないこと) / Dashboard から重複ナビが消えたことの回帰 / Admin/Users のメンバー行がモバイル縦積みクラス (`flex-col` + `sm:flex-row`) と操作ブロック `flex-wrap` を持つこと (jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして検証)
> - **出口条件 (実装 Phase)**: クラス不変条件はプロキシのため、実装時に実ブラウザ観察 (375px で `document.body.scrollWidth <= clientWidth`) を verify 手順に含め、bug-hunt 再走行での F-14 消込を最終確認とする

### 期待効果の修正

> - F-03/F-06: メール送信系操作の成否が toast で即座に分かり、成功が見えないことによる不要な再試行を低減する (送信制御そのものは既存の loading ガード/throttle の責務で、本施策の保証範囲ではない)。

---

以上の反映で Round 1 の全 Critical (0 件)・Warning (4 件) に対応しました。全体判定をお願いします。
