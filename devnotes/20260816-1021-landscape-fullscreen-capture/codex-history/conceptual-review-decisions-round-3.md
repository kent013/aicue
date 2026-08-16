# 対応マトリクス: conceptual-review Round 3 (APPROVED)

Round 3 で全体判定 **APPROVED**。Critical / Warning は 0 件。
残る [Suggestion] 2 件は**詳細設計 (Phase 2) へ引き継ぐ**。

## [Suggestion] 負のコントロールに「高さ 540px 以下かつ `pointer: fine`」も加える

- 判断: **対応する (詳細設計で反映)**
- 根拠: 現在の負のコントロール 2 本は `max-height` 条件の検証にはなるが、
  `pointer: coarse` の項が式から**抜け落ちた**ケース (= 誰でも全画面になる回帰) を
  直接は検出しない。「高さ 540px 以下 かつ `pointer: fine`」で全画面にならないことを
  見れば、`pointer` 条件が式に残っていることを直接固定できる。
- 対応内容: 詳細設計のテスト計画で負のコントロールを 3 本に増やす。

## [Suggestion] `CameraRecorder` が受け取る撮影ガイド文言の型を `CaptureCut.shooting_point` の nullable 契約と一致させる

- 判断: **対応する (詳細設計で反映)**
- 根拠: `CaptureCut.shooting_point` は `string | null`。`CameraRecorder` の props で
  非 null に狭めると呼び出し側が `?? ""` を書くことになり、
  「空文字列」と「未設定」の区別が呼び出し側の作法に依存する。
  型は**上流の契約に合わせ**、非 null へ絞る判定は `CameraRecorder` の内側 1 か所で行う
  (そこから先の `ShootingGuideOverlay` は非 null の `text: string` を受ける)。
- 対応内容: 詳細設計で `shootingPoint?: CaptureCut["shooting_point"]` と宣言し、
  既存 `subtitlePrimary?: CaptureCut["subtitle_primary"]` の書き方に合わせる。

## その他の [Suggestion] (使命整合 / 禁止事項 / 実現可能性 / 効果 / リスク / スコープ / 型安全性)

- 判断: **見送る** (対応不要)
- 根拠: いずれも肯定的評価であり設計変更を要しない。
