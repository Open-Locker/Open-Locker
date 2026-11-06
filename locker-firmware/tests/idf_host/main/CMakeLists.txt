idf_component_register(SRCS "app_main.c" "test_mqtt_transport.c" "test_ota.c"
                       REQUIRES cmock lc_mqtt_espidf espressif__mqtt lc_ota_espidf esp_https_ota app_update)
