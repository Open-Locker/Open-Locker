idf_component_register(
  SRCS softap.c
  INCLUDE_DIRS include
  REQUIRES lc_domain
  PRIV_REQUIRES lc_softap_portal protocomm wifi_provisioning esp_http_server esp_wifi esp_netif
                esp_event esp_timer
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
