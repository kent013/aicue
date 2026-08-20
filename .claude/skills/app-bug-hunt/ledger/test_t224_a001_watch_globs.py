"""T224: A-001 の再オープン条件と watch_globs の食い違いを閉じたことの契約テスト (stdlib のみ)。

A-001 の再オープン条件 (b) は `resources/js/lib/stores/toast.ts` の
`AUTO_DISMISS_MS` を挙げているが、A-001 自身の `watch_globs` にこのファイルは無かった
(移行時点の登録はそのままにする append-only 規約のため、A-001 は今後も直さない)。
この登録を supersede する新登録 (A-004) が `watch_globs` に `toast.ts` を持つことを固定する。

実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
"""

from __future__ import annotations

import unittest
from pathlib import Path

import render_spec_ledger as renderer

LEDGER_DIR = Path(__file__).resolve().parent
ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"

# A-001 が持っていた種別・対象面 (機械項目は不変。新登録も同じ判定を指す)。
SPECIES_KEY = "other:video_manual:delete:self"
SCOPE_VALUE = "projects.manuals.destroy"
REQUIRED_WATCH_GLOB = "resources/js/lib/stores/toast.ts"


# A-001 の移行時点の内容 (機械項目は append-only 規約によりこの先も書き換えない)。
EXPECTED_A001 = {
    "adjudication_id": "A-001",
    "species_key": SPECIES_KEY,
    "scope": {"scope_kind": "route_name", "scope_value": SCOPE_VALUE},
    "conditions": {"browser": "chromium", "mode": "real-llm"},
    "symptom": {
        "required_tokens": ["delete_success_flash_missing"],
        "known_tokens": ["toast", "auto_dismiss", "projects_show_redirect"],
    },
    "verdict": "false_positive",
    "rationale_ref": "devnotes/20260804-0021-ux-small-gaps/detailed-design.md",
    "source_finding_ids": ["F-1-02"],
    "adjudicated_at_run": "20260803-203721",
    "adjudicated_at_commit": "22d6d30",
    "watch_globs": [
        "app/Http/Controllers/Projects/VideoManualController.php",
        "resources/js/components/organisms/ToastContainer.svelte",
        "resources/js/lib/stores/flash-to-toast.ts",
    ],
    "review_after_days": 180,
    "context": {
        "title": "動画マニュアル削除後に「成功 flash が出ない」ように見えた",
        "spec_basis": [
            "app/Http/Controllers/Projects/VideoManualController.php:230-232 削除後 projects.show へ redirect し ->with('success', '動画マニュアルを削除しました')",
            "resources/js/lib/stores/toast.ts:23-29 success/info/warning は 4000ms で auto-dismiss、error のみ null = 自動消去しない",
            'resources/js/components/organisms/ToastContainer.svelte role="status" + data-testid="toast-{type}" で描画',
            "tests/Browser/FlashToastTest.php 着地マーカーと同一時間窓で toast-success が可視になることを Chromium / WebKit の 2 レーンで pin",
        ],
        "narrative": '**なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**\n\n**driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。',
        "reopen_condition": "次のいずれか。(a) VideoManualController::destroy が ->with('success', ...) を落とした、(b) toast.ts の success 用 AUTO_DISMISS_MS が大幅に短縮された、(c) feedback probe が installed_now:false かつ seen(visible:true) / present_new ともに空を返した。**probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**",
    },
}


class A001WatchGlobsCoverToastTest(unittest.TestCase):
    def test_a001_itself_is_unchanged_and_now_superseded(self) -> None:
        """A-001 は機械項目・context を含め移行時点から 1 バイトも変えず、
        supersede によって履歴化されていること。"""
        records = {r["adjudication_id"]: r for r in renderer.load_adjudications(str(ADJUDICATIONS))}
        self.assertIn("A-001", records)
        self.assertEqual(
            records["A-001"],
            EXPECTED_A001,
            "A-001 は機械項目・context を含めて append-only 規約により書き換えない",
        )
        superseded_ids = {r["supersedes"] for r in records.values() if r.get("supersedes")}
        self.assertIn("A-001", superseded_ids, "A-001 は新登録に supersede されて履歴化されていること")

    def test_active_successor_of_a001_is_a004_and_watches_toast_ts(self) -> None:
        """A-001 を **直接** supersede し、かつ同じ種別・対象面を持つ登録がちょうど 1 件あり、
        それが現在 active であり、その watch_globs に toast.ts が含まれること。

        supersede 関係・種別/対象面の一致・active 状態・watch_globs をまとめて 1 つの登録
        (A-004) に対して検証する (別々の登録がそれぞれの条件を満たすだけでは通らない)。
        """
        records = renderer.load_adjudications(str(ADJUDICATIONS))
        by_id = {r["adjudication_id"]: r for r in records}
        superseded_ids = {r["supersedes"] for r in records if r.get("supersedes")}

        direct_successors = [r for r in records if r.get("supersedes") == "A-001"]
        self.assertEqual(
            len(direct_successors),
            1,
            f"A-001 を直接 supersede する登録がちょうど 1 件であること: {direct_successors!r}",
        )
        successor = direct_successors[0]
        self.assertEqual(
            successor["adjudication_id"],
            "A-004",
            "A-001 を supersede する登録の id は A-004 であること (他の id へ差し替わっていないこと)",
        )

        # 同じ種別・対象面を持ち、かつ現在 active (誰にも supersede されていない) であること。
        self.assertEqual(successor["species_key"], SPECIES_KEY)
        self.assertEqual(successor["scope"]["scope_kind"], "route_name")
        self.assertEqual(successor["scope"]["scope_value"], SCOPE_VALUE)
        self.assertNotIn(
            successor["adjudication_id"],
            superseded_ids,
            f"{successor['adjudication_id']} 自身が別の登録に supersede されていないこと (active であること)",
        )

        # 判定内容 (機械項目) は A-001 から変えていないこと。
        for key in (
            "verdict",
            "conditions",
            "symptom",
            "rationale_ref",
            "source_finding_ids",
            "adjudicated_at_run",
            "adjudicated_at_commit",
        ):
            self.assertEqual(
                successor[key],
                by_id["A-001"][key],
                f"{successor['adjudication_id']}.{key} は A-001 と同じであること (判定内容は変えていない)",
            )

        self.assertIn(
            REQUIRED_WATCH_GLOB,
            successor["watch_globs"],
            f"{successor['adjudication_id']}: watch_globs に {REQUIRED_WATCH_GLOB} が無い "
            "(toast.ts の AUTO_DISMISS_MS 変更が invalidation を発火しない)",
        )

        # 同じ種別・対象面を持つ active な登録は、この 1 件だけであること
        # (誤って A-001 と同種の登録が並立していないか)。
        active_same_species_scope = [
            r
            for r in records
            if r["species_key"] == SPECIES_KEY
            and r["scope"]["scope_value"] == SCOPE_VALUE
            and r["adjudication_id"] not in superseded_ids
        ]
        self.assertEqual(
            active_same_species_scope,
            [successor],
            "同じ種別・対象面の active な登録は A-004 の 1 件だけであること: "
            f"{[r['adjudication_id'] for r in active_same_species_scope]!r}",
        )


if __name__ == "__main__":
    unittest.main()
