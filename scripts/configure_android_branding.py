#!/usr/bin/env python3
import re
import sys
from pathlib import Path

if len(sys.argv) != 3:
    raise SystemExit('usage: configure_android_branding.py <AndroidManifest.xml> <label>')

path = Path(sys.argv[1])
label = sys.argv[2].strip()
if not label:
    raise SystemExit('label must not be empty')

text = path.read_text(encoding='utf-8')
pattern = r'(<application\b[^>]*\bandroid:label=")[^"]*(")'
updated, count = re.subn(pattern, lambda m: m.group(1) + label + m.group(2), text, count=1, flags=re.S)
if count != 1:
    raise SystemExit(f'android:label not found in {path}')
path.write_text(updated, encoding='utf-8')
print(f'Android launcher label set to: {label}')
