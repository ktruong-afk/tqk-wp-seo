# -*- coding: utf-8 -*-
"""Build the installable zip: dist/tqk-wpseo-<version>.zip

Ships only what runs on a site (plugin file, uninstall, src, assets,
templates, languages, readme). Tests, tools, docs, planning notes and the
dist folder itself never go to customers.

Refuses to build when the version differs between the plugin header, the
TQK_WPSEO_VERSION constant and readme.txt's Stable tag, and rebuilds the
translations first so the .mo always matches the sources.

Also builds the Pro add-on (sibling folder tqk-wpseo-pro) into
dist/tqk-wpseo-pro-<version>.zip when it is present. The two zips are
sold/shipped separately: the free zip never contains Pro code.

Run: python tools/build-release.py
"""
import pathlib
import re
import subprocess
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
INCLUDE = ['tqk-wpseo.php', 'uninstall.php', 'readme.txt', 'src', 'assets', 'templates', 'languages']
SKIP_NAMES = {'.DS_Store', 'Thumbs.db'}


def versions():
    main = (ROOT / 'tqk-wpseo.php').read_text(encoding='utf-8')
    readme = (ROOT / 'readme.txt').read_text(encoding='utf-8')
    header = re.search(r'^\s*\*\s*Version:\s*([\w.\-]+)', main, re.M)
    const = re.search(r"define\('TQK_WPSEO_VERSION',\s*'([^']+)'\)", main)
    stable = re.search(r'^Stable tag:\s*([\w.\-]+)', readme, re.M)
    return {k: (m.group(1) if m else None) for k, m in [('header', header), ('constant', const), ('readme', stable)]}


def main():
    v = versions()
    if len(set(v.values())) != 1 or None in v.values():
        sys.exit('Version mismatch, fix before release: %s' % v)
    version = v['header']

    subprocess.run([sys.executable, str(ROOT / 'tools' / 'i18n-build.py')], check=True)

    dist = ROOT / 'dist'
    dist.mkdir(exist_ok=True)
    out = dist / ('tqk-wpseo-%s.zip' % version)
    count = 0
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for item in INCLUDE:
            p = ROOT / item
            files = [p] if p.is_file() else sorted(f for f in p.rglob('*') if f.is_file())
            for f in files:
                if f.name in SKIP_NAMES:
                    continue
                z.write(f, 'tqk-wpseo/' + f.relative_to(ROOT).as_posix())
                count += 1
    print('built %s (%d files, %.1f KB)' % (out.relative_to(ROOT), count, out.stat().st_size / 1024))
    build_pro(dist)


def build_pro(dist):
    pro = ROOT.parent / 'tqk-wpseo-pro'
    if not (pro / 'tqk-wpseo-pro.php').is_file():
        return
    m = re.search(r"define\('TQK_WPSEO_PRO_VERSION',\s*'([^']+)'\)", (pro / 'tqk-wpseo-pro.php').read_text(encoding='utf-8'))
    out = dist / ('tqk-wpseo-pro-%s.zip' % (m.group(1) if m else 'dev'))
    count = 0
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for item in ['tqk-wpseo-pro.php', 'uninstall.php', 'src']:
            p = pro / item
            files = [p] if p.is_file() else sorted(f for f in p.rglob('*') if f.is_file())
            for f in files:
                if f.name in SKIP_NAMES:
                    continue
                z.write(f, 'tqk-wpseo-pro/' + f.relative_to(pro).as_posix())
                count += 1
    print('built %s (%d files, %.1f KB)' % (out.relative_to(ROOT), count, out.stat().st_size / 1024))


if __name__ == '__main__':
    main()
