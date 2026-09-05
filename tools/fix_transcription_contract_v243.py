#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
path = root / 'tests/chat-transcription-canvas-v243.mjs'
text = path.read_text(encoding='utf-8')
old = "assert.ok(canvas.includes('data-transcription-audio controls preload=\\\\\"metadata\\\\\"'),'canvas must render the retained recording as playable audio');"
new = "assert.ok(canvas.includes('data-transcription-audio controls preload=\"metadata\"'),'canvas must render the retained recording as playable audio');"
if old not in text:
    # Be tolerant of the exact escaping written by the generator while remaining
    # strict about the HTML contract we actually care about.
    import re
    text, count = re.subn(
        r"assert\.ok\(canvas\.includes\('data-transcription-audio controls preload=.*?'\),'canvas must render the retained recording as playable audio'\);",
        new,
        text,
        count=1,
    )
    if count != 1:
        raise SystemExit('Playable-audio assertion was not found.')
else:
    text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
Path(__file__).unlink(missing_ok=True)
print('TRANSCRIPTION_CONTRACT_FIX=PASS')
