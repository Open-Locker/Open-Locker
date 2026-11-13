idf_component_register(
  SRCS wifi.c
  INCLUDE_DIRS include
  REQUIRES lc_domain
  PRIV_REQUIRES esp_wifi esp_netif esp_event esp_timer nvs_flash
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
