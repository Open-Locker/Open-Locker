"""Preserve the bench project's PlatformIO 7.1.3 bootloader linker workaround."""
import os
Import("env")
env.Append(LINKFLAGS=["-L" + os.path.join(env.subst("$BUILD_DIR"), "bootloader", "ld")])
