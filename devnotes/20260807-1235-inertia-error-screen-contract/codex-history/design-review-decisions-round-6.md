# 対応マトリクス: design-review Round 6 (確認ラウンド)

Codex 全体判定: **APPROVED**
([Critical] 0 件 / [Warning] 0 件 / [Suggestion] 0 件。新規 [Critical] も「なし」)

Round 6 は Round 5 の残件 1 件に対する対応の**再判定のみ**を目的とした確認ラウンド。
one-shot モード (`--ephemeral`) で実施 (Round 5 までのセッションは残っていないため)。

## [Warning] S4: 「既存の Cache-Control directive を落とさない」テストが成立していない (Round 5 提起)

- 判断: **解消 (Codex が RESOLVED と判定)**
- Codex の判定理由:
  > `ErrorScreenCachePolicy::apply(Response $response)` にキャッシュポリシーを独立させ、
  > Unit テストで `must-revalidate` を持つ **適用対象そのものの応答**に直接適用する設計になっている。
  > これにより「原応答に積んだ directive が、Inertia が新規生成した `$rendered` に移植されない」
  > という混同は解消されている。Feature 側から当該テストを外し、削除理由も明記されているため、
  > テストが検出する契約と実装構造が一致している。
- 反映済みの内容 (Round 5 decisions のとおり):
  - 新規 `app/Support/Http/ErrorScreenCachePolicy.php` (`apply(Response): void` に
    Vary / no-store / private を集約。加算方式で既存 directive を落とさないことを docblock で契約化)
  - `InertiaExceptionRenderer::render()` は `ErrorScreenCachePolicy::apply($rendered)` を呼ぶだけ
    (**原応答ではなく生成した応答**に適用することをコメントで明示)
  - 施策一覧 / S4 変更箇所 / 波及変更 / PHPStan 適合チェックに新ファイルを追加
  - Feature から `it('既存の Cache-Control directive を落とさない')` を削除 (削除理由を設計書に引用注で明記)
  - 新規 `tests/Unit/Http/ErrorScreenCachePolicyTest.php` に 5 ケース
  - mutation M17 の対象テストを `ErrorScreenCachePolicyTest` へ付け替え

## Round 6 で Claude 側が自己検証して見つけた反映漏れ (Codex 送付前に修正済み)

- **事象**: Round 5 の反映編集で新規 Unit テストの箇条書きを挿入した位置が原因で、
  Feature テスト `InertiaErrorScreenTest` に属する 2 ケース
  (`戻り先が全 status で 1 件以上ある` / `cross-org 実在と不在で Error 応答が分岐しない`) が
  `tests/Unit/Http/ErrorScreenCachePolicyTest.php` の箇条書きへ誤って吸収されていた。
- **問題**: 後者は Project Factory (DB) と HTTP 経路を要するため、
  「DB 不使用・reflection 不使用」と明記した Unit テストに置くのは矛盾する。
  かつ `TenantBoundaryPrecedenceTest` の契約を差し替え経路で維持する確認という
  **最も落としてはいけないケース**が実行不能な場所に置かれていた。
- **対応**: 両ケースを `InertiaErrorScreenTest` (Feature) 側へ戻した。
  修正後の本文を Round 6 プロンプトに貼り、その旨も明記して Codex に提示した。

## ラウンド記録

| Round | 判定 | 残件 |
|-------|------|------|
| 1〜4 | CHANGES_REQUESTED | (各 decisions ファイル参照) |
| 5 | CHANGES_REQUESTED | S4 [Warning] 1 件 ([Critical] 0) |
| **6 (確認)** | **APPROVED** | **なし** |

詳細設計レビューは Round 6 で完了。未解消の [Critical] / [Warning] は 0 件。
Round 7 は不要。
