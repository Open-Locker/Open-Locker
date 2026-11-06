# The version of the image (ADR-0075): semantic versioning without a "v" or build metadata, at most
# 31 characters (the size of the image's version field). It is read from the environment variable
# LC_FIRMWARE_VERSION at every configure and from nowhere else: a -D value would stay in the CMake
# cache and carry over into later builds. A build without it is a development build.
#
# CMake does not watch the environment: after changing LC_FIRMWARE_VERSION run `idf.py reconfigure`.
function(lc_firmware_version out)
  if(DEFINED ENV{LC_FIRMWARE_VERSION})
    set(version "$ENV{LC_FIRMWARE_VERSION}")
  else()
    set(version "0.0.0-dev")
  endif()

  set(number "(0|[1-9][0-9]*)")
  set(identifier "(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)")
  string(LENGTH "${version}" length)
  if(NOT version MATCHES "^${number}\.${number}\.${number}(-${identifier}(\.${identifier})*)?$" OR length GREATER 31)
    message(FATAL_ERROR
      "LC_FIRMWARE_VERSION is '${version}', which is not a version the firmware accepts. "
      "Use semantic versioning without a leading 'v' and without build metadata, "
      "at most 31 characters, for example 0.2.0 or 0.2.0-rc.1.")
  endif()

  set(${out} "${version}" PARENT_SCOPE)
endfunction()
