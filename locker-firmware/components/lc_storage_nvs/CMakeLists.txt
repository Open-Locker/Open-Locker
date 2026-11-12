idf_component_register(
  SRCS nvs_stores.c partition_flash.c
  INCLUDE_DIRS include
  REQUIRES lc_domain lc_ports
  PRIV_REQUIRES nvs_flash esp_partition
)
target_compile_options(${COMPONENT_LIB} PRIVATE -Werror)
