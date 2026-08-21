## Round 3: 前回 (Round 2) 指摘への対応

### [Warning] F-2-03「全経路 403」表現が 2 箇所残存
→ 対応。事前検証表 F-2-03(c) と背景・課題の文をいずれも
  「検証した主要 route (dashboard/projects/billing/manage-users) で 403 (fail-closed)」に統一。

### [Warning] F-2-01 のテスト計画がフロントエンド UI 挙動を固定していない
→ 対応。テスト計画に項 9 を追加:
  Svelte テスト (tests/js/pages/AdminUsers.test.ts) で
  - hasDefaultProject=false: 編集者・撮影者 option に注記サフィックス付与、管理者は非付与、
    3 option とも disabled でない (禁止事項 8 の遵守を固定)
  - hasDefaultProject=true: 注記が消える (対の正例)

上記 2 点のみの修正です。該当箇所を再掲します。全体判定をお願いします。

---

## 事前検証表 F-2-03(c) (修正後)
| F-2-03 (c) | 「未割当」= attach 済み・laratrust ロール無しの行が組織データを見られるか | 検証した主要 route (dashboard/projects/billing/manage-users) で **403** (fail-closed) |

## 背景・課題 F-2-03 (修正後・抜粋)
検証の結果、この状態は検証した主要 route (dashboard/projects/billing/manage-users) で
アクセスが 403 (fail-closed) となり、管理画面から applyConsoleRole の修復経路でロールを
付け直せるため、情報漏洩には至らない。

## テスト計画 項 8-9 (修正後)
8. (backend データ契約) プロジェクト 0 件組織で /manage/users の Inertia prop
   hasDefaultProject=false、1 件以上で true。既存の no-project-note 表示も維持。
9. (frontend, Svelte テスト tests/js/pages/AdminUsers.test.ts) hasDefaultProject=false のとき
   ロール select の編集者・撮影者 option に注記サフィックスが付き、管理者には付かず、
   3 option とも disabled でない (禁止事項 8 の遵守を固定)。hasDefaultProject=true の対の
   正例では注記が消えることを固定する。
