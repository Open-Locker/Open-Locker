cmake_minimum_required(VERSION 3.20)
project(locker_firmware_host_tests C)

# Host build of the pure firmware components with fakes for their ports.
# No ESP-IDF involved. See docs/testing.md.

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
  ${LC_COMPONENTS}/lc_domain/config.c
  ${LC_COMPONENTS}/lc_domain/door_detection.c
  ${LC_COMPONENTS}/lc_application/app.c
  ${LC_COMPONENTS}/lc_application/handle_command.c
  ${LC_COMPONENTS}/lc_application/open_compartment.c
  ${LC_COMPONENTS}/lc_application/apply_config.c
  ${LC_COMPONENTS}/lc_application/publish_state.c
  ${LC_COMPONENTS}/lc_application/inbox.c
  ${LC_COMPONENTS}/lc_application/provision_device.c
  ${LC_COMPONENTS}/lc_application/commission_device.c
  ${LC_COMPONENTS}/lc_mqtt_codec/codec.c
  ${LC_COMPONENTS}/lc_mqtt_codec/topics.c
  ${LC_COMPONENTS}/lc_mqtt_codec/provisioning.c
  ${LC_COMPONENTS}/lc_mqtt_codec/events.c
  ${LC_COMPONENTS}/lc_journal/journal.c
  ${LC_COMPONENTS}/lc_rs485_lock_board/codec.c
  ${LC_COMPONENTS}/lc_rs485_lock_board/bus.c
  ${LC_COMPONENTS}/lc_rs485_lock_board/self_test.c
  ${LC_COMPONENTS}/lc_usb_console/line.c
  ${LC_COMPONENTS}/lc_usb_console/console.c
  ${LC_COMPONENTS}/lc_usb_console/setup_json.c
  fakes/probe.c
)
target_include_directories(lc_under_test PUBLIC
  ${LC_COMPONENTS}/lc_domain/include
  ${LC_COMPONENTS}/lc_ports/include
  ${LC_COMPONENTS}/lc_rs485_lock_board/include
  ${LC_COMPONENTS}/lc_usb_console/include
  ${LC_COMPONENTS}/lc_application/include
  ${LC_COMPONENTS}/lc_mqtt_codec/include
  ${LC_COMPONENTS}/lc_journal/include
)
target_link_libraries(lc_under_test PUBLIC cjson)

add_library(lc_fakes STATIC
  fakes/fake_serial.c
  fakes/fake_ports.c
  fakes/fake_flash.c
  fakes/emitted.c
  fakes/probe.c
)
target_include_directories(lc_fakes PUBLIC ${CMAKE_CURRENT_SOURCE_DIR})
target_link_libraries(lc_fakes PUBLIC lc_under_test unity cjson)

# The application wired to its fakes.
add_library(lc_fixture STATIC fakes/app_fixture.c fakes/probe.c)
target_link_libraries(lc_fixture PUBLIC lc_fakes)

target_compile_definitions(lc_under_test PRIVATE PROBE_NAME=under_test)
target_compile_definitions(lc_fakes PRIVATE PROBE_NAME=fakes)
target_compile_definitions(lc_fixture PRIVATE PROBE_NAME=fixture)

if(NOT MSVC)
  foreach(target lc_under_test lc_fakes lc_fixture)
    target_compile_options(${target} PRIVATE -Wall -Wextra -Werror)
  endforeach()
  if(LC_SANITIZE)
    # add_compile_options() would only reach targets created after it, not these three.
    foreach(target lc_under_test lc_fakes lc_fixture)
      target_compile_options(${target} PRIVATE -fsanitize=address,undefined -fno-omit-frame-pointer)
    endforeach()
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
  target_link_libraries(${name} PRIVATE lc_fixture lc_under_test lc_fakes unity)
  target_compile_definitions(${name} PRIVATE LC_REPO_ROOT="${LC_REPO_ROOT}")
  if(LC_SANITIZE AND NOT MSVC)
    target_compile_definitions(${name} PRIVATE LC_SANITIZE_EXPECTED)
  endif()
  if(NOT MSVC)
    target_compile_options(${name} PRIVATE -Wall -Wextra -Werror)
  endif()
  add_test(NAME ${name} COMMAND ${name})
endfunction()

# A suite whose code is not implemented yet. It is compiled against the
# headers, so it cannot rot, but not linked or run: the code it tests does not
# exist. The change that implements it turns the line into lc_host_test() and
# adds its sources to lc_under_test.
function(lc_pending_test name)
  add_library(${name} OBJECT ${name}.c)
  target_link_libraries(${name} PRIVATE lc_fixture)
  target_compile_definitions(${name} PRIVATE LC_REPO_ROOT="${LC_REPO_ROOT}")
  if(NOT MSVC)
    target_compile_options(${name} PRIVATE -Wall -Wextra -Werror)
  endif()
endfunction()

lc_host_test(test_sanitizer_coverage)
lc_host_test(test_rs485_codec)
lc_host_test(test_rs485_bus)
lc_host_test(test_console_line)
lc_host_test(test_console)

# S2: commands, journal and MQTT codec
lc_host_test(test_mqtt_codec)
lc_host_test(test_config)
lc_host_test(test_journal)
lc_host_test(test_handle_command)
lc_host_test(test_open_compartment)
lc_host_test(test_apply_config)
lc_host_test(test_owner_inbox)
# Beyond the test catalogue
lc_host_test(test_domain)
lc_host_test(test_journal_edge)
lc_host_test(test_journal_retention)
lc_host_test(test_app_edge)
lc_host_test(test_mqtt_codec_edge)
lc_host_test(test_console_command)
# S3: network, MQTT and enrollment
lc_host_test(test_provision_device)
lc_host_test(test_session)
# S4: state and door events
lc_host_test(test_door_detection)
lc_host_test(test_publish_state)
# S5: USB commissioning
lc_host_test(test_commission_device)
lc_host_test(test_console_protocol)
