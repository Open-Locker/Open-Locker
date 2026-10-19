# ESP-IDF adapters tested on the host: the Linux target, with the SDK below
# them replaced by CMock mocks generated from the real headers.
#   idf.py --preview set-target linux build && ./build/idf_host_test.elf
cmake_minimum_required(VERSION 3.16)

set(COMPONENTS main)
list(APPEND EXTRA_COMPONENT_DIRS
     "${CMAKE_CURRENT_LIST_DIR}/../../components/lc_mqtt_espidf"
     "${CMAKE_CURRENT_LIST_DIR}/mocks/mqtt"
     "$ENV{IDF_PATH}/tools/mocks/esp_event/"
     "$ENV{IDF_PATH}/tools/mocks/esp-tls/"
     "$ENV{IDF_PATH}/tools/mocks/tcp_transport/")

include($ENV{IDF_PATH}/tools/cmake/project.cmake)
project(idf_host_test)
