#!/usr/bin/env python3
"""Publish guard for the Labelixa printer agent and the public example repos.

Scans every source file that ships (the agent binary is built from these
files and the source directory is mirrored publicly) and fails (exit code
1) when any of them contains:

  * an internal ticket id (three uppercase letters, a dash and three
    digits, optionally prefixed with the project code),
  * Turkish characters (the published tree is English-only),
  * common Turkish words written in ASCII (e.g. "sunucu", "yanit"),
  * an unreleased/audit marker.

Scanned: every text file under the root except directories named .git,
node_modules, __pycache__, dist, test, testdata, scripts, bin or obj, Go
test files (*_test.go), the Business Central test app (core-test) and
binaries. `contract.go` (and its copies, e.g. `contract.ts`) is exempt from the WORD rule only:
it holds the wire vocabulary (JSON keys, legacy command names) that
existing installations depend on and that cannot be renamed; the other
rules still apply to it.
Business Central UI translation files (`Translations/<App>.de-DE.xlf`,
`Translations/<App>.tr-TR.xlf`) are exempt from the language rules
(characters, words, markers); the ticket-id rule still applies to them.

Usage: python scripts/check_publish.py [root]
The release workflow runs it before building; the repository test suite
runs it on every pull request and also points it at the example repos.
This file defines the patterns and is therefore not scanned itself.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

TICKET = re.compile(r"\b(LBL-)?[A-Z]{3}-\d{3}\b")
TURKISH = re.compile("[çğıöşüÇĞİÖŞÜ]")
MARKERS = ("yayımlanmadı", "denetim", "ölçüm")
# ASCII-spelled Turkish words: the character rule cannot see them, and most
# leaks are written that way. Keep this list identical across the three
# packages (tests/test_paket_dili.py locks it). API response field names that
# must stay as-is (e.g. "kod", "komutlar", "ozet") are deliberately absent.
WORDS = ('sunucu', 'yanit', 'hata', 'hatasi', 'kota', 'dosya', 'istek', 'olcum', 'denetim', 'surum', 'yayin', 'uretilmedi', 'uretildi', 'kullanin', 'gecersiz', 'bilinmeyen', 'baslik', 'basliklar', 'govde', 'taban', 'arac', 'araclar', 'sozlesme', 'cikti', 'girdi', 'deger', 'uyari', 'kayit', 'gecmis', 'sonuc', 'ornek', 'yazici', 'etiket', 'dogrula', 'donustur', 'bekle', 'olcu', 'yol', 'gerekce', 'yorum')
WORD = re.compile(r"\b(?:" + "|".join(WORDS) + r")\b", re.IGNORECASE)

# `bin` and `obj` are the .NET build output directories. They hold
# generated files whose contents depend on the machine that built them
# (absolute paths, restore caches), so scanning them measures the build
# host rather than the package. NOTE the asymmetry: the npm packages'
# `bin/` holds a PUBLISHED file (bin/labelixa.mjs) and is scanned by the
# node guard, which keeps its own list. Pointing THIS scanner at an npm
# package would therefore skip that file.
# `.gradle`, `.intellijPlatform`, `.kotlin` and `build` are Gradle's own
# caches and outputs for the JetBrains plugin: machine-specific, never
# published.
# `core-test` is the Business Central test app (bc/): test code like
# `test/` and `*_test.go`, never published.
SKIP_DIRS = {".git", "node_modules", "__pycache__", "dist", "test", "testdata",
             "scripts", "bin", "obj", "target", "build", ".gradle",
             ".intellijPlatform", ".kotlin", "core-test", ".alpackages"}
BINARY = {".png", ".jpg", ".jpeg", ".gif", ".ico", ".pdf", ".zip", ".gz",
          ".exe", ".woff", ".woff2", ".ttf"}
# Files holding the frozen wire vocabulary: exempt from the WORD rule only.
# `Contract.kt` is the JetBrains plugin's copy of the same vocabulary;
# `LBXWire.Codeunit.al` is the Business Central app's; `contract.ts` is the
# n8n node's (API answer field names it renames to English). The SDKs and
# the Pipedream components keep the same vocabulary (at least the error
# answer's field name) in `contract.go` / `Contract.php` / `Contract.cs` /
# `Contract.java` / `contract.mjs`.
WORD_EXEMPT = {"contract.go", "Contract.kt", "LBXWire.Codeunit.al", "contract.ts",
               "Contract.php", "Contract.cs", "Contract.java", "contract.mjs"}

# Localized UI translation files of the Business Central apps. A translation
# file is, by definition, written in its target language (German umlauts,
# Turkish characters and words), so the language rules cannot apply to it.
# The exemption is deliberately narrow: only these locale suffixes and only
# files directly inside a `Translations` directory. The generated English
# source (`*.g.xlf`), the AL code and any other file stay fully scanned, and
# the ticket-id rule still applies to the translation files themselves.
TRANSLATION_SUFFIXES = (".de-DE.xlf", ".tr-TR.xlf")


def _is_translation(path: Path) -> bool:
    return path.parent.name == "Translations" and path.name.endswith(TRANSLATION_SUFFIXES)


def _files(root: Path) -> list[Path]:
    out: list[Path] = []
    for path in sorted(root.rglob("*")):
        if not path.is_file():
            continue
        rel = path.relative_to(root)
        if SKIP_DIRS & set(rel.parts[:-1]):
            continue
        if path.suffix in BINARY or path.name.endswith("_test.go"):
            continue
        # A compiled binary next to the sources (e.g. `go build` output) is
        # not text; skip anything with a NUL byte.
        try:
            head = path.read_bytes()[:512]
        except OSError:
            continue
        if b"\0" in head:
            continue
        out.append(path)
    return out


def scan(root: Path) -> list[str]:
    problems: list[str] = []
    for path in _files(root):
        text = path.read_text(encoding="utf-8", errors="replace")
        rel = path.relative_to(root)
        translation = _is_translation(path)
        for n, line in enumerate(text.splitlines(), 1):
            where = f"{rel}:{n}"
            m = TICKET.search(line)
            if m:
                problems.append(f"{where}: ticket id {m.group(0)!r}")
            if translation:
                continue
            m = TURKISH.search(line)
            if m:
                problems.append(f"{where}: non-English character {m.group(0)!r}")
            for marker in MARKERS:
                if marker in line:
                    problems.append(f"{where}: marker {marker!r}")
            if path.name in WORD_EXEMPT:
                continue
            m = WORD.search(line)
            if m:
                problems.append(f"{where}: Turkish word {m.group(0)!r}")
    return problems


def main(argv: list[str]) -> int:
    root = Path(argv[1]).resolve() if len(argv) > 1 else Path(__file__).resolve().parent.parent
    problems = scan(root)
    if problems:
        print("check_publish: FAILED - internal content in published files:")
        for p in problems:
            print("  " + p)
        return 1
    print(f"check_publish: OK ({len(_files(root))} files scanned)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
