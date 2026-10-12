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

set(LC_COMPONENTS ${CMAKE_CURRENT_SOURCE_DIR}/../../components)

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
)

add_library(lc_fakes STATIC fakes/fake_serial.c)
target_include_directories(lc_fakes PUBLIC ${CMAKE_CURRENT_SOURCE_DIR})
target_link_libraries(lc_fakes PUBLIC lc_under_test)

if(NOT MSVC)
  foreach(target lc_under_test lc_fakes)
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

lc_host_test(test_rs485_codec)
lc_host_test(test_rs485_bus)
lc_host_test(test_console_line)
lc_host_test(test_console)
