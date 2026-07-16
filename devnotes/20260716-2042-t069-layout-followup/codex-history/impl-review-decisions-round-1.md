# 対応マトリクス: impl-review Round 1（REQUEST_CHANGES）

## [Critical] Invitations/Accept の幅分類が実態と不一致（7xl 割当だが元は mx-auto max-w-md）
- 判断: 対応する（実装と設計を揃える。実装を正=狭幅フォームとして md に修正）
- 根因: 私の当初監査 grep が `max-w-(xl|2xl|..7xl)` のみで **`max-w-md` を見落とし**、Invitations/Accept を
  「max-w 無し=7xl」と誤分類していた。実態は `mx-auto max-w-md`（既に中央寄せの狭幅フォーム、F-0-2 の
  左寄せ問題は元々無し）。
- 対応:
  1) `PageContentMaxWidth` union に `sm | md | lg` を追加（狭幅カードも表現可能に）。`MAX_W` Record と
     PageContent.test（md ケース）も更新。
  2) Invitations/Accept を `<PageContent maxWidth="md">` に修正し、内側の重複 `mx-auto max-w-md` を除去
     （幅は PageContent が単独所有。`mt-8` は保持）。
  3) 詳細設計の maxWidth 割当表を訂正（Invitations/Accept = md、監査 grep の穴を注記）。
  4) 他の「7xl」5 ページ（Admin/Users, Admin/Categories, Notifications/Index, Capture/Index, Dashboard）は
     再監査の結果 max-w-(sm|md|lg) も mx-auto も無く真の全幅 → 7xl のままで正しい（変更なし）。

## [Warning] arch テスト allowlist が「理由コメント必須」だけで実効性が弱い
- 判断: 対応する（機械強制化）
- 対応: `ALLOWLIST` を `{ path, reason }[]` 構造にし、「各エントリの reason 非空」を assert するテストを追加
  （空理由の無断追加を機械的に fail）。走査は `ALLOWLIST_PATHS`(Set) で判定。

## [Warning] importsAppLayout が default import 1 形前提
- 判断: 見送る（Codex も「Svelte では通常ない」と明記）
- 根拠: Svelte のコンポーネント import は default import が標準。`import { }` 形は使わない。過剰対応を避ける
  （AGENTS.md オーバーエンジニアリング禁止）。将来書き方が変わればその時点で拡張。

## [Warning] AppLayout テストの desktop/mobile 両負例 / testId は class assertion 主体
- 判断: 対応済み（確認）
- 対応: AppLayout.test に desktop/mobile 両シェルの `/settings` 非表示負例を実装済み。PageContent 表示テストは
  class assertion（mx-auto + max-w-*）主体で testId は補助。

## [Suggestion] 群（PageContent union/Record, Manuals/Edit 二段, PurchaseTickets 内側 xs 保持）
- いずれも APPROVE。変更なし。
