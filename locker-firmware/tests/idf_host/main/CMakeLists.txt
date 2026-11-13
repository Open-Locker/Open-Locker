idf_component_register(SRCS "test_mqtt_transport.c" "test_softap.c"
                       REQUIRES cmock lc_mqtt_espidf espressif__mqtt lc_softap_espidf lc_softap_portal
                                protocomm network_provisioning esp_wifi esp_netif esp_event esp_timer)
