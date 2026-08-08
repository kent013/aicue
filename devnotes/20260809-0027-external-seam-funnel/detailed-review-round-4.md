## 全体判定: CHANGES_REQUESTED

設計ロジック自体は承認可能な水準です。残件は波及変更一覧と実装手順の同期漏れで、実装対象の取りこぼしにつながるため修正が必要です。

### S1: REQUEST_CHANGES

[Warning] `ReferenceScanResult` の新規ファイルが変更ファイル一覧にありません。

設計では独立した `final readonly class ReferenceScanResult` を追加していますが、施策一覧とS1変更箇所には次の3ファイルしか記載されていません。

- `PhpReferenceScanner.php`
- `ReferenceKind.php`
- `ReferenceSite.php`

修正案: 新規 `tests/Support/ReferenceScanResult.php` を施策一覧、S1変更箇所、波及変更へ追加してください。PHPStan適合チェックも「`references()` は `ReferenceScanResult` を返す」へ更新してください。

走査ロジック、public API維持、既存テストによる振る舞い保存の方針は承認します。

### S2: APPROVE

import metadata、facade canonical、抑制の可視化、既知の検出限界が一貫しています。

### S3: APPROVE

`(class, kind)` の分類、委譲、免除の既定拒否に問題はありません。

### S4: APPROVE

移設範囲と回帰条件は適切です。

### S5: REQUEST_CHANGES

[Warning] 新設するscannerとunit testが施策一覧・変更箇所に反映されていません。

S5では次も新設します。

- `tests/Support/PestTestNameScanner.php`
- `tests/Unit/Architecture/PestTestNameScannerTest.php`

現状の変更箇所は `ExternalSeamInventoryTest.php` のみで、実装者が補助scannerの追加を落とせる状態です。

修正案: 上記2ファイルを施策一覧のS5とS5変更箇所、新規テスト一覧へ追加してください。

[Warning] テストファースト手順に旧テスト名が残っています。

目録を空にして赤を確認する対象が、旧名称の「走査で検出したクラスは目録と対称差ゼロ」のままです。現在の正式名称は「走査 site と目録は (クラス, 種別) で双方向に一致する」です。

修正案: 実装順序とテスト計画を新名称・新しい失敗条件に同期してください。期待結果も「12クラス」ではなく「12個の分類済み到達 `(class, kind)`」と表現する方が現在の識別単位に合います。

排他的被覆の実装、M16/M17、`PestTestNameScanner` の負のコントロールは妥当です。

### S6: APPROVE

20ケースで走査器の正例、負例、scope、canonical、複数規則を十分に固定しています。

### S7: APPROVE

fake配線と負のコントロール、環境復元、既存Architecture gateへの波及は適切です。

### S8: REQUEST_CHANGES

[Warning] `docs/app-integration-guide.md` が実際の変更対象なのに変更箇所一覧から漏れています。

波及変更では「相互参照を1行追加」としていますが、施策一覧とS8変更箇所は `docs/architecture.md` / `AGENTS.md` の2ファイルだけです。

修正案: `docs/app-integration-guide.md` を施策一覧とS8変更箇所へ追加してください。

保証しないものの正本を `docs/architecture.md` に一本化する設計は承認します。

DTO/JsonResource、Inertia、TypeScript、DESIGN.md、Atomic Designは本変更の対象外です。上記のファイル台帳と手順名を同期すれば、設計上の追加変更要求はありません。