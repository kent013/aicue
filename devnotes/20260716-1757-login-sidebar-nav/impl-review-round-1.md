**全体判定**
- **APPROVED**

**総評**
- 設計 S1〜S7 への整合は高く、特に「認可マッピングを shared prop 真実に合わせる」「org null 時に org-scoped 導線を非表示」「OrganizationSwitcher 廃止と AppLayout 一元化」の主眼を満たしています。
- バックエンド変更を Feature テスト補強（sidebar visibility contract）に限定しており、DTO/JsonResource 禁止事項とも矛盾しません。
- 追加テスト内容も主要操作・負例・回帰点を押さえており、品質基準を満たします。

**ファイル別レビュー**

- `resources/js/components/templates/AppLayout.svelte`
  - 判定: **OK**
  - [Suggestion] `toggleSidebar()` 内の `if (!sidebarOpen) closeUserMenu();` は、トグル後の値に依存するため意図が「折りたたみ時に閉じる」か「展開時に閉じる」か読み取りづらいです。  
    修正案: `const next = !sidebarOpen; sidebarOpen = next; if (!next) closeUserMenu();` のように明示すると将来保守が安全です。

- `resources/js/components/templates/_helpers/SidebarNavItems.svelte`
  - 判定: **OK**
  - [Suggestion] `data-testid="nav-item-{item.href}"` は `/` を含むため、将来 selector で扱いづらくなる可能性があります。  
    修正案: testid を `nav-item-${encodeURIComponent(item.href)}` 相当に寄せる（既存テスト資産との兼ね合いがあるため今すぐ必須ではない）。

- `resources/js/components/templates/_helpers/SidebarUserMenu.svelte`
  - 判定: **OK**
  - [Suggestion] desktop 側 popup に `role="menu"` があり、子要素は `a/button` のままですが、厳密な ARIA menu パターンに寄せるなら `menuitem` 等との整合を取る余地があります。  
    修正案: 現状維持（実運用優先）か、将来 a11y 改善として role 設計を再整理する。

- `tests/js/components/templates/AppLayout.test.ts`
  - 判定: **OK**
  - [Suggestion] コメント内ファイル名が `tests/Feature/SidebarVisibilityContractTest.php` になっていますが、実体は `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php` です。  
    修正案: コメントだけ実ファイル名に合わせると追跡性が上がります。

- `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php`
  - 判定: **OK**
  - [Suggestion] 追加した未認証ケースは良いです。将来の可読性のため、既存ケース名にも `(sidebar visibility contract)` の語を統一的に付与すると契約テスト群として検索しやすくなります。

- 削除: `resources/js/components/features/organizations/OrganizationSwitcher.svelte`
  - 判定: **OK（設計 S4 に一致）**

- 削除: `tests/js/components/features/organizations/OrganizationSwitcher.test.ts`
  - 判定: **OK（旧実装並走の解消として妥当）**

**観点別チェック**

- 設計一致（S1〜S7）: **満たす**
- 正確性/エッジケース/null 安全: **満たす**
- PHPStan Lv10: **問題なし（報告ベース）**
- DTO/JsonResource 観点: **逸脱なし**
- テスト網羅性: **十分**
- セキュリティ（403導線抑止）: **満たす**
- DESIGN.md/token/DS purity: **逸脱なし**
- Atomic Design/単方向 import/Lucide: **逸脱なし**

必要なら次に、上記 Suggestion のうち「今PRで直すべき最小セット（コメント整合のみ等）」を優先度付きで絞って提案します。