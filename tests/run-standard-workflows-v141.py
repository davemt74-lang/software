#!/usr/bin/env python3
from __future__ import annotations

import os
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = [
    '.github/workflows/runtime-root-cause.yml',
    '.github/workflows/v100-validation.yml',
    '.github/workflows/v101-validation.yml',
    '.github/workflows/v104-validation.yml',
    '.github/workflows/v105-validation.yml',
    '.github/workflows/v106-team-chat-rail.yml',
    '.github/workflows/v107-runtime-recovery.yml',
    '.github/workflows/v108-direct-runtime.yml',
    '.github/workflows/v131-video-truth.yml',
    '.github/workflows/v132-voice-interrupt-resume.yml',
    '.github/workflows/v133-voice-recognizer-hard-reset.yml',
    '.github/workflows/v134-voice-debug-single-controller.yml',
    '.github/workflows/v136-persisted-listen.yml',
    '.github/workflows/v137-voice-input.yml',
    '.github/workflows/v139-chat-voice-direct.yml',
    '.github/workflows/voice-recognition-v135.yml',
]


def run_blocks(path: Path) -> list[str]:
    lines = path.read_text(encoding='utf-8').splitlines()
    blocks: list[str] = []
    i = 0
    while i < len(lines):
        match = re.match(r'^(\s*)run:\s*(.*)$', lines[i])
        if not match:
            i += 1
            continue
        indent = len(match.group(1))
        tail = match.group(2).strip()
        if tail and tail not in {'|', '>', '|-', '>-'}:
            blocks.append(tail)
            i += 1
            continue
        i += 1
        raw: list[str] = []
        while i < len(lines):
            line = lines[i]
            if line.strip() == '':
                raw.append('')
                i += 1
                continue
            leading = len(line) - len(line.lstrip(' '))
            if leading <= indent:
                break
            raw.append(line)
            i += 1
        nonempty = [len(line) - len(line.lstrip(' ')) for line in raw if line.strip()]
        cut = min(nonempty) if nonempty else indent + 2
        blocks.append('\n'.join(line[cut:] if line.strip() else '' for line in raw))
    return blocks


def main() -> int:
    env = os.environ.copy()
    env.setdefault('CI', 'true')
    total = 0
    for rel in WORKFLOWS:
        path = ROOT / rel
        if not path.is_file():
            raise SystemExit(f'Missing workflow: {rel}')
        blocks = run_blocks(path)
        print(f'\n=== {rel} · {len(blocks)} command blocks ===', flush=True)
        for index, command in enumerate(blocks, 1):
            if '${{' in command:
                raise SystemExit(f'Unsupported GitHub expression in {rel} block {index}: {command[:120]}')
            total += 1
            print(f'--- block {index}/{len(blocks)} ---', flush=True)
            result = subprocess.run(['bash', '-lc', command], cwd=ROOT, env=env)
            if result.returncode != 0:
                raise SystemExit(f'FAILED {rel} block {index} with exit {result.returncode}')
    print(f'\nSTANDARD_WORKFLOW_BLOCKS_V141=PASS ({total} command blocks across {len(WORKFLOWS)} workflows)')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
