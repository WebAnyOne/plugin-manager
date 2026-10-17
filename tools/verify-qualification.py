#!/usr/bin/env python3
"""Fail-closed publication gate for a manually reviewed qualification report."""
from pathlib import Path
import argparse
import hashlib
import json
import re
import sys

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('report')
args = parser.parse_args()
report_file = Path(args.report)
report = json.loads(report_file.read_text())
plan = json.loads((Path(__file__).parents[1] / 'Tests/qualification-plan.json').read_text())
errors = []
for key in ('plugin_version', 'mautic', 'php'):
    if not re.fullmatch(r'\d+\.\d+\.\d+', report.get(key, '')):
        errors.append('exact_version_required:' + key)
if not re.fullmatch(r'[a-f0-9]{40}', report.get('source_commit', '')):
    errors.append('immutable_source_commit_required')
for package in ('doctrine/dbal', 'doctrine/orm', 'symfony/framework-bundle'):
    if not report.get('dependencies', {}).get(package):
        errors.append('dependency_version_required:' + package)
for key in plan['required_checks']:
    check = report.get('checks', {}).get(key, {})
    evidence = check.get('evidence', '')
    expected = check.get('sha256', '')
    evidence_file = (report_file.parent / evidence).resolve()
    if check.get('status') != 'passed':
        errors.append('not_passed:' + key)
    if not evidence or not evidence_file.is_file() or not re.fullmatch(r'[a-f0-9]{64}', expected):
        errors.append('evidence_required:' + key)
    elif hashlib.sha256(evidence_file.read_bytes()).hexdigest() != expected:
        errors.append('evidence_digest:' + key)
if report.get('status') != 'passed':
    errors.append('report_not_passed')
print(json.dumps(dict(status='rejected' if errors else 'passed', errors=errors), indent=2))
sys.exit(1 if errors else 0)
