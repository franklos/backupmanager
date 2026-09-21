"""Archive local data as its service user; no links or special files are accepted."""
import os
import stat
import tarfile
from pathlib import Path


def pack_data(root, output):
    root = Path(root)
    if root.is_symlink() or not root.is_dir():
        raise ValueError('Invalid data directory')
    with tarfile.open(fileobj=output, mode='w|gz', dereference=False) as archive:
        for current, directories, files, descriptor in os.fwalk(root, follow_symlinks=False):
            for name in sorted(directories):
                info = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
                if not stat.S_ISDIR(info.st_mode):
                    raise ValueError('Data links and special files are not supported')
                member = tarfile.TarInfo(str((Path(current) / name).relative_to(root)))
                member.type, member.mode, member.mtime = tarfile.DIRTYPE, 0o750, info.st_mtime
                archive.addfile(member)
            for name in sorted(files):
                fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=descriptor)
                with os.fdopen(fd, 'rb') as source:
                    info = os.fstat(source.fileno())
                    if not stat.S_ISREG(info.st_mode):
                        raise ValueError('Data links and special files are not supported')
                    member = tarfile.TarInfo(str((Path(current) / name).relative_to(root)))
                    member.size, member.mode, member.mtime = info.st_size, 0o640, info.st_mtime
                    archive.addfile(member, source)
