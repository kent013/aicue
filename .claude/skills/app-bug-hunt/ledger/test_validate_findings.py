#!/usr/bin/env python3
"""Tests for validate_findings.py (stdlib unittest)。

実行: python3 -m unittest -v   (このディレクトリで)
     または python3 test_validate_findings.py
"""
import io
import json
import unittest

import validate_findings as v


def rec(**over):
    base = {
        "finding_id": "F-1",
        "run_id": "20260620-001",
        "story_id": "S7",
        "capability_tag": "scenario.read",
        "principal": "org_b_admin",
        "tenant_relation": "cross_tenant",
        "failure_class": "authz_bypass",
        "resource_type": "scenario",
        "operation": "read",
        "species_key": "authz_bypass:scenario:read:cross_tenant",
        "oracle_attribution": "oracle_gap",
        "evidence_ref": "report.md#F-1",
        "triage_status": "proposed",
    }
    base.update(over)
    return base


class ValidateRecordTest(unittest.TestCase):
    def test_valid_record_has_no_errors(self):
        self.assertEqual(v.validate_record(rec()), [])

    def test_missing_required_detected(self):
        r = rec()
        del r["principal"]
        errs = v.validate_record(r)
        self.assertTrue(any("missing required: principal" in e for e in errs))

    def test_empty_required_detected(self):
        errs = v.validate_record(rec(evidence_ref=""))
        self.assertTrue(any("missing required: evidence_ref" in e for e in errs))

    def test_bad_enum_detected(self):
        self.assertTrue(any("failure_class" in e for e in v.validate_record(rec(failure_class="oops"))))
        self.assertTrue(any("tenant_relation" in e for e in v.validate_record(rec(tenant_relation="elsewhere"))))
        self.assertTrue(any("triage_status" in e for e in v.validate_record(rec(triage_status="maybe"))))

    def test_bad_failure_class_suggests_canonical(self):
        # 既知の誤用は正規候補を suggest する (error は維持)
        errs = v.validate_record(rec(failure_class="ux_defect"))
        self.assertTrue(any("bad failure_class" in e for e in errs))
        self.assertTrue(any("broken_flow" in e for e in errs))

    def test_unknown_free_text_still_errors_without_hint(self):
        errs = v.validate_record(rec(failure_class="totally_made_up"))
        self.assertTrue(any("bad failure_class: 'totally_made_up'" in e for e in errs))
        self.assertFalse(any("did you mean" in e for e in errs))

    def test_species_key_must_match_derived(self):
        # 自由文 species_key は拒否される
        errs = v.validate_record(rec(species_key="some free text"))
        self.assertTrue(any("species_key" in e for e in errs))

    def test_species_key_derivation_follows_fields(self):
        # app core: cross-tenant evaluation IDOR (S7)
        r = rec(failure_class="idor", resource_type="evaluation", operation="read",
                tenant_relation="cross_tenant",
                species_key="idor:evaluation:read:cross_tenant")
        self.assertEqual(v.validate_record(r), [])

    def test_resource_type_must_be_lower_token(self):
        self.assertTrue(any("resource_type" in e for e in v.validate_record(rec(resource_type="Scenario"))))

    def test_finding_id_prefix(self):
        self.assertTrue(any("finding_id" in e for e in v.validate_record(rec(finding_id="X-1"))))


class AnalyzeTest(unittest.TestCase):
    def _analyze(self, records):
        buf = io.StringIO("\n".join(json.dumps(r) for r in records))
        # monkeypatch stdin path
        import contextlib
        with contextlib.redirect_stdout(io.StringIO()):
            rep = self._run(buf)
        return rep

    def _run(self, buf):
        # analyze は path から open するので、stdin 経路を使う
        import sys
        old = sys.stdin
        sys.stdin = buf
        try:
            return v.analyze("-")
        finally:
            sys.stdin = old

    def test_counts_and_kpis(self):
        records = [
            rec(finding_id="F-1", severity="critical", regression_link="tests/Feature/Idor1Test.php"),
            rec(finding_id="F-2", severity="high"),                       # regression 無し
            rec(finding_id="F-3", failure_class="ux_dead_end", resource_type="checkout",
                operation="submit", tenant_relation="self",
                species_key="ux_dead_end:checkout:submit:self", oracle_attribution="rule:dead_end"),
            rec(finding_id="F-4", same_as="F-1"),                        # F-1 と同種
        ]
        rep = self._analyze(records)
        s = v.to_summary(rep)
        self.assertEqual(s["total"], 4)
        self.assertEqual(s["completeness"], 1.0)
        self.assertEqual(s["species_consistency"], 1.0)
        # F-1,F-2,F-4 は同 species、F-3 が別 => distinct 2
        self.assertEqual(s["distinct_species"], 2)
        self.assertEqual(s["oracle_gap"], 3)         # F-1,F-2,F-4 が oracle_gap
        self.assertEqual(s["same_as"], 1)
        self.assertEqual(s["high_critical"], 2)      # F-1 critical, F-2 high
        self.assertEqual(s["high_critical_regression_coverage"], 0.5)

    def test_incomplete_lowers_completeness(self):
        bad = rec(finding_id="F-9")
        del bad["capability_tag"]
        rep = self._analyze([rec(finding_id="F-1"), bad])
        self.assertEqual(v.to_summary(rep)["completeness"], 0.5)


class MainExitCodeTest(unittest.TestCase):
    def _write(self, tmp, records):
        p = tmp / "f.jsonl"
        p.write_text("\n".join(json.dumps(r) for r in records), encoding="utf-8")
        return str(p)

    def test_strict_fails_on_low_completeness(self):
        import tempfile, pathlib, contextlib
        with tempfile.TemporaryDirectory() as d:
            bad = rec(finding_id="F-2")
            del bad["principal"]
            path = self._write(pathlib.Path(d), [rec(finding_id="F-1"), bad])
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                code = v.main([path, "--strict", "--min-completeness", "0.9"])
            self.assertEqual(code, 1)

    def test_clean_passes(self):
        import tempfile, pathlib, contextlib
        with tempfile.TemporaryDirectory() as d:
            path = self._write(pathlib.Path(d), [rec(finding_id="F-1"), rec(finding_id="F-2")])
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                code = v.main([path, "--strict"])
            self.assertEqual(code, 0)


# ───────────────── adjudication registry (T877) ─────────────────

def adj(**over):
    base = {
        "adjudication_id": "A-001",
        "species_key": "broken_flow:navigation:read:self",
        "scope": {"scope_kind": "screen_id", "scope_value": "Layout.sidebar"},
        "conditions": {"viewport": "<=389px"},
        "symptom": {"required_tokens": ["sidebar", "drawer"],
                    "known_tokens": ["overlap", "操作不能", "no feedback"]},
        "verdict": "false_positive",
        "rationale_ref": "devnotes/x",
        "source_finding_ids": ["F-1-05"],
        "adjudicated_at_run": "20260623-080644",
        "adjudicated_at_commit": "582ed1fe",
        "watch_globs": ["resources/js/components/templates/Layout.svelte"],
        "review_after_days": 120,
    }
    base.update(over)
    return base


def find(**over):
    base = rec(finding_id="F-9", species_key="broken_flow:navigation:read:self",
               failure_class="broken_flow", resource_type="navigation", operation="read",
               tenant_relation="self", severity="medium",
               summary="375px sidebar drawer overlap covers main",
               surface={"screen_id": "Layout.sidebar"},
               observed_conditions={"viewport": "375px"},
               symptom_tokens=["sidebar", "drawer", "overlap"])
    base.update(over)
    return base


def _adjs(*objs):
    return [(i + 1, o, json.dumps(o)) for i, o in enumerate(objs)]


def _annotate_one(finding, adj_obj, **kw):
    kw.setdefault("run_id", "20260624-101406")
    kw.setdefault("changed_map", set())  # 既定: 何も変わっていない (resolvable)
    out, kpi = v.annotate_findings([finding], _adjs(adj_obj), **kw)
    return out[0], kpi


class AdjudicationValidationTest(unittest.TestCase):
    def test_valid_adjudication(self):
        self.assertEqual(v.validate_adjudications(_adjs(adj())), [])

    def test_rejects(self):
        def errs(**o):
            e = v.validate_adjudications(_adjs(adj(**o)))
            return e[0][2] if e else []
        self.assertTrue(any("scope" in x for x in errs(scope={"scope_kind": "path_glob", "scope_value": ""})))
        self.assertTrue(any("overbroad" in x for x in errs(scope={"scope_kind": "path_glob", "scope_value": "/organizations/*"})))
        self.assertTrue(any("verdict" in x for x in errs(verdict="nope")))
        self.assertTrue(any("required_tokens" in x for x in errs(symptom={"required_tokens": [], "known_tokens": []})))
        self.assertTrue(any("source_finding_ids" in x for x in errs(source_finding_ids=[])))
        self.assertTrue(any("watch_globs" in x for x in errs(watch_globs=[])))
        self.assertTrue(any("review_after_days" in x for x in errs(review_after_days=0)))
        self.assertTrue(any("species_key" in x for x in errs(species_key="free text")))
        self.assertTrue(any("condition key" in x for x in errs(conditions={"bogus": "x"})))

    def test_overbroad_glob_ok_with_two_segments(self):
        ok = adj(scope={"scope_kind": "path_glob", "scope_value": "/organizations/*/settings"})
        self.assertEqual(v.validate_adjudications(_adjs(ok)), [])

    def test_duplicate_id(self):
        e = v.validate_adjudications(_adjs(adj(), adj()))
        self.assertTrue(any("duplicate adjudication_id" in m for _, _, ms in e for m in ms))

    def test_active_multiple_same_key(self):
        a1 = adj(adjudication_id="A-001")
        a2 = adj(adjudication_id="A-002")  # 同 species/scope/conditions/symptom, 両方 active
        e = v.validate_adjudications(_adjs(a1, a2))
        self.assertTrue(any("duplicate active adjudication" in m for _, _, ms in e for m in ms))

    def test_supersede_resolves_multiple(self):
        a1 = adj(adjudication_id="A-001")
        a2 = adj(adjudication_id="A-002", supersedes="A-001")  # A-001 を差し替え → active 1 件
        self.assertEqual(v.validate_adjudications(_adjs(a1, a2)), [])

    def test_supersede_self_and_unknown(self):
        self.assertTrue(any("self-reference" in m for _, _, ms in
                            v.validate_adjudications(_adjs(adj(supersedes="A-001"))) for m in ms))
        self.assertTrue(any("unknown id" in m for _, _, ms in
                            v.validate_adjudications(_adjs(adj(supersedes="A-999"))) for m in ms))

    def test_supersede_cycle_detected(self):
        a1 = adj(adjudication_id="A-001", supersedes="A-002",
                 scope={"scope_kind": "screen_id", "scope_value": "S.a"})
        a2 = adj(adjudication_id="A-002", supersedes="A-001",
                 scope={"scope_kind": "screen_id", "scope_value": "S.b"})
        e = v.validate_adjudications(_adjs(a1, a2))
        self.assertTrue(any("cycle" in m for _, _, ms in e for m in ms))


class AdjudicationMatchTest(unittest.TestCase):
    def test_known_accepted(self):
        ann, _ = _annotate_one(find(), adj())
        self.assertEqual(ann["adjudication_status"], "known_accepted")
        self.assertEqual(ann["adjudication_verdict"], "false_positive")
        self.assertFalse(ann["must_remain_actionable"])  # medium

    def test_token_normalization_hyphen_and_phrase(self):
        # x-inertia-location / read-only / no feedback / covers main が split で壊れず hit する
        a = adj(symptom={"required_tokens": ["x-inertia-location", "read-only"],
                         "known_tokens": ["no feedback", "covers main"]})
        f = find(symptom_tokens=["x-inertia-location", "read-only", "no feedback", "covers main"])
        self.assertTrue(v.required_hits(a, f))
        self.assertFalse(v.has_new_signal(a, f))

    def test_condition_mismatch_keeps_actionable(self):
        ann, _ = _annotate_one(find(observed_conditions={"viewport": "768px"}), adj())
        self.assertEqual(ann["adjudication_status"], "ambiguous")
        self.assertEqual(ann["adjudication_ambiguity_reason"], "condition_mismatch:viewport")
        self.assertTrue(ann["must_remain_actionable"])

    def test_condition_unverified(self):
        ann, _ = _annotate_one(find(observed_conditions={}), adj())
        self.assertEqual(ann["adjudication_ambiguity_reason"], "condition_unverified:viewport")

    def test_condition_unspecified_guard(self):
        # finding が auth_role を観測しているが adj は指定なし → 過広適用防止で ambiguous
        ann, _ = _annotate_one(find(observed_conditions={"viewport": "375px", "auth_role": "owner"}), adj())
        self.assertEqual(ann["adjudication_status"], "ambiguous")
        self.assertEqual(ann["adjudication_ambiguity_reason"], "condition_unspecified:auth_role")

    def test_required_token_missing(self):
        ann, _ = _annotate_one(find(symptom_tokens=["sidebar"]), adj())  # "drawer" 欠落
        self.assertEqual(ann["adjudication_ambiguity_reason"], "required_token_missing")

    def test_required_negation_in_body_not_hit(self):
        # 本文 fallback (symptom_tokens なし) で否定文 "without X" / "X 無し" は required hit にしない
        a = adj(species_key="broken_flow:billing:create:self",
                scope={"scope_kind": "path_glob", "scope_value": "/billing/checkout/*"},
                conditions={}, symptom={"required_tokens": ["x-inertia-location"], "known_tokens": ["409"]})
        f_en = find(species_key="broken_flow:billing:create:self", resource_type="billing",
                    operation="create", summary="checkout 409 without x-inertia-location header",
                    surface={"path": "/billing/checkout/standard"}, observed_conditions={})
        f_en.pop("symptom_tokens", None)
        self.assertFalse(v.required_hits(a, f_en))
        f_ja = find(species_key="broken_flow:billing:create:self", resource_type="billing",
                    operation="create", summary="checkout 409 で x-inertia-location 無し",
                    surface={"path": "/billing/checkout/standard"}, observed_conditions={})
        f_ja.pop("symptom_tokens", None)
        self.assertFalse(v.required_hits(a, f_ja))
        # 肯定文なら hit する
        f_ok = find(species_key="broken_flow:billing:create:self", resource_type="billing",
                    operation="create", summary="checkout 409 returns x-inertia-location to stripe",
                    surface={"path": "/billing/checkout/standard"}, observed_conditions={})
        f_ok.pop("symptom_tokens", None)
        self.assertTrue(v.required_hits(a, f_ok))

    def test_required_negation_distant_not_hit(self):
        # "does not include x-inertia-location" のように否定語が少し離れていても hit しない
        a = adj(species_key="broken_flow:billing:create:self",
                scope={"scope_kind": "path_glob", "scope_value": "/billing/checkout/*"},
                conditions={}, symptom={"required_tokens": ["x-inertia-location"], "known_tokens": ["409"]})
        f = find(species_key="broken_flow:billing:create:self", resource_type="billing",
                 operation="create", summary="response does not include x-inertia-location",
                 surface={"path": "/billing/checkout/standard"}, observed_conditions={})
        f.pop("symptom_tokens", None)
        self.assertFalse(v.required_hits(a, f))


class AdjudicationFailClosedTest(unittest.TestCase):
    def _run_annotate(self, findings, adj_lines):
        import tempfile, pathlib, contextlib
        with tempfile.TemporaryDirectory() as d:
            fp = pathlib.Path(d) / "f.jsonl"
            fp.write_text("\n".join(json.dumps(x) for x in findings), encoding="utf-8")
            ap = pathlib.Path(d) / "adj.jsonl"
            ap.write_text("\n".join(json.dumps(x) for x in adj_lines), encoding="utf-8")
            out, err = io.StringIO(), io.StringIO()
            with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                code = v.main([str(fp), "--adjudications", str(ap), "--annotate",
                               "--run-id", "20260624-101406", "--changed-globs-file", self._empty()])
            recs = [json.loads(l) for l in out.getvalue().splitlines() if l.startswith("{")]
            return code, recs

    def _empty(self):
        import tempfile
        f = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        f.write("[]")
        f.close()
        return f.name

    def test_cycle_registry_fails_closed_no_suppression(self):
        # global cycle error (lineno=0) でも fail-closed: 抑制ゼロ + exit 1
        a1 = adj(adjudication_id="A-001", supersedes="A-002",
                 scope={"scope_kind": "screen_id", "scope_value": "S.a"})
        a2 = adj(adjudication_id="A-002", supersedes="A-001",
                 scope={"scope_kind": "screen_id", "scope_value": "S.b"})
        code, recs = self._run_annotate([find()], [a1, a2])
        self.assertEqual(code, 1)                       # loud に失敗
        self.assertEqual(recs[0]["adjudication_status"], "none")  # 壊れた registry で抑制しない

    def test_valid_registry_annotates_and_passes(self):
        code, recs = self._run_annotate([find()], [adj()])
        self.assertEqual(code, 0)
        self.assertEqual(recs[0]["adjudication_status"], "known_accepted")

    def test_new_signal(self):
        ann, _ = _annotate_one(find(symptom_tokens=["sidebar", "drawer", "datacorruption"]), adj())
        self.assertEqual(ann["adjudication_ambiguity_reason"], "new_signal")

    def test_scope_miss_is_none(self):
        ann, _ = _annotate_one(find(surface={"screen_id": "Other.screen"}), adj())
        self.assertEqual(ann["adjudication_status"], "none")
        self.assertIsNone(ann["adjudication_id"])

    def test_invalidation_asset_changed(self):
        ann, _ = _annotate_one(find(), adj(), changed_map={"A-001"})
        self.assertEqual(ann["adjudication_ambiguity_reason"], "invalidated_asset")

    def test_unresolvable_when_no_git_info(self):
        # changed_map=None かつ repo_root=None → git 検査不能 → unresolvable
        out, _ = v.annotate_findings([find()], _adjs(adj()), run_id="20260624-101406",
                                     changed_map=None, repo_root=None)
        self.assertEqual(out[0]["adjudication_ambiguity_reason"], "unresolvable")

    def test_review_window_expired(self):
        ann, kpi = _annotate_one(find(), adj(review_after_days=1), run_id="20270101-000000")
        self.assertEqual(ann["adjudication_ambiguity_reason"], "review_window")
        self.assertTrue(ann["adjudication_expired"])
        self.assertEqual(kpi["stale"], 1)

    def test_bad_run_id_unresolvable(self):
        self.assertIsNone(v._run_date("not-a-run"))
        ann, _ = _annotate_one(find(), adj(), run_id="garbage")
        self.assertEqual(ann["adjudication_ambiguity_reason"], "unresolvable")

    def test_high_sev_held_even_when_accepted(self):
        ann, kpi = _annotate_one(find(severity="high"), adj())
        self.assertEqual(ann["adjudication_status"], "known_accepted")
        self.assertTrue(ann["must_remain_actionable"])
        self.assertEqual(ann["actionable_hold_reason"], "high_severity")
        self.assertEqual(kpi["accepted_high_sev_held"], 1)

    def test_drop_never_happens(self):
        findings = [find(finding_id="F-1"), find(finding_id="F-2", surface={"screen_id": "Other"})]
        out, _ = v.annotate_findings(findings, _adjs(adj()), run_id="20260624-101406", changed_map=set())
        self.assertEqual(len(out), 2)  # 抑制で消えない
        self.assertEqual(out[0]["triage_status"], "proposed")  # triage_status 不変

    def test_specific_ambiguous_beats_broad_accepted(self):
        # 広い accepted (path_glob, conditions 少) と 具体 ambiguous (screen_id, viewport mismatch) が
        # 同 finding に当たったら、より具体的な ambiguous が勝ち known_accepted にならない。
        broad = adj(adjudication_id="A-100",
                    scope={"scope_kind": "path_glob", "scope_value": "/dash/sidebar/*"},
                    conditions={})
        specific = adj(adjudication_id="A-200",
                       scope={"scope_kind": "screen_id", "scope_value": "Layout.sidebar"},
                       conditions={"viewport": "<=389px"})
        f = find(observed_conditions={"viewport": "768px"},  # specific は viewport mismatch=ambiguous
                 surface={"screen_id": "Layout.sidebar", "path": "/dash/sidebar/x"})
        out, _ = v.annotate_findings([f], _adjs(broad, specific), run_id="20260624-101406", changed_map=set())
        self.assertEqual(out[0]["adjudication_status"], "ambiguous")
        self.assertEqual(out[0]["adjudication_id"], "A-200")  # 具体的な方が選ばれる

    def test_rederive_errors_zero_on_normal(self):
        _, kpi = _annotate_one(find(), adj())
        self.assertEqual(kpi["rederive_errors"], 0)

    def test_num_no_false_negative_from_hyphen(self):
        # "wide-500px" のハイフンを負号と誤読しない (Adversarial: _num)
        self.assertEqual(v._num("wide-500px"), 500.0)
        self.assertFalse(v.viewport_satisfies("<=389px", "wide-500px"))  # 500>389 → 不一致
        # 別 viewport の real bug は known_accepted にならない
        ann, _ = _annotate_one(find(observed_conditions={"viewport": "wide-500px"}), adj())
        self.assertEqual(ann["adjudication_status"], "ambiguous")

    def test_review_window_boundary_inclusive(self):
        # 経過 N 日ちょうどで review_window 到来 (Adversarial: off-by-one)
        ann, _ = _annotate_one(find(), adj(review_after_days=180), run_id="20261220-080644")  # 180 日後
        self.assertEqual(ann["adjudication_ambiguity_reason"], "review_window")
        self.assertTrue(ann["adjudication_expired"])

    def test_stopword_only_known_tokens_does_not_cover_novel(self):
        # known_tokens が stopword だけだと novel 語を覆えず new_signal (Adversarial: over-suppression)
        a = adj(symptom={"required_tokens": ["sidebar", "drawer"],
                         "known_tokens": ["for", "with", "page"]})  # 全部 noise
        ann, _ = _annotate_one(find(symptom_tokens=["sidebar", "drawer", "datacorruption"]), a)
        self.assertEqual(ann["adjudication_ambiguity_reason"], "new_signal")


class AdjudicationBackwardCompatTest(unittest.TestCase):
    def test_no_adjudications_unchanged(self):
        import tempfile, pathlib, contextlib
        with tempfile.TemporaryDirectory() as d:
            p = pathlib.Path(d) / "f.jsonl"
            p.write_text(json.dumps(rec(finding_id="F-1")), encoding="utf-8")
            buf = io.StringIO()
            with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(io.StringIO()):
                code = v.main([str(p), "--json"])
            self.assertEqual(code, 0)
            self.assertNotIn("adjudications_total", buf.getvalue())

    def test_seed_registry_is_valid(self):
        # 同梱 seed (adjudications.jsonl) が validator を通る
        import os
        here = os.path.dirname(__file__)
        path = os.path.join(here, "adjudications.jsonl")
        if os.path.exists(path):
            self.assertEqual(v.validate_adjudications(v.load_jsonl(path)), [])




class SpeciesTokenHyphenTest(unittest.TestCase):
    """T937: species_key token の char class 統一 (is_token / adjudication regex が単一 SoT)。"""

    def test_is_token_accepts_hyphenated_and_underscore(self):
        self.assertTrue(v.is_token("admin-organization"))
        self.assertTrue(v.is_token("answer_signals"))
        self.assertTrue(v.is_token("update"))
        self.assertTrue(v.is_token("a"))

    def test_is_token_is_strict_superset_of_old_rule(self):
        # 旧 is_token ([a-z0-9_]+) が通した token は引き続き通る (superset invariant を固定)。
        for old_ok in ("update", "answer_signals", "_x", "x_", "___"):
            self.assertTrue(v.is_token(old_ok), old_ok)

    def test_is_token_rejects_bad_chars(self):
        # 大文字 / 空白 / 記号 / 空文字は superset でも引き続き弾く。
        for bad in ("Bad", "a b", "x!", "", "admin-org "):
            self.assertFalse(v.is_token(bad), bad)

    def test_hyphenated_resource_type_no_longer_flagged(self):
        # F-0-01 系 (admin-organization) が lower-token エラーにならない
        errs = v.validate_record(rec(failure_class="other", resource_type="admin-organization",
                                     operation="read", tenant_relation="n/a",
                                     species_key="other:admin-organization:read:n/a"))
        self.assertFalse(any("resource_type" in e for e in errs), errs)

    def test_is_token_and_adj_regex_share_single_sot(self):
        # 単一 SoT: adjudication 正規表現が is_token と同じ _SPECIES_TOKEN から導出される
        self.assertIn(v._SPECIES_TOKEN, v._ADJ_SPECIES_KEY_RE.pattern)
        self.assertIn(v._SPECIES_TOKEN, v._SPECIES_TOKEN_RE.pattern)

    def test_adjudication_species_key_accepts_hyphen(self):
        self.assertIsNotNone(v._ADJ_SPECIES_KEY_RE.match("other:admin-organization:read:n/a"))
        # 従来の underscore key も引き続き valid
        self.assertIsNotNone(v._ADJ_SPECIES_KEY_RE.match("claimed_success_no_change:organization:update:self"))
        # 大文字 (token 外文字) / 不正 tenant_relation は invalid
        self.assertIsNone(v._ADJ_SPECIES_KEY_RE.match("Other:x:y:self"))
        self.assertIsNone(v._ADJ_SPECIES_KEY_RE.match("other:x:y:bad_relation"))
        # segment 数不足 (tenant_relation 欠落) も invalid
        self.assertIsNone(v._ADJ_SPECIES_KEY_RE.match("other:x:y"))


class FlashAdjudicationBehaviourTest(unittest.TestCase):
    """flash 系 adjudication が意図どおり fire し、真退行 (novel token) は ambiguous に逃げる。

    旧 `NewFlashAdjudicationsTest` は同梱 seed の A-015..A-018 を直接読んでいたが、
    seed は spirux 由来 (実在しない資産を指す) のため削除された (README 運用ガード (d))。
    固定したい振る舞いはデータではなく機構なので、fixture をテスト内に持つ形へ移した。
    """

    def _adj(self):
        return adj(
            adjudication_id="A-015",
            species_key="claimed_success_no_change:organization:update:self",
            scope={"scope_kind": "screen_id", "scope_value": "Organizations/Settings.name-update"},
            conditions={},
            symptom={"required_tokens": ["flash", "成功", "トースト"],
                     "known_tokens": ["update", "保存", "フィードバック", "組織",
                                      "organization", "toast", "success"]},
            verdict="false_positive",
            rationale_ref="AGENTS.md#mutation-success-flash-implemented ; "
                          "tests/Architecture/MutationRedirectFlashTest.php",
            source_finding_ids=["F-3-01"],
            watch_globs=["app/Http/Controllers/OrganizationController.php",
                         "resources/js/lib/stores/toast.ts"],
        )

    def test_entry_is_valid(self):
        self.assertEqual(v.validate_adjudications(_adjs(self._adj())), [])

    def _finding(self, tokens):
        return {
            "finding_id": "F-x", "run_id": "20260701-020000", "story_id": "S4",
            "capability_tag": "settings-write-feedback", "principal": "owner",
            "tenant_relation": "self", "failure_class": "claimed_success_no_change",
            "resource_type": "organization", "operation": "update",
            "species_key": "claimed_success_no_change:organization:update:self",
            "oracle_attribution": "H7-feedback",
            "evidence_ref": "x.png", "triage_status": "proposed",
            "surface": {"screen_id": "Organizations/Settings.name-update"},
            "symptom_tokens": tokens,
        }

    def test_benign_flash_finding_is_known_accepted(self):
        f = self._finding(["flash", "成功", "トースト", "update"])
        res = v.match_finding(f, self._adj(), run_id="20260701-020000",
                              changed=False, unresolvable=False)
        self.assertIsNotNone(res)
        self.assertEqual(res["adjudication_status"], "known_accepted", res)

    def test_dataloss_novel_token_escapes_to_ambiguous(self):
        # 「保存が反映されない」= 真退行の novel token → known_accepted せず ambiguous
        f = self._finding(["flash", "成功", "トースト", "反映されない"])
        res = v.match_finding(f, self._adj(), run_id="20260701-020000",
                              changed=False, unresolvable=False)
        self.assertIsNotNone(res)
        self.assertEqual(res["adjudication_status"], "ambiguous", res)
        self.assertEqual(res["adjudication_ambiguity_reason"], "new_signal", res)


class GovernedConditionKeysTest(unittest.TestCase):
    """mode / env は governed COND_KEYS (generic な precondition に潰さない)。

    spirux HARNESS-01: 旧 COND_KEYS に mode/env が無く schema drift →
    `bad condition key: 'mode'` で fail-closed → 抑制が全面停止した。
    """

    def test_mode_and_env_are_governed_keys(self):
        self.assertIn("mode", v.COND_KEYS)
        self.assertIn("env", v.COND_KEYS)

    def test_adjudication_with_mode_condition_is_valid(self):
        self.assertEqual(v.validate_adjudications(_adjs(adj(conditions={"mode": "fake"}))), [])

    def test_adjudication_with_env_condition_is_valid(self):
        self.assertEqual(v.validate_adjudications(_adjs(adj(conditions={"env": "bughunt"}))), [])

    def test_adjudication_with_mode_and_env_is_valid(self):
        self.assertEqual(
            v.validate_adjudications(_adjs(adj(conditions={"mode": "fake", "env": "bughunt"}))), [])

    def test_unknown_condition_key_still_rejected(self):
        errs = v.validate_adjudications(_adjs(adj(conditions={"bogus": "x"})))
        self.assertTrue(any("condition key" in m for _, _, ms in errs for m in ms))

    def test_mode_condition_gates_matching(self):
        # fake 限定の偽陽性が real モードの finding に誤適用されないこと (load-bearing な理由)
        conds = {"mode": "fake"}
        hit = find(observed_conditions={"mode": "fake"})
        miss = find(observed_conditions={"mode": "real"})
        unobserved = find(observed_conditions={})
        self.assertIsNone(v.conditions_status(conds, hit))
        self.assertEqual(v.conditions_status(conds, miss), "condition_mismatch:mode")
        self.assertEqual(v.conditions_status(conds, unobserved), "condition_unverified:mode")

    def test_unspecified_mode_prevents_overbroad_application(self):
        # finding が mode を観測しているのに adj が指定していない → 過広適用防止 (安全側)
        self.assertEqual(v.conditions_status({}, find(observed_conditions={"mode": "real"})),
                         "condition_unspecified:mode")


class EmptySeedRegistryTest(unittest.TestCase):
    """**空の** registry でも valid / exit 0 であること (fail-closed で全面停止しない)。

    かつては同梱 seed (`adjudications.jsonl`) が空である前提でそのファイルを使っていたが、
    AI-CUE の実 run 由来の裁定 (A-001) が登録されて前提が崩れた。守りたい不変条件は
    「registry が空でも validator が落ちない」ことなので、**空ファイルを都度作って**検証する。
    同梱 seed 自体の妥当性は `AdjudicationBackwardCompatTest::test_seed_registry_is_valid` が見る。
    """

    def test_empty_registry_reports_zero_and_exits_zero(self):
        import contextlib
        import os
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            empty = os.path.join(tmp, "adjudications.jsonl")
            with open(empty, "w", encoding="utf-8"):
                pass
            buf = io.StringIO()
            with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(io.StringIO()):
                code = v.main([self._example_findings(), "--adjudications", empty, "--json"])
        self.assertEqual(code, 0)
        summary = json.loads(buf.getvalue())
        self.assertEqual(summary["adjudications_total"], 0)
        self.assertEqual(summary["adjudications_invalid"], 0)

    def _example_findings(self):
        import os
        return os.path.join(os.path.dirname(__file__), "example.findings.jsonl")


class StdinTwoPassTest(unittest.TestCase):
    """stdin `-` は 1 度しか読めない。--annotate の 2-pass で findings が落ちない回帰テスト。

    修正前は 2 回目の read が空になり、annotate 出力が静かに 0 件になっていた。
    """

    def _run_stdin(self, findings, adj_lines):
        import contextlib, pathlib, tempfile
        payload = "\n".join(json.dumps(x, ensure_ascii=False) for x in findings) + "\n"
        with tempfile.TemporaryDirectory() as d:
            ap = pathlib.Path(d) / "adj.jsonl"
            ap.write_text("\n".join(json.dumps(x, ensure_ascii=False) for x in adj_lines),
                          encoding="utf-8")
            # 一時ファイルは TemporaryDirectory 配下に置き、テスト終了時に確実に回収する
            # (delete=False の NamedTemporaryFile だと実行のたび /tmp に残留する。impl-review R1 Suggestion)
            gp = pathlib.Path(d) / "changed-globs.json"
            gp.write_text("[]", encoding="utf-8")
            out, err = io.StringIO(), io.StringIO()
            import sys as _sys
            old_stdin = _sys.stdin
            _sys.stdin = io.StringIO(payload)
            try:
                with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                    code = v.main(["-", "--adjudications", str(ap), "--annotate",
                                   "--run-id", "20260701-020000",
                                   "--changed-globs-file", str(gp)])
            finally:
                _sys.stdin = old_stdin
            recs = [json.loads(l) for l in out.getvalue().splitlines() if l.startswith("{")]
            return code, recs

    def test_annotate_from_stdin_does_not_drop_findings(self):
        findings = [find(finding_id="F-1"), find(finding_id="F-2")]
        code, recs = self._run_stdin(findings, [adj()])
        self.assertEqual(code, 0)
        self.assertEqual(len(recs), 2, recs)  # 修正前は 0 件になっていた
        self.assertEqual([r["finding_id"] for r in recs], ["F-1", "F-2"])
        self.assertTrue(all("adjudication_status" in r for r in recs), recs)

    def test_analyze_from_stdin_counts_findings(self):
        # analyze 側 (1-pass 目) も stdin バッファ経由で総数を数えられること
        import contextlib
        payload = json.dumps(rec(finding_id="F-1")) + "\n" + json.dumps(rec(finding_id="F-2")) + "\n"
        import sys as _sys
        old_stdin = _sys.stdin
        _sys.stdin = io.StringIO(payload)
        buf = io.StringIO()
        try:
            with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(io.StringIO()):
                code = v.main(["-", "--json"])
        finally:
            _sys.stdin = old_stdin
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(buf.getvalue())["total"], 2)

    def test_load_jsonl_accepts_text(self):
        text = json.dumps({"a": 1}) + "\n# comment\n\n" + json.dumps({"b": 2})
        got = [o for _, o, _ in v.load_jsonl("/nonexistent/path.jsonl", text=text)]
        self.assertEqual(got, [{"a": 1}, {"b": 2}])


if __name__ == "__main__":
    unittest.main()
