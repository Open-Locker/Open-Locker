cmake_minimum_required(VERSION 3.16)

# The version of the image (ADR-0075), from LC_FIRMWARE_VERSION in the environment; see the module.
# ESP-IDF's own default (git describe) is not semantic versioning.
include(${CMAKE_CURRENT_LIST_DIR}/cmake/lc_firmware_version.cmake)
lc_firmware_version(PROJECT_VER)

include($ENV{IDF_PATH}/tools/cmake/project.cmake)
project(locker-firmware)
