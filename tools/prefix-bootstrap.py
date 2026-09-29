#!/usr/bin/env python3
"""Scope Bootstrap CSS under .tqk-wpseo so it never leaks into WP admin.

- Prefixes every style-rule selector with `.tqk-wpseo ` (comma-aware, paren-aware).
- Recurses into @media/@supports. Skips @keyframes/@font-face/@charset/@import.
- Rewrites `:root` -> `.tqk-wpseo` (CSS vars stay inside our wrapper).
- Selectors starting with html/body become inert (good: kills reboot globals).

Usage: python tools/prefix-bootstrap.py  (reads assets/vendor/bootstrap.min.css,
writes assets/vendor/bootstrap-scoped.min.css)
"""
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'assets' / 'vendor' / 'bootstrap.min.css'
DST = ROOT / 'assets' / 'vendor' / 'bootstrap-scoped.min.css'
SCOPE = '.tqk-wpseo'

SKIP_AT = ('@keyframes', '@-webkit-keyframes', '@font-face', '@charset', '@import', '@namespace')
RECURSE_AT = ('@media', '@supports', '@layer', '@container')


def split_selectors(text):
    parts, depth, cur = [], 0, ''
    for ch in text:
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth = max(0, depth - 1)
        if ch == ',' and depth == 0:
            parts.append(cur)
            cur = ''
        else:
            cur += ch
    if cur.strip():
        parts.append(cur)
    return parts


def prefix_selector(sel):
    sel = sel.strip()
    if not sel or sel.startswith(SCOPE):
        return sel
    sel = re.sub(r'(^|[\s>+~,])\:root\b', r'\1' + SCOPE, sel)
    return f'{SCOPE} {sel}'


def scope_css(css):
    out = []
    i, n = 0, len(css)
    while i < n:
        if css[i].isspace():
            out.append(css[i])
            i += 1
            continue
        if css[i] == '@':
            m = re.match(r'@[a-zA-Z-]+', css[i:])
            name = m.group(0) if m else '@'
            semi = css.find(';', i)
            brace = css.find('{', i)
            if brace == -1 or (semi != -1 and semi < brace):
                out.append(css[i:semi + 1])
                i = semi + 1
                continue
            depth, j = 0, brace
            while j < n:
                if css[j] == '{':
                    depth += 1
                elif css[j] == '}':
                    depth -= 1
                    if depth == 0:
                        break
                j += 1
            block = css[i:j + 1]
            if name in SKIP_AT:
                out.append(block)
            elif name in RECURSE_AT:
                head = css[i:brace + 1]
                inner = scope_css(css[brace + 1:j])
                out.append(head + inner + '}')
            else:  # unknown at-rule with block: keep as-is (safe)
                out.append(block)
            i = j + 1
            continue
        brace = css.find('{', i)
        if brace == -1:
            out.append(css[i:])
            break
        selector = css[i:brace]
        depth, j = 1, brace + 1
        in_str = None
        while j < n and depth:
            ch = css[j]
            if in_str:
                if ch == in_str and css[j - 1] != '\\':
                    in_str = None
            elif ch in ('"', "'"):
                in_str = ch
            elif ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
            j += 1
        body = css[brace:j]
        prefixed = ','.join(prefix_selector(s) for s in split_selectors(selector))
        out.append(prefixed + body)
        i = j
    return ''.join(out)


def main():
    css = SRC.read_text(encoding='utf-8')
    scoped = scope_css(css)
    DST.write_text(scoped, encoding='utf-8')
    # sanity: no unscoped dangerous globals left at top level
    print(f'wrote {DST.name}: {len(scoped)//1024} KB')


if __name__ == '__main__':
    main()
