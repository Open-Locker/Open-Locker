# Replaces the esp-mqtt component with a CMock mock generated from its real
# header, as ESP-IDF does for its own components in tools/mocks. Linux target only.
idf_component_get_property(original_mqtt_dir mqtt COMPONENT_OVERRIDEN_DIR)

idf_component_mock(INCLUDE_DIRS "${original_mqtt_dir}/esp-mqtt/include"
                   MOCK_HEADER_FILES ${original_mqtt_dir}/esp-mqtt/include/mqtt_client.h
                   REQUIRES esp_event tcp_transport
                   )
