#!/usr/bin/env python3
"""Check the repository layout, release metadata, and relative Markdown links.

This does not execute WordPress or prove application behavior. No network or
third-party Python modules are required. Markdown URL fragments are not checked.
"""

import importlib.util
import json
from pathlib import Path
import re
import sys
from urllib.parse import unquote, urlsplit


SCRIPT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("jalinwp_package", SCRIPT / "package.py")
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


def main():
    root = package.ROOT
    errors = []
    try:
        release = package.version(root)
        members = package.source_files(root)
        for name in package.REQUIRED_PLUGIN:
            package.checked_file(root, f"{package.PLUGIN_NAME}/{name}")
        if (root / "LICENSE").read_bytes() != (root / package.PLUGIN_NAME / "LICENSE").read_bytes():
            errors.append("Root LICENSE differs from the plugin LICENSE")
        for name in package.REQUIRED_ROOT:
            if not (root / name).read_bytes().strip():
                errors.append(f"Required repository metadata is empty: {name}")
    except (OSError, ValueError) as error:
        print(f"Repository check failed: {error}", file=sys.stderr)
        return 1
    local_links = 0
    for file in members:
        if file.suffix.lower() != ".md":
            continue
        content = file.read_text(encoding="utf-8")
        # Fenced examples are documentation, not navigation links.
        content = re.sub(r"(?ms)^```.*?^```[^\n]*$", "", content)
        for match in re.finditer(r"\[[^\]\n]*\]\((<[^>]+>|[^\s)]+)(?:\s+['\"][^\n]*?['\"])?\)", content):
            target = match.group(1).strip("<>")
            parsed = urlsplit(target)
            if parsed.scheme or parsed.netloc or not parsed.path:
                continue
            destination = (root if parsed.path.startswith("/") else file.parent) / unquote(parsed.path).lstrip("/")
            local_links += 1
            if not destination.resolve().is_relative_to(root) or not destination.exists():
                errors.append(f"Broken local link in {file.relative_to(root)}: {target}")
    if errors:
        print("Repository check failed:\n" + "\n".join(f"- {item}" for item in errors), file=sys.stderr)
        return 1
    print(json.dumps({
        "status": "passed", "version": release, "source_files": len(members),
        "relative_markdown_links": local_links,
        "checks": ["Required root metadata", "Legacy plugin layout", "Version agreement",
                   "Matching license copies", "Required brand assets", "Curated source members",
                   "Relative Markdown file targets"],
        "scope": "No network links, anchors, WordPress execution, or Git ignore semantics tested",
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
