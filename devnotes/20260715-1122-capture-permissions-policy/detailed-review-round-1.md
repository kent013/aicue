**施策別判定**

- **T1（`config/security.php` への capture 用 policy / route allowlist 追加）**: **APPROVE**
- **T2（`SecurityHeaders` で route ごとに `Permissions-Policy` を切替）**: **APPROVE**
- **T3（`SecurityHeadersTest` の追加）**: **REQUEST_CHANGES**

**指摘事項**

- [Warning] **T3 の 404 ケースの前提が実装実態とズレる可能性**
  - 設計では「`/app` 配下の未解決 404（存在しない manual id）」を fail-secure 検証対象にしていますが、Laravel の scoped binding では「route は解決済みだが model bind 失敗で 404」になり、`routeIs('capture.manuals.show')` が `true` のままになる可能性があります。  
  - その場合、期待値は baseline ではなく capture policy になり、テストが不安定/誤検知化します。
  - **修正案**: 404 検証を2系統に分離してください。  
    1) **route 未解決 404**（例: `/app/not-found`）→ baseline を期待（真の fail-secure）  
    2) **binding 失敗 404**（`capture.manuals.show`）→ route 名一致なら capture policy を期待（現在実装仕様に整合）

- [Warning] **`config()->array()` の戻り値保証に対する PHPStan 観点の明文化不足**
  - `config()->array('security.capture_permissions_policy_routes')` は framework 拡張前提ですが、プロジェクト外から見ると型保証が暗黙です。`array_filter(..., is_string(...))` は良いものの、`list<string>` 前提を置くならテストで「不正型混入時に安全側へ倒れる」ケースを追加した方が堅いです。
  - **修正案**: テスト追加  
    - `Config::set('security.capture_permissions_policy_routes', ['capture.manuals.show', 123, null])`  
    - `capture.manuals.show` では capture policy が適用されること（文字列のみ採用）  
    - 非 capture route では baseline であること

- [Suggestion] **ポリシー文字列の妥当性チェックを軽く入れると運用事故を減らせる**
  - env ミスで無効な `Permissions-Policy` が設定されても気づきづらいです。
  - **提案**: 少なくともテストで期待する directive（`camera`, `microphone`）を含むことを1本追加、または config コメントに RFC 準拠形式を明記。

- [Suggestion] **設計意図の追記**
  - least-privilege の意図は明確で良いです。`config/security.php` に「`capture.manuals.show` 以外へ広げない理由（XSS時の権限面積増大）」を1行コメントすると将来の緩和変更にブレーキが掛かります。

**レビュー観点サマリ**

- 正確性/エッジケース: 概ね良好。route 未解決と binding 404 の区別のみ要補強。
- 既存整合: 命名・実装パターンとも既存 middleware 設計に整合。
- PHPStan Lv10: `is_string` ナローイングは適切。配列型の安全性は追加テストで補強推奨。
- テスト網羅: 方針は良いが 404 シナリオ定義の再整理が必要。
- DTO/JsonResource: 今回対象外（ヘッダ middleware 変更）で逸脱なし。
- Inertia/API 使い分け: 今回対象外で問題なし。
- 副作用/後退: 限定 route 適用により影響面は小。`manuals.index` baseline 維持テストは有効。
- セキュリティ不変条件: tenant/認可/IDOR 等を直接崩す変更ではなく、緩和範囲も最小で妥当。  
  Permissions-Policy 緩和の妥当性は **「撮影 document のみに限定」** を満たしており適切。

**全体判定**

- **CHANGES_REQUESTED**（主に T3 の 404 テスト期待値の整理が必須）