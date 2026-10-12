cmake_minimum_required(VERSION 3.20)
project(locker_firmware_host_tests C)

# Host build of the pure firmware components with fakes for their ports.
# No ESP-IDF involved. See docs/plans/esp32-rs485-hexagonal-client.md, 4.1.

set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_C_EXTENSIONS OFF)

option(LC_SANITIZE "Build with AddressSanitizer and UndefinedBehaviorSanitizer" OFF)
option(LC_COVERAGE "Record line coverage of the components under test" OFF)

include(FetchContent)
FetchContent_Declare(
  unity
  GIT_REPOSITORY https://github.com/ThrowTheSwitch/Unity.git
  GIT_TAG v2.6.1
)
FetchContent_MakeAvailable(unity)

# cJSON is one source file. Its own CMake project is not used: SOURCE_SUBDIR
# points at a directory that does not exist, so only the sources are fetched.
FetchContent_Declare(
  cjson
  GIT_REPOSITORY https://github.com/DaveGamble/cJSON.git
  GIT_TAG v1.7.18
  SOURCE_SUBDIR not-a-cmake-project
)
FetchContent_MakeAvailable(cjson)
add_library(cjson STATIC ${cjson_SOURCE_DIR}/cJSON.c)
target_include_directories(cjson PUBLIC ${cjson_SOURCE_DIR})
target_compile_definitions(cjson PUBLIC CJSON_NESTING_LIMIT=16)

set(LC_COMPONENTS ${CMAKE_CURRENT_SOURCE_DIR}/../../components)
get_filename_component(LC_REPO_ROOT ${CMAKE_CURRENT_SOURCE_DIR}/../../.. ABSOLUTE)

add_library(lc_under_test STATIC
  ${LC_COMPONENTS}/lc_rs485_lock_board/codec.c
  ${LC_COMPONENTS}/lc_rs485_lock_board/bus.c
  ${LC_COMPONENTS}/lc_rs485_lock_board/self_test.c
  ${LC_COMPONENTS}/lc_usb_console/line.c
  ${LC_COMPONENTS}/lc_usb_console/console.c
)
target_include_directories(lc_under_test PUBLIC
  ${LC_COMPONENTS}/lc_domain/include
  ${LC_COMPONENTS}/lc_ports/include
  ${LC_COMPONENTS}/lc_rs485_lock_board/include
  ${LC_COMPONENTS}/lc_usb_console/include
  # Declared by slice 0 of the tests; implemented slice by slice.
  ${LC_COMPONENTS}/lc_application/include
  ${LC_COMPONENTS}/lc_mqtt_codec/include
  ${LC_COMPONENTS}/lc_journal/include
)

add_library(lc_fakes STATIC
  fakes/fake_serial.c
  fakes/fake_ports.c
  fakes/fake_flash.c
  fakes/emitted.c
)
target_include_directories(lc_fakes PUBLIC ${CMAKE_CURRENT_SOURCE_DIR})
target_link_libraries(lc_fakes PUBLIC lc_under_test unity cjson)

# The application wired to its fakes. It calls the application, so it can only
# be linked once slice 2 exists; until then it is compiled and nothing more.
add_library(lc_fixture STATIC fakes/app_fixture.c)
target_link_libraries(lc_fixture PUBLIC lc_fakes)

if(NOT MSVC)
  foreach(target lc_under_test lc_fakes lc_fixture)
    target_compile_options(${target} PRIVATE -Wall -Wextra -Werror)
  endforeach()
  if(LC_SANITIZE)
    add_compile_options(-fsanitize=address,undefined -fno-omit-frame-pointer)
    add_link_options(-fsanitize=address,undefined)
  endif()
  if(LC_COVERAGE)
    target_compile_options(lc_under_test PRIVATE --coverage -O0)
    add_link_options(--coverage)
  endif()
endif()

enable_testing()

function(lc_host_test name)
  add_executable(${name} ${name}.c)
  target_link_libraries(${name} PRIVATE lc_under_test lc_fakes unity)
  if(NOT MSVC)
    target_compile_options(${name} PRIVATE -Wall -Wextra -Werror)
  endif()
  add_test(NAME ${name} COMMAND ${name})
endfunction()

# A suite whose slice is not implemented yet. It is compiled against the
# headers, so it cannot rot, but not linked or run: the code it tests does not
# exist. The slice that implements it turns the line into lc_host_test() and
# adds its sources to lc_under_test.
function(lc_pending_test name)
  add_library(${name} OBJECT ${name}.c)
  target_link_libraries(${name} PRIVATE lc_fixture)
  target_compile_definitions(${name} PRIVATE LC_REPO_ROOT="${LC_REPO_ROOT}")
  if(NOT MSVC)
    target_compile_options(${name} PRIVATE -Wall -Wextra -Werror)
  endif()
endfunction()

lc_host_test(test_rs485_codec)
lc_host_test(test_rs485_bus)
lc_host_test(test_console_line)
lc_host_test(test_console)

# Slice 2
lc_pending_test(test_mqtt_codec)
lc_pending_test(test_config)
lc_pending_test(test_journal)
lc_pending_test(test_handle_command)
lc_pending_test(test_open_compartment)
lc_pending_test(test_apply_config)
lc_pending_test(test_owner_inbox)
# Slice 3
lc_pending_test(test_provision_device)
lc_pending_test(test_session)
