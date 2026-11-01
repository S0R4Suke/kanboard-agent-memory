#!/usr/bin/env python3
"""Build a plugin ZIP from the public source tree only."""
import hashlib
import re
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parent.parent
dist = root / 'dist'
dist.mkdir(exist_ok=True)
version = re.search(r"function getPluginVersion\(\).*?return '([^']+)';", (root / 'AgentMemory/Plugin.php').read_text(), re.S).group(1)
archive = dist / f'AgentMemory-{version}.zip'
with ZipFile(archive, 'w', ZIP_DEFLATED) as output:
    for folder in ('AgentMemory', 'docs', 'examples'):
        for source in sorted((root / folder).rglob('*')):
            if not source.is_file() or '__pycache__' in source.parts or source.name.startswith('.'):
                continue
            if source.is_symlink():
                raise RuntimeError('Symlinks are not allowed in a public package')
            relative = source.relative_to(root / 'AgentMemory') if folder == 'AgentMemory' else source.relative_to(root)
            output.write(source, str(Path('AgentMemory') / relative))
    for name in ('README.md', 'LICENSE', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md'):
        output.write(root / name, 'AgentMemory/' + name)
(dist / (archive.name + '.sha256')).write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n')
print(archive)
