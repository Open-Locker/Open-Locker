# task.c needs FreeRTOS; the host tests (Linux target) bring their own task starter.
idf_build_get_property(target IDF_TARGET)
if(target STREQUAL "linux")
  set(srcs ota.c)
else()
  set(srcs ota.c task.c)
endif()

idf_component_register(
  SRCS ${srcs}
  INCLUDE_DIRS include
  REQUIRES lc_domain lc_ports
  PRIV_REQUIRES esp_https_ota esp_http_client app_update esp_app_format mbedtls log
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
