"""
Parse every manifest so a YAML typo is caught here rather than by the cluster.

kubectl gives a poor error for a malformed file - it reports the resource it
was trying to apply, not the line that broke - and a stray tab or a missing
indent in a probe block fails the whole apply with nothing deployed.
"""

import glob
import sys

import yaml

FAILED = False

for path in sorted(glob.glob("k8s/*.yaml")):
    try:
        docs = [d for d in yaml.safe_load_all(open(path, encoding="utf-8")) if d]
        kinds = ", ".join(f"{d.get('kind')}/{d.get('metadata', {}).get('name')}" for d in docs)
        print(f"  OK    {path}  ->  {kinds}")
    except Exception as exc:
        FAILED = True
        print(f"  FAIL  {path}: {exc}")

sys.exit(1 if FAILED else 0)