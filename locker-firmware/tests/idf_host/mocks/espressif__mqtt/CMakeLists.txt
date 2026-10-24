# Replaces the esp-mqtt component with a CMock mock generated from its real
# header, as ESP-IDF does for its own components in tools/mocks. Linux target only.
# The header comes from the firmware's resolved copy (dependencies.lock), so build the
# firmware, or run `idf.py reconfigure` in locker-firmware, first.
set(mqtt_dir "${CMAKE_CURRENT_LIST_DIR}/../../../../managed_components/espressif__mqtt")
if(NOT EXISTS "${mqtt_dir}/include/mqtt_client.h")
  message(FATAL_ERROR "${mqtt_dir} is missing: run `idf.py reconfigure` in locker-firmware first.")
endif()

idf_component_mock(INCLUDE_DIRS "${mqtt_dir}/include"
                   MOCK_HEADER_FILES ${mqtt_dir}/include/mqtt_client.h
                   REQUIRES esp_event tcp_transport
                   )
