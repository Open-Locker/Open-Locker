"""PlatformIO 7.1.3 / IDF 6.1 generates bootloader scripts in bootloader/ld.

Add that directory to the linker search path; this platform version otherwise
fails to find bootloader.memory.ld. No installed tool files are modified.
"""

import os

Import("env")

env.Append(LINKFLAGS=["-L" + os.path.join(env.subst("$BUILD_DIR"), "bootloader", "ld")])
