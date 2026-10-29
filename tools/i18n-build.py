# -*- coding: utf-8 -*-
"""Rebuild languages/tqk-wp-seo.pot, tqk-wp-seo-vi.po and tqk-wp-seo-vi.mo from the PHP sources.

Translations come from, in increasing priority:
  1. msgstr already in languages/tqk-wp-seo-vi.po,
  2. the TR dict in tools/sync-i18n.py,
  3. tools/i18n-vi-extra.json.

Unlike sync-i18n.py it escapes quotes/backslashes for .po and compiles the
binary .mo itself (no gettext tools needed). Run: python tools/i18n-build.py
"""
import json
import pathlib
import re
import struct

ROOT = pathlib.Path(__file__).resolve().parent.parent
LANG = ROOT / 'languages'


def php_unescape(s):
    return s.replace("\\'", "'").replace('\\\\', '\\')


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def po_unescape(s):
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t'}.get(m.group(1), m.group(1)), s)


def used_strings():
    pat = re.compile(r"(?:\b__|\besc_html__|\besc_attr__|\b_e|\besc_html_e|\besc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'")
    used = set()
    # The Pro add-on (sibling folder) uses this plugin's text domain.
    pro = ROOT.parent / 'tqk-wpseo-pro'
    files = sorted((ROOT / 'src').rglob('*.php')) + [ROOT / 'tqk-wp-seo.php']
    if pro.is_dir():
        files += sorted((pro / 'src').rglob('*.php')) + [pro / 'tqk-wpseo-pro.php']
    for f in files:
        for s in pat.findall(f.read_text(encoding='utf-8')):
            s = php_unescape(s)
            if s:
                used.add(s)
    return sorted(used)


def existing_po(path):
    out, header = {}, ''
    if not path.exists():
        return out, header
    txt = path.read_text(encoding='utf-8')
    for mid, mstr in re.findall(r'msgid "((?:[^"\\]|\\.)*)"\s*\nmsgstr "((?:[^"\\]|\\.)*)"', txt):
        if mid == '':
            header = po_unescape(mstr)
        elif mstr:
            out[po_unescape(mid)] = po_unescape(mstr)
    return out, header


def sync_tr():
    src = (ROOT / 'tools' / 'sync-i18n.py').read_text(encoding='utf-8')
    ns = {}
    exec(src.split('\npat = ')[0], {'re': re, 'pathlib': pathlib}, ns)
    return ns.get('TR', {})


def write_mo(path, pairs, header):
    entries = sorted([('', header)] + list(pairs.items()), key=lambda kv: kv[0].encode('utf-8'))
    ids = [k.encode('utf-8') for k, _ in entries]
    strs = [v.encode('utf-8') for _, v in entries]
    n = len(entries)
    o_ids, o_strs = 28, 28 + n * 8
    data_start = 28 + n * 16
    id_tab, str_tab, blob = [], [], b''
    for b in ids:
        id_tab.append((len(b), data_start + len(blob)))
        blob += b + b'\0'
    for b in strs:
        str_tab.append((len(b), data_start + len(blob)))
        blob += b + b'\0'
    # No hash table (size 0), but its offset must still point right after the
    # translations table: WordPress' MO reader derives that table's length
    # from hash_addr - translations_addr, like msgfmt output.
    out = struct.pack('<7I', 0x950412de, 0, n, o_ids, o_strs, 0, data_start)
    for ln, off in id_tab + str_tab:
        out += struct.pack('<2I', ln, off)
    path.write_bytes(out + blob)


def main():
    used = used_strings()
    po_tr, header = existing_po(LANG / 'tqk-wp-seo-vi.po')
    tr = dict(po_tr)
    tr.update(sync_tr())
    # Every tools/i18n-vi-*.json dictionary, in name order.
    for extra in sorted((LANG.parent / 'tools').glob('i18n-vi-*.json')):
        tr.update(json.loads(extra.read_text(encoding='utf-8')))
    header = header or 'Content-Type: text/plain; charset=UTF-8\nLanguage: vi\nPlural-Forms: nplurals=1; plural=0;\n'
    if 'Plural-Forms' not in header:
        header += 'Plural-Forms: nplurals=1; plural=0;\n'

    pot = ['msgid ""', 'msgstr ""', '"Content-Type: text/plain; charset=UTF-8\\n"', '']
    po = ['msgid ""', 'msgstr ""'] + ['"' + po_escape(l) + '\\n"' for l in header.strip('\n').split('\n')] + ['']
    pairs, missing = {}, []
    for s in used:
        pot += ['msgid "%s"' % po_escape(s), 'msgstr ""', '']
        vi = tr.get(s, '')
        po += ['msgid "%s"' % po_escape(s), 'msgstr "%s"' % po_escape(vi), '']
        if vi:
            pairs[s] = vi
        else:
            missing.append(s)
    (LANG / 'tqk-wp-seo.pot').write_text('\n'.join(pot), encoding='utf-8')
    (LANG / 'tqk-wp-seo-vi.po').write_text('\n'.join(po), encoding='utf-8')
    write_mo(LANG / 'tqk-wp-seo-vi.mo', pairs, header)
    print('strings:', len(used), 'translated:', len(pairs), 'missing:', len(missing))
    for m in missing:
        print('  -', m)


if __name__ == '__main__':
    main()
