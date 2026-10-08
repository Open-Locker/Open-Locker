idf_component_register(SRCS "main.c" INCLUDE_DIRS "." REQUIRES core storage lock_bus network locker_mqtt esp_driver_uart esp_timer esp_system mbedtls)
